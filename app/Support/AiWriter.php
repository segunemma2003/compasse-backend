<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * The school's own AI key (Settings → Integrations → AI), used for teachers' lesson notes.
 * Supports Anthropic (Claude), OpenAI and Google Gemini. The key is stored encrypted and
 * never sent to the browser; every teacher in the school can use it.
 */
class AiWriter
{
    public const PROVIDERS = [
        'anthropic' => ['name' => 'Anthropic (Claude)', 'model' => 'claude-sonnet-5-5'],
        'openai' => ['name' => 'OpenAI (ChatGPT)', 'model' => 'gpt-4o-mini'],
        'gemini' => ['name' => 'Google Gemini', 'model' => 'gemini-2.0-flash'],
    ];

    private static function rows(): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('settings')) {
            return collect();
        }
        $school = \App\Models\School::first();

        return DB::table('settings')->where('school_id', $school?->id)->whereIn('key', ['ai_provider', 'ai_model', 'ai_api_key'])->pluck('value', 'key');
    }

    /** @return array{provider: ?string, model: ?string, key: ?string} */
    public static function config(): array
    {
        $r = self::rows();
        $key = null;
        if (! empty($r['ai_api_key'])) {
            try {
                $key = Crypt::decryptString($r['ai_api_key']);
            } catch (\Throwable) {
                $key = null;
            }
        }
        $provider = isset(self::PROVIDERS[$r['ai_provider'] ?? '']) ? $r['ai_provider'] : null;

        return ['provider' => $provider, 'model' => ($r['ai_model'] ?? null) ?: ($provider ? self::PROVIDERS[$provider]['model'] : null), 'key' => $key];
    }

    public static function ready(): bool
    {
        $c = self::config();

        return (bool) ($c['provider'] && $c['key']);
    }

    /** What the admin sees: never the key itself. */
    public static function publicConfig(): array
    {
        $c = self::config();

        return [
            'provider' => $c['provider'], 'model' => $c['model'], 'has_key' => (bool) $c['key'],
            'key_hint' => $c['key'] ? substr($c['key'], 0, 4) . '…' . substr($c['key'], -4) : null,
            'providers' => collect(self::PROVIDERS)->map(fn ($p, $k) => ['id' => $k, 'name' => $p['name'], 'default_model' => $p['model']])->values(),
        ];
    }

    public static function save(?string $provider, ?string $model, ?string $key, bool $removeKey = false): void
    {
        $school = \App\Models\School::first();
        $put = function (string $k, ?string $v) use ($school) {
            DB::table('settings')->updateOrInsert(['school_id' => $school?->id, 'key' => $k], ['value' => $v, 'category' => 'integrations', 'updated_at' => now(), 'created_at' => now()]);
        };
        $put('ai_provider', $provider);
        $put('ai_model', $model ?: null);
        if ($removeKey) {
            $put('ai_api_key', null);
        } elseif ($key !== null && $key !== '') {
            $put('ai_api_key', Crypt::encryptString(trim($key)));
        }
    }

    /** One request to the school's provider; returns the reply text. */
    public static function complete(string $system, string $prompt, int $maxTokens = 3000): string
    {
        ['provider' => $provider, 'model' => $model, 'key' => $key] = self::config();
        if (! $provider || ! $key) {
            throw new \RuntimeException('The school has not added an AI key yet (Settings → Integrations → AI).');
        }
        $http = Http::timeout(90)->acceptJson();
        if ($provider === 'anthropic') {
            $res = $http->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->post('https://api.anthropic.com/v1/messages', [
                'model' => $model, 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
            self::fail($res);

            return (string) collect($res->json('content') ?? [])->where('type', 'text')->pluck('text')->implode('');
        }
        if ($provider === 'openai') {
            $res = $http->withToken($key)->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model, 'max_tokens' => $maxTokens,
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]],
            ]);
            self::fail($res);

            return (string) $res->json('choices.0.message.content');
        }
        $res = $http->post('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode((string) $model) . ':generateContent?key=' . urlencode($key), [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['maxOutputTokens' => $maxTokens],
        ]);
        self::fail($res);

        return (string) collect($res->json('candidates.0.content.parts') ?? [])->pluck('text')->implode('');
    }

    private static function fail(\Illuminate\Http\Client\Response $res): void
    {
        if ($res->successful()) {
            return;
        }
        $msg = $res->json('error.message') ?? $res->json('error.0.message') ?? $res->json('message') ?? $res->body();
        throw new \RuntimeException(match (true) {
            in_array($res->status(), [401, 403], true) => 'The AI provider rejected the school\'s key — check it in Settings → Integrations → AI.',
            $res->status() === 429 => 'The AI provider says the school\'s key has reached its limit for now. Try again later.',
            default => 'The AI provider returned an error: ' . mb_substr((string) $msg, 0, 200),
        });
    }

    /** The first JSON object in a reply (models sometimes wrap it in ```json fences or prose). */
    public static function json(string $text): ?array
    {
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false) {
            return null;
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($data) ? $data : null;
    }
}

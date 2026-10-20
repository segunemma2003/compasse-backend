<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Who did it" for edits and deletes on payroll, care records and requisitions:
 * one row per action with the person, the time and what changed.
 */
class AuditLog
{
    /** Keys never worth showing in a change list. */
    private const SKIP = ['updated_at', 'created_at', 'updated_by'];

    public static function record(string $subjectType, ?int $subjectId, string $action, string $summary, array $changes = []): void
    {
        try {
            if (! Schema::hasTable('audit_logs')) {
                return;
            }
            $u = auth()->user();
            DB::table('audit_logs')->insert([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'action' => $action,
                'summary' => mb_substr($summary, 0, 500),
                'changes' => $changes ? json_encode($changes) : null,
                'user_id' => $u?->id,
                'user_name' => $u?->name,
                'user_role' => $u?->role,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * {field: [old, new]} for the fields that actually changed.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public static function diff(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $k => $new) {
            if (in_array($k, self::SKIP, true)) {
                continue;
            }
            $old = $before[$k] ?? null;
            $norm = fn ($v) => is_array($v) ? json_encode($v) : (is_numeric($v) ? (string) (float) $v : (string) ($v ?? ''));
            if ($norm($old) !== $norm($new)) {
                $out[$k] = [$old, $new];
            }
        }

        return $out;
    }

    public static function for(string $subjectType, ?int $subjectId = null, int $limit = 200): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }
        $q = DB::table('audit_logs')->where('subject_type', $subjectType);
        if ($subjectId !== null) {
            $q->where('subject_id', $subjectId);
        }

        return $q->orderByDesc('id')->limit($limit)->get()->map(function ($r) {
            $r->changes = $r->changes ? json_decode($r->changes, true) : null;

            return $r;
        })->all();
    }
}

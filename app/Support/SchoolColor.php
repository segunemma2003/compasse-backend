<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The school's own colour (Settings → Website → Primary), used on every document the system
 * generates: receipts, invoices, pay stubs, report cards, broadsheets, checkpoint reports, emails.
 * The documents were designed in navy (#1a3a6b) and its pale tints; apply() swaps that palette
 * for the school's colour and matching tints.
 */
class SchoolColor
{
    public const FALLBACK = '#1a3a6b';

    /** Template id => primary colour, for schools that picked a template but no colour. */
    private const TEMPLATE_PRIMARY = [
        'classic' => '#1a3a6b', 'minimal' => '#171717',
        'summit' => '#0a2e5c', 'sunshine' => '#b91c1c', 'shepherd' => '#c41e3a', 'prestige' => '#0b1f3a',
        'evergreen' => '#0d4f3c', 'crimson' => '#b80000', 'heritage' => '#5c1a1b',
    ];

    /** navy-palette colour => how far it sits towards white (0 = full colour). */
    private const PALETTE = [
        '#1a3a6b' => 0.0, '#7030a0' => 0.0, '#1a56db' => 0.0, '#217346' => 0.0,
        '#9aabc9' => 0.56, '#b9c6de' => 0.69, '#d5deec' => 0.82, '#e3e8f2' => 0.88,
        '#eef2fb' => 0.93, '#eef1f8' => 0.93, '#f0f4ff' => 0.94, '#f7f9fd' => 0.97, '#f8faff' => 0.97, '#fafcff' => 0.98,
    ];

    private static array $cache = [];

    public static function primary(): string
    {
        $key = 'k';
        try {
            $key = (string) (tenant()?->getTenantKey() ?? 'central');
        } catch (\Throwable) {
        }
        // Long-running queue workers re-read it every minute, so a changed colour shows up.
        if (isset(self::$cache[$key]) && self::$cache[$key][1] > time() - 60) {
            return self::$cache[$key][0];
        }
        $color = null;
        try {
            if (Schema::hasTable('settings')) {
                $s = DB::table('settings')->where('category', 'landing_page')->whereIn('key', ['primary_color', 'template'])->pluck('value', 'key');
                $color = self::valid($s['primary_color'] ?? null) ?? self::valid(self::TEMPLATE_PRIMARY[$s['template'] ?? ''] ?? null);
            }
        } catch (\Throwable) {
        }

        $color = $color ? self::readable($color) : self::FALLBACK;
        self::$cache[$key] = [$color, time()];

        return $color;
    }

    /** For tests / after the colour is changed. */
    public static function forget(): void
    {
        self::$cache = [];
    }

    public static function valid(?string $c): ?string
    {
        $c = trim((string) $c);
        if (preg_match('/^#([0-9a-f]{3})$/i', $c, $m)) {
            $c = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }

        return preg_match('/^#[0-9a-f]{6}$/i', $c) ? strtolower($c) : null;
    }

    /**
     * Documents print the colour as text and as a band behind white text, so a very light colour
     * (e.g. a pale grey picked for the website) is darkened until it reads on white paper.
     */
    public static function readable(string $hex): string
    {
        $lum = function (string $h): float {
            [$r, $g, $b] = array_map(function ($v) {
                $v /= 255;

                return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }, sscanf($h, '#%02x%02x%02x'));

            return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        };
        // Contrast with white of at least 4.5:1 (luminance about 0.18 or less).
        for ($i = 0; $i < 20 && $lum($hex) > 0.18; $i++) {
            [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
            $hex = sprintf('#%02x%02x%02x', (int) ($r * 0.85), (int) ($g * 0.85), (int) ($b * 0.85));
        }

        return $hex;
    }

    /** The colour mixed towards white by $t (0..1). */
    public static function tint(string $hex, float $t): string
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        $mix = fn ($v) => (int) round($v + (255 - $v) * $t);

        return sprintf('#%02x%02x%02x', $mix($r), $mix($g), $mix($b));
    }

    /** Re-colour a generated document in the school's colour. */
    public static function apply(string $html, ?string $color = null): string
    {
        $color = self::valid($color) ?? self::primary();
        if ($color === self::FALLBACK) {
            return $html;
        }
        $map = [];
        foreach (self::PALETTE as $from => $t) {
            $to = $t == 0.0 ? $color : self::tint($color, $t);
            $map[$from] = $to;
            $map[strtoupper($from)] = $to;
        }

        return strtr($html, $map);
    }
}

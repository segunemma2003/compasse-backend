<?php

namespace App\Support;

use App\Models\CareRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The school's choices for Records (reports and diaries):
 *  - approval: per kind of form, staff forms that go to parents wait for the admin / head of school
 *    to approve them (Ouch and Head Injury reports do unless the school says otherwise);
 *  - email: which kinds of form are emailed to parents (otherwise they only appear on the parent portal).
 */
class RecordsPolicy
{
    private const APPROVAL_KEY = 'records_approval_required';
    private const EMAIL_KEY = 'records_email_parents';
    private const APPROVAL_TYPES_KEY = 'records_approval_types';

    /** Forms checked by the admin / head of school before parents see them, until the school chooses. */
    private const APPROVAL_DEFAULT = ['ouch_report', 'head_injury'];

    /** Whether any kind of form needs approval. */
    public static function approvalRequired(): bool
    {
        return in_array(true, self::approvalMap(), true);
    }

    /** Whether this kind of form waits for approval before parents see it. */
    public static function needsApproval(string $type): bool
    {
        return self::approvalMap()[$type] ?? false;
    }

    /** @return array<string, bool> */
    private static function approvalMap(): array
    {
        $parentTypes = array_keys(array_filter(CareRecord::TYPES, fn ($m) => ! empty($m['parents'])));
        $saved = json_decode((string) self::get(self::APPROVAL_TYPES_KEY), true);
        $all = self::get(self::APPROVAL_KEY); // the earlier one switch for every form

        return collect($parentTypes)->mapWithKeys(fn ($t) => [$t => is_array($saved) && array_key_exists($t, $saved)
            ? (bool) $saved[$t]
            : ($all === '1' || in_array($t, self::APPROVAL_DEFAULT, true))])->all();
    }

    /** Whether this kind of form is emailed to parents: the school's choice, else the form's default. */
    public static function emailsParents(string $type): bool
    {
        $map = json_decode((string) self::get(self::EMAIL_KEY), true);
        if (is_array($map) && array_key_exists($type, $map)) {
            return (bool) $map[$type];
        }

        return (bool) (CareRecord::meta($type)['email'] ?? false);
    }

    /** @return array{approval_required: bool, email_parents: array<string, bool>, types: array} */
    public static function all(): array
    {
        $parentTypes = array_filter(CareRecord::TYPES, fn ($m) => ! empty($m['parents']));

        return [
            'approval_required' => self::approvalRequired(),
            'approval' => self::approvalMap(),
            'email_parents' => collect($parentTypes)->mapWithKeys(fn ($m, $k) => [$k => self::emailsParents($k)])->all(),
            'types' => collect($parentTypes)->map(fn ($m, $k) => ['type' => $k, 'label' => $m['label']])->values()->all(),
        ];
    }

    /** $approval: one switch for every form; $approvalTypes: per kind of form (wins). */
    public static function save(?bool $approval, ?array $emailParents, ?array $approvalTypes = null): void
    {
        if ($approval !== null && $approvalTypes === null) {
            $approvalTypes = array_fill_keys(array_keys(self::approvalMap()), $approval);
        }
        if ($approvalTypes !== null) {
            $valid = array_intersect_key(array_map(fn ($v) => (bool) $v, $approvalTypes), CareRecord::TYPES);
            self::put(self::APPROVAL_TYPES_KEY, json_encode(array_merge(self::approvalMap(), $valid)), 'json');
        }
        if ($emailParents !== null) {
            $valid = array_intersect_key(array_map(fn ($v) => (bool) $v, $emailParents), CareRecord::TYPES);
            self::put(self::EMAIL_KEY, json_encode($valid), 'json');
        }
    }

    private static function get(string $key): ?string
    {
        if (! Schema::hasTable('settings')) {
            return null;
        }

        return DB::table('settings')->where('key', $key)->value('value');
    }

    private static function put(string $key, string $value, string $type): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], [
            'value' => $value, 'type' => $type, 'category' => 'records',
            'school_id' => DB::table('schools')->value('id'), 'updated_at' => now(), 'created_at' => now(),
        ]);
    }
}

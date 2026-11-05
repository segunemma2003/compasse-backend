<?php

namespace App\Support;

use App\Models\CareRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The school's choices for Records (reports and diaries):
 *  - approval: staff forms that go to parents wait for the admin / head of school to approve them;
 *  - email: which kinds of form are emailed to parents (otherwise they only appear on the parent portal).
 */
class RecordsPolicy
{
    private const APPROVAL_KEY = 'records_approval_required';
    private const EMAIL_KEY = 'records_email_parents';

    public static function approvalRequired(): bool
    {
        return self::get(self::APPROVAL_KEY) === '1';
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
            'email_parents' => collect($parentTypes)->mapWithKeys(fn ($m, $k) => [$k => self::emailsParents($k)])->all(),
            'types' => collect($parentTypes)->map(fn ($m, $k) => ['type' => $k, 'label' => $m['label']])->values()->all(),
        ];
    }

    public static function save(?bool $approval, ?array $emailParents): void
    {
        if ($approval !== null) {
            self::put(self::APPROVAL_KEY, $approval ? '1' : '0', 'boolean');
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

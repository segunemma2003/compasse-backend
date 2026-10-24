<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Lets a school upload its OWN Excel (invoice sheet, staff payment sheet…) instead of our template:
 * find the heading row (title rows above it are skipped), guess what each column is, let the bursar
 * confirm or change it, then turn the sheet into the layout the importer understands. The confirmed
 * choices are remembered per school, so next term's sheet in the same layout just works.
 */
class SpreadsheetMapping
{
    /** role => [label, words that suggest it] per importer */
    public const ROLES = [
        'fees' => [
            'admission' => ['Admission number', ['admission', 'adm no', 'adm. no', 'reg no', 'student id', 'reg. no', 'admission no']],
            'name' => ['Student name', ['name', 'pupil', 'student', 'learner', 'child']],
            'class' => ['Class', ['class', 'grade', 'year group', 'form']],
            'fee_type' => ['Fee type', ['fee type', 'type', 'description of fee']],
            'due_date' => ['Due date', ['due']],
            'note' => ['Note', ['note', 'remark', 'comment']],
            'deduct' => ['Discount (taken off)', ['discount', 'deduct', 'less', 'scholarship', 'waiver', 'rebate', 'credit']],
            'line' => ['Fee line (amount)', []],
            'ignore' => ['Ignore', ['s/n', 'sn', 's.n', 'no.', 'total', 'grand total', 'amount paid', 'paid', 'balance', 'outstanding', 'sign', 'signature', 'phone', 'parent']],
        ],
        'payroll' => [
            'staff_id' => ['Staff ID / email', ['staff id', 'staff no', 'email', 'employee id', 'username', 'id no', 'ippis']],
            'name' => ['Name', ['name', 'staff', 'employee']],
            'basic' => ['Basic salary', ['basic', 'salary', 'gross']],
            'notes' => ['Notes', ['note', 'remark', 'comment']],
            'deduction' => ['Deduction', ['tax', 'paye', 'pension', 'loan', 'penalt', 'deduct', 'welfare', 'levy', 'union', 'advance', 'fine', 'absent', 'late', 'nhf', 'nhis', 'contribution']],
            'earning' => ['Allowance / earning', ['allowance', 'bonus', 'overtime', 'reward', 'transport', 'housing', 'reimburs', 'additional', 'income', 'arrears']],
            'ignore' => ['Ignore', ['s/n', 'sn', 's.n', 'no.', 'net', 'net pay', 'total', 'bank', 'account', 'acct', 'designation', 'role', 'department', 'sign', 'signature', 'phone']],
        ],
    ];

    private static function norm($v): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', (string) $v)));
    }

    private static function isNumber($v): bool
    {
        $s = trim(str_replace([',', '₦', 'N', ' '], '', (string) $v));

        return $s !== '' && is_numeric($s);
    }

    /**
     * The heading row: the first of the top 15 rows that reads like headings (2+ cells such as
     * "Name", "Admission No", "Basic Salary", "Tuition"); failing that, the first row with 3+ text
     * cells followed by a row with numbers.
     */
    public static function headerRow(array $rows): int
    {
        $words = ['name', 'admission', 'adm', 'reg', 'class', 'fee', 'tuition', 'amount', 'total', 's/n', 'staff', 'salary', 'basic', 'email', 'pupil', 'student', 'allowance', 'pension', 'tax', 'paye', 'net', 'due', 'balance', 'discount', 'description', 'id'];
        foreach (array_slice($rows, 0, 15, true) as $i => $r) {
            $hits = count(array_filter($r, function ($c) use ($words) {
                $c = self::norm($c);
                if ($c === '' || strlen($c) > 40 || self::isNumber($c)) {
                    return false;
                }
                foreach ($words as $w) {
                    if (preg_match('/\\b' . preg_quote($w, '/') . '/', $c)) {
                        return true;
                    }
                }

                return false;
            }));
            if ($hits >= 2) {
                return $i;
            }
        }
        foreach (array_slice($rows, 0, 15, true) as $i => $r) {
            $text = count(array_filter($r, fn ($c) => trim((string) $c) !== '' && ! self::isNumber($c)));
            $next = $rows[$i + 1] ?? [];
            if ($text >= 3 && count(array_filter($next, fn ($c) => self::isNumber($c))) >= 1) {
                return $i;
            }
        }

        return 0;
    }

    public static function guess(string $kind, string $header, array $samples): string
    {
        $h = self::norm($header);
        if ($h === '') {
            return 'ignore';
        }
        foreach (['ignore', 'deduct', 'deduction', 'admission', 'staff_id', 'due_date', 'fee_type', 'note', 'notes', 'class', 'basic', 'earning', 'name'] as $role) {
            foreach (self::ROLES[$kind][$role][1] ?? [] as $w) {
                if ($h === $w || str_contains($h, $w)) {
                    return $role;
                }
            }
        }
        $numeric = count(array_filter($samples, fn ($v) => self::isNumber($v)));

        return $kind === 'fees' ? ($numeric ? 'line' : 'ignore') : ($numeric ? 'earning' : 'ignore');
    }

    private static function signature(array $headers): string
    {
        return md5(implode('|', array_map([self::class, 'norm'], $headers)));
    }

    /** {header_row, headers, sample rows, roles (remembered for this layout, else guessed), role labels} */
    public static function preview(string $kind, array $rows, ?int $headerRow = null): array
    {
        $hr = $headerRow ?? self::headerRow($rows);
        $headers = array_map(fn ($c) => trim((string) $c), $rows[$hr] ?? []);
        $body = array_slice($rows, $hr + 1, 6);
        $saved = self::saved($kind, $headers);
        $roles = [];
        foreach ($headers as $i => $h) {
            $roles[$i] = $saved[$i] ?? self::guess($kind, $h, array_column($body, $i));
        }

        return [
            'header_row' => $hr, 'headers' => $headers, 'sample' => $body, 'roles' => $roles, 'remembered' => (bool) $saved,
            'role_labels' => array_map(fn ($r) => $r[0], self::ROLES[$kind]), 'top_rows' => array_slice($rows, 0, min(8, $hr + 1)),
        ];
    }

    /**
     * The sheet rearranged for the importer: a heading row with the importer's own names, then the
     * data rows (blank / total rows dropped). Also returns explicit line types (payroll).
     *
     * @return array{0: array, 1: array<string,string>}
     */
    public static function apply(string $kind, array $rows, int $headerRow, array $roles): array
    {
        $headers = array_map(fn ($c) => trim((string) $c), $rows[$headerRow] ?? []);
        $canon = [];
        $types = [];
        $keep = [];
        foreach ($headers as $i => $h) {
            $role = $roles[$i] ?? $roles[(string) $i] ?? 'ignore';
            $name = match ($role) {
                'admission' => 'Admission Number', 'name' => $kind === 'fees' ? 'Student Name' : 'Name', 'class' => 'Class', 'fee_type' => 'Fee Type',
                'due_date' => 'Due Date', 'note' => 'Note', 'notes' => 'Notes', 'staff_id' => 'Staff ID or Email', 'basic' => 'Basic Salary',
                'deduct' => ($h ?: 'Discount') . ' (deduct)', 'line', 'earning', 'deduction' => $h ?: 'Column ' . ($i + 1),
                default => null,
            };
            if ($name === null) {
                continue;
            }
            if (in_array($role, ['earning', 'deduction'], true)) {
                $types[self::norm(preg_replace('/[^a-z0-9%\/ ]/i', ' ', $name))] = $role;
            }
            $keep[$i] = $name;
        }
        $who = array_keys(array_intersect($roles, $kind === 'fees' ? ['admission', 'name'] : ['staff_id', 'name']));
        $out = [array_values($keep)];
        foreach (array_slice($rows, $headerRow + 1) as $r) {
            $id = implode('', array_map(fn ($i) => trim((string) ($r[$i] ?? '')), $who));
            if ($id === '' || preg_match('/^(grand )?total/i', $id)) {
                continue;
            }
            $out[] = array_map(fn ($i) => (string) ($r[$i] ?? ''), array_keys($keep));
        }

        return [$out, $types];
    }

    private static function saved(string $kind, array $headers): array
    {
        try {
            $raw = DB::table('settings')->where('key', "import_mapping_{$kind}_" . self::signature($headers))->value('value');

            return $raw ? (json_decode($raw, true) ?: []) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public static function remember(string $kind, array $rows, int $headerRow, array $roles): void
    {
        try {
            $headers = array_map(fn ($c) => trim((string) $c), $rows[$headerRow] ?? []);
            DB::table('settings')->updateOrInsert(
                ['school_id' => \App\Models\School::first()?->id, 'key' => "import_mapping_{$kind}_" . self::signature($headers)],
                ['value' => json_encode($roles), 'category' => 'imports', 'updated_at' => now(), 'created_at' => now()],
            );
        } catch (\Throwable) {
        }
    }

    /**
     * For an import request: the rows in the importer's layout. With a confirmed mapping (JSON
     * {header_row, roles}) it's applied and remembered; without one the heading row is still found
     * automatically, so title rows above it don't break the import.
     *
     * @return array{0: array, 1: array<string,string>}
     */
    public static function rowsFor(string $kind, array $rows, ?string $mappingJson): array
    {
        $m = $mappingJson ? json_decode($mappingJson, true) : null;
        if (is_array($m) && isset($m['roles'])) {
            $hr = (int) ($m['header_row'] ?? 0);
            self::remember($kind, $rows, $hr, $m['roles']);

            return self::apply($kind, $rows, $hr, $m['roles']);
        }
        $hr = self::headerRow($rows);

        return [array_slice($rows, $hr), []];
    }
}

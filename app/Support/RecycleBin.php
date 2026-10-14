<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps a copy of deleted records (recycle_bin) so a school can restore something deleted
 * by mistake. Models listed in WATCH are captured on every Eloquent delete; students,
 * teachers and staff are soft-deleted already and are restored from their own tables.
 */
class RecycleBin
{
    /** model class => type shown to users */
    public const WATCH = [
        \App\Models\Payment::class => 'payment',
        \App\Modules\Financial\Models\Payment::class => 'payment',
        \App\Models\Fee::class => 'fee',
        \App\Modules\Financial\Models\Fee::class => 'fee',
        \App\Models\Invoice::class => 'invoice',
        \App\Models\Guardian::class => 'parent',
        \App\Models\ClassModel::class => 'class',
        \App\Modules\Academic\Models\ClassModel::class => 'class',
        \App\Models\Subject::class => 'subject',
        \App\Modules\Academic\Models\Subject::class => 'subject',
        \App\Models\Event::class => 'event',
        \App\Models\Exam::class => 'exam',
        \App\Modules\Assessment\Models\Exam::class => 'exam',
        \App\Models\Assignment::class => 'assignment',
        \App\Modules\Assessment\Models\Assignment::class => 'assignment',
    ];

    public static function register(): void
    {
        foreach (self::WATCH as $class => $type) {
            if (! class_exists($class)) {
                continue;
            }
            $class::deleting(function (Model $m) use ($type) {
                // Soft-deleting models keep their own row; only capture hard deletes.
                if (method_exists($m, 'isForceDeleting') && ! $m->isForceDeleting()) {
                    return;
                }
                self::capture($m, $type);
            });
        }
    }

    public static function capture(Model $m, string $type): void
    {
        try {
            if (! Schema::hasTable('recycle_bin')) {
                return;
            }
            $row = DB::table($m->getTable())->where($m->getKeyName(), $m->getKey())->first();
            if (! $row) {
                return;
            }
            DB::table('recycle_bin')->insert([
                'table_name' => $m->getTable(),
                'record_id' => $m->getKey(),
                'type' => $type,
                'label' => self::label((array) $row, $type),
                'data' => json_encode($row),
                'deleted_by' => auth()->id(),
                'deleted_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function label(array $row, string $type): string
    {
        foreach (['name', 'title', 'invoice_number', 'payment_reference'] as $k) {
            if (! empty($row[$k])) {
                $label = (string) $row[$k];
                break;
            }
        }
        if (empty($label) && ! empty($row['first_name'])) {
            $label = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        }
        if (empty($label) && isset($row['fee_type'])) {
            $label = ucwords(str_replace('_', ' ', (string) $row['fee_type']));
        }
        $label = $label ?? ucfirst($type) . ' #' . ($row['id'] ?? '');
        if (isset($row['amount']) && in_array($type, ['payment', 'fee'], true)) {
            $label .= ' · ₦' . number_format((float) $row['amount'], 2);
        }
        if (! empty($row['student_id']) && Schema::hasTable('students')) {
            $s = DB::table('students')->where('id', $row['student_id'])->first(['first_name', 'last_name']);
            if ($s) {
                $label .= " · {$s->first_name} {$s->last_name}";
            }
        }

        return mb_substr($label, 0, 250);
    }
}

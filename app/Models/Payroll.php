<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payroll extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'staff_id',
        'academic_year_id',
        'month',
        'year',
        'basic_salary',
        'allowances',
        'deductions',
        'net_salary',
        'payment_date',
        'payment_method',
        'status',
        'notes',
        'processed_by',
        'items',
    ];

    /** Lines a school starts with; it can rename, add or remove them (Payroll → Pay slip lines). */
    public const DEFAULT_LINES = [
        ['name' => 'Allowances', 'type' => 'earning'],
        ['name' => 'Rewards / Bonus', 'type' => 'earning'],
        ['name' => 'Additional income', 'type' => 'earning'],
        ['name' => 'Reimbursable', 'type' => 'earning'],
        ['name' => 'PAYE (tax)', 'type' => 'deduction'],
        ['name' => 'Pension', 'type' => 'deduction', 'percent' => 8],
        ['name' => 'Loan deduction', 'type' => 'deduction'],
        ['name' => 'Penalties', 'type' => 'deduction'],
        ['name' => 'Staff welfare', 'type' => 'deduction'],
    ];

    protected $casts = [
        'basic_salary' => 'decimal:2',
        'allowances' => 'decimal:2',
        'deductions' => 'decimal:2',
        'net_salary' => 'decimal:2',
        'payment_date' => 'date',
        'items' => 'array',
    ];

    /**
     * Clean [{name, type, amount}] lines (blank / zero lines dropped) and the totals they give.
     *
     * @return array{items: array, allowances: float, deductions: float, net_salary: float}
     */
    public static function totals(float $basic, array $items): array
    {
        $clean = [];
        foreach ($items as $i) {
            $name = trim((string) ($i['name'] ?? ''));
            $amount = round((float) ($i['amount'] ?? 0), 2);
            if ($name === '' || $amount == 0.0) {
                continue;
            }
            $clean[] = ['name' => $name, 'type' => ($i['type'] ?? '') === 'deduction' ? 'deduction' : 'earning', 'amount' => $amount];
        }
        $earn = array_sum(array_map(fn ($i) => $i['type'] === 'earning' ? $i['amount'] : 0, $clean));
        $ded = array_sum(array_map(fn ($i) => $i['type'] === 'deduction' ? $i['amount'] : 0, $clean));

        return ['items' => $clean, 'allowances' => round($earn, 2), 'deductions' => round($ded, 2), 'net_salary' => round($basic + $earn - $ded, 2)];
    }

    /** The pay slip's lines; records made before lines existed show their two totals as lines. */
    public function lines(): array
    {
        if (is_array($this->items) && $this->items) {
            return $this->items;
        }
        $out = [];
        if ((float) $this->allowances) {
            $out[] = ['name' => 'Allowances', 'type' => 'earning', 'amount' => (float) $this->allowances];
        }
        if ((float) $this->deductions) {
            $out[] = ['name' => 'Deductions', 'type' => 'deduction', 'amount' => (float) $this->deductions];
        }

        return $out;
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    protected $appends = ['period'];

    public function getPeriodAttribute(): string
    {
        return \Carbon\Carbon::createFromDate((int) $this->year, (int) $this->month, 1)->format('F Y');
    }
}


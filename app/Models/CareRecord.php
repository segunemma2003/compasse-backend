<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One filled-in school form (ouch report, medicine form, daily diary, class/school/nurse report…).
 * The form's fields live in `data`; the portal lays them out like the school's paper template.
 */
class CareRecord extends Model
{
    protected $fillable = [
        'type', 'serial', 'student_id', 'class_id', 'record_date', 'period_end', 'data', 'parent_response',
        'status', 'created_by', 'updated_by', 'sent_at',
    ];

    protected $casts = [
        'data' => 'array',
        'parent_response' => 'array',
        'record_date' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'sent_at' => 'datetime',
    ];

    /**
     * subject: who the form is about (student | class | school).
     * parents: parents see it (and can reply). email: parents are emailed by default.
     * admin: leadership is notified when it is filed.
     */
    public const TYPES = [
        'ouch_report'         => ['label' => 'Ouch Report', 'subject' => 'student', 'parents' => true, 'email' => true, 'admin' => true],
        'head_injury'         => ['label' => 'Head Injury Report', 'subject' => 'student', 'parents' => true, 'email' => true, 'admin' => true],
        'medicine_form'       => ['label' => 'Prescribed Medicine', 'subject' => 'student', 'parents' => true, 'email' => false, 'admin' => false],
        'preschool_diary'     => ['label' => 'Pre-school Daily Diary', 'subject' => 'student', 'parents' => true, 'email' => false, 'admin' => false],
        'homework_diary'      => ['label' => 'Homework Daily Diary', 'subject' => 'student', 'parents' => true, 'email' => false, 'admin' => false],
        'weekly_homework'     => ['label' => 'Weekly Homework Diary', 'subject' => 'student', 'parents' => true, 'email' => false, 'admin' => false],
        'class_daily_report'  => ['label' => 'Class Daily Report', 'subject' => 'class', 'parents' => true, 'email' => true, 'admin' => true],
        'class_weekly_report' => ['label' => 'Class Weekly Report', 'subject' => 'class', 'parents' => false, 'email' => false, 'admin' => true],
        'school_daily_report' => ['label' => 'School Daily Report', 'subject' => 'school', 'parents' => false, 'email' => false, 'admin' => true],
        'nurse_daily_report'  => ['label' => 'Nurse Daily Report', 'subject' => 'school', 'parents' => false, 'email' => false, 'admin' => true],
    ];

    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type]['label'] ?? ucwords(str_replace('_', ' ', $type));
    }

    public static function meta(string $type): array
    {
        return self::TYPES[$type] ?? [];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getNumberAttribute(): string
    {
        return str_pad((string) $this->serial, 5, '0', STR_PAD_LEFT);
    }
}

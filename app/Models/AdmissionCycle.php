<?php

namespace App\Models;

use App\Modules\Academic\Models\ClassModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AdmissionCycle extends Model
{
    /**
     * Placeholders a school can use inside welcome_email_subject/_body,
     * substituted by AdmissionController when an applicant is approved.
     * {login_email} and {password} are only meaningful there — they render
     * blank on the rejected/waitlisted templates, which don't create a login.
     */
    public const EMAIL_PLACEHOLDERS = [
        '{applicant_name}', '{school_name}', '{class_name}',
        '{login_email}', '{password}', '{portal_url}',
    ];

    public const DEFAULT_WELCOME_SUBJECT = 'Welcome to {school_name}!';

    public const DEFAULT_WELCOME_BODY = <<<'BODY'
        Dear {applicant_name},

        Congratulations! We are delighted to offer you admission to {class_name} at {school_name}.

        Your parent/guardian portal login:
        Email: {login_email}
        Password: {password}
        Portal: {portal_url}

        Please log in and change your password on first use. We look forward to welcoming you to the school.
        BODY;

    protected $fillable = [
        'school_id',
        'name',
        'class_id',
        'academic_year_id',
        'description',
        'welcome_email_subject',
        'welcome_email_body',
        'requires_entrance_exam',
        'opens_at',
        'closes_at',
        'status',
        'created_by',
        'class_ids',
        'form_fields',
    ];

    /** Field types a school can add to its admission form. */
    public const FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'select', 'checkbox', 'file'];

    protected $casts = [
        'requires_entrance_exam' => 'boolean',
        'opens_at'  => 'datetime',
        'closes_at' => 'datetime',
        'class_ids'   => 'array',
        'form_fields' => 'array',
    ];

    protected $appends = ['registration_url', 'class_list'];

    /** Classes this cycle admits into (the parent picks one). */
    public function classIds(): array
    {
        $ids = array_values(array_filter(array_map('intval', $this->class_ids ?? [])));

        return $ids ?: array_values(array_filter([(int) $this->class_id]));
    }

    /** [{id, name}] of the classes offered, for the admin and public pages. */
    public function getClassListAttribute(): array
    {
        $ids = $this->classIds();
        if (! $ids) {
            return [];
        }

        return ClassModel::whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all();
    }

    /**
     * Normalises a school's form field definitions: label, type, required,
     * options (for select) and a stable key derived from the label.
     */
    public static function normaliseFields(array $fields): array
    {
        $out = [];
        $used = [];
        foreach ($fields as $f) {
            $label = trim((string) ($f['label'] ?? ''));
            $type  = in_array($f['type'] ?? null, self::FIELD_TYPES, true) ? $f['type'] : 'text';
            if ($label === '') {
                continue;
            }
            $key = $f['key'] ?? null;
            $key = $key ? preg_replace('/[^a-z0-9_]/', '', strtolower($key)) : \Illuminate\Support\Str::slug($label, '_');
            $base = $key ?: 'field';
            $n = 2;
            while (in_array($key, $used, true) || $key === '') {
                $key = $base . '_' . $n++;
            }
            $used[] = $key;
            $options = $type === 'select'
                ? array_values(array_filter(array_map(fn ($o) => trim((string) $o), (array) ($f['options'] ?? []))))
                : [];
            $out[] = [
                'key'      => $key,
                'label'    => mb_substr($label, 0, 150),
                'type'     => $type,
                'required' => (bool) ($f['required'] ?? false),
                'options'  => $options,
                'help'     => mb_substr(trim((string) ($f['help'] ?? '')), 0, 300),
            ];
        }

        return $out;
    }

    /**
     * The public registration form URL for this school, so an admin doesn't
     * have to know/guess it to share it anywhere (site, letterhead, social).
     * The form itself is cycle-agnostic (PublicAdmissionController serves
     * whichever cycles are currently open), so this is the same URL for
     * every cycle — it's on the cycle purely so the UI has somewhere to
     * show it next to "Open for registration".
     */
    public function getRegistrationUrlAttribute(): string
    {
        $subdomain = \App\Support\TenantUrl::currentSubdomain();
        $rootDomain = parse_url(env('FRONTEND_URL', 'https://compasse.net'), PHP_URL_HOST) ?: 'compasse.net';
        $base = $subdomain ? "https://{$subdomain}.{$rootDomain}" : "https://{$rootDomain}";

        return "{$base}/apply";
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function exam(): HasOne
    {
        return $this->hasOne(AdmissionExam::class);
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class);
    }

    /**
     * Whether the public registration form should currently accept applications.
     */
    public function isOpen(): bool
    {
        if ($this->status !== 'open') {
            return false;
        }
        $now = now();
        if ($this->opens_at && $now->lt($this->opens_at)) {
            return false;
        }
        if ($this->closes_at && $now->gt($this->closes_at)) {
            return false;
        }

        return true;
    }
}

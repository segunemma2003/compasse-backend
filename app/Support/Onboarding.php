<?php

namespace App\Support;

use App\Jobs\SendEmailJob;
use App\Models\Applicant;
use App\Models\School;
use App\Models\Term;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * A school's onboarding of new families: a sequence of emails it writes itself, each sent once at
 * its moment — when the application arrives, when a place is offered (with the admission letter),
 * when the child is enrolled (starter pack: handbook, prospectus…), days before resumption, and
 * after the first week. Steps can be switched off, reworded, retimed, or added to.
 */
class Onboarding
{
    public const TRIGGERS = [
        'application_received' => 'When the application is received',
        'admission_offered'    => 'When a place is offered (approved)',
        'enrolled'             => 'When the child is enrolled (after payment)',
        'before_resumption'    => 'Days before resumption',
        'after_resumption'     => 'Days after resumption',
    ];

    /** Onboarding started on this day; families enrolled earlier never get the timed emails. */
    public const LAUNCHED = '2026-10-21';

    public const PLACEHOLDERS = [
        '{parent_name}' => "Parent's name", '{child_name}' => "Child's full name", '{child_first_name}' => "Child's first name",
        '{school_name}' => 'School name', '{class_name}' => 'Class', '{session}' => 'Session (e.g. 2027/2028)',
        '{resumption_date}' => 'Resumption date', '{teacher_name}' => "Class teacher's name", '{teacher_email}' => "Class teacher's email",
        '{status_url}' => 'Application status / exam link', '{portal_url}' => 'Portal sign-in link',
        '{login_email}' => "Child's portal login", '{password}' => "Child's first password",
        '{school_phone}' => 'School phone', '{school_email}' => 'School email', '{application_number}' => 'Application number',
    ];

    /** The built-in sequence a school starts from. */
    public static function defaults(): array
    {
        return [
            ['key' => 'welcome', 'name' => 'Welcome & Registration', 'trigger' => 'application_received', 'offset_days' => 0, 'attach_letter' => false,
                'subject' => 'Welcome to {school_name} — we have received your application',
                'body' => "Dear {parent_name},\n\nThank you for choosing {school_name} for {child_first_name}. We have received the application for {class_name} and we are delighted that you are considering joining our school family.\n\nWhat happens next:\n1. Our admissions team reviews the application.\n2. If an assessment is needed, we will invite {child_first_name} for it.\n3. We will write to you with our decision.\n\nYou can check the application at any time here: {status_url}\n\nIf you have any questions, simply reply to this email or call us on {school_phone}.\n\nWarm regards,\nAdmissions Team\n{school_name}"],
            ['key' => 'offer', 'name' => 'Admission Offer (with admission letter)', 'trigger' => 'admission_offered', 'offset_days' => 0, 'attach_letter' => true,
                'subject' => 'Admission offer for {child_first_name} — {school_name}',
                'body' => "Dear {parent_name},\n\nCongratulations! We are pleased to offer {child_name} a place in {class_name} for the {session} session. Your admission letter is attached.\n\nTo accept the offer, please complete the fee payment stated in the letter. Once payment is confirmed, we will send your starter pack with everything you need for resumption on {resumption_date}.\n\nWe look forward to welcoming {child_first_name}.\n\nWarm regards,\nAdmissions Team\n{school_name}"],
            ['key' => 'confirmed', 'name' => 'Admission Confirmed + Starter Pack', 'trigger' => 'enrolled', 'offset_days' => 0, 'attach_letter' => false,
                'subject' => "{child_first_name}'s admission is confirmed — welcome to {school_name}!",
                'body' => "Dear {parent_name},\n\nThank you — {child_name}'s admission to {class_name} is now confirmed. Welcome to the {school_name} family!\n\nAttached you will find our starter pack (parent handbook and prospectus). Please take a few minutes to read through it.\n\nParent portal: {portal_url}\nYou will receive your own sign-in details in a separate email.\n\nResumption date: {resumption_date}\n\nWe will be in touch again before the first day with everything you need.\n\nWarm regards,\n{school_name}"],
            ['key' => 'meet_teacher', 'name' => 'Meet Your Teacher', 'trigger' => 'before_resumption', 'offset_days' => 7, 'attach_letter' => false,
                'subject' => "Meet {child_first_name}'s teacher at {school_name}",
                'body' => "Dear {parent_name},\n\nWith one week to go, we would love to introduce {child_first_name}'s class teacher, {teacher_name}, who will be looking after {class_name} this term.\n\n{teacher_name} is looking forward to meeting {child_first_name} and getting to know your family. You can reach the class teacher through the Chats on the parent portal ({portal_url}).\n\nSee you on {resumption_date}!\n\nWarm regards,\n{school_name}"],
            ['key' => 'first_day', 'name' => 'First Day Reminder', 'trigger' => 'before_resumption', 'offset_days' => 3, 'attach_letter' => false,
                'subject' => "{child_first_name}'s first day is on {resumption_date}",
                'body' => "Dear {parent_name},\n\nJust a friendly reminder that {child_first_name}'s first day at {school_name} is {resumption_date}.\n\nA few things to prepare:\n• School uniform and a named water bottle\n• A spare change of clothes (for younger children)\n• Any medication with a completed medicine form\n\nPlease arrive a little early on the first day so we can welcome you both. Our team will be at the gate to help.\n\nWarm regards,\n{school_name}"],
            ['key' => 'first_week', 'name' => 'How Did the First Week Go?', 'trigger' => 'after_resumption', 'offset_days' => 7, 'attach_letter' => false,
                'subject' => "How did {child_first_name}'s first week go?",
                'body' => "Dear {parent_name},\n\n{child_first_name} has completed the first week at {school_name} — well done to you both!\n\nWe would love to hear how it went. Is there anything that is going really well, or anything we can help with? Simply reply to this email or message {teacher_name} on the parent portal ({portal_url}).\n\nThank you for partnering with us.\n\nWarm regards,\n{school_name}"],
        ];
    }

    public const DEFAULT_LETTER = "We are pleased to inform you that {child_name} has been offered admission into {class_name} at {school_name} for the {session} academic session.\n\nResumption date: {resumption_date}\n\nTo accept this offer, please pay the school fees for the first term and return the acceptance form (or confirm by email) within two weeks of this letter. The offer may lapse if not accepted within this time.\n\nOn resumption, please bring: the child's birth certificate, two passport photographs and immunisation records.\n\nWe congratulate you and look forward to a happy and fruitful partnership in {child_first_name}'s education.";

    /** The school's steps, created from the defaults the first time. */
    public static function steps()
    {
        if (! Schema::hasTable('onboarding_steps')) {
            return collect();
        }
        if (! DB::table('onboarding_steps')->exists()) {
            self::seedDefaults();
        }

        return DB::table('onboarding_steps')->orderBy('sort')->orderBy('id')->get()->map(function ($s) {
            $s->document_ids = $s->document_ids ? json_decode($s->document_ids, true) : [];
            $s->enabled = (bool) $s->enabled;
            $s->attach_letter = (bool) $s->attach_letter;

            return $s;
        });
    }

    public static function seedDefaults(): void
    {
        $now = now();
        foreach (self::defaults() as $i => $d) {
            DB::table('onboarding_steps')->insert($d + ['enabled' => true, 'sort' => $i, 'document_ids' => json_encode([]), 'created_at' => $now, 'updated_at' => $now]);
        }
        // The starter pack carries whatever handbook / prospectus is uploaded.
        self::attachKindsToStarterPack();
    }

    /** New handbook / prospectus uploads go on the starter-pack email automatically. */
    public static function attachKindsToStarterPack(): void
    {
        $ids = DB::table('onboarding_documents')->whereIn('kind', ['handbook', 'prospectus'])->pluck('id')->all();
        $step = DB::table('onboarding_steps')->where('key', 'confirmed')->first();
        if ($step && $ids) {
            $current = $step->document_ids ? json_decode($step->document_ids, true) : [];
            DB::table('onboarding_steps')->where('id', $step->id)->update(['document_ids' => json_encode(array_values(array_unique(array_merge($current, $ids))))]);
        }
    }

    public static function resumptionDate(Applicant $a): ?Carbon
    {
        if ($a->resumption_date) {
            return Carbon::parse($a->resumption_date);
        }
        $cycle = $a->relationLoaded('cycle') ? $a->cycle : $a->cycle()->first();
        if ($cycle?->resumption_date) {
            return Carbon::parse($cycle->resumption_date);
        }
        // Next term to start (or the current term's start if it is still ahead).
        $term = Schema::hasTable('terms') ? Term::whereDate('start_date', '>=', now()->subDays(14)->toDateString())->orderBy('start_date')->first() : null;

        return $term?->start_date ? Carbon::parse($term->start_date) : null;
    }

    public static function values(Applicant $a, array $extra = []): array
    {
        $school = School::first();
        $a->loadMissing(['cycle', 'class']);
        $teacher = null;
        if ($a->class_id && Schema::hasTable('teachers')) {
            $tid = ClassTeachers::teacherIdsFor((int) $a->class_id)[0] ?? null;
            $teacher = $tid ? DB::table('teachers')->leftJoin('users', 'users.id', '=', 'teachers.user_id')->where('teachers.id', $tid)
                ->first(['teachers.first_name', 'teachers.last_name', 'teachers.title', 'users.email']) : null;
        }
        $resume = self::resumptionDate($a);
        $session = null;
        if (Schema::hasTable('academic_years')) {
            $session = $a->cycle?->academic_year_id ? DB::table('academic_years')->where('id', $a->cycle->academic_year_id)->value('name') : null;
            $session ??= DB::table('academic_years')->where('is_current', true)->value('name');
        }
        $sub = null;
        try {
            $sub = tenant()?->subdomain;
        } catch (\Throwable) {
        }
        $root = config('app.root_domain', 'compasse.net');

        return array_merge([
            '{parent_name}' => trim((string) $a->parent_name) ?: 'Parent/Guardian',
            '{child_name}' => trim("{$a->first_name} {$a->last_name}"),
            '{child_first_name}' => (string) $a->first_name,
            '{school_name}' => $school?->name ?? 'our school',
            '{class_name}' => $a->class?->name ?? 'the class',
            '{session}' => $session ?: 'coming',
            '{resumption_date}' => $resume ? $resume->format('l, j F Y') : 'the resumption date (to be confirmed)',
            '{teacher_name}' => $teacher ? trim(($teacher->title ? $teacher->title . ' ' : '') . "{$teacher->first_name} {$teacher->last_name}") : 'the class teacher',
            '{teacher_email}' => $teacher?->email ?? ($school?->email ?? ''),
            '{status_url}' => $sub ? "https://{$sub}.{$root}/apply/status/{$a->access_token}" : '',
            '{portal_url}' => $sub ? "https://{$sub}.{$root}/login" : '',
            '{login_email}' => '(sent separately)',
            '{password}' => '(sent separately)',
            '{school_phone}' => $school?->phone ?? '',
            '{school_email}' => $school?->email ?? '',
            '{application_number}' => 'APP-' . str_pad((string) $a->id, 5, '0', STR_PAD_LEFT),
        ], $extra);
    }

    /** [subject, html body] for a step and family. */
    public static function render(object $step, Applicant $a, array $extra = []): array
    {
        $v = self::values($a, $extra);
        $subject = strtr((string) $step->subject, $v);
        $text = strtr((string) $step->body, $v);
        $color = SchoolColor::primary();
        $school = School::first();
        $logo = FinanceDocument::logoSrc($school);
        $logoTag = str_starts_with($logo, 'data:') ? '' : '<img src="' . e($logo) . '" alt="" style="height:56px;max-width:180px;object-fit:contain;margin-bottom:8px">';
        // Links clickable, line breaks kept.
        $bodyHtml = nl2br(preg_replace('~(https?://[^\s<]+)~', '<a href="$1" style="color:' . $color . '">$1</a>', e($text)));
        if ($step->trigger === 'application_received' && $v['{status_url}'] && ! str_contains((string) $step->body, '{status_url}')) {
            $bodyHtml .= '<p>Check the application: <a href="' . e($v['{status_url}']) . '" style="color:' . $color . '">' . e($v['{status_url}']) . '</a></p>';
        }
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;color:#222">'
            . '<div style="border-top:5px solid ' . $color . ';padding:20px 24px 4px">' . $logoTag
            . '<div style="font-size:18px;font-weight:bold;color:' . $color . '">' . e($school?->name ?? '') . '</div></div>'
            . '<div style="padding:8px 24px 24px;font-size:15px;line-height:1.6">' . $bodyHtml . '</div>'
            . '<div style="padding:12px 24px;background:#f6f6f8;font-size:12px;color:#666">' . e(trim(($school?->address ?? '') . ' · ' . ($school?->phone ?? ''), ' ·')) . '</div></div>';

        return [$subject, $html];
    }

    /** Recipients: the parent's email, else the applicant's own. */
    public static function recipient(Applicant $a): ?string
    {
        foreach ([$a->parent_email, $a->email] as $e) {
            if ($e && EmailAddress::isReal($e) && ! EmailAddress::isPlaceholder($e)) {
                return $e;
            }
        }

        return null;
    }

    /**
     * Send one step to one family — once (unless $force, for "send again"). Returns what happened:
     * sent | already_sent | no_email | disabled.
     */
    public static function send(object $step, Applicant $a, array $extra = [], bool $force = false, ?int $by = null): string
    {
        if (! $step->enabled && ! $force) {
            return 'disabled';
        }
        $already = DB::table('onboarding_sends')->where('applicant_id', $a->id)->where('step_id', $step->id)->exists();
        if ($already && ! $force) {
            return 'already_sent';
        }
        $to = self::recipient($a);
        if (! $to) {
            return 'no_email';
        }
        [$subject, $html] = self::render($step, $a, $extra);

        $attachments = [];
        if (! empty($step->attach_letter)) {
            try {
                $attachments[] = ['name' => 'Admission-letter-' . preg_replace('/[^A-Za-z0-9]+/', '-', trim("{$a->first_name} {$a->last_name}")) . '.pdf',
                    'data' => base64_encode(FeeDocuments::pdf(self::letterHtml($a))), 'mime' => 'application/pdf'];
            } catch (\Throwable $e) {
                Log::error('Admission letter PDF failed', ['applicant' => $a->id, 'error' => $e->getMessage()]);
            }
        }
        $docIds = is_array($step->document_ids) ? $step->document_ids : (json_decode((string) $step->document_ids, true) ?: []);
        foreach (DB::table('onboarding_documents')->whereIn('id', $docIds ?: [0])->get() as $d) {
            if (Storage::disk('local')->exists($d->path)) {
                $attachments[] = ['name' => $d->file_name, 'data' => base64_encode(Storage::disk('local')->get($d->path)), 'mime' => $d->mime ?: 'application/octet-stream'];
            }
        }

        SendEmailJob::dispatch($to, $subject, $html, [], [], (string) (School::first()?->id ?? ''), true, 'onboarding', $attachments);
        DB::table('onboarding_sends')->updateOrInsert(
            ['applicant_id' => $a->id, 'step_id' => $step->id],
            ['sent_to' => $to, 'status' => 'sent', 'sent_by' => $by, 'sent_at' => now()],
        );

        return 'sent';
    }

    /** Every enabled step for an event (application received / offered / enrolled). */
    public static function fire(string $trigger, Applicant $a, array $extra = []): array
    {
        $out = [];
        foreach (self::steps()->where('trigger', $trigger)->where('enabled', true) as $step) {
            $out[$step->name] = self::send($step, $a, $extra);
        }

        return $out;
    }

    /** When a timed step is due for this family (null if it isn't a timed step or there's no resumption date). */
    public static function dueDate(object $step, Applicant $a): ?Carbon
    {
        if (! in_array($step->trigger, ['before_resumption', 'after_resumption'], true)) {
            return null;
        }
        $r = self::resumptionDate($a);
        if (! $r) {
            return null;
        }

        return $step->trigger === 'before_resumption' ? $r->copy()->subDays((int) $step->offset_days) : $r->copy()->addDays((int) $step->offset_days);
    }

    /**
     * Daily: send the timed steps that are due to enrolled families. A step more than a week late
     * (e.g. a child enrolled after resumption) is not sent — it would no longer make sense.
     */
    public static function sendDue(): int
    {
        $n = 0;
        $steps = self::steps()->whereIn('trigger', ['before_resumption', 'after_resumption'])->where('enabled', true);
        if ($steps->isEmpty()) {
            return 0;
        }
        $today = now()->startOfDay();
        // Only children enrolled through this flow: those admitted before it existed are already in school.
        $query = Applicant::with(['cycle', 'class'])->where('status', 'enrolled')
            ->where(fn ($q) => $q->where('enrolled_at', '>=', self::LAUNCHED)->orWhereNotNull('resumption_date'));
        foreach ($query->get() as $a) {
            foreach ($steps as $step) {
                $due = self::dueDate($step, $a);
                if (! $due || $today->lt($due->copy()->startOfDay()) || $today->gt($due->copy()->addDays(7))) {
                    continue;
                }
                if (self::send($step, $a) === 'sent') {
                    $n++;
                }
            }
        }

        return $n;
    }

    // ── Documents: admission letter and the application form ──────────────

    private static function letterhead(?School $school, string $title): string
    {
        $color = SchoolColor::primary();
        $logo = e(FinanceDocument::logoSrc($school));
        $contact = e(implode('  ·  ', array_filter([$school?->address, $school?->phone, $school?->email, $school?->website])));

        return '<table style="width:100%;border-bottom:3px solid ' . $color . ';margin-bottom:18px"><tr>'
            . '<td style="width:90px;vertical-align:middle"><img src="' . $logo . '" style="height:72px;max-width:90px"></td>'
            . '<td style="vertical-align:middle;padding-left:12px"><div style="font-size:22px;font-weight:bold;color:' . $color . '">' . e($school?->name ?? '') . '</div>'
            . '<div style="font-size:11px;color:#555;margin-top:4px">' . $contact . '</div></td></tr></table>'
            . '<div style="text-align:center;font-size:17px;font-weight:bold;letter-spacing:2px;color:' . $color . ';margin:6px 0 18px">' . e($title) . '</div>';
    }

    private static function page(string $title, string $inner): string
    {
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . e($title) . '</title><style>'
            . 'body{font-family:"DejaVu Serif",Georgia,serif;font-size:13px;color:#222;padding:28px 34px;line-height:1.55}'
            . 'table.grid{width:100%;border-collapse:collapse;margin-bottom:14px}table.grid td{border:1px solid #ccc;padding:5px 8px;vertical-align:top}'
            . 'table.grid td.l{width:34%;background:#f5f5f7;font-weight:bold;font-size:12px}h3{font-size:13px;margin:16px 0 6px;text-transform:uppercase;letter-spacing:1px}'
            . '@media print{body{padding:0}}</style></head><body>' . $inner . '</body></html>';
    }

    public static function letterHtml(Applicant $a): string
    {
        $school = School::first();
        $v = self::values($a);
        $body = (string) ($school?->settings['admission_letter_body'] ?? '') ?: self::DEFAULT_LETTER;
        $sig = '';
        if ($school) {
            $sigs = \App\Models\SchoolSignature::activeForSchool($school->id);
            $s = $sigs['principal'] ?? $sigs['head_teacher'] ?? $sigs['director'] ?? $sigs->first();
            if ($s) {
                $img = $s->signature_url ? '<img src="' . e($s->signature_url) . '" style="max-height:60px;max-width:180px"><br>' : '';
                $sig = $img . '<strong>' . e($s->name) . '</strong><br>' . e(ucwords(str_replace('_', ' ', (string) $s->role)));
            }
        }
        $sig = $sig ?: '<div style="border-bottom:1px solid #333;width:200px;height:50px"></div>For: ' . e($school?->name ?? '') . ' Management';

        $inner = self::letterhead($school, 'LETTER OF ADMISSION')
            . '<p style="text-align:right">' . now()->format('j F Y') . '<br>Ref: ' . e($v['{application_number}']) . '</p>'
            . '<p>' . e($v['{parent_name}']) . '<br>Parent/Guardian of ' . e($v['{child_name}']) . '</p>'
            . '<p>Dear ' . e($v['{parent_name}']) . ',</p>'
            . '<p><strong>OFFER OF ADMISSION — ' . e(mb_strtoupper($v['{child_name}'])) . '</strong></p>'
            . '<p>' . nl2br(e(strtr($body, $v))) . '</p>'
            . '<p>Yours faithfully,</p><div style="margin-top:10px">' . $sig . '</div>';

        return SchoolColor::apply(self::page('Admission letter — ' . $v['{child_name}'], $inner));
    }

    /** The whole submitted application, as a printable / downloadable form. */
    public static function applicationFormHtml(Applicant $a): string
    {
        $school = School::first();
        $a->loadMissing(['cycle', 'class']);
        $row = fn ($l, $v) => '<tr><td class="l">' . e($l) . '</td><td>' . nl2br(e((string) ($v ?? '—') ?: '—')) . '</td></tr>';
        $inner = self::letterhead($school, 'ADMISSION APPLICATION FORM')
            . '<p style="text-align:right;font-size:12px">Application No. <strong>APP-' . str_pad((string) $a->id, 5, '0', STR_PAD_LEFT) . '</strong><br>Submitted ' . $a->created_at?->format('j F Y, g:ia') . '<br>Status: <strong>' . e(ucfirst(str_replace('_', ' ', (string) $a->status))) . '</strong></p>'
            . '<h3>Child</h3><table class="grid">' . $row('Full name', trim("{$a->first_name} {$a->last_name}")) . $row('Date of birth', $a->date_of_birth?->format('j F Y'))
            . $row('Gender', $a->gender ? ucfirst($a->gender) : null) . $row('Class applied for', $a->class?->name) . $row('Previous school', $a->previous_school)
            . $row('Email', $a->email) . $row('Phone', $a->phone) . $row('Admission cycle', $a->cycle?->name) . '</table>'
            . '<h3>Parent / Guardian</h3><table class="grid">' . $row('Name', $a->parent_name) . $row('Phone', $a->parent_phone) . $row('Email', $a->parent_email) . '</table>';

        $fields = $a->cycle?->form_fields ?? [];
        if ($fields) {
            $inner .= '<h3>Further information</h3><table class="grid">';
            foreach ($fields as $f) {
                $val = $f['type'] === 'file'
                    ? (isset($a->documents[$f['key']]) ? 'Uploaded: ' . ($a->documents[$f['key']]['name'] ?? 'document') : 'Not uploaded')
                    : $a->custom_fields[$f['key']] ?? null;
                if (is_array($val)) {
                    $val = implode(', ', $val);
                } elseif (is_bool($val)) {
                    $val = $val ? 'Yes' : 'No';
                }
                $inner .= $row($f['label'] ?? $f['key'], $val);
            }
            $inner .= '</table>';
        }
        if ($a->exam_score !== null || $a->decision_notes) {
            $inner .= '<h3>School use</h3><table class="grid">' . ($a->exam_score !== null ? $row('Entrance exam score', $a->exam_score) : '')
                . ($a->decision_notes ? $row('Notes', $a->decision_notes) : '') . '</table>';
        }

        return SchoolColor::apply(self::page('Application — ' . trim("{$a->first_name} {$a->last_name}"), $inner));
    }
}

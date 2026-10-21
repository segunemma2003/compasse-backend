<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use App\Models\School;
use App\Support\Onboarding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The school's onboarding set-up (Admissions → Onboarding): its sequence of emails to new families,
 * the documents they carry (handbook, prospectus…), the admission letter wording — and, per family,
 * which emails went out and which are still to come.
 */
class OnboardingController extends Controller
{
    /** GET /admissions/onboarding */
    public function index(): JsonResponse
    {
        $school = School::first();

        return response()->json([
            'steps' => Onboarding::steps()->values(),
            'documents' => DB::table('onboarding_documents')->orderBy('kind')->orderBy('title')->get(['id', 'title', 'kind', 'file_name', 'mime', 'size', 'created_at']),
            'triggers' => Onboarding::TRIGGERS,
            'placeholders' => Onboarding::PLACEHOLDERS,
            'letter_body' => (string) ($school?->settings['admission_letter_body'] ?? '') ?: Onboarding::DEFAULT_LETTER,
            'default_letter' => Onboarding::DEFAULT_LETTER,
        ]);
    }

    private function stepRules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => "{$req}|string|max:150",
            'trigger' => "{$req}|in:" . implode(',', array_keys(Onboarding::TRIGGERS)),
            'offset_days' => 'nullable|integer|min:0|max:120',
            'subject' => "{$req}|string|max:255",
            'body' => "{$req}|string|max:20000",
            'document_ids' => 'nullable|array',
            'document_ids.*' => 'integer',
            'attach_letter' => 'nullable|boolean',
            'enabled' => 'nullable|boolean',
        ];
    }

    private function stepRow(array $v): array
    {
        $row = array_intersect_key($v, array_flip(['name', 'trigger', 'offset_days', 'subject', 'body', 'attach_letter', 'enabled']));
        if (array_key_exists('document_ids', $v)) {
            $row['document_ids'] = json_encode(array_values(array_map('intval', $v['document_ids'] ?? [])));
        }

        return $row + ['updated_at' => now()];
    }

    /** POST /admissions/onboarding/steps — a step of the school's own. */
    public function storeStep(Request $request): JsonResponse
    {
        $v = $request->validate($this->stepRules(true));
        Onboarding::steps();   // make sure the defaults exist first
        $id = DB::table('onboarding_steps')->insertGetId($this->stepRow($v) + [
            'enabled' => $v['enabled'] ?? true, 'offset_days' => $v['offset_days'] ?? 0, 'document_ids' => json_encode($v['document_ids'] ?? []),
            'sort' => (int) DB::table('onboarding_steps')->max('sort') + 1, 'created_at' => now(),
        ]);

        return response()->json(['message' => 'Email added', 'id' => $id], 201);
    }

    /** PUT /admissions/onboarding/steps/{id} */
    public function updateStep(Request $request, int $id): JsonResponse
    {
        $v = $request->validate($this->stepRules(false));
        DB::table('onboarding_steps')->where('id', $id)->update($this->stepRow($v));

        return response()->json(['message' => 'Saved']);
    }

    /** DELETE /admissions/onboarding/steps/{id} */
    public function destroyStep(int $id): JsonResponse
    {
        DB::table('onboarding_steps')->where('id', $id)->delete();
        DB::table('onboarding_sends')->where('step_id', $id)->delete();

        return response()->json(['message' => 'Removed']);
    }

    /** POST /admissions/onboarding/reset — back to the standard sequence (sent history kept). */
    public function reset(): JsonResponse
    {
        DB::table('onboarding_steps')->delete();
        Onboarding::seedDefaults();

        return response()->json(['message' => 'Back to the standard emails']);
    }

    /** PUT /admissions/onboarding/letter {body} — the admission letter's wording. */
    public function saveLetter(Request $request): JsonResponse
    {
        $v = $request->validate(['body' => 'nullable|string|max:10000']);
        $school = School::first();
        if (! $school) {
            return response()->json(['error' => 'School not found'], 404);
        }
        $school->settings = array_merge($school->settings ?? [], ['admission_letter_body' => trim((string) ($v['body'] ?? ''))]);
        $school->save();

        return response()->json(['message' => 'Admission letter saved']);
    }

    /** POST /admissions/onboarding/documents {file, title, kind} — handbook, prospectus, … */
    public function uploadDocument(Request $request): JsonResponse
    {
        $v = $request->validate([
            'file' => 'required|file|max:10240|mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png',
            'title' => 'nullable|string|max:150',
            'kind' => 'nullable|in:handbook,prospectus,other',
        ]);
        $file = $request->file('file');
        $kind = $v['kind'] ?? 'other';
        $path = $file->store('onboarding', 'local');
        $id = DB::table('onboarding_documents')->insertGetId([
            'title' => $v['title'] ?: ($kind === 'other' ? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) : ucfirst($kind)),
            'kind' => $kind, 'path' => $path, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'uploaded_by' => $request->user()?->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if (in_array($kind, ['handbook', 'prospectus'], true)) {
            Onboarding::steps();
            Onboarding::attachKindsToStarterPack();
        }

        return response()->json(['message' => 'Uploaded', 'id' => $id], 201);
    }

    /** GET /admissions/onboarding/documents/{id} */
    public function downloadDocument(int $id)
    {
        $d = DB::table('onboarding_documents')->find($id);
        if (! $d || ! Storage::disk('local')->exists($d->path)) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return Storage::disk('local')->download($d->path, $d->file_name);
    }

    /** DELETE /admissions/onboarding/documents/{id} — also taken off every email it was attached to. */
    public function destroyDocument(int $id): JsonResponse
    {
        $d = DB::table('onboarding_documents')->find($id);
        if ($d) {
            Storage::disk('local')->delete($d->path);
            DB::table('onboarding_documents')->where('id', $id)->delete();
            foreach (DB::table('onboarding_steps')->get(['id', 'document_ids']) as $s) {
                $ids = array_values(array_filter(json_decode((string) $s->document_ids, true) ?: [], fn ($x) => (int) $x !== $id));
                DB::table('onboarding_steps')->where('id', $s->id)->update(['document_ids' => json_encode($ids)]);
            }
        }

        return response()->json(['message' => 'Deleted']);
    }

    /** POST /admissions/onboarding/steps/{id}/preview {applicant_id?} — subject and email as a family would see it. */
    public function preview(Request $request, int $id): JsonResponse
    {
        $step = Onboarding::steps()->firstWhere('id', $id);
        if (! $step) {
            return response()->json(['error' => 'Not found'], 404);
        }
        foreach (['subject', 'body'] as $k) {
            if ($request->filled($k)) {
                $step->{$k} = $request->input($k);   // preview unsaved edits
            }
        }
        $a = $request->filled('applicant_id') ? Applicant::find($request->applicant_id) : Applicant::orderByDesc('id')->first();
        $a ??= new Applicant(['first_name' => 'Amina', 'last_name' => 'Bello', 'parent_name' => 'Mrs Bello', 'parent_email' => 'parent@example.com']);
        [$subject, $html] = Onboarding::render($step, $a);

        return response()->json(['subject' => $subject, 'html' => $html, 'sample' => trim("{$a->first_name} {$a->last_name}")]);
    }

    /** GET /admissions/applicants/{id}/onboarding — each email: sent (when, to whom), due, or not applicable yet. */
    public function timeline(int $id): JsonResponse
    {
        $a = Applicant::with(['cycle', 'class'])->findOrFail($id);
        $sends = DB::table('onboarding_sends')->where('applicant_id', $id)->get()->keyBy('step_id');
        $reached = [
            'application_received' => true,
            'admission_offered' => in_array($a->status, ['approved', 'enrolled'], true),
            'enrolled' => $a->status === 'enrolled',
            'before_resumption' => $a->status === 'enrolled',
            'after_resumption' => $a->status === 'enrolled',
        ];
        $rows = Onboarding::steps()->map(function ($s) use ($a, $sends, $reached) {
            $sent = $sends[$s->id] ?? null;
            $due = Onboarding::dueDate($s, $a);

            return [
                'step_id' => $s->id, 'name' => $s->name, 'trigger' => $s->trigger, 'enabled' => $s->enabled,
                'sent_at' => $sent?->sent_at, 'sent_to' => $sent?->sent_to,
                'due_date' => $due?->toDateString(),
                'state' => $sent ? 'sent' : (! $s->enabled ? 'off' : ($reached[$s->trigger] ? ($due && $due->isFuture() ? 'scheduled' : 'due') : 'waiting')),
            ];
        })->values();

        return response()->json([
            'timeline' => $rows,
            'resumption_date' => Onboarding::resumptionDate($a)?->toDateString(),
            'recipient' => Onboarding::recipient($a),
        ]);
    }

    /** POST /admissions/applicants/{id}/onboarding/{stepId}/send — send (or send again) now. */
    public function sendNow(Request $request, int $id, int $stepId): JsonResponse
    {
        $a = Applicant::with(['cycle', 'class'])->findOrFail($id);
        $step = Onboarding::steps()->firstWhere('id', $stepId);
        if (! $step) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $r = Onboarding::send($step, $a, [], true, $request->user()?->id);

        return $r === 'sent'
            ? response()->json(['message' => "Sent to " . Onboarding::recipient($a)])
            : response()->json(['error' => $r === 'no_email' ? 'There is no parent email on this application.' : 'Not sent.'], 422);
    }
}

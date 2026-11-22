<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Support\StudentDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Transcript and letter of recommendation for any student (school staff who can see the student). */
class StudentDocumentController extends Controller
{
    private function student(Request $request, int $id): Student|JsonResponse
    {
        $student = Student::withTrashed()->find($id);
        if (! $student) {
            return response()->json(['error' => 'Student not found'], 404);
        }
        if (! $this->studentWithinScope($request->user(), (int) $student->id)) {
            return $this->forbiddenResponse('You do not have access to this student.');
        }

        return $student;
    }

    /** GET /students/{id}/transcript?published_only=1 — printable HTML. */
    public function transcript(Request $request, int $id): Response|JsonResponse
    {
        $s = $this->student($request, $id);
        if ($s instanceof JsonResponse) {
            return $s;
        }

        return response(StudentDocuments::transcriptHtml($s, $request->boolean('published_only')), 200)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /** GET /students/{id}/recommendation-letter/template — the default text with its placeholders. */
    public function recommendationTemplate(Request $request, int $id): JsonResponse
    {
        $s = $this->student($request, $id);
        if ($s instanceof JsonResponse) {
            return $s;
        }
        $saved = (string) (\Illuminate\Support\Facades\DB::table('settings')->where('key', 'recommendation_letter_text')->value('value') ?? '');

        return response()->json(['text' => $saved !== '' ? $saved : StudentDocuments::DEFAULT_LETTER,
            'placeholders' => array_keys(StudentDocuments::values($s, \App\Models\School::first()))]);
    }

    /** POST /students/{id}/recommendation-letter {text?, addressee?, save_as_default?} — printable HTML. */
    public function recommendation(Request $request, int $id): Response|JsonResponse
    {
        $s = $this->student($request, $id);
        if ($s instanceof JsonResponse) {
            return $s;
        }
        $v = $request->validate(['text' => 'nullable|string|max:8000', 'addressee' => 'nullable|string|max:500', 'save_as_default' => 'nullable|boolean']);
        if ($request->boolean('save_as_default') && filled($v['text'] ?? null)) {
            \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'recommendation_letter_text'], ['value' => $v['text'], 'updated_at' => now(), 'created_at' => now()]);
        }
        \App\Support\AuditLog::record('student', $s->id, 'recommendation_letter', 'Letter of recommendation printed for ' . trim("{$s->first_name} {$s->last_name}"));

        return response(StudentDocuments::recommendationHtml($s, $v['text'] ?? null, $v['addressee'] ?? null), 200)->header('Content-Type', 'text/html; charset=utf-8');
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** My Notes: each student's own notes — nobody else can read them. */
class StudentNoteController extends Controller
{
    private function me(Request $request): int
    {
        return (int) ($request->user() ?: auth()->user())->id;
    }

    public function index(Request $request): JsonResponse
    {
        $q = DB::table('student_notes')->where('user_id', $this->me($request));
        if ($request->filled('q')) {
            $term = '%' . $request->q . '%';
            $q->where(fn ($w) => $w->where('title', 'like', $term)->orWhere('body', 'like', $term)->orWhere('subject', 'like', $term));
        }
        if ($request->filled('subject')) {
            $q->where('subject', $request->subject);
        }

        return response()->json([
            'notes' => $q->orderByDesc('pinned')->orderByDesc('updated_at')->limit(500)->get(),
            'subjects' => DB::table('student_notes')->where('user_id', $this->me($request))->whereNotNull('subject')->distinct()->orderBy('subject')->pluck('subject'),
        ]);
    }

    private function data(Request $request): array
    {
        return $request->validate([
            'title' => 'nullable|string|max:200', 'body' => 'nullable|string|max:100000', 'subject' => 'nullable|string|max:120',
            'color' => 'nullable|string|max:20', 'pinned' => 'nullable|boolean',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $id = DB::table('student_notes')->insertGetId($this->data($request) + ['user_id' => $this->me($request), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Saved', 'note' => DB::table('student_notes')->find($id)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $n = DB::table('student_notes')->where('id', $id)->where('user_id', $this->me($request));
        if (! $n->exists()) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $n->update($this->data($request) + ['updated_at' => now()]);

        return response()->json(['message' => 'Saved', 'note' => DB::table('student_notes')->find($id)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        DB::table('student_notes')->where('id', $id)->where('user_id', $this->me($request))->delete();

        return response()->json(['message' => 'Deleted']);
    }
}

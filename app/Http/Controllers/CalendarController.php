<?php

namespace App\Http\Controllers;

use App\Models\Calendar;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CalendarController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    public function index(Request $request): JsonResponse
    {
        $query = Calendar::where('school_id', $this->school($request)?->id ?? 0);

        if ($request->filled('type'))             $query->where('type', $request->type);
        if ($request->filled('academic_year_id')) $query->where('academic_year_id', $request->academic_year_id);
        if ($request->filled('date_from'))        $query->whereDate('date', '>=', $request->date_from);
        if ($request->filled('date_to'))          $query->whereDate('date', '<=', $request->date_to);
        if ($request->filled('month')) {
            $query->whereMonth('date', $request->month);
            if ($request->filled('year')) $query->whereYear('date', $request->year);
        }
        if ($request->filled('search')) {
            $query->where('title', 'like', '%' . $request->search . '%');
        }

        return response()->json([
            'entries' => $query->with('academicYear')->orderBy('date')->paginate($request->get('per_page', 50)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'            => 'required|string|max:200',
            'description'      => 'nullable|string',
            'date'             => 'required|date',
            'end_date'         => 'nullable|date|after_or_equal:date',
            'type'             => 'required|in:holiday,exam,event,term_start,term_end,other',
            'color'            => 'nullable|string|max:20',
            'is_recurring'     => 'boolean',
            'recurrence_rule'  => 'nullable|string',
            'academic_year_id' => 'nullable|exists:academic_years,id',
        ]);

        $entry = Calendar::create(array_merge($data, ['school_id' => $this->school($request)?->id]));

        return response()->json(['message' => 'Calendar entry created', 'entry' => $entry], 201);
    }

    public function show(string $id): JsonResponse
    {
        $entry = Calendar::with('academicYear')->findOrFail($id);
        return response()->json(['entry' => $entry]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $entry = Calendar::findOrFail($id);
        $data  = $request->validate([
            'title'            => 'sometimes|string|max:200',
            'description'      => 'nullable|string',
            'date'             => 'sometimes|date',
            'end_date'         => 'nullable|date',
            'type'             => 'sometimes|in:holiday,exam,event,term_start,term_end,other',
            'color'            => 'nullable|string|max:20',
            'is_recurring'     => 'boolean',
            'recurrence_rule'  => 'nullable|string',
            'academic_year_id' => 'nullable|exists:academic_years,id',
        ]);
        $entry->update($data);
        return response()->json(['message' => 'Calendar entry updated', 'entry' => $entry]);
    }

    public function destroy(string $id): JsonResponse
    {
        Calendar::findOrFail($id)->delete();
        return response()->json(['message' => 'Calendar entry deleted']);
    }
}

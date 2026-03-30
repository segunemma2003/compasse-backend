<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ExpenseController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    public function index(Request $request): JsonResponse
    {
        $query = Expense::where('school_id', $this->school($request)?->id ?? 0)
            ->with(['approvedBy', 'recordedBy']);

        if ($request->filled('category')) $query->where('category', $request->category);
        if ($request->filled('status'))   $query->where('status', $request->status);
        if ($request->filled('date_from'))$query->whereDate('date', '>=', $request->date_from);
        if ($request->filled('date_to'))  $query->whereDate('date', '<=', $request->date_to);
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('description', 'like', "%$s%")
                                      ->orWhere('vendor',      'like', "%$s%"));
        }

        return response()->json(
            $query->orderByDesc('date')->paginate($request->get('per_page', 15))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $school = $this->school($request);
        if (!$school) {
            return response()->json(['error' => 'School context not found'], 400);
        }

        $data = $request->validate([
            'description'    => 'required|string|max:500',
            'amount'         => 'required|numeric|min:0',
            'category'       => 'required|string|max:100',
            'date'           => 'required|date',
            'payment_method' => 'nullable|in:cash,bank_transfer,cheque,card',
            'vendor'         => 'nullable|string|max:200',
            'receipt_number' => 'nullable|string|max:100',
            'notes'          => 'nullable|string',
            'status'         => 'nullable|in:pending,approved,rejected,paid',
        ]);

        $data['school_id']  = $school->id;
        $data['recorded_by']= auth()->id();
        $data['status']     = $data['status'] ?? 'pending';

        $expense = Expense::create($data);

        return response()->json([
            'message' => 'Expense recorded successfully',
            'expense' => $expense->load(['recordedBy']),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $expense = Expense::with(['approvedBy', 'recordedBy', 'school'])->findOrFail($id);
        return response()->json(['expense' => $expense]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $expense = Expense::findOrFail($id);

        $data = $request->validate([
            'description'    => 'sometimes|string|max:500',
            'amount'         => 'sometimes|numeric|min:0',
            'category'       => 'sometimes|string|max:100',
            'date'           => 'sometimes|date',
            'payment_method' => 'nullable|in:cash,bank_transfer,cheque,card',
            'vendor'         => 'nullable|string|max:200',
            'receipt_number' => 'nullable|string|max:100',
            'notes'          => 'nullable|string',
            'status'         => 'sometimes|in:pending,approved,rejected,paid',
        ]);

        if (isset($data['status']) && $data['status'] === 'approved') {
            $data['approved_by'] = auth()->id();
        }

        $expense->update($data);
        return response()->json([
            'message' => 'Expense updated successfully',
            'expense' => $expense->load(['approvedBy', 'recordedBy']),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $expense = Expense::findOrFail($id);

        if ($expense->status === 'paid') {
            return response()->json(['error' => 'Cannot delete a paid expense.'], 422);
        }

        $expense->delete();
        return response()->json(['message' => 'Expense deleted successfully']);
    }
}

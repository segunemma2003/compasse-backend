<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('expenses');

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        $expenses = $query->orderBy('date', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json($expenses);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'category' => 'required|string|max:100',
            'date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $expenseId = DB::table('expenses')->insertGetId([
            'school_id' => $request->school_id ?? 1,
            'description' => $request->description,
            'amount' => $request->amount,
            'category' => $request->category,
            'date' => $request->date,
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $expense = DB::table('expenses')->find($expenseId);

        return response()->json([
            'message' => 'Expense created successfully',
            'expense' => $expense
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id): JsonResponse
    {
        $expense = DB::table('expenses')->find($id);

        if (!$expense) {
            return response()->json(['error' => 'Expense not found'], 404);
        }

        return response()->json(['expense' => $expense]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $expense = DB::table('expenses')->find($id);

        if (!$expense) {
            return response()->json(['error' => 'Expense not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'description' => 'sometimes|string|max:255',
            'amount' => 'sometimes|numeric|min:0',
            'category' => 'sometimes|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        DB::table('expenses')
            ->where('id', $id)
            ->update(array_merge(
                $request->only(['description', 'amount', 'category']),
                ['updated_at' => now()]
            ));

        $expense = DB::table('expenses')->find($id);

        return response()->json([
            'message' => 'Expense updated successfully',
            'expense' => $expense
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id): JsonResponse
    {
        $expense = DB::table('expenses')->find($id);

        if (!$expense) {
            return response()->json(['error' => 'Expense not found'], 404);
        }

        DB::table('expenses')->where('id', $id)->delete();

        return response()->json([
            'message' => 'Expense deleted successfully'
        ]);
    }
}

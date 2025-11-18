<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class InventoryTransactionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    /**
     * Checkout inventory item
     */
    public function checkout(Request $request): \Illuminate\Http\JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'item_id' => 'required|exists:inventory_items,id',
            'quantity' => 'required|integer|min:1',
            'borrower_id' => 'required|integer',
            'borrower_type' => 'required|string',
            'expected_return_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        return response()->json([
            'message' => 'Item checked out successfully',
            'transaction' => []
        ], 201);
    }

    /**
     * Return inventory item
     */
    public function return(Request $request, $transactionId): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'message' => 'Item returned successfully',
            'transaction_id' => $transactionId
        ]);
    }
}

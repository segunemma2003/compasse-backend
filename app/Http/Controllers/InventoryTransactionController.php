<?php

namespace App\Http\Controllers;

use App\Models\InventoryTransaction;
use App\Models\InventoryItem;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InventoryTransactionController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    public function index(Request $request): JsonResponse
    {
        $query = InventoryTransaction::where('school_id', $this->school($request)?->id ?? 0);

        if ($request->filled('type'))    $query->where('type', $request->type);
        if ($request->filled('status'))  $query->where('status', $request->status);
        if ($request->filled('item_id')) $query->where('item_id', $request->item_id);

        return response()->json([
            'transactions' => $query->with(['item', 'recordedBy'])
                                    ->orderByDesc('created_at')
                                    ->paginate($request->get('per_page', 15)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id'              => 'required|exists:inventory_items,id',
            'type'                 => 'required|in:purchase,adjustment,disposal',
            'quantity'             => 'required|integer|min:1',
            'purpose'              => 'nullable|string|max:500',
            'notes'                => 'nullable|string',
        ]);

        $item = InventoryItem::findOrFail($data['item_id']);

        $transaction = DB::transaction(function () use ($data, $item, $request) {
            if ($data['type'] === 'disposal') {
                if ($item->quantity < $data['quantity']) {
                    throw new \InvalidArgumentException('Insufficient quantity for disposal.');
                }
                $item->decrement('quantity', $data['quantity']);
            } else {
                $item->increment('quantity', $data['quantity']);
            }

            return InventoryTransaction::create(array_merge($data, [
                'school_id'          => $item->school_id,
                'remaining_quantity' => $item->fresh()->quantity,
                'recorded_by'        => auth()->id(),
                'status'             => 'completed',
            ]));
        });

        return response()->json(['message' => 'Transaction recorded', 'transaction' => $transaction->load('item')], 201);
    }

    public function show(string $id): JsonResponse
    {
        $transaction = InventoryTransaction::with(['item', 'recordedBy'])->findOrFail($id);
        return response()->json(['transaction' => $transaction]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $transaction = InventoryTransaction::findOrFail($id);
        $data = $request->validate([
            'purpose' => 'nullable|string|max:500',
            'notes'   => 'nullable|string',
        ]);
        $transaction->update($data);
        return response()->json(['message' => 'Transaction updated', 'transaction' => $transaction]);
    }

    public function destroy(string $id): JsonResponse
    {
        InventoryTransaction::findOrFail($id)->delete();
        return response()->json(['message' => 'Transaction deleted']);
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id'              => 'required|exists:inventory_items,id',
            'quantity'             => 'required|integer|min:1',
            'borrower_id'          => 'required|integer',
            'borrower_type'        => 'required|string|in:App\Models\Student,App\Models\Staff,App\Models\Teacher',
            'borrower_name'        => 'nullable|string|max:200',
            'purpose'              => 'nullable|string|max:500',
            'expected_return_date' => 'nullable|date|after_or_equal:today',
            'notes'                => 'nullable|string',
        ]);

        $item = InventoryItem::findOrFail($data['item_id']);

        if ($item->quantity < $data['quantity']) {
            return response()->json(['error' => 'Insufficient quantity available.'], 422);
        }

        $transaction = DB::transaction(function () use ($data, $item) {
            $item->decrement('quantity', $data['quantity']);

            return InventoryTransaction::create(array_merge($data, [
                'school_id'          => $item->school_id,
                'type'               => 'checkout',
                'remaining_quantity' => $item->fresh()->quantity,
                'recorded_by'        => auth()->id(),
                'status'             => 'checked_out',
            ]));
        });

        return response()->json(['message' => 'Item checked out', 'transaction' => $transaction->load('item')], 201);
    }

    public function returnItem(Request $request, string $transactionId): JsonResponse
    {
        $transaction = InventoryTransaction::with('item')->findOrFail($transactionId);

        if ($transaction->type !== 'checkout' || $transaction->status !== 'checked_out') {
            return response()->json(['error' => 'This transaction is not an open checkout.'], 422);
        }

        $request->validate(['notes' => 'nullable|string']);

        DB::transaction(function () use ($transaction, $request) {
            $transaction->item->increment('quantity', $transaction->quantity);
            $transaction->update([
                'returned_at'        => now(),
                'status'             => 'returned',
                'remaining_quantity' => $transaction->item->fresh()->quantity,
                'notes'              => $request->notes ?? $transaction->notes,
            ]);
        });

        return response()->json(['message' => 'Item returned successfully', 'transaction' => $transaction]);
    }
}

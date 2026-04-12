<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class InventoryItemController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    public function index(Request $request): JsonResponse
    {
        $query = InventoryItem::where('school_id', $this->school($request)?->id ?? 0);

        if ($request->filled('status'))      $query->where('status', $request->status);
        if ($request->filled('category_id')) $query->where('category_id', $request->category_id);
        if ($request->boolean('low_stock'))  $query->whereColumn('quantity', '<=', 'min_quantity');
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('name', 'like', "%$s%")
                                      ->orWhere('sku',  'like', "%$s%"));
        }

        return response()->json(
            $query->with('category')->orderBy('name')->paginate($request->get('per_page', 15))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id'  => 'nullable|exists:inventory_categories,id',
            'name'         => 'required|string|max:200',
            'description'  => 'nullable|string',
            'sku'          => 'nullable|string|max:50|unique:inventory_items,sku',
            'quantity'     => 'required|integer|min:0',
            'unit'         => 'nullable|string|max:30',
            'min_quantity' => 'nullable|integer|min:0',
            'unit_price'   => 'nullable|numeric|min:0',
            'location'     => 'nullable|string|max:200',
            'supplier'     => 'nullable|string|max:200',
            'status'       => 'nullable|in:active,inactive,discontinued',
        ]);

        $item = InventoryItem::create(array_merge($data, ['school_id' => $this->school($request)?->id]));

        return response()->json(['message' => 'Item created', 'item' => $item->load('category')], 201);
    }

    public function show(string $id): JsonResponse
    {
        $item = InventoryItem::with(['category', 'transactions'])->findOrFail($id);
        return response()->json(['item' => $item, 'is_low_stock' => $item->isLowStock()]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $item = InventoryItem::findOrFail($id);
        $data = $request->validate([
            'category_id'  => 'nullable|exists:inventory_categories,id',
            'name'         => 'sometimes|string|max:200',
            'description'  => 'nullable|string',
            'sku'          => 'nullable|string|max:50|unique:inventory_items,sku,' . $id,
            'unit'         => 'nullable|string|max:30',
            'min_quantity' => 'nullable|integer|min:0',
            'unit_price'   => 'nullable|numeric|min:0',
            'location'     => 'nullable|string|max:200',
            'supplier'     => 'nullable|string|max:200',
            'status'       => 'nullable|in:active,inactive,discontinued',
        ]);
        $item->update($data);
        return response()->json(['message' => 'Item updated', 'item' => $item]);
    }

    public function destroy(string $id): JsonResponse
    {
        $item = InventoryItem::withCount('transactions')->findOrFail($id);
        if ($item->transactions_count > 0) {
            return response()->json(['error' => 'Cannot delete an item with transaction history.'], 422);
        }
        $item->delete();
        return response()->json(['message' => 'Item deleted']);
    }
}

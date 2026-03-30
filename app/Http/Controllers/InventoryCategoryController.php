<?php

namespace App\Http\Controllers;

use App\Models\InventoryCategory;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class InventoryCategoryController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    public function index(Request $request): JsonResponse
    {
        $query = InventoryCategory::where('school_id', $this->school($request)?->id ?? 0);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where('name', 'like', "%$s%");
        }

        return response()->json([
            'categories' => $query->withCount('items')->orderBy('name')->paginate($request->get('per_page', 50)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:150',
            'description' => 'nullable|string',
            'color'       => 'nullable|string|max:20',
        ]);

        $category = InventoryCategory::create(array_merge($data, ['school_id' => $this->school($request)?->id]));

        return response()->json(['message' => 'Category created', 'category' => $category], 201);
    }

    public function show(string $id): JsonResponse
    {
        $category = InventoryCategory::withCount('items')->findOrFail($id);
        return response()->json(['category' => $category]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $category = InventoryCategory::findOrFail($id);
        $data = $request->validate([
            'name'        => 'sometimes|string|max:150',
            'description' => 'nullable|string',
            'color'       => 'nullable|string|max:20',
        ]);
        $category->update($data);
        return response()->json(['message' => 'Category updated', 'category' => $category]);
    }

    public function destroy(string $id): JsonResponse
    {
        $category = InventoryCategory::withCount('items')->findOrFail($id);
        if ($category->items_count > 0) {
            return response()->json(['error' => 'Cannot delete a category with items. Reassign items first.'], 422);
        }
        $category->delete();
        return response()->json(['message' => 'Category deleted']);
    }
}

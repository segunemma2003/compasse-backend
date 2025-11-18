<?php

namespace App\Http\Controllers;

use App\Models\LibraryBook;
use App\Models\LibraryBorrow;
use App\Models\LibraryCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class LibraryController extends Controller
{
    /**
     * List books
     */
    public function getBooks(Request $request): JsonResponse
    {
        try {
            $query = LibraryBook::with(['category', 'subcategory']);

            if ($request->has('category_id')) {
                $query->where('category_id', $request->category_id);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                      ->orWhere('author', 'like', "%{$search}%")
                      ->orWhere('isbn', 'like', "%{$search}%");
                });
            }

            if ($request->has('available_only') && $request->available_only) {
                $query->where('available_copies', '>', 0);
            }

            $books = $query->paginate($request->get('per_page', 15));

            return response()->json($books);
        } catch (\Exception $e) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'per_page' => 15,
                'total' => 0
            ]);
        }
    }

    /**
     * Get book details
     */
    public function getBook($id): JsonResponse
    {
        $book = LibraryBook::with(['category', 'subcategory', 'reviews'])->find($id);

        if (!$book) {
            return response()->json(['error' => 'Book not found'], 404);
        }

        return response()->json(['book' => $book]);
    }

    /**
     * Add book
     */
    public function addBook(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'isbn' => 'nullable|string|max:20',
            'title' => 'required|string|max:255',
            'author' => 'required|string|max:255',
            'publisher' => 'nullable|string|max:255',
            'publication_year' => 'nullable|integer',
            'category_id' => 'nullable|exists:library_categories,id',
            'total_copies' => 'required|integer|min:0',
            'price' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'is_digital' => 'nullable|boolean',
            'digital_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $book = LibraryBook::create([
            'school_id' => $request->school_id ?? 1,
            'isbn' => $request->isbn,
            'title' => $request->title,
            'author' => $request->author,
            'publisher' => $request->publisher,
            'publication_year' => $request->publication_year,
            'category_id' => $request->category_id,
            'total_copies' => $request->total_copies,
            'available_copies' => $request->total_copies,
            'price' => $request->price,
            'description' => $request->description,
            'is_digital' => $request->is_digital ?? false,
            'digital_url' => $request->digital_url,
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Book added successfully',
            'book' => $book
        ], 201);
    }

    /**
     * Update book
     */
    public function updateBook(Request $request, $id): JsonResponse
    {
        $book = LibraryBook::find($id);

        if (!$book) {
            return response()->json(['error' => 'Book not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'author' => 'sometimes|string|max:255',
            'total_copies' => 'sometimes|integer|min:0',
            'available_copies' => 'sometimes|integer|min:0',
            'status' => 'sometimes|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $book->update($request->only([
            'title', 'author', 'publisher', 'publication_year',
            'total_copies', 'available_copies', 'price', 'description', 'status'
        ]));

        return response()->json([
            'message' => 'Book updated successfully',
            'book' => $book->fresh()
        ]);
    }

    /**
     * Delete book
     */
    public function deleteBook($id): JsonResponse
    {
        $book = LibraryBook::find($id);

        if (!$book) {
            return response()->json(['error' => 'Book not found'], 404);
        }

        // Check if book has active borrows
        $activeBorrows = LibraryBorrow::where('book_id', $id)
            ->where('status', 'borrowed')
            ->exists();

        if ($activeBorrows) {
            return response()->json([
                'error' => 'Cannot delete book',
                'message' => 'Book has active borrows. Please return all copies first.'
            ], 422);
        }

        $book->delete();

        return response()->json([
            'message' => 'Book deleted successfully'
        ]);
    }

    /**
     * List borrowed books
     */
    public function getBorrowed(Request $request): JsonResponse
    {
        $query = LibraryBorrow::with(['book', 'borrower']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('student_id')) {
            $query->where('borrower_id', $request->student_id)
                  ->where('borrower_type', 'App\Models\Student');
        }

        $borrows = $query->orderBy('borrowed_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json($borrows);
    }

    /**
     * Borrow book
     */
    public function borrow(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'book_id' => 'required|exists:library_books,id',
            'student_id' => 'required|exists:students,id',
            'due_date' => 'required|date|after:today',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $book = LibraryBook::find($request->book_id);

        if (!$book->isAvailable()) {
            return response()->json([
                'error' => 'Book not available',
                'message' => 'No copies available for borrowing'
            ], 422);
        }

        $borrow = LibraryBorrow::create([
            'school_id' => $request->school_id ?? 1,
            'book_id' => $request->book_id,
            'borrower_id' => $request->student_id,
            'borrower_type' => 'App\Models\Student',
            'borrowed_at' => now(),
            'due_date' => $request->due_date,
            'status' => 'borrowed',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        $book->decrement('available_copies');

        return response()->json([
            'message' => 'Book borrowed successfully',
            'borrow' => $borrow
        ], 201);
    }

    /**
     * Return book
     */
    public function returnBook(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'borrow_id' => 'required|exists:library_borrows,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $borrow = LibraryBorrow::find($request->borrow_id);

        if ($borrow->status === 'returned') {
            return response()->json([
                'error' => 'Book already returned'
            ], 422);
        }

        $borrow->update([
            'status' => 'returned',
            'returned_at' => now(),
        ]);

        $borrow->book->increment('available_copies');

        // Calculate fine if overdue
        if ($borrow->isOverdue()) {
            $fine = $borrow->calculateFine();
            $borrow->update(['fine_amount' => $fine]);
        }

        return response()->json([
            'message' => 'Book returned successfully',
            'borrow' => $borrow->fresh(),
            'fine_amount' => $borrow->fine_amount ?? 0
        ]);
    }

    /**
     * Get digital resources
     */
    public function getDigitalResources(Request $request): JsonResponse
    {
        $books = LibraryBook::where('is_digital', true)
            ->whereNotNull('digital_url')
            ->paginate($request->get('per_page', 15));

        return response()->json($books);
    }

    /**
     * Add digital resource
     */
    public function addDigitalResource(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'author' => 'required|string|max:255',
            'digital_url' => 'required|url',
            'category_id' => 'nullable|exists:library_categories,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $book = LibraryBook::create([
            'school_id' => $request->school_id ?? 1,
            'title' => $request->title,
            'author' => $request->author,
            'category_id' => $request->category_id,
            'is_digital' => true,
            'digital_url' => $request->digital_url,
            'total_copies' => 999, // Unlimited for digital
            'available_copies' => 999,
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Digital resource added successfully',
            'book' => $book
        ], 201);
    }

    /**
     * Get library members
     */
    public function getMembers(Request $request): JsonResponse
    {
        // Get all students who have borrowed books
        $memberIds = LibraryBorrow::distinct()
            ->where('borrower_type', 'App\Models\Student')
            ->pluck('borrower_id');

        $members = \App\Models\Student::whereIn('id', $memberIds)
            ->with('user')
            ->paginate($request->get('per_page', 15));

        return response()->json($members);
    }

    /**
     * Get library statistics
     */
    public function getStats(): JsonResponse
    {
        try {
            $totalBooks = LibraryBook::count();
            $totalBorrows = LibraryBorrow::where('status', 'borrowed')->count();
            $overdueBorrows = LibraryBorrow::where('status', 'borrowed')
                ->where('due_date', '<', now())
                ->count();
            $totalMembers = LibraryBorrow::distinct('borrower_id')->count('borrower_id');

            return response()->json([
                'total_books' => $totalBooks,
                'total_borrows' => $totalBorrows,
                'overdue_borrows' => $overdueBorrows,
                'total_members' => $totalMembers,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'total_books' => 0,
                'total_borrows' => 0,
                'overdue_borrows' => 0,
                'total_members' => 0,
            ]);
        }
    }
}

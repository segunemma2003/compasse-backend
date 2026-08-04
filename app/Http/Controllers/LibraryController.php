<?php

namespace App\Http\Controllers;

use App\Models\LibraryBook;
use App\Models\LibraryBorrow;
use App\Models\LibraryBookRequest;
use App\Models\LibraryCategory;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class LibraryController extends Controller
{
    /**
     * List books
     */
    public function getBooks(Request $request): JsonResponse
    {
        try {
            $query = LibraryBook::query();
            if (Schema::hasTable('library_categories')) {
                $query->with(['category', 'subcategory']);
            }

            if ($request->has('category_id')) {
                $query->where('category_id', $request->category_id);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                      ->orWhere('author', 'like', "%{$search}%")
                      ->orWhere('isbn', 'like', "%{$search}%");
                });
            }

            if ($request->boolean('available_only')) {
                $query->where('available_copies', '>', 0)
                    ->where(function ($q) {
                        $q->where('is_digital', true)
                            ->orWhereIn('status', ['active', 'available']);
                    });
            }

            if ($request->boolean('physical_only')) {
                $query->where(function ($q) {
                    $q->where('is_digital', false)->orWhereNull('is_digital');
                });
            }

            $books = $query
                ->orderBy('title')
                ->paginate(min((int) $request->get('per_page', 15), 100));

            $books->getCollection()->transform(function ($book) {
                $catName = $book->category?->name;
                if (! $catName && Schema::hasColumn('library_books', 'category')) {
                    $catName = $book->getAttributes()['category'] ?? null;
                }

                return array_merge($book->toArray(), [
                    'category_name' => $catName,
                    'location'        => $book->location ?? $book->shelf_number,
                ]);
            });

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

        $schoolId = $this->getSchoolIdFromTenant($request) ?? 1;
        $categoryId = $this->resolveCategoryId($request, $schoolId);

        $book = LibraryBook::create([
            'school_id' => $schoolId,
            'isbn' => $request->isbn,
            'title' => $request->title,
            'author' => $request->author,
            'publisher' => $request->publisher,
            'publication_year' => $request->publication_year,
            'category_id' => $categoryId,
            'total_copies' => $request->total_copies,
            'available_copies' => $request->total_copies,
            'price' => $request->price,
            'description' => $request->description,
            'location' => $request->location ?? $request->shelf_location ?? $request->shelf_number,
            'shelf_number' => $request->shelf_number ?? $request->shelf_location,
            'is_digital' => $request->is_digital ?? false,
            'digital_url' => $request->digital_url,
            'status' => 'available',
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
            'category_id' => 'nullable|exists:library_categories,id',
            'category' => 'nullable|string|max:100',
            'total_copies' => 'sometimes|integer|min:0',
            'available_copies' => 'sometimes|integer|min:0',
            'location' => 'nullable|string|max:100',
            'shelf_location' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:active,available,unavailable,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $schoolId = $this->getSchoolIdFromTenant($request) ?? $book->school_id ?? 1;
        $categoryId = $request->has('category_id') || $request->has('category')
            ? $this->resolveCategoryId($request, (int) $schoolId)
            : $book->category_id;

        $book->update(array_filter([
            'title' => $request->input('title'),
            'author' => $request->input('author'),
            'publisher' => $request->input('publisher'),
            'publication_year' => $request->input('publication_year'),
            'category_id' => $categoryId,
            'total_copies' => $request->input('total_copies'),
            'available_copies' => $request->input('available_copies'),
            'price' => $request->input('price'),
            'description' => $request->input('description'),
            'location' => $request->input('location') ?? $request->input('shelf_location'),
            'shelf_number' => $request->input('shelf_number') ?? $request->input('shelf_location'),
            'status' => $request->input('status'),
        ], fn ($v) => $v !== null));

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

        $user = Auth::user();
        if ($user?->role === 'student') {
            $studentId = $user->student?->id;
            if ($studentId) {
                $query->where('borrower_id', $studentId)
                    ->where('borrower_type', 'App\Models\Student');
            } else {
                return response()->json(['data' => [], 'total' => 0]);
            }
        } elseif ($request->has('student_id')) {
            $query->where('borrower_id', $request->student_id)
                  ->where('borrower_type', 'App\Models\Student');
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $borrows = $query->orderBy('borrowed_at', 'desc')
            ->paginate(min((int) $request->get('per_page', 15), 50));

        $borrows->getCollection()->transform(function ($row) {
            $name = '—';
            $borrower = $row->borrower;
            if ($borrower instanceof \App\Models\Student) {
                $name = trim("{$borrower->first_name} {$borrower->last_name}");
            } elseif ($borrower instanceof \App\Models\User) {
                $name = $borrower->name ?? '—';
            }

            return [
                'id'           => $row->id,
                'book_id'      => $row->book_id,
                'book_title'   => $row->book?->title ?? '—',
                'student_name' => $name,
                'student_id'   => (string) $row->borrower_id,
                'borrowed_at'  => $row->borrowed_at,
                'due_date'     => $row->due_date,
                'returned_at'  => $row->returned_at,
                'status'       => $row->status,
            ];
        });

        return response()->json($borrows);
    }

    /**
     * Borrow book
     */
    public function borrow(Request $request): JsonResponse
    {
        $user = Auth::user();
        if ($user?->role === 'student') {
            return response()->json([
                'error' => 'Use library book requests',
                'message' => 'Submit a borrow request — a librarian will approve and issue the physical book.',
            ], 422);
        }

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

        if (! $book->isAvailable()) {
            return response()->json([
                'error' => 'Book not available',
                'message' => 'No copies available for borrowing',
            ], 422);
        }

        $book->decrement('available_copies');

        $borrow = LibraryBorrow::create([
            'school_id' => $request->school_id ?? $book->school_id ?? 1,
            'book_id' => $request->book_id,
            'borrower_id' => $request->student_id,
            'borrower_type' => 'App\Models\Student',
            'borrowed_at' => now(),
            'due_date' => $request->due_date,
            'status' => 'borrowed',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

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
     * Mark a borrow record as lost
     */
    public function markLost(Request $request): JsonResponse
    {
        $request->validate(['borrow_id' => 'required|exists:library_borrows,id']);

        $borrow = LibraryBorrow::find($request->borrow_id);

        if (in_array($borrow->status, ['returned', 'lost'])) {
            return response()->json(['error' => 'Borrow record is already closed.'], 422);
        }

        $borrow->update(['status' => 'lost', 'returned_at' => now()]);

        return response()->json([
            'message' => 'Book marked as lost',
            'borrow'  => $borrow->fresh(),
        ]);
    }

    public function downloadDigitalResource(int $id): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $book = LibraryBook::where('is_digital', true)->find($id);
        if (! $book || ! $book->digital_url) {
            return response()->json(['error' => 'Digital resource not found'], 404);
        }

        $url = $book->digital_url;
        if (str_starts_with($url, '/storage/') || str_starts_with($url, 'storage/')) {
            $path = public_path(ltrim(parse_url($url, PHP_URL_PATH) ?? $url, '/'));
            if (is_file($path)) {
                return response()->download($path, \Illuminate\Support\Str::slug($book->title) . '.' . pathinfo($path, PATHINFO_EXTENSION));
            }
        }

        return response()->json([
            'download_url' => $url,
            'title'        => $book->title,
            'external'     => true,
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

    // ── Categories ────────────────────────────────────────────────────────

    public function listCategories(Request $request): JsonResponse
    {
        if (! Schema::hasTable('library_categories')) {
            return response()->json(['categories' => []]);
        }

        $schoolId = $this->getSchoolIdFromTenant($request) ?? 1;
        $categories = LibraryCategory::query()
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'parent_id']);

        return response()->json(['categories' => $categories]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        if (! Schema::hasTable('library_categories')) {
            return response()->json(['error' => 'Categories not available — run tenant migrations'], 503);
        }

        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:120',
            'description' => 'nullable|string',
            'parent_id'   => 'nullable|exists:library_categories,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $schoolId = $this->getSchoolIdFromTenant($request) ?? 1;
        $category = LibraryCategory::create([
            'school_id'   => $schoolId,
            'name'        => $request->name,
            'slug'        => \Illuminate\Support\Str::slug($request->name),
            'description' => $request->description,
            'parent_id'   => $request->parent_id,
            'is_active'   => true,
        ]);

        return response()->json(['message' => 'Category created', 'category' => $category], 201);
    }

    public function updateCategory(Request $request, int $id): JsonResponse
    {
        $category = LibraryCategory::find($id);
        if (! $category) {
            return response()->json(['error' => 'Category not found'], 404);
        }

        $category->update($request->only(['name', 'description', 'parent_id', 'is_active']));
        if ($request->filled('name')) {
            $category->update(['slug' => \Illuminate\Support\Str::slug($request->name)]);
        }

        return response()->json(['message' => 'Category updated', 'category' => $category->fresh()]);
    }

    public function deleteCategory(int $id): JsonResponse
    {
        $category = LibraryCategory::find($id);
        if (! $category) {
            return response()->json(['error' => 'Category not found'], 404);
        }

        LibraryBook::where('category_id', $id)->update(['category_id' => null]);
        $category->delete();

        return response()->json(['message' => 'Category deleted']);
    }

    // ── Borrow requests (physical books) ──────────────────────────────────

    public function listRequests(Request $request): JsonResponse
    {
        if (! Schema::hasTable('library_book_requests')) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $user = Auth::user();
        $query = LibraryBookRequest::with(['book:id,title,author', 'student:id,first_name,last_name,admission_number'])
            ->orderByDesc('created_at');

        $isStaff = in_array($user?->role, ['librarian', 'admin', 'school_admin', 'principal', 'vice_principal'], true);
        if (! $isStaff) {
            $studentId = $this->ownStudentId($user);
            if (! $studentId) {
                return response()->json(['data' => [], 'total' => 0]);
            }
            $query->where('student_id', $studentId);
        } elseif ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $rows = $query->paginate(min((int) $request->get('per_page', 30), 100));
        $rows->getCollection()->transform(function (LibraryBookRequest $row) {
            $student = $row->student;

            return [
                'id'                 => $row->id,
                'book_id'            => $row->book_id,
                'book_title'         => $row->book?->title,
                'student_id'         => $row->student_id,
                'student_name'       => $student ? trim("{$student->first_name} {$student->last_name}") : '—',
                'admission_number'   => $student?->admission_number,
                'status'             => $row->status,
                'requested_due_date' => $row->requested_due_date,
                'student_note'       => $row->student_note,
                'librarian_note'     => $row->librarian_note,
                'created_at'         => $row->created_at,
            ];
        });

        return response()->json($rows);
    }

    public function storeRequest(Request $request): JsonResponse
    {
        if (! Schema::hasTable('library_book_requests')) {
            return response()->json(['error' => 'Requests not available — run tenant migrations'], 503);
        }

        $user = Auth::user();
        $studentId = $this->ownStudentId($user);
        if (! $studentId) {
            return response()->json(['error' => 'Only students can request physical books'], 403);
        }

        $validator = Validator::make($request->all(), [
            'book_id'              => 'required|exists:library_books,id',
            'requested_due_date'   => 'nullable|date|after:today',
            'student_note'         => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $book = LibraryBook::find($request->book_id);
        if ($book?->is_digital) {
            return response()->json(['error' => 'Digital items do not require approval — use download'], 422);
        }
        if (! $book?->isAvailable()) {
            return response()->json(['error' => 'No copies available to request'], 422);
        }

        $pending = LibraryBookRequest::where('student_id', $studentId)
            ->where('book_id', $book->id)
            ->where('status', 'pending')
            ->exists();
        if ($pending) {
            return response()->json(['error' => 'You already have a pending request for this book'], 422);
        }

        $schoolId = $this->getSchoolIdFromTenant($request) ?? $book->school_id ?? 1;
        $row = LibraryBookRequest::create([
            'school_id'            => $schoolId,
            'book_id'              => $book->id,
            'student_id'           => $studentId,
            'status'               => 'pending',
            'requested_due_date'   => $request->requested_due_date ?? now()->addDays(14)->toDateString(),
            'student_note'         => $request->student_note,
        ]);

        return response()->json(['message' => 'Request submitted for librarian approval', 'request' => $row], 201);
    }

    public function approveRequest(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->librarianStaffDenied()) {
            return $deny;
        }

        $row = LibraryBookRequest::with('book')->find($id);
        if (! $row || $row->status !== 'pending') {
            return response()->json(['error' => 'Request not found or already processed'], 404);
        }

        $validator = Validator::make($request->all(), [
            'due_date'        => 'required|date|after:today',
            'librarian_note'  => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        // Lock the book row for the duration of the transaction so two
        // librarians approving requests for the last copy at the same time
        // can't both pass the availability check before either decrements.
        try {
            $borrow = \Illuminate\Support\Facades\DB::transaction(function () use ($row, $request) {
                $book = LibraryBook::whereKey($row->book_id)->lockForUpdate()->first();
                if (! $book || ! $book->isAvailable()) {
                    throw new \RuntimeException('Book no longer available');
                }

                $book->decrement('available_copies');

                $borrow = LibraryBorrow::create([
                    'school_id'     => $row->school_id,
                    'book_id'       => $row->book_id,
                    'borrower_id'   => $row->student_id,
                    'borrower_type' => Student::class,
                    'borrowed_at'   => now(),
                    'due_date'      => $request->due_date,
                    'status'        => 'borrowed',
                    'approved_by'   => auth()->id(),
                    'approved_at'   => now(),
                ]);

                $row->update([
                    'status'             => 'approved',
                    'reviewed_by'        => auth()->id(),
                    'reviewed_at'        => now(),
                    'librarian_note'     => $request->librarian_note,
                    'library_borrow_id'  => $borrow->id,
                ]);

                return $borrow;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Request approved — book issued', 'borrow' => $borrow]);
    }

    public function rejectRequest(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->librarianStaffDenied()) {
            return $deny;
        }

        $row = LibraryBookRequest::find($id);
        if (! $row || $row->status !== 'pending') {
            return response()->json(['error' => 'Request not found or already processed'], 404);
        }

        $row->update([
            'status'         => 'rejected',
            'reviewed_by'    => auth()->id(),
            'reviewed_at'    => now(),
            'librarian_note' => $request->input('librarian_note'),
        ]);

        return response()->json(['message' => 'Request rejected']);
    }

    public function cancelRequest(int $id): JsonResponse
    {
        $user = Auth::user();
        $row = LibraryBookRequest::find($id);
        if (! $row || $row->status !== 'pending') {
            return response()->json(['error' => 'Request not found or already processed'], 404);
        }

        $studentId = $this->ownStudentId($user);
        if ($studentId !== (int) $row->student_id) {
            return $this->forbiddenResponse('You can only cancel your own requests.');
        }

        $row->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Request cancelled']);
    }

    private function resolveCategoryId(Request $request, int $schoolId): ?int
    {
        if ($request->filled('category_id')) {
            return (int) $request->category_id;
        }

        if (! $request->filled('category') || ! Schema::hasTable('library_categories')) {
            return null;
        }

        $name = trim($request->category);
        $existing = LibraryCategory::where('school_id', $schoolId)->where('name', $name)->first();
        if ($existing) {
            return $existing->id;
        }

        return LibraryCategory::create([
            'school_id' => $schoolId,
            'name'      => $name,
            'slug'      => \Illuminate\Support\Str::slug($name),
            'is_active' => true,
        ])->id;
    }

    private function librarianStaffDenied(): ?JsonResponse
    {
        $role = Auth::user()?->role;
        if (! in_array($role, ['librarian', 'admin', 'school_admin', 'principal', 'vice_principal'], true)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return null;
    }
}

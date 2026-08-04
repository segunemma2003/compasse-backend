<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryBookRequest extends Model
{
    protected $fillable = [
        'school_id',
        'book_id',
        'student_id',
        'status',
        'requested_due_date',
        'student_note',
        'librarian_note',
        'reviewed_by',
        'reviewed_at',
        'library_borrow_id',
    ];

    protected $casts = [
        'requested_due_date' => 'date',
        'reviewed_at'        => 'datetime',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(LibraryBook::class, 'book_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function borrow(): BelongsTo
    {
        return $this->belongsTo(LibraryBorrow::class, 'library_borrow_id');
    }
}

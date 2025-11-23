<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('library_borrows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('book_id');
            $table->morphs('borrower'); // student_id or teacher_id or user_id
            $table->date('borrowed_at');
            $table->date('due_date');
            $table->date('returned_at')->nullable();
            $table->enum('status', ['borrowed', 'returned', 'overdue'])->default('borrowed');
            $table->decimal('fine', 8, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();
            
            $table->foreign('book_id')->references('id')->on('library_books')->onDelete('cascade');
            
            $table->index('status');
            $table->index('due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('library_borrows');
    }
};

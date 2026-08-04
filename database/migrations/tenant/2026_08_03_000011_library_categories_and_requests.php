<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('library_categories')) {
            Schema::create('library_categories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->string('name');
                $table->string('slug')->nullable();
                $table->text('description')->nullable();
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['school_id', 'name']);
            });
        }

        if (! Schema::hasTable('library_book_requests')) {
            Schema::create('library_book_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->foreignId('book_id')->constrained('library_books')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
                $table->date('requested_due_date')->nullable();
                $table->text('student_note')->nullable();
                $table->text('librarian_note')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->unsignedBigInteger('library_borrow_id')->nullable();
                $table->timestamps();

                $table->index(['school_id', 'status']);
                $table->index(['student_id', 'status']);
            });
        }

        if (Schema::hasTable('library_books')) {
            Schema::table('library_books', function (Blueprint $table) {
                if (! Schema::hasColumn('library_books', 'category_id')) {
                    $table->unsignedBigInteger('category_id')->nullable()->after('publication_year');
                }
                if (! Schema::hasColumn('library_books', 'shelf_number')) {
                    $table->string('shelf_number')->nullable()->after('location');
                }
            });

            // Backfill categories from legacy string `category` column when present.
            if (Schema::hasColumn('library_books', 'category')) {
                $schoolId = DB::table('schools')->value('id') ?? 1;
                $names = DB::table('library_books')
                    ->whereNotNull('category')
                    ->where('category', '!=', '')
                    ->distinct()
                    ->pluck('category');

                foreach ($names as $name) {
                    $catId = DB::table('library_categories')->where('school_id', $schoolId)->where('name', $name)->value('id');
                    if (! $catId) {
                        $catId = DB::table('library_categories')->insertGetId([
                            'school_id'   => $schoolId,
                            'name'        => $name,
                            'slug'        => \Illuminate\Support\Str::slug($name),
                            'is_active'   => true,
                            'created_at'  => now(),
                            'updated_at'  => now(),
                        ]);
                    }
                    DB::table('library_books')
                        ->where('category', $name)
                        ->whereNull('category_id')
                        ->update(['category_id' => $catId]);
                }
            }
        }

        if (Schema::hasTable('library_borrows')) {
            Schema::table('library_borrows', function (Blueprint $table) {
                if (! Schema::hasColumn('library_borrows', 'school_id')) {
                    $table->unsignedBigInteger('school_id')->nullable()->after('id');
                }
                if (! Schema::hasColumn('library_borrows', 'approved_by')) {
                    $table->unsignedBigInteger('approved_by')->nullable()->after('status');
                }
                if (! Schema::hasColumn('library_borrows', 'approved_at')) {
                    $table->timestamp('approved_at')->nullable()->after('approved_by');
                }
                if (! Schema::hasColumn('library_borrows', 'fine_amount')) {
                    $table->decimal('fine_amount', 10, 2)->default(0)->after('returned_at');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('library_book_requests');
        Schema::dropIfExists('library_categories');
    }
};

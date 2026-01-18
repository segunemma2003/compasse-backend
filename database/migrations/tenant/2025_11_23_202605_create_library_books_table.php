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
        Schema::create('library_books', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('isbn')->nullable()->unique();
            $table->string('publisher')->nullable();
            $table->year('publication_year')->nullable();
            $table->string('category')->nullable();
            $table->integer('total_copies')->default(1);
            $table->integer('available_copies')->default(1);
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->boolean('is_digital')->default(false);
            $table->string('digital_url')->nullable();
            $table->enum('status', ['available', 'unavailable'])->default('available');
            $table->timestamps();
            
            $table->index('title');
            $table->index('author');
            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('library_books');
    }
};

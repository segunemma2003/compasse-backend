<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CBT answers were rejected on save ("is_correct cannot be null"): an answer
     * is written first and graded after, and essays stay ungraded until a
     * teacher marks them. Let both grading columns be null.
     */
    public function up(): void
    {
        if (! Schema::hasTable('answers')) {
            return;
        }
        Schema::table('answers', function (Blueprint $table) {
            $table->boolean('is_correct')->nullable()->default(null)->change();
            $table->decimal('marks_obtained', 8, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Left nullable: making them NOT NULL again would reject ungraded essays.
    }
};

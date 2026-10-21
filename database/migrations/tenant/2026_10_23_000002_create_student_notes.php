<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Students' own notes (private to each student). */
    public function up(): void
    {
        if (! Schema::hasTable('student_notes')) {
            Schema::create('student_notes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('title')->nullable();
                $t->longText('body')->nullable();
                $t->string('subject', 120)->nullable();
                $t->string('color', 20)->nullable();
                $t->boolean('pinned')->default(false);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_notes');
    }
};

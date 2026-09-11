<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A department is an organisational unit (Science, Languages, ...) that
 * spans multiple classes at once — unlike a Subject, which is naturally
 * one row per class (a different teacher usually takes JSS1 Maths vs SS1
 * Maths), duplicating "Science Department" per class would just be noise.
 * So this is a many-to-many link instead of a per-class row: check several
 * classes for one department, and it's the same department linked to all
 * of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('department_classes')) {
            Schema::create('department_classes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('department_id')->constrained()->onDelete('cascade');
                $table->foreignId('class_id')->constrained('classes')->onDelete('cascade');
                $table->timestamps();

                $table->unique(['department_id', 'class_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('department_classes');
    }
};

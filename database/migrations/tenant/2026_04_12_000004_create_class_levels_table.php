<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Create class_levels table
        if (! Schema::hasTable('class_levels')) {
            Schema::create('class_levels', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->string('name');
                $table->integer('order')->default(0);
                $table->text('description')->nullable();
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->timestamps();
                $table->index(['school_id', 'status']);
                $table->index(['school_id', 'order']);
            });
        }

        // Add class_level_id FK to classes table
        if (Schema::hasTable('classes') && ! Schema::hasColumn('classes', 'class_level_id')) {
            Schema::table('classes', function (Blueprint $table) {
                $table->foreignId('class_level_id')
                    ->nullable()
                    ->after('level')
                    ->constrained('class_levels')
                    ->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('classes') && Schema::hasColumn('classes', 'class_level_id')) {
            Schema::table('classes', function (Blueprint $table) {
                $table->dropForeign(['class_level_id']);
                $table->dropColumn('class_level_id');
            });
        }

        Schema::dropIfExists('class_levels');
    }
};

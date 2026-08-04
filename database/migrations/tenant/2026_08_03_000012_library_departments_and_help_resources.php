<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('library_books') && ! Schema::hasColumn('library_books', 'department_id')) {
            Schema::table('library_books', function (Blueprint $table) {
                $table->unsignedBigInteger('department_id')->nullable()->after('category_id');
                $table->index('department_id');
            });
        }

        if (! Schema::hasTable('library_help_resources')) {
            Schema::create('library_help_resources', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('department_id')->nullable();
                $table->string('title');
                $table->text('description')->nullable();
                $table->enum('resource_type', ['video', 'guide', 'link', 'database'])->default('guide');
                $table->string('topic')->default('general'); // apa, research, citation, general
                $table->string('url')->nullable();
                $table->string('video_embed_url')->nullable();
                $table->unsignedSmallInteger('display_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['school_id', 'topic']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('library_help_resources');
        if (Schema::hasTable('library_books') && Schema::hasColumn('library_books', 'department_id')) {
            Schema::table('library_books', function (Blueprint $table) {
                $table->dropColumn('department_id');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('houses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('color')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('house_master_id')->nullable()->constrained('teachers')->onDelete('set null');
            $table->integer('total_points')->default(0);
            $table->timestamps();
            
            $table->index(['school_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('houses');
    }
};

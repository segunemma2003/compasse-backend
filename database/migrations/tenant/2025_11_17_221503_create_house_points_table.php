<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('house_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('house_id')->constrained()->onDelete('cascade');
            $table->integer('points');
            $table->string('reason');
            $table->enum('type', ['award', 'deduction'])->default('award');
            $table->foreignId('awarded_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            
            $table->index(['house_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('house_points');
    }
};

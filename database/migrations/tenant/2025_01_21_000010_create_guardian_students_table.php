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
        Schema::create('guardian_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guardian_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->constrained()->onDelete('cascade');
            $table->string('relationship');
            $table->boolean('is_primary')->default(false);
            $table->boolean('emergency_contact')->default(false);
            $table->timestamps();
            
            $table->unique(['guardian_id', 'student_id']);
            $table->index(['guardian_id', 'is_primary']);
            $table->index(['student_id', 'is_primary']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guardian_students');
    }
};

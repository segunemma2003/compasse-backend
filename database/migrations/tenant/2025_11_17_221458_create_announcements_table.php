<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('content');
            $table->enum('type', ['general', 'academic', 'event', 'emergency'])->default('general');
            $table->enum('target_audience', ['all', 'students', 'teachers', 'parents', 'staff'])->default('all');
            $table->foreignId('class_id')->nullable()->constrained('classes')->onDelete('cascade');
            $table->datetime('publish_date')->nullable();
            $table->datetime('expiry_date')->nullable();
            $table->boolean('is_published')->default(false);
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            
            $table->index(['school_id', 'is_published']);
            $table->index(['publish_date', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};

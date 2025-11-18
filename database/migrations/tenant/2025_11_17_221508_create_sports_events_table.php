<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('activity_id')->nullable()->constrained('sports_activities')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->datetime('event_date');
            $table->string('location')->nullable();
            $table->enum('type', ['competition', 'practice', 'tournament', 'match'])->default('competition');
            $table->enum('status', ['scheduled', 'ongoing', 'completed', 'cancelled'])->default('scheduled');
            $table->timestamps();
            
            $table->index(['school_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_events');
    }
};

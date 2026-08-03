<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_meetings')) {
            Schema::create('school_meetings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('host_user_id');
                $table->enum('meeting_type', ['class_session', 'staff_meeting', 'one_on_one'])->default('one_on_one');
                $table->string('title');
                $table->text('description')->nullable();
                $table->enum('stream_provider', ['meet', 'mux'])->default('meet');
                $table->boolean('recording_required')->default(true);
                $table->string('recording_url')->nullable();
                $table->enum('recording_status', ['pending', 'processing', 'ready', 'unavailable'])->default('pending');
                $table->string('meeting_link')->nullable();
                $table->string('meeting_id')->nullable();
                $table->string('meeting_password')->nullable();
                $table->string('mux_live_stream_id')->nullable();
                $table->string('mux_playback_id')->nullable();
                $table->string('mux_stream_key')->nullable();
                $table->string('mux_rtmp_url')->nullable();
                $table->unsignedBigInteger('teacher_id')->nullable();
                $table->unsignedBigInteger('class_id')->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->dateTime('start_time');
                $table->dateTime('end_time')->nullable();
                $table->unsignedSmallInteger('duration_minutes')->default(60);
                $table->enum('status', ['provisioning', 'scheduled', 'active', 'completed', 'cancelled'])->default('provisioning');
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->index(['school_id', 'status']);
                $table->index(['host_user_id', 'start_time']);
                $table->index(['meeting_type']);
            });
        }

        if (! Schema::hasTable('school_meeting_participants')) {
            Schema::create('school_meeting_participants', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_meeting_id');
                $table->unsignedBigInteger('user_id');
                $table->enum('role', ['host', 'participant'])->default('participant');
                $table->timestamp('invited_at')->nullable();
                $table->timestamp('joined_at')->nullable();
                $table->timestamps();

                $table->unique(['school_meeting_id', 'user_id']);
                $table->index(['user_id']);
            });
        }

        if (! Schema::hasTable('online_payment_intents')) {
            Schema::create('online_payment_intents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('fee_id')->nullable();
                $table->decimal('amount', 12, 2);
                $table->string('reference')->unique();
                $table->string('provider', 32)->default('paystack');
                $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
                $table->unsignedBigInteger('payment_id')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['student_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('online_payment_intents');
        Schema::dropIfExists('school_meeting_participants');
        Schema::dropIfExists('school_meetings');
    }
};

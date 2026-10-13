<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Reminder messages about an event, sent now or at a scheduled time. */
    public function up(): void
    {
        if (Schema::hasTable('event_reminders')) {
            return;
        }

        Schema::create('event_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id')->index();
            // everyone | parents | class_parents | staff | teachers | students
            $table->string('audience', 30);
            $table->unsignedBigInteger('class_id')->nullable();
            $table->string('subject');
            $table->text('message');
            $table->timestamp('send_at')->nullable();
            $table->string('status', 20)->default('pending'); // pending | sent | cancelled
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'send_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_reminders');
    }
};

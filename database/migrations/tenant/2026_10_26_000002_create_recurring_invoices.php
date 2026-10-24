<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Recurring billing (crèche, lunch, transport…): on the set date the system bills and emails invoices. */
    public function up(): void
    {
        if (! Schema::hasTable('recurring_invoices')) {
            Schema::create('recurring_invoices', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->json('lines');                         // [{name, amount}]
                $t->json('class_ids')->nullable();
                $t->json('arm_ids')->nullable();
                $t->json('student_ids')->nullable();       // or particular students
                $t->string('frequency', 20)->default('monthly');   // monthly | weekly | termly
                $t->date('next_run_on');
                $t->unsignedSmallInteger('due_in_days')->default(7);
                $t->boolean('send_email')->default(true);
                $t->boolean('active')->default(true);
                $t->text('notes')->nullable();
                $t->timestamp('last_run_at')->nullable();
                $t->unsignedInteger('last_run_count')->default(0);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoices');
    }
};

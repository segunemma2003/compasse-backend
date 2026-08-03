<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('teacher_periodic_reports')) {
            return;
        }

        Schema::create('teacher_periodic_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('class_id')->nullable();
            $table->enum('period_type', ['weekly', 'monthly']);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('title')->nullable();
            $table->text('summary');
            $table->text('challenges')->nullable();
            $table->text('recommendations')->nullable();
            $table->enum('status', ['draft', 'submitted'])->default('draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['teacher_id', 'class_id', 'period_type', 'period_start'],
                'tpr_teacher_class_period_unique'
            );
            $table->index(['school_id', 'period_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_periodic_reports');
    }
};

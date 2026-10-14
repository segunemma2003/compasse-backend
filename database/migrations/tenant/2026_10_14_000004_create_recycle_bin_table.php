<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A copy of every deleted payment, fee, parent, class, subject, event, exam, assignment and invoice, so admins can restore it. */
    public function up(): void
    {
        if (Schema::hasTable('recycle_bin')) {
            return;
        }
        Schema::create('recycle_bin', function (Blueprint $t) {
            $t->id();
            $t->string('table_name', 64);
            $t->unsignedBigInteger('record_id');
            $t->string('type', 40);
            $t->string('label')->nullable();
            $t->longText('data');
            $t->unsignedBigInteger('deleted_by')->nullable();
            $t->timestamp('deleted_at')->useCurrent();
            $t->timestamp('restored_at')->nullable();
            $t->index(['table_name', 'record_id']);
            $t->index(['restored_at', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recycle_bin');
    }
};

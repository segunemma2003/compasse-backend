<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An admission cycle can offer several classes (the parent picks one)
     * and carry the school's own extra form fields; applicants store their
     * answers and uploaded documents.
     */
    public function up(): void
    {
        Schema::table('admission_cycles', function (Blueprint $table) {
            if (! Schema::hasColumn('admission_cycles', 'class_ids')) {
                $table->json('class_ids')->nullable();
            }
            if (! Schema::hasColumn('admission_cycles', 'form_fields')) {
                $table->json('form_fields')->nullable();
            }
        });
        // class_id stays as "the first class" for older code; it no longer
        // has to be set when class_ids is.
        if (Schema::hasColumn('admission_cycles', 'class_id')) {
            Schema::table('admission_cycles', fn (Blueprint $t) => $t->unsignedBigInteger('class_id')->nullable()->change());
        }

        Schema::table('applicants', function (Blueprint $table) {
            if (! Schema::hasColumn('applicants', 'custom_fields')) {
                $table->json('custom_fields')->nullable();
            }
            if (! Schema::hasColumn('applicants', 'documents')) {
                $table->json('documents')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('admission_cycles', fn (Blueprint $t) => $t->dropColumn(['class_ids', 'form_fields']));
        Schema::table('applicants', fn (Blueprint $t) => $t->dropColumn(['custom_fields', 'documents']));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Set once per term by the admin: when next term begins (report card) and how many days school opened (attendance). */
    public function up(): void
    {
        Schema::table('terms', function (Blueprint $t) {
            if (! Schema::hasColumn('terms', 'next_term_begins')) {
                $t->date('next_term_begins')->nullable();
            }
            if (! Schema::hasColumn('terms', 'days_opened')) {
                $t->unsignedSmallInteger('days_opened')->nullable();
            }
        });
    }

    public function down(): void
    {
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Teachers ──────────────────────────────────────────────────────────
        if (Schema::hasTable('teachers')) {
            Schema::table('teachers', function (Blueprint $table) {
                if (! Schema::hasColumn('teachers', 'employment_type')) {
                    $table->enum('employment_type', ['full_time', 'part_time', 'contract'])
                          ->default('full_time')->nullable()->after('employment_date');
                }
                if (! Schema::hasColumn('teachers', 'bank_name')) {
                    $table->string('bank_name')->nullable()->after('profile_picture');
                }
                if (! Schema::hasColumn('teachers', 'bank_account_number')) {
                    $table->string('bank_account_number')->nullable()->after('bank_name');
                }
                if (! Schema::hasColumn('teachers', 'bank_account_name')) {
                    $table->string('bank_account_name')->nullable()->after('bank_account_number');
                }
            });
        }

        // ── Students ──────────────────────────────────────────────────────────
        if (Schema::hasTable('students')) {
            Schema::table('students', function (Blueprint $table) {
                if (! Schema::hasColumn('students', 'nationality')) {
                    $table->string('nationality')->nullable()->after('address');
                }
                if (! Schema::hasColumn('students', 'state_of_origin')) {
                    $table->string('state_of_origin')->nullable()->after('nationality');
                }
                if (! Schema::hasColumn('students', 'religion')) {
                    $table->string('religion')->nullable()->after('state_of_origin');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('teachers')) {
            Schema::table('teachers', function (Blueprint $table) {
                $table->dropColumn(array_filter([
                    Schema::hasColumn('teachers', 'employment_type')    ? 'employment_type'    : null,
                    Schema::hasColumn('teachers', 'bank_name')          ? 'bank_name'          : null,
                    Schema::hasColumn('teachers', 'bank_account_number')? 'bank_account_number': null,
                    Schema::hasColumn('teachers', 'bank_account_name')  ? 'bank_account_name'  : null,
                ]));
            });
        }

        if (Schema::hasTable('students')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropColumn(array_filter([
                    Schema::hasColumn('students', 'nationality')     ? 'nationality'     : null,
                    Schema::hasColumn('students', 'state_of_origin') ? 'state_of_origin' : null,
                    Schema::hasColumn('students', 'religion')        ? 'religion'        : null,
                ]));
            });
        }
    }
};

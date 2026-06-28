<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff')) {
            Schema::table('staff', function (Blueprint $table) {
                if (! Schema::hasColumn('staff', 'gender')) {
                    $table->enum('gender', ['male', 'female', 'other'])->nullable()->after('phone');
                }
                if (! Schema::hasColumn('staff', 'date_of_birth')) {
                    $table->date('date_of_birth')->nullable()->after('gender');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('staff')) {
            Schema::table('staff', function (Blueprint $table) {
                $columns = array_filter([
                    Schema::hasColumn('staff', 'gender')        ? 'gender'        : null,
                    Schema::hasColumn('staff', 'date_of_birth') ? 'date_of_birth' : null,
                ]);
                if ($columns) {
                    $table->dropColumn(array_values($columns));
                }
            });
        }
    }
};

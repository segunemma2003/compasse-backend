<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds line-item breakdown support to fees.
 *
 * fee_structures already existed as a single fee_type + amount template with
 * no Eloquent model and no controller actually reading/writing it (dead
 * table). This turns it into the real "class fee plan" container — a plan
 * has many fee_structure_items (e.g. Tuition 50,000 + Sports 5,000 + PTA
 * 2,000 = total_amount 57,000), applied to a class to generate one `fees`
 * row per student. Editing the plan propagates to every student's fee unless
 * that student's fee has been individually customized (fees.is_customized).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fee_structures')) {
            Schema::table('fee_structures', function (Blueprint $table) {
                if (! Schema::hasColumn('fee_structures', 'total_amount')) {
                    $table->decimal('total_amount', 12, 2)->default(0)->after('amount');
                }
                if (! Schema::hasColumn('fee_structures', 'arm_id')) {
                    $table->unsignedBigInteger('arm_id')->nullable()->after('class_id');
                }
                if (! Schema::hasColumn('fee_structures', 'due_date')) {
                    $table->date('due_date')->nullable()->after('description');
                }
            });

            // Existing single fee_type/amount rows are now optional — new
            // structures carry their total in total_amount + line items instead.
            // Raw ALTER since doctrine/dbal (needed for Schema::change()) isn't installed.
            if (DB::connection()->getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE fee_structures MODIFY fee_type VARCHAR(255) NULL');
                DB::statement('ALTER TABLE fee_structures MODIFY amount DECIMAL(10,2) NULL');
            }
        }

        if (! Schema::hasTable('fee_structure_items')) {
            Schema::create('fee_structure_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('fee_structure_id')->constrained('fee_structures')->onDelete('cascade');
                $table->string('name');
                $table->decimal('amount', 12, 2);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('fees')) {
            Schema::table('fees', function (Blueprint $table) {
                if (! Schema::hasColumn('fees', 'fee_structure_id')) {
                    $table->foreignId('fee_structure_id')->nullable()->after('class_id')
                        ->constrained('fee_structures')->nullOnDelete();
                }
                if (! Schema::hasColumn('fees', 'is_customized')) {
                    $table->boolean('is_customized')->default(false)->after('fee_structure_id');
                }
            });
        }

        if (! Schema::hasTable('fee_items')) {
            Schema::create('fee_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('fee_id')->constrained('fees')->onDelete('cascade');
                $table->string('name');
                $table->decimal('amount', 12, 2);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_items');

        if (Schema::hasTable('fees')) {
            Schema::table('fees', function (Blueprint $table) {
                if (Schema::hasColumn('fees', 'fee_structure_id')) {
                    $table->dropConstrainedForeignId('fee_structure_id');
                }
                if (Schema::hasColumn('fees', 'is_customized')) {
                    $table->dropColumn('is_customized');
                }
            });
        }

        Schema::dropIfExists('fee_structure_items');

        if (Schema::hasTable('fee_structures')) {
            Schema::table('fee_structures', function (Blueprint $table) {
                if (Schema::hasColumn('fee_structures', 'total_amount')) {
                    $table->dropColumn('total_amount');
                }
                if (Schema::hasColumn('fee_structures', 'arm_id')) {
                    $table->dropColumn('arm_id');
                }
                if (Schema::hasColumn('fee_structures', 'due_date')) {
                    $table->dropColumn('due_date');
                }
            });
        }
    }
};

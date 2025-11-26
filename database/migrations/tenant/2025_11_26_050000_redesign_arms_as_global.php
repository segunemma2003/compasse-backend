<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check and drop existing constraints and columns safely
        Schema::table('arms', function (Blueprint $table) {
            // Check if class_id column exists before dropping
            if (Schema::hasColumn('arms', 'class_id')) {
                // Drop foreign key constraint if it exists
                $foreignKeys = DB::select(
                    "SELECT CONSTRAINT_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_NAME = 'arms'
                    AND COLUMN_NAME = 'class_id'
                    AND CONSTRAINT_NAME != 'PRIMARY'
                    AND TABLE_SCHEMA = DATABASE()"
                );

                foreach ($foreignKeys as $key) {
                    $table->dropForeign($key->CONSTRAINT_NAME);
                }

                $table->dropColumn('class_id');
            }

            // Check and drop capacity column if it exists
            if (Schema::hasColumn('arms', 'capacity')) {
                $table->dropColumn('capacity');
            }

            // Check and drop class_teacher_id if it exists
            if (Schema::hasColumn('arms', 'class_teacher_id')) {
                $table->dropColumn('class_teacher_id');
            }
        });

        // Add school_id if it doesn't exist
        if (!Schema::hasColumn('arms', 'school_id')) {
            Schema::table('arms', function (Blueprint $table) {
                $table->foreignId('school_id')->after('id')->constrained()->onDelete('cascade');
                $table->index(['school_id', 'status']);
            });
        }

        // Create pivot table for class-arm relationship (many-to-many)
        if (!Schema::hasTable('class_arm')) {
            Schema::create('class_arm', function (Blueprint $table) {
                $table->id();
                $table->foreignId('class_id')->constrained()->onDelete('cascade');
                $table->foreignId('arm_id')->constrained()->onDelete('cascade');
                $table->integer('capacity')->default(30);
                $table->foreignId('class_teacher_id')->nullable()->constrained('teachers')->onDelete('set null');
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->timestamps();

                $table->unique(['class_id', 'arm_id']);
                $table->index(['class_id', 'status']);
            });
        }

        // Seed default arms (A, B, C, D, E, F) only if school_id exists and no arms exist
        if (Schema::hasColumn('arms', 'school_id')) {
            $schools = DB::table('schools')->pluck('id');
            foreach ($schools as $schoolId) {
                // Check if arms already exist for this school
                $existingArms = DB::table('arms')->where('school_id', $schoolId)->count();

                if ($existingArms === 0) {
                    foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $armName) {
                        DB::table('arms')->insert([
                            'school_id' => $schoolId,
                            'name' => $armName,
                            'description' => "Arm $armName",
                            'status' => 'active',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop class_arm pivot table if it exists
        Schema::dropIfExists('class_arm');

        // Check and drop school_id if it exists
        if (Schema::hasColumn('arms', 'school_id')) {
            Schema::table('arms', function (Blueprint $table) {
                // Drop foreign key constraint if it exists
                $foreignKeys = DB::select(
                    "SELECT CONSTRAINT_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_NAME = 'arms'
                    AND COLUMN_NAME = 'school_id'
                    AND CONSTRAINT_NAME != 'PRIMARY'
                    AND TABLE_SCHEMA = DATABASE()"
                );

                foreach ($foreignKeys as $key) {
                    $table->dropForeign($key->CONSTRAINT_NAME);
                }

                $table->dropColumn('school_id');
            });
        }

        // Restore original structure
        if (!Schema::hasColumn('arms', 'class_id')) {
            Schema::table('arms', function (Blueprint $table) {
                $table->foreignId('class_id')->after('id')->constrained()->onDelete('cascade');
                $table->integer('capacity')->default(30)->after('description');
                $table->index(['class_id', 'status']);
            });
        }
    }
};


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
        // Grading System Configuration
        if (!Schema::hasTable('grading_systems')) {
            Schema::create('grading_systems', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->string('name');
                $table->text('description')->nullable();
                $table->json('grade_boundaries');
                $table->decimal('pass_mark', 5, 2)->default(50);
                $table->boolean('is_default')->default(false);
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->timestamps();
                
                $table->index(['school_id', 'is_default']);
            });
        }

        // Continuous Assessment (CA) Tests
        if (!Schema::hasTable('continuous_assessments')) {
            Schema::create('continuous_assessments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->unsignedBigInteger('class_id');
                $table->unsignedBigInteger('term_id');
                $table->unsignedBigInteger('academic_year_id');
                $table->unsignedBigInteger('teacher_id')->nullable();
                $table->string('name');
                $table->enum('type', ['test', 'classwork', 'homework', 'project', 'quiz'])->default('test');
                $table->decimal('total_marks', 5, 2);
                $table->date('assessment_date')->nullable();
                $table->text('description')->nullable();
                $table->enum('status', ['draft', 'published', 'completed'])->default('draft');
                $table->timestamps();
                
                $table->index(['subject_id', 'class_id', 'term_id']);
            });
        }

        // CA Scores
        if (!Schema::hasTable('ca_scores')) {
            Schema::create('ca_scores', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('continuous_assessment_id');
                $table->unsignedBigInteger('student_id');
                $table->decimal('score', 5, 2);
                $table->text('remarks')->nullable();
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->timestamps();
                
                $table->unique(['continuous_assessment_id', 'student_id'], 'ca_student_unique');
                $table->index('student_id');
            });
        }

        // Psychomotor Assessment - WITH DYNAMIC CONFIGURATION!
        if (!Schema::hasTable('psychomotor_assessments')) {
            Schema::create('psychomotor_assessments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('term_id');
                $table->unsignedBigInteger('academic_year_id');
                $table->unsignedBigInteger('assessed_by')->nullable();
                
                // Standard Psychomotor Skills (1-5 rating) - HARDCODED for common use
                $table->integer('handwriting')->nullable();
                $table->integer('drawing')->nullable();
                $table->integer('sports')->nullable();
                $table->integer('musical_skills')->nullable();
                $table->integer('handling_tools')->nullable();
                
                // Standard Affective Domain (1-5 rating) - HARDCODED for common use
                $table->integer('punctuality')->nullable();
                $table->integer('neatness')->nullable();
                $table->integer('politeness')->nullable();
                $table->integer('honesty')->nullable();
                $table->integer('relationship_with_others')->nullable();
                $table->integer('self_control')->nullable();
                $table->integer('attentiveness')->nullable();
                $table->integer('perseverance')->nullable();
                $table->integer('emotional_stability')->nullable();
                
                // DYNAMIC FIELDS - Schools can add custom assessments!
                $table->json('custom_psychomotor')->nullable(); // {"creativity": 4, "leadership": 5}
                $table->json('custom_affective')->nullable(); // {"teamwork": 5, "responsibility": 4}
                
                $table->text('teacher_comment')->nullable();
                $table->timestamps();
                
                $table->unique(['student_id', 'term_id', 'academic_year_id'], 'psychomotor_unique');
            });
        }

        // Results (Generated Results per Term)
        if (!Schema::hasTable('student_results')) {
            Schema::create('student_results', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('class_id');
                $table->unsignedBigInteger('term_id');
                $table->unsignedBigInteger('academic_year_id');
                $table->decimal('total_score', 5, 2);
                $table->decimal('average_score', 5, 2);
                $table->string('grade')->nullable();
                $table->integer('position')->nullable();
                $table->integer('out_of')->nullable();
                $table->decimal('class_average', 5, 2)->nullable();
                $table->text('class_teacher_comment')->nullable();
                $table->text('principal_comment')->nullable();
                $table->date('next_term_begins')->nullable();
                $table->enum('status', ['draft', 'approved', 'published'])->default('draft');
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamps();
                
                $table->unique(['student_id', 'term_id', 'academic_year_id'], 'result_unique');
                $table->index(['class_id', 'term_id']);
            });
        }

        // Subject Results
        if (!Schema::hasTable('subject_results')) {
            Schema::create('subject_results', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('student_result_id');
                $table->unsignedBigInteger('subject_id');
                $table->decimal('ca_total', 5, 2)->default(0);
                $table->decimal('exam_score', 5, 2)->default(0);
                $table->decimal('total_score', 5, 2);
                $table->string('grade')->nullable();
                $table->text('teacher_remark')->nullable();
                $table->integer('position')->nullable();
                $table->integer('highest_score')->nullable();
                $table->integer('lowest_score')->nullable();
                $table->decimal('class_average', 5, 2)->nullable();
                $table->timestamps();
                
                $table->unique(['student_result_id', 'subject_id'], 'subject_result_unique');
            });
        }

        // Scoreboard/Rankings Cache
        if (!Schema::hasTable('scoreboards')) {
            Schema::create('scoreboards', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('class_id');
                $table->unsignedBigInteger('term_id');
                $table->unsignedBigInteger('academic_year_id');
                $table->json('rankings');
                $table->decimal('class_average', 5, 2);
                $table->integer('total_students');
                $table->integer('pass_rate');
                $table->timestamp('last_updated');
                $table->timestamps();
                
                $table->unique(['class_id', 'term_id', 'academic_year_id'], 'scoreboard_unique');
            });
        }

        // Promotion Records
        if (!Schema::hasTable('promotions')) {
            Schema::create('promotions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('from_class_id');
                $table->unsignedBigInteger('to_class_id');
                $table->unsignedBigInteger('academic_year_id');
                $table->enum('status', ['promoted', 'repeated', 'graduated'])->default('promoted');
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('approved_by');
                $table->timestamp('promoted_at');
                $table->timestamps();
                
                $table->index(['student_id', 'academic_year_id']);
            });
        }

        // Seed default grading system
        $this->seedDefaultGradingSystem();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('scoreboards');
        Schema::dropIfExists('subject_results');
        Schema::dropIfExists('student_results');
        Schema::dropIfExists('psychomotor_assessments');
        Schema::dropIfExists('ca_scores');
        Schema::dropIfExists('continuous_assessments');
        Schema::dropIfExists('grading_systems');
    }

    /**
     * Seed default grading system
     */
    protected function seedDefaultGradingSystem(): void
    {
        // Only seed if schools table exists
        if (!Schema::hasTable('schools')) {
            return;
        }

        try {
            $schools = DB::table('schools')->get();
            foreach ($schools as $school) {
                // Check if grading system already exists
                $exists = DB::table('grading_systems')
                    ->where('school_id', $school->id)
                    ->where('is_default', true)
                    ->exists();

                if (!$exists) {
                    DB::table('grading_systems')->insert([
                        'school_id' => $school->id,
                        'name' => 'Standard Grading System',
                        'description' => 'Default grading system',
                        'grade_boundaries' => json_encode([
                            ['min' => 90, 'max' => 100, 'grade' => 'A', 'remark' => 'Excellent'],
                            ['min' => 80, 'max' => 89, 'grade' => 'B', 'remark' => 'Very Good'],
                            ['min' => 70, 'max' => 79, 'grade' => 'C', 'remark' => 'Good'],
                            ['min' => 60, 'max' => 69, 'grade' => 'D', 'remark' => 'Fair'],
                            ['min' => 50, 'max' => 59, 'grade' => 'E', 'remark' => 'Pass'],
                            ['min' => 0, 'max' => 49, 'grade' => 'F', 'remark' => 'Fail'],
                        ]),
                        'pass_mark' => 50,
                        'is_default' => true,
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Silently fail if seeding fails
            \Log::warning('Failed to seed grading systems: ' . $e->getMessage());
        }
    }
};


<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Staff;
use App\Models\Guardian;
use App\Models\User;
use App\Models\School;
use App\Models\QuestionBank;

/**
 * Optimized Bulk Operations Service
 * 
 * Handles large-scale bulk operations (10,000+ records) efficiently using:
 * - True bulk inserts (DB::table()->insert())
 * - Chunking to avoid memory exhaustion
 * - Transaction management
 * - Progress tracking
 */
class OptimizedBulkService
{
    protected int $chunkSize = 500; // Process 500 records at a time
    protected int $maxExecutionTime = 600; // 10 minutes
    protected string $memoryLimit = '512M';

    /**
     * Bulk insert students with optimized performance
     */
    public function bulkInsertStudents(array $students, int $schoolId): array
    {
        set_time_limit($this->maxExecutionTime);
        ini_set('memory_limit', $this->memoryLimit);

        DB::beginTransaction();

        try {
            $now = now();
            $totalCreated = 0;
            $totalFailed = 0;
            $failed = [];

            // Get starting admission number
            $school = School::find($schoolId);
            $lastStudent = Student::where('school_id', $schoolId)->orderBy('id', 'desc')->first();
            $startNumber = $lastStudent ? (intval(substr($lastStudent->admission_number, 3)) + 1) : 1;

            // Process in chunks
            foreach (array_chunk($students, $this->chunkSize) as $chunkIndex => $chunk) {
                $usersToInsert = [];
                $studentsToInsert = [];

                foreach ($chunk as $index => $student) {
                    try {
                        $globalIndex = ($chunkIndex * $this->chunkSize) + $index;
                        $admissionNumber = 'ADM' . str_pad($startNumber + $globalIndex, 5, '0', STR_PAD_LEFT);
                        
                        $email = strtolower($student['first_name'] . '.' . $student['last_name'] . ($startNumber + $globalIndex)) . '@' . $this->getSchoolDomain($schoolId);
                        $username = strtolower($student['first_name'] . '.' . $student['last_name'] . rand(100, 999));

                        // Prepare user data
                        $usersToInsert[] = [
                            'name' => trim($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']),
                            'email' => $email,
                            'password' => bcrypt('Password@123'),
                            'role' => 'student',
                            'status' => 'active',
                            'email_verified_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                    } catch (\Exception $e) {
                        $failed[] = [
                            'index' => $globalIndex,
                            'error' => $e->getMessage()
                        ];
                        $totalFailed++;
                    }
                }

                // Bulk insert users
                if (!empty($usersToInsert)) {
                    DB::table('users')->insert($usersToInsert);
                    
                    // Get inserted user IDs
                    $insertedUsers = User::where('role', 'student')
                        ->where('created_at', $now)
                        ->orderBy('id', 'desc')
                        ->take(count($usersToInsert))
                        ->get()
                        ->reverse()
                        ->values();

                    // Prepare student data
                    foreach ($chunk as $index => $student) {
                        if (isset($insertedUsers[$index])) {
                            $globalIndex = ($chunkIndex * $this->chunkSize) + $index;
                            $studentsToInsert[] = array_merge($student, [
                                'school_id' => $schoolId,
                                'user_id' => $insertedUsers[$index]->id,
                                'admission_number' => 'ADM' . str_pad($startNumber + $globalIndex, 5, '0', STR_PAD_LEFT),
                                'email' => $usersToInsert[$index]['email'],
                                'username' => strtolower($student['first_name'] . '.' . $student['last_name'] . rand(100, 999)),
                                'status' => 'active',
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        }
                    }

                    // Bulk insert students
                    if (!empty($studentsToInsert)) {
                        DB::table('students')->insert($studentsToInsert);
                        $totalCreated += count($studentsToInsert);
                    }
                }

                // Clear memory after each chunk
                unset($usersToInsert, $studentsToInsert, $insertedUsers);
            }

            DB::commit();

            return [
                'success' => true,
                'total' => count($students),
                'created' => $totalCreated,
                'failed' => $totalFailed,
                'failed_records' => $failed,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk insert students failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Bulk insert questions
     */
    public function bulkInsertQuestions(array $questions, int $schoolId, int $createdBy): array
    {
        set_time_limit($this->maxExecutionTime);
        ini_set('memory_limit', $this->memoryLimit);

        DB::beginTransaction();

        try {
            $now = now();
            $totalCreated = 0;
            $totalFailed = 0;
            $failed = [];

            // Process in chunks
            foreach (array_chunk($questions, $this->chunkSize) as $chunkIndex => $chunk) {
                $questionsToInsert = [];

                foreach ($chunk as $index => $question) {
                    try {
                        $questionsToInsert[] = [
                            'school_id' => $schoolId,
                            'subject_id' => $question['subject_id'],
                            'class_id' => $question['class_id'],
                            'term_id' => $question['term_id'],
                            'academic_year_id' => $question['academic_year_id'],
                            'created_by' => $createdBy,
                            'question_type' => $question['question_type'],
                            'question' => $question['question'],
                            'options' => isset($question['options']) ? json_encode($question['options']) : null,
                            'correct_answer' => json_encode($question['correct_answer']),
                            'explanation' => $question['explanation'] ?? null,
                            'difficulty' => $question['difficulty'] ?? 'medium',
                            'marks' => $question['marks'] ?? 1,
                            'tags' => isset($question['tags']) ? json_encode($question['tags']) : null,
                            'topic' => $question['topic'] ?? null,
                            'hints' => $question['hints'] ?? null,
                            'attachments' => isset($question['attachments']) ? json_encode($question['attachments']) : null,
                            'status' => 'active',
                            'usage_count' => 0,
                            'last_used_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                    } catch (\Exception $e) {
                        $globalIndex = ($chunkIndex * $this->chunkSize) + $index;
                        $failed[] = [
                            'index' => $globalIndex,
                            'error' => $e->getMessage()
                        ];
                        $totalFailed++;
                    }
                }

                // Bulk insert questions
                if (!empty($questionsToInsert)) {
                    DB::table('question_banks')->insert($questionsToInsert);
                    $totalCreated += count($questionsToInsert);
                }

                // Clear memory
                unset($questionsToInsert);
            }

            DB::commit();

            return [
                'success' => true,
                'total' => count($questions),
                'created' => $totalCreated,
                'failed' => $totalFailed,
                'failed_records' => $failed,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk insert questions failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get school domain for email generation
     */
    private function getSchoolDomain(int $schoolId): string
    {
        $school = School::find($schoolId);
        if (!$school) {
            return 'samschool.com';
        }

        if ($school->website) {
            $domain = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $school->website);
            return rtrim($domain, '/');
        }

        if ($school->tenant) {
            return $school->tenant->subdomain . '.samschool.com';
        }

        return 'samschool.com';
    }

    /**
     * Set custom chunk size
     */
    public function setChunkSize(int $size): self
    {
        $this->chunkSize = $size;
        return $this;
    }

    /**
     * Set max execution time
     */
    public function setMaxExecutionTime(int $seconds): self
    {
        $this->maxExecutionTime = $seconds;
        return $this;
    }

    /**
     * Set memory limit
     */
    public function setMemoryLimit(string $limit): self
    {
        $this->memoryLimit = $limit;
        return $this;
    }
}


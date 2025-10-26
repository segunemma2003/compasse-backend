<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\ClassModel;
use App\Models\Arm;
use App\Models\Subject;
use App\Models\Guardian;
use App\Models\Exam;
use App\Models\Assignment;
use App\Models\Fee;
use App\Models\Attendance;
use App\Models\Result;
use App\Models\Notification;
use App\Jobs\BulkOperationJob;
use App\Jobs\SendNotificationJob;
use App\Jobs\SendEmailJob;
use App\Jobs\SendSMSJob;

class BulkOperationService
{
    protected TenantService $tenantService;

    public function __construct(TenantService $tenantService)
    {
        $this->tenantService = $tenantService;
    }

    /**
     * Bulk register students
     */
    public function bulkRegisterStudents(array $students, array $guardians = []): array
    {
        $results = [
            'successful' => [],
            'failed' => [],
            'total' => count($students),
            'operation_id' => Str::uuid()
        ];

        foreach ($students as $index => $studentData) {
            try {
                DB::beginTransaction();

                // Create user account
                $user = User::create([
                    'tenant_id' => $this->tenantService->getTenant()->id,
                    'name' => $studentData['first_name'] . ' ' . $studentData['last_name'],
                    'email' => $studentData['email'],
                    'password' => Hash::make(Str::random(12)), // Random password
                    'phone' => $studentData['phone'] ?? null,
                    'role' => 'student',
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]);

                // Validate that the arm belongs to the specified class
                $arm = Arm::where('id', $studentData['arm_id'])
                          ->where('class_id', $studentData['class_id'])
                          ->first();

                if (!$arm) {
                    throw new \Exception("Arm does not belong to the specified class");
                }

                // Create student profile
                $student = Student::create([
                    'user_id' => $user->id,
                    'school_id' => $this->tenantService->getTenant()->schools()->first()->id,
                    'admission_number' => $studentData['admission_number'],
                    'first_name' => $studentData['first_name'],
                    'last_name' => $studentData['last_name'],
                    'date_of_birth' => $studentData['date_of_birth'],
                    'gender' => $studentData['gender'],
                    'address' => $studentData['address'] ?? null,
                    'phone' => $studentData['phone'] ?? null,
                    'email' => $studentData['email'],
                    'class_id' => $studentData['class_id'],
                    'arm_id' => $studentData['arm_id'],
                    'admission_date' => now(),
                    'status' => 'active',
                ]);

                // Create guardians if provided
                if (!empty($guardians)) {
                    foreach ($guardians as $guardianData) {
                        if ($guardianData['student_index'] == $index) {
                            $guardianUser = User::create([
                                'tenant_id' => $this->tenantService->getTenant()->id,
                                'name' => $guardianData['first_name'] . ' ' . $guardianData['last_name'],
                                'email' => $guardianData['email'],
                                'password' => Hash::make(Str::random(12)),
                                'phone' => $guardianData['phone'],
                                'role' => 'parent',
                                'status' => 'active',
                                'email_verified_at' => now(),
                            ]);

                            $guardian = Guardian::create([
                                'user_id' => $guardianUser->id,
                                'school_id' => $this->tenantService->getTenant()->schools()->first()->id,
                                'first_name' => $guardianData['first_name'],
                                'last_name' => $guardianData['last_name'],
                                'phone' => $guardianData['phone'],
                                'email' => $guardianData['email'],
                                'address' => $guardianData['address'] ?? null,
                                'relationship_to_student' => $guardianData['relationship'],
                                'is_primary_contact' => $guardianData['is_primary'] ?? false,
                                'status' => 'active',
                            ]);

                            // Link guardian to student
                            $student->guardians()->attach($guardian->id);
                        }
                    }
                }

                DB::commit();

                $results['successful'][] = [
                    'index' => $index,
                    'student_id' => $student->id,
                    'user_id' => $user->id,
                    'admission_number' => $student->admission_number,
                    'name' => $student->first_name . ' ' . $student->last_name
                ];

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("Bulk student registration failed for index {$index}: " . $e->getMessage());

                $results['failed'][] = [
                    'index' => $index,
                    'data' => $studentData,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Bulk register teachers
     */
    public function bulkRegisterTeachers(array $teachers, array $subjects = [], array $classes = []): array
    {
        $results = [
            'successful' => [],
            'failed' => [],
            'total' => count($teachers),
            'operation_id' => Str::uuid()
        ];

        foreach ($teachers as $index => $teacherData) {
            try {
                DB::beginTransaction();

                // Create user account
                $user = User::create([
                    'tenant_id' => $this->tenantService->getTenant()->id,
                    'name' => $teacherData['first_name'] . ' ' . $teacherData['last_name'],
                    'email' => $teacherData['email'],
                    'password' => Hash::make(Str::random(12)),
                    'phone' => $teacherData['phone'] ?? null,
                    'role' => 'teacher',
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]);

                // Create teacher profile
                $teacher = Teacher::create([
                    'user_id' => $user->id,
                    'school_id' => $this->tenantService->getTenant()->schools()->first()->id,
                    'employee_id' => $teacherData['employee_id'],
                    'first_name' => $teacherData['first_name'],
                    'last_name' => $teacherData['last_name'],
                    'date_of_birth' => $teacherData['date_of_birth'],
                    'gender' => $teacherData['gender'],
                    'address' => $teacherData['address'] ?? null,
                    'phone' => $teacherData['phone'] ?? null,
                    'email' => $teacherData['email'],
                    'qualification' => $teacherData['qualification'],
                    'experience_years' => $teacherData['experience_years'] ?? 0,
                    'hire_date' => $teacherData['hire_date'],
                    'department_id' => $teacherData['department_id'],
                    'status' => 'active',
                ]);

                // Assign subjects if provided
                if (!empty($subjects) && isset($subjects[$index])) {
                    $teacher->subjects()->attach($subjects[$index]);
                }

                // Assign classes if provided
                if (!empty($classes) && isset($classes[$index])) {
                    $teacher->classes()->attach($classes[$index]);
                }

                DB::commit();

                $results['successful'][] = [
                    'index' => $index,
                    'teacher_id' => $teacher->id,
                    'user_id' => $user->id,
                    'employee_id' => $teacher->employee_id,
                    'name' => $teacher->first_name . ' ' . $teacher->last_name
                ];

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("Bulk teacher registration failed for index {$index}: " . $e->getMessage());

                $results['failed'][] = [
                    'index' => $index,
                    'data' => $teacherData,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Bulk create classes
     */
    public function bulkCreateClasses(array $classes): array
    {
        $results = [
            'successful' => [],
            'failed' => [],
            'total' => count($classes),
            'operation_id' => Str::uuid()
        ];

        foreach ($classes as $index => $classData) {
            try {
                DB::beginTransaction();

                // Create class
                $class = ClassModel::create([
                    'school_id' => $this->tenantService->getTenant()->schools()->first()->id,
                    'name' => $classData['name'],
                    'description' => $classData['description'] ?? null,
                    'academic_year_id' => $classData['academic_year_id'],
                    'term_id' => $classData['term_id'],
                    'status' => 'active',
                ]);

                // Create arms if provided
                if (!empty($classData['arms'])) {
                    foreach ($classData['arms'] as $armData) {
                        $arm = Arm::create([
                            'class_id' => $class->id,
                            'name' => $armData['name'],
                            'description' => $armData['description'] ?? null,
                            'capacity' => $armData['capacity'] ?? null,
                            'status' => 'active',
                        ]);

                        // Assign class teacher to arm if provided
                        if (!empty($armData['class_teacher_id'])) {
                            $classTeacher = Teacher::find($armData['class_teacher_id']);
                            if ($classTeacher) {
                                // Update the arm with class teacher
                                $arm->update(['class_teacher_id' => $classTeacher->id]);

                                // Also assign the teacher to the class
                                $class->teachers()->syncWithoutDetaching([$classTeacher->id]);
                            }
                        }
                    }
                }

                // Assign subjects if provided
                if (!empty($classData['subjects'])) {
                    $class->subjects()->attach($classData['subjects']);
                }

                DB::commit();

                $results['successful'][] = [
                    'index' => $index,
                    'class_id' => $class->id,
                    'name' => $class->name,
                    'arms_count' => count($classData['arms'] ?? [])
                ];

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("Bulk class creation failed for index {$index}: " . $e->getMessage());

                $results['failed'][] = [
                    'index' => $index,
                    'data' => $classData,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Bulk create subjects
     */
    public function bulkCreateSubjects(array $subjects): array
    {
        $results = [
            'successful' => [],
            'failed' => [],
            'total' => count($subjects),
            'operation_id' => Str::uuid()
        ];

        foreach ($subjects as $index => $subjectData) {
            try {
                DB::beginTransaction();

                // Create subject
                $subject = Subject::create([
                    'school_id' => $this->tenantService->getTenant()->schools()->first()->id,
                    'name' => $subjectData['name'],
                    'code' => $subjectData['code'],
                    'description' => $subjectData['description'] ?? null,
                    'department_id' => $subjectData['department_id'],
                    'status' => 'active',
                ]);

                // Assign to classes if provided
                if (!empty($subjectData['classes'])) {
                    $subject->classes()->attach($subjectData['classes']);
                }

                DB::commit();

                $results['successful'][] = [
                    'index' => $index,
                    'subject_id' => $subject->id,
                    'name' => $subject->name,
                    'code' => $subject->code
                ];

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("Bulk subject creation failed for index {$index}: " . $e->getMessage());

                $results['failed'][] = [
                    'index' => $index,
                    'data' => $subjectData,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Bulk create exams
     */
    public function bulkCreateExams(array $exams): array
    {
        $results = [
            'successful' => [],
            'failed' => [],
            'total' => count($exams),
            'operation_id' => Str::uuid()
        ];

        foreach ($exams as $index => $examData) {
            try {
                DB::beginTransaction();

                // Create exam
                $exam = Exam::create([
                    'school_id' => $this->tenantService->getTenant()->schools()->first()->id,
                    'name' => $examData['name'],
                    'description' => $examData['description'] ?? null,
                    'subject_id' => $examData['subject_id'],
                    'class_id' => $examData['class_id'],
                    'teacher_id' => $examData['teacher_id'],
                    'type' => $examData['type'],
                    'duration_minutes' => $examData['duration_minutes'],
                    'total_marks' => $examData['total_marks'],
                    'passing_marks' => $examData['passing_marks'],
                    'start_date' => $examData['start_date'],
                    'end_date' => $examData['end_date'],
                    'is_cbt' => $examData['is_cbt'] ?? false,
                    'cbt_settings' => $examData['cbt_settings'] ?? null,
                    'question_settings' => $examData['question_settings'] ?? null,
                    'status' => 'draft',
                    'created_by' => auth()->id(),
                ]);

                DB::commit();

                $results['successful'][] = [
                    'index' => $index,
                    'exam_id' => $exam->id,
                    'name' => $exam->name,
                    'type' => $exam->type
                ];

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("Bulk exam creation failed for index {$index}: " . $e->getMessage());

                $results['failed'][] = [
                    'index' => $index,
                    'data' => $examData,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Bulk create assignments
     */
    public function bulkCreateAssignments(array $assignments): array
    {
        $results = [
            'successful' => [],
            'failed' => [],
            'total' => count($assignments),
            'operation_id' => Str::uuid()
        ];

        foreach ($assignments as $index => $assignmentData) {
            try {
                DB::beginTransaction();

                // Create assignment
                $assignment = Assignment::create([
                    'school_id' => $this->tenantService->getTenant()->schools()->first()->id,
                    'title' => $assignmentData['title'],
                    'description' => $assignmentData['description'] ?? null,
                    'subject_id' => $assignmentData['subject_id'],
                    'class_id' => $assignmentData['class_id'],
                    'teacher_id' => $assignmentData['teacher_id'],
                    'due_date' => $assignmentData['due_date'],
                    'total_marks' => $assignmentData['total_marks'],
                    'instructions' => $assignmentData['instructions'] ?? null,
                    'attachments' => $assignmentData['attachments'] ?? null,
                    'status' => 'active',
                    'created_by' => auth()->id(),
                ]);

                DB::commit();

                $results['successful'][] = [
                    'index' => $index,
                    'assignment_id' => $assignment->id,
                    'title' => $assignment->title,
                    'due_date' => $assignment->due_date
                ];

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("Bulk assignment creation failed for index {$index}: " . $e->getMessage());

                $results['failed'][] = [
                    'index' => $index,
                    'data' => $assignmentData,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Bulk create fees
     */
    public function bulkCreateFees(array $fees): array
    {
        $results = [
            'successful' => [],
            'failed' => [],
            'total' => count($fees),
            'operation_id' => Str::uuid()
        ];

        foreach ($fees as $index => $feeData) {
            try {
                DB::beginTransaction();

                // Create fee
                $fee = Fee::create([
                    'school_id' => $this->tenantService->getTenant()->schools()->first()->id,
                    'name' => $feeData['name'],
                    'description' => $feeData['description'] ?? null,
                    'amount' => $feeData['amount'],
                    'currency' => $feeData['currency'],
                    'due_date' => $feeData['due_date'],
                    'fee_type' => $feeData['fee_type'],
                    'is_mandatory' => $feeData['is_mandatory'] ?? true,
                    'status' => 'active',
                ]);

                // Assign to classes if provided
                if (!empty($feeData['classes'])) {
                    $fee->classes()->attach($feeData['classes']);
                }

                // Assign to students if provided
                if (!empty($feeData['students'])) {
                    $fee->students()->attach($feeData['students']);
                }

                DB::commit();

                $results['successful'][] = [
                    'index' => $index,
                    'fee_id' => $fee->id,
                    'name' => $fee->name,
                    'amount' => $fee->amount
                ];

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("Bulk fee creation failed for index {$index}: " . $e->getMessage());

                $results['failed'][] = [
                    'index' => $index,
                    'data' => $feeData,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Bulk mark attendance
     */
    public function bulkMarkAttendance(array $attendanceRecords): array
    {
        $results = [
            'successful' => [],
            'failed' => [],
            'total' => count($attendanceRecords),
            'operation_id' => Str::uuid()
        ];

        foreach ($attendanceRecords as $index => $record) {
            try {
                DB::beginTransaction();

                // Create attendance record
                $attendance = Attendance::create([
                    'school_id' => $this->tenantService->getTenant()->schools()->first()->id,
                    'attendanceable_id' => $record['attendanceable_id'],
                    'attendanceable_type' => $record['attendanceable_type'],
                    'date' => $record['date'],
                    'status' => $record['status'],
                    'check_in_time' => $record['check_in_time'] ?? null,
                    'check_out_time' => $record['check_out_time'] ?? null,
                    'notes' => $record['notes'] ?? null,
                    'marked_by' => auth()->id(),
                ]);

                DB::commit();

                $results['successful'][] = [
                    'index' => $index,
                    'attendance_id' => $attendance->id,
                    'date' => $attendance->date,
                    'status' => $attendance->status
                ];

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("Bulk attendance marking failed for index {$index}: " . $e->getMessage());

                $results['failed'][] = [
                    'index' => $index,
                    'data' => $record,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Bulk update results
     */
    public function bulkUpdateResults(array $results): array
    {
        $operationResults = [
            'successful' => [],
            'failed' => [],
            'total' => count($results),
            'operation_id' => Str::uuid()
        ];

        foreach ($results as $index => $resultData) {
            try {
                DB::beginTransaction();

                // Create or update result
                $result = Result::updateOrCreate(
                    [
                        'student_id' => $resultData['student_id'],
                        'exam_id' => $resultData['exam_id'],
                        'subject_id' => $resultData['subject_id'],
                    ],
                    [
                        'marks_obtained' => $resultData['marks_obtained'],
                        'total_marks' => $resultData['total_marks'],
                        'grade' => $resultData['grade'] ?? null,
                        'remarks' => $resultData['remarks'] ?? null,
                        'updated_by' => auth()->id(),
                    ]
                );

                DB::commit();

                $operationResults['successful'][] = [
                    'index' => $index,
                    'result_id' => $result->id,
                    'student_id' => $result->student_id,
                    'marks_obtained' => $result->marks_obtained
                ];

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("Bulk result update failed for index {$index}: " . $e->getMessage());

                $operationResults['failed'][] = [
                    'index' => $index,
                    'data' => $resultData,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $operationResults;
    }

    /**
     * Bulk send notifications
     */
    public function bulkSendNotifications(array $notifications): array
    {
        $results = [
            'successful' => [],
            'failed' => [],
            'total' => count($notifications),
            'operation_id' => Str::uuid()
        ];

        foreach ($notifications as $index => $notificationData) {
            try {
                // Create notification record
                $notification = Notification::create([
                    'title' => $notificationData['title'],
                    'message' => $notificationData['message'],
                    'type' => $notificationData['type'],
                    'data' => [
                        'channels' => $notificationData['channels'],
                        'scheduled_at' => $notificationData['scheduled_at'] ?? null,
                    ],
                    'scheduled_at' => $notificationData['scheduled_at'] ?? null,
                ]);

                // Send to recipients
                foreach ($notificationData['recipients'] as $recipient) {
                    $notification->users()->attach($recipient['user_id']);

                    // Queue notification jobs
                    foreach ($notificationData['channels'] as $channel) {
                        switch ($channel) {
                            case 'email':
                                Queue::push(new SendEmailJob($recipient['user_id'], $notification));
                                break;
                            case 'sms':
                                Queue::push(new SendSMSJob($recipient['user_id'], $notification));
                                break;
                            case 'push':
                                Queue::push(new SendNotificationJob($recipient['user_id'], $notification));
                                break;
                        }
                    }
                }

                $results['successful'][] = [
                    'index' => $index,
                    'notification_id' => $notification->id,
                    'title' => $notification->title,
                    'recipients_count' => count($notificationData['recipients'])
                ];

            } catch (\Exception $e) {
                Log::error("Bulk notification sending failed for index {$index}: " . $e->getMessage());

                $results['failed'][] = [
                    'index' => $index,
                    'data' => $notificationData,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Bulk import from CSV
     */
    public function bulkImportFromCSV($file, string $type, array $mapping, bool $skipHeader = true, bool $validateData = true): array
    {
        $results = [
            'successful' => [],
            'failed' => [],
            'total' => 0,
            'operation_id' => Str::uuid()
        ];

        try {
            $csvData = $this->parseCSV($file, $skipHeader);
            $results['total'] = count($csvData);

            foreach ($csvData as $index => $row) {
                try {
                    $mappedData = $this->mapCSVRow($row, $mapping);

                    if ($validateData) {
                        $this->validateCSVData($mappedData, $type);
                    }

                    // Process based on type
                    switch ($type) {
                        case 'students':
                            $this->processStudentImport($mappedData, $index, $results);
                            break;
                        case 'teachers':
                            $this->processTeacherImport($mappedData, $index, $results);
                            break;
                        case 'classes':
                            $this->processClassImport($mappedData, $index, $results);
                            break;
                        case 'subjects':
                            $this->processSubjectImport($mappedData, $index, $results);
                            break;
                        case 'exams':
                            $this->processExamImport($mappedData, $index, $results);
                            break;
                        case 'assignments':
                            $this->processAssignmentImport($mappedData, $index, $results);
                            break;
                        case 'fees':
                            $this->processFeeImport($mappedData, $index, $results);
                            break;
                        default:
                            throw new \Exception("Unsupported import type: {$type}");
                    }

                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'index' => $index,
                        'data' => $row,
                        'error' => $e->getMessage()
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::error("Bulk CSV import failed: " . $e->getMessage());
            throw $e;
        }

        return $results;
    }

    /**
     * Parse CSV file
     */
    protected function parseCSV($file, bool $skipHeader = true): array
    {
        $data = [];
        $handle = fopen($file->getPathname(), 'r');

        if ($skipHeader) {
            fgetcsv($handle); // Skip header row
        }

        while (($row = fgetcsv($handle)) !== false) {
            $data[] = $row;
        }

        fclose($handle);
        return $data;
    }

    /**
     * Map CSV row to data structure
     */
    protected function mapCSVRow(array $row, array $mapping): array
    {
        $mappedData = [];

        foreach ($mapping as $field => $columnIndex) {
            if (isset($row[$columnIndex])) {
                $mappedData[$field] = trim($row[$columnIndex]);
            }
        }

        return $mappedData;
    }

    /**
     * Validate CSV data
     */
    protected function validateCSVData(array $data, string $type): void
    {
        // Basic validation based on type
        switch ($type) {
            case 'students':
                if (empty($data['first_name']) || empty($data['last_name']) || empty($data['email'])) {
                    throw new \Exception("Required fields missing for student");
                }
                break;
            case 'teachers':
                if (empty($data['first_name']) || empty($data['last_name']) || empty($data['email'])) {
                    throw new \Exception("Required fields missing for teacher");
                }
                break;
            // Add more validation as needed
        }
    }

    /**
     * Process student import
     */
    protected function processStudentImport(array $data, int $index, array &$results): void
    {
        try {
            $this->bulkRegisterStudents([$data]);
            $results['successful'][] = [
                'index' => $index,
                'type' => 'student',
                'data' => $data
            ];
        } catch (\Exception $e) {
            $results['failed'][] = [
                'index' => $index,
                'type' => 'student',
                'error' => $e->getMessage(),
                'data' => $data
            ];
        }
    }

    /**
     * Process teacher import
     */
    protected function processTeacherImport(array $data, int $index, array &$results): void
    {
        try {
            $this->bulkRegisterTeachers([$data]);
            $results['successful'][] = [
                'index' => $index,
                'type' => 'teacher',
                'data' => $data
            ];
        } catch (\Exception $e) {
            $results['failed'][] = [
                'index' => $index,
                'type' => 'teacher',
                'error' => $e->getMessage(),
                'data' => $data
            ];
        }
    }

    /**
     * Process class import
     */
    protected function processClassImport(array $data, int $index, array &$results): void
    {
        try {
            $this->bulkCreateClasses([$data]);
            $results['successful'][] = [
                'index' => $index,
                'type' => 'class',
                'data' => $data
            ];
        } catch (\Exception $e) {
            $results['failed'][] = [
                'index' => $index,
                'type' => 'class',
                'error' => $e->getMessage(),
                'data' => $data
            ];
        }
    }

    /**
     * Process subject import
     */
    protected function processSubjectImport(array $data, int $index, array &$results): void
    {
        try {
            $this->bulkCreateSubjects([$data]);
            $results['successful'][] = [
                'index' => $index,
                'type' => 'subject',
                'data' => $data
            ];
        } catch (\Exception $e) {
            $results['failed'][] = [
                'index' => $index,
                'type' => 'subject',
                'error' => $e->getMessage(),
                'data' => $data
            ];
        }
    }

    /**
     * Process exam import
     */
    protected function processExamImport(array $data, int $index, array &$results): void
    {
        try {
            $this->bulkCreateExams([$data]);
            $results['successful'][] = [
                'index' => $index,
                'type' => 'exam',
                'data' => $data
            ];
        } catch (\Exception $e) {
            $results['failed'][] = [
                'index' => $index,
                'type' => 'exam',
                'error' => $e->getMessage(),
                'data' => $data
            ];
        }
    }

    /**
     * Process assignment import
     */
    protected function processAssignmentImport(array $data, int $index, array &$results): void
    {
        try {
            $this->bulkCreateAssignments([$data]);
            $results['successful'][] = [
                'index' => $index,
                'type' => 'assignment',
                'data' => $data
            ];
        } catch (\Exception $e) {
            $results['failed'][] = [
                'index' => $index,
                'type' => 'assignment',
                'error' => $e->getMessage(),
                'data' => $data
            ];
        }
    }

    /**
     * Process fee import
     */
    protected function processFeeImport(array $data, int $index, array &$results): void
    {
        try {
            $this->bulkCreateFees([$data]);
            $results['successful'][] = [
                'index' => $index,
                'type' => 'fee',
                'data' => $data
            ];
        } catch (\Exception $e) {
            $results['failed'][] = [
                'index' => $index,
                'type' => 'fee',
                'error' => $e->getMessage(),
                'data' => $data
            ];
        }
    }

    /**
     * Get operation status
     */
    public function getOperationStatus(string $operationId): array
    {
        // Implementation to get operation status from cache or database
        return [
            'operation_id' => $operationId,
            'status' => 'completed', // or 'running', 'failed'
            'progress' => 100,
            'started_at' => now(),
            'completed_at' => now(),
        ];
    }

    /**
     * Cancel operation
     */
    public function cancelOperation(string $operationId): bool
    {
        // Implementation to cancel operation
        return true;
    }
}

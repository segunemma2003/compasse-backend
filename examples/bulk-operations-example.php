<?php

/**
 * Bulk Operations Example for SamSchool Management System
 *
 * This example demonstrates how to use the bulk operations API
 * with proper class-arm structure where students are in different arms
 * and each arm has a class teacher.
 */

require_once 'vendor/autoload.php';

use Illuminate\Support\Facades\Http;

class BulkOperationsExample
{
    private $baseUrl;
    private $token;
    private $tenantId;

    public function __construct($baseUrl = 'http://localhost:8000')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Authenticate and get token
     */
    public function authenticate($email, $password, $tenantId)
    {
        $response = Http::post($this->baseUrl . '/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
            'tenant_id' => $tenantId,
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $this->token = $data['token'];
            $this->tenantId = $tenantId;
            return true;
        }

        return false;
    }

    /**
     * Example: Bulk create classes with arms and class teachers
     */
    public function bulkCreateClassesWithArms()
    {
        $classesData = [
            [
                'name' => 'Senior Secondary 1 (SS1)',
                'description' => 'Senior Secondary 1 Class',
                'academic_year_id' => 1,
                'term_id' => 1,
                'arms' => [
                    [
                        'name' => 'SS1A',
                        'description' => 'Science Class A',
                        'capacity' => 40,
                        'class_teacher_id' => 1, // Teacher ID who will be the class teacher
                    ],
                    [
                        'name' => 'SS1B',
                        'description' => 'Science Class B',
                        'capacity' => 40,
                        'class_teacher_id' => 2,
                    ],
                    [
                        'name' => 'SS1C',
                        'description' => 'Arts Class',
                        'capacity' => 35,
                        'class_teacher_id' => 3,
                    ],
                ],
                'subjects' => [1, 2, 3, 4, 5], // Subject IDs
            ],
            [
                'name' => 'Senior Secondary 2 (SS2)',
                'description' => 'Senior Secondary 2 Class',
                'academic_year_id' => 1,
                'term_id' => 1,
                'arms' => [
                    [
                        'name' => 'SS2A',
                        'description' => 'Science Class A',
                        'capacity' => 40,
                        'class_teacher_id' => 4,
                    ],
                    [
                        'name' => 'SS2B',
                        'description' => 'Science Class B',
                        'capacity' => 40,
                        'class_teacher_id' => 5,
                    ],
                ],
                'subjects' => [1, 2, 3, 4, 5],
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenantId,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/api/v1/bulk/classes/create', [
            'classes' => $classesData
        ]);

        return $response->json();
    }

    /**
     * Example: Bulk register students with proper arm assignment
     */
    public function bulkRegisterStudentsWithArms()
    {
        $studentsData = [
            [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@student.com',
                'phone' => '+1234567890',
                'admission_number' => 'SS1A001',
                'class_id' => 1, // SS1 class ID
                'arm_id' => 1,   // SS1A arm ID
                'date_of_birth' => '2008-05-15',
                'gender' => 'male',
                'address' => '123 Main Street, City',
            ],
            [
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'email' => 'jane.smith@student.com',
                'phone' => '+1234567891',
                'admission_number' => 'SS1A002',
                'class_id' => 1, // SS1 class ID
                'arm_id' => 1,   // SS1A arm ID
                'date_of_birth' => '2008-03-20',
                'gender' => 'female',
                'address' => '456 Oak Avenue, City',
            ],
            [
                'first_name' => 'Mike',
                'last_name' => 'Johnson',
                'email' => 'mike.johnson@student.com',
                'phone' => '+1234567892',
                'admission_number' => 'SS1B001',
                'class_id' => 1, // SS1 class ID
                'arm_id' => 2,   // SS1B arm ID
                'date_of_birth' => '2008-07-10',
                'gender' => 'male',
                'address' => '789 Pine Road, City',
            ],
        ];

        $guardiansData = [
            [
                'student_index' => 0, // Index of student in the students array
                'first_name' => 'Robert',
                'last_name' => 'Doe',
                'email' => 'robert.doe@parent.com',
                'phone' => '+1234567893',
                'relationship' => 'father',
                'is_primary' => true,
            ],
            [
                'student_index' => 1,
                'first_name' => 'Mary',
                'last_name' => 'Smith',
                'email' => 'mary.smith@parent.com',
                'phone' => '+1234567894',
                'relationship' => 'mother',
                'is_primary' => true,
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenantId,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/api/v1/bulk/students/register', [
            'students' => $studentsData,
            'guardians' => $guardiansData
        ]);

        return $response->json();
    }

    /**
     * Example: Bulk register teachers with class assignments
     */
    public function bulkRegisterTeachersWithClasses()
    {
        $teachersData = [
            [
                'first_name' => 'Dr. Sarah',
                'last_name' => 'Wilson',
                'email' => 'sarah.wilson@teacher.com',
                'phone' => '+1234567895',
                'employee_id' => 'TCH001',
                'department_id' => 1,
                'qualification' => 'PhD in Mathematics',
                'experience_years' => 10,
                'hire_date' => '2024-01-01',
                'date_of_birth' => '1985-06-15',
                'gender' => 'female',
                'address' => '321 Teacher Lane, City',
            ],
            [
                'first_name' => 'Mr. David',
                'last_name' => 'Brown',
                'email' => 'david.brown@teacher.com',
                'phone' => '+1234567896',
                'employee_id' => 'TCH002',
                'department_id' => 2,
                'qualification' => 'MSc in Physics',
                'experience_years' => 8,
                'hire_date' => '2024-01-01',
                'date_of_birth' => '1987-09-22',
                'gender' => 'male',
                'address' => '654 Educator Street, City',
            ],
        ];

        $subjectsData = [
            [1, 2], // Teacher 1 teaches subjects 1 and 2
            [3, 4], // Teacher 2 teaches subjects 3 and 4
        ];

        $classesData = [
            [1, 2], // Teacher 1 teaches classes 1 and 2
            [3, 4], // Teacher 2 teaches classes 3 and 4
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenantId,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/api/v1/bulk/teachers/register', [
            'teachers' => $teachersData,
            'subjects' => $subjectsData,
            'classes' => $classesData
        ]);

        return $response->json();
    }

    /**
     * Example: Bulk create exams for different arms
     */
    public function bulkCreateExamsForArms()
    {
        $examsData = [
            [
                'name' => 'SS1A Mathematics Test',
                'description' => 'First term mathematics test for SS1A',
                'subject_id' => 1, // Mathematics
                'class_id' => 1,   // SS1 class
                'teacher_id' => 1,  // Mathematics teacher
                'type' => 'cbt',
                'duration_minutes' => 60,
                'total_marks' => 100,
                'passing_marks' => 40,
                'start_date' => '2024-02-15 09:00:00',
                'end_date' => '2024-02-15 10:00:00',
                'is_cbt' => true,
                'cbt_settings' => [
                    'shuffle_questions' => true,
                    'shuffle_options' => true,
                    'show_answers' => false,
                    'allow_review' => true,
                ],
                'question_settings' => [
                    'multiple_choice' => 20,
                    'true_false' => 10,
                    'short_answer' => 5,
                ],
            ],
            [
                'name' => 'SS1B Physics Test',
                'description' => 'First term physics test for SS1B',
                'subject_id' => 2, // Physics
                'class_id' => 1,   // SS1 class
                'teacher_id' => 2,  // Physics teacher
                'type' => 'written',
                'duration_minutes' => 90,
                'total_marks' => 100,
                'passing_marks' => 40,
                'start_date' => '2024-02-16 09:00:00',
                'end_date' => '2024-02-16 10:30:00',
                'is_cbt' => false,
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenantId,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/api/v1/bulk/exams/create', [
            'exams' => $examsData
        ]);

        return $response->json();
    }

    /**
     * Example: Bulk mark attendance for students in different arms
     */
    public function bulkMarkAttendanceForArms()
    {
        $attendanceData = [
            // SS1A students
            [
                'attendanceable_id' => 1, // Student ID
                'attendanceable_type' => 'student',
                'date' => '2024-01-15',
                'status' => 'present',
                'check_in_time' => '08:00:00',
                'notes' => 'On time',
            ],
            [
                'attendanceable_id' => 2,
                'attendanceable_type' => 'student',
                'date' => '2024-01-15',
                'status' => 'present',
                'check_in_time' => '08:05:00',
                'notes' => 'Slightly late',
            ],
            // SS1B students
            [
                'attendanceable_id' => 3,
                'attendanceable_type' => 'student',
                'date' => '2024-01-15',
                'status' => 'absent',
                'notes' => 'Sick leave',
            ],
            // Teachers
            [
                'attendanceable_id' => 1, // Teacher ID
                'attendanceable_type' => 'teacher',
                'date' => '2024-01-15',
                'status' => 'present',
                'check_in_time' => '07:30:00',
                'check_out_time' => '15:30:00',
                'notes' => 'Full day',
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenantId,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/api/v1/bulk/attendance/mark', [
            'attendance_records' => $attendanceData
        ]);

        return $response->json();
    }

    /**
     * Example: Bulk import from CSV
     */
    public function bulkImportFromCSV()
    {
        // This would be a file upload, but for example purposes:
        $csvData = [
            'type' => 'students',
            'mapping' => [
                'first_name' => 0,
                'last_name' => 1,
                'email' => 2,
                'admission_number' => 3,
                'class_id' => 4,
                'arm_id' => 5,
                'date_of_birth' => 6,
                'gender' => 7,
            ],
            'skip_header' => true,
            'validate_data' => true,
        ];

        // In real implementation, you would upload a CSV file
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenantId,
        ])->post($this->baseUrl . '/api/v1/bulk/import/csv', $csvData);

        return $response->json();
    }

    /**
     * Example: Get bulk operation status
     */
    public function getBulkOperationStatus($operationId)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenantId,
        ])->get($this->baseUrl . '/api/v1/bulk/operations/' . $operationId . '/status');

        return $response->json();
    }

    /**
     * Run all examples
     */
    public function runAllExamples()
    {
        echo "🚀 Running Bulk Operations Examples...\n\n";

        // Authenticate
        if (!$this->authenticate('admin@samschool.com', 'password', 1)) {
            echo "❌ Authentication failed\n";
            return;
        }
        echo "✅ Authentication successful\n\n";

        // Create classes with arms
        echo "📚 Creating classes with arms and class teachers...\n";
        $classesResult = $this->bulkCreateClassesWithArms();
        echo "Classes created: " . json_encode($classesResult, JSON_PRETTY_PRINT) . "\n\n";

        // Register students with arms
        echo "👥 Registering students with proper arm assignment...\n";
        $studentsResult = $this->bulkRegisterStudentsWithArms();
        echo "Students registered: " . json_encode($studentsResult, JSON_PRETTY_PRINT) . "\n\n";

        // Register teachers
        echo "👨‍🏫 Registering teachers with class assignments...\n";
        $teachersResult = $this->bulkRegisterTeachersWithClasses();
        echo "Teachers registered: " . json_encode($teachersResult, JSON_PRETTY_PRINT) . "\n\n";

        // Create exams
        echo "📝 Creating exams for different arms...\n";
        $examsResult = $this->bulkCreateExamsForArms();
        echo "Exams created: " . json_encode($examsResult, JSON_PRETTY_PRINT) . "\n\n";

        // Mark attendance
        echo "📊 Marking attendance for students in different arms...\n";
        $attendanceResult = $this->bulkMarkAttendanceForArms();
        echo "Attendance marked: " . json_encode($attendanceResult, JSON_PRETTY_PRINT) . "\n\n";

        echo "🎉 All bulk operations examples completed!\n";
    }
}

// Run examples if called directly
if (php_sapi_name() === 'cli') {
    $example = new BulkOperationsExample();
    $example->runAllExamples();
} else {
    echo "This script should be run from the command line.\n";
    echo "Usage: php examples/bulk-operations-example.php\n";
}

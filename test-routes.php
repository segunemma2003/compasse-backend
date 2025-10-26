<?php

/**
 * Simple Route Testing Script
 *
 * This script tests all API routes locally to ensure they're working properly.
 * Run this script after setting up your local environment.
 */

require_once 'vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class RouteTester
{
    private $baseUrl;
    private $token;
    private $tenantId;
    private $results = [];

    public function __construct($baseUrl = 'http://localhost:8000')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Test a single route
     */
    public function testRoute($method, $url, $headers = [], $data = null)
    {
        $fullUrl = $this->baseUrl . $url;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = 'Content-Type: application/json';
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        curl_close($ch);

        $result = [
            'method' => $method,
            'url' => $url,
            'status' => $httpCode,
            'success' => $httpCode >= 200 && $httpCode < 300,
            'response' => json_decode($body, true),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        $this->results[] = $result;

        return $result;
    }

    /**
     * Test all routes
     */
    public function testAllRoutes()
    {
        echo "🚀 Starting Route Testing...\n\n";

        // First, get authentication token
        $this->authenticate();

        if (!$this->token) {
            echo "❌ Authentication failed. Please check your credentials.\n";
            return;
        }

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'X-Tenant-ID: ' . $this->tenantId,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        // Test routes
        $this->testPublicRoutes();
        $this->testAuthRoutes();
        $this->testSchoolRoutes();
        $this->testStudentRoutes();
        $this->testTeacherRoutes();
        $this->testClassRoutes();
        $this->testSubjectRoutes();
        $this->testGuardianRoutes();
        $this->testLivestreamRoutes();
        $this->testSubscriptionRoutes();
        $this->testFileUploadRoutes();
        $this->testReportRoutes();

        $this->generateReport();
    }

    /**
     * Test public routes
     */
    private function testPublicRoutes()
    {
        echo "📋 Testing Public Routes...\n";

        $this->testRoute('GET', '/api/health');
        $this->testRoute('GET', '/api/v1/auth/me');
    }

    /**
     * Test authentication routes
     */
    private function testAuthRoutes()
    {
        echo "🔐 Testing Authentication Routes...\n";

        $loginData = [
            'email' => 'admin@samschool.com',
            'password' => 'password',
            'tenant_id' => 1,
            'school_id' => 1
        ];

        $this->testRoute('POST', '/api/v1/auth/login', [], $loginData);
        $this->testRoute('POST', '/api/v1/auth/register', [], $loginData);
        $this->testRoute('POST', '/api/v1/auth/logout', $this->getAuthHeaders());
        $this->testRoute('POST', '/api/v1/auth/refresh', $this->getAuthHeaders());
    }

    /**
     * Test school routes
     */
    private function testSchoolRoutes()
    {
        echo "🏫 Testing School Routes...\n";

        $this->testRoute('GET', '/api/v1/schools/1', $this->getAuthHeaders());
        $this->testRoute('PUT', '/api/v1/schools/1', $this->getAuthHeaders(), [
            'name' => 'Updated School Name'
        ]);
        $this->testRoute('GET', '/api/v1/schools/1/stats', $this->getAuthHeaders());
        $this->testRoute('GET', '/api/v1/schools/1/dashboard', $this->getAuthHeaders());
        $this->testRoute('GET', '/api/v1/schools/1/organogram', $this->getAuthHeaders());
    }

    /**
     * Test student routes
     */
    private function testStudentRoutes()
    {
        echo "👨‍🎓 Testing Student Routes...\n";

        $this->testRoute('GET', '/api/v1/students', $this->getAuthHeaders());
        $this->testRoute('POST', '/api/v1/students', $this->getAuthHeaders(), [
            'user_id' => 1,
            'admission_number' => 'ADM001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@student.com',
            'admission_date' => '2024-01-01',
            'class_id' => 1,
            'arm_id' => 1
        ]);
        $this->testRoute('GET', '/api/v1/students/1', $this->getAuthHeaders());
        $this->testRoute('PUT', '/api/v1/students/1', $this->getAuthHeaders(), [
            'first_name' => 'Updated Name'
        ]);
        $this->testRoute('GET', '/api/v1/students/1/attendance', $this->getAuthHeaders());
        $this->testRoute('GET', '/api/v1/students/1/results', $this->getAuthHeaders());
        $this->testRoute('GET', '/api/v1/students/1/assignments', $this->getAuthHeaders());
        $this->testRoute('GET', '/api/v1/students/1/subjects', $this->getAuthHeaders());
        $this->testRoute('DELETE', '/api/v1/students/1', $this->getAuthHeaders());
    }

    /**
     * Test teacher routes
     */
    private function testTeacherRoutes()
    {
        echo "👨‍🏫 Testing Teacher Routes...\n";

        $this->testRoute('GET', '/api/v1/teachers', $this->getAuthHeaders());
        $this->testRoute('POST', '/api/v1/teachers', $this->getAuthHeaders(), [
            'user_id' => 1,
            'employee_id' => 'EMP001',
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.smith@teacher.com',
            'employment_date' => '2024-01-01',
            'department_id' => 1
        ]);
        $this->testRoute('GET', '/api/v1/teachers/1', $this->getAuthHeaders());
        $this->testRoute('PUT', '/api/v1/teachers/1', $this->getAuthHeaders(), [
            'first_name' => 'Updated Name'
        ]);
        $this->testRoute('GET', '/api/v1/teachers/1/classes', $this->getAuthHeaders());
        $this->testRoute('GET', '/api/v1/teachers/1/subjects', $this->getAuthHeaders());
        $this->testRoute('GET', '/api/v1/teachers/1/students', $this->getAuthHeaders());
        $this->testRoute('DELETE', '/api/v1/teachers/1', $this->getAuthHeaders());
    }

    /**
     * Test class routes
     */
    private function testClassRoutes()
    {
        echo "📚 Testing Class Routes...\n";

        $this->testRoute('GET', '/api/v1/classes', $this->getAuthHeaders());
        $this->testRoute('POST', '/api/v1/classes', $this->getAuthHeaders(), [
            'name' => 'Grade 10',
            'description' => 'Grade 10 Class',
            'academic_year_id' => 1,
            'term_id' => 1
        ]);
        $this->testRoute('GET', '/api/v1/classes/1', $this->getAuthHeaders());
        $this->testRoute('PUT', '/api/v1/classes/1', $this->getAuthHeaders(), [
            'name' => 'Updated Class Name'
        ]);
        $this->testRoute('DELETE', '/api/v1/classes/1', $this->getAuthHeaders());
    }

    /**
     * Test subject routes
     */
    private function testSubjectRoutes()
    {
        echo "📖 Testing Subject Routes...\n";

        $this->testRoute('GET', '/api/v1/subjects', $this->getAuthHeaders());
        $this->testRoute('POST', '/api/v1/subjects', $this->getAuthHeaders(), [
            'name' => 'Mathematics',
            'code' => 'MATH101',
            'description' => 'Basic Mathematics',
            'department_id' => 1
        ]);
        $this->testRoute('GET', '/api/v1/subjects/1', $this->getAuthHeaders());
        $this->testRoute('PUT', '/api/v1/subjects/1', $this->getAuthHeaders(), [
            'name' => 'Updated Subject Name'
        ]);
        $this->testRoute('DELETE', '/api/v1/subjects/1', $this->getAuthHeaders());
    }

    /**
     * Test guardian routes
     */
    private function testGuardianRoutes()
    {
        echo "👨‍👩‍👧‍👦 Testing Guardian Routes...\n";

        $this->testRoute('GET', '/api/v1/guardians', $this->getAuthHeaders());
        $this->testRoute('POST', '/api/v1/guardians', $this->getAuthHeaders(), [
            'user_id' => 1,
            'first_name' => 'Parent',
            'last_name' => 'Guardian',
            'email' => 'parent@guardian.com',
            'phone' => '+1234567890',
            'relationship_to_student' => 'Mother'
        ]);
        $this->testRoute('GET', '/api/v1/guardians/1', $this->getAuthHeaders());
        $this->testRoute('PUT', '/api/v1/guardians/1', $this->getAuthHeaders(), [
            'first_name' => 'Updated Parent'
        ]);
        $this->testRoute('POST', '/api/v1/guardians/1/assign-student', $this->getAuthHeaders(), [
            'student_id' => 1
        ]);
        $this->testRoute('GET', '/api/v1/guardians/1/students', $this->getAuthHeaders());
        $this->testRoute('DELETE', '/api/v1/guardians/1', $this->getAuthHeaders());
    }

    /**
     * Test livestream routes
     */
    private function testLivestreamRoutes()
    {
        echo "📹 Testing Livestream Routes...\n";

        $this->testRoute('GET', '/api/v1/livestreams', $this->getAuthHeaders());
        $this->testRoute('POST', '/api/v1/livestreams', $this->getAuthHeaders(), [
            'teacher_id' => 1,
            'class_id' => 1,
            'subject_id' => 1,
            'title' => 'Math Lesson',
            'description' => 'Basic Math Concepts',
            'start_time' => '2024-12-25 10:00:00',
            'duration_minutes' => 60
        ]);
        $this->testRoute('GET', '/api/v1/livestreams/1', $this->getAuthHeaders());
        $this->testRoute('POST', '/api/v1/livestreams/1/join', $this->getAuthHeaders(), [
            'student_id' => 1
        ]);
        $this->testRoute('GET', '/api/v1/livestreams/1/attendance', $this->getAuthHeaders());
        $this->testRoute('POST', '/api/v1/livestreams/1/start', $this->getAuthHeaders());
        $this->testRoute('POST', '/api/v1/livestreams/1/end', $this->getAuthHeaders());
    }

    /**
     * Test subscription routes
     */
    private function testSubscriptionRoutes()
    {
        echo "💳 Testing Subscription Routes...\n";

        $this->testRoute('GET', '/api/v1/subscriptions/plans', $this->getAuthHeaders());
        $this->testRoute('GET', '/api/v1/subscriptions/modules', $this->getAuthHeaders());
        $this->testRoute('GET', '/api/v1/subscriptions/status', $this->getAuthHeaders());
        $this->testRoute('POST', '/api/v1/subscriptions/create', $this->getAuthHeaders(), [
            'plan_id' => 1
        ]);
        $this->testRoute('GET', '/api/v1/subscriptions/modules/student_management/access', $this->getAuthHeaders());
        $this->testRoute('GET', '/api/v1/subscriptions/school/modules', $this->getAuthHeaders());
        $this->testRoute('GET', '/api/v1/subscriptions/school/limits', $this->getAuthHeaders());
    }

    /**
     * Test file upload routes
     */
    private function testFileUploadRoutes()
    {
        echo "📁 Testing File Upload Routes...\n";

        $this->testRoute('GET', '/api/v1/uploads/presigned-urls', $this->getAuthHeaders(), [
            'type' => 'profile_picture',
            'entity_type' => 'student',
            'entity_id' => 1
        ]);
        $this->testRoute('DELETE', '/api/v1/uploads/test-file.jpg', $this->getAuthHeaders());
    }

    /**
     * Test report routes
     */
    private function testReportRoutes()
    {
        echo "📊 Testing Report Routes...\n";

        $this->testRoute('GET', '/api/v1/reports/academic', $this->getAuthHeaders());
        $this->testRoute('GET', '/api/v1/reports/financial', $this->getAuthHeaders());
        $this->testRoute('GET', '/api/v1/reports/attendance', $this->getAuthHeaders());
        $this->testRoute('GET', '/api/v1/reports/performance', $this->getAuthHeaders());
    }

    /**
     * Authenticate and get token
     */
    private function authenticate()
    {
        $loginData = [
            'email' => 'admin@samschool.com',
            'password' => 'password',
            'tenant_id' => 1,
            'school_id' => 1
        ];

        $result = $this->testRoute('POST', '/api/v1/auth/login', [], $loginData);

        if ($result['success'] && isset($result['response']['token'])) {
            $this->token = $result['response']['token'];
            $this->tenantId = 1;
            echo "✅ Authentication successful\n";
        } else {
            echo "❌ Authentication failed\n";
        }
    }

    /**
     * Get authentication headers
     */
    private function getAuthHeaders()
    {
        return [
            'Authorization: Bearer ' . $this->token,
            'X-Tenant-ID: ' . $this->tenantId,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
    }

    /**
     * Generate test report
     */
    private function generateReport()
    {
        $totalTests = count($this->results);
        $successfulTests = array_filter($this->results, function($result) {
            return $result['success'];
        });
        $successCount = count($successfulTests);
        $failureCount = $totalTests - $successCount;

        echo "\n" . str_repeat("=", 60) . "\n";
        echo "📊 ROUTE TESTING REPORT\n";
        echo str_repeat("=", 60) . "\n";
        echo "Total Tests: {$totalTests}\n";
        echo "✅ Successful: {$successCount}\n";
        echo "❌ Failed: {$failureCount}\n";
        echo "Success Rate: " . round(($successCount / $totalTests) * 100, 2) . "%\n";
        echo str_repeat("=", 60) . "\n";

        if ($failureCount > 0) {
            echo "\n❌ FAILED TESTS:\n";
            echo str_repeat("-", 40) . "\n";

            foreach ($this->results as $result) {
                if (!$result['success']) {
                    echo "Method: {$result['method']} | URL: {$result['url']} | Status: {$result['status']}\n";
                }
            }
        }

        echo "\n🎉 Route testing completed!\n";
    }
}

// Run the tests
if (php_sapi_name() === 'cli') {
    $tester = new RouteTester();
    $tester->testAllRoutes();
} else {
    echo "This script should be run from the command line.\n";
    echo "Usage: php test-routes.php\n";
}

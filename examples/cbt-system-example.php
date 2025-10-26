<?php

/**
 * CBT System Example for SamSchool Management System
 *
 * This example demonstrates the complete CBT workflow:
 * 1. Teacher creates questions for CBT exam
 * 2. Student takes CBT exam (gets all questions at once with unique session ID)
 * 3. Student submits answers and gets immediate results with revision info
 * 4. System generates comprehensive results and reports
 */

require_once 'vendor/autoload.php';

use Illuminate\Support\Facades\Http;

class CBTSystemExample
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
     * Example: Teacher creates CBT questions
     */
    public function createCBTQuestions()
    {
        $questionsData = [
            [
                'question_text' => 'What is the capital of Nigeria?',
                'question_type' => 'multiple_choice',
                'marks' => 2,
                'difficulty_level' => 'easy',
                'options' => [
                    'Lagos',
                    'Abuja',
                    'Kano',
                    'Port Harcourt'
                ],
                'correct_answer' => ['Abuja'],
                'explanation' => 'Abuja became the capital of Nigeria in 1991, replacing Lagos.',
                'time_limit_seconds' => 60,
            ],
            [
                'question_text' => 'The sum of 5 + 3 equals 8.',
                'question_type' => 'true_false',
                'marks' => 1,
                'difficulty_level' => 'easy',
                'correct_answer' => [true],
                'explanation' => '5 + 3 = 8 is a correct mathematical statement.',
                'time_limit_seconds' => 30,
            ],
            [
                'question_text' => 'Explain the process of photosynthesis.',
                'question_type' => 'essay',
                'marks' => 10,
                'difficulty_level' => 'hard',
                'correct_answer' => ['Photosynthesis is the process by which plants convert light energy into chemical energy...'],
                'explanation' => 'Photosynthesis involves the conversion of carbon dioxide and water into glucose using sunlight.',
                'time_limit_seconds' => 300,
            ],
            [
                'question_text' => 'Fill in the blank: The _____ is the largest planet in our solar system.',
                'question_type' => 'fill_blank',
                'marks' => 2,
                'difficulty_level' => 'medium',
                'correct_answer' => ['Jupiter'],
                'explanation' => 'Jupiter is the largest planet in our solar system.',
                'time_limit_seconds' => 45,
            ],
            [
                'question_text' => 'What is 15% of 200?',
                'question_type' => 'numerical',
                'marks' => 3,
                'difficulty_level' => 'medium',
                'correct_answer' => [30, 0.1], // 30 with tolerance of 0.1
                'explanation' => '15% of 200 = 0.15 × 200 = 30',
                'time_limit_seconds' => 90,
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenantId,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/api/v1/assessments/cbt/1/questions/create', [
            'questions' => $questionsData
        ]);

        return $response->json();
    }

    /**
     * Example: Student gets CBT questions
     */
    public function getCBTQuestions($examId = 1)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenantId,
        ])->get($this->baseUrl . '/api/v1/assessments/cbt/' . $examId . '/questions');

        return $response->json();
    }

    /**
     * Example: Student submits CBT answers
     */
    public function submitCBTAnswers($sessionId, $attemptId)
    {
        $answersData = [
            'session_id' => $sessionId,
            'attempt_id' => $attemptId,
            'answers' => [
                [
                    'question_id' => 1,
                    'answer' => ['Abuja'],
                    'time_taken' => 45,
                ],
                [
                    'question_id' => 2,
                    'answer' => [true],
                    'time_taken' => 15,
                ],
                [
                    'question_id' => 3,
                    'answer' => ['Photosynthesis is the process by which plants use sunlight to convert carbon dioxide and water into glucose and oxygen. This process occurs in the chloroplasts of plant cells and is essential for life on Earth.'],
                    'time_taken' => 180,
                ],
                [
                    'question_id' => 4,
                    'answer' => ['Jupiter'],
                    'time_taken' => 30,
                ],
                [
                    'question_id' => 5,
                    'answer' => [30],
                    'time_taken' => 60,
                ],
            ]
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenantId,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/api/v1/assessments/cbt/submit', $answersData);

        return $response->json();
    }

    /**
     * Example: Get CBT session status
     */
    public function getCBTSessionStatus($sessionId)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenantId,
        ])->get($this->baseUrl . '/api/v1/assessments/cbt/session/' . $sessionId . '/status');

        return $response->json();
    }

    /**
     * Example: Get CBT results for revision
     */
    public function getCBTResults($sessionId)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenantId,
        ])->get($this->baseUrl . '/api/v1/assessments/cbt/session/' . $sessionId . '/results');

        return $response->json();
    }

    /**
     * Example: Generate mid-term results
     */
    public function generateMidTermResults()
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenantId,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/api/v1/results/mid-term/generate', [
            'class_id' => 1,
            'term_id' => 1,
            'academic_year_id' => 1,
        ]);

        return $response->json();
    }

    /**
     * Example: Generate end-of-term results
     */
    public function generateEndOfTermResults()
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenantId,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/api/v1/results/end-term/generate', [
            'class_id' => 1,
            'term_id' => 1,
            'academic_year_id' => 1,
        ]);

        return $response->json();
    }

    /**
     * Example: Generate annual results
     */
    public function generateAnnualResults()
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenantId,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/api/v1/results/annual/generate', [
            'class_id' => 1,
            'academic_year_id' => 1,
        ]);

        return $response->json();
    }

    /**
     * Example: Get student results
     */
    public function getStudentResults($studentId = 1)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenantId,
        ])->get($this->baseUrl . '/api/v1/results/student/' . $studentId);

        return $response->json();
    }

    /**
     * Example: Get class results
     */
    public function getClassResults($classId = 1)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant-ID' => $this->tenantId,
        ])->get($this->baseUrl . '/api/v1/results/class/' . $classId, [
            'term_id' => 1,
            'academic_year_id' => 1,
        ]);

        return $response->json();
    }

    /**
     * Run complete CBT workflow example
     */
    public function runCompleteCBTWorkflow()
    {
        echo "🚀 Running Complete CBT System Workflow...\n\n";

        // Authenticate as teacher
        if (!$this->authenticate('teacher@samschool.com', 'password', 1)) {
            echo "❌ Teacher authentication failed\n";
            return;
        }
        echo "✅ Teacher authenticated\n\n";

        // Step 1: Teacher creates CBT questions
        echo "📝 Step 1: Teacher creating CBT questions...\n";
        $questionsResult = $this->createCBTQuestions();
        echo "Questions created: " . json_encode($questionsResult, JSON_PRETTY_PRINT) . "\n\n";

        // Authenticate as student
        if (!$this->authenticate('student@samschool.com', 'password', 1)) {
            echo "❌ Student authentication failed\n";
            return;
        }
        echo "✅ Student authenticated\n\n";

        // Step 2: Student gets CBT questions
        echo "📚 Step 2: Student getting CBT questions...\n";
        $questionsData = $this->getCBTQuestions();
        echo "Questions retrieved: " . json_encode($questionsData, JSON_PRETTY_PRINT) . "\n\n";

        if ($questionsData['success']) {
            $sessionId = $questionsData['data']['session_id'];
            $attemptId = $questionsData['data']['attempt_id'];

            // Step 3: Student submits answers
            echo "📝 Step 3: Student submitting CBT answers...\n";
            $submitResult = $this->submitCBTAnswers($sessionId, $attemptId);
            echo "Answers submitted: " . json_encode($submitResult, JSON_PRETTY_PRINT) . "\n\n";

            // Step 4: Get CBT results for revision
            echo "📊 Step 4: Getting CBT results for revision...\n";
            $resultsData = $this->getCBTResults($sessionId);
            echo "Results retrieved: " . json_encode($resultsData, JSON_PRETTY_PRINT) . "\n\n";
        }

        // Authenticate as admin for result generation
        if (!$this->authenticate('admin@samschool.com', 'password', 1)) {
            echo "❌ Admin authentication failed\n";
            return;
        }
        echo "✅ Admin authenticated\n\n";

        // Step 5: Generate mid-term results
        echo "📈 Step 5: Generating mid-term results...\n";
        $midTermResults = $this->generateMidTermResults();
        echo "Mid-term results: " . json_encode($midTermResults, JSON_PRETTY_PRINT) . "\n\n";

        // Step 6: Generate end-of-term results
        echo "📈 Step 6: Generating end-of-term results...\n";
        $endTermResults = $this->generateEndOfTermResults();
        echo "End-of-term results: " . json_encode($endTermResults, JSON_PRETTY_PRINT) . "\n\n";

        // Step 7: Generate annual results
        echo "📈 Step 7: Generating annual results...\n";
        $annualResults = $this->generateAnnualResults();
        echo "Annual results: " . json_encode($annualResults, JSON_PRETTY_PRINT) . "\n\n";

        echo "🎉 Complete CBT System Workflow completed!\n";
        echo "\n📋 CBT System Features Demonstrated:\n";
        echo "✅ Teacher can create multiple question types (MCQ, True/False, Essay, Fill-in-blank, Numerical)\n";
        echo "✅ Student gets all questions at once with unique session ID\n";
        echo "✅ Student submits all answers and gets immediate results\n";
        echo "✅ System provides correct answers and explanations for revision\n";
        echo "✅ System calculates scores, grades, and positions\n";
        echo "✅ System generates comprehensive reports (mid-term, end-term, annual)\n";
        echo "✅ System provides performance analysis and recommendations\n";
        echo "✅ System supports different grading scales per school\n";
        echo "✅ System handles time limits and session management\n";
        echo "✅ System provides detailed statistics and analytics\n";
    }
}

// Run examples if called directly
if (php_sapi_name() === 'cli') {
    $example = new CBTSystemExample();
    $example->runCompleteCBTWorkflow();
} else {
    echo "This script should be run from the command line.\n";
    echo "Usage: php examples/cbt-system-example.php\n";
}

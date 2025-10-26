<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIService
{
    protected $apiKey;
    protected $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('ai.openai_api_key');
        $this->baseUrl = config('ai.openai_base_url', 'https://api.openai.com/v1');
    }

    /**
     * Generate lesson notes using AI
     */
    public function generateLessonNotes(array $data): array
    {
        $prompt = $this->buildLessonNotesPrompt($data);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/chat/completions', [
                'model' => 'gpt-4',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert educational content creator specializing in creating comprehensive lesson notes for school teachers.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => 2000,
                'temperature' => 0.7,
            ]);

            if ($response->successful()) {
                $content = $response->json('choices.0.message.content');
                return $this->parseLessonNotes($content);
            }

            throw new \Exception('AI service request failed: ' . $response->body());

        } catch (\Exception $e) {
            Log::error('AI Service Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate exam questions using AI
     */
    public function generateExamQuestions(array $data): array
    {
        $prompt = $this->buildExamQuestionsPrompt($data);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/chat/completions', [
                'model' => 'gpt-4',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert educational assessment specialist who creates high-quality exam questions for various subjects and grade levels.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => 3000,
                'temperature' => 0.8,
            ]);

            if ($response->successful()) {
                $content = $response->json('choices.0.message.content');
                return $this->parseExamQuestions($content);
            }

            throw new \Exception('AI service request failed: ' . $response->body());

        } catch (\Exception $e) {
            Log::error('AI Service Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate assignment feedback using AI
     */
    public function generateAssignmentFeedback(array $data): string
    {
        $prompt = $this->buildFeedbackPrompt($data);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/chat/completions', [
                'model' => 'gpt-4',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert teacher who provides constructive feedback on student assignments. Focus on being encouraging while pointing out areas for improvement.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => 1000,
                'temperature' => 0.6,
            ]);

            if ($response->successful()) {
                return $response->json('choices.0.message.content');
            }

            throw new \Exception('AI service request failed: ' . $response->body());

        } catch (\Exception $e) {
            Log::error('AI Service Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Build lesson notes prompt
     */
    protected function buildLessonNotesPrompt(array $data): string
    {
        return "Create comprehensive lesson notes for the following:

        Subject: {$data['subject']}
        Topic: {$data['topic']}
        Grade Level: {$data['grade_level']}
        Duration: {$data['duration']} minutes
        Learning Objectives: " . implode(', ', $data['objectives'] ?? []) . "

        Please include:
        1. Learning objectives
        2. Introduction/Engagement activity
        3. Main content with key concepts
        4. Examples and illustrations
        5. Activities and exercises
        6. Assessment questions
        7. Summary and conclusion
        8. Homework/Extension activities

        Format the response in a structured manner suitable for teachers to use in class.";
    }

    /**
     * Build exam questions prompt
     */
    protected function buildExamQuestionsPrompt(array $data): string
    {
        return "Generate exam questions for the following:

        Subject: {$data['subject']}
        Topic: {$data['topic']}
        Grade Level: {$data['grade_level']}
        Question Types: " . implode(', ', $data['question_types'] ?? ['multiple_choice', 'short_answer']) . "
        Number of Questions: {$data['number_of_questions']}
        Difficulty Level: {$data['difficulty']}

        Please provide:
        1. Multiple choice questions with 4 options each
        2. Short answer questions
        3. Essay questions (if requested)
        4. Answer keys for all questions
        5. Marking scheme

        Ensure questions are age-appropriate and cover the topic comprehensively.";
    }

    /**
     * Build feedback prompt
     */
    protected function buildFeedbackPrompt(array $data): string
    {
        return "Provide constructive feedback for this student assignment:

        Subject: {$data['subject']}
        Assignment: {$data['assignment_title']}
        Student Response: {$data['student_response']}
        Grade Level: {$data['grade_level']}
        Score: {$data['score']}/{$data['total_marks']}

        Please provide:
        1. Positive feedback on what the student did well
        2. Areas for improvement
        3. Specific suggestions for better performance
        4. Encouragement and motivation

        Keep the tone supportive and educational.";
    }

    /**
     * Parse lesson notes from AI response
     */
    protected function parseLessonNotes(string $content): array
    {
        // Extract structured content from AI response
        $sections = [
            'objectives' => $this->extractSection($content, 'Learning Objectives', 'Introduction'),
            'introduction' => $this->extractSection($content, 'Introduction', 'Main Content'),
            'main_content' => $this->extractSection($content, 'Main Content', 'Activities'),
            'activities' => $this->extractSection($content, 'Activities', 'Assessment'),
            'assessment' => $this->extractSection($content, 'Assessment', 'Summary'),
            'summary' => $this->extractSection($content, 'Summary', 'Homework'),
            'homework' => $this->extractSection($content, 'Homework', null),
        ];

        return [
            'content' => $content,
            'sections' => $sections,
            'word_count' => str_word_count($content),
            'estimated_duration' => $this->estimateDuration($content),
        ];
    }

    /**
     * Parse exam questions from AI response
     */
    protected function parseExamQuestions(string $content): array
    {
        $questions = [];
        $lines = explode("\n", $content);
        $currentQuestion = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if (preg_match('/^\d+\./', $line)) {
                if ($currentQuestion) {
                    $questions[] = $currentQuestion;
                }
                $currentQuestion = [
                    'question' => $line,
                    'options' => [],
                    'answer' => '',
                    'type' => 'multiple_choice'
                ];
            } elseif (preg_match('/^[A-D]\./', $line)) {
                if ($currentQuestion) {
                    $currentQuestion['options'][] = $line;
                }
            } elseif (strpos($line, 'Answer:') === 0) {
                if ($currentQuestion) {
                    $currentQuestion['answer'] = $line;
                }
            }
        }

        if ($currentQuestion) {
            $questions[] = $currentQuestion;
        }

        return [
            'questions' => $questions,
            'total_questions' => count($questions),
            'content' => $content,
        ];
    }

    /**
     * Extract section from content
     */
    protected function extractSection(string $content, string $startMarker, ?string $endMarker): string
    {
        $startPos = stripos($content, $startMarker);
        if ($startPos === false) {
            return '';
        }

        $startPos += strlen($startMarker);

        if ($endMarker) {
            $endPos = stripos($content, $endMarker, $startPos);
            if ($endPos === false) {
                return trim(substr($content, $startPos));
            }
            return trim(substr($content, $startPos, $endPos - $startPos));
        }

        return trim(substr($content, $startPos));
    }

    /**
     * Estimate lesson duration based on content
     */
    protected function estimateDuration(string $content): int
    {
        $wordCount = str_word_count($content);
        $baseMinutes = 45; // Base lesson duration

        // Estimate additional time based on content complexity
        $additionalMinutes = ceil($wordCount / 100); // 1 minute per 100 words

        return min($baseMinutes + $additionalMinutes, 120); // Cap at 2 hours
    }
}

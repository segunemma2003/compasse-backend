<?php

require __DIR__ . '/vendor/autoload.php';

$baseUrl = 'http://127.0.0.1:8000';
$credentialsFile = 'taiwo-school-credentials.json';

if (!file_exists($credentialsFile)) {
    echo "❌ Credentials file not found. Please run create-taiwo-school.php first.\n";
    exit(1);
}

$credentials = json_decode(file_get_contents($credentialsFile), true);
$schoolId = $credentials['school_id'] ?? null;
$tenantId = $credentials['tenant_id'] ?? null;
$adminToken = $credentials['admin_token'] ?? $credentials['superadmin_token'] ?? null;

if (!$schoolId || !$tenantId || !$adminToken) {
    echo "❌ Missing credentials. Please check taiwo-school-credentials.json\n";
    exit(1);
}

// Colors
$green = "\033[32m";
$red = "\033[31m";
$yellow = "\033[33m";
$cyan = "\033[36m";
$reset = "\033[0m";

function apiCall($method, $url, $data = null, $headers = []) {
    global $baseUrl;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl . $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $headers['Content-Type'] = 'application/json';
    }
    
    $headerArray = [];
    foreach ($headers as $key => $value) {
        $headerArray[] = "$key: $value";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'status' => $httpCode,
        'response' => json_decode($response, true),
        'raw' => $response
    ];
}

echo "{$cyan}=== Populating Taiwo International School ==={$reset}\n\n";

$headers = [
    'Authorization' => "Bearer {$adminToken}",
    'X-Tenant-ID' => $tenantId,
    'X-School-ID' => $schoolId
];

// Step 1: Verify school and database
echo "{$yellow}Step 1: Verifying school setup...{$reset}\n";
$schoolResult = apiCall('GET', "/api/v1/schools/{$schoolId}", null, $headers);
if ($schoolResult['status'] === 200) {
    echo "{$green}✅ School verified{$reset}\n";
    $school = $schoolResult['response']['school'] ?? $schoolResult['response'];
    echo "   Name: {$school['name']}\n";
    echo "   Database: " . ($school['database_name'] ?? 'N/A') . "\n\n";
} else {
    echo "{$red}❌ Failed to verify school: {$schoolResult['status']}{$reset}\n";
    echo "Response: " . substr($schoolResult['raw'], 0, 200) . "\n\n";
}

// Step 2: Create Academic Year
echo "{$yellow}Step 2: Creating Academic Year...{$reset}\n";
$academicYearData = [
    'name' => '2024/2025',
    'start_date' => '2024-09-01',
    'end_date' => '2025-08-31',
    'is_current' => true
];
$academicYearResult = apiCall('POST', '/api/v1/academic-years', $academicYearData, $headers);
if ($academicYearResult['status'] === 201 || $academicYearResult['status'] === 200) {
    $academicYearId = $academicYearResult['response']['academic_year']['id'] ?? $academicYearResult['response']['id'] ?? null;
    echo "{$green}✅ Academic Year created{$reset}\n";
    echo "   ID: {$academicYearId}\n\n";
} else {
    echo "{$yellow}⚠️  Academic Year creation: {$academicYearResult['status']}{$reset}\n";
    echo "Response: " . substr($academicYearResult['raw'], 0, 200) . "\n\n";
    $academicYearId = null;
}

// Step 3: Create Terms
echo "{$yellow}Step 3: Creating Terms...{$reset}\n";
$terms = [
    ['name' => 'First Term', 'start_date' => '2024-09-01', 'end_date' => '2024-12-15', 'is_current' => true],
    ['name' => 'Second Term', 'start_date' => '2025-01-08', 'end_date' => '2025-04-15', 'is_current' => false],
    ['name' => 'Third Term', 'start_date' => '2025-04-22', 'end_date' => '2025-08-31', 'is_current' => false]
];
$termIds = [];
foreach ($terms as $termData) {
    if ($academicYearId) {
        $termData['academic_year_id'] = $academicYearId;
    }
    $termResult = apiCall('POST', '/api/v1/terms', $termData, $headers);
    if ($termResult['status'] === 201 || $termResult['status'] === 200) {
        $termId = $termResult['response']['term']['id'] ?? $termResult['response']['id'] ?? null;
        if ($termId) {
            $termIds[] = $termId;
            echo "{$green}✅ {$termData['name']} created (ID: {$termId}){$reset}\n";
        }
    } else {
        echo "{$yellow}⚠️  {$termData['name']} creation: {$termResult['status']}{$reset}\n";
    }
}
echo "\n";

// Step 4: Create Classes
echo "{$yellow}Step 4: Creating Classes...{$reset}\n";
$classes = [
    ['name' => 'JSS 1A', 'level' => 'JSS 1', 'capacity' => 40],
    ['name' => 'JSS 1B', 'level' => 'JSS 1', 'capacity' => 40],
    ['name' => 'JSS 2A', 'level' => 'JSS 2', 'capacity' => 40],
    ['name' => 'SS 1A', 'level' => 'SS 1', 'capacity' => 35],
    ['name' => 'SS 2A', 'level' => 'SS 2', 'capacity' => 35],
];
$classIds = [];
foreach ($classes as $classData) {
    $classResult = apiCall('POST', '/api/v1/classes', $classData, $headers);
    if ($classResult['status'] === 201 || $classResult['status'] === 200) {
        $classId = $classResult['response']['class']['id'] ?? $classResult['response']['id'] ?? null;
        if ($classId) {
            $classIds[] = $classId;
            echo "{$green}✅ {$classData['name']} created (ID: {$classId}){$reset}\n";
        }
    } else {
        echo "{$yellow}⚠️  {$classData['name']} creation: {$classResult['status']}{$reset}\n";
    }
}
echo "\n";

// Step 5: Create Subjects
echo "{$yellow}Step 5: Creating Subjects...{$reset}\n";
$subjects = [
    ['name' => 'Mathematics', 'code' => 'MATH', 'type' => 'core'],
    ['name' => 'English Language', 'code' => 'ENG', 'type' => 'core'],
    ['name' => 'Physics', 'code' => 'PHY', 'type' => 'science'],
    ['name' => 'Chemistry', 'code' => 'CHEM', 'type' => 'science'],
    ['name' => 'Biology', 'code' => 'BIO', 'type' => 'science'],
    ['name' => 'Economics', 'code' => 'ECO', 'type' => 'arts'],
    ['name' => 'Geography', 'code' => 'GEO', 'type' => 'arts'],
];
$subjectIds = [];
foreach ($subjects as $subjectData) {
    $subjectResult = apiCall('POST', '/api/v1/subjects', $subjectData, $headers);
    if ($subjectResult['status'] === 201 || $subjectResult['status'] === 200) {
        $subjectId = $subjectResult['response']['subject']['id'] ?? $subjectResult['response']['id'] ?? null;
        if ($subjectId) {
            $subjectIds[] = $subjectId;
            echo "{$green}✅ {$subjectData['name']} created (ID: {$subjectId}){$reset}\n";
        }
    } else {
        echo "{$yellow}⚠️  {$subjectData['name']} creation: {$subjectResult['status']}{$reset}\n";
    }
}
echo "\n";

// Step 6: Create Teachers
echo "{$yellow}Step 6: Creating Teachers...{$reset}\n";
$teachers = [
    ['first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john.doe@taiwointernational.com', 'phone' => '+234-801-234-5678', 'employee_id' => 'TCH001'],
    ['first_name' => 'Jane', 'last_name' => 'Smith', 'email' => 'jane.smith@taiwointernational.com', 'phone' => '+234-802-234-5678', 'employee_id' => 'TCH002'],
    ['first_name' => 'Michael', 'last_name' => 'Johnson', 'email' => 'michael.johnson@taiwointernational.com', 'phone' => '+234-803-234-5678', 'employee_id' => 'TCH003'],
    ['first_name' => 'Sarah', 'last_name' => 'Williams', 'email' => 'sarah.williams@taiwointernational.com', 'phone' => '+234-804-234-5678', 'employee_id' => 'TCH004'],
];
$teacherIds = [];
foreach ($teachers as $teacherData) {
    $teacherResult = apiCall('POST', '/api/v1/teachers', $teacherData, $headers);
    if ($teacherResult['status'] === 201 || $teacherResult['status'] === 200) {
        $teacherId = $teacherResult['response']['teacher']['id'] ?? $teacherResult['response']['id'] ?? null;
        if ($teacherId) {
            $teacherIds[] = $teacherId;
            echo "{$green}✅ {$teacherData['first_name']} {$teacherData['last_name']} created (ID: {$teacherId}){$reset}\n";
        }
    } else {
        echo "{$yellow}⚠️  {$teacherData['first_name']} {$teacherData['last_name']} creation: {$teacherResult['status']}{$reset}\n";
        echo "   Response: " . substr($teacherResult['raw'], 0, 150) . "\n";
    }
}
echo "\n";

// Step 7: Create Students
echo "{$yellow}Step 7: Creating Students...{$reset}\n";
$students = [];
for ($i = 1; $i <= 20; $i++) {
    $classId = null;
    if (!empty($classIds)) {
        $classId = $classIds[array_rand($classIds)];
    }
    $students[] = [
        'first_name' => "Student{$i}",
        'last_name' => "Lastname{$i}",
        'admission_number' => "TAIWO{$i}",
        'class_id' => $classId,
        'date_of_birth' => '2010-01-01',
        'gender' => ($i % 2 === 0) ? 'male' : 'female',
        'email' => "student{$i}@taiwointernational.com",
        'phone' => "+234-810-000-{$i}",
    ];
}
$studentIds = [];
foreach ($students as $studentData) {
    $studentResult = apiCall('POST', '/api/v1/students', $studentData, $headers);
    if ($studentResult['status'] === 201 || $studentResult['status'] === 200) {
        $studentId = $studentResult['response']['student']['id'] ?? $studentResult['response']['id'] ?? null;
        if ($studentId) {
            $studentIds[] = $studentId;
        }
    }
}
echo "{$green}✅ Created " . count($studentIds) . " students{$reset}\n\n";

// Step 8: Create Exam
echo "{$yellow}Step 8: Creating Exam...{$reset}\n";
$examData = [
    'title' => 'First Term Examination 2024/2025',
    'type' => 'examination',
    'academic_year_id' => $academicYearId,
    'term_id' => $termIds[0] ?? null,
    'start_date' => '2024-12-10',
    'end_date' => '2024-12-15',
    'instructions' => 'Answer all questions. Time allowed: 2 hours per paper.',
];
$examResult = apiCall('POST', '/api/v1/exams', $examData, $headers);
if ($examResult['status'] === 201 || $examResult['status'] === 200) {
    $examId = $examResult['response']['exam']['id'] ?? $examResult['response']['id'] ?? null;
    echo "{$green}✅ Exam created (ID: {$examId}){$reset}\n\n";
    
    // Step 9: Create Exam Questions
    if ($examId && !empty($subjectIds)) {
        echo "{$yellow}Step 9: Creating Exam Questions...{$reset}\n";
        $questions = [
            ['subject_id' => $subjectIds[0], 'question' => 'What is 2 + 2?', 'type' => 'multiple_choice', 'options' => ['A' => '3', 'B' => '4', 'C' => '5', 'D' => '6'], 'correct_answer' => 'B', 'marks' => 5],
            ['subject_id' => $subjectIds[0], 'question' => 'Solve for x: 2x + 5 = 15', 'type' => 'short_answer', 'correct_answer' => '5', 'marks' => 10],
            ['subject_id' => $subjectIds[1], 'question' => 'What is a noun?', 'type' => 'essay', 'marks' => 20],
            ['subject_id' => $subjectIds[2], 'question' => 'State Newton\'s First Law of Motion', 'type' => 'essay', 'marks' => 15],
            ['subject_id' => $subjectIds[3], 'question' => 'What is the chemical formula for water?', 'type' => 'short_answer', 'correct_answer' => 'H2O', 'marks' => 5],
        ];
        
        $questionCount = 0;
        foreach ($questions as $questionData) {
            $questionData['exam_id'] = $examId;
            $questionResult = apiCall('POST', '/api/v1/exams/' . $examId . '/questions', $questionData, $headers);
            if ($questionResult['status'] === 201 || $questionResult['status'] === 200) {
                $questionCount++;
                echo "{$green}✅ Question created{$reset}\n";
            } else {
                echo "{$yellow}⚠️  Question creation: {$questionResult['status']}{$reset}\n";
            }
        }
        echo "{$green}✅ Created {$questionCount} exam questions{$reset}\n\n";
    }
} else {
    echo "{$yellow}⚠️  Exam creation: {$examResult['status']}{$reset}\n";
    echo "Response: " . substr($examResult['raw'], 0, 200) . "\n\n";
}

echo "{$cyan}=== Population Complete ==={$reset}\n";
echo "School ID: {$schoolId}\n";
echo "Tenant ID: {$tenantId}\n";
echo "Academic Years: " . ($academicYearId ? '1' : '0') . "\n";
echo "Terms: " . count($termIds) . "\n";
echo "Classes: " . count($classIds) . "\n";
echo "Subjects: " . count($subjectIds) . "\n";
echo "Teachers: " . count($teacherIds) . "\n";
echo "Students: " . count($studentIds) . "\n";
echo "Exams: " . (isset($examId) ? '1' : '0') . "\n\n";


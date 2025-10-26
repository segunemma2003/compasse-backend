# CBT System and Result Generation Documentation

## Overview

The SamSchool Management System provides a comprehensive Computer-Based Testing (CBT) system with advanced result generation capabilities. Teachers can create various question types, students take exams with unique session IDs, and the system generates detailed results with revision information.

## CBT System Features

### 🎯 **Question Types Supported**

-   **Multiple Choice**: Single or multiple correct answers
-   **True/False**: Binary choice questions
-   **Essay**: Open-ended questions requiring manual grading
-   **Fill in the Blank**: Text completion questions
-   **Matching**: Pair matching questions
-   **Short Answer**: Brief text responses
-   **Numerical**: Mathematical calculations with tolerance

### 🔧 **CBT Workflow**

#### 1. Teacher Creates Questions

```http
POST /api/v1/assessments/cbt/{exam}/questions/create
```

**Request Body:**

```json
{
    "questions": [
        {
            "question_text": "What is the capital of Nigeria?",
            "question_type": "multiple_choice",
            "marks": 2,
            "difficulty_level": "easy",
            "options": ["Lagos", "Abuja", "Kano", "Port Harcourt"],
            "correct_answer": ["Abuja"],
            "explanation": "Abuja became the capital of Nigeria in 1991.",
            "time_limit_seconds": 60
        }
    ]
}
```

#### 2. Student Gets All Questions

```http
GET /api/v1/assessments/cbt/{exam}/questions
```

**Response:**

```json
{
    "success": true,
    "data": {
        "session_id": "uuid-here",
        "exam": {
            "id": 1,
            "name": "Mathematics Test",
            "duration_minutes": 60,
            "total_marks": 100
        },
        "questions": [
            {
                "id": 1,
                "question_text": "What is the capital of Nigeria?",
                "question_type": "multiple_choice",
                "options": ["Lagos", "Abuja", "Kano", "Port Harcourt"],
                "marks": 2,
                "time_limit_seconds": 60
            }
        ],
        "attempt_id": 1,
        "start_time": "2024-01-15T10:00:00Z",
        "time_remaining": 3600
    }
}
```

#### 3. Student Submits All Answers

```http
POST /api/v1/assessments/cbt/submit
```

**Request Body:**

```json
{
    "session_id": "uuid-here",
    "attempt_id": 1,
    "answers": [
        {
            "question_id": 1,
            "answer": ["Abuja"],
            "time_taken": 45
        }
    ]
}
```

**Response:**

```json
{
  "success": true,
  "data": {
    "session_id": "uuid-here",
    "summary": {
      "total_questions": 5,
      "correct_answers": 4,
      "total_marks": 20,
      "obtained_marks": 16,
      "percentage": 80.0,
      "grade": "A",
      "position": 3,
      "time_taken": 15
    },
    "question_results": [
      {
        "question_id": 1,
        "question_text": "What is the capital of Nigeria?",
        "student_answer": ["Abuja"],
        "correct_answer": ["Abuja"],
        "is_correct": true,
        "marks_obtained": 2,
        "explanation": "Abuja became the capital of Nigeria in 1991."
      }
    ],
    "revision_info": {
      "correct_answers": [...],
      "explanations": [...],
      "performance_analysis": {
        "overall_performance": {
          "accuracy_percentage": 80.0
        },
        "recommendations": [
          "Review topics covered in incorrect answers"
        ]
      }
    }
  }
}
```

## Result Generation System

### 📊 **Result Types**

#### 1. Mid-Term Results

```http
POST /api/v1/results/mid-term/generate
```

**Request Body:**

```json
{
    "class_id": 1,
    "term_id": 1,
    "academic_year_id": 1
}
```

**Features:**

-   Weighted assessment scores
-   Continuous assessment + Mid-term exam
-   Class ranking and statistics
-   Performance analysis

#### 2. End-of-Term Results

```http
POST /api/v1/results/end-term/generate
```

**Features:**

-   Comprehensive subject results
-   All assessments for the term
-   Class position and ranking
-   Grade distribution analysis

#### 3. Annual Results

```http
POST /api/v1/results/annual/generate
```

**Features:**

-   All terms combined
-   Yearly performance tracking
-   Promotion/repetition analysis
-   Academic progression

### 🎯 **Grading System**

#### Custom Grading Scales

Each school can set their own grading scale:

```json
{
    "scales": [
        { "min_percentage": 90, "max_percentage": 100, "grade": "A+" },
        { "min_percentage": 80, "max_percentage": 89, "grade": "A" },
        { "min_percentage": 70, "max_percentage": 79, "grade": "B+" },
        { "min_percentage": 60, "max_percentage": 69, "grade": "B" },
        { "min_percentage": 50, "max_percentage": 59, "grade": "C" },
        { "min_percentage": 40, "max_percentage": 49, "grade": "D" },
        { "min_percentage": 0, "max_percentage": 39, "grade": "F" }
    ]
}
```

#### Grade Descriptions

-   **A+**: Excellent (90-100%)
-   **A**: Very Good (80-89%)
-   **B+**: Good (70-79%)
-   **B**: Satisfactory (60-69%)
-   **C**: Average (50-59%)
-   **D**: Below Average (40-49%)
-   **F**: Fail (0-39%)

### 📈 **Result Statistics**

#### Class Statistics

```json
{
    "statistics": {
        "total_students": 30,
        "average_percentage": 75.5,
        "highest_percentage": 95.0,
        "lowest_percentage": 45.0,
        "grade_distribution": {
            "A+": 5,
            "A": 8,
            "B+": 10,
            "B": 4,
            "C": 2,
            "D": 1,
            "F": 0
        },
        "pass_rate": 96.7
    }
}
```

#### Performance Analysis

-   Subject-wise performance
-   Difficulty level analysis
-   Time management analysis
-   Improvement recommendations

## API Endpoints

### CBT Endpoints

| Method | Endpoint                                              | Description               |
| ------ | ----------------------------------------------------- | ------------------------- |
| GET    | `/api/v1/assessments/cbt/{exam}/questions`            | Get all questions for CBT |
| POST   | `/api/v1/assessments/cbt/submit`                      | Submit all answers        |
| GET    | `/api/v1/assessments/cbt/session/{sessionId}/status`  | Get session status        |
| GET    | `/api/v1/assessments/cbt/session/{sessionId}/results` | Get results for revision  |
| POST   | `/api/v1/assessments/cbt/{exam}/questions/create`     | Create CBT questions      |

### Result Generation Endpoints

| Method | Endpoint                              | Description                  |
| ------ | ------------------------------------- | ---------------------------- |
| POST   | `/api/v1/results/mid-term/generate`   | Generate mid-term results    |
| POST   | `/api/v1/results/end-term/generate`   | Generate end-of-term results |
| POST   | `/api/v1/results/annual/generate`     | Generate annual results      |
| GET    | `/api/v1/results/student/{studentId}` | Get student results          |
| GET    | `/api/v1/results/class/{classId}`     | Get class results            |
| POST   | `/api/v1/results/publish`             | Publish results              |
| POST   | `/api/v1/results/unpublish`           | Unpublish results            |

## Key Features

### 🔐 **Session Management**

-   Unique session IDs for each CBT attempt
-   Time tracking and session validation
-   Automatic session timeout
-   Session status monitoring

### 📊 **Real-time Scoring**

-   Immediate answer validation
-   Automatic grading for objective questions
-   Manual grading support for essays
-   Tolerance-based numerical grading

### 🎯 **Revision Support**

-   Correct answers provided after submission
-   Detailed explanations for each question
-   Performance analysis and recommendations
-   Study guidance based on weak areas

### 📈 **Comprehensive Reporting**

-   Individual student reports
-   Class performance analysis
-   Subject-wise statistics
-   Grade distribution charts
-   Progress tracking over time

### 🔧 **Advanced Features**

-   Question shuffling and randomization
-   Time limits per question
-   Difficulty level tracking
-   Performance analytics
-   Bulk result generation
-   Export capabilities

## Security Features

### 🛡️ **CBT Security**

-   Session-based authentication
-   Time-limited access
-   Answer validation
-   Anti-cheating measures
-   Secure session management

### 🔒 **Result Security**

-   Role-based access control
-   Result publishing controls
-   Audit trail for changes
-   Data encryption
-   Privacy protection

## Performance Optimization

### ⚡ **CBT Performance**

-   Efficient question loading
-   Optimized answer processing
-   Real-time result calculation
-   Minimal database queries
-   Caching for better performance

### 📊 **Result Performance**

-   Batch processing for large classes
-   Efficient statistical calculations
-   Optimized database queries
-   Background processing support
-   Caching for reports

## Usage Examples

### Teacher Workflow

1. Create exam with CBT settings
2. Add questions with various types
3. Set time limits and difficulty levels
4. Configure grading criteria
5. Publish exam for students

### Student Workflow

1. Access CBT exam
2. Receive all questions with session ID
3. Answer questions within time limits
4. Submit all answers at once
5. Get immediate results and revision info

### Admin Workflow

1. Generate various result types
2. Analyze class performance
3. Publish/unpublish results
4. Export reports
5. Monitor system performance

## Best Practices

### 📝 **Question Creation**

-   Use clear and unambiguous language
-   Provide appropriate time limits
-   Include helpful explanations
-   Test questions before publishing
-   Maintain question banks

### 🎯 **Result Management**

-   Regular backup of results
-   Proper access control
-   Timely result publication
-   Clear communication with stakeholders
-   Performance monitoring

### 🔧 **System Maintenance**

-   Regular database optimization
-   Performance monitoring
-   Security updates
-   User training
-   Documentation updates

## Support and Troubleshooting

### Common Issues

-   Session timeout during CBT
-   Question loading problems
-   Result calculation errors
-   Performance issues with large classes

### Solutions

-   Check session validity
-   Verify question data integrity
-   Monitor system resources
-   Optimize database queries
-   Scale system resources

For technical support or questions about the CBT and result generation system, please contact the development team or refer to the main API documentation.

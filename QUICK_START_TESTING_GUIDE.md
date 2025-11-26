# Quick Start Testing Guide

## 🚀 How to Test the New Assessment Features

### Prerequisites:
1. ✅ All code is committed and pushed to GitHub
2. ✅ All controllers and models are created
3. ✅ All routes are added
4. ⏳ Migration needs to be run on tenant databases

---

## Step 1: Run Migration on Tenant Database

### Option A: Run on Local Tenant Database
```bash
# Switch to a tenant database context
php artisan tenants:run "php artisan migrate --path=database/migrations/tenant"
```

### Option B: Run on Production Server
```bash
# SSH into production server
ssh your-server

# Navigate to project
cd /path/to/samschool-backend

# Clear caches
php artisan route:clear
php artisan config:clear
php artisan cache:clear

# Run tenant migrations
php artisan tenants:run "php artisan migrate --path=database/migrations/tenant"
```

---

## Step 2: Test Grading System

### 2.1 Get Default Grading System
```bash
curl -X GET "https://api.compasse.net/api/v1/assessments/grading-systems/default" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: YOUR_SCHOOL_SUBDOMAIN" \
  -H "Accept: application/json"
```

**Expected Response:**
```json
{
  "grading_system": {
    "id": 1,
    "name": "Standard Grading System",
    "grade_boundaries": [
      {"min": 90, "max": 100, "grade": "A", "remark": "Excellent"},
      {"min": 80, "max": 89, "grade": "B", "remark": "Very Good"},
      ...
    ],
    "pass_mark": 50,
    "is_default": true
  }
}
```

---

## Step 3: Create Continuous Assessment

### 3.1 Create CA Test
```bash
curl -X POST "https://api.compasse.net/api/v1/assessments/continuous-assessments" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: YOUR_SCHOOL_SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{
    "subject_id": 1,
    "class_id": 1,
    "term_id": 1,
    "academic_year_id": 1,
    "name": "CA Test 1",
    "type": "test",
    "total_marks": 20,
    "description": "First CA test"
  }'
```

### 3.2 Record CA Scores
```bash
curl -X POST "https://api.compasse.net/api/v1/assessments/continuous-assessments/1/record-scores" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: YOUR_SCHOOL_SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{
    "scores": [
      {"student_id": 1, "score": 18, "remarks": "Excellent"},
      {"student_id": 2, "score": 15, "remarks": "Very good"}
    ]
  }'
```

---

## Step 4: Create Psychomotor Assessment

```bash
curl -X POST "https://api.compasse.net/api/v1/assessments/psychomotor-assessments" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: YOUR_SCHOOL_SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{
    "student_id": 1,
    "term_id": 1,
    "academic_year_id": 1,
    "handwriting": 4,
    "drawing": 3,
    "sports": 5,
    "musical_skills": 4,
    "handling_tools": 4,
    "punctuality": 5,
    "neatness": 4,
    "politeness": 5,
    "honesty": 5,
    "relationship_with_others": 4,
    "self_control": 4,
    "attentiveness": 5,
    "perseverance": 4,
    "emotional_stability": 4,
    "teacher_comment": "Excellent student"
  }'
```

---

## Step 5: Generate Results

### 5.1 Generate Results for Class
```bash
curl -X POST "https://api.compasse.net/api/v1/assessments/results/generate" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: YOUR_SCHOOL_SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{
    "class_id": 1,
    "term_id": 1,
    "academic_year_id": 1
  }'
```

### 5.2 Get Student Result
```bash
curl -X GET "https://api.compasse.net/api/v1/assessments/results/student/1/1/1" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: YOUR_SCHOOL_SUBDOMAIN" \
  -H "Accept: application/json"
```

---

## Step 6: View Scoreboard

```bash
curl -X GET "https://api.compasse.net/api/v1/assessments/scoreboards/class/1?term_id=1&academic_year_id=1" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: YOUR_SCHOOL_SUBDOMAIN" \
  -H "Accept: application/json"
```

---

## Step 7: Get Report Card

```bash
curl -X GET "https://api.compasse.net/api/v1/assessments/report-cards/1/1/1" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: YOUR_SCHOOL_SUBDOMAIN" \
  -H "Accept: application/json"
```

---

## Step 8: View Analytics

### School Analytics
```bash
curl -X GET "https://api.compasse.net/api/v1/assessments/analytics/school?term_id=1&academic_year_id=1" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: YOUR_SCHOOL_SUBDOMAIN" \
  -H "Accept: application/json"
```

### Class Analytics
```bash
curl -X GET "https://api.compasse.net/api/v1/assessments/analytics/class/1?term_id=1&academic_year_id=1" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: YOUR_SCHOOL_SUBDOMAIN" \
  -H "Accept: application/json"
```

---

## Step 9: Promote Students

### Auto-Promote Based on Performance
```bash
curl -X POST "https://api.compasse.net/api/v1/assessments/promotions/auto-promote" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: YOUR_SCHOOL_SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{
    "from_class_id": 1,
    "to_class_id": 2,
    "academic_year_id": 1,
    "term_id": 3,
    "pass_mark": 50
  }'
```

---

## Complete Endpoint List

### Grading Systems (6 endpoints)
- `GET /api/v1/assessments/grading-systems` - List all
- `GET /api/v1/assessments/grading-systems/default` - Get default
- `POST /api/v1/assessments/grading-systems` - Create
- `PUT /api/v1/assessments/grading-systems/{id}` - Update
- `DELETE /api/v1/assessments/grading-systems/{id}` - Delete
- `POST /api/v1/assessments/grading-systems/calculate-grade` - Calculate grade

### Continuous Assessments (7 endpoints)
- `GET /api/v1/assessments/continuous-assessments` - List all
- `POST /api/v1/assessments/continuous-assessments` - Create
- `PUT /api/v1/assessments/continuous-assessments/{id}` - Update
- `DELETE /api/v1/assessments/continuous-assessments/{id}` - Delete
- `POST /api/v1/assessments/continuous-assessments/{id}/record-scores` - Record scores
- `GET /api/v1/assessments/continuous-assessments/{id}/scores` - Get scores
- `GET /api/v1/assessments/continuous-assessments/student/{studentId}/scores` - Get student scores

### Psychomotor Assessments (5 endpoints)
- `GET /api/v1/assessments/psychomotor-assessments/{studentId}/{termId}/{academicYearId}` - Get
- `POST /api/v1/assessments/psychomotor-assessments` - Create/Update
- `POST /api/v1/assessments/psychomotor-assessments/bulk` - Bulk create
- `GET /api/v1/assessments/psychomotor-assessments/class/{classId}` - Get by class
- `DELETE /api/v1/assessments/psychomotor-assessments/{id}` - Delete

### Results (6 endpoints)
- `POST /api/v1/assessments/results/generate` - Generate results
- `GET /api/v1/assessments/results/student/{studentId}/{termId}/{academicYearId}` - Get student result
- `GET /api/v1/assessments/results/class/{classId}` - Get class results
- `POST /api/v1/assessments/results/{resultId}/comments` - Add comments
- `POST /api/v1/assessments/results/{resultId}/approve` - Approve result
- `POST /api/v1/assessments/results/publish` - Publish results

### Scoreboards (5 endpoints)
- `GET /api/v1/assessments/scoreboards/class/{classId}` - Get class scoreboard
- `GET /api/v1/assessments/scoreboards/top-performers` - Get top performers
- `GET /api/v1/assessments/scoreboards/subject/{subjectId}/toppers` - Get subject toppers
- `POST /api/v1/assessments/scoreboards/refresh` - Refresh scoreboard
- `GET /api/v1/assessments/scoreboards/class-comparison` - Compare classes

### Report Cards (5 endpoints)
- `GET /api/v1/assessments/report-cards/{studentId}/{termId}/{academicYearId}` - Get report card
- `GET /api/v1/assessments/report-cards/{studentId}/{termId}/{academicYearId}/pdf` - Generate PDF
- `GET /api/v1/assessments/report-cards/{studentId}/{termId}/{academicYearId}/print` - Printable
- `POST /api/v1/assessments/report-cards/bulk-download` - Bulk download
- `POST /api/v1/assessments/report-cards/{studentId}/{termId}/{academicYearId}/email` - Email

### Analytics (6 endpoints)
- `GET /api/v1/assessments/analytics/school` - School analytics
- `GET /api/v1/assessments/analytics/class/{classId}` - Class analytics
- `GET /api/v1/assessments/analytics/subject/{subjectId}` - Subject analytics
- `GET /api/v1/assessments/analytics/student/{studentId}/trend` - Student trend
- `GET /api/v1/assessments/analytics/comparative` - Comparative analytics
- `GET /api/v1/assessments/analytics/student/{studentId}/prediction` - Prediction

### Promotions (7 endpoints)
- `GET /api/v1/assessments/promotions` - List promotions
- `POST /api/v1/assessments/promotions/promote` - Promote student
- `POST /api/v1/assessments/promotions/bulk-promote` - Bulk promote
- `POST /api/v1/assessments/promotions/auto-promote` - Auto-promote
- `POST /api/v1/assessments/promotions/graduate` - Graduate students
- `GET /api/v1/assessments/promotions/statistics` - Get statistics
- `DELETE /api/v1/assessments/promotions/{id}` - Delete promotion

---

## Troubleshooting

### Issue: Route not found
**Solution:** Clear route cache on server
```bash
php artisan route:clear
php artisan route:cache
```

### Issue: Migration failed
**Solution:** Check if you're running on tenant database, not central
```bash
# Use tenants:run for tenant migrations
php artisan tenants:run "php artisan migrate --path=database/migrations/tenant"
```

### Issue: Authentication failed
**Solution:** Ensure you're using valid Bearer token and X-Subdomain header

---

## Next Steps

1. ✅ Run migration on tenant databases
2. ✅ Test each endpoint
3. ✅ Verify data is being saved correctly
4. ✅ Test result generation workflow
5. ✅ Test report card generation
6. ✅ Test analytics endpoints
7. ✅ Test promotion workflow

---

**Happy Testing!** 🚀


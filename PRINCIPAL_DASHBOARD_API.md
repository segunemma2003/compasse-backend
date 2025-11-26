# Principal Dashboard API Documentation

Complete API reference for Principal/Vice Principal Dashboard functionality.

---

## Authentication

All endpoints require:

```http
Authorization: Bearer {token}
X-Subdomain: {school_subdomain}
Content-Type: application/json
```

---

## Dashboard Overview

### Get Principal Dashboard

**Endpoint:** `GET /api/v1/dashboard/principal`

**Response (200):**

```json
{
  "user": {
    "id": 2,
    "name": "Dr. Principal",
    "email": "principal@westwoodschool.com",
    "role": "principal"
  },
  "school_overview": {
    "total_students": 1200,
    "total_teachers": 85,
    "total_staff": 45,
    "total_classes": 36,
    "student_teacher_ratio": 14.1
  },
  "academic_performance": {
    "overall_average": 76.5,
    "top_performing_class": "SS3A",
    "pass_rate": 92.3
  },
  "attendance": {
    "student_attendance_today": 95.5,
    "teacher_attendance_today": 98.2,
    "absent_students": 54,
    "absent_teachers": 2
  },
  "pending_approvals": {
    "leave_requests": 5,
    "disciplinary_cases": 3,
    "expense_approvals": 12
  },
  "recent_activities": [...],
  "role": "principal"
}
```

---

## School Management

### Get School Statistics

**Endpoint:** `GET /api/v1/schools/statistics`

### Get Department Performance

**Endpoint:** `GET /api/v1/departments/performance`

### Get Staff Overview

**Endpoint:** `GET /api/v1/staff/overview`

---

## Approval Management

### Get Pending Approvals

**Endpoint:** `GET /api/v1/approvals/pending`

### Approve Leave Request

**Endpoint:** `POST /api/v1/approvals/leave/{id}/approve`

### Approve Expense

**Endpoint:** `POST /api/v1/approvals/expense/{id}/approve`

---

## Academic Oversight

### Get Class Performance Report

**Endpoint:** `GET /api/v1/academic/performance/classes`

### Get Teacher Performance

**Endpoint:** `GET /api/v1/academic/teachers/performance`

### Get Exam Analysis

**Endpoint:** `GET /api/v1/academic/exams/analysis`

---

## Disciplinary Management

### Get Disciplinary Cases

**Endpoint:** `GET /api/v1/discipline/cases`

### Review Disciplinary Case

**Endpoint:** `POST /api/v1/discipline/cases/{id}/review`

---

## Communication

### Send School-Wide Announcement

**Endpoint:** `POST /api/v1/announcements/school-wide`

### Send Message to Department

**Endpoint:** `POST /api/v1/messages/department/{id}`

---

## Summary

### Principal Can:

✅ View comprehensive school overview  
✅ Monitor academic performance  
✅ Track attendance (students & staff)  
✅ Approve leave requests and expenses  
✅ Review disciplinary cases  
✅ Monitor department performance  
✅ Send school-wide communications  
✅ Access all reports and analytics

---

**Last Updated:** November 26, 2025  
**API Version:** 1.0.0

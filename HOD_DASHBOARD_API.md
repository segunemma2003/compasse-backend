# HOD (Head of Department) Dashboard API Documentation

Complete API reference for HOD Dashboard functionality.

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

### Get HOD Dashboard

**Endpoint:** `GET /api/v1/dashboard/hod`

**Response (200):**

```json
{
  "user": {
    "id": 15,
    "name": "Dr. HOD Science",
    "email": "hod.science@westwoodschool.com",
    "role": "hod"
  },
  "department": {
    "id": 1,
    "name": "Science Department",
    "total_teachers": 12,
    "total_subjects": 6,
    "total_students": 450
  },
  "stats": {
    "department_average": 78.5,
    "teachers_present_today": 11,
    "subjects_taught": 6,
    "pending_approvals": 3
  },
  "teacher_performance": [...],
  "subject_statistics": [...],
  "recent_activities": [...],
  "role": "hod"
}
```

---

## Department Management

### Get Department Teachers

**Endpoint:** `GET /api/v1/departments/{id}/teachers`

### Get Department Subjects

**Endpoint:** `GET /api/v1/departments/{id}/subjects`

### Get Department Performance

**Endpoint:** `GET /api/v1/departments/{id}/performance`

---

## Teacher Management

### Assign Teacher to Subject

**Endpoint:** `POST /api/v1/departments/assign-teacher`

### Monitor Teacher Attendance

**Endpoint:** `GET /api/v1/departments/{id}/teacher-attendance`

### Review Teacher Performance

**Endpoint:** `GET /api/v1/teachers/{id}/performance-review`

---

## Subject Management

### Get Subject Performance

**Endpoint:** `GET /api/v1/subjects/{id}/performance`

### Review Curriculum Progress

**Endpoint:** `GET /api/v1/subjects/{id}/curriculum-progress`

---

## Summary

### HOD Can:

✅ Manage department teachers  
✅ Monitor subject performance  
✅ Track teacher attendance  
✅ Review curriculum progress  
✅ Approve departmental requests  
✅ Generate department reports

---

**Last Updated:** November 26, 2025  
**API Version:** 1.0.0

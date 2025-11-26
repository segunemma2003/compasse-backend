# Nurse/Medical Dashboard API Documentation

Complete API reference for Nurse/Medical Dashboard functionality.

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

### Get Nurse Dashboard

**Endpoint:** `GET /api/v1/dashboard/nurse`

**Response (200):**
```json
{
  "user": {
    "id": 35,
    "name": "Nurse Mary",
    "email": "nurse@westwoodschool.com",
    "role": "nurse"
  },
  "stats": {
    "clinic_visits_today": 12,
    "students_with_chronic_conditions": 45,
    "medications_due_today": 8,
    "pending_vaccinations": 15,
    "first_aid_cases_this_week": 25,
    "medical_supplies_low": 3
  },
  "today_appointments": [...],
  "medication_schedule": [...],
  "recent_cases": [...],
  "role": "nurse"
}
```

---

## Medical Records

### Add Medical Record

**Endpoint:** `POST /api/v1/health/records`

**Request Body:**
```json
{
  "student_id": 10,
  "visit_date": "2025-11-26",
  "complaint": "Headache and fever",
  "diagnosis": "Common cold",
  "treatment": "Paracetamol prescribed",
  "temperature": 37.8,
  "blood_pressure": "120/80",
  "notes": "Rest advised"
}
```

### Get Student Medical History

**Endpoint:** `GET /api/v1/health/records/student/{student_id}`

### Get Students with Chronic Conditions

**Endpoint:** `GET /api/v1/health/students/chronic-conditions`

---

## Medication Management

### Schedule Medication

**Endpoint:** `POST /api/v1/health/medications`

### Get Today's Medication Schedule

**Endpoint:** `GET /api/v1/health/medications/today`

### Record Medication Given

**Endpoint:** `POST /api/v1/health/medications/{id}/administer`

---

## Vaccination Management

### Record Vaccination

**Endpoint:** `POST /api/v1/health/vaccinations`

### Get Vaccination Schedule

**Endpoint:** `GET /api/v1/health/vaccinations/schedule`

---

## Medical Supplies

### Get Inventory

**Endpoint:** `GET /api/v1/health/supplies`

### Record Supply Usage

**Endpoint:** `POST /api/v1/health/supplies/use`

### Request Supplies

**Endpoint:** `POST /api/v1/health/supplies/request`

---

## Summary

### Nurse Can:
✅ Manage clinic visits and medical records  
✅ Track chronic conditions  
✅ Schedule and administer medications  
✅ Manage vaccination records  
✅ Monitor medical supplies  
✅ Generate health reports  

---

**Last Updated:** November 26, 2025  
**API Version:** 1.0.0


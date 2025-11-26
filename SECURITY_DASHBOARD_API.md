# Security Dashboard API Documentation

Complete API reference for Security Dashboard functionality.

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

### Get Security Dashboard

**Endpoint:** `GET /api/v1/dashboard/security`

**Response (200):**
```json
{
  "user": {
    "id": 40,
    "name": "Mr. Security",
    "email": "security@westwoodschool.com",
    "role": "security"
  },
  "stats": {
    "visitors_today": 15,
    "vehicles_in_campus": 25,
    "gate_passes_issued": 8,
    "incidents_this_week": 2,
    "patrol_checkpoints": 12,
    "cctv_cameras_active": 28,
    "cctv_cameras_inactive": 2
  },
  "current_visitors": [...],
  "recent_incidents": [...],
  "patrol_schedule": [...],
  "role": "security"
}
```

---

## Visitor Management

### Register Visitor

**Endpoint:** `POST /api/v1/security/visitors`

**Request Body:**
```json
{
  "name": "John Visitor",
  "phone": "+234801234567",
  "purpose": "Meeting with Principal",
  "person_to_see": "Dr. Principal",
  "id_type": "driver_license",
  "id_number": "DL12345",
  "vehicle_number": "ABC-123-XY",
  "entry_time": "2025-11-26 10:00:00"
}
```

### Get Active Visitors

**Endpoint:** `GET /api/v1/security/visitors/active`

### Check Out Visitor

**Endpoint:** `POST /api/v1/security/visitors/{id}/checkout`

---

## Gate Pass Management

### Issue Gate Pass

**Endpoint:** `POST /api/v1/security/gate-passes`

**Request Body:**
```json
{
  "student_id": 10,
  "reason": "Medical appointment",
  "exit_time": "2025-11-26 12:00:00",
  "expected_return": "2025-11-26 14:00:00",
  "guardian_phone": "+234809876543"
}
```

### Verify Gate Pass

**Endpoint:** `GET /api/v1/security/gate-passes/{code}/verify`

---

## Incident Management

### Report Incident

**Endpoint:** `POST /api/v1/security/incidents`

**Request Body:**
```json
{
  "type": "theft",
  "severity": "medium",
  "location": "Library",
  "description": "Missing laptop reported",
  "reported_time": "2025-11-26 15:00:00",
  "witnesses": ["Student A", "Teacher B"],
  "action_taken": "CCTV footage reviewed"
}
```

### Get Incident History

**Endpoint:** `GET /api/v1/security/incidents`

---

## Vehicle Management

### Log Vehicle Entry

**Endpoint:** `POST /api/v1/security/vehicles/entry`

### Log Vehicle Exit

**Endpoint:** `POST /api/v1/security/vehicles/exit`

### Get Vehicles on Campus

**Endpoint:** `GET /api/v1/security/vehicles/on-campus`

---

## Patrol Management

### Record Patrol Check

**Endpoint:** `POST /api/v1/security/patrols/check`

### Get Patrol Schedule

**Endpoint:** `GET /api/v1/security/patrols/schedule`

---

## Lost and Found

### Register Lost Item

**Endpoint:** `POST /api/v1/security/lost-and-found`

### Get Lost Items

**Endpoint:** `GET /api/v1/security/lost-and-found`

### Mark Item as Found

**Endpoint:** `POST /api/v1/security/lost-and-found/{id}/found`

---

## Summary

### Security Can:
✅ Register and manage visitors  
✅ Issue and verify gate passes  
✅ Report and track incidents  
✅ Log vehicle entry/exit  
✅ Conduct security patrols  
✅ Manage lost and found items  
✅ Monitor CCTV systems  

---

**Last Updated:** November 26, 2025  
**API Version:** 1.0.0


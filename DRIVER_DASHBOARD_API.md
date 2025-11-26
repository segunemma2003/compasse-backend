# Driver Dashboard API Documentation

Complete API reference for Driver Dashboard functionality.

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

### Get Driver Dashboard

**Endpoint:** `GET /api/v1/dashboard/driver`

**Response (200):**

```json
{
  "user": {
    "id": 30,
    "name": "Mr. Driver",
    "email": "driver@westwoodschool.com",
    "role": "driver"
  },
  "vehicle": {
    "id": 1,
    "name": "School Bus 1",
    "plate_number": "ABC-123-XY",
    "capacity": 40,
    "status": "active"
  },
  "route": {
    "id": 1,
    "name": "Route A - Ikeja",
    "students_count": 35,
    "pickup_points": 8
  },
  "stats": {
    "today_trips": 2,
    "students_today": 35,
    "total_trips_this_month": 44,
    "pending_maintenance": false,
    "fuel_status": "75%"
  },
  "today_schedule": [...],
  "students_list": [...],
  "role": "driver"
}
```

---

## My Route Management

### Get My Route

**Endpoint:** `GET /api/v1/transport/drivers/me/route`

### Get Students on My Route

**Endpoint:** `GET /api/v1/transport/drivers/me/students`

### Mark Student Pickup

**Endpoint:** `POST /api/v1/transport/attendance/pickup`

**Request Body:**

```json
{
    "student_id": 10,
    "pickup_point_id": 3,
    "pickup_time": "07:30:00",
    "status": "picked_up"
}
```

### Mark Student Dropoff

**Endpoint:** `POST /api/v1/transport/attendance/dropoff`

---

## Trip Management

### Start Trip

**Endpoint:** `POST /api/v1/transport/trips/start`

### End Trip

**Endpoint:** `POST /api/v1/transport/trips/end`

### Get Trip History

**Endpoint:** `GET /api/v1/transport/drivers/me/trips`

---

## Vehicle Management

### Get My Vehicle Status

**Endpoint:** `GET /api/v1/transport/drivers/me/vehicle`

### Report Vehicle Issue

**Endpoint:** `POST /api/v1/transport/vehicles/{id}/report-issue`

### Get Maintenance Schedule

**Endpoint:** `GET /api/v1/transport/drivers/me/maintenance`

---

## Summary

### Driver Can:

✅ View assigned route and students  
✅ Track daily schedule  
✅ Mark student pickup/dropoff  
✅ Manage trip logs  
✅ Report vehicle issues  
✅ View maintenance schedule

---

**Last Updated:** November 26, 2025  
**API Version:** 1.0.0

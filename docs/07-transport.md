# Transport Management

> **Base URL:** `https://{subdomain}.compasse.net/api/v1/`
> **Auth:** `Authorization: Bearer {token}` required on all protected endpoints
> **Module gate:** `transport_management`

---

## Overview

The transport module manages school vehicles, drivers, bus routes, student route assignments, and a secure pickup system for verifying authorised persons collecting students.

---

## User Stories

> **As a school admin**, I want to register all school vehicles and their insurance/service dates so I can track compliance.

> **As a transport coordinator**, I want to create bus routes, assign a vehicle and driver, and enroll students on specific routes so parents know which bus their child rides.

> **As a security officer / driver**, I want to verify the identity of a person collecting a student by entering their unique pickup code so only authorised persons can take students.

> **As a parent**, I want to register an authorised pickup person with their photo and relationship, and get a pickup code to give them.

---

## Models

### Vehicle
| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Auto-increment primary key |
| `make` | string | Manufacturer (e.g. "Toyota") |
| `model` | string | Model name (e.g. "Coaster") |
| `year` | integer | Year of manufacture |
| `plate_number` | string | Unique registration number |
| `capacity` | integer | Passenger capacity |
| `type` | enum | `bus`, `van`, `car`, `minibus` |
| `status` | enum | `active`, `inactive`, `maintenance` |
| `insurance_expiry` | date\|null | Insurance expiry date |
| `last_service_date` | date\|null | Last maintenance date |

### Driver
| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Auto-increment primary key |
| `user_id` | integer\|null | Linked user account (optional) |
| `name` | string | Full name |
| `license_number` | string | Driver's licence number (unique per school) |
| `license_expiry` | date\|null | Licence expiry date |
| `phone` | string | Contact number |
| `status` | enum | `active`, `inactive`, `suspended` |

### TransportRoute
| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Auto-increment primary key |
| `vehicle_id` | integer | Assigned vehicle FK |
| `driver_id` | integer | Assigned driver FK |
| `name` | string | Route display name |
| `route_code` | string | Short unique code (e.g. `RT-IKJ-01`) |
| `start_point` | string | Origin terminal location |
| `end_point` | string | Destination terminal location |
| `stops` | JSON | Array of intermediate stop names |
| `distance_km` | decimal\|null | Route distance in kilometres |
| `fare` | decimal\|null | Student fare amount |
| `morning_pickup_time` | string | `HH:MM` format |
| `afternoon_dropoff_time` | string | `HH:MM` format |
| `status` | enum | `active`, `inactive` |

Students are linked to routes via `student_transport_routes` pivot with `pickup_stop` and `dropoff_stop`.

### SecurePickup
| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Auto-increment primary key |
| `student_id` | integer | Student who can be collected |
| `authorized_name` | string | Authorised person's full name |
| `authorized_phone` | string | Their phone number |
| `relationship` | string | e.g. `Mother`, `Uncle`, `Guardian` |
| `authorized_photo` | string\|null | Photo URL (uploaded via separate file endpoint) |
| `pickup_code` | string | Unique 8-character uppercase code (auto-generated) |
| `status` | enum | `active`, `inactive` |

---

## API Endpoints

**Base path:** `/api/v1/transport/`

### Vehicles

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/vehicles` | List vehicles (filter: `status`, `type`, `search`) |
| POST | `/vehicles` | Register vehicle |
| GET | `/vehicles/{id}` | Get vehicle + assigned routes |
| PUT | `/vehicles/{id}` | Update vehicle |
| DELETE | `/vehicles/{id}` | Delete (blocked if on active route) |

### Drivers

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/drivers` | List drivers (filter: `status`, `search`) |
| POST | `/drivers` | Register driver |
| GET | `/drivers/{id}` | Get driver + assigned routes |
| PUT | `/drivers/{id}` | Update driver |
| DELETE | `/drivers/{id}` | Delete (blocked if on active route) |

### Routes

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/routes` | List routes (filter: `status`, `driver_id`, `vehicle_id`) |
| POST | `/routes` | Create route |
| GET | `/routes/{id}` | Get route + vehicle + driver + students |
| PUT | `/routes/{id}` | Update route |
| DELETE | `/routes/{id}` | Delete (blocked if students assigned) |
| GET | `/routes/{id}/students` | List students on this route |
| POST | `/routes/{id}/students` | Assign student to route |
| DELETE | `/routes/{id}/students` | Remove student from route |

### Secure Pickup

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/secure-pickup` | List authorised pickups (filter: `student_id`, `status`) |
| POST | `/secure-pickup` | Add authorised pickup person (code auto-generated) |
| GET | `/secure-pickup/{id}` | Get pickup auth details |
| PUT | `/secure-pickup/{id}` | Update pickup details |
| DELETE | `/secure-pickup/{id}` | Remove authorisation |
| POST | `/secure-pickup/verify` | Verify a pickup code at the gate |

---

## Request / Response Examples

### Vehicles

#### Register a Vehicle

**Request**
```http
POST /api/v1/transport/vehicles HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "make": "Toyota",
  "model": "Coaster",
  "year": 2022,
  "plate_number": "LSD-456AB",
  "capacity": 35,
  "type": "bus",
  "status": "active",
  "insurance_expiry": "2027-01-15",
  "last_service_date": "2026-01-10"
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Vehicle registered successfully",
  "vehicle": {
    "id": 3,
    "make": "Toyota",
    "model": "Coaster",
    "year": 2022,
    "plate_number": "LSD-456AB",
    "capacity": 35,
    "type": "bus",
    "status": "active",
    "insurance_expiry": "2027-01-15",
    "last_service_date": "2026-01-10",
    "created_at": "2026-03-30T09:00:00Z"
  }
}
```

#### List Vehicles

**Request**
```http
GET /api/v1/transport/vehicles?status=active HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": [
    {
      "id": 3,
      "make": "Toyota",
      "model": "Coaster",
      "year": 2022,
      "plate_number": "LSD-456AB",
      "capacity": 35,
      "type": "bus",
      "status": "active",
      "insurance_expiry": "2027-01-15"
    },
    {
      "id": 4,
      "make": "Mercedes",
      "model": "Sprinter",
      "year": 2021,
      "plate_number": "LSD-789CD",
      "capacity": 18,
      "type": "van",
      "status": "active",
      "insurance_expiry": "2026-08-30"
    }
  ],
  "meta": { "total": 2 }
}
```

#### Update a Vehicle

**Request**
```http
PUT /api/v1/transport/vehicles/3 HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "status": "maintenance",
  "last_service_date": "2026-03-28"
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Vehicle updated successfully",
  "vehicle": {
    "id": 3,
    "plate_number": "LSD-456AB",
    "status": "maintenance",
    "last_service_date": "2026-03-28"
  }
}
```

#### Delete a Vehicle

**Request**
```http
DELETE /api/v1/transport/vehicles/3 HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Vehicle deleted successfully"
}
```

**Blocked response** (vehicle on active route) `HTTP 422 Unprocessable Entity`
```json
{
  "message": "Cannot delete vehicle assigned to an active route."
}
```

---

### Drivers

#### Register a Driver

**Request**
```http
POST /api/v1/transport/drivers HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "name": "Mr. Biodun Adekunle",
  "license_number": "LAG-DL-2019-00451",
  "license_expiry": "2028-06-30",
  "phone": "08034567890",
  "status": "active"
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Driver registered successfully",
  "driver": {
    "id": 2,
    "name": "Mr. Biodun Adekunle",
    "license_number": "LAG-DL-2019-00451",
    "license_expiry": "2028-06-30",
    "phone": "08034567890",
    "status": "active",
    "created_at": "2026-03-30T09:30:00Z"
  }
}
```

#### List Drivers

**Request**
```http
GET /api/v1/transport/drivers?status=active HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": [
    {
      "id": 2,
      "name": "Mr. Biodun Adekunle",
      "license_number": "LAG-DL-2019-00451",
      "license_expiry": "2028-06-30",
      "phone": "08034567890",
      "status": "active",
      "routes_count": 1
    }
  ],
  "meta": { "total": 1 }
}
```

#### Update a Driver

**Request**
```http
PUT /api/v1/transport/drivers/2 HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "phone": "08099887766",
  "license_expiry": "2029-06-30"
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Driver updated successfully",
  "driver": {
    "id": 2,
    "name": "Mr. Biodun Adekunle",
    "phone": "08099887766",
    "license_expiry": "2029-06-30"
  }
}
```

#### Delete a Driver

**Request**
```http
DELETE /api/v1/transport/drivers/2 HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Blocked response** (driver on active route) `HTTP 422 Unprocessable Entity`
```json
{
  "message": "Cannot delete driver assigned to an active route."
}
```

---

### Routes

#### Create a Route

**Request**
```http
POST /api/v1/transport/routes HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "name": "Ikeja Express",
  "route_code": "RT-IKJ-01",
  "vehicle_id": 3,
  "driver_id": 2,
  "start_point": "Greenfield Academy, Lekki",
  "end_point": "Ikeja Under Bridge",
  "stops": ["Ajah", "Sangotedo", "Oregun"],
  "distance_km": 42.5,
  "fare": 15000,
  "morning_pickup_time": "06:30",
  "afternoon_dropoff_time": "15:00",
  "status": "active"
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Route created successfully",
  "route": {
    "id": 5,
    "name": "Ikeja Express",
    "route_code": "RT-IKJ-01",
    "vehicle": { "id": 3, "make": "Toyota", "model": "Coaster", "plate_number": "LSD-456AB" },
    "driver": { "id": 2, "name": "Mr. Biodun Adekunle" },
    "start_point": "Greenfield Academy, Lekki",
    "end_point": "Ikeja Under Bridge",
    "stops": ["Ajah", "Sangotedo", "Oregun"],
    "distance_km": "42.50",
    "fare": "15000.00",
    "morning_pickup_time": "06:30",
    "afternoon_dropoff_time": "15:00",
    "status": "active",
    "students_count": 0
  }
}
```

#### List Routes

**Request**
```http
GET /api/v1/transport/routes?status=active HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": [
    {
      "id": 5,
      "name": "Ikeja Express",
      "route_code": "RT-IKJ-01",
      "driver": { "name": "Mr. Biodun Adekunle" },
      "vehicle": { "plate_number": "LSD-456AB", "capacity": 35 },
      "morning_pickup_time": "06:30",
      "afternoon_dropoff_time": "15:00",
      "status": "active",
      "students_count": 24,
      "fare": "15000.00"
    }
  ],
  "meta": { "total": 1 }
}
```

#### Update a Route

**Request**
```http
PUT /api/v1/transport/routes/5 HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "morning_pickup_time": "06:15",
  "fare": 16000,
  "stops": ["Ajah", "Sangotedo", "Magodo", "Oregun"]
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Route updated successfully",
  "route": {
    "id": 5,
    "morning_pickup_time": "06:15",
    "fare": "16000.00",
    "stops": ["Ajah", "Sangotedo", "Magodo", "Oregun"]
  }
}
```

#### Assign Student to Route

**Request**
```http
POST /api/v1/transport/routes/5/students HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "student_id": 101,
  "pickup_stop": "Ajah",
  "dropoff_stop": "Ikeja Under Bridge"
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Student assigned to route successfully",
  "assignment": {
    "route_id": 5,
    "student_id": 101,
    "student": {
      "id": 101,
      "name": "Chisom Okonkwo",
      "admission_number": "GFA/2024/0101",
      "class": "JSS 2",
      "arm": "A"
    },
    "pickup_stop": "Ajah",
    "dropoff_stop": "Ikeja Under Bridge"
  }
}
```

**Duplicate enrollment** `HTTP 422 Unprocessable Entity`
```json
{
  "message": "Student is already assigned to this route."
}
```

#### Remove Student from Route

**Request**
```http
DELETE /api/v1/transport/routes/5/students HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "student_id": 101
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Student removed from route successfully"
}
```

#### List Students on a Route

**Request**
```http
GET /api/v1/transport/routes/5/students HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "route": {
    "id": 5,
    "name": "Ikeja Express",
    "morning_pickup_time": "06:15"
  },
  "students": [
    {
      "id": 101,
      "name": "Chisom Okonkwo",
      "admission_number": "GFA/2024/0101",
      "class": "JSS 2A",
      "pickup_stop": "Ajah",
      "dropoff_stop": "Ikeja Under Bridge"
    },
    {
      "id": 87,
      "name": "David Afolabi",
      "admission_number": "GFA/2024/0087",
      "class": "SS1B",
      "pickup_stop": "Sangotedo",
      "dropoff_stop": "Ikeja Under Bridge"
    }
  ],
  "meta": { "total": 24 }
}
```

---

### Secure Pickup

#### Add Authorised Pickup Person

**Request**
```http
POST /api/v1/transport/secure-pickup HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "student_id": 101,
  "authorized_name": "Mrs. Grace Okonkwo",
  "authorized_phone": "08012345678",
  "relationship": "Mother",
  "status": "active"
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Secure pickup authorization created",
  "pickup": {
    "id": 14,
    "student_id": 101,
    "student": { "id": 101, "name": "Chisom Okonkwo" },
    "authorized_name": "Mrs. Grace Okonkwo",
    "authorized_phone": "08012345678",
    "relationship": "Mother",
    "authorized_photo": null,
    "pickup_code": "XKQR7MNP",
    "status": "active",
    "created_at": "2026-03-30T10:00:00Z"
  }
}
```

#### List Authorised Pickups

**Request**
```http
GET /api/v1/transport/secure-pickup?student_id=101 HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": [
    {
      "id": 14,
      "student": { "id": 101, "name": "Chisom Okonkwo" },
      "authorized_name": "Mrs. Grace Okonkwo",
      "authorized_phone": "08012345678",
      "relationship": "Mother",
      "authorized_photo": null,
      "pickup_code": "XKQR7MNP",
      "status": "active"
    }
  ],
  "meta": { "total": 1 }
}
```

#### Update Pickup Auth

**Request**
```http
PUT /api/v1/transport/secure-pickup/14 HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "authorized_phone": "08099001122",
  "status": "active"
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Secure pickup updated successfully",
  "pickup": {
    "id": 14,
    "authorized_name": "Mrs. Grace Okonkwo",
    "authorized_phone": "08099001122",
    "pickup_code": "XKQR7MNP",
    "status": "active"
  }
}
```

#### Delete Pickup Auth

**Request**
```http
DELETE /api/v1/transport/secure-pickup/14 HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Pickup authorization removed successfully"
}
```

#### Verify Pickup Code at Gate

This endpoint is accessible by security staff (reduced auth scope — no admin privileges required, but still within the tenant context).

**Request**
```http
POST /api/v1/transport/secure-pickup/verify HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "pickup_code": "XKQR7MNP"
}
```

**Response** `HTTP 200 OK — Verified`
```json
{
  "verified": true,
  "pickup": {
    "id": 14,
    "authorized_name": "Mrs. Grace Okonkwo",
    "authorized_phone": "08012345678",
    "relationship": "Mother",
    "authorized_photo": "https://cdn.compasse.net/tenants/greenfield/pickups/14.jpg",
    "status": "active"
  },
  "student": {
    "id": 101,
    "name": "Chisom Okonkwo",
    "admission_number": "GFA/2024/0101",
    "class": "JSS 2",
    "arm": "A",
    "photo": "https://cdn.compasse.net/tenants/greenfield/students/101.jpg"
  }
}
```

**Invalid code** `HTTP 404 Not Found`
```json
{
  "verified": false,
  "message": "Invalid or inactive pickup code."
}
```

---

## Business Rules

1. **Active route guard** — A vehicle or driver cannot be deleted while assigned to an active route.
2. **Student assignment guard** — A route cannot be deleted while students are enrolled on it.
3. **Duplicate enrollment** — A student cannot be assigned to the same route twice.
4. **Pickup code uniqueness** — Codes are randomly generated as 8-character uppercase strings and checked for collisions before saving.
5. **Verify endpoint scope** — Security staff can POST to `/secure-pickup/verify` without full admin auth; the endpoint only requires a valid tenant Sanctum token, not a specific role.
6. **Inactive pickup** — If a pickup record's `status` is `inactive`, the verify endpoint returns the same `404` response as an invalid code.

---

## Frontend Integration

### How the Frontend Handles Tenancy

All transport API calls are made to `https://{school}.compasse.net/api/v1/transport/...`. The subdomain resolves the tenant automatically. Every request carries `Authorization: Bearer {token}`. The `transport_management` module flag is checked on app boot; if absent the Transport section is hidden from the sidebar.

### Gate Security App Flow

The gate security feature is designed to be used on a tablet or phone held by security staff at the school entrance. The flow is:

1. **Enter code or scan QR** — The security staff opens the Secure Pickup screen. They either:
   - Type the 8-character `pickup_code` manually, or
   - Scan a QR code (which encodes the `pickup_code`) using the device camera.

2. **POST verify** — The app immediately calls:
   ```
   POST /api/v1/transport/secure-pickup/verify
   { "pickup_code": "XKQR7MNP" }
   ```

3. **Show result** — On success the screen displays:
   - **Authorised person:** `authorized_name`, `relationship`, `authorized_photo` (large, easy to visually confirm)
   - **Student:** `student.name`, `student.photo`, class/arm

4. **Not found** — If the API returns `404`, the screen shows a large red "NOT AUTHORISED" warning.

5. **No network** — A cached list of pickup codes for that day can be pre-fetched at shift start (`GET /api/v1/transport/secure-pickup?status=active`) and stored locally for offline fallback.

### Transport Admin Views

- **Vehicle list** — Table with `status` badges (Active / Maintenance / Inactive). Clicking a vehicle opens its assigned routes.
- **Route map view** — The frontend renders the `stops` JSON array as a sequential list of stops with morning/afternoon times.
- **Student route assignment** — The "Assign Student" form includes a student search autocomplete (calls `GET /api/v1/students?search=...`), a stop selector pre-populated from the route's `stops` array, and submits via `POST /api/v1/transport/routes/{id}/students`.

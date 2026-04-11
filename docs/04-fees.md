# School Fees & Payments

> **Base URL:** `https://{subdomain}.compasse.africa/api/v1/financial/`
> **Auth:** `Authorization: Bearer {token}` required on all protected endpoints
> **Module gate:** `fee_management`

---

## Overview

The fee management module handles school fee creation, student billing, payment recording, and receipt generation. It is gated by the `fee_management` module.

---

## User Stories

> **As a school admin / accountant**, I want to create fee structures per class and term so students are automatically billed the correct amount.

> **As an accountant**, I want to record payments made by students or guardians, automatically update the balance, and generate a receipt.

> **As a school admin**, I want to see which students have outstanding balances so I can follow up before exams.

> **As a parent / guardian**, I want to view my child's fee balance and payment history.

---

## Fee Model

| Field | Type | Description |
|-------|------|-------------|
| `school_id` | FK | Owning school |
| `student_id` | FK | Student being billed (nullable for class-wide fees) |
| `class_id` | FK | Target class |
| `academic_year_id` | FK | Academic year |
| `term_id` | FK | Term |
| `fee_type` | string | e.g. `tuition`, `bus`, `hostel`, `uniform`, `exam` |
| `amount` | decimal | Total fee amount |
| `amount_paid` | decimal | Total amount paid so far |
| `balance` | decimal | Outstanding balance |
| `due_date` | date | Payment deadline |
| `status` | enum | `unpaid`, `partial`, `paid`, `waived`, `overdue` |
| `description` | string | Additional details |

### Computed Properties (model methods)
- `getRemainingAmount()` — returns `balance` if set, otherwise `amount - amount_paid`
- `getStats()` — returns `{ total_amount, amount_paid, balance, payment_percent, is_overdue }`

---

## Payment Model

| Field | Type | Description |
|-------|------|-------------|
| `school_id` | FK | Owning school |
| `student_id` | FK | Student |
| `fee_id` | FK | Fee being paid |
| `amount` | decimal | Amount paid in this transaction |
| `payment_method` | enum | `cash`, `bank_transfer`, `card`, `mobile_money`, `cheque` |
| `payment_date` | date | Date payment was received |
| `reference` | string | Bank ref / receipt number |
| `received_by` | FK → User | Staff who recorded the payment |
| `status` | enum | `pending`, `confirmed`, `failed`, `refunded` |
| `notes` | string | Optional notes |

---

## API Endpoints

**Required module:** `fee_management`
**Base path:** `/api/v1/financial/`

### Fees

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/fees` | List fees (filter: class_id, student_id, status, fee_type) |
| POST | `/fees` | Create a fee record |
| GET | `/fees/{id}` | Get fee details + payment stats |
| PUT | `/fees/{id}` | Update fee |
| DELETE | `/fees/{id}` | Delete fee (blocked if payments exist) |
| POST | `/fees/{id}/pay` | Record a payment against this fee |
| GET | `/fees/student/{student_id}` | All fees for a student |
| GET | `/fees/structure` | Fee structure summary by class/term |
| POST | `/fees/structure` | Bulk-create fees for an entire class |
| PUT | `/fees/structure/{id}` | Update a fee structure entry |

### Payments

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/payments` | List all payments |
| POST | `/payments` | Record a payment |
| GET | `/payments/{id}` | Get payment details |
| GET | `/payments/student/{student_id}` | Payments by student |
| GET | `/payments/receipt/{id}` | Generate receipt data |

---

## Full Request / Response Examples — Fees

### List Fees

```
GET /api/v1/financial/fees
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `class_id` | integer | Filter by class |
| `student_id` | integer | Filter by student |
| `status` | string | `unpaid`, `partial`, `paid`, `waived`, `overdue` |
| `fee_type` | string | `tuition`, `bus`, `hostel`, `uniform`, `exam` |
| `term_id` | integer | Filter by term |
| `academic_year_id` | integer | Filter by academic year |
| `per_page` | integer | Items per page (default 20) |
| `page` | integer | Page number |

**Response `200 OK`:**
```json
{
  "data": [
    {
      "id": 11,
      "student_id": 102,
      "student_name": "John Okafor",
      "class_id": 3,
      "class_name": "JSS 1A",
      "academic_year": "2025/2026",
      "term": "Second Term",
      "fee_type": "tuition",
      "amount": "75000.00",
      "amount_paid": "75000.00",
      "balance": "0.00",
      "due_date": "2026-03-30",
      "status": "paid",
      "description": "Second term tuition fee",
      "created_at": "2026-01-15T09:00:00Z"
    },
    {
      "id": 12,
      "student_id": 103,
      "student_name": "Jane Doe",
      "class_id": 3,
      "class_name": "JSS 1A",
      "academic_year": "2025/2026",
      "term": "Second Term",
      "fee_type": "tuition",
      "amount": "75000.00",
      "amount_paid": "50000.00",
      "balance": "25000.00",
      "due_date": "2026-04-30",
      "status": "partial",
      "description": "Second term tuition fee",
      "created_at": "2026-01-15T09:00:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 156
  },
  "summary": {
    "total_fees": 156,
    "total_amount": 11700000,
    "total_paid": 9200000,
    "total_outstanding": 2500000,
    "unpaid_count": 22,
    "partial_count": 31,
    "paid_count": 103
  }
}
```

---

### Create a Fee

```
POST /api/v1/financial/fees
Authorization: Bearer {token}
X-Subdomain: greenfield
Content-Type: application/json
```

**Request Body (student-specific fee):**
```json
{
  "student_id": 103,
  "class_id": 3,
  "academic_year_id": 1,
  "term_id": 2,
  "fee_type": "tuition",
  "amount": 75000,
  "due_date": "2026-04-30",
  "description": "Second term tuition fee"
}
```

**Request Body (class-wide fee — no student_id):**
```json
{
  "class_id": 3,
  "academic_year_id": 1,
  "term_id": 2,
  "fee_type": "tuition",
  "amount": 75000,
  "due_date": "2026-04-30",
  "description": "Second term tuition fee for JSS 1A"
}
```

**Response `201 Created`:**
```json
{
  "message": "Fee created successfully",
  "fee": {
    "id": 12,
    "student_id": 103,
    "student_name": "Jane Doe",
    "class_id": 3,
    "class_name": "JSS 1A",
    "academic_year": "2025/2026",
    "term": "Second Term",
    "fee_type": "tuition",
    "amount": "75000.00",
    "amount_paid": "0.00",
    "balance": "75000.00",
    "due_date": "2026-04-30",
    "status": "unpaid",
    "description": "Second term tuition fee",
    "created_at": "2026-03-30T14:00:00Z"
  }
}
```

**Response `422 Unprocessable Entity`:**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "amount": ["The amount must be at least 1."],
    "due_date": ["The due date must be a valid date in YYYY-MM-DD format."]
  }
}
```

---

### Get Fee Details + Stats

```
GET /api/v1/financial/fees/{id}
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK`:**
```json
{
  "fee": {
    "id": 12,
    "student_id": 103,
    "student_name": "Jane Doe",
    "class_id": 3,
    "class_name": "JSS 1A",
    "academic_year": "2025/2026",
    "term": "Second Term",
    "fee_type": "tuition",
    "amount": "75000.00",
    "amount_paid": "50000.00",
    "balance": "25000.00",
    "due_date": "2026-04-30",
    "status": "partial",
    "description": "Second term tuition fee"
  },
  "stats": {
    "total_amount": 75000,
    "amount_paid": 50000,
    "balance": 25000,
    "payment_percent": 66.7,
    "is_overdue": false,
    "days_until_due": 31
  },
  "payments": [
    {
      "id": 45,
      "amount": "50000.00",
      "payment_method": "bank_transfer",
      "payment_date": "2026-03-28",
      "reference": "TRX-2026-001234",
      "received_by": "Mr. Adeyemi",
      "status": "confirmed",
      "created_at": "2026-03-28T10:00:00Z"
    }
  ]
}
```

---

### Update Fee

```
PUT /api/v1/financial/fees/{id}
Authorization: Bearer {token}
X-Subdomain: greenfield
Content-Type: application/json
```

**Request Body:**
```json
{
  "amount": 80000,
  "due_date": "2026-05-15",
  "description": "Second term tuition fee (revised)"
}
```

**Response `200 OK`:**
```json
{
  "message": "Fee updated successfully",
  "fee": {
    "id": 12,
    "amount": "80000.00",
    "balance": "30000.00",
    "due_date": "2026-05-15",
    "status": "partial",
    "updated_at": "2026-03-30T15:00:00Z"
  }
}
```

---

### Delete Fee

```
DELETE /api/v1/financial/fees/{id}
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK`:**
```json
{
  "message": "Fee deleted successfully"
}
```

**Response `409 Conflict` (payments exist):**
```json
{
  "message": "Cannot delete this fee because payment records exist against it. Waive the fee instead."
}
```

---

### Record Payment Against a Fee

```
POST /api/v1/financial/fees/{id}/pay
Authorization: Bearer {token}
X-Subdomain: greenfield
Content-Type: application/json
```

**Request Body:**
```json
{
  "amount": 50000,
  "payment_method": "bank_transfer",
  "payment_date": "2026-03-28",
  "reference": "TRX-2026-001234",
  "notes": "First installment"
}
```

**Response `200 OK`:**
```json
{
  "message": "Payment recorded",
  "payment": {
    "id": 45,
    "fee_id": 12,
    "student_id": 103,
    "amount": "50000.00",
    "payment_method": "bank_transfer",
    "payment_date": "2026-03-28",
    "reference": "TRX-2026-001234",
    "received_by": 1,
    "status": "confirmed",
    "notes": "First installment",
    "created_at": "2026-03-28T10:00:00Z"
  },
  "fee": {
    "id": 12,
    "amount": "75000.00",
    "amount_paid": "50000.00",
    "balance": "25000.00",
    "status": "partial"
  }
}
```

**Response `422 Unprocessable Entity` (overpayment):**
```json
{
  "message": "Payment amount exceeds the remaining balance of ₦25,000.00"
}
```

---

### Get All Fees for a Student

```
GET /api/v1/financial/fees/student/{student_id}
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `academic_year_id` | integer | Filter by academic year |
| `term_id` | integer | Filter by term |
| `status` | string | Filter by status |

**Response `200 OK`:**
```json
{
  "student": {
    "id": 103,
    "name": "Jane Doe",
    "class": "JSS 1A",
    "admission_number": "GFA/2024/0103"
  },
  "fees": [
    {
      "id": 12,
      "fee_type": "tuition",
      "term": "Second Term",
      "academic_year": "2025/2026",
      "amount": "75000.00",
      "amount_paid": "50000.00",
      "balance": "25000.00",
      "due_date": "2026-04-30",
      "status": "partial",
      "is_overdue": false
    },
    {
      "id": 8,
      "fee_type": "tuition",
      "term": "First Term",
      "academic_year": "2025/2026",
      "amount": "75000.00",
      "amount_paid": "75000.00",
      "balance": "0.00",
      "due_date": "2025-12-15",
      "status": "paid",
      "is_overdue": false
    }
  ],
  "summary": {
    "total_owed": 150000,
    "total_paid": 125000,
    "total_outstanding": 25000,
    "overdue_count": 0
  }
}
```

---

### Get Fee Structure

Returns the fee structure summary by class and term.

```
GET /api/v1/financial/fees/structure
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Query Parameters:** `academic_year_id`, `term_id`

**Response `200 OK`:**
```json
{
  "structures": [
    {
      "id": 1,
      "class_id": 3,
      "class_name": "JSS 1A",
      "term": "Second Term",
      "academic_year": "2025/2026",
      "fee_type": "tuition",
      "amount": "75000.00",
      "due_date": "2026-04-30",
      "student_count": 42,
      "total_billed": 3150000,
      "total_collected": 2850000,
      "total_outstanding": 300000,
      "collection_rate_percent": 90.5
    }
  ]
}
```

---

### Bulk-Create Fees for an Entire Class

Creates one fee record per student currently enrolled in the given class.

```
POST /api/v1/financial/fees/structure
Authorization: Bearer {token}
X-Subdomain: greenfield
Content-Type: application/json
```

**Request Body:**
```json
{
  "class_id": 3,
  "academic_year_id": 1,
  "term_id": 2,
  "fee_type": "tuition",
  "amount": 75000,
  "due_date": "2026-04-30",
  "description": "Second term tuition for JSS 1A"
}
```

**Response `201 Created`:**
```json
{
  "message": "Fee structure created. 42 fee records generated.",
  "structure": {
    "id": 1,
    "class_id": 3,
    "class_name": "JSS 1A",
    "term": "Second Term",
    "fee_type": "tuition",
    "amount": "75000.00",
    "due_date": "2026-04-30",
    "students_billed": 42,
    "total_amount": 3150000
  }
}
```

---

### Update Fee Structure Entry

```
PUT /api/v1/financial/fees/structure/{id}
Authorization: Bearer {token}
X-Subdomain: greenfield
Content-Type: application/json
```

**Request Body:**
```json
{
  "amount": 80000,
  "due_date": "2026-05-15"
}
```

**Response `200 OK`:**
```json
{
  "message": "Fee structure updated. 42 fee records updated.",
  "structure": {
    "id": 1,
    "amount": "80000.00",
    "due_date": "2026-05-15",
    "updated_at": "2026-03-30T16:00:00Z"
  }
}
```

---

## Full Request / Response Examples — Payments

### List All Payments

```
GET /api/v1/financial/payments
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `student_id` | integer | Filter by student |
| `fee_type` | string | Filter by fee type |
| `payment_method` | string | Filter by method |
| `status` | string | `pending`, `confirmed`, `failed`, `refunded` |
| `from_date` | date | Start date (YYYY-MM-DD) |
| `to_date` | date | End date (YYYY-MM-DD) |
| `per_page` | integer | Items per page |

**Response `200 OK`:**
```json
{
  "data": [
    {
      "id": 45,
      "fee_id": 12,
      "student_id": 103,
      "student_name": "Jane Doe",
      "class": "JSS 1A",
      "fee_type": "tuition",
      "amount": "50000.00",
      "payment_method": "bank_transfer",
      "payment_date": "2026-03-28",
      "reference": "TRX-2026-001234",
      "received_by": "Mr. Adeyemi",
      "status": "confirmed",
      "created_at": "2026-03-28T10:00:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 2901
  },
  "summary": {
    "total_payments": 2901,
    "total_amount_collected": 9200000,
    "by_method": {
      "bank_transfer": 7500000,
      "cash": 1200000,
      "card": 500000,
      "mobile_money": 0,
      "cheque": 0
    }
  }
}
```

---

### Record a Payment (Direct)

```
POST /api/v1/financial/payments
Authorization: Bearer {token}
X-Subdomain: greenfield
Content-Type: application/json
```

**Request Body:**
```json
{
  "fee_id": 12,
  "student_id": 103,
  "amount": 25000,
  "payment_method": "cash",
  "payment_date": "2026-03-30",
  "reference": "CASH-2026-0030",
  "notes": "Final installment — balance cleared"
}
```

**Response `201 Created`:**
```json
{
  "message": "Payment recorded successfully",
  "payment": {
    "id": 46,
    "fee_id": 12,
    "student_id": 103,
    "student_name": "Jane Doe",
    "amount": "25000.00",
    "payment_method": "cash",
    "payment_date": "2026-03-30",
    "reference": "CASH-2026-0030",
    "received_by": 1,
    "received_by_name": "Mr. Adeyemi",
    "status": "confirmed",
    "notes": "Final installment — balance cleared",
    "created_at": "2026-03-30T16:30:00Z"
  },
  "fee": {
    "id": 12,
    "amount": "75000.00",
    "amount_paid": "75000.00",
    "balance": "0.00",
    "status": "paid"
  }
}
```

---

### Get Payment Details

```
GET /api/v1/financial/payments/{id}
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK`:**
```json
{
  "payment": {
    "id": 45,
    "fee_id": 12,
    "student": {
      "id": 103,
      "name": "Jane Doe",
      "admission_number": "GFA/2024/0103",
      "class": "JSS 1A"
    },
    "fee": {
      "id": 12,
      "fee_type": "tuition",
      "term": "Second Term",
      "academic_year": "2025/2026",
      "total_amount": "75000.00"
    },
    "amount": "50000.00",
    "payment_method": "bank_transfer",
    "payment_date": "2026-03-28",
    "reference": "TRX-2026-001234",
    "received_by": "Mr. Adeyemi",
    "status": "confirmed",
    "notes": "First installment",
    "created_at": "2026-03-28T10:00:00Z"
  }
}
```

---

### Get Payments by Student

```
GET /api/v1/financial/payments/student/{student_id}
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK`:**
```json
{
  "student": {
    "id": 103,
    "name": "Jane Doe",
    "admission_number": "GFA/2024/0103",
    "class": "JSS 1A"
  },
  "payments": [
    {
      "id": 46,
      "fee_type": "tuition",
      "term": "Second Term",
      "amount": "25000.00",
      "payment_method": "cash",
      "payment_date": "2026-03-30",
      "reference": "CASH-2026-0030",
      "status": "confirmed"
    },
    {
      "id": 45,
      "fee_type": "tuition",
      "term": "Second Term",
      "amount": "50000.00",
      "payment_method": "bank_transfer",
      "payment_date": "2026-03-28",
      "reference": "TRX-2026-001234",
      "status": "confirmed"
    }
  ],
  "total_paid": 75000
}
```

---

### Get Fee Receipt

```
GET /api/v1/financial/payments/receipt/{id}
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK`:**
```json
{
  "receipt_id": "RCP-0000045",
  "school": {
    "name": "Greenfield Academy",
    "address": "14 Victoria Island, Lagos",
    "phone": "+2348012345678",
    "logo_url": "https://cdn.compasse.africa/schools/greenfield/logo.png"
  },
  "student": {
    "name": "Jane Doe",
    "admission_number": "GFA/2024/0103",
    "class": "JSS 1A"
  },
  "fee": {
    "type": "tuition",
    "term": "Second Term",
    "academic_year": "2025/2026",
    "total_fee": 75000,
    "amount_paid_before": 0,
    "amount_paid_this_receipt": 50000,
    "cumulative_paid": 50000,
    "outstanding_after": 25000
  },
  "payment": {
    "id": 45,
    "amount": 50000,
    "payment_method": "bank_transfer",
    "payment_date": "2026-03-28",
    "reference": "TRX-2026-001234",
    "received_by": "Mr. Adeyemi",
    "status": "confirmed"
  },
  "issued_at": "2026-03-28T10:05:00Z"
}
```

---

## Full Request / Response Examples — Expenses

**Base path:** `/api/v1/financial/expenses`

### List Expenses

```
GET /api/v1/financial/expenses
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `category` | string | e.g. `utilities`, `maintenance`, `supplies`, `salaries` |
| `status` | string | `pending`, `approved`, `paid` |
| `from_date` | date | Start date |
| `to_date` | date | End date |
| `per_page` | integer | Items per page |

**Response `200 OK`:**
```json
{
  "data": [
    {
      "id": 14,
      "description": "Generator fuel — March 2026",
      "amount": "45000.00",
      "category": "utilities",
      "date": "2026-03-25",
      "payment_method": "cash",
      "vendor": "Akin Petroleum",
      "receipt_number": "APL-20260325",
      "status": "paid",
      "approved_by": "Mrs. Adaobi Nwosu",
      "created_at": "2026-03-25T11:00:00Z"
    },
    {
      "id": 15,
      "description": "Classroom chairs replacement — Block B",
      "amount": "120000.00",
      "category": "maintenance",
      "date": "2026-03-28",
      "payment_method": "bank_transfer",
      "vendor": "Lagos Furniture Ltd",
      "receipt_number": null,
      "status": "approved",
      "approved_by": "Mrs. Adaobi Nwosu",
      "created_at": "2026-03-28T09:00:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 38
  },
  "summary": {
    "total_expenses": 38,
    "total_amount": 1450000,
    "by_status": {
      "pending": 5,
      "approved": 8,
      "paid": 25
    },
    "by_category": {
      "utilities": 320000,
      "maintenance": 580000,
      "supplies": 210000,
      "salaries": 340000
    }
  }
}
```

---

### Record Expense

```
POST /api/v1/financial/expenses
Authorization: Bearer {token}
X-Subdomain: greenfield
Content-Type: application/json
```

**Request Body:**
```json
{
  "description": "Internet subscription — April 2026",
  "amount": 35000,
  "category": "utilities",
  "date": "2026-04-01",
  "payment_method": "bank_transfer",
  "vendor": "Spectranet Nigeria",
  "receipt_number": "SPN-APR2026-00892"
}
```

**Response `201 Created`:**
```json
{
  "message": "Expense recorded successfully",
  "expense": {
    "id": 16,
    "description": "Internet subscription — April 2026",
    "amount": "35000.00",
    "category": "utilities",
    "date": "2026-04-01",
    "payment_method": "bank_transfer",
    "vendor": "Spectranet Nigeria",
    "receipt_number": "SPN-APR2026-00892",
    "status": "pending",
    "approved_by": null,
    "created_by": "Mr. Emeka Eze",
    "created_at": "2026-03-30T16:45:00Z"
  }
}
```

---

### Get Expense Details

```
GET /api/v1/financial/expenses/{id}
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK`:**
```json
{
  "expense": {
    "id": 14,
    "description": "Generator fuel — March 2026",
    "amount": "45000.00",
    "category": "utilities",
    "date": "2026-03-25",
    "payment_method": "cash",
    "vendor": "Akin Petroleum",
    "receipt_number": "APL-20260325",
    "status": "paid",
    "approved_by": "Mrs. Adaobi Nwosu",
    "approved_at": "2026-03-25T12:00:00Z",
    "created_by": "Mr. Emeka Eze",
    "created_at": "2026-03-25T11:00:00Z",
    "updated_at": "2026-03-25T12:00:00Z"
  }
}
```

---

### Update Expense

```
PUT /api/v1/financial/expenses/{id}
Authorization: Bearer {token}
X-Subdomain: greenfield
Content-Type: application/json
```

**Request Body (approve and mark paid):**
```json
{
  "status": "paid",
  "payment_method": "bank_transfer",
  "receipt_number": "LFL-20260328-REF"
}
```

**Response `200 OK`:**
```json
{
  "message": "Expense updated successfully",
  "expense": {
    "id": 15,
    "status": "paid",
    "approved_by": "Mrs. Adaobi Nwosu",
    "receipt_number": "LFL-20260328-REF",
    "updated_at": "2026-03-30T17:00:00Z"
  }
}
```

---

### Delete Expense

```
DELETE /api/v1/financial/expenses/{id}
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK`:**
```json
{
  "message": "Expense deleted successfully"
}
```

**Response `409 Conflict` (expense already paid):**
```json
{
  "message": "Cannot delete a paid expense. Reverse it manually if needed."
}
```

---

## Business Rules

1. **Duplicate protection** — A fee cannot be deleted if any payment records exist against it
2. **Auto status update** — When `amount_paid >= amount`, fee status is set to `paid`; partial payment → `partial`
3. **Overdue detection** — `getStats()` returns `is_overdue: true` if `due_date` is past and balance > 0
4. **Class-wide billing** — `POST /fees/structure` creates one fee record per student in the class automatically
5. **School scoping** — All fee queries are scoped to the current tenant's school; cross-school access is not possible
6. **Overpayment blocked** — Payment amount cannot exceed the remaining balance
7. **Expense delete guard** — Expenses with `status=paid` cannot be deleted

---

## Frontend Integration — Fee Payment Flow

This section explains the complete flow from fetching a student's fees to recording a payment and refreshing the balance.

### Step 1 — Check Module Access on Route Mount

```typescript
// pages/fees/index.tsx
import { hasModule } from '@/services/modules';
import { getSubdomain } from '@/utils/tenancy';

const subdomain = getSubdomain()!;

if (!hasModule(subdomain, 'fee_management')) {
  return <UpgradePrompt module="fee_management" />;
}
```

### Step 2 — Fetch Outstanding Fees for a Student

```typescript
// hooks/useStudentFees.ts
import { createApiClient } from '@/services/api';

export function useStudentFees(studentId: number) {
  const subdomain = getSubdomain()!;
  const api = createApiClient(subdomain);
  const [fees, setFees] = useState([]);
  const [summary, setSummary] = useState(null);
  const [loading, setLoading] = useState(true);

  const fetchFees = async () => {
    setLoading(true);
    const data = await api.get(`/financial/fees/student/${studentId}`);
    setFees(data.fees);
    setSummary(data.summary);
    setLoading(false);
  };

  useEffect(() => { fetchFees(); }, [studentId]);

  return { fees, summary, loading, refetch: fetchFees };
}
```

### Step 3 — Display Outstanding Fees

```typescript
// components/StudentFeeList.tsx

export function StudentFeeList({ studentId }: { studentId: number }) {
  const { fees, summary, loading, refetch } = useStudentFees(studentId);

  if (loading) return <Spinner />;

  const outstanding = fees.filter(f => f.status !== 'paid' && f.status !== 'waived');

  return (
    <div>
      <div className="summary-bar">
        <span>Total Outstanding: ₦{summary.total_outstanding.toLocaleString()}</span>
      </div>
      {outstanding.map(fee => (
        <FeeRow
          key={fee.id}
          fee={fee}
          onPaymentComplete={refetch}
        />
      ))}
    </div>
  );
}
```

### Step 4 — Record Payment

```typescript
// components/PaymentModal.tsx

async function handlePayment(feeId: number, amount: number, method: string, reference: string) {
  const subdomain = getSubdomain()!;
  const api = createApiClient(subdomain);

  try {
    const result = await api.post(`/financial/fees/${feeId}/pay`, {
      amount,
      payment_method: method,
      payment_date: new Date().toISOString().split('T')[0],
      reference,
    });

    // Payment recorded — show receipt and refresh fee balance
    showReceipt(result.payment.id);
    onPaymentComplete(); // triggers useStudentFees refetch

    toast.success(`Payment of ₦${amount.toLocaleString()} recorded successfully`);
  } catch (err) {
    toast.error(err.message || 'Payment failed');
  }
}
```

### Step 5 — Fetch and Display Receipt

```typescript
async function showReceipt(paymentId: number) {
  const subdomain = getSubdomain()!;
  const api = createApiClient(subdomain);

  const data = await api.get(`/financial/payments/receipt/${paymentId}`);

  openModal(
    <ReceiptView receipt={data} />,
    { title: `Receipt ${data.receipt_id}`, printable: true }
  );
}
```

### Full Payment Flow Summary

```
1. User navigates to student profile → Fees tab
       GET /financial/fees/student/{id}
       → Display list of fees, highlight outstanding ones

2. Accountant clicks "Record Payment" on a fee row
       → Open payment modal with fee details pre-filled

3. Accountant enters amount, method, and reference
       POST /financial/fees/{id}/pay
       → Response includes updated fee balance

4. On success:
       a. Refresh the fee list (refetch step 1)
       b. Fetch receipt: GET /financial/payments/receipt/{payment_id}
       c. Display printable receipt modal
```

# Finance/Accountant Dashboard API Documentation

Complete API reference for Finance/Accountant Dashboard functionality.

---

## Table of Contents

1. [Dashboard Overview](#dashboard-overview)
2. [Fee Management](#fee-management)
3. [Payment Processing](#payment-processing)
4. [Expense Management](#expense-management)
5. [Payroll Management](#payroll-management)
6. [Financial Reports](#financial-reports)
7. [Budget Management](#budget-management)
8. [Invoice Generation](#invoice-generation)

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

### Get Finance Dashboard

**Endpoint:** `GET /api/v1/dashboard/finance`

**Response (200):**

```json
{
  "user": {
    "id": 20,
    "name": "Mrs. Finance Officer",
    "email": "finance@westwoodschool.com",
    "role": "accountant"
  },
  "stats": {
    "total_revenue": {
      "today": 500000,
      "this_month": 15000000,
      "this_term": 45000000,
      "this_year": 120000000
    },
    "pending_fees": {
      "amount": 25000000,
      "students": 150
    },
    "expenses": {
      "today": 200000,
      "this_month": 5000000,
      "this_term": 15000000
    },
    "payroll": {
      "pending": 3000000,
      "paid_this_month": 12000000
    },
    "outstanding_invoices": 45,
    "overdue_payments": 28,
    "profit_margin": 65.5
  },
  "recent_transactions": [...],
  "pending_approvals": [...],
  "role": "accountant"
}
```

---

## Fee Management

### Get Fee Structure

**Endpoint:** `GET /api/v1/financial/fees/structure`

### Create Fee Structure

**Endpoint:** `POST /api/v1/financial/fees/structure`

**Request Body:**

```json
{
    "name": "First Term Fees",
    "academic_year_id": 1,
    "term_id": 1,
    "class_id": 1,
    "components": [
        {
            "name": "Tuition Fee",
            "amount": 150000,
            "is_mandatory": true
        },
        {
            "name": "Development Levy",
            "amount": 25000,
            "is_mandatory": true
        },
        {
            "name": "Extra-Curricular",
            "amount": 15000,
            "is_mandatory": false
        }
    ],
    "total_amount": 190000,
    "due_date": "2025-10-15"
}
```

### Get Student Fee Status

**Endpoint:** `GET /api/v1/financial/fees/student/{student_id}`

### Get Outstanding Fees by Class

**Endpoint:** `GET /api/v1/financial/fees/outstanding?class_id=1`

### Send Fee Reminder

**Endpoint:** `POST /api/v1/financial/fees/send-reminder`

---

## Payment Processing

### Record Payment

**Endpoint:** `POST /api/v1/financial/payments`

**Request Body:**

```json
{
    "student_id": 10,
    "amount": 150000,
    "payment_method": "bank_transfer",
    "reference": "TRX12345",
    "payment_date": "2025-11-26",
    "payer_name": "Jane Doe",
    "description": "First Term Fees - Partial Payment",
    "fee_components": [
        {
            "fee_id": 1,
            "amount": 150000
        }
    ]
}
```

### Get Payment History

**Endpoint:** `GET /api/v1/financial/payments`

**Query Parameters:**

-   `student_id` - Filter by student
-   `class_id` - Filter by class
-   `payment_method` - Filter by method
-   `from` - Start date
-   `to` - End date
-   `per_page` - Items per page

### Generate Receipt

**Endpoint:** `GET /api/v1/financial/payments/{id}/receipt`

### Get Daily Collections

**Endpoint:** `GET /api/v1/financial/payments/daily-collections?date=2025-11-26`

---

## Expense Management

### Record Expense

**Endpoint:** `POST /api/v1/financial/expenses`

**Request Body:**

```json
{
    "category": "utilities",
    "description": "Electricity Bill - November",
    "amount": 250000,
    "expense_date": "2025-11-26",
    "vendor": "PHCN",
    "payment_method": "bank_transfer",
    "reference": "EXP12345",
    "attachments": [
        {
            "name": "bill.pdf",
            "url": "https://..."
        }
    ]
}
```

### Get Expense Categories

**Endpoint:** `GET /api/v1/financial/expenses/categories`

**Response (200):**

```json
{
    "categories": [
        {
            "id": 1,
            "name": "Utilities",
            "budget": 3000000,
            "spent": 2500000,
            "remaining": 500000
        },
        {
            "id": 2,
            "name": "Salaries",
            "budget": 15000000,
            "spent": 12000000,
            "remaining": 3000000
        },
        {
            "id": 3,
            "name": "Maintenance",
            "budget": 2000000,
            "spent": 1500000,
            "remaining": 500000
        }
    ]
}
```

### Get Expense Report

**Endpoint:** `GET /api/v1/financial/expenses/report?from=2025-11-01&to=2025-11-30`

---

## Payroll Management

### Create Payroll

**Endpoint:** `POST /api/v1/financial/payroll`

**Request Body:**

```json
{
    "month": "November",
    "year": 2025,
    "employees": [
        {
            "employee_id": 5,
            "basic_salary": 300000,
            "allowances": {
                "housing": 100000,
                "transport": 50000
            },
            "deductions": {
                "tax": 45000,
                "pension": 30000
            },
            "net_salary": 375000
        }
    ]
}
```

### Get Payroll History

**Endpoint:** `GET /api/v1/financial/payroll?month=11&year=2025`

### Generate Payslip

**Endpoint:** `GET /api/v1/financial/payroll/{id}/payslip/{employee_id}`

### Process Bulk Salary Payment

**Endpoint:** `POST /api/v1/financial/payroll/{id}/process`

---

## Financial Reports

### Income Statement

**Endpoint:** `GET /api/v1/financial/reports/income-statement`

**Query Parameters:**

-   `from` - Start date
-   `to` - End date
-   `format` - pdf, excel, json

**Response (200):**

```json
{
    "period": {
        "from": "2025-11-01",
        "to": "2025-11-30"
    },
    "income": {
        "tuition_fees": 15000000,
        "other_fees": 2000000,
        "total": 17000000
    },
    "expenses": {
        "salaries": 12000000,
        "utilities": 500000,
        "maintenance": 300000,
        "other": 200000,
        "total": 13000000
    },
    "net_profit": 4000000,
    "profit_margin": 23.5
}
```

### Cash Flow Statement

**Endpoint:** `GET /api/v1/financial/reports/cash-flow`

### Balance Sheet

**Endpoint:** `GET /api/v1/financial/reports/balance-sheet`

### Fee Collection Report

**Endpoint:** `GET /api/v1/financial/reports/fee-collections`

### Expense Analysis

**Endpoint:** `GET /api/v1/financial/reports/expense-analysis`

---

## Budget Management

### Create Budget

**Endpoint:** `POST /api/v1/financial/budgets`

**Request Body:**

```json
{
    "name": "2025/2026 Academic Year Budget",
    "academic_year_id": 1,
    "categories": [
        {
            "name": "Salaries",
            "allocated_amount": 180000000,
            "priority": "high"
        },
        {
            "name": "Infrastructure",
            "allocated_amount": 50000000,
            "priority": "medium"
        },
        {
            "name": "Equipment",
            "allocated_amount": 20000000,
            "priority": "low"
        }
    ],
    "total_budget": 250000000
}
```

### Get Budget vs Actual

**Endpoint:** `GET /api/v1/financial/budgets/{id}/vs-actual`

### Update Budget

**Endpoint:** `PUT /api/v1/financial/budgets/{id}`

---

## Invoice Generation

### Create Invoice

**Endpoint:** `POST /api/v1/financial/invoices`

**Request Body:**

```json
{
    "student_id": 10,
    "invoice_number": "INV2025001",
    "issue_date": "2025-11-26",
    "due_date": "2025-12-26",
    "items": [
        {
            "description": "Tuition Fee - First Term",
            "amount": 150000
        },
        {
            "description": "Development Levy",
            "amount": 25000
        }
    ],
    "total": 175000,
    "tax": 0,
    "grand_total": 175000
}
```

### Send Invoice

**Endpoint:** `POST /api/v1/financial/invoices/{id}/send`

### Get Outstanding Invoices

**Endpoint:** `GET /api/v1/financial/invoices/outstanding`

### Mark Invoice as Paid

**Endpoint:** `POST /api/v1/financial/invoices/{id}/mark-paid`

---

## Summary

### Finance/Accountant Can:

✅ View comprehensive financial dashboard  
✅ Manage fee structures  
✅ Process payments and generate receipts  
✅ Track and categorize expenses  
✅ Manage payroll and generate payslips  
✅ Generate financial reports (P&L, Cash Flow, Balance Sheet)  
✅ Create and monitor budgets  
✅ Generate and send invoices  
✅ Track outstanding fees  
✅ Send payment reminders  
✅ Analyze financial performance

---

**Last Updated:** November 26, 2025  
**API Version:** 1.0.0

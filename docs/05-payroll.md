# Payroll & Pay Stubs

> **Base URL:** `https://{subdomain}.compasse.africa/api/v1/financial/`
> **Auth:** `Authorization: Bearer {token}` required on all protected endpoints
> **Module gate:** `fee_management`

---

## Overview

The payroll module manages staff salary records, allowances, deductions, and net pay calculation. It generates pay stubs that staff can download or view. Payroll is gated by the `fee_management` module.

---

## User Stories

> **As a school admin / accountant**, I want to create monthly payroll records for each staff member specifying their basic salary, allowances, and deductions so the system calculates net pay automatically.

> **As a staff member**, I want to view my pay stub for any given month showing my earnings breakdown, deductions, and net salary.

> **As a school admin**, I want to mark payrolls as paid once bank transfers are confirmed so we have an accurate payment trail.

> **As an accountant**, I want to filter payroll by month, year, or staff member to prepare financial reports.

---

## Payroll Model

| Field | Type | Description |
|-------|------|-------------|
| `school_id` | FK | Owning school |
| `staff_id` | FK → users | Staff member receiving pay |
| `academic_year_id` | FK | Academic year (optional) |
| `month` | integer | 1–12 |
| `year` | integer | 4-digit year |
| `basic_salary` | decimal | Base gross salary |
| `allowances` | decimal | Housing, transport, and other allowances |
| `deductions` | decimal | Tax, pension, loans, etc. |
| `net_salary` | decimal | `basic_salary + allowances − deductions` (auto-calculated) |
| `payment_date` | date | Date salary was disbursed |
| `payment_method` | enum | `bank_transfer`, `cash`, `cheque` |
| `status` | enum | `pending`, `paid`, `cancelled` |
| `processed_by` | FK → users | Who created/approved the payroll |
| `notes` | string | Any additional remarks |

**Net salary is always recalculated** whenever `basic_salary`, `allowances`, or `deductions` change.

---

## API Endpoints

**Required module:** `fee_management`
**Base path:** `/api/v1/financial/payroll`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/payroll` | List payrolls (filter: staff_id, month, year, status) |
| POST | `/payroll` | Create payroll record |
| GET | `/payroll/{id}` | Get payroll details |
| PUT | `/payroll/{id}` | Update payroll (salary components or status) |
| DELETE | `/payroll/{id}` | Delete payroll (blocked if status=paid) |
| GET | `/payroll/{id}/pay-stub` | Generate pay stub for this record |

---

## Full Request / Response Examples

### List Payrolls

```
GET /api/v1/financial/payroll
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `staff_id` | integer | Filter by staff member |
| `month` | integer | Filter by month (1–12) |
| `year` | integer | Filter by year (e.g. `2026`) |
| `status` | string | `pending`, `paid`, `cancelled` |
| `per_page` | integer | Items per page (default 20) |
| `page` | integer | Page number |

**Example — list March 2026 payroll:**
```
GET /api/v1/financial/payroll?month=3&year=2026
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK`:**
```json
{
  "data": [
    {
      "id": 22,
      "staff_id": 14,
      "staff_name": "Mr. Chukwuemeka Obi",
      "staff_role": "teacher",
      "department": "Mathematics",
      "month": 3,
      "year": 2026,
      "period": "March 2026",
      "basic_salary": "120000.00",
      "allowances": "30000.00",
      "deductions": "18000.00",
      "net_salary": "132000.00",
      "payment_date": "2026-03-29",
      "payment_method": "bank_transfer",
      "status": "paid",
      "processed_by": "Mrs. Adaobi Nwosu",
      "notes": "March 2026 salary",
      "created_at": "2026-03-25T09:00:00Z"
    },
    {
      "id": 23,
      "staff_id": 15,
      "staff_name": "Miss Ngozi Eze",
      "staff_role": "teacher",
      "department": "English",
      "month": 3,
      "year": 2026,
      "period": "March 2026",
      "basic_salary": "95000.00",
      "allowances": "20000.00",
      "deductions": "14250.00",
      "net_salary": "100750.00",
      "payment_date": null,
      "payment_method": "bank_transfer",
      "status": "pending",
      "processed_by": "Mrs. Adaobi Nwosu",
      "notes": null,
      "created_at": "2026-03-25T09:05:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 64
  },
  "summary": {
    "period": "March 2026",
    "total_staff": 64,
    "total_gross": 7800000,
    "total_deductions": 975000,
    "total_net": 6825000,
    "paid_count": 48,
    "pending_count": 16,
    "cancelled_count": 0
  }
}
```

---

### Create Payroll

```
POST /api/v1/financial/payroll
Authorization: Bearer {token}
X-Subdomain: greenfield
Content-Type: application/json
```

**Request Body:**
```json
{
  "staff_id": 14,
  "month": 3,
  "year": 2026,
  "basic_salary": 120000,
  "allowances": 30000,
  "deductions": 18000,
  "payment_method": "bank_transfer",
  "notes": "March 2026 salary"
}
```

| Field | Required | Notes |
|-------|----------|-------|
| `staff_id` | Yes | Must be a valid user in this school |
| `month` | Yes | 1–12 |
| `year` | Yes | 4-digit year |
| `basic_salary` | Yes | Must be > 0 |
| `allowances` | No | Defaults to 0 |
| `deductions` | No | Defaults to 0 |
| `payment_method` | No | Defaults to `bank_transfer` |
| `notes` | No | Optional remarks |

**Response `201 Created`:**
```json
{
  "message": "Payroll created successfully",
  "payroll": {
    "id": 22,
    "staff_id": 14,
    "staff_name": "Mr. Chukwuemeka Obi",
    "month": 3,
    "year": 2026,
    "period": "March 2026",
    "basic_salary": "120000.00",
    "allowances": "30000.00",
    "deductions": "18000.00",
    "net_salary": "132000.00",
    "payment_date": null,
    "payment_method": "bank_transfer",
    "status": "pending",
    "processed_by": 1,
    "processed_by_name": "Mrs. Adaobi Nwosu",
    "notes": "March 2026 salary",
    "created_at": "2026-03-25T09:00:00Z"
  }
}
```

**Response `409 Conflict` (duplicate month/year for same staff):**
```json
{
  "message": "A payroll record already exists for Mr. Chukwuemeka Obi for March 2026. Use the update endpoint to modify it."
}
```

**Response `422 Unprocessable Entity`:**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "staff_id": ["The selected staff member does not belong to this school."],
    "month": ["The month must be between 1 and 12."]
  }
}
```

---

### Get Payroll Details

```
GET /api/v1/financial/payroll/{id}
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK`:**
```json
{
  "payroll": {
    "id": 22,
    "staff": {
      "id": 14,
      "name": "Mr. Chukwuemeka Obi",
      "email": "c.obi@greenfieldacademy.edu.ng",
      "role": "teacher",
      "department": "Mathematics",
      "bank_name": "First Bank Nigeria",
      "bank_account_number": "3012345678"
    },
    "school_id": 1,
    "month": 3,
    "year": 2026,
    "period": "March 2026",
    "basic_salary": "120000.00",
    "allowances": "30000.00",
    "deductions": "18000.00",
    "net_salary": "132000.00",
    "payment_date": "2026-03-29",
    "payment_method": "bank_transfer",
    "status": "paid",
    "processed_by": {
      "id": 1,
      "name": "Mrs. Adaobi Nwosu"
    },
    "notes": "March 2026 salary",
    "created_at": "2026-03-25T09:00:00Z",
    "updated_at": "2026-03-29T14:00:00Z"
  }
}
```

---

### Update Payroll

Used to adjust salary components while status is `pending`, or to mark a record as paid after bank transfer.

```
PUT /api/v1/financial/payroll/{id}
Authorization: Bearer {token}
X-Subdomain: greenfield
Content-Type: application/json
```

**Request Body — adjust deductions:**
```json
{
  "deductions": 20000,
  "notes": "Pension deduction updated per March schedule"
}
```

**Response `200 OK`:**
```json
{
  "message": "Payroll updated successfully",
  "payroll": {
    "id": 22,
    "basic_salary": "120000.00",
    "allowances": "30000.00",
    "deductions": "20000.00",
    "net_salary": "130000.00",
    "status": "pending",
    "updated_at": "2026-03-26T10:00:00Z"
  }
}
```

**Request Body — mark as paid:**
```json
{
  "status": "paid",
  "payment_date": "2026-03-29",
  "payment_method": "bank_transfer"
}
```

**Response `200 OK`:**
```json
{
  "message": "Payroll marked as paid",
  "payroll": {
    "id": 22,
    "status": "paid",
    "payment_date": "2026-03-29",
    "payment_method": "bank_transfer",
    "updated_at": "2026-03-29T14:00:00Z"
  }
}
```

**Response `422 Unprocessable Entity` (attempt to update a paid record's salary):**
```json
{
  "message": "Salary components cannot be changed after a payroll is marked as paid. Cancel and recreate if correction is needed."
}
```

---

### Delete Payroll

```
DELETE /api/v1/financial/payroll/{id}
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK`:**
```json
{
  "message": "Payroll record deleted successfully"
}
```

**Response `409 Conflict` (cannot delete paid record):**
```json
{
  "message": "Cannot delete a paid payroll record. Cancel it first if a correction is needed."
}
```

---

### Get Pay Stub

Returns the full pay stub data for a given payroll record. This is used to render a printable/downloadable pay stub in the frontend.

```
GET /api/v1/financial/payroll/{id}/pay-stub
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK` (full pay stub JSON):**
```json
{
  "pay_stub": {
    "stub_id": "PS-2026-0022",
    "generated_at": "2026-03-30T17:00:00Z",

    "school": {
      "name": "Greenfield Academy",
      "address": "14 Victoria Island, Lagos",
      "phone": "+2348012345678",
      "email": "info@greenfieldacademy.edu.ng",
      "logo_url": "https://cdn.compasse.africa/schools/greenfield/logo.png"
    },

    "employee": {
      "id": 14,
      "name": "Mr. Chukwuemeka Obi",
      "email": "c.obi@greenfieldacademy.edu.ng",
      "role": "Teacher",
      "department": "Mathematics",
      "employment_type": "full_time",
      "bank_name": "First Bank Nigeria",
      "bank_account_number": "3012345678"
    },

    "period": {
      "month": 3,
      "year": 2026,
      "label": "March 2026",
      "payment_date": "2026-03-29"
    },

    "earnings": {
      "basic_salary": 120000,
      "allowances": 30000,
      "gross_salary": 150000
    },

    "deductions": {
      "total": 18000,
      "breakdown": {
        "tax_paye": 12000,
        "pension": 6000,
        "loan_repayment": 0,
        "other": 0
      }
    },

    "net_salary": 132000,

    "payment_info": {
      "method": "bank_transfer",
      "status": "paid",
      "reference": null
    },

    "processed_by": "Mrs. Adaobi Nwosu",
    "notes": "March 2026 salary"
  }
}
```

**Response `403 Forbidden` (staff accessing another staff's stub):**
```json
{
  "message": "You do not have permission to view this pay stub."
}
```

**Response `404 Not Found`:**
```json
{
  "message": "Payroll record not found."
}
```

---

## Business Rules

1. **Duplicate prevention** — The system blocks creating a second payroll record for the same `staff_id + month + year` combination
2. **Delete guard** — Paid payrolls cannot be deleted (status=`paid`); only `pending` and `cancelled` records can be removed
3. **Auto net salary** — `net_salary` is always `basic_salary + allowances - deductions`; it cannot be set manually
4. **Salary lock on paid** — Salary component fields (`basic_salary`, `allowances`, `deductions`) cannot be changed once status is `paid`
5. **Processed by** — `processed_by` is auto-set to the authenticated user on creation
6. **School scoping** — All payroll queries are scoped to the current tenant's school
7. **Staff access** — Staff members (role: `teacher`, `staff`, etc.) can only view their own pay stubs via `GET /payroll/{id}/pay-stub`; they cannot list all payroll records or see other staff pay data

---

## Typical Monthly Payroll Workflow

```
1. Accountant creates payroll record for each staff member
       POST /payroll  { staff_id, month, year, basic_salary, allowances, deductions }
       → status = pending, net_salary auto-calculated

2. Admin reviews and optionally adjusts deductions
       PUT /payroll/{id}  { deductions: 20000 }
       → net_salary recalculated automatically

3. Bank transfers are made externally (outside the system)

4. Accountant marks each record as paid
       PUT /payroll/{id}  { "status": "paid", "payment_date": "2026-03-29" }

5. Staff view their pay stub
       GET /payroll/{id}/pay-stub
       → Full pay stub JSON returned for rendering
```

---

## Frontend Integration — Payroll Listing and Pay Stub Modal

### Step 1 — Check Module Access on Route Mount

```typescript
// pages/payroll/index.tsx
import { hasModule } from '@/services/modules';
import { getSubdomain } from '@/utils/tenancy';

export default function PayrollPage() {
  const subdomain = getSubdomain()!;

  if (!hasModule(subdomain, 'fee_management')) {
    return <UpgradePrompt module="fee_management" />;
  }

  return <PayrollList subdomain={subdomain} />;
}
```

### Step 2 — Payroll List with Month/Year Selectors

```typescript
// components/PayrollList.tsx
import { useState, useEffect } from 'react';
import { createApiClient } from '@/services/api';

const MONTHS = [
  { value: 1, label: 'January' }, { value: 2, label: 'February' },
  { value: 3, label: 'March' },   { value: 4, label: 'April' },
  { value: 5, label: 'May' },     { value: 6, label: 'June' },
  { value: 7, label: 'July' },    { value: 8, label: 'August' },
  { value: 9, label: 'September' },{ value: 10, label: 'October' },
  { value: 11, label: 'November' },{ value: 12, label: 'December' },
];

export function PayrollList({ subdomain }: { subdomain: string }) {
  const currentDate = new Date();
  const [month, setMonth] = useState(currentDate.getMonth() + 1);
  const [year, setYear] = useState(currentDate.getFullYear());
  const [payrolls, setPayrolls] = useState([]);
  const [summary, setSummary] = useState(null);
  const [loading, setLoading] = useState(true);
  const [selectedPayroll, setSelectedPayroll] = useState(null); // for pay stub modal

  const api = createApiClient(subdomain);

  useEffect(() => {
    setLoading(true);
    api.get(`/financial/payroll?month=${month}&year=${year}&per_page=100`)
      .then(data => {
        setPayrolls(data.data);
        setSummary(data.summary);
      })
      .finally(() => setLoading(false));
  }, [month, year]);

  // Generate year options — current year back 3 years
  const yearOptions = Array.from({ length: 4 }, (_, i) => year - i);

  return (
    <div>
      <div className="filters">
        <select value={month} onChange={e => setMonth(Number(e.target.value))}>
          {MONTHS.map(m => (
            <option key={m.value} value={m.value}>{m.label}</option>
          ))}
        </select>
        <select value={year} onChange={e => setYear(Number(e.target.value))}>
          {yearOptions.map(y => (
            <option key={y} value={y}>{y}</option>
          ))}
        </select>
      </div>

      {summary && (
        <div className="payroll-summary">
          <span>Total Gross: ₦{summary.total_gross.toLocaleString()}</span>
          <span>Total Deductions: ₦{summary.total_deductions.toLocaleString()}</span>
          <span>Total Net: ₦{summary.total_net.toLocaleString()}</span>
          <span>Paid: {summary.paid_count} / {summary.total_staff}</span>
        </div>
      )}

      {loading ? <Spinner /> : (
        <table>
          <thead>
            <tr>
              <th>Staff</th>
              <th>Department</th>
              <th>Basic</th>
              <th>Allowances</th>
              <th>Deductions</th>
              <th>Net Pay</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {payrolls.map(p => (
              <tr key={p.id}>
                <td>{p.staff_name}</td>
                <td>{p.department}</td>
                <td>₦{Number(p.basic_salary).toLocaleString()}</td>
                <td>₦{Number(p.allowances).toLocaleString()}</td>
                <td>₦{Number(p.deductions).toLocaleString()}</td>
                <td>₦{Number(p.net_salary).toLocaleString()}</td>
                <td>
                  <StatusBadge status={p.status} />
                </td>
                <td>
                  <button onClick={() => setSelectedPayroll(p)}>Pay Stub</button>
                  {p.status === 'pending' && (
                    <button onClick={() => markAsPaid(p.id)}>Mark Paid</button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {selectedPayroll && (
        <PayStubModal
          payrollId={selectedPayroll.id}
          onClose={() => setSelectedPayroll(null)}
        />
      )}
    </div>
  );
}
```

### Step 3 — Mark as Paid

```typescript
async function markAsPaid(payrollId: number) {
  const subdomain = getSubdomain()!;
  const api = createApiClient(subdomain);

  const today = new Date().toISOString().split('T')[0];

  try {
    await api.put(`/financial/payroll/${payrollId}`, {
      status: 'paid',
      payment_date: today,
    });

    toast.success('Payroll marked as paid');
    // Re-fetch payrolls for the current month/year
    refetchPayrolls();
  } catch (err) {
    toast.error(err.message || 'Failed to update payroll status');
  }
}
```

### Step 4 — Pay Stub Modal

```typescript
// components/PayStubModal.tsx
import { useEffect, useState } from 'react';
import { createApiClient } from '@/services/api';

export function PayStubModal({
  payrollId,
  onClose,
}: {
  payrollId: number;
  onClose: () => void;
}) {
  const subdomain = getSubdomain()!;
  const api = createApiClient(subdomain);
  const [stub, setStub] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get(`/financial/payroll/${payrollId}/pay-stub`)
      .then(data => setStub(data.pay_stub))
      .finally(() => setLoading(false));
  }, [payrollId]);

  if (loading) return <Modal onClose={onClose}><Spinner /></Modal>;

  return (
    <Modal onClose={onClose} printable>
      <div className="pay-stub" id="pay-stub-printable">
        {/* School Header */}
        <div className="stub-header">
          <img src={stub.school.logo_url} alt="School logo" />
          <h2>{stub.school.name}</h2>
          <p>{stub.school.address}</p>
        </div>

        <h3>PAY STUB — {stub.period.label}</h3>
        <p>Stub ID: {stub.stub_id}</p>

        {/* Employee Info */}
        <section>
          <h4>Employee Details</h4>
          <table>
            <tbody>
              <tr><td>Name</td><td>{stub.employee.name}</td></tr>
              <tr><td>Role</td><td>{stub.employee.role}</td></tr>
              <tr><td>Department</td><td>{stub.employee.department}</td></tr>
              <tr><td>Bank</td><td>{stub.employee.bank_name}</td></tr>
              <tr><td>Account</td><td>{stub.employee.bank_account_number}</td></tr>
            </tbody>
          </table>
        </section>

        {/* Earnings */}
        <section>
          <h4>Earnings</h4>
          <table>
            <tbody>
              <tr><td>Basic Salary</td><td>₦{stub.earnings.basic_salary.toLocaleString()}</td></tr>
              <tr><td>Allowances</td><td>₦{stub.earnings.allowances.toLocaleString()}</td></tr>
              <tr className="total"><td>Gross Salary</td><td>₦{stub.earnings.gross_salary.toLocaleString()}</td></tr>
            </tbody>
          </table>
        </section>

        {/* Deductions */}
        <section>
          <h4>Deductions</h4>
          <table>
            <tbody>
              <tr><td>PAYE Tax</td><td>₦{stub.deductions.breakdown.tax_paye.toLocaleString()}</td></tr>
              <tr><td>Pension</td><td>₦{stub.deductions.breakdown.pension.toLocaleString()}</td></tr>
              {stub.deductions.breakdown.loan_repayment > 0 && (
                <tr><td>Loan Repayment</td><td>₦{stub.deductions.breakdown.loan_repayment.toLocaleString()}</td></tr>
              )}
              <tr className="total"><td>Total Deductions</td><td>₦{stub.deductions.total.toLocaleString()}</td></tr>
            </tbody>
          </table>
        </section>

        {/* Net Pay */}
        <div className="net-pay">
          <strong>NET PAY: ₦{stub.net_salary.toLocaleString()}</strong>
        </div>

        <div className="stub-footer">
          <p>Payment Method: {stub.payment_info.method.replace('_', ' ')}</p>
          <p>Payment Date: {stub.period.payment_date ?? 'Pending'}</p>
          <p>Processed by: {stub.processed_by}</p>
          <p>Generated: {new Date(stub.generated_at).toLocaleString()}</p>
        </div>
      </div>

      <button onClick={() => window.print()}>Print Pay Stub</button>
    </Modal>
  );
}
```

### Step 5 — Create Payroll Form

```typescript
// components/CreatePayrollForm.tsx

async function handleCreatePayroll(formData: {
  staffId: number;
  month: number;
  year: number;
  basicSalary: number;
  allowances: number;
  deductions: number;
  notes: string;
}) {
  const subdomain = getSubdomain()!;
  const api = createApiClient(subdomain);

  try {
    const result = await api.post('/financial/payroll', {
      staff_id: formData.staffId,
      month: formData.month,
      year: formData.year,
      basic_salary: formData.basicSalary,
      allowances: formData.allowances,
      deductions: formData.deductions,
      notes: formData.notes,
    });

    // Show calculated net salary to user before closing form
    toast.success(
      `Payroll created. Net salary: ₦${Number(result.payroll.net_salary).toLocaleString()}`
    );
    onSuccess();
  } catch (err) {
    if (err.message?.includes('already exists')) {
      toast.error(err.message); // Duplicate month/year
    } else {
      toast.error('Failed to create payroll record');
    }
  }
}
```

### Pay Stub Print Styles (CSS)

```css
/* Only shown when printing */
@media print {
  body * { visibility: hidden; }
  #pay-stub-printable,
  #pay-stub-printable * { visibility: visible; }
  #pay-stub-printable { position: fixed; top: 0; left: 0; width: 100%; }
  button { display: none !important; }
}
```

---

## Bulk Payroll Creation (Batch Flow)

For schools with many staff, create payroll for all staff members at once:

```typescript
// Fetch all active staff first
const staffList = await api.get('/staff?per_page=200&status=active');

// Create payroll records in sequence (or parallel in batches)
const results = await Promise.allSettled(
  staffList.data.map((staff: any) =>
    api.post('/financial/payroll', {
      staff_id: staff.id,
      month: selectedMonth,
      year: selectedYear,
      basic_salary: staff.basic_salary, // pre-loaded from staff profile
      allowances: staff.allowances ?? 0,
      deductions: staff.deductions ?? 0,
    })
  )
);

const succeeded = results.filter(r => r.status === 'fulfilled').length;
const failed = results.filter(r => r.status === 'rejected').length;

toast.info(`Payroll created for ${succeeded} staff members. ${failed} failed (duplicates or errors).`);
```

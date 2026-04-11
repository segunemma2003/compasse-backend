# Inventory Management

> **Base URL:** `https://{subdomain}.compasse.africa/api/v1/`
> **Auth:** `Authorization: Bearer {token}` required on all protected endpoints
> **Module gate:** `inventory_management`

---

## Overview

The inventory module tracks school assets and consumables — books, lab equipment, sports gear, stationery, furniture — including purchase history, checkout loans, and returns.

---

## User Stories

> **As a school admin**, I want to categorise all school assets and track quantities so I always know what we have in stock.

> **As a librarian / store keeper**, I want to check out items to students or staff and record when items are returned so nothing goes missing.

> **As an admin**, I want to be alerted when item quantities fall below the minimum threshold so I can reorder before stock runs out.

> **As an accountant**, I want to record new purchases and disposals so the asset register stays accurate.

---

## Models

### InventoryCategory
| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Auto-increment primary key |
| `name` | string | Category name (e.g. "Lab Equipment", "Stationery") |
| `description` | string\|null | Optional description |
| `color` | string | UI colour hex for display (e.g. `#4CAF50`) |
| `created_at` | timestamp | Creation timestamp |
| `updated_at` | timestamp | Last update timestamp |

### InventoryItem
| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Auto-increment primary key |
| `category_id` | integer | Parent category FK |
| `name` | string | Item name |
| `sku` | string | Stock-keeping unit (unique per school) |
| `quantity` | integer | Current quantity in stock |
| `unit` | string | Unit of measurement (`pcs`, `kg`, `litres`, etc.) |
| `min_quantity` | integer | Low-stock threshold |
| `unit_price` | decimal | Cost per unit |
| `location` | string\|null | Physical storage location |
| `supplier` | string\|null | Supplier name |
| `status` | enum | `active`, `inactive`, `discontinued` |

`isLowStock()` returns `true` when `quantity <= min_quantity`.

### InventoryTransaction
| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Auto-increment primary key |
| `item_id` | integer | Item involved |
| `type` | enum | `purchase`, `checkout`, `return`, `adjustment`, `disposal` |
| `quantity` | integer | Units involved |
| `remaining_quantity` | integer | Stock level after this transaction (snapshot) |
| `borrower_id` | integer\|null | Who borrowed (for checkout) |
| `borrower_type` | string\|null | Polymorphic: `App\Models\Student`, `App\Models\Staff`, `App\Models\Teacher` |
| `borrower_name` | string\|null | Display name of borrower |
| `purpose` | string\|null | Reason for checkout/adjustment |
| `expected_return_date` | date\|null | When item should be returned |
| `returned_at` | timestamp\|null | Actual return timestamp |
| `notes` | string\|null | Additional notes |
| `status` | enum | `completed`, `checked_out`, `returned` |
| `recorded_by` | integer | FK → User who recorded this |

---

## API Endpoints

**Base path:** `/api/v1/inventory/`

### Categories

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/categories` | List categories (with item count) |
| POST | `/categories` | Create category |
| GET | `/categories/{id}` | Get single category |
| PUT | `/categories/{id}` | Update category |
| DELETE | `/categories/{id}` | Delete (blocked if items exist in category) |

### Items

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/items` | List items (filter: `category_id`, `status`, `low_stock=1`, `search`) |
| POST | `/items` | Create item |
| GET | `/items/{id}` | Get item + transaction history + low stock flag |
| PUT | `/items/{id}` | Update item |
| DELETE | `/items/{id}` | Delete (blocked if transaction history exists) |

### Transactions

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/transactions` | List transactions (filter: `item_id`, `type`, `status`, `borrower_id`) |
| POST | `/transactions` | Record purchase / adjustment / disposal |
| GET | `/transactions/{id}` | Get transaction details |
| PUT | `/transactions/{id}` | Update notes/purpose |
| DELETE | `/transactions/{id}` | Delete transaction record |
| POST | `/transactions/checkout` | Checkout item to a borrower |
| POST | `/transactions/{id}/return` | Return a checked-out item |

---

## Request / Response Examples

### Categories

#### Create Category

**Request**
```http
POST /api/v1/inventory/categories HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "name": "Lab Equipment",
  "description": "Science laboratory tools and apparatus",
  "color": "#2196F3"
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Category created successfully",
  "category": {
    "id": 3,
    "name": "Lab Equipment",
    "description": "Science laboratory tools and apparatus",
    "color": "#2196F3",
    "items_count": 0,
    "created_at": "2026-03-30T08:15:00Z",
    "updated_at": "2026-03-30T08:15:00Z"
  }
}
```

#### List Categories

**Request**
```http
GET /api/v1/inventory/categories HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": [
    {
      "id": 1,
      "name": "Stationery",
      "description": "Pens, notebooks, markers",
      "color": "#FF9800",
      "items_count": 12
    },
    {
      "id": 2,
      "name": "Sports Equipment",
      "description": "Football, basketball, nets",
      "color": "#4CAF50",
      "items_count": 8
    },
    {
      "id": 3,
      "name": "Lab Equipment",
      "description": "Science laboratory tools and apparatus",
      "color": "#2196F3",
      "items_count": 0
    }
  ],
  "meta": {
    "total": 3
  }
}
```

#### Update Category

**Request**
```http
PUT /api/v1/inventory/categories/3 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "name": "Laboratory Equipment",
  "color": "#1565C0"
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Category updated successfully",
  "category": {
    "id": 3,
    "name": "Laboratory Equipment",
    "description": "Science laboratory tools and apparatus",
    "color": "#1565C0",
    "items_count": 0
  }
}
```

#### Delete Category

**Request**
```http
DELETE /api/v1/inventory/categories/3 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Category deleted successfully"
}
```

**Blocked response** (category has items) `HTTP 422 Unprocessable Entity`
```json
{
  "message": "Cannot delete category with existing items. Remove or reassign items first."
}
```

---

### Items

#### Create Item

**Request**
```http
POST /api/v1/inventory/items HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "category_id": 2,
  "name": "Bunsen Burner",
  "sku": "LAB-BB-001",
  "quantity": 20,
  "unit": "pcs",
  "min_quantity": 5,
  "unit_price": 4500,
  "location": "Science Lab Store",
  "supplier": "EduSupply Ltd",
  "status": "active"
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Item created successfully",
  "item": {
    "id": 7,
    "category_id": 2,
    "category": {
      "id": 2,
      "name": "Lab Equipment",
      "color": "#2196F3"
    },
    "name": "Bunsen Burner",
    "sku": "LAB-BB-001",
    "quantity": 20,
    "unit": "pcs",
    "min_quantity": 5,
    "unit_price": "4500.00",
    "location": "Science Lab Store",
    "supplier": "EduSupply Ltd",
    "status": "active",
    "is_low_stock": false,
    "created_at": "2026-03-30T09:00:00Z"
  }
}
```

#### List Items

**Request**
```http
GET /api/v1/inventory/items?category_id=2&status=active HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": [
    {
      "id": 7,
      "name": "Bunsen Burner",
      "sku": "LAB-BB-001",
      "category": { "id": 2, "name": "Lab Equipment" },
      "quantity": 20,
      "unit": "pcs",
      "min_quantity": 5,
      "unit_price": "4500.00",
      "location": "Science Lab Store",
      "status": "active",
      "is_low_stock": false
    },
    {
      "id": 8,
      "name": "Test Tubes (pack of 10)",
      "sku": "LAB-TT-010",
      "category": { "id": 2, "name": "Lab Equipment" },
      "quantity": 3,
      "unit": "pack",
      "min_quantity": 5,
      "unit_price": "1200.00",
      "location": "Science Lab Store",
      "status": "active",
      "is_low_stock": true
    }
  ],
  "meta": {
    "total": 2,
    "per_page": 15,
    "current_page": 1,
    "last_page": 1
  }
}
```

#### List Low-Stock Items

**Request**
```http
GET /api/v1/inventory/items?low_stock=1 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": [
    {
      "id": 8,
      "name": "Test Tubes (pack of 10)",
      "sku": "LAB-TT-010",
      "quantity": 3,
      "min_quantity": 5,
      "unit": "pack",
      "is_low_stock": true,
      "category": { "id": 2, "name": "Lab Equipment" }
    },
    {
      "id": 11,
      "name": "A4 Printer Paper",
      "sku": "STN-PP-A4",
      "quantity": 2,
      "min_quantity": 10,
      "unit": "ream",
      "is_low_stock": true,
      "category": { "id": 1, "name": "Stationery" }
    }
  ],
  "meta": {
    "total": 2
  }
}
```

#### Get Single Item

**Request**
```http
GET /api/v1/inventory/items/7 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": {
    "id": 7,
    "name": "Bunsen Burner",
    "sku": "LAB-BB-001",
    "category": { "id": 2, "name": "Lab Equipment", "color": "#2196F3" },
    "quantity": 18,
    "unit": "pcs",
    "min_quantity": 5,
    "unit_price": "4500.00",
    "location": "Science Lab Store",
    "supplier": "EduSupply Ltd",
    "status": "active",
    "is_low_stock": false,
    "recent_transactions": [
      {
        "id": 18,
        "type": "checkout",
        "quantity": 2,
        "remaining_quantity": 18,
        "borrower_name": "Emeka Okafor",
        "purpose": "Lab practical — SS2B",
        "status": "checked_out",
        "created_at": "2026-03-28T10:00:00Z"
      }
    ]
  }
}
```

#### Update Item

**Request**
```http
PUT /api/v1/inventory/items/7 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "min_quantity": 8,
  "location": "Physics Lab Store",
  "unit_price": 4800
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Item updated successfully",
  "item": {
    "id": 7,
    "name": "Bunsen Burner",
    "min_quantity": 8,
    "location": "Physics Lab Store",
    "unit_price": "4800.00",
    "is_low_stock": false
  }
}
```

#### Delete Item

**Request**
```http
DELETE /api/v1/inventory/items/7 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** (no transaction history) `HTTP 200 OK`
```json
{
  "message": "Item deleted successfully"
}
```

**Blocked response** (has transaction history) `HTTP 422 Unprocessable Entity`
```json
{
  "message": "Cannot delete item with existing transaction history."
}
```

---

### Transactions

#### Record a Purchase (increases stock)

**Request**
```http
POST /api/v1/inventory/transactions HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "item_id": 7,
  "type": "purchase",
  "quantity": 10,
  "purpose": "Restocking after term break",
  "notes": "Purchased from EduSupply Ltd, invoice #INV-2026-0112"
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Transaction recorded successfully",
  "transaction": {
    "id": 22,
    "item_id": 7,
    "item": { "name": "Bunsen Burner", "sku": "LAB-BB-001" },
    "type": "purchase",
    "quantity": 10,
    "remaining_quantity": 28,
    "purpose": "Restocking after term break",
    "notes": "Purchased from EduSupply Ltd, invoice #INV-2026-0112",
    "status": "completed",
    "recorded_by": { "id": 3, "name": "Mr. Segun Adeyemi" },
    "created_at": "2026-03-30T11:00:00Z"
  }
}
```

#### Checkout Item to a Borrower

**Request**
```http
POST /api/v1/inventory/transactions/checkout HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "item_id": 7,
  "quantity": 2,
  "borrower_id": 45,
  "borrower_type": "App\\Models\\Student",
  "borrower_name": "Emeka Okafor",
  "purpose": "Lab practical — SS2B",
  "expected_return_date": "2026-04-10"
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Item checked out successfully",
  "transaction": {
    "id": 18,
    "item_id": 7,
    "item": {
      "id": 7,
      "name": "Bunsen Burner",
      "quantity": 18,
      "is_low_stock": false
    },
    "type": "checkout",
    "quantity": 2,
    "remaining_quantity": 18,
    "borrower_id": 45,
    "borrower_type": "App\\Models\\Student",
    "borrower_name": "Emeka Okafor",
    "purpose": "Lab practical — SS2B",
    "expected_return_date": "2026-04-10",
    "returned_at": null,
    "status": "checked_out",
    "recorded_by": { "id": 3, "name": "Mr. Segun Adeyemi" },
    "created_at": "2026-03-28T10:00:00Z"
  }
}
```

**Insufficient stock** `HTTP 422 Unprocessable Entity`
```json
{
  "message": "Insufficient stock. Available: 1, Requested: 2"
}
```

#### Return a Checked-Out Item

**Request**
```http
POST /api/v1/inventory/transactions/18/return HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "notes": "Returned in good condition"
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Item returned successfully",
  "transaction": {
    "id": 18,
    "type": "checkout",
    "quantity": 2,
    "remaining_quantity": 18,
    "borrower_name": "Emeka Okafor",
    "status": "returned",
    "returned_at": "2026-04-09T14:30:00Z",
    "notes": "Returned in good condition",
    "item": {
      "id": 7,
      "name": "Bunsen Burner",
      "quantity": 20
    }
  }
}
```

#### Record an Adjustment

**Request**
```http
POST /api/v1/inventory/transactions HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "item_id": 7,
  "type": "adjustment",
  "quantity": -3,
  "purpose": "Damaged during storage — written off after audit",
  "notes": "Three units found cracked during term-end stock count"
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Transaction recorded successfully",
  "transaction": {
    "id": 25,
    "type": "adjustment",
    "quantity": -3,
    "remaining_quantity": 17,
    "purpose": "Damaged during storage — written off after audit",
    "status": "completed",
    "created_at": "2026-03-30T14:00:00Z"
  }
}
```

#### List Transactions

**Request**
```http
GET /api/v1/inventory/transactions?item_id=7&type=checkout&status=checked_out HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": [
    {
      "id": 18,
      "type": "checkout",
      "quantity": 2,
      "remaining_quantity": 18,
      "borrower_name": "Emeka Okafor",
      "borrower_type": "App\\Models\\Student",
      "purpose": "Lab practical — SS2B",
      "expected_return_date": "2026-04-10",
      "returned_at": null,
      "status": "checked_out",
      "item": { "id": 7, "name": "Bunsen Burner" },
      "created_at": "2026-03-28T10:00:00Z"
    }
  ],
  "meta": {
    "total": 1,
    "per_page": 15,
    "current_page": 1
  }
}
```

---

## Business Rules

1. **Atomic quantity changes** — All checkout and return operations use `DB::transaction()` to prevent race conditions.
2. **Insufficient stock guard** — Checkout is rejected if `item.quantity < requested_quantity`.
3. **Return guard** — Only open `checkout` transactions (status = `checked_out`) can be returned.
4. **Category guard** — A category cannot be deleted while it has items.
5. **Transaction history guard** — An item cannot be deleted if it has any transaction records.
6. **Snapshot quantity** — `remaining_quantity` is recorded on every transaction as a point-in-time snapshot, enabling a full audit trail even if the item is later adjusted.
7. **Negative adjustment** — Adjustments accept negative `quantity` values for write-offs and disposals.

---

## Frontend Integration

### How the Frontend Handles Tenancy

The inventory module lives under the authenticated tenant context. When the frontend makes API calls:

1. **Subdomain routing** — The app is loaded at `https://{school}.compasse.africa`. All API requests go to `https://{school}.compasse.africa/api/v1/inventory/...`. The subdomain is part of the origin URL so no extra header is needed.
2. **Bearer token** — After login, the token is stored (e.g. in `localStorage` or a secure cookie). Every request includes `Authorization: Bearer {token}`.
3. **Module gate check** — On app load, the frontend checks the school's enabled modules. If `inventory_management` is not in the list, the Inventory nav item is hidden entirely.

### Inventory Dashboard — Low-Stock Alerts

```
GET /api/v1/inventory/items?low_stock=1
```

The dashboard fetches low-stock items on load and displays a dismissible alert banner:

> "5 items are running low — [View All]"

The alert card shows `item.name`, `item.quantity / item.min_quantity`, and the category colour badge.

### Checkout Form with Borrower Search

The checkout form works in two steps:

1. **Search borrower** — A debounced input calls `GET /api/v1/students?search={query}` (or staff/teachers). The user selects a result which populates `borrower_id`, `borrower_type`, and `borrower_name` in the form state.
2. **Submit checkout** — `POST /api/v1/inventory/transactions/checkout` with the assembled payload. On `HTTP 201`, a success toast is shown and the item's quantity badge updates in real time.

### Return Confirmation

The active-checkouts table has a "Return" button per row. Clicking it opens a confirmation modal:

> "Confirm return of 2 × Bunsen Burner from Emeka Okafor?"

On confirmation the frontend calls `POST /api/v1/inventory/transactions/{id}/return`. On success, the row moves from "Checked Out" to "Returned" with the `returned_at` timestamp displayed.

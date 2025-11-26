# Librarian Dashboard API Documentation

Complete API reference for Librarian Dashboard functionality.

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

### Get Librarian Dashboard

**Endpoint:** `GET /api/v1/dashboard/librarian`

**Response (200):**

```json
{
  "user": {
    "id": 25,
    "name": "Mrs. Librarian",
    "email": "librarian@westwoodschool.com",
    "role": "librarian"
  },
  "stats": {
    "total_books": 5000,
    "available_books": 4200,
    "borrowed_books": 800,
    "overdue_books": 45,
    "total_members": 650,
    "active_members": 420,
    "books_added_this_month": 50,
    "popular_categories": [...]
  },
  "recent_borrows": [...],
  "overdue_list": [...],
  "pending_requests": [...],
  "role": "librarian"
}
```

---

## Book Management

### Add New Book

**Endpoint:** `POST /api/v1/library/books`

**Request Body:**

```json
{
    "title": "Things Fall Apart",
    "author": "Chinua Achebe",
    "isbn": "978-0-385-47454-2",
    "category_id": 1,
    "publisher": "Heinemann",
    "publication_year": 1958,
    "copies": 10,
    "shelf_location": "A-12",
    "description": "Classic Nigerian literature"
}
```

### Get All Books

**Endpoint:** `GET /api/v1/library/books`

**Query Parameters:**

-   `category_id` - Filter by category
-   `status` - available, borrowed, reserved
-   `search` - Search title, author, ISBN

### Borrow Book

**Endpoint:** `POST /api/v1/library/books/{id}/borrow`

**Request Body:**

```json
{
    "student_id": 10,
    "due_date": "2025-12-10"
}
```

### Return Book

**Endpoint:** `POST /api/v1/library/books/{id}/return`

**Request Body:**

```json
{
    "borrow_id": 123,
    "condition": "good",
    "fine": 0
}
```

### Get Overdue Books

**Endpoint:** `GET /api/v1/library/books/overdue`

### Get Popular Books

**Endpoint:** `GET /api/v1/library/books/popular?limit=10`

---

## Member Management

### Get Borrowing History

**Endpoint:** `GET /api/v1/library/members/{student_id}/history`

### Get Active Borrows

**Endpoint:** `GET /api/v1/library/members/{student_id}/active-borrows`

### Block/Unblock Member

**Endpoint:** `POST /api/v1/library/members/{student_id}/block`

---

## Reports

### Monthly Report

**Endpoint:** `GET /api/v1/library/reports/monthly?month=11&year=2025`

### Most Borrowed Books

**Endpoint:** `GET /api/v1/library/reports/most-borrowed`

### Fine Collection Report

**Endpoint:** `GET /api/v1/library/reports/fines`

---

## Summary

### Librarian Can:

✅ Manage book inventory  
✅ Process book borrowing and returns  
✅ Track overdue books and fines  
✅ Manage library members  
✅ Generate library reports  
✅ Monitor popular books

---

**Last Updated:** November 26, 2025  
**API Version:** 1.0.0

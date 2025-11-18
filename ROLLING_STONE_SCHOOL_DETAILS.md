# Rolling Stone International School - Admin Details

## 🏫 School Information

- **School Name:** Rolling Stone International School
- **School ID:** 1
- **Tenant ID:** `620636d5-bb26-4b86-b049-c0236c44126f`
- **Database Name:** `20251117143037_sessions-test-school`
- **Status:** Active

## 👤 Admin Login Credentials

### Primary Admin Account
- **Email:** `admin@rolling-stone-international-school.com`
- **Password:** `Password@12345`
- **Role:** School Admin

## 🌐 API Endpoints

### Base URL
- **Local:** `http://localhost:8000`
- **Production:** `https://api.compasse.net`

### Authentication Endpoint
```
POST /api/v1/auth/login
```

### School Endpoints
```
GET  /api/v1/schools/{school_id}
PUT  /api/v1/schools/{school_id}
GET  /api/v1/schools/{school_id}/stats
GET  /api/v1/schools/{school_id}/dashboard
```

## 📝 How to Login

### Option 1: Using cURL
```bash
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -H 'X-Tenant-ID: 620636d5-bb26-4b86-b049-c0236c44126f' \
  -d '   {
     "email": "admin@rolling-stone-international-school.com",
     "password": "Password@12345",
     "tenant_id": "620636d5-bb26-4b86-b049-c0236c44126f"
   }'
```

### Option 2: Using Postman/Insomnia
1. **Method:** POST
2. **URL:** `http://localhost:8000/api/v1/auth/login`
3. **Headers:**
   - `Content-Type: application/json`
   - `X-Tenant-ID: 620636d5-bb26-4b86-b049-c0236c44126f`
4. **Body (JSON):**
   ```json
   {
     "email": "admin@rolling-stone-international-school.com",
     "password": "Password@12345",
     "tenant_id": "620636d5-bb26-4b86-b049-c0236c44126f"
   }
   ```

### Option 3: Using Frontend
Include in your request:
- **Header:** `X-Tenant-ID: 620636d5-bb26-4b86-b049-c0236c44126f`
- **Body:** Include `tenant_id` in the login request

## 🔐 Expected Response

On successful login, you'll receive:
```json
{
  "message": "Login successful",
  "user": {
    "id": 1,
    "name": "Administrator",
    "email": "admin@rolling-stone-international-school.com",
    "role": "school_admin",
    "status": "active"
  },
  "token": "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "token_type": "Bearer",
  "tenant": {
    "id": "620636d5-bb26-4b86-b049-c0236c44126f",
    "name": "System Administration",
    "database_name": "20251117143037_sessions-test-school"
  },
  "school": {
    "id": 1,
    "name": "Rolling Stone International School"
  }
}
```

## 📋 Next Steps

1. **Login** using the admin credentials above
2. **Save the token** from the response for authenticated requests
3. **Use the token** in subsequent API calls:
   ```
   Authorization: Bearer {your_token_here}
   X-Tenant-ID: 620636d5-bb26-4b86-b049-c0236c44126f
   ```

## 🗄️ Database Access

The school's data is stored in a separate tenant database:
- **Database Name:** `20251117143037_sessions-test-school`
- **Host:** Same as main database
- **Tables Include:**
  - `users` - All school users
  - `schools` - School information
  - `students` - Student records
  - `teachers` - Teacher records
  - `classes` - Class information
  - `subjects` - Subject information
  - And all other school-specific tables

## ⚠️ Important Notes

1. **Always include `X-Tenant-ID` header** when making API requests for this school
2. **The admin password is:** `Password@12345` (change it after first login for security)
3. **All school data is isolated** in the tenant database
4. **The token expires** - use the refresh endpoint if needed

## 🔄 Token Refresh

If your token expires:
```
POST /api/v1/auth/refresh
Headers:
  Authorization: Bearer {your_token}
```

## 📞 Support

If you encounter any issues:
1. Verify the tenant ID is correct
2. Check that the database exists
3. Ensure the admin account exists in the tenant database
4. Verify network connectivity to the API server


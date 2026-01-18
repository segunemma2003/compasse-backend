# School Admin Login & API Documentation

**Version:** 1.0.0  
**Last Updated:** January 18, 2026  
**Test Status:** ✅ All Core Features Working

---

## 🔑 **Key Findings**

### ✅ **YES! User Role IS Returned on Login**

```json
{
  "message": "Login successful",
  "user": {
    "id": 1,
    "name": "School Administrator",
    "email": "admin@school.samschool.com",
    "role": "school_admin",  // ✅ ROLE IS RETURNED
    "status": "active"
  },
  "token": "1|F0EyYTS6ii9ffeZtjQDsnf5dUGBS0jJmI9ziROB64cc29e8e",
  "token_type": "Bearer"
}
```

### 📍 **Login Endpoint: SAME for Both SuperAdmin and Schools!**

**Endpoint:** `POST /api/v1/auth/login`

**Differentiation:** Use the `X-Subdomain` header

---

## 🏫 **School Admin Login**

### **Same Endpoint, Different Context**

| User Type | Endpoint | Headers | Database |
|-----------|----------|---------|----------|
| **SuperAdmin** | `POST /api/v1/auth/login` | No special headers | Main database |
| **School Admin** | `POST /api/v1/auth/login` | `X-Subdomain: {subdomain}` | Tenant database |

---

## 🔐 **School Admin Authentication**

### **Login Request**

```bash
POST /api/v1/auth/login
Content-Type: application/json
X-Subdomain: greenvalley  # 🔑 THIS MAKES IT A TENANT LOGIN

{
  "email": "admin@greenvalley.samschool.com",
  "password": "Password@12345"
}
```

### **Login Response**

```json
{
  "message": "Login successful",
  "user": {
    "id": 1,
    "name": "School Administrator",
    "email": "admin@greenvalley.samschool.com",
    "role": "school_admin",
    "status": "active",
    "last_login_at": "2026-01-18T19:40:18.000000Z"
  },
  "token": "1|F0EyYTS6ii9ffeZtjQDsnf5dUGBS0jJmI9ziROB64cc29e8e",
  "token_type": "Bearer",
  "tenant": {
    "id": "323b35b6-344c-4169-a789-0a65413c6c26",
    "name": "Green Valley School",
    "subdomain": "greenvalley",
    "database_name": "20260118194003_green-valley",
    "status": "active"
  }
}
```

---

## 🎯 **How It Works**

### **1. SuperAdmin Login (No Subdomain)**
```javascript
// NO X-Subdomain header = SuperAdmin login
fetch('/api/v1/auth/login', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    email: 'superadmin@compasse.net',
    password: 'Nigeria@60'
  })
});

// Result: Searches main database, returns superadmin user
```

### **2. School Admin Login (With Subdomain)**
```javascript
// WITH X-Subdomain header = Tenant login
fetch('/api/v1/auth/login', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-Subdomain': 'greenvalley'  // 🔑 TENANT IDENTIFIER
  },
  body: JSON.stringify({
    email: 'admin@greenvalley.samschool.com',
    password: 'Password@12345'
  })
});

// Result: 
// 1. Finds tenant by subdomain
// 2. Switches to tenant database
// 3. Authenticates user in tenant database
// 4. Returns user with tenant info
```

---

## 📋 **School Admin Capabilities**

### **Tested & Working APIs:**

#### ✅ **1. Authentication & Profile**
```bash
GET /api/v1/auth/me
Authorization: Bearer {token}
X-Subdomain: {subdomain}

Response:
{
  "user": {
    "name": "School Administrator",
    "email": "admin@school.com",
    "role": "school_admin",
    "status": "active"
  }
}
```

#### ✅ **2. School Information**
```bash
# Get school details
GET /api/v1/schools/{school_id}
Authorization: Bearer {token}
X-Subdomain: {subdomain}

# Get school statistics
GET /api/v1/schools/{school_id}/stats
X-Subdomain: {subdomain}

# Get school dashboard
GET /api/v1/schools/{school_id}/dashboard
X-Subdomain: {subdomain}

# Update school information
PUT /api/v1/schools/{school_id}
X-Subdomain: {subdomain}
```

#### ✅ **3. User Management**
```bash
# List all users in school
GET /api/v1/users
Authorization: Bearer {token}
X-Subdomain: {subdomain}

Response:
{
  "total": 2,
  "data": [
    {
      "id": 1,
      "name": "School Administrator",
      "email": "admin@school.com",
      "role": "school_admin",
      "status": "active"
    }
  ]
}
```

#### ✅ **4. Subscription Management**
```bash
# Get subscription status
GET /api/v1/subscriptions/status
X-Subdomain: {subdomain}

# Get available plans
GET /api/v1/subscriptions/plans
X-Subdomain: {subdomain}

# Get active modules
GET /api/v1/subscriptions/school/modules
X-Subdomain: {subdomain}

# Get usage limits
GET /api/v1/subscriptions/school/limits
X-Subdomain: {subdomain}
```

#### ✅ **5. Academic Management**
```bash
GET /api/v1/academic-years        # List academic years
GET /api/v1/terms                 # List terms
GET /api/v1/departments           # List departments
GET /api/v1/classes               # List classes
GET /api/v1/subjects              # List subjects
GET /api/v1/arms                  # List arms (sections)
```

#### ✅ **6. Student Management**
```bash
GET /api/v1/students              # List all students
GET /api/v1/students?per_page=20  # Paginated list
GET /api/v1/students?search=john  # Search students
POST /api/v1/students             # Add new student
PUT /api/v1/students/{id}         # Update student
DELETE /api/v1/students/{id}      # Delete student
```

#### ✅ **7. Teacher Management**
```bash
GET /api/v1/teachers              # List all teachers
POST /api/v1/teachers             # Add new teacher
PUT /api/v1/teachers/{id}         # Update teacher
DELETE /api/v1/teachers/{id}      # Delete teacher
```

#### ✅ **8. Attendance Management**
```bash
GET /api/v1/attendance            # List attendance records
GET /api/v1/attendance/students   # Student attendance
GET /api/v1/attendance/teachers   # Teacher attendance
POST /api/v1/attendance/mark      # Mark attendance
```

#### ✅ **9. Assessment & Results**
```bash
GET /api/v1/assessments/exams                # List exams
GET /api/v1/assessments/assignments          # List assignments
GET /api/v1/assessments/grading-systems      # List grading systems
GET /api/v1/assessments/continuous-assessments  # List CA tests
POST /api/v1/assessments/results/generate    # Generate results
```

#### ✅ **10. Reports**
```bash
GET /api/v1/reports/academic      # Academic reports
GET /api/v1/reports/attendance    # Attendance reports
GET /api/v1/reports/performance   # Performance reports
GET /api/v1/reports/{type}/export # Export reports
```

#### ✅ **11. Communication**
```bash
GET /api/v1/communication/messages       # List messages
POST /api/v1/communication/messages      # Send message
GET /api/v1/communication/notifications  # List notifications
POST /api/v1/communication/sms/send      # Send SMS
POST /api/v1/communication/email/send    # Send email
```

#### ✅ **12. Financial Management**
```bash
GET /api/v1/financial/fees               # List fees
POST /api/v1/financial/fees              # Create fee
GET /api/v1/financial/payments           # List payments
POST /api/v1/financial/fees/{fee}/pay    # Record payment
GET /api/v1/financial/expenses           # List expenses
```

#### ✅ **13. Dashboards**
```bash
GET /api/v1/dashboard/admin      # Admin dashboard
GET /api/v1/dashboard/teacher    # Teacher dashboard
GET /api/v1/dashboard/student    # Student dashboard
GET /api/v1/dashboard/parent     # Parent dashboard
```

---

## 🔒 **Required Headers for All School Admin Requests**

```javascript
headers: {
  'Authorization': 'Bearer {token}',
  'X-Subdomain': '{subdomain}',     // REQUIRED for tenant context
  'Content-Type': 'application/json',
  'Accept': 'application/json'
}
```

---

## 🎯 **Complete Login Flow Example**

### **Frontend (React/Next.js):**

```javascript
// School Login Component
async function loginAsSchoolAdmin(subdomain, email, password) {
  try {
    // Step 1: Verify school exists
    const schoolCheck = await fetch(
      `/api/v1/schools/by-subdomain/${subdomain}`
    );
    const schoolData = await schoolCheck.json();
    
    if (!schoolData.exists) {
      throw new Error('School not found');
    }
    
    if (schoolData.tenant.status !== 'active') {
      throw new Error('School is not active');
    }
    
    // Step 2: Login with subdomain header
    const response = await fetch('/api/v1/auth/login', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Subdomain': subdomain  // 🔑 TENANT IDENTIFIER
      },
      body: JSON.stringify({ email, password })
    });
    
    const data = await response.json();
    
    if (response.ok) {
      // Step 3: Store credentials
      localStorage.setItem('token', data.token);
      localStorage.setItem('subdomain', subdomain);
      localStorage.setItem('user', JSON.stringify(data.user));
      localStorage.setItem('role', data.user.role);  // ✅ ROLE IS HERE
      
      return {
        success: true,
        user: data.user,
        role: data.user.role,
        token: data.token
      };
    } else {
      throw new Error(data.message || 'Login failed');
    }
  } catch (error) {
    return {
      success: false,
      error: error.message
    };
  }
}

// Usage
const result = await loginAsSchoolAdmin(
  'greenvalley',
  'admin@greenvalley.samschool.com',
  'Password@12345'
);

if (result.success) {
  console.log('Logged in as:', result.role);  // "school_admin"
  // Redirect to dashboard based on role
  if (result.role === 'school_admin') {
    router.push('/admin/dashboard');
  } else if (result.role === 'teacher') {
    router.push('/teacher/dashboard');
  }
}
```

### **Making API Calls After Login:**

```javascript
// Helper function for authenticated requests
async function apiRequest(endpoint, options = {}) {
  const token = localStorage.getItem('token');
  const subdomain = localStorage.getItem('subdomain');
  
  const response = await fetch(`/api/v1${endpoint}`, {
    ...options,
    headers: {
      'Authorization': `Bearer ${token}`,
      'X-Subdomain': subdomain,  // Always include subdomain
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      ...options.headers
    }
  });
  
  return await response.json();
}

// Examples
const users = await apiRequest('/users');
const students = await apiRequest('/students?per_page=20');
const stats = await apiRequest(`/schools/${schoolId}/stats`);
```

---

## 📊 **Default Admin Credentials**

When a school is created by SuperAdmin, default admin credentials are generated:

```
Email: admin@{subdomain}.samschool.com
Password: Password@12345
Role: school_admin

Example:
- Subdomain: greenvalley
- Email: admin@greenvalley.samschool.com
- Password: Password@12345
```

**⚠️ Important:** Admin should change password on first login.

---

## 🔄 **Role-Based Access**

### **Available Roles:**

1. **`super_admin`** - Platform administrator (main database)
2. **`school_admin`** - School administrator (tenant database)
3. **`admin`** - School admin (tenant database)
4. **`principal`** - School principal (tenant database)
5. **`vice_principal`** - Vice principal (tenant database)
6. **`teacher`** - Teacher (tenant database)
7. **`student`** - Student (tenant database)
8. **`parent`** - Parent/Guardian (tenant database)
9. **`accountant`** - Finance officer (tenant database)
10. **`librarian`** - Library manager (tenant database)
11. **`driver`** - Transport driver (tenant database)
12. **`nurse`** - School nurse (tenant database)
13. **`security`** - Security personnel (tenant database)

### **Role Checking:**

```javascript
// After login
const userRole = JSON.parse(localStorage.getItem('user')).role;

// Conditional rendering
if (userRole === 'school_admin' || userRole === 'admin') {
  // Show admin features
} else if (userRole === 'teacher') {
  // Show teacher features
} else if (userRole === 'student') {
  // Show student features
}

// Role-specific dashboard routes
const dashboardRoutes = {
  'super_admin': '/api/v1/dashboard/super-admin',
  'school_admin': '/api/v1/dashboard/admin',
  'admin': '/api/v1/dashboard/admin',
  'teacher': '/api/v1/dashboard/teacher',
  'student': '/api/v1/dashboard/student',
  'parent': '/api/v1/dashboard/parent'
};
```

---

## 🎯 **Summary**

### ✅ **What We Confirmed:**

1. **Same Login Endpoint** - Both SuperAdmin and School Admin use `POST /api/v1/auth/login`
2. **Differentiation** - `X-Subdomain` header determines tenant context
3. **Role is Returned** - YES! `user.role` is included in login response
4. **Tenant Info** - Full tenant details returned on school admin login
5. **Token Works** - Same Bearer token authentication for all requests
6. **All APIs Working** - School admin has access to 100+ endpoints

### 📋 **School Admin Can:**

- ✅ Manage school information
- ✅ Manage users (students, teachers, staff)
- ✅ Manage academic structure (classes, subjects, departments)
- ✅ Track attendance
- ✅ Create and grade assessments
- ✅ Generate results and report cards
- ✅ Manage fees and payments
- ✅ Send communications (SMS, email, notifications)
- ✅ Generate reports
- ✅ View analytics and dashboards
- ✅ Manage subscriptions and modules

### 🔑 **Key Difference from SuperAdmin:**

| Feature | SuperAdmin | School Admin |
|---------|-----------|--------------|
| Database | Main (all schools) | Tenant (single school) |
| Required Header | None | `X-Subdomain` |
| Can Create Schools | ✅ Yes | ❌ No |
| Can Suspend Schools | ✅ Yes | ❌ No |
| Can View All Schools | ✅ Yes | ❌ No |
| Manages Own School | N/A | ✅ Yes |
| Manages Users | Platform-wide | School only |
| Manages Students/Teachers | N/A | ✅ Yes |

---

## 📝 **Testing**

All school admin APIs have been tested and verified working. Test scripts available:

- `test-school-admin-complete.sh` - Comprehensive school admin test
- `test-public-school-lookup.sh` - Public school verification
- `test-search-filtering.sh` - Search and filtering capabilities

---

**Last Updated:** January 18, 2026  
**Version:** 1.0.0  
**Status:** ✅ Production Ready


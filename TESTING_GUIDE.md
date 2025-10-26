# 🧪 Route Testing Guide

This guide will help you test all API routes locally to ensure they're working properly.

## 🚀 Quick Start

### 1. Run the Setup Script
```bash
./setup-testing.sh
```

This will:
- Install all dependencies
- Set up the database
- Run initial tests
- Start the development server

### 2. Manual Testing
```bash
# Test all routes automatically
php test-routes.php

# Run specific test suites
php artisan test --filter RouteTest
php artisan test tests/RouteTest.php
```

## 🔧 Manual Setup

If you prefer to set up manually:

### 1. Install Dependencies
```bash
composer install
npm install  # if you have frontend assets
```

### 2. Environment Setup
```bash
cp .env.example .env
php artisan key:generate
```

### 3. Database Setup
```bash
# Update .env with your database credentials
php artisan migrate:fresh --seed
```

### 4. Start Server
```bash
php artisan serve
```

## 📋 Testing All Routes

### Health Check
```bash
curl http://localhost:8000/api/health
```

### Authentication
```bash
# Login
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@samschool.com",
    "password": "password",
    "tenant_id": 1,
    "school_id": 1
  }'

# Get user info
curl http://localhost:8000/api/v1/auth/me \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### School Management
```bash
# Get school info
curl http://localhost:8000/api/v1/schools/1 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: 1"

# Get school stats
curl http://localhost:8000/api/v1/schools/1/stats \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: 1"
```

### Student Management
```bash
# Get all students
curl http://localhost:8000/api/v1/students \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: 1"

# Create student
curl -X POST http://localhost:8000/api/v1/students \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: 1" \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 1,
    "admission_number": "ADM001",
    "first_name": "John",
    "last_name": "Doe",
    "email": "john.doe@student.com",
    "admission_date": "2024-01-01",
    "class_id": 1,
    "arm_id": 1
  }'

# Get student details
curl http://localhost:8000/api/v1/students/1 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: 1"
```

### Teacher Management
```bash
# Get all teachers
curl http://localhost:8000/api/v1/teachers \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: 1"

# Create teacher
curl -X POST http://localhost:8000/api/v1/teachers \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: 1" \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 1,
    "employee_id": "EMP001",
    "first_name": "Jane",
    "last_name": "Smith",
    "email": "jane.smith@teacher.com",
    "employment_date": "2024-01-01",
    "department_id": 1
  }'
```

### Livestream Management
```bash
# Get all livestreams
curl http://localhost:8000/api/v1/livestreams \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: 1"

# Create livestream
curl -X POST http://localhost:8000/api/v1/livestreams \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: 1" \
  -H "Content-Type: application/json" \
  -d '{
    "teacher_id": 1,
    "class_id": 1,
    "subject_id": 1,
    "title": "Math Lesson",
    "description": "Basic Math Concepts",
    "start_time": "2024-12-25 10:00:00",
    "duration_minutes": 60
  }'

# Join livestream
curl -X POST http://localhost:8000/api/v1/livestreams/1/join \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: 1" \
  -H "Content-Type: application/json" \
  -d '{
    "student_id": 1
  }'
```

### Subscription Management
```bash
# Get available plans
curl http://localhost:8000/api/v1/subscriptions/plans \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: 1"

# Get modules
curl http://localhost:8000/api/v1/subscriptions/modules \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: 1"

# Check module access
curl http://localhost:8000/api/v1/subscriptions/modules/student_management/access \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: 1"
```

### File Upload
```bash
# Get presigned URLs
curl "http://localhost:8000/api/v1/uploads/presigned-urls?type=profile_picture&entity_type=student&entity_id=1" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: 1"
```

### Reports
```bash
# Academic report
curl http://localhost:8000/api/v1/reports/academic \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: 1"

# Financial report
curl http://localhost:8000/api/v1/reports/financial \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: 1"
```

## 🧪 Automated Testing

### Run All Tests
```bash
php artisan test
```

### Run Specific Test Classes
```bash
php artisan test --filter RouteTest
php artisan test tests/RouteTest.php
```

### Run with Coverage
```bash
php artisan test --coverage
```

## 🔍 Debugging

### Check Routes
```bash
php artisan route:list
```

### Check Middleware
```bash
php artisan route:list --middleware=tenant
php artisan route:list --middleware=module
```

### Check Database
```bash
php artisan migrate:status
php artisan db:seed --class=TestDataSeeder
```

## 📊 Performance Testing

### Test Response Times
```bash
# Install Artillery for load testing
npm install -g artillery

# Run load test
artillery run tests/performance.yml
```

### Monitor Performance
```bash
# Check slow queries
php artisan db:slow-queries

# Check cache performance
php artisan cache:stats
```

## 🐛 Common Issues

### 1. Authentication Issues
- Ensure you have a valid token
- Check tenant ID is correct
- Verify user has proper permissions

### 2. Database Issues
- Run migrations: `php artisan migrate:fresh --seed`
- Check database connection in `.env`
- Verify database exists

### 3. Route Not Found
- Check route is registered: `php artisan route:list`
- Verify middleware is applied correctly
- Check for typos in URL

### 4. Module Access Denied
- Check subscription status
- Verify module is enabled for school
- Check user permissions

## 📈 Expected Results

### Successful Tests Should Return:
- **200 OK**: Successful GET requests
- **201 Created**: Successful POST requests
- **200 OK**: Successful PUT/PATCH requests
- **204 No Content**: Successful DELETE requests

### Error Responses Should Return:
- **401 Unauthorized**: Missing or invalid token
- **403 Forbidden**: Insufficient permissions
- **404 Not Found**: Resource doesn't exist
- **422 Unprocessable Entity**: Validation errors

## 🎯 Testing Checklist

- [ ] Health check endpoint works
- [ ] Authentication endpoints work
- [ ] All CRUD operations work for each resource
- [ ] Middleware is applied correctly
- [ ] Error handling works properly
- [ ] Response times are acceptable (< 3 seconds)
- [ ] Database queries are optimized
- [ ] Caching is working
- [ ] File uploads work
- [ ] Reports generate correctly

## 🚀 Production Testing

Before deploying to production:

1. **Load Testing**: Test with multiple concurrent users
2. **Security Testing**: Test authentication and authorization
3. **Performance Testing**: Ensure response times are acceptable
4. **Database Testing**: Test with large datasets
5. **Integration Testing**: Test with external services

## 📞 Support

If you encounter issues:

1. Check the logs: `tail -f storage/logs/laravel.log`
2. Enable debug mode in `.env`
3. Check database connectivity
4. Verify all dependencies are installed
5. Check file permissions

Happy Testing! 🎉

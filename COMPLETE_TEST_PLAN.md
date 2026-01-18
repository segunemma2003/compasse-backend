# Complete School Admin Feature Test Plan

## Feature Categories to Test

### ✅ ALREADY TESTED (20 tests)
1. Authentication (Login, /auth/me)
2. Dashboard Stats
3. User Management (CRUD, Roles)
4. Teacher Management (Create, List)
5. Student Management (Create, List)
6. Class Management (Create, List)
7. Academic Years (List)
8. Terms (List)
9. Settings (Get)

### 🔴 NOT YET TESTED - HIGH PRIORITY

#### 1. School Management (Tenant-specific)
- GET /schools/{school} - View school details
- PUT /schools/{school} - Update school
- GET /schools/{school}/stats
- GET /schools/{school}/dashboard
- GET /schools/{school}/organogram

#### 2. User Management (Advanced)
- POST /users/{user}/activate
- POST /users/{user}/suspend
- POST /users/me/profile-picture (Upload)
- DELETE /users/me/profile-picture
- POST /users/{id}/profile-picture
- DELETE /users/{id}/profile-picture

#### 3. Student Management (Advanced)
- GET /students/{id} - View student
- PUT /students/{id} - Update student
- DELETE /students/{id} - Delete student
- GET /students/{student}/attendance
- GET /students/{student}/results
- GET /students/{student}/subjects
- POST /students/generate-admission-number
- POST /students/generate-credentials

#### 4. Teacher Management (Advanced)
- GET /teachers/{id} - View teacher
- PUT /teachers/{id} - Update teacher
- DELETE /teachers/{id} - Delete teacher
- GET /teachers/{teacher}/classes
- GET /teachers/{teacher}/subjects
- GET /teachers/{teacher}/students

#### 5. Class Management (Advanced)
- GET /classes/{id} - View class
- PUT /classes/{id} - Update class
- DELETE /classes/{id} - Delete class

#### 6. Academic Management
- Academic Years (CRUD)
- Terms (CRUD)
- Departments (CRUD)
- Subjects (CRUD)
- Arms (CRUD + assignment)

#### 7. Guardian Management
- CRUD operations
- Assign/remove students
- Get students, notifications, messages, payments

#### 8. Assessment & Results Module
- Exams (CRUD)
- Assignments (CRUD + submissions)
- Grading Systems
- Continuous Assessments
- Psychomotor Assessments
- Results generation
- Report Cards
- Scoreboards

#### 9. Grades & Timetable
- Grades (CRUD)
- Timetable (CRUD)

#### 10. Communication
- Announcements (CRUD + publish)
- Stories (CRUD + reactions/comments)

#### 11. Library Management
- Books (CRUD)
- Borrow/Return
- Digital Resources
- Stats

#### 12. Houses & Sports
- Houses (CRUD + points)
- Sports Activities, Teams, Events

#### 13. Staff Management
- Staff (CRUD)

#### 14. Achievements
- Achievements (CRUD)
- Student achievements

#### 15. Subscription Management
- View plans, modules
- Check subscription status
- View school modules and limits

#### 16. File Uploads
- Upload files
- Get presigned URLs
- Delete files

#### 17. Quiz System
- Quizzes (CRUD)
- Questions, Attempts, Results

### 🟡 MEDIUM PRIORITY (Module-specific)

#### CBT Module
- CBT exams
- Questions
- Sessions
- Submissions

#### Livestream Module
- Livestreams
- Join/Leave
- Attendance

#### Finance Module
- Fees
- Payments
- Invoices

#### Communication Module
- Messages
- Notifications
- Bulk SMS/Email

#### Attendance Module
- Mark attendance
- View reports
- Patterns

#### Transport Module
- Routes
- Vehicles
- Tracking

#### Hostel Module
- Rooms
- Assignments
- Maintenance



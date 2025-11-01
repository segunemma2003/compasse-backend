# SamSchool Management System - Complete Application Overview

## 📋 Executive Summary

**SamSchool** is a comprehensive, multi-tenant, multi-database microservices-based school management system built with Laravel 12 and designed to handle millions of users with sub-3-second API response times. The system provides complete school administration, academic management, assessment, communication, financial, and administrative capabilities through a modular subscription-based architecture.

---

## 🏗️ Core Architecture

### Multi-Tenancy System

-   **Database-per-tenant architecture**: Complete data isolation with separate databases for each school
-   **Subdomain-based routing**: Each school gets `{school}.samschool.com`
-   **Header-based identification**: `X-Tenant-ID` for API requests
-   **Parameter-based identification**: `?school_id={id}` for API requests
-   **Automatic tenant provisioning**: Super admin can register schools with automatic subdomain and database creation

### Performance & Scalability

-   **Sub-3-second API response times** with intelligent caching
-   **Redis caching** for frequently accessed data
-   **Database indexing** for optimal query performance
-   **Query optimization** with eager loading and caching
-   **New Relic integration** for application monitoring
-   **Laravel Horizon** for queue management
-   **Designed for millions of users** across multiple schools

### Technology Stack

-   **Backend**: Laravel 12 (PHP 8.2+)
-   **Database**: MySQL 8.0+ (main database) + MySQL (tenant databases)
-   **Cache**: Redis 6.0+
-   **File Storage**: AWS S3 with presigned URLs
-   **Queue**: Laravel Horizon (Redis-based)
-   **Monitoring**: New Relic APM
-   **Authentication**: Laravel Sanctum (API tokens)
-   **Frontend Assets**: Vite 7, Tailwind CSS 4

---

## 👥 User Roles & Permissions

### Role-Based Access Control (RBAC)

The system supports comprehensive RBAC with the following roles:

1. **Super Admin** - Platform administrator with full system access
2. **School Admin** - School-level administrator
3. **Principal** - School principal
4. **Vice Principal** - Assistant principal
5. **HOD (Head of Department)** - Department head
6. **Year Tutor** - Academic year coordinator
7. **Class Teacher** - Class-level teacher
8. **Subject Teacher** - Subject-specific teacher
9. **Teacher** - General teaching staff
10. **Student** - Student users
11. **Parent/Guardian** - Parent/guardian access
12. **Accountant** - Financial staff
13. **Librarian** - Library staff
14. **Driver** - Transport staff
15. **Security** - Security personnel
16. **Cleaner** - Maintenance staff
17. **Caterer** - Food service staff
18. **Nurse** - Health staff
19. **Admin** - General administrative staff
20. **Staff** - General staff members

### Permission System

-   **Granular permissions** for all actions (read, write, delete, update)
-   **Module-based access control** - Access to features based on subscription
-   **Role-based middleware** - Route protection based on roles
-   **Permission middleware** - Action-level authorization

---

## 📦 Modular Architecture

The system uses a **subscription-based modular architecture** where schools can subscribe to specific modules based on their needs. Modules are organized into categories:

### Core Modules (Always Included)

1. **Student Management** - Student enrollment, profiles, academic records, class assignment
2. **Teacher Management** - Teacher profiles, assignments, schedules, performance
3. **Academic Management** - Classes, subjects, academic years, terms, departments

### Assessment Modules (Subscription-based)

4. **Computer-Based Testing (CBT)** - Online examinations with multiple question types
5. **Result Management** - Grade management, result processing, report cards, analytics

### Communication Modules (Subscription-based)

6. **SMS Integration** - Bulk SMS notifications, automated messages
7. **Email Integration** - Bulk email, newsletters, automated emails
8. **Messaging System** - Internal communication between staff and students
9. **Notification System** - Real-time notifications

### Financial Modules (Subscription-based)

10. **Fee Management** - Fee structure, payment tracking, receipts, arrears
11. **Payroll Management** - Salary calculation, benefits, tax deductions, payslips
12. **Payment Processing** - Online payment integration
13. **Expense Tracking** - School expense management
14. **Invoice Generation** - Automated invoice creation

### Administrative Modules (Subscription-based)

15. **Attendance Management** - Student and staff attendance tracking (clock-in/clock-out)
16. **Transport Management** - Route management, driver assignment, pickup tracking, secure pickup
17. **Hostel Management** - Room management, allocation, maintenance, billing
18. **Health Management** - Health records, medical history, appointments, medications
19. **Inventory Management** - Asset tracking, stock management, purchases, maintenance
20. **Event Management** - Event planning, scheduling, notifications, attendance
21. **Livestream Module** - Google Meet integration for online lectures, attendance tracking
22. **Library System** - Physical and online library management, book borrowing, reviews

---

## 🎓 Academic Management

### Student Management

-   **Complete student lifecycle**: Enrollment to graduation
-   **Auto-generated admission numbers**: Format `SCHOOL_ABBR + YEAR + CLASS_ABBR + SEQUENCE` (e.g., `ABC2025SS001`)
-   **Auto-generated credentials**:
    -   **Email**: `firstname.lastname@schoolname.samschool.com`
    -   **Username**: `firstname.lastname`
-   **Automatic user account creation** for students
-   **Student profiles** with comprehensive information:
    -   Personal details (name, DOB, gender, blood group)
    -   Contact information (phone, email, address)
    -   Parent/guardian information
    -   Emergency contacts
    -   Medical information
    -   Transport information
    -   Hostel information
-   **Class and Arm assignment**: Students assigned to classes and arms (e.g., SS1 A, SS1 B)
-   **Bulk registration** via CSV import
-   **Student tracking**: Attendance, results, assignments, subjects

### Teacher Management

-   **Teacher profiles** with qualifications and specializations
-   **Subject assignments** - Teachers assigned to specific subjects
-   **Class assignments** - Class teachers for each arm
-   **Organogram support**: Hierarchical structure (Principal → Vice Principal → HOD → Year Tutor → Class Teacher → Subject Teacher)
-   **Performance tracking**
-   **Bulk registration** via CSV import

### Academic Structure

-   **Academic Years** - Manage multiple academic years
-   **Terms** - Academic terms (1st, 2nd, 3rd term)
-   **Classes** - Hierarchical class structure (e.g., JSS1, JSS2, SS1, SS2, SS3)
-   **Arms** - Class divisions (A, B, C, etc.)
-   **Departments** - Academic departments (Mathematics, Science, Arts, etc.)
-   **Subjects** - Subject management with teacher allocation

---

## 📝 Assessment System

### Computer-Based Testing (CBT)

-   **Multiple question types**:

    1. **Multiple Choice** - Single or multiple correct answers with auto-grading
    2. **True/False** - Binary choice questions with auto-grading
    3. **Essay** - Open-ended questions requiring manual grading
    4. **Fill in the Blank** - Text completion with auto-grading
    5. **Short Answer** - Brief text responses requiring manual grading
    6. **Numerical** - Mathematical calculations with tolerance-based auto-grading
    7. **Matching** - Pair matching questions
    8. **Open-ended** - Free-form responses

-   **CBT Workflow**:

    1. **Teacher creates exam** - Sets up exam with questions
    2. **Student starts exam** - Creates unique session ID
    3. **All questions sent at once** - Student receives all questions
    4. **Real-time submission** - Answers submitted per question
    5. **Auto-grading** - Objective questions graded automatically
    6. **Manual grading** - Subjective questions flagged for teacher review
    7. **Score calculation** - Total score and percentage calculated
    8. **Revision feedback** - Correct answers and explanations provided for review

-   **Session Management**:

    -   Unique session ID for each exam attempt
    -   Time tracking per question
    -   Time limits for questions/exams
    -   Progress tracking

-   **Grading System**:
    -   **Auto-grading** for objective questions (multiple choice, true/false, numerical, fill-in-blank)
    -   **Manual grading** for subjective questions (essay, short answer, open-ended)
    -   **Partial credit** support
    -   **Feedback** and explanations

### Result Management

-   **Mid-term results** - Generate results for mid-term examinations
-   **End-of-term results** - Generate comprehensive end-of-term results
-   **Annual results** - Year-end result generation
-   **Dynamic grading scales** - Configurable by school admin
-   **Result publication** - Publish/unpublish results
-   **Report cards** - Comprehensive report card generation
-   **Analytics** - Performance analytics and trends
-   **Result tracking** - Historical result tracking

### Exam & Assignment Management

-   **Exams** - Traditional written examinations
-   **Assignments** - Regular assignments and homework
-   **Bulk creation** - Create multiple exams/assignments at once

---

## 💰 Financial Management

### Fee Management

-   **Flexible fee structures** - Configure fees by class, term, or student
-   **Payment tracking** - Track all fee payments
-   **Receipts** - Automated receipt generation
-   **Arrears management** - Track outstanding fees
-   **Payment history** - Complete payment history per student

### Payment Processing

-   **Online payment integration** - Accept online payments
-   **Payment gateways** - Integration with payment processors
-   **Transaction management** - Complete transaction records
-   **Refund processing** - Handle refunds

### Payroll Management

-   **Salary calculation** - Automated salary computation
-   **Benefits management** - Staff benefits tracking
-   **Tax deductions** - Tax calculation and deductions
-   **Payslips** - Automated payslip generation

### Expense Tracking

-   **School expenses** - Track all school expenditures
-   **Categories** - Expense categorization
-   **Budget management** - Budget planning and tracking
-   **Reports** - Financial reports and analytics

### Invoice Generation

-   **Automated invoicing** - Generate invoices for fees, services, etc.
-   **Invoice templates** - Customizable invoice templates
-   **Invoice tracking** - Track invoice status and payments

---

## 📧 Communication System

### SMS Integration

-   **Bulk SMS** - Send SMS to multiple recipients
-   **Automated notifications** - Event-based SMS notifications
-   **Custom messages** - Personalized messages
-   **Delivery tracking** - Track SMS delivery status

### Email Integration

-   **Bulk emails** - Send emails to multiple recipients
-   **Newsletters** - Newsletter distribution
-   **Automated emails** - Event-based email notifications
-   **Email templates** - Customizable email templates

### Messaging System

-   **Internal messaging** - Communication between staff, students, and parents
-   **Group messaging** - Group conversations
-   **Message history** - Complete message records
-   **Notifications** - Real-time message notifications

### Notification System

-   **Real-time notifications** - Push notifications
-   **Notification preferences** - User-configurable preferences
-   **Notification history** - Complete notification log
-   **Bulk notifications** - Send notifications to multiple users

---

## 📋 Administrative Modules

### Attendance Management

-   **Student attendance** - Track daily student attendance
-   **Teacher attendance** - Track teacher clock-in/clock-out
-   **Staff attendance** - Track staff clock-in/clock-out
-   **Attendance reports** - Comprehensive attendance reports
-   **Automated notifications** - Alert parents/guardians of absences
-   **Bulk marking** - Mark attendance for multiple students at once

### Transport Management

-   **Route management** - Define and manage transport routes
-   **Vehicle management** - Vehicle registration and tracking
-   **Driver management** - Driver profiles and assignments
-   **Pickup tracking** - Track student pickup/drop-off
-   **Secure pickup** - Secure pickup authorization system

### Hostel Management

-   **Room management** - Manage hostel rooms and facilities
-   **Room allocation** - Assign students to rooms
-   **Maintenance tracking** - Track room maintenance
-   **Billing** - Hostel fee management

### Health Management

-   **Health records** - Student health information
-   **Medical history** - Complete medical history tracking
-   **Appointments** - Health appointment scheduling
-   **Medications** - Medication tracking and reminders
-   **Emergency contacts** - Quick access to emergency information

### Inventory Management

-   **Asset tracking** - Track school assets and equipment
-   **Stock management** - Manage inventory stock levels
-   **Purchases** - Purchase order management
-   **Maintenance** - Asset maintenance tracking
-   **Transactions** - Complete transaction history

### Event Management

-   **Event planning** - Plan and organize school events
-   **Calendar management** - School calendar with events
-   **Scheduling** - Event scheduling and coordination
-   **Notifications** - Event notifications to stakeholders
-   **Attendance tracking** - Track event attendance

### Livestream Module

-   **Google Meet integration** - Generate Google Meet links for lectures
-   **Lecture scheduling** - Schedule livestream lectures
-   **Attendance tracking** - Track student attendance in livestreams
-   **Join/Leave tracking** - Monitor student participation
-   **Recordings** - Record livestream sessions (if available)

### Library System

-   **Physical library** - Manage physical book library
-   **Online library** - Digital library resources
-   **Book management** - Catalog and manage books
-   **Borrowing system** - Book borrowing and returns
-   **Reviews** - Book reviews and ratings
-   **Categories** - Book categorization

---

## 🔐 Security & Access Control

### Authentication

-   **Laravel Sanctum** - API token-based authentication
-   **Bearer tokens** - Secure token authentication
-   **Token refresh** - Token refresh mechanism
-   **Multi-device support** - Multiple device authentication

### Authorization

-   **Role-based access control (RBAC)** - Access based on user roles
-   **Permission-based access** - Granular permission system
-   **Module-based access** - Feature access based on subscription
-   **Middleware protection** - Route and action protection

### Data Security

-   **Data isolation** - Complete tenant data separation
-   **Encryption** - Sensitive data encryption
-   **Audit logging** - Complete activity tracking
-   **Rate limiting** - API rate limiting to prevent abuse

---

## 📊 Reporting & Analytics

### Academic Reports

-   **Student performance** - Individual student performance reports
-   **Class performance** - Class-level performance analytics
-   **Subject performance** - Subject-wise performance tracking
-   **Progress reports** - Student progress over time

### Financial Reports

-   **Revenue reports** - School revenue tracking
-   **Expense reports** - Expense analysis
-   **Payment reports** - Payment collection reports
-   **Budget reports** - Budget vs. actual reports

### Attendance Reports

-   **Daily attendance** - Daily attendance summaries
-   **Monthly attendance** - Monthly attendance reports
-   **Student attendance** - Individual attendance tracking
-   **Attendance analytics** - Attendance trend analysis

### Performance Reports

-   **System performance** - API response times, database performance
-   **User activity** - User activity tracking
-   **Module usage** - Module usage statistics

---

## 🔄 Bulk Operations

### Bulk Registration

-   **Bulk student registration** - Import students via CSV
-   **Bulk teacher registration** - Import teachers via CSV
-   **Bulk class creation** - Create multiple classes at once
-   **Bulk subject creation** - Create multiple subjects at once

### Bulk Management

-   **Bulk exam creation** - Create multiple exams
-   **Bulk assignment creation** - Create multiple assignments
-   **Bulk fee creation** - Set fees for multiple students
-   **Bulk attendance marking** - Mark attendance for multiple students
-   **Bulk result updates** - Update multiple results
-   **Bulk notifications** - Send notifications to multiple users
-   **CSV import/export** - Complete CSV support for all entities

### Bulk Operations Management

-   **Operation status tracking** - Track bulk operation progress
-   **Operation cancellation** - Cancel running bulk operations
-   **Error handling** - Comprehensive error reporting for failed operations

---

## 📁 File Management

### AWS S3 Integration

-   **Presigned URLs** - Generate presigned URLs for secure file uploads
-   **File upload** - Upload files to S3
-   **File deletion** - Delete files from S3
-   **File management** - Complete file management system

### Supported File Types

-   **Images** - Profile pictures, documents
-   **Documents** - PDFs, Word documents
-   **Videos** - Video lessons, recordings
-   **Audio** - Audio files

---

## 🤖 AI Integration

### AI-Powered Features

-   **Lesson notes generation** - AI to create lesson notes
-   **Content generation** - AI-assisted content creation
-   **AIService** - Comprehensive AI service integration

---

## 📱 API Structure

### Base URL

```
https://api.samschool.com/v1
```

### Authentication

```
Authorization: Bearer {token}
```

### Key API Endpoints (200+ endpoints)

#### Authentication

-   `POST /api/v1/auth/register` - User registration
-   `POST /api/v1/auth/login` - User login
-   `POST /api/v1/auth/logout` - User logout
-   `GET /api/v1/auth/me` - Get current user

#### Tenant Management (Super Admin)

-   `GET /api/v1/tenants` - List tenants
-   `POST /api/v1/tenants` - Create tenant
-   `GET /api/v1/tenants/{id}/stats` - Tenant statistics

#### School Management

-   `GET /api/v1/schools/{id}` - Get school details
-   `PUT /api/v1/schools/{id}` - Update school
-   `GET /api/v1/schools/{id}/dashboard` - School dashboard
-   `GET /api/v1/schools/{id}/organogram` - School organogram

#### Student Management

-   `GET /api/v1/students` - List students
-   `POST /api/v1/students` - Create student (auto-generates credentials)
-   `GET /api/v1/students/{id}` - Get student details
-   `PUT /api/v1/students/{id}` - Update student
-   `DELETE /api/v1/students/{id}` - Delete student
-   `POST /api/v1/students/generate-admission-number` - Generate admission number
-   `POST /api/v1/students/generate-credentials` - Generate email/username

#### Teacher Management

-   `GET /api/v1/teachers` - List teachers
-   `POST /api/v1/teachers` - Create teacher
-   `GET /api/v1/teachers/{id}/classes` - Teacher's classes
-   `GET /api/v1/teachers/{id}/subjects` - Teacher's subjects

#### Assessment (CBT, Exams, Results)

-   `GET /api/v1/assessments/exams` - List exams
-   `POST /api/v1/assessments/exams` - Create exam
-   `GET /api/v1/assessments/cbt/{exam}/questions` - Get CBT questions
-   `POST /api/v1/assessments/cbt/submit` - Submit CBT answers
-   `POST /api/v1/assessments/cbt/{exam}/questions/create` - Create CBT questions
-   `POST /api/v1/results/mid-term/generate` - Generate mid-term results
-   `POST /api/v1/results/end-term/generate` - Generate end-of-term results

#### Financial

-   `GET /api/v1/financial/fees` - List fees
-   `POST /api/v1/financial/fees` - Create fee
-   `GET /api/v1/financial/payments` - List payments
-   `POST /api/v1/financial/payments` - Process payment

#### Communication

-   `POST /api/v1/communication/sms/send` - Send SMS
-   `POST /api/v1/communication/email/send` - Send email
-   `GET /api/v1/communication/messages` - List messages
-   `GET /api/v1/communication/notifications` - List notifications

#### Attendance

-   `GET /api/v1/attendance/students` - Student attendance
-   `GET /api/v1/attendance/teachers` - Teacher attendance
-   `POST /api/v1/attendance/mark` - Mark attendance

#### Bulk Operations

-   `POST /api/v1/bulk/students/register` - Bulk register students
-   `POST /api/v1/bulk/import/csv` - Import from CSV
-   `POST /api/v1/bulk/attendance/mark` - Bulk mark attendance

#### Reports

-   `GET /api/v1/reports/academic` - Academic reports
-   `GET /api/v1/reports/financial` - Financial reports
-   `GET /api/v1/reports/attendance` - Attendance reports

### Health Check

-   `GET /api/health` - System health check

---

## 🚀 Deployment & Infrastructure

### GitHub Actions CI/CD

-   **Automated testing** - PHPUnit tests on push
-   **Automated deployment** - Deploy to VPS on merge to main
-   **Health checks** - Automated health check after deployment
-   **Queue management** - Automatic queue worker and Horizon restart
-   **Database migrations** - Automatic migration on deployment

### VPS Setup

-   **Nginx** - Web server (port 8078)
-   **MySQL** - Database server
-   **Redis** - Cache server
-   **PHP 8.2** - Application runtime
-   **Supervisor** - Process management for queues and Horizon
-   **SSL** - HTTPS support

### Environment Configuration

-   **Multi-environment support** - Development, staging, production
-   **Environment variables** - Comprehensive .env configuration
-   **Database configuration** - Multi-database support
-   **Cache configuration** - Redis caching setup
-   **Queue configuration** - Horizon queue management

---

## 📈 Performance Monitoring

### Monitoring Tools

-   **New Relic APM** - Application performance monitoring
-   **Laravel Horizon** - Queue monitoring dashboard
-   **Redis monitoring** - Cache performance tracking
-   **Database monitoring** - Query performance analysis

### Performance Metrics

-   **API response times** - Tracked to ensure < 3 seconds
-   **Database query times** - Optimized for < 1 second
-   **Cache hit rates** - Target > 80%
-   **Memory usage** - Monitored per worker
-   **CPU usage** - Tracked for optimization

---

## 🔧 Development & Testing

### Testing

-   **PHPUnit** - Unit and feature tests
-   **Route testing** - Comprehensive API route testing
-   **Local testing** - SQLite for local development
-   **Test scripts** - Multiple test scripts for different scenarios

### Development Tools

-   **Laravel Pint** - Code formatting
-   **Laravel Pail** - Log viewing
-   **Laravel Tinker** - Interactive shell
-   **Laravel Sail** - Docker development environment

---

## 📚 Documentation

### Available Documentation

1. **API_DOCUMENTATION.md** - Complete API reference (1459 lines)
2. **STUDENT_ADMISSION_SYSTEM.md** - Student admission system documentation
3. **CBT_AND_RESULTS.md** - CBT and result generation documentation
4. **BULK_OPERATIONS.md** - Bulk operations guide
5. **ENVIRONMENT_SETUP_PORT_8078.md** - Environment setup guide
6. **README.md** - Project overview and installation guide

---

## 🎯 Key Features Summary

✅ **Multi-tenant architecture** with database-per-tenant isolation  
✅ **Sub-3-second API response times** for millions of users  
✅ **20+ user roles** with granular permissions  
✅ **20+ modules** in subscription-based architecture  
✅ **8 question types** in CBT system with auto-grading  
✅ **Auto-generated student credentials** (admission number, email, username)  
✅ **Comprehensive attendance tracking** (students, teachers, staff)  
✅ **Livestream integration** with Google Meet  
✅ **Physical and online library** management  
✅ **AI-powered lesson notes** generation  
✅ **Bulk operations** for all entities via CSV  
✅ **Invoice generation** system  
✅ **Comprehensive reporting** and analytics  
✅ **AWS S3 integration** for file storage  
✅ **New Relic monitoring** and Laravel Horizon queue management  
✅ **GitHub Actions CI/CD** with automated deployment  
✅ **Full RBAC system** with roles and permissions  
✅ **200+ API endpoints** with comprehensive documentation

---

## 🏆 System Capabilities

The SamSchool Management System is a **production-ready, enterprise-grade school management platform** that can:

1. **Handle millions of users** across multiple schools
2. **Provide sub-3-second API response times** through intelligent caching
3. **Scale horizontally** with multiple application servers
4. **Isolate data completely** through database-per-tenant architecture
5. **Support flexible subscriptions** with module-based access control
6. **Automate routine tasks** like credential generation, result processing, and notifications
7. **Provide comprehensive reporting** and analytics for all modules
8. **Integrate with third-party services** like AWS S3, Google Meet, SMS/Email providers
9. **Monitor performance** in real-time with New Relic and Horizon
10. **Deploy automatically** with GitHub Actions CI/CD

---

**Built with ❤️ for education**

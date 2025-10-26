# SamSchool Management System

A comprehensive multi-tenant school management system built with Laravel, designed to handle millions of users with sub-3-second API response times.

## 🚀 Features

### Core Features

-   **Multi-Tenancy**: Database-per-tenant architecture for complete data isolation
-   **Scalability**: Designed to handle millions of users across multiple schools
-   **Performance**: Sub-3-second API response times with intelligent caching
-   **Modular Architecture**: Schools can subscribe to only the modules they need

### Academic Management

-   **Student Management**: Complete student lifecycle from enrollment to graduation
-   **Teacher Management**: Staff management with organogram support
-   **Class Management**: Hierarchical class structure with arms
-   **Subject Management**: Subject assignment and teacher allocation
-   **Academic Calendar**: Terms and academic year management

### Assessment System

-   **Computer-Based Testing (CBT)**: Advanced online examination system
-   **Multiple Question Types**: Multiple choice, true/false, essay, fill-in-blank, short answer, numerical
-   **Auto-grading**: Automatic grading for objective questions
-   **Manual Grading**: Teacher grading for subjective questions
-   **Result Management**: Comprehensive result tracking and reporting

### Communication

-   **SMS Integration**: Bulk SMS notifications to parents and students
-   **Email Integration**: Automated email notifications
-   **Messaging System**: Internal communication between staff and students
-   **Notification System**: Real-time notifications

### Financial Management

-   **Fee Management**: Flexible fee structure management
-   **Payment Processing**: Online payment integration
-   **Payroll Management**: Staff salary management
-   **Expense Tracking**: School expense management

### Administrative Modules

-   **Attendance Management**: Student and staff attendance tracking
-   **Transport Management**: School bus and route management
-   **Hostel Management**: Boarding house management
-   **Health Module**: Student health records and medical tracking
-   **Inventory Management**: School asset and resource management
-   **Event Management**: School events and calendar management

## 🏗️ Architecture

### Multi-Tenancy

-   **Database-per-tenant**: Each school gets its own isolated database
-   **Subdomain Support**: `{school}.samschool.com` for each school
-   **Header-based**: `X-Tenant-ID` for API requests
-   **Parameter-based**: `?school_id={id}` for API requests

### Performance Optimization

-   **Redis Caching**: Intelligent caching for frequently accessed data
-   **Database Indexing**: Strategic indexes for optimal query performance
-   **Query Optimization**: Eager loading and query caching
-   **Response Time Monitoring**: Real-time performance tracking

### Security

-   **Data Isolation**: Complete tenant data separation
-   **Role-based Access**: Granular permission system
-   **API Security**: Rate limiting and authentication
-   **Audit Logging**: Complete activity tracking

## 📋 Requirements

-   PHP 8.1+
-   MySQL 8.0+
-   Redis 6.0+
-   Composer
-   Node.js 16+ (for frontend assets)
-   AWS S3 (for file storage)

## 🛠️ Installation

### 1. Clone the Repository

```bash
git clone https://github.com/your-org/samschool-backend.git
cd samschool-backend
```

### 2. Install Dependencies

```bash
composer install
npm install
```

### 3. Environment Configuration

```bash
cp .env.example .env
php artisan key:generate
```

### 4. Database Setup

```bash
# Create main database
mysql -u root -p -e "CREATE DATABASE samschool_main;"

# Run migrations
php artisan migrate

# Seed initial data
php artisan db:seed
```

### 5. Redis Setup

```bash
# Install Redis (Ubuntu/Debian)
sudo apt-get install redis-server

# Start Redis
sudo systemctl start redis-server
sudo systemctl enable redis-server
```

### 6. File Storage Setup

```bash
# Configure S3 credentials in .env
AWS_ACCESS_KEY_ID=your_access_key
AWS_SECRET_ACCESS_KEY=your_secret_key
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket-name
```

### 7. Queue Setup

```bash
# Start queue worker
php artisan queue:work --daemon
```

## 🚀 Deployment

### Production Deployment

#### 1. Server Requirements

-   **CPU**: 4+ cores
-   **RAM**: 8GB+ (16GB recommended)
-   **Storage**: 100GB+ SSD
-   **Database**: MySQL 8.0+ with replication
-   **Cache**: Redis 6.0+ with clustering
-   **Load Balancer**: Nginx or Apache

#### 2. Environment Configuration

```bash
# Production environment
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.samschool.com

# Database configuration
DB_CONNECTION=mysql
DB_HOST=your-db-host
DB_DATABASE=samschool_main
DB_USERNAME=your-username
DB_PASSWORD=your-password

# Cache configuration
CACHE_DRIVER=redis
REDIS_HOST=your-redis-host
REDIS_PASSWORD=your-redis-password

# Performance optimization
ENABLE_PERFORMANCE_MONITORING=true
LOG_SLOW_REQUESTS=true
```

#### 3. SSL Configuration

```bash
# Install SSL certificate
sudo certbot --nginx -d api.samschool.com
```

#### 4. Database Optimization

```bash
# Run performance migrations
php artisan migrate --path=database/migrations/performance

# Create indexes
php artisan db:index
```

#### 5. Cache Warmup

```bash
# Warm up cache
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## 📊 Performance Monitoring

### Key Metrics

-   **API Response Time**: < 3 seconds
-   **Database Query Time**: < 1 second
-   **Cache Hit Rate**: > 80%
-   **Memory Usage**: < 2GB per worker
-   **CPU Usage**: < 70%

### Monitoring Tools

-   **Laravel Telescope**: For debugging and performance analysis
-   **Redis Monitor**: For cache performance
-   **MySQL Performance Schema**: For database optimization
-   **New Relic/DataDog**: For production monitoring

## 🔧 Configuration

### Multi-Tenancy

```php
// config/tenant.php
'database' => [
    'prefix' => 'tenant_',
    'auto_create' => true,
    'auto_migrate' => true,
],
```

### Performance

```php
// config/performance.php
'cache' => [
    'default_ttl' => 3600,
    'student_ttl' => 300,
    'teacher_ttl' => 300,
],
```

### File Storage

```php
// config/filesystems.php
'disks' => [
    's3' => [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
        'bucket' => env('AWS_BUCKET'),
    ],
],
```

## 📚 API Documentation

### Base URL

```
https://api.samschool.com/v1
```

### Authentication

```bash
Authorization: Bearer {your-token}
```

### Key Endpoints

-   **Authentication**: `/auth/login`, `/auth/register`
-   **Students**: `/students`, `/students/{id}`
-   **Teachers**: `/teachers`, `/teachers/{id}`
-   **CBT**: `/assessments/cbt/start`, `/assessments/cbt/submit`
-   **Results**: `/results`, `/results/{id}`
-   **File Upload**: `/uploads/presigned-urls`

### Rate Limiting

-   **General API**: 1000 requests/hour
-   **File Upload**: 100 requests/hour
-   **SMS/Email**: 100 requests/hour

## 🧪 Testing

### Unit Tests

```bash
php artisan test
```

### Performance Tests

```bash
# Load testing with Artillery
npm install -g artillery
artillery run tests/performance.yml
```

### Database Tests

```bash
# Test database performance
php artisan db:test-performance
```

## 🔒 Security

### Data Protection

-   **Encryption**: All sensitive data encrypted at rest
-   **Isolation**: Complete tenant data separation
-   **Access Control**: Role-based permissions
-   **Audit Logging**: Complete activity tracking

### API Security

-   **Rate Limiting**: Prevents abuse
-   **Authentication**: JWT-based authentication
-   **Authorization**: Module-based access control
-   **Input Validation**: Comprehensive request validation

## 📈 Scaling

### Horizontal Scaling

-   **Load Balancers**: Multiple application servers
-   **Database Sharding**: Distribute tenant databases
-   **Cache Clustering**: Redis cluster for high availability
-   **CDN**: CloudFront for static assets

### Vertical Scaling

-   **CPU**: Scale up to 16+ cores
-   **RAM**: Scale up to 64GB+
-   **Storage**: Use high-performance SSDs
-   **Network**: High-bandwidth connections

## 🆘 Troubleshooting

### Common Issues

#### 1. High Response Times

```bash
# Check slow queries
php artisan db:slow-queries

# Optimize cache
php artisan cache:optimize
```

#### 2. Memory Issues

```bash
# Check memory usage
php artisan memory:check

# Optimize memory
php artisan optimize
```

#### 3. Database Issues

```bash
# Check database performance
php artisan db:performance

# Optimize indexes
php artisan db:index-optimize
```

## 📞 Support

-   **Documentation**: [https://docs.samschool.com](https://docs.samschool.com)
-   **API Reference**: [https://api.samschool.com/docs](https://api.samschool.com/docs)
-   **Support Email**: support@samschool.com
-   **Status Page**: [https://status.samschool.com](https://status.samschool.com)

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests
5. Submit a pull request

## 🙏 Acknowledgments

-   Laravel Framework
-   Redis for caching
-   AWS S3 for file storage
-   MySQL for database
-   All contributors and supporters

---

**Built with ❤️ for education**

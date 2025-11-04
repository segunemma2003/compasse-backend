# Enhanced Health Check System

## ✅ **What's New:**

The deployment workflow now includes a **comprehensive health check system** that verifies deployment success by checking multiple endpoints and services.

## 🏥 **Health Check Features:**

### **1. Automatic Health Endpoint Checking**

-   ✅ **Localhost check**: `http://localhost:8078/api/health`
-   ✅ **Public URL check**: `{APP_URL}/api/health` (if configured)
-   ✅ **Retry logic**: 5 attempts with 5-second delays
-   ✅ **HTTP status validation**: Verifies 200 OK response
-   ✅ **Response body display**: Shows actual health check response

### **2. Service Health Checks**

-   ✅ **Database connection**: Verifies MySQL connection
-   ✅ **Redis connection**: Verifies Redis cache connection
-   ✅ **Queue workers**: Tests queue processing
-   ✅ **Horizon**: Checks Laravel Horizon status
-   ✅ **New Relic**: Verifies monitoring integration (if configured)

### **3. Comprehensive Reporting**

-   ✅ **Detailed logs**: Step-by-step health check progress
-   ✅ **Summary report**: Overview of all checks
-   ✅ **URL display**: Shows all health check endpoints
-   ✅ **Error handling**: Clear error messages on failures

## 📋 **Health Check URLs:**

### **Default Endpoints:**

1. **Local Health**: `http://localhost:8078/api/health`
2. **API v1 Health**: `http://localhost:8078/api/v1/health` (optional)

### **Public Endpoints (if APP_URL configured):**

1. **Public Health**: `{APP_URL}/api/health`
2. **Public API v1**: `{APP_URL}/api/v1/health` (optional)

### **Health Check Response:**

```json
{
    "status": "ok",
    "timestamp": "2025-01-26T10:30:00.000000Z",
    "version": "1.0.0"
}
```

## 🔧 **Setup Instructions:**

### **1. Basic Setup (Localhost Only)**

No additional configuration needed! The health check will automatically:

-   ✅ Check `http://localhost:8078/api/health`
-   ✅ Verify database and Redis connections
-   ✅ Test queue workers and services

### **2. Public URL Health Check (Optional)**

To enable public health checks:

1. **Add GitHub Secret:**

    - Go to: Repository → Settings → Secrets and variables → Actions
    - Add secret: `APP_URL`
    - Value: Your public API URL
        - Example: `https://api.yourschool.com`
        - Example: `http://your-server.com:8078`

2. **Health Check Will Automatically:**
    - ✅ Check public URL: `{APP_URL}/api/health`
    - ✅ Verify public accessibility
    - ✅ Include in health check summary

## 📊 **Health Check Output Example:**

```
=========================================
🏥 Health Check - Verifying Deployment
=========================================
⏳ Waiting for services to start...

=========================================
🌐 Checking Local Health Endpoints
=========================================
🔍 Checking API Health (localhost)...
   Attempt 1/5: http://localhost:8078/api/health
   ✅ API Health (localhost) is healthy! (HTTP 200)
   Response: {"status":"ok","timestamp":"2025-01-26T10:30:00.000000Z","version":"1.0.0"}

=========================================
🌍 Checking Public Health Endpoints
=========================================
📡 Public URL: https://api.yourschool.com
🔍 Checking API Health (public)...
   Attempt 1/5: https://api.yourschool.com/api/health
   ✅ API Health (public) is healthy! (HTTP 200)

=========================================
👷 Checking Queue Workers
=========================================
✅ Queue workers running

=========================================
🗄️  Checking Database Connection
=========================================
✅ Database connected successfully

=========================================
🔴 Checking Redis Connection
=========================================
✅ Redis connected successfully

=========================================
✅ All Health Checks Passed!
=========================================

📋 Health Check Summary:
   ✅ Local API Health: OK (http://localhost:8078/api/health)
   ✅ Public API Health: OK (https://api.yourschool.com/api/health)
   ✅ Database: Connected
   ✅ Redis: Connected
   ✅ Services: Running

🎉 Deployment verified successfully!

📡 Health Check URLs:
   Local: http://localhost:8078/api/health
   Public: https://api.yourschool.com/api/health
```

## 🔄 **Retry Logic:**

The health check includes **automatic retry logic**:

-   **5 attempts** per endpoint
-   **5-second delay** between attempts
-   **10-second timeout** per request
-   **Graceful failure** with clear error messages

This ensures the health check accounts for:

-   ⏱️ Services taking time to start
-   🔄 Temporary network issues
-   📡 DNS resolution delays
-   🔌 Port binding delays

## 🚨 **Failure Handling:**

If health checks fail:

-   ❌ **Deployment fails** - GitHub Actions will show error
-   📋 **Detailed logs** - Shows which check failed and why
-   🔍 **HTTP status codes** - Displays actual response codes
-   ⚠️ **Clear error messages** - Explains what went wrong

## 💡 **Best Practices:**

1. **Always configure APP_URL** for production deployments
2. **Monitor health check logs** in GitHub Actions
3. **Set up alerts** if health checks consistently fail
4. **Use health endpoints** for monitoring tools (UptimeRobot, Pingdom, etc.)
5. **Test health endpoints manually** before deployment

## 🔗 **Using Health Endpoints Externally:**

You can also use these health endpoints for:

-   **Uptime monitoring** (UptimeRobot, Pingdom, StatusCake)
-   **Load balancer health checks**
-   **Kubernetes liveness/readiness probes**
-   **CI/CD pipeline verification**
-   **Manual testing** and debugging

## 📝 **Example Health Check Commands:**

```bash
# Check local health
curl http://localhost:8078/api/health

# Check public health
curl https://api.yourschool.com/api/health

# Get detailed response
curl -v https://api.yourschool.com/api/health

# Check with timeout
curl --max-time 10 https://api.yourschool.com/api/health
```

---

The health check system ensures your deployment is verified and working correctly! 🎉

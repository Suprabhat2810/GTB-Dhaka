# System Health Check Documentation

## Overview

The health check endpoint provides comprehensive monitoring of all system components, including database connectivity, file system, storage, PHP environment, dependencies, security, and API endpoints.

## Endpoints

### JSON Output (Default)
```
GET /health.php
```

Returns JSON response with detailed health information.

### HTML Output (Browser View)
```
GET /health.php?format=html
```

Returns a beautiful, color-coded HTML dashboard for browser viewing.

---

## Health Status Levels

### 🟢 Healthy
- All checks passed
- No warnings or errors
- System fully operational
- **HTTP Status:** 200

### 🟡 Degraded
- All critical checks passed
- Some warnings present
- System operational but needs attention
- **HTTP Status:** 200

### 🔴 Unhealthy
- One or more critical checks failed
- System may not be fully functional
- Immediate attention required
- **HTTP Status:** 503

---

## Checks Performed

### 1. Environment Configuration ⚙️
- ✅ `.env` file exists and readable
- ✅ Required environment variables set
- ✅ Configuration values valid

**Checks:**
- DB_HOST, DB_NAME, DB_USER
- JWT_SECRET
- APP_ENV

### 2. PHP Environment 🐘
- ✅ PHP version compatibility (>= 7.4)
- ✅ Required extensions loaded
- ✅ Memory limits adequate
- ✅ Upload size limits configured

**Required Extensions:**
- pdo
- pdo_mysql
- fileinfo
- mbstring
- json

### 3. Database Connectivity 🗄️
- ✅ Database connection successful
- ✅ Database version retrieved
- ✅ Required tables exist
- ✅ Table count matches expected

**Required Tables:**
- students
- users
- documents
- payments
- approvals
- subject_allocations
- notifications

### 4. File System 📁
- ✅ Critical directories exist
- ✅ Upload directories writable
- ✅ Log directories writable
- ✅ Disk space available

**Critical Directories:**
- uploads/documents (writable)
- v1/logs (writable)
- vendor (exists)
- services (exists)

### 5. Storage Configuration 💾
- ✅ Storage driver configured
- ✅ Local storage accessible (development)
- ✅ S3 credentials set (production)
- ✅ Storage paths valid

**Modes:**
- **Development:** Local filesystem
- **Production:** AWS S3

### 6. Dependencies 📦
- ✅ Composer autoload exists
- ✅ Required packages installed
- ✅ Vendor directory present

**Required Packages:**
- vlucas/phpdotenv
- monolog/monolog
- aws/aws-sdk-php

### 7. Security 🔒
- ✅ JWT secret configured (not default)
- ✅ HTTPS enabled (production)
- ✅ Sensitive files protected

**Security Checks:**
- JWT_SECRET not default value
- HTTPS in production mode
- Production security settings

### 8. API Endpoints 🌐
- ✅ All endpoints responding
- ✅ Authentication endpoints working
- ✅ Student endpoints accessible
- ✅ Admin endpoints accessible
- ✅ Utility endpoints functional

**Endpoints Tested:**
- Authentication: login, register
- Student: profile, documents, payments, subjects
- Admin: students, documents, approvals
- Utility: health, download

---

## Response Format

### JSON Response Structure

```json
{
  "status": "healthy",
  "timestamp": "2026-03-14T00:37:00+05:30",
  "environment": "development",
  "checks": {
    "environment": {
      "status": "pass",
      "message": "Environment configured",
      "details": {
        "app_env": "development",
        "env_file_exists": true,
        "required_vars_set": true
      }
    },
    "php": {
      "status": "pass",
      "message": "PHP 8.2.12",
      "details": {
        "version": "8.2.12",
        "extensions": ["pdo", "pdo_mysql", "fileinfo", "mbstring", "json"],
        "memory_limit": "128M",
        "upload_max_filesize": "5M",
        "post_max_size": "8M"
      }
    },
    "database": {
      "status": "pass",
      "message": "Database connected",
      "details": {
        "host": "localhost:3307",
        "database": "gtb_database",
        "version": "10.4.32-MariaDB",
        "tables_found": 7,
        "tables_expected": 7
      }
    },
    "filesystem": {
      "status": "pass",
      "message": "File system accessible",
      "details": {
        "free_space_gb": 50.2,
        "disk_used_percent": 45.3,
        "directories_ok": true
      }
    },
    "storage": {
      "status": "pass",
      "message": "Local storage configured",
      "details": {
        "driver": "local",
        "app_env": "development",
        "s3_configured": false
      }
    },
    "dependencies": {
      "status": "pass",
      "message": "Dependencies installed",
      "details": {
        "autoload_exists": true,
        "package_count": 3
      }
    },
    "security": {
      "status": "pass",
      "message": "Security configured",
      "details": {
        "jwt_configured": true,
        "https_enabled": false,
        "production_mode": false
      }
    },
    "endpoints": {
      "status": "pass",
      "message": "12 of 12 endpoints responding",
      "details": {
        "total": 12,
        "passed": 12,
        "endpoints": {
          "authentication": {
            "login": "pass",
            "register": "pass"
          },
          "student": {
            "profile": "pass",
            "documents": "pass",
            "payments": "pass"
          },
          "admin": {
            "students": "pass",
            "documents": "pass",
            "approvals": "pass"
          },
          "utility": {
            "health": "pass"
          }
        }
      }
    }
  },
  "warnings": [
    "JWT_SECRET is using default value"
  ],
  "errors": []
}
```

---

## Usage Examples

### 1. Check System Health (JSON)
```bash
curl http://localhost/new_api_deploy/health.php
```

### 2. View in Browser (HTML)
```
http://localhost/new_api_deploy/health.php?format=html
```

### 3. Monitoring Integration
```bash
# Check if system is healthy (exit code 0 = healthy)
curl -f http://localhost/new_api_deploy/health.php || echo "System unhealthy"
```

### 4. Parse JSON Response
```bash
# Get overall status
curl -s http://localhost/new_api_deploy/health.php | jq '.status'

# Check database status
curl -s http://localhost/new_api_deploy/health.php | jq '.checks.database.status'

# Get all warnings
curl -s http://localhost/new_api_deploy/health.php | jq '.warnings[]'
```

---

## Monitoring Setup

### Uptime Monitoring Services

Configure your monitoring service to check:
- **URL:** `https://yourdomain.com/new_api_deploy/health.php`
- **Method:** GET
- **Expected Status:** 200
- **Check Interval:** 5 minutes
- **Alert on:** Status 503 or timeout

### Recommended Services
- **UptimeRobot** - Free tier available
- **Pingdom** - Advanced monitoring
- **StatusCake** - Multiple locations
- **New Relic** - Full APM integration

---

## Troubleshooting

### Common Issues

#### 1. Database Connection Failed
**Symptoms:** `database.status = "fail"`

**Solutions:**
- Check database credentials in `.env`
- Verify database server is running
- Check port number (3306 or 3307)
- Verify database name exists

#### 2. Missing PHP Extensions
**Symptoms:** `php.status = "fail"`, errors about missing extensions

**Solutions:**
```bash
# Install missing extensions (Ubuntu/Debian)
sudo apt-get install php-pdo php-mysql php-mbstring php-fileinfo

# Enable extensions (Windows XAMPP)
# Edit php.ini and uncomment:
extension=pdo_mysql
extension=mbstring
extension=fileinfo
```

#### 3. Upload Directory Not Writable
**Symptoms:** `filesystem.status = "fail"`

**Solutions:**
```bash
# Linux/Mac
chmod 755 uploads/documents
chmod 755 v1/logs

# Windows - Right-click folder → Properties → Security → Edit permissions
```

#### 4. Endpoints Not Responding
**Symptoms:** `endpoints.status = "warn"` or `"fail"`

**Solutions:**
- Check web server configuration
- Verify `.htaccess` rules
- Check file permissions
- Review error logs

#### 5. JWT Secret Warning
**Symptoms:** Warning: "JWT_SECRET is using default value"

**Solutions:**
```bash
# Generate secure random secret
php -r "echo bin2hex(random_bytes(32));"

# Update .env file
JWT_SECRET=<generated_secret_here>
```

---

## Security Considerations

### Production Checklist

Before deploying to production:

- [ ] Change `JWT_SECRET` to unique random value
- [ ] Set `APP_ENV=production` in `.env`
- [ ] Configure AWS S3 credentials
- [ ] Enable HTTPS
- [ ] Review CORS settings
- [ ] Check file permissions
- [ ] Test all endpoints
- [ ] Set up monitoring alerts

### Sensitive Information

The health check is designed to be **safe for public access**:

✅ **Exposed (Safe):**
- PHP version
- Database version
- Overall status
- Check results (pass/fail)

❌ **Hidden in Production:**
- Database credentials
- File paths
- Detailed error messages
- Internal configurations

---

## Development vs Production

### Development Mode
- Full details in responses
- File paths shown
- Detailed error messages
- All checks visible

### Production Mode
- Minimal details
- Paths hidden
- Generic error messages
- Security-focused checks

---

## Integration Examples

### PHP
```php
$response = file_get_contents('http://localhost/new_api_deploy/health.php');
$health = json_decode($response, true);

if ($health['status'] !== 'healthy') {
    // Send alert
    mail('admin@example.com', 'System Health Alert', json_encode($health));
}
```

### JavaScript
```javascript
fetch('/new_api_deploy/health.php')
  .then(res => res.json())
  .then(health => {
    if (health.status === 'unhealthy') {
      console.error('System unhealthy:', health.errors);
    }
  });
```

### Python
```python
import requests

response = requests.get('http://localhost/new_api_deploy/health.php')
health = response.json()

if health['status'] != 'healthy':
    print(f"System status: {health['status']}")
    print(f"Warnings: {health['warnings']}")
    print(f"Errors: {health['errors']}")
```

---

## Support

For issues or questions:
1. Check this documentation
2. Review error logs in `v1/logs/`
3. Run health check in browser (`?format=html`)
4. Contact system administrator

---

**Last Updated:** March 14, 2026

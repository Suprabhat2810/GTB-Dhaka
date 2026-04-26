# 🔒 SYSTEM ADMIN PANEL - BACKEND API

## ✅ PHASE 2: SYSTEM ADMIN BACKEND - COMPLETED

---

## 📁 FILES CREATED

### **Core Infrastructure**
1. `config.php` - Configuration and helper functions
2. `middleware.php` - Authentication and authorization middleware

### **API Endpoints**
3. `auth.php` - Authentication (login/logout/verify)
4. `database.php` - Database management (backup/restore/stats)
5. `audit-logs.php` - Audit logs viewer
6. `api-monitor.php` - API health and performance monitoring
7. `system-health.php` - System health monitoring
8. `traffic.php` - Traffic analytics

---

## 🔐 AUTHENTICATION

### **Login**
```http
POST /system-admin/auth
Content-Type: application/json

{
  "username": "admin",
  "password": "your_password",
  "two_factor_code": "123456"  // Optional, if 2FA enabled
}
```

**Response:**
```json
{
  "success": true,
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "admin": {
    "id": 1,
    "username": "admin",
    "email": "admin@example.com",
    "full_name": "System Administrator"
  }
}
```

### **Logout**
```http
POST /system-admin/auth?action=logout
Authorization: Bearer {token}
```

### **Verify Token**
```http
POST /system-admin/auth?action=verify
Authorization: Bearer {token}
```

---

## 🗄️ DATABASE MANAGEMENT

### **Create Backup**
```http
GET /system-admin/database?action=backup
Authorization: Bearer {token}
```

**Response:**
```json
{
  "success": true,
  "message": "Backup created successfully",
  "backup": {
    "filename": "db_backup_2026-04-13_10-05-30.sql.gz",
    "size": 5242880,
    "compressed_size": 1048576,
    "created_at": "2026-04-13 10:05:30",
    "path": "/path/to/backup"
  }
}
```

### **Restore from Backup**
```http
POST /system-admin/database?action=restore
Authorization: Bearer {token}
Content-Type: application/json

{
  "backup_file": "db_backup_2026-04-13_10-05-30.sql.gz"
}
```

### **List Backups**
```http
GET /system-admin/database?action=list_backups
Authorization: Bearer {token}
```

### **Database Statistics**
```http
GET /system-admin/database?action=stats
Authorization: Bearer {token}
```

**Response:**
```json
{
  "database": {
    "name": "gtb_database",
    "size_mb": 245.67,
    "size_formatted": "245.67 MB",
    "table_count": 28,
    "total_rows": 15432
  },
  "tables": [
    {
      "table_name": "students",
      "row_count": 1234,
      "data_size_mb": 12.45,
      "index_size_mb": 3.21,
      "total_size_mb": 15.66
    }
  ]
}
```

### **Optimize Tables**
```http
POST /system-admin/database?action=optimize
Authorization: Bearer {token}
Content-Type: application/json

{
  "tables": ["students", "payments"]  // Optional, omit to optimize all
}
```

---

## 📋 AUDIT LOGS VIEWER

### **Get All Audit Logs**
```http
GET /system-admin/audit-logs?type=all&page=1&limit=50
Authorization: Bearer {token}
```

**Query Parameters:**
- `type` - Log type (all, login, notification, payment, data_change, api, system_event, system_admin)
- `page` - Page number (default: 1)
- `limit` - Items per page (default: 50, max: 100)
- `user_id` - Filter by user ID
- `user_type` - Filter by user type
- `action_type` - Filter by action type
- `status` - Filter by status
- `date_from` - Filter from date (YYYY-MM-DD)
- `date_to` - Filter to date (YYYY-MM-DD)
- `search` - Search in description, email, name

### **Get Login Audit Logs**
```http
GET /system-admin/audit-logs?type=login&page=1&limit=50
Authorization: Bearer {token}
```

### **Get Notification Audit Logs**
```http
GET /system-admin/audit-logs?type=notification
Authorization: Bearer {token}
```

### **Get Payment Audit Logs**
```http
GET /system-admin/audit-logs?type=payment
Authorization: Bearer {token}
```

### **Get API Logs**
```http
GET /system-admin/audit-logs?type=api
Authorization: Bearer {token}
```

---

## 📡 API MONITORING

### **API Health Status**
```http
GET /system-admin/api-monitor?action=health&time_range=24h
Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "healthy",
  "uptime_percentage": 99.87,
  "time_range": "24h",
  "metrics": {
    "total_requests": 15432,
    "successful_requests": 15312,
    "failed_requests": 120,
    "error_rate": 0.78,
    "avg_response_time_ms": 245.67,
    "slow_requests": 23
  }
}
```

### **Performance Metrics**
```http
GET /system-admin/api-monitor?action=performance&time_range=24h
Authorization: Bearer {token}
```

**Response:**
```json
{
  "statistics": {
    "min_response_time_ms": 45.23,
    "max_response_time_ms": 3456.78,
    "avg_response_time_ms": 245.67,
    "stddev_response_time_ms": 123.45
  },
  "distribution": {
    "under_100ms": 5432,
    "under_500ms": 8765,
    "under_1s": 1234,
    "under_2s": 234,
    "over_2s": 23
  },
  "hourly_trend": [...]
}
```

### **Error Statistics**
```http
GET /system-admin/api-monitor?action=errors&time_range=24h
Authorization: Bearer {token}
```

### **Traffic Statistics**
```http
GET /system-admin/api-monitor?action=traffic&time_range=24h
Authorization: Bearer {token}
```

### **Endpoint Statistics**
```http
GET /system-admin/api-monitor?action=endpoints&time_range=24h
Authorization: Bearer {token}
```

**Response:**
```json
{
  "endpoints": [
    {
      "endpoint": "/v1/student.php",
      "total_requests": 5432,
      "successful_requests": 5398,
      "failed_requests": 34,
      "success_rate": 99.37,
      "avg_response_time": 234.56,
      "min_response_time": 45.23,
      "max_response_time": 1234.56,
      "slow_requests": 12,
      "health": "healthy"
    }
  ]
}
```

---

## 💚 SYSTEM HEALTH MONITORING

### **System Overview**
```http
GET /system-admin/system-health?action=overview
Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "healthy",
  "timestamp": "2026-04-13 10:05:30",
  "uptime": "15d 6h 23m",
  "php_version": "8.1.0",
  "server_software": "Apache/2.4.54",
  "memory": {
    "used": "128.45 MB",
    "limit": "256M",
    "percentage": 50.18
  },
  "cpu_load": {
    "1min": 0.45,
    "5min": 0.67,
    "15min": 0.89
  },
  "disk": {
    "total": "500 GB",
    "used": "245.67 GB",
    "free": "254.33 GB",
    "percentage": 49.13
  },
  "database": {
    "status": "connected",
    "host": "localhost",
    "name": "gtb_database"
  }
}
```

### **Database Health**
```http
GET /system-admin/system-health?action=database
Authorization: Bearer {token}
```

### **External Services Status**
```http
GET /system-admin/system-health?action=services
Authorization: Bearer {token}
```

**Response:**
```json
{
  "services": {
    "twilio": {
      "name": "Twilio (WhatsApp)",
      "configured": true,
      "status": "configured"
    },
    "aws_s3": {
      "name": "AWS S3 (Storage)",
      "configured": false,
      "status": "not_configured"
    },
    "smtp": {
      "name": "SMTP (Email)",
      "configured": true,
      "status": "configured"
    },
    "audit_system": {
      "name": "Audit System",
      "configured": true,
      "status": "enabled"
    }
  }
}
```

### **Disk Usage**
```http
GET /system-admin/system-health?action=disk
Authorization: Bearer {token}
```

---

## 🚦 TRAFFIC ANALYTICS

### **Traffic Overview**
```http
GET /system-admin/traffic?action=overview&time_range=24h
Authorization: Bearer {token}
```

**Response:**
```json
{
  "summary": {
    "total_requests": 15432,
    "unique_users": 234,
    "unique_ips": 156,
    "avg_requests_per_hour": 643,
    "peak_hour": "2026-04-13 14:00:00",
    "peak_requests": 1234
  },
  "hourly_data": [...]
}
```

### **User Statistics**
```http
GET /system-admin/traffic?action=users&time_range=24h
Authorization: Bearer {token}
```

### **Geographic Distribution**
```http
GET /system-admin/traffic?action=geographic&time_range=24h
Authorization: Bearer {token}
```

### **Real-time Activity**
```http
GET /system-admin/traffic?action=realtime
Authorization: Bearer {token}
```

**Response:**
```json
{
  "current_metrics": {
    "requests_per_second": 2.34,
    "active_sessions": 45,
    "requests_last_5min": 702
  },
  "recent_requests": [...],
  "active_sessions": [...],
  "requests_per_minute": [...]
}
```

---

## 🔒 SECURITY FEATURES

### **Authentication**
- ✅ Separate JWT secret for system admins
- ✅ Role-based access control (only `system_admin` role)
- ✅ Token expiration (30 minutes default)
- ✅ 2FA support (ready for TOTP integration)

### **Rate Limiting**
- ✅ Max 3 failed login attempts
- ✅ 15-minute lockout after failed attempts
- ✅ IP-based rate limiting

### **Audit Trail**
- ✅ All system admin actions logged
- ✅ Login/logout tracking
- ✅ Database operations logged
- ✅ IP address and user agent tracking

### **Access Control**
- ✅ Regular admins CANNOT access system admin endpoints
- ✅ Students CANNOT access system admin endpoints
- ✅ Middleware verification on every request
- ✅ Active account check on every request

---

## 📊 TIME RANGE OPTIONS

All monitoring endpoints support these time ranges:
- `1h` - Last 1 hour
- `24h` - Last 24 hours (default)
- `7d` - Last 7 days
- `30d` - Last 30 days

---

## 🧪 TESTING

### **Test Authentication**
```bash
# Login
curl -X POST http://localhost/new_api_deploy/system-admin/auth \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"password"}'

# Use returned token for other requests
TOKEN="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."

# Test database stats
curl http://localhost/new_api_deploy/system-admin/database?action=stats \
  -H "Authorization: Bearer $TOKEN"
```

---

## 🚨 ERROR RESPONSES

### **401 Unauthorized**
```json
{
  "error": "Authentication required"
}
```

### **403 Forbidden**
```json
{
  "error": "Access denied. System administrator privileges required."
}
```

### **429 Too Many Requests**
```json
{
  "error": "Too many failed attempts",
  "message": "Account temporarily locked. Try again in 15 minutes."
}
```

### **500 Internal Server Error**
```json
{
  "error": "Operation failed",
  "message": "Detailed error message"
}
```

---

## 📝 NOTES

1. **All endpoints require authentication** - Include `Authorization: Bearer {token}` header
2. **Tokens expire after 30 minutes** - Refresh or re-login as needed
3. **All actions are logged** - Every system admin action is recorded in `system_admin_audit` table
4. **Database backups are compressed** - Backups are automatically gzipped to save space
5. **API logs are optional** - Enable by integrating API logging middleware (Phase 3)

---

## ✅ PHASE 2 COMPLETE!

**What's working:**
- ✅ System admin authentication
- ✅ Database backup/restore
- ✅ Audit logs viewer (8 types)
- ✅ API monitoring (5 metrics)
- ✅ System health monitoring
- ✅ Traffic analytics
- ✅ Complete security layer
- ✅ All endpoints tested and documented

**Next Phase:**
- 🔄 **Phase 3:** Optional Integration (add audit logging to existing endpoints)
- 🔄 **Phase 4:** System Admin Frontend (React UI)

---

## 🎯 READY FOR PHASE 3?

Phase 3 will add audit logging to existing endpoints without breaking anything!

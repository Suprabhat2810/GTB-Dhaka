# 🔒 AUDIT SYSTEM - IMPLEMENTATION GUIDE

## ✅ PHASE 1: FOUNDATION - COMPLETED

### 📁 Files Created

1. **Database Migration**
   - `v1/migrations/audit_system.sql` - Creates all 9 audit tables

2. **Core Services**
   - `services/EncryptionService.php` - Handles data encryption/decryption
   - `services/AuditService.php` - Main audit logging service

3. **Configuration**
   - `.env` - Added audit system configuration

---

## 🗄️ DATABASE SETUP

### Step 1: Run the Migration

```bash
# Connect to MySQL
mysql -u root -p

# Select database
USE gtb_database;

# Run migration
SOURCE c:/xampp/htdocs/School_Project/Final_Enhancements/new_api_deploy/v1/migrations/audit_system.sql;
```

### Step 2: Verify Tables Created

```sql
-- Check if all 9 tables exist
SHOW TABLES LIKE '%audit%';
SHOW TABLES LIKE 'system_%';

-- Expected tables:
-- 1. audit_logs
-- 2. login_audit
-- 3. notification_audit
-- 4. payment_audit
-- 5. data_change_audit
-- 6. system_admins
-- 7. api_logs
-- 8. system_events
-- 9. system_admin_audit
```

---

## 🔐 ENCRYPTION SERVICE

### Features
- AES-256-CBC encryption
- Random IV for each encryption
- Automatic JSON encoding/decoding
- Base64 encoding for storage

### Usage Example

```php
require_once __DIR__ . '/services/EncryptionService.php';

$encryption = new EncryptionService();

// Encrypt data
$data = ['password' => 'secret123', 'amount' => 5000];
$encrypted = $encryption->encrypt($data);

// Decrypt data
$decrypted = $encryption->decrypt($encrypted);

// Verify encryption is working
$isWorking = $encryption->verify(); // Returns true/false
```

---

## 📊 AUDIT SERVICE

### Features
- Fail-safe logging (never breaks main functionality)
- Encrypted sensitive data
- Multiple log types (auth, payment, notification, etc.)
- Automatic IP and user agent tracking

### Usage Examples

#### 1. Log General Action

```php
require_once __DIR__ . '/services/AuditService.php';

$audit = new AuditService($pdo, $logger);

$audit->log(
    'student_registration',  // action type
    'student',               // category
    'New student registered', // description
    [
        'user_id' => 123,
        'user_type' => 'student',
        'user_email' => 'student@example.com',
        'user_name' => 'John Doe',
        'entity_type' => 'student',
        'entity_id' => 123,
        'new_values' => ['name' => 'John Doe', 'program' => 'BCA'],
        'status' => 'success'
    ]
);
```

#### 2. Log Login Attempt

```php
$audit->logLogin(
    'student@example.com',  // email
    'student',              // user type
    'success',              // status (success, failed, locked, expired)
    [
        'user_id' => 123,
        'session_id' => 'abc123xyz'
    ]
);

// Failed login
$audit->logLogin(
    'admin@example.com',
    'admin',
    'failed',
    [
        'failure_reason' => 'invalid_password'
    ]
);
```

#### 3. Log Notification Sent

```php
$audit->logNotification(
    123,                    // recipient ID
    'student',              // recipient type
    'payment_approved',     // notification type
    'whatsapp',             // channel
    'sent',                 // status
    [
        'recipient_phone' => '+919876543210',
        'message_title' => 'Payment Approved',
        'message_body' => 'Your payment has been approved',
        'provider' => 'twilio',
        'provider_message_id' => 'SM1234567890'
    ]
);
```

#### 4. Log Payment Action

```php
$oldData = ['payment_status' => 'pending', 'amount' => 5000];
$newData = ['payment_status' => 'paid', 'amount' => 5000, 'admin_notes' => 'Verified'];

$audit->logPayment(
    789,                    // payment ID
    'approved',             // action
    $oldData,               // old data
    $newData,               // new data
    ['id' => 5, 'type' => 'admin']  // performer
);
```

#### 5. Log Data Change

```php
$oldData = ['name' => 'John', 'email' => 'old@example.com'];
$newData = ['name' => 'John Doe', 'email' => 'new@example.com'];

$audit->logDataChange(
    'students',             // table name
    123,                    // record ID
    'UPDATE',               // operation
    $oldData,               // old data
    $newData,               // new data
    ['id' => 123, 'type' => 'student']  // who changed
);
```

#### 6. Log API Request

```php
$audit->logAPI(
    '/v1/student.php',      // endpoint
    'POST',                 // method
    200,                    // status code
    250,                    // response time (ms)
    [
        'user_id' => 123,
        'user_type' => 'student',
        'request_size' => 1024,
        'response_size' => 2048
    ]
);
```

#### 7. Log System Event

```php
$audit->logSystemEvent(
    'database_backup',      // event type
    'info',                 // severity (info, warning, error, critical)
    'Database backup completed successfully',
    ['backup_size' => '2.5GB', 'duration' => '45s'],  // details
    1                       // system admin ID
);
```

---

## 🔒 SECURITY FEATURES

### 1. Encrypted Fields
The following fields are automatically encrypted:
- `audit_logs.old_values`
- `audit_logs.new_values`
- `data_change_audit.old_data`
- `data_change_audit.new_data`

### 2. Fail-Safe Design
```php
// Audit logging NEVER breaks main functionality
try {
    // Main operation
    $stmt = $pdo->prepare("UPDATE payments SET status = 'paid' WHERE id = ?");
    $stmt->execute([$paymentId]);
    
    // Audit logging (wrapped in try-catch)
    try {
        $audit->logPayment($paymentId, 'approved', $oldData, $newData, $admin);
    } catch (Exception $e) {
        // Silent failure - main operation still succeeds
        error_log('Audit failed: ' . $e->getMessage());
    }
    
    // Continue normally
    return success();
    
} catch (PDOException $e) {
    return error();
}
```

### 3. Performance Optimization
- Indexed columns for fast queries
- Optional async logging (future enhancement)
- Minimal overhead on main operations

---

## 📋 CONFIGURATION

### Environment Variables (.env)

```env
# Audit System
AUDIT_ENABLED=true
AUDIT_ENCRYPTION_KEY=a7f3e9c2b8d4f1a6e5c9b3d7f2a8e4c1b6d9f3a7e2c8b4d1f6a9e3c7b2d8f4a1

# System Admin
SYSTEM_ADMIN_JWT_SECRET=sys_admin_7d9f2e4a6c8b1d3f5e7a9c2b4d6f8e1a3c5b7d9f2e4a6c8b1d3f5e7a9c2b4d6f8
SYSTEM_ADMIN_SESSION_TIMEOUT=1800
SYSTEM_ADMIN_2FA_REQUIRED=true
SYSTEM_ADMIN_MAX_LOGIN_ATTEMPTS=3
SYSTEM_ADMIN_LOCKOUT_DURATION=900
```

### Generate New Encryption Key

```php
require_once 'services/EncryptionService.php';
echo EncryptionService::generateKey();
// Output: 64-character hex string
```

---

## 🧪 TESTING

### Test Encryption Service

```php
require_once 'services/EncryptionService.php';

$encryption = new EncryptionService();

// Test encryption/decryption
$testData = ['test' => 'data', 'number' => 123];
$encrypted = $encryption->encrypt($testData);
echo "Encrypted: " . $encrypted . "\n";

$decrypted = $encryption->decrypt($encrypted);
print_r($decrypted);

// Verify
if ($encryption->verify()) {
    echo "✅ Encryption working correctly\n";
} else {
    echo "❌ Encryption failed\n";
}
```

### Test Audit Service

```php
require_once 'services/AuditService.php';

$audit = new AuditService($pdo, $logger);

// Test general log
$result = $audit->log('test_action', 'system', 'Test audit log', [
    'user_id' => 1,
    'user_type' => 'admin',
    'status' => 'success'
]);

if ($result) {
    echo "✅ Audit log created\n";
} else {
    echo "❌ Audit log failed\n";
}

// Check database
$stmt = $pdo->query("SELECT COUNT(*) FROM audit_logs");
$count = $stmt->fetchColumn();
echo "Total audit logs: " . $count . "\n";
```

---

## 📊 DATABASE QUERIES

### View Recent Audit Logs

```sql
-- Last 10 audit logs
SELECT 
    id,
    user_type,
    action_type,
    description,
    status,
    created_at
FROM audit_logs
ORDER BY created_at DESC
LIMIT 10;
```

### View Login Attempts

```sql
-- Failed login attempts today
SELECT 
    email,
    login_status,
    failure_reason,
    ip_address,
    login_time
FROM login_audit
WHERE login_status = 'failed'
AND DATE(login_time) = CURDATE()
ORDER BY login_time DESC;
```

### View Notification Delivery Status

```sql
-- WhatsApp notifications sent today
SELECT 
    recipient_id,
    notification_type,
    status,
    sent_at,
    provider_message_id
FROM notification_audit
WHERE channel = 'whatsapp'
AND DATE(created_at) = CURDATE()
ORDER BY created_at DESC;
```

### View Payment Actions

```sql
-- Recent payment approvals
SELECT 
    payment_id,
    student_id,
    action,
    old_status,
    new_status,
    admin_notes,
    created_at
FROM payment_audit
WHERE action = 'approved'
ORDER BY created_at DESC
LIMIT 20;
```

### View API Performance

```sql
-- Slow API endpoints (>1 second)
SELECT 
    endpoint,
    method,
    AVG(response_time) as avg_response_time,
    COUNT(*) as request_count,
    SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as error_count
FROM api_logs
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY endpoint, method
HAVING avg_response_time > 1000
ORDER BY avg_response_time DESC;
```

---

## ✅ PHASE 1 CHECKLIST

- [x] Database migration file created
- [x] EncryptionService.php created
- [x] AuditService.php created
- [x] .env configuration updated
- [x] All 9 audit tables defined
- [x] Encryption working (AES-256-CBC)
- [x] Fail-safe design implemented
- [x] Documentation completed

---

## 🚀 NEXT STEPS

### Phase 2: System Admin Backend
- Create system admin authentication
- Create database backup/restore APIs
- Create audit log viewer APIs
- Create API monitoring endpoints
- Create system health endpoints

### Phase 3: Optional Integration
- Add audit logging to existing endpoints
- Integrate with student.php
- Integrate with payment.php
- Integrate with approvals.php
- Integrate with WhatsAppService.php

### Phase 4: System Admin Frontend
- Create system admin login page
- Create dashboard
- Create database management UI
- Create API monitoring UI
- Create audit logs viewer

---

## 📞 SUPPORT

For questions or issues:
1. Check this README
2. Review the code comments in service files
3. Test with provided examples
4. Check database table structures

---

## 🔐 SECURITY NOTES

1. **Encryption Key**: Keep `AUDIT_ENCRYPTION_KEY` secret and secure
2. **System Admin JWT**: Keep `SYSTEM_ADMIN_JWT_SECRET` different from regular JWT
3. **Database Access**: Only system admins should access audit tables
4. **Backup**: Regularly backup audit tables
5. **Retention**: Consider data retention policies (currently unlimited)

---

**Phase 1 Complete! ✅**

Ready to proceed to Phase 2: System Admin Backend

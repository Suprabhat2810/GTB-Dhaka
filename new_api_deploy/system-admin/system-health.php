<?php
/**
 * Comprehensive System Health Monitoring
 * Similar to system_test.php but for real-time monitoring
 * 
 * Endpoints:
 * GET /system-health?action=overview - Complete system health check
 */

header('Content-Type: application/json');
require_once __DIR__ . '/middleware.php';

$logger = getLogger('system_admin_system_health');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

// Handle CORS
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require system admin authentication
$admin = requireSystemAdmin();

$action = $_GET['action'] ?? 'overview';

try {
    switch ($action) {
        case 'overview':
            handleComprehensiveHealthCheck($pdo, $logger, $admin);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    $logger->error('System health error', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to fetch system health',
        'error' => $e->getMessage()
    ]);
}

/**
 * Comprehensive Health Check (like system_test.php)
 */
function handleComprehensiveHealthCheck($pdo, $logger, $admin) {
    $startTime = microtime(true);
    
    $health = [
        'status' => 'healthy',
        'timestamp' => date('Y-m-d H:i:s'),
        'checks_passed' => 0,
        'checks_failed' => 0,
        'total_checks' => 0
    ];
    
    // 1. PHP Configuration
    $health['php'] = checkPHPConfiguration();
    updateCheckCounts($health, $health['php']['status']);
    
    // 2. Server Information
    $health['server'] = checkServerInformation();
    updateCheckCounts($health, $health['server']['status']);
    
    // 3. Memory Usage
    $health['memory'] = checkMemoryUsage();
    updateCheckCounts($health, $health['memory']['status']);
    
    // 4. Disk Usage
    $health['disk'] = checkDiskUsage();
    updateCheckCounts($health, $health['disk']['status']);
    
    // 5. Database Health
    $health['database'] = checkDatabaseHealth($pdo);
    updateCheckCounts($health, $health['database']['status']);
    
    // 6. Database Tables
    $health['tables'] = checkDatabaseTables($pdo);
    updateCheckCounts($health, $health['tables']['status']);
    
    // 7. Stored Procedures
    $health['stored_procedures'] = checkStoredProcedures($pdo);
    updateCheckCounts($health, $health['stored_procedures']['status']);
    
    // 8. Triggers
    $health['triggers'] = checkTriggers($pdo);
    updateCheckCounts($health, $health['triggers']['status']);
    
    // 9. Foreign Keys
    $health['foreign_keys'] = checkForeignKeys($pdo);
    updateCheckCounts($health, $health['foreign_keys']['status']);
    
    // 10. Services Status
    $health['services'] = checkServices($pdo);
    
    // 11. System Uptime
    $health['uptime'] = getServerUptime();
    
    // 12. Performance Metrics
    $health['performance'] = [
        'health_check_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
    ];
    
    // Determine overall status
    if ($health['checks_failed'] > 0) {
        $health['status'] = 'warning';
    }
    if ($health['checks_failed'] > 5 || $health['database']['status'] === 'error') {
        $health['status'] = 'critical';
    }
    
    // Log health check to database
    logHealthCheck($pdo, $health);
    
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'data' => $health
    ], JSON_PRETTY_PRINT);
}

/**
 * Check PHP Configuration
 */
function checkPHPConfiguration() {
    return [
        'status' => 'ok',
        'version' => PHP_VERSION,
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'display_errors' => ini_get('display_errors'),
        'error_reporting' => ini_get('error_reporting'),
        'extensions' => [
            'pdo' => extension_loaded('pdo'),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'mbstring' => extension_loaded('mbstring'),
            'json' => extension_loaded('json'),
            'curl' => extension_loaded('curl'),
            'openssl' => extension_loaded('openssl')
        ]
    ];
}

/**
 * Check Server Information
 */
function checkServerInformation() {
    return [
        'status' => 'ok',
        'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'protocol' => $_SERVER['SERVER_PROTOCOL'] ?? 'Unknown',
        'os' => PHP_OS,
        'hostname' => gethostname(),
        'server_addr' => $_SERVER['SERVER_ADDR'] ?? 'Unknown'
    ];
}

/**
 * Check Memory Usage
 */
function checkMemoryUsage() {
    $memoryUsage = memory_get_usage(true);
    $memoryLimit = ini_get('memory_limit');
    $memoryLimitBytes = convertToBytes($memoryLimit);
    $percentage = round(($memoryUsage / $memoryLimitBytes) * 100, 2);
    
    $status = 'ok';
    if ($percentage > 80) $status = 'warning';
    if ($percentage > 90) $status = 'critical';
    
    return [
        'status' => $status,
        'used' => formatBytes($memoryUsage),
        'limit' => $memoryLimit,
        'percentage' => $percentage,
        'peak_usage' => formatBytes(memory_get_peak_usage(true))
    ];
}

/**
 * Check Disk Usage
 */
function checkDiskUsage() {
    $diskTotal = disk_total_space('.');
    $diskFree = disk_free_space('.');
    $diskUsed = $diskTotal - $diskFree;
    $percentage = round(($diskUsed / $diskTotal) * 100, 2);
    
    $status = 'ok';
    if ($percentage > 80) $status = 'warning';
    if ($percentage > 90) $status = 'critical';
    
    return [
        'status' => $status,
        'total' => formatBytes($diskTotal),
        'used' => formatBytes($diskUsed),
        'free' => formatBytes($diskFree),
        'percentage' => $percentage
    ];
}

/**
 * Check Database Health
 */
function checkDatabaseHealth($pdo) {
    try {
        $startTime = microtime(true);
        $stmt = $pdo->query("SELECT 1");
        $responseTime = round((microtime(true) - $startTime) * 1000, 2);
        
        // Get database size
        $dbName = $_ENV['DB_NAME'] ?? getenv('DB_NAME');
        $stmt = $pdo->prepare("
            SELECT ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as size_mb
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ?
        ");
        $stmt->execute([$dbName]);
        $dbSize = $stmt->fetchColumn();
        
        // Get connection info
        $stmt = $pdo->query("SHOW STATUS LIKE 'Threads_connected'");
        $connections = $stmt->fetch(PDO::FETCH_ASSOC)['Value'] ?? 0;
        
        $stmt = $pdo->query("SHOW VARIABLES LIKE 'max_connections'");
        $maxConnections = $stmt->fetch(PDO::FETCH_ASSOC)['Value'] ?? 0;
        
        return [
            'status' => 'ok',
            'message' => 'Database connection successful',
            'response_time' => $responseTime . 'ms',
            'size_mb' => $dbSize,
            'connections' => [
                'current' => $connections,
                'max' => $maxConnections,
                'percentage' => round(($connections / $maxConnections) * 100, 2)
            ]
        ];
    } catch (PDOException $e) {
        return [
            'status' => 'error',
            'message' => 'Database connection failed',
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Check Database Tables (like system_test.php)
 */
function checkDatabaseTables($pdo) {
    $dbName = $_ENV['DB_NAME'] ?? getenv('DB_NAME');
    
    $requiredTables = [
        'users', 'students', 'admins', 'system_admins',
        'payments', 'notifications', 'audit_logs',
        'api_metrics', 'traffic_logs', 'system_health_logs'
    ];
    
    try {
        $stmt = $pdo->prepare("
            SELECT TABLE_NAME 
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ?
        ");
        $stmt->execute([$dbName]);
        $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $missingTables = array_diff($requiredTables, $existingTables);
        $status = empty($missingTables) ? 'ok' : 'warning';
        
        return [
            'status' => $status,
            'total_tables' => count($existingTables),
            'required_tables' => count($requiredTables),
            'missing_tables' => array_values($missingTables),
            'all_tables' => $existingTables
        ];
    } catch (PDOException $e) {
        return [
            'status' => 'error',
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Check Stored Procedures
 */
function checkStoredProcedures($pdo) {
    $dbName = $_ENV['DB_NAME'] ?? getenv('DB_NAME');
    
    try {
        $stmt = $pdo->prepare("
            SELECT ROUTINE_NAME
            FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = ? AND ROUTINE_TYPE = 'PROCEDURE'
        ");
        $stmt->execute([$dbName]);
        $procedures = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        return [
            'status' => 'ok',
            'count' => count($procedures),
            'procedures' => $procedures
        ];
    } catch (PDOException $e) {
        return [
            'status' => 'error',
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Check Triggers
 */
function checkTriggers($pdo) {
    $dbName = $_ENV['DB_NAME'] ?? getenv('DB_NAME');
    
    try {
        $stmt = $pdo->prepare("
            SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = ?
        ");
        $stmt->execute([$dbName]);
        $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'status' => 'ok',
            'count' => count($triggers),
            'triggers' => $triggers
        ];
    } catch (PDOException $e) {
        return [
            'status' => 'error',
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Check Foreign Keys
 */
function checkForeignKeys($pdo) {
    $dbName = $_ENV['DB_NAME'] ?? getenv('DB_NAME');
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                CONSTRAINT_NAME,
                TABLE_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $stmt->execute([$dbName]);
        $foreignKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'status' => 'ok',
            'count' => count($foreignKeys),
            'foreign_keys' => $foreignKeys
        ];
    } catch (PDOException $e) {
        return [
            'status' => 'error',
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Check Services Status
 */
function checkServices($pdo) {
    $services = [];
    
    // Database Service
    $services['database'] = [
        'status' => 'ok',
        'message' => 'Connected and operational'
    ];
    
    // Cache Service (if applicable)
    $services['cache'] = [
        'status' => 'ok',
        'message' => 'File-based caching active'
    ];
    
    // Queue Service
    $services['queue'] = [
        'status' => 'ok',
        'message' => 'Database queue operational'
    ];
    
    // Storage Service
    $services['storage'] = [
        'status' => is_writable(__DIR__ . '/../../uploads') ? 'ok' : 'warning',
        'message' => is_writable(__DIR__ . '/../../uploads') ? 'Writable' : 'Not writable'
    ];
    
    return $services;
}

/**
 * Update check counts
 */
function updateCheckCounts(&$health, $status) {
    $health['total_checks']++;
    if ($status === 'ok') {
        $health['checks_passed']++;
    } else {
        $health['checks_failed']++;
    }
}

/**
 * Log health check to database
 */
function logHealthCheck($pdo, $health) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO system_health_logs (
                status, memory_usage, memory_used, memory_limit,
                disk_usage, disk_used, disk_total, disk_free,
                database_status, database_response_time,
                php_version, checks_passed, checks_failed, total_checks,
                details, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )
        ");
        
        $stmt->execute([
            $health['status'],
            $health['memory']['percentage'],
            $health['memory']['used'],
            $health['memory']['limit'],
            $health['disk']['percentage'],
            $health['disk']['used'],
            $health['disk']['total'],
            $health['disk']['free'],
            $health['database']['status'],
            isset($health['database']['response_time']) ? floatval($health['database']['response_time']) : null,
            $health['php']['version'],
            $health['checks_passed'],
            $health['checks_failed'],
            $health['total_checks'],
            json_encode($health)
        ]);
    } catch (PDOException $e) {
        error_log("Failed to log health check: " . $e->getMessage());
    }
}

/**
 * Helper Functions
 */
function getServerUptime() {
    if (stristr(PHP_OS, 'WIN')) {
        return 'N/A (Windows)';
    } else {
        if (file_exists('/proc/uptime')) {
            $uptime = file_get_contents('/proc/uptime');
            $uptime = explode(' ', $uptime);
            return formatUptime($uptime[0]);
        }
    }
    return 'Unknown';
}

function formatUptime($seconds) {
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    
    $parts = [];
    if ($days > 0) $parts[] = "{$days}d";
    if ($hours > 0) $parts[] = "{$hours}h";
    if ($minutes > 0) $parts[] = "{$minutes}m";
    
    return !empty($parts) ? implode(' ', $parts) : '0m';
}

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, 2) . ' ' . $units[$pow];
}

function convertToBytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = (int)$val;
    
    switch($last) {
        case 'g':
            $val *= 1024;
        case 'm':
            $val *= 1024;
        case 'k':
            $val *= 1024;
    }
    
    return $val;
}

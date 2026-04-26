<?php
/**
 * Audit Logs Viewer API
 * 
 * View and search all audit logs
 * ONLY accessible by system administrators
 * 
 * Endpoints:
 * GET /audit-logs?type=all - Get all audit logs
 * GET /audit-logs?type=login - Get login audit logs
 * GET /audit-logs?type=notification - Get notification audit logs
 * GET /audit-logs?type=payment - Get payment audit logs
 * GET /audit-logs?type=data_change - Get data change audit logs
 * GET /audit-logs?type=api - Get API logs
 * GET /audit-logs?type=system_event - Get system events
 * GET /audit-logs?type=system_admin - Get system admin actions
 */

header('Content-Type: application/json');
require_once __DIR__ . '/middleware.php';

$logger = getLogger('system_admin_audit_logs');
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

// Only GET allowed
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require system admin authentication
$admin = requireSystemAdmin();

$type = $_GET['type'] ?? 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

// Filters
$filters = [
    'user_id' => $_GET['user_id'] ?? null,
    'user_type' => $_GET['user_type'] ?? null,
    'action_type' => $_GET['action_type'] ?? null,
    'status' => $_GET['status'] ?? null,
    'date_from' => $_GET['date_from'] ?? null,
    'date_to' => $_GET['date_to'] ?? null,
    'search' => $_GET['search'] ?? null
];

try {
    switch ($type) {
        case 'all':
            handleAuditLogs($pdo, $logger, $admin, $filters, $limit, $offset);
            break;
            
        case 'login':
            handleLoginAudit($pdo, $logger, $admin, $filters, $limit, $offset);
            break;
            
        case 'notification':
            handleNotificationAudit($pdo, $logger, $admin, $filters, $limit, $offset);
            break;
            
        case 'payment':
            handlePaymentAudit($pdo, $logger, $admin, $filters, $limit, $offset);
            break;
            
        case 'data_change':
            handleDataChangeAudit($pdo, $logger, $admin, $filters, $limit, $offset);
            break;
            
        case 'api':
            handleAPILogs($pdo, $logger, $admin, $filters, $limit, $offset);
            break;
            
        case 'system_event':
            handleSystemEvents($pdo, $logger, $admin, $filters, $limit, $offset);
            break;
            
        case 'system_admin':
            handleSystemAdminAudit($pdo, $logger, $admin, $filters, $limit, $offset);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid log type']);
    }
    
} catch (Exception $e) {
    $logger->error('Audit logs error', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch logs']);
}

/**
 * Handle Main Audit Logs
 */
function handleAuditLogs($pdo, $logger, $admin, $filters, $limit, $offset) {
    $where = [];
    $params = [];
    
    if ($filters['user_id']) {
        $where[] = "user_id = ?";
        $params[] = $filters['user_id'];
    }
    
    if ($filters['user_type']) {
        $where[] = "user_type = ?";
        $params[] = $filters['user_type'];
    }
    
    if ($filters['action_type']) {
        $where[] = "action_type = ?";
        $params[] = $filters['action_type'];
    }
    
    if ($filters['status']) {
        $where[] = "status = ?";
        $params[] = $filters['status'];
    }
    
    if ($filters['date_from']) {
        $where[] = "created_at >= ?";
        $params[] = $filters['date_from'];
    }
    
    if ($filters['date_to']) {
        $where[] = "created_at <= ?";
        $params[] = $filters['date_to'];
    }
    
    if ($filters['search']) {
        $where[] = "(description LIKE ? OR user_email LIKE ? OR user_name LIKE ?)";
        $searchTerm = '%' . $filters['search'] . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs $whereClause");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    
    // Get logs
    $stmt = $pdo->prepare("
        SELECT 
            id, user_id, user_type, user_email, user_name,
            action_type, action_category, description,
            entity_type, entity_id,
            ip_address, request_method, request_url,
            status, error_message, created_at
        FROM audit_logs
        $whereClause
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Decrypt sensitive data if needed (old_values, new_values not included for performance)
    
    http_response_code(200);
    echo json_encode([
        'logs' => $logs,
        'pagination' => [
            'total' => $total,
            'page' => (int)($_GET['page'] ?? 1),
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

/**
 * Handle Login Audit Logs
 */
function handleLoginAudit($pdo, $logger, $admin, $filters, $limit, $offset) {
    $where = [];
    $params = [];
    
    if ($filters['user_type']) {
        $where[] = "user_type = ?";
        $params[] = $filters['user_type'];
    }
    
    if ($filters['status']) {
        $where[] = "login_status = ?";
        $params[] = $filters['status'];
    }
    
    if ($filters['date_from']) {
        $where[] = "login_time >= ?";
        $params[] = $filters['date_from'];
    }
    
    if ($filters['date_to']) {
        $where[] = "login_time <= ?";
        $params[] = $filters['date_to'];
    }
    
    if ($filters['search']) {
        $where[] = "(email LIKE ? OR ip_address LIKE ?)";
        $searchTerm = '%' . $filters['search'] . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_audit $whereClause");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    
    // Get logs
    $stmt = $pdo->prepare("
        SELECT 
            id, user_id, user_type, email, login_status, failure_reason,
            ip_address, device_type, browser, os,
            login_time, logout_time, session_duration
        FROM login_audit
        $whereClause
        ORDER BY login_time DESC
        LIMIT ? OFFSET ?
    ");
    
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    http_response_code(200);
    echo json_encode([
        'logs' => $logs,
        'pagination' => [
            'total' => $total,
            'page' => (int)($_GET['page'] ?? 1),
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

/**
 * Handle Notification Audit Logs
 */
function handleNotificationAudit($pdo, $logger, $admin, $filters, $limit, $offset) {
    $where = [];
    $params = [];
    
    if ($filters['status']) {
        $where[] = "status = ?";
        $params[] = $filters['status'];
    }
    
    if ($filters['date_from']) {
        $where[] = "created_at >= ?";
        $params[] = $filters['date_from'];
    }
    
    if ($filters['date_to']) {
        $where[] = "created_at <= ?";
        $params[] = $filters['date_to'];
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notification_audit $whereClause");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    
    // Get logs
    $stmt = $pdo->prepare("
        SELECT 
            id, recipient_id, recipient_type, notification_type, channel,
            message_title, status, sent_at, delivered_at, failed_at,
            provider, error_message, retry_count, created_at
        FROM notification_audit
        $whereClause
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    http_response_code(200);
    echo json_encode([
        'logs' => $logs,
        'pagination' => [
            'total' => $total,
            'page' => (int)($_GET['page'] ?? 1),
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

/**
 * Handle Payment Audit Logs
 */
function handlePaymentAudit($pdo, $logger, $admin, $filters, $limit, $offset) {
    $where = [];
    $params = [];
    
    if ($filters['user_id']) {
        $where[] = "student_id = ?";
        $params[] = $filters['user_id'];
    }
    
    if ($filters['date_from']) {
        $where[] = "created_at >= ?";
        $params[] = $filters['date_from'];
    }
    
    if ($filters['date_to']) {
        $where[] = "created_at <= ?";
        $params[] = $filters['date_to'];
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payment_audit $whereClause");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    
    // Get logs
    $stmt = $pdo->prepare("
        SELECT 
            id, payment_id, student_id, action, performed_by_id, performed_by_type,
            old_status, new_status, old_amount, new_amount,
            transaction_id, admin_notes, rejection_reason,
            ip_address, created_at
        FROM payment_audit
        $whereClause
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    http_response_code(200);
    echo json_encode([
        'logs' => $logs,
        'pagination' => [
            'total' => $total,
            'page' => (int)($_GET['page'] ?? 1),
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

/**
 * Handle Data Change Audit Logs
 */
function handleDataChangeAudit($pdo, $logger, $admin, $filters, $limit, $offset) {
    $where = [];
    $params = [];
    
    if ($filters['search']) {
        $where[] = "table_name LIKE ?";
        $params[] = '%' . $filters['search'] . '%';
    }
    
    if ($filters['date_from']) {
        $where[] = "created_at >= ?";
        $params[] = $filters['date_from'];
    }
    
    if ($filters['date_to']) {
        $where[] = "created_at <= ?";
        $params[] = $filters['date_to'];
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM data_change_audit $whereClause");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    
    // Get logs
    $stmt = $pdo->prepare("
        SELECT 
            id, table_name, record_id, operation,
            changed_by_id, changed_by_type, changed_fields,
            ip_address, created_at
        FROM data_change_audit
        $whereClause
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Parse changed_fields JSON
    foreach ($logs as &$log) {
        if ($log['changed_fields']) {
            $log['changed_fields'] = json_decode($log['changed_fields'], true);
        }
    }
    
    http_response_code(200);
    echo json_encode([
        'logs' => $logs,
        'pagination' => [
            'total' => $total,
            'page' => (int)($_GET['page'] ?? 1),
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

/**
 * Handle API Logs
 */
function handleAPILogs($pdo, $logger, $admin, $filters, $limit, $offset) {
    $where = [];
    $params = [];
    
    if ($filters['status']) {
        $where[] = "status_code = ?";
        $params[] = $filters['status'];
    }
    
    if ($filters['search']) {
        $where[] = "endpoint LIKE ?";
        $params[] = '%' . $filters['search'] . '%';
    }
    
    if ($filters['date_from']) {
        $where[] = "created_at >= ?";
        $params[] = $filters['date_from'];
    }
    
    if ($filters['date_to']) {
        $where[] = "created_at <= ?";
        $params[] = $filters['date_to'];
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM api_logs $whereClause");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    
    // Get logs
    $stmt = $pdo->prepare("
        SELECT 
            id, endpoint, method, status_code, response_time,
            request_size, response_size, ip_address,
            user_id, user_type, error_message, created_at
        FROM api_logs
        $whereClause
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    http_response_code(200);
    echo json_encode([
        'logs' => $logs,
        'pagination' => [
            'total' => $total,
            'page' => (int)($_GET['page'] ?? 1),
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

/**
 * Handle System Events
 */
function handleSystemEvents($pdo, $logger, $admin, $filters, $limit, $offset) {
    $where = [];
    $params = [];
    
    if ($filters['status']) {
        $where[] = "severity = ?";
        $params[] = $filters['status'];
    }
    
    if ($filters['search']) {
        $where[] = "(event_type LIKE ? OR message LIKE ?)";
        $searchTerm = '%' . $filters['search'] . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if ($filters['date_from']) {
        $where[] = "created_at >= ?";
        $params[] = $filters['date_from'];
    }
    
    if ($filters['date_to']) {
        $where[] = "created_at <= ?";
        $params[] = $filters['date_to'];
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_events $whereClause");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    
    // Get logs
    $stmt = $pdo->prepare("
        SELECT 
            id, event_type, severity, message, details,
            performed_by, created_at
        FROM system_events
        $whereClause
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Parse details JSON
    foreach ($logs as &$log) {
        if ($log['details']) {
            $log['details'] = json_decode($log['details'], true);
        }
    }
    
    http_response_code(200);
    echo json_encode([
        'logs' => $logs,
        'pagination' => [
            'total' => $total,
            'page' => (int)($_GET['page'] ?? 1),
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

/**
 * Handle System Admin Audit
 */
function handleSystemAdminAudit($pdo, $logger, $admin, $filters, $limit, $offset) {
    $where = [];
    $params = [];
    
    if ($filters['user_id']) {
        $where[] = "admin_id = ?";
        $params[] = $filters['user_id'];
    }
    
    if ($filters['search']) {
        $where[] = "(action LIKE ? OR description LIKE ?)";
        $searchTerm = '%' . $filters['search'] . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if ($filters['date_from']) {
        $where[] = "created_at >= ?";
        $params[] = $filters['date_from'];
    }
    
    if ($filters['date_to']) {
        $where[] = "created_at <= ?";
        $params[] = $filters['date_to'];
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_admin_audit $whereClause");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    
    // Get logs
    $stmt = $pdo->prepare("
        SELECT 
            id, admin_id, admin_username, action, description,
            affected_table, affected_record_id,
            ip_address, created_at
        FROM system_admin_audit
        $whereClause
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    http_response_code(200);
    echo json_encode([
        'logs' => $logs,
        'pagination' => [
            'total' => $total,
            'page' => (int)($_GET['page'] ?? 1),
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

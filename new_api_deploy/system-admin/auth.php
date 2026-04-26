<?php
/**
 * System Admin Authentication
 * 
 * Handles login/logout for system administrators
 * Completely separate from regular admin authentication
 * 
 * Endpoints:
 * POST /auth - Login
 * POST /auth?action=logout - Logout
 * POST /auth?action=verify - Verify token
 */

// Enable error reporting for debugging
ini_set('display_errors', 0); // Don't display to browser
ini_set('log_errors', 1);
error_reporting(E_ALL);

// CRITICAL: Set CORS headers FIRST before any includes
// Define flag to prevent config.php from setting CORS again
define('CORS_ALREADY_SET', true);

$allowedOrigins = ['http://localhost:5173', 'http://localhost:3000', 'http://127.0.0.1:5173'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: http://localhost:5173');
}

header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Request-ID, x-client-time');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Wrap everything in try-catch to catch fatal errors
try {
    require_once __DIR__ . '/middleware.php';
    
    $logger = getLogger('system_admin_auth');
    $pdo = getPDO();
    $method = $_SERVER['REQUEST_METHOD'];
} catch (Throwable $e) {
    // Fatal error during initialization
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Server initialization failed',
        'debug' => [
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]
    ]);
    exit;
}

// Only POST allowed
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$action = $_GET['action'] ?? 'login';

try {
    switch ($action) {
        case 'login':
            handleLogin($pdo, $logger);
            break;
            
        case 'logout':
            handleLogout($pdo, $logger);
            break;
            
        case 'verify':
            handleVerify($pdo, $logger);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    $logger->error('System admin auth error', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(500);
    echo json_encode([
        'error' => 'Authentication error',
        'debug' => [
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]
    ]);
}

/**
 * Handle Login
 */
function handleLogin($pdo, $logger) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request']);
        return;
    }
    
    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Username and password required']);
        return;
    }
    
    // Check rate limit
    if (!checkSystemAdminRateLimit($username)) {
        $lockoutDuration = getSystemAdminLockoutDuration();
        $minutes = ceil($lockoutDuration / 60);
        
        http_response_code(429);
        echo json_encode([
            'error' => 'Too many failed attempts',
            'message' => "Account temporarily locked. Try again in {$minutes} minutes."
        ]);
        
        $logger->warning('System admin rate limit exceeded', ['username' => $username]);
        return;
    }
    
    try {
        // Find system admin by username
        $stmt = $pdo->prepare("
            SELECT id, username, password, email, full_name, is_active, 
                   two_factor_enabled, two_factor_secret
            FROM system_admins 
            WHERE username = ?
        ");
        
        $stmt->execute([$username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin) {
            // Log failed attempt
            logLoginAttempt($pdo, $username, 'failed', 'account_not_found');
            
            jsonResponse("error", "Invalid credentials", [], 401);
            return;
        }
        
        // Check if account is active
        if (!$admin['is_active']) {
            logLoginAttempt($pdo, $username, 'locked', 'account_inactive');
            
            jsonResponse("error", "Account is inactive", [], 403);
            return;
        }
        
        // Verify password
        if (!password_verify($password, $admin['password'])) {
            logLoginAttempt($pdo, $username, 'failed', 'invalid_password', $admin['id']);
            
            jsonResponse("error", "Invalid credentials", [], 401);
            return;
        }
        
        // Check if 2FA is required
        if ($admin['two_factor_enabled'] && !empty($admin['two_factor_secret'])) {
            // For now, return a flag that 2FA is required
            // In production, you'd verify the TOTP code here
            $twoFactorCode = $data['two_factor_code'] ?? null;
            
            if (empty($twoFactorCode)) {
                jsonResponse("success", "Two-factor authentication required", [
                    'requires_2fa' => true
                ], 200);
                return;
            }
            
            // Verify 2FA code (simplified - in production use proper TOTP library)
            // For now, accept any 6-digit code for testing
            if (!preg_match('/^\d{6}$/', $twoFactorCode)) {
                jsonResponse("error", "Invalid 2FA code", [], 401);
                return;
            }
        }
        
        // Generate JWT token
        $token = createSystemAdminJWT($admin['id'], $admin['username'], $admin['email']);
        
        // Update last login
        $stmt = $pdo->prepare("UPDATE system_admins SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$admin['id']]);
        
        // Log successful login (wrapped in try-catch to prevent failures)
        try {
            logLoginAttempt($pdo, $admin['email'], 'success', null, $admin['id'], $token);
        } catch (Exception $auditError) {
            // Log audit error but don't fail the login
            $logger->warning('Audit logging failed', ['error' => $auditError->getMessage()]);
        }
        
        $logger->info('System admin logged in', ['username' => $username]);
        
        // Return success with token
        jsonResponse("success", "Login successful", [
            'token' => $token,
            'admin' => [
                'id' => $admin['id'],
                'username' => $admin['username'],
                'email' => $admin['email'],
                'full_name' => $admin['full_name']
            ]
        ], 200);
        
    } catch (PDOException $e) {
        $logger->error('Login database error', ['error' => $e->getMessage()]);
        jsonResponse("error", "Login failed", [], 500);
    }
}

/**
 * Handle Logout
 */
function handleLogout($pdo, $logger) {
    try {
        $admin = requireSystemAdmin();
        
        // Update logout time in login_audit
        $stmt = $pdo->prepare("
            UPDATE login_audit 
            SET logout_time = NOW(),
                session_duration = TIMESTAMPDIFF(SECOND, login_time, NOW())
            WHERE user_id = ? 
            AND user_type = 'system_admin'
            AND logout_time IS NULL
            ORDER BY login_time DESC
            LIMIT 1
        ");
        
        $stmt->execute([$admin->user_id]);
        
        // Log action
        logSystemAdminAction($admin, 'logout', 'System admin logged out');
        
        $logger->info('System admin logged out', ['username' => $admin->username]);
        
        jsonResponse("success", "Logged out successfully", [], 200);
        
    } catch (Exception $e) {
        $logger->error('Logout error', ['error' => $e->getMessage()]);
        jsonResponse("error", "Logout failed", [], 500);
    }
}

/**
 * Handle Token Verification
 */
function handleVerify($pdo, $logger) {
    try {
        $admin = requireSystemAdmin();
        
        jsonResponse("success", "Token is valid", [
            'valid' => true,
            'admin' => [
                'id' => $admin->user_id,
                'username' => $admin->username,
                'email' => $admin->email,
                'full_name' => $admin->admin_data['full_name'] ?? null
            ]
        ], 200);
        
    } catch (Exception $e) {
        jsonResponse("error", "Invalid token", ['valid' => false], 401);
    }
}

/**
 * Log Login Attempt
 */
function logLoginAttempt($pdo, $email, $status, $failureReason = null, $userId = null, $sessionId = null) {
    try {
        // Load AuditService on-demand
        require_once __DIR__ . '/../services/AuditService.php';
        $audit = new AuditService($pdo);
        $audit->logLogin($email, 'system_admin', $status, [
            'user_id' => $userId,
            'failure_reason' => $failureReason,
            'session_id' => $sessionId
        ]);
    } catch (Exception $e) {
        error_log('Failed to log login attempt: ' . $e->getMessage());
    }
}

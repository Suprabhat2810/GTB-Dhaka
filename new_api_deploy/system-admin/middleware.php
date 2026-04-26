<?php
/**
 * System Admin Middleware
 * 
 * Authentication and authorization middleware for system admin endpoints
 * Ensures ONLY system administrators can access these endpoints
 */

require_once __DIR__ . '/config.php';

/**
 * Require System Admin Authentication
 * 
 * Verifies JWT token and ensures user has system_admin role
 * Returns decoded token data if valid, otherwise terminates with 403
 * 
 * @return object Decoded JWT token
 */
function requireSystemAdmin() {
    global $pdo, $logger;
    
    try {
        // Get bearer token from header
        $token = getBearerToken();
        
        if (!$token) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            exit;
        }
        
        // Verify JWT with SYSTEM ADMIN secret (not regular JWT secret)
        $secret = getSystemAdminJWTSecret();
        $decoded = verifyJWT($token, $secret);
        
        if (!$decoded) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid token']);
            exit;
        }
        
        // Check if role is system_admin
        if (!isset($decoded->role) || $decoded->role !== 'system_admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied. System administrator privileges required.']);
            
            // Log unauthorized access attempt
            if ($logger) {
                $logger->warning('Unauthorized system admin access attempt', [
                    'user_id' => $decoded->user_id ?? null,
                    'role' => $decoded->role ?? 'unknown',
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
            }
            
            exit;
        }
        
        // Verify system admin still exists and is active
        $stmt = $pdo->prepare("SELECT id, username, email, is_active FROM system_admins WHERE id = ?");
        $stmt->execute([$decoded->user_id]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin || !$admin['is_active']) {
            http_response_code(403);
            echo json_encode(['error' => 'Account inactive or not found']);
            exit;
        }
        
        // Add admin data to decoded token
        $decoded->admin_data = $admin;
        
        return $decoded;
        
    } catch (Exception $e) {
        if ($logger) {
            $logger->error('System admin middleware error', ['error' => $e->getMessage()]);
        }
        
        http_response_code(500);
        echo json_encode(['error' => 'Authentication error']);
        exit;
    }
}

/**
 * Get Bearer Token from Authorization Header
 * 
 * @return string|null Token or null if not found
 */
function getBearerToken() {
    $headers = getallheaders();
    
    if (isset($headers['Authorization'])) {
        $matches = [];
        if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
            return $matches[1];
        }
    }
    
    return null;
}

/**
 * Verify JWT Token
 * 
 * @param string $token JWT token
 * @param string $secret Secret key
 * @return object|null Decoded token or null if invalid
 */
function verifyJWT($token, $secret) {
    try {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return null;
        }
        
        list($header, $payload, $signature) = $parts;
        
        // Verify signature
        $validSignature = hash_hmac('sha256', "$header.$payload", $secret, true);
        $validSignature = rtrim(strtr(base64_encode($validSignature), '+/', '-_'), '=');
        
        if ($signature !== $validSignature) {
            return null;
        }
        
        // Decode payload
        $payload = json_decode(base64_decode(strtr($payload, '-_', '+/')));
        
        if (!$payload) {
            return null;
        }
        
        // Check expiration
        if (isset($payload->exp) && $payload->exp < time()) {
            return null;
        }
        
        return $payload;
        
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Create JWT Token for System Admin
 * 
 * @param int $userId System admin user ID
 * @param string $username Username
 * @param string $email Email
 * @return string JWT token
 */
function createSystemAdminJWT($userId, $username, $email) {
    $secret = getSystemAdminJWTSecret();
    $timeout = getSystemAdminSessionTimeout();
    
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload = json_encode([
        'user_id' => $userId,
        'username' => $username,
        'email' => $email,
        'role' => 'system_admin',
        'iat' => time(),
        'exp' => time() + $timeout
    ]);
    
    $base64UrlHeader = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');
    $base64UrlPayload = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    
    $signature = hash_hmac('sha256', "$base64UrlHeader.$base64UrlPayload", $secret, true);
    $base64UrlSignature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    
    return "$base64UrlHeader.$base64UrlPayload.$base64UrlSignature";
}

/**
 * Log System Admin Action
 * 
 * @param object $admin Decoded JWT token with admin data
 * @param string $action Action performed
 * @param string $description Action description
 * @param array $details Additional details
 */
function logSystemAdminAction($admin, $action, $description, $details = []) {
    global $pdo, $logger;
    
    try {
        $affectedTable = $details['affected_table'] ?? null;
        $affectedRecordId = $details['affected_record_id'] ?? null;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt = $pdo->prepare("
            INSERT INTO system_admin_audit (
                admin_id, admin_username, action, description,
                affected_table, affected_record_id, ip_address, user_agent
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $admin->user_id,
            $admin->username,
            $action,
            $description,
            $affectedTable,
            $affectedRecordId,
            $ipAddress,
            $userAgent
        ]);
        
    } catch (Exception $e) {
        if ($logger) {
            $logger->error('Failed to log system admin action', ['error' => $e->getMessage()]);
        }
    }
}

/**
 * Check Rate Limit for System Admin Login
 * 
 * @param string $email Email attempting login
 * @return bool True if allowed, false if rate limited
 */
function checkSystemAdminRateLimit($email) {
    global $pdo;
    
    $maxAttempts = getSystemAdminMaxLoginAttempts();
    $lockoutDuration = getSystemAdminLockoutDuration();
    
    try {
        // Count failed attempts in lockout window
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM login_audit 
            WHERE email = ? 
            AND user_type = 'system_admin'
            AND login_status = 'failed'
            AND login_time > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        
        $stmt->execute([$email, $lockoutDuration]);
        $failedAttempts = $stmt->fetchColumn();
        
        return $failedAttempts < $maxAttempts;
        
    } catch (Exception $e) {
        // On error, allow login (fail open)
        return true;
    }
}

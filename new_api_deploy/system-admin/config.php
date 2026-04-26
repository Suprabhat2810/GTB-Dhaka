<?php
/**
 * System Admin Configuration
 * 
 * Shared configuration and helper functions for system admin endpoints
 */

// Load main config
require_once __DIR__ . '/../config.php';

// Services are loaded on-demand when needed
// Don't load them here to avoid initialization errors before .env is fully loaded

/**
 * Get System Admin JWT Secret (different from regular JWT)
 */
function getSystemAdminJWTSecret() {
    $secret = $_ENV['SYSTEM_ADMIN_JWT_SECRET'] ?? getenv('SYSTEM_ADMIN_JWT_SECRET');
    if (empty($secret)) {
        throw new Exception('SYSTEM_ADMIN_JWT_SECRET not configured');
    }
    return $secret;
}

/**
 * Get System Admin Session Timeout
 */
function getSystemAdminSessionTimeout() {
    return (int)(($_ENV['SYSTEM_ADMIN_SESSION_TIMEOUT'] ?? getenv('SYSTEM_ADMIN_SESSION_TIMEOUT')) ?: 1800); // 30 minutes default
}

/**
 * Check if 2FA is required
 */
function isSystemAdmin2FARequired() {
    $value = $_ENV['SYSTEM_ADMIN_2FA_REQUIRED'] ?? getenv('SYSTEM_ADMIN_2FA_REQUIRED');
    return $value !== 'false';
}

/**
 * Get max login attempts
 */
function getSystemAdminMaxLoginAttempts() {
    return (int)(($_ENV['SYSTEM_ADMIN_MAX_LOGIN_ATTEMPTS'] ?? getenv('SYSTEM_ADMIN_MAX_LOGIN_ATTEMPTS')) ?: 3);
}

/**
 * Get lockout duration
 */
function getSystemAdminLockoutDuration() {
    return (int)(($_ENV['SYSTEM_ADMIN_LOCKOUT_DURATION'] ?? getenv('SYSTEM_ADMIN_LOCKOUT_DURATION')) ?: 900); // 15 minutes default
}

/**
 * Set CORS headers for system admin endpoints
 */
function setSystemAdminCORS() {
    $allowedOrigins = ['http://localhost:5173', 'http://localhost:3000', 'http://127.0.0.1:5173','https://gtbnc.co.in'];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (in_array($origin, $allowedOrigins)) {
        header('Access-Control-Allow-Origin: ' . $origin);
    } else {
        header('Access-Control-Allow-Origin: https://gtbnc.co.in');
    }
    
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
    
    // Handle preflight
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

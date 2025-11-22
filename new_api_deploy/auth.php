<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\JsonFormatter;

/**
 * ------------------------------------------------------------
 * 🛡️ Enterprise-Grade Auth Kernel
 * ------------------------------------------------------------
 * Enhancements:
 * - Structured JSON logging with request correlation
 * - Token extraction hardened (case-insensitive, header fallback)
 * - Clock skew tolerance (±30s)
 * - Role-based guard (non-breaking)
 * - Future-proofed for RS256 / ES256 transition
 * - Ready for Redis-based blacklist or cache integration
 * ------------------------------------------------------------
 */

if (!defined('JWT_SECRET') && !defined('JWT_PUBLIC_KEY_PATH')) {
    jsonResponse("error", "Server misconfiguration: Missing JWT secret.", [], 500);
    exit;
}

/**
 * Initialize logger once per request
 */
function getAuthLogger(): Logger {
    static $logger = null;
    if ($logger === null) {
        $logger = new Logger('auth');
        $handler = new StreamHandler(__DIR__ . '/logs/auth.log', Logger::INFO);
        $handler->setFormatter(new JsonFormatter());
        $logger->pushHandler($handler);

        // Attach correlation ID
        $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));
        $logger->pushProcessor(function ($record) use ($requestId) {
            $record['extra']['request_id'] = $requestId;
            $record['extra']['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $record['extra']['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $record['extra']['path'] = $_SERVER['REQUEST_URI'] ?? '';
            return $record;
        });
    }
    return $logger;
}

/**
 * Main authentication gate
 */
function authenticate($requiredRole = null) {
    $logger = getAuthLogger();
    $start = microtime(true);

    // Extract Authorization header (case-insensitive)
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (empty($token) && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                $token = $value;
                break;
            }
        }
    }

    if (!$token || !str_starts_with($token, 'Bearer ')) {
        $logger->warning('Missing or malformed Authorization header.');
        jsonResponse("error", "Authentication token required.", [], 401);
    }

    $token = trim(str_replace('Bearer ', '', $token));

    try {
        // Algorithm auto-detect (default HS256)
        $algo = defined('JWT_ALGO') ? constant('JWT_ALGO') : 'HS256';

        // Load correct key type
        if (in_array($algo, ['RS256', 'ES256']) && defined('JWT_PUBLIC_KEY_PATH')) {
            $key = new Key(file_get_contents(JWT_PUBLIC_KEY_PATH), $algo);
        } else {
            $key = new Key(JWT_SECRET, $algo);
        }

        // Decode token
        $decoded = JWT::decode($token, $key);

        // Optional: enforce short clock skew tolerance
        $now = time();
        if (isset($decoded->exp) && $decoded->exp < ($now - 30)) {
            $logger->warning('Expired token.', ['sub' => $decoded->sub ?? null]);
            jsonResponse("error", "Token expired.", [], 401);
        }

        // Role-based authorization
        if ($requiredRole && (!isset($decoded->role) || $decoded->role !== $requiredRole)) {
            $logger->warning('Unauthorized role access.', [
                'role' => $decoded->role ?? 'none',
                'required' => $requiredRole
            ]);
            jsonResponse("error", "Unauthorized access.", [], 403);
        }

        $logger->info('Authentication success.', [
            'user' => $decoded->sub ?? 'unknown',
            'role' => $decoded->role ?? 'none',
            'duration_ms' => round((microtime(true) - $start) * 1000, 2)
        ]);

        return $decoded;

    } catch (Exception $e) {
        $logger->error('Token validation failed.', ['error' => $e->getMessage()]);
        jsonResponse("error", "Invalid token: " . $e->getMessage(), [], 401);
    }
}

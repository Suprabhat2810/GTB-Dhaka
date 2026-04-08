<?php
// config.php — hardened, non-breaking replacement
// - Preserves jsonResponse() and authenticate() signatures/behavior
// - Adds env config, structured logging, safer PDO options, CORS whitelist, request-id
// - Supports legacy base64 JSON tokens (current behavior) AND real JWTs (Firebase\JWT) if available

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\JsonFormatter;
use Dotenv\Dotenv;

// Load .env if present
$envPath = dirname(__FILE__); // path of config.php
if (!file_exists($envPath . '/.env')) {
    // try parent directory (in case structure changes)
    $envPath = dirname($envPath);
}

if (file_exists($envPath . '/.env')) {
    $dotenv = Dotenv::createImmutable($envPath);
    $dotenv->load();
} else {
    die('❌ .env file not found');
}


// ---- Configuration (prefer environment variables) ----
$DB_HOST = $_ENV['DB_HOST'] ?? 'localhost';
$DB_PORT = $_ENV['DB_PORT'] ?? '3306';
$DB_NAME = $_ENV['DB_NAME'] ?? '';
$DB_USER = $_ENV['DB_USER'] ?? '';
$DB_PASS = $_ENV['DB_PASS'] ?? '';
$DB_PERSISTENT = ($_ENV['DB_PERSISTENT'] ?? 'true') === 'true';

// JWT config: either a symmetric secret or public key path (prefer env / secret manager)
if (getenv('JWT_ALGO')) {
    define('JWT_ALGO', getenv('JWT_ALGO'));
}
if (getenv('JWT_SECRET')) {
    if (!defined('JWT_SECRET')) define('JWT_SECRET', getenv('JWT_SECRET'));
}
if (getenv('JWT_PUBLIC_KEY_PATH')) {
    if (!defined('JWT_PUBLIC_KEY_PATH')) define('JWT_PUBLIC_KEY_PATH', getenv('JWT_PUBLIC_KEY_PATH'));
}

// CORS configuration (safer default: restrict origins via env)
$CORS_ALLOW_ORIGINS = $_ENV['CORS_ALLOW_ORIGINS'] ?: 'http://localhost:3000';
$CORS_ALLOW_HEADERS = 'Content-Type, Authorization, X-Requested-With, X-Request-ID, x-client-time';
$CORS_ALLOW_METHODS = 'GET, POST, PUT, DELETE, OPTIONS';

// Ensure logs directory exists and is writable
$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0775, true);
}

// ---- Logger (structured JSON) ----
/**
 * getLogger - singleton logger
 */
function getLogger(string $module = 'app'): Logger {
    static $loggers = [];

    if (!isset($loggers[$module])) {
        $logDir = __DIR__ . '/v1/logs/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        // Create a logger for this module
        $logger = new Logger($module);
        $logFile = $logDir . $module . '.log'; // e.g., login.log, billing.log
        $stream = new StreamHandler($logFile, Logger::DEBUG);
        $stream->setFormatter(new JsonFormatter());
        $logger->pushHandler($stream);

        // Add metadata processor
        $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));
        $logger->pushProcessor(function ($record) use ($requestId) {
            $record['extra']['request_id'] = $requestId;
            $record['extra']['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $record['extra']['ua'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $record['extra']['path'] = $_SERVER['REQUEST_URI'] ?? '';
            return $record;
        });

        $loggers[$module] = $logger;
    }

    return $loggers[$module];
}
// ---- PDO (centralized) ----
/**
 * getPDO - returns a configured PDO instance (singleton)
 */
function getPDO(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS, $DB_PERSISTENT;
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $DB_HOST,
            $DB_PORT,
            $DB_NAME
        );
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => $DB_PERSISTENT,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        try {
            $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
        } catch (PDOException $e) {
            // log but keep the same behavior as before: die with message
            getLogger()->critical('DB connection failed', ['error' => $e->getMessage()]);
            // fail-fast - sanitize error message in production
            $isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
            $errorMsg = $isDebug ? $e->getMessage() : 'Database connection failed. Please contact support.';
            die("Connection failed: " . $errorMsg);
        }
    }
    return $pdo;
}

// ---- CORS / Preflight handling ----
$allowedOrigins = array_filter(array_map('trim', explode(',', $CORS_ALLOW_ORIGINS)));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Only allow explicitly whitelisted origins (no wildcard in production)
$isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
if ($origin && in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
} elseif ($isDebug && ($CORS_ALLOW_ORIGINS === '*' || empty($allowedOrigins))) {
    // Only allow wildcard in debug mode
    header("Access-Control-Allow-Origin: *");
}
header("Access-Control-Allow-Headers: {$CORS_ALLOW_HEADERS}");
header("Access-Control-Allow-Methods: {$CORS_ALLOW_METHODS}");
header("Access-Control-Allow-Credentials: true");

// Handle preflight OPTIONS request (short-circuit)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ---- Helper: jsonResponse (keeps signature and behavior) ----
function jsonResponse($status, $message, $data = [], $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data,
        'code' => $code
    ]);
    exit;
}

// ---- Authentication (keeps original behavior but more robust) ----
/**
 * authenticate($requiredRole = null)
 * - Backwards compatible: supports legacy "base64 JSON" token that you used before
 * - If Firebase\JWT is available and JWT_SECRET / JWT_PUBLIC_KEY_PATH is configured, will attempt to validate JWTs
 * - Preserves same error responses and codes
 */
function authenticate($requiredRole = null) {
    $logger = getLogger();

    // Extract Authorization header (case-insensitive) - try multiple methods
    $token = '';
    
    // Method 1: Check $_SERVER['HTTP_AUTHORIZATION']
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $token = $_SERVER['HTTP_AUTHORIZATION'];
    }
    
    // Method 2: Check getallheaders()
    if (empty($token) && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'authorization') {
                $token = $v;
                break;
            }
        }
    }
    
    // Method 3: Check apache_request_headers() as fallback
    if (empty($token) && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'authorization') {
                $token = $v;
                break;
            }
        }
    }

    $token = trim(str_replace('Bearer ', '', (string)$token));

    if (!$token) {
        $logger->warning('Authorization token missing', [
            'http_auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? 'not set',
            'headers_available' => function_exists('getallheaders'),
            'apache_headers_available' => function_exists('apache_request_headers')
        ]);
        jsonResponse("error", "Authorization token missing.", [], 401);
        exit;
    }

    // Try legacy base64 JSON (existing behaviour)
    $decodedData = null;
    $legacyDecoded = @json_decode(@base64_decode($token), true);
    if (is_array($legacyDecoded)) {
        // keep same structure as original: object cast at return
        $decodedData = (object)$legacyDecoded;
        // check expiry if present
        if (isset($decodedData->exp) && $decodedData->exp < time()) {
            $logger->warning('Legacy token expired', ['sub' => $decodedData->sub ?? null]);
            jsonResponse("error", "Token expired.", [], 401);
            exit;
        }
    } else {
        // Try real JWT if library present and secrets are configured
        if (class_exists('\Firebase\JWT\JWT')) {
            try {
                $algo = defined('JWT_ALGO') ? JWT_ALGO : 'HS256';
                if (in_array($algo, ['RS256', 'ES256']) && defined('JWT_PUBLIC_KEY_PATH') && file_exists(JWT_PUBLIC_KEY_PATH)) {
                    $key = new \Firebase\JWT\Key(file_get_contents(JWT_PUBLIC_KEY_PATH), $algo);
                } elseif (defined('JWT_SECRET')) {
                    $key = new \Firebase\JWT\Key(JWT_SECRET, $algo);
                } else {
                    // no key available for JWT verification -> fallback to invalid
                    throw new Exception('JWT key not configured');
                }
                $jwtDecoded = \Firebase\JWT\JWT::decode($token, $key);
                $decodedData = $jwtDecoded; // already an object
                // check expiry (if library didn't enforce)
                if (isset($decodedData->exp) && $decodedData->exp < (time() - 30)) {
                    $logger->warning('JWT expired', ['sub' => $decodedData->sub ?? null]);
                    jsonResponse("error", "Token expired.", [], 401);
                    exit;
                }
            } catch (Exception $e) {
                $logger->error('Invalid JWT', ['error' => $e->getMessage()]);
                jsonResponse("error", "Invalid token.", [], 401);
                exit;
            }
        } else {
            // No library and not legacy -> invalid
            $logger->warning('Token decoding failed: neither legacy nor JWT decode available');
            jsonResponse("error", "Invalid token.", [], 401);
            exit;
        }
    }

    // Role check (backwards compatible)
    $role = $decodedData->role ?? null;
    if ($requiredRole && $role !== $requiredRole) {
        $logger->warning('Unauthorized access - role mismatch', ['role' => $role, 'required' => $requiredRole]);
        jsonResponse("error", "Unauthorized access.", [], 403);
        exit;
    }

    $logger->info('Authentication successful', ['sub' => $decodedData->sub ?? null, 'role' => $role]);

    return $decodedData;
}


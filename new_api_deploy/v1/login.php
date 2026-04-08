<?php
// login.php — hardened, non-breaking replacement
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$logger = getLogger('login');
$start = microtime(true);

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    $logger->warning('Login attempt with non-POST method', ['method' => $method]);
    jsonResponse("error", "Method not allowed.", [], 405);
}

// Parse JSON body
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$email = isset($data['email']) ? filter_var($data['email'], FILTER_SANITIZE_EMAIL) : '';
$password = $data['password'] ?? '';
$role = $data['role'] ?? '';

// Input length validation
if (strlen($email) > 255) {
    jsonResponse("error", "Email is too long.", [], 400);
}
if (strlen($password) > 255) {
    jsonResponse("error", "Password is too long.", [], 400);
}
if (strlen($role) > 20) {
    jsonResponse("error", "Invalid role.", [], 400);
}

if (!$email || !$password || !$role) {
    $logger->info('Login missing parameters', ['email_present' => (bool)$email, 'role' => $role]);
    jsonResponse("error", "Email, password, and role are required.", [], 400);
}

// Whitelist roles and corresponding tables to avoid injection via table name
$roleToTable = [
    'admin' => 'admins',
    'student' => 'students'
];

if (!array_key_exists($role, $roleToTable)) {
    $logger->info('Login with invalid role', ['role' => $role]);
    jsonResponse("error", "Invalid role.", [], 400);
}

$table = $roleToTable[$role];

try {
    $pdo = getPDO();

    // Prepare statement (table name is from a safe whitelist above)
    $stmt = $pdo->prepare("SELECT id, email, password FROM {$table} WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        // Don't reveal whether email exists
        $logger->warning('Invalid login attempt', [
            'email' => $email,
            'role' => $role,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        jsonResponse("error", "Invalid credentials.", [], 401);
    }

    // Build token payload
    $payload = [
        'sub' => $user['id'],
        'email' => $user['email'],
        'role' => $role,
        'iat' => time(),
        'exp' => time() + (60 * 60 * 24) // 24 hours
    ];

    // Prefer producing a signed JWT if library + secret/key available, otherwise fallback to legacy base64 JSON
    $token = null;
    if (class_exists('\Firebase\JWT\JWT')) {
        try {
            $algo = defined('JWT_ALGO') ? JWT_ALGO : 'HS256';

            if (in_array($algo, ['RS256', 'ES256']) && defined('JWT_PRIVATE_KEY_PATH') && file_exists(JWT_PRIVATE_KEY_PATH)) {
                $privateKey = file_get_contents(JWT_PRIVATE_KEY_PATH);
                $token = JWT::encode($payload, $privateKey, $algo);
            } elseif (defined('JWT_SECRET')) {
                $secret = JWT_SECRET;
                $token = JWT::encode($payload, $secret, $algo);
            } else {
                // Fall back when no key configured
                $logger->notice('JWT library present but no key configured; using legacy token',
                    ['algo' => $algo]);
            }
        } catch (Exception $e) {
            // If JWT creation fails for any reason, log and fall back to base64 payload
            $logger->error('Failed to generate JWT; falling back to legacy token', ['error' => $e->getMessage()]);
            $token = null;
        }
    }

    if ($token === null) {
        // Legacy token (keeps backward compatibility)
        $token = base64_encode(json_encode($payload));
    }

    $durationMs = round((microtime(true) - $start) * 1000, 2);
    $logger->info('User logged in', [
        'user_id' => $user['id'],
        'email' => $user['email'],
        'role' => $role,
        'duration_ms' => $durationMs
    ]);

    // Return token (unchanged shape for clients)
    jsonResponse("success", "Login successful.", [
        'token' => $token,
        'role' => $role
    ], 200);

} catch (PDOException $e) {
    // DB error
    $logger->error('Login failed - DB error', ['error' => $e->getMessage()]);
    jsonResponse("error", "Login failed.", [], 500);
} catch (Exception $e) {
    // Generic catch-all
    $logger->error('Login failed - unexpected error', ['error' => $e->getMessage()]);
    jsonResponse("error", "Login failed.", [], 500);
}

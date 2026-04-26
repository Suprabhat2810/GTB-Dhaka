<?php
/**
 * Simple CORS Test
 * Access this at: http://localhost/new_api_deploy/system-admin/test-cors.php
 */

// Set CORS headers FIRST
define('CORS_ALREADY_SET', true);

$allowedOrigins = ['http://localhost:5173', 'http://localhost:3000', 'http://127.0.0.1:5173'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: http://localhost:5173');
}

header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';

// config.php already set CORS headers
// Just return a simple response

jsonResponse("success", "CORS test successful", [
    'origin' => $_SERVER['HTTP_ORIGIN'] ?? 'not set',
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers' => getallheaders()
], 200);

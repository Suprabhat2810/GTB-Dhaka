<?php
// Database connection
$host = 'localhost';
$dbname = 'student_registration_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// JWT Secret Key
define('JWT_SECRET', 'your_jwt_secret_key_here');

// Razorpay API Keys (Test Mode)
define('RAZORPAY_KEY_ID', 'rzp_test_Ugj6jGrq7Gt5SC'); // Replace with your Razorpay Key ID
define('RAZORPAY_KEY_SECRET', 'UPOPjyRMkysGASaueAdMca29'); // Replace with your Razorpay Key Secret

// Set CORS headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");


// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Logging setup
require_once 'vendor/autoload.php';
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$log = new Logger('api');
$log->pushHandler(new StreamHandler('logs/error.log', Logger::ERROR));

// Helper function to send JSON responses
function jsonResponse($status, $message, $data = [], $code = 200) {
    http_response_code($code);
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data,
        'code' => $code
    ]);
    exit;
}

// Authentication function (simplified for brevity)
function authenticate($requiredRole = null) {
    $headers = getallheaders();
    $token = $headers['Authorization'] ?? '';
    $token = str_replace('Bearer ', '', $token);

    if (!$token) {
        jsonResponse("error", "Authorization token missing.", [], 401);
    }

    // Decode the token (single base64-encoded JSON string)
    $decoded = json_decode(base64_decode($token), true);
    if (!$decoded) {
        jsonResponse("error", "Invalid token.", [], 401);
    }

    // Check token expiration
    if (isset($decoded['exp']) && $decoded['exp'] < time()) {
        jsonResponse("error", "Token expired.", [], 401);
    }

    if ($requiredRole && $decoded['role'] !== $requiredRole) {
        jsonResponse("error", "Unauthorized access.", [], 403);
    }

    return (object) $decoded;
}
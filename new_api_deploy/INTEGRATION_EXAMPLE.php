<?php
/**
 * METRICS TRACKER INTEGRATION EXAMPLE
 * 
 * This shows how to integrate MetricsTracker into any API endpoint
 * Copy this pattern to all your API files
 */

// Example API endpoint (login.php, payments.php, etc.)

header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

// STEP 1: Include the MetricsTracker
require_once __DIR__ . '/middleware/MetricsTracker.php';

$pdo = getPDO();
$logger = getLogger('example_api');

// STEP 2: Initialize the tracker at the start
$tracker = new MetricsTracker($pdo);

try {
    // Your existing API logic here
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'POST') {
        // Example: Login endpoint
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate input
        if (empty($data['email']) || empty($data['password'])) {
            // STEP 3: Track failed request
            $tracker->track(
                400,              // HTTP status code
                false,            // Success = false
                null,             // No user ID (not logged in)
                'guest',          // User type
                null,             // No email
                null,             // No name
                'Missing credentials' // Error message
            );
            
            http_response_code(400);
            echo json_encode(['error' => 'Missing credentials']);
            exit;
        }
        
        // Authenticate user
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$data['email']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($data['password'], $user['password'])) {
            // Success!
            
            // STEP 4: Track successful request with user info
            $tracker->track(
                200,                    // HTTP status code
                true,                   // Success = true
                $user['id'],            // User ID
                'student',              // User type
                $user['email'],         // Email
                $user['full_name'],     // Name
                null                    // No error
            );
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'user' => $user
            ]);
        } else {
            // Failed login
            
            // STEP 5: Track failed authentication
            $tracker->track(
                401,                    // HTTP status code
                false,                  // Success = false
                null,                   // No user ID
                'guest',                // User type
                $data['email'],         // Email (for tracking)
                null,                   // No name
                'Invalid credentials'   // Error message
            );
            
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials']);
        }
    }
    
} catch (Exception $e) {
    // STEP 6: Track exceptions/errors
    $tracker->track(
        500,                    // HTTP status code
        false,                  // Success = false
        null,                   // No user ID
        'guest',                // User type
        null,                   // No email
        null,                   // No name
        $e->getMessage()        // Error message
    );
    
    $logger->error('API error', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

/**
 * INTEGRATION CHECKLIST:
 * 
 * 1. ✅ Include MetricsTracker.php
 * 2. ✅ Create new MetricsTracker($pdo) at start
 * 3. ✅ Call $tracker->track() before EVERY response
 * 4. ✅ Pass correct status code, success flag, user info
 * 5. ✅ Include error messages for failed requests
 * 
 * WHAT GETS TRACKED:
 * - Endpoint URL
 * - HTTP method (GET, POST, etc.)
 * - Response time (automatic)
 * - Status code
 * - Success/failure
 * - User information (if logged in)
 * - IP address (automatic)
 * - User agent (automatic)
 * - Device type, browser, OS (automatic)
 * - Timestamp (automatic)
 * 
 * WHERE TO ADD:
 * - /v1/login.php
 * - /v1/register.php
 * - /v1/payments.php
 * - /v1/notifications.php
 * - /v1/students.php
 * - /v1/admins.php
 * - /system-admin/*.php
 * - Any other API endpoint!
 */

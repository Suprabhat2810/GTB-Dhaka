<?php
/**
 * Debug Auth - Test each component separately
 */

// Enable error display
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:5173');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$debug = [];

try {
    $debug['step'] = 'Loading config';
    require_once __DIR__ . '/config.php';
    $debug['config_loaded'] = true;
    
    $debug['step'] = 'Getting PDO';
    $pdo = getPDO();
    $debug['pdo_connected'] = true;
    
    $debug['step'] = 'Getting logger';
    $logger = getLogger('debug_auth');
    $debug['logger_created'] = true;
    
    $debug['step'] = 'Reading input';
    $input = file_get_contents('php://input');
    $debug['raw_input'] = $input;
    
    $data = json_decode($input, true);
    $debug['parsed_data'] = $data;
    
    if (!$data) {
        throw new Exception('Failed to parse JSON input');
    }
    
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';
    
    $debug['step'] = 'Checking credentials';
    $debug['username'] = $username;
    $debug['password_length'] = strlen($password);
    
    // Query database
    $debug['step'] = 'Querying database';
    $stmt = $pdo->prepare("SELECT id, username, password, email, is_active FROM system_admins WHERE username = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin) {
        $debug['admin_found'] = false;
        $debug['error'] = 'Admin account not found';
    } else {
        $debug['admin_found'] = true;
        $debug['admin_id'] = $admin['id'];
        $debug['admin_email'] = $admin['email'];
        $debug['admin_active'] = $admin['is_active'];
        
        // Verify password
        $debug['step'] = 'Verifying password';
        $passwordMatch = password_verify($password, $admin['password']);
        $debug['password_match'] = $passwordMatch;
        
        if ($passwordMatch) {
            $debug['result'] = 'SUCCESS - Password is correct!';
        } else {
            $debug['result'] = 'FAIL - Password is incorrect';
            $debug['stored_hash'] = substr($admin['password'], 0, 20) . '...';
        }
    }
    
    echo json_encode([
        'success' => true,
        'debug' => $debug
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'debug' => $debug
    ], JSON_PRETTY_PRINT);
}

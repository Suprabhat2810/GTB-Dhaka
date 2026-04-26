<?php
/**
 * Test auth.php from command line
 */

// Simulate POST request
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'http://localhost:5173';

// Simulate POST data
$_POST = [];
file_put_contents('php://input', json_encode([
    'username' => 'sysadmin',
    'password' => 'admin123'
]));

// Capture output
ob_start();

try {
    require __DIR__ . '/auth.php';
    $output = ob_get_clean();
    echo "SUCCESS - Output:\n";
    echo $output;
} catch (Throwable $e) {
    ob_end_clean();
    echo "ERROR:\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nTrace:\n" . $e->getTraceAsString();
}

<?php
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Explicitly set CORS headers for preflight
    header("Access-Control-Allow-Origin: http://localhost:5173"); // Specific origin for security
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    http_response_code(204); // No content for preflight
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse('error', 'Method not allowed.', [], 405);
    exit;
}

try {
    $stmt = $pdo->query("SELECT id, name FROM programs");
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse('success', 'Programs retrieved successfully.', ['programs' => $programs], 200);
} catch (PDOException $e) {
    jsonResponse('error', 'Failed to fetch programs: ' . $e->getMessage(), [], 500);
}

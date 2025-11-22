<?php
// programs.php — logged, safe, non-breaking replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('programs');
$pdo = getPDO();
$start = microtime(true);

// Handle CORS preflight explicitly (keeps your original origin restriction)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: http://localhost:5173");
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Request-ID");
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $logger->warning('programs.php - method not allowed', ['method' => $_SERVER['REQUEST_METHOD']]);
    jsonResponse('error', 'Method not allowed.', [], 405);
    exit;
}

try {
    $logger->info('programs.php - fetching programs');

    $stmt = $pdo->query("SELECT id, name FROM programs");
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $durationMs = round((microtime(true) - $start) * 1000, 2);
    $logger->info('programs.php - programs fetched', ['count' => count($programs), 'duration_ms' => $durationMs]);

    jsonResponse('success', 'Programs retrieved successfully.', ['programs' => $programs], 200);
} catch (PDOException $e) {
    $logger->error('programs.php - failed to fetch programs', ['error' => $e->getMessage()]);
    jsonResponse('error', 'Failed to fetch programs: ' . $e->getMessage(), [], 500);
}

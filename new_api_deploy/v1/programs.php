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

    // Fetch programs with registration settings (backward compatible)
    $sql = "
        SELECT 
            p.id,
            p.name,
            p.program_code,
            p.is_active,
            p.duration_years,
            p.total_semesters,
            ps.registration_open,
            ps.registration_start,
            ps.registration_end,
            ps.contact_email,
            ps.contact_whatsapp,
            ps.query_message
        FROM programs p
        LEFT JOIN program_settings ps ON p.id = ps.program_id
        WHERE p.is_active = 1
        ORDER BY p.name ASC
    ";
    
    $stmt = $pdo->query($sql);
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $durationMs = round((microtime(true) - $start) * 1000, 2);
    $logger->info('programs.php - programs fetched', ['count' => count($programs), 'duration_ms' => $durationMs]);

    jsonResponse('success', 'Programs retrieved successfully.', ['programs' => $programs], 200);
} catch (PDOException $e) {
    $logger->error('programs.php - failed to fetch programs', ['error' => $e->getMessage()]);
    jsonResponse('error', 'Failed to fetch programs: ' . $e->getMessage(), [], 500);
}

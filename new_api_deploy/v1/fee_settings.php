<?php
// fee_settings.php — hardened & logged (non-breaking)
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('fee_settings');
$start = microtime(true);

// Keep existing preflight behavior
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $logger->warning('fee_settings: method not allowed', ['method' => $_SERVER['REQUEST_METHOD']]);
    jsonResponse('error', 'Method not allowed.', [], 405);
    exit;
}

$program = isset($_GET['program']) ? trim((string)$_GET['program']) : null;
$latest = strtolower(trim((string)($_GET['latest'] ?? 'false'))) === 'true';

if (!$program) {
    $logger->warning('fee_settings: missing program parameter', ['qs' => $_GET]);
    jsonResponse('error', 'Program is required.', [], 400);
    exit;
}

try {
    $pdo = getPDO();
    $logger->info('fee_settings: fetching fee settings', ['program' => $program, 'latest' => $latest]);

    // Fetch the latest by updated_at (keeps original behavior)
    $stmt = $pdo->prepare("SELECT * FROM fee_settings WHERE program = ? ORDER BY updated_at DESC LIMIT 1");
    $stmt->execute([$program]);
    $feeSettings = $stmt->fetch(PDO::FETCH_ASSOC);

    $durationMs = round((microtime(true) - $start) * 1000, 2);

    if ($feeSettings) {
        // Convert is_live to proper boolean
        $feeSettings['is_live'] = (bool)((int)($feeSettings['is_live'] ?? 0));
        // Convert numeric fields to proper types
        $feeSettings['total_fee'] = $feeSettings['total_fee'] ? (float)$feeSettings['total_fee'] : null;
        $feeSettings['fee_per_credit'] = $feeSettings['fee_per_credit'] ? (float)$feeSettings['fee_per_credit'] : null;
        
        $logger->info('fee_settings: found settings', [
            'program' => $program, 
            'is_live' => $feeSettings['is_live'],
            'duration_ms' => $durationMs
        ]);
        jsonResponse('success', 'Fee settings retrieved successfully.', ['feeSettings' => $feeSettings], 200);
    } else {
        $logger->info('fee_settings: no settings found', ['program' => $program, 'duration_ms' => $durationMs]);
        jsonResponse('success', 'No fee settings found for this program.', ['feeSettings' => ['fee_per_credit' => null, 'total_fee' => null, 'is_live' => false]], 200);
    }
} catch (PDOException $e) {
    $logger->error('fee_settings: DB error', ['error' => $e->getMessage(), 'program' => $program]);
    jsonResponse('error', 'Failed to fetch fee settings: ' . $e->getMessage(), [], 500);
}

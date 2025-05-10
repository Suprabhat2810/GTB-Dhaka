<?php
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse('error', 'Method not allowed.', [], 405);
    exit;
}

$program = $_GET['program'] ?? null;
$latest = $_GET['latest'] ?? 'false';

if (!$program) {
    jsonResponse('error', 'Program is required.', [], 400);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM fee_settings WHERE program = ? ORDER BY updated_at DESC LIMIT 1");
    $stmt->execute([$program]);
    $feeSettings = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($feeSettings) {
        jsonResponse('success', 'Fee settings retrieved successfully.', ['feeSettings' => $feeSettings], 200);
    } else {
        jsonResponse('success', 'No fee settings found for this program.', ['feeSettings' => ['fee_per_credit' => null, 'total_fee' => null, 'is_live' => false]], 200);
    }
} catch (PDOException $e) {
    jsonResponse('error', 'Failed to fetch fee settings: ' . $e->getMessage(), [], 500);
}
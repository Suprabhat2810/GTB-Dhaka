<?php
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse('error', 'Method not allowed.', [], 405);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$program = $input['program'] ?? null;
$isLive = $input['is_live'] ?? null;
$updatedBy = authenticate()->id ?? null;

if (!$program || $isLive === null) {
    jsonResponse('error', 'Program and Live status are required.', [], 400);
    exit;
}

if (!$updatedBy) {
    jsonResponse('error', 'Authentication required to toggle payment status.', [], 401);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("UPDATE fee_settings SET is_live = ?, updated_by = ?, updated_at = NOW() WHERE program = ?");
    $stmt->execute([$isLive ? 1 : 0, $updatedBy, $program]);

    $pdo->commit();
    jsonResponse('success', $isLive ? 'Payments activated successfully.' : 'Payments stopped successfully.', [], 200);
} catch (PDOException $e) {
    $pdo->rollBack();
    jsonResponse('error', 'Failed to toggle payment status: ' . $e->getMessage(), [], 500);
}
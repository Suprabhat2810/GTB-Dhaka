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
$totalFee = $input['totalFee'] ?? null;
$updatedBy = authenticate()->id ?? null;

if (!$program || $totalFee === null) {
    jsonResponse('error', 'Program and Total Fee are required.', [], 400);
    exit;
}

if (!$updatedBy) {
    jsonResponse('error', 'Authentication required to apply payment structure.', [], 401);
    exit;
}

try {
    $pdo->beginTransaction();

    // Set all existing records for this program to is_live = 0 in fee_settings
    $stmt = $pdo->prepare("UPDATE fee_settings SET is_live = 0 WHERE program = ?");
    $stmt->execute([$program]);

    // Insert new record with is_live = 1 in fee_settings
    $stmt = $pdo->prepare("INSERT INTO fee_settings (program, total_fee, updated_by, updated_at, is_live) VALUES (?, ?, ?, NOW(), 1)");
    $stmt->execute([$program, $totalFee, $updatedBy]);

    // Update total_fee in payments for students enrolled in the specified program
    $stmt = $pdo->prepare("UPDATE payments p 
                          JOIN students s ON p.student_id = s.id 
                          SET p.total_fee = ? 
                          WHERE s.program = ?");
    $stmt->execute([$totalFee, $program]);

    $pdo->commit();
    jsonResponse('success', 'Payment structure applied to the latest entry successfully.', [], 200);
} catch (PDOException $e) {
    $pdo->rollBack();
    jsonResponse('error', 'Failed to apply payment structure: ' . $e->getMessage(), [], 500);
}


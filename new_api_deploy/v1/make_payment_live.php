<?php
// make_payments_live.php — hardened, logged, non-breaking replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('make_payments_live');
$start = microtime(true);

// Keep preflight behavior
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $logger->warning('make_payments_live: method not allowed', ['method' => $_SERVER['REQUEST_METHOD']]);
    jsonResponse('error', 'Method not allowed.', [], 405);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$program = isset($input['program']) ? trim((string)$input['program']) : null;
$totalFeeRaw = $input['totalFee'] ?? null;

$user = authenticate('admin'); // keep same auth behaviour as before
$updatedBy = (int)($user->sub ?? $user->id ?? 0);

if (!$program || $totalFeeRaw === null) {
    $logger->warning('make_payments_live: missing required params', ['program' => $program, 'totalFee' => $totalFeeRaw, 'actor' => $updatedBy ?? null]);
    jsonResponse('error', 'Program and Total Fee are required.', [], 400);
    exit;
}

// validate numeric totalFee
if (!is_numeric($totalFeeRaw)) {
    $logger->warning('make_payments_live: invalid totalFee', ['totalFee' => $totalFeeRaw, 'actor' => $updatedBy ?? null]);
    jsonResponse('error', 'Total Fee must be a numeric value.', [], 400);
    exit;
}
$totalFee = (float)$totalFeeRaw;

if (!$updatedBy) {
    $logger->warning('make_payments_live: unauthenticated attempt', ['program' => $program, 'actor' => null]);
    jsonResponse('error', 'Authentication required to apply payment structure.', [], 401);
    exit;
}

$pdo = getPDO();
$inTransaction = false;

try {
    $logger->info('make_payments_live: starting operation', ['program' => $program, 'totalFee' => $totalFee, 'actor' => $updatedBy]);

    $pdo->beginTransaction();
    $inTransaction = true;

    // Set all existing records for this program to is_live = 0 in fee_settings
    $stmt = $pdo->prepare("UPDATE fee_settings SET is_live = 0 WHERE program = ?");
    $stmt->execute([$program]);

    // Insert new record with is_live = 1 in fee_settings
    $stmt = $pdo->prepare("INSERT INTO fee_settings (program, total_fee, updated_by, updated_at, is_live) VALUES (?, ?, ?, NOW(), 1)");
    $stmt->execute([$program, $totalFee, $updatedBy]);

    // Update total_fee in payments for students enrolled in the specified program
    $stmt = $pdo->prepare("
        UPDATE payments p
        JOIN students s ON p.student_id = s.id
        SET p.total_fee = ?
        WHERE s.program = ?
    ");
    $stmt->execute([$totalFee, $program]);

    $pdo->commit();
    $inTransaction = false;

    $durationMs = round((microtime(true) - $start) * 1000, 2);
    $logger->info('make_payments_live: operation completed', [
        'program' => $program,
        'totalFee' => $totalFee,
        'actor' => $updatedBy,
        'duration_ms' => $durationMs
    ]);

    jsonResponse('success', 'Payment structure applied to the latest entry successfully.', [], 200);
} catch (PDOException $e) {
    if ($inTransaction && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $logger->error('make_payments_live: DB error, rolled back', [
        'error' => $e->getMessage(),
        'program' => $program,
        'actor' => $updatedBy ?? null
    ]);
    jsonResponse('error', 'Failed to apply payment structure: ' . $e->getMessage(), [], 500);
} catch (Exception $e) {
    if ($inTransaction && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $logger->critical('make_payments_live: unexpected error', ['error' => $e->getMessage()]);
    jsonResponse('error', 'Failed to apply payment structure: ' . $e->getMessage(), [], 500);
}

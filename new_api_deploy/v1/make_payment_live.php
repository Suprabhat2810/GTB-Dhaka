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
$applyToExisting = isset($input['applyToExisting']) ? (bool)$input['applyToExisting'] : false;

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
    $logger->info('make_payments_live: starting operation', [
        'program' => $program, 
        'totalFee' => $totalFee, 
        'applyToExisting' => $applyToExisting,
        'actor' => $updatedBy
    ]);

    $pdo->beginTransaction();
    $inTransaction = true;

    // Use the ApplyNewFeeStructure stored procedure for fee versioning
    $stmt = $pdo->prepare("CALL ApplyNewFeeStructure(?, ?, ?, ?, ?, ?)");
    $effectiveFrom = date('Y-m-d');
    $reason = $applyToExisting 
        ? 'Fee updated via admin panel - Applied to all students'
        : 'Fee updated via admin panel - New students only';
    
    $stmt->execute([
        $program,
        $totalFee,
        $effectiveFrom,
        $updatedBy,
        $reason,
        $applyToExisting ? 1 : 0
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    
    // Mark the new version as live
    $stmt = $pdo->prepare("
        UPDATE fee_settings 
        SET is_live = 1 
        WHERE id = ? AND is_active = 1
    ");
    $stmt->execute([$result['new_fee_setting_id']]);

    $pdo->commit();
    $inTransaction = false;

    $durationMs = round((microtime(true) - $start) * 1000, 2);
    $logger->info('make_payments_live: operation completed', [
        'program' => $program,
        'totalFee' => $totalFee,
        'applyToExisting' => $applyToExisting,
        'affectedStudents' => $result['affected_students'],
        'actor' => $updatedBy,
        'duration_ms' => $durationMs
    ]);

    $message = $applyToExisting 
        ? "Fee updated to ₹{$totalFee} for all {$result['affected_students']} students in {$program}"
        : "Fee updated to ₹{$totalFee}. Will apply to new students in {$program}";

    jsonResponse('success', $message, [], 200);
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

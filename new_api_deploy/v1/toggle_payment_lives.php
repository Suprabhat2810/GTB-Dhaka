<?php
// toggle_payment.php — hardened, logged, non-breaking replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('toggle_payment');
$pdo = getPDO();
$start = microtime(true);

try {
    // Keep preflight behavior
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $logger->warning('toggle_payment: method not allowed', ['method' => $_SERVER['REQUEST_METHOD']]);
        jsonResponse('error', 'Method not allowed.', [], 405);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $logger->warning('toggle_payment: invalid json payload');
        jsonResponse('error', 'Invalid JSON payload.', [], 400);
        exit;
    }

    // authenticate (preserve existing behavior; actor may be admin or other role)
    $user = authenticate('admin');
    $actor = (int)($user->sub ?? $user->id ?? 0);
    // Safely extract program and is_live from the decoded input
    $program = null;
    if (is_array($input) && array_key_exists('program', $input)) {
        $program = trim((string)$input['program']);
    }
    $isLiveRaw = null;
    if (is_array($input) && array_key_exists('is_live', $input)) {
        $isLiveRaw = $input['is_live'];
    }
    
   // Accept booleans or integer-like values for is_live
    if ($isLiveRaw === null) {
        $logger->warning('toggle_payment: missing is_live', ['actor' => $actor, 'payload' => $input]);
        jsonResponse('error', 'Program and Live status are required.', [], 400);
        exit;
    }

    if ($program === '' || $program === null) {
        $logger->warning('toggle_payment: missing program', ['actor' => $actor, 'payload' => $input]);
        jsonResponse('error', 'Program and Live status are required.', [], 400);
        exit;
    }

    // Normalize is_live to boolean
    if (is_bool($isLiveRaw)) {
        $isLive = $isLiveRaw;
    } elseif (is_numeric($isLiveRaw)) {
        $isLive = ((int)$isLiveRaw) === 1;
    } elseif (is_string($isLiveRaw)) {
        $lower = strtolower($isLiveRaw);
        $isLive = in_array($lower, ['1', 'true', 'yes', 'on'], true);
    } else {
        $isLive = false;
    }

    if (!$actor) {
        $logger->warning('toggle_payment: unauthenticated attempt', ['program' => $program]);
        jsonResponse('error', 'Authentication required to toggle payment status.', [], 401);
        exit;
    }

    $logger->info('toggle_payment: request received', [
        'program' => $program,
        'is_live' => $isLive,
        'actor' => $actor
    ]);

    try {
        $pdo->beginTransaction();

        // Update existing rows; if none updated, insert a new row for the program (idempotent)
        $stmt = $pdo->prepare("UPDATE fee_settings SET is_live = ?, updated_by = ?, updated_at = NOW() WHERE program = ?");
        $stmt->execute([$isLive ? 1 : 0, $actor, $program]);
        $rows = $stmt->rowCount();

        if ($rows === 0) {
            // Insert a new fee_settings row (we don't know total_fee here, so set NULL)
            $stmtIns = $pdo->prepare("INSERT INTO fee_settings (program, total_fee, updated_by, updated_at, is_live) VALUES (?, NULL, ?, NOW(), ?)");
            $stmtIns->execute([$program, $actor, $isLive ? 1 : 0]);
            $logger->info('toggle_payment: inserted fee_settings row for program', ['program' => $program, 'actor' => $actor]);
        } else {
            $logger->info('toggle_payment: updated fee_settings rows', ['rows' => $rows, 'program' => $program, 'actor' => $actor]);
        }

        $pdo->commit();

        $durationMs = round((microtime(true) - $start) * 1000, 2);
        $logger->info('toggle_payment: completed', ['program' => $program, 'is_live' => $isLive, 'actor' => $actor, 'duration_ms' => $durationMs]);

        jsonResponse('success', $isLive ? 'Payments activated successfully.' : 'Payments stopped successfully.', [], 200);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $logger->error('toggle_payment: DB error', ['error' => $e->getMessage(), 'program' => $program, 'actor' => $actor]);
        jsonResponse('error', 'Failed to toggle payment status: ' . $e->getMessage(), [], 500);
    }
} catch (Exception $e) {
    $logger->critical('toggle_payment: unexpected error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    jsonResponse('error', 'Internal server error.', [], 500);
}

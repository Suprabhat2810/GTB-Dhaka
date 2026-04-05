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

        // NEW: Only update the ACTIVE version (respects fee versioning)
        // Get the active fee_settings for this program
        $stmt = $pdo->prepare("SELECT id FROM fee_settings WHERE program = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$program]);
        $activeId = $stmt->fetchColumn();

        if ($activeId) {
            // Update the active version only
            $stmt = $pdo->prepare("UPDATE fee_settings SET is_live = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$isLive ? 1 : 0, $actor, $activeId]);
            $logger->info('toggle_payment: updated active fee_settings version', ['id' => $activeId, 'program' => $program, 'actor' => $actor]);
        } else {
            // No active version found, try to get the latest one
            $stmt = $pdo->prepare("SELECT id FROM fee_settings WHERE program = ? ORDER BY version DESC, updated_at DESC LIMIT 1");
            $stmt->execute([$program]);
            $latestId = $stmt->fetchColumn();
            
            if ($latestId) {
                // Mark it as active and update is_live
                $stmt = $pdo->prepare("UPDATE fee_settings SET is_live = ?, is_active = 1, updated_by = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$isLive ? 1 : 0, $actor, $latestId]);
                $logger->info('toggle_payment: activated and updated latest fee_settings', ['id' => $latestId, 'program' => $program, 'actor' => $actor]);
            } else {
                // No fee settings exist for this program - log error
                $logger->error('toggle_payment: no fee_settings found for program', ['program' => $program]);
                throw new Exception("No fee settings found for program: $program");
            }
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

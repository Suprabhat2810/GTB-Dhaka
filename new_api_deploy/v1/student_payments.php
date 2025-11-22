<?php
// student_payments.php — hardened, logged, non-breaking replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('student_payments');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

try {
    if ($method !== 'GET') {
        $logger->warning('student_payments: method not allowed', ['method' => $method]);
        jsonResponse("error", "Method not allowed.", [], 405);
    }

    $user = authenticate('student'); // requires student role
    $studentId = (int)($user->id ?? 0);
    if ($studentId <= 0) {
        $logger->warning('student_payments: invalid student context', ['user' => $user]);
        jsonResponse("error", "Unauthorized.", [], 403);
    }

    try {
        $logger->info('student_payments: fetching payments', ['student_id' => $studentId]);

        $stmt = $pdo->prepare("
            SELECT amount, payment_date, payment_received, payment_status
            FROM payments
            WHERE student_id = ?
            ORDER BY payment_date DESC, id DESC
        ");
        $stmt->execute([$studentId]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate total paid reliably (cast to float & check payment_received/payment_status)
        $totalPaid = 0.0;
        foreach ($payments as $p) {
            $receivedFlag = (int)($p['payment_received'] ?? 0);
            $status = strtolower((string)($p['payment_status'] ?? ''));
            if ($receivedFlag === 1 || $status === 'paid' || $status === 'captured') {
                $totalPaid += (float)$p['amount'];
            }
        }

        $formatted = array_map(function ($payment) {
            $receivedFlag = (int)($payment['payment_received'] ?? 0);
            $status = strtolower((string)($payment['payment_status'] ?? ''));
            $frontendStatus = ($receivedFlag === 1 || $status === 'paid' || $status === 'captured') ? 'captured' : 'pending';

            return [
                'amount' => (float)$payment['amount'],
                'payment_date' => $payment['payment_date'],
                'status' => $frontendStatus,
            ];
        }, $payments);

        $data = [
            'total_paid' => $totalPaid,
            'payments' => $formatted,
        ];

        $durationMs = round((microtime(true) - $start) * 1000, 2);
        $logger->info('student_payments: retrieved', ['student_id' => $studentId, 'count' => count($formatted), 'total_paid' => $totalPaid, 'duration_ms' => $durationMs]);

        jsonResponse("success", "Payments retrieved successfully.", $data, 200);
    } catch (PDOException $e) {
        $logger->error('student_payments: DB error', ['error' => $e->getMessage(), 'student_id' => $studentId]);
        jsonResponse("error", "Failed to fetch payments.", [], 500);
    }
} catch (Exception $e) {
    $logger->critical('student_payments: unexpected error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    jsonResponse("error", "Internal server error.", [], 500);
}

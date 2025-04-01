<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $user = authenticate('student');

    try {
        $stmt = $pdo->prepare("
            SELECT amount, payment_date, payment_received
            FROM payments
            WHERE student_id = ?
        ");
        $stmt->execute([$user->id]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalPaid = array_reduce($payments, function ($sum, $payment) {
            return $payment['payment_received'] == 1 ? $sum + $payment['amount'] : $sum;
        }, 0);

        $data = [
            'total_paid' => $totalPaid,
            'payments' => array_map(function ($payment) {
                return [
                    'amount' => (float) $payment['amount'], // Convert to float for consistency
                    'payment_date' => $payment['payment_date'],
                    'status' => $payment['payment_received'] == 1 ? 'captured' : 'pending', // Map to frontend-expected values
                ];
            }, $payments),
        ];

        jsonResponse("success", "Payments retrieved successfully.", $data, 200);
    } catch (PDOException $e) {
        $log->error("Failed to fetch student payments: " . $e->getMessage());
        jsonResponse("error", "Failed to fetch payments.", [], 500);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
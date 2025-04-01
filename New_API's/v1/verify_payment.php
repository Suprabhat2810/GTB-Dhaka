<?php
require_once '../config.php';
require_once '../vendor/autoload.php';

use Razorpay\Api\Api;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $user = authenticate('admin');

    // Extract payment details from the request
    $data = json_decode(file_get_contents("php://input"), true);
    $studentId = filter_var($data['student_id'] ?? 0, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    $razorpayPaymentId = $data['razorpay_payment_id'] ?? '';
    $razorpayOrderId = $data['razorpay_order_id'] ?? '';
    $razorpaySignature = $data['razorpay_signature'] ?? '';
    $amount = filter_var($data['amount'] ?? 0, FILTER_VALIDATE_FLOAT);

    if (!$studentId || !$razorpayPaymentId || !$razorpayOrderId || !$razorpaySignature || !$amount) {
        jsonResponse("error", "Invalid or missing payment details.", [], 400);
    }

    try {
        // Verify the payment signature
        $api = new Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);
        $attributes = [
            'razorpay_order_id' => $razorpayOrderId,
            'razorpay_payment_id' => $razorpayPaymentId,
            'razorpay_signature' => $razorpaySignature
        ];

        $api->utility->verifyPaymentSignature($attributes);

        // Payment signature verified, record the payment
        $stmt = $pdo->prepare("
            INSERT INTO payments (student_id, razorpay_payment_id, amount, currency, status, payment_date)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $studentId,
            $razorpayPaymentId,
            $amount,
            'INR',
            'captured'
        ]);

        $paymentId = $pdo->lastInsertId();

        // Trigger a notification for the student
        $stmt = $pdo->prepare("
            INSERT INTO notifications (student_id, message, notification_date)
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$studentId, "Payment of $amount INR has been received successfully."]);

        jsonResponse("success", "Payment verified and recorded successfully.", [
            "payment_id" => $paymentId,
            "razorpay_payment_id" => $razorpayPaymentId,
            "amount" => $amount,
            "currency" => "INR"
        ], 201);
    } catch (Exception $e) {
        $log->error("Payment verification failed: " . $e->getMessage());
        jsonResponse("error", "Payment verification failed.", [], 400);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
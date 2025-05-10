<?php
require_once '../config.php';
require_once '../vendor/autoload.php';

use Razorpay\Api\Api;

$method = $_SERVER['REQUEST_METHOD'];

// Handle CORS preflight request
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($method === 'GET') {
    $user = authenticate();
    $role = $user->role;

    try {
        if ($role === 'admin') {
            $stmt = $pdo->prepare("
                SELECT p.id, p.student_id, s.name AS student_name, s.program, p.amount, p.payment_date, p.payment_received, p.pending_amount
                FROM payments p
                JOIN students s ON p.student_id = s.id
            ");
            $stmt->execute();
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formattedPayments = array_map(function ($payment) {
                return [
                    'id' => $payment['id'],
                    'student_id' => $payment['student_id'],
                    'student_name' => $payment['student_name'],
                    'program' => $payment['program'],
                    'amount' => $payment['amount'],
                    'payment_date' => $payment['payment_date'],
                    'payment_received' => $payment['payment_received'],
                    'pending_amount' => $payment['pending_amount']
                ];
            }, $payments);

            $totalPaid = array_sum(array_column($payments, 'amount'));
            $data = ['payments' => $formattedPayments, 'total_paid' => $totalPaid];
        } else {
            $student_id = $user->id;
            $stmt = $pdo->prepare("
                SELECT amount, payment_date, payment_received, total_fee
                FROM payments
                WHERE student_id = ?
                ORDER BY payment_date DESC
                LIMIT 1
            ");
            $stmt->execute([$student_id]);
            $latestPayment = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                SELECT amount, payment_date, payment_received
                FROM payments
                WHERE student_id = ?
            ");
            $stmt->execute([$student_id]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalPaid = array_sum(array_column($payments, 'amount'));

            if ($latestPayment) {
                $totalFee = $latestPayment['total_fee'];
            } else {
                $stmt = $pdo->prepare("SELECT program FROM students WHERE id = ?");
                $stmt->execute([$student_id]);
                $student = $stmt->fetch();
                $program = $student['program'];

                $stmt = $pdo->prepare("
                    SELECT total_fee FROM fee_settings 
                    WHERE program = ? 
                    ORDER BY updated_at DESC 
                    LIMIT 1
                ");
                $stmt->execute([$program]);
                $feeSetting = $stmt->fetch();
                $totalFee = $feeSetting ? $feeSetting['total_fee'] : 110000; // Default to 110000
            }

            $remainingFee = max(0, $totalFee - $totalPaid);
            $canPay = $remainingFee > 0;

            // Check if payment is live for the student's program
            $stmt = $pdo->prepare("
                SELECT is_live FROM fee_settings 
                WHERE program = (SELECT program FROM students WHERE id = ?) 
                ORDER BY updated_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$student_id]);
            $feeSetting = $stmt->fetch();
            $isLive = $feeSetting ? $feeSetting['is_live'] : false;

            if (!$isLive) {
                $canPay = false;
                $data['payment_not_live_message'] = "Payment is not live for your program at this time. Contact the admin.";
            }

            // Check if student is approved
            $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ?");
            $stmt->execute([$student_id]);
            $approval = $stmt->fetch();
            if (!$approval || $approval['approved'] != 1) {
                $canPay = false;
                $data['approval_message'] = "You cannot pay at this time. Ensure you are approved and have uploaded documents.";
            }

            $data = array_merge([
                'payments' => $payments,
                'total_paid' => $totalPaid,
                'total_fee' => $totalFee,
                'remaining_fee' => $remainingFee,
                'can_pay' => $canPay
            ], isset($data['payment_not_live_message']) ? ['payment_not_live_message' => $data['payment_not_live_message']] : [], 
               isset($data['approval_message']) ? ['approval_message' => $data['approval_message']] : []);
        }

        jsonResponse("success", "Payments retrieved successfully.", $data, 200);
    } catch (PDOException $e) {
        $log->error("Failed to fetch payments: " . $e->getMessage());
        jsonResponse("error", "Failed to fetch payments: " . $e->getMessage(), [], 500);
    }
} elseif ($method === 'POST') {
    $user = authenticate();
    $role = $user->role;

    $data = json_decode(file_get_contents("php://input"), true);
    $amount = filter_var($data['amount'] ?? 0, FILTER_VALIDATE_FLOAT);

    if (!$amount || $amount <= 0) {
        jsonResponse("error", "Invalid or missing amount.", [], 400);
    }

    try {
        if ($role === 'admin') {
            $studentId = filter_var($data['student_id'] ?? 0, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
            if (!$studentId) {
                jsonResponse("error", "Invalid or missing student ID.", [], 400);
            }

            $stmt = $pdo->prepare("SELECT 1 FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            if (!$stmt->fetch()) {
                jsonResponse("error", "Student not found.", [], 404);
            }

            $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ?");
            $stmt->execute([$studentId]);
            $approval = $stmt->fetch();
            if (!$approval || $approval['approved'] != 1) {
                jsonResponse("error", "Student must be approved before recording payment.", [], 403);
            }

            $stmt = $pdo->prepare("SELECT 1 FROM documents WHERE student_id = ?");
            $stmt->execute([$studentId]);
            if (!$stmt->fetch()) {
                jsonResponse("error", "Student must upload a document before recording payment.", [], 403);
            }

            $stmt = $pdo->prepare("SELECT 1 FROM payments WHERE student_id = ? AND payment_received = 1");
            $stmt->execute([$studentId]);
            if ($stmt->fetch()) {
                jsonResponse("error", "Payment already recorded for this student.", [], 400);
            }
        } else {
            $studentId = $user->id;

            $stmt = $pdo->prepare("SELECT program, semester FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch();
            if (!$student) {
                jsonResponse("error", "Student not found.", [], 404);
            }

            $program = $student['program'];
            $semester = $student['semester'];

            $stmt = $pdo->prepare("
                SELECT total_fee
                FROM payments
                WHERE student_id = ?
                ORDER BY payment_date DESC
                LIMIT 1
            ");
            $stmt->execute([$studentId]);
            $latestPayment = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalFee = $latestPayment ? $latestPayment['total_fee'] : 110000; // Default to 110000

            $stmt = $pdo->prepare("SELECT SUM(amount) as total_paid FROM payments WHERE student_id = ? AND payment_received = 1");
            $stmt->execute([$studentId]);
            $result = $stmt->fetch();
            $totalPaid = $result['total_paid'] ?? 0;

            $remainingFee = max(0, $totalFee - $totalPaid);
            if ($amount > $remainingFee) {
                jsonResponse("error", "Amount exceeds remaining fee. Remaining fee: ₹$remainingFee", [], 400);
            }

            // Check if payment is live
            $stmt = $pdo->prepare("
                SELECT is_live FROM fee_settings 
                WHERE program = ? 
                ORDER BY updated_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$program]);
            $feeSetting = $stmt->fetch();
            $isLive = $feeSetting ? $feeSetting['is_live'] : false;

            if (!$isLive) {
                jsonResponse("error", "Payment is not live for your program at this time. Contact the admin.", [], 403);
            }

            $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ?");
            $stmt->execute([$studentId]);
            $approval = $stmt->fetch();
            if (!$approval || $approval['approved'] != 1) {
                jsonResponse("error", "Student must be approved before making payment.", [], 403);
            }
        }

        $api = new Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);

        $orderData = [
            'receipt' => 'student_' . $studentId,
            'amount' => $amount * 100,
            'currency' => 'INR',
            'payment_capture' => 1
        ];

        $log->info("Creating Razorpay order with data: " . json_encode($orderData));
        $razorpayOrder = $api->order->create($orderData);
        $log->info("Razorpay order created: " . json_encode($razorpayOrder));
        $razorpayOrderId = $razorpayOrder['id'];

        jsonResponse("success", "Razorpay order created successfully.", [
            "order_id" => $razorpayOrderId,
            "amount" => $amount,
            "currency" => "INR",
            "key_id" => RAZORPAY_KEY_ID,
            "total_fee" => $totalFee,
            "remaining_fee" => $remainingFee
        ], 200);
    } catch (Exception $e) {
        $log->error("Razorpay order creation failed: " . $e->getMessage());
        jsonResponse("error", "Razorpay order creation failed: " . $e->getMessage(), [], 500);
    }
} elseif ($method === 'PUT') {
    $user = authenticate();
    $role = $user->role;

    if ($role !== 'student') {
        jsonResponse("error", "Only students can verify payments.", [], 403);
    }

    $data = json_decode(file_get_contents("php://input"), true);
    $razorpayPaymentId = $data['razorpay_payment_id'] ?? null;
    $razorpayOrderId = $data['razorpay_order_id'] ?? null;
    $razorpaySignature = $data['razorpay_signature'] ?? null;
    $amount = filter_var($data['amount'] ?? 0, FILTER_VALIDATE_FLOAT);

    if (!$razorpayPaymentId || !$razorpayOrderId || !$razorpaySignature || !$amount) {
        jsonResponse("error", "Missing payment details.", [], 400);
    }

    try {
        $api = new Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);
        $attributes = [
            'razorpay_order_id' => $razorpayOrderId,
            'razorpay_payment_id' => $razorpayPaymentId,
            'razorpay_signature' => $razorpaySignature
        ];
        $api->utility->verifyPaymentSignature($attributes);

        $stmt = $pdo->prepare("
            INSERT INTO payments (student_id, amount, payment_received, payment_date, total_fee)
            VALUES (?, ?, 1, NOW(), (SELECT total_fee FROM payments WHERE student_id = ? ORDER BY payment_date DESC LIMIT 1))
        ");
        $stmt->execute([$user->id, $amount, $user->id]);

        // Optional: Send notification
        $stmt = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
        $stmt->execute([$user->id, "Your payment of ₹$amount has been successfully recorded."]);

        jsonResponse("success", "Payment recorded successfully.", [], 200);
    } catch (Exception $e) {
        $log->error("Payment verification failed: " . $e->getMessage());
        jsonResponse("error", "Payment verification failed: " . $e->getMessage(), [], 400);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
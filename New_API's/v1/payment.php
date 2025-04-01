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
            // Admin: Fetch all payments
            $stmt = $pdo->prepare("
                SELECT p.id, p.student_id, s.first_name, s.last_name, s.program, p.amount, p.payment_date, p.payment_received
                FROM payments p
                JOIN students s ON p.student_id = s.id
            ");
            $stmt->execute();
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formattedPayments = array_map(function ($payment) {
                return [
                    'id' => $payment['id'],
                    'student_id' => $payment['student_id'],
                    'student_name' => $payment['first_name'] . ' ' . $payment['last_name'],
                    'program' => $payment['program'],
                    'amount' => $payment['amount'],
                    'payment_date' => $payment['payment_date'],
                    'payment_received' => $payment['payment_received'],
                ];
            }, $payments);

            $totalPaid = array_sum(array_column($payments, 'amount'));
            $data = [
                'payments' => $formattedPayments,
                'total_paid' => $totalPaid
            ];
        } else {
            // Student: Fetch their own payments
            $student_id = $user->id;
            $stmt = $pdo->prepare("
                SELECT amount, payment_date, payment_received
                FROM payments
                WHERE student_id = ?
            ");
            $stmt->execute([$student_id]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalPaid = array_sum(array_column($payments, 'amount'));

            // Fetch student's program and semester
            $stmt = $pdo->prepare("SELECT program, semester FROM students WHERE id = ?");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch();

            if (!$student) {
                jsonResponse("error", "Student not found.", [], 404);
            }

            $program = $student['program'];
            $semester = $student['semester'];

            // Fetch subjects for the student's program and semester
            $stmt = $pdo->prepare("
                SELECT credits, valid_from, valid_to
                FROM subjects
                WHERE department = ? AND semester = ?
            ");
            $stmt->execute([$program, $semester]);
            $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalFee = 0;
            $remainingFee = 0;
            $canPay = false;

            if (!empty($subjects)) {
                // Check if the current date is within the payment window
                $currentDate = date('Y-m-d');
                $validFrom = $subjects[0]['valid_from'];
                $validTo = $subjects[0]['valid_to'];

                if ($currentDate >= $validFrom && $currentDate <= $validTo) {
                    $canPay = true;
                }

                // Calculate total semester fee (₹5000 per credit)
                $totalCredits = array_sum(array_column($subjects, 'credits'));
                $feePerCredit = 5000; // ₹5000 per credit
                $totalFee = $totalCredits * $feePerCredit;
                $remainingFee = $totalFee - $totalPaid;
            }

            $data = [
                'payments' => $payments,
                'total_paid' => $totalPaid,
                'total_fee' => $totalFee,
                'remaining_fee' => $remainingFee,
                'can_pay' => $canPay
            ];
        }

        jsonResponse("success", "Payments retrieved successfully.", $data, 200);
    } catch (PDOException $e) {
        $log->error("Failed to fetch payments: " . $e->getMessage());
        jsonResponse("error", "Failed to fetch payments: " . $e->getMessage(), [], 500);
    }
} elseif ($method === 'POST') {
    $user = authenticate();
    $role = $user->role;

    // Extract student_id and amount from the request
    $data = json_decode(file_get_contents("php://input"), true);
    $amount = filter_var($data['amount'] ?? 0, FILTER_VALIDATE_FLOAT);

    if (!$amount || $amount <= 0) {
        jsonResponse("error", "Invalid or missing amount.", [], 400);
    }

    try {
        if ($role === 'admin') {
            // Admin: Create a payment order for a specific student
            $studentId = filter_var($data['student_id'] ?? 0, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
            if (!$studentId) {
                jsonResponse("error", "Invalid or missing student ID.", [], 400);
            }

            // Check if the student exists
            $stmt = $pdo->prepare("SELECT 1 FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            if (!$stmt->fetch()) {
                jsonResponse("error", "Student not found.", [], 404);
            }

            // Check if the student is approved
            $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ?");
            $stmt->execute([$studentId]);
            $approval = $stmt->fetch();
            if (!$approval || $approval['approved'] != 1) {
                jsonResponse("error", "Student must be approved before recording payment.", [], 403);
            }

            // Check if the student has uploaded a document
            $stmt = $pdo->prepare("SELECT 1 FROM documents WHERE student_id = ?");
            $stmt->execute([$studentId]);
            if (!$stmt->fetch()) {
                jsonResponse("error", "Student must upload a document before recording payment.", [], 403);
            }

            // Check if payment has already been recorded
            $stmt = $pdo->prepare("SELECT 1 FROM payments WHERE student_id = ? AND payment_received = 1");
            $stmt->execute([$studentId]);
            if ($stmt->fetch()) {
                jsonResponse("error", "Payment already recorded for this student.", [], 400);
            }
        } else {
            // Student: Create a payment order for themselves
            $studentId = $user->id;

            // Fetch student's profile to get program and semester
            $stmt = $pdo->prepare("SELECT program, semester FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch();
            if (!$student) {
                jsonResponse("error", "Student not found.", [], 404);
            }

            $program = $student['program'];
            $semester = $student['semester'];

            // Fetch subjects for the student's program and semester
            $stmt = $pdo->prepare("
                SELECT credits, valid_from, valid_to
                FROM subjects
                WHERE program = ? AND semester = ?
            ");
            $stmt->execute([$program, $semester]);
            $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($subjects)) {
                jsonResponse("error", "No subjects found for your program and semester.", [], 404);
            }

            // Check if the current date is within the payment window
            $currentDate = date('Y-m-d');
            $validFrom = $subjects[0]['valid_from'];
            $validTo = $subjects[0]['valid_to'];

            if ($currentDate < $validFrom || $currentDate > $validTo) {
                jsonResponse("error", "Payment window is closed for this semester.", [], 403);
            }

            // Calculate total semester fee (e.g., ₹5000 per credit)
            $totalCredits = array_sum(array_column($subjects, 'credits'));
            $feePerCredit = 5000; // ₹5000 per credit
            $totalFee = $totalCredits * $feePerCredit;

            // Check if the requested amount is valid
            $stmt = $pdo->prepare("SELECT SUM(amount) as total_paid FROM payments WHERE student_id = ? AND payment_received = 1");
            $stmt->execute([$studentId]);
            $result = $stmt->fetch();
            $totalPaid = $result['total_paid'] ?? 0;

            $remainingFee = $totalFee - $totalPaid;
            if ($amount > $remainingFee) {
                jsonResponse("error", "Amount exceeds remaining fee. Remaining fee: ₹$remainingFee", [], 400);
            }
        }

        // Initialize Razorpay API
        $api = new Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);

        // Create a Razorpay order
        $orderData = [
            'receipt' => 'student_' . $studentId,
            'amount' => $amount * 100, // Amount in paise
            'currency' => 'INR',
            'payment_capture' => 1 // Auto-capture the payment
        ];

        $razorpayOrder = $api->order->create($orderData);
        $razorpayOrderId = $razorpayOrder['id'];

        // Return the order ID to the front-end
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
    // Verify and record payment after Razorpay payment
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
        // Verify the payment signature
        $api = new Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);
        $attributes = [
            'razorpay_order_id' => $razorpayOrderId,
            'razorpay_payment_id' => $razorpayPaymentId,
            'razorpay_signature' => $razorpaySignature
        ];
        $api->utility->verifyPaymentSignature($attributes);

        // Record the payment in the database
        $stmt = $pdo->prepare("
            INSERT INTO payments (student_id, amount, payment_received, payment_date)
            VALUES (?, ?, 1, NOW())
        ");
        $stmt->execute([$user->id, $amount]);

        jsonResponse("success", "Payment recorded successfully.", [], 200);
    } catch (Exception $e) {
        $log->error("Payment verification failed: " . $e->getMessage());
        jsonResponse("error", "Payment verification failed: " . $e->getMessage(), [], 400);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
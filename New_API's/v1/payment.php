<?php
require_once '../config.php';

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
                SELECT p.id, p.student_id, s.name AS student_name, s.program, p.amount, p.payment_date, p.payment_received, p.payment_status, p.pending_amount, p.total_fee
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
                    'payment_status' => $payment['payment_status'], // Include payment_status
                    'pending_amount' => $payment['pending_amount'],
                    'total_fee' => $payment['total_fee']
                ];
            }, $payments);

            $totalPaid = array_sum(array_column($payments, 'amount'));
            $data = ['payments' => $formattedPayments, 'total_paid' => $totalPaid];
        } else {
            $student_id = $user->id;
            $stmt = $pdo->prepare("
                SELECT amount, payment_date, payment_received, payment_status, total_fee
                FROM payments
                WHERE student_id = ?
                ORDER BY payment_date DESC
                LIMIT 1
            ");
            $stmt->execute([$student_id]);
            $latestPayment = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                SELECT amount, payment_date, payment_received, payment_status
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

            $stmt = $pdo->prepare("SELECT 1 FROM payments WHERE student_id = ? AND payment_status = 'paid'");
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

            $stmt = $pdo->prepare("SELECT SUM(amount) as total_paid FROM payments WHERE student_id = ? AND payment_status = 'paid'");
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

            // Insert payment as pending
            $stmt = $pdo->prepare("
                INSERT INTO payments (student_id, amount, payment_received, payment_status, payment_date, total_fee, pending_amount)
                VALUES (?, ?, 0, 'pending', NOW(), ?, ?)
            ");
            $stmt->execute([$studentId, $amount, $totalFee, $remainingFee - $amount]);
        }

        jsonResponse("success", "Payment submitted successfully. Awaiting verification.", [], 200);
    } catch (Exception $e) {
        $log->error("Payment submission failed: " . $e->getMessage());
        jsonResponse("error", "Payment submission failed: " . $e->getMessage(), [], 500);
    }
} elseif ($method === 'PUT') {
    $user = authenticate();
    $role = $user->role;

    $data = json_decode(file_get_contents("php://input"), true);
    $action = $data['action'] ?? '';
    $paymentId = filter_var($data['payment_id'] ?? 0, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);

    if (!$paymentId) {
        jsonResponse("error", "Invalid or missing payment ID.", [], 400);
    }

    if ($role === 'student') {
        // Update payment status to 'processing'
        $stmt = $pdo->prepare("
            UPDATE payments 
            SET payment_status = 'processing' 
            WHERE id = ? AND student_id = ? AND payment_status = 'pending'
        ");
        $stmt->execute([$paymentId, $user->id]);

        if ($stmt->rowCount() === 0) {
            jsonResponse("error", "Payment not found or already processed.", [], 404);
        }

        // Notify admin
        $stmt = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
        $stmt->execute([$user->id, "Payment of ₹{$data['amount']} submitted by student ID {$user->id}. Awaiting verification."]);

        jsonResponse("success", "Payment marked as processing. Awaiting admin verification.", [], 200);
    } elseif ($role === 'admin') {
        if ($action === 'confirm') {
            // Update payment status to 'paid'
            $stmt = $pdo->prepare("
                UPDATE payments 
                SET payment_status = 'paid', payment_received = 1, pending_amount = 0
                WHERE id = ? AND payment_status = 'pending'
            ");
            $stmt->execute([$paymentId]);

            if ($stmt->rowCount() === 0) {
                jsonResponse("error", "Payment not found or not in pending status.", [], 404);
            }

            // Fetch payment details for notification
            $stmt = $pdo->prepare("SELECT student_id, amount FROM payments WHERE id = ?");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            // Notify student
            $stmt = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
            $stmt->execute([$payment['student_id'], "Your payment of ₹{$payment['amount']} has been confirmed by the admin."]);

            jsonResponse("success", "Payment confirmed successfully.", [], 200);
        } else {
            // Existing logic for approving 'processing' payments
            $stmt = $pdo->prepare("
                UPDATE payments 
                SET payment_status = 'paid', payment_received = 1 
                WHERE id = ? AND payment_status = 'processing'
            ");
            $stmt->execute([$paymentId]);

            if ($stmt->rowCount() === 0) {
                jsonResponse("error", "Payment not found or not in processing state.", [], 404);
            }

            // Notify student
            $stmt = $pdo->prepare("SELECT student_id, amount FROM payments WHERE id = ?");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            $studentId = $payment['student_id'];
            $amount = $payment['amount'];

            $stmt = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
            $stmt->execute([$studentId, "Your payment of ₹{$amount} has been approved by the admin."]);

            jsonResponse("success", "Payment approved successfully.", [], 200);
        }
    } else {
        jsonResponse("error", "Unauthorized role.", [], 403);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
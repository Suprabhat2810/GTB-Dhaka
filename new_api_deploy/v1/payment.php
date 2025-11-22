<?php
// payment.php — hardened, logged, enterprise-grade replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('payment');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

// 🧭 CORS preflight
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // -------------------------------------------
    // 🧾 GET — Retrieve payments (Admin/Student)
    // -------------------------------------------
    if ($method === 'GET') {
        $user = authenticate();
        $role = $user->role ?? 'guest';
        $logger->info('Payments GET initiated', ['actor_role' => $role, 'actor_id' => $user->id ?? null]);

        if ($role === 'admin') {
            $stmt = $pdo->query("
                SELECT p.id, p.student_id, s.name AS student_name, s.program,
                       p.amount, p.payment_date, p.payment_received, p.payment_status,
                       p.pending_amount, p.total_fee
                FROM payments p
                JOIN students s ON p.student_id = s.id
                ORDER BY p.payment_date DESC
            ");
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formattedPayments = array_map(fn($p) => [
                'id' => $p['id'],
                'student_id' => $p['student_id'],
                'student_name' => $p['student_name'],
                'program' => $p['program'],
                'amount' => (float)$p['amount'],
                'payment_date' => $p['payment_date'],
                'payment_received' => (bool)$p['payment_received'],
                'payment_status' => $p['payment_status'],
                'pending_amount' => (float)$p['pending_amount'],
                'total_fee' => (float)$p['total_fee']
            ], $payments);

            $totalPaid = array_sum(array_column($formattedPayments, 'amount'));
            $data = ['payments' => $formattedPayments, 'total_paid' => $totalPaid];

            $logger->info('Payments GET completed (admin)', ['count' => count($payments), 'duration_ms' => round((microtime(true) - $start) * 1000, 2)]);
            jsonResponse("success", "Payments retrieved successfully.", $data, 200);
        }

        // 🎓 Student flow
        $student_id = (int)($user->sub ?? $user->id ?? 0);;
        $stmt = $pdo->prepare("
            SELECT amount, payment_date, payment_received, payment_status, total_fee
            FROM payments WHERE student_id = ?
            ORDER BY payment_date DESC
        ");
        $stmt->execute([$student_id]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalPaid = array_sum(array_column($payments, 'amount'));
        $latest = $payments[0] ?? null;

        // Fetch total_fee (fallback from fee_settings)
        $totalFee = $latest['total_fee'] ?? null;
        if (!$totalFee) {
            $stmt = $pdo->prepare("
                SELECT fs.total_fee FROM fee_settings fs
                JOIN students s ON s.program = fs.program
                WHERE s.id = ? ORDER BY fs.updated_at DESC LIMIT 1
            ");
            $stmt->execute([$student_id]);
            $totalFee = (float)($stmt->fetchColumn() ?: 110000);
        }

        $remainingFee = max(0, $totalFee - $totalPaid);
        $canPay = $remainingFee > 0;

        // Check if payment is live
        $stmt = $pdo->prepare("
            SELECT is_live FROM fee_settings 
            WHERE program = (SELECT program FROM students WHERE id = ?) 
            ORDER BY updated_at DESC LIMIT 1
        ");
        $stmt->execute([$student_id]);
        $isLive = (bool)$stmt->fetchColumn();

        if (!$isLive) {
            $canPay = false;
            $data['payment_not_live_message'] = "Payment is not live for your program at this time.";
        }

        // Ensure student approved
        $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ?");
        $stmt->execute([$student_id]);
        $approved = (int)$stmt->fetchColumn();
        if ($approved !== 1) {
            $canPay = false;
            $data['approval_message'] = "You must be approved before payment.";
        }

        $data = [
            'payments' => $payments,
            'total_paid' => $totalPaid,
            'total_fee' => $totalFee,
            'remaining_fee' => $remainingFee,
            'can_pay' => $canPay
        ] + ($data ?? []);

        $logger->info('Payments GET completed (student)', [
            'student_id' => $student_id,
            'total_paid' => $totalPaid,
            'remaining' => $remainingFee,
            'can_pay' => $canPay
        ]);

        jsonResponse("success", "Payments retrieved successfully.", $data, 200);
    }

    // -------------------------------------------
    // 💳 POST — Submit or Record Payment
    // -------------------------------------------
    elseif ($method === 'POST') {
        $user = authenticate();
        $role = $user->role ?? 'guest';
        $data = json_decode(file_get_contents("php://input"), true);
        $amount = filter_var($data['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
        if (!$amount || $amount <= 0) {
            jsonResponse("error", "Invalid or missing amount.", [], 400);
        }

        if ($role === 'admin') {
            $studentId = filter_var($data['student_id'] ?? 0, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
            if (!$studentId) jsonResponse("error", "Invalid or missing student ID.", [], 400);

            $stmt = $pdo->prepare("SELECT 1 FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            if (!$stmt->fetch()) jsonResponse("error", "Student not found.", [], 404);

            $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ?");
            $stmt->execute([$studentId]);
            if ((int)$stmt->fetchColumn() !== 1) jsonResponse("error", "Student must be approved before recording payment.", [], 403);

            $stmt = $pdo->prepare("SELECT 1 FROM documents WHERE student_id = ?");
            $stmt->execute([$studentId]);
            if (!$stmt->fetch()) jsonResponse("error", "Student must upload a document before payment.", [], 403);

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE student_id = ? AND payment_status = 'paid'");
            $stmt->execute([$studentId]);
            if ($stmt->fetchColumn() > 0) jsonResponse("error", "Payment already recorded.", [], 400);

            $stmt = $pdo->prepare("INSERT INTO payments (student_id, amount, payment_received, payment_status, payment_date, total_fee, pending_amount) VALUES (?, ?, 1, 'paid', NOW(), ?, 0)");
            $stmt->execute([$studentId, $amount, $amount]);

            $logger->info('Admin recorded payment', ['student_id' => $studentId, 'amount' => $amount, 'actor' => $user->id ?? null]);
            jsonResponse("success", "Payment recorded successfully.", [], 200);
        }

        // Student payment initiation
        $studentId = (int)$user->id;
        $stmt = $pdo->prepare("
            SELECT total_fee, COALESCE(SUM(amount),0) as total_paid
            FROM payments WHERE student_id = ? AND payment_status = 'paid'
        ");
        $stmt->execute([$studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalFee = $row['total_fee'] ?? 110000;
        $totalPaid = (float)$row['total_paid'];
        $remaining = max(0, $totalFee - $totalPaid);
        if ($amount > $remaining) jsonResponse("error", "Amount exceeds remaining fee (₹$remaining).", [], 400);

        $stmt = $pdo->prepare("SELECT is_live FROM fee_settings WHERE program = (SELECT program FROM students WHERE id = ?) ORDER BY updated_at DESC LIMIT 1");
        $stmt->execute([$studentId]);
        if (!(bool)$stmt->fetchColumn()) jsonResponse("error", "Payment is not live for your program.", [], 403);

        $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ?");
        $stmt->execute([$studentId]);
        if ((int)$stmt->fetchColumn() !== 1) jsonResponse("error", "Student not approved for payment.", [], 403);

        // Insert as pending
        $stmt = $pdo->prepare("INSERT INTO payments (student_id, amount, payment_received, payment_status, payment_date, total_fee, pending_amount)
                               VALUES (?, ?, 0, 'pending', NOW(), ?, ?)");
        $stmt->execute([$studentId, $amount, $totalFee, $remaining - $amount]);

        $logger->info('Student payment submitted', ['student_id' => $studentId, 'amount' => $amount]);
        jsonResponse("success", "Payment submitted successfully. Awaiting verification.", [], 200);
    }

    // -------------------------------------------
    // 🔄 PUT — Update Payment Status
    // -------------------------------------------
    elseif ($method === 'PUT') {
        $user = authenticate();
        $role = $user->role ?? 'guest';
        $data = json_decode(file_get_contents("php://input"), true);
        $action = strtolower(trim($data['action'] ?? ''));
        $paymentId = (int)($data['payment_id'] ?? 0);
        $amount = (float)($data['amount'] ?? 0);

        if ($paymentId <= 0) jsonResponse("error", "Invalid or missing payment ID.", [], 400);

        if ($role === 'student') {
            $stmt = $pdo->prepare("UPDATE payments SET payment_status = 'processing' WHERE id = ? AND student_id = ? AND payment_status = 'pending'");
            $stmt->execute([$paymentId, $user->id]);
            if ($stmt->rowCount() === 0) jsonResponse("error", "Payment not found or already processed.", [], 404);

            $stmt = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
            $stmt->execute([$user->id, "Payment of ₹$amount submitted. Awaiting admin verification."]);

            $logger->info('Student payment moved to processing', ['payment_id' => $paymentId, 'student_id' => $user->id]);
            jsonResponse("success", "Payment marked as processing. Awaiting admin verification.", [], 200);
        }

        if ($role === 'admin') {
            if ($action === 'confirm') {
                $stmt = $pdo->prepare("UPDATE payments SET payment_status = 'paid', payment_received = 1, pending_amount = 0 WHERE id = ? AND payment_status = 'pending'");
                $stmt->execute([$paymentId]);
                if ($stmt->rowCount() === 0) jsonResponse("error", "Payment not found or not pending.", [], 404);

                $stmt = $pdo->prepare("SELECT student_id, amount FROM payments WHERE id = ?");
                $stmt->execute([$paymentId]);
                $p = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
                $stmt->execute([$p['student_id'], "Your payment of ₹{$p['amount']} has been confirmed by admin."]);

                $logger->info('Admin confirmed payment', ['payment_id' => $paymentId, 'student_id' => $p['student_id']]);
                jsonResponse("success", "Payment confirmed successfully.", [], 200);
            }

            // Default admin approval for 'processing'
            $stmt = $pdo->prepare("UPDATE payments SET payment_status = 'paid', payment_received = 1 WHERE id = ? AND payment_status = 'processing'");
            $stmt->execute([$paymentId]);
            if ($stmt->rowCount() === 0) jsonResponse("error", "Payment not found or not processing.", [], 404);

            $stmt = $pdo->prepare("SELECT student_id, amount FROM payments WHERE id = ?");
            $stmt->execute([$paymentId]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
            $stmt->execute([$p['student_id'], "Your payment of ₹{$p['amount']} has been approved by admin."]);

            $logger->info('Admin approved processing payment', ['payment_id' => $paymentId, 'student_id' => $p['student_id']]);
            jsonResponse("success", "Payment approved successfully.", [], 200);
        }

        jsonResponse("error", "Unauthorized role.", [], 403);
    }

    // ---------------------------
    // 🚫 Unsupported method
    // ---------------------------
    else {
        $logger->warning('Payment method not allowed', ['method' => $method]);
        jsonResponse("error", "Method not allowed.", [], 405);
    }
} catch (Exception $e) {
    $logger->error('Payment endpoint error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    jsonResponse("error", "Internal server error: " . $e->getMessage(), [], 500);
}

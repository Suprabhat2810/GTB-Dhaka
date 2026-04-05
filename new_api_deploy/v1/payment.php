<?php
// payment.php — hardened, logged, enterprise-grade replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/WhatsAppService.php';

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
            // Check if session columns exist
            $stmt = $pdo->query("SHOW COLUMNS FROM payments LIKE 'session_id'");
            $sessionColumnsExist = $stmt->rowCount() > 0;
            
            if ($sessionColumnsExist) {
                // New query with session support
                $stmt = $pdo->prepare("
                    SELECT p.id, p.student_id, s.name AS student_name, s.program,
                           p.amount, p.payment_date, p.payment_received, p.payment_status,
                           p.pending_amount, p.total_fee, p.session_id, p.remaining_after_payment,
                           ps.session_number, ps.total_fee as session_fee
                    FROM payments p
                    JOIN students s ON p.student_id = s.id
                    LEFT JOIN payment_sessions ps ON p.session_id = ps.id
                    ORDER BY p.payment_date DESC
                ");
            } else {
                // Legacy query without session support
                $stmt = $pdo->prepare("
                    SELECT p.id, p.student_id, s.name AS student_name, s.program,
                           p.amount, p.payment_date, p.payment_received, p.payment_status,
                           p.pending_amount, p.total_fee
                    FROM payments p
                    JOIN students s ON p.student_id = s.id
                    ORDER BY p.payment_date DESC
                ");
            }
            $stmt->execute();
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get student-specific assigned fees (NEW: uses fee versioning)
            $stmt = $pdo->prepare("
                SELECT s.id as student_id, s.program,
                       COALESCE(sfa.total_fee, fs.total_fee) as assigned_fee,
                       fs.is_live, fs.updated_at,
                       sfa.assignment_type, sfa.assigned_date
                FROM students s
                LEFT JOIN student_fee_assignments sfa ON sfa.student_id = s.id AND sfa.program COLLATE utf8mb4_general_ci = s.program COLLATE utf8mb4_general_ci
                LEFT JOIN fee_settings fs ON fs.program COLLATE utf8mb4_general_ci = s.program COLLATE utf8mb4_general_ci AND fs.is_active = 1
                WHERE s.final_registration_number IS NOT NULL
            ");
            $stmt->execute();
            $studentAssignedFees = [];
            $feeSettings = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $studentAssignedFees[$row['student_id']] = (float)$row['assigned_fee'];
                if (!isset($feeSettings[$row['program']])) {
                    $feeSettings[$row['program']] = [
                        'total_fee' => (float)$row['assigned_fee'],
                        'is_live' => (bool)$row['is_live'],
                        'period_start' => $row['updated_at']
                    ];
                }
            }

            // Group payments by student and calculate cumulative paid amounts
            $studentPayments = [];
            foreach ($payments as $p) {
                $studentPayments[$p['student_id']][] = $p;
            }

            $grandTotalPaid = 0;
            $formattedPayments = [];

            foreach ($studentPayments as $studentId => $studentPaymentList) {
                $currentFee = $studentAssignedFees[$studentId] ?? 110000;
                $cumulativePaid = 0;

                // Process payments in chronological order (oldest first) to calculate cumulative
                $sortedPayments = $studentPaymentList;
                usort($sortedPayments, function($a, $b) {
                    return strtotime($a['payment_date']) - strtotime($b['payment_date']);
                });

                foreach ($sortedPayments as $p) {
                    $paymentAmount = (float)$p['amount'];
                    
                    // Add this payment to cumulative if paid
                    if ($p['payment_status'] === 'paid') {
                        $cumulativePaid += $paymentAmount;
                        $grandTotalPaid += $paymentAmount;
                    }
                    
                    // Calculate remaining AFTER this payment (for payment details modal)
                    $remainingAfterPayment = max(0, $currentFee - $cumulativePaid);

                    $formattedPayments[] = [
                        'id' => $p['id'],
                        'student_id' => $p['student_id'],
                        'student_name' => $p['student_name'],
                        'program' => $p['program'],
                        'amount' => $paymentAmount,
                        'payment_date' => $p['payment_date'],
                        'payment_received' => (bool)$p['payment_received'],
                        'payment_status' => $p['payment_status'],
                        'pending_amount' => $remainingAfterPayment, // Remaining AFTER this payment (for modal)
                        'remaining_after_payment' => isset($p['remaining_after_payment']) ? (float)$p['remaining_after_payment'] : $remainingAfterPayment,
                        'total_fee' => $currentFee,
                        'session_id' => isset($p['session_id']) ? (int)$p['session_id'] : null,
                        'session_number' => isset($p['session_number']) ? (int)$p['session_number'] : null,
                        'session_fee' => isset($p['session_fee']) ? (float)$p['session_fee'] : $currentFee
                    ];
                }
            }

            // Sort back to descending order by date for display
            usort($formattedPayments, function($a, $b) {
                return strtotime($b['payment_date']) - strtotime($a['payment_date']);
            });

            // Get ALL approved students with registration numbers (including those with no payments)
            $stmt = $pdo->prepare("
                SELECT s.id, s.name, s.program, s.final_registration_number
                FROM students s
                JOIN approvals a ON s.id = a.student_id
                WHERE a.approved = 1 
                AND s.final_registration_number IS NOT NULL 
                AND s.final_registration_number != ''
            ");
            $stmt->execute();
            $allApprovedStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate current pending per student (for summary cards)
            // This is separate from pending_amount in each payment row
            $studentCurrentPending = [];
            
            // First, calculate for students who have made payments
            foreach ($studentPayments as $studentId => $studentPaymentList) {
                $currentFee = $studentAssignedFees[$studentId] ?? 0;
                
                // If no fee found, try to get from student_fee_assignments or fee_settings
                if ($currentFee == 0) {
                    $program = $studentPaymentList[0]['program'];
                    $stmtFee = $pdo->prepare("
                        SELECT COALESCE(sfa.total_fee, fs.total_fee) as assigned_fee
                        FROM students s
                        LEFT JOIN student_fee_assignments sfa ON sfa.student_id = s.id AND sfa.program = s.program
                        LEFT JOIN fee_settings fs ON fs.program = s.program AND fs.is_active = 1
                        WHERE s.id = ?
                        LIMIT 1
                    ");
                    $stmtFee->execute([$studentId]);
                    $currentFee = (float)($stmtFee->fetchColumn() ?: 0);
                }
                
                $totalPaidForStudent = 0;
                
                foreach ($studentPaymentList as $p) {
                    if ($p['payment_status'] === 'paid') {
                        $totalPaidForStudent += (float)$p['amount'];
                    }
                }
                
                $studentCurrentPending[$studentId] = max(0, $currentFee - $totalPaidForStudent);
            }

            // Then, add students who haven't made any payments yet
            foreach ($allApprovedStudents as $student) {
                if (!isset($studentCurrentPending[$student['id']])) {
                    // Get student-specific assigned fee
                    $currentFee = $studentAssignedFees[$student['id']] ?? 0;
                    
                    // If no fee found, try to get from student_fee_assignments or fee_settings
                    if ($currentFee == 0) {
                        $stmtFee = $pdo->prepare("
                            SELECT COALESCE(sfa.total_fee, fs.total_fee) as assigned_fee
                            FROM students s
                            LEFT JOIN student_fee_assignments sfa ON sfa.student_id = s.id AND sfa.program = s.program
                            LEFT JOIN fee_settings fs ON fs.program = s.program AND fs.is_active = 1
                            WHERE s.id = ?
                            LIMIT 1
                        ");
                        $stmtFee->execute([$student['id']]);
                        $currentFee = (float)($stmtFee->fetchColumn() ?: 0);
                    }
                    
                    $studentCurrentPending[$student['id']] = $currentFee; // Full fee pending
                    
                    // Only add virtual entry if there's a fee to pay
                    if ($currentFee > 0) {
                        // Add a virtual "no payment" entry for frontend display
                        $formattedPayments[] = [
                            'id' => null,
                            'student_id' => $student['id'],
                            'student_name' => $student['name'],
                            'program' => $student['program'],
                            'amount' => 0,
                            'payment_date' => null,
                            'payment_received' => false,
                            'payment_status' => 'not_started',
                            'pending_amount' => $currentFee,
                            'total_fee' => $currentFee,
                            'current_pending' => $currentFee,
                            'registration_number' => $student['final_registration_number']
                        ];
                    }
                }
            }

            // Add current_pending to each payment (for frontend summary calculations)
            // ALWAYS show the actual pending amount, regardless of live status
            foreach ($formattedPayments as &$payment) {
                $program = $payment['program'];
                $isLive = $feeSettings[$program]['is_live'] ?? false;
                
                if ($payment['id'] !== null) { // Only for actual payments
                    // Always show actual pending amount
                    $payment['current_pending'] = $studentCurrentPending[$payment['student_id']] ?? 0;
                } 
                // For not_started students, current_pending is already set correctly
                
                // Add payment period info
                $payment['payment_period_start'] = $feeSettings[$program]['period_start'] ?? null;
                $payment['is_payment_live'] = $isLive;
            }
            unset($payment);

            $data = [
                'payments' => $formattedPayments, 
                'total_paid' => $grandTotalPaid,
                'fee_settings' => $feeSettings
            ];

            $logger->info('Payments GET completed (admin)', ['count' => count($payments), 'duration_ms' => round((microtime(true) - $start) * 1000, 2)]);
            jsonResponse("success", "Payments retrieved successfully.", $data, 200);
        }

        // 🎓 Student flow
        $student_id = (int)($user->sub ?? $user->id ?? 0);;
        
        // Get student-specific assigned fee (NEW: uses fee versioning)
        $stmt = $pdo->prepare("
            SELECT COALESCE(sfa.total_fee, fs.total_fee) as assigned_fee
            FROM students s
            LEFT JOIN student_fee_assignments sfa ON sfa.student_id = s.id AND sfa.program COLLATE utf8mb4_general_ci = s.program COLLATE utf8mb4_general_ci
            LEFT JOIN fee_settings fs ON fs.program COLLATE utf8mb4_general_ci = s.program COLLATE utf8mb4_general_ci AND fs.is_active = 1
            WHERE s.id = ?
            LIMIT 1
        ");
        $stmt->execute([$student_id]);
        $currentTotalFee = (float)($stmt->fetchColumn() ?: 110000);
        
        // Get all payments with their individual total_fee snapshots
        $stmt = $pdo->prepare("
            SELECT 
                id,
                amount, 
                payment_date, 
                payment_received, 
                payment_status, 
                total_fee,
                pending_amount
            FROM payments 
            WHERE student_id = ?
            ORDER BY payment_date DESC
        ");
        $stmt->execute([$student_id]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate total paid (only from 'paid' status)
        $totalPaid = 0;
        foreach ($payments as &$payment) {
            if ($payment['payment_status'] === 'paid') {
                $totalPaid += (float)$payment['amount'];
            }
            // Calculate individual pending for this payment row
            // (for display purposes, based on the fee at time of payment)
            $paymentTotalFee = (float)($payment['total_fee'] ?: $currentTotalFee);
            $payment['pending_amount'] = max(0, $paymentTotalFee - (float)$payment['amount']);
        }
        unset($payment); // Break reference

        // Calculate ACTUAL remaining fee based on CURRENT fee settings
        $remainingFee = max(0, $currentTotalFee - $totalPaid);
        $totalFee = $currentTotalFee;
        $canPay = $remainingFee > 0;

        // Check if payment is live (use active version)
        $stmt = $pdo->prepare("
            SELECT fs.is_live, fs.program, s.program as student_program
            FROM fee_settings fs
            JOIN students s ON s.program COLLATE utf8mb4_general_ci = fs.program COLLATE utf8mb4_general_ci
            WHERE s.id = ? AND fs.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$student_id]);
        $liveData = $stmt->fetch(PDO::FETCH_ASSOC);
        $isLiveResult = $liveData['is_live'] ?? false;
        $isLive = ($isLiveResult !== false && (int)$isLiveResult === 1);
        
        $logger->info('Payment live status check', [
            'student_id' => $student_id,
            'is_live_result' => $isLiveResult,
            'is_live' => $isLive,
            'program' => $liveData['program'] ?? 'NOT_FOUND',
            'student_program' => $liveData['student_program'] ?? 'NOT_FOUND'
        ]);

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
            'total_fee' => $totalFee, // Current fee from fee_settings
            'remaining_fee' => $remainingFee, // Current fee - total paid
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

            // Check if session columns exist
            $stmt = $pdo->query("SHOW COLUMNS FROM payments LIKE 'session_id'");
            $sessionColumnsExist = $stmt->rowCount() > 0;

            if ($sessionColumnsExist) {
                // Get student's program to find active session
                $stmt = $pdo->prepare("SELECT program FROM students WHERE id = ?");
                $stmt->execute([$studentId]);
                $studentProgram = $stmt->fetchColumn();

                // Check if payment_sessions table exists
                $stmt = $pdo->query("SHOW TABLES LIKE 'payment_sessions'");
                $sessionTableExists = $stmt->rowCount() > 0;

                $sessionId = null;
                $sessionFee = $amount;

                if ($sessionTableExists) {
                    // Get active session for this program
                    $stmt = $pdo->prepare("
                        SELECT id, total_fee FROM payment_sessions 
                        WHERE program = ? AND is_active = 1
                        ORDER BY started_at DESC LIMIT 1
                    ");
                    $stmt->execute([$studentProgram]);
                    $activeSession = $stmt->fetch(PDO::FETCH_ASSOC);
                    $sessionId = $activeSession ? $activeSession['id'] : null;
                    $sessionFee = $activeSession ? $activeSession['total_fee'] : $amount;
                }

                // Calculate total paid so far for this student
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(amount), 0) as total_paid 
                    FROM payments 
                    WHERE student_id = ? AND payment_status = 'paid'
                ");
                $stmt->execute([$studentId]);
                $totalPaid = (float)$stmt->fetchColumn();

                // Calculate remaining after this payment
                $remainingAfter = max(0, $sessionFee - ($totalPaid + $amount));

                $stmt = $pdo->prepare("
                    INSERT INTO payments 
                    (student_id, amount, payment_received, payment_status, payment_date, total_fee, pending_amount, session_id, remaining_after_payment) 
                    VALUES (?, ?, 1, 'paid', NOW(), ?, 0, ?, ?)
                ");
                $stmt->execute([$studentId, $amount, $sessionFee, $sessionId, $remainingAfter]);
            } else {
                // Legacy insert without session support
                $stmt = $pdo->prepare("
                    INSERT INTO payments 
                    (student_id, amount, payment_received, payment_status, payment_date, total_fee, pending_amount) 
                    VALUES (?, ?, 1, 'paid', NOW(), ?, 0)
                ");
                $stmt->execute([$studentId, $amount, $amount]);
            }

            // Send WhatsApp payment confirmation (non-breaking)
            try {
                $stmt = $pdo->prepare("SELECT name, phone, semester FROM students WHERE id = ? LIMIT 1");
                $stmt->execute([$studentId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($student && !empty($student['phone'])) {
                    $whatsappService = new WhatsAppService($logger);
                    if ($whatsappService->isEnabled()) {
                        $paymentDate = date('d M Y');
                        $transactionId = 'TXN' . time() . $studentId;
                        $semester = $student['semester'] ?? 1;
                        
                        $whatsappService->sendPaymentConfirmation(
                            $student['phone'],
                            $student['name'],
                            $amount,
                            "Semester {$semester}",
                            $transactionId,
                            $paymentDate
                        );
                        $logger->info('Payment WhatsApp sent', ['student_id' => $studentId, 'amount' => $amount]);
                    }
                }
            } catch (Exception $e) {
                // Silent failure - payment recording continues normally
                $logger->error('WhatsApp payment notification error (non-critical)', [
                    'error' => $e->getMessage(),
                    'student_id' => $studentId
                ]);
            }

            $logger->info('Admin recorded payment', ['student_id' => $studentId, 'amount' => $amount, 'actor' => $user->id ?? null]);
            jsonResponse("success", "Payment recorded successfully.", [], 200);
        }

        // Student payment initiation
        $studentId = (int)($user->sub ?? $user->id ?? 0);
        if (!$studentId) {
            $logger->error('Invalid student ID from token', ['user' => $user]);
            jsonResponse("error", "Invalid student authentication.", [], 401);
        }
        $stmt = $pdo->prepare("
            SELECT 
                MAX(total_fee) as total_fee, 
                COALESCE(SUM(amount), 0) as total_paid
            FROM payments 
            WHERE student_id = ? AND payment_status = 'paid'
            GROUP BY student_id
        ");
        $stmt->execute([$studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalFee = $row['total_fee'] ?? 110000;
        $totalPaid = (float)($row['total_paid'] ?? 0);
        $remaining = max(0, $totalFee - $totalPaid);
        if ($amount > $remaining) jsonResponse("error", "Amount exceeds remaining fee (₹$remaining).", [], 400);

        $stmt = $pdo->prepare("
            SELECT fs.is_live, fs.program, s.program as student_program
            FROM fee_settings fs
            JOIN students s ON s.program COLLATE utf8mb4_general_ci = fs.program COLLATE utf8mb4_general_ci
            WHERE s.id = ? 
            ORDER BY fs.updated_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$studentId]);
        $liveData = $stmt->fetch(PDO::FETCH_ASSOC);
        $isLiveResult = $liveData['is_live'] ?? false;
        $isLive = ($isLiveResult !== false && (int)$isLiveResult === 1);
        
        $logger->info('Student payment initiation - live check', [
            'student_id' => $studentId,
            'is_live_result' => $isLiveResult,
            'is_live' => $isLive,
            'program' => $liveData['program'] ?? 'NOT_FOUND',
            'student_program' => $liveData['student_program'] ?? 'NOT_FOUND'
        ]);
        
        if (!$isLive) {
            $logger->warning('Payment not live for student', [
                'student_id' => $studentId,
                'program' => $liveData['student_program'] ?? 'UNKNOWN'
            ]);
            jsonResponse("error", "Payment is not live for your program.", [], 403);
        }

        $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ?");
        $stmt->execute([$studentId]);
        if ((int)$stmt->fetchColumn() !== 1) jsonResponse("error", "Student not approved for payment.", [], 403);

        // Check if session columns exist
        $stmt = $pdo->query("SHOW COLUMNS FROM payments LIKE 'session_id'");
        $sessionColumnsExist = $stmt->rowCount() > 0;

        if ($sessionColumnsExist) {
            // Check if payment_sessions table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'payment_sessions'");
            $sessionTableExists = $stmt->rowCount() > 0;

            $sessionId = null;
            $sessionFee = $totalFee;

            if ($sessionTableExists) {
                // Get active session for student's program
                $stmt = $pdo->prepare("
                    SELECT ps.id, ps.total_fee 
                    FROM payment_sessions ps
                    JOIN students s ON s.program COLLATE utf8mb4_general_ci = ps.program COLLATE utf8mb4_general_ci
                    WHERE s.id = ? AND ps.is_active = 1
                    ORDER BY ps.started_at DESC LIMIT 1
                ");
                $stmt->execute([$studentId]);
                $activeSession = $stmt->fetch(PDO::FETCH_ASSOC);
                $sessionId = $activeSession ? $activeSession['id'] : null;
                $sessionFee = $activeSession ? $activeSession['total_fee'] : $totalFee;
            }

            // Calculate remaining after this payment (assuming it will be paid)
            $remainingAfter = max(0, $sessionFee - ($totalPaid + $amount));

            // Insert as pending
            $stmt = $pdo->prepare("
                INSERT INTO payments 
                (student_id, amount, payment_received, payment_status, payment_date, total_fee, pending_amount, session_id, remaining_after_payment)
                VALUES (?, ?, 0, 'pending', NOW(), ?, ?, ?, ?)
            ");
            $stmt->execute([$studentId, $amount, $sessionFee, $remaining - $amount, $sessionId, $remainingAfter]);
        } else {
            // Legacy insert without session support
            $stmt = $pdo->prepare("
                INSERT INTO payments 
                (student_id, amount, payment_received, payment_status, payment_date, total_fee, pending_amount)
                VALUES (?, ?, 0, 'pending', NOW(), ?, ?)
            ");
            $stmt->execute([$studentId, $amount, $totalFee, $remaining - $amount]);
        }

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

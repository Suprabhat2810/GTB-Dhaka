<?php
// payment.php — hardened, logged, enterprise-grade replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/WhatsAppService.php';
require_once __DIR__ . '/business_rules.php';
require_once __DIR__ . '/api_logger_middleware.php';
require_once __DIR__ . '/../services/AuditService.php';

$logger = getLogger('payment');
$pdo = getPDO();

// Initialize API logger
$apiLogger = createAPILogger($pdo, $logger);
$apiLogger->start();
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
        $action = $_GET['action'] ?? 'default';
        $logger->info('Payments GET initiated', ['actor_role' => $role, 'actor_id' => $user->id ?? null, 'action' => $action]);

        if ($role === 'admin') {
            // NEW: Student cards view for admin dashboard
            if ($action === 'student_cards') {
                $stmt = $pdo->prepare("
                    SELECT 
                        s.id,
                        s.name,
                        s.program,
                        s.semester,
                        s.final_registration_number,
                        s.fee_clearance_status,
                        s.pending_fee_amount,
                        s.can_be_promoted,
                        ac.semester_name,
                        ac.semester_number,
                        sf.total_fee,
                        sf.paid_amount,
                        sf.pending_amount as semester_pending,
                        sf.fee_status
                    FROM students s
                    LEFT JOIN academic_calendar ac ON s.current_semester_id = ac.id
                    LEFT JOIN semester_fees sf ON s.id = sf.student_id AND s.current_semester_id = sf.semester_id
                    WHERE s.final_registration_number IS NOT NULL
                    ORDER BY s.name ASC
                ");
                $stmt->execute();
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $logger->info('Student cards retrieved', ['count' => count($students)]);
                jsonResponse("success", "Student cards retrieved successfully.", ['students' => $students], 200);
                exit;
            }
            
            // NEW: Semester-wise payments for a specific student
            if ($action === 'student_semesters' && isset($_GET['student_id'])) {
                $studentId = (int)$_GET['student_id'];
                
                // Get student info
                $stmt = $pdo->prepare("
                    SELECT s.id, s.name, s.program, s.current_semester_id
                    FROM students s
                    WHERE s.id = ?
                ");
                $stmt->execute([$studentId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$student) {
                    jsonResponse("error", "Student not found.", [], 404);
                    exit;
                }
                
                // Get all semesters for this student
                $stmt = $pdo->prepare("
                    SELECT 
                        sf.semester_id,
                        sf.semester_number,
                        sf.semester_name,
                        sf.total_fee,
                        sf.paid_amount,
                        sf.pending_amount,
                        sf.fee_status,
                        sf.cleared_date,
                        CASE WHEN sf.semester_id = ? THEN 1 ELSE 0 END as is_current
                    FROM semester_fees sf
                    WHERE sf.student_id = ?
                    ORDER BY sf.semester_number ASC
                ");
                $stmt->execute([$student['current_semester_id'], $studentId]);
                $semesters = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $logger->info('Student semesters retrieved', ['student_id' => $studentId, 'semester_count' => count($semesters)]);
                jsonResponse("success", "Student semesters retrieved.", [
                    'student' => $student,
                    'semesters' => $semesters
                ], 200);
                exit;
            }
            
            // Existing admin payment list (keep for backward compatibility)
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
            exit; // IMPORTANT: Exit after admin response to prevent falling through to student code
        }

        // 🎓 Student flow
        $student_id = (int)($user->sub ?? $user->id ?? 0);
        
        // Calculate current fee status using business rules
        $feeStatusResult = calculateFeeStatus($student_id, $pdo, $logger);
        
        if ($feeStatusResult['status'] === 'error') {
            http_response_code(400);
            jsonResponse("error", $feeStatusResult['message'], [], 400);
            exit;
        }
        
        // Check if student can pay
        $paymentCheck = canStudentPay($student_id, $pdo, $logger);
        
        // Check if payment_sessions table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'payment_sessions'");
        $sessionTableExists = $stmt->rowCount() > 0;
        
        $useSessionBasedCalculation = false;
        $activeSession = null;
        $currentTotalFee = $feeStatusResult['current_semester']['total_fee'];
        $totalPaid = $feeStatusResult['current_semester']['paid_amount'];
        $statusMessage = null;
        
        // Try to get active payment session for student's program
        if ($sessionTableExists) {
            $stmt = $pdo->prepare("
                SELECT ps.id, ps.total_fee, ps.session_number, ps.program
                FROM payment_sessions ps
                JOIN students s ON s.program COLLATE utf8mb4_general_ci = ps.program COLLATE utf8mb4_general_ci
                WHERE s.id = ? AND ps.is_active = 1
                ORDER BY ps.started_at DESC
                LIMIT 1
            ");
            $stmt->execute([$student_id]);
            $activeSession = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($activeSession) {
                $useSessionBasedCalculation = true;
                $currentTotalFee = (float)$activeSession['total_fee'];
                
                $logger->info('Using session-based fee calculation', [
                    'student_id' => $student_id,
                    'session_id' => $activeSession['id'],
                    'session_number' => $activeSession['session_number'],
                    'session_fee' => $currentTotalFee
                ]);
            }
        }
        
        // Fallback to fee versioning system if no active session
        if (!$useSessionBasedCalculation) {
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
            
            $logger->info('Using fee versioning calculation', [
                'student_id' => $student_id,
                'assigned_fee' => $currentTotalFee
            ]);
        }
        
        // Get all payments with semester information and correct semester fee
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.student_id,
                s.name as student_name,
                s.program,
                p.amount, 
                p.payment_date, 
                p.payment_received, 
                p.payment_status, 
                COALESCE(sf.total_fee, p.total_fee) as total_fee,
                p.pending_amount,
                p.session_id,
                p.semester_id,
                ac.semester_name,
                ac.semester_number
            FROM payments p
            LEFT JOIN students s ON p.student_id = s.id
            LEFT JOIN academic_calendar ac ON p.semester_id = ac.id
            LEFT JOIN semester_fees sf ON sf.student_id = p.student_id AND sf.semester_id = p.semester_id
            WHERE p.student_id = ?
            ORDER BY ac.semester_number DESC, p.payment_date DESC
        ");
        $stmt->execute([$student_id]);
        $allPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group payments by semester
        $semesterPayments = [];
        foreach ($allPayments as $payment) {
            $semesterId = $payment['semester_id'] ?? 'unknown';
            $semesterName = $payment['semester_name'] ?? 'Unknown Semester';
            
            if (!isset($semesterPayments[$semesterId])) {
                $semesterPayments[$semesterId] = [
                    'semester_id' => $semesterId,
                    'semester_name' => $semesterName,
                    'semester_number' => $payment['semester_number'] ?? 0,
                    'payments' => []
                ];
            }
            $semesterPayments[$semesterId]['payments'][] = $payment;
        }
        
        // Filter payments for current view (session-based or all)
        if ($useSessionBasedCalculation) {
            $payments = array_filter($allPayments, function($p) use ($activeSession) {
                return $p['session_id'] == $activeSession['id'];
            });
        } else {
            $payments = $allPayments;
        }

        // Calculate total paid (only from 'paid' status)
        $totalPaid = 0;
        foreach ($payments as &$payment) {
            if ($payment['payment_status'] === 'paid') {
                $totalPaid += (float)$payment['amount'];
            }
            // Calculate individual pending for this payment row
            $paymentTotalFee = (float)($payment['total_fee'] ?: $currentTotalFee);
            $payment['pending_amount'] = max(0, $paymentTotalFee - (float)$payment['amount']);
        }
        unset($payment); // Break reference

        // Calculate remaining fee
        $remainingFee = max(0, $currentTotalFee - $totalPaid);
        $totalFee = $currentTotalFee;
        $canPay = $remainingFee > 0;

        // Check if payment is live
        // If using session-based calculation, session being active means payment is live
        // Otherwise, check fee_settings.is_live
        $isLive = false;
        
        if ($useSessionBasedCalculation) {
            // Session is active, so payment is live
            $isLive = true;
            $logger->info('Payment live status check (session-based)', [
                'student_id' => $student_id,
                'session_id' => $activeSession['id'],
                'is_live' => true,
                'reason' => 'Active payment session exists'
            ]);
        } else {
            // Check fee_settings.is_live
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
            
            $logger->info('Payment live status check (fee versioning)', [
                'student_id' => $student_id,
                'is_live_result' => $isLiveResult,
                'is_live' => $isLive,
                'program' => $liveData['program'] ?? 'NOT_FOUND',
                'student_program' => $liveData['student_program'] ?? 'NOT_FOUND'
            ]);
        }

        if (!$isLive) {
            $canPay = false;
            // Only set generic message if we don't have a better status message
            if (!$statusMessage) {
                $data['payment_not_live_message'] = "Payment is not live for your program at this time.";
            }
        }

        // Ensure student approved
        $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ?");
        $stmt->execute([$student_id]);
        $approved = (int)$stmt->fetchColumn();
        if ($approved !== 1) {
            $canPay = false;
            $data['approval_message'] = "You must be approved before payment.";
        }

        // Get student's current semester for display
        $stmt = $pdo->prepare("SELECT semester FROM students WHERE id = ?");
        $stmt->execute([$student_id]);
        $currentSemester = (int)($stmt->fetchColumn() ?: 1);
        
        // Build session info if using session-based calculation
        $sessionInfo = null;
        $previousSessions = [];
        
        if ($useSessionBasedCalculation && $activeSession) {
            $sessionInfo = [
                'session_id' => (int)$activeSession['id'],
                'session_number' => (int)$activeSession['session_number'],
                'session_fee' => (float)$activeSession['total_fee'],
                'is_active' => true,
                'program' => $activeSession['program']
            ];
            
            // Get previous sessions summary
            $stmt = $pdo->prepare("
                SELECT 
                    ps.id,
                    ps.session_number,
                    ps.total_fee,
                    COALESCE(SUM(CASE WHEN p.payment_status = 'paid' THEN p.amount ELSE 0 END), 0) as total_paid,
                    ps.started_at,
                    ps.stopped_at
                FROM payment_sessions ps
                LEFT JOIN payments p ON p.session_id = ps.id AND p.student_id = ?
                WHERE ps.program COLLATE utf8mb4_general_ci = ? AND ps.id != ?
                GROUP BY ps.id, ps.session_number, ps.total_fee, ps.started_at, ps.stopped_at
                ORDER BY ps.session_number ASC
            ");
            $stmt->execute([$student_id, $activeSession['program'], $activeSession['id']]);
            $previousSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Generate smart status message
            if ($remainingFee == 0 && count($previousSessions) > 0) {
                $lastSession = end($previousSessions);
                $statusMessage = sprintf(
                    "Already paid ₹%s for Session #%d. Session #%d payment is now open!",
                    number_format($lastSession['total_paid'], 0),
                    $lastSession['session_number'],
                    $activeSession['session_number']
                );
            } elseif ($remainingFee > 0) {
                $statusMessage = sprintf(
                    "Session #%d payment is open. Pay ₹%s to complete.",
                    $activeSession['session_number'],
                    number_format($remainingFee, 0)
                );
            }
        } else {
            // Fee versioning mode - generate appropriate message
            if ($remainingFee == 0) {
                $statusMessage = "Fully paid. Waiting for next payment session to open.";
            } elseif (!$isLive) {
                $statusMessage = "Payment is not currently available for your program.";
            }
        }
        
        $data = [
            'payments' => $payments,
            'total_paid' => $totalPaid,
            'total_fee' => $totalFee,
            'remaining_fee' => $remainingFee,
            'can_pay' => $paymentCheck['can_pay'],
            'payment_block_type' => $paymentCheck['block_type'] ?? null,
            'payment_block_reason' => $paymentCheck['reason'] ?? null,
            'action_required' => $paymentCheck['action_required'] ?? null,
            'current_semester' => $currentSemester,
            'session_info' => $sessionInfo,
            'previous_sessions' => $previousSessions,
            'status_message' => $statusMessage,
            'using_session_based_calculation' => $useSessionBasedCalculation,
            // New fee tracking data
            'fee_status' => $feeStatusResult['current_semester'] ?? null,
            'can_be_promoted' => $feeStatusResult['can_be_promoted'] ?? false,
            'clearance_status' => $feeStatusResult['clearance_status'] ?? 'pending',
            // NEW: Semester-grouped payments
            'semester_payments' => array_values($semesterPayments)
        ] + ($data ?? []);

        $logger->info('Payments GET completed (student)', [
            'student_id' => $student_id,
            'total_paid' => $totalPaid,
            'remaining' => $remainingFee,
            'can_pay' => $canPay,
            'session_based' => $useSessionBasedCalculation,
            'session_id' => $sessionInfo['session_id'] ?? null
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

            // Get student's current semester
            $stmt = $pdo->prepare("SELECT current_semester_id, program FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            $studentData = $stmt->fetch(PDO::FETCH_ASSOC);
            $currentSemesterId = $studentData['current_semester_id'];
            $studentProgram = $studentData['program'];

            if ($sessionColumnsExist) {
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
                    (student_id, semester_id, amount, payment_received, payment_status, payment_date, total_fee, pending_amount, session_id, remaining_after_payment) 
                    VALUES (?, ?, ?, 1, 'paid', NOW(), ?, 0, ?, ?)
                ");
                $stmt->execute([$studentId, $currentSemesterId, $amount, $sessionFee, $sessionId, $remainingAfter]);
            } else {
                // Legacy insert without session support
                $stmt = $pdo->prepare("
                    INSERT INTO payments 
                    (student_id, amount, payment_received, payment_status, payment_date, total_fee, pending_amount) 
                    VALUES (?, ?, 1, 'paid', NOW(), ?, 0)
                ");
                $stmt->execute([$studentId, $amount, $amount]);
            }

            // Update fee status after payment
            updateFeeStatusAfterPayment($studentId, $currentSemesterId, $amount, $pdo, $logger);

            // Send WhatsApp payment confirmation (non-breaking)
            try {
                $stmt = $pdo->prepare("SELECT name, phone, semester FROM students WHERE id = ? LIMIT 1");
                $stmt->execute([$studentId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($student && !empty($student['phone'])) {
                    $whatsappService = new WhatsAppService($logger, $pdo);
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
        
        // Get student's current semester
        $stmt = $pdo->prepare("SELECT current_semester_id FROM students WHERE id = ?");
        $stmt->execute([$studentId]);
        $currentSemesterId = $stmt->fetchColumn();
        
        if (!$currentSemesterId) {
            jsonResponse("error", "Student semester not found. Contact admin.", [], 400);
        }
        
        // Check if student can pay using business rules
        $paymentCheck = canStudentPay($studentId, $pdo, $logger);
        
        if (!$paymentCheck['can_pay']) {
            jsonResponse("error", $paymentCheck['reason'], [
                'block_type' => $paymentCheck['block_type'],
                'action_required' => $paymentCheck['action_required']
            ], 403);
        }
        
        // Get fee status
        $feeStatus = calculateFeeStatus($studentId, $pdo, $logger);
        $remaining = $feeStatus['current_semester']['pending_amount'];
        
        if ($amount > $remaining) {
            jsonResponse("error", "Amount exceeds remaining fee (₹" . number_format($remaining, 0) . ").", [], 400);
        }

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
                (student_id, semester_id, amount, payment_received, payment_status, payment_date, total_fee, pending_amount, session_id, remaining_after_payment)
                VALUES (?, ?, ?, 0, 'pending', NOW(), ?, ?, ?, ?)
            ");
            $stmt->execute([$studentId, $currentSemesterId, $amount, $sessionFee, $remaining - $amount, $sessionId, $remainingAfter]);
        } else {
            // Legacy insert without session support
            $stmt = $pdo->prepare("
                INSERT INTO payments 
                (student_id, semester_id, amount, payment_received, payment_status, payment_date, total_fee, pending_amount)
                VALUES (?, ?, ?, 0, 'pending', NOW(), ?, ?)
            ");
            $stmt->execute([$studentId, $currentSemesterId, $amount, $totalFee, $remaining - $amount]);
        }

        // Get payment ID that was just inserted
        $paymentId = $pdo->lastInsertId();
        
        // Get student details for notification
        $stmt = $pdo->prepare("SELECT name, program, semester FROM students WHERE id = ?");
        $stmt->execute([$studentId]);
        $studentInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get current semester name
        $stmt = $pdo->prepare("SELECT semester_name FROM academic_calendar WHERE id = ?");
        $stmt->execute([$currentSemesterId]);
        $semesterName = $stmt->fetchColumn() ?: "Semester " . ($studentInfo['semester'] ?? '');
        
        // Create notification for all admins
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin'");
            $stmt->execute();
            $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $notificationTitle = "New Payment Pending Approval";
            $notificationMessage = sprintf(
                "%s submitted ₹%s for %s. Click to review.",
                $studentInfo['name'] ?? 'Student',
                number_format($amount, 0),
                $semesterName
            );
            
            foreach ($admins as $adminId) {
                $stmt = $pdo->prepare("
                    INSERT INTO notifications 
                    (user_id, user_type, title, message, type, related_id, is_read, created_at)
                    VALUES (?, 'admin', ?, ?, 'payment_pending', ?, 0, NOW())
                ");
                $stmt->execute([$adminId, $notificationTitle, $notificationMessage, $paymentId]);
            }
            
            $logger->info('Admin notifications created for payment', [
                'payment_id' => $paymentId,
                'student_id' => $studentId,
                'admin_count' => count($admins)
            ]);
        } catch (Exception $e) {
            // Non-critical - payment still recorded
            $logger->error('Failed to create admin notifications', [
                'error' => $e->getMessage(),
                'payment_id' => $paymentId
            ]);
        }
        
        $logger->info('Student payment submitted', ['student_id' => $studentId, 'amount' => $amount, 'payment_id' => $paymentId]);
        jsonResponse("success", "Payment submitted successfully. Awaiting verification.", ['payment_id' => $paymentId], 200);
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
            $transactionId = $data['transaction_id'] ?? null;
            
            // Update payment status and transaction ID
            if ($transactionId) {
                $stmt = $pdo->prepare("UPDATE payments SET payment_status = 'processing', transaction_id = ? WHERE id = ? AND student_id = ? AND payment_status = 'pending'");
                $stmt->execute([$transactionId, $paymentId, $user->id]);
            } else {
                $stmt = $pdo->prepare("UPDATE payments SET payment_status = 'processing' WHERE id = ? AND student_id = ? AND payment_status = 'pending'");
                $stmt->execute([$paymentId, $user->id]);
            }
            
            if ($stmt->rowCount() === 0) jsonResponse("error", "Payment not found or already processed.", [], 404);

            $stmt = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
            $stmt->execute([$user->id, "Payment of ₹$amount submitted. Awaiting admin verification."]);

            $logger->info('Student payment moved to processing', [
                'payment_id' => $paymentId, 
                'student_id' => $user->id,
                'transaction_id' => $transactionId
            ]);
            jsonResponse("success", "Payment marked as processing. Awaiting admin verification.", [], 200);
        }

        if ($role === 'admin') {
            // Handle payment approval
            if ($action === 'confirm' || $action === 'approve') {
                $stmt = $pdo->prepare("UPDATE payments SET payment_status = 'paid', payment_received = 1, pending_amount = 0 WHERE id = ? AND payment_status IN ('pending', 'processing')");
                $stmt->execute([$paymentId]);
                if ($stmt->rowCount() === 0) jsonResponse("error", "Payment not found or already processed.", [], 404);

                $stmt = $pdo->prepare("SELECT student_id, amount, semester_id FROM payments WHERE id = ?");
                $stmt->execute([$paymentId]);
                $p = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Update fee status after payment confirmation
                updateFeeStatusAfterPayment($p['student_id'], $p['semester_id'], $p['amount'], $pdo, $logger);
                
                // Mark all admin notifications for this payment as read
                try {
                    $stmt = $pdo->prepare("
                        UPDATE notifications 
                        SET is_read = 1, read_at = NOW() 
                        WHERE related_id = ? AND type = 'payment_pending' AND user_type = 'admin'
                    ");
                    $stmt->execute([$paymentId]);
                } catch (Exception $e) {
                    $logger->error('Failed to mark notifications as read', ['error' => $e->getMessage()]);
                }
                
                // Send approval notification to student with enhanced details
                try {
                    $adminNotes = isset($data['admin_notes']) && !empty(trim($data['admin_notes'])) 
                        ? trim($data['admin_notes']) 
                        : null;
                    
                    // Build enhanced notification message
                    $approvalMessage = sprintf("Your payment of ₹%s has been confirmed by admin.", number_format($p['amount'], 0));
                    
                    // Build detailed body with payment info
                    $bodyParts = [];
                    $bodyParts[] = sprintf("• Transaction ID: %s", $p['transaction_id'] ?? 'N/A');
                    $bodyParts[] = sprintf("• Payment Date: %s", date('d M Y', strtotime($p['payment_date'])));
                    $bodyParts[] = sprintf("• Amount: ₹%s", number_format($p['amount'], 0));
                    
                    // Add remaining balance if available
                    if (isset($p['remaining_after_payment'])) {
                        $remaining = (float)$p['remaining_after_payment'];
                        if ($remaining > 0) {
                            $bodyParts[] = sprintf("• Remaining Balance: ₹%s", number_format($remaining, 0));
                        } else {
                            $bodyParts[] = "• Fee Status: Fully Paid ✓";
                        }
                    }
                    
                    $bodyParts[] = "• Invoice: Available for download";
                    
                    // Add admin notes if provided
                    if ($adminNotes) {
                        $bodyParts[] = "\nAdmin Notes:\n" . $adminNotes;
                    }
                    
                    $notificationBody = implode("\n", $bodyParts);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO notifications 
                        (user_id, user_type, title, message, body, type, related_id, is_read, created_at)
                        VALUES (?, 'student', 'Payment Approved', ?, ?, 'payment_approved', ?, 0, NOW())
                    ");
                    $stmt->execute([$p['student_id'], $approvalMessage, $notificationBody, $paymentId]);
                } catch (Exception $e) {
                    $logger->error('Failed to create student notification', ['error' => $e->getMessage()]);
                }

                $logger->info('Admin approved payment', ['payment_id' => $paymentId, 'student_id' => $p['student_id'], 'admin_id' => $user->id]);
                
                // Audit logging (safe - wrapped in try-catch)
                try {
                    $audit = new AuditService($pdo, $logger);
                    $audit->logPayment(
                        $paymentId,
                        'approved',
                        ['payment_status' => 'pending'],
                        ['payment_status' => 'paid', 'admin_notes' => $adminNotes ?? null],
                        ['id' => $user->id, 'type' => 'admin']
                    );
                    $apiLogger->setUser($user->id, 'admin');
                } catch (Exception $e) {
                    $logger->warning('Audit logging failed (non-critical)', ['error' => $e->getMessage()]);
                }
                
                $apiLogger->end(200);
                jsonResponse("success", "Payment approved successfully.", [], 200);
            }
            
            // Handle payment rejection
            if ($action === 'reject') {
                $rejectionReason = trim($data['rejection_reason'] ?? 'Payment rejected by admin.');
                
                $stmt = $pdo->prepare("
                    UPDATE payments 
                    SET payment_status = 'rejected', 
                        payment_received = 0
                    WHERE id = ? AND payment_status IN ('pending', 'processing')
                ");
                $stmt->execute([$paymentId]);
                if ($stmt->rowCount() === 0) jsonResponse("error", "Payment not found or already processed.", [], 404);

                $stmt = $pdo->prepare("SELECT student_id, amount FROM payments WHERE id = ?");
                $stmt->execute([$paymentId]);
                $p = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Mark all admin notifications for this payment as read
                try {
                    $stmt = $pdo->prepare("
                        UPDATE notifications 
                        SET is_read = 1, read_at = NOW() 
                        WHERE related_id = ? AND type = 'payment_pending' AND user_type = 'admin'
                    ");
                    $stmt->execute([$paymentId]);
                } catch (Exception $e) {
                    $logger->error('Failed to mark notifications as read', ['error' => $e->getMessage()]);
                }
                
                // Send rejection notification to student with enhanced details
                try {
                    $rejectionMessage = sprintf("Your payment of ₹%s was rejected by admin.", number_format($p['amount'], 0));
                    
                    // Build detailed body with payment info and next steps
                    $bodyParts = [];
                    $bodyParts[] = "Rejection Reason:";
                    $bodyParts[] = $rejectionReason;
                    $bodyParts[] = "";
                    $bodyParts[] = "Payment Details:";
                    $bodyParts[] = sprintf("• Transaction ID: %s", $p['transaction_id'] ?? 'N/A');
                    $bodyParts[] = sprintf("• Amount: ₹%s", number_format($p['amount'], 0));
                    $bodyParts[] = sprintf("• Submitted on: %s", date('d M Y', strtotime($p['payment_date'])));
                    $bodyParts[] = "";
                    $bodyParts[] = "Next Steps:";
                    $bodyParts[] = "• Verify your transaction details";
                    $bodyParts[] = "• Resubmit payment with correct information";
                    $bodyParts[] = "• Contact admin if you need assistance";
                    
                    $notificationBody = implode("\n", $bodyParts);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO notifications 
                        (user_id, user_type, title, message, body, type, related_id, is_read, created_at)
                        VALUES (?, 'student', 'Payment Rejected', ?, ?, 'payment_rejected', ?, 0, NOW())
                    ");
                    $stmt->execute([$p['student_id'], $rejectionMessage, $notificationBody, $paymentId]);
                } catch (Exception $e) {
                    $logger->error('Failed to create student notification', ['error' => $e->getMessage()]);
                }

                $logger->info('Admin rejected payment', [
                    'payment_id' => $paymentId, 
                    'student_id' => $p['student_id'], 
                    'admin_id' => $user->id,
                    'reason' => $rejectionReason
                ]);
                
                // Audit logging (safe - wrapped in try-catch)
                try {
                    $audit = new AuditService($pdo, $logger);
                    $audit->logPayment(
                        $paymentId,
                        'rejected',
                        ['payment_status' => 'pending'],
                        ['payment_status' => 'rejected', 'rejection_reason' => $rejectionReason],
                        ['id' => $user->id, 'type' => 'admin']
                    );
                    $apiLogger->setUser($user->id, 'admin');
                } catch (Exception $e) {
                    $logger->warning('Audit logging failed (non-critical)', ['error' => $e->getMessage()]);
                }
                
                $apiLogger->end(200);
                jsonResponse("success", "Payment rejected successfully.", [], 200);
            }

            // Default admin approval for backward compatibility
            $stmt = $pdo->prepare("UPDATE payments SET payment_status = 'paid', payment_received = 1 WHERE id = ? AND payment_status IN ('pending', 'processing')");
            $stmt->execute([$paymentId]);
            if ($stmt->rowCount() === 0) jsonResponse("error", "Payment not found or already processed.", [], 404);

            $stmt = $pdo->prepare("SELECT student_id, amount, semester_id FROM payments WHERE id = ?");
            $stmt->execute([$paymentId]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Update fee status after payment approval
            updateFeeStatusAfterPayment($p['student_id'], $p['semester_id'], $p['amount'], $pdo, $logger);
            
            // Mark notifications as read
            try {
                $stmt = $pdo->prepare("
                    UPDATE notifications 
                    SET is_read = 1, read_at = NOW() 
                    WHERE related_id = ? AND type = 'payment_pending' AND user_type = 'admin'
                ");
                $stmt->execute([$paymentId]);
            } catch (Exception $e) {
                $logger->error('Failed to mark notifications as read', ['error' => $e->getMessage()]);
            }
            
            // Send notification to student
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO notifications 
                    (user_id, user_type, title, message, type, related_id, is_read, created_at)
                    VALUES (?, 'student', 'Payment Approved', ?, 'payment_approved', ?, 0, NOW())
                ");
                $approvalMessage = sprintf("Your payment of ₹%s has been approved by admin.", number_format($p['amount'], 0));
                $stmt->execute([$p['student_id'], $approvalMessage, $paymentId]);
            } catch (Exception $e) {
                $logger->error('Failed to create student notification', ['error' => $e->getMessage()]);
            }

            $logger->info('Admin approved payment (default)', ['payment_id' => $paymentId, 'student_id' => $p['student_id']]);
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

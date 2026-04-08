<?php
/**
 * Business Rules Engine
 * Enforces college policies for payments, promotions, and fee clearance
 */

declare(strict_types=1);

/**
 * Calculate and update fee status for a student's current semester
 * This is the core function that maintains fee tracking accuracy
 */
function calculateFeeStatus($studentId, $pdo, $logger) {
    try {
        // Get student's current semester
        $stmt = $pdo->prepare("
            SELECT 
                s.id,
                s.current_semester_id, 
                s.semester, 
                s.program,
                ac.semester_number, 
                ac.semester_name
            FROM students s
            LEFT JOIN academic_calendar ac ON s.current_semester_id = ac.id
            WHERE s.id = ?
        ");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student || !$student['current_semester_id']) {
            $logger->warning('Student semester not found', ['student_id' => $studentId]);
            return [
                'status' => 'error',
                'message' => 'Student semester not found'
            ];
        }
        
        $currentSemesterId = $student['current_semester_id'];
        $currentSemesterNumber = $student['semester_number'];
        $semesterName = $student['semester_name'];
        
        // Get total fee for this semester
        $totalFee = getFeeForSemester($currentSemesterId, $studentId, $pdo, $logger);
        
        // Calculate paid amount for current semester
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total_paid
            FROM payments
            WHERE student_id = ? 
            AND semester_id = ? 
            AND payment_status = 'paid'
        ");
        $stmt->execute([$studentId, $currentSemesterId]);
        $paidAmount = (float)$stmt->fetchColumn();
        
        $pendingAmount = max(0, $totalFee - $paidAmount);
        
        // Determine fee status
        $feeStatus = 'not_started';
        $clearedDate = null;
        
        if ($paidAmount >= $totalFee && $totalFee > 0) {
            $feeStatus = 'paid';
            $clearedDate = date('Y-m-d H:i:s');
        } elseif ($paidAmount > 0) {
            $feeStatus = 'partial';
        }
        
        // Update or insert semester_fees record
        $stmt = $pdo->prepare("
            INSERT INTO semester_fees 
            (student_id, semester_id, semester_number, semester_name, total_fee, paid_amount, pending_amount, fee_status, cleared_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                paid_amount = VALUES(paid_amount),
                pending_amount = VALUES(pending_amount),
                fee_status = VALUES(fee_status),
                cleared_date = VALUES(cleared_date),
                updated_at = NOW()
        ");
        $stmt->execute([
            $studentId,
            $currentSemesterId,
            $currentSemesterNumber,
            $semesterName,
            $totalFee,
            $paidAmount,
            $pendingAmount,
            $feeStatus,
            $clearedDate
        ]);
        
        // Update student's overall fee clearance status
        $canBePromoted = ($feeStatus === 'paid' || $feeStatus === 'exempted');
        $clearanceStatus = ($feeStatus === 'paid') ? 'cleared' : 'pending';
        
        $stmt = $pdo->prepare("
            UPDATE students 
            SET fee_clearance_status = ?,
                pending_fee_amount = ?,
                can_be_promoted = ?,
                last_cleared_semester_id = CASE WHEN ? = 'paid' THEN ? ELSE last_cleared_semester_id END
            WHERE id = ?
        ");
        $stmt->execute([
            $clearanceStatus,
            $pendingAmount,
            $canBePromoted,
            $feeStatus,
            $currentSemesterId,
            $studentId
        ]);
        
        // Remove fee block if cleared
        if ($feeStatus === 'paid') {
            $stmt = $pdo->prepare("
                UPDATE promotion_blocks
                SET is_active = FALSE,
                    resolved_at = NOW(),
                    resolution_notes = 'Fee cleared automatically'
                WHERE student_id = ?
                AND block_type = 'fee_pending'
                AND blocking_semester_id = ?
                AND is_active = TRUE
            ");
            $stmt->execute([$studentId, $currentSemesterId]);
        } else if ($pendingAmount > 0) {
            // Create or update fee block
            $stmt = $pdo->prepare("
                INSERT INTO promotion_blocks 
                (student_id, block_type, block_reason, blocking_semester_id, severity, is_active)
                VALUES (?, 'fee_pending', ?, ?, 'critical', TRUE)
                ON DUPLICATE KEY UPDATE
                    block_reason = VALUES(block_reason),
                    is_active = TRUE
            ");
            $blockReason = sprintf(
                "Fee clearance required for %s. ₹%s pending.",
                $semesterName,
                number_format($pendingAmount, 0)
            );
            $stmt->execute([$studentId, $blockReason, $currentSemesterId]);
        }
        
        $logger->info('Fee status calculated', [
            'student_id' => $studentId,
            'semester_id' => $currentSemesterId,
            'total_fee' => $totalFee,
            'paid_amount' => $paidAmount,
            'pending_amount' => $pendingAmount,
            'fee_status' => $feeStatus,
            'can_be_promoted' => $canBePromoted
        ]);
        
        return [
            'status' => 'success',
            'current_semester' => [
                'semester_id' => $currentSemesterId,
                'semester_number' => $currentSemesterNumber,
                'semester_name' => $semesterName,
                'total_fee' => $totalFee,
                'paid_amount' => $paidAmount,
                'pending_amount' => $pendingAmount,
                'fee_status' => $feeStatus
            ],
            'can_be_promoted' => $canBePromoted,
            'clearance_status' => $clearanceStatus
        ];
        
    } catch (Exception $e) {
        $logger->error('Error calculating fee status', [
            'student_id' => $studentId,
            'error' => $e->getMessage()
        ]);
        return [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Get fee for a specific semester
 */
function getFeeForSemester($semesterId, $studentId, $pdo, $logger) {
    // First check for student-specific fee assignment
    $stmt = $pdo->prepare("
        SELECT sfa.total_fee
        FROM student_fee_assignments sfa
        JOIN students s ON sfa.student_id = s.id
        WHERE sfa.student_id = ?
        AND sfa.program = s.program
        LIMIT 1
    ");
    $stmt->execute([$studentId]);
    $studentFee = $stmt->fetchColumn();
    
    $logger->info('getFeeForSemester - student_fee_assignments check', [
        'student_id' => $studentId,
        'student_fee' => $studentFee
    ]);
    
    if ($studentFee !== false && $studentFee > 0) {
        return (float)$studentFee;
    }
    
    // Fall back to program-wide fee settings
    $stmt = $pdo->prepare("
        SELECT fs.total_fee, fs.program, s.program as student_program
        FROM fee_settings fs
        JOIN students s ON fs.program COLLATE utf8mb4_general_ci = s.program COLLATE utf8mb4_general_ci
        WHERE s.id = ?
        AND fs.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$studentId]);
    $feeData = $stmt->fetch(PDO::FETCH_ASSOC);
    $programFee = $feeData['total_fee'] ?? false;
    
    $logger->info('getFeeForSemester - fee_settings check', [
        'student_id' => $studentId,
        'program_fee' => $programFee,
        'fee_settings_program' => $feeData['program'] ?? 'NOT_FOUND',
        'student_program' => $feeData['student_program'] ?? 'NOT_FOUND'
    ]);
    
    return $programFee !== false ? (float)$programFee : 0;
}

/**
 * Check if student can make payment
 * Enforces sequential payment rule
 */
function canStudentPay($studentId, $pdo, $logger) {
    try {
        // Get student data
        $stmt = $pdo->prepare("
            SELECT 
                s.id,
                s.current_semester_id,
                s.semester,
                s.program,
                s.fee_clearance_status,
                s.pending_fee_amount,
                ac.semester_number,
                ac.semester_name
            FROM students s
            LEFT JOIN academic_calendar ac ON s.current_semester_id = ac.id
            WHERE s.id = ?
        ");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            return [
                'can_pay' => false,
                'reason' => 'Student not found',
                'block_type' => 'error'
            ];
        }
        
        // Check if student is approved
        $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ?");
        $stmt->execute([$studentId]);
        $approved = (int)$stmt->fetchColumn();
        
        if ($approved !== 1) {
            return [
                'can_pay' => false,
                'reason' => 'Your application is pending approval. You cannot make payments yet.',
                'block_type' => 'not_approved',
                'action_required' => 'Wait for admin approval'
            ];
        }
        
        // Check if payment is live for this program
        $stmt = $pdo->prepare("
            SELECT is_live FROM fee_settings 
            WHERE program = ? AND is_active = 1
        ");
        $stmt->execute([$student['program']]);
        $isLive = (int)$stmt->fetchColumn();
        
        if (!$isLive) {
            return [
                'can_pay' => false,
                'reason' => 'Payments are not currently active for your program. Contact admin.',
                'block_type' => 'payment_not_live',
                'action_required' => 'Wait for admin to activate payments'
            ];
        }
        
        // Get current semester fee status
        $stmt = $pdo->prepare("
            SELECT total_fee, pending_amount, fee_status
            FROM semester_fees
            WHERE student_id = ? AND semester_id = ?
        ");
        $stmt->execute([$studentId, $student['current_semester_id']]);
        $currentFeeStatus = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$currentFeeStatus || $currentFeeStatus['total_fee'] == 0) {
            return [
                'can_pay' => false,
                'reason' => 'No fee assigned for current semester. Contact admin.',
                'block_type' => 'no_fee_assigned',
                'action_required' => 'Contact admin'
            ];
        }
        
        if ($currentFeeStatus['pending_amount'] <= 0) {
            return [
                'can_pay' => false,
                'reason' => sprintf(
                    'Semester fees fully paid (₹%s). No pending amount.',
                    number_format($currentFeeStatus['total_fee'], 0)
                ),
                'block_type' => 'fully_paid',
                'action_required' => 'No action needed',
                'is_success' => true
            ];
        }
        
        // All checks passed
        return [
            'can_pay' => true,
            'reason' => sprintf(
                'You can pay up to ₹%s for %s.',
                number_format($currentFeeStatus['pending_amount'], 0),
                $student['semester_name']
            ),
            'block_type' => null,
            'semester_info' => $currentFeeStatus,
            'action_required' => 'Make payment'
        ];
        
    } catch (Exception $e) {
        $logger->error('Error checking payment eligibility', [
            'student_id' => $studentId,
            'error' => $e->getMessage()
        ]);
        return [
            'can_pay' => false,
            'reason' => 'System error. Please try again.',
            'block_type' => 'error'
        ];
    }
}

/**
 * Check if student can be promoted
 * Enforces fee clearance requirement
 */
function canStudentBePromoted($studentId, $fromSemester, $toSemester, $pdo, $logger) {
    try {
        // Get student's current semester fee status
        $stmt = $pdo->prepare("
            SELECT 
                s.can_be_promoted,
                s.fee_clearance_status,
                s.pending_fee_amount,
                sf.semester_id,
                sf.semester_name,
                sf.pending_amount,
                sf.fee_status
            FROM students s
            LEFT JOIN semester_fees sf ON s.id = sf.student_id AND s.current_semester_id = sf.semester_id
            WHERE s.id = ?
        ");
        $stmt->execute([$studentId]);
        $feeData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $blocks = [];
        
        // Check fee clearance
        if (!$feeData['can_be_promoted'] && $feeData['pending_amount'] > 0) {
            $blocks[] = [
                'type' => 'fee_pending',
                'message' => sprintf(
                    "Fee clearance required for %s. ₹%s pending.",
                    $feeData['semester_name'],
                    number_format($feeData['pending_amount'], 0)
                ),
                'severity' => 'critical',
                'blocking_semester_id' => $feeData['semester_id']
            ];
        }
        
        // Check for other active blocks
        $stmt = $pdo->prepare("
            SELECT block_type, block_reason, blocking_semester_id, severity
            FROM promotion_blocks
            WHERE student_id = ? AND is_active = TRUE
        ");
        $stmt->execute([$studentId]);
        $activeBlocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($activeBlocks as $block) {
            $blocks[] = [
                'type' => $block['block_type'],
                'message' => $block['block_reason'],
                'severity' => $block['severity'],
                'blocking_semester_id' => $block['blocking_semester_id']
            ];
        }
        
        if (!empty($blocks)) {
            return [
                'can_promote' => false,
                'blocks' => $blocks,
                'action_required' => 'Clear all blocks before promotion'
            ];
        }
        
        return [
            'can_promote' => true,
            'blocks' => [],
            'action_required' => 'Proceed with promotion'
        ];
        
    } catch (Exception $e) {
        $logger->error('Error checking promotion eligibility', [
            'student_id' => $studentId,
            'error' => $e->getMessage()
        ]);
        return [
            'can_promote' => false,
            'blocks' => [[
                'type' => 'error',
                'message' => 'System error checking promotion eligibility',
                'severity' => 'critical'
            ]],
            'action_required' => 'Contact admin'
        ];
    }
}

/**
 * Update fee status after payment is confirmed
 */
function updateFeeStatusAfterPayment($studentId, $semesterId, $paidAmount, $pdo, $logger) {
    try {
        // Recalculate fee status
        $result = calculateFeeStatus($studentId, $pdo, $logger);
        
        $logger->info('Fee status updated after payment', [
            'student_id' => $studentId,
            'semester_id' => $semesterId,
            'paid_amount' => $paidAmount,
            'result' => $result
        ]);
        
        return $result;
        
    } catch (Exception $e) {
        $logger->error('Error updating fee status after payment', [
            'student_id' => $studentId,
            'error' => $e->getMessage()
        ]);
        return [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

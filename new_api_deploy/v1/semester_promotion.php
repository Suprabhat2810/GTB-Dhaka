<?php
/**
 * Semester Promotion API
 * Handles bulk and individual student semester promotions
 * 
 * Endpoints:
 * GET  - Get promotion eligibility for students
 * POST - Execute bulk or individual promotion
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/business_rules.php';

$logger = getLogger('semester_promotion');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

// CORS preflight
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================
// VALIDATION FUNCTION
// ============================================================
function validatePromotion($studentId, $targetSemesterId, $pdo, $logger) {
    $errors = [];
    
    // Check target semester exists and is valid
    $stmt = $pdo->prepare("
        SELECT id, status, semester_name, semester_number, start_date 
        FROM academic_calendar 
        WHERE id = ?
    ");
    $stmt->execute([$targetSemesterId]);
    $semester = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$semester) {
        $errors[] = 'Target semester does not exist';
    } elseif ($semester['status'] === 'completed') {
        $errors[] = 'Cannot promote to completed semester';
    }
    
    // Check student isn't already in target semester
    $stmt = $pdo->prepare("
        SELECT id FROM students 
        WHERE id = ? AND current_semester_id = ?
    ");
    $stmt->execute([$studentId, $targetSemesterId]);
    if ($stmt->fetch()) {
        $errors[] = 'Student already in target semester';
    }
    
    // NEW: Check fee clearance using business rules
    $stmt = $pdo->prepare("
        SELECT semester FROM students WHERE id = ?
    ");
    $stmt->execute([$studentId]);
    $currentSemester = (int)$stmt->fetchColumn();
    
    $promotionCheck = canStudentBePromoted($studentId, $currentSemester, $semester['semester_number'], $pdo, $logger);
    
    if (!$promotionCheck['can_promote']) {
        foreach ($promotionCheck['blocks'] as $block) {
            $errors[] = $block['message'];
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'semester' => $semester,
        'promotion_blocks' => $promotionCheck['blocks'] ?? []
    ];
}

// ============================================================
// GET - Get promotion eligibility
// ============================================================
if ($method === 'GET') {
    try {
        $programId = isset($_GET['program_id']) ? (int)$_GET['program_id'] : null;
        $fromSemester = isset($_GET['from_semester']) ? (int)$_GET['from_semester'] : null;
        $academicYear = isset($_GET['academic_year']) ? trim($_GET['academic_year']) : null;
        
        if (!$programId || !$fromSemester) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'program_id and from_semester are required']);
            exit;
        }
        
        // Get program name
        $progStmt = $pdo->prepare("SELECT name FROM programs WHERE id = ?");
        $progStmt->execute([$programId]);
        $programName = $progStmt->fetchColumn();
        
        if (!$programName) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Program not found']);
            exit;
        }
        
        // Get semester settings
        $settingsStmt = $pdo->prepare("
            SELECT * FROM semester_settings 
            WHERE program_id = ? OR program_id IS NULL 
            ORDER BY program_id DESC LIMIT 1
        ");
        $settingsStmt->execute([$programId]);
        $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
        
        $maxSemesters = $settings ? (int)$settings['max_semesters'] : 8;
        $promotionCriteria = $settings && $settings['promotion_criteria'] 
            ? json_decode($settings['promotion_criteria'], true) 
            : ['clear_dues' => true];
        
        // Check if promotion is possible
        if ($fromSemester >= $maxSemesters) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'can_promote' => false,
                    'message' => 'Students are already in the final semester',
                    'students' => [],
                    'summary' => [
                        'total' => 0,
                        'eligible' => 0,
                        'not_eligible' => 0
                    ]
                ]
            ]);
            exit;
        }
        
        // Get students in the specified semester
        $studentsQuery = "
            SELECT 
                s.id,
                s.name,
                s.email,
                s.program,
                s.semester,
                s.year,
                s.academic_year,
                s.final_registration_number,
                s.temporary_serial_number,
                COALESCE(
                    (SELECT SUM(p.amount) FROM payments p 
                     WHERE p.student_id = s.id AND p.payment_status = 'paid'), 0
                ) AS total_paid,
                COALESCE(
                    (SELECT fs.total_fee FROM fee_settings fs 
                     WHERE fs.program = s.program 
                     ORDER BY fs.id DESC LIMIT 1), 0
                ) AS total_fee
            FROM students s
            JOIN approvals a ON s.id = a.student_id
            WHERE s.program = ?
            AND s.semester = ?
            AND s.semester IS NOT NULL
            AND s.academic_year IS NOT NULL
            AND a.approved = 1
            AND s.final_registration_number IS NOT NULL
        ";
        
        $params = [$programName, $fromSemester];
        
        // Filter by academic year if provided
        if ($academicYear) {
            $studentsQuery .= " AND s.academic_year = ?";
            $params[] = $academicYear;
        }
        
        $studentsQuery .= " ORDER BY s.name";
        
        $studentsStmt = $pdo->prepare($studentsQuery);
        $studentsStmt->execute($params);
        $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Evaluate eligibility for each student
        $eligibleCount = 0;
        $notEligibleCount = 0;
        $formattedStudents = [];
        
        foreach ($students as $student) {
            $isEligible = true;
            $reasons = [];
            
            // Check dues if required
            if (isset($promotionCriteria['clear_dues']) && $promotionCriteria['clear_dues']) {
                $pendingAmount = max(0, (float)$student['total_fee'] - (float)$student['total_paid']);
                if ($pendingAmount > 0) {
                    $isEligible = false;
                    $reasons[] = "Pending fees: ₹" . number_format($pendingAmount);
                }
            }
            
            // Add more criteria checks here as needed
            // e.g., attendance, credits, etc.
            
            if ($isEligible) {
                $eligibleCount++;
            } else {
                $notEligibleCount++;
            }
            
            $formattedStudents[] = [
                'id' => (int)$student['id'],
                'name' => $student['name'],
                'email' => $student['email'],
                'registration_number' => $student['final_registration_number'],
                'current_semester' => (int)$student['semester'],
                'year' => (int)$student['year'],
                'total_paid' => (float)$student['total_paid'],
                'total_fee' => (float)$student['total_fee'],
                'pending_amount' => max(0, (float)$student['total_fee'] - (float)$student['total_paid']),
                'is_eligible' => $isEligible,
                'reasons' => $reasons
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'can_promote' => true,
                'from_semester' => $fromSemester,
                'to_semester' => $fromSemester + 1,
                'program_id' => $programId,
                'program_name' => $programName,
                'max_semesters' => $maxSemesters,
                'promotion_criteria' => $promotionCriteria,
                'students' => $formattedStudents,
                'summary' => [
                    'total' => count($students),
                    'eligible' => $eligibleCount,
                    'not_eligible' => $notEligibleCount
                ]
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        $logger->error('Promotion eligibility error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        echo json_encode(['success' => false, 'message' => 'Error fetching eligibility: ' . $e->getMessage()]);
    }
}

// ============================================================
// POST - Execute promotion
// ============================================================
elseif ($method === 'POST') {
    try {
        $rawInput = file_get_contents('php://input');
        $logger->info('Promotion POST request received', ['raw_input' => $rawInput]);
        
        $data = json_decode($rawInput, true);
        $logger->info('Decoded promotion request', ['data' => $data]);
        
        // Validate required fields
        if (!isset($data['student_ids']) || !is_array($data['student_ids']) || empty($data['student_ids'])) {
            $logger->error('Validation failed: student_ids missing or invalid', ['data' => $data]);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'student_ids array is required']);
            exit;
        }
        
        if (!isset($data['from_semester']) || !isset($data['to_semester'])) {
            $logger->error('Validation failed: semester data missing', ['data' => $data]);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'from_semester and to_semester are required']);
            exit;
        }
        
        $studentIds = array_map('intval', $data['student_ids']);
        $fromSemester = (int)$data['from_semester'];
        $toSemester = (int)$data['to_semester'];
        $adminId = isset($data['admin_id']) ? (int)$data['admin_id'] : null;
        $reason = $data['reason'] ?? 'Bulk promotion by admin';
        
        $logger->info('Promotion parameters extracted', [
            'student_ids' => $studentIds,
            'from_semester' => $fromSemester,
            'to_semester' => $toSemester,
            'admin_id' => $adminId,
            'reason' => $reason
        ]);
        
        // Validate semester progression
        if ($toSemester <= $fromSemester) {
            $logger->error('Validation failed: invalid semester progression', [
                'from_semester' => $fromSemester,
                'to_semester' => $toSemester
            ]);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'to_semester must be greater than from_semester']);
            exit;
        }
        
        // Check semester settings
        $settingsStmt = $pdo->prepare("SELECT max_semesters FROM semester_settings WHERE program_id IS NULL LIMIT 1");
        $settingsStmt->execute();
        $maxSemesters = $settingsStmt->fetchColumn() ?: 8;
        
        $logger->info('Semester settings checked', ['max_semesters' => $maxSemesters]);
        
        if ($toSemester > $maxSemesters) {
            $logger->error('Validation failed: exceeds max semesters', [
                'to_semester' => $toSemester,
                'max_semesters' => $maxSemesters
            ]);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Cannot promote beyond semester $maxSemesters"]);
            exit;
        }
        
        $logger->info('Starting database transaction for promotion');
        $pdo->beginTransaction();
        
        $promotedCount = 0;
        $failedCount = 0;
        $results = [];
        
        foreach ($studentIds as $studentId) {
            try {
                $logger->info('Processing student for promotion', ['student_id' => $studentId]);
                
                // Get current student data with program_id
                $studentStmt = $pdo->prepare("
                    SELECT s.*, p.id as program_id 
                    FROM students s
                    JOIN programs p ON s.program = p.name
                    WHERE s.id = ?
                ");
                $studentStmt->execute([$studentId]);
                $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
                
                $logger->info('Student data retrieved', ['student' => $student]);
                
                if (!$student) {
                    throw new Exception('Student not found');
                }
                
                if ((int)$student['semester'] !== $fromSemester) {
                    throw new Exception("Student is in semester {$student['semester']}, not $fromSemester");
                }
                
                // Get target semester with FK
                $targetStmt = $pdo->prepare("
                    SELECT id, academic_year, semester_name, status 
                    FROM academic_calendar 
                    WHERE program_id = ? AND semester_number = ?
                    ORDER BY created_at DESC 
                    LIMIT 1
                ");
                $targetStmt->execute([$student['program_id'], $toSemester]);
                $targetSemester = $targetStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$targetSemester) {
                    throw new Exception("Target semester $toSemester not found for program");
                }
                
                // Validate promotion
                $validation = validatePromotion($studentId, $targetSemester['id'], $pdo, $logger);
                if (!$validation['valid']) {
                    throw new Exception(implode(', ', $validation['errors']));
                }
                
                $oldYear = (int)$student['year'];
                $oldSemesterId = $student['current_semester_id'];
                
                // Increment year if moving from even to odd semester
                $newYear = ($toSemester % 2 === 1 && $fromSemester % 2 === 0) ? $oldYear + 1 : $oldYear;
                
                $logger->info('Updating student record', [
                    'student_id' => $studentId,
                    'old_semester' => $fromSemester,
                    'new_semester' => $toSemester,
                    'old_semester_id' => $oldSemesterId,
                    'new_semester_id' => $targetSemester['id'],
                    'old_year' => $oldYear,
                    'new_year' => $newYear,
                    'new_academic_year' => $targetSemester['academic_year']
                ]);
                
                // Update student with both old columns (backward compat) and new FK
                $updateStmt = $pdo->prepare("
                    UPDATE students 
                    SET semester = ?, 
                        year = ?, 
                        academic_year = ?,
                        current_semester_id = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $toSemester,
                    $newYear,
                    $targetSemester['academic_year'],
                    $targetSemester['id'],
                    $studentId
                ]);
                
                $logger->info('Student record updated successfully', [
                    'student_id' => $studentId,
                    'rows_affected' => $updateStmt->rowCount()
                ]);
                
                // Log the transition
                $logger->info('Logging semester transition', [
                    'student_id' => $studentId,
                    'from_semester' => $fromSemester,
                    'to_semester' => $toSemester,
                    'promoted_by' => $adminId
                ]);
                
                try {
                    $logStmt = $pdo->prepare("
                        INSERT INTO semester_transitions 
                        (student_id, from_semester, to_semester, from_year, to_year, transition_type, promoted_by, reason)
                        VALUES (?, ?, ?, ?, ?, 'promotion', ?, ?)
                    ");
                    $logStmt->execute([$studentId, $fromSemester, $toSemester, $oldYear, $newYear, $adminId, $reason]);
                    $logger->info('Semester transition logged successfully', ['student_id' => $studentId]);
                } catch (Exception $transitionError) {
                    $logger->error('Failed to log semester transition', [
                        'student_id' => $studentId,
                        'error' => $transitionError->getMessage(),
                        'sql_state' => $transitionError->getCode()
                    ]);
                    // Continue anyway - transition log is not critical
                }
                
                // Log to promotion_history (new audit table)
                try {
                    $historyStmt = $pdo->prepare("
                        INSERT INTO promotion_history 
                        (student_id, from_semester_id, to_semester_id, promoted_by, reason, status)
                        VALUES (?, ?, ?, ?, ?, 'success')
                    ");
                    $historyStmt->execute([
                        $studentId,
                        $oldSemesterId,
                        $targetSemester['id'],
                        $adminId,
                        $reason ?? 'bulk_promotion'
                    ]);
                    $logger->info('Promotion history logged successfully', ['student_id' => $studentId]);
                } catch (Exception $historyError) {
                    $logger->error('Failed to log promotion history', [
                        'student_id' => $studentId,
                        'error' => $historyError->getMessage()
                    ]);
                    // Continue anyway - history log is not critical for promotion success
                }
                
                // Send notification to student about promotion
                try {
                    $notificationMessage = "Congratulations! You have been promoted from Semester {$fromSemester} to Semester {$toSemester}";
                    if ($newYear > $oldYear) {
                        $notificationMessage .= " and Year {$oldYear} to Year {$newYear}";
                    }
                    $notificationMessage .= ". Academic Year: {$newAcademicYear}";
                    
                    $notifStmt = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
                    $notifStmt->execute([$studentId, $notificationMessage]);
                } catch (Exception $notifError) {
                    // Non-critical: log but don't fail promotion
                    $logger->warning('Failed to send promotion notification', [
                        'student_id' => $studentId,
                        'error' => $notifError->getMessage()
                    ]);
                }
                
                $promotedCount++;
                $results[] = [
                    'id' => $studentId,
                    'name' => $student['name'],
                    'success' => true,
                    'from_semester' => $fromSemester,
                    'to_semester' => $toSemester,
                    'from_year' => $oldYear,
                    'to_year' => $newYear
                ];
                
                $logger->info('Student promoted successfully', [
                    'student_id' => $studentId,
                    'name' => $student['name'],
                    'from_semester' => $fromSemester,
                    'to_semester' => $toSemester
                ]);
                
            } catch (Exception $e) {
                $logger->error('Student promotion failed', [
                    'student_id' => $studentId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $failedCount++;
                $results[] = ['id' => $studentId, 'success' => false, 'message' => $e->getMessage()];
            }
        }
        
        $logger->info('Committing transaction', [
            'promoted_count' => $promotedCount,
            'failed_count' => $failedCount
        ]);
        $pdo->commit();
        $logger->info('Transaction committed successfully');
        
        $response = [
            'success' => true,
            'message' => "Promoted $promotedCount students successfully" . ($failedCount > 0 ? ", $failedCount failed" : ""),
            'data' => [
                'promoted_count' => $promotedCount,
                'failed_count' => $failedCount,
                'results' => $results
            ]
        ];
        
        $logger->info('Promotion completed', $response);
        echo json_encode($response);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $logger->error('Rolling back transaction due to error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $pdo->rollBack();
        }
        http_response_code(500);
        $logger->error('Promotion execution error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        echo json_encode([
            'success' => false, 
            'message' => 'Error executing promotion: ' . $e->getMessage(),
            'error_details' => [
                'file' => basename($e->getFile()),
                'line' => $e->getLine()
            ]
        ]);
    }
}

else {
    http_response_code(405);
    $logger->error('Semester promotion error', ['error' => 'Method not allowed']);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

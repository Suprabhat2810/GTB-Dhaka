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

$logger = getLogger('semester_promotion');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

// CORS preflight
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
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
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        if (!isset($data['student_ids']) || !is_array($data['student_ids']) || empty($data['student_ids'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'student_ids array is required']);
            exit;
        }
        
        if (!isset($data['from_semester']) || !isset($data['to_semester'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'from_semester and to_semester are required']);
            exit;
        }
        
        $studentIds = array_map('intval', $data['student_ids']);
        $fromSemester = (int)$data['from_semester'];
        $toSemester = (int)$data['to_semester'];
        $adminId = isset($data['admin_id']) ? (int)$data['admin_id'] : null;
        $reason = $data['reason'] ?? 'Bulk promotion by admin';
        
        // Validate semester progression
        if ($toSemester <= $fromSemester) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'to_semester must be greater than from_semester']);
            exit;
        }
        
        // Check semester settings
        $settingsStmt = $pdo->prepare("SELECT max_semesters FROM semester_settings WHERE program_id IS NULL LIMIT 1");
        $settingsStmt->execute();
        $maxSemesters = $settingsStmt->fetchColumn() ?: 8;
        
        if ($toSemester > $maxSemesters) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Cannot promote beyond semester $maxSemesters"]);
            exit;
        }
        
        $pdo->beginTransaction();
        
        $promotedCount = 0;
        $failedCount = 0;
        $results = [];
        
        foreach ($studentIds as $studentId) {
            try {
                // Get current student data
                $studentStmt = $pdo->prepare("SELECT id, name, semester, year, academic_year, program FROM students WHERE id = ?");
                $studentStmt->execute([$studentId]);
                $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$student) {
                    $failedCount++;
                    $results[] = ['id' => $studentId, 'success' => false, 'message' => 'Student not found'];
                    continue;
                }
                
                if ((int)$student['semester'] !== $fromSemester) {
                    $failedCount++;
                    $results[] = [
                        'id' => $studentId, 
                        'name' => $student['name'],
                        'success' => false, 
                        'message' => "Student is in semester {$student['semester']}, not $fromSemester"
                    ];
                    continue;
                }
                
                $oldYear = (int)$student['year'];
                $oldAcademicYear = $student['academic_year'];
                
                // Increment year if moving from even to odd semester (new academic year)
                $newYear = ($toSemester % 2 === 1 && $fromSemester % 2 === 0) ? $oldYear + 1 : $oldYear;
                
                // Update academic year if year changed
                $newAcademicYear = $oldAcademicYear;
                if ($newYear > $oldYear) {
                    $newAcademicYear = $newYear . '-' . ($newYear + 1);
                }
                
                // Update student semester and academic year
                $updateStmt = $pdo->prepare("UPDATE students SET semester = ?, year = ?, academic_year = ? WHERE id = ?");
                $updateStmt->execute([$toSemester, $newYear, $newAcademicYear, $studentId]);
                
                // Log the transition
                $logStmt = $pdo->prepare("
                    INSERT INTO semester_transitions 
                    (student_id, from_semester, to_semester, from_year, to_year, transition_type, promoted_by, reason)
                    VALUES (?, ?, ?, ?, ?, 'promotion', ?, ?)
                ");
                $logStmt->execute([$studentId, $fromSemester, $toSemester, $oldYear, $newYear, $adminId, $reason]);
                
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
                
            } catch (Exception $e) {
                $failedCount++;
                $results[] = ['id' => $studentId, 'success' => false, 'message' => $e->getMessage()];
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Promoted $promotedCount students successfully" . ($failedCount > 0 ? ", $failedCount failed" : ""),
            'data' => [
                'promoted_count' => $promotedCount,
                'failed_count' => $failedCount,
                'results' => $results
            ]
        ]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        $logger->error('Promotion execution error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        echo json_encode(['success' => false, 'message' => 'Error executing promotion: ' . $e->getMessage()]);
    }
}

else {
    http_response_code(405);
    $logger->error('Semester promotion error', ['error' => 'Method not allowed']);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

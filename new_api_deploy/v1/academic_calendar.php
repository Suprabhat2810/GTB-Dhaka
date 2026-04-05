<?php
/**
 * Academic Calendar API
 * Manages semester schedules, dates, and status for programs
 *
 * Endpoints:
 * GET    - Fetch calendar entries (with filters)
 * POST   - Create new semester entry
 * PUT    - Update semester entry (status, dates, etc.)
 * DELETE - Remove semester entry
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('academic_calendar');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

// CORS preflight
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================
// GET - Fetch academic calendar entries
// ============================================================
if ($method === 'GET') {
    try {
        $programId = isset($_GET['program_id']) ? (int)$_GET['program_id'] : null;
        $academicYear = isset($_GET['academic_year']) ? $_GET['academic_year'] : null;
        $status = isset($_GET['status']) ? $_GET['status'] : null;
        $currentOnly = isset($_GET['current_only']) && $_GET['current_only'] === 'true';
        
        // Build query with filters
        $query = "
            SELECT 
                ac.id,
                ac.program_id,
                p.name AS program_name,
                p.code AS program_code,
                ac.academic_year,
                ac.semester_number,
                ac.semester_name,
                ac.start_date,
                ac.end_date,
                ac.registration_start,
                ac.registration_end,
                ac.exam_start,
                ac.exam_end,
                ac.status,
                ac.is_current,
                ac.created_at,
                ac.updated_at,
                (SELECT COUNT(*) FROM students s 
                 JOIN approvals a ON s.id = a.student_id
                 WHERE s.program = p.name 
                 AND s.semester = ac.semester_number
                 AND s.academic_year = ac.academic_year
                 AND a.approved = 1
                 AND s.final_registration_number IS NOT NULL) AS student_count
            FROM academic_calendar ac
            JOIN programs p ON ac.program_id = p.id
            WHERE 1=1
        ";
        
        $params = [];
        
        if ($programId) {
            $query .= " AND ac.program_id = ?";
            $params[] = $programId;
        }
        
        if ($academicYear) {
            $query .= " AND ac.academic_year = ?";
            $params[] = $academicYear;
        }
        
        if ($status) {
            $query .= " AND ac.status = ?";
            $params[] = $status;
        }
        
        if ($currentOnly) {
            $query .= " AND ac.is_current = 1";
        }
        
        $query .= " ORDER BY ac.academic_year DESC, ac.semester_number ASC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $calendar = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format dates and calculate additional info
        $formattedCalendar = array_map(function($entry) {
            $startDate = new DateTime($entry['start_date']);
            $endDate = new DateTime($entry['end_date']);
            $today = new DateTime();
            
            // Dynamically calculate actual status based on current date
            $actualStatus = 'upcoming';
            if ($today >= $startDate && $today <= $endDate) {
                $actualStatus = 'active';
            } elseif ($today > $endDate) {
                $actualStatus = 'completed';
            }
            
            // Dynamically set is_current based on active status
            $isCurrent = ($actualStatus === 'active');
            
            // Calculate days remaining or elapsed
            $daysRemaining = null;
            $progress = 0;
            
            if ($actualStatus === 'active') {
                $daysRemaining = max(0, $today->diff($endDate)->days);
                $totalDays = $startDate->diff($endDate)->days;
                $elapsedDays = $startDate->diff($today)->days;
                $progress = $totalDays > 0 ? min(100, round(($elapsedDays / $totalDays) * 100)) : 0;
            }
            
            return [
                'id' => (int)$entry['id'],
                'program_id' => (int)$entry['program_id'],
                'program_name' => $entry['program_name'],
                'program_code' => $entry['program_code'],
                'academic_year' => $entry['academic_year'],
                'semester_number' => (int)$entry['semester_number'],
                'semester_name' => $entry['semester_name'] ?: "Semester " . $entry['semester_number'],
                'start_date' => $entry['start_date'],
                'end_date' => $entry['end_date'],
                'registration_start' => $entry['registration_start'],
                'registration_end' => $entry['registration_end'],
                'exam_start' => $entry['exam_start'],
                'exam_end' => $entry['exam_end'],
                'status' => $actualStatus,
                'is_current' => $isCurrent,
                'student_count' => (int)$entry['student_count'],
                'days_remaining' => $daysRemaining,
                'progress' => $progress,
                'created_at' => $entry['created_at'],
                'updated_at' => $entry['updated_at']
            ];
        }, $calendar);
        
        // Also fetch semester statistics
        $statsQuery = "
            SELECT 
                p.id AS program_id,
                p.name AS program_name,
                s.semester,
                COUNT(s.id) AS student_count
            FROM students s
            JOIN programs p ON s.program = p.name
            JOIN approvals a ON s.id = a.student_id
            WHERE s.final_registration_number IS NOT NULL
            AND a.approved = 1
            AND s.semester IS NOT NULL
            AND s.academic_year IS NOT NULL
            GROUP BY p.id, p.name, s.semester
            ORDER BY p.name, s.semester
        ";
        $statsStmt = $pdo->query($statsQuery);
        $semesterStats = $statsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get available academic years
        $yearsQuery = "SELECT DISTINCT academic_year FROM academic_calendar ORDER BY academic_year DESC";
        $yearsStmt = $pdo->query($yearsQuery);
        $academicYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // If no years exist, generate current and next
        if (empty($academicYears)) {
            $currentYear = (int)date('Y');
            $academicYears = [
                $currentYear . '-' . ($currentYear + 1),
                ($currentYear - 1) . '-' . $currentYear
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'calendar' => $formattedCalendar,
                'semester_stats' => $semesterStats,
                'academic_years' => $academicYears
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        $logger->error('Academic calendar error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        echo json_encode(['success' => false, 'message' => 'Error fetching calendar: ' . $e->getMessage()]);
    }
}

// ============================================================
// POST - Create new semester entry
// ============================================================
elseif ($method === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $required = ['program_id', 'academic_year', 'semester_number', 'start_date', 'end_date'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
                exit;
            }
        }
        
        $programId = (int)$data['program_id'];
        $academicYear = $data['academic_year'];
        $semesterNumber = (int)$data['semester_number'];
        $startDate = $data['start_date'];
        $endDate = $data['end_date'];
        $semesterName = $data['semester_name'] ?? null;
        
        // Convert empty strings to NULL for optional date fields
        $registrationStart = !empty($data['registration_start']) ? $data['registration_start'] : null;
        $registrationEnd = !empty($data['registration_end']) ? $data['registration_end'] : null;
        $examStart = !empty($data['exam_start']) ? $data['exam_start'] : null;
        $examEnd = !empty($data['exam_end']) ? $data['exam_end'] : null;
        
        $status = $data['status'] ?? 'upcoming';
        $isCurrent = isset($data['is_current']) ? (bool)$data['is_current'] : false;
        
        // Validate dates
        if (strtotime($endDate) <= strtotime($startDate)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'End date must be after start date']);
            exit;
        }
        
        // Check for duplicate
        $checkStmt = $pdo->prepare("
            SELECT id FROM academic_calendar 
            WHERE program_id = ? AND academic_year = ? AND semester_number = ?
        ");
        $checkStmt->execute([$programId, $academicYear, $semesterNumber]);
        if ($checkStmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Semester already exists for this program and academic year']);
            exit;
        }
        
        // If setting as current, unset other current semesters for this program
        if ($isCurrent) {
            $pdo->prepare("UPDATE academic_calendar SET is_current = 0 WHERE program_id = ?")->execute([$programId]);
        }
        
        // Insert new entry
        $stmt = $pdo->prepare("
            INSERT INTO academic_calendar 
            (program_id, academic_year, semester_number, semester_name, start_date, end_date, 
             registration_start, registration_end, exam_start, exam_end, status, is_current)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $programId, $academicYear, $semesterNumber, $semesterName,
            $startDate, $endDate, $registrationStart, $registrationEnd,
            $examStart, $examEnd, $status, $isCurrent ? 1 : 0
        ]);
        
        $newId = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'Semester created successfully',
            'data' => ['id' => (int)$newId]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        $logger->error('Academic calendar error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        echo json_encode(['success' => false, 'message' => 'Error creating semester: ' . $e->getMessage()]);
    }
}

// ============================================================
// PUT - Update semester entry
// ============================================================
elseif ($method === 'PUT') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing semester ID']);
            exit;
        }
        
        $id = (int)$data['id'];
        
        // Check if entry exists
        $checkStmt = $pdo->prepare("SELECT * FROM academic_calendar WHERE id = ?");
        $checkStmt->execute([$id]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Semester not found']);
            exit;
        }
        
        // Build update query dynamically
        $updateFields = [];
        $params = [];
        
        $allowedFields = [
            'semester_name', 'start_date', 'end_date', 'registration_start', 
            'registration_end', 'exam_start', 'exam_end', 'status'
        ];
        
        $dateFields = ['registration_start', 'registration_end', 'exam_start', 'exam_end'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "$field = ?";
                // Convert empty strings to NULL for date fields
                if (in_array($field, $dateFields) && empty($data[$field])) {
                    $params[] = null;
                } else {
                    $params[] = $data[$field];
                }
            }
        }
        
        // Handle is_current separately
        if (isset($data['is_current'])) {
            $isCurrent = (bool)$data['is_current'];
            if ($isCurrent) {
                // Unset other current semesters for this program
                $pdo->prepare("UPDATE academic_calendar SET is_current = 0 WHERE program_id = ?")->execute([$existing['program_id']]);
            }
            $updateFields[] = "is_current = ?";
            $params[] = $isCurrent ? 1 : 0;
        }
        
        if (empty($updateFields)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            exit;
        }
        
        $params[] = $id;
        $query = "UPDATE academic_calendar SET " . implode(', ', $updateFields) . " WHERE id = ?";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        // If status changed to 'active', auto-set as current
        if (isset($data['status']) && $data['status'] === 'active') {
            $pdo->prepare("UPDATE academic_calendar SET is_current = 0 WHERE program_id = ? AND id != ?")->execute([$existing['program_id'], $id]);
            $pdo->prepare("UPDATE academic_calendar SET is_current = 1 WHERE id = ?")->execute([$id]);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Semester updated successfully'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        $logger->error('Academic calendar error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        echo json_encode(['success' => false, 'message' => 'Error updating semester: ' . $e->getMessage()]);
    }
}

// ============================================================
// DELETE - Remove semester entry
// ============================================================
elseif ($method === 'DELETE') {
    try {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing semester ID']);
            exit;
        }
        
        // Check if entry exists and has students
        $checkStmt = $pdo->prepare("
            SELECT ac.*, p.name AS program_name,
                   (SELECT COUNT(*) FROM students s 
                    JOIN approvals a ON s.id = a.student_id
                    WHERE s.program = p.name 
                    AND s.semester = ac.semester_number
                    AND s.academic_year = ac.academic_year
                    AND a.approved = 1) AS student_count
            FROM academic_calendar ac
            JOIN programs p ON ac.program_id = p.id
            WHERE ac.id = ?
        ");
        $checkStmt->execute([$id]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Semester not found']);
            exit;
        }
        
        // Warn if students are enrolled
        if ($existing['student_count'] > 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false, 
                'message' => "Cannot delete: {$existing['student_count']} students are enrolled in this semester"
            ]);
            exit;
        }
        
        $stmt = $pdo->prepare("DELETE FROM academic_calendar WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Semester deleted successfully'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        $logger->error('Academic calendar error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        echo json_encode(['success' => false, 'message' => 'Error deleting semester: ' . $e->getMessage()]);
    }
}

else {
    http_response_code(405);
    $logger->error('Academic calendar error', ['error' => 'Method not allowed']);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

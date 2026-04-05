<?php
/**
 * Fee Management API with Versioning
 * Handles fee structure changes with historical tracking
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('fee_management');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

// CORS preflight
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================
// GET - Fetch fee structure history
// ============================================================
if ($method === 'GET') {
    try {
        $action = $_GET['action'] ?? 'current';
        
        if ($action === 'history') {
            // Get fee change history
            $programId = isset($_GET['program_id']) ? (int)$_GET['program_id'] : null;
            $semester = isset($_GET['semester']) ? (int)$_GET['semester'] : null;
            $year = isset($_GET['year']) ? (int)$_GET['year'] : null;
            
            $query = "
                SELECT 
                    fch.*,
                    p.name AS program_name,
                    u.name AS changed_by_name
                FROM fee_change_history fch
                JOIN programs p ON fch.program_id = p.id
                LEFT JOIN users u ON fch.changed_by = u.id
                WHERE 1=1
            ";
            
            $params = [];
            
            if ($programId) {
                $query .= " AND fch.program_id = ?";
                $params[] = $programId;
            }
            
            if ($semester) {
                $query .= " AND fch.semester = ?";
                $params[] = $semester;
            }
            
            if ($year) {
                $query .= " AND fch.year = ?";
                $params[] = $year;
            }
            
            $query .= " ORDER BY fch.created_at DESC LIMIT 50";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $history
            ]);
            
        } elseif ($action === 'versions') {
            // Get all versions of a fee structure
            $programId = (int)$_GET['program_id'];
            $semester = (int)$_GET['semester'];
            $year = (int)$_GET['year'];
            
            $stmt = $pdo->prepare("
                SELECT 
                    fs.*,
                    COUNT(DISTINCT sfa.student_id) AS students_assigned
                FROM fee_settings fs
                LEFT JOIN student_fee_assignments sfa ON sfa.fee_setting_id = fs.id
                WHERE fs.program_id = ? AND fs.semester = ? AND fs.year = ?
                GROUP BY fs.id
                ORDER BY fs.version DESC
            ");
            $stmt->execute([$programId, $semester, $year]);
            $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $versions
            ]);
            
        } else {
            // Get current active fee structures
            $programId = isset($_GET['program_id']) ? (int)$_GET['program_id'] : null;
            
            $query = "
                SELECT 
                    fs.*,
                    p.name AS program_name,
                    COUNT(DISTINCT sfa.student_id) AS students_assigned
                FROM fee_settings fs
                JOIN programs p ON fs.program_id = p.id
                LEFT JOIN student_fee_assignments sfa ON sfa.fee_setting_id = fs.id
                WHERE fs.is_active = 1
            ";
            
            $params = [];
            
            if ($programId) {
                $query .= " AND fs.program_id = ?";
                $params[] = $programId;
            }
            
            $query .= " GROUP BY fs.id ORDER BY p.name, fs.semester, fs.year";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $feeStructures = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $feeStructures
            ]);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        $logger->error('Fee management GET error', ['error' => $e->getMessage()]);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============================================================
// POST - Apply new fee structure
// ============================================================
elseif ($method === 'POST') {
    try {
        $user = authenticate();
        
        if ($user->role !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        $programId = (int)$data['program_id'];
        $semester = (int)$data['semester'];
        $year = (int)$data['year'];
        $newTotalFee = (float)$data['new_total_fee'];
        $effectiveFrom = $data['effective_from'] ?? date('Y-m-d');
        $reason = $data['reason'] ?? 'Fee structure update';
        $applyToExisting = isset($data['apply_to_existing']) ? (bool)$data['apply_to_existing'] : false;
        
        // Validate
        if ($newTotalFee <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid fee amount']);
            exit;
        }
        
        // Call stored procedure
        $stmt = $pdo->prepare("
            CALL ApplyNewFeeStructure(?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $programId,
            $semester,
            $year,
            $newTotalFee,
            $effectiveFrom,
            $user->id,
            $reason,
            $applyToExisting ? 1 : 0
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $logger->info('Fee structure updated', [
            'program_id' => $programId,
            'semester' => $semester,
            'year' => $year,
            'old_fee' => $result['old_fee'],
            'new_fee' => $result['new_fee'],
            'affected_students' => $result['affected_students'],
            'admin_id' => $user->id
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Fee structure updated successfully',
            'data' => $result
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        $logger->error('Fee structure update error', ['error' => $e->getMessage()]);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============================================================
// PUT - Update student fee assignment
// ============================================================
elseif ($method === 'PUT') {
    try {
        $user = authenticate();
        
        if ($user->role !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        $studentId = (int)$data['student_id'];
        $feeSettingId = (int)$data['fee_setting_id'];
        $notes = $data['notes'] ?? 'Manual fee assignment';
        
        // Get fee amount
        $stmt = $pdo->prepare("SELECT total_fee, program_id, semester, year FROM fee_settings WHERE id = ?");
        $stmt->execute([$feeSettingId]);
        $feeSetting = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$feeSetting) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Fee setting not found']);
            exit;
        }
        
        // Update or insert assignment
        $stmt = $pdo->prepare("
            INSERT INTO student_fee_assignments 
                (student_id, fee_setting_id, program_id, semester, year, total_fee, assigned_by, assignment_type, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'manual', ?)
            ON DUPLICATE KEY UPDATE
                fee_setting_id = VALUES(fee_setting_id),
                total_fee = VALUES(total_fee),
                assigned_by = VALUES(assigned_by),
                assigned_date = CURRENT_DATE,
                notes = VALUES(notes)
        ");
        
        $stmt->execute([
            $studentId,
            $feeSettingId,
            $feeSetting['program_id'],
            $feeSetting['semester'],
            $feeSetting['year'],
            $feeSetting['total_fee'],
            $user->id,
            $notes
        ]);
        
        $logger->info('Student fee assignment updated', [
            'student_id' => $studentId,
            'fee_setting_id' => $feeSettingId,
            'admin_id' => $user->id
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Student fee assignment updated'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        $logger->error('Fee assignment update error', ['error' => $e->getMessage()]);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

<?php
// program_management.php - Admin program CRUD operations with registration window control
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('program_management');
$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = getPDO();
    
    // Handle OPTIONS for CORS
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    
    // Authentication check - Admin only
    $user = authenticate('admin');
    $adminId = $user->sub ?? $user->id ?? 0;
    
    switch ($method) {
        case 'GET':
            handleGet($pdo, $logger);
            break;
        case 'POST':
            handlePost($pdo, $logger, $adminId);
            break;
        case 'PUT':
            handlePut($pdo, $logger, $adminId);
            break;
        case 'DELETE':
            handleDelete($pdo, $logger, $adminId);
            break;
        default:
            jsonResponse('error', 'Method not allowed', [], 405);
    }
} catch (Exception $e) {
    $logger->error('program_management error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    jsonResponse('error', 'Internal server error: ' . $e->getMessage(), [], 500);
}

// ============================================================
// GET - List all programs with settings
// ============================================================
function handleGet($pdo, $logger): void {
    $logger->info('Fetching all programs with settings');
    
    try {
        $sql = "
            SELECT 
                p.id,
                p.name,
                p.program_code,
                p.description,
                p.is_active,
                p.created_at,
                ps.registration_open,
                ps.registration_start,
                ps.registration_end,
                ps.contact_email,
                ps.contact_whatsapp,
                ps.query_message
            FROM programs p
            LEFT JOIN program_settings ps ON p.id = ps.program_id
            ORDER BY p.name ASC
        ";
        
        $stmt = $pdo->query($sql);
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $logger->info('Programs fetched', ['count' => count($programs), 'programs' => $programs]);
        jsonResponse('success', 'Programs retrieved successfully', ['programs' => $programs], 200);
    } catch (PDOException $e) {
        $logger->error('Failed to fetch programs', ['error' => $e->getMessage()]);
        jsonResponse('error', 'Failed to fetch programs: ' . $e->getMessage(), [], 500);
    }
}

// ============================================================
// POST - Create new program
// ============================================================
function handlePost($pdo, $logger, $adminId): void {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (empty($input['name'])) {
        jsonResponse('error', 'Program name is required', [], 400);
        return;
    }
    
    // Validate program code if provided
    if (!empty($input['program_code'])) {
        if (!preg_match('/^[A-Z0-9]{2,10}$/', $input['program_code'])) {
            jsonResponse('error', 'Program code must be 2-10 uppercase alphanumeric characters', [], 400);
            return;
        }
    }
    
    // Validate dates if provided
    if (!empty($input['registration_start']) && !empty($input['registration_end'])) {
        $start = strtotime($input['registration_start']);
        $end = strtotime($input['registration_end']);
        if ($end <= $start) {
            jsonResponse('error', 'Registration end date must be after start date', [], 400);
            return;
        }
    }
    
    $pdo->beginTransaction();
    
    try {
        // Insert program
        $sql = "INSERT INTO programs (name, program_code, description, is_active) VALUES (?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $input['name'],
            $input['program_code'] ?? null,
            $input['description'] ?? null,
            $input['is_active'] ?? 1
        ]);
        
        $programId = (int)$pdo->lastInsertId();
        
        // Insert program settings
        $sql = "
            INSERT INTO program_settings 
            (program_id, registration_open, registration_start, registration_end, 
             contact_email, contact_whatsapp, query_message)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $programId,
            $input['registration_open'] ?? 0,
            $input['registration_start'] ?? null,
            $input['registration_end'] ?? null,
            $input['contact_email'] ?? null,
            $input['contact_whatsapp'] ?? null,
            $input['query_message'] ?? 'For admission queries, please contact the administration office.'
        ]);
        
        $pdo->commit();
        
        $logger->info('Program created', [
            'program_id' => $programId,
            'name' => $input['name'],
            'admin_id' => $adminId
        ]);
        
        jsonResponse('success', 'Program created successfully', [
            'program_id' => $programId,
            'name' => $input['name'],
            'program_code' => $input['program_code'] ?? null
        ], 201);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        
        // Check for duplicate program code
        if ($e->getCode() == 23000 && strpos($e->getMessage(), 'program_code') !== false) {
            jsonResponse('error', 'Program code already exists', [], 409);
        } else {
            $logger->error('Failed to create program', ['error' => $e->getMessage()]);
            jsonResponse('error', 'Failed to create program: ' . $e->getMessage(), [], 500);
        }
    }
}

// ============================================================
// PUT - Update program or toggle registration
// ============================================================
function handlePut($pdo, $logger, $adminId): void {
    $programId = $_GET['id'] ?? null;
    $action = $_GET['action'] ?? 'update';
    
    if (!$programId) {
        jsonResponse('error', 'Program ID is required', [], 400);
        return;
    }
    
    // Check if program exists
    $stmt = $pdo->prepare("SELECT id FROM programs WHERE id = ?");
    $stmt->execute([$programId]);
    if (!$stmt->fetch()) {
        jsonResponse('error', 'Program not found', [], 404);
        return;
    }
    
    if ($action === 'toggle_registration') {
        handleToggleRegistration($pdo, $logger, $programId, $adminId);
    } else {
        handleUpdate($pdo, $logger, $programId, $adminId);
    }
}

function handleToggleRegistration($pdo, $logger, $programId, $adminId): void {
    $input = json_decode(file_get_contents('php://input'), true);
    $registrationOpen = $input['registration_open'] ?? null;
    
    if ($registrationOpen === null) {
        jsonResponse('error', 'registration_open field is required', [], 400);
        return;
    }
    
    try {
        $sql = "UPDATE program_settings SET registration_open = ? WHERE program_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$registrationOpen ? 1 : 0, $programId]);
        
        $logger->info('Registration toggled', [
            'program_id' => $programId,
            'registration_open' => $registrationOpen,
            'admin_id' => $adminId
        ]);
        
        jsonResponse('success', 'Registration status updated successfully', [
            'program_id' => $programId,
            'registration_open' => (bool)$registrationOpen
        ], 200);
        
    } catch (PDOException $e) {
        $logger->error('Failed to toggle registration', ['error' => $e->getMessage()]);
        jsonResponse('error', 'Failed to update registration status', [], 500);
    }
}

function handleUpdate($pdo, $logger, $programId, $adminId): void {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate program code if provided
    if (isset($input['program_code']) && !empty($input['program_code'])) {
        if (!preg_match('/^[A-Z0-9]{2,10}$/', $input['program_code'])) {
            jsonResponse('error', 'Program code must be 2-10 uppercase alphanumeric characters', [], 400);
            return;
        }
    }
    
    // Validate dates if both provided
    if (!empty($input['registration_start']) && !empty($input['registration_end'])) {
        $start = strtotime($input['registration_start']);
        $end = strtotime($input['registration_end']);
        if ($end <= $start) {
            jsonResponse('error', 'Registration end date must be after start date', [], 400);
            return;
        }
    }
    
    $pdo->beginTransaction();
    
    try {
        // Update program
        $sql = "UPDATE programs SET name = ?, program_code = ?, description = ?, is_active = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $input['name'] ?? null,
            $input['program_code'] ?? null,
            $input['description'] ?? null,
            $input['is_active'] ?? 1,
            $programId
        ]);
        
        // Update program settings
        $sql = "
            UPDATE program_settings 
            SET registration_open = ?, registration_start = ?, registration_end = ?,
                contact_email = ?, contact_whatsapp = ?, query_message = ?
            WHERE program_id = ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $input['registration_open'] ?? 0,
            $input['registration_start'] ?? null,
            $input['registration_end'] ?? null,
            $input['contact_email'] ?? null,
            $input['contact_whatsapp'] ?? null,
            $input['query_message'] ?? null,
            $programId
        ]);
        
        $pdo->commit();
        
        $logger->info('Program updated', [
            'program_id' => $programId,
            'admin_id' => $adminId
        ]);
        
        jsonResponse('success', 'Program updated successfully', ['program_id' => $programId], 200);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        
        if ($e->getCode() == 23000 && strpos($e->getMessage(), 'program_code') !== false) {
            jsonResponse('error', 'Program code already exists', [], 409);
        } else {
            $logger->error('Failed to update program', ['error' => $e->getMessage()]);
            jsonResponse('error', 'Failed to update program: ' . $e->getMessage(), [], 500);
        }
    }
}

// ============================================================
// DELETE - Delete program
// ============================================================
function handleDelete($pdo, $logger, $adminId): void {
    $programId = $_GET['id'] ?? null;
    
    if (!$programId) {
        jsonResponse('error', 'Program ID is required', [], 400);
        return;
    }
    
    // Check if program exists
    $stmt = $pdo->prepare("SELECT name FROM programs WHERE id = ?");
    $stmt->execute([$programId]);
    $program = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$program) {
        jsonResponse('error', 'Program not found', [], 404);
        return;
    }
    
    // Check if program has students
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM students WHERE program = ?");
    $stmt->execute([$program['name']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] > 0) {
        jsonResponse('error', 'Cannot delete program with enrolled students', [
            'student_count' => $result['count']
        ], 409);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM programs WHERE id = ?");
        $stmt->execute([$programId]);
        
        $logger->info('Program deleted', [
            'program_id' => $programId,
            'program_name' => $program['name'],
            'admin_id' => $adminId
        ]);
        
        jsonResponse('success', 'Program deleted successfully', [], 200);
        
    } catch (PDOException $e) {
        $logger->error('Failed to delete program', ['error' => $e->getMessage()]);
        jsonResponse('error', 'Failed to delete program: ' . $e->getMessage(), [], 500);
    }
}

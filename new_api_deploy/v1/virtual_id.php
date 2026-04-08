<?php
// virtual_id.php - Student Virtual ID Card API
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('virtual_id');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method !== 'GET') {
        $logger->warning('virtual_id: method not allowed', ['method' => $method]);
        jsonResponse("error", "Method not allowed.", [], 405);
        exit;
    }

    $user = authenticate('student');
    $studentId = (int)($user->sub ?? $user->id ?? 0);
    
    if ($studentId <= 0) {
        $logger->warning('virtual_id: invalid student context', ['user' => $user]);
        jsonResponse("error", "Unauthorized.", [], 403);
        exit;
    }

    $logger->info('virtual_id: fetching virtual ID data', ['student_id' => $studentId]);

    // Fetch student data with personal info
    $stmt = $pdo->prepare("
        SELECT 
            s.id,
            s.name,
            s.email,
            s.phone,
            s.program,
            s.semester,
            s.year,
            s.final_registration_number,
            s.temporary_serial_number,
            p.father_name,
            p.address,
            p.father_occupation,
            p.mother_name
        FROM students s
        LEFT JOIN personal_info p ON s.id = p.student_id
        WHERE s.id = ?
        LIMIT 1
    ");
    
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        $logger->info('virtual_id: student not found', ['student_id' => $studentId]);
        jsonResponse("error", "Student not found.", [], 404);
        exit;
    }

    // Calculate valid until date (end of current academic year + 1 year)
    $currentYear = (int)$student['year'];
    $validUntil = date('Y-12-31', strtotime("+1 year"));

    // Prepare response data
    $data = [
        'name' => $student['name'] ?? 'N/A',
        'registration_number' => $student['final_registration_number'] ?? $student['temporary_serial_number'] ?? 'Pending',
        'father_name' => $student['father_name'] ?? 'N/A',
        'address' => $student['address'] ?? 'N/A',
        'program' => $student['program'] ?? 'N/A',
        'semester' => $student['semester'] ?? 1,
        'year' => $student['year'] ?? 1,
        'email' => $student['email'] ?? '',
        'phone' => $student['phone'] ?? '',
        'valid_until' => $validUntil,
        'issue_date' => date('Y-m-d'),
        'student_id' => $studentId
    ];

    $logger->info('virtual_id: data retrieved successfully', ['student_id' => $studentId]);
    jsonResponse("success", "Virtual ID data retrieved successfully.", $data, 200);

} catch (PDOException $e) {
    $logger->error('virtual_id: DB error', ['error' => $e->getMessage()]);
    jsonResponse("error", "Failed to fetch virtual ID data: " . $e->getMessage(), [], 500);
} catch (Exception $e) {
    $logger->critical('virtual_id: unexpected error', ['error' => $e->getMessage()]);
    jsonResponse("error", "Internal server error.", [], 500);
}

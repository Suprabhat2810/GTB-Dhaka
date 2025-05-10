<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

if ($method === 'GET') {
    $user = authenticate();

    if (!$user || $user->role !== 'admin') {
        jsonResponse("error", "Unauthorized access.", [], 403);
        exit;
    }

    try {
        // Fetch approved students with their photo and lock_form_student status
        $query = "
            SELECT 
                s.id,
                s.name,
                s.program,
                pi.lock_form_student,
                CASE 
                    WHEN d.document_path IS NOT NULL 
                    THEN CONCAT('http://localhost/School_project/Final_Enhancements/New_API\'s/', REPLACE(d.document_path, '../', '')) 
                    ELSE NULL 
                END AS photo
            FROM students s
            LEFT JOIN personal_info pi ON s.id = pi.student_id
            LEFT JOIN approvals a ON s.id = a.student_id
            LEFT JOIN documents d ON s.id = d.student_id AND d.document_type = 'Photo' AND d.status = 'verified'
            WHERE a.approved = 1
        ";

        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($students)) {
            jsonResponse("error", "No approved students found.", [], 404);
        }

        // Debug: Log the fetched students to ensure name and photo are present
        $log->info("Fetched students: " . json_encode($students));

        // Return the students array directly without extra nesting
        jsonResponse("success", "Approved students retrieved successfully.", $students, 200);
    } catch (PDOException $e) {
        $log->error("Failed to fetch approved students: " . $e->getMessage());
        jsonResponse("error", "Failed to fetch approved students.", [], 500);
    }
} elseif ($method === 'PUT') {
    $user = authenticate();

    if (!$user || $user->role !== 'admin') {
        jsonResponse("error", "Unauthorized access.", [], 403);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data || !isset($data['id'])) {
        jsonResponse("error", "Invalid data. Student ID is required.", [], 400);
        exit;
    }

    $student_id = (int)$data['id'];

    // Check if the student exists and is approved
    try {
        $stmt = $pdo->prepare("
            SELECT s.id 
            FROM students s
            LEFT JOIN approvals a ON s.id = a.student_id
            WHERE s.id = ? AND a.approved = 1
        ");
        $stmt->execute([$student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            jsonResponse("error", "Student not found or not approved.", [], 404);
            exit;
        }
    } catch (PDOException $e) {
        $log->error("Failed to verify student: " . $e->getMessage());
        jsonResponse("error", "Failed to verify student.", [], 500);
        exit;
    }

    // Prepare the fields to update
    $updateFields = [];
    $params = ['id' => $student_id];

    // Map the fields that can be updated
    $allowedFields = [
        'name' => 'name',
        'date_of_birth' => 'date_of_birth',
        'gender' => 'gender',
        'phone' => 'phone',
        'email' => 'email',
        'program' => 'program',
        'final_registration_number' => 'final_registration_number'
    ];

    foreach ($allowedFields as $field => $dbColumn) {
        if (isset($data[$field])) {
            $updateFields[] = "$dbColumn = :$field";
            $params[$field] = $data[$field];
        }
    }

    if (empty($updateFields)) {
        jsonResponse("error", "No fields provided to update.", [], 400);
        exit;
    }

    try {
        $query = "UPDATE students SET " . implode(', ', $updateFields) . " WHERE id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        $log->info("Updated student ID $student_id: " . json_encode($params));
        jsonResponse("success", "Student details updated successfully.", [], 200);
    } catch (PDOException $e) {
        $log->error("Failed to update student ID $student_id: " . $e->getMessage());
        jsonResponse("error", "Failed to update student details.", [], 500);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
?>
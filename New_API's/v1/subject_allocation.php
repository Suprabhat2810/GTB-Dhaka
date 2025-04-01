<?php
require '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $user = authenticate('admin');

    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['student_id'], $data['subjects'])) {
        jsonResponse("error", "Missing student ID or subjects.", [], 400);
    }

    $studentId = filter_var($data['student_id'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    $subjects = $data['subjects'];

    if (!$studentId || !is_array($subjects) || empty($subjects)) {
        jsonResponse("error", "Invalid student ID or subjects.", [], 400);
    }

    try {
        $stmt = $pdo->prepare("SELECT final_registration_number FROM students WHERE id = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        if (!$student || !$student['final_registration_number']) {
            jsonResponse("error", "Student not found or registration not finalized.", [], 404);
        }

        // Validate subjects
        $stmt = $pdo->prepare("SELECT id FROM subjects WHERE subject_name IN (" . implode(',', array_fill(0, count($subjects), '?')) . ")");
        $stmt->execute($subjects);
        $validSubjects = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($validSubjects) !== count($subjects)) {
            jsonResponse("error", "Invalid subjects provided.", [], 400);
        }

        $subjectsJson = json_encode($subjects);
        $stmt = $pdo->prepare("INSERT INTO subject_allocations (student_id, subjects, allocation_date) VALUES (?, ?, NOW())");
        $stmt->execute([$studentId, $subjectsJson]);
        $allocationId = $pdo->lastInsertId();

        jsonResponse("success", "Subjects allocated successfully.", ["allocation_id" => $allocationId], 201);
    } catch (PDOException $e) {
        $log->error("Subject allocation failed: " . $e->getMessage());
        jsonResponse("error", "Failed to allocate subjects.", [], 500);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
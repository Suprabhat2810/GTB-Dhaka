<?php
require '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $user = authenticate('admin');

    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['student_id'], $data['subject_ids'])) {
        jsonResponse("error", "Missing student ID or subject IDs.", [], 400);
    }

    $studentId = filter_var($data['student_id'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    $subjectIds = $data['subject_ids'];

    if (!$studentId || !is_array($subjectIds) || empty($subjectIds)) {
        jsonResponse("error", "Invalid student ID or subject IDs.", [], 400);
    }

    try {
        // Check if student exists and registration is finalized
        $stmt = $pdo->prepare("SELECT program, semester, final_registration_number FROM students WHERE id = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        if (!$student || !$student['final_registration_number']) {
            jsonResponse("error", "Student not found or registration not finalized.", [], 404);
        }

        $program = $student['program'];
        $semester = $student['semester'] ?? 1; // Default to semester 1 if not set

        // Validate subject IDs and ensure they belong to the student's program and semester
        $placeholders = implode(',', array_fill(0, count($subjectIds), '?'));
        $stmt = $pdo->prepare("SELECT id FROM subjects WHERE id IN ($placeholders) AND department = ? AND semester = ?");
        $params = array_merge($subjectIds, [$program, $semester]);
        $stmt->execute($params);
        $validSubjects = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($validSubjects) !== count($subjectIds)) {
            jsonResponse("error", "One or more subjects are invalid or do not belong to the student's program and semester.", [], 400);
        }

        // Insert allocations into student_subjects
        $stmt = $pdo->prepare("INSERT INTO student_subjects (student_id, subject_id, allocation_date) VALUES (?, ?, NOW())");
        foreach ($subjectIds as $subjectId) {
            $stmt->execute([$studentId, $subjectId]);
        }

        jsonResponse("success", "Subjects allocated successfully.", [], 201);
    } catch (PDOException $e) {
        $log->error("Subject allocation failed: " . $e->getMessage());
        jsonResponse("error", "Failed to allocate subjects: " . $e->getMessage(), [], 500);
    }
} elseif ($method === 'GET') {
    $user = authenticate('admin');

    $studentId = filter_var($_GET['student_id'] ?? 0, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    if (!$studentId) {
        jsonResponse("error", "Invalid or missing student ID.", [], 400);
    }

    try {
        // Get student's program and semester
        $stmt = $pdo->prepare("SELECT program, semester FROM students WHERE id = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        if (!$student) {
            jsonResponse("error", "Student not found.", [], 404);
        }

        $program = $student['program'];
        $semester = $student['semester'] ?? 1;

        // Fetch available subjects for the student's program and semester
        $stmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE department = ? AND semester = ?");
        $stmt->execute([$program, $semester]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse("success", "Subjects retrieved successfully.", ["subjects" => $subjects]);
    } catch (PDOException $e) {
        $log->error("Failed to fetch subjects: " . $e->getMessage());
        jsonResponse("error", "Failed to fetch subjects: " . $e->getMessage(), [], 500);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
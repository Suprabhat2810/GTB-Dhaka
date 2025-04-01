<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $user = authenticate('student');

    try {
        // Fetch the student's program (department), semester, and year
        $stmt = $pdo->prepare("
            SELECT program, semester, year
            FROM students
            WHERE id = ?
        ");
        $stmt->execute([$user->id]);
        $student = $stmt->fetch();

        if (!$student) {
            jsonResponse("error", "Student not found.", [], 404);
        }

        // Fetch subjects with additional details from the subjects table
        $currentDate = date('Y-m-d');
        $stmt = $pdo->prepare("
            SELECT subject_name, subject_code, instructor, schedule, credits, progress
            FROM subjects
            WHERE department = ?
              AND semester = ?
              AND year = ?
              AND (valid_from IS NULL OR valid_from <= ?)
              AND (valid_to IS NULL OR valid_to >= ?)
        ");
        $stmt->execute([
            $student['program'], // Use program as department
            $student['semester'],
            $student['year'],
            $currentDate,
            $currentDate
        ]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($subjects)) {
            jsonResponse("success", "No subjects found for this semester.", [], 200);
        }

        // Calculate total credits
        $totalCredits = array_reduce($subjects, function ($sum, $subject) {
            return $sum + $subject['credits'];
        }, 0);

        $data = [
            'subjects' => $subjects,
            'total_credits' => $totalCredits,
            'total_subjects' => count($subjects)
        ];

        jsonResponse("success", "Subjects retrieved successfully.", $data, 200);
    } catch (PDOException $e) {
        $log->error("Failed to fetch student subjects: " . $e->getMessage());
        jsonResponse("error", "Failed to fetch subjects.", [], 500);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
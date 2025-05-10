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
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            jsonResponse("error", "Student not found.", [], 404);
            exit;
        }

        $programName = $student['program'];
        $semester = $student['semester'];
        $year = $student['year'];
        $currentDate = date('Y-m-d');

        // Fetch subjects with all relevant details
        $stmt = $pdo->prepare("
            SELECT 
                s.id,
                s.department,
                s.semester,
                s.year,
                s.subject_name,
                s.valid_from,
                s.valid_to,
                s.subject_code,
                s.instructor,
                s.schedule,
                s.credits,
                s.progress
            FROM subjects s
            WHERE s.department = ?
              AND s.semester = ?
              AND s.year = ?
              AND (s.valid_from IS NULL OR s.valid_from <= ?)
              AND (s.valid_to IS NULL OR s.valid_to >= ?)
        ");
        $stmt->execute([$programName, $semester, $year, $currentDate, $currentDate]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($subjects)) {
            jsonResponse("success", "No subjects found for this semester.", [
                'subjects' => [],
                'total_credits' => 0,
                'total_subjects' => 0
            ], 200);
            exit;
        }

        // Calculate total credits and total subjects
        $totalCredits = array_reduce($subjects, function ($sum, $subject) {
            return $sum + ($subject['credits'] ?? 0);
        }, 0);
        $totalSubjects = count($subjects);

        $data = [
            'subjects' => $subjects,
            'total_credits' => $totalCredits,
            'total_subjects' => $totalSubjects
        ];

        jsonResponse("success", "Subjects retrieved successfully.", $data, 200);
    } catch (PDOException $e) {
        $log->error("Failed to fetch student subjects: " . $e->getMessage());
        jsonResponse("error", "Failed to fetch subjects: " . $e->getMessage(), [], 500);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
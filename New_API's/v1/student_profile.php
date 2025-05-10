<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $user = authenticate('student');

    try {
        $stmt = $pdo->prepare("
            SELECT s.name, s.program, s.semester ,a.approved
            FROM students s
            LEFT JOIN approvals a ON s.id = a.student_id
            WHERE s.id = ?
        ");
        $stmt->execute([$user->id]);
        $profile = $stmt->fetch();

        if (!$profile) {
            jsonResponse("error", "Student not found.", [], 404);
        }

        // Split the full name into first_name and last_name
        $nameParts = explode(' ', $profile['name'], 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        $data = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'semester'=> $profile['semester'],
            'program' => $profile['program'],
            'status' => $profile['approved'] ? 'Approved' : 'Pending',
        ];

        jsonResponse("success", "Profile retrieved successfully.", $data, 200);
    } catch (PDOException $e) {
        $log->error("Failed to fetch student profile: " . $e->getMessage());
        jsonResponse("error", "Failed to fetch profile.", [], 500);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $user = authenticate('admin');

    try {
        // Total Students
        $stmt = $pdo->query("SELECT COUNT(*) FROM students");
        $totalStudents = $stmt->fetchColumn();

        // Approved Students
        $stmt = $pdo->query("SELECT COUNT(*) FROM approvals WHERE approved = 1");
        $approved = $stmt->fetchColumn();

        // Pending Students
        $stmt = $pdo->query("
            SELECT COUNT(*) 
            FROM students s
            LEFT JOIN approvals a ON s.id = a.student_id
            WHERE a.approved = 0 OR a.approved IS NULL
        ");
        $pending = $stmt->fetchColumn();

        // Total Subjects
        $stmt = $pdo->query("SELECT COUNT(*) FROM subjects");
        $totalSubjects = $stmt->fetchColumn();

        jsonResponse("success", "Statistics retrieved successfully.", [
            "totalStudents" => (int)$totalStudents,
            "approved" => (int)$approved,
            "pending" => (int)$pending,
            "totalSubjects" => (int)$totalSubjects,
        ], 200);
    } catch (PDOException $e) {
        $log->error("Failed to fetch statistics: " . $e->getMessage());
        jsonResponse("error", "Failed to fetch statistics: " . $e->getMessage(), [], 500);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
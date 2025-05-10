<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $user = authenticate('admin');
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['student_id'])) {
        jsonResponse("error", "Student ID is required.", [], 400);
    }

    $studentId = filter_var($data['student_id'], FILTER_VALIDATE_INT);

    if (!$studentId) {
        jsonResponse("error", "Invalid student ID.", [], 400);
    }

    try {
        $stmt = $pdo->prepare("SELECT name, date_of_birth FROM students WHERE id = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        if (!$student) {
            jsonResponse("error", "Student not found.", [], 404);
        }

        // Check if a birthday wish was already sent today
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE student_id = ? AND DATE(notification_date) = CURDATE()");
        $checkStmt->execute([$studentId]);
        $notificationCount = $checkStmt->fetchColumn();

        if ($notificationCount > 0) {
            jsonResponse("success", "Birthday wish already sent today.", ["student_name" => $student['name']], 200);
        }

        $message = "Happy Birthday, " . $student['name'] . "! Wishing you a fantastic day!";
        $stmt = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
        $stmt->execute([$studentId, $message]);

        jsonResponse("success", "Birthday wish sent successfully.", ["student_name" => $student['name']], 201);
    } catch (PDOException $e) {
        $log->error("Failed to send birthday notification: " . $e->getMessage());
        jsonResponse("error", "Failed to send birthday wish.", [], 500);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
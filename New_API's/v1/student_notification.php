<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $user = authenticate('student');

    try {
        // Fetch notifications for the logged-in student
        $stmt = $pdo->prepare("
            SELECT id, message, notification_date
            FROM notifications
            WHERE student_id = ?
            ORDER BY notification_date DESC
        ");
        $stmt->execute([$user->id]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = [
            'notifications' => $notifications,
            'total_notifications' => count($notifications)
        ];

        jsonResponse("success", "Notifications retrieved successfully.", $data, 200);
    } catch (PDOException $e) {
        $log->error("Failed to fetch student notifications: " . $e->getMessage());
        jsonResponse("error", "Failed to fetch notifications.", [], 500);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('student_notification');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

try {
    if ($method !== 'GET') {
        $logger->warning('student_notification: method not allowed', ['method' => $method]);
        jsonResponse("error", "Method not allowed.", [], 405);
    }

    $user = authenticate('student'); // requires student role
    if (!$user) {
        $logger->warning('student_notification: unauthenticated access attempt');
        jsonResponse("error", "Unauthorized.", [], 403);
    }

    // ✅ Get student ID safely from token (works for both "id" and "sub")
    $studentId = (int)($user->sub ?? $user->id ?? 0);
    if ($studentId <= 0) {
        $logger->warning('student_notification: invalid or missing student ID', ['user' => $user]);
        jsonResponse("error", "Invalid or missing student ID.", [], 403);
    }

    try {
        $logger->info('student_notification: fetching notifications', ['student_id' => $studentId]);

        // Fetch only notifications for this student
        $stmt = $pdo->prepare("
            SELECT id, message, notification_date
            FROM notifications
            WHERE student_id = ?
            ORDER BY notification_date DESC, id DESC
        ");
        $stmt->execute([$studentId]);

        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $data = [
            'notifications' => $notifications,
            'total_notifications' => count($notifications)
        ];

        $durationMs = round((microtime(true) - $start) * 1000, 2);
        $logger->info('student_notification: retrieved notifications', [
            'student_id' => $studentId,
            'count' => count($notifications),
            'duration_ms' => $durationMs
        ]);

        jsonResponse("success", "Notifications retrieved successfully.", $data, 200);
    } catch (PDOException $e) {
        $logger->error('student_notification: DB error', ['error' => $e->getMessage(), 'student_id' => $studentId]);
        jsonResponse("error", "Failed to fetch notifications.", [], 500);
    }
} catch (Exception $e) {
    $logger->critical('student_notification: unexpected error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    jsonResponse("error", "Internal server error.", [], 500);
}

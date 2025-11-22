<?php
// sendBirthdaynotification.php — hardened, logged, non-breaking replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('sendBirthdaynotification');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method !== 'POST') {
        $logger->warning('sendBirthdaynotification: method not allowed', ['method' => $method]);
        jsonResponse("error", "Method not allowed.", [], 405);
    }

    $user = authenticate('admin'); // restrict to admin
    $actor = $user->id ?? null;

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data) || !isset($data['student_id'])) {
        $logger->warning('sendBirthdaynotification: missing student_id', ['actor' => $actor, 'payload' => $data]);
        jsonResponse("error", "Student ID is required.", [], 400);
    }

    $studentId = filter_var($data['student_id'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    if (!$studentId) {
        $logger->warning('sendBirthdaynotification: invalid student_id', ['actor' => $actor, 'student_id_raw' => $data['student_id'] ?? null]);
        jsonResponse("error", "Invalid student ID.", [], 400);
    }

    // fetch student
    $stmt = $pdo->prepare("SELECT name, date_of_birth FROM students WHERE id = ? LIMIT 1");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        $logger->info('sendBirthdaynotification: student not found', ['actor' => $actor, 'student_id' => $studentId]);
        jsonResponse("error", "Student not found.", [], 404);
    }

    // Check if a birthday wish was already sent today
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE student_id = ? AND DATE(notification_date) = CURDATE()");
    $checkStmt->execute([$studentId]);
    $notificationCount = (int)$checkStmt->fetchColumn();

    if ($notificationCount > 0) {
        $logger->info('sendBirthdaynotification: already sent today', ['actor' => $actor, 'student_id' => $studentId, 'student_name' => $student['name']]);
        jsonResponse("success", "Birthday wish already sent today.", ["student_name" => $student['name']], 200);
    }

    // Insert notification
    $message = "Happy Birthday, " . ($student['name'] ?? 'Student') . "! Wishing you a fantastic day!";
    $insert = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
    $insert->execute([$studentId, $message]);

    $logger->info('sendBirthdaynotification: wish sent', ['actor' => $actor, 'student_id' => $studentId, 'student_name' => $student['name']]);
    jsonResponse("success", "Birthday wish sent successfully.", ["student_name" => $student['name']], 201);

} catch (PDOException $e) {
    $logger->error('sendBirthdaynotification: DB error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    jsonResponse("error", "Failed to send birthday wish.", [], 500);
} catch (Exception $e) {
    $logger->critical('sendBirthdaynotification: unexpected error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    jsonResponse("error", "Failed to send birthday wish.", [], 500);
}

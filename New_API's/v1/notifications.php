<?php
require_once '../config.php';
use PHPMailer\PHPMailer\PHPMailer;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $user = authenticate('admin');

    $data = json_decode(file_get_contents("php://input"), true);

    // Handle sending a new notification or resending an existing one
    if (isset($data['message'])) {
        if (!isset($data['message'])) {
            jsonResponse("error", "Message is required.", [], 400);
        }

        $message = trim($data['message']);
        if (empty($message)) {
            jsonResponse("error", "Message cannot be empty.", [], 400);
        }

        $studentIds = [];
        $program = isset($data['program']) ? trim($data['program']) : null;
        $semester = isset($data['semester']) ? filter_var($data['semester'], FILTER_VALIDATE_INT) : null;
        $sendToAll = isset($data['send_to_all']) ? filter_var($data['send_to_all'], FILTER_VALIDATE_BOOLEAN) : false;
        $specificStudentId = isset($data['student_id']) ? filter_var($data['student_id'], FILTER_VALIDATE_INT) : null;

        try {
            if ($sendToAll) {
                $stmt = $pdo->prepare("SELECT id, email FROM students");
                $stmt->execute();
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $studentIds = array_column($students, 'id');
                $studentEmails = array_column($students, 'email', 'id');
            } elseif ($program && $semester !== null) {
                $stmt = $pdo->prepare("SELECT id, email FROM students WHERE program = ? AND semester = ?");
                $stmt->execute([$program, $semester]);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $studentIds = array_column($students, 'id');
                $studentEmails = array_column($students, 'email', 'id');
            } elseif ($specificStudentId) {
                $stmt = $pdo->prepare("SELECT id, email FROM students WHERE id = ?");
                $stmt->execute([$specificStudentId]);
                $student = $stmt->fetch();
                if (!$student) {
                    jsonResponse("error", "Student not found.", [], 404);
                }
                $studentIds = [$student['id']];
                $studentEmails = [$student['id'] => $student['email']];
            } else {
                jsonResponse("error", "Must specify student_id, program and semester, or send_to_all.", [], 400);
            }

            if (empty($studentIds)) {
                jsonResponse("error", "No students found for the given criteria.", [], 404);
            }

            $notificationIds = [];
            $stmt = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
            foreach ($studentIds as $studentId) {
                $stmt->execute([$studentId, $message]);
                $notificationIds[] = $pdo->lastInsertId();
            }

            // Send emails
            // $mail = new PHPMailer(true);
            // $mail->isSMTP();
            // $mail->Host = 'smtp.example.com'; // Update with your SMTP host
            // $mail->SMTPAuth = true;
            // $mail->Username = 'your_email@example.com'; // Update with your email
            // $mail->Password = 'your_password'; // Update with your password
            // $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            // $mail->Port = 587;

            // $mail->setFrom('no-reply@yourdomain.com', 'Your Institution');
            // $mail->Subject = 'Registration Update';
            // $mail->Body = $message;

            // foreach ($studentIds as $studentId) {
            //     $email = $studentEmails[$studentId];
            //     $mail->addAddress($email);
            //     $mail->send();
            //     $mail->clearAddresses();
            // }

            jsonResponse("success", "Notifications sent successfully.", ["notification_ids" => $notificationIds], 201);
        } catch (Exception $e) {
            $log->error("Notification failed: " . $e->getMessage());
            jsonResponse("error", "Failed to send notifications.", [], 500);
        }
    } elseif (isset($data['resend_id'])) {
        // Handle resending an existing notification
        $notificationId = filter_var($data['resend_id'], FILTER_VALIDATE_INT);
        if (!$notificationId) {
            jsonResponse("error", "Invalid notification ID.", [], 400);
        }

        try {
            $stmt = $pdo->prepare("SELECT student_id, message FROM notifications WHERE id = ?");
            $stmt->execute([$notificationId]);
            $notification = $stmt->fetch();
            if (!$notification) {
                jsonResponse("error", "Notification not found.", [], 404);
            }

            $stmt = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
            $stmt->execute([$notification['student_id'], $notification['message']]);
            $newNotificationId = $pdo->lastInsertId();

            // Resend email
            // $mail = new PHPMailer(true);
            // $mail->isSMTP();
            // $mail->Host = 'smtp.example.com'; // Update with your SMTP host
            // $mail->SMTPAuth = true;
            // $mail->Username = 'your_email@example.com'; // Update with your email
            // $mail->Password = 'your_password'; // Update with your password
            // $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            // $mail->Port = 587;

            // $mail->setFrom('no-reply@yourdomain.com', 'Your Institution');
            // $mail->Subject = 'Registration Update';
            // $mail->Body = $notification['message'];

            // $stmt = $pdo->prepare("SELECT email FROM students WHERE id = ?");
            // $stmt->execute([$notification['student_id']]);
            // $email = $stmt->fetchColumn();
            // $mail->addAddress($email);
            // $mail->send();
            // $mail->clearAddresses();

            jsonResponse("success", "Notification resent successfully.", ["new_notification_id" => $newNotificationId], 201);
        } catch (Exception $e) {
            $log->error("Resend failed: " . $e->getMessage());
            jsonResponse("error", "Failed to resend notification.", [], 500);
        }
    }
} elseif ($method === 'DELETE') {
    $user = authenticate('admin');

    // Get ID from query parameter or request body
    $notificationId = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : (json_decode(file_get_contents("php://input"), true)['id'] ?? null);
    if (!$notificationId) {
        jsonResponse("error", "Invalid notification ID.", [], 400);
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ?");
        $stmt->execute([$notificationId]);
        if ($stmt->rowCount() === 0) {
            jsonResponse("error", "Notification not found.", [], 404);
        }
        jsonResponse("success", "Notification deleted successfully.", [], 200);
    } catch (PDOException $e) {
        $log->error("Delete failed: " . $e->getMessage());
        jsonResponse("error", "Failed to delete notification.", [], 500);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
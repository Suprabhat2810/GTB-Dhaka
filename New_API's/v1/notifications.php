<?php
require_once '../config.php';
use PHPMailer\PHPMailer\PHPMailer;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $user = authenticate('admin');

    $data = json_decode(file_get_contents("php://input"), true);
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
        // Determine which students to send the notification to
        if ($sendToAll) {
            // Send to all students
            $stmt = $pdo->prepare("SELECT id, email FROM students");
            $stmt->execute();
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $studentIds = array_column($students, 'id');
            $studentEmails = array_column($students, 'email', 'id');
        } elseif ($program && $semester !== null) {
            // Send to students in a specific program and semester
            $stmt = $pdo->prepare("SELECT id, email FROM students WHERE program = ? AND semester = ?");
            $stmt->execute([$program, $semester]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $studentIds = array_column($students, 'id');
            $studentEmails = array_column($students, 'email', 'id');
        } elseif ($specificStudentId) {
            // Send to a specific student
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

        // Insert notifications into the database
        $notificationIds = [];
        $stmt = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
        foreach ($studentIds as $studentId) {
            $stmt->execute([$studentId, $message]);
            $notificationIds[] = $pdo->lastInsertId();
        }

        // Send emails to all selected students
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.example.com'; // Update with your SMTP host
        $mail->SMTPAuth = true;
        $mail->Username = 'your_email@example.com'; // Update with your email
        $mail->Password = 'your_password'; // Update with your password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('no-reply@yourdomain.com', 'Your Institution');
        $mail->Subject = 'Registration Update';
        $mail->Body = $message;

        foreach ($studentIds as $studentId) {
            $email = $studentEmails[$studentId];
            $mail->addAddress($email);
            $mail->send();
            $mail->clearAddresses(); // Clear the address for the next email
        }

        jsonResponse("success", "Notifications sent successfully.", ["notification_ids" => $notificationIds], 201);
    } catch (Exception $e) {
        $log->error("Notification failed: " . $e->getMessage());
        jsonResponse("error", "Failed to send notifications.", [], 500);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
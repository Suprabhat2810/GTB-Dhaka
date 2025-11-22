<?php
// notifications.php — hardened, logged, non-breaking replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

$logger = getLogger('notifications');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

try {
    if ($method === 'POST') {
        $admin = authenticate('admin');
        $actor = $admin->id ?? null;

        $data = json_decode(file_get_contents("php://input"), true);
        if (!is_array($data)) {
            $logger->warning('notifications POST: invalid json payload', ['actor' => $actor]);
            jsonResponse("error", "Invalid JSON payload.", [], 400);
        }

        // === New notification flow ===
        if (isset($data['message'])) {
            $message = trim((string)$data['message']);
            if ($message === '') {
                $logger->warning('notifications POST: empty message', ['actor' => $actor]);
                jsonResponse("error", "Message cannot be empty.", [], 400);
            }

            $program = isset($data['program']) ? trim((string)$data['program']) : null;
            $semester = isset($data['semester']) ? filter_var($data['semester'], FILTER_VALIDATE_INT) : null;
            $sendToAll = isset($data['send_to_all']) ? filter_var($data['send_to_all'], FILTER_VALIDATE_BOOLEAN) : false;
            $specificStudentId = isset($data['student_id']) ? filter_var($data['student_id'], FILTER_VALIDATE_INT) : null;

            try {
                // Resolve recipients safely
                $studentIds = [];
                $studentEmails = [];

                if ($sendToAll) {
                    $logger->info('notifications POST: sending to all students', ['actor' => $actor]);
                    $stmt = $pdo->prepare("SELECT id, email FROM students");
                    $stmt->execute();
                    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } elseif ($program && $semester !== null) {
                    $logger->info('notifications POST: sending to program+semester', ['program' => $program, 'semester' => $semester, 'actor' => $actor]);
                    $stmt = $pdo->prepare("SELECT id, email FROM students WHERE program = ? AND semester = ?");
                    $stmt->execute([$program, $semester]);
                    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } elseif ($specificStudentId) {
                    $logger->info('notifications POST: sending to specific student', ['student_id' => $specificStudentId, 'actor' => $actor]);
                    $stmt = $pdo->prepare("SELECT id, email FROM students WHERE id = ?");
                    $stmt->execute([$specificStudentId]);
                    $student = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$student) {
                        $logger->info('notifications POST: specific student not found', ['student_id' => $specificStudentId, 'actor' => $actor]);
                        jsonResponse("error", "Student not found.", [], 404);
                    }
                    $students = [$student];
                } else {
                    $logger->warning('notifications POST: missing recipient criteria', ['actor' => $actor]);
                    jsonResponse("error", "Must specify student_id, program and semester, or send_to_all.", [], 400);
                }

                if (empty($students)) {
                    $logger->info('notifications POST: no students matched criteria', ['actor' => $actor]);
                    jsonResponse("error", "No students found for the given criteria.", [], 404);
                }

                // Limit bulk size to prevent floods (configurable): safe-guard
                $maxBatch = 2000;
                if (count($students) > $maxBatch) {
                    $logger->warning('notifications POST: recipient list exceeds max batch', ['count' => count($students), 'max' => $maxBatch, 'actor' => $actor]);
                    jsonResponse("error", "Recipient list too large. Please target smaller groups.", [], 400);
                }

                foreach ($students as $s) {
                    $sid = (int)$s['id'];
                    $studentIds[] = $sid;
                    $studentEmails[$sid] = $s['email'] ?? null;
                }

                // Use transaction for batch inserts to keep atomicity
                $pdo->beginTransaction();
                $insertStmt = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
                $notificationIds = [];
                foreach ($studentIds as $sid) {
                    $insertStmt->execute([$sid, $message]);
                    $notificationIds[] = $pdo->lastInsertId();
                }
                $pdo->commit();

                $logger->info('notifications POST: notifications inserted', ['count' => count($notificationIds), 'actor' => $actor]);

                // Email sending (commented) — kept as legacy placeholder
                /*
                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = 'smtp.example.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'your_email@example.com';
                    $mail->Password = 'your_password';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;

                    $mail->setFrom('no-reply@yourdomain.com', 'Your Institution');
                    $mail->Subject = 'Notification';
                    $mail->Body = $message;

                    foreach ($studentIds as $sid) {
                        $email = $studentEmails[$sid] ?? null;
                        if (!$email) continue;
                        $mail->addAddress($email);
                        $mail->send();
                        $mail->clearAddresses();
                    }
                } catch (Exception $e) {
                    // log email errors but do not fail the main operation
                    $logger->warning('notifications POST: email sending failed (non-fatal)', ['error' => $e->getMessage(), 'actor' => $actor]);
                }
                */

                $durationMs = round((microtime(true) - $start) * 1000, 2);
                $logger->info('notifications POST: completed', ['sent' => count($notificationIds), 'duration_ms' => $durationMs, 'actor' => $actor]);

                jsonResponse("success", "Notifications sent successfully.", ["notification_ids" => $notificationIds], 201);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $logger->error('notifications POST: DB error', ['error' => $e->getMessage(), 'actor' => $actor]);
                jsonResponse("error", "Failed to send notifications.", [], 500);
            }
        }

        // === Resend single notification flow ===
        if (isset($data['resend_id'])) {
            $notificationId = filter_var($data['resend_id'], FILTER_VALIDATE_INT);
            if (!$notificationId) {
                $logger->warning('notifications POST: invalid resend_id', ['actor' => $actor, 'resend_id' => $data['resend_id'] ?? null]);
                jsonResponse("error", "Invalid notification ID.", [], 400);
            }

            try {
                $stmt = $pdo->prepare("SELECT student_id, message FROM notifications WHERE id = ?");
                $stmt->execute([$notificationId]);
                $notification = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$notification) {
                    $logger->info('notifications POST: notification to resend not found', ['resend_id' => $notificationId, 'actor' => $actor]);
                    jsonResponse("error", "Notification not found.", [], 404);
                }

                $pdo->beginTransaction();
                $stmtIns = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
                $stmtIns->execute([(int)$notification['student_id'], $notification['message']]);
                $newNotificationId = $pdo->lastInsertId();
                $pdo->commit();

                // Email resend (commented placeholder)
                /*
                try {
                    $mail = new PHPMailer(true);
                    // configure and send...
                } catch (Exception $e) {
                    $logger->warning('notifications POST: email resend failed (non-fatal)', ['error' => $e->getMessage(), 'actor' => $actor]);
                }
                */

                $logger->info('notifications POST: resent notification', ['old_id' => $notificationId, 'new_id' => $newNotificationId, 'actor' => $actor]);
                jsonResponse("success", "Notification resent successfully.", ["new_notification_id" => $newNotificationId], 201);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $logger->error('notifications POST: resend DB error', ['error' => $e->getMessage(), 'actor' => $actor]);
                jsonResponse("error", "Failed to resend notification.", [], 500);
            }
        }

        // If we reach here, the payload didn't match expected shapes
        $logger->warning('notifications POST: unsupported payload shape', ['actor' => $actor, 'payload' => $data]);
        jsonResponse("error", "Unsupported request.", [], 400);
    }

    // === DELETE ===
    elseif ($method === 'DELETE') {
        $admin = authenticate('admin');
        $actor = $admin->id ?? null;

        // Accept ID from query param or JSON body
        $rawId = isset($_GET['id']) ? $_GET['id'] : null;
        if ($rawId === null) {
            $body = json_decode(file_get_contents("php://input"), true);
            $rawId = $body['id'] ?? null;
        }
        $notificationId = filter_var($rawId, FILTER_VALIDATE_INT);
        if (!$notificationId) {
            $logger->warning('notifications DELETE: invalid id', ['actor' => $actor, 'raw' => $rawId]);
            jsonResponse("error", "Invalid notification ID.", [], 400);
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ?");
            $stmt->execute([$notificationId]);
            if ($stmt->rowCount() === 0) {
                $logger->info('notifications DELETE: not found', ['notification_id' => $notificationId, 'actor' => $actor]);
                jsonResponse("error", "Notification not found.", [], 404);
            }
            $logger->info('notifications DELETE: deleted', ['notification_id' => $notificationId, 'actor' => $actor]);
            jsonResponse("success", "Notification deleted successfully.", [], 200);
        } catch (PDOException $e) {
            $logger->error('notifications DELETE: DB error', ['error' => $e->getMessage(), 'actor' => $actor]);
            jsonResponse("error", "Failed to delete notification.", [], 500);
        }
    } elseif ($method === 'GET') {
    $user = authenticate('admin');

    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // Optional filters
    $program  = $_GET['program']  ?? null;
    $semester = isset($_GET['semester']) ? (int)$_GET['semester'] : null;
    $studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : null;

    try {
        $where = [];
        $params = [];

        if ($studentId) {
            $where[] = "n.student_id = ?";
            $params[] = $studentId;
        }
        if ($program) {
            $where[] = "s.program = ?";
            $params[] = $program;
        }
        if ($semester !== null) {
            $where[] = "s.semester = ?";
            $params[] = $semester;
        }

        $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

        $sql = "
            SELECT n.id, n.student_id, n.message, n.notification_date,
                   s.name AS student_name, s.program, s.semester
            FROM notifications n
            JOIN students s ON s.id = n.student_id
            $whereSql
            ORDER BY n.notification_date DESC, n.id DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $i => $p) {
            $stmt->bindValue($i+1, $p);
        }
        $stmt->bindValue(count($params)+1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(count($params)+2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cntSql = "
            SELECT COUNT(*)
            FROM notifications n
            JOIN students s ON s.id = n.student_id
            $whereSql
        ";
        $cnt = $pdo->prepare($cntSql);
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        jsonResponse("success", "Notifications retrieved.", [
            "notifications" => $rows,
            "meta" => ["page"=>$page, "limit"=>$limit, "total"=>$total]
        ]);
    } catch (PDOException $e) {
        $log->error("Failed to fetch notifications (admin): ".$e->getMessage());
        jsonResponse("error", "Failed to fetch notifications.", [], 500);
    }
    }

    // === Unsupported method ===
    else {
        $logger->warning('notifications: method not allowed', ['method' => $method]);
        jsonResponse("error", "Method not allowed.", [], 405);
    }
} catch (Exception $e) {
    $logger->critical('notifications: unexpected error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    jsonResponse("error", "Internal server error.", [], 500);
}

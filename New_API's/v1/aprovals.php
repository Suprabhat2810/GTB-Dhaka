<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'PUT') {
    // Extract student_id from the query parameter
    $studentId = filter_var($_GET['student_id'] ?? 0, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    if (!$studentId) {
        jsonResponse("error", "Invalid or missing student ID.", [], 400);
    }

    $user = authenticate('admin');

    $data = json_decode(file_get_contents("php://input"), true);
    $adminId = filter_var($data['admin_id'] ?? 0, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    if (!$adminId) {
        jsonResponse("error", "Invalid or missing admin ID.", [], 400);
    }

    try {
        $checkStudent = $pdo->prepare("SELECT 1 FROM students WHERE id = ?");
        $checkStudent->execute([$studentId]);
        if (!$checkStudent->fetch()) {
            jsonResponse("error", "Student not found.", [], 404);
        }

        $checkAdmin = $pdo->prepare("SELECT 1 FROM admins WHERE id = ?");
        $checkAdmin->execute([$adminId]);
        if (!$checkAdmin->fetch()) {
            jsonResponse("error", "Admin not found.", [], 404);
        }

        $checkApproval = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ?");
        $checkApproval->execute([$studentId]);
        $approval = $checkApproval->fetch();
        if ($approval && $approval['approved'] == 1) {
            jsonResponse("error", "Student already approved.", [], 400);
        }

        $stmt = $pdo->prepare("
            INSERT INTO approvals (student_id, admin_id, approved, approval_date)
            VALUES (?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE
            admin_id = ?, approved = 1, approval_date = NOW()
        ");
        $stmt->execute([$studentId, $adminId, $adminId]);

        // Trigger notification
        $stmt = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
        $stmt->execute([$studentId, "Your registration has been approved. Please upload documents and make payment."]);

        jsonResponse("success", "Student approved successfully.", ["student_id" => $studentId]);
    } catch (PDOException $e) {
        $log->error("Approval failed: " . $e->getMessage());
        jsonResponse("error", "Approval failed.", [], 500);
    }
} elseif ($method === 'GET' && isset($_GET['pending'])) {
    $user = authenticate('admin');

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $stmt = $pdo->prepare("
        SELECT s.id, s.name, s.email, s.program 
        FROM students s
        LEFT JOIN approvals a ON s.id = a.student_id
        WHERE a.approved = 0 OR a.approved IS NULL
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$limit, $offset]);
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT COUNT(*) 
        FROM students s
        LEFT JOIN approvals a ON s.id = a.student_id
        WHERE a.approved = 0 OR a.approved IS NULL
    ");
    $total = $stmt->fetchColumn();

    jsonResponse("success", "Pending approvals retrieved successfully.", [
        "pending" => $pending,
        "meta" => ["page" => $page, "limit" => $limit, "total" => $total]
    ]);
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
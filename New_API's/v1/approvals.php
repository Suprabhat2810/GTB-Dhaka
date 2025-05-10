<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$uriParts = explode('/', parse_url($uri, PHP_URL_PATH));
$endpoint = end($uriParts);

if ($method === 'PUT') {
    $studentId = filter_var($_GET['student_id'] ?? 0, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    if (!$studentId) {
        jsonResponse("error", "Invalid or missing student ID.", [], 400);
    }

    $action = $_GET['action'] ?? 'approve';
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

        if ($action === 'approve') {
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

            // Pre-populate personal_info with existing student data
            $stmt = $pdo->prepare("SELECT name, gender, date_of_birth FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            $studentData = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                INSERT INTO personal_info (student_id, name, gender, date_of_birth)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                name = VALUES(name), gender = VALUES(gender), date_of_birth = VALUES(date_of_birth)
            ");
            $stmt->execute([$studentId, $studentData['name'], $studentData['gender'], $studentData['date_of_birth']]);

            // Trigger notification
            $stmt = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
            $stmt->execute([$studentId, "Your registration has been approved. Please complete your final registration details."]);

            jsonResponse("success", "Student approved successfully.", ["student_id" => $studentId]);
        } elseif ($action === 'reject') {
            $checkApproval = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ?");
            $checkApproval->execute([$studentId]);
            $approval = $checkApproval->fetch();
            if ($approval && $approval['approved'] == 1) {
                jsonResponse("error", "Student already approved, cannot reject.", [], 400);
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("DELETE FROM approvals WHERE student_id = ?");
            $stmt->execute([$studentId]);
            $stmt->prepare("DELETE FROM personal_info WHERE student_id = ?");
            $stmt->execute([$studentId]);
            $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            $pdo->commit();

            $stmt = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
            $stmt->execute([$studentId, "Your registration has been rejected."]);

            jsonResponse("success", "Student rejected successfully.", ["student_id" => $studentId]);
        } else {
            jsonResponse("error", "Invalid action.", [], 400);
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $log->error("Approval/Rejection failed: " . $e->getMessage());
        jsonResponse("error", "Operation failed: " . $e->getMessage(), [], 500);
    }
} elseif ($method === 'POST' && $endpoint === 'final-registration') {
    $data = json_decode(file_get_contents("php://input"), true);
    $user = authenticate('student');

    $requiredFields = ['fatherName', 'motherName', 'casteCategory', 'phone', 'aadhaarNumber', 'address', 'previousBoardUniversity', 'lastClassResult', 'subjectsPapers'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            jsonResponse("error", "Missing required field: $field", [], 400);
        }
    }

    $studentId = $user->id;
    $fatherName = filter_var($data['fatherName'], FILTER_SANITIZE_STRING);
    $motherName = filter_var($data['motherName'], FILTER_SANITIZE_STRING);
    $casteCategory = filter_var($data['casteCategory'], FILTER_SANITIZE_STRING);
    $phone = filter_var($data['phone'], FILTER_SANITIZE_STRING);
    $aadhaarNumber = filter_var($data['aadhaarNumber'], FILTER_SANITIZE_STRING);
    $address = filter_var($data['address'], FILTER_SANITIZE_STRING);
    $previousBoardUniversity = filter_var($data['previousBoardUniversity'], FILTER_SANITIZE_STRING);
    $lastClassResult = filter_var($data['lastClassResult'], FILTER_SANITIZE_STRING);
    $subjectsPapers = filter_var($data['subjectsPapers'], FILTER_SANITIZE_STRING);
    $additionalSubjects = filter_var($data['additionalSubjects'] ?? '', FILTER_SANITIZE_STRING);

    if (!preg_match('/^\+91\d{10}$/', $phone)) {
        jsonResponse("error", "Invalid phone number format. Use +91 followed by 10 digits.", [], 400);
    }
    if (!preg_match('/^\d{12}$/', $aadhaarNumber)) {
        jsonResponse("error", "Invalid Aadhaar number format. Use 12 digits.", [], 400);
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO personal_info (student_id, father_name, mother_name, caste_category, phone, aadhaar_number, address, previous_board_university, last_class_result, subjects_papers, additional_subjects)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            father_name = VALUES(father_name),
            mother_name = VALUES(mother_name),
            caste_category = VALUES(caste_category),
            phone = VALUES(phone),
            aadhaar_number = VALUES(aadhaar_number),
            address = VALUES(address),
            previous_board_university = VALUES(previous_board_university),
            last_class_result = VALUES(last_class_result),
            subjects_papers = VALUES(subjects_papers),
            additional_subjects = VALUES(additional_subjects)
        ");
        $stmt->execute([
            $studentId,
            $fatherName,
            $motherName,
            $casteCategory,
            $phone,
            $aadhaarNumber,
            $address,
            $previousBoardUniversity,
            $lastClassResult,
            $subjectsPapers,
            $additionalSubjects
        ]);

        jsonResponse("success", "Final registration details submitted successfully.", [], 200);
    } catch (PDOException $e) {
        $log->error("Failed to submit final registration details: " . $e->getMessage());
        jsonResponse("error", "Failed to submit final registration details.", [], 500);
    }
} elseif ($method === 'GET' && isset($_GET['pending'])) {
    $user = authenticate('admin');

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    try {
        $log->info("Fetching pending approvals with page: $page, limit: $limit, offset: $offset");

        $stmt = $pdo->prepare("
            SELECT s.id, s.name, s.email, s.program 
            FROM students s
            LEFT JOIN approvals a ON s.id = a.student_id
            WHERE a.approved = 0 OR a.approved IS NULL
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(2, (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pending as &$student) {
            $student['documents'] = [];
        }

        $log->info("Pending students fetched: " . json_encode($pending));

        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM students s
            LEFT JOIN approvals a ON s.id = a.student_id
            WHERE a.approved = 0 OR a.approved IS NULL
        ");
        $stmt->execute();
        $total = $stmt->fetchColumn();

        jsonResponse("success", "Pending approvals retrieved successfully.", [
            "pending" => $pending,
            "meta" => ["page" => $page, "limit" => $limit, "total" => $total]
        ]);
    } catch (PDOException $e) {
        $log->error("Failed to fetch pending approvals: " . $e->getMessage());
        jsonResponse("error", "Failed to fetch pending approvals: " . $e->getMessage(), [], 500);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
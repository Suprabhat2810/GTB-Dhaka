<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['name'], $data['email'], $data['phone'], $data['program'], $data['password'])) {
        jsonResponse("error", "Missing required fields.", [], 400);
    }

    $name = filter_var($data['name'], FILTER_SANITIZE_STRING);
    $email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);
    $phone = filter_var($data['phone'], FILTER_SANITIZE_STRING);
    $state = filter_var($data['state'] ?? null, FILTER_SANITIZE_STRING);
    $gender = filter_var($data['gender'] ?? null, FILTER_SANITIZE_STRING);
    $qualification = filter_var($data['qualification'] ?? null, FILTER_SANITIZE_STRING);
    $program = filter_var($data['program'], FILTER_SANITIZE_STRING);
    $password = password_hash($data['password'], PASSWORD_BCRYPT);

    if (!$email) {
        jsonResponse("error", "Invalid email format.", [], 400);
    }

    try {
        $stmt = $pdo->prepare("SELECT 1 FROM students WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            jsonResponse("error", "Email already registered.", [], 400);
        }

        $temporarySerialNumber = "TEMP" . substr(md5(uniqid()), 0, 10);
        $stmt = $pdo->prepare("
            INSERT INTO students (name, email, phone, state, gender, qualification, program, temporary_serial_number, password)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $email, $phone, $state, $gender, $qualification, $program, $temporarySerialNumber, $password]);
        $studentId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO approvals (student_id, approved) VALUES (?, 0)");
        $stmt->execute([$studentId]);

        jsonResponse("success", "Student registered successfully.", [
            "temporary_serial_number" => $temporarySerialNumber,
            "student_id" => $studentId
        ], 201);
    } catch (PDOException $e) {
        $log->error("Registration failed: " . $e->getMessage());
        jsonResponse("error", "Registration failed.", [], 500);
    }
} elseif($method === 'GET') {
    // Extract student_id from the URL path
    $uri = $_SERVER['REQUEST_URI'];
    $uriParts = explode('/', parse_url($uri, PHP_URL_PATH));
    $studentId = filter_var(end($uriParts), FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);

    if (!$studentId) {
        jsonResponse("error", "Invalid or missing student ID.", [], 400);
    }

    $user = authenticate(); // Allow both student and admin to access

    // Check if the user is authorized to view this student's details
    if ($user->role === 'student' && $user->id !== $studentId) {
        jsonResponse("error", "Unauthorized access.", [], 403);
    }

    if (isset($_GET['status']) && $_GET['status'] === 'true') {
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            if (!$stmt->fetch()) {
                jsonResponse("error", "Student not found.", [], 404);
            }

            $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ?");
            $stmt->execute([$studentId]);
            $approval = $stmt->fetch();
            $approved = $approval && $approval['approved'] == 1 ? "Yes" : "No";

            $stmt = $pdo->prepare("SELECT payment_received FROM payments WHERE student_id = ?");
            $stmt->execute([$studentId]);
            $payment = $stmt->fetch();
            $paymentReceived = $payment && $payment['payment_received'] == 1 ? "Yes" : "No";

            $stmt = $pdo->prepare("SELECT final_registration_number FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch();
            $finalized = $student['final_registration_number'] ? "Yes" : "No";

            jsonResponse("success", "Status retrieved successfully.", [
                "student_id" => $studentId,
                "approved" => $approved,
                "payment_received" => $paymentReceived,
                "finalized" => $finalized
            ]);
        } catch (PDOException $e) {
            $log->error("Status retrieval failed: " . $e->getMessage());
            jsonResponse("error", "Status retrieval failed.", [], 500);
        }
    } elseif ($finalize) {
        $user = authenticate('admin');

        try {
            $stmt = $pdo->prepare("SELECT 1 FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            if (!$stmt->fetch()) {
                jsonResponse("error", "Student not found.", [], 404);
            }

            $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ?");
            $stmt->execute([$studentId]);
            $approval = $stmt->fetch();
            if (!$approval || $approval['approved'] != 1) {
                jsonResponse("error", "Student not approved yet.", [], 400);
            }

            $stmt = $pdo->prepare("SELECT payment_received FROM payments WHERE student_id = ?");
            $stmt->execute([$studentId]);
            $payment = $stmt->fetch();
            if (!$payment || $payment['payment_received'] != 1) {
                jsonResponse("error", "Payment not received yet.", [], 400);
            }

            $stmt = $pdo->prepare("SELECT final_registration_number FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch();
            if ($student['final_registration_number']) {
                jsonResponse("error", "Registration already finalized.", [], 400);
            }

            $serial = str_pad($studentId, 2, "0", STR_PAD_LEFT);
            $month = date('m');
            $year = date('y');
            $finalRegistrationNumber = "GTB{$serial}{$month}{$year}";

            $stmt = $pdo->prepare("UPDATE students SET final_registration_number = ? WHERE id = ?");
            $stmt->execute([$finalRegistrationNumber, $studentId]);

            jsonResponse("success", "Registration finalized successfully.", [
                "final_registration_number" => $finalRegistrationNumber
            ]);
        } catch (PDOException $e) {
            $log->error("Finalization failed: " . $e->getMessage());
            jsonResponse("error", "Finalization failed.", [], 500);
        }
    } else {
        $user = authenticate();
        if ($user->role === 'student' && $user->student_id != $studentId) {
            jsonResponse("error", "Unauthorized access.", [], 403);
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$student) {
                jsonResponse("error", "Student not found.", [], 404);
            }

            jsonResponse("success", "Student retrieved successfully.", ["student" => $student]);
        } catch (PDOException $e) {
            $log->error("Student retrieval failed: " . $e->getMessage());
            jsonResponse("error", "Student retrieval failed.", [], 500);
        }
    }
} elseif ($method === 'PUT') {
    // Extract student_id from the URL path
    $uri = $_SERVER['REQUEST_URI'];
    $uriParts = explode('/', parse_url($uri, PHP_URL_PATH));
    $studentId = filter_var(end($uriParts), FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);

    if (!$studentId) {
        jsonResponse("error", "Invalid student ID.", [], 400);
    }

    $finalize = isset($_GET['finalize']) && $_GET['finalize'] === 'true';
    if ($finalize) {
        $user = authenticate('admin');

        try {
            $stmt = $pdo->prepare("SELECT 1 FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            if (!$stmt->fetch()) {
                jsonResponse("error", "Student not found.", [], 404);
            }

            $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ?");
            $stmt->execute([$studentId]);
            $approval = $stmt->fetch();
            if (!$approval || $approval['approved'] != 1) {
                jsonResponse("error", "Student not approved yet.", [], 400);
            }

            $stmt = $pdo->prepare("SELECT payment_received FROM payments WHERE student_id = ?");
            $stmt->execute([$studentId]);
            $payment = $stmt->fetch();
            if (!$payment || $payment['payment_received'] != 1) {
                jsonResponse("error", "Payment not received yet.", [], 400);
            }

            $stmt = $pdo->prepare("SELECT final_registration_number FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch();
            if ($student['final_registration_number']) {
                jsonResponse("error", "Registration already finalized.", [], 400);
            }

            $serial = str_pad($studentId, 2, "0", STR_PAD_LEFT);
            $month = date('m');
            $year = date('y');
            $finalRegistrationNumber = "GTB{$serial}{$month}{$year}";

            $stmt = $pdo->prepare("UPDATE students SET final_registration_number = ? WHERE id = ?");
            $stmt->execute([$finalRegistrationNumber, $studentId]);

            jsonResponse("success", "Registration finalized successfully.", [
                "final_registration_number" => $finalRegistrationNumber
            ]);
        } catch (PDOException $e) {
            $log->error("Finalization failed: " . $e->getMessage());
            jsonResponse("error", "Finalization failed.", [], 500);
        }
    } else {
        jsonResponse("error", "Method not allowed for this action.", [], 405);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$uriParts = explode('/', parse_url($uri, PHP_URL_PATH));
$endpoint = end($uriParts); // Get the last part of the URI (e.g., 'all_students', 'all_semesters', or student ID)

if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['name'], $data['email'], $data['phone'], $data['program'], $data['password'])) {
        jsonResponse("error", "Missing required fields.", [], 400);
    }

    $name = filter_var($data['name'], FILTER_SANITIZE_STRING);
    $email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);
    $phone = filter_var($data['phone'], FILTER_SANITIZE_STRING);
    $alternatePhone = filter_var($data['alternatePhone'] ?? null, FILTER_SANITIZE_STRING);
    $state = filter_var($data['state'] ?? null, FILTER_SANITIZE_STRING);
    $gender = filter_var($data['gender'] ?? null, FILTER_SANITIZE_STRING);
    $qualification = filter_var($data['qualification'] ?? null, FILTER_SANITIZE_STRING);
    $program = filter_var($data['program'], FILTER_SANITIZE_STRING);
    $password = $data['password']; // Store plain password temporarily for WhatsApp
    $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);
    $date_of_birth = isset($data['date_of_birth']) ? filter_var($data['date_of_birth'], FILTER_SANITIZE_STRING) : null;

    // Validate date_of_birth if provided
    if ($date_of_birth && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_of_birth)) {
        jsonResponse("error", "Invalid date of birth format. Use YYYY-MM-DD.", [], 400);
    }

    // Validate phone numbers
    if (!preg_match('/^\+91\d{10}$/', $phone)) {
        jsonResponse("error", "Invalid primary phone number format. Use +91 followed by 10 digits.", [], 400);
    }
    if ($alternatePhone && !preg_match('/^\+91\d{10}$/', $alternatePhone)) {
        jsonResponse("error", "Invalid alternate phone number format. Use +91 followed by 10 digits.", [], 400);
    }

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
            INSERT INTO students (name, email, phone, alternatePhone, date_of_birth, state, gender, qualification, program, temporary_serial_number, password)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $email, $phone, $alternatePhone, $date_of_birth, $state, $gender, $qualification, $program, $temporarySerialNumber, $hashedPassword]);
        $studentId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO approvals (student_id, approved) VALUES (?, 0)");
        $stmt->execute([$studentId]);

        // Craft WhatsApp message
        $institutionName = "Global Tech Institute"; // Replace with your institution name
        $message = "Welcome to {$institutionName},\nDear {$name},\nThank you for registering with us! Your Temporary Serial Number is {$temporarySerialNumber} and your password is {$password}. Please log in to continue your journey with us!";

        // Attempt to send WhatsApp message (commented out without Twilio)
        $sentWelcomeMessage = 0; // Default to FALSE
        /*
        // Placeholder for Twilio integration (uncomment and configure when ready)
        require_once '../vendor/autoload.php';
        use Twilio\Rest\Client;
        try {
            $sid = 'YOUR_TWILIO_SID';
            $token = 'YOUR_TWILIO_AUTH_TOKEN';
            $twilio = new Client($sid, $token);
            $twilio->messages->create(
                "whatsapp:{$phone}",
                ['from' => "whatsapp:+YOUR_TWILIO_NUMBER", 'body' => $message]
            );
            $sentWelcomeMessage = 1; // Set to TRUE if sent successfully
        } catch (Exception $e) {
            $log->error("WhatsApp message failed: " . $e->getMessage());
            // Do not fail registration, just log the error
        }
        */

        // Update sent_welcome_message column
        $stmt = $pdo->prepare("UPDATE students SET sent_welcome_message = ? WHERE id = ?");
        $stmt->execute([$sentWelcomeMessage, $studentId]);

        jsonResponse("success", "Student registered successfully.", [
            "temporary_serial_number" => $temporarySerialNumber,
            "student_id" => $studentId,
            "password" => $password // Temporary inclusion for WhatsApp
        ], 201);
    } catch (PDOException $e) {
        $log->error("Registration failed: " . $e->getMessage());
        jsonResponse("error", "Registration failed.", [], 500);
    }
} elseif ($method === 'GET') {
    $user = authenticate(); // Allow both student and admin to access

    if ($endpoint === 'all_students') {
        // Fetch all students (restricted to admins)
        $user = authenticate('admin');
        try {
            $stmt = $pdo->query("SELECT id, name, email, program, date_of_birth, alternatePhone, semester, sent_welcome_message FROM students");
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['data' => $students]);
        } catch (PDOException $e) {
            $log->error("Failed to fetch students: " . $e->getMessage());
            jsonResponse("error", "Failed to fetch students.", [], 500);
        }
    } elseif ($endpoint === 'all_semesters') {
        // Fetch all semesters (restricted to admins)
        $user = authenticate('admin');
        try {
            $stmt = $pdo->query("SELECT DISTINCT semester FROM students WHERE semester IS NOT NULL ORDER BY semester");
            $semesters = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode(['data' => ['semesters' => $semesters]]);
        } catch (PDOException $e) {
            $log->error("Failed to fetch semesters: " . $e->getMessage());
            jsonResponse("error", "Failed to fetch semesters.", [], 500);
        }
    } else {
        // Existing GET logic for individual student
        $studentId = filter_var($endpoint, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);

        if (!$studentId) {
            jsonResponse("error", "Invalid or missing student ID.", [], 400);
        }

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
        } elseif (isset($_GET['finalize']) && $_GET['finalize'] === 'true') {
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
    }
} elseif ($method === 'PUT') {
    $studentId = filter_var($endpoint, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);

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
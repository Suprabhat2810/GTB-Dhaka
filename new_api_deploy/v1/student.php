<?php
// student.php — hardened, logged, non-breaking replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('student');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$uriParts = explode('/', parse_url($uri, PHP_URL_PATH));
$endpoint = end($uriParts); // e.g., 'all_students', 'all_semesters', or a student ID

try {
    // -----------------------
    // POST — Register student
    // -----------------------
    if ($method === 'POST') {
        $payload = json_decode(file_get_contents("php://input"), true);
        if (!is_array($payload)) {
            $logger->warning('student POST: invalid JSON payload');
            jsonResponse("error", "Invalid JSON payload.", [], 400);
        }

        $required = ['name', 'email', 'phone', 'program', 'password'];
        foreach ($required as $r) {
            if (empty($payload[$r])) {
                $logger->info('student POST: missing required fields', ['missing' => $r]);
                jsonResponse("error", "Missing required fields.", [], 400);
            }
        }

        // sanitize & validate
        $name = trim((string)$payload['name']);
        $email = filter_var($payload['email'], FILTER_VALIDATE_EMAIL);
        $phone = trim((string)$payload['phone']);
        $alternatePhone = isset($payload['alternatePhone']) ? trim((string)$payload['alternatePhone']) : null;
        $state = isset($payload['state']) ? trim((string)$payload['state']) : null;
        $gender = isset($payload['gender']) ? trim((string)$payload['gender']) : null;
        $qualification = isset($payload['qualification']) ? trim((string)$payload['qualification']) : null;
        $program = trim((string)$payload['program']);
        $rawPassword = (string)$payload['password']; // kept for WhatsApp content (temporary)
        $hashedPassword = password_hash($rawPassword, PASSWORD_BCRYPT);
        $date_of_birth = isset($payload['date_of_birth']) ? trim((string)$payload['date_of_birth']) : null;

        if ($date_of_birth && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_of_birth)) {
            $logger->info('student POST: invalid dob format', ['dob' => $date_of_birth]);
            jsonResponse("error", "Invalid date of birth format. Use YYYY-MM-DD.", [], 400);
        }

        // phone validation (India)
        if (!preg_match('/^\+91\d{10}$/', $phone)) {
            $logger->info('student POST: invalid primary phone', ['phone' => $phone]);
            jsonResponse("error", "Invalid primary phone number format. Use +91 followed by 10 digits.", [], 400);
        }
        if ($alternatePhone && !preg_match('/^\+91\d{10}$/', $alternatePhone)) {
            $logger->info('student POST: invalid alternate phone', ['alternatePhone' => $alternatePhone]);
            jsonResponse("error", "Invalid alternate phone number format. Use +91 followed by 10 digits.", [], 400);
        }

        if (!$email) {
            $logger->info('student POST: invalid email', ['email' => $payload['email'] ?? null]);
            jsonResponse("error", "Invalid email format.", [], 400);
        }

        try {
            $pdo->beginTransaction();

            // uniqueness check
            $stmt = $pdo->prepare("SELECT 1 FROM students WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn()) {
                $pdo->rollBack();
                $logger->info('student POST: email already registered', ['email' => $email]);
                jsonResponse("error", "Email already registered.", [], 400);
            }

            // generate temporary serial
            $temporarySerialNumber = "TEMP" . substr(md5(uniqid((string)microtime(true), true)), 0, 10);

            $stmt = $pdo->prepare("
                INSERT INTO students 
                    (name, email, phone, alternatePhone, date_of_birth, state, gender, qualification, program, temporary_serial_number, password)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name, $email, $phone, $alternatePhone, $date_of_birth, $state, $gender, $qualification, $program, $temporarySerialNumber, $hashedPassword
            ]);

            $studentId = (int)$pdo->lastInsertId();

            // create approvals row (default not approved)
            $stmt = $pdo->prepare("INSERT INTO approvals (student_id, approved) VALUES (?, 0)");
            $stmt->execute([$studentId]);

            $pdo->commit();

            // Craft WhatsApp message (do NOT send sensitive info in logs)
            $institutionName = "GTB National College Dhaka";
            $message = "Welcome to {$institutionName},\nDear {$name},\nThank you for registering with us! Your Temporary Serial Number is {$temporarySerialNumber} and your password is {$rawPassword}. Please log in to continue your journey with us!";

            // Attempt to send WhatsApp message via Twilio — commented out but safely logged if attempted
            $sentWelcomeMessage = 0;
            /*
            try {
                require_once __DIR__ . '/../vendor/autoload.php';
                use Twilio\Rest\Client;
                $sid = 'YOUR_TWILIO_SID';
                $token = 'YOUR_TWILIO_AUTH_TOKEN';
                $twilio = new Client($sid, $token);
                $twilio->messages->create(
                    "whatsapp:{$phone}",
                    ['from' => "whatsapp:+YOUR_TWILIO_NUMBER", 'body' => $message]
                );
                $sentWelcomeMessage = 1;
            } catch (Exception $e) {
                $logger->error('student POST: WhatsApp send failed', ['error' => $e->getMessage(), 'student_id' => $studentId]);
                // do not fail registration if message sending fails
            }
            */

            // Update sent_welcome_message flag
            try {
                $stmt = $pdo->prepare("UPDATE students SET sent_welcome_message = ? WHERE id = ?");
                $stmt->execute([$sentWelcomeMessage, $studentId]);
            } catch (PDOException $e) {
                // Non-fatal; log and continue
                $logger->warning('student POST: failed to update sent_welcome_message', ['error' => $e->getMessage(), 'student_id' => $studentId]);
            }

            $logger->info('student POST: registration successful', ['student_id' => $studentId, 'email' => $email, 'temp_serial' => $temporarySerialNumber]);

            // NOTE: returning raw password in response is legacy behavior — keep for compatibility but avoid logging it.
            jsonResponse("success", "Student registered successfully.", [
                "temporary_serial_number" => $temporarySerialNumber,
                "student_id" => $studentId,
                "password" => $rawPassword
            ], 201);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $logger->error('student POST: registration failed', ['error' => $e->getMessage()]);
            jsonResponse("error", "Registration failed.", [], 500);
        }
    }

    // -----------------------
    // GET — many sub-flows
    // -----------------------
    elseif ($method === 'GET') {
        $user = authenticate(); // allow both student and admin
        // ---- all_students ----
        if ($endpoint === 'all_students') {
            $admin = authenticate('admin');
            try {
                $stmt = $pdo->query("SELECT id, name, email, program, date_of_birth, alternatePhone, semester, sent_welcome_message FROM students");
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $logger->info('student GET: all_students fetched', ['count' => count($students), 'actor' => $admin->id ?? null]);
                jsonResponse("success", "Students retrieved successfully.", ['data' => $students], 200);
            } catch (PDOException $e) {
                $logger->error('student GET: failed to fetch all_students', ['error' => $e->getMessage()]);
                jsonResponse("error", "Failed to fetch students.", [], 500);
            }
        }
        // ---- all_semesters ----
        elseif ($endpoint === 'all_semesters') {
            $admin = authenticate('admin');
            try {
                $stmt = $pdo->query("SELECT DISTINCT semester FROM students WHERE semester IS NOT NULL ORDER BY semester");
                $semesters = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $logger->info('student GET: all_semesters fetched', ['count' => count($semesters), 'actor' => $admin->id ?? null]);
                jsonResponse("success", "Semesters retrieved successfully.", ['data' => ['semesters' => $semesters]], 200);
            } catch (PDOException $e) {
                $logger->error('student GET: failed to fetch semesters', ['error' => $e->getMessage()]);
                jsonResponse("error", "Failed to fetch semesters.", [], 500);
            }
        }
        // ---- individual student retrieval / status / finalize ----
        else {
            $studentId = filter_var($endpoint, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
            if (!$studentId) {
                $logger->warning('student GET: invalid or missing student ID', ['endpoint' => $endpoint]);
                jsonResponse("error", "Invalid or missing student ID.", [], 400);
            }

            // authorization: students can view their own record; admins can view any
            if (($user->role ?? '') === 'student' && (int)$user->id !== (int)$studentId) {
                $logger->warning('student GET: unauthorized access attempt', ['actor' => $user->id ?? null, 'target' => $studentId]);
                jsonResponse("error", "Unauthorized access.", [], 403);
            }

            // status check
            if (isset($_GET['status']) && $_GET['status'] === 'true') {
                try {
                    $stmt = $pdo->prepare("SELECT 1 FROM students WHERE id = ? LIMIT 1");
                    $stmt->execute([$studentId]);
                    if (!$stmt->fetchColumn()) {
                        jsonResponse("error", "Student not found.", [], 404);
                    }

                    $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ? LIMIT 1");
                    $stmt->execute([$studentId]);
                    $approval = $stmt->fetch(PDO::FETCH_ASSOC);
                    $approved = ($approval && (int)$approval['approved'] === 1) ? "Yes" : "No";

                    $stmt = $pdo->prepare("SELECT payment_received FROM payments WHERE student_id = ? LIMIT 1");
                    $stmt->execute([$studentId]);
                    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
                    $paymentReceived = ($payment && (int)$payment['payment_received'] === 1) ? "Yes" : "No";

                    $stmt = $pdo->prepare("SELECT final_registration_number FROM students WHERE id = ? LIMIT 1");
                    $stmt->execute([$studentId]);
                    $student = $stmt->fetch(PDO::FETCH_ASSOC);
                    $finalized = (!empty($student['final_registration_number'])) ? "Yes" : "No";

                    jsonResponse("success", "Status retrieved successfully.", [
                        "student_id" => $studentId,
                        "approved" => $approved,
                        "payment_received" => $paymentReceived,
                        "finalized" => $finalized
                    ], 200);
                } catch (PDOException $e) {
                    $logger->error('student GET: status retrieval failed', ['error' => $e->getMessage(), 'student_id' => $studentId]);
                    jsonResponse("error", "Status retrieval failed.", [], 500);
                }
            }
            // finalize via GET (legacy flow) — admin only
            elseif (isset($_GET['finalize']) && $_GET['finalize'] === 'true') {
                $admin = authenticate('admin');
                try {
                    $stmt = $pdo->prepare("SELECT 1 FROM students WHERE id = ? LIMIT 1");
                    $stmt->execute([$studentId]);
                    if (!$stmt->fetchColumn()) {
                        jsonResponse("error", "Student not found.", [], 404);
                    }

                    $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ? LIMIT 1");
                    $stmt->execute([$studentId]);
                    $approval = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$approval || (int)$approval['approved'] !== 1) {
                        jsonResponse("error", "Student not approved yet.", [], 400);
                    }

                    $stmt = $pdo->prepare("SELECT payment_received FROM payments WHERE student_id = ? LIMIT 1");
                    $stmt->execute([$studentId]);
                    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$payment || (int)$payment['payment_received'] !== 1) {
                        jsonResponse("error", "Payment not received yet.", [], 400);
                    }

                    $stmt = $pdo->prepare("SELECT final_registration_number FROM students WHERE id = ? LIMIT 1");
                    $stmt->execute([$studentId]);
                    $studentRow = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!empty($studentRow['final_registration_number'])) {
                        jsonResponse("error", "Registration already finalized.", [], 400);
                    }

                    $serial = str_pad((string)$studentId, 2, "0", STR_PAD_LEFT);
                    $month = date('m');
                    $year = date('y');
                    $finalRegistrationNumber = "GTB{$serial}{$month}{$year}";

                    $stmt = $pdo->prepare("UPDATE students SET final_registration_number = ? WHERE id = ?");
                    $stmt->execute([$finalRegistrationNumber, $studentId]);

                    $logger->info('student GET finalize: registration finalized', ['student_id' => $studentId, 'finalRegistrationNumber' => $finalRegistrationNumber, 'actor' => $admin->id ?? null]);
                    jsonResponse("success", "Registration finalized successfully.", ["final_registration_number" => $finalRegistrationNumber], 200);
                } catch (PDOException $e) {
                    $logger->error('student GET finalize: finalization failed', ['error' => $e->getMessage(), 'student_id' => $studentId]);
                    jsonResponse("error", "Finalization failed.", [], 500);
                }
            }
            // default: return student record
            else {
                try {
                    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? LIMIT 1");
                    $stmt->execute([$studentId]);
                    $studentRow = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$studentRow) {
                        jsonResponse("error", "Student not found.", [], 404);
                    }
                    jsonResponse("success", "Student retrieved successfully.", ["student" => $studentRow], 200);
                } catch (PDOException $e) {
                    $logger->error('student GET: student retrieval failed', ['error' => $e->getMessage(), 'student_id' => $studentId]);
                    jsonResponse("error", "Student retrieval failed.", [], 500);
                }
            }
        }
    }

    // -----------------------
    // PUT — finalize via PUT (admin)
    // -----------------------
    elseif ($method === 'PUT') {
        $studentId = filter_var($endpoint, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
        if (!$studentId) {
            $logger->warning('student PUT: invalid student id', ['endpoint' => $endpoint]);
            jsonResponse("error", "Invalid student ID.", [], 400);
        }

        $finalize = isset($_GET['finalize']) && $_GET['finalize'] === 'true';
        if (!$finalize) {
            jsonResponse("error", "Method not allowed for this action.", [], 405);
        }

        $admin = authenticate('admin');
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM students WHERE id = ? LIMIT 1");
            $stmt->execute([$studentId]);
            if (!$stmt->fetchColumn()) {
                jsonResponse("error", "Student not found.", [], 404);
            }

            $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ? LIMIT 1");
            $stmt->execute([$studentId]);
            $approval = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$approval || (int)$approval['approved'] !== 1) {
                jsonResponse("error", "Student not approved yet.", [], 400);
            }

            $stmt = $pdo->prepare("SELECT payment_received FROM payments WHERE student_id = ? LIMIT 1");
            $stmt->execute([$studentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$payment || (int)$payment['payment_received'] !== 1) {
                jsonResponse("error", "Payment not received yet.", [], 400);
            }

            $stmt = $pdo->prepare("SELECT final_registration_number FROM students WHERE id = ? LIMIT 1");
            $stmt->execute([$studentId]);
            $studentRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($studentRow['final_registration_number'])) {
                jsonResponse("error", "Registration already finalized.", [], 400);
            }

            $serial = str_pad((string)$studentId, 2, "0", STR_PAD_LEFT);
            $month = date('m');
            $year = date('y');
            $finalRegistrationNumber = "GTB{$serial}{$month}{$year}";

            $stmt = $pdo->prepare("UPDATE students SET final_registration_number = ? WHERE id = ?");
            $stmt->execute([$finalRegistrationNumber, $studentId]);

            $logger->info('student PUT finalize: registration finalized', ['student_id' => $studentId, 'finalRegistrationNumber' => $finalRegistrationNumber, 'actor' => $admin->id ?? null]);
            jsonResponse("success", "Registration finalized successfully.", ["final_registration_number" => $finalRegistrationNumber], 200);
        } catch (PDOException $e) {
            $logger->error('student PUT finalize: finalization failed', ['error' => $e->getMessage(), 'student_id' => $studentId]);
            jsonResponse("error", "Finalization failed.", [], 500);
        }
    }

    // Unsupported method
    else {
        jsonResponse("error", "Method not allowed.", [], 405);
    }
} catch (Exception $e) {
    $logger->critical('student endpoint: unexpected error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    jsonResponse("error", "Internal server error.", [], 500);
}

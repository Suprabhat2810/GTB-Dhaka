<?php
// approval.php — hardened, non-breaking replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/WhatsAppService.php';

$logger = getLogger('approvals');
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$uriParts = explode('/', parse_url($uri, PHP_URL_PATH));
$endpoint = end($uriParts);

// Helper: safe integer from GET
function getIntQuery(string $key, int $min = 1): ?int {
    $val = $_GET[$key] ?? null;
    if ($val === null) return null;
    $v = filter_var($val, FILTER_VALIDATE_INT, ["options" => ["min_range" => $min]]);
    return $v === false ? null : (int)$v;
}

// Normalize action
$action = strtolower(trim((string)($_GET['action'] ?? 'approve')));
if (!in_array($action, ['approve', 'reject'], true)) {
    $action = 'approve';
}

try {
    $pdo = getPDO();

    if ($method === 'PUT') {
        $studentId = getIntQuery('student_id');
        if (!$studentId) {
            $logger->warning('Invalid or missing student ID in request', ['student_id' => $_GET['student_id'] ?? null]);
            jsonResponse("error", "Invalid or missing student ID.", [], 400);
        }

        // admin auth required
        $user = authenticate('admin');
        $data = json_decode(file_get_contents("php://input"), true);
        $adminId = isset($data['admin_id']) ? filter_var($data['admin_id'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) : null;
        if (!$adminId) {
            $logger->warning('Invalid or missing admin ID', ['payload' => $data]);
            jsonResponse("error", "Invalid or missing admin ID.", [], 400);
        }

        // check student exists
        $stmt = $pdo->prepare("SELECT 1 FROM students WHERE id = ? LIMIT 1");
        $stmt->execute([$studentId]);
        if (!$stmt->fetchColumn()) {
            $logger->info('Student not found during approval/rejection', ['student_id' => $studentId, 'actor' => $user->id ?? null]);
            jsonResponse("error", "Student not found.", [], 404);
        }

        // check admin exists
        $stmt = $pdo->prepare("SELECT 1 FROM admins WHERE id = ? LIMIT 1");
        $stmt->execute([$adminId]);
        if (!$stmt->fetchColumn()) {
            $logger->info('Admin not found during approval/rejection', ['admin_id' => $adminId, 'actor' => $user->id ?? null]);
            jsonResponse("error", "Admin not found.", [], 404);
        }

        if ($action === 'approve') {
            // check existing approval
            $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ? LIMIT 1");
            $stmt->execute([$studentId]);
            $approval = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($approval && (int)$approval['approved'] === 1) {
                $logger->info('Attempt to re-approve already approved student', ['student_id' => $studentId, 'actor' => $user->id ?? null]);
                jsonResponse("error", "Student already approved.", [], 400);
            }

            // Get student's year (admission year)
            $stmt = $pdo->prepare("SELECT year FROM students WHERE id = ? LIMIT 1");
            $stmt->execute([$studentId]);
            $studentInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            $admissionYear = (int)($studentInfo['year'] ?? date('Y'));
            
            // Calculate academic year
            $currentMonth = (int)date('n');
            $currentYear = (int)date('Y');
            
            // Academic year starts in January (month 1) for now
            if ($currentMonth >= 1) {
                $academicYear = $currentYear . '-' . ($currentYear + 1);
            } else {
                $academicYear = ($currentYear - 1) . '-' . $currentYear;
            }

            // Upsert approval
            $stmt = $pdo->prepare("
                INSERT INTO approvals (student_id, admin_id, approved, approval_date)
                VALUES (?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE
                  admin_id = VALUES(admin_id),
                  approved = VALUES(approved),
                  approval_date = VALUES(approval_date)
            ");
            $stmt->execute([$studentId, $adminId]);
            
            // Update student with semester and academic year
            $stmt = $pdo->prepare("
                UPDATE students 
                SET semester = 1, 
                    academic_year = ?
                WHERE id = ?
            ");
            $stmt->execute([$academicYear, $studentId]);

            // copy student basic data into personal_info
            $stmt = $pdo->prepare("SELECT name, gender, date_of_birth FROM students WHERE id = ? LIMIT 1");
            $stmt->execute([$studentId]);
            $studentData = $stmt->fetch(PDO::FETCH_ASSOC);

            // defensive: ensure keys exist
            // $name = $studentData['name'] ?? '';
            $gender = $studentData['gender'] ?? '';
            $dob = $studentData['date_of_birth'] ?? null;

            // $stmt = $pdo->prepare("
            //     INSERT INTO personal_info (student_id)
            //     VALUES (?)
            //     ON DUPLICATE KEY UPDATE
            // ");
            // $stmt->execute([$studentId]);

            // Notification (simple DB insert; future: push to queue)
            $stmt = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
            $stmt->execute([$studentId, "Your registration has been approved. Please complete your final registration details."]);

            // Send WhatsApp approval notification (non-breaking)
            try {
                $stmt = $pdo->prepare("SELECT name, phone, program FROM students WHERE id = ? LIMIT 1");
                $stmt->execute([$studentId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($student && !empty($student['phone'])) {
                    $whatsappService = new WhatsAppService($logger);
                    if ($whatsappService->isEnabled()) {
                        $whatsappService->sendApprovalMessage(
                            $student['phone'],
                            $student['name'],
                            1, // semester
                            $academicYear,
                            $student['program']
                        );
                        $logger->info('Approval WhatsApp sent', ['student_id' => $studentId]);
                    }
                }
            } catch (Exception $e) {
                // Silent failure - approval continues normally
                $logger->error('WhatsApp approval notification error (non-critical)', [
                    'error' => $e->getMessage(),
                    'student_id' => $studentId
                ]);
            }

            $logger->info('Student approved', [
                'student_id' => $studentId,
                'admin_id' => $adminId,
                'actor' => $user->id ?? null
            ]);

            jsonResponse("success", "Student approved successfully.", ["student_id" => $studentId]);
        }

        if ($action === 'reject') {
            // ensure not already approved
            $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ? LIMIT 1");
            $stmt->execute([$studentId]);
            $approval = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($approval && (int)$approval['approved'] === 1) {
                $logger->warning('Attempt to reject an already approved student', ['student_id' => $studentId, 'actor' => $user->id ?? null]);
                jsonResponse("error", "Student already approved, cannot reject.", [], 400);
            }

            // Transaction: delete approvals, personal_info, students
            $inTransaction = false;
            try {
                $pdo->beginTransaction();
                $inTransaction = true;

                // Send notification BEFORE deleting student (optional - can be skipped since student is being deleted)
                // Commenting out since student won't be able to see it anyway after deletion
                // $stmt = $pdo->prepare("INSERT INTO notifications (student_id, message, notification_date) VALUES (?, ?, NOW())");
                // $stmt->execute([$studentId, "Your registration has been rejected."]);

                $stmt = $pdo->prepare("DELETE FROM approvals WHERE student_id = ?");
                $stmt->execute([$studentId]);

                $stmt = $pdo->prepare("DELETE FROM personal_info WHERE student_id = ?");
                $stmt->execute([$studentId]);

                $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
                $stmt->execute([$studentId]);

                $pdo->commit();
                $inTransaction = false;
            } catch (PDOException $ex) {
                if ($inTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $logger->error('Reject transaction failed', ['error' => $ex->getMessage(), 'student_id' => $studentId]);
                jsonResponse("error", "Operation failed.", [], 500);
            }

            $logger->info('Student rejected', [
                'student_id' => $studentId,
                'admin_id' => $adminId,
                'actor' => $user->id ?? null
            ]);

            jsonResponse("success", "Student rejected successfully.", ["student_id" => $studentId]);
        }
    }

    // Final registration path
    if ($method === 'POST' && $endpoint === 'final-registration') {
        $user = authenticate('student');
        $data = json_decode(file_get_contents("php://input"), true);

        $requiredFields = ['fatherName', 'motherName', 'casteCategory', 'phone', 'aadhaarNumber', 'address', 'previousBoardUniversity', 'lastClassResult', 'subjectsPapers'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                $logger->warning('Missing required field for final-registration', ['field' => $field, 'student' => $user->id ?? null]);
                jsonResponse("error", "Missing required field: $field", [], 400);
            }
        }

        $studentId = (int)($user->id ?? 0);
        if ($studentId <= 0) {
            $logger->warning('Invalid student context during final-registration', ['student' => $user]);
            jsonResponse("error", "Invalid student context.", [], 401);
        }

        // sanitize inputs (strip tags, trim)
        $fatherName = trim(strip_tags((string)$data['fatherName']));
        $motherName = trim(strip_tags((string)$data['motherName']));
        $casteCategory = trim(strip_tags((string)$data['casteCategory']));
        $phone = trim((string)$data['phone']);
        $aadhaarNumber = trim((string)$data['aadhaarNumber']);
        $address = trim(strip_tags((string)$data['address']));
        $previousBoardUniversity = trim(strip_tags((string)$data['previousBoardUniversity']));
        $lastClassResult = trim(strip_tags((string)$data['lastClassResult']));
        $subjectsPapers = trim(strip_tags((string)$data['subjectsPapers']));
        $additionalSubjects = trim(strip_tags((string)($data['additionalSubjects'] ?? '')));

        // Validate phone and aadhaar
        if (!preg_match('/^\+91\d{10}$/', $phone)) {
            $logger->info('Invalid phone format submitted', ['phone' => $phone, 'student' => $studentId]);
            jsonResponse("error", "Invalid phone number format. Use +91 followed by 10 digits.", [], 400);
        }
        if (!preg_match('/^\d{12}$/', $aadhaarNumber)) {
            $logger->info('Invalid aadhaar format submitted', ['aadhaar' => $aadhaarNumber, 'student' => $studentId]);
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

            $logger->info('Final registration submitted', ['student_id' => $studentId]);
            jsonResponse("success", "Final registration details submitted successfully.", [], 200);
        } catch (PDOException $e) {
            $logger->error('Failed to submit final registration details', ['error' => $e->getMessage(), 'student_id' => $studentId]);
            jsonResponse("error", "Failed to submit final registration details.", [], 500);
        }
    }

    // Pending approvals retrieval
    if ($method === 'GET' && isset($_GET['pending'])) {
        $user = authenticate('admin');

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;

        try {
            $logger->info("Fetching pending approvals", ['page' => $page, 'limit' => $limit, 'offset' => $offset, 'actor' => $user->id ?? null]);

            $stmt = $pdo->prepare("
                SELECT s.id, s.name, s.email, s.phone, s.alternatePhone, s.date_of_birth, 
                       s.state, s.gender, s.qualification, s.program, s.temporary_serial_number
                FROM students s
                LEFT JOIN approvals a ON s.id = a.student_id
                WHERE a.approved = 0 OR a.approved IS NULL
                LIMIT ? OFFSET ?
            ");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // attach empty documents array and default submitted date
            foreach ($pending as &$student) {
                $student['documents'] = [];
                // Use current date as submitted date (since we don't have a registration_date column)
                $student['submittedDate'] = date('Y-m-d H:i:s');
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM students s
                LEFT JOIN approvals a ON s.id = a.student_id
                WHERE a.approved = 0 OR a.approved IS NULL
            ");
            $stmt->execute();
            $total = (int)$stmt->fetchColumn();

            $logger->info('Pending approvals retrieved', ['count' => count($pending)]);

            jsonResponse("success", "Pending approvals retrieved successfully.", [
                "pending" => $pending,
                "meta" => ["page" => $page, "limit" => $limit, "total" => $total]
            ]);
        } catch (PDOException $e) {
            $logger->error('Failed to fetch pending approvals', ['error' => $e->getMessage()]);
            jsonResponse("error", "Failed to fetch pending approvals.", [], 500);
        }
    }

    // If no route matched
    jsonResponse("error", "Method not allowed.", [], 405);

} catch (Exception $e) {
    // Global fallback
    $logger->critical('Unhandled exception in approval.php', ['error' => $e->getMessage()]);
    jsonResponse("error", "Internal server error.", [], 500);
}

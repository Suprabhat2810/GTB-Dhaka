<?php
// final_registration.php — hardened, logged, non-breaking replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('final_registration');
$start = microtime(true);

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $user = authenticate(); // allow admin or student

        if (!$user) {
            $logger->warning('Unauthorized access attempt to final_registration (GET)');
            jsonResponse("error", "Unauthorized access.", [], 403);
            exit;
        }

        // Determine student ID: use query param for admin, token for student
        $student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
        if (($user->role ?? '') === 'student') {
            $student_id = (int)($user->sub ?? $user->id ?? 0); // Use ID from token for students
        } elseif (($user->role ?? '') === 'admin' && $student_id <= 0) {
            $logger->warning('Admin requested GET final_registration without valid student_id', ['actor' => $user->id ?? null]);
            jsonResponse("error", "Invalid student ID.", [], 400);
            exit;
        }

        $pdo = getPDO();

        // Fetch student data with their photo and lock_form_student status
        $query = "
            SELECT 
                s.id AS student_id,
                s.final_registration_number,
                s.date_of_birth,
                s.name,
                s.gender,
                s.phone,
                s.email,
                s.program,
                a.approved,
                pi.id AS personal_info_id,
                pi.father_name,
                pi.mother_name,
                pi.father_occupation,
                pi.mother_occupation,
                pi.address,
                pi.father_mobile,
                pi.mother_mobile,
                pi.father_income,
                pi.mother_income,
                pi.caste_category,
                pi.aadhaar_number,
                pi.previous_board_university,
                pi.last_class_result,
                pi.subjects_papers,
                pi.additional_subjects,
                pi.qualification,
                pi.lock_form_student,
                pi.stream,
                pi.institute,
                pi.batch_year,
                pi.percentage,
                pi.study_gap,
                d.document_path AS photo_path
            FROM students s
            LEFT JOIN personal_info pi ON s.id = pi.student_id
            LEFT JOIN approvals a ON s.id = a.student_id
            LEFT JOIN documents d ON s.id = d.student_id AND d.document_type = 'Photo' AND d.status = 'verified'
            WHERE s.id = :student_id AND a.approved = 1
            LIMIT 1
        ";

        $stmt = $pdo->prepare($query);
        $stmt->execute(['student_id' => $student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            $logger->info('Student not found or not approved (final_registration GET)', ['student_id' => $student_id, 'actor' => $user->id ?? null]);
            jsonResponse("error", "Student not found or not approved.", [], 404);
        }

        // Fetch compulsory subjects based on the program
        $compulsory_subjects_query = "
            SELECT subject_name 
            FROM subjects 
            WHERE department = :program
        ";
        $comp_sub_stmt = $pdo->prepare($compulsory_subjects_query);
        $comp_sub_stmt->execute(['program' => $student['program'] ?? '']);
        $compulsory_subjects = $comp_sub_stmt->fetchAll(PDO::FETCH_COLUMN);

        // Split the full name into first_name and last_name
        $nameParts = preg_split('/\s+/', trim((string)($student['name'] ?? '')), 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        // Build secure photo URL if exists — avoid hardcoding server paths
        $photo = null;
        if (!empty($student['photo_path'])) {
            // If photo_path is stored as relative path, build absolute using current host
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            $photo = $scheme . '://' . $host . $base . '/' . ltrim($student['photo_path'], '/\\');
        }

        // Restructure the response to match PersonalProfile interface
        $response = [
            'student_id' => (int)$student['student_id'],
            'vid_number' => (string)($student['student_id'] ?? ''),
            'final_registration_number' => $student['final_registration_number'] ?? '',
            'temporary_serial_number' => '',
            'date_of_birth' => $student['date_of_birth'] ?? '',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => $student['gender'] ?? '',
            'phone' => $student['phone'] ?? '',
            'email' => $student['email'] ?? '',
            'program' => $student['program'] ?? '',
            'status' => !empty($student['approved']) ? 'Approved' : 'Pending',
            'photo' => $photo ?? '',
            'personal_info' => [
                'id' => (int)($student['personal_info_id'] ?? 0),
                'student_id' => (int)$student['student_id'],
                'father_name' => $student['father_name'] ?? '',
                'mother_name' => $student['mother_name'] ?? '',
                'father_occupation' => $student['father_occupation'] ?? '',
                'mother_occupation' => $student['mother_occupation'] ?? '',
                'address' => $student['address'] ?? '',
                'father_mobile' => $student['father_mobile'] ?? '',
                'mother_mobile' => $student['mother_mobile'] ?? '',
                'father_income' => $student['father_income'] ?? '',
                'mother_income' => $student['mother_income'] ?? '',
                'caste_category' => $student['caste_category'] ?? '',
                'aadhaar_number' => $student['aadhaar_number'] ?? '',
                'previous_board_university' => $student['previous_board_university'] ?? '',
                'last_class_result' => $student['last_class_result'] ?? '',
                'subjects_papers' => $student['subjects_papers'] ?? '',
                'additional_subjects' => $student['additional_subjects'] ?? '',
                'qualification' => $student['qualification'] ?? '',
                'compulsory_subjects' => is_array($compulsory_subjects) ? implode(',', $compulsory_subjects) : '',
                'lock_form_student' => (bool)($student['lock_form_student'] ?? false),
                'stream' => $student['stream'] ?? '',
                'institute' => $student['institute'] ?? '',
                'batch_year' => $student['batch_year'] ?? '',
                'percentage' => $student['percentage'] ?? '',
                'study_gap' => $student['study_gap'] ?? '',
            ],
        ];

        $durationMs = round((microtime(true) - $start) * 1000, 2);
        $logger->info('Fetched student for final registration', ['student_id' => $student_id, 'actor' => $user->id ?? null, 'duration_ms' => $durationMs]);

        jsonResponse("success", "Student data retrieved successfully.", $response, 200);
    }

    elseif ($method === 'POST') {
        $logger->info('POST request received to final_registration');
        
        $user = authenticate();

        if (!$user) {
            $logger->warning('Unauthorized access attempt to final_registration (POST)');
            jsonResponse("error", "Unauthorized access.", [], 403);
            exit;
        }

        $logger->info('User authenticated', ['user_id' => $user->id ?? $user->sub ?? 'UNKNOWN', 'role' => $user->role ?? 'UNKNOWN']);

        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data || !isset($data['student_id'])) {
            $logger->warning('Invalid data submitted to final_registration (missing student_id)', ['actor' => $user->id ?? null]);
            jsonResponse("error", "Invalid data.", [], 400);
            exit;
        }

        $logger->info('Data received', ['student_id' => $data['student_id'], 'data_keys' => array_keys($data)]);

        // Validate user access - use sub if id is not set
        $userId = (int)($user->sub ?? $user->id ?? 0);
        if (($user->role ?? '') === 'student' && $userId !== (int)$data['student_id']) {
            $logger->warning('Student attempted to modify another student\'s final registration', ['actor' => $userId, 'target_student' => $data['student_id']]);
            jsonResponse("error", "Unauthorized access.", [], 403);
            exit;
        }

        $logger->info('Authorization check passed', ['user_id' => $userId, 'student_id' => $data['student_id']]);

        $pdo = getPDO();

        // Check if the student is approved
        try {
            $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ? LIMIT 1");
            $stmt->execute([(int)$data['student_id']]);
            $approval = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$approval || (int)$approval['approved'] !== 1) {
                $logger->info('Attempt to submit final registration for unapproved student', ['student_id' => $data['student_id'], 'actor' => $user->id ?? null]);
                jsonResponse("error", "Student not approved yet.", [], 400);
                exit;
            }
        } catch (PDOException $e) {
            $logger->error('Failed to check student approval status', ['error' => $e->getMessage(), 'student_id' => $data['student_id']]);
            jsonResponse("error", "Failed to check student approval status.", [], 500);
            exit;
        }

        // Check if the form is locked (only applies to students)
        if (($user->role ?? '') === 'student') {
            try {
                $stmt = $pdo->prepare("SELECT lock_form_student FROM personal_info WHERE student_id = ? LIMIT 1");
                $stmt->execute([(int)$data['student_id']]);
                $personalInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($personalInfo && $personalInfo['lock_form_student']) {
                    $logger->info('Student attempted to edit locked form', ['student_id' => $data['student_id'], 'actor' => $user->id ?? null]);
                    jsonResponse("error", "Form is locked and cannot be edited.", [], 403);
                    exit;
                }
            } catch (PDOException $e) {
                $logger->error('Failed to check lock_form_student status', ['error' => $e->getMessage(), 'student_id' => $data['student_id']]);
                jsonResponse("error", "Failed to check form lock status.", [], 500);
                exit;
            }
        }

        // Log the received data for debugging
        $logger->info('Received final registration data', [
            'student_id' => $data['student_id'],
            'father_name' => $data['father_name'] ?? 'NOT_SET',
            'address' => $data['address'] ?? 'NOT_SET',
            'caste_category' => $data['caste_category'] ?? 'NOT_SET',
            'data_keys' => array_keys($data)
        ]);

        try {
            // Check if a personal_info record already exists for this student
            $stmt = $pdo->prepare("SELECT id FROM personal_info WHERE student_id = ? LIMIT 1");
            $stmt->execute([(int)$data['student_id']]);
            $existingRecord = $stmt->fetch(PDO::FETCH_ASSOC);

            $logger->info('Existing record check', [
                'student_id' => $data['student_id'],
                'existing_record' => $existingRecord ? 'FOUND (id='.$existingRecord['id'].')' : 'NOT_FOUND'
            ]);

            if ($existingRecord) {
                // Update the existing record
                $logger->info('Executing UPDATE query', ['student_id' => $data['student_id'], 'record_id' => $existingRecord['id']]);
                $query = "
                    UPDATE personal_info SET
                        father_name = :father_name,
                        mother_name = :mother_name,
                        father_occupation = :father_occupation,
                        mother_occupation = :mother_occupation,
                        address = :address,
                        father_mobile = :father_mobile,
                        mother_mobile = :mother_mobile,
                        father_income = :father_income,
                        mother_income = :mother_income,
                        caste_category = :caste_category,
                        aadhaar_number = :aadhaar_number,
                        previous_board_university = :previous_board_university,
                        last_class_result = :last_class_result,
                        subjects_papers = :subjects_papers,
                        additional_subjects = :additional_subjects,
                        qualification = :qualification,
                        lock_form_student = :lock_form_student,
                        stream = :stream,
                        institute = :institute,
                        batch_year = :batch_year,
                        percentage = :percentage,
                        study_gap = :study_gap
                    WHERE id = :id
                ";

                $stmt = $pdo->prepare($query);
                $stmt->execute([
                    'id' => $existingRecord['id'],
                    'father_name' => $data['father_name'] ?? '',
                    'mother_name' => $data['mother_name'] ?? '',
                    'father_occupation' => $data['father_occupation'] ?? '',
                    'mother_occupation' => $data['mother_occupation'] ?? '',
                    'address' => $data['address'] ?? '',
                    'father_mobile' => $data['father_mobile'] ?? '',
                    'mother_mobile' => $data['mother_mobile'] ?? '',
                    'father_income' => $data['father_income'] ?? '',
                    'mother_income' => $data['mother_income'] ?? '',
                    'caste_category' => $data['caste_category'] ?? '',
                    'aadhaar_number' => $data['aadhaar_number'] ?? '',
                    'previous_board_university' => $data['previous_board_university'] ?? '',
                    'last_class_result' => $data['last_class_result'] ?? '',
                    'subjects_papers' => $data['subjects_papers'] ?? '',
                    'additional_subjects' => $data['additional_subjects'] ?? '',
                    'qualification' => $data['qualification'] ?? '',
                    'lock_form_student' => !empty($data['lock_form_student']) ? 1 : 0,
                    'stream' => $data['stream'] ?? '',
                    'institute' => $data['institute'] ?? '',
                    'batch_year' => $data['batch_year'] ?? '',
                    'percentage' => $data['percentage'] ?? '',
                    'study_gap' => $data['study_gap'] ?? '',
                ]);
                $logger->info('UPDATE query executed successfully', ['student_id' => $data['student_id'], 'rows_affected' => $stmt->rowCount()]);
            } else {
                // Insert a new record if no existing record is found
                $logger->info('Executing INSERT query', ['student_id' => $data['student_id']]);
                $query = "
                    INSERT INTO personal_info (
                        student_id, father_name, mother_name, father_occupation, mother_occupation,
                        address, father_mobile, mother_mobile, father_income, mother_income,
                        caste_category, aadhaar_number, previous_board_university, last_class_result,
                        subjects_papers, additional_subjects, qualification,
                        lock_form_student, stream, institute, batch_year, percentage, study_gap
                    ) VALUES (
                        :student_id, :father_name, :mother_name, :father_occupation, :mother_occupation,
                        :address, :father_mobile, :mother_mobile, :father_income, :mother_income,
                        :caste_category, :aadhaar_number, :previous_board_university, :last_class_result,
                        :subjects_papers, :additional_subjects, :qualification,
                        :lock_form_student, :stream, :institute, :batch_year, :percentage, :study_gap
                    )
                ";

                $stmt = $pdo->prepare($query);
                $stmt->execute([
                    'student_id' => $data['student_id'],
                    'father_name' => $data['father_name'] ?? '',
                    'mother_name' => $data['mother_name'] ?? '',
                    'father_occupation' => $data['father_occupation'] ?? '',
                    'mother_occupation' => $data['mother_occupation'] ?? '',
                    'address' => $data['address'] ?? '',
                    'father_mobile' => $data['father_mobile'] ?? '',
                    'mother_mobile' => $data['mother_mobile'] ?? '',
                    'father_income' => $data['father_income'] ?? '',
                    'mother_income' => $data['mother_income'] ?? '',
                    'caste_category' => $data['caste_category'] ?? '',
                    'aadhaar_number' => $data['aadhaar_number'] ?? '',
                    'previous_board_university' => $data['previous_board_university'] ?? '',
                    'last_class_result' => $data['last_class_result'] ?? '',
                    'subjects_papers' => $data['subjects_papers'] ?? '',
                    'additional_subjects' => $data['additional_subjects'] ?? '',
                    'qualification' => $data['qualification'] ?? '',
                    'lock_form_student' => !empty($data['lock_form_student']) ? 1 : 0,
                    'stream' => $data['stream'] ?? '',
                    'institute' => $data['institute'] ?? '',
                    'batch_year' => $data['batch_year'] ?? '',
                    'percentage' => $data['percentage'] ?? '',
                    'study_gap' => $data['study_gap'] ?? '',
                ]);
                $logger->info('INSERT query executed successfully', ['student_id' => $data['student_id'], 'last_insert_id' => $pdo->lastInsertId()]);
            }

            // Auto-assign final registration number when form is submitted and locked
            if (!empty($data['lock_form_student'])) {
                try {
                    // Check if final registration number already exists
                    $stmt = $pdo->prepare("SELECT final_registration_number FROM students WHERE id = ? LIMIT 1");
                    $stmt->execute([$data['student_id']]);
                    $studentRow = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (empty($studentRow['final_registration_number'])) {
                        // Generate final registration number
                        $serial = str_pad((string)$data['student_id'], 2, "0", STR_PAD_LEFT);
                        $month = date('m');
                        $year = date('y');
                        $finalRegistrationNumber = "GTB{$serial}{$month}{$year}";
                        
                        // Update student with final registration number
                        $stmt = $pdo->prepare("UPDATE students SET final_registration_number = ? WHERE id = ?");
                        $stmt->execute([$finalRegistrationNumber, $data['student_id']]);
                        
                        $logger->info('Final registration number auto-assigned on form submission', [
                            'student_id' => $data['student_id'],
                            'final_registration_number' => $finalRegistrationNumber,
                            'actor' => $user->id ?? null
                        ]);
                    } else {
                        $logger->info('Final registration number already exists', [
                            'student_id' => $data['student_id'],
                            'final_registration_number' => $studentRow['final_registration_number']
                        ]);
                    }
                } catch (PDOException $e) {
                    $logger->error('Failed to auto-assign final registration number', [
                        'error' => $e->getMessage(),
                        'student_id' => $data['student_id']
                    ]);
                    // Don't fail the whole request if this fails
                }
            }

            $logger->info('Final registration details updated', ['student_id' => $data['student_id'], 'actor' => $user->id ?? null]);
            jsonResponse("success", "Final registration details updated successfully.", [], 200);
        } catch (PDOException $e) {
            $logger->error("Failed to update final registration details", ['error' => $e->getMessage(), 'student_id' => $data['student_id'] ?? null]);
            jsonResponse("error", "Failed to update final registration details.", [], 500);
        }
    } else {
        $logger->warning('final_registration endpoint - method not allowed', ['method' => $method]);
        jsonResponse("error", "Method not allowed.", [], 405);
    }
} catch (Exception $e) {
    $logger->critical('Unhandled exception in final_registration.php', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    jsonResponse("error", "Internal server error.", [], 500);
}
?>

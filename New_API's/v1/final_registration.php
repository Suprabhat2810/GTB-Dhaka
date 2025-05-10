<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $user = authenticate();

    if (!$user) {
        jsonResponse("error", "Unauthorized access.", [], 403);
        exit;
    }

    // Determine student ID: use query param for admin, token for student
    $student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
    if ($user->role === 'student') {
        $student_id = $user->id; // Use ID from token for students
    } elseif ($user->role === 'admin' && $student_id <= 0) {
        jsonResponse("error", "Invalid student ID.", [], 400);
        exit;
    }

    try {
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
                CASE 
                    WHEN d.document_path IS NOT NULL 
                    THEN CONCAT('http://localhost/School_project/Final_Enhancements/New_API\'s/', REPLACE(d.document_path, '../', '')) 
                    ELSE NULL 
                END AS photo
            FROM students s
            LEFT JOIN personal_info pi ON s.id = pi.student_id
            LEFT JOIN approvals a ON s.id = a.student_id
            LEFT JOIN documents d ON s.id = d.student_id AND d.document_type = 'Photo' AND d.status = 'verified'
            WHERE s.id = :student_id AND a.approved = 1
        ";

        $stmt = $pdo->prepare($query);
        $stmt->execute(['student_id' => $student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            jsonResponse("error", "Student not found or not approved.", [], 404);
        }

        // Fetch compulsory subjects based on the program
        $compulsory_subjects_query = "
            SELECT subject_name 
            FROM subjects 
            WHERE department = :program
        ";
        $comp_sub_stmt = $pdo->prepare($compulsory_subjects_query);
        $comp_sub_stmt->execute(['program' => $student['program']]);
        $compulsory_subjects = $comp_sub_stmt->fetchAll(PDO::FETCH_COLUMN);

        // Split the full name into first_name and last_name
        $nameParts = explode(' ', $student['name'], 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        // Restructure the response to match PersonalProfile interface
        $response = [
            'student_id' => $student['student_id'],
            'vid_number' => $student['final_registration_number'] ?? '',
            'date_of_birth' => $student['date_of_birth'] ?? '',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => $student['gender'] ?? '',
            'phone' => $student['phone'] ?? '',
            'email' => $student['email'] ?? '',
            'program' => $student['program'] ?? '',
            'status' => $student['approved'] ? 'Approved' : 'Pending',
            'photo' => $student['photo'] ?? '',
            'personal_info' => [
                'id' => $student['personal_info_id'] ?? 0,
                'student_id' => $student['student_id'],
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
                'qualification' => $student['qualification'],
                'compulsory_subjects' => implode(',', $compulsory_subjects),
                'lock_form_student' => (bool)($student['lock_form_student'] ?? false),
                'stream' => $student['stream'] ?? '',
                'institute' => $student['institute'] ?? '',
                'batch_year' => $student['batch_year'] ?? '',
                'percentage' => $student['percentage'] ?? '',
                'study_gap' => $student['study_gap'] ?? '',
            ],
        ];

        // Debug: Log the fetched student data
        $log->info("Fetched student for final registration: " . json_encode($response));

        jsonResponse("success", "Student data retrieved successfully.", $response, 200);
    } catch (PDOException $e) {
        $log->error("Failed to fetch student data: " . $e->getMessage());
        jsonResponse("error", "Failed to fetch student data.", [], 500);
    }
} elseif ($method === 'POST') {
    $user = authenticate();

    if (!$user) {
        jsonResponse("error", "Unauthorized access.", [], 403);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data || !isset($data['student_id'])) {
        jsonResponse("error", "Invalid data.", [], 400);
        exit;
    }

    // Validate user access
    if ($user->role === 'student' && $user->id !== $data['student_id']) {
        jsonResponse("error", "Unauthorized access.", [], 403);
        exit;
    }

    // Check if the student is approved
    try {
        $stmt = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ?");
        $stmt->execute([$data['student_id']]);
        $approval = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$approval || $approval['approved'] != 1) {
            jsonResponse("error", "Student not approved yet.", [], 400);
            exit;
        }
    } catch (PDOException $e) {
        $log->error("Failed to check student approval status: " . $e->getMessage());
        jsonResponse("error", "Failed to check student approval status.", [], 500);
        exit;
    }

    // Check if the form is locked (only applies to students)
    if ($user->role === 'student') {
        try {
            $stmt = $pdo->prepare("SELECT lock_form_student FROM personal_info WHERE student_id = ?");
            $stmt->execute([$data['student_id']]);
            $personalInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($personalInfo && $personalInfo['lock_form_student']) {
                jsonResponse("error", "Form is locked and cannot be edited.", [], 403);
                exit;
            }
        } catch (PDOException $e) {
            $log->error("Failed to check lock_form_student status: " . $e->getMessage());
            jsonResponse("error", "Failed to check form lock status.", [], 500);
            exit;
        }
    }

    try {
        // Check if a personal_info record already exists for this student
        $stmt = $pdo->prepare("SELECT id FROM personal_info WHERE student_id = ?");
        $stmt->execute([$data['student_id']]);
        $existingRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingRecord && isset($data['id']) && $data['id'] == $existingRecord['id']) {
            // Update the existing record using the provided id
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
                'id' => $data['id'],
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
                'lock_form_student' => $data['lock_form_student'] ? 1 : 0,
                'stream' => $data['stream'] ?? '',
                'institute' => $data['institute'] ?? '',
                'batch_year' => $data['batch_year'] ?? '',
                'percentage' => $data['percentage'] ?? '',
                'study_gap' => $data['study_gap'] ?? '',
            ]);
        } else {
            // Insert a new record if no existing record is found
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
                'lock_form_student' => $data['lock_form_student'] ? 1 : 0,
                'stream' => $data['stream'] ?? '',
                'institute' => $data['institute'] ?? '',
                'batch_year' => $data['batch_year'] ?? '',
                'percentage' => $data['percentage'] ?? '',
                'study_gap' => $data['study_gap'] ?? '',
            ]);
        }

        jsonResponse("success", "Final registration details updated successfully.", [], 200);
    } catch (PDOException $e) {
        $log->error("Failed to update final registration details: " . $e->getMessage());
        jsonResponse("error", "Failed to update final registration details.", [], 500);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
?>
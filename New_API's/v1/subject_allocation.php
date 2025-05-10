<?php
require '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $user = authenticate('admin');

    $data = json_decode(file_get_contents("php://input"), true);

    // Handle program creation
    if (isset($data['action']) && $data['action'] === 'create_program') {
        if (!isset($data['name']) || !isset($data['code'])) {
            jsonResponse("error", "Missing required fields: name or code.", [], 400);
            exit;
        }

        $name = filter_var($data['name'], FILTER_SANITIZE_STRING);
        $code = filter_var($data['code'], FILTER_SANITIZE_STRING);

        if (empty($name) || empty($code)) {
            jsonResponse("error", "Program name and code cannot be empty.", [], 400);
            exit;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO programs (name, code, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$name, $code]);
            jsonResponse("success", "Program '$name' created successfully.", [], 201);
        } catch (PDOException $e) {
            $log->error("Failed to create program: " . $e->getMessage());
            jsonResponse("error", "Failed to create program: " . $e->getMessage(), [], 500);
        }
        exit;
    }

    // Handle subject allocation (create/update subjects)
    if (!isset($data['program'], $data['semester'], $data['subjects'], $data['valid_from'], $data['valid_to'])) {
        jsonResponse("error", "Missing required fields: program, semester, subjects, valid_from, or valid_to.", [], 400);
        exit;
    }

    $programId = filter_var($data['program'], FILTER_VALIDATE_INT);
    $semester = filter_var($data['semester'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    $subjects = $data['subjects'];
    $validFrom = filter_var($data['valid_from'], FILTER_SANITIZE_STRING);
    $validTo = filter_var($data['valid_to'], FILTER_SANITIZE_STRING);

    if (!$programId || !$semester || !is_array($subjects) || empty($subjects) || !$validFrom || !$validTo) {
        jsonResponse("error", "Invalid input data provided.", [], 400);
        exit;
    }

    try {
        $validFromDate = DateTime::createFromFormat('Y-m-d', $validFrom);
        $validToDate = DateTime::createFromFormat('Y-m-d', $validTo);
        if (!$validFromDate || !$validToDate || $validFromDate > $validToDate) {
            jsonResponse("error", "Invalid date range: valid_to must be after valid_from.", [], 400);
            exit;
        }

        $stmt = $pdo->prepare("SELECT name, code FROM programs WHERE id = ?");
        $stmt->execute([$programId]);
        $program = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$program) {
            jsonResponse("error", "Invalid program ID specified.", [], 400);
            exit;
        }
        $programName = $program['name'];
        $programCode = $program['code'];

        $log->info("Program ID: $programId, Program Name: $programName, Program Code: $programCode");

        $updatesMade = false;
        $year = date('Y');

        // Fetch existing subjects for comparison
        $stmt = $pdo->prepare("SELECT subject_name, instructor, subject_code, valid_from, valid_to FROM subjects WHERE department = ? AND semester = ?");
        $stmt->execute([$programName, $semester]);
        $existingSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($subjects as $subject) {
            $subjectName = filter_var($subject['name'], FILTER_SANITIZE_STRING);
            $instructor = isset($subject['instructor']) ? filter_var($subject['instructor'], FILTER_SANITIZE_STRING) : 'N/A';
            $providedSubjectCode = isset($subject['subject_code']) && !empty($subject['subject_code']) ? filter_var($subject['subject_code'], FILTER_SANITIZE_STRING) : '';

            $subjectCode = $providedSubjectCode;
            if (empty($subjectCode)) {
                $baseCode = $programCode;
                $maxAttempts = 1000;
                $attempt = 0;
                do {
                    $suffix = str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
                    $subjectCode = $baseCode . $suffix;
                    $stmtCheckCode = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE department = ? AND subject_code = ? AND id NOT IN (SELECT id FROM subjects WHERE subject_name = ? AND department = ? AND semester = ?)");
                    $stmtCheckCode->execute([$programName, $subjectCode, $subjectName, $programName, $semester]);
                    $attempt++;
                } while ($stmtCheckCode->fetchColumn() > 0 && $attempt < $maxAttempts);

                if ($attempt >= $maxAttempts) {
                    jsonResponse("error", "Unable to generate a unique subject code after multiple attempts.", [], 500);
                    exit;
                }
            } else {
                if (strpos($subjectCode, $programCode) !== 0) {
                    jsonResponse("error", "Provided subject code '$subjectCode' must start with the program code '$programCode'.", [], 400);
                    exit;
                }
                // Only check for duplicates among new subjects, excluding the current subject if it exists
                $existing = array_filter($existingSubjects, function($es) use ($subjectName) {
                    return $es['subject_name'] !== $subjectName;
                });
                $duplicate = array_filter($existing, function($es) use ($subjectCode) {
                    return $es['subject_code'] === $subjectCode;
                });
                if (!empty($duplicate)) {
                    jsonResponse("error", "Subject code '$subjectCode' already exists for this program.", [], 400);
                    exit;
                }
            }

            $existingSubject = array_filter($existingSubjects, function($es) use ($subjectName, $instructor, $subjectCode, $validFrom, $validTo) {
                return $es['subject_name'] === $subjectName &&
                       $es['instructor'] === $instructor &&
                       $es['subject_code'] === $subjectCode &&
                       $es['valid_from'] === $validFrom &&
                       $es['valid_to'] === $validTo;
            });

            if (empty($existingSubject)) {
                $stmtInsert = $pdo->prepare("INSERT INTO subjects (department, semester, year, subject_name, valid_from, valid_to, subject_code, instructor, schedule, credits, progress) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtInsert->execute([$programName, $semester, $year, $subjectName, $validFrom, $validTo, $subjectCode, $instructor, 'N/A', 'N/A', 'N/A']);
                $updatesMade = true;
            } else {
                // Check if any field has changed to trigger an update
                $existingMatch = reset($existingSubject);
                if ($existingMatch['instructor'] !== $instructor || $existingMatch['valid_from'] !== $validFrom || $existingMatch['valid_to'] !== $validTo || $existingMatch['subject_code'] !== $subjectCode) {
                    $stmtUpdate = $pdo->prepare("UPDATE subjects SET instructor = ?, valid_from = ?, valid_to = ?, subject_code = ? WHERE department = ? AND semester = ? AND subject_name = ?");
                    $stmtUpdate->execute([$instructor, $validFrom, $validTo, $subjectCode, $programName, $semester, $subjectName]);
                    $updatesMade = true;
                }
            }
        }

        if (!$updatesMade) {
            jsonResponse("warning", "Subjects are already allocated with no changes detected.", [], 200);
            exit;
        }

        $currentDate = new DateTime('now');
        $stmt = $pdo->prepare("SELECT id, program, semester, year FROM students WHERE program = ?");
        $stmt->execute([$programName]);
        $studentsToUpdate = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($studentsToUpdate as $student) {
            $stmtCheck = $pdo->prepare("SELECT valid_to FROM subjects WHERE department = ? AND semester = ? ORDER BY valid_to DESC LIMIT 1");
            $stmtCheck->execute([$programName, $student['semester']]);
            $lastValidTo = $stmtCheck->fetchColumn();
            if ($lastValidTo && new DateTime($lastValidTo) < $currentDate) {
                $newSemester = $student['semester'] + 1;
                $newYear = $student['year'];
                if ($newSemester > 8) {
                    $newYear += 1;
                    $newSemester = 1;
                }
                $stmtUpdate = $pdo->prepare("UPDATE students SET semester = ?, year = ? WHERE id = ?");
                $stmtUpdate->execute([$newSemester, $newYear, $student['id']]);
                $log->info("Updated student ID {$student['id']} to semester $newSemester, year $newYear");
            }
        }

        jsonResponse("success", "Subjects created/updated successfully.", ['updates_made' => true], 201);
    } catch (PDOException $e) {
        $log->error("Subject allocation failed: " . $e->getMessage());
        jsonResponse("error", "Failed to allocate subjects: " . $e->getMessage(), [], 500);
    }
} elseif ($method === 'GET') {
    $user = authenticate('admin');

    $program = filter_var($_GET['program'] ?? '', FILTER_VALIDATE_INT);
    $semester = filter_var($_GET['semester'] ?? 1, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);

    try {
        if ($program) {
            $stmt = $pdo->prepare("SELECT name FROM programs WHERE id = ?");
            $stmt->execute([$program]);
            $programName = $stmt->fetchColumn();
            if (!$programName) {
                jsonResponse("error", "Invalid program ID specified.", [], 400);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT s.id, s.department, s.semester, s.year, s.subject_name, s.valid_from, s.valid_to, s.subject_code, s.instructor, s.schedule, s.credits, s.progress
                FROM subjects s
                WHERE s.department = ? AND s.semester = ? AND s.valid_from <= CURDATE() AND s.valid_to >= CURDATE()
            ");
            $stmt->execute([$programName, $semester]);
            $allocatedSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse("success", "Allocated subjects retrieved successfully.", [
                "allocated_subjects" => $allocatedSubjects,
            ]);
        } else {
            $stmt = $pdo->query("SELECT id, name FROM programs");
            $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse("success", "Programs retrieved.", [
                "programs" => $programs,
            ]);
        }
    } catch (PDOException $e) {
        $log->error("Failed to fetch data: " . $e->getMessage());
        jsonResponse("error", "Server error: " . $e->getMessage(), [], 500);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}
<?php
// student_track.php — hardened, logged, non-breaking replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('student_track');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

try {
    if ($method === 'GET') {
        $user = authenticate('admin');
        $actor = $user->id ?? null;

        try {
            $logger->info('student_track: fetching approved students', ['actor' => $actor]);

            // Fetch approved students with photo and lock_form_student status
            $query = "
                SELECT 
                    s.id,
                    s.name,
                    s.program,
                    COALESCE(pi.lock_form_student, 0) AS lock_form_student,
                    d.document_path AS photo_path
                FROM students s
                LEFT JOIN personal_info pi ON s.id = pi.student_id
                LEFT JOIN approvals a ON s.id = a.student_id
                LEFT JOIN documents d ON s.id = d.student_id AND d.document_type = 'Photo' AND d.status = 'verified'
                WHERE a.approved = 1
            ";

            $stmt = $pdo->prepare($query);
            $stmt->execute();
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($students)) {
                $logger->info('student_track: no approved students found', ['actor' => $actor]);
                jsonResponse("error", "No approved students found.", [], 404);
            }

            // Build safe photo URL if present, and sanitize rows
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');

            $out = [];
            foreach ($students as $s) {
                $photo = null;
                if (!empty($s['photo_path'])) {
                    // prefer relative stored paths — construct absolute URL safely
                    $path = ltrim($s['photo_path'], '/\\');
                    $photo = $scheme . '://' . $host . $base . '/' . $path;
                }

                $out[] = [
                    'id' => (int)$s['id'],
                    'name' => $s['name'] ?? '',
                    'program' => $s['program'] ?? '',
                    'lock_form_student' => (bool)($s['lock_form_student'] ?? false),
                    'photo' => $photo,
                ];
            }

            $durationMs = round((microtime(true) - $start) * 1000, 2);
            $logger->info('student_track: approved students fetched', ['count' => count($out), 'actor' => $actor, 'duration_ms' => $durationMs]);

            // Return the students array directly (keeps original shape)
            jsonResponse("success", "Approved students retrieved successfully.", $out, 200);
        } catch (PDOException $e) {
            $logger->error('student_track: DB error on GET', ['error' => $e->getMessage(), 'actor' => $actor]);
            jsonResponse("error", "Failed to fetch approved students.", [], 500);
        }
    }

    elseif ($method === 'PUT') {
        $user = authenticate('admin');
        $actor = $user->id ?? null;

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || !isset($data['id'])) {
            $logger->warning('student_track PUT: invalid payload', ['payload' => $data, 'actor' => $actor]);
            jsonResponse("error", "Invalid data. Student ID is required.", [], 400);
        }

        $student_id = filter_var($data['id'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
        if (!$student_id) {
            $logger->warning('student_track PUT: invalid student id', ['id' => $data['id'] ?? null, 'actor' => $actor]);
            jsonResponse("error", "Invalid data. Student ID is required.", [], 400);
        }

        // Verify student exists and is approved
        try {
            $checkStmt = $pdo->prepare("
                SELECT s.id 
                FROM students s
                LEFT JOIN approvals a ON s.id = a.student_id
                WHERE s.id = ? AND a.approved = 1
                LIMIT 1
            ");
            $checkStmt->execute([$student_id]);
            $student = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                $logger->info('student_track PUT: student not found or not approved', ['student_id' => $student_id, 'actor' => $actor]);
                jsonResponse("error", "Student not found or not approved.", [], 404);
            }
        } catch (PDOException $e) {
            $logger->error('student_track PUT: DB error during verification', ['error' => $e->getMessage(), 'student_id' => $student_id, 'actor' => $actor]);
            jsonResponse("error", "Failed to verify student.", [], 500);
        }

        // Allowed updatable fields mapping
        $allowedFields = [
            'name' => 'name',
            'date_of_birth' => 'date_of_birth',
            'gender' => 'gender',
            'phone' => 'phone',
            'email' => 'email',
            'program' => 'program',
            'final_registration_number' => 'final_registration_number'
        ];

        $updateFields = [];
        $params = ['id' => $student_id];

        foreach ($allowedFields as $field => $column) {
            if (array_key_exists($field, $data)) {
                // small sanitization per field
                $val = $data[$field];
                if (is_string($val)) $val = trim($val);
                $updateFields[] = "$column = :$field";
                $params[$field] = $val;
            }
        }

        if (empty($updateFields)) {
            $logger->warning('student_track PUT: no update fields provided', ['student_id' => $student_id, 'actor' => $actor]);
            jsonResponse("error", "No fields provided to update.", [], 400);
        }

        try {
            $query = "UPDATE students SET " . implode(', ', $updateFields) . " WHERE id = :id";
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);

            $logger->info('student_track PUT: student updated', ['student_id' => $student_id, 'updated_fields' => array_keys($params), 'actor' => $actor]);
            jsonResponse("success", "Student details updated successfully.", [], 200);
        } catch (PDOException $e) {
            $logger->error('student_track PUT: failed to update student', ['error' => $e->getMessage(), 'student_id' => $student_id, 'actor' => $actor]);
            jsonResponse("error", "Failed to update student details.", [], 500);
        }
    }

    else {
        $logger->warning('student_track: method not allowed', ['method' => $method]);
        jsonResponse("error", "Method not allowed.", [], 405);
    }
} catch (Exception $e) {
    $logger->critical('student_track: unexpected error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    jsonResponse("error", "Internal server error.", [], 500);
}

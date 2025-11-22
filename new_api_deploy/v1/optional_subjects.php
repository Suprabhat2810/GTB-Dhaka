<?php
// optional_subject.php — hardened, logged, PDO-correct replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('optional_subjects');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

try {
    if ($method === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    if ($method === 'POST') {
        // Requires admin role
        $user = authenticate('admin');

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $logger->warning('optional_subject POST: invalid JSON payload', ['actor' => $user->id ?? null]);
            jsonResponse("error", "Invalid JSON payload.", [], 400);
        }

        $name = trim((string)($data['name'] ?? ''));
        $fees = isset($data['fees']) ? (float)$data['fees'] : 0.00;
        $subjectCode = trim((string)($data['subject_code'] ?? ''));
        $semester = isset($data['semester']) ? filter_var($data['semester'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 8]]) : false;
        $compatibleProgramsArr = $data['compatible_programs'] ?? [];

        // Validate inputs
        if ($name === '' || $subjectCode === '' || $semester === false || $fees < 0 || !is_array($compatibleProgramsArr) || count($compatibleProgramsArr) === 0) {
            $logger->info('optional_subject POST: invalid input', ['input' => $data, 'actor' => $user->id ?? null]);
            jsonResponse("error", "Invalid input data: name, subject_code, semester (1-8), fees, or compatible_programs required.", [], 400);
        }

        // Sanitize compatible programs and encode as JSON
        $compatibleProgramsClean = array_values(array_filter(array_map(function ($p) {
            return is_string($p) ? trim($p) : null;
        }, $compatibleProgramsArr)));

        if (empty($compatibleProgramsClean)) {
            $logger->info('optional_subject POST: compatible_programs empty after sanitize', ['actor' => $user->id ?? null]);
            jsonResponse("error", "compatible_programs must contain at least one program.", [], 400);
        }

        $compatibleProgramsJson = json_encode($compatibleProgramsClean, JSON_UNESCAPED_UNICODE);

        // Check subject code uniqueness
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM optional_subjects WHERE subject_code = ?");
        $stmt->execute([$subjectCode]);
        if ((int)$stmt->fetchColumn() > 0) {
            $logger->info('optional_subject POST: subject code exists', ['subject_code' => $subjectCode, 'actor' => $user->id ?? null]);
            jsonResponse("error", "Subject code '$subjectCode' already exists.", [], 400);
        }

        // Insert new subject
        $stmt = $pdo->prepare("INSERT INTO optional_subjects (name, fees, subject_code, semester, compatible_programs) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $fees, $subjectCode, $semester, $compatibleProgramsJson]);

        $lastInsertId = $pdo->lastInsertId();

        $durationMs = round((microtime(true) - $start) * 1000, 2);
        $logger->info('optional_subject POST: subject added', ['id' => $lastInsertId, 'subject_code' => $subjectCode, 'actor' => $user->id ?? null, 'duration_ms' => $durationMs]);

        jsonResponse("success", "Subject added successfully", ["id" => $lastInsertId], 201);
    }

    elseif ($method === 'GET') {
        // Any authenticated user
        $user = authenticate();

        $program = isset($_GET['program']) ? trim((string)$_GET['program']) : '';
        $semester = isset($_GET['semester']) ? filter_var($_GET['semester'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) : 0;

        $query = "SELECT * FROM optional_subjects";
        $params = [];
        $clauses = [];

        if ($program !== '') {
            // Use JSON_QUOTE(?) to safely pass a JSON string for JSON_CONTAINS
            $clauses[] = "JSON_CONTAINS(compatible_programs, JSON_QUOTE(?), '$')";
            $params[] = $program;
        }
        if ($semester > 0) {
            $clauses[] = "semester = ?";
            $params[] = $semester;
        }

        if (!empty($clauses)) {
            $query .= " WHERE " . implode(" AND ", $clauses);
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Transform compatible_programs JSON -> array
        foreach ($subjects as &$subject) {
            $subject['compatible_programs'] = json_decode($subject['compatible_programs'] ?? '[]', true);
            if (!is_array($subject['compatible_programs'])) {
                $subject['compatible_programs'] = [];
            }
        }
        unset($subject);

        $durationMs = round((microtime(true) - $start) * 1000, 2);
        $logger->info('optional_subject GET: retrieved subjects', ['count' => count($subjects), 'actor' => $user->id ?? null, 'duration_ms' => $durationMs, 'filters' => ['program' => $program, 'semester' => $semester]]);

        jsonResponse("success", "Optional subjects retrieved successfully.", ["data" => $subjects], 200);
    }

    else {
        $logger->warning('optional_subject: method not allowed', ['method' => $method]);
        jsonResponse("error", "Method not allowed", [], 405);
    }
} catch (PDOException $e) {
    $logger->error('optional_subject: DB error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    jsonResponse("error", "Server error: " . $e->getMessage(), [], 500);
} catch (Exception $e) {
    $logger->critical('optional_subject: unexpected error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    jsonResponse("error", "Server error.", [], 500);
}

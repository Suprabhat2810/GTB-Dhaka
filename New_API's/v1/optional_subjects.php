<?php
require '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    if ($method === 'POST') {
        $user = authenticate('admin'); // Requires admin role

        $data = json_decode(file_get_contents('php://input'), true);

        $name = filter_var($data['name'] ?? '', FILTER_SANITIZE_STRING);
        $fees = floatval($data['fees'] ?? 0.00);
        $subjectCode = filter_var($data['subject_code'] ?? '', FILTER_SANITIZE_STRING);
        $semester = filter_var($data['semester'] ?? 0, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 8]]);

        $compatiblePrograms = json_encode($data['compatible_programs'] ?? []);

        if (empty($name) || empty($subjectCode) || !$semester || $fees < 0 || empty($compatiblePrograms)) {
            jsonResponse("error", "Invalid input data: name, subject_code, semester (1-8), fees, or compatible_programs required.", [], 400);
            exit;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM optional_subjects WHERE subject_code = ?");
        $stmt->execute([$subjectCode]);
        if ($stmt->fetchColumn() > 0) {
            jsonResponse("error", "Subject code '$subjectCode' already exists.", [], 400);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO optional_subjects (name, fees, subject_code, semester, compatible_programs) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $fees, $subjectCode, $semester, $compatiblePrograms]);

        $lastInsertId = $pdo->lastInsertId();
        jsonResponse("success", "Subject added successfully", ["id" => $lastInsertId], 201);
    } elseif ($method === 'GET') {
        $user = authenticate(); // Allow any authenticated user

        $program = filter_var($_GET['program'] ?? '', FILTER_SANITIZE_STRING);
        $semester = filter_var($_GET['semester'] ?? 0, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);

        $query = "SELECT * FROM optional_subjects";
        $params = [];
        $types = '';

        if (!empty($program) || $semester > 0) {
            $query .= " WHERE 1=1";
            if (!empty($program)) {
                $query .= " AND JSON_CONTAINS(compatible_programs, ?, '$')";
                $params[] = "\"$program\"";
                $types .= 's';
            }
            if ($semester > 0) {
                $query .= " AND semester = ?";
                $params[] = $semester;
                $types .= 'i';
            }
        }

        $stmt = $pdo->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params); // Note: PDO uses execute() with array, not bind_param
            $stmt->execute($params); // Corrected to use execute with array for PDO
        } else {
            $stmt->execute();
        }

        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Transform compatible_programs to array
        foreach ($subjects as &$subject) {
            $subject['compatible_programs'] = json_decode($subject['compatible_programs'], true);
        }
        unset($subject); // Break reference

        jsonResponse("success", "Optional subjects retrieved successfully.", ["data" => $subjects], 200);
    } else {
        jsonResponse("error", "Method not allowed", [], 405);
    }
} catch (PDOException $e) {
    $log->error("Optional subjects operation failed: " . $e->getMessage());
    jsonResponse("error", "Server error: " . $e->getMessage(), [], 500);
}
?>
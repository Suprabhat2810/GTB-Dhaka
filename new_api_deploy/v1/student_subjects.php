<?php
// student_subject.php — hardened, logged, non-breaking replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('student_subjects');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

try {
    if ($method !== 'GET') {
        $logger->warning('student_subject: method not allowed', ['method' => $method]);
        jsonResponse("error", "Method not allowed.", [], 405);
    }

    $user = authenticate('student');
    $studentId = (int)($user->sub ?? $user->id ?? 0);
    if ($studentId <= 0) {
        $logger->warning('student_subject: invalid student context', ['user' => $user]);
        jsonResponse("error", "Unauthorized.", [], 403);
    }

    try {
        $logger->info('student_subject: fetching student program/semester/year', ['student_id' => $studentId]);

        // Fetch student's program, semester, and year
        $stmt = $pdo->prepare("SELECT program, semester, year FROM students WHERE id = ? LIMIT 1");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            $logger->info('student_subject: student not found', ['student_id' => $studentId]);
            jsonResponse("error", "Student not found.", [], 404);
        }

        $programName = (string)($student['program'] ?? '');
        $semester = isset($student['semester']) ? (int)$student['semester'] : 0;
        $year = isset($student['year']) ? (int)$student['year'] : 0;

        if ($programName === '' || $semester <= 0 || $year <= 0) {
            $logger->info('student_subject: insufficient student academic info', ['student_id' => $studentId, 'program' => $programName, 'semester' => $semester, 'year' => $year]);
            jsonResponse("success", "No subjects found for this semester.", [
                'subjects' => [],
                'total_credits' => 0,
                'total_subjects' => 0
            ], 200);
        }

        $currentDate = date('Y-m-d');

        // Fetch subjects active for this student (respect validity window)
        $stmt = $pdo->prepare("
            SELECT 
                s.id,
                s.department,
                s.semester,
                s.year,
                s.subject_name,
                s.valid_from,
                s.valid_to,
                s.subject_code,
                s.instructor,
                s.schedule,
                s.credits,
                s.progress,
                s.type
            FROM subjects s
            WHERE s.department = ?
              AND s.semester = ?
              AND s.year = ?
              AND (s.valid_from IS NULL OR s.valid_from <= ?)
              AND (s.valid_to IS NULL OR s.valid_to >= ?)
            ORDER BY s.subject_name ASC
        ");
        $stmt->execute([$programName, $semester, $year, $currentDate, $currentDate]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($subjects)) {
            $logger->info('student_subject: no subjects found', ['student_id' => $studentId, 'program' => $programName, 'semester' => $semester, 'year' => $year]);
            jsonResponse("success", "No subjects found for this semester.", [
                'subjects' => [],
                'total_credits' => 0,
                'total_subjects' => 0
            ], 200);
        }

        // Calculate total credits and total subjects (defensive numeric casts)
        $totalCredits = 0.0;
        foreach ($subjects as $sub) {
            $credits = isset($sub['credits']) ? (float)$sub['credits'] : 0.0;
            $totalCredits += $credits;
        }
        $totalSubjects = count($subjects);

        $durationMs = round((microtime(true) - $start) * 1000, 2);
        $logger->info('student_subject: subjects retrieved', [
            'student_id' => $studentId,
            'program' => $programName,
            'semester' => $semester,
            'year' => $year,
            'count' => $totalSubjects,
            'total_credits' => $totalCredits,
            'duration_ms' => $durationMs
        ]);

        $data = [
            'subjects' => $subjects,
            'total_credits' => $totalCredits,
            'total_subjects' => $totalSubjects
        ];

        jsonResponse("success", "Subjects retrieved successfully.", $data, 200);
    } catch (PDOException $e) {
        $logger->error('student_subject: DB error', ['error' => $e->getMessage(), 'student_id' => $studentId]);
        jsonResponse("error", "Failed to fetch subjects: " . $e->getMessage(), [], 500);
    }
} catch (Exception $e) {
    $logger->critical('student_subject: unexpected error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    jsonResponse("error", "Internal server error.", [], 500);
}

<?php
// student_profile.php — hardened, logged, non-breaking replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('student_profile');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

try {
    if ($method !== 'GET') {
        $logger->warning('student_profile: method not allowed', ['method' => $method]);
        jsonResponse("error", "Method not allowed.", [], 405);
    }

    // Only students can access their profile
    $user = authenticate('student');
    $studentId = (int)($user->sub ?? $user->id ?? 0);


    if ($studentId <= 0) {
        $logger->warning('student_profile: invalid or missing student ID', ['actor' => $user]);
        jsonResponse("error", "Unauthorized.", [], 403);
    }

    try {
        $logger->info('student_profile: fetching profile', ['student_id' => $studentId]);

        $stmt = $pdo->prepare("
            SELECT s.name, s.program, s.semester, a.approved
            FROM students s
            LEFT JOIN approvals a ON s.id = a.student_id
            WHERE s.id = ?
            LIMIT 1
        ");
        $stmt->execute([$studentId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$profile) {
            $logger->info('student_profile: not found', ['student_id' => $studentId]);
            jsonResponse("error", "Student not found.", [], 404);
        }

        // Split the full name safely
        $nameParts = preg_split('/\s+/', trim((string)$profile['name']));
        $firstName = $nameParts[0] ?? '';
        $lastName = isset($nameParts[1]) ? implode(' ', array_slice($nameParts, 1)) : '';

        $data = [
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'semester'   => (int)($profile['semester'] ?? 0),
            'program'    => (string)($profile['program'] ?? ''),
            'status'     => ((int)($profile['approved'] ?? 0) === 1) ? 'Approved' : 'Pending',
        ];

        $durationMs = round((microtime(true) - $start) * 1000, 2);
        $logger->info('student_profile: profile retrieved', [
            'student_id' => $studentId,
            'status' => $data['status'],
            'program' => $data['program'],
            'duration_ms' => $durationMs
        ]);

        jsonResponse("success", "Profile retrieved successfully.", $data, 200);
    } catch (PDOException $e) {
        $logger->error('student_profile: DB error', ['error' => $e->getMessage(), 'student_id' => $studentId]);
        jsonResponse("error", "Failed to fetch profile.", [], 500);
    }
} catch (Exception $e) {
    $logger->critical('student_profile: unexpected error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    jsonResponse("error", "Internal server error.", [], 500);
}

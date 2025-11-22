<?php
// admin_stats.php — hardened & logged version
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('admin_stats');
$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

try {
    if ($method !== 'GET') {
        $logger->warning('Invalid method for admin_stats endpoint', ['method' => $method]);
        jsonResponse("error", "Method not allowed.", [], 405);
    }

    $user = authenticate('admin');
    $pdo = getPDO();

    $logger->info('Admin stats request initiated', [
        'admin_id' => $user->id ?? null,
        'email' => $user->email ?? null
    ]);

    // Total Students
    $stmt = $pdo->query("SELECT COUNT(*) FROM students");
    $totalStudents = (int)$stmt->fetchColumn();

    // Approved Students
    $stmt = $pdo->query("SELECT COUNT(*) FROM approvals WHERE approved = 1");
    $approved = (int)$stmt->fetchColumn();

    // Pending Students
    $stmt = $pdo->query("
        SELECT COUNT(*) 
        FROM students s
        LEFT JOIN approvals a ON s.id = a.student_id
        WHERE a.approved = 0 OR a.approved IS NULL
    ");
    $pending = (int)$stmt->fetchColumn();

    // Total Subjects
    $stmt = $pdo->query("SELECT COUNT(*) FROM subjects");
    $totalSubjects = (int)$stmt->fetchColumn();

    $durationMs = round((microtime(true) - $start) * 1000, 2);

    $logger->info('Admin statistics retrieved successfully', [
        'admin_id' => $user->id ?? null,
        'duration_ms' => $durationMs,
        'stats' => [
            'totalStudents' => $totalStudents,
            'approved' => $approved,
            'pending' => $pending,
            'totalSubjects' => $totalSubjects
        ]
    ]);

    jsonResponse("success", "Statistics retrieved successfully.", [
        "totalStudents" => $totalStudents,
        "approved" => $approved,
        "pending" => $pending,
        "totalSubjects" => $totalSubjects,
    ], 200);

} catch (PDOException $e) {
    $logger->error('Database error in admin_stats.php', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    jsonResponse("error", "Failed to fetch statistics: " . $e->getMessage(), [], 500);

} catch (Exception $e) {
    $logger->critical('Unhandled exception in admin_stats.php', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    jsonResponse("error", "Internal server error.", [], 500);
}

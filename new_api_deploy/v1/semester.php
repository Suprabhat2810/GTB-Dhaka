<?php

require_once __DIR__ . '/../config.php';

$logger = getLogger('semester');
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    jsonResponse('error', 'Method not allowed.', [], 405);
    exit;
}

try {
    // Authenticate admin (optional if you want to restrict)
    $user = authenticate();

    $pdo = getPDO();

    // Fetch distinct semesters for students who have finalized registration
    $stmt = $pdo->prepare("
        SELECT DISTINCT semester 
        FROM students 
        WHERE semester IS NOT NULL 
          AND final_registration_number IS NOT NULL 
        ORDER BY semester ASC
    ");
    $stmt->execute();
    $semesters = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$semesters) {
        jsonResponse('success', 'No semesters found.', ['semesters' => []], 200);
    } else {
        jsonResponse('success', 'Semesters fetched successfully.', ['semesters' => $semesters], 200);
    }
} catch (PDOException $e) {
    $logger->error('Failed to fetch semesters: ' . $e->getMessage());
    jsonResponse('error', 'Database error occurred while fetching semesters.', [], 500);
} catch (Exception $e) {
    $logger->error('Unexpected error: ' . $e->getMessage());
    jsonResponse('error', 'Unexpected server error.', [], 500);
}

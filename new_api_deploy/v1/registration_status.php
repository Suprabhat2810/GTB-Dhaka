<?php
/**
 * Registration Status API
 * Check if registration is open for a program
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$logger = getLogger('registration_status');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

// CORS preflight
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($method === 'GET') {
    try {
        $programName = $_GET['program'] ?? null;
        
        if (!$programName) {
            jsonResponse("error", "Program name is required.", [], 400);
        }

        // Get semester with registration window (any semester number)
        // First try to find one with an active registration window
        $stmt = $pdo->prepare("
            SELECT 
                ac.id,
                ac.program_id,
                p.name as program_name,
                p.code as program_code,
                ac.semester_number,
                ac.semester_name,
                ac.academic_year,
                ac.start_date,
                ac.end_date,
                ac.registration_start,
                ac.registration_end,
                ac.status
            FROM academic_calendar ac
            JOIN programs p ON ac.program_id = p.id
            WHERE p.name = ?
            AND ac.registration_start IS NOT NULL
            AND ac.registration_end IS NOT NULL
            AND ac.status IN ('active', 'upcoming')
            AND CURDATE() BETWEEN ac.registration_start AND ac.registration_end
            ORDER BY ac.start_date DESC
            LIMIT 1
        ");
        $stmt->execute([$programName]);
        $semester = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If no active window, get the next upcoming one or most recently closed
        if (!$semester) {
            $stmt = $pdo->prepare("
                SELECT 
                    ac.id,
                    ac.program_id,
                    p.name as program_name,
                    p.code as program_code,
                    ac.semester_number,
                    ac.semester_name,
                    ac.academic_year,
                    ac.start_date,
                    ac.end_date,
                    ac.registration_start,
                    ac.registration_end,
                    ac.status
                FROM academic_calendar ac
                JOIN programs p ON ac.program_id = p.id
                WHERE p.name = ?
                AND ac.registration_start IS NOT NULL
                AND ac.registration_end IS NOT NULL
                AND ac.status IN ('active', 'upcoming')
                ORDER BY 
                    CASE 
                        WHEN CURDATE() < ac.registration_start THEN 1  -- Upcoming (not started)
                        WHEN CURDATE() > ac.registration_end THEN 3     -- Past (closed)
                        ELSE 2                                           -- Should not happen (active)
                    END,
                    ABS(DATEDIFF(CURDATE(), ac.registration_start))    -- Closest to today
                LIMIT 1
            ");
            $stmt->execute([$programName]);
            $semester = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$semester) {
            jsonResponse("error", "No semester with registration dates found for this program.", [], 404);
        }

        $today = date('Y-m-d');
        $regStart = $semester['registration_start'];
        $regEnd = $semester['registration_end'];

        // Determine registration status
        $registrationStatus = 'open'; // Default if no dates set
        $message = 'Registration is open';
        $daysRemaining = null;
        $opensIn = null;

        if ($regStart && $regEnd) {
            if ($today < $regStart) {
                $registrationStatus = 'not_started';
                $openDate = new DateTime($regStart);
                $todayDate = new DateTime($today);
                $opensIn = $todayDate->diff($openDate)->days;
                $message = 'Registration opens on ' . date('F j, Y', strtotime($regStart));
            } elseif ($today > $regEnd) {
                $registrationStatus = 'closed';
                $message = 'Registration closed on ' . date('F j, Y', strtotime($regEnd));
            } else {
                $registrationStatus = 'open';
                $endDate = new DateTime($regEnd);
                $todayDate = new DateTime($today);
                $daysRemaining = $todayDate->diff($endDate)->days;
                $message = 'Registration is open until ' . date('F j, Y', strtotime($regEnd));
            }
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'status' => $registrationStatus,
                'message' => $message,
                'registration_start' => $regStart,
                'registration_end' => $regEnd,
                'days_remaining' => $daysRemaining,
                'opens_in_days' => $opensIn,
                'semester' => [
                    'id' => (int)$semester['id'],
                    'number' => (int)$semester['semester_number'],
                    'name' => $semester['semester_name'] ?: "Semester " . $semester['semester_number'],
                    'academic_year' => $semester['academic_year'],
                    'program' => $semester['program_name']
                ]
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        $logger->error('Registration status error', ['error' => $e->getMessage()]);
        echo json_encode(['success' => false, 'message' => 'Error checking registration status: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

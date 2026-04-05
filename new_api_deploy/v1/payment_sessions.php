<?php
// payment_sessions.php - Payment Session Management
// Handles starting, stopping, and retrieving payment sessions

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/utils/jwt.php';
require_once __DIR__ . '/utils/response.php';
require_once __DIR__ . '/utils/logger.php';

$logger = new Logger();
$start = microtime(true);

try {
    $pdo = getDBConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    // ==================== GET: Retrieve Sessions ====================
    if ($method === 'GET') {
        $user = authenticate('admin');
        $program = $_GET['program'] ?? null;

        if ($program) {
            // Get sessions for specific program
            $stmt = $pdo->prepare("
                SELECT 
                    ps.*,
                    a.name as started_by_name,
                    a2.name as stopped_by_name,
                    COUNT(p.id) as payment_count,
                    COALESCE(SUM(CASE WHEN p.payment_status = 'paid' THEN p.amount ELSE 0 END), 0) as total_collected
                FROM payment_sessions ps
                LEFT JOIN admins a ON ps.started_by = a.id
                LEFT JOIN admins a2 ON ps.stopped_by = a2.id
                LEFT JOIN payments p ON ps.id = p.session_id
                WHERE ps.program = ?
                GROUP BY ps.id
                ORDER BY ps.started_at DESC
            ");
            $stmt->execute([$program]);
        } else {
            // Get all sessions
            $stmt = $pdo->prepare("
                SELECT 
                    ps.*,
                    a.name as started_by_name,
                    a2.name as stopped_by_name,
                    COUNT(p.id) as payment_count,
                    COALESCE(SUM(CASE WHEN p.payment_status = 'paid' THEN p.amount ELSE 0 END), 0) as total_collected
                FROM payment_sessions ps
                LEFT JOIN admins a ON ps.started_by = a.id
                LEFT JOIN admins a2 ON ps.stopped_by = a2.id
                LEFT JOIN payments p ON ps.id = p.session_id
                GROUP BY ps.id
                ORDER BY ps.started_at DESC
            ");
            $stmt->execute();
        }

        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format sessions
        foreach ($sessions as &$session) {
            $session['is_active'] = (bool)$session['is_active'];
            $session['total_fee'] = (float)$session['total_fee'];
            $session['payment_count'] = (int)$session['payment_count'];
            $session['total_collected'] = (float)$session['total_collected'];
            
            // Calculate duration if stopped
            if ($session['stopped_at']) {
                $start_time = strtotime($session['started_at']);
                $stop_time = strtotime($session['stopped_at']);
                $duration_seconds = $stop_time - $start_time;
                $session['duration_hours'] = round($duration_seconds / 3600, 2);
            } else {
                $session['duration_hours'] = null;
            }
        }

        $logger->info('Payment sessions GET completed', ['program' => $program, 'count' => count($sessions)]);
        jsonResponse("success", "Sessions retrieved successfully.", ['sessions' => $sessions], 200);
    }

    // ==================== POST: Start/Stop Session ====================
    elseif ($method === 'POST') {
        $user = authenticate('admin');
        $data = json_decode(file_get_contents('php://input'), true);
        
        $action = $data['action'] ?? null;
        $program = $data['program'] ?? null;

        if (!$action || !$program) {
            jsonResponse("error", "Missing required fields: action and program.", [], 400);
        }

        // ========== START SESSION ==========
        if ($action === 'start') {
            $totalFee = filter_var($data['total_fee'] ?? 0, FILTER_VALIDATE_FLOAT);
            
            if (!$totalFee || $totalFee <= 0) {
                jsonResponse("error", "Invalid total_fee. Must be a positive number.", [], 400);
            }

            $pdo->beginTransaction();

            try {
                // Check if there's already an active session for this program
                $stmt = $pdo->prepare("
                    SELECT id FROM payment_sessions 
                    WHERE program = ? AND is_active = 1
                ");
                $stmt->execute([$program]);
                
                if ($stmt->fetch()) {
                    $pdo->rollBack();
                    jsonResponse("error", "There is already an active payment session for this program. Please stop it first.", [], 400);
                }

                // Get the next session number for this program
                $stmt = $pdo->prepare("
                    SELECT COALESCE(MAX(session_number), 0) + 1 as next_session
                    FROM payment_sessions
                    WHERE program = ?
                ");
                $stmt->execute([$program]);
                $nextSession = $stmt->fetchColumn();

                // Create new session
                $stmt = $pdo->prepare("
                    INSERT INTO payment_sessions 
                    (program, session_number, total_fee, started_at, started_by, is_active)
                    VALUES (?, ?, ?, NOW(), ?, 1)
                ");
                $stmt->execute([$program, $nextSession, $totalFee, $user->id]);
                $sessionId = $pdo->lastInsertId();

                // Update fee_settings to mark as live
                $stmt = $pdo->prepare("
                    UPDATE fee_settings 
                    SET is_live = 1, updated_at = NOW(), updated_by = ?
                    WHERE program = ?
                    ORDER BY updated_at DESC
                    LIMIT 1
                ");
                $stmt->execute([$user->id, $program]);

                $pdo->commit();

                $logger->info('Payment session started', [
                    'session_id' => $sessionId,
                    'program' => $program,
                    'session_number' => $nextSession,
                    'total_fee' => $totalFee,
                    'admin' => $user->id
                ]);

                jsonResponse("success", "Payment session started successfully.", [
                    'session' => [
                        'id' => $sessionId,
                        'session_number' => $nextSession,
                        'program' => $program,
                        'total_fee' => $totalFee,
                        'started_at' => date('Y-m-d H:i:s'),
                        'is_active' => true
                    ]
                ], 201);

            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // ========== STOP SESSION ==========
        elseif ($action === 'stop') {
            $pdo->beginTransaction();

            try {
                // Get active session for this program
                $stmt = $pdo->prepare("
                    SELECT id FROM payment_sessions 
                    WHERE program = ? AND is_active = 1
                ");
                $stmt->execute([$program]);
                $session = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$session) {
                    $pdo->rollBack();
                    jsonResponse("error", "No active payment session found for this program.", [], 404);
                }

                $sessionId = $session['id'];

                // Stop the session
                $stmt = $pdo->prepare("
                    UPDATE payment_sessions 
                    SET stopped_at = NOW(), stopped_by = ?, is_active = 0
                    WHERE id = ?
                ");
                $stmt->execute([$user->id, $sessionId]);

                // Update fee_settings to mark as not live
                $stmt = $pdo->prepare("
                    UPDATE fee_settings 
                    SET is_live = 0, updated_at = NOW(), updated_by = ?
                    WHERE program = ?
                    ORDER BY updated_at DESC
                    LIMIT 1
                ");
                $stmt->execute([$user->id, $program]);

                // Get session statistics
                $stmt = $pdo->prepare("
                    SELECT 
                        ps.started_at,
                        ps.stopped_at,
                        COUNT(p.id) as payment_count,
                        COALESCE(SUM(CASE WHEN p.payment_status = 'paid' THEN p.amount ELSE 0 END), 0) as total_collected,
                        TIMESTAMPDIFF(SECOND, ps.started_at, ps.stopped_at) as duration_seconds
                    FROM payment_sessions ps
                    LEFT JOIN payments p ON ps.id = p.session_id
                    WHERE ps.id = ?
                    GROUP BY ps.id
                ");
                $stmt->execute([$sessionId]);
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);

                $pdo->commit();

                $logger->info('Payment session stopped', [
                    'session_id' => $sessionId,
                    'program' => $program,
                    'payment_count' => $stats['payment_count'],
                    'total_collected' => $stats['total_collected'],
                    'admin' => $user->id
                ]);

                jsonResponse("success", "Payment session stopped successfully.", [
                    'session' => [
                        'id' => $sessionId,
                        'stopped_at' => $stats['stopped_at'],
                        'duration_hours' => round($stats['duration_seconds'] / 3600, 2),
                        'payment_count' => (int)$stats['payment_count'],
                        'total_collected' => (float)$stats['total_collected']
                    ]
                ], 200);

            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        else {
            jsonResponse("error", "Invalid action. Use 'start' or 'stop'.", [], 400);
        }
    }

    else {
        jsonResponse("error", "Method not allowed.", [], 405);
    }

} catch (Exception $e) {
    $logger->error('Payment sessions error', ['error' => $e->getMessage()]);
    jsonResponse("error", "Internal server error: " . $e->getMessage(), [], 500);
}

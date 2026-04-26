<?php
/**
 * Traffic Analytics with Real Data
 * Queries traffic_logs table for real-time analytics
 * 
 * Endpoints:
 * GET /traffic?action=overview&time_range=24h - Get traffic overview
 */

header('Content-Type: application/json');
require_once __DIR__ . '/middleware.php';

$logger = getLogger('system_admin_traffic');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

// Handle CORS
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require system admin authentication
$admin = requireSystemAdmin();

$action = $_GET['action'] ?? 'overview';
$timeRange = $_GET['time_range'] ?? '24h';

try {
    switch ($action) {
        case 'overview':
            handleTrafficOverview($pdo, $logger, $admin, $timeRange);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    $logger->error('Traffic analytics error', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to fetch traffic data',
        'error' => $e->getMessage()
    ]);
}

/**
 * Handle Traffic Overview with Real Data
 */
function handleTrafficOverview($pdo, $logger, $admin, $timeRange) {
    $hours = getHoursFromTimeRange($timeRange);
    
    try {
        // Check if traffic_logs table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'traffic_logs'");
        if ($stmt->rowCount() === 0) {
            // Table doesn't exist yet
            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'summary' => [
                        'total_requests' => 0,
                        'unique_users' => 0,
                        'avg_session_duration' => 0,
                        'bounce_rate' => 0,
                        'peak_hour' => 'N/A'
                    ],
                    'by_endpoint' => [],
                    'by_user_type' => [],
                    'by_hour' => [],
                    'top_users' => [],
                    'message' => 'No traffic data available yet. Start using the system to collect data.'
                ]
            ]);
            return;
        }
        
        // Get summary statistics
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_requests,
                COUNT(DISTINCT user_id) as unique_users,
                COUNT(DISTINCT session_id) as unique_sessions,
                AVG(session_duration) as avg_session_duration
            FROM traffic_logs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$hours]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get peak hour
        $stmt = $pdo->prepare("
            SELECT 
                HOUR(created_at) as hour,
                COUNT(*) as requests
            FROM traffic_logs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            GROUP BY HOUR(created_at)
            ORDER BY requests DESC
            LIMIT 1
        ");
        $stmt->execute([$hours]);
        $peakHour = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get traffic by endpoint
        $stmt = $pdo->prepare("
            SELECT 
                endpoint,
                COUNT(*) as requests,
                COUNT(DISTINCT user_id) as unique_users,
                (COUNT(*) / (SELECT COUNT(*) FROM traffic_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)) * 100) as percentage
            FROM traffic_logs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            GROUP BY endpoint
            ORDER BY requests DESC
            LIMIT 10
        ");
        $stmt->execute([$hours, $hours]);
        $byEndpoint = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get traffic by user type
        $stmt = $pdo->prepare("
            SELECT 
                user_type,
                COUNT(*) as requests,
                COUNT(DISTINCT user_id) as users,
                (COUNT(*) / (SELECT COUNT(*) FROM traffic_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)) * 100) as percentage
            FROM traffic_logs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            GROUP BY user_type
            ORDER BY requests DESC
        ");
        $stmt->execute([$hours, $hours]);
        $byUserType = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get hourly traffic pattern
        $stmt = $pdo->prepare("
            SELECT 
                CONCAT(LPAD(HOUR(created_at), 2, '0'), ':00') as hour,
                COUNT(*) as requests,
                COUNT(DISTINCT user_id) as users
            FROM traffic_logs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            GROUP BY HOUR(created_at)
            ORDER BY HOUR(created_at)
        ");
        $stmt->execute([$hours]);
        $byHour = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get top active users
        $stmt = $pdo->prepare("
            SELECT 
                user_id,
                user_email,
                user_name,
                COUNT(*) as requests,
                MAX(created_at) as last_active
            FROM traffic_logs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                AND user_id IS NOT NULL
            GROUP BY user_id, user_email, user_name
            ORDER BY requests DESC
            LIMIT 10
        ");
        $stmt->execute([$hours]);
        $topUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format data
        foreach ($byEndpoint as &$item) {
            $item['percentage'] = round($item['percentage'], 2);
        }
        
        foreach ($byUserType as &$item) {
            $item['percentage'] = round($item['percentage'], 2);
        }
        
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'data' => [
                'summary' => [
                    'total_requests' => (int)$summary['total_requests'],
                    'unique_users' => (int)$summary['unique_users'],
                    'avg_session_duration' => round($summary['avg_session_duration'] ?? 0, 0),
                    'bounce_rate' => 0, // Can be calculated if needed
                    'peak_hour' => $peakHour ? sprintf('%02d:00', $peakHour['hour']) : 'N/A'
                ],
                'by_endpoint' => $byEndpoint,
                'by_user_type' => $byUserType,
                'by_hour' => $byHour,
                'top_users' => $topUsers,
                'time_range' => $timeRange,
                'hours_analyzed' => $hours
            ]
        ], JSON_PRETTY_PRINT);
        
    } catch (PDOException $e) {
        throw $e;
    }
}

/**
 * Convert time range to hours
 */
function getHoursFromTimeRange($timeRange) {
    switch ($timeRange) {
        case '1h':
            return 1;
        case '24h':
            return 24;
        case '7d':
            return 168;
        case '30d':
            return 720;
        default:
            return 24;
    }
}

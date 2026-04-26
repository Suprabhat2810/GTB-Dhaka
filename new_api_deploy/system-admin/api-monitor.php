<?php
/**
 * API Monitoring with Real Metrics
 * Queries api_metrics table for real-time data
 * 
 * Endpoints:
 * GET /api-monitor?action=health&time_range=24h - Get API health status
 */

header('Content-Type: application/json');
require_once __DIR__ . '/middleware.php';

$logger = getLogger('system_admin_api_monitor');
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

$action = $_GET['action'] ?? 'health';
$timeRange = $_GET['time_range'] ?? '24h';

try {
    switch ($action) {
        case 'health':
            handleAPIHealth($pdo, $logger, $admin, $timeRange);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    $logger->error('API monitor error', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to fetch API metrics',
        'error' => $e->getMessage()
    ]);
}

/**
 * Handle API Health with Real Metrics
 */
function handleAPIHealth($pdo, $logger, $admin, $timeRange) {
    $hours = getHoursFromTimeRange($timeRange);
    
    try {
        // Check if api_metrics table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'api_metrics'");
        if ($stmt->rowCount() === 0) {
            // Table doesn't exist yet - return empty state
            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'total_requests' => 0,
                    'success_count' => 0,
                    'error_count' => 0,
                    'avg_response_time' => 0,
                    'uptime_percentage' => 100,
                    'status' => 'healthy',
                    'endpoints' => [],
                    'message' => 'No metrics available yet. Start using the API to collect data.'
                ]
            ]);
            return;
        }
        
        // Get overall metrics
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_requests,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as success_count,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as error_count,
                AVG(response_time) as avg_response_time,
                MIN(response_time) as min_response_time,
                MAX(response_time) as max_response_time
            FROM api_metrics
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$hours]);
        $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate uptime percentage
        $uptimePercentage = $metrics['total_requests'] > 0 
            ? ($metrics['success_count'] / $metrics['total_requests']) * 100 
            : 100;
        
        // Determine status
        $status = 'healthy';
        if ($uptimePercentage < 95) $status = 'degraded';
        if ($uptimePercentage < 80) $status = 'down';
        
        // Get endpoint performance
        $stmt = $pdo->prepare("
            SELECT 
                endpoint,
                method,
                COUNT(*) as total_requests,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as success_count,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as error_count,
                AVG(response_time) as avg_response_time,
                MIN(response_time) as min_response_time,
                MAX(response_time) as max_response_time,
                (SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) / COUNT(*) * 100) as success_rate,
                MAX(created_at) as last_accessed
            FROM api_metrics
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            GROUP BY endpoint, method
            ORDER BY total_requests DESC
            LIMIT 50
        ");
        $stmt->execute([$hours]);
        $endpoints = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format endpoint data
        foreach ($endpoints as &$endpoint) {
            $endpoint['avg_response_time'] = round($endpoint['avg_response_time'], 2);
            $endpoint['min_response_time'] = round($endpoint['min_response_time'], 2);
            $endpoint['max_response_time'] = round($endpoint['max_response_time'], 2);
            $endpoint['success_rate'] = round($endpoint['success_rate'], 2);
        }
        
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'data' => [
                'total_requests' => (int)$metrics['total_requests'],
                'success_count' => (int)$metrics['success_count'],
                'error_count' => (int)$metrics['error_count'],
                'avg_response_time' => round($metrics['avg_response_time'] ?? 0, 2),
                'min_response_time' => round($metrics['min_response_time'] ?? 0, 2),
                'max_response_time' => round($metrics['max_response_time'] ?? 0, 2),
                'uptime_percentage' => round($uptimePercentage, 2),
                'status' => $status,
                'endpoints' => $endpoints,
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
            return 168; // 7 * 24
        case '30d':
            return 720; // 30 * 24
        default:
            return 24;
    }
}

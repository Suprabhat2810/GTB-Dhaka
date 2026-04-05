<?php
// new_api_deploy/health.php - Comprehensive System Health Check
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/classes/HealthCheck.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Determine output format
$format = $_GET['format'] ?? 'json';

try {
    // Run health checks
    $healthCheck = new HealthCheck();
    $results = $healthCheck->runAll();

    // Set HTTP status code based on health
    $statusCode = match($results['status']) {
        'healthy' => 200,
        'degraded' => 200,  // Still operational
        'unhealthy' => 503, // Service unavailable
        default => 500,
    };

    http_response_code($statusCode);

    // Output based on format
    if ($format === 'html') {
        renderHtmlOutput($results);
    } else {
        // JSON output (default)
        header('Content-Type: application/json');
        echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Health check failed: ' . $e->getMessage(),
        'timestamp' => date('c'),
    ], JSON_PRETTY_PRINT);
}

/**
 * Render HTML output for browser viewing
 */
function renderHtmlOutput(array $results): void
{
    $status = $results['status'];
    $statusColor = match($status) {
        'healthy' => '#10b981',
        'degraded' => '#f59e0b',
        'unhealthy' => '#ef4444',
        default => '#6b7280',
    };
    $statusIcon = match($status) {
        'healthy' => '✓',
        'degraded' => '⚠',
        'unhealthy' => '✗',
        default => '?',
    };

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Health Check</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f3f4f6;
            padding: 2rem;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header {
            background: <?= $statusColor ?>;
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .header h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: rgba(255,255,255,0.2);
            border-radius: 20px;
            font-size: 1.2rem;
            font-weight: 600;
        }
        .content {
            padding: 2rem;
        }
        .check-section {
            margin-bottom: 2rem;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
        }
        .check-header {
            background: #f9fafb;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .check-title {
            font-weight: 600;
            font-size: 1.1rem;
            text-transform: capitalize;
        }
        .check-status {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .status-pass { background: #d1fae5; color: #065f46; }
        .status-warn { background: #fef3c7; color: #92400e; }
        .status-fail { background: #fee2e2; color: #991b1b; }
        .check-body {
            padding: 1.5rem;
        }
        .detail-row {
            display: flex;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .detail-row:last-child { border-bottom: none; }
        .detail-label {
            flex: 0 0 200px;
            font-weight: 500;
            color: #6b7280;
        }
        .detail-value {
            flex: 1;
            color: #111827;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
        }
        .alerts {
            margin-top: 2rem;
        }
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }
        .alert-warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            color: #92400e;
        }
        .alert-error {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            color: #991b1b;
        }
        .footer {
            text-align: center;
            padding: 1rem;
            color: #6b7280;
            font-size: 0.875rem;
            border-top: 1px solid #e5e7eb;
        }
        .json-link {
            display: inline-block;
            margin-top: 1rem;
            padding: 0.5rem 1rem;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.875rem;
        }
        .json-link:hover { background: #2563eb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?= $statusIcon ?> System Health Check</h1>
            <div class="status-badge"><?= strtoupper($status) ?></div>
            <p style="margin-top: 1rem; opacity: 0.9;"><?= $results['timestamp'] ?></p>
        </div>

        <div class="content">
            <?php foreach ($results['checks'] as $checkName => $check): ?>
                <div class="check-section">
                    <div class="check-header">
                        <div class="check-title"><?= htmlspecialchars($checkName) ?></div>
                        <div class="check-status status-<?= $check['status'] ?>">
                            <?= strtoupper($check['status']) ?>
                        </div>
                    </div>
                    <div class="check-body">
                        <p style="margin-bottom: 1rem; color: #374151;">
                            <strong>Message:</strong> <?= htmlspecialchars($check['message']) ?>
                        </p>
                        <?php if (!empty($check['details'])): ?>
                            <div style="margin-top: 1rem;">
                                <strong style="color: #6b7280;">Details:</strong>
                                <?php foreach ($check['details'] as $key => $value): ?>
                                    <div class="detail-row">
                                        <div class="detail-label"><?= htmlspecialchars($key) ?>:</div>
                                        <div class="detail-value">
                                            <?php 
                                            if (is_array($value)) {
                                                echo htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT));
                                            } elseif (is_bool($value)) {
                                                echo $value ? '✓ true' : '✗ false';
                                            } else {
                                                echo htmlspecialchars((string)$value);
                                            }
                                            ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (!empty($results['warnings']) || !empty($results['errors'])): ?>
                <div class="alerts">
                    <?php foreach ($results['warnings'] as $warning): ?>
                        <div class="alert alert-warning">
                            <strong>⚠ Warning:</strong> <?= htmlspecialchars($warning) ?>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach ($results['errors'] as $error): ?>
                        <div class="alert alert-error">
                            <strong>✗ Error:</strong> <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div style="text-align: center;">
                <a href="?format=json" class="json-link">View JSON Output</a>
            </div>
        </div>

        <div class="footer">
            Environment: <strong><?= htmlspecialchars($results['environment']) ?></strong> |
            Last checked: <?= date('Y-m-d H:i:s') ?>
        </div>
    </div>
</body>
</html>
    <?php
}

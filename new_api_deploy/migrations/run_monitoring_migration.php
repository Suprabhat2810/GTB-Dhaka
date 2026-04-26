<?php
/**
 * Run Monitoring Tables Migration
 * Execute this file once to create all monitoring tables
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

try {
    $pdo = getPDO();
    
    // Read SQL file
    $sqlFile = __DIR__ . '/create_monitoring_tables.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("Migration file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Split by delimiter to handle stored procedures
    $statements = [];
    $currentStatement = '';
    $inDelimiter = false;
    
    foreach (explode("\n", $sql) as $line) {
        $line = trim($line);
        
        // Skip comments and empty lines
        if (empty($line) || strpos($line, '--') === 0) {
            continue;
        }
        
        // Check for DELIMITER change
        if (strpos($line, 'DELIMITER') === 0) {
            if ($currentStatement) {
                $statements[] = $currentStatement;
                $currentStatement = '';
            }
            $inDelimiter = !$inDelimiter;
            continue;
        }
        
        $currentStatement .= $line . "\n";
        
        // If not in delimiter block and line ends with ;, it's a complete statement
        if (!$inDelimiter && substr(rtrim($line), -1) === ';') {
            $statements[] = $currentStatement;
            $currentStatement = '';
        }
        
        // If in delimiter block and line ends with $$, it's a complete statement
        if ($inDelimiter && substr(rtrim($line), -2) === '$$') {
            $statements[] = $currentStatement;
            $currentStatement = '';
        }
    }
    
    // Add last statement if any
    if ($currentStatement) {
        $statements[] = $currentStatement;
    }
    
    // Execute each statement
    $executed = 0;
    $errors = [];
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement)) continue;
        
        try {
            $pdo->exec($statement);
            $executed++;
        } catch (PDOException $e) {
            $errors[] = [
                'statement' => substr($statement, 0, 100) . '...',
                'error' => $e->getMessage()
            ];
        }
    }
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Monitoring tables migration completed',
        'statements_executed' => $executed,
        'errors' => $errors,
        'tables_created' => [
            'api_metrics',
            'traffic_logs',
            'system_health_logs',
            'performance_metrics'
        ],
        'procedures_created' => [
            'sp_cleanup_old_metrics',
            'sp_calculate_api_metrics',
            'sp_get_endpoint_performance'
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}

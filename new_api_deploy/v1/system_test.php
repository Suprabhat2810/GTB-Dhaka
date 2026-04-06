<?php
/**
 * COMPREHENSIVE SYSTEM TEST ENDPOINT
 * Tests database tables, endpoints, response structures, and data integrity
 * Access: /v1/system_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$logger = getLogger('system_test');
$pdo = getPDO();

// Output format: json or html
$format = $_GET['format'] ?? 'json';

$results = [
    'timestamp' => date('Y-m-d H:i:s'),
    'summary' => [
        'total_tests' => 0,
        'passed' => 0,
        'warnings' => 0,
        'failed' => 0,
        'overall_status' => 'healthy'
    ],
    'tests' => [],
    'issues_found' => [],
    'recommendations' => []
];

// ============================================================
// TEST 1: Database Tables Existence
// ============================================================
function testDatabaseTables($pdo): array {
    $requiredTables = [
        'students', 'admins', 'programs', 'subjects', 'student_subjects',
        'documents', 'payments', 'notifications', 'approvals',
        'academic_calendar', 'semester_transitions', 'semester_settings',
        'fee_settings', 'student_fee_assignments', 'fee_change_history',
        'allocation_logs', 'personal_info', 'payment_sessions', 'optional_subjects'
    ];
    
    $result = [
        'test_name' => 'Database Tables',
        'status' => 'pass',
        'total_tables_required' => count($requiredTables),
        'tables_found' => 0,
        'missing_tables' => [],
        'all_tables' => []
    ];
    
    foreach ($requiredTables as $table) {
        // Escape table name for SHOW TABLES LIKE
        $escapedTable = str_replace("'", "''", $table);
        $stmt = $pdo->query("SHOW TABLES LIKE '{$escapedTable}'");
        $exists = $stmt && $stmt->fetch() !== false;
        
        if ($exists) {
            $result['tables_found']++;
            $result['all_tables'][$table] = 'exists';
        } else {
            $result['missing_tables'][] = $table;
            $result['all_tables'][$table] = 'MISSING';
            $result['status'] = 'fail';
        }
    }
    
    return $result;
}

// ============================================================
// TEST 2: Foreign Key Constraints
// ============================================================
function testForeignKeys($pdo): array {
    $result = [
        'test_name' => 'Foreign Key Constraints',
        'status' => 'pass',
        'total_constraints' => 0,
        'constraints_with_cascade' => 0,
        'constraints_without_cascade' => 0,
        'issues' => [],
        'all_constraints' => []
    ];
    
    $stmt = $pdo->query("
        SELECT 
            CONSTRAINT_NAME,
            TABLE_NAME,
            REFERENCED_TABLE_NAME,
            DELETE_RULE,
            UPDATE_RULE
        FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
        ORDER BY TABLE_NAME
    ");
    
    $constraints = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result['total_constraints'] = count($constraints);
    
    foreach ($constraints as $constraint) {
        $key = "{$constraint['TABLE_NAME']}.{$constraint['CONSTRAINT_NAME']}";
        $result['all_constraints'][$key] = [
            'table' => $constraint['TABLE_NAME'],
            'references' => $constraint['REFERENCED_TABLE_NAME'],
            'on_delete' => $constraint['DELETE_RULE'],
            'on_update' => $constraint['UPDATE_RULE']
        ];
        
        if ($constraint['DELETE_RULE'] === 'CASCADE') {
            $result['constraints_with_cascade']++;
        } else {
            $result['constraints_without_cascade']++;
        }
        
        // Check for missing CASCADE on critical tables
        if ($constraint['TABLE_NAME'] === 'student_subjects' && 
            $constraint['DELETE_RULE'] !== 'CASCADE') {
            $issue = "Missing CASCADE on {$key} (currently: {$constraint['DELETE_RULE']})";
            $result['issues'][] = $issue;
            $result['status'] = 'warning';
        }
    }
    
    return $result;
}

// ============================================================
// TEST 3: Stored Procedures and Triggers
// ============================================================
function testProceduresAndTriggers($pdo): array {
    $expectedProcedures = ['ApplyNewFeeStructure', 'BulkPromoteStudents'];
    $expectedTriggers = ['trg_auto_assign_fee_to_student', 'trg_log_semester_change'];
    $expectedViews = ['v_student_fee_summary', 'v_semester_stats'];
    
    $result = [
        'test_name' => 'Procedures and Triggers',
        'status' => 'pass',
        'procedures_found' => 0,
        'procedures_expected' => count($expectedProcedures),
        'triggers_found' => 0,
        'triggers_expected' => count($expectedTriggers),
        'views_found' => 0,
        'views_expected' => count($expectedViews),
        'missing_procedures' => [],
        'missing_triggers' => [],
        'missing_views' => [],
        'all_procedures' => [],
        'all_triggers' => [],
        'all_views' => []
    ];
    
    // Check procedures
    $stmt = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = DATABASE()");
    $procedures = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($procedures as $proc) {
        $result['all_procedures'][] = $proc['Name'];
    }
    $result['procedures_found'] = count($result['all_procedures']);
    $result['missing_procedures'] = array_diff($expectedProcedures, $result['all_procedures']);
    
    // Check triggers
    $stmt = $pdo->query("SHOW TRIGGERS");
    $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($triggers as $trigger) {
        $result['all_triggers'][] = $trigger['Trigger'];
    }
    $result['triggers_found'] = count($result['all_triggers']);
    $result['missing_triggers'] = array_diff($expectedTriggers, $result['all_triggers']);
    
    // Check views
    $stmt = $pdo->query("
        SELECT TABLE_NAME 
        FROM INFORMATION_SCHEMA.VIEWS 
        WHERE TABLE_SCHEMA = DATABASE()
    ");
    $views = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $result['all_views'] = $views;
    $result['views_found'] = count($views);
    $result['missing_views'] = array_diff($expectedViews, $views);
    
    if (!empty($result['missing_procedures']) || !empty($result['missing_triggers']) || !empty($result['missing_views'])) {
        $result['status'] = 'warning';
    }
    
    return $result;
}

// ============================================================
// TEST 4: Indexes
// ============================================================
function testIndexes($pdo): array {
    $result = [
        'test_name' => 'Database Indexes',
        'status' => 'pass',
        'total_indexes_checked' => 0,
        'indexes_found' => 0,
        'missing_indexes' => [],
        'table_index_counts' => []
    ];
    
    $criticalIndexes = [
        'students' => ['idx_students_program', 'idx_students_semester'],
        'student_subjects' => ['idx_student_subjects_student_fk', 'idx_student_subjects_subject_fk'],
        'notifications' => ['idx_notifications_student_id'],
        'documents' => ['idx_documents_student_id'],
        'payments' => ['idx_payments_student_id']
    ];
    
    foreach ($criticalIndexes as $table => $indexes) {
        $result['total_indexes_checked'] += count($indexes);
        
        try {
            $stmt = $pdo->query("SHOW INDEX FROM `{$table}`");
            $existingIndexes = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN, 2) : []; // Key_name column
        } catch (PDOException $e) {
            $existingIndexes = [];
        }
        
        $result['table_index_counts'][$table] = [
            'total_indexes' => count($existingIndexes),
            'critical_indexes_expected' => count($indexes),
            'critical_indexes_found' => 0,
            'all_indexes' => $existingIndexes
        ];
        
        foreach ($indexes as $index) {
            if (in_array($index, $existingIndexes)) {
                $result['indexes_found']++;
                $result['table_index_counts'][$table]['critical_indexes_found']++;
            } else {
                $result['missing_indexes'][] = "{$table}.{$index}";
                $result['status'] = 'warning';
            }
        }
    }
    
    return $result;
}

// ============================================================
// TEST 5: API Endpoints
// ============================================================
function testEndpoints(): array {
    $result = [
        'name' => 'API Endpoints',
        'status' => 'pass',
        'endpoints' => [],
        'failed' => []
    ];
    
    $baseUrl = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
    $baseUrl = str_replace('/system_test.php', '', $baseUrl);
    
    $endpoints = [
        'health.php' => 'GET',
        'programs.php' => 'GET',
        'semester.php' => 'GET',
        'registration_status.php' => 'GET'
    ];
    
    foreach ($endpoints as $endpoint => $method) {
        $url = $baseUrl . '/' . $endpoint;
        
        // Use file_get_contents for simple GET requests
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => 'Accept: application/json',
                'timeout' => 5,
                'ignore_errors' => true
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        $httpCode = 200;
        
        if (isset($http_response_header)) {
            preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $matches);
            $httpCode = (int)($matches[1] ?? 0);
        }
        
        $status = ($httpCode >= 200 && $httpCode < 500) ? 'reachable' : 'unreachable';
        
        $result['endpoints'][$endpoint] = [
            'method' => $method,
            'status' => $status,
            'http_code' => $httpCode
        ];
        
        if ($status === 'unreachable') {
            $result['failed'][] = $endpoint;
            $result['status'] = 'warning';
        }
    }
    
    return $result;
}

// ============================================================
// TEST 6: Data Integrity
// ============================================================
function testDataIntegrity($pdo): array {
    $result = [
        'test_name' => 'Data Integrity',
        'status' => 'pass',
        'total_checks' => 0,
        'checks_passed' => 0,
        'checks_failed' => 0,
        'total_issues_found' => 0,
        'integrity_checks' => [],
        'issues' => []
    ];
    
    // Check for orphaned records
    $checks = [
        'orphaned_student_subjects' => [
            'description' => 'Student subjects without valid student',
            'query' => "SELECT COUNT(*) FROM student_subjects ss LEFT JOIN students s ON ss.student_id = s.id WHERE s.id IS NULL"
        ],
        'orphaned_documents' => [
            'description' => 'Documents without valid student',
            'query' => "SELECT COUNT(*) FROM documents d LEFT JOIN students s ON d.student_id = s.id WHERE s.id IS NULL"
        ],
        'orphaned_payments' => [
            'description' => 'Payments without valid student',
            'query' => "SELECT COUNT(*) FROM payments p LEFT JOIN students s ON p.student_id = s.id WHERE s.id IS NULL"
        ],
        'duplicate_subject_codes' => [
            'description' => 'Duplicate subject codes',
            'query' => "SELECT COUNT(*) FROM (SELECT subject_code FROM subjects WHERE subject_code IS NOT NULL GROUP BY subject_code HAVING COUNT(*) > 1) AS dupes"
        ],
        'students_without_program' => [
            'description' => 'Students without valid program',
            'query' => "SELECT COUNT(*) FROM students WHERE program IS NULL OR program = ''"
        ]
    ];
    
    $result['total_checks'] = count($checks);
    
    foreach ($checks as $checkName => $checkData) {
        $stmt = $pdo->query($checkData['query']);
        $count = (int)$stmt->fetchColumn();
        
        $result['integrity_checks'][$checkName] = [
            'description' => $checkData['description'],
            'count' => $count,
            'status' => $count > 0 ? 'FAIL' : 'PASS'
        ];
        
        if ($count > 0) {
            $result['checks_failed']++;
            $result['total_issues_found'] += $count;
            $result['issues'][] = "{$checkData['description']}: {$count} records";
            $result['status'] = 'warning';
        } else {
            $result['checks_passed']++;
        }
    }
    
    return $result;
}

// ============================================================
// TEST 7: Response Structure Validation
// ============================================================
function testResponseStructures($pdo): array {
    $result = [
        'test_name' => 'Table Schema Validation',
        'status' => 'pass',
        'tables_checked' => 0,
        'tables_valid' => 0,
        'tables_invalid' => 0,
        'schema_validations' => []
    ];
    
    // Test that critical tables return expected columns
    $tableColumns = [
        'students' => ['id', 'name', 'email', 'program', 'semester', 'final_registration_number'],
        'subjects' => ['id', 'subject_name', 'subject_code', 'department', 'semester'],
        'documents' => ['id', 'student_id', 'document_path', 'document_type', 'file_type'],
        'payments' => ['id', 'student_id', 'amount', 'payment_date', 'payment_status']
    ];
    
    $result['tables_checked'] = count($tableColumns);
    
    foreach ($tableColumns as $table => $expectedColumns) {
        try {
            $stmt = $pdo->query("DESCRIBE `{$table}`");
            $actualColumns = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        } catch (PDOException $e) {
            $actualColumns = [];
        }
        
        $missing = array_diff($expectedColumns, $actualColumns);
        $extra = array_diff($actualColumns, $expectedColumns);
        
        if (empty($missing)) {
            $result['tables_valid']++;
            $result['schema_validations'][$table] = [
                'status' => 'VALID',
                'expected_columns' => count($expectedColumns),
                'actual_columns' => count($actualColumns),
                'missing_columns' => [],
                'all_columns' => $actualColumns
            ];
        } else {
            $result['tables_invalid']++;
            $result['schema_validations'][$table] = [
                'status' => 'INVALID',
                'expected_columns' => count($expectedColumns),
                'actual_columns' => count($actualColumns),
                'missing_columns' => array_values($missing),
                'all_columns' => $actualColumns
            ];
            $result['status'] = 'fail';
        }
    }
    
    return $result;
}

// ============================================================
// TEST 8: Environment Configuration
// ============================================================
function testEnvironment(): array {
    $result = [
        'test_name' => 'Environment Configuration',
        'status' => 'pass',
        'total_vars_checked' => 0,
        'vars_set' => 0,
        'vars_missing' => 0,
        'security_issues' => [],
        'environment_variables' => []
    ];
    
    $requiredEnvVars = [
        'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
        'JWT_SECRET', 'APP_ENV', 'STORAGE_DRIVER'
    ];
    
    $result['total_vars_checked'] = count($requiredEnvVars);
    
    foreach ($requiredEnvVars as $var) {
        $value = $_ENV[$var] ?? getenv($var);
        $isSet = !empty($value);
        
        $result['environment_variables'][$var] = [
            'status' => $isSet ? 'SET' : 'MISSING',
            'value_length' => $isSet ? strlen($value) : 0
        ];
        
        if ($isSet) {
            $result['vars_set']++;
        } else {
            $result['vars_missing']++;
            $result['status'] = 'fail';
        }
    }
    
    // Check JWT secret strength
    $jwtSecret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET');
    if ($jwtSecret === 'your-secret-key-here' || strlen($jwtSecret) < 32) {
        $result['security_issues'][] = 'JWT_SECRET is weak (length: ' . strlen($jwtSecret) . ', minimum: 32)';
        $result['environment_variables']['JWT_SECRET']['strength'] = 'WEAK';
        $result['status'] = 'warning';
    } else {
        $result['environment_variables']['JWT_SECRET']['strength'] = 'STRONG';
    }
    
    return $result;
}

// ============================================================
// Run All Tests
// ============================================================
try {
    $results['tests']['database_tables'] = testDatabaseTables($pdo);
    $results['tests']['foreign_keys'] = testForeignKeys($pdo);
    $results['tests']['procedures_triggers'] = testProceduresAndTriggers($pdo);
    $results['tests']['indexes'] = testIndexes($pdo);
    $results['tests']['endpoints'] = testEndpoints();
    $results['tests']['data_integrity'] = testDataIntegrity($pdo);
    $results['tests']['response_structures'] = testResponseStructures($pdo);
    $results['tests']['environment'] = testEnvironment();
    
    // Calculate summary statistics
    $results['summary']['total_tests'] = count($results['tests']);
    foreach ($results['tests'] as $test) {
        if ($test['status'] === 'pass') {
            $results['summary']['passed']++;
        } elseif ($test['status'] === 'warning') {
            $results['summary']['warnings']++;
        } elseif ($test['status'] === 'fail') {
            $results['summary']['failed']++;
        }
    }
    
    // Collect all issues found
    foreach ($results['tests'] as $testName => $test) {
        // Collect issues from various test result structures
        if (!empty($test['missing_tables'])) {
            foreach ($test['missing_tables'] as $table) {
                $results['issues_found'][] = "Missing database table: {$table}";
            }
        }
        if (!empty($test['issues'])) {
            $results['issues_found'] = array_merge($results['issues_found'], $test['issues']);
        }
        if (!empty($test['missing_indexes'])) {
            foreach ($test['missing_indexes'] as $index) {
                $results['issues_found'][] = "Missing index: {$index}";
            }
        }
        if (!empty($test['missing_procedures'])) {
            foreach ($test['missing_procedures'] as $proc) {
                $results['issues_found'][] = "Missing stored procedure: {$proc}";
            }
        }
        if (!empty($test['missing_triggers'])) {
            foreach ($test['missing_triggers'] as $trigger) {
                $results['issues_found'][] = "Missing trigger: {$trigger}";
            }
        }
        if (!empty($test['missing_views'])) {
            foreach ($test['missing_views'] as $view) {
                $results['issues_found'][] = "Missing view: {$view}";
            }
        }
        if (!empty($test['security_issues'])) {
            $results['issues_found'] = array_merge($results['issues_found'], $test['security_issues']);
        }
        if (!empty($test['failed'])) {
            foreach ($test['failed'] as $endpoint) {
                $results['issues_found'][] = "Endpoint unreachable: {$endpoint}";
            }
        }
    }
    
    // Generate recommendations based on issues found
    if (!empty($results['issues_found'])) {
        // Check for CASCADE issues
        $hasCascadeIssues = false;
        foreach ($results['issues_found'] as $issue) {
            if (strpos($issue, 'CASCADE') !== false) {
                $hasCascadeIssues = true;
                break;
            }
        }
        if ($hasCascadeIssues) {
            $results['recommendations'][] = "Run migration: v1/migrations/critical_fixes.sql to add CASCADE constraints";
        }
        
        // Check for missing procedures/triggers
        if (!empty($results['tests']['procedures_triggers']['missing_procedures']) || 
            !empty($results['tests']['procedures_triggers']['missing_triggers'])) {
            $results['recommendations'][] = "Run migration: v1/migrations/fix_procedures_triggers.sql to create missing procedures and triggers";
        }
        
        // Check for data integrity issues
        if (!empty($results['tests']['data_integrity']['issues'])) {
            $results['recommendations'][] = "Clean up orphaned records - see data_integrity test for details";
        }
        
        // Check for weak JWT
        if (!empty($results['tests']['environment']['security_issues'])) {
            $results['recommendations'][] = "Update JWT_SECRET in .env file with a strong 32+ character secret";
        }
        
        // Check for missing indexes
        if (!empty($results['tests']['indexes']['missing_indexes'])) {
            $results['recommendations'][] = "Run critical_fixes.sql migration to add missing performance indexes";
        }
    } else {
        $results['recommendations'][] = "All tests passed! System is healthy.";
    }
    
    // Determine overall status
    $statuses = array_column($results['tests'], 'status');
    if (in_array('fail', $statuses)) {
        $results['summary']['overall_status'] = 'unhealthy';
    } elseif (in_array('warning', $statuses)) {
        $results['summary']['overall_status'] = 'degraded';
    } else {
        $results['summary']['overall_status'] = 'healthy';
    }
    
} catch (Exception $e) {
    $results['summary']['overall_status'] = 'unhealthy';
    $results['error'] = $e->getMessage();
    $results['issues_found'][] = "System test execution error: " . $e->getMessage();
    $logger->error('System test failed', ['error' => $e->getMessage()]);
}

// ============================================================
// Output Results
// ============================================================
if ($format === 'html') {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>System Test Results</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
            .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            h1 { color: #333; border-bottom: 3px solid #4CAF50; padding-bottom: 10px; }
            h2 { color: #555; margin-top: 30px; }
            .status { display: inline-block; padding: 5px 15px; border-radius: 4px; font-weight: bold; }
            .status.healthy { background: #4CAF50; color: white; }
            .status.degraded { background: #FF9800; color: white; }
            .status.unhealthy { background: #f44336; color: white; }
            .status.pass { background: #4CAF50; color: white; }
            .status.warning { background: #FF9800; color: white; }
            .status.fail { background: #f44336; color: white; }
            table { width: 100%; border-collapse: collapse; margin: 15px 0; }
            th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
            th { background: #f8f8f8; font-weight: bold; }
            .timestamp { color: #666; font-size: 14px; }
            pre { background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>System Test Results</h1>
            <p class="timestamp">Tested at: <?= $results['timestamp'] ?></p>
            
            <h2>Summary</h2>
            <p>Overall Status: <span class="status <?= $results['summary']['overall_status'] ?>"><?= strtoupper($results['summary']['overall_status']) ?></span></p>
            <table>
                <tr><th>Metric</th><th>Value</th></tr>
                <tr><td>Total Tests</td><td><?= $results['summary']['total_tests'] ?></td></tr>
                <tr><td>Passed</td><td style="color: #4CAF50; font-weight: bold;"><?= $results['summary']['passed'] ?></td></tr>
                <tr><td>Warnings</td><td style="color: #FF9800; font-weight: bold;"><?= $results['summary']['warnings'] ?></td></tr>
                <tr><td>Failed</td><td style="color: #f44336; font-weight: bold;"><?= $results['summary']['failed'] ?></td></tr>
            </table>
            
            <?php if (!empty($results['issues_found'])): ?>
                <h2>Issues Found (<?= count($results['issues_found']) ?>)</h2>
                <ul>
                    <?php foreach ($results['issues_found'] as $issue): ?>
                        <li style="color: #f44336;"><?= htmlspecialchars($issue) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            
            <?php if (!empty($results['recommendations'])): ?>
                <h2>Recommendations</h2>
                <ul>
                    <?php foreach ($results['recommendations'] as $rec): ?>
                        <li style="color: #2196F3;"><?= htmlspecialchars($rec) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            
            <h2>Detailed Test Results</h2>
            <?php foreach ($results['tests'] as $testName => $test): ?>
                <h3><?= htmlspecialchars($test['test_name']) ?> <span class="status <?= $test['status'] ?>"><?= strtoupper($test['status']) ?></span></h3>
                <pre><?= htmlspecialchars(json_encode($test, JSON_PRETTY_PRINT)) ?></pre>
            <?php endforeach; ?>
        </div>
    </body>
    </html>
    <?php
} else {
    header('Content-Type: application/json; charset=utf-8');
    
    // Set HTTP status code based on overall status
    if ($results['summary']['overall_status'] === 'unhealthy') {
        http_response_code(503);
    } elseif ($results['summary']['overall_status'] === 'degraded') {
        http_response_code(200);
    } else {
        http_response_code(200);
    }
    
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

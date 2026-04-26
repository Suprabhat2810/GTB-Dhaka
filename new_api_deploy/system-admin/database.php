<?php
/**
 * Database Management API
 * 
 * Handles database backup, restore, and management operations
 * ONLY accessible by system administrators
 * 
 * Endpoints:
 * GET /database?action=backup - Create database backup
 * POST /database?action=restore - Restore from backup
 * GET /database?action=list_backups - List all backups
 * GET /database?action=stats - Get database statistics
 * POST /database?action=optimize - Optimize database tables
 */

header('Content-Type: application/json');
require_once __DIR__ . '/middleware.php';

$logger = getLogger('system_admin_database');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

// Handle CORS
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Require system admin authentication
$admin = requireSystemAdmin();

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'backup':
            handleBackup($pdo, $logger, $admin);
            break;
            
        case 'restore':
            handleRestore($pdo, $logger, $admin);
            break;
            
        case 'list_backups':
            handleListBackups($pdo, $logger, $admin);
            break;
            
        case 'stats':
            handleStats($pdo, $logger, $admin);
            break;
            
        case 'optimize':
            handleOptimize($pdo, $logger, $admin);
            break;
        
        case 'tables':
            handleGetTables($pdo, $logger, $admin);
            break;
        
        case 'table_structure':
            handleGetTableStructure($pdo, $logger, $admin);
            break;
        
        case 'table_data':
            handleGetTableData($pdo, $logger, $admin);
            break;
        
        case 'table_relationships':
            handleGetTableRelationships($pdo, $logger, $admin);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    $logger->error('Database management error', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Operation failed', 'message' => $e->getMessage()]);
}

/**
 * Handle Database Backup
 */
function handleBackup($pdo, $logger, $admin) {
    try {
        $dbHost = getenv('DB_HOST');
        $dbName = getenv('DB_NAME');
        $dbUser = getenv('DB_USER');
        $dbPass = getenv('DB_PASS');
        $dbPort = getenv('DB_PORT') ?: '3306';
        
        // Create backups directory if not exists
        $backupDir = __DIR__ . '/../../backups';
        if (!file_exists($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        // Generate backup filename
        $timestamp = date('Y-m-d_H-i-s');
        $backupFile = $backupDir . "/db_backup_{$timestamp}.sql";
        
        // Build mysqldump command
        $command = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --password=%s %s > %s 2>&1',
            escapeshellarg($dbHost),
            escapeshellarg($dbPort),
            escapeshellarg($dbUser),
            escapeshellarg($dbPass),
            escapeshellarg($dbName),
            escapeshellarg($backupFile)
        );
        
        // Execute backup
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);
        
        if ($returnVar !== 0) {
            throw new Exception('Backup command failed: ' . implode("\n", $output));
        }
        
        // Check if file was created
        if (!file_exists($backupFile)) {
            throw new Exception('Backup file was not created');
        }
        
        $fileSize = filesize($backupFile);
        
        // Compress backup
        $gzFile = $backupFile . '.gz';
        $fp = gzopen($gzFile, 'w9');
        gzwrite($fp, file_get_contents($backupFile));
        gzclose($fp);
        
        // Remove uncompressed file
        unlink($backupFile);
        
        $compressedSize = filesize($gzFile);
        
        // Log system event
        $audit = new AuditService($pdo, $logger);
        $audit->logSystemEvent(
            'database_backup',
            'info',
            'Database backup created successfully',
            [
                'filename' => basename($gzFile),
                'size' => $fileSize,
                'compressed_size' => $compressedSize,
                'compression_ratio' => round((1 - $compressedSize / $fileSize) * 100, 2) . '%'
            ],
            $admin->user_id
        );
        
        // Log admin action
        logSystemAdminAction($admin, 'database_backup', 'Created database backup');
        
        $logger->info('Database backup created', ['file' => basename($gzFile)]);
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Backup created successfully',
            'backup' => [
                'filename' => basename($gzFile),
                'size' => $fileSize,
                'compressed_size' => $compressedSize,
                'created_at' => date('Y-m-d H:i:s'),
                'path' => $gzFile
            ]
        ]);
        
    } catch (Exception $e) {
        $logger->error('Backup failed', ['error' => $e->getMessage()]);
        http_response_code(500);
        echo json_encode(['error' => 'Backup failed', 'message' => $e->getMessage()]);
    }
}

/**
 * Handle Database Restore
 */
function handleRestore($pdo, $logger, $admin) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $backupFile = $data['backup_file'] ?? '';
        
        if (empty($backupFile)) {
            http_response_code(400);
            echo json_encode(['error' => 'Backup file required']);
            return;
        }
        
        $backupDir = __DIR__ . '/../../backups';
        $fullPath = $backupDir . '/' . basename($backupFile);
        
        if (!file_exists($fullPath)) {
            http_response_code(404);
            echo json_encode(['error' => 'Backup file not found']);
            return;
        }
        
        $dbHost = getenv('DB_HOST');
        $dbName = getenv('DB_NAME');
        $dbUser = getenv('DB_USER');
        $dbPass = getenv('DB_PASS');
        $dbPort = getenv('DB_PORT') ?: '3306';
        
        // Decompress if gzipped
        $sqlFile = $fullPath;
        if (substr($fullPath, -3) === '.gz') {
            $sqlFile = substr($fullPath, 0, -3);
            $fp = gzopen($fullPath, 'r');
            $output = fopen($sqlFile, 'w');
            while (!gzeof($fp)) {
                fwrite($output, gzread($fp, 4096));
            }
            gzclose($fp);
            fclose($output);
        }
        
        // Build mysql command
        $command = sprintf(
            'mysql --host=%s --port=%s --user=%s --password=%s %s < %s 2>&1',
            escapeshellarg($dbHost),
            escapeshellarg($dbPort),
            escapeshellarg($dbUser),
            escapeshellarg($dbPass),
            escapeshellarg($dbName),
            escapeshellarg($sqlFile)
        );
        
        // Execute restore
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);
        
        // Clean up decompressed file
        if ($sqlFile !== $fullPath && file_exists($sqlFile)) {
            unlink($sqlFile);
        }
        
        if ($returnVar !== 0) {
            throw new Exception('Restore command failed: ' . implode("\n", $output));
        }
        
        // Log system event
        $audit = new AuditService($pdo, $logger);
        $audit->logSystemEvent(
            'database_restore',
            'warning',
            'Database restored from backup',
            ['filename' => basename($backupFile)],
            $admin->user_id
        );
        
        // Log admin action
        logSystemAdminAction($admin, 'database_restore', 'Restored database from backup: ' . basename($backupFile));
        
        $logger->warning('Database restored', ['file' => basename($backupFile)]);
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Database restored successfully',
            'backup_file' => basename($backupFile)
        ]);
        
    } catch (Exception $e) {
        $logger->error('Restore failed', ['error' => $e->getMessage()]);
        http_response_code(500);
        echo json_encode(['error' => 'Restore failed', 'message' => $e->getMessage()]);
    }
}

/**
 * Handle List Backups
 */
function handleListBackups($pdo, $logger, $admin) {
    try {
        $backupDir = __DIR__ . '/../../backups';
        
        if (!file_exists($backupDir)) {
            http_response_code(200);
            echo json_encode(['backups' => []]);
            return;
        }
        
        $files = glob($backupDir . '/db_backup_*.sql.gz');
        $backups = [];
        
        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'size' => filesize($file),
                'size_formatted' => formatBytes(filesize($file)),
                'created_at' => date('Y-m-d H:i:s', filemtime($file)),
                'age' => timeAgo(filemtime($file))
            ];
        }
        
        // Sort by creation time (newest first)
        usort($backups, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        http_response_code(200);
        echo json_encode(['backups' => $backups]);
        
    } catch (Exception $e) {
        $logger->error('List backups failed', ['error' => $e->getMessage()]);
        http_response_code(500);
        echo json_encode(['error' => 'Failed to list backups']);
    }
}

/**
 * Handle Database Statistics
 */
function handleStats($pdo, $logger, $admin) {
    try {
        $dbName = getenv('DB_NAME');
        
        // Get table statistics
        $stmt = $pdo->query("
            SELECT 
                TABLE_NAME as table_name,
                TABLE_ROWS as row_count,
                ROUND(DATA_LENGTH / 1024 / 1024, 2) as data_size_mb,
                ROUND(INDEX_LENGTH / 1024 / 1024, 2) as index_size_mb,
                ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as total_size_mb,
                TABLE_COLLATION as collation,
                ENGINE as engine
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ?
            ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
        ");
        
        $stmt->execute([$dbName]);
        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate totals
        $totalRows = array_sum(array_column($tables, 'row_count'));
        $totalSize = array_sum(array_column($tables, 'total_size_mb'));
        
        // Get database size
        $stmt = $pdo->query("
            SELECT 
                ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as size_mb
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ?
        ");
        
        $stmt->execute([$dbName]);
        $dbSize = $stmt->fetchColumn();
        
        http_response_code(200);
        echo json_encode([
            'database' => [
                'name' => $dbName,
                'size_mb' => $dbSize,
                'size_formatted' => formatBytes($dbSize * 1024 * 1024),
                'table_count' => count($tables),
                'total_rows' => $totalRows
            ],
            'tables' => $tables
        ]);
        
    } catch (Exception $e) {
        $logger->error('Get stats failed', ['error' => $e->getMessage()]);
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get statistics']);
    }
}

/**
 * Handle Optimize Tables
 */
function handleOptimize($pdo, $logger, $admin) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $tables = $data['tables'] ?? [];
        
        if (empty($tables)) {
            // Optimize all tables
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        $results = [];
        
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->query("OPTIMIZE TABLE `$table`");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $results[] = [
                    'table' => $table,
                    'status' => 'success',
                    'message' => $result['Msg_text'] ?? 'Optimized'
                ];
            } catch (Exception $e) {
                $results[] = [
                    'table' => $table,
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }
        
        // Log system event
        $audit = new AuditService($pdo, $logger);
        $audit->logSystemEvent(
            'database_optimize',
            'info',
            'Database tables optimized',
            ['tables_count' => count($tables)],
            $admin->user_id
        );
        
        // Log admin action
        logSystemAdminAction($admin, 'database_optimize', 'Optimized ' . count($tables) . ' tables');
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Tables optimized',
            'results' => $results
        ]);
        
    } catch (Exception $e) {
        $logger->error('Optimize failed', ['error' => $e->getMessage()]);
        http_response_code(500);
        echo json_encode(['error' => 'Optimization failed']);
    }
}

/**
 * Format bytes to human readable
 */
function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, 2) . ' ' . $units[$pow];
}

/**
 * Time ago helper
 */
function timeAgo($timestamp) {
    $diff = time() - $timestamp;
    
    if ($diff < 60) return $diff . ' seconds ago';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    if ($diff < 2592000) return floor($diff / 604800) . ' weeks ago';
    return date('Y-m-d H:i:s', $timestamp);
}

/**
 * Get All Tables
 */
function handleGetTables($pdo, $logger, $admin) {
    try {
        $dbName = $_ENV['DB_NAME'] ?? getenv('DB_NAME');
        
        // Get all tables with row counts and sizes
        $stmt = $pdo->query("
            SELECT 
                TABLE_NAME as name,
                TABLE_ROWS as row_count,
                ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) as size_mb,
                ENGINE as engine,
                TABLE_COLLATION as collation,
                CREATE_TIME as created_at,
                UPDATE_TIME as updated_at
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = '$dbName'
            ORDER BY TABLE_NAME
        ");
        
        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse('success', 'Tables retrieved successfully', [
            'tables' => $tables,
            'total' => count($tables)
        ], 200);
        
    } catch (Exception $e) {
        $logger->error('Failed to get tables', ['error' => $e->getMessage()]);
        jsonResponse('error', 'Failed to retrieve tables', [], 500);
    }
}

/**
 * Get Table Structure
 */
function handleGetTableStructure($pdo, $logger, $admin) {
    try {
        $tableName = $_GET['table'] ?? '';
        
        if (empty($tableName)) {
            jsonResponse('error', 'Table name is required', [], 400);
            return;
        }
        
        // Get columns
        $stmt = $pdo->query("DESCRIBE `$tableName`");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get indexes
        $stmt = $pdo->query("SHOW INDEX FROM `$tableName`");
        $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get foreign keys
        $dbName = $_ENV['DB_NAME'] ?? getenv('DB_NAME');
        $stmt = $pdo->query("
            SELECT 
                COLUMN_NAME as column_name,
                REFERENCED_TABLE_NAME as referenced_table,
                REFERENCED_COLUMN_NAME as referenced_column
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = '$dbName'
            AND TABLE_NAME = '$tableName'
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $foreignKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse('success', 'Table structure retrieved successfully', [
            'table' => $tableName,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $foreignKeys
        ], 200);
        
    } catch (Exception $e) {
        $logger->error('Failed to get table structure', ['error' => $e->getMessage()]);
        jsonResponse('error', 'Failed to retrieve table structure', [], 500);
    }
}

/**
 * Get Table Data with Pagination
 */
function handleGetTableData($pdo, $logger, $admin) {
    try {
        $tableName = $_GET['table'] ?? '';
        $page = (int)($_GET['page'] ?? 1);
        $limit = (int)($_GET['limit'] ?? 50);
        $offset = ($page - 1) * $limit;
        
        if (empty($tableName)) {
            jsonResponse('error', 'Table name is required', [], 400);
            return;
        }
        
        // Get total count
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM `$tableName`");
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Get data
        $stmt = $pdo->query("SELECT * FROM `$tableName` LIMIT $limit OFFSET $offset");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse('success', 'Table data retrieved successfully', [
            'table' => $tableName,
            'data' => $data,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => ceil($total / $limit)
            ]
        ], 200);
        
    } catch (Exception $e) {
        $logger->error('Failed to get table data', ['error' => $e->getMessage()]);
        jsonResponse('error', 'Failed to retrieve table data', [], 500);
    }
}

/**
 * Get Table Relationships (for ER Diagram)
 */
function handleGetTableRelationships($pdo, $logger, $admin) {
    try {
        $dbName = $_ENV['DB_NAME'] ?? getenv('DB_NAME');
        
        // Get all foreign key relationships
        $stmt = $pdo->query("
            SELECT 
                TABLE_NAME as from_table,
                COLUMN_NAME as from_column,
                REFERENCED_TABLE_NAME as to_table,
                REFERENCED_COLUMN_NAME as to_column,
                CONSTRAINT_NAME as constraint_name
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = '$dbName'
            AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY TABLE_NAME, COLUMN_NAME
        ");
        
        $relationships = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get all tables for the diagram
        $stmt = $pdo->query("
            SELECT TABLE_NAME as name
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = '$dbName'
            ORDER BY TABLE_NAME
        ");
        
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        jsonResponse('success', 'Relationships retrieved successfully', [
            'relationships' => $relationships,
            'tables' => $tables
        ], 200);
        
    } catch (Exception $e) {
        $logger->error('Failed to get relationships', ['error' => $e->getMessage()]);
        jsonResponse('error', 'Failed to retrieve relationships', [], 500);
    }
}

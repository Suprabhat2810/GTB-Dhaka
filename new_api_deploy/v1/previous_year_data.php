<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('previous_year_data');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

// CORS preflight
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    if ($method === 'GET') {
        $user = authenticate();
        $role = $user->role ?? 'guest';
        
        // Only admin can access previous year data
        if ($role !== 'admin') {
            jsonResponse('error', 'Unauthorized access', [], 403);
            exit;
        }
        
        // Check if requesting stats
        if (isset($_GET['stats']) && $_GET['stats'] === 'true') {
            $stmt = $pdo->query("
                SELECT 
                    academic_year,
                    total_students,
                    total_active,
                    total_releave,
                    total_fee_collected,
                    total_pending,
                    programs_count,
                    last_updated
                FROM previous_year_stats
                ORDER BY academic_year DESC
            ");
            $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            jsonResponse('success', 'Statistics retrieved', ['stats' => $stats], 200);
            exit;
        }
        
        // Check if requesting export
        if (isset($_GET['export']) && $_GET['export'] === 'true') {
            // Build query with filters
            $where = [];
            $params = [];
            
            if (!empty($_GET['academic_year'])) {
                $where[] = "academic_year = ?";
                $params[] = $_GET['academic_year'];
            }
            
            if (!empty($_GET['program'])) {
                $where[] = "program = ?";
                $params[] = $_GET['program'];
            }
            
            if (!empty($_GET['year_level'])) {
                $where[] = "year_level = ?";
                $params[] = $_GET['year_level'];
            }
            
            if (!empty($_GET['status'])) {
                $where[] = "status = ?";
                $params[] = $_GET['status'];
            }
            
            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            
            $stmt = $pdo->prepare("
                SELECT 
                    academic_year,
                    program,
                    year_level,
                    status,
                    student_name,
                    roll_number,
                    father_name,
                    total_fee,
                    paid_amount,
                    pending_amount,
                    remarks
                FROM previous_year_data
                {$whereClause}
                ORDER BY academic_year DESC, program, year_level, student_name
            ");
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Generate CSV
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="previous_year_data_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // Headers
            fputcsv($output, [
                'Academic Year', 'Program', 'Year Level', 'Status', 'Student Name',
                'Roll Number', 'Father Name', 'Total Fee', 'Paid Amount', 'Pending Amount', 'Remarks'
            ]);
            
            // Data
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
            
            fclose($output);
            exit;
        }
        
        // Determine which table to query
        $table = 'previous_year_fees'; // default
        if (!empty($_GET['type'])) {
            if ($_GET['type'] === 'students') {
                $table = 'previous_year_students';
            } elseif ($_GET['type'] === 'subjects') {
                $table = 'previous_year_subjects';
            }
        }
        
        // Regular data fetch with filters
        $where = [];
        $params = [];
        
        if (!empty($_GET['academic_year'])) {
            $where[] = "academic_year = ?";
            $params[] = $_GET['academic_year'];
        }
        
        if (!empty($_GET['program'])) {
            $where[] = "program = ?";
            $params[] = $_GET['program'];
        }
        
        if (!empty($_GET['year_level'])) {
            $where[] = "year_level = ?";
            $params[] = $_GET['year_level'];
        }
        
        if (!empty($_GET['status']) && $table === 'previous_year_fees') {
            $where[] = "status = ?";
            $params[] = $_GET['status'];
        }
        
        if (!empty($_GET['search'])) {
            $where[] = "(student_name LIKE ? OR roll_number LIKE ?)";
            $searchTerm = '%' . $_GET['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        // Pagination
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $offset = ($page - 1) * $limit;
        
        // Get total count
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} {$whereClause}");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        
        // Build SELECT based on table
        $selectFields = "id, academic_year, program, year_level, student_name, roll_number, source_file, imported_at";
        
        if ($table === 'previous_year_fees') {
            $selectFields .= ", status, father_name, total_fee, paid_amount, pending_amount, remarks";
        } elseif ($table === 'previous_year_students') {
            $selectFields .= ", father_name, mother_name, date_of_birth, gender, category, address, phone, email, admission_date";
        } elseif ($table === 'previous_year_subjects') {
            $selectFields .= ", subject_name, subject_code, marks_obtained, total_marks, grade, result, subjects_data";
        }
        
        // Get data
        $stmt = $pdo->prepare("
            SELECT {$selectFields}
            FROM {$table}
            {$whereClause}
            ORDER BY academic_year DESC, program, year_level, student_name
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get filter options from all tables
        $yearsStmt = $pdo->query("SELECT DISTINCT academic_year FROM {$table} ORDER BY academic_year DESC");
        $years = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);
        
        $programsStmt = $pdo->query("SELECT DISTINCT program FROM {$table} ORDER BY program");
        $programs = $programsStmt->fetchAll(PDO::FETCH_COLUMN);
        
        $yearLevelsStmt = $pdo->query("SELECT DISTINCT year_level FROM {$table} ORDER BY year_level");
        $yearLevels = $yearLevelsStmt->fetchAll(PDO::FETCH_COLUMN);
        
        jsonResponse('success', 'Data retrieved', [
            'data' => $data,
            'pagination' => [
                'total' => $totalRecords,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($totalRecords / $limit)
            ],
            'filters' => [
                'academic_years' => $years,
                'programs' => $programs,
                'year_levels' => $yearLevels,
                'statuses' => ['Active', 'Releave']
            ]
        ], 200);
        
    } else {
        jsonResponse('error', 'Method not allowed', [], 405);
    }
    
} catch (Exception $e) {
    $logger->error('previous_year_data error', ['error' => $e->getMessage()]);
    jsonResponse('error', 'Internal server error', [], 500);
}

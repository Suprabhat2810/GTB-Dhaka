<?php
declare(strict_types=1);

class HealthCheck
{
    private array $checks = [];
    private array $warnings = [];
    private array $errors = [];
    private string $appEnv;
    private bool $isProduction;

    public function __construct()
    {
        $this->appEnv = $_ENV['APP_ENV'] ?? 'development';
        $this->isProduction = $this->appEnv === 'production';
    }

    /**
     * Run all health checks
     */
    public function runAll(): array
    {
        $this->checkEnvironment();
        $this->checkPHP();
        $this->checkDatabase();
        $this->checkFileSystem();
        $this->checkStorage();
        $this->checkDependencies();
        $this->checkSecurity();
        $this->checkEndpoints();

        return $this->getResults();
    }

    /**
     * Check environment configuration
     */
    private function checkEnvironment(): void
    {
        $status = 'pass';
        $details = [];
        $message = 'Environment configured';

        try {
            // Check .env file exists
            $envFile = __DIR__ . '/../.env';
            if (!file_exists($envFile)) {
                $status = 'fail';
                $this->errors[] = '.env file not found';
            }

            // Check required environment variables
            $required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'JWT_SECRET', 'APP_ENV'];
            $missing = [];
            
            foreach ($required as $var) {
                if (empty($_ENV[$var])) {
                    $missing[] = $var;
                }
            }

            if (!empty($missing)) {
                $status = 'fail';
                $this->errors[] = 'Missing environment variables: ' . implode(', ', $missing);
            }

            $details = [
                'app_env' => $this->appEnv,
                'env_file_exists' => file_exists($envFile),
                'required_vars_set' => empty($missing),
            ];

        } catch (Exception $e) {
            $status = 'fail';
            $message = 'Environment check failed: ' . $e->getMessage();
            $this->errors[] = $message;
        }

        $this->checks['environment'] = [
            'status' => $status,
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * Check PHP environment
     */
    private function checkPHP(): void
    {
        $status = 'pass';
        $details = [];
        $message = 'PHP ' . PHP_VERSION;

        try {
            // Check PHP version
            if (version_compare(PHP_VERSION, '7.4.0', '<')) {
                $status = 'fail';
                $this->errors[] = 'PHP version 7.4+ required, found ' . PHP_VERSION;
            }

            // Check required extensions
            $requiredExtensions = ['pdo', 'pdo_mysql', 'fileinfo', 'mbstring', 'json'];
            $missingExtensions = [];

            foreach ($requiredExtensions as $ext) {
                if (!extension_loaded($ext)) {
                    $missingExtensions[] = $ext;
                }
            }

            if (!empty($missingExtensions)) {
                $status = 'fail';
                $this->errors[] = 'Missing PHP extensions: ' . implode(', ', $missingExtensions);
            }

            // Check memory limit
            $memoryLimit = ini_get('memory_limit');
            $uploadMaxSize = ini_get('upload_max_filesize');
            $postMaxSize = ini_get('post_max_size');

            $details = [
                'version' => PHP_VERSION,
                'extensions' => $requiredExtensions,
                'memory_limit' => $memoryLimit,
                'upload_max_filesize' => $uploadMaxSize,
                'post_max_size' => $postMaxSize,
            ];

            // Warnings for low limits
            if ($this->parseSize($memoryLimit) < 128 * 1024 * 1024) {
                $this->warnings[] = "Memory limit is low: $memoryLimit (recommend 128M+)";
            }

        } catch (Exception $e) {
            $status = 'fail';
            $message = 'PHP check failed: ' . $e->getMessage();
            $this->errors[] = $message;
        }

        $this->checks['php'] = [
            'status' => $status,
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * Check database connectivity
     */
    private function checkDatabase(): void
    {
        $status = 'pass';
        $details = [];
        $message = 'Database connected';

        try {
            require_once __DIR__ . '/../config.php';
            $pdo = getPDO();

            // Get database version
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();
            
            // Check required tables (core tables only)
            $requiredTables = [
                'students', 'admins', 'documents', 'payments', 
                'approvals', 'student_subjects', 'notifications', 'subjects'
            ];
            
            $existingTables = [];
            $missingTables = [];

            foreach ($requiredTables as $table) {
                $result = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
                if ($result) {
                    $existingTables[] = $table;
                } else {
                    $missingTables[] = $table;
                }
            }

            if (!empty($missingTables)) {
                $status = 'warn';
                $this->warnings[] = 'Missing database tables: ' . implode(', ', $missingTables);
            }

            $details = [
                'host' => $_ENV['DB_HOST'] . ':' . ($_ENV['DB_PORT'] ?? '3306'),
                'database' => $_ENV['DB_NAME'],
                'version' => $version,
                'tables_found' => count($existingTables),
                'tables_expected' => count($requiredTables),
            ];

            if (!$this->isProduction) {
                $details['existing_tables'] = $existingTables;
                if (!empty($missingTables)) {
                    $details['missing_tables'] = $missingTables;
                }
            }

        } catch (PDOException $e) {
            $status = 'fail';
            $message = 'Database connection failed';
            $this->errors[] = 'Database error: ' . $e->getMessage();
            $details = ['error' => $e->getMessage()];
        } catch (Exception $e) {
            $status = 'fail';
            $message = 'Database check failed';
            $this->errors[] = $e->getMessage();
        }

        $this->checks['database'] = [
            'status' => $status,
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * Check file system and directories
     */
    private function checkFileSystem(): void
    {
        $status = 'pass';
        $details = [];
        $message = 'File system accessible';

        try {
            $baseDir = __DIR__ . '/..';
            
            // Check critical directories
            $directories = [
                'uploads/documents' => true,  // must be writable
                'v1/logs' => true,            // must be writable
                'vendor' => false,            // must exist
                'services' => false,          // must exist
            ];

            $issues = [];
            foreach ($directories as $dir => $mustBeWritable) {
                $fullPath = $baseDir . '/' . $dir;
                
                if (!is_dir($fullPath)) {
                    $issues[] = "$dir does not exist";
                    $status = 'fail';
                    continue;
                }

                if ($mustBeWritable && !is_writable($fullPath)) {
                    $issues[] = "$dir is not writable";
                    $status = 'fail';
                }
            }

            if (!empty($issues)) {
                $this->errors = array_merge($this->errors, $issues);
            }

            // Check disk space
            $freeSpace = disk_free_space($baseDir);
            $totalSpace = disk_total_space($baseDir);
            $freeSpaceGB = round($freeSpace / 1024 / 1024 / 1024, 2);
            $usedPercent = round((1 - $freeSpace / $totalSpace) * 100, 1);

            if ($usedPercent > 90) {
                $this->warnings[] = "Disk space low: {$usedPercent}% used";
            }

            $details = [
                'base_directory' => $this->isProduction ? '[hidden]' : $baseDir,
                'free_space_gb' => $freeSpaceGB,
                'disk_used_percent' => $usedPercent,
                'directories_ok' => empty($issues),
            ];

        } catch (Exception $e) {
            $status = 'fail';
            $message = 'File system check failed';
            $this->errors[] = $e->getMessage();
        }

        $this->checks['filesystem'] = [
            'status' => $status,
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * Check storage configuration
     */
    private function checkStorage(): void
    {
        $status = 'pass';
        $details = [];
        $driver = $_ENV['STORAGE_DRIVER'] ?? 'local';
        $message = ucfirst($driver) . ' storage configured';

        try {
            if ($driver === 's3' || $this->appEnv === 'production') {
                // Check S3 configuration
                $s3Vars = ['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_BUCKET_NAME', 'AWS_DEFAULT_REGION'];
                $missingS3 = [];

                foreach ($s3Vars as $var) {
                    if (empty($_ENV[$var]) || $_ENV[$var] === 'your_aws_access_key_here' || $_ENV[$var] === 'your_aws_secret_key_here') {
                        $missingS3[] = $var;
                    }
                }

                if (!empty($missingS3) && $this->appEnv === 'production') {
                    $status = 'fail';
                    $this->errors[] = 'Production requires S3 configuration: ' . implode(', ', $missingS3);
                } elseif (!empty($missingS3)) {
                    $this->warnings[] = 'S3 not configured (OK for development)';
                }

                $details['s3_configured'] = empty($missingS3);
            }

            $details['driver'] = $driver;
            $details['app_env'] = $this->appEnv;

        } catch (Exception $e) {
            $status = 'fail';
            $message = 'Storage check failed';
            $this->errors[] = $e->getMessage();
        }

        $this->checks['storage'] = [
            'status' => $status,
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * Check composer dependencies
     */
    private function checkDependencies(): void
    {
        $status = 'pass';
        $details = [];
        $message = 'Dependencies installed';

        try {
            $vendorDir = __DIR__ . '/../vendor';
            $autoloadFile = $vendorDir . '/autoload.php';

            if (!file_exists($autoloadFile)) {
                $status = 'fail';
                $this->errors[] = 'Composer dependencies not installed (run: composer install)';
            }

            // Check for required packages
            $composerJson = __DIR__ . '/../composer.json';
            if (file_exists($composerJson)) {
                $composer = json_decode(file_get_contents($composerJson), true);
                $required = $composer['require'] ?? [];
                
                $details['packages'] = array_keys($required);
                $details['package_count'] = count($required);
            }

            $details['autoload_exists'] = file_exists($autoloadFile);

        } catch (Exception $e) {
            $status = 'fail';
            $message = 'Dependencies check failed';
            $this->errors[] = $e->getMessage();
        }

        $this->checks['dependencies'] = [
            'status' => $status,
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * Check security configuration
     */
    private function checkSecurity(): void
    {
        $status = 'pass';
        $details = [];
        $message = 'Security configured';

        try {
            // Check JWT secret
            $jwtSecret = $_ENV['JWT_SECRET'] ?? '';
            if (empty($jwtSecret) || $jwtSecret === 'very_long_random_secret_here_replace_it') {
                if ($this->isProduction) {
                    $status = 'fail';
                    $this->errors[] = 'JWT_SECRET must be changed in production';
                } else {
                    $this->warnings[] = 'JWT_SECRET is using default value';
                }
            }

            // Check HTTPS in production
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                       (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
            
            if ($this->isProduction && !$isHttps) {
                $this->warnings[] = 'HTTPS recommended for production';
            }

            $details = [
                'jwt_configured' => !empty($jwtSecret) && $jwtSecret !== 'very_long_random_secret_here_replace_it',
                'https_enabled' => $isHttps,
                'production_mode' => $this->isProduction,
            ];

        } catch (Exception $e) {
            $status = 'fail';
            $message = 'Security check failed';
            $this->errors[] = $e->getMessage();
        }

        $this->checks['security'] = [
            'status' => $status,
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * Check API endpoints
     */
    private function checkEndpoints(): void
    {
        $status = 'pass';
        $details = [];
        $endpointResults = [];
        
        try {
            $baseUrl = $this->getBaseUrl();
            
            // Define endpoints to check (lightweight checks only)
            $endpoints = [
                'authentication' => [
                    'login' => ['method' => 'OPTIONS', 'path' => '/login.php'],
                    'register' => ['method' => 'OPTIONS', 'path' => '/register.php'],
                ],
                'student' => [
                    'profile' => ['method' => 'OPTIONS', 'path' => '/student_profile.php'],
                    'documents' => ['method' => 'OPTIONS', 'path' => '/student_documents.php'],
                    'payments' => ['method' => 'OPTIONS', 'path' => '/student_payments.php'],
                ],
                'admin' => [
                    'students' => ['method' => 'OPTIONS', 'path' => '/students.php'],
                    'documents' => ['method' => 'OPTIONS', 'path' => '/documents.php'],
                    'approvals' => ['method' => 'OPTIONS', 'path' => '/approvals.php'],
                ],
                'utility' => [
                    'health' => ['method' => 'GET', 'path' => '/health.php'],
                ],
            ];

            $totalEndpoints = 0;
            $passedEndpoints = 0;

            foreach ($endpoints as $category => $categoryEndpoints) {
                $endpointResults[$category] = [];
                
                foreach ($categoryEndpoints as $name => $config) {
                    $totalEndpoints++;
                    $result = $this->checkEndpoint($baseUrl . $config['path'], $config['method']);
                    $endpointResults[$category][$name] = $result ? 'pass' : 'fail';
                    
                    if ($result) {
                        $passedEndpoints++;
                    }
                }
            }

            if ($passedEndpoints < $totalEndpoints) {
                $status = 'warn';
                $this->warnings[] = "Only $passedEndpoints of $totalEndpoints endpoints responding";
            }

            $details = [
                'total' => $totalEndpoints,
                'passed' => $passedEndpoints,
                'endpoints' => $endpointResults,
            ];

            $message = "$passedEndpoints of $totalEndpoints endpoints responding";

        } catch (Exception $e) {
            $status = 'fail';
            $message = 'Endpoint check failed';
            $this->errors[] = $e->getMessage();
        }

        $this->checks['endpoints'] = [
            'status' => $status,
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * Check single endpoint
     */
    private function checkEndpoint(string $url, string $method = 'GET'): bool
    {
        try {
            // Use file_get_contents for simple check
            $context = stream_context_create([
                'http' => [
                    'method' => $method,
                    'timeout' => 2,
                    'ignore_errors' => true,
                ],
            ]);

            $result = @file_get_contents($url, false, $context);
            
            // Check if we got a response (even if it's an error response)
            if ($result !== false || !empty($http_response_header)) {
                return true;
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get base URL for endpoint checks
     */
    private function getBaseUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = dirname($scriptName);
        
        return $protocol . '://' . $host . $basePath . '/v1';
    }

    /**
     * Parse size string to bytes
     */
    private function parseSize(string $size): int
    {
        $unit = strtoupper(substr($size, -1));
        $value = (int) substr($size, 0, -1);

        switch ($unit) {
            case 'G':
                return $value * 1024 * 1024 * 1024;
            case 'M':
                return $value * 1024 * 1024;
            case 'K':
                return $value * 1024;
            default:
                return (int) $size;
        }
    }

    /**
     * Get overall status
     */
    private function getOverallStatus(): string
    {
        $hasErrors = !empty($this->errors);
        $hasWarnings = !empty($this->warnings);
        $hasFailedChecks = false;

        foreach ($this->checks as $check) {
            if ($check['status'] === 'fail') {
                $hasFailedChecks = true;
                break;
            }
        }

        if ($hasErrors || $hasFailedChecks) {
            return 'unhealthy';
        } elseif ($hasWarnings) {
            return 'degraded';
        }

        return 'healthy';
    }

    /**
     * Get all results
     */
    public function getResults(): array
    {
        return [
            'status' => $this->getOverallStatus(),
            'timestamp' => date('c'),
            'environment' => $this->appEnv,
            'checks' => $this->checks,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
        ];
    }
}

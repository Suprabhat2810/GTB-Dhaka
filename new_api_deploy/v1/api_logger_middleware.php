<?php
/**
 * API Logger Middleware
 * 
 * Automatically logs all API requests and responses
 * Safe to use - never breaks main functionality
 * 
 * Usage:
 * require_once __DIR__ . '/api_logger_middleware.php';
 * $apiLogger = new APILogger($pdo, $logger);
 * $apiLogger->start();
 * // ... your API code ...
 * $apiLogger->end($statusCode);
 */

require_once __DIR__ . '/../services/AuditService.php';

class APILogger
{
    private $pdo;
    private $logger;
    private $startTime;
    private $endpoint;
    private $method;
    private $userId;
    private $userType;
    private $enabled;
    
    public function __construct($pdo, $logger = null)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->enabled = getenv('AUDIT_ENABLED') !== 'false';
        $this->startTime = microtime(true);
        $this->endpoint = $_SERVER['REQUEST_URI'] ?? 'unknown';
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
        $this->userId = null;
        $this->userType = null;
    }
    
    /**
     * Start logging (called at beginning of request)
     */
    public function start()
    {
        if (!$this->enabled) {
            return;
        }
        
        $this->startTime = microtime(true);
    }
    
    /**
     * Set user context (call after authentication)
     * 
     * @param int $userId User ID
     * @param string $userType User type (student, admin, system_admin)
     */
    public function setUser($userId, $userType)
    {
        $this->userId = $userId;
        $this->userType = $userType;
    }
    
    /**
     * End logging (called at end of request)
     * 
     * @param int $statusCode HTTP status code
     * @param string $errorMessage Optional error message
     */
    public function end($statusCode = 200, $errorMessage = null)
    {
        if (!$this->enabled) {
            return;
        }
        
        try {
            $endTime = microtime(true);
            $responseTime = round(($endTime - $this->startTime) * 1000); // milliseconds
            
            $audit = new AuditService($this->pdo, $this->logger);
            $audit->logAPI(
                $this->endpoint,
                $this->method,
                $statusCode,
                $responseTime,
                [
                    'user_id' => $this->userId,
                    'user_type' => $this->userType,
                    'error_message' => $errorMessage
                ]
            );
            
        } catch (Exception $e) {
            // Silent failure - don't break main functionality
            if ($this->logger) {
                $this->logger->error('API logging failed', ['error' => $e->getMessage()]);
            }
        }
    }
    
    /**
     * Log error (convenience method)
     * 
     * @param int $statusCode HTTP status code
     * @param string $errorMessage Error message
     */
    public function logError($statusCode, $errorMessage)
    {
        $this->end($statusCode, $errorMessage);
    }
}

/**
 * Helper function to create API logger
 * 
 * @param PDO $pdo Database connection
 * @param object $logger Logger instance
 * @return APILogger
 */
function createAPILogger($pdo, $logger = null)
{
    return new APILogger($pdo, $logger);
}

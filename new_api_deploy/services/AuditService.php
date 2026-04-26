<?php
/**
 * Audit Service
 * 
 * Centralized service for logging all system activities
 * 
 * Features:
 * - Fail-safe: Never breaks main functionality
 * - Encrypted: Sensitive data encrypted before storage
 * - Comprehensive: Logs all types of activities
 * - Performance: Async logging option
 * 
 * Usage:
 * $audit = new AuditService($pdo, $logger);
 * $audit->log('student_login', 'auth', 'Student logged in', [...]);
 */

require_once __DIR__ . '/EncryptionService.php';

class AuditService
{
    private $pdo;
    private $logger;
    private $encryption;
    private $enabled;
    
    public function __construct($pdo, $logger = null)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
        
        // Check if audit system is enabled
        $this->enabled = getenv('AUDIT_ENABLED') !== 'false';
        
        // Initialize encryption service
        try {
            $this->encryption = new EncryptionService();
        } catch (Exception $e) {
            $this->logInternal('Encryption service initialization failed: ' . $e->getMessage(), 'error');
            $this->enabled = false;
        }
    }
    
    /**
     * Log a general action
     * 
     * @param string $actionType Type of action (e.g., 'student_login', 'payment_approved')
     * @param string $category Category (auth, payment, student, subject, notification, document, system, admin)
     * @param string $description Human-readable description
     * @param array $data Additional data (user_id, entity_type, old_values, new_values, etc.)
     * @return bool Success status
     */
    public function log($actionType, $category, $description, $data = [])
    {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            // Extract common fields
            $userId = $data['user_id'] ?? null;
            $userType = $data['user_type'] ?? 'system';
            $userEmail = $data['user_email'] ?? null;
            $userName = $data['user_name'] ?? null;
            
            $entityType = $data['entity_type'] ?? null;
            $entityId = $data['entity_id'] ?? null;
            
            $oldValues = isset($data['old_values']) ? $this->encryption->encrypt($data['old_values']) : null;
            $newValues = isset($data['new_values']) ? $this->encryption->encrypt($data['new_values']) : null;
            
            $ipAddress = $this->getClientIP();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
            $requestUrl = $_SERVER['REQUEST_URI'] ?? null;
            
            $status = $data['status'] ?? 'success';
            $errorMessage = $data['error_message'] ?? null;
            
            // Insert into audit_logs table
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_logs (
                    user_id, user_type, user_email, user_name,
                    action_type, action_category, description,
                    entity_type, entity_id, old_values, new_values,
                    ip_address, user_agent, request_method, request_url,
                    status, error_message
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId, $userType, $userEmail, $userName,
                $actionType, $category, $description,
                $entityType, $entityId, $oldValues, $newValues,
                $ipAddress, $userAgent, $requestMethod, $requestUrl,
                $status, $errorMessage
            ]);
            
            $this->logInternal('Audit log created: ' . $actionType, 'info');
            return true;
            
        } catch (Exception $e) {
            $this->logInternal('Failed to create audit log: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Log a login attempt
     * 
     * @param string $email Email used for login
     * @param string $userType Type of user (student, admin, system_admin)
     * @param string $status Login status (success, failed, locked, expired)
     * @param array $data Additional data (user_id, failure_reason, session_id, etc.)
     * @return bool Success status
     */
    public function logLogin($email, $userType, $status, $data = [])
    {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            $userId = $data['user_id'] ?? null;
            $failureReason = $data['failure_reason'] ?? null;
            $sessionId = $data['session_id'] ?? null;
            
            $ipAddress = $this->getClientIP();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            // Parse user agent for device info
            $deviceInfo = $this->parseUserAgent($userAgent);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO login_audit (
                    user_id, user_type, email, login_status, failure_reason,
                    ip_address, user_agent, device_type, browser, os, session_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId, $userType, $email, $status, $failureReason,
                $ipAddress, $userAgent, 
                $deviceInfo['device'], $deviceInfo['browser'], $deviceInfo['os'],
                $sessionId
            ]);
            
            $this->logInternal('Login audit created for: ' . $email, 'info');
            return true;
            
        } catch (Exception $e) {
            $this->logInternal('Failed to create login audit: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Log a notification sent
     * 
     * @param int $recipientId Recipient user ID
     * @param string $recipientType Recipient type (student, admin)
     * @param string $notificationType Type of notification
     * @param string $channel Channel (whatsapp, email, in_app, sms)
     * @param string $status Status (pending, sent, delivered, failed, read)
     * @param array $data Additional data
     * @return bool Success status
     */
    public function logNotification($recipientId, $recipientType, $notificationType, $channel, $status, $data = [])
    {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            $notificationId = $data['notification_id'] ?? null;
            $recipientPhone = $data['recipient_phone'] ?? null;
            $recipientEmail = $data['recipient_email'] ?? null;
            $messageTitle = $data['message_title'] ?? null;
            $messageBody = $data['message_body'] ?? null;
            $provider = $data['provider'] ?? null;
            $providerMessageId = $data['provider_message_id'] ?? null;
            $errorMessage = $data['error_message'] ?? null;
            $retryCount = $data['retry_count'] ?? 0;
            
            $sentAt = $status === 'sent' || $status === 'delivered' ? date('Y-m-d H:i:s') : null;
            $deliveredAt = $status === 'delivered' ? date('Y-m-d H:i:s') : null;
            $failedAt = $status === 'failed' ? date('Y-m-d H:i:s') : null;
            
            $stmt = $this->pdo->prepare("
                INSERT INTO notification_audit (
                    notification_id, recipient_id, recipient_type, recipient_phone, recipient_email,
                    notification_type, channel, message_title, message_body,
                    status, sent_at, delivered_at, failed_at,
                    provider, provider_message_id, error_message, retry_count
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $notificationId, $recipientId, $recipientType, $recipientPhone, $recipientEmail,
                $notificationType, $channel, $messageTitle, $messageBody,
                $status, $sentAt, $deliveredAt, $failedAt,
                $provider, $providerMessageId, $errorMessage, $retryCount
            ]);
            
            $this->logInternal('Notification audit created: ' . $notificationType, 'info');
            return true;
            
        } catch (Exception $e) {
            $this->logInternal('Failed to create notification audit: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Log a payment action
     * 
     * @param int $paymentId Payment ID
     * @param string $action Action performed (submitted, approved, rejected, updated)
     * @param array $oldData Previous payment data
     * @param array $newData New payment data
     * @param array $performer Who performed the action
     * @return bool Success status
     */
    public function logPayment($paymentId, $action, $oldData, $newData, $performer)
    {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            $studentId = $newData['student_id'] ?? $oldData['student_id'] ?? null;
            $performedById = $performer['id'] ?? null;
            $performedByType = $performer['type'] ?? 'system';
            
            $oldStatus = $oldData['payment_status'] ?? null;
            $newStatus = $newData['payment_status'] ?? null;
            $oldAmount = $oldData['amount'] ?? null;
            $newAmount = $newData['amount'] ?? null;
            
            $transactionId = $newData['transaction_id'] ?? null;
            $adminNotes = $newData['admin_notes'] ?? null;
            $rejectionReason = $newData['rejection_reason'] ?? null;
            
            $ipAddress = $this->getClientIP();
            
            $stmt = $this->pdo->prepare("
                INSERT INTO payment_audit (
                    payment_id, student_id, action, performed_by_id, performed_by_type,
                    old_status, new_status, old_amount, new_amount,
                    transaction_id, admin_notes, rejection_reason, ip_address
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $paymentId, $studentId, $action, $performedById, $performedByType,
                $oldStatus, $newStatus, $oldAmount, $newAmount,
                $transactionId, $adminNotes, $rejectionReason, $ipAddress
            ]);
            
            $this->logInternal('Payment audit created: ' . $action, 'info');
            return true;
            
        } catch (Exception $e) {
            $this->logInternal('Failed to create payment audit: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Log a data change (INSERT, UPDATE, DELETE)
     * 
     * @param string $tableName Table that was modified
     * @param int $recordId Record ID
     * @param string $operation Operation (INSERT, UPDATE, DELETE)
     * @param array $oldData Previous data
     * @param array $newData New data
     * @param array $changedBy Who made the change
     * @return bool Success status
     */
    public function logDataChange($tableName, $recordId, $operation, $oldData, $newData, $changedBy)
    {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            $changedById = $changedBy['id'] ?? null;
            $changedByType = $changedBy['type'] ?? 'system';
            
            // Determine which fields changed
            $changedFields = [];
            if ($operation === 'UPDATE' && $oldData && $newData) {
                foreach ($newData as $key => $value) {
                    if (!isset($oldData[$key]) || $oldData[$key] !== $value) {
                        $changedFields[] = $key;
                    }
                }
            }
            
            $oldDataEncrypted = $oldData ? $this->encryption->encrypt($oldData) : null;
            $newDataEncrypted = $newData ? $this->encryption->encrypt($newData) : null;
            $changedFieldsJson = !empty($changedFields) ? json_encode($changedFields) : null;
            
            $ipAddress = $this->getClientIP();
            
            $stmt = $this->pdo->prepare("
                INSERT INTO data_change_audit (
                    table_name, record_id, operation, changed_by_id, changed_by_type,
                    old_data, new_data, changed_fields, ip_address
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $tableName, $recordId, $operation, $changedById, $changedByType,
                $oldDataEncrypted, $newDataEncrypted, $changedFieldsJson, $ipAddress
            ]);
            
            $this->logInternal('Data change audit created: ' . $tableName, 'info');
            return true;
            
        } catch (Exception $e) {
            $this->logInternal('Failed to create data change audit: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Log an API request
     * 
     * @param string $endpoint API endpoint
     * @param string $method HTTP method
     * @param int $statusCode HTTP status code
     * @param int $responseTime Response time in milliseconds
     * @param array $data Additional data
     * @return bool Success status
     */
    public function logAPI($endpoint, $method, $statusCode, $responseTime, $data = [])
    {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            $requestSize = $data['request_size'] ?? null;
            $responseSize = $data['response_size'] ?? null;
            $userId = $data['user_id'] ?? null;
            $userType = $data['user_type'] ?? null;
            $errorMessage = $data['error_message'] ?? null;
            
            $ipAddress = $this->getClientIP();
            
            $stmt = $this->pdo->prepare("
                INSERT INTO api_logs (
                    endpoint, method, status_code, response_time,
                    request_size, response_size, ip_address, user_id, user_type, error_message
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $endpoint, $method, $statusCode, $responseTime,
                $requestSize, $responseSize, $ipAddress, $userId, $userType, $errorMessage
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->logInternal('Failed to create API log: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Log a system event
     * 
     * @param string $eventType Type of event
     * @param string $severity Severity (info, warning, error, critical)
     * @param string $message Event message
     * @param array $details Additional details
     * @param int $performedBy System admin ID who triggered event
     * @return bool Success status
     */
    public function logSystemEvent($eventType, $severity, $message, $details = [], $performedBy = null)
    {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            $detailsJson = !empty($details) ? json_encode($details) : null;
            
            $stmt = $this->pdo->prepare("
                INSERT INTO system_events (event_type, severity, message, details, performed_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([$eventType, $severity, $message, $detailsJson, $performedBy]);
            
            $this->logInternal('System event logged: ' . $eventType, 'info');
            return true;
            
        } catch (Exception $e) {
            $this->logInternal('Failed to create system event: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Get client IP address
     * 
     * @return string IP address
     */
    private function getClientIP()
    {
        $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (isset($_SERVER[$key]) && filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
                return $_SERVER[$key];
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * Parse user agent string
     * 
     * @param string $userAgent User agent string
     * @return array Device info (device, browser, os)
     */
    private function parseUserAgent($userAgent)
    {
        $device = 'desktop';
        $browser = 'unknown';
        $os = 'unknown';
        
        if (empty($userAgent)) {
            return compact('device', 'browser', 'os');
        }
        
        // Detect device
        if (preg_match('/mobile|android|iphone|ipad|tablet/i', $userAgent)) {
            $device = 'mobile';
        }
        
        // Detect browser
        if (preg_match('/chrome/i', $userAgent)) $browser = 'Chrome';
        elseif (preg_match('/firefox/i', $userAgent)) $browser = 'Firefox';
        elseif (preg_match('/safari/i', $userAgent)) $browser = 'Safari';
        elseif (preg_match('/edge/i', $userAgent)) $browser = 'Edge';
        elseif (preg_match('/opera/i', $userAgent)) $browser = 'Opera';
        
        // Detect OS
        if (preg_match('/windows/i', $userAgent)) $os = 'Windows';
        elseif (preg_match('/mac/i', $userAgent)) $os = 'macOS';
        elseif (preg_match('/linux/i', $userAgent)) $os = 'Linux';
        elseif (preg_match('/android/i', $userAgent)) $os = 'Android';
        elseif (preg_match('/ios|iphone|ipad/i', $userAgent)) $os = 'iOS';
        
        return compact('device', 'browser', 'os');
    }
    
    /**
     * Internal logging (uses provided logger or error_log)
     * 
     * @param string $message Log message
     * @param string $level Log level
     */
    private function logInternal($message, $level = 'info')
    {
        if ($this->logger) {
            $this->logger->{$level}($message);
        } else {
            error_log("[AuditService] [{$level}] {$message}");
        }
    }
    
    /**
     * Check if audit system is enabled
     * 
     * @return bool
     */
    public function isEnabled()
    {
        return $this->enabled;
    }
}

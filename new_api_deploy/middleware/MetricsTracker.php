<?php
/**
 * Metrics Tracker Middleware
 * Automatically tracks all API calls and traffic
 */

class MetricsTracker {
    private $pdo;
    private $startTime;
    private $requestData;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->startTime = microtime(true);
        $this->requestData = $this->captureRequest();
    }
    
    /**
     * Capture request data
     */
    private function captureRequest() {
        return [
            'endpoint' => $_SERVER['REQUEST_URI'] ?? '/',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'ip_address' => $this->getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'session_id' => session_id() ?: $this->generateSessionId(),
            'request_size' => isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0
        ];
    }
    
    /**
     * Get client IP address
     */
    private function getClientIP() {
        $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 
                   'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (isset($_SERVER[$key]) && filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
                return $_SERVER[$key];
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Generate session ID if none exists
     */
    private function generateSessionId() {
        return md5($this->getClientIP() . ($_SERVER['HTTP_USER_AGENT'] ?? '') . date('Y-m-d-H'));
    }
    
    /**
     * Track API metric
     */
    public function trackAPICall($statusCode, $success, $errorMessage = null, $userId = null, $userType = null) {
        try {
            $responseTime = (int)((microtime(true) - $this->startTime) * 1000); // Convert to milliseconds
            
            $stmt = $this->pdo->prepare("
                INSERT INTO api_metrics (
                    endpoint, method, response_time, status_code, success,
                    error_message, user_id, user_type, ip_address, user_agent,
                    request_size, created_at
                ) VALUES (
                    :endpoint, :method, :response_time, :status_code, :success,
                    :error_message, :user_id, :user_type, :ip_address, :user_agent,
                    :request_size, NOW()
                )
            ");
            
            $stmt->execute([
                'endpoint' => $this->cleanEndpoint($this->requestData['endpoint']),
                'method' => $this->requestData['method'],
                'response_time' => $responseTime,
                'status_code' => $statusCode,
                'success' => $success ? 1 : 0,
                'error_message' => $errorMessage,
                'user_id' => $userId,
                'user_type' => $userType,
                'ip_address' => $this->requestData['ip_address'],
                'user_agent' => $this->requestData['user_agent'],
                'request_size' => $this->requestData['request_size']
            ]);
            
        } catch (PDOException $e) {
            error_log("Failed to track API metric: " . $e->getMessage());
        }
    }
    
    /**
     * Track traffic
     */
    public function trackTraffic($userId = null, $userType = null, $userEmail = null, $userName = null) {
        try {
            $deviceInfo = $this->parseUserAgent($this->requestData['user_agent']);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO traffic_logs (
                    session_id, user_id, user_type, user_email, user_name,
                    endpoint, method, ip_address, user_agent, referer,
                    device_type, browser, os, created_at
                ) VALUES (
                    :session_id, :user_id, :user_type, :user_email, :user_name,
                    :endpoint, :method, :ip_address, :user_agent, :referer,
                    :device_type, :browser, :os, NOW()
                )
            ");
            
            $stmt->execute([
                'session_id' => $this->requestData['session_id'],
                'user_id' => $userId,
                'user_type' => $userType ?: 'guest',
                'user_email' => $userEmail,
                'user_name' => $userName,
                'endpoint' => $this->cleanEndpoint($this->requestData['endpoint']),
                'method' => $this->requestData['method'],
                'ip_address' => $this->requestData['ip_address'],
                'user_agent' => $this->requestData['user_agent'],
                'referer' => $this->requestData['referer'],
                'device_type' => $deviceInfo['device_type'],
                'browser' => $deviceInfo['browser'],
                'os' => $deviceInfo['os']
            ]);
            
        } catch (PDOException $e) {
            error_log("Failed to track traffic: " . $e->getMessage());
        }
    }
    
    /**
     * Clean endpoint (remove query parameters and IDs)
     */
    private function cleanEndpoint($endpoint) {
        // Remove query string
        $endpoint = strtok($endpoint, '?');
        
        // Replace numeric IDs with placeholder
        $endpoint = preg_replace('/\/\d+/', '/:id', $endpoint);
        
        // Limit length
        return substr($endpoint, 0, 255);
    }
    
    /**
     * Parse user agent for device info
     */
    private function parseUserAgent($userAgent) {
        if (!$userAgent) {
            return ['device_type' => 'unknown', 'browser' => 'unknown', 'os' => 'unknown'];
        }
        
        // Device type
        $deviceType = 'desktop';
        if (preg_match('/mobile|android|iphone|ipad|tablet/i', $userAgent)) {
            if (preg_match('/tablet|ipad/i', $userAgent)) {
                $deviceType = 'tablet';
            } else {
                $deviceType = 'mobile';
            }
        }
        
        // Browser
        $browser = 'unknown';
        if (preg_match('/Chrome/i', $userAgent)) $browser = 'Chrome';
        elseif (preg_match('/Firefox/i', $userAgent)) $browser = 'Firefox';
        elseif (preg_match('/Safari/i', $userAgent)) $browser = 'Safari';
        elseif (preg_match('/Edge/i', $userAgent)) $browser = 'Edge';
        elseif (preg_match('/MSIE|Trident/i', $userAgent)) $browser = 'IE';
        
        // OS
        $os = 'unknown';
        if (preg_match('/Windows/i', $userAgent)) $os = 'Windows';
        elseif (preg_match('/Mac OS X/i', $userAgent)) $os = 'macOS';
        elseif (preg_match('/Linux/i', $userAgent)) $os = 'Linux';
        elseif (preg_match('/Android/i', $userAgent)) $os = 'Android';
        elseif (preg_match('/iOS|iPhone|iPad/i', $userAgent)) $os = 'iOS';
        
        return [
            'device_type' => $deviceType,
            'browser' => $browser,
            'os' => $os
        ];
    }
    
    /**
     * Track both API and traffic in one call
     */
    public function track($statusCode, $success, $userId = null, $userType = null, $userEmail = null, $userName = null, $errorMessage = null) {
        $this->trackAPICall($statusCode, $success, $errorMessage, $userId, $userType);
        $this->trackTraffic($userId, $userType, $userEmail, $userName);
    }
}

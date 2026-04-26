<?php
/**
 * WhatsApp Service using Twilio API
 * 
 * This service handles sending WhatsApp messages to students
 * for registration, approval, and payment notifications.
 * 
 * Features:
 * - Fail-safe: Never breaks main functionality
 * - Optional: Works only if Twilio credentials are configured
 * - Logging: All attempts are logged for debugging
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../services/AuditService.php';

use Twilio\Rest\Client;

class WhatsAppService
{
    private $client;
    private $from;
    private $instituteName;
    private $logoUrl;
    private $enabled;
    private $logger;
    private $pdo;

    public function __construct($logger = null, $pdo = null)
    {
        $this->logger = $logger;
        $this->pdo = $pdo;
        
        // Load environment variables
        $accountSid = getenv('TWILIO_ACCOUNT_SID');
        $authToken = getenv('TWILIO_AUTH_TOKEN');
        $this->from = getenv('TWILIO_WHATSAPP_FROM') ?: 'whatsapp:+14155238886';
        $this->instituteName = getenv('INSTITUTE_NAME') ?: 'GTB Dhaka College';
        $this->logoUrl = getenv('INSTITUTE_LOGO_URL') ?: '';

        // Check if Twilio is configured
        $this->enabled = !empty($accountSid) && 
                        !empty($authToken) && 
                        $accountSid !== 'your_twilio_account_sid_here';

        if ($this->enabled) {
            try {
                $this->client = new Client($accountSid, $authToken);
                $this->log('WhatsApp service initialized successfully');
            } catch (Exception $e) {
                $this->enabled = false;
                $this->log('Failed to initialize Twilio client: ' . $e->getMessage(), 'error');
            }
        } else {
            $this->log('WhatsApp service disabled - Twilio credentials not configured', 'info');
        }
    }

    /**
     * Send welcome message with login credentials to newly registered student
     */
    public function sendWelcomeMessage($phone, $studentName, $registrationNumber = null, $password = null)
    {
        if (!$this->enabled) {
            return false;
        }

        $message = "🎓 *Welcome to {$this->instituteName}!*\n\n";
        $message .= "Dear *{$studentName}*,\n\n";
        $message .= "Thank you for registering with us! Your registration has been received successfully.\n\n";
        
        if ($registrationNumber) {
            $message .= "📋 *Your Login Credentials:*\n";
            $message .= "• Registration Number: `{$registrationNumber}`\n";
            if ($password) {
                $message .= "• Password: `{$password}`\n\n";
                $message .= "⚠️ *IMPORTANT:* Please keep these credentials safe and change your password after first login.\n\n";
            }
        }
        
        $message .= "✅ *Next Steps:*\n";
        $message .= "1. Login to your account\n";
        $message .= "2. Complete your profile information\n";
        $message .= "3. Upload required documents\n";
        $message .= "4. Wait for admin approval\n\n";
        $message .= "We will notify you once your application is reviewed.\n\n";
        $message .= "Best regards,\n";
        $message .= "{$this->instituteName} Team";

        return $this->sendMessage($phone, $message, 'Welcome');
    }

    /**
     * Send login credentials to student (one-time use)
     */
    public function sendCredentials($phone, $studentName, $registrationNumber, $password)
    {
        if (!$this->enabled) {
            return false;
        }

        $message = "🔐 *Login Credentials - {$this->instituteName}*\n\n";
        $message .= "Dear *{$studentName}*,\n\n";
        $message .= "Your account has been created successfully!\n\n";
        $message .= "📋 *Login Details:*\n";
        $message .= "• Registration Number: `{$registrationNumber}`\n";
        $message .= "• Password: `{$password}`\n\n";
        $message .= "⚠️ *SECURITY NOTICE:*\n";
        $message .= "• Keep these credentials confidential\n";
        $message .= "• Do not share with anyone\n";
        $message .= "• Change your password after first login\n";
        $message .= "• Delete this message after saving credentials\n\n";
        $message .= "🌐 Login at: https://gtbnc.co.in/login\n\n";
        $message .= "Best regards,\n";
        $message .= "{$this->instituteName} Team";

        return $this->sendMessage($phone, $message, 'Credentials');
    }

    /**
     * Send approval notification to student
     */
    public function sendApprovalMessage($phone, $studentName, $semester, $academicYear, $program = null)
    {
        if (!$this->enabled) {
            return false;
        }

        $message = "🎉 *Congratulations {$studentName}!*\n\n";
        $message .= "Your application has been *APPROVED* by {$this->instituteName}.\n\n";
        $message .= "📚 *Enrollment Details:*\n";
        
        if ($program) {
            $message .= "• Program: {$program}\n";
        }
        
        $message .= "• Semester: {$semester}\n";
        $message .= "• Academic Year: {$academicYear}\n\n";
        $message .= "✅ *Next Steps:*\n";
        $message .= "1. Complete your final registration form\n";
        $message .= "2. Pay the semester fees\n";
        $message .= "3. Access your student portal\n\n";
        $message .= "Welcome to our academic family! 🎓\n\n";
        $message .= "Best regards,\n";
        $message .= "{$this->instituteName} Team";

        return $this->sendMessage($phone, $message, 'Approval');
    }

    /**
     * Send payment confirmation message
     */
    public function sendPaymentConfirmation($phone, $studentName, $amount, $semester, $transactionId = null, $paymentDate = null)
    {
        if (!$this->enabled) {
            return false;
        }

        $message = "✅ *Payment Received Successfully*\n\n";
        $message .= "Dear *{$studentName}*,\n\n";
        $message .= "We have received your semester fee payment.\n\n";
        $message .= "💰 *Payment Details:*\n";
        $message .= "• Amount: ₹{$amount}\n";
        $message .= "• Semester: {$semester}\n";
        
        if ($transactionId) {
            $message .= "• Transaction ID: {$transactionId}\n";
        }
        
        if ($paymentDate) {
            $message .= "• Date: {$paymentDate}\n";
        }
        
        $message .= "\n📝 Your payment has been recorded in our system.\n";
        $message .= "You can now access all semester resources and materials.\n\n";
        $message .= "Thank you for your prompt payment! 🙏\n\n";
        $message .= "Best regards,\n";
        $message .= "{$this->instituteName} Team";

        return $this->sendMessage($phone, $message, 'Payment');
    }

    /**
     * Core method to send WhatsApp message via Twilio
     */
    private function sendMessage($phone, $body, $type = 'General')
    {
        if (!$this->enabled) {
            $this->log("WhatsApp service disabled - skipping {$type} message to {$phone}", 'info');
            return false;
        }

        try {
            // Format phone number for WhatsApp
            $to = $this->formatPhoneNumber($phone);
            
            $this->log("Attempting to send {$type} WhatsApp to {$to}");

            // Prepare message options
            $options = ['from' => $this->from, 'body' => $body];
            
            // Add media (logo) if available
            if (!empty($this->logoUrl)) {
                $options['mediaUrl'] = [$this->logoUrl];
            }

            // Send message
            $message = $this->client->messages->create($to, $options);

            $this->log("WhatsApp {$type} message sent successfully. SID: {$message->sid}");
            
            // Audit logging (safe - wrapped in try-catch)
            if ($this->pdo) {
                try {
                    $audit = new AuditService($this->pdo, $this->logger);
                    $audit->logNotification(
                        null, // recipient_id (unknown at this level)
                        'student', // recipient_type
                        strtolower($type), // notification_type
                        'whatsapp', // channel
                        'sent', // status
                        [
                            'recipient_phone' => $phone,
                            'message_body' => substr($body, 0, 500), // Limit to 500 chars
                            'provider' => 'twilio',
                            'provider_message_id' => $message->sid
                        ]
                    );
                } catch (Exception $e) {
                    $this->log("Audit logging failed (non-critical): " . $e->getMessage(), 'warning');
                }
            }
            
            return true;

        } catch (Exception $e) {
            $this->log("Failed to send {$type} WhatsApp to {$phone}: " . $e->getMessage(), 'error');
            
            // Audit logging for failed notification
            if ($this->pdo) {
                try {
                    $audit = new AuditService($this->pdo, $this->logger);
                    $audit->logNotification(
                        null,
                        'student',
                        strtolower($type),
                        'whatsapp',
                        'failed',
                        [
                            'recipient_phone' => $phone,
                            'error_message' => $e->getMessage(),
                            'provider' => 'twilio'
                        ]
                    );
                } catch (Exception $e2) {
                    $this->log("Audit logging failed (non-critical): " . $e2->getMessage(), 'warning');
                }
            }
            
            return false;
        }
    }

    /**
     * Format phone number for WhatsApp (add whatsapp: prefix and country code)
     */
    private function formatPhoneNumber($phone)
    {
        // Remove any non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // If phone doesn't start with country code, assume India (+91)
        if (strlen($phone) === 10) {
            $phone = '91' . $phone;
        }
        
        // Add whatsapp: prefix
        return 'whatsapp:+' . $phone;
    }

    /**
     * Log messages (uses provided logger or error_log)
     */
    private function log($message, $level = 'info')
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] WhatsApp Service [{$level}]: {$message}";
        
        if ($this->logger) {
            $this->logger->{$level}($message);
        } else {
            error_log($logMessage);
        }
    }

    /**
     * Check if WhatsApp service is enabled and configured
     */
    public function isEnabled()
    {
        return $this->enabled;
    }
}

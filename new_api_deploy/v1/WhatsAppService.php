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

use Twilio\Rest\Client;

class WhatsAppService
{
    private $client;
    private $from;
    private $instituteName;
    private $logoUrl;
    private $enabled;
    private $logger;

    public function __construct($logger = null)
    {
        $this->logger = $logger;
        
        // Load environment variables
        $accountSid = getenv('TWILIO_ACCOUNT_SID');
        $authToken = getenv('TWILIO_AUTH_TOKEN');
        $this->from = getenv('TWILIO_WHATSAPP_FROM') ?: 'whatsapp:+14155238886';
        $this->instituteName = getenv('INSTITUTE_NAME') ?: 'Guru Tegh Bahadur Khalsa College';
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
     * Send welcome message to newly registered student
     */
    public function sendWelcomeMessage($phone, $studentName, $registrationNumber = null)
    {
        if (!$this->enabled) {
            return false;
        }

        $message = "🎓 *Welcome to {$this->instituteName}!*\n\n";
        $message .= "Dear *{$studentName}*,\n\n";
        $message .= "Thank you for registering with us! Your registration has been received successfully.\n\n";
        
        if ($registrationNumber) {
            $message .= "📋 *Registration Number:* {$registrationNumber}\n\n";
        }
        
        $message .= "✅ *Next Steps:*\n";
        $message .= "1. Complete your profile information\n";
        $message .= "2. Upload required documents\n";
        $message .= "3. Wait for admin approval\n\n";
        $message .= "We will notify you once your application is reviewed.\n\n";
        $message .= "Best regards,\n";
        $message .= "{$this->instituteName} Team";

        return $this->sendMessage($phone, $message, 'Welcome');
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
            return true;

        } catch (Exception $e) {
            $this->log("Failed to send {$type} WhatsApp to {$phone}: " . $e->getMessage(), 'error');
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

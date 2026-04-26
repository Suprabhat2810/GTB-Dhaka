<?php
/**
 * Encryption Service
 * 
 * Handles encryption and decryption of sensitive data in audit logs
 * Uses AES-256-CBC encryption with random IV for each encryption
 * 
 * Security Features:
 * - AES-256-CBC encryption
 * - Random IV for each encryption
 * - Base64 encoding for storage
 * - Key derivation from environment variable
 */

class EncryptionService
{
    private $key;
    private $cipher = 'AES-256-CBC';
    
    public function __construct()
    {
        // Get encryption key from environment (use $_ENV to match config.php)
        $envKey = $_ENV['AUDIT_ENCRYPTION_KEY'] ?? getenv('AUDIT_ENCRYPTION_KEY');
        
        if (empty($envKey)) {
            throw new Exception('AUDIT_ENCRYPTION_KEY not configured in .env file');
        }
        
        // Derive a proper 256-bit key from the environment variable
        $this->key = hash('sha256', $envKey, true);
    }
    
    /**
     * Encrypt data for storage
     * 
     * @param mixed $data Data to encrypt (will be JSON encoded)
     * @return string Base64 encoded encrypted data with IV
     * @throws Exception If encryption fails
     */
    public function encrypt($data)
    {
        if ($data === null) {
            return null;
        }
        
        try {
            // Convert data to JSON
            $json = json_encode($data);
            
            if ($json === false) {
                throw new Exception('Failed to JSON encode data');
            }
            
            // Generate random IV
            $ivLength = openssl_cipher_iv_length($this->cipher);
            $iv = openssl_random_pseudo_bytes($ivLength);
            
            // Encrypt the data
            $encrypted = openssl_encrypt(
                $json,
                $this->cipher,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv
            );
            
            if ($encrypted === false) {
                throw new Exception('Encryption failed');
            }
            
            // Combine IV and encrypted data, then base64 encode
            $combined = $iv . $encrypted;
            return base64_encode($combined);
            
        } catch (Exception $e) {
            error_log('Encryption error: ' . $e->getMessage());
            throw new Exception('Failed to encrypt data: ' . $e->getMessage());
        }
    }
    
    /**
     * Decrypt data from storage
     * 
     * @param string $encryptedData Base64 encoded encrypted data with IV
     * @return mixed Decrypted and JSON decoded data
     * @throws Exception If decryption fails
     */
    public function decrypt($encryptedData)
    {
        if ($encryptedData === null || $encryptedData === '') {
            return null;
        }
        
        try {
            // Base64 decode
            $combined = base64_decode($encryptedData, true);
            
            if ($combined === false) {
                throw new Exception('Invalid base64 data');
            }
            
            // Extract IV and encrypted data
            $ivLength = openssl_cipher_iv_length($this->cipher);
            $iv = substr($combined, 0, $ivLength);
            $encrypted = substr($combined, $ivLength);
            
            // Decrypt
            $decrypted = openssl_decrypt(
                $encrypted,
                $this->cipher,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv
            );
            
            if ($decrypted === false) {
                throw new Exception('Decryption failed');
            }
            
            // JSON decode
            $data = json_decode($decrypted, true);
            
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Failed to JSON decode decrypted data');
            }
            
            return $data;
            
        } catch (Exception $e) {
            error_log('Decryption error: ' . $e->getMessage());
            throw new Exception('Failed to decrypt data: ' . $e->getMessage());
        }
    }
    
    /**
     * Encrypt a string (for simple text encryption)
     * 
     * @param string $text Text to encrypt
     * @return string Base64 encoded encrypted text
     */
    public function encryptString($text)
    {
        if ($text === null || $text === '') {
            return null;
        }
        
        return $this->encrypt($text);
    }
    
    /**
     * Decrypt a string (for simple text decryption)
     * 
     * @param string $encryptedText Base64 encoded encrypted text
     * @return string Decrypted text
     */
    public function decryptString($encryptedText)
    {
        if ($encryptedText === null || $encryptedText === '') {
            return null;
        }
        
        return $this->decrypt($encryptedText);
    }
    
    /**
     * Generate a random encryption key (for initial setup)
     * 
     * @return string Random 64-character hex string
     */
    public static function generateKey()
    {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Verify if encryption is properly configured
     * 
     * @return bool True if encryption is working
     */
    public function verify()
    {
        try {
            $testData = ['test' => 'data', 'number' => 123];
            $encrypted = $this->encrypt($testData);
            $decrypted = $this->decrypt($encrypted);
            
            return $testData === $decrypted;
        } catch (Exception $e) {
            return false;
        }
    }
}

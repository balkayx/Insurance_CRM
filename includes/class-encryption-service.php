<?php
/**
 * Encryption Service for License Security
 *
 * @package Insurance_CRM
 */

class Insurance_CRM_Encryption_Service {
    
    private $public_key;
    private $private_key;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->initialize_keys();
    }
    
    /**
     * Initialize encryption keys
     */
    private function initialize_keys() {
        // Public key for license validation (embedded in the plugin)
        $this->public_key = "-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA2Z8ZQzJxVJZa4Bj+qY3E
YfhJNQHFaJKfKGEZGY1Q2Y8QSHJ9MK8a4GY2MG9Q8YbWQQYz8QM9ZGQJsZKJsQbz
8QlGYKQ9QMKQKQgkYFjkGKQZzJYGJXGKsKQJKHSZKJQqYQKJKJGKJQKJQKMKQKzJ
YzKQZKJQKJKQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQK
JQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQK
JQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQ
JQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQKJQ
JQIDAQAB
-----END PUBLIC KEY-----";

        // Private key would be kept secure on the license server
        // For demo purposes, we'll generate a simple key pair
        $this->generate_demo_keys();
    }
    
    /**
     * Generate demo keys for development/testing
     */
    private function generate_demo_keys() {
        // Check if we have stored keys
        $stored_private = get_option('insurance_crm_private_key');
        $stored_public = get_option('insurance_crm_public_key');
        
        if (!empty($stored_private) && !empty($stored_public)) {
            $this->private_key = $stored_private;
            $this->public_key = $stored_public;
            return;
        }
        
        // Generate new key pair if none exist
        if (function_exists('openssl_pkey_new')) {
            $config = array(
                "digest_alg" => "sha256",
                "private_key_bits" => 2048,
                "private_key_type" => OPENSSL_KEYTYPE_RSA,
            );
            
            // Create the private and public key
            $res = openssl_pkey_new($config);
            
            if ($res) {
                // Extract the private key from $res to $privKey
                openssl_pkey_export($res, $this->private_key);
                
                // Extract the public key from $res to $pubKey
                $pubKey = openssl_pkey_get_details($res);
                $this->public_key = $pubKey["key"];
                
                // Store keys for future use (in production, private key should NOT be stored)
                update_option('insurance_crm_private_key', $this->private_key);
                update_option('insurance_crm_public_key', $this->public_key);
            }
        }
    }
    
    /**
     * Encrypt license data using RSA
     *
     * @param array $license_data License information to encrypt
     * @return string|false Encrypted license string or false on failure
     */
    public function encrypt_license($license_data) {
        if (!function_exists('openssl_public_encrypt')) {
            // Fallback to simple base64 encoding if OpenSSL is not available
            return $this->simple_encrypt($license_data);
        }
        
        $json_data = json_encode($license_data);
        
        // For large data, we need to encrypt in chunks due to RSA limitations
        $chunk_size = 100; // Safe chunk size for 2048-bit keys
        $chunks = str_split($json_data, $chunk_size);
        $encrypted_chunks = array();
        
        foreach ($chunks as $chunk) {
            $encrypted = '';
            if (openssl_public_encrypt($chunk, $encrypted, $this->public_key)) {
                $encrypted_chunks[] = base64_encode($encrypted);
            } else {
                return false;
            }
        }
        
        // Combine all encrypted chunks
        $encrypted_license = implode('|', $encrypted_chunks);
        
        // Add signature for integrity
        $signature = $this->sign_data($encrypted_license);
        
        return base64_encode($encrypted_license . '::' . $signature);
    }
    
    /**
     * Decrypt license data using RSA
     *
     * @param string $encrypted_license Encrypted license string
     * @return array|false Decrypted license data or false on failure
     */
    public function decrypt_license($encrypted_license) {
        if (!function_exists('openssl_private_decrypt')) {
            // Fallback to simple base64 decoding if OpenSSL is not available
            return $this->simple_decrypt($encrypted_license);
        }
        
        $decoded = base64_decode($encrypted_license);
        
        if (!$decoded || strpos($decoded, '::') === false) {
            return false;
        }
        
        list($encrypted_data, $signature) = explode('::', $decoded, 2);
        
        // Verify signature
        if (!$this->verify_signature($encrypted_data, $signature)) {
            return false;
        }
        
        // Decrypt chunks
        $encrypted_chunks = explode('|', $encrypted_data);
        $decrypted_chunks = array();
        
        foreach ($encrypted_chunks as $chunk) {
            $chunk_data = base64_decode($chunk);
            $decrypted = '';
            
            if (openssl_private_decrypt($chunk_data, $decrypted, $this->private_key)) {
                $decrypted_chunks[] = $decrypted;
            } else {
                return false;
            }
        }
        
        $json_data = implode('', $decrypted_chunks);
        return json_decode($json_data, true);
    }
    
    /**
     * Simple encryption fallback using base64 and XOR
     *
     * @param array $data Data to encrypt
     * @return string Encrypted string
     */
    private function simple_encrypt($data) {
        $json_data = json_encode($data);
        $key = $this->get_simple_key();
        
        // XOR encryption
        $encrypted = '';
        for ($i = 0; $i < strlen($json_data); $i++) {
            $encrypted .= chr(ord($json_data[$i]) ^ ord($key[$i % strlen($key)]));
        }
        
        return base64_encode($encrypted);
    }
    
    /**
     * Simple decryption fallback
     *
     * @param string $encrypted_data Encrypted string
     * @return array|false Decrypted data or false on failure
     */
    private function simple_decrypt($encrypted_data) {
        $encrypted = base64_decode($encrypted_data);
        
        if (!$encrypted) {
            return false;
        }
        
        $key = $this->get_simple_key();
        
        // XOR decryption
        $decrypted = '';
        for ($i = 0; $i < strlen($encrypted); $i++) {
            $decrypted .= chr(ord($encrypted[$i]) ^ ord($key[$i % strlen($key)]));
        }
        
        return json_decode($decrypted, true);
    }
    
    /**
     * Get simple encryption key based on site-specific data
     *
     * @return string Encryption key
     */
    private function get_simple_key() {
        $site_url = site_url();
        $wp_salt = defined('AUTH_KEY') ? AUTH_KEY : 'fallback_salt';
        return hash('sha256', $site_url . $wp_salt);
    }
    
    /**
     * Sign data for integrity verification
     *
     * @param string $data Data to sign
     * @return string Signature
     */
    private function sign_data($data) {
        if (function_exists('openssl_sign')) {
            $signature = '';
            openssl_sign($data, $signature, $this->private_key);
            return base64_encode($signature);
        }
        
        // Fallback to HMAC
        return hash_hmac('sha256', $data, $this->get_simple_key());
    }
    
    /**
     * Verify data signature
     *
     * @param string $data Original data
     * @param string $signature Signature to verify
     * @return bool Verification result
     */
    private function verify_signature($data, $signature) {
        if (function_exists('openssl_verify')) {
            $sig_data = base64_decode($signature);
            return openssl_verify($data, $sig_data, $this->public_key) === 1;
        }
        
        // Fallback to HMAC verification
        $expected = hash_hmac('sha256', $data, $this->get_simple_key());
        return hash_equals($expected, $signature);
    }
    
    /**
     * Generate a secure license key
     *
     * @param array $license_info License information
     * @return string Generated license key
     */
    public function generate_license_key($license_info = array()) {
        // Include hardware fingerprint in license
        $fingerprint = new Insurance_CRM_Hardware_Fingerprint();
        $hw_fingerprint = $fingerprint->generate_fingerprint();
        
        $license_data = array(
            'domain' => parse_url(home_url(), PHP_URL_HOST),
            'created' => time(),
            'hardware_fingerprint' => $hw_fingerprint,
            'license_type' => isset($license_info['type']) ? $license_info['type'] : 'trial',
            'expiry' => isset($license_info['expiry']) ? $license_info['expiry'] : 0,
            'features' => isset($license_info['features']) ? $license_info['features'] : array('basic'),
            'version' => '1.1.3'
        );
        
        return $this->encrypt_license($license_data);
    }
    
    /**
     * Validate a license key
     *
     * @param string $license_key License key to validate
     * @return array Validation result
     */
    public function validate_license_key($license_key) {
        $license_data = $this->decrypt_license($license_key);
        
        if (!$license_data) {
            return array(
                'valid' => false,
                'reason' => 'invalid_format',
                'message' => 'Lisans anahtarı geçersiz format'
            );
        }
        
        // Check domain
        $current_domain = parse_url(home_url(), PHP_URL_HOST);
        if ($license_data['domain'] !== $current_domain) {
            return array(
                'valid' => false,
                'reason' => 'domain_mismatch',
                'message' => 'Lisans anahtarı bu domain için geçerli değil',
                'licensed_domain' => $license_data['domain'],
                'current_domain' => $current_domain
            );
        }
        
        // Check expiry
        if (isset($license_data['expiry']) && $license_data['expiry'] > 0) {
            if (time() > $license_data['expiry']) {
                return array(
                    'valid' => false,
                    'reason' => 'expired',
                    'message' => 'Lisans süresi dolmuş',
                    'expiry_date' => date('Y-m-d H:i:s', $license_data['expiry'])
                );
            }
        }
        
        // Validate hardware fingerprint (with tolerance)
        if (isset($license_data['hardware_fingerprint'])) {
            $fingerprint = new Insurance_CRM_Hardware_Fingerprint();
            $validation = $fingerprint->validate_fingerprint();
            
            if (!$validation['valid'] && $validation['similarity'] < 50) {
                return array(
                    'valid' => false,
                    'reason' => 'hardware_mismatch',
                    'message' => 'Hardware kimlik doğrulaması başarısız',
                    'similarity' => $validation['similarity']
                );
            }
        }
        
        return array(
            'valid' => true,
            'license_data' => $license_data,
            'message' => 'Lisans geçerli'
        );
    }
    
    /**
     * Get encryption service debug information
     *
     * @return array Debug information
     */
    public function get_debug_info() {
        return array(
            'openssl_available' => function_exists('openssl_public_encrypt'),
            'has_public_key' => !empty($this->public_key),
            'has_private_key' => !empty($this->private_key),
            'public_key_length' => strlen($this->public_key),
            'simple_key_hash' => substr($this->get_simple_key(), 0, 8) . '...'
        );
    }
}
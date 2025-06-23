<?php
/**
 * Müşteri Temsilcisi Giriş İşlemleri
 */

class Insurance_CRM_Auth {
    
    /**
     * Sınıf örneklemesini başlat
     */
    public function __construct() {
        // Login form işlemini dinle
        add_action('init', array($this, 'process_login'));
        
        // WordPress giriş form kontrolünü ekle
        add_filter('authenticate', array($this, 'check_representative_status'), 30, 3);
    }
    
    /**
     * Login formunu işle
     */
    public function process_login() {
        // Login form gönderildi mi kontrol et
        if (isset($_POST['insurance_crm_login']) && isset($_POST['insurance_crm_login_nonce'])) {
            
            // Nonce doğrulama
            if (!wp_verify_nonce($_POST['insurance_crm_login_nonce'], 'insurance_crm_login')) {
                wp_die('Güvenlik doğrulaması başarısız oldu. Lütfen sayfayı yenileyip tekrar deneyin.');
            }
            
            // Kullanıcı adı ve şifreyi al
            $username = isset($_POST['username']) ? sanitize_user($_POST['username']) : '';
            $password = isset($_POST['password']) ? $_POST['password'] : '';
            
            // Hatırla seçeneği
            $remember = isset($_POST['remember']) ? true : false;
            
            // Kullanıcı doğrulama
            $user = wp_authenticate($username, $password);
            
            // Hata kontrolü
            if (is_wp_error($user)) {
                // Log failed login attempt
                $this->log_failed_login_attempt($username);
                
                // Hata yönlendirmesi
                wp_redirect(add_query_arg('login', 'failed', wp_get_referer()));
                exit;
            } else {
                // Başarılı giriş
                wp_set_auth_cookie($user->ID, $remember);
                
                // Dashboard'a yönlendir
                wp_redirect(home_url('/temsilci-paneli/'));
                exit;
            }
        }
    }
    
    /**
     * Müşteri temsilcisi durumunu kontrol et
     */
    public function check_representative_status($user, $username, $password) {
        // Kullanıcı zaten doğrulanmış mı kontrol et
        if (is_wp_error($user) || empty($username) || empty($password)) {
            // Don't log here - wp_login_failed hook will handle logging
            return $user;
        }
        
        // Check user+IP blocking first, before any other authentication
        $ip_address = $this->get_client_ip();
        $this->check_user_ip_blocking($username, $ip_address);
        
        // Also check for manual IP blocks (legacy functionality)
        $this->check_ip_blocking($ip_address);
        
        // Kullanıcı müşteri temsilcisi mi kontrol et
        if (in_array('insurance_representative', (array)$user->roles)) {
            
            // Veritabanında temsilcinin durumunu kontrol et
            global $wpdb;
            $status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}insurance_crm_representatives 
                 WHERE user_id = %d",
                $user->ID
            ));
            
            // Eğer temsilci pasif ise giriş izni verme
            if ($status !== 'active') {
                // Return error - wp_login_failed hook will handle logging
                return new WP_Error('account_inactive', 'Hesabınız pasif durumda. Lütfen yöneticiniz ile iletişime geçin.');
            }
        }
        
        return $user;
    }
    
    /**
     * Check if user+IP combination is blocked due to retry limits
     */
    private function check_user_ip_blocking($username, $ip_address) {
        // Load user logger
        require_once plugin_dir_path(__FILE__) . 'logging/class-insurance-crm-user-logger.php';
        $user_logger = new Insurance_CRM_User_Logger();
        
        // Try to get user ID
        $user = get_user_by('login', $username);
        $user_id = $user ? $user->ID : 0;
        
        $block_info = $user_logger->is_user_ip_blocked($user_id, $ip_address);
        
        if ($block_info && $block_info['blocked']) {
            $remaining_minutes = ceil($block_info['remaining_time'] / 60);
            
            // Return appropriate error message for user+IP combination block
            wp_die(
                sprintf(
                    'Bu kullanıcı adı için bu IP adresinden giriş yapmak geçici olarak engellenmiştir. Lütfen %d dakika sonra tekrar deneyin.',
                    $remaining_minutes
                ),
                'Giriş Engellendi',
                array('response' => 429)
            );
        }
    }
    
    /**
     * Check if IP is blocked due to manual IP blocking (legacy functionality)
     */
    private function check_ip_blocking($ip_address) {
        // Load user logger
        require_once plugin_dir_path(__FILE__) . 'logging/class-insurance-crm-user-logger.php';
        $user_logger = new Insurance_CRM_User_Logger();
        
        $block_info = $user_logger->is_ip_blocked($ip_address);
        
        if ($block_info && $block_info['blocked']) {
            $remaining_minutes = ceil($block_info['remaining_time'] / 60);
            
            // Return appropriate error message for IP-wide block
            wp_die(
                sprintf(
                    'Bu IP adresinden giriş yapmak geçici olarak engellenmiştir. Lütfen %d dakika sonra tekrar deneyin.',
                    $remaining_minutes
                ),
                'IP Engellendi',
                array('response' => 429)
            );
        }
    }
    
    /**
     * Log failed login attempt
     */
    private function log_failed_login_attempt($username) {
        // Load user logger
        require_once plugin_dir_path(__FILE__) . 'logging/class-insurance-crm-user-logger.php';
        $user_logger = new Insurance_CRM_User_Logger();
        
        $user_logger->log_failed_login($username);
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip() {
        // Use the same IP detection method as the logger to ensure consistency
        require_once plugin_dir_path(__FILE__) . 'logging/class-insurance-crm-logger.php';
        return Insurance_CRM_Logger::get_client_ip();
    }
}

// Sınıfı başlat
new Insurance_CRM_Auth();
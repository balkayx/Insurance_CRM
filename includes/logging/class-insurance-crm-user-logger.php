<?php
/**
 * User logging class for Insurance CRM
 *
 * @package Insurance_CRM
 * @subpackage Insurance_CRM/includes/logging
 */

if (!defined('WPINC')) {
    die;
}

/**
 * User logging class
 */
class Insurance_CRM_User_Logger {
    
    /**
     * User session tracking
     */
    private $session_starts = array();
    
    /**
     * Constructor
     */
    public function __construct() {
        // Track session starts for duration calculation
        add_action('init', array($this, 'track_session_start'));
        
        // Note: Login/logout hooks are registered in Insurance_CRM_Logger
        // to avoid duplicate hook registrations
    }
    
    /**
     * Track session start for duration calculation
     */
    public function track_session_start() {
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            if (!isset($this->session_starts[$user_id])) {
                $this->session_starts[$user_id] = time();
            }
        }
    }
    
    /**
     * Log user login
     */
    public function log_login($user_login, $user) {
        // Only log insurance representatives
        if (!in_array('insurance_representative', (array)$user->roles) && !current_user_can('manage_insurance_crm')) {
            return;
        }
        
        global $wpdb;
        
        $ip_address = Insurance_CRM_Logger::get_client_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $browser_info = Insurance_CRM_Logger::get_browser_info($user_agent);
        
        // Try to get location from IP (basic implementation)
        $location = $this->get_location_from_ip($ip_address);
        
        $wpdb->insert(
            $wpdb->prefix . 'insurance_user_logs',
            array(
                'user_id' => $user->ID,
                'action' => 'login',
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'browser' => $browser_info['browser'],
                'device' => $browser_info['device'],
                'location' => $location,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        // Track session start
        $this->session_starts[$user->ID] = time();
    }
    
    /**
     * Log user logout
     */
    public function log_logout($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) {
            return;
        }
        
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return;
        }
        
        // Only log insurance representatives
        if (!in_array('insurance_representative', (array)$user->roles) && !user_can($user_id, 'manage_insurance_crm')) {
            return;
        }
        
        global $wpdb;
        
        $ip_address = Insurance_CRM_Logger::get_client_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $browser_info = Insurance_CRM_Logger::get_browser_info($user_agent);
        
        // Calculate session duration
        $session_duration = null;
        if (isset($this->session_starts[$user_id])) {
            $session_duration = time() - $this->session_starts[$user_id];
            unset($this->session_starts[$user_id]);
        }
        
        $location = $this->get_location_from_ip($ip_address);
        
        $wpdb->insert(
            $wpdb->prefix . 'insurance_user_logs',
            array(
                'user_id' => $user_id,
                'action' => 'logout',
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'browser' => $browser_info['browser'],
                'device' => $browser_info['device'],
                'location' => $location,
                'session_duration' => $session_duration,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s')
        );
    }
    
    /**
     * Log failed login attempt
     */
    public function log_failed_login($username) {
        global $wpdb;
        
        $ip_address = Insurance_CRM_Logger::get_client_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $browser_info = Insurance_CRM_Logger::get_browser_info($user_agent);
        $location = $this->get_location_from_ip($ip_address);
        
        // Try to get user ID if username exists
        $user = get_user_by('login', $username);
        $user_id = $user ? $user->ID : 0;
        
        $wpdb->insert(
            $wpdb->prefix . 'insurance_user_logs',
            array(
                'user_id' => $user_id,
                'action' => 'failed_login',
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'browser' => $browser_info['browser'],
                'device' => $browser_info['device'],
                'location' => $location,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        // Check for suspicious activity (multiple failed attempts from same user+IP combination)
        $this->check_suspicious_activity($ip_address, $username);
    }
    
    /**
     * Check for suspicious login activity - now checks per user+IP combination
     */
    private function check_suspicious_activity($ip_address, $username = null) {
        global $wpdb;
        
        // If username is provided, check for user+IP combination
        if ($username) {
            $user = get_user_by('login', $username);
            $user_id = $user ? $user->ID : 0;
            
            // Count failed login attempts for this user+IP combination in the last hour
            $failed_attempts = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_user_logs 
                 WHERE action = 'failed_login' 
                 AND user_id = %d 
                 AND ip_address = %s 
                 AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                $user_id,
                $ip_address
            ));
            
            // If more than 5 failed attempts for this user+IP, block this specific combination
            if ($failed_attempts >= 5) {
                // Block the user+IP combination for 15 minutes
                $this->block_user_ip_retry($user_id, $ip_address, $failed_attempts, $username);
                
                // Get system logger instance
                require_once plugin_dir_path(__FILE__) . 'class-insurance-crm-system-logger.php';
                $system_logger = new Insurance_CRM_System_Logger();
                
                $system_logger->log_system_action(
                    'suspicious_activity',
                    'security',
                    null,
                    null,
                    array(
                        'ip_address' => $ip_address,
                        'user_id' => $user_id,
                        'username' => $username,
                        'failed_attempts' => $failed_attempts,
                        'time_window' => '1 hour',
                        'action_taken' => 'User+IP combination blocked for 15 minutes'
                    ),
                    "Suspicious activity detected: {$failed_attempts} failed login attempts for user '{$username}' from IP {$ip_address} in the last hour - User+IP combination blocked"
                );
                
                // Send notification to admin
                $this->send_security_notification($ip_address, $failed_attempts, $username);
            }
        }
    }
    
    /**
     * Send security notification to admin
     */
    private function send_security_notification($ip_address, $failed_attempts, $username = null) {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        $subject = "[{$site_name}] Güvenlik Uyarısı - Şüpheli Giriş Aktivitesi";
        
        $message = "Merhaba,\n\n";
        $message .= "Insurance CRM sisteminde şüpheli giriş aktivitesi tespit edildi.\n\n";
        $message .= "Detaylar:\n";
        $message .= "- IP Adresi: {$ip_address}\n";
        if ($username) {
            $message .= "- Kullanıcı Adı: {$username}\n";
        }
        $message .= "- Başarısız Giriş Denemeleri: {$failed_attempts}\n";
        $message .= "- Zaman Aralığı: Son 1 saat\n";
        $message .= "- Tarih: " . current_time('d.m.Y H:i:s') . "\n\n";
        if ($username) {
            $message .= "Bu kullanıcı+IP kombinasyonu 15 dakika süreyle engellenmiştir.\n\n";
        } else {
            $message .= "Bu IP adresi 15 dakika süreyle engellenmiştir.\n\n";
        }
        $message .= "Lütfen bu aktiviteyi inceleyin ve gerekli güvenlik önlemlerini alın.\n\n";
        $message .= "Saygılarımızla,\n";
        $message .= "{$site_name} Güvenlik Sistemi";
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Get location from IP address (basic implementation)
     * In production, you might want to use a proper geolocation service
     */
    private function get_location_from_ip($ip_address) {
        // Basic implementation - just check if it's a local IP
        if (filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return 'Local Network';
        }
        
        // For now, return Unknown. In production, integrate with a geolocation service
        return 'Unknown';
    }
    
    /**
     * Get user login statistics
     */
    public function get_user_login_stats($user_id, $days = 30) {
        global $wpdb;
        
        $start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(CASE WHEN action = 'login' THEN 1 END) as total_logins,
                COUNT(CASE WHEN action = 'failed_login' THEN 1 END) as failed_attempts,
                AVG(session_duration) as avg_session_duration,
                MAX(created_at) as last_login
             FROM {$wpdb->prefix}insurance_user_logs 
             WHERE user_id = %d 
             AND created_at >= %s",
            $user_id,
            $start_date
        ));
        
        return $stats;
    }
    
    /**
     * Get failed login attempts by user+IP combinations
     */
    public function get_failed_attempts_by_user_ip($hours = 24, $limit = 50) {
        global $wpdb;
        
        $start_time = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT 
                ul.user_id,
                ul.ip_address,
                u.user_login as username,
                COUNT(*) as attempts, 
                MAX(ul.created_at) as last_attempt
             FROM {$wpdb->prefix}insurance_user_logs ul
             LEFT JOIN {$wpdb->users} u ON ul.user_id = u.ID
             WHERE ul.action = 'failed_login' 
             AND ul.created_at >= %s
             GROUP BY ul.user_id, ul.ip_address 
             ORDER BY attempts DESC, last_attempt DESC
             LIMIT %d",
            $start_time,
            $limit
        ));
    }
    
    /**
     * Get failed login attempts by IP (legacy method for manual IP blocks)
     */
    public function get_failed_attempts_by_ip($hours = 24, $limit = 10) {
        global $wpdb;
        
        $start_time = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT ip_address, COUNT(*) as attempts, MAX(created_at) as last_attempt
             FROM {$wpdb->prefix}insurance_user_logs 
             WHERE action = 'failed_login' 
             AND created_at >= %s
             GROUP BY ip_address 
             ORDER BY attempts DESC 
             LIMIT %d",
            $start_time,
            $limit
        ));
    }
    
    /**
     * Block user+IP combination for retry attempts
     */
    private function block_user_ip_retry($user_id, $ip_address, $failed_attempts, $username = '') {
        $retry_key = 'insurance_retry_user_ip_' . md5($user_id . '_' . $ip_address);
        
        // Block for 15 minutes (900 seconds)
        $block_duration = 15 * 60;
        $block_until = time() + $block_duration;
        
        $retry_data = array(
            'user_id' => $user_id,
            'username' => $username,
            'ip_address' => $ip_address,
            'failed_attempts' => $failed_attempts,
            'block_until' => $block_until,
            'blocked_at' => time()
        );
        
        // Store the block information as a transient
        set_transient($retry_key, $retry_data, $block_duration);
    }
    
    /**
     * Block IP address for retry attempts (legacy method for backward compatibility)
     */
    private function block_ip_retry($ip_address, $failed_attempts) {
        $retry_key = 'insurance_retry_limit_' . md5($ip_address);
        
        // Block for 15 minutes (900 seconds)
        $block_duration = 15 * 60;
        $block_until = time() + $block_duration;
        
        $retry_data = array(
            'ip_address' => $ip_address,
            'failed_attempts' => $failed_attempts,
            'block_until' => $block_until,
            'blocked_at' => time()
        );
        
        // Store the block information as a transient
        set_transient($retry_key, $retry_data, $block_duration);
    }
    
    /**
     * Check if user+IP combination is currently blocked
     */
    public function is_user_ip_blocked($user_id, $ip_address) {
        $retry_key = 'insurance_retry_user_ip_' . md5($user_id . '_' . $ip_address);
        $retry_data = get_transient($retry_key);
        
        // If no transient data found, also check options table directly for cache issues
        if ($retry_data === false) {
            global $wpdb;
            $option_value = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                '_transient_' . $retry_key
            ));
            
            if ($option_value) {
                $retry_data = maybe_unserialize($option_value);
            }
        }
        
        if ($retry_data === false || !is_array($retry_data)) {
            return false;
        }
        
        // Check if block time has expired
        if (time() >= $retry_data['block_until']) {
            delete_transient($retry_key);
            // Also delete from options table directly
            global $wpdb;
            $wpdb->delete(
                $wpdb->options,
                array('option_name' => '_transient_' . $retry_key),
                array('%s')
            );
            return false;
        }
        
        return array(
            'blocked' => true,
            'remaining_time' => $retry_data['block_until'] - time(),
            'failed_attempts' => $retry_data['failed_attempts'],
            'user_id' => $retry_data['user_id'],
            'username' => $retry_data['username'] ?? ''
        );
    }

    /**
     * Check if IP address is currently blocked (legacy method for backward compatibility and manual IP blocks)
     */
    public function is_ip_blocked($ip_address) {
        $retry_key = 'insurance_retry_limit_' . md5($ip_address);
        $retry_data = get_transient($retry_key);
        
        // If no transient data found, also check options table directly for cache issues
        if ($retry_data === false) {
            global $wpdb;
            $option_value = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                '_transient_' . $retry_key
            ));
            
            if ($option_value) {
                $retry_data = maybe_unserialize($option_value);
            }
        }
        
        if ($retry_data === false || !is_array($retry_data)) {
            return false;
        }
        
        // Check if block time has expired
        if (time() >= $retry_data['block_until']) {
            delete_transient($retry_key);
            // Also delete from options table directly
            global $wpdb;
            $wpdb->delete(
                $wpdb->options,
                array('option_name' => '_transient_' . $retry_key),
                array('%s')
            );
            return false;
        }
        
        return array(
            'blocked' => true,
            'remaining_time' => $retry_data['block_until'] - time(),
            'failed_attempts' => $retry_data['failed_attempts']
        );
    }
    
    /**
     * Get remaining block time for IP
     */
    public function get_ip_block_remaining_time($ip_address) {
        $block_info = $this->is_ip_blocked($ip_address);
        
        if (!$block_info) {
            return 0;
        }
        
        return $block_info['remaining_time'];
    }
    
    /**
     * Log logout for current user (wrapper for WordPress hook)
     */
    public function log_current_user_logout() {
        $user_id = get_current_user_id();
        if ($user_id) {
            $this->log_logout($user_id);
        }
    }
}
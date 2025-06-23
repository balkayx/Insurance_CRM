<?php
/**
 * Main logging class for Insurance CRM
 *
 * @package Insurance_CRM
 * @subpackage Insurance_CRM/includes/logging
 */

if (!defined('WPINC')) {
    die;
}

/**
 * Main logging class
 */
class Insurance_CRM_Logger {
    
    /**
     * Logger instances
     */
    private $user_logger;
    private $system_logger;
    
    /**
     * Initialize the logger
     */
    public function __construct() {
        $this->load_dependencies();
        $this->init_loggers();
        $this->setup_hooks();
    }
    
    /**
     * Load required dependencies
     */
    private function load_dependencies() {
        require_once plugin_dir_path(__FILE__) . 'class-insurance-crm-user-logger.php';
        require_once plugin_dir_path(__FILE__) . 'class-insurance-crm-system-logger.php';
        require_once plugin_dir_path(__FILE__) . 'class-insurance-crm-log-viewer.php';
    }
    
    /**
     * Initialize logger instances
     */
    private function init_loggers() {
        $this->user_logger = new Insurance_CRM_User_Logger();
        $this->system_logger = new Insurance_CRM_System_Logger();
    }
    
    /**
     * Setup WordPress hooks
     */
    private function setup_hooks() {
        // User login/logout hooks
        add_action('wp_login', array($this->user_logger, 'log_login'), 10, 2);
        add_action('wp_logout', array($this->user_logger, 'log_logout'), 10, 1);
        add_action('wp_login_failed', array($this->user_logger, 'log_failed_login'), 10, 1);
        
        // System operation hooks
        add_action('insurance_crm_customer_created', array($this->system_logger, 'log_customer_created'), 10, 2);
        add_action('insurance_crm_customer_updated', array($this->system_logger, 'log_customer_updated'), 10, 3);
        add_action('insurance_crm_customer_deleted', array($this->system_logger, 'log_customer_deleted'), 10, 2);
        
        add_action('insurance_crm_policy_created', array($this->system_logger, 'log_policy_created'), 10, 2);
        add_action('insurance_crm_policy_updated', array($this->system_logger, 'log_policy_updated'), 10, 3);
        add_action('insurance_crm_policy_deleted', array($this->system_logger, 'log_policy_deleted'), 10, 2);
        
        add_action('insurance_crm_task_created', array($this->system_logger, 'log_task_created'), 10, 2);
        add_action('insurance_crm_task_updated', array($this->system_logger, 'log_task_updated'), 10, 3);
        add_action('insurance_crm_task_deleted', array($this->system_logger, 'log_task_deleted'), 10, 2);
        
        // File upload/delete hooks
        add_action('insurance_crm_file_uploaded', array($this->system_logger, 'log_file_uploaded'), 10, 2);
        add_action('insurance_crm_file_deleted', array($this->system_logger, 'log_file_deleted'), 10, 2);
        
        // Auto cleanup logs older than 90 days
        add_action('insurance_crm_daily_cron', array($this, 'cleanup_old_logs'));
        
        // Track user activity for active users functionality
        add_action('wp_loaded', array($this, 'track_user_activity'));
    }
    
    /**
     * Get user logger instance
     */
    public function get_user_logger() {
        return $this->user_logger;
    }
    
    /**
     * Get system logger instance
     */
    public function get_system_logger() {
        return $this->system_logger;
    }
    
    /**
     * Cleanup logs older than specified days
     */
    public function cleanup_old_logs($days = 90) {
        global $wpdb;
        
        $user_logs_table = $wpdb->prefix . 'insurance_user_logs';
        $system_logs_table = $wpdb->prefix . 'insurance_system_logs';
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // Delete old user logs
        $deleted_user_logs = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$user_logs_table} WHERE created_at < %s",
            $cutoff_date
        ));
        
        // Delete old system logs
        $deleted_system_logs = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$system_logs_table} WHERE created_at < %s",
            $cutoff_date
        ));
        
        // Log the cleanup action
        if ($deleted_user_logs || $deleted_system_logs) {
            $this->system_logger->log_system_action(
                'log_cleanup',
                'logs',
                null,
                null,
                array(
                    'deleted_user_logs' => $deleted_user_logs,
                    'deleted_system_logs' => $deleted_system_logs,
                    'cutoff_date' => $cutoff_date
                ),
                "Automated log cleanup: deleted {$deleted_user_logs} user logs and {$deleted_system_logs} system logs older than {$days} days"
            );
        }
    }
    
    /**
     * Get current user IP address
     */
    public static function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (array_map('trim', explode(',', $_SERVER[$key])) as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Get browser information from user agent
     */
    public static function get_browser_info($user_agent) {
        $browser = 'Unknown';
        $device = 'Unknown';
        
        if (empty($user_agent)) {
            return array('browser' => $browser, 'device' => $device);
        }
        
        // Common browsers
        $browsers = array(
            'Firefox' => 'Firefox',
            'Chrome' => 'Chrome',
            'Safari' => 'Safari',
            'Edge' => 'Edge',
            'Opera' => 'Opera',
            'IE' => 'MSIE|Trident'
        );
        
        foreach ($browsers as $name => $pattern) {
            if (preg_match("/{$pattern}/i", $user_agent)) {
                $browser = $name;
                break;
            }
        }
        
        // Device detection
        if (preg_match('/Mobile|Android|iPhone|iPad/i', $user_agent)) {
            $device = 'Mobile';
        } elseif (preg_match('/Tablet/i', $user_agent)) {
            $device = 'Tablet';
        } else {
            $device = 'Desktop';
        }
        
        return array('browser' => $browser, 'device' => $device);
    }
    
    /**
     * Track user activity for active users display
     */
    public function track_user_activity() {
        // Only track logged in users
        if (!is_user_logged_in()) {
            return;
        }
        
        $user_id = get_current_user_id();
        $user = wp_get_current_user();
        
        // Only track insurance representatives and admins
        if (!in_array('insurance_representative', (array)$user->roles) && !current_user_can('manage_insurance_crm')) {
            return;
        }
        
        // Update last activity timestamp
        update_user_meta($user_id, '_user_last_activity', time());
    }
}
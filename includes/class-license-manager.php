<?php
/**
 * License Manager Class
 * 
 * Main license management functionality
 * 
 * @package Insurance_CRM
 * @author  Anadolu Birlik
 * @since   1.1.3
 */

if (!defined('ABSPATH')) {
    exit;
}

class Insurance_CRM_License_Manager {
    
    /**
     * Plugin version
     */
    private $version;
    
    /**
     * License API instance
     */
    public $license_api;
    
    /**
     * Grace period in days
     */
    private $grace_period_days;

    /**
     * Constructor
     * 
     * @param string $version Plugin version
     */
    public function __construct($version) {
        $this->version = $version;
        $this->grace_period_days = 7; // 1 week grace period
        
        // Initialize license API
        if (class_exists('Insurance_CRM_License_API')) {
            $this->license_api = new Insurance_CRM_License_API();
        }
        
        // Hook into WordPress
        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'handle_license_form_submission'));
        add_action('admin_notices', array($this, 'show_license_notices'));
        
        // AJAX handlers
        add_action('wp_ajax_validate_license', array($this, 'ajax_validate_license'));
        add_action('wp_ajax_nopriv_validate_license', array($this, 'ajax_validate_license'));
        add_action('wp_login', array($this, 'validate_license_on_login'), 10, 2);
        
        // Periodic license check (every 4 hours)
        add_action('insurance_crm_periodic_license_check', array($this, 'perform_periodic_license_check'));
        if (!wp_next_scheduled('insurance_crm_periodic_license_check')) {
            wp_schedule_event(time(), 'insurance_crm_4_hours', 'insurance_crm_periodic_license_check');
        }
        
        // Add custom cron schedule for 4 hours
        add_filter('cron_schedules', array($this, 'add_custom_cron_schedules'));
    }

    /**
     * Initialize license manager
     */
    public function init() {
        // Check license status if needed
        $this->maybe_check_license_status();
    }

    /**
     * Check if license is valid
     * 
     * @return bool True if license is valid
     */
    public function is_license_valid() {
        // Check if license bypass is enabled
        if ($this->license_api && $this->license_api->is_license_bypassed()) {
            return true;
        }

        $license_key = get_option('insurance_crm_license_key', '');
        if (empty($license_key)) {
            return false;
        }

        $license_status = get_option('insurance_crm_license_status', 'inactive');
        return $license_status === 'active';
    }

    /**
     * Check if license is in grace period
     * 
     * @return bool True if in grace period
     */
    public function is_in_grace_period() {
        $license_status = get_option('insurance_crm_license_status', 'inactive');
        if ($license_status !== 'expired') {
            return false;
        }

        $license_expiry = get_option('insurance_crm_license_expiry', '');
        if (empty($license_expiry)) {
            return false;
        }

        $expiry_date = strtotime($license_expiry);
        $grace_period_end = $expiry_date + ($this->grace_period_days * 24 * 60 * 60);
        
        $is_in_grace = time() <= $grace_period_end;
        
        // Update can_access_data logic to include grace period
        return $is_in_grace;
    }

    /**
     * Get remaining grace period days
     * 
     * @return int Days remaining in grace period
     */
    public function get_grace_period_days_remaining() {
        if (!$this->is_in_grace_period()) {
            return 0;
        }

        $license_expiry = get_option('insurance_crm_license_expiry', '');
        $expiry_date = strtotime($license_expiry);
        $grace_period_end = $expiry_date + ($this->grace_period_days * 24 * 60 * 60);
        
        return ceil(($grace_period_end - time()) / (24 * 60 * 60));
    }

    /**
     * Check if license allows access to data
     * 
     * @return bool True if data access is allowed
     */
    public function can_access_data() {
        // Check if access is explicitly restricted (for expired licenses past grace period)
        if (get_option('insurance_crm_license_access_restricted', false)) {
            return false;
        }
        
        // Allow access for valid licenses or those in grace period
        return $this->is_license_valid() || $this->is_in_grace_period();
    }

    /**
     * Get current user count
     * 
     * @return int Number of active users
     */
    public function get_current_user_count() {
        global $wpdb;
        
        // Sadece aktif temsilcileri say
        $active_representatives = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}insurance_crm_representatives 
            WHERE status = %s
        ", 'active'));
        
        // Sadece CRM kullanıcıları olan admin'leri say
        $admin_users = get_users(array(
            'role' => 'administrator',
            'meta_query' => array(
                array(
                    'key' => 'wp_capabilities',
                    'value' => 'insurance_representative',
                    'compare' => 'LIKE'
                )
            )
        ));
        
        $total_active_users = intval($active_representatives) + count($admin_users);
        
        return $total_active_users;
    }

    /**
     * Check if user limit is exceeded
     * 
     * @return bool True if limit is exceeded
     */
    public function is_user_limit_exceeded() {
        $user_limit = get_option('insurance_crm_license_user_limit', 5);
        $current_users = $this->get_current_user_count();
        
        return $current_users > $user_limit;
    }

    /**
     * Validate license (without saving)
     * 
     * @param string $license_key License key to validate
     * @return bool True if license is valid
     */
    public function validate_license($license_key) {
        if (!$this->license_api) {
            return false;
        }

        $response = $this->license_api->validate_license($license_key);
        
        // Handle WP_Error objects
        if (is_object($response) && get_class($response) === 'WP_Error') {
            return false;
        }
        
        return is_array($response) && $response['status'] === 'active';
    }

    /**
     * Activate license
     * 
     * @param string $license_key License key to activate
     * @return array Result of activation
     */
    public function activate_license($license_key) {
        if (!$this->license_api) {
            return array(
                'success' => false,
                'message' => 'Lisans API sınıfı yüklenemedi'
            );
        }

        $response = $this->license_api->validate_license($license_key);
        
        // Handle WP_Error objects
        if (is_object($response) && get_class($response) === 'WP_Error') {
            // Deactivate any existing license on validation failure
            $this->deactivate_license_completely();
            return array(
                'success' => false,
                'message' => 'Lisans doğrulaması başarısız: ' . $response->get_error_message()
            );
        }
        
        if (is_array($response) && $response['status'] === 'active') {
            // Save license information
            update_option('insurance_crm_license_key', $license_key);
            update_option('insurance_crm_license_status', 'active');
            update_option('insurance_crm_license_type', $response['license_type'] ?? 'monthly');
            update_option('insurance_crm_license_package', $response['license_package'] ?? '');
            update_option('insurance_crm_license_type_description', $response['license_type_description'] ?? '');
            update_option('insurance_crm_license_expiry', $response['expires_on'] ?? '');
            update_option('insurance_crm_license_user_limit', $response['user_limit'] ?? 5);
            update_option('insurance_crm_license_modules', $response['modules'] ?? array());
            update_option('insurance_crm_license_access_restricted', false);
            update_option('insurance_crm_license_last_check', current_time('mysql'));
            
            error_log('[LISANS DEBUG] License activated successfully: ' . $license_key);
            
            return array(
                'success' => true,
                'message' => 'Lisans başarıyla etkinleştirildi'
            );
        } else {
            // Deactivate any existing license on validation failure
            $this->deactivate_license_completely();
            
            $message = 'Lisans etkinleştirilemedi - lisans anahtarı geçersiz veya sunucuda bulunamadı';
            if (is_array($response) && isset($response['message'])) {
                $message = $response['message'];
            }
            
            error_log('[LISANS DEBUG] License activation failed: ' . $message);
            
            return array(
                'success' => false,
                'message' => $message
            );
        }
    }

    /**
     * Deactivate license
     * 
     * @return array Result of deactivation
     */
    public function deactivate_license() {
        update_option('insurance_crm_license_status', 'inactive');
        
        return array(
            'success' => true,
            'message' => 'Lisans devre dışı bırakıldı'
        );
    }

    /**
     * Maybe check license status (if not checked recently)
     */
    private function maybe_check_license_status() {
        $last_check = get_option('insurance_crm_license_last_check', '');
        
        // Check every 4 hours
        if (empty($last_check) || 
            strtotime($last_check) < (time() - 4 * 60 * 60)) {
            $this->perform_license_check();
        }
    }

    /**
     * Perform license status check with enhanced validation
     */
    public function perform_license_check() {
        if (!$this->license_api) {
            return;
        }

        $license_key = get_option('insurance_crm_license_key', '');
        if (empty($license_key)) {
            // No license key, deactivate any existing license
            $this->deactivate_license_completely();
            return;
        }

        $response = $this->license_api->check_license_status($license_key);
        
        // Handle WP_Error objects (communication errors)
        if (is_object($response) && get_class($response) === 'WP_Error') {
            error_log('[LISANS DEBUG] License check communication error: ' . $response->get_error_message());
            // Don't update status on communication error, keep last known status
            return; 
        }
        
        // Handle successful server response
        if (is_array($response) && !isset($response['offline'])) {
            $server_status = $response['status'] ?? 'invalid';
            
            // If server says license is invalid, deleted, or expired, deactivate immediately
            if (in_array($server_status, array('invalid', 'deleted', 'not_found', 'inactive'))) {
                error_log('[LISANS DEBUG] Server reported license as invalid/deleted: ' . $server_status);
                $this->deactivate_license_completely();
                return;
            }
            
            // If server says expired, update status but keep some data for grace period
            if ($server_status === 'expired') {
                update_option('insurance_crm_license_status', 'expired');
                if (isset($response['expires_on'])) {
                    update_option('insurance_crm_license_expiry', $response['expires_on']);
                }
                // Check if grace period has ended
                if (!$this->is_in_grace_period()) {
                    error_log('[LISANS DEBUG] License expired and grace period ended');
                    // Don't completely deactivate, but restrict access
                    update_option('insurance_crm_license_access_restricted', true);
                }
            } else {
                // License is active, update all information
                update_option('insurance_crm_license_status', $server_status);
                update_option('insurance_crm_license_access_restricted', false);
                
                if (isset($response['expires_on'])) {
                    update_option('insurance_crm_license_expiry', $response['expires_on']);
                }
                if (isset($response['user_limit'])) {
                    update_option('insurance_crm_license_user_limit', $response['user_limit']);
                }
                if (isset($response['modules'])) {
                    update_option('insurance_crm_license_modules', $response['modules']);
                }
                if (isset($response['license_type'])) {
                    update_option('insurance_crm_license_type', $response['license_type']);
                }
                if (isset($response['license_package'])) {
                    update_option('insurance_crm_license_package', $response['license_package']);
                }
                if (isset($response['license_type_description'])) {
                    update_option('insurance_crm_license_type_description', $response['license_type_description']);
                }
            }
        }
        
        update_option('insurance_crm_license_last_check', current_time('mysql'));
    }

    /**
     * Daily license check (deprecated, use periodic check)
     */
    public function perform_daily_license_check() {
        $this->perform_license_check();
    }
    
    /**
     * Periodic license check (every 4 hours)
     */
    public function perform_periodic_license_check() {
        error_log('[LISANS DEBUG] Performing periodic license check (4-hour interval)');
        $this->perform_license_check();
    }

    /**
     * Completely deactivate license and clear all data
     */
    public function deactivate_license_completely() {
        error_log('[LISANS DEBUG] Completely deactivating license - clearing all data');
        
        update_option('insurance_crm_license_status', 'inactive');
        update_option('insurance_crm_license_key', '');
        update_option('insurance_crm_license_type', '');
        update_option('insurance_crm_license_package', '');
        update_option('insurance_crm_license_type_description', '');
        update_option('insurance_crm_license_expiry', '');
        update_option('insurance_crm_license_user_limit', 5);
        update_option('insurance_crm_license_modules', array());
        update_option('insurance_crm_license_access_restricted', true);
        update_option('insurance_crm_license_last_check', current_time('mysql'));
    }

    /**
     * Add custom cron schedules
     */
    public function add_custom_cron_schedules($schedules) {
        $schedules['insurance_crm_4_hours'] = array(
            'interval' => 4 * HOUR_IN_SECONDS,
            'display' => __('Every 4 Hours (Insurance CRM License Check)')
        );
        return $schedules;
    }

    /**
     * Validate license on user login
     * 
     * @param string $user_login Username
     * @param WP_User $user User object
     */
    public function validate_license_on_login($user_login, $user) {
        // Only check for insurance users
        if (!in_array('insurance_representative', $user->roles) && 
            !in_array('administrator', $user->roles)) {
            return;
        }

        error_log('[LISANS DEBUG] User login detected, performing license validation: ' . $user_login);
        
        // Perform immediate license check on every login
        $this->perform_license_check();
        
        // If license is invalid or access is restricted, log them out
        if (!$this->can_access_data()) {
            $license_status = get_option('insurance_crm_license_status', 'inactive');
            $is_restricted = get_option('insurance_crm_license_access_restricted', false);
            
            error_log('[LISANS DEBUG] License check failed on login - Status: ' . $license_status . ', Restricted: ' . ($is_restricted ? 'Yes' : 'No'));
            
            // Allow access only to license management for expired/invalid licenses
            if ($license_status !== 'active' && !$this->is_in_grace_period()) {
                // Don't log them out, but they'll be redirected to license page by access control
                error_log('[LISANS DEBUG] User will be restricted to license management only');
            }
        }
    }

    /**
     * Handle license form submission
     */
    public function handle_license_form_submission() {
        if (!isset($_POST['insurance_crm_license_nonce']) || 
            !wp_verify_nonce($_POST['insurance_crm_license_nonce'], 'insurance_crm_license')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $action = sanitize_text_field($_POST['insurance_crm_license_action'] ?? '');
        
        if ($action === 'activate') {
            $license_key = sanitize_text_field($_POST['insurance_crm_license_key'] ?? '');
            $result = $this->activate_license($license_key);
            
            if ($result['success']) {
                add_settings_error('insurance_crm_license', 'license_activated', 
                    $result['message'], 'updated');
            } else {
                add_settings_error('insurance_crm_license', 'license_activation_failed', 
                    $result['message'], 'error');
            }
        } elseif ($action === 'deactivate') {
            $result = $this->deactivate_license();
            add_settings_error('insurance_crm_license', 'license_deactivated', 
                $result['message'], 'updated');
        }
    }

    /**
     * Get license notices for frontend display
     * 
     * @return array Array of notices
     */
    public function get_frontend_license_notices() {
        $notices = array();
        $license_status = get_option('insurance_crm_license_status', 'inactive');
        
        if ($license_status === 'inactive' || $license_status === 'invalid') {
            $notices[] = array(
                'type' => 'warning',
                'message' => '<strong>Insurance CRM:</strong> Lisansınız etkin değil. Lütfen lisansınızı etkinleştirin.'
            );
        } elseif ($license_status === 'expired') {
            if ($this->is_in_grace_period()) {
                $days_remaining = $this->get_grace_period_days_remaining();
                $notices[] = array(
                    'type' => 'error',
                    'message' => '<strong>Insurance CRM:</strong> Lisansınızın süresi dolmuştur. Lütfen ' . $days_remaining . ' gün içinde ödemenizi yaparak yenileyin.'
                );
            } else {
                $notices[] = array(
                    'type' => 'error',
                    'message' => '<strong>Insurance CRM:</strong> Lisansınızın süresi dolmuştur ve ek kullanım süreniz sona ermiştir. Uygulamamızı kullanabilmek için lütfen ödemenizi yapın ve lisansınızı yenileyin.'
                );
            }
        }

        // Check user limit
        if ($this->is_user_limit_exceeded()) {
            $notices[] = array(
                'type' => 'warning',
                'message' => '<strong>Insurance CRM:</strong> Kullanıcı sayısı sınırı aşıldı. Mevcut: ' . $this->get_current_user_count() . ', Limit: ' . get_option('insurance_crm_license_user_limit', 5) . '. Lütfen lisansınızı yükseltin.'
            );
        }

        return $notices;
    }

    /**
     * Show license notices in admin
     */
    public function show_license_notices() {
        // Only show on CRM pages
        if (!isset($_GET['page']) || strpos($_GET['page'], 'insurance-crm') === false) {
            return;
        }

        // Skip on license settings page to avoid duplicate notices
        if ($_GET['page'] === 'insurance-crm-license') {
            return;
        }

        $license_status = get_option('insurance_crm_license_status', 'inactive');
        
        if ($license_status === 'inactive' || $license_status === 'invalid') {
            echo '<div class="notice notice-warning">';
            echo '<p><strong>Insurance CRM:</strong> Lisansınız etkin değil. ';
            echo '<a href="' . admin_url('admin.php?page=insurance-crm-license') . '">Lisans yönetimi sayfasına gidin</a>';
            echo ' ve lisansınızı etkinleştirin.</p>';
            echo '</div>';
        } elseif ($license_status === 'expired') {
            if ($this->is_in_grace_period()) {
                $days_remaining = $this->get_grace_period_days_remaining();
                echo '<div class="notice notice-error">';
                echo '<p><strong>Insurance CRM:</strong> Lisansınızın süresi dolmuştur. ';
                echo 'Ek kullanım süreniz ' . $days_remaining . ' gün. ';
                echo 'Lütfen <a href="' . admin_url('admin.php?page=insurance-crm-license') . '">lisansınızı yenileyin</a>.</p>';
                echo '</div>';
            } else {
                echo '<div class="notice notice-error">';
                echo '<p><strong>Insurance CRM:</strong> Lisansınızın süresi dolmuştur ve ek kullanım süreniz sona ermiştir. ';
                echo 'Uygulamayı kullanabilmek için <a href="' . admin_url('admin.php?page=insurance-crm-license') . '">lisansınızı yenileyin</a>.</p>';
                echo '</div>';
            }
        }

        // Check user limit
        if ($this->is_user_limit_exceeded()) {
            echo '<div class="notice notice-warning">';
            echo '<p><strong>Insurance CRM:</strong> Kullanıcı sayısı sınırı aşıldı. ';
            echo 'Mevcut: ' . $this->get_current_user_count() . ', ';
            echo 'Limit: ' . get_option('insurance_crm_license_user_limit', 5) . '. ';
            echo '<a href="' . admin_url('admin.php?page=insurance-crm-license') . '">Lisansınızı yükseltin</a>.</p>';
            echo '</div>';
        }
    }

    /**
     * Check if module is allowed by license
     * 
     * @param string $module Module name
     * @return bool True if module is allowed
     */
    public function is_module_allowed($module) {
        // If license is bypassed, allow all modules
        if ($this->license_api && $this->license_api->is_license_bypassed()) {
            return true;
        }

        // If no valid license, deny access
        if (!$this->can_access_data()) {
            return false;
        }

        $allowed_modules = get_option('insurance_crm_license_modules', array());
        
        // If no specific modules defined, allow all
        if (empty($allowed_modules)) {
            return true;
        }

        return in_array($module, $allowed_modules);
    }

    /**
     * Get license information for display
     * 
     * @return array License information
     */
    public function get_license_info() {
        return array(
            'key' => get_option('insurance_crm_license_key', ''),
            'status' => get_option('insurance_crm_license_status', 'inactive'),
            'type' => get_option('insurance_crm_license_type', ''),
            'package' => get_option('insurance_crm_license_package', ''),
            'type_description' => get_option('insurance_crm_license_type_description', ''),
            'expiry' => get_option('insurance_crm_license_expiry', ''),
            'user_limit' => get_option('insurance_crm_license_user_limit', 5),
            'modules' => get_option('insurance_crm_license_modules', array()),
            'last_check' => get_option('insurance_crm_license_last_check', ''),
            'current_users' => $this->get_current_user_count(),
            'in_grace_period' => $this->is_in_grace_period(),
            'grace_days_remaining' => $this->get_grace_period_days_remaining()
        );
    }

    /**
     * AJAX handler for license validation
     */
    public function ajax_validate_license() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'validate_license')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }
        
        // Get license key
        $license_key = isset($_POST['license_key']) ? sanitize_text_field($_POST['license_key']) : '';
        
        if (empty($license_key)) {
            wp_send_json_error(array('message' => 'License key is required'));
            return;
        }
        
        // Validate license
        $validation_result = $this->validate_license($license_key);
        
        if ($validation_result) {
            wp_send_json_success(array('message' => 'License validated successfully'));
        } else {
            wp_send_json_error(array('message' => 'License validation failed'));
        }
    }

    /**
     * Cleanup on plugin deactivation
     */
    public static function deactivation_cleanup() {
        wp_clear_scheduled_hook('insurance_crm_daily_license_check');
        wp_clear_scheduled_hook('insurance_crm_periodic_license_check');
    }
}
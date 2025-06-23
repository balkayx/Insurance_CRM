<?php
/**
 * Logging helper functions for Insurance CRM
 *
 * @package Insurance_CRM
 * @subpackage Insurance_CRM/includes
 */

if (!defined('WPINC')) {
    die;
}

/**
 * Initialize the logging system
 */
function insurance_crm_init_logging() {
    require_once plugin_dir_path(__FILE__) . 'logging/class-insurance-crm-logger.php';
    
    // Initialize the main logger
    new Insurance_CRM_Logger();
}
add_action('plugins_loaded', 'insurance_crm_init_logging');

/**
 * Log customer operations
 */
function insurance_crm_log_customer_action($action, $customer_id, $old_data = null, $new_data = null) {
    do_action("insurance_crm_customer_{$action}", $customer_id, $old_data, $new_data);
}

/**
 * Log policy operations
 */
function insurance_crm_log_policy_action($action, $policy_id, $old_data = null, $new_data = null) {
    do_action("insurance_crm_policy_{$action}", $policy_id, $old_data, $new_data);
}

/**
 * Log task operations
 */
function insurance_crm_log_task_action($action, $task_id, $old_data = null, $new_data = null) {
    do_action("insurance_crm_task_{$action}", $task_id, $old_data, $new_data);
}

/**
 * Log file operations
 */
function insurance_crm_log_file_action($action, $file_path, $file_data = null) {
    do_action("insurance_crm_file_{$action}", $file_path, $file_data);
}

/**
 * Get recent activities for dashboard widget
 */
function insurance_crm_get_recent_activities($limit = 10) {
    require_once plugin_dir_path(__FILE__) . 'logging/class-insurance-crm-system-logger.php';
    
    $system_logger = new Insurance_CRM_System_Logger();
    return $system_logger->get_recent_activities($limit);
}

/**
 * Get user activity summary
 */
function insurance_crm_get_user_activity_summary($user_id, $days = 30) {
    require_once plugin_dir_path(__FILE__) . 'logging/class-insurance-crm-system-logger.php';
    
    $system_logger = new Insurance_CRM_System_Logger();
    return $system_logger->get_user_activity_summary($user_id, $days);
}

/**
 * Get login statistics for a user
 */
function insurance_crm_get_user_login_stats($user_id, $days = 30) {
    require_once plugin_dir_path(__FILE__) . 'logging/class-insurance-crm-user-logger.php';
    
    $user_logger = new Insurance_CRM_User_Logger();
    return $user_logger->get_user_login_stats($user_id, $days);
}

/**
 * Add dashboard widget for recent activities
 */
function insurance_crm_add_logging_dashboard_widget() {
    if (current_user_can('manage_insurance_crm')) {
        wp_add_dashboard_widget(
            'insurance_crm_recent_activities',
            'Insurance CRM - Son Aktiviteler',
            'insurance_crm_display_recent_activities_widget'
        );
    }
}
add_action('wp_dashboard_setup', 'insurance_crm_add_logging_dashboard_widget');

/**
 * Display recent activities dashboard widget
 */
function insurance_crm_display_recent_activities_widget() {
    $activities = insurance_crm_get_recent_activities(10);
    
    ?>
    <div class="insurance-crm-activities">
        <style>
        .insurance-crm-activities {
            max-height: 300px;
            overflow-y: auto;
        }
        .activity-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .activity-item:last-child {
            border-bottom: none;
        }
        .activity-action {
            font-weight: 600;
            color: #2271b1;
        }
        .activity-user {
            color: #666;
            font-size: 0.9em;
        }
        .activity-time {
            color: #999;
            font-size: 0.8em;
        }
        .no-activities {
            text-align: center;
            color: #666;
            padding: 20px;
        }
        </style>
        
        <?php if (empty($activities)): ?>
            <div class="no-activities">
                <p>Son 24 saatte aktivite bulunmamaktadır.</p>
            </div>
        <?php else: ?>
            <?php foreach ($activities as $activity): ?>
                <div class="activity-item">
                    <div>
                        <div class="activity-action">
                            <?php echo esc_html(insurance_crm_format_action_name($activity->action)); ?>
                        </div>
                        <div class="activity-user">
                            <?php echo esc_html($activity->user_name ?: 'Sistem'); ?>
                        </div>
                    </div>
                    <div class="activity-time">
                        <?php echo esc_html(human_time_diff(strtotime($activity->created_at), current_time('timestamp'))); ?> önce
                    </div>
                </div>
            <?php endforeach; ?>
            
            <div style="text-align: center; margin-top: 15px;">
                <a href="<?php echo admin_url('admin.php?page=insurance-crm-logs'); ?>" class="button">
                    Tüm Logları Görüntüle
                </a>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Format action name for display
 */
function insurance_crm_format_action_name($action) {
    $actions = array(
        'create_customer' => 'Müşteri Oluşturuldu',
        'update_customer' => 'Müşteri Güncellendi',
        'delete_customer' => 'Müşteri Silindi',
        'create_policy' => 'Poliçe Oluşturuldu',
        'update_policy' => 'Poliçe Güncellendi',
        'delete_policy' => 'Poliçe Silindi',
        'create_task' => 'Görev Oluşturuldu',
        'update_task' => 'Görev Güncellendi',
        'delete_task' => 'Görev Silindi',
        'upload_file' => 'Dosya Yüklendi',
        'delete_file' => 'Dosya Silindi',
        'log_cleanup' => 'Log Temizlendi',
        'suspicious_activity' => 'Şüpheli Aktivite Tespit Edildi'
    );
    
    return $actions[$action] ?? ucfirst(str_replace('_', ' ', $action));
}

/**
 * Handle AJAX requests for log data
 */
function insurance_crm_ajax_get_logs() {
    // Check nonce for security
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'insurance_crm_logs_nonce')) {
        wp_send_json_error('Invalid nonce');
    }
    
    // Check permissions
    if (!current_user_can('manage_insurance_crm')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $tab = sanitize_text_field($_POST['tab'] ?? 'system');
    $page = intval($_POST['page'] ?? 1);
    $per_page = intval($_POST['per_page'] ?? 20);
    
    if ($tab === 'user') {
        global $wpdb;
        
        $offset = ($page - 1) * $per_page;
        
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                ul.*,
                u.display_name as user_name
             FROM {$wpdb->prefix}insurance_user_logs ul
             LEFT JOIN {$wpdb->users} u ON ul.user_id = u.ID
             ORDER BY ul.created_at DESC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_user_logs"
        );
        
    } else {
        require_once plugin_dir_path(__FILE__) . 'logging/class-insurance-crm-system-logger.php';
        
        $system_logger = new Insurance_CRM_System_Logger();
        $offset = ($page - 1) * $per_page;
        
        $logs = $system_logger->search_logs(array(), $per_page, $offset);
        $total = $system_logger->count_logs(array());
    }
    
    wp_send_json_success(array(
        'logs' => $logs,
        'total' => $total,
        'pages' => ceil($total / $per_page)
    ));
}
add_action('wp_ajax_insurance_crm_get_logs', 'insurance_crm_ajax_get_logs');

/**
 * Handle log export requests
 */
function insurance_crm_handle_log_export() {
    if (isset($_GET['page']) && $_GET['page'] === 'insurance-crm-logs' && isset($_GET['export'])) {
        if (!current_user_can('manage_insurance_crm')) {
            wp_die('Insufficient permissions');
        }
        
        require_once plugin_dir_path(__FILE__) . 'logging/class-insurance-crm-log-viewer.php';
        
        $log_viewer = new Insurance_CRM_Log_Viewer();
        $tab = sanitize_text_field($_GET['tab'] ?? 'system');
        
        // Get filters
        $filters = array();
        if (!empty($_GET['start_date'])) {
            $filters['start_date'] = sanitize_text_field($_GET['start_date']);
        }
        if (!empty($_GET['end_date'])) {
            $filters['end_date'] = sanitize_text_field($_GET['end_date']);
        }
        if (!empty($_GET['user_id'])) {
            $filters['user_id'] = intval($_GET['user_id']);
        }
        if (!empty($_GET['action'])) {
            $filters['action'] = sanitize_text_field($_GET['action']);
        }
        if (!empty($_GET['search'])) {
            $filters['search'] = sanitize_text_field($_GET['search']);
        }
        
        $log_viewer->export_logs($tab, $filters);
    }
}
/**
 * Test logging functionality (for debugging)
 */
function insurance_crm_test_logging() {
    if (!current_user_can('manage_insurance_crm')) {
        return;
    }
    
    // Test system logging
    do_action('insurance_crm_customer_created', 999, array(
        'first_name' => 'Test',
        'last_name' => 'Customer',
        'email' => 'test@example.com'
    ));
    
    // Test user logging (simulate login)
    $user = wp_get_current_user();
    if ($user && $user->ID) {
        do_action('wp_login', $user->user_login, $user);
    }
    
    return 'Test logs created successfully';
}

// Add test action for admin (can be removed in production)
add_action('wp_ajax_insurance_crm_test_logs', function() {
    if (!current_user_can('manage_insurance_crm')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $result = insurance_crm_test_logging();
    wp_send_json_success($result);
});

add_action('admin_init', 'insurance_crm_handle_log_export');

/**
 * AJAX handler to reset retry limit for specific user+IP combination
 */
add_action('wp_ajax_insurance_crm_reset_user_ip_retry_limit', 'insurance_crm_handle_reset_user_ip_retry_limit');

function insurance_crm_handle_reset_user_ip_retry_limit() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'insurance_crm_retry_nonce')) {
        wp_send_json_error(array('message' => 'Güvenlik doğrulaması başarısız.'));
    }
    
    // Check permissions - only admins can reset retry limits
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Bu işlem için yetkiniz bulunmuyor.'));
    }
    
    $user_id = intval($_POST['user_id']);
    $ip_address = sanitize_text_field($_POST['ip_address']);
    
    if (empty($user_id) || empty($ip_address)) {
        wp_send_json_error(array('message' => 'Kullanıcı ID ve IP adresi belirtilmemiş.'));
    }
    
    global $wpdb;
    
    // Remove the retry limit transient for this user+IP combination
    $retry_key = 'insurance_retry_user_ip_' . md5($user_id . '_' . $ip_address);
    
    // First delete the transient
    $transient_deleted = delete_transient($retry_key);
    
    // Also delete from options table directly to handle caching issues
    $wpdb->delete(
        $wpdb->options,
        array(
            'option_name' => '_transient_' . $retry_key
        ),
        array('%s')
    );
    
    $wpdb->delete(
        $wpdb->options,
        array(
            'option_name' => '_transient_timeout_' . $retry_key
        ),
        array('%s')
    );
    
    // Clear failed login logs for this user+IP combination to prevent immediate re-blocking
    $deleted_logs = $wpdb->delete(
        $wpdb->prefix . 'insurance_user_logs',
        array(
            'action' => 'failed_login',
            'user_id' => $user_id,
            'ip_address' => $ip_address
        ),
        array('%s', '%d', '%s')
    );
    
    // Verify the transient was actually deleted
    $transient_check = get_transient($retry_key);
    $success = ($transient_check === false);
    
    // Get username for logging
    $user = get_user_by('ID', $user_id);
    $username = $user ? $user->user_login : 'Unknown #' . $user_id;
    
    // Log the reset action with detailed info
    insurance_crm_log_retry_reset($ip_address, 'single_user_ip_with_logs', $deleted_logs, $username);
    
    if ($success) {
        wp_send_json_success(array(
            'message' => sprintf('Kullanıcı %s (%s) için IP adresi %s\'nin retry limiti başarıyla sıfırlandı ve %d failed login kaydı temizlendi.', $username, $user_id, $ip_address, $deleted_logs),
            'debug_info' => array(
                'transient_deleted' => $transient_deleted,
                'logs_deleted' => $deleted_logs,
                'retry_key' => $retry_key
            )
        ));
    } else {
        wp_send_json_error(array(
            'message' => 'Retry limiti sıfırlama işleminde bir sorun oluştu. Lütfen tekrar deneyin.',
            'debug_info' => array(
                'transient_deleted' => $transient_deleted,
                'logs_deleted' => $deleted_logs,
                'retry_key' => $retry_key,
                'transient_still_exists' => ($transient_check !== false)
            )
        ));
    }
}

/**
 * AJAX handler to reset retry limits for all user+IP combinations
 */
add_action('wp_ajax_insurance_crm_reset_all_user_ip_retry_limits', 'insurance_crm_handle_reset_all_user_ip_retry_limits');

function insurance_crm_handle_reset_all_user_ip_retry_limits() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'insurance_crm_retry_nonce')) {
        wp_send_json_error(array('message' => 'Güvenlik doğrulaması başarısız.'));
    }
    
    // Check permissions - only admins can reset retry limits
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Bu işlem için yetkiniz bulunmuyor.'));
    }
    
    global $wpdb;
    
    // Get all user+IP retry limit transients and delete them
    $transients = $wpdb->get_results(
        "SELECT option_name FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_insurance_retry_user_ip_%'"
    );
    
    $count = 0;
    foreach ($transients as $transient) {
        $key = str_replace('_transient_', '', $transient->option_name);
        delete_transient($key);
        $count++;
    }
    
    // Also delete directly from options table to handle caching issues
    $wpdb->query(
        "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_insurance_retry_user_ip_%' 
         OR option_name LIKE '_transient_timeout_insurance_retry_user_ip_%'"
    );
    
    // Also clear all failed login logs to prevent immediate re-blocking
    $deleted_logs = $wpdb->delete(
        $wpdb->prefix . 'insurance_user_logs',
        array('action' => 'failed_login'),
        array('%s')
    );
    
    // Log the bulk reset action with log clearing info
    insurance_crm_log_retry_reset('all', 'bulk_user_ip_with_logs', $count, $deleted_logs);
    
    wp_send_json_success(array(
        'message' => sprintf('Toplam %d kullanıcı+IP kombinasyonunun retry limiti sıfırlandı ve %d failed login kaydı temizlendi.', $count, $deleted_logs),
        'debug_info' => array(
            'transients_processed' => $count,
            'logs_deleted' => $deleted_logs
        )
    ));
}

/**
 * AJAX handler to clear failed login logs for specific user+IP combination
 */
add_action('wp_ajax_insurance_crm_clear_user_ip_failed_logs', 'insurance_crm_handle_clear_user_ip_failed_logs');

function insurance_crm_handle_clear_user_ip_failed_logs() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'insurance_crm_retry_nonce')) {
        wp_send_json_error(array('message' => 'Güvenlik doğrulaması başarısız.'));
    }
    
    // Check permissions - only admins can clear logs
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Bu işlem için yetkiniz bulunmuyor.'));
    }
    
    $user_id = intval($_POST['user_id']);
    $ip_address = sanitize_text_field($_POST['ip_address']);
    
    if (empty($user_id) || empty($ip_address)) {
        wp_send_json_error(array('message' => 'Kullanıcı ID ve IP adresi belirtilmemiş.'));
    }
    
    global $wpdb;
    
    // Delete failed login logs for this user+IP combination
    $deleted = $wpdb->delete(
        $wpdb->prefix . 'insurance_user_logs',
        array(
            'action' => 'failed_login',
            'user_id' => $user_id,
            'ip_address' => $ip_address
        ),
        array('%s', '%d', '%s')
    );
    
    if ($deleted === false) {
        wp_send_json_error(array('message' => 'Loglar silinirken bir hata oluştu.'));
    }
    
    // Also remove any retry limit for this user+IP combination
    $retry_key = 'insurance_retry_user_ip_' . md5($user_id . '_' . $ip_address);
    delete_transient($retry_key);
    
    // Get username for logging
    $user = get_user_by('ID', $user_id);
    $username = $user ? $user->user_login : 'Unknown #' . $user_id;
    
    // Log the clear action
    insurance_crm_log_retry_reset($ip_address, 'clear_user_ip_logs', $deleted, $username);
    
    wp_send_json_success(array(
        'message' => sprintf('Kullanıcı %s (%s) için IP adresi %s\'nin %d failed login kaydı silindi.', $username, $user_id, $ip_address, $deleted)
    ));
}

/**
 * AJAX handler to get current retry status for user+IP combination
 */
add_action('wp_ajax_insurance_crm_get_user_ip_retry_status', 'insurance_crm_handle_get_user_ip_retry_status');

function insurance_crm_handle_get_user_ip_retry_status() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'insurance_crm_retry_nonce')) {
        wp_send_json_error(array('message' => 'Güvenlik doğrulaması başarısız.'));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Bu işlem için yetkiniz bulunmuyor.'));
    }
    
    $user_id = intval($_POST['user_id']);
    $ip_address = sanitize_text_field($_POST['ip_address']);
    
    if (empty($user_id) || empty($ip_address)) {
        wp_send_json_error(array('message' => 'Kullanıcı ID ve IP adresi belirtilmemiş.'));
    }
    
    // Check current retry status
    $retry_key = 'insurance_retry_user_ip_' . md5($user_id . '_' . $ip_address);
    $retry_data = get_transient($retry_key);
    
    $is_blocked = false;
    $remaining_time = 0;
    
    if ($retry_data !== false) {
        $block_until = $retry_data['block_until'];
        $current_time = time();
        
        if ($current_time < $block_until) {
            $is_blocked = true;
            $remaining_time = $block_until - $current_time;
        }
    }
    
    wp_send_json_success(array(
        'is_blocked' => $is_blocked,
        'remaining_time' => $remaining_time
    ));
}

/**
 * AJAX handler for manual IP blocking
 */
add_action('wp_ajax_insurance_crm_manual_block_ip', 'insurance_crm_handle_manual_block_ip');

function insurance_crm_handle_manual_block_ip() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'insurance_crm_retry_nonce')) {
        wp_send_json_error(array('message' => 'Güvenlik doğrulaması başarısız.'));
    }
    
    // Check permissions - only admins can block IPs
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Bu işlem için yetkiniz bulunmuyor.'));
    }
    
    $ip_address = sanitize_text_field($_POST['ip_address']);
    
    if (empty($ip_address) || !filter_var($ip_address, FILTER_VALIDATE_IP)) {
        wp_send_json_error(array('message' => 'Geçersiz IP adresi.'));
    }
    
    // Block the IP for 15 minutes using the legacy method
    $retry_key = 'insurance_retry_limit_' . md5($ip_address);
    
    // Block for 15 minutes (900 seconds)
    $block_duration = 15 * 60;
    $block_until = time() + $block_duration;
    
    $retry_data = array(
        'ip_address' => $ip_address,
        'failed_attempts' => 0, // Manual block
        'block_until' => $block_until,
        'blocked_at' => time(),
        'manual_block' => true
    );
    
    // Store the block information as a transient
    set_transient($retry_key, $retry_data, $block_duration);
    
    // Log the manual block action
    insurance_crm_log_retry_reset($ip_address, 'manual_ip_block', 1);
    
    wp_send_json_success(array(
        'message' => sprintf('IP adresi %s 15 dakika boyunca manuel olarak engellendi.', $ip_address)
    ));
}

/**
 * AJAX handler to get manual IP blocks
 */
add_action('wp_ajax_insurance_crm_get_manual_ip_blocks', 'insurance_crm_handle_get_manual_ip_blocks');

function insurance_crm_handle_get_manual_ip_blocks() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'insurance_crm_retry_nonce')) {
        wp_send_json_error(array('message' => 'Güvenlik doğrulaması başarısız.'));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Bu işlem için yetkiniz bulunmuyor.'));
    }
    
    global $wpdb;
    
    // Get all IP-based blocks (legacy system)
    $transients = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_insurance_retry_limit_%'"
    );
    
    $html = '<h4>Mevcut IP Engelleri:</h4>';
    
    if (empty($transients)) {
        $html .= '<p>Şu anda engellenmiş IP adresi bulunmuyor.</p>';
    } else {
        $html .= '<table class="wp-list-table widefat fixed striped">';
        $html .= '<thead><tr><th>IP Adresi</th><th>Engelleme Tipi</th><th>Kalan Süre</th><th>İşlemler</th></tr></thead>';
        $html .= '<tbody>';
        
        foreach ($transients as $transient) {
            $retry_data = maybe_unserialize($transient->option_value);
            if ($retry_data && isset($retry_data['ip_address'])) {
                $ip_address = $retry_data['ip_address'];
                $remaining_time = max(0, $retry_data['block_until'] - time());
                $is_manual = isset($retry_data['manual_block']) && $retry_data['manual_block'];
                
                if ($remaining_time > 0) {
                    $minutes = floor($remaining_time / 60);
                    $seconds = $remaining_time % 60;
                    $time_display = ($minutes > 0) ? "{$minutes} dakika {$seconds} saniye" : "{$seconds} saniye";
                    
                    $html .= '<tr>';
                    $html .= '<td>' . esc_html($ip_address) . '</td>';
                    $html .= '<td>' . ($is_manual ? 'Manuel Engel' : 'Otomatik Engel') . '</td>';
                    $html .= '<td>' . $time_display . '</td>';
                    $html .= '<td><button type="button" class="button button-small" onclick="unblockIPManually(\'' . esc_js($ip_address) . '\')">Engeli Kaldır</button></td>';
                    $html .= '</tr>';
                }
            }
        }
        
        $html .= '</tbody></table>';
    }
    
    wp_send_json_success(array('html' => $html));
}

/**
 * AJAX handler to reset retry limit for specific IP (legacy method - kept for backward compatibility)
 */
add_action('wp_ajax_insurance_crm_reset_retry_limit', 'insurance_crm_handle_reset_retry_limit');

function insurance_crm_handle_reset_retry_limit() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'insurance_crm_retry_nonce')) {
        wp_send_json_error(array('message' => 'Güvenlik doğrulaması başarısız.'));
    }
    
    // Check permissions - only admins can reset retry limits
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Bu işlem için yetkiniz bulunmuyor.'));
    }
    
    $ip_address = sanitize_text_field($_POST['ip_address']);
    
    if (empty($ip_address)) {
        wp_send_json_error(array('message' => 'IP adresi belirtilmemiş.'));
    }
    
    global $wpdb;
    
    // Remove the retry limit transient for this IP
    $retry_key = 'insurance_retry_limit_' . md5($ip_address);
    
    // First delete the transient
    $transient_deleted = delete_transient($retry_key);
    
    // Also delete from options table directly to handle caching issues
    $wpdb->delete(
        $wpdb->options,
        array(
            'option_name' => '_transient_' . $retry_key
        ),
        array('%s')
    );
    
    $wpdb->delete(
        $wpdb->options,
        array(
            'option_name' => '_transient_timeout_' . $retry_key
        ),
        array('%s')
    );
    
    // Clear failed login logs for this IP to prevent immediate re-blocking
    $deleted_logs = $wpdb->delete(
        $wpdb->prefix . 'insurance_user_logs',
        array(
            'action' => 'failed_login',
            'ip_address' => $ip_address
        ),
        array('%s', '%s')
    );
    
    // Verify the transient was actually deleted
    $transient_check = get_transient($retry_key);
    $success = ($transient_check === false);
    
    // Log the reset action with detailed info
    insurance_crm_log_retry_reset($ip_address, 'single_with_logs', $deleted_logs);
    
    if ($success) {
        wp_send_json_success(array(
            'message' => sprintf('IP adresi %s için retry limiti başarıyla sıfırlandı ve %d failed login kaydı temizlendi.', $ip_address, $deleted_logs),
            'debug_info' => array(
                'transient_deleted' => $transient_deleted,
                'logs_deleted' => $deleted_logs,
                'retry_key' => $retry_key
            )
        ));
    } else {
        wp_send_json_error(array(
            'message' => 'Retry limiti sıfırlama işleminde bir sorun oluştu. Lütfen tekrar deneyin.',
            'debug_info' => array(
                'transient_deleted' => $transient_deleted,
                'logs_deleted' => $deleted_logs,
                'retry_key' => $retry_key,
                'transient_still_exists' => ($transient_check !== false)
            )
        ));
    }
}

/**
 * AJAX handler to reset retry limits for all IPs
 */
add_action('wp_ajax_insurance_crm_reset_all_retry_limits', 'insurance_crm_handle_reset_all_retry_limits');

function insurance_crm_handle_reset_all_retry_limits() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'insurance_crm_retry_nonce')) {
        wp_send_json_error(array('message' => 'Güvenlik doğrulaması başarısız.'));
    }
    
    // Check permissions - only admins can reset retry limits
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Bu işlem için yetkiniz bulunmuyor.'));
    }
    
    global $wpdb;
    
    // Get all retry limit transients and delete them
    $transients = $wpdb->get_results(
        "SELECT option_name FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_insurance_retry_limit_%'"
    );
    
    $count = 0;
    foreach ($transients as $transient) {
        $key = str_replace('_transient_', '', $transient->option_name);
        delete_transient($key);
        $count++;
    }
    
    // Also delete directly from options table to handle caching issues
    $wpdb->query(
        "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_insurance_retry_limit_%' 
         OR option_name LIKE '_transient_timeout_insurance_retry_limit_%'"
    );
    
    // Also clear all failed login logs to prevent immediate re-blocking
    $deleted_logs = $wpdb->delete(
        $wpdb->prefix . 'insurance_user_logs',
        array('action' => 'failed_login'),
        array('%s')
    );
    
    // Log the bulk reset action with log clearing info
    insurance_crm_log_retry_reset('all', 'bulk_with_logs', $count, $deleted_logs);
    
    wp_send_json_success(array(
        'message' => sprintf('Toplam %d IP adresinin retry limiti sıfırlandı ve %d failed login kaydı temizlendi.', $count, $deleted_logs),
        'debug_info' => array(
            'transients_processed' => $count,
            'logs_deleted' => $deleted_logs
        )
    ));
}

/**
 * AJAX handler to clear failed login logs for specific IP
 */
add_action('wp_ajax_insurance_crm_clear_failed_logs', 'insurance_crm_handle_clear_failed_logs');

function insurance_crm_handle_clear_failed_logs() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'insurance_crm_retry_nonce')) {
        wp_send_json_error(array('message' => 'Güvenlik doğrulaması başarısız.'));
    }
    
    // Check permissions - only admins can clear logs
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Bu işlem için yetkiniz bulunmuyor.'));
    }
    
    $ip_address = sanitize_text_field($_POST['ip_address']);
    
    if (empty($ip_address)) {
        wp_send_json_error(array('message' => 'IP adresi belirtilmemiş.'));
    }
    
    global $wpdb;
    
    // Delete failed login logs for this IP
    $deleted = $wpdb->delete(
        $wpdb->prefix . 'insurance_user_logs',
        array(
            'action' => 'failed_login',
            'ip_address' => $ip_address
        ),
        array('%s', '%s')
    );
    
    if ($deleted === false) {
        wp_send_json_error(array('message' => 'Loglar silinirken bir hata oluştu.'));
    }
    
    // Also remove any retry limit for this IP
    $retry_key = 'insurance_retry_limit_' . md5($ip_address);
    delete_transient($retry_key);
    
    // Log the clear action
    insurance_crm_log_retry_reset($ip_address, 'clear_logs', $deleted);
    
    wp_send_json_success(array(
        'message' => sprintf('IP adresi %s için %d failed login kaydı silindi.', $ip_address, $deleted)
    ));
}

/**
 * AJAX handler to get current retry status for IP
 */
add_action('wp_ajax_insurance_crm_get_retry_status', 'insurance_crm_handle_get_retry_status');

function insurance_crm_handle_get_retry_status() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'insurance_crm_retry_nonce')) {
        wp_send_json_error(array('message' => 'Güvenlik doğrulaması başarısız.'));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Bu işlem için yetkiniz bulunmuyor.'));
    }
    
    $ip_address = sanitize_text_field($_POST['ip_address']);
    
    if (empty($ip_address)) {
        wp_send_json_error(array('message' => 'IP adresi belirtilmemiş.'));
    }
    
    // Check current retry status
    $retry_key = 'insurance_retry_limit_' . md5($ip_address);
    $retry_data = get_transient($retry_key);
    
    $is_blocked = false;
    $remaining_time = 0;
    
    if ($retry_data !== false) {
        $block_until = $retry_data['block_until'];
        $current_time = time();
        
        if ($current_time < $block_until) {
            $is_blocked = true;
            $remaining_time = $block_until - $current_time;
        }
    }
    
    wp_send_json_success(array(
        'is_blocked' => $is_blocked,
        'remaining_time' => $remaining_time
    ));
}

/**
 * Log retry reset actions
 */
function insurance_crm_log_retry_reset($ip_address, $action_type, $count = null, $username_or_logs = null) {
    // Use the system logger to record the action
    require_once plugin_dir_path(__FILE__) . 'logging/class-insurance-crm-system-logger.php';
    
    $system_logger = new Insurance_CRM_System_Logger();
    $user_id = get_current_user_id();
    
    $details_array = array(
        'ip_address' => $ip_address,
        'action_type' => $action_type,
        'admin_user_id' => $user_id,
        'timestamp' => current_time('mysql')
    );
    
    if ($count !== null) {
        $details_array['affected_count'] = $count;
    }
    
    // Handle different parameter meanings based on action type
    if (strpos($action_type, 'user_ip') !== false && is_string($username_or_logs)) {
        $details_array['username'] = $username_or_logs;
    } elseif (is_numeric($username_or_logs)) {
        $details_array['logs_deleted'] = $username_or_logs;
    }
    
    $action_messages = array(
        'single' => "IP {$ip_address} için retry limiti sıfırlandı",
        'single_with_logs' => "IP {$ip_address} için retry limiti sıfırlandı ve {$count} failed login kaydı temizlendi",
        'single_user_ip_with_logs' => "Kullanıcı {$username_or_logs} için IP {$ip_address}'nin retry limiti sıfırlandı ve {$count} failed login kaydı temizlendi",
        'bulk' => "Tüm IP'ler için retry limiti sıfırlandı (Toplam: {$count})",
        'bulk_with_logs' => "Tüm IP'ler için retry limiti sıfırlandı (Toplam: {$count}) ve {$username_or_logs} failed login kaydı temizlendi",
        'bulk_user_ip_with_logs' => "Tüm kullanıcı+IP kombinasyonları için retry limiti sıfırlandı (Toplam: {$count}) ve {$username_or_logs} failed login kaydı temizlendi",
        'clear_logs' => "IP {$ip_address} için {$count} failed login kaydı silindi",
        'clear_user_ip_logs' => "Kullanıcı {$username_or_logs} için IP {$ip_address}'nin {$count} failed login kaydı silindi",
        'manual_ip_block' => "IP {$ip_address} manuel olarak engellendi"
    );
    
    $message = isset($action_messages[$action_type]) ? $action_messages[$action_type] : "Retry reset işlemi: {$action_type}";
    
    $system_logger->log_system_action(
        'retry_limit_reset',
        'security',
        null,
        null,
        $details_array,
        $message
    );
}
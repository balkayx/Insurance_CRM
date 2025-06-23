<?php
/**
 * Log viewer class for Insurance CRM
 *
 * @package Insurance_CRM
 * @subpackage Insurance_CRM/includes/logging
 */

if (!defined('WPINC')) {
    die;
}

/**
 * Log viewer class
 */
class Insurance_CRM_Log_Viewer {
    
    /**
     * Display logs page
     */
    public function display_logs_page() {
        // Check permissions
        if (!current_user_can('manage_insurance_crm')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'system';
        
        ?>
        <div class="wrap">
            <h1><?php _e('Sistema Logları', 'insurance-crm'); ?></h1>
            
            <input type="hidden" id="log-nonce" value="<?php echo wp_create_nonce('insurance_crm_logs_nonce'); ?>">
            
            <nav class="nav-tab-wrapper">
                <a href="<?php echo admin_url('admin.php?page=insurance-crm-logs&tab=system'); ?>" 
                   class="nav-tab <?php echo $tab === 'system' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Sistem Logları', 'insurance-crm'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=insurance-crm-logs&tab=user'); ?>" 
                   class="nav-tab <?php echo $tab === 'user' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Kullanıcı Logları', 'insurance-crm'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=insurance-crm-logs&tab=security'); ?>" 
                   class="nav-tab <?php echo $tab === 'security' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Güvenlik Raporu', 'insurance-crm'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=insurance-crm-logs&tab=retry-management'); ?>" 
                   class="nav-tab <?php echo $tab === 'retry-management' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Login Retry Yönetimi', 'insurance-crm'); ?>
                </a>
            </nav>
            
            <div class="tab-content">
                <?php
                switch ($tab) {
                    case 'user':
                        $this->display_user_logs();
                        break;
                    case 'security':
                        $this->display_security_report();
                        break;
                    case 'retry-management':
                        $this->display_retry_management();
                        break;
                    default:
                        $this->display_system_logs();
                        break;
                }
                ?>
            </div>
        </div>
        
        <style>
        .tab-content {
            margin-top: 20px;
        }
        .log-filters {
            background: #f9f9f9;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .log-filters .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 10px;
            align-items: center;
        }
        .log-filters label {
            font-weight: 600;
            min-width: 100px;
        }
        .log-filters input, .log-filters select {
            padding: 5px 8px;
        }
        .logs-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .logs-table th, .logs-table td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #f1f1f1;
        }
        .logs-table th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            font-weight: 600;
            color: #495057;
            font-size: 0.85em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .logs-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .logs-table tr:hover {
            background-color: #f0f8ff;
            transition: background-color 0.2s ease;
        }
        .logs-table tr:last-child td {
            border-bottom: none;
        }
        .log-action {
            font-weight: 600;
            color: #2271b1;
            background: #e7f3ff;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.85em;
            display: inline-block;
        }
        .log-details {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 0.9em;
            color: #666;
        }
        .log-user {
            font-weight: 500;
            color: #0073aa;
        }
        .log-ip {
            font-family: monospace;
            font-size: 0.85em;
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 4px;
        }
        .pagination {
            margin: 20px 0;
            text-align: center;
        }
        .pagination a, .pagination span {
            display: inline-block;
            padding: 8px 12px;
            margin: 0 2px;
            border: 1px solid #ddd;
            text-decoration: none;
        }
        .pagination .current {
            background: #2271b1;
            color: white;
            border-color: #2271b1;
        }
        .export-buttons {
            margin-bottom: 20px;
        }
        .export-buttons .button {
            margin-right: 10px;
        }
        .stats-boxes {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stats-box {
            background: white;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
        }
        .stats-box h3 {
            margin: 0 0 10px 0;
            color: #2271b1;
        }
        .stats-box .number {
            font-size: 2em;
            font-weight: bold;
            color: #333;
        }
        </style>
        <?php
    }
    
    /**
     * Display system logs
     */
    private function display_system_logs() {
        $system_logger = new Insurance_CRM_System_Logger();
        
        // Handle filters
        $filters = $this->get_filters();
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 50;
        $offset = ($page - 1) * $per_page;
        
        // Get logs
        $logs = $system_logger->search_logs($filters, $per_page, $offset);
        $total_logs = $system_logger->count_logs($filters);
        
        // Get stats
        $stats = $system_logger->get_activity_stats(30);
        
        ?>
        <h2><?php _e('Sistem İşlem Logları', 'insurance-crm'); ?></h2>
        
        <?php $this->display_stats($stats); ?>
        <?php $this->display_filters('system'); ?>
        <?php $this->display_export_buttons('system', $filters); ?>
        
        <table class="logs-table widefat">
            <thead>
                <tr>
                    <th><?php _e('Tarih', 'insurance-crm'); ?></th>
                    <th><?php _e('Kullanıcı', 'insurance-crm'); ?></th>
                    <th><?php _e('İşlem', 'insurance-crm'); ?></th>
                    <th><?php _e('Detaylar', 'insurance-crm'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="4"><?php _e('Log kaydı bulunamadı.', 'insurance-crm'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html(date('d.m.Y H:i:s', strtotime($log->created_at))); ?></td>
                            <td class="log-user"><?php echo esc_html($log->user_name ?: 'Sistem'); ?></td>
                            <td class="log-action"><?php echo esc_html($this->format_action($log->action)); ?></td>
                            <td class="log-details" title="<?php echo esc_attr($log->details); ?>">
                                <?php echo esc_html($log->table_name . ($log->details ? ': ' . wp_trim_words($log->details, 8) : '')); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php $this->display_pagination($page, $total_logs, $per_page, 'system'); ?>
        <?php
    }
    
    /**
     * Display user logs
     */
    private function display_user_logs() {
        global $wpdb;
        
        // Handle filters
        $filters = $this->get_filters();
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 50;
        $offset = ($page - 1) * $per_page;
        
        // Build query
        $where_conditions = array();
        $params = array();
        
        if (!empty($filters['start_date'])) {
            $where_conditions[] = 'ul.created_at >= %s';
            $params[] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $where_conditions[] = 'ul.created_at <= %s';
            $params[] = $filters['end_date'];
        }
        
        if (!empty($filters['user_id'])) {
            $where_conditions[] = 'ul.user_id = %d';
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['action'])) {
            $where_conditions[] = 'ul.action = %s';
            $params[] = $filters['action'];
        }
        
        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }
        
        $params[] = $per_page;
        $params[] = $offset;
        
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                ul.*,
                u.display_name as user_name
             FROM {$wpdb->prefix}insurance_user_logs ul
             LEFT JOIN {$wpdb->users} u ON ul.user_id = u.ID
             {$where_clause}
             ORDER BY ul.created_at DESC
             LIMIT %d OFFSET %d",
            ...$params
        ));
        
        // Get total count
        $count_params = array_slice($params, 0, -2);
        $total_logs = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_user_logs ul {$where_clause}",
            ...$count_params
        ));
        
        ?>
        <h2><?php _e('Kullanıcı Giriş Logları', 'insurance-crm'); ?></h2>
        
        <?php $this->display_filters('user'); ?>
        <?php $this->display_export_buttons('user', $filters); ?>
        
        <table class="logs-table widefat">
            <thead>
                <tr>
                    <th><?php _e('Tarih', 'insurance-crm'); ?></th>
                    <th><?php _e('Kullanıcı', 'insurance-crm'); ?></th>
                    <th><?php _e('İşlem', 'insurance-crm'); ?></th>
                    <th><?php _e('IP Adresi', 'insurance-crm'); ?></th>
                    <th><?php _e('Tarayıcı', 'insurance-crm'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="5"><?php _e('Log kaydı bulunamadı.', 'insurance-crm'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html(date('d.m.Y H:i:s', strtotime($log->created_at))); ?></td>
                            <td class="log-user"><?php echo esc_html($log->user_name ?: 'Bilinmeyen'); ?></td>
                            <td class="log-action"><?php echo esc_html($this->format_user_action($log->action)); ?></td>
                            <td class="log-ip"><?php echo esc_html($log->ip_address); ?></td>
                            <td><?php echo esc_html(wp_trim_words($log->browser, 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php $this->display_pagination($page, $total_logs, $per_page, 'user'); ?>
        <?php
    }
    
    /**
     * Display security report
     */
    private function display_security_report() {
        $user_logger = new Insurance_CRM_User_Logger();
        
        // Get failed login attempts
        $failed_attempts = $user_logger->get_failed_attempts_by_ip(24, 20);
        
        // Get login statistics
        global $wpdb;
        $login_stats = $wpdb->get_row(
            "SELECT 
                COUNT(CASE WHEN action = 'login' THEN 1 END) as successful_logins,
                COUNT(CASE WHEN action = 'failed_login' THEN 1 END) as failed_logins,
                COUNT(DISTINCT ip_address) as unique_ips
             FROM {$wpdb->prefix}insurance_user_logs 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        
        ?>
        <h2><?php _e('Güvenlik Raporu', 'insurance-crm'); ?></h2>
        
        <div class="stats-boxes">
            <div class="stats-box">
                <h3><?php _e('Başarılı Girişler (24 saat)', 'insurance-crm'); ?></h3>
                <div class="number"><?php echo intval($login_stats->successful_logins); ?></div>
            </div>
            <div class="stats-box">
                <h3><?php _e('Başarısız Girişler (24 saat)', 'insurance-crm'); ?></h3>
                <div class="number"><?php echo intval($login_stats->failed_logins); ?></div>
            </div>
            <div class="stats-box">
                <h3><?php _e('Benzersiz IP Adresleri', 'insurance-crm'); ?></h3>
                <div class="number"><?php echo intval($login_stats->unique_ips); ?></div>
            </div>
            <div class="stats-box">
                <h3><?php _e('Şüpheli Aktiviteler', 'insurance-crm'); ?></h3>
                <div class="number"><?php echo $this->count_suspicious_activities(); ?></div>
            </div>
        </div>
        
        <div style="margin-bottom: 30px;">
            <h3><?php _e('Güvenlik Ayarları', 'insurance-crm'); ?></h3>
            <form method="post" action="">
                <?php wp_nonce_field('insurance_crm_security_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th><?php _e('Otomatik Log Temizleme (Gün)', 'insurance-crm'); ?></th>
                        <td>
                            <input type="number" name="log_retention_days" value="<?php echo get_option('insurance_crm_log_retention_days', 90); ?>" min="1" max="365">
                            <p class="description"><?php _e('Loglar kaç gün sonra otomatik olarak silinsin?', 'insurance-crm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Başarısız Giriş Limiti', 'insurance-crm'); ?></th>
                        <td>
                            <input type="number" name="failed_login_limit" value="<?php echo get_option('insurance_crm_failed_login_limit', 5); ?>" min="1" max="50">
                            <p class="description"><?php _e('Saatte kaç başarısız girişte uyarı gönderilsin?', 'insurance-crm'); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="save_security_settings" class="button button-primary" value="<?php _e('Ayarları Kaydet', 'insurance-crm'); ?>">
                </p>
            </form>
        </div>
        
        <?php
        // Handle security settings form
        if (isset($_POST['save_security_settings']) && wp_verify_nonce($_POST['_wpnonce'], 'insurance_crm_security_settings')) {
            update_option('insurance_crm_log_retention_days', intval($_POST['log_retention_days']));
            update_option('insurance_crm_failed_login_limit', intval($_POST['failed_login_limit']));
            echo '<div class="notice notice-success"><p>' . __('Güvenlik ayarları başarıyla kaydedildi.', 'insurance-crm') . '</p></div>';
        }
        ?>
        
        <h3><?php _e('Başarısız Giriş Denemeleri (IP Bazında)', 'insurance-crm'); ?></h3>
        <table class="logs-table widefat">
            <thead>
                <tr>
                    <th><?php _e('IP Adresi', 'insurance-crm'); ?></th>
                    <th><?php _e('Deneme Sayısı', 'insurance-crm'); ?></th>
                    <th><?php _e('Son Deneme', 'insurance-crm'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($failed_attempts)): ?>
                    <tr>
                        <td colspan="3"><?php _e('Son 24 saatte başarısız giriş denemesi bulunmamaktadır.', 'insurance-crm'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($failed_attempts as $attempt): ?>
                        <tr>
                            <td class="log-ip"><?php echo esc_html($attempt->ip_address); ?></td>
                            <td><?php echo intval($attempt->attempts); ?></td>
                            <td><?php echo esc_html(date('d.m.Y H:i:s', strtotime($attempt->last_attempt))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Get filters from request
     */
    private function get_filters() {
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
        
        if (!empty($_GET['table_name'])) {
            $filters['table_name'] = sanitize_text_field($_GET['table_name']);
        }
        
        if (!empty($_GET['search'])) {
            $filters['search'] = sanitize_text_field($_GET['search']);
        }
        
        return $filters;
    }
    
    /**
     * Display filters form
     */
    private function display_filters($tab) {
        $filters = $this->get_filters();
        
        ?>
        <div class="log-filters">
            <form method="get" action="">
                <input type="hidden" name="page" value="insurance-crm-logs">
                <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
                
                <div class="form-row">
                    <label><?php _e('Başlangıç Tarihi:', 'insurance-crm'); ?></label>
                    <input type="date" name="start_date" value="<?php echo esc_attr($filters['start_date'] ?? ''); ?>">
                    
                    <label><?php _e('Bitiş Tarihi:', 'insurance-crm'); ?></label>
                    <input type="date" name="end_date" value="<?php echo esc_attr($filters['end_date'] ?? ''); ?>">
                </div>
                
                <div class="form-row">
                    <label><?php _e('Kullanıcı:', 'insurance-crm'); ?></label>
                    <select name="user_id">
                        <option value=""><?php _e('Tüm Kullanıcılar', 'insurance-crm'); ?></option>
                        <?php
                        $users = get_users(array('role__in' => array('insurance_representative', 'administrator')));
                        foreach ($users as $user):
                        ?>
                            <option value="<?php echo $user->ID; ?>" <?php selected($filters['user_id'] ?? '', $user->ID); ?>>
                                <?php echo esc_html($user->display_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <?php if ($tab === 'system'): ?>
                        <label><?php _e('İşlem:', 'insurance-crm'); ?></label>
                        <select name="action">
                            <option value=""><?php _e('Tüm İşlemler', 'insurance-crm'); ?></option>
                            <option value="create" <?php selected(strpos($filters['action'] ?? '', 'create') !== false); ?>><?php _e('Oluşturma', 'insurance-crm'); ?></option>
                            <option value="update" <?php selected(strpos($filters['action'] ?? '', 'update') !== false); ?>><?php _e('Güncelleme', 'insurance-crm'); ?></option>
                            <option value="delete" <?php selected(strpos($filters['action'] ?? '', 'delete') !== false); ?>><?php _e('Silme', 'insurance-crm'); ?></option>
                        </select>
                    <?php elseif ($tab === 'user'): ?>
                        <label><?php _e('İşlem:', 'insurance-crm'); ?></label>
                        <select name="action">
                            <option value=""><?php _e('Tüm İşlemler', 'insurance-crm'); ?></option>
                            <option value="login" <?php selected($filters['action'] ?? '', 'login'); ?>><?php _e('Giriş', 'insurance-crm'); ?></option>
                            <option value="logout" <?php selected($filters['action'] ?? '', 'logout'); ?>><?php _e('Çıkış', 'insurance-crm'); ?></option>
                            <option value="failed_login" <?php selected($filters['action'] ?? '', 'failed_login'); ?>><?php _e('Başarısız Giriş', 'insurance-crm'); ?></option>
                        </select>
                    <?php endif; ?>
                </div>
                
                <div class="form-row">
                    <label><?php _e('Arama:', 'insurance-crm'); ?></label>
                    <input type="text" name="search" value="<?php echo esc_attr($filters['search'] ?? ''); ?>" placeholder="<?php _e('Detaylarda ara...', 'insurance-crm'); ?>">
                    
                    <button type="submit" class="button"><?php _e('Filtrele', 'insurance-crm'); ?></button>
                    <a href="<?php echo admin_url('admin.php?page=insurance-crm-logs&tab=' . $tab); ?>" class="button"><?php _e('Temizle', 'insurance-crm'); ?></a>
                </div>
            </form>
        </div>
        <?php
    }
    
    /**
     * Display export buttons
     */
    private function display_export_buttons($tab, $filters) {
        $export_url = admin_url('admin.php?page=insurance-crm-logs&tab=' . $tab . '&export=csv');
        
        // Add filters to export URL
        foreach ($filters as $key => $value) {
            if (!empty($value)) {
                $export_url = add_query_arg($key, $value, $export_url);
            }
        }
        
        ?>
        <div class="export-buttons">
            <a href="<?php echo esc_url($export_url); ?>" class="button">
                <?php _e('CSV olarak İndir', 'insurance-crm'); ?>
            </a>
        </div>
        <?php
    }
    
    /**
     * Display statistics
     */
    private function display_stats($stats) {
        if (empty($stats)) {
            return;
        }
        
        ?>
        <div class="stats-boxes">
            <?php foreach (array_slice($stats, 0, 4) as $stat): ?>
                <div class="stats-box">
                    <h3><?php echo esc_html($this->format_action($stat->action)); ?></h3>
                    <div class="number"><?php echo intval($stat->count); ?></div>
                    <small><?php echo esc_html($stat->table_name); ?></small>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }
    
    /**
     * Display pagination
     */
    private function display_pagination($current_page, $total_items, $per_page, $tab) {
        $total_pages = ceil($total_items / $per_page);
        
        if ($total_pages <= 1) {
            return;
        }
        
        $base_url = admin_url('admin.php?page=insurance-crm-logs&tab=' . $tab);
        
        // Add current filters to pagination URLs
        $filters = $this->get_filters();
        foreach ($filters as $key => $value) {
            if (!empty($value)) {
                $base_url = add_query_arg($key, $value, $base_url);
            }
        }
        
        ?>
        <div class="pagination">
            <?php if ($current_page > 1): ?>
                <a href="<?php echo add_query_arg('paged', $current_page - 1, $base_url); ?>">&laquo; <?php _e('Önceki', 'insurance-crm'); ?></a>
            <?php endif; ?>
            
            <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
                <?php if ($i === $current_page): ?>
                    <span class="current"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="<?php echo add_query_arg('paged', $i, $base_url); ?>"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($current_page < $total_pages): ?>
                <a href="<?php echo add_query_arg('paged', $current_page + 1, $base_url); ?>"><?php _e('Sonraki', 'insurance-crm'); ?> &raquo;</a>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Format action name for display
     */
    private function format_action($action) {
        $actions = array(
            'create_customer' => 'Müşteri Oluşturma',
            'update_customer' => 'Müşteri Güncelleme',
            'delete_customer' => 'Müşteri Silme',
            'create_policy' => 'Poliçe Oluşturma',
            'update_policy' => 'Poliçe Güncelleme',
            'delete_policy' => 'Poliçe Silme',
            'create_task' => 'Görev Oluşturma',
            'update_task' => 'Görev Güncelleme',
            'delete_task' => 'Görev Silme',
            'upload_file' => 'Dosya Yükleme',
            'delete_file' => 'Dosya Silme',
            'log_cleanup' => 'Log Temizleme',
            'suspicious_activity' => 'Şüpheli Aktivite'
        );
        
        return $actions[$action] ?? ucfirst(str_replace('_', ' ', $action));
    }
    
    /**
     * Format user action name for display
     */
    private function format_user_action($action) {
        $actions = array(
            'login' => 'Giriş',
            'logout' => 'Çıkış',
            'failed_login' => 'Başarısız Giriş'
        );
        
        return $actions[$action] ?? ucfirst($action);
    }
    
    /**
     * Format duration in seconds to human readable format
     */
    private function format_duration($seconds) {
        if ($seconds < 60) {
            return $seconds . ' saniye';
        } elseif ($seconds < 3600) {
            return floor($seconds / 60) . ' dakika';
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return $hours . ' saat ' . $minutes . ' dakika';
        }
    }
    
    /**
     * Count suspicious activities
     */
    private function count_suspicious_activities() {
        global $wpdb;
        
        return $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_system_logs 
             WHERE action = 'suspicious_activity' 
             AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
    }
    
    /**
     * Export logs to CSV
     */
    public function export_logs($tab, $filters = array()) {
        if (!current_user_can('manage_insurance_crm')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        // Set headers for CSV download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="insurance_crm_logs_' . $tab . '_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        if ($tab === 'user') {
            // Export user logs
            fputcsv($output, array('Tarih', 'Kullanıcı', 'İşlem', 'IP Adresi', 'Tarayıcı'));
            
            global $wpdb;
            
            $where_conditions = array();
            $params = array();
            
            if (!empty($filters['start_date'])) {
                $where_conditions[] = 'ul.created_at >= %s';
                $params[] = $filters['start_date'];
            }
            
            if (!empty($filters['end_date'])) {
                $where_conditions[] = 'ul.created_at <= %s';
                $params[] = $filters['end_date'];
            }
            
            if (!empty($filters['user_id'])) {
                $where_conditions[] = 'ul.user_id = %d';
                $params[] = $filters['user_id'];
            }
            
            if (!empty($filters['action'])) {
                $where_conditions[] = 'ul.action = %s';
                $params[] = $filters['action'];
            }
            
            $where_clause = '';
            if (!empty($where_conditions)) {
                $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
            }
            
            $logs = $wpdb->get_results($wpdb->prepare(
                "SELECT 
                    ul.*,
                    u.display_name as user_name
                 FROM {$wpdb->prefix}insurance_user_logs ul
                 LEFT JOIN {$wpdb->users} u ON ul.user_id = u.ID
                 {$where_clause}
                 ORDER BY ul.created_at DESC",
                ...$params
            ));
            
            foreach ($logs as $log) {
                fputcsv($output, array(
                    date('d.m.Y H:i:s', strtotime($log->created_at)),
                    $log->user_name ?: 'Bilinmeyen',
                    $this->format_user_action($log->action),
                    $log->ip_address,
                    wp_trim_words($log->browser, 2)
                ));
            }
            
        } else {
            // Export system logs
            fputcsv($output, array('Tarih', 'Kullanıcı', 'İşlem', 'Detaylar'));
            
            $system_logger = new Insurance_CRM_System_Logger();
            $logs = $system_logger->search_logs($filters, 10000, 0); // Export max 10k records
            
            foreach ($logs as $log) {
                fputcsv($output, array(
                    date('d.m.Y H:i:s', strtotime($log->created_at)),
                    $log->user_name ?: 'Sistem',
                    $this->format_action($log->action),
                    $log->table_name . ($log->details ? ': ' . $log->details : '')
                ));
            }
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Display login retry management page
     */
    private function display_retry_management() {
        // Check permissions - only admins can manage retry limits
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>' . __('Bu özelliği kullanmak için yeterli izniniz yok.', 'insurance-crm') . '</p></div>';
            return;
        }
        
        $user_logger = new Insurance_CRM_User_Logger();
        
        // Get failed login attempts from last 24 hours (by user+IP combinations)
        $failed_attempts = $user_logger->get_failed_attempts_by_user_ip(24, 50);
        
        ?>
        <h2><?php _e('Login Retry Yönetimi', 'insurance-crm'); ?></h2>
        
        <div class="retry-management-header">
            <p><?php _e('Başarısız giriş denemelerinde bulunan kullanıcı+IP kombinasyonlarını görüntüleyebilir ve bekleme sürelerini sıfırlayabilirsiniz.', 'insurance-crm'); ?></p>
            <p><strong><?php _e('Önemli:', 'insurance-crm'); ?></strong> <?php _e('Artık sadece belirli kullanıcı+IP kombinasyonları engellenir, tüm IP adresleri engellenmez.', 'insurance-crm'); ?></p>
            
            <div class="bulk-actions" style="margin: 20px 0;">
                <button type="button" id="reset-all-retry-limits" class="button button-secondary" 
                        onclick="resetAllRetryLimits()">
                    <?php _e('Tüm Kullanıcı+IP Kombinasyonlarının Retry Limitini Sıfırla', 'insurance-crm'); ?>
                </button>
                
                <button type="button" id="show-ip-management" class="button button-link" 
                        onclick="toggleManualIPManagement()" style="margin-left: 10px;">
                    <?php _e('Manuel IP Engelleme Yönetimi', 'insurance-crm'); ?>
                </button>
            </div>
        </div>
        
        <table class="wp-list-table widefat fixed striped" id="retry-management-table">
            <thead>
                <tr>
                    <th style="width: 12%;"><?php _e('Kullanıcı Adı', 'insurance-crm'); ?></th>
                    <th style="width: 12%;"><?php _e('IP Adresi', 'insurance-crm'); ?></th>
                    <th style="width: 10%;"><?php _e('Başarısız Denemeler', 'insurance-crm'); ?></th>
                    <th style="width: 13%;"><?php _e('Son Deneme', 'insurance-crm'); ?></th>
                    <th style="width: 13%;"><?php _e('Retry Durumu', 'insurance-crm'); ?></th>
                    <th style="width: 15%;"><?php _e('Kalan Süre', 'insurance-crm'); ?></th>
                    <th style="width: 25%;"><?php _e('İşlemler', 'insurance-crm'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($failed_attempts)): ?>
                    <?php foreach ($failed_attempts as $attempt): ?>
                        <?php 
                        $user_id = $attempt->user_id;
                        $username = $attempt->username ?: (__('Bilinmeyen Kullanıcı', 'insurance-crm') . ' #' . $user_id);
                        $ip_address = $attempt->ip_address;
                        $retry_status = $this->get_user_ip_retry_status($user_id, $ip_address);
                        $is_blocked = $retry_status['is_blocked'];
                        $remaining_time = $retry_status['remaining_time'];
                        ?>
                        <tr data-user-id="<?php echo esc_attr($user_id); ?>" data-ip="<?php echo esc_attr($ip_address); ?>">
                            <td class="username"><?php echo esc_html($username); ?></td>
                            <td class="ip-address"><?php echo esc_html($ip_address); ?></td>
                            <td class="attempt-count"><?php echo intval($attempt->attempts); ?></td>
                            <td><?php echo esc_html(date('d.m.Y H:i:s', strtotime($attempt->last_attempt))); ?></td>
                            <td class="retry-status">
                                <?php if ($is_blocked): ?>
                                    <span class="status-blocked" style="color: #dc3545; font-weight: bold;">
                                        <?php _e('Engellenmiş', 'insurance-crm'); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="status-normal" style="color: #28a745;">
                                        <?php _e('Normal', 'insurance-crm'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="remaining-time">
                                <?php if ($is_blocked && $remaining_time > 0): ?>
                                    <span style="color: #dc3545;">
                                        <?php echo $this->format_remaining_time($remaining_time); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #6c757d;">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions">
                                <?php if ($is_blocked): ?>
                                    <button type="button" class="button button-primary reset-retry-btn" 
                                            data-user-id="<?php echo esc_attr($user_id); ?>"
                                            data-ip="<?php echo esc_attr($ip_address); ?>"
                                            onclick="resetUserIPRetryLimit('<?php echo esc_js($user_id); ?>', '<?php echo esc_js($ip_address); ?>')">
                                        <?php _e('Bekleme Süresini İptal Et', 'insurance-crm'); ?>
                                    </button>
                                <?php else: ?>
                                    <span style="color: #6c757d;"><?php _e('İşlem gerekmiyor', 'insurance-crm'); ?></span>
                                <?php endif; ?>
                                
                                <button type="button" class="button button-secondary clear-logs-btn" 
                                        data-user-id="<?php echo esc_attr($user_id); ?>"
                                        data-ip="<?php echo esc_attr($ip_address); ?>"
                                        onclick="clearUserIPFailedLogs('<?php echo esc_js($user_id); ?>', '<?php echo esc_js($ip_address); ?>')"
                                        style="margin-left: 5px;">
                                    <?php _e('Logları Temizle', 'insurance-crm'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px;">
                            <?php _e('Son 24 saatte başarısız giriş denemesi bulunamadı.', 'insurance-crm'); ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Manual IP Management Section (Hidden by default) -->
        <div id="manual-ip-management" style="display: none; margin-top: 30px; padding: 20px; border: 1px solid #ddd; background: #f9f9f9;">
            <h3><?php _e('Manuel IP Engelleme Yönetimi', 'insurance-crm'); ?></h3>
            <p><?php _e('Burada tüm IP adreslerini manuel olarak engelleyebilir veya mevcut IP engellemelerini yönetebilirsiniz.', 'insurance-crm'); ?></p>
            
            <div style="margin: 15px 0;">
                <label for="manual-ip-input"><?php _e('IP Adresi:', 'insurance-crm'); ?></label>
                <input type="text" id="manual-ip-input" placeholder="192.168.1.1" style="margin-left: 10px; margin-right: 10px;" />
                <button type="button" class="button button-primary" onclick="blockIPManually()">
                    <?php _e('IP\'yi Engelle (15 dakika)', 'insurance-crm'); ?>
                </button>
            </div>
            
            <!-- Show existing IP blocks -->
            <div id="manual-ip-blocks">
                <!-- This will be populated via AJAX -->
            </div>
        </div>
        
        <div id="retry-management-messages" style="margin-top: 20px;"></div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Auto-refresh every 30 seconds
            setInterval(function() {
                updateRetryStatus();
            }, 30000);
        });
        
        function resetUserIPRetryLimit(userId, ipAddress) {
            if (!confirm('<?php _e("Bu kullanıcı+IP kombinasyonunun retry limitini sıfırlamak istediğinizden emin misiniz?", "insurance-crm"); ?>')) {
                return;
            }
            
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'insurance_crm_reset_user_ip_retry_limit',
                    user_id: userId,
                    ip_address: ipAddress,
                    nonce: '<?php echo wp_create_nonce('insurance_crm_retry_nonce'); ?>'
                },
                beforeSend: function() {
                    jQuery('[data-user-id="' + userId + '"][data-ip="' + ipAddress + '"] .reset-retry-btn').prop('disabled', true).text('<?php _e("İşleniyor...", "insurance-crm"); ?>');
                },
                success: function(response) {
                    if (response.success) {
                        showMessage(response.data.message, 'success');
                        updateRowStatus(userId, ipAddress, false, 0);
                    } else {
                        showMessage(response.data.message || '<?php _e("Bir hata oluştu", "insurance-crm"); ?>', 'error');
                    }
                },
                error: function() {
                    showMessage('<?php _e("AJAX hatası oluştu", "insurance-crm"); ?>', 'error');
                },
                complete: function() {
                    jQuery('[data-user-id="' + userId + '"][data-ip="' + ipAddress + '"] .reset-retry-btn').prop('disabled', false).text('<?php _e("Bekleme Süresini İptal Et", "insurance-crm"); ?>');
                }
            });
        }
        
        function clearUserIPFailedLogs(userId, ipAddress) {
            if (!confirm('<?php _e("Bu kullanıcı+IP kombinasyonunun başarısız giriş loglarını temizlemek istediğinizden emin misiniz?", "insurance-crm"); ?>')) {
                return;
            }
            
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'insurance_crm_clear_user_ip_failed_logs',
                    user_id: userId,
                    ip_address: ipAddress,
                    nonce: '<?php echo wp_create_nonce('insurance_crm_retry_nonce'); ?>'
                },
                beforeSend: function() {
                    jQuery('[data-user-id="' + userId + '"][data-ip="' + ipAddress + '"] .clear-logs-btn').prop('disabled', true).text('<?php _e("İşleniyor...", "insurance-crm"); ?>');
                },
                success: function(response) {
                    if (response.success) {
                        showMessage(response.data.message, 'success');
                        // Refresh the page to update the table
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        showMessage(response.data.message || '<?php _e("Bir hata oluştu", "insurance-crm"); ?>', 'error');
                    }
                },
                error: function() {
                    showMessage('<?php _e("AJAX hatası oluştu", "insurance-crm"); ?>', 'error');
                },
                complete: function() {
                    jQuery('[data-user-id="' + userId + '"][data-ip="' + ipAddress + '"] .clear-logs-btn').prop('disabled', false).text('<?php _e("Logları Temizle", "insurance-crm"); ?>');
                }
            });
        }
        
        function resetAllRetryLimits() {
            if (!confirm('<?php _e("Tüm kullanıcı+IP kombinasyonlarının retry limitlerini sıfırlamak istediğinizden emin misiniz?", "insurance-crm"); ?>')) {
                return;
            }
            
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'insurance_crm_reset_all_user_ip_retry_limits',
                    nonce: '<?php echo wp_create_nonce('insurance_crm_retry_nonce'); ?>'
                },
                beforeSend: function() {
                    jQuery('#reset-all-retry-limits').prop('disabled', true).text('<?php _e("İşleniyor...", "insurance-crm"); ?>');
                },
                success: function(response) {
                    if (response.success) {
                        showMessage(response.data.message, 'success');
                        // Refresh the page to update all statuses
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        showMessage(response.data.message || '<?php _e("Bir hata oluştu", "insurance-crm"); ?>', 'error');
                    }
                },
                error: function() {
                    showMessage('<?php _e("AJAX hatası oluştu", "insurance-crm"); ?>', 'error');
                },
                complete: function() {
                    jQuery('#reset-all-retry-limits').prop('disabled', false).text('<?php _e("Tüm Kullanıcı+IP Kombinasyonlarının Retry Limitini Sıfırla", "insurance-crm"); ?>');
                }
            });
        }
        
        function toggleManualIPManagement() {
            var section = jQuery('#manual-ip-management');
            if (section.is(':visible')) {
                section.hide();
                jQuery('#show-ip-management').text('<?php _e("Manuel IP Engelleme Yönetimi", "insurance-crm"); ?>');
            } else {
                section.show();
                jQuery('#show-ip-management').text('<?php _e("Manuel IP Yönetimini Gizle", "insurance-crm"); ?>');
                loadManualIPBlocks();
            }
        }
        
        function loadManualIPBlocks() {
            // Load existing manual IP blocks
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'insurance_crm_get_manual_ip_blocks',
                    nonce: '<?php echo wp_create_nonce('insurance_crm_retry_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        jQuery('#manual-ip-blocks').html(response.data.html);
                    }
                }
            });
        }
        
        function blockIPManually() {
            var ipAddress = jQuery('#manual-ip-input').val().trim();
            if (!ipAddress) {
                alert('<?php _e("Lütfen geçerli bir IP adresi girin.", "insurance-crm"); ?>');
                return;
            }
            
            if (!confirm('<?php _e("Bu IP adresini 15 dakika boyunca engellemek istediğinizden emin misiniz?", "insurance-crm"); ?>')) {
                return;
            }
            
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'insurance_crm_manual_block_ip',
                    ip_address: ipAddress,
                    nonce: '<?php echo wp_create_nonce('insurance_crm_retry_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        showMessage(response.data.message, 'success');
                        jQuery('#manual-ip-input').val('');
                        loadManualIPBlocks();
                    } else {
                        showMessage(response.data.message || '<?php _e("Bir hata oluştu", "insurance-crm"); ?>', 'error');
                    }
                },
                error: function() {
                    showMessage('<?php _e("AJAX hatası oluştu", "insurance-crm"); ?>', 'error');
                }
            });
        }
        
        function updateRowStatus(userId, ipAddress, isBlocked, remainingTime) {
            var row = jQuery('[data-user-id="' + userId + '"][data-ip="' + ipAddress + '"]');
            
            if (isBlocked) {
                row.find('.retry-status').html('<span class="status-blocked" style="color: #dc3545; font-weight: bold;"><?php _e("Engellenmiş", "insurance-crm"); ?></span>');
                row.find('.remaining-time').html('<span style="color: #dc3545;">' + formatRemainingTime(remainingTime) + '</span>');
                row.find('.actions').html('<button type="button" class="button button-primary reset-retry-btn" data-user-id="' + userId + '" data-ip="' + ipAddress + '" onclick="resetUserIPRetryLimit(\'' + userId + '\', \'' + ipAddress + '\')"><?php _e("Bekleme Süresini İptal Et", "insurance-crm"); ?></button><button type="button" class="button button-secondary clear-logs-btn" data-user-id="' + userId + '" data-ip="' + ipAddress + '" onclick="clearUserIPFailedLogs(\'' + userId + '\', \'' + ipAddress + '\')" style="margin-left: 5px;"><?php _e("Logları Temizle", "insurance-crm"); ?></button>');
            } else {
                row.find('.retry-status').html('<span class="status-normal" style="color: #28a745;"><?php _e("Normal", "insurance-crm"); ?></span>');
                row.find('.remaining-time').html('<span style="color: #6c757d;">-</span>');
                row.find('.actions').html('<span style="color: #6c757d;"><?php _e("İşlem gerekmiyor", "insurance-crm"); ?></span><button type="button" class="button button-secondary clear-logs-btn" data-user-id="' + userId + '" data-ip="' + ipAddress + '" onclick="clearUserIPFailedLogs(\'' + userId + '\', \'' + ipAddress + '\')" style="margin-left: 5px;"><?php _e("Logları Temizle", "insurance-crm"); ?></button>');
            }
        }
        
        function updateRetryStatus() {
            // Update remaining times for blocked user+IP combinations
            jQuery('.status-blocked').each(function() {
                var row = jQuery(this).closest('tr');
                var userId = row.data('user-id');
                var ipAddress = row.data('ip');
                
                jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'insurance_crm_get_user_ip_retry_status',
                        user_id: userId,
                        ip_address: ipAddress,
                        nonce: '<?php echo wp_create_nonce('insurance_crm_retry_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            updateRowStatus(userId, ipAddress, response.data.is_blocked, response.data.remaining_time);
                        }
                    }
                });
            });
        }
        
        function formatRemainingTime(seconds) {
            if (seconds <= 0) return '-';
            
            var minutes = Math.floor(seconds / 60);
            var remainingSeconds = seconds % 60;
            
            if (minutes > 0) {
                return minutes + ' <?php _e("dakika", "insurance-crm"); ?> ' + remainingSeconds + ' <?php _e("saniye", "insurance-crm"); ?>';
            } else {
                return remainingSeconds + ' <?php _e("saniye", "insurance-crm"); ?>';
            }
        }
        
        function showMessage(message, type) {
            var messageClass = type === 'success' ? 'notice-success' : 'notice-error';
            var messageHtml = '<div class="notice ' + messageClass + ' is-dismissible"><p>' + message + '</p></div>';
            
            jQuery('#retry-management-messages').html(messageHtml);
            
            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                jQuery('#retry-management-messages .notice').fadeOut();
            }, 5000);
        }
        </script>
        
        <style>
        .retry-management-header {
            background: #f8f9fa;
            padding: 15px;
            border-left: 4px solid #007cba;
            margin-bottom: 20px;
        }
        .retry-management-header p {
            margin: 0 0 10px 0;
            font-size: 14px;
        }
        .bulk-actions {
            margin-top: 15px;
        }
        #retry-management-table .status-blocked {
            font-weight: bold;
        }
        #retry-management-table .status-normal {
            font-weight: bold;
        }
        #retry-management-table .actions {
            white-space: nowrap;
        }
        #retry-management-table .actions button {
            margin-right: 5px;
        }
        </style>
        <?php
    }
    
    /**
     * Get user+IP retry status 
     */
    private function get_user_ip_retry_status($user_id, $ip_address) {
        // Check if user+IP combination is currently blocked using transient
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
        
        return array(
            'is_blocked' => $is_blocked,
            'remaining_time' => $remaining_time
        );
    }
    
    /**
     * Get IP retry status (legacy method for manual IP blocks)
     */
    private function get_ip_retry_status($ip_address) {
        // Check if IP is currently blocked using transient
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
        
        return array(
            'is_blocked' => $is_blocked,
            'remaining_time' => $remaining_time
        );
    }
    
    /**
     * Format remaining time in human readable format
     */
    private function format_remaining_time($seconds) {
        if ($seconds <= 0) {
            return '-';
        }
        
        $minutes = floor($seconds / 60);
        $remaining_seconds = $seconds % 60;
        
        if ($minutes > 0) {
            return sprintf('%d dakika %d saniye', $minutes, $remaining_seconds);
        } else {
            return sprintf('%d saniye', $remaining_seconds);
        }
    }
}
<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/temsilci-girisi/'));
    exit;
}

$user = wp_get_current_user();
if (!in_array('insurance_representative', (array)$user->roles)) {
    wp_safe_redirect(home_url());
    exit;
}

$current_user = wp_get_current_user();

global $wpdb;
$representative = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}insurance_crm_representatives 
     WHERE user_id = %d AND status = 'active'",
    $current_user->ID
));

if (!$representative) {
    wp_die('Müşteri temsilcisi kaydınız bulunamadı veya hesabınız pasif durumda.');
}

// Check if user is patron
$user_role = '';
$has_permission = false;

if (function_exists('is_patron') && is_patron($current_user->ID)) {
    $user_role = 'patron';
    $has_permission = true;
} elseif (current_user_can('administrator')) {
    $user_role = 'admin';
    $has_permission = true;
}

// If no permission, show error message instead of breaking the page
if (!$has_permission) {
    ?>
    <div class="main-content">
        <div class="ab-notice ab-error">
            <i class="dashicons dashicons-lock"></i>
            <strong>Yetkiniz Yok</strong><br>
            Bu sayfaya erişim yetkiniz bulunmamaktadır. Sadece patronlar güvenlik loglarını görüntüleyebilir.
        </div>
    </div>
    <?php
    return;
}

// Load logging classes
if (defined('INSURANCE_CRM_PATH')) {
    require_once(INSURANCE_CRM_PATH . 'includes/logging/class-insurance-crm-logger.php');
    require_once(INSURANCE_CRM_PATH . 'includes/logging/class-insurance-crm-system-logger.php');
    require_once(INSURANCE_CRM_PATH . 'includes/logging/class-insurance-crm-user-logger.php');
    require_once(INSURANCE_CRM_PATH . 'includes/logging/class-insurance-crm-log-viewer.php');
}

// Initialize logging classes
$system_logger = new Insurance_CRM_System_Logger();
$user_logger = new Insurance_CRM_User_Logger();

// Get current tab
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'system';

// Handle AJAX requests for logs
if (isset($_POST['action']) && $_POST['action'] === 'get_logs') {
    if (!wp_verify_nonce($_POST['nonce'], 'insurance_crm_logs_nonce')) {
        wp_die('Security check failed');
    }
    
    $tab = sanitize_text_field($_POST['tab']);
    $page = intval($_POST['page']) ?: 1;
    $per_page = 20;
    $offset = ($page - 1) * $per_page;
    
    if ($tab === 'user') {
        // User logger doesn't have get_logs method, use database query instead
        global $wpdb;
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT ul.*, u.display_name as user_name
             FROM {$wpdb->prefix}insurance_user_logs ul
             LEFT JOIN {$wpdb->users} u ON ul.user_id = u.ID
             ORDER BY ul.created_at DESC
             LIMIT %d OFFSET %d",
            $per_page, $offset
        ));
    } else {
        $logs = $system_logger->get_recent_activities($per_page);
    }
    
    wp_send_json_success($logs);
}

// Get recent logs for dashboard
$recent_system_logs = $wpdb->get_results($wpdb->prepare(
    "SELECT sl.*, u.display_name as user_name
     FROM {$wpdb->prefix}insurance_system_logs sl
     LEFT JOIN {$wpdb->users} u ON sl.user_id = u.ID
     ORDER BY sl.created_at DESC
     LIMIT %d",
    10
));
// Get user logs using direct database query since user logger doesn't have get_logs method
$recent_user_logs = $wpdb->get_results($wpdb->prepare(
    "SELECT ul.*, u.display_name as user_name
     FROM {$wpdb->prefix}insurance_user_logs ul
     LEFT JOIN {$wpdb->users} u ON ul.user_id = u.ID
     ORDER BY ul.created_at DESC
     LIMIT %d",
    10
));

// Get statistics
$total_system_logs = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}insurance_system_logs");
$total_user_logs = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}insurance_user_logs");
$today_system_logs = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_system_logs WHERE DATE(created_at) = %s",
    current_time('Y-m-d')
));
$today_user_logs = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_user_logs WHERE DATE(created_at) = %s", 
    current_time('Y-m-d')
));

// Get failed login attempts in last 24 hours
$failed_logins_24h = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_user_logs 
     WHERE action = 'failed_login' AND created_at > %s",
    date('Y-m-d H:i:s', strtotime('-24 hours'))
));

// Get active users (last 15 minutes)
$active_users = get_users(array(
    'role__in' => array('insurance_representative', 'administrator'),
    'meta_query' => array(
        array(
            'key' => '_user_last_activity',
            'value' => time() - (15 * 60), // 15 minutes ago
            'compare' => '>',
            'type' => 'NUMERIC'
        )
    ),
    'fields' => array('ID', 'display_name'),
    'number' => 20 // Limit to 20 users
));

// Get user meta for last activity times
foreach ($active_users as $active_user) {
    $last_activity = get_user_meta($active_user->ID, '_user_last_activity', true);
    $active_user->last_activity = $last_activity ? intval($last_activity) : 0;
}

?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

    <!-- Dashboard Enhancement Assets -->
    <link rel="stylesheet" href="<?php echo plugins_url('insurance-crm/assets/css/dashboard-updates.css'); ?>">
    <script src="<?php echo plugins_url('insurance-crm/assets/js/dashboard-widgets.js'); ?>" defer></script>

    <!-- Sayfa Loader CSS ve JS -->
    <link rel="stylesheet" href="<?php echo plugin_dir_url(__FILE__) . 'loader.css'; ?>">
    <script src="<?php echo plugin_dir_url(__FILE__) . 'loader.js'; ?>"></script>

    <?php wp_head(); ?>
    
    <style>
        * {
            box-sizing: border-box;
        }
        
        body.insurance-crm-page {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            margin: 0;
            padding: 20px 0;
        }
        
        .patron-dashboard {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .dashboard-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            color: #2c3e50;
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .dashboard-header h1 {
            margin: 0;
            font-size: 2em;
            font-weight: 600;
            color: #2c3e50;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }
        
        .dashboard-header h1 i {
            color: #667eea;
            font-size: 0.9em;
        }
        
        .dashboard-header .subtitle {
            margin-top: 8px;
            color: #7f8c8d;
            font-size: 1em;
            font-weight: 400;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 20px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--card-color), var(--card-color-light));
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .stat-card.system { 
            --card-color: #3498db; 
            --card-color-light: #5dade2;
        }
        .stat-card.user { 
            --card-color: #2ecc71; 
            --card-color-light: #58d68d;
        }
        .stat-card.security { 
            --card-color: #e74c3c; 
            --card-color-light: #ec7063;
        }
        .stat-card.activity { 
            --card-color: #f39c12; 
            --card-color-light: #f7b731;
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            flex-shrink: 0;
        }
        
        .stat-card.system .stat-icon {
            background: linear-gradient(135deg, #3498db, #5dade2);
        }
        
        .stat-card.user .stat-icon {
            background: linear-gradient(135deg, #2ecc71, #58d68d);
        }
        
        .stat-card.activity .stat-icon {
            background: linear-gradient(135deg, #f39c12, #f7b731);
        }
        
        .stat-card.security .stat-icon {
            background: linear-gradient(135deg, #e74c3c, #ec7063);
        }
        
        .stat-content h3 {
            margin: 0 0 8px 0;
            font-size: 14px;
            font-weight: 500;
            color: #6c757d;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
            margin: 0;
        }
            letter-spacing: 0.5px;
        }
        
        .active-users-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .active-users-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            color: #2c3e50;
        }
        
        .active-users-header h3 {
            margin: 0;
            font-size: 1.4em;
            font-weight: 600;
        }
        
        .active-users-header i {
            color: #27ae60;
            font-size: 1.2em;
        }
        
        .active-users-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }
        
        .active-user-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 3px solid #27ae60;
            transition: all 0.2s ease;
        }
        
        .active-user-item:hover {
            background: #e8f5e8;
            transform: translateX(3px);
        }
        
        .user-status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #27ae60;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .user-info {
            flex: 1;
        }
        
        .user-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 2px;
        }
        
        .user-activity-time {
            font-size: 0.85em;
            color: #7f8c8d;
        }
        
        .tabs-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
        }
        
        .tab-buttons {
            display: flex;
            background: rgba(248, 249, 250, 0.8);
            border-bottom: 1px solid rgba(222, 226, 230, 0.5);
        }
        
        .tab-button {
            flex: 1;
            padding: 18px 24px;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95em;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            color: #6c757d;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .tab-button::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            width: 0;
            height: 3px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            transition: all 0.3s ease;
            transform: translateX(-50%);
        }
        
        .tab-button.active {
            background: rgba(255, 255, 255, 0.9);
            color: #495057;
        }
        
        .tab-button.active::after {
            width: 60%;
        }
        
        .tab-button:hover {
            background: rgba(255, 255, 255, 0.7);
            color: #495057;
        }
        
        .tab-content {
            padding: 30px;
            min-height: 500px;
        }
        
        .log-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 25px;
            font-size: 0.9em;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
        }
        
        .log-table th,
        .log-table td {
            padding: 16px 18px;
            text-align: left;
            border-bottom: 1px solid rgba(241, 241, 241, 0.8);
        }
        
        .log-table th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            font-weight: 700;
            color: #495057;
            font-size: 0.8em;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        
        .log-table tbody tr {
            transition: all 0.2s ease;
        }
        
        .log-table tbody tr:hover {
            background: rgba(102, 126, 234, 0.05);
            transform: scale(1.001);
        }
        
        .log-table tr:last-child td {
            border-bottom: none;
        }
        
        .action-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .action-badge.create { 
            background: linear-gradient(135deg, #d4edda, #c3e6cb); 
            color: #155724; 
        }
        .action-badge.update { 
            background: linear-gradient(135deg, #d1ecf1, #bee5eb); 
            color: #0c5460; 
        }
        .action-badge.delete { 
            background: linear-gradient(135deg, #f8d7da, #f5c6cb); 
            color: #721c24; 
        }
        .action-badge.login { 
            background: linear-gradient(135deg, #d1ecf1, #bee5eb); 
            color: #0c5460; 
        }
        .action-badge.logout { 
            background: linear-gradient(135deg, #e2e3e5, #d6d8db); 
            color: #383d41; 
        }
        .action-badge.failed_login { 
            background: linear-gradient(135deg, #f8d7da, #f5c6cb); 
            color: #721c24; 
        }
        
        .loading {
            text-align: center;
            padding: 50px;
            color: #6c757d;
            font-size: 1.1em;
        }
        
        .loading i {
            font-size: 1.5em;
            margin-right: 10px;
            color: #667eea;
        }
        
        .refresh-button {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 25px;
            cursor: pointer;
            margin-bottom: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .refresh-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        
        .refresh-button i {
            margin-right: 8px;
        }
        
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: rgba(255, 255, 255, 0.95);
            color: #495057;
            text-decoration: none;
            border-radius: 25px;
            margin-bottom: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }
        
        .back-button:hover {
            background: rgba(255, 255, 255, 1);
            color: #495057;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }
        
        .no-active-users {
            text-align: center;
            padding: 30px;
            color: #7f8c8d;
            font-style: italic;
        }
        
        @media (max-width: 768px) {
            .patron-dashboard {
                padding: 0 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .stat-card {
                flex-direction: column;
                text-align: center;
                gap: 12px;
            }
            
            .active-users-list {
                grid-template-columns: 1fr;
            }
            
            .tab-buttons {
                flex-direction: column;
            }
            
            .dashboard-header h1 {
                font-size: 1.6em;
            }
        }
    </style>
</head>

<body class="insurance-crm-page">

<?php 
// Sayfa Loader'ını dahil et
include_once __DIR__ . '/loader.php'; 
?>

<div class="patron-dashboard">
    
    <div class="dashboard-header">
        <h1><i class="fas fa-clipboard-list"></i> Patron Log Kontrol Paneli</h1>
        <div class="subtitle">Sistem ve kullanıcı aktivitelerini izleyin ve güvenlik durumunu kontrol edin</div>
    </div>

    <div class="stats-grid">
        <div class="stat-card system">
            <div class="stat-icon">
                <i class="fas fa-cogs"></i>
            </div>
            <div class="stat-content">
                <h3>Sistem Logları</h3>
                <div class="stat-value"><?php echo number_format($total_system_logs); ?></div>
            </div>
        </div>
        <div class="stat-card user">
            <div class="stat-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-content">
                <h3>Kullanıcı Logları</h3>
                <div class="stat-value"><?php echo number_format($total_user_logs); ?></div>
            </div>
        </div>
        <div class="stat-card activity">
            <div class="stat-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="stat-content">
                <h3>Bugünkü Aktivite</h3>
                <div class="stat-value"><?php echo number_format($today_system_logs + $today_user_logs); ?></div>
            </div>
        </div>
        <div class="stat-card security">
            <div class="stat-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-content">
                <h3>Başarısız Giriş</h3>
                <div class="stat-value"><?php echo number_format($failed_logins_24h); ?></div>
            </div>
        </div>
    </div>

    <!-- Active Users Section -->
    <div class="active-users-section">
        <div class="active-users-header">
            <i class="fas fa-users"></i>
            <h3>Aktif Kullanıcılar</h3>
            <span style="margin-left: auto; font-size: 0.9em; color: #7f8c8d;">(Son 15 dakika)</span>
        </div>
        
        <?php if (!empty($active_users)): ?>
            <div class="active-users-list">
                <?php foreach ($active_users as $active_user): ?>
                    <?php 
                    $time_diff = time() - $active_user->last_activity;
                    if ($time_diff < 60) {
                        $activity_text = "Şimdi aktif";
                    } else {
                        $minutes = floor($time_diff / 60);
                        $activity_text = $minutes . " dakika önce";
                    }
                    ?>
                    <div class="active-user-item">
                        <div class="user-status-dot"></div>
                        <div class="user-info">
                            <div class="user-name"><?php echo esc_html($active_user->display_name); ?></div>
                            <div class="user-activity-time"><?php echo esc_html($activity_text); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-active-users">
                <i class="fas fa-moon" style="font-size: 2em; margin-bottom: 10px; color: #bdc3c7;"></i>
                <p>Şu anda aktif kullanıcı bulunmuyor.</p>
                <small>Kullanıcılar son 15 dakika içinde sistemde işlem yaptıklarında burada görünecekler.</small>
            </div>
        <?php endif; ?>
    </div>

    <div class="tabs-container">
        <div class="tab-buttons">
            <button class="tab-button <?php echo $current_tab === 'system' ? 'active' : ''; ?>" onclick="switchTab('system')">
                <i class="fas fa-cogs"></i> Sistem Logları
            </button>
            <button class="tab-button <?php echo $current_tab === 'user' ? 'active' : ''; ?>" onclick="switchTab('user')">
                <i class="fas fa-users"></i> Kullanıcı Logları
            </button>
            <button class="tab-button <?php echo $current_tab === 'security' ? 'active' : ''; ?>" onclick="switchTab('security')">
                <i class="fas fa-shield-alt"></i> Güvenlik Raporu
            </button>
        </div>

        <div class="tab-content">
            <button class="refresh-button" onclick="refreshCurrentTab()">
                <i class="fas fa-sync-alt"></i> Yenile
            </button>
            
            <div id="system-tab" class="tab-panel" style="display: <?php echo $current_tab === 'system' ? 'block' : 'none'; ?>">
                <h3>Son Sistem Aktiviteleri</h3>
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Zaman</th>
                            <th>Kullanıcı</th>
                            <th>İşlem</th>
                            <th>Detaylar</th>
                        </tr>
                    </thead>
                    <tbody id="system-logs-body">
                        <?php if (!empty($recent_system_logs)): ?>
                            <?php foreach ($recent_system_logs as $log): ?>
                                <tr>
                                    <td><?php echo esc_html(date('d.m.Y H:i', strtotime($log->created_at))); ?></td>
                                    <td><?php echo esc_html($log->user_name ?: 'Sistem'); ?></td>
                                    <td><span class="action-badge <?php echo esc_attr($log->action); ?>"><?php echo esc_html($log->action); ?></span></td>
                                    <td><?php echo esc_html($log->table_name . ($log->details ? ': ' . wp_trim_words($log->details, 8) : '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4">Henüz sistem logu bulunmuyor.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="user-tab" class="tab-panel" style="display: <?php echo $current_tab === 'user' ? 'block' : 'none'; ?>">
                <h3>Son Kullanıcı Aktiviteleri</h3>
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Zaman</th>
                            <th>Kullanıcı</th>
                            <th>İşlem</th>
                            <th>IP Adresi</th>
                            <th>Tarayıcı</th>
                        </tr>
                    </thead>
                    <tbody id="user-logs-body">
                        <?php if (!empty($recent_user_logs)): ?>
                            <?php foreach ($recent_user_logs as $log): ?>
                                <tr>
                                    <td><?php echo esc_html(date('d.m.Y H:i', strtotime($log->created_at))); ?></td>
                                    <td><?php echo esc_html($log->user_name ?: 'Bilinmeyen'); ?></td>
                                    <td><span class="action-badge <?php echo esc_attr($log->action); ?>"><?php echo esc_html($log->action); ?></span></td>
                                    <td><?php echo esc_html($log->ip_address); ?></td>
                                    <td><?php echo esc_html(wp_trim_words($log->browser ?: 'Bilinmiyor', 2)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5">Henüz kullanıcı logu bulunmuyor.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="security-tab" class="tab-panel" style="display: <?php echo $current_tab === 'security' ? 'block' : 'none'; ?>">
                <h3>Güvenlik Durumu</h3>
                
                <div class="stats-grid">
                    <div class="stat-card security">
                        <div class="stat-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Başarısız Giriş</h3>
                            <div class="stat-value"><?php echo number_format($failed_logins_24h); ?></div>
                        </div>
                    </div>
                    <div class="stat-card user">
                        <div class="stat-icon">
                            <i class="fas fa-sign-in-alt"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Bugün Giriş</h3>
                            <div class="stat-value"><?php echo number_format($today_user_logs); ?></div>
                        </div>
                    </div>
                </div>

                <?php if ($failed_logins_24h > 0): ?>
                    <h4>Son Başarısız Giriş Denemeleri</h4>
                    <?php
                    $failed_logins = $wpdb->get_results($wpdb->prepare(
                        "SELECT ul.*, u.display_name as user_name
                         FROM {$wpdb->prefix}insurance_user_logs ul
                         LEFT JOIN {$wpdb->users} u ON ul.user_id = u.ID
                         WHERE ul.action = 'failed_login' AND ul.created_at > %s
                         ORDER BY ul.created_at DESC LIMIT 10",
                        date('Y-m-d H:i:s', strtotime('-24 hours'))
                    ));
                    ?>
                    <table class="log-table">
                        <thead>
                            <tr>
                                <th>Zaman</th>
                                <th>Kullanıcı</th>
                                <th>IP Adresi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($failed_logins as $failed): ?>
                                <tr>
                                    <td><?php echo esc_html(date('d.m.Y H:i', strtotime($failed->created_at))); ?></td>
                                    <td><?php echo esc_html($failed->user_name ?: 'Bilinmeyen'); ?></td>
                                    <td><?php echo esc_html($failed->ip_address); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #28a745;">
                        <i class="fas fa-shield-alt" style="font-size: 3em; margin-bottom: 20px;"></i>
                        <h4>Güvenlik Durumu İyi</h4>
                        <p>Son 24 saatte hiç başarısız giriş denemesi tespit edilmedi.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function switchTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-panel').forEach(panel => {
        panel.style.display = 'none';
    });
    
    // Remove active class from all buttons
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active');
    });
    
    // Show selected tab
    document.getElementById(tabName + '-tab').style.display = 'block';
    
    // Add active class to clicked button
    event.target.classList.add('active');
    
    // Update URL
    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    window.history.replaceState({}, '', url);
}

function refreshCurrentTab() {
    const activeTab = document.querySelector('.tab-button.active');
    if (!activeTab) return;
    
    const tabName = activeTab.textContent.includes('Sistem') ? 'system' : 
                   activeTab.textContent.includes('Kullanıcı') ? 'user' : 'security';
    
    // Show loading state
    const tbody = document.getElementById(tabName + '-logs-body');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="5" class="loading"><i class="fas fa-spinner fa-spin"></i> Yükleniyor...</td></tr>';
    }
    
    // Reload the page to get fresh data
    setTimeout(() => {
        window.location.reload();
    }, 500);
}

// Auto refresh every 5 minutes
setInterval(() => {
    console.log('Auto refreshing logs...');
    refreshCurrentTab();
}, 300000);
</script>

<?php wp_footer(); ?>
</body>
</html>
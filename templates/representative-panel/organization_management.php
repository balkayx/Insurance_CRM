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

// Sadece patron erişebilir
$representative = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}insurance_crm_representatives 
     WHERE user_id = %d AND status = 'active'",
    $current_user->ID
));

if (!$representative) {
    wp_die('Müşteri temsilcisi kaydınız bulunamadı veya hesabınız pasif durumda.');
}

// Patron kontrolü
function is_patron($user_id) {
    global $wpdb;
    $role_value = $wpdb->get_var($wpdb->prepare(
        "SELECT role FROM {$wpdb->prefix}insurance_crm_representatives 
         WHERE user_id = %d AND status = 'active'",
        $user_id
    ));
    return intval($role_value) === 1;
}

if (!is_patron($current_user->ID)) {
    wp_die('Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Alt sayfa kontrolü
$management_section = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'overview';
$item_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Rol tanımları
$role_definitions = [
    1 => 'Patron',
    2 => 'Müdür', 
    3 => 'Müdür Yardımcısı',
    4 => 'Ekip Lideri',
    5 => 'Müşteri Temsilcisi'
];

// Mesaj sistemi
$message = '';
$message_type = '';

// Veritabanı güncelleme fonksiyonu
function update_representative_database_fields() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_representatives';
    
    // Gerekli sütunları kontrol et ve ekle
    $columns_to_check = [
        'role' => "INT NOT NULL DEFAULT 5 AFTER `user_id`",
        'target_policy_count' => "INT NOT NULL DEFAULT 0 AFTER `monthly_target`",
        'avatar_url' => "VARCHAR(255) DEFAULT '' AFTER `target_policy_count`",
        'customer_edit' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `avatar_url`",
        'customer_delete' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `customer_edit`",
        'policy_edit' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `customer_delete`",
        'policy_delete' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `policy_edit`"
    ];
    
    foreach ($columns_to_check as $column => $definition) {
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE '{$column}'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN `{$column}` {$definition}");
        }
    }
}

// Veritabanını güncelle
update_representative_database_fields();

// Form işleme fonksiyonları
function handle_representative_form() {
    global $wpdb, $role_definitions;
    
    $editing = isset($_POST['rep_id']) && !empty($_POST['rep_id']);
    $rep_id = $editing ? intval($_POST['rep_id']) : 0;
    
    // Avatar yükleme işlemi
    $avatar_url = '';
    if (isset($_FILES['avatar_file']) && !empty($_FILES['avatar_file']['name'])) {
        $file = $_FILES['avatar_file'];
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        
        if (in_array($file['type'], $allowed_types) && $file['size'] <= 5 * 1024 * 1024) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            
            $attachment_id = media_handle_upload('avatar_file', 0);
            if (!is_wp_error($attachment_id)) {
                $avatar_url = wp_get_attachment_url($attachment_id);
            }
        }
    }
    
    // Yetkilendirme değerleri
    $customer_edit = isset($_POST['customer_edit']) ? 1 : 0;
    $customer_delete = isset($_POST['customer_delete']) ? 1 : 0;
    $policy_edit = isset($_POST['policy_edit']) ? 1 : 0;
    $policy_delete = isset($_POST['policy_delete']) ? 1 : 0;
    
    if ($editing) {
        // Güncelleme işlemi
        $rep_data = [
            'role' => intval($_POST['role']),
            'title' => sanitize_text_field($_POST['title']),
            'phone' => sanitize_text_field($_POST['phone']),
            'department' => sanitize_text_field($_POST['department']),
            'monthly_target' => floatval($_POST['monthly_target']),
            'target_policy_count' => intval($_POST['target_policy_count']),
            'customer_edit' => $customer_edit,
            'customer_delete' => $customer_delete,
            'policy_edit' => $policy_edit,
            'policy_delete' => $policy_delete,
            'updated_at' => current_time('mysql')
        ];
        
        if (!empty($avatar_url)) {
            $rep_data['avatar_url'] = $avatar_url;
        }
        
        $result = $wpdb->update(
            $wpdb->prefix . 'insurance_crm_representatives',
            $rep_data,
            ['id' => $rep_id]
        );
        
        if ($result !== false) {
            // Kullanıcı bilgilerini güncelle
            $user_data = $wpdb->get_row($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}insurance_crm_representatives WHERE id = %d",
                $rep_id
            ));
            
            if ($user_data) {
                wp_update_user([
                    'ID' => $user_data->user_id,
                    'first_name' => sanitize_text_field($_POST['first_name']),
                    'last_name' => sanitize_text_field($_POST['last_name']),
                    'display_name' => sanitize_text_field($_POST['first_name']) . ' ' . sanitize_text_field($_POST['last_name']),
                    'user_email' => sanitize_email($_POST['email'])
                ]);
                
                // Şifre değiştirme
                if (!empty($_POST['password']) && !empty($_POST['confirm_password']) && $_POST['password'] === $_POST['confirm_password']) {
                    wp_set_password($_POST['password'], $user_data->user_id);
                }
            }
            
            return ['message' => 'Temsilci başarıyla güncellendi.', 'type' => 'success'];
        } else {
            return ['message' => 'Güncelleme sırasında bir hata oluştu.', 'type' => 'error'];
        }
    } else {
        // Yeni temsilci ekleme
        $username = sanitize_user($_POST['username']);
        $password = $_POST['password'];
        $email = sanitize_email($_POST['email']);
        
        if (username_exists($username)) {
            return ['message' => 'Bu kullanıcı adı zaten kullanımda.', 'type' => 'error'];
        }
        
        if (email_exists($email)) {
            return ['message' => 'Bu e-posta adresi zaten kullanımda.', 'type' => 'error'];
        }
        
        if ($password !== $_POST['confirm_password']) {
            return ['message' => 'Şifreler eşleşmiyor.', 'type' => 'error'];
        }
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (!is_wp_error($user_id)) {
            wp_update_user([
                'ID' => $user_id,
                'first_name' => sanitize_text_field($_POST['first_name']),
                'last_name' => sanitize_text_field($_POST['last_name']),
                'display_name' => sanitize_text_field($_POST['first_name']) . ' ' . sanitize_text_field($_POST['last_name'])
            ]);
            
            $user = new WP_User($user_id);
            $user->set_role('insurance_representative');
            
            $selected_role = intval($_POST['role']);
            $title = isset($role_definitions[$selected_role]) ? $role_definitions[$selected_role] : 'Müşteri Temsilcisi';
            
            $insert_result = $wpdb->insert(
                $wpdb->prefix . 'insurance_crm_representatives',
                [
                    'user_id' => $user_id,
                    'role' => $selected_role,
                    'title' => $title,
                    'phone' => sanitize_text_field($_POST['phone']),
                    'department' => sanitize_text_field($_POST['department']),
                    'monthly_target' => floatval($_POST['monthly_target']),
                    'target_policy_count' => intval($_POST['target_policy_count']),
                    'avatar_url' => $avatar_url,
                    'customer_edit' => $customer_edit,
                    'customer_delete' => $customer_delete,
                    'policy_edit' => $policy_edit,
                    'policy_delete' => $policy_delete,
                    'status' => 'active',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ]
            );
            
            if ($insert_result !== false) {
                return ['message' => 'Yeni temsilci başarıyla eklendi.', 'type' => 'success'];
            } else {
                return ['message' => 'Temsilci kaydı oluşturulurken bir hata oluştu.', 'type' => 'error'];
            }
        } else {
            return ['message' => 'Kullanıcı oluşturulurken bir hata oluştu: ' . $user_id->get_error_message(), 'type' => 'error'];
        }
    }
}

function handle_team_form() {
    $team_name = sanitize_text_field($_POST['team_name']);
    $team_leader_id = intval($_POST['team_leader_id']);
    $team_members = isset($_POST['team_members']) ? array_map('intval', $_POST['team_members']) : [];
    $team_id = isset($_POST['team_id']) ? sanitize_text_field($_POST['team_id']) : 'team_' . uniqid();
    
    $settings = get_option('insurance_crm_settings', []);
    if (!isset($settings['teams_settings'])) {
        $settings['teams_settings'] = ['teams' => []];
    }
    
    $settings['teams_settings']['teams'][$team_id] = [
        'name' => $team_name,
        'leader_id' => $team_leader_id,
        'members' => $team_members
    ];
    
    $result = update_option('insurance_crm_settings', $settings);
    
    if ($result) {
        return ['message' => 'Ekip başarıyla kaydedildi.', 'type' => 'success'];
    } else {
        return ['message' => 'Ekip kaydedilirken bir hata oluştu.', 'type' => 'error'];
    }
}

function handle_hierarchy_form() {
    $patron_id = isset($_POST['patron_id']) ? intval($_POST['patron_id']) : 0;
    $manager_id = isset($_POST['manager_id']) ? intval($_POST['manager_id']) : 0;
    $assistant_manager_ids = isset($_POST['assistant_manager_ids']) ? array_map('intval', $_POST['assistant_manager_ids']) : [];
    
    $settings = get_option('insurance_crm_settings', []);
    $settings['management_hierarchy'] = [
        'patron_id' => $patron_id,
        'manager_id' => $manager_id,
        'assistant_manager_ids' => $assistant_manager_ids,
        'updated_at' => current_time('mysql')
    ];
    
    $result = update_option('insurance_crm_settings', $settings);
    
    if ($result) {
        return ['message' => 'Yönetim hiyerarşisi başarıyla güncellendi.', 'type' => 'success'];
    } else {
        return ['message' => 'Hiyerarşi güncellenirken bir hata oluştu.', 'type' => 'error'];
    }
}

// Form işlemleri
if (isset($_POST['submit_representative']) && wp_verify_nonce($_POST['representative_nonce'], 'manage_representative')) {
    $result = handle_representative_form();
    $message = $result['message'];
    $message_type = $result['type'];
}

if (isset($_POST['submit_team']) && wp_verify_nonce($_POST['team_nonce'], 'manage_team')) {
    $result = handle_team_form();
    $message = $result['message'];
    $message_type = $result['type'];
}

if (isset($_POST['submit_hierarchy']) && wp_verify_nonce($_POST['hierarchy_nonce'], 'manage_hierarchy')) {
    $result = handle_hierarchy_form();
    $message = $result['message'];
    $message_type = $result['type'];
}

// Silme işlemi
if ($management_section === 'delete_representative' && $item_id > 0 && isset($_GET['_wpnonce'])) {
    if (wp_verify_nonce($_GET['_wpnonce'], 'delete_representative_' . $item_id)) {
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}insurance_crm_representatives WHERE id = %d",
            $item_id
        ));
        
        if ($user_id) {
            require_once(ABSPATH . 'wp-admin/includes/user.php');
            if (wp_delete_user($user_id)) {
                $wpdb->delete($wpdb->prefix . 'insurance_crm_representatives', ['id' => $item_id]);
                $message = 'Temsilci başarıyla silindi.';
                $message_type = 'success';
            } else {
                $message = 'Temsilci silinirken bir hata oluştu.';
                $message_type = 'error';
            }
        }
    }
}

// Ekip silme işlemi
if ($management_section === 'delete_team' && isset($_GET['team_id']) && isset($_GET['_wpnonce'])) {
    $team_id = sanitize_text_field($_GET['team_id']);
    if (wp_verify_nonce($_GET['_wpnonce'], 'delete_team_' . $team_id)) {
        $settings = get_option('insurance_crm_settings', []);
        if (isset($settings['teams_settings']['teams'][$team_id])) {
            unset($settings['teams_settings']['teams'][$team_id]);
            update_option('insurance_crm_settings', $settings);
            $message = 'Ekip başarıyla silindi.';
            $message_type = 'success';
        }
    }
}

// Verileri çek
$representatives = $wpdb->get_results(
    "SELECT r.*, u.user_email, u.display_name, u.user_login 
     FROM {$wpdb->prefix}insurance_crm_representatives r 
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
     WHERE r.status = 'active' 
     ORDER BY r.role ASC, u.display_name ASC"
);

$settings = get_option('insurance_crm_settings', []);
$teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : [];
$management_hierarchy = isset($settings['management_hierarchy']) ? $settings['management_hierarchy'] : [
    'patron_id' => 0,
    'manager_id' => 0,
    'assistant_manager_ids' => []
];

// Düzenleme için veri alma
$edit_representative = null;
$edit_team = null;
$edit_team_id = '';

if ($management_section === 'edit_representative' && $item_id > 0) {
    $edit_representative = $wpdb->get_row($wpdb->prepare(
        "SELECT r.*, u.user_email, u.display_name, u.user_login, u.first_name, u.last_name
         FROM {$wpdb->prefix}insurance_crm_representatives r 
         LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
         WHERE r.id = %d",
        $item_id
    ));
}

if ($management_section === 'edit_team' && isset($_GET['team_id'])) {
    $edit_team_id = sanitize_text_field($_GET['team_id']);
    if (isset($teams[$edit_team_id])) {
        $edit_team = $teams[$edit_team_id];
    }
}

function generate_panel_url($view, $action = '', $id = '', $additional_params = []) {
    $base_url = get_permalink();
    $query_args = [];
    
    if ($view !== 'dashboard') {
        $query_args['view'] = $view;
    }
    
    if (!empty($action)) {
        $query_args['action'] = $action;
    }
    
    if (!empty($id)) {
        $query_args['id'] = $id;
    }
    
    if (!empty($additional_params) && is_array($additional_params)) {
        $query_args = array_merge($query_args, $additional_params);
    }
    
    if (empty($query_args)) {
        return $base_url;
    }
    
    return add_query_arg($query_args, $base_url);
}
?>

<div class="main-content management-panel">
    <!-- Header -->
    <div class="panel-header">
        <div class="header-content">
            <div class="header-left">
                <h1 class="panel-title">
                    <i class="icon-management"></i>
                    Yönetim Paneli
                </h1>
                <p class="panel-subtitle">Organizasyon yönetimi ve ayarları</p>
            </div>
            <div class="header-actions">
                <a href="<?php echo generate_panel_url('organization'); ?>" class="btn btn-outline">
                    <i class="icon-back"></i>
                    <span>Geri Dön</span>
                </a>
            </div>
        </div>
    </div>

    <?php if (!empty($message)): ?>
    <div class="message message-<?php echo $message_type; ?>">
        <i class="message-icon"></i>
        <span><?php echo esc_html($message); ?></span>
        <button class="message-close" onclick="this.parentElement.style.display='none'">×</button>
    </div>
    <?php endif; ?>

    <!-- Management Navigation -->
    <div class="management-nav">
        <nav class="nav-tabs">
            <a href="<?php echo generate_panel_url('organization_management', 'overview'); ?>" 
               class="nav-tab <?php echo $management_section === 'overview' ? 'active' : ''; ?>">
                <i class="icon-overview"></i>
                <span>Genel Bakış</span>
            </a>
            <a href="<?php echo generate_panel_url('organization_management', 'representatives'); ?>" 
               class="nav-tab <?php echo in_array($management_section, ['representatives', 'new_representative', 'edit_representative']) ? 'active' : ''; ?>">
                <i class="icon-users"></i>
                <span>Temsilciler</span>
            </a>
            <a href="<?php echo generate_panel_url('organization_management', 'teams'); ?>" 
               class="nav-tab <?php echo in_array($management_section, ['teams', 'new_team', 'edit_team']) ? 'active' : ''; ?>">
                <i class="icon-teams"></i>
                <span>Ekipler</span>
            </a>
            <a href="<?php echo generate_panel_url('organization_management', 'hierarchy'); ?>" 
               class="nav-tab <?php echo $management_section === 'hierarchy' ? 'active' : ''; ?>">
                <i class="icon-hierarchy"></i>
                <span>Hiyerarşi</span>
            </a>
        </nav>
    </div>

    <!-- Content Area -->
    <div class="management-content">
        <?php
        switch ($management_section) {
            case 'overview':
                include 'management-sections/overview.php';
                break;
            case 'representatives':
            case 'new_representative':
            case 'edit_representative':
                include 'management-sections/representatives.php';
                break;
            case 'teams':
            case 'new_team':
            case 'edit_team':
                include 'management-sections/teams.php';
                break;
            case 'hierarchy':
                include 'management-sections/hierarchy.php';
                break;
            default:
                include 'management-sections/overview.php';
        }
        ?>
    </div>
</div>

<style>
/* Modern Management Panel Styles */
.management-panel {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0;
}

/* Panel Header */
.panel-header {
    background: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);
    color: white;
    padding: 2rem;
    border-radius: 12px;
    margin-bottom: 2rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.panel-title {
    font-size: 2rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.panel-title .icon-management::before {
    content: "⚙️";
    font-size: 1.5rem;
}

.panel-subtitle {
    margin: 0.5rem 0 0;
    opacity: 0.9;
    font-size: 1.1rem;
}

/* Message System */
.message {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 2rem;
    position: relative;
}

.message-success {
    background: #f0fff4;
    color: #22543d;
    border: 1px solid #9ae6b4;
}

.message-error {
    background: #fed7d7;
    color: #742a2a;
    border: 1px solid #feb2b2;
}

.message-icon::before {
    font-size: 1.2rem;
}

.message-success .message-icon::before {
    content: "✅";
}

.message-error .message-icon::before {
    content: "❌";
}

.message-close {
    position: absolute;
    top: 0.5rem;
    right: 1rem;
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: inherit;
    opacity: 0.7;
}

.message-close:hover {
    opacity: 1;
}

/* Navigation Tabs */
.management-nav {
    margin-bottom: 2rem;
}

.nav-tabs {
    display: flex;
    background: white;
    border-radius: 12px;
    padding: 0.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    overflow-x: auto;
    gap: 0.25rem;
}

.nav-tab {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    color: #718096;
    transition: all 0.3s ease;
    white-space: nowrap;
    min-width: 0;
    flex: 1;
    justify-content: center;
}

.nav-tab.active {
    background: linear-gradient(135deg, #2d3748, #4a5568);
    color: white;
    box-shadow: 0 2px 10px rgba(45, 55, 72, 0.3);
}

.nav-tab:not(.active):hover {
    background: #f7fafc;
    color: #4a5568;
}

.nav-tab .icon-overview::before { content: "📊"; }
.nav-tab .icon-users::before { content: "👤"; }
.nav-tab .icon-teams::before { content: "👥"; }
.nav-tab .icon-hierarchy::before { content: "🏗️"; }

/* Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
    cursor: pointer;
    font-size: 0.95rem;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
    color: white;
}

.btn-outline {
    background: transparent;
    color: #2d3748;
    border: 2px solid #2d3748;
}

.btn-outline:hover {
    background: #2d3748;
    color: white;
    transform: translateY(-1px);
}

.btn-success {
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
    box-shadow: 0 4px 15px rgba(72, 187, 120, 0.3);
}

.btn-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(72, 187, 120, 0.4);
    color: white;
}

.btn-danger {
    background: linear-gradient(135deg, #f56565, #e53e3e);
    color: white;
    box-shadow: 0 4px 15px rgba(245, 101, 101, 0.3);
}

.btn-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(245, 101, 101, 0.4);
    color: white;
}

.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

.btn-lg {
    padding: 1rem 2rem;
    font-size: 1.1rem;
}

/* Icons */
.btn .icon-back::before { content: "◀️"; }
.btn .icon-add::before { content: "➕"; }
.btn .icon-edit::before { content: "✏️"; }
.btn .icon-delete::before { content: "🗑️"; }
.btn .icon-save::before { content: "💾"; }

/* Content Area */
.management-content {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    min-height: 400px;
}

/* Form Styles */
.form-section {
    margin-bottom: 2rem;
}

.form-section h3 {
    font-size: 1.25rem;
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e2e8f0;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.form-label {
    font-weight: 600;
    color: #4a5568;
    font-size: 0.95rem;
}

.form-label .required {
    color: #e53e3e;
    margin-left: 0.25rem;
}

.form-input {
    padding: 0.75rem;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    font-size: 1rem;
    transition: border-color 0.3s ease;
}

.form-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.form-select {
    padding: 0.75rem;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    font-size: 1rem;
    background: white;
    transition: border-color 0.3s ease;
}

.form-select:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.form-textarea {
    padding: 0.75rem;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    font-size: 1rem;
    min-height: 100px;
    resize: vertical;
    transition: border-color 0.3s ease;
}

.form-textarea:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.form-checkbox {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem;
    background: #f7fafc;
    border-radius: 8px;
    border: 2px solid #e2e8f0;
    transition: all 0.3s ease;
}

.form-checkbox:hover {
    background: #edf2f7;
}

.form-checkbox input[type="checkbox"] {
    width: 1.25rem;
    height: 1.25rem;
    margin: 0;
}

.form-help {
    font-size: 0.875rem;
    color: #718096;
    margin-top: 0.25rem;
}

/* Table Styles */
.data-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1rem;
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.data-table th {
    background: #f7fafc;
    padding: 1rem;
    text-align: left;
    font-weight: 600;
    color: #4a5568;
    border-bottom: 2px solid #e2e8f0;
}

.data-table td {
    padding: 1rem;
    border-bottom: 1px solid #e2e8f0;
    vertical-align: top;
}

.data-table tbody tr:hover {
    background: #f7fafc;
}

.data-table tbody tr:last-child td {
    border-bottom: none;
}

/* Cards */
.card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #e2e8f0;
}

.card-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #2d3748;
    margin: 0;
}

.card-content {
    color: #4a5568;
    line-height: 1.6;
}

/* Responsive Design */
@media (max-width: 768px) {
    .management-panel {
        padding: 0 1rem;
    }
    
    .panel-header {
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }
    
    .header-content {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .panel-title {
        font-size: 1.5rem;
    }
    
    .nav-tabs {
        flex-direction: column;
        gap: 0;
    }
    
    .nav-tab {
        border-radius: 0;
        padding: 0.75rem 1rem;
    }
    
    .nav-tab:first-child {
        border-radius: 8px 8px 0 0;
    }
    
    .nav-tab:last-child {
        border-radius: 0 0 8px 8px;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .management-content {
        padding: 1rem;
    }
    
    .data-table {
        font-size: 0.875rem;
    }
    
    .data-table th,
    .data-table td {
        padding: 0.75rem 0.5rem;
    }
}

@media (max-width: 480px) {
    .btn {
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
    }
    
    .card {
        padding: 1rem;
    }
    
    .form-input,
    .form-select,
    .form-textarea {
        padding: 0.5rem;
    }
}

/* Loading Animation */
.loading {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid #f3f3f3;
    border-top: 3px solid #667eea;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Utilities */
.text-center { text-align: center; }
.text-right { text-align: right; }
.mb-0 { margin-bottom: 0; }
.mb-1 { margin-bottom: 0.5rem; }
.mb-2 { margin-bottom: 1rem; }
.mb-3 { margin-bottom: 1.5rem; }
.mb-4 { margin-bottom: 2rem; }
.mt-0 { margin-top: 0; }
.mt-1 { margin-top: 0.5rem; }
.mt-2 { margin-top: 1rem; }
.mt-3 { margin-top: 1.5rem; }
.mt-4 { margin-top: 2rem; }
.hidden { display: none; }
.flex { display: flex; }
.flex-wrap { flex-wrap: wrap; }
.items-center { align-items: center; }
.justify-between { justify-content: space-between; }
.gap-1 { gap: 0.5rem; }
.gap-2 { gap: 1rem; }
.gap-3 { gap: 1.5rem; }

/* Lisans Yönetimi Stilleri */
.license-management {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.license-status-section {
    margin-bottom: 2rem;
}

.license-status-section h3 {
    color: #2c3e50;
    margin-bottom: 1rem;
    font-size: 1.2rem;
}

.license-status-card {
    display: flex;
    align-items: center;
    padding: 1.5rem;
    border-radius: 8px;
    margin-bottom: 1rem;
    border: 2px solid transparent;
}

.license-status-card.active {
    background: linear-gradient(135deg, #e8f5e8 0%, #f0f9f0 100%);
    border-color: #28a745;
}

.license-status-card.inactive {
    background: linear-gradient(135deg, #fff5f5 0%, #fef5f5 100%);
    border-color: #dc3545;
}

.license-status-card.grace-period {
    background: linear-gradient(135deg, #fff8e1 0%, #fffbf0 100%);
    border-color: #ffc107;
}

.license-icon {
    font-size: 2rem;
    margin-right: 1rem;
    flex-shrink: 0;
}

.license-details h4 {
    margin: 0 0 0.5rem 0;
    color: #2c3e50;
}

.license-details p {
    margin: 0.25rem 0;
    color: #6c757d;
}

.license-management-form {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 8px;
    margin-bottom: 2rem;
}

.license-management-form h3 {
    color: #2c3e50;
    margin-bottom: 1.5rem;
    font-size: 1.2rem;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #2c3e50;
}

.form-control {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 0.9rem;
}

.form-control:focus {
    outline: none;
    border-color: #007cba;
    box-shadow: 0 0 0 2px rgba(0, 124, 186, 0.2);
}

.form-control:read-only {
    background-color: #f8f9fa;
    color: #6c757d;
}

.form-help {
    margin-top: 0.5rem;
    font-size: 0.8rem;
    color: #6c757d;
}

.license-status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
}

.license-status-badge.valid {
    background-color: #28a745;
    color: white;
}

.license-status-badge.invalid,
.license-status-badge.expired {
    background-color: #dc3545;
    color: white;
}

.license-status-badge.grace-period {
    background-color: #ffc107;
    color: #212529;
}

.limit-exceeded {
    color: #dc3545;
    font-weight: bold;
}

.grace-period-warning {
    color: #dc3545;
}

.form-actions {
    display: flex;
    gap: 1rem;
    align-items: center;
}

.btn {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 4px;
    font-size: 0.9rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-primary {
    background-color: #007cba;
    color: white;
}

.btn-primary:hover {
    background-color: #005a8b;
}

.btn-secondary {
    background-color: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background-color: #545b62;
}

.license-info-section {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 8px;
    border-left: 4px solid #007cba;
}

.license-info-section h3 {
    color: #2c3e50;
    margin-bottom: 1rem;
    font-size: 1.2rem;
}

.license-info-section h4 {
    color: #2c3e50;
    margin: 1rem 0 0.5rem 0;
    font-size: 1rem;
}

.license-contact a {
    color: #007cba;
    text-decoration: none;
}

.license-contact a:hover {
    text-decoration: underline;
}

.nav-tab .icon-license::before { 
    content: "🔐"; 
    margin-right: 0.5rem; 
}

@media (max-width: 768px) {
    .license-status-card {
        flex-direction: column;
        text-align: center;
        padding: 1rem;
    }
    
    .license-icon {
        margin-right: 0;
        margin-bottom: 0.5rem;
    }
    
    .form-actions {
        flex-direction: column;
        align-items: stretch;
    }
    
    .btn {
        margin-bottom: 0.5rem;
    }
}

</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Form validation
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.style.borderColor = '#e53e3e';
                    field.focus();
                } else {
                    field.style.borderColor = '#e2e8f0';
                }
            });
            
            // Password matching validation
            const password = form.querySelector('input[name="password"]');
            const confirmPassword = form.querySelector('input[name="confirm_password"]');
            
            if (password && confirmPassword && password.value && password.value !== confirmPassword.value) {
                e.preventDefault();
                alert('Şifreler eşleşmiyor!');
                confirmPassword.focus();
                return false;
            }
            
            if (!isValid) {
                e.preventDefault();
                alert('Lütfen tüm gerekli alanları doldurun.');
                return false;
            }
        });
    });
    
    // Smooth transitions
    const cards = document.querySelectorAll('.card');
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
    
    // Auto-hide messages
    const messages = document.querySelectorAll('.message');
    messages.forEach(message => {
        setTimeout(() => {
            message.style.opacity = '0';
            setTimeout(() => {
                message.style.display = 'none';
            }, 300);
        }, 5000);
    });
    
    // Confirm delete actions
    const deleteButtons = document.querySelectorAll('.btn-danger[href*="delete"]');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            if (!confirm('Bu işlemi gerçekleştirmek istediğinizden emin misiniz?')) {
                e.preventDefault();
                return false;
            }
        });
    });
});
</script>
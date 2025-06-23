<?php
/**
 * License Management Frontend Page
 * @version 1.0.0
 * @updated 2025-01-06
 * @description Frontend license management page under Help Desk
 */

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

// Include license manager
global $insurance_crm_license_manager;

// Process license form submission
$form_result = null;
$debug_info = array();

if (isset($_POST['license_submit']) && isset($_POST['license_nonce']) && wp_verify_nonce($_POST['license_nonce'], 'license_form')) {
    $license_key = sanitize_text_field($_POST['license_key']);
    
    if (!empty($license_key)) {
        // Activate license
        if ($insurance_crm_license_manager) {
            $debug_info[] = "Lisans yöneticisi yüklendi.";
            
            // Debug mode'u etkinleştir
            $old_debug_mode = get_option('insurance_crm_license_debug_mode', false);
            update_option('insurance_crm_license_debug_mode', true);
            
            $activation_result = $insurance_crm_license_manager->activate_license($license_key);
            
            // Debug mode'u eski haline getir
            update_option('insurance_crm_license_debug_mode', $old_debug_mode);
            
            if ($activation_result['success']) {
                $form_result = array(
                    'success' => true,
                    'message' => $activation_result['message']
                );
                $debug_info[] = "Lisans aktivasyonu başarılı.";
            } else {
                $form_result = array(
                    'success' => false,
                    'message' => $activation_result['message']
                );
                $debug_info[] = "Lisans aktivasyonu başarısız: " . $activation_result['message'];
            }
        } else {
            $form_result = array(
                'success' => false,
                'message' => 'Lisans yöneticisi yüklenemedi. Lütfen sistem yöneticinizle iletişime geçin.'
            );
            $debug_info[] = "Lisans yöneticisi yüklenemedi.";
        }
    } else {
        $form_result = array(
            'success' => false,
            'message' => 'Lütfen geçerli bir lisans anahtarı girin.'
        );
    }
}

// Debug mode toggle
if (isset($_POST['toggle_debug']) && isset($_POST['debug_nonce']) && wp_verify_nonce($_POST['debug_nonce'], 'debug_form')) {
    $current_debug = get_option('insurance_crm_license_debug_mode', false);
    update_option('insurance_crm_license_debug_mode', !$current_debug);
    $form_result = array(
        'success' => true,
        'message' => 'Debug modu ' . (!$current_debug ? 'etkinleştirildi' : 'devre dışı bırakıldı') . '.'
    );
}

// Server settings update
if (isset($_POST['update_server']) && isset($_POST['server_nonce']) && wp_verify_nonce($_POST['server_nonce'], 'server_form')) {
    $server_url = sanitize_url($_POST['license_server_url']);
    if (!empty($server_url)) {
        update_option('insurance_crm_license_server_url', $server_url);
        $form_result = array(
            'success' => true,
            'message' => 'Lisans sunucusu URL\'si güncellendi.'
        );
    } else {
        $form_result = array(
            'success' => false,
            'message' => 'Lütfen geçerli bir URL girin.'
        );
    }
}

// Test connection
if (isset($_POST['test_connection']) && isset($_POST['test_nonce']) && wp_verify_nonce($_POST['test_nonce'], 'test_form')) {
    if ($insurance_crm_license_manager && $insurance_crm_license_manager->license_api) {
        $license_key = get_option('insurance_crm_license_key', '');
        
        // Run comprehensive connection test
        $connection_test = $insurance_crm_license_manager->license_api->test_server_connection();
        
        $test_message = '<strong>Bağlantı Test Sonuçları:</strong><br>';
        
        // Basic connectivity
        if ($connection_test['connectivity']['success']) {
            $test_message .= '✅ Temel bağlantı: ' . $connection_test['connectivity']['message'] . '<br>';
        } else {
            $test_message .= '❌ Temel bağlantı: ' . $connection_test['connectivity']['message'] . '<br>';
        }
        
        // WordPress detection
        $test_message .= ($connection_test['is_wordpress'] ? '✅' : '❌') . ' WordPress API: ' . $connection_test['wordpress_api'] . '<br>';
        
        // Endpoint tests
        $test_message .= '<br><strong>Endpoint Test Sonuçları:</strong><br>';
        foreach ($connection_test['endpoints'] as $endpoint => $result) {
            $status = $result['accessible'] ? '✅' : '❌';
            $test_message .= $status . ' ' . $endpoint . ' (HTTP ' . ($result['code'] ?: 'N/A') . ')<br>';
        }
        
        // License validation test if license key exists
        if (!empty($license_key)) {
            $test_message .= '<br><strong>Lisans Doğrulama Testi:</strong><br>';
            $validation_result = $insurance_crm_license_manager->license_api->validate_license($license_key);
            
            if (is_wp_error($validation_result)) {
                $test_message .= '❌ Lisans doğrulama başarısız: ' . $validation_result->get_error_message();
            } else {
                $test_message .= '✅ Lisans doğrulama yanıtı alındı: ' . json_encode($validation_result);
            }
        } else {
            $test_message .= '<br>ℹ️ Lisans doğrulama testi için önce bir lisans anahtarı girin.';
        }
        
        $form_result = array(
            'success' => $connection_test['connectivity']['success'],
            'message' => $test_message
        );
    } else {
        $form_result = array(
            'success' => false,
            'message' => 'Lisans API sınıfı yüklenemedi.'
        );
    }
}



// Get license information
$license_info = array();
if ($insurance_crm_license_manager) {
    $license_info = $insurance_crm_license_manager->get_license_info();
} else {
    // Fallback - get from options directly
    $license_info = array(
        'key' => get_option('insurance_crm_license_key', ''),
        'status' => get_option('insurance_crm_license_status', 'inactive'),
        'type' => get_option('insurance_crm_license_type', ''),
        'package' => get_option('insurance_crm_license_package', ''),
        'type_description' => get_option('insurance_crm_license_type_description', ''),
        'expiry' => get_option('insurance_crm_license_expiry', ''),
        'user_limit' => get_option('insurance_crm_license_user_limit', 5),
        'modules' => get_option('insurance_crm_license_modules', array()),
        'last_check' => get_option('insurance_crm_license_last_check', ''),
        'current_users' => 0,
        'in_grace_period' => false,
        'grace_days_remaining' => 0
    );
}

// Get current user count
global $wpdb;
$active_users_count = $wpdb->get_var($wpdb->prepare("
    SELECT COUNT(DISTINCT r.user_id) 
    FROM {$wpdb->prefix}insurance_crm_representatives r 
    INNER JOIN {$wpdb->users} u ON r.user_id = u.ID 
    WHERE r.status = %s
", 'active')) ?: 0;

$license_info['current_users'] = $active_users_count;

// Prepare display data
$license_key = $license_info['key'];
$license_status = $license_info['status'];
$license_type = $license_info['type'];
$license_package = $license_info['package'];
$license_type_description = $license_info['type_description'];
$license_expiry = $license_info['expiry'];

// Determine status text and class
$status_text = 'Etkin Değil';
$status_class = 'invalid';

if ($license_status === 'active') {
    $status_text = 'Etkin';
    $status_class = 'valid';
} elseif ($license_status === 'expired') {
    if ($license_info['in_grace_period']) {
        $status_text = 'Süresi Dolmuş (Ek Kullanım Süresi)';
        $status_class = 'grace-period';
    } else {
        $status_text = 'Süresi Dolmuş';
        $status_class = 'expired';
    }
} elseif ($license_status === 'invalid') {
    $status_text = 'Geçersiz';
    $status_class = 'invalid';
}

// Check if access is restricted
$is_access_restricted = get_option('insurance_crm_license_access_restricted', false);
if ($is_access_restricted && $license_status !== 'active') {
    $status_text .= ' (Erişim Kısıtlı)';
    $status_class = 'restricted';
}

// Format expiry date
$expiry_date = '';
if (!empty($license_expiry)) {
    $expiry_date = date_i18n(get_option('date_format'), strtotime($license_expiry));
}

// Helper function to get license type display name
function get_license_type_display_name($license_type, $license_type_description = '') {
    // Prefer server-provided description over hardcoded mapping
    if (!empty($license_type_description)) {
        return $license_type_description;
    }
    
    // Fallback to hardcoded mapping if server doesn't provide description
    $type_map = array(
        'monthly' => 'Aylık',
        'yearly' => 'Yıllık', 
        'lifetime' => 'Ömürlük',
        'trial' => 'Deneme'
    );
    
    return isset($type_map[$license_type]) ? $type_map[$license_type] : 'Bilinmiyor';
}

// Helper function to get license package display name
function get_license_package_display_name($license_package) {
    if (empty($license_package)) {
        return 'Standart';
    }
    
    $package_map = array(
        'basic' => 'Temel Paket',
        'standard' => 'Standart Paket',
        'premium' => 'Premium Paket',
        'enterprise' => 'Kurumsal Paket',
        'unlimited' => 'Sınırsız Paket'
    );
    
    return isset($package_map[$license_package]) ? $package_map[$license_package] : ucfirst($license_package);
}

// Calculate days left
$days_left = '';
if ($license_status === 'active' && in_array($license_type, array('monthly', 'yearly', 'trial')) && !empty($license_expiry)) {
    $days_left = ceil((strtotime($license_expiry) - time()) / 86400);
    if ($days_left < 0) {
        $days_left = 0;
    }
}
?>

<style>
    .license-management-container {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        padding: 20px 30px;
        max-width: 1200px;
        margin: 20px auto;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    
    .license-header {
        margin-bottom: 30px;
        border-bottom: 1px solid #e0e0e0;
        padding-bottom: 15px;
    }
    
    .license-header h2 {
        font-size: 24px;
        color: #333;
        margin-bottom: 5px;
        font-weight: 600;
    }
    
    .license-header p {
        font-size: 14px;
        color: #666;
        margin: 0;
    }
    
    .license-status-card {
        display: flex;
        align-items: center;
        gap: 20px;
        padding: 20px;
        margin-bottom: 30px;
        border-radius: 8px;
        border: 1px solid #ddd;
    }
    
    .license-status-card.active {
        background: linear-gradient(135deg, #e8f5e9, #f1f8e9);
        border-color: #4caf50;
    }
    
    .license-status-card.invalid,
    .license-status-card.expired {
        background: linear-gradient(135deg, #ffebee, #fce4ec);
        border-color: #f44336;
    }
    
    .license-status-card.grace-period {
        background: linear-gradient(135deg, #fff3e0, #fef7e0);
        border-color: #ff9800;
    }
    
    .license-icon {
        font-size: 48px;
        min-width: 60px;
        text-align: center;
    }
    
    .license-details h4 {
        margin: 0 0 8px 0;
        font-size: 18px;
        color: #333;
        font-weight: 600;
    }
    
    .license-details p {
        margin: 4px 0;
        color: #555;
        font-size: 14px;
    }
    
    .license-form-section {
        background: #f9f9f9;
        border-radius: 6px;
        padding: 20px;
        border: 1px solid #eee;
        margin-bottom: 20px;
    }
    
    .license-form-section h3 {
        margin-top: 0;
        font-size: 18px;
        color: #333;
        margin-bottom: 15px;
        font-weight: 600;
        border-bottom: 1px solid #ddd;
        padding-bottom: 10px;
    }
    
    .form-row {
        margin-bottom: 15px;
    }
    
    .form-row label {
        display: block;
        margin-bottom: 5px;
        font-weight: 500;
        font-size: 14px;
        color: #444;
    }
    
    .form-input {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
        font-family: inherit;
    }
    
    .form-input:focus {
        border-color: #1976d2;
        outline: none;
        box-shadow: 0 0 0 2px rgba(25, 118, 210, 0.2);
    }
    
    .form-actions {
        display: flex;
        gap: 10px;
        align-items: center;
    }
    
    .btn {
        padding: 10px 20px;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        border: none;
        transition: all 0.2s ease;
        text-decoration: none;
        display: inline-block;
        text-align: center;
    }
    
    .btn-primary {
        background-color: #1976d2;
        color: white;
    }
    
    .btn-primary:hover:not(:disabled) {
        background-color: #1565c0;
    }
    
    .btn-secondary {
        background-color: #f5f5f5;
        color: #333;
        border: 1px solid #ddd;
    }
    
    .btn-secondary:hover {
        background-color: #e0e0e0;
    }
    
    .notification {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 4px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .notification.success {
        background-color: #e8f5e9;
        border-left: 4px solid #4caf50;
        color: #2e7d32;
    }
    
    .notification.error {
        background-color: #ffebee;
        border-left: 4px solid #f44336;
        color: #c62828;
    }
    
    .license-info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    
    .license-info-item {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 6px;
        border-left: 4px solid #007cba;
    }
    
    .license-info-item h4 {
        margin: 0 0 8px 0;
        color: #333;
        font-size: 14px;
        font-weight: 600;
    }
    
    .license-info-item p {
        margin: 0;
        color: #555;
        font-size: 14px;
    }
    
    .license-status-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .license-status-badge.valid {
        background-color: #e8f5e9;
        color: #2e7d32;
        border: 1px solid #4caf50;
    }
    
    .license-status-badge.invalid,
    .license-status-badge.restricted {
        background-color: #ffebee;
        color: #c62828;
        border: 1px solid #f44336;
    }
    
    .license-status-badge.expired {
        background-color: #fff3e0;
        color: #e65100;
        border: 1px solid #ff9800;
    }
    
    .license-status-badge.grace-period {
        background-color: #fff8e1;
        color: #f57c00;
        border: 1px solid #ffc107;
    }
    
    .user-limit-warning {
        color: #f44336;
        font-weight: bold;
    }
    
    .grace-period-warning {
        color: #ff9800;
        font-weight: bold;
    }
    
    .access-restriction-warning {
        background: linear-gradient(135deg, #ffebee, #fff3e0);
        border: 2px solid #f44336;
        border-radius: 8px;
        padding: 20px;
        display: flex;
        align-items: flex-start;
        gap: 15px;
        color: #c62828;
    }
    
    .access-restriction-warning i {
        font-size: 24px;
        color: #f44336;
        margin-top: 2px;
    }
    
    .access-restriction-warning h4 {
        margin: 0 0 10px 0;
        color: #c62828;
        font-size: 16px;
        font-weight: 600;
    }
    
    .access-restriction-warning p {
        margin: 0 0 10px 0;
        color: #c62828;
        line-height: 1.5;
    }
    
    .access-restriction-warning p:last-child {
        margin-bottom: 0;
    }
    
    @media (max-width: 768px) {
        .license-management-container {
            padding: 15px;
            margin: 10px;
        }
        
        .license-status-card {
            flex-direction: column;
            text-align: center;
            padding: 15px;
        }
        
        .license-info-grid {
            grid-template-columns: 1fr;
        }
        
        .form-actions {
            flex-direction: column;
        }
        
        .btn {
            width: 100%;
        }
    }
    
    /* Advanced Sections Tabs */
    .advanced-sections-tabs {
        margin-top: 20px;
    }
    
    .tab-headers {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-bottom: 20px;
    }
    
    .tab-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 15px;
        background: #f8f9fa;
        border: 1px solid #ddd;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.3s ease;
        font-size: 14px;
        font-weight: 600;
        color: #666;
    }
    
    .tab-header:hover {
        background: #e9ecef;
        border-color: #007cba;
        color: #007cba;
    }
    
    .tab-header.active {
        background: #007cba;
        color: white;
        border-color: #007cba;
    }
    
    .tab-arrow {
        transition: transform 0.3s ease;
        font-size: 12px;
    }
    
    .tab-header.active .tab-arrow {
        transform: rotate(180deg);
    }
    
    .tab-content {
        padding: 20px;
        border: 1px solid #ddd;
        border-radius: 6px;
        background: white;
        margin-bottom: 15px;
        animation: slideDown 0.3s ease;
    }
    
    @keyframes slideDown {
        from {
            opacity: 0;
            max-height: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            max-height: 1000px;
            transform: translateY(0);
        }
    }
    
    .tab-content[style*="display: none"] {
        display: none !important;
    }
</style>

<?php if ($form_result): ?>
<div class="notification <?php echo $form_result['success'] ? 'success' : 'error'; ?>">
    <i class="fas fa-<?php echo $form_result['success'] ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <div style="flex: 1;">
        <?php 
        // Check if message contains HTML
        if (strpos($form_result['message'], '<') !== false) {
            echo $form_result['message']; // Allow HTML for detailed test results
        } else {
            echo esc_html($form_result['message']); // Escape plain text
        }
        ?>
    </div>
</div>
<?php endif; ?>

<div class="license-management-container">
    <div class="license-header">
        <h2><i class="fas fa-key"></i> Lisans Yönetimi</h2>
        <p>Insurance CRM lisans bilgilerinizi buradan yönetebilir ve durumunuzu kontrol edebilirsiniz.</p>
    </div>
    
    <!-- License Status Display -->
    <?php if ($license_status === 'active'): ?>
        <div class="license-status-card active">
            <div class="license-icon">✅</div>
            <div class="license-details">
                <h4>Lisans Aktif</h4>
                <p class="license-type">
                    <?php 
                    echo get_license_type_display_name($license_type, $license_type_description);
                    if (!empty($license_package)) {
                        echo ' - ' . get_license_package_display_name($license_package);
                    }
                    ?>
                </p>
                <?php if (!empty($license_type_description)): ?>
                    <p class="license-description" style="color: #666; font-size: 13px;">
                        <?php echo esc_html($license_type_description); ?>
                    </p>
                <?php endif; ?>
                <?php if (in_array($license_type, array('monthly', 'yearly', 'trial')) && !empty($expiry_date)): ?>
                    <p class="expiry-info">
                        Bitiş Tarihi: <?php echo $expiry_date; ?> 
                        (<?php echo $days_left; ?> gün kaldı)
                    </p>
                <?php endif; ?>
                <p class="user-info">
                    Kullanıcı Sayısı: <?php echo $license_info['current_users']; ?> / <?php echo $license_info['user_limit']; ?>
                    <?php if ($license_info['current_users'] > $license_info['user_limit']): ?>
                        <span class="user-limit-warning">(Limit Aşıldı)</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    <?php elseif ($license_status === 'expired' && $license_info['in_grace_period']): ?>
        <div class="license-status-card grace-period">
            <div class="license-icon">⏰</div>
            <div class="license-details">
                <h4>Lisans Süresi Dolmuş - Ek Kullanım Süresi</h4>
                <p class="license-type">
                    <?php 
                    echo get_license_type_display_name($license_type, $license_type_description);
                    if (!empty($license_package)) {
                        echo ' - ' . get_license_package_display_name($license_package);
                    }
                    echo ' - Süre Dolmuş';
                    ?>
                </p>
                <?php if (!empty($license_type_description)): ?>
                    <p class="license-description" style="color: #666; font-size: 13px;">
                        <?php echo esc_html($license_type_description); ?>
                    </p>
                <?php endif; ?>
                <p class="grace-period-warning">
                    Kalan Süre: <?php echo $license_info['grace_days_remaining']; ?> gün
                </p>
                <p>Lütfen ödemenizi yaparak lisansınızı yenileyin.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="license-status-card invalid">
            <div class="license-icon">❌</div>
            <div class="license-details">
                <h4>Lisans Aktif Değil</h4>
                <p>Sistem kullanımı için geçerli bir lisans anahtarı girin.</p>
                <?php if ($license_status === 'expired'): ?>
                    <p>Lisansınızın süresi dolmuştur ve ek kullanım süreniz sona ermiştir.</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- License Key Input Form -->
    <div class="license-form-section">
        <h3><i class="fas fa-key"></i> Lisans Anahtarı</h3>
        
        <form method="post" action="">
            <?php wp_nonce_field('license_form', 'license_nonce'); ?>
            
            <div class="form-row">
                <label for="license_key">Lisans Anahtarı</label>
                <input type="text" id="license_key" name="license_key" class="form-input" 
                       value="<?php echo esc_attr($license_key); ?>" 
                       placeholder="Lisans anahtarınızı buraya girin..." />
            </div>
            
            <div class="form-actions">
                <button type="submit" name="license_submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Lisans Anahtarını Kaydet ve Doğrula
                </button>
            </div>
        </form>
    </div>
    
    <!-- License Information -->
    <?php if (!empty($license_key)): ?>
    <div class="license-form-section">
        <h3><i class="fas fa-info-circle"></i> Lisans Bilgileri</h3>
        
        <div class="license-info-grid">
            <div class="license-info-item">
                <h4>Lisans Durumu</h4>
                <p><span class="license-status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span></p>
            </div>
            
            <div class="license-info-item">
                <h4>Lisans Türü</h4>
                <p>
                    <?php 
                    echo get_license_type_display_name($license_type, $license_type_description);
                    ?>
                </p>
            </div>
            
            <?php if (!empty($license_package)): ?>
            <div class="license-info-item">
                <h4>Lisans Paketi</h4>
                <p><?php echo get_license_package_display_name($license_package); ?></p>
            </div>
            <?php endif; ?>
            
            <div class="license-info-item">
                <h4>Kullanıcı Limiti</h4>
                <p>
                    <?php echo $license_info['current_users']; ?> / <?php echo $license_info['user_limit']; ?>
                    <?php if ($license_info['current_users'] > $license_info['user_limit']): ?>
                        <span class="user-limit-warning">(Limit Aşıldı)</span>
                    <?php endif; ?>
                </p>
            </div>
            
            <?php if (!empty($expiry_date) && $license_type !== 'lifetime'): ?>
            <div class="license-info-item">
                <h4>Lisans Süresi</h4>
                <p>
                    <?php 
                    if ($license_type === 'monthly') {
                        echo 'Aylık abonelik - ';
                    } elseif ($license_type === 'yearly') {
                        echo 'Yıllık abonelik - ';
                    } elseif ($license_type === 'trial') {
                        echo 'Deneme süresi - ';
                    }
                    echo 'Bitiş: ' . $expiry_date;
                    if ($days_left > 0) {
                        echo ' (' . $days_left . ' gün kaldı)';
                    }
                    ?>
                </p>
            </div>
            <?php elseif ($license_type === 'lifetime'): ?>
            <div class="license-info-item">
                <h4>Lisans Süresi</h4>
                <p>Ömürlük lisans - Süresiz kullanım</p>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($license_info['last_check'])): ?>
            <div class="license-info-item">
                <h4>Son Kontrol</h4>
                <p><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($license_info['last_check'])); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if ($is_access_restricted): ?>
    <div class="license-form-section">
        <div class="access-restriction-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <div>
                <h4>Erişim Kısıtlı</h4>
                <p>Lisansınızın süresi dolmuş ve ek kullanım süreniz sona ermiştir. Şu anda sadece bu lisans yönetimi sayfasına erişebilirsiniz.</p>
                <p><strong>CRM özelliklerini kullanabilmek için lütfen lisansınızı yenileyin veya geçerli bir lisans anahtarı girin.</strong></p>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    
    <!-- Help and Contact -->
    <div class="license-form-section">
        <h3><i class="fas fa-question-circle"></i> Yardım ve İletişim</h3>
        
        <div class="license-info-grid">
            <div class="license-info-item">
                <h4>Lisans Satın Alma</h4>
                <p>Yeni lisans satın almak için: <a href="https://www.balkay.net/crm" target="_blank">www.balkay.net/crm</a></p>
            </div>
            
            <div class="license-info-item">
                <h4>Teknik Destek</h4>
                <p>Lisans sorunları için <a href="<?php echo generate_panel_url('helpdesk'); ?>">Destek Talebi</a> oluşturun.</p>
            </div>
            
            <div class="license-info-item">
                <h4>Ödeme Sorunları</h4>
                <p>Ödeme ve faturalandırma: <a href="mailto:info@balkay.net">info@balkay.net</a></p>
            </div>
        </div>
    </div>
    
    <!-- Advanced Sections - Collapsible Tabs -->
    <div class="license-form-section">
        <div class="advanced-sections-tabs">
            <!-- Tab Headers -->
            <div class="tab-headers">
                <button type="button" class="tab-header" data-tab="debug-tools">
                    <i class="fas fa-bug"></i> Geliştirici Araçları
                    <i class="fas fa-chevron-down tab-arrow"></i>
                </button>
                <button type="button" class="tab-header" data-tab="server-settings">
                    <i class="fas fa-server"></i> Sunucu Ayarları
                    <i class="fas fa-chevron-down tab-arrow"></i>
                </button>
            </div>
            
            <!-- Debug Tools Tab Content -->
            <div class="tab-content" id="debug-tools" style="display: none;">
                <div class="license-info-grid">
                    <div class="license-info-item">
                        <h4>Debug Modu</h4>
                        <p>Lisans sunucusu ile iletişim loglarını görüntüler.</p>
                        <form method="post" action="" style="margin-top: 10px;">
                            <?php wp_nonce_field('debug_form', 'debug_nonce'); ?>
                            <button type="submit" name="toggle_debug" class="btn btn-secondary">
                                <i class="fas fa-<?php echo get_option('insurance_crm_license_debug_mode', false) ? 'toggle-on' : 'toggle-off'; ?>"></i>
                                Debug Modu <?php echo get_option('insurance_crm_license_debug_mode', false) ? 'Kapat' : 'Aç'; ?>
                            </button>
                        </form>
                    </div>
                    
                    <div class="license-info-item">
                        <h4>Bağlantı Testi</h4>
                        <p>Lisans sunucusu ile bağlantıyı test eder.</p>
                        <form method="post" action="" style="margin-top: 10px;">
                            <?php wp_nonce_field('test_form', 'test_nonce'); ?>
                            <button type="submit" name="test_connection" class="btn btn-secondary">
                                <i class="fas fa-satellite-dish"></i> Bağlantıyı Test Et
                            </button>
                        </form>
                    </div>
                </div>
                
                <?php if (!empty($debug_info)): ?>
                <div class="license-info-item" style="margin-top: 15px;">
                    <h4>Debug Bilgileri</h4>
                    <ul style="margin: 10px 0; padding-left: 20px;">
                        <?php foreach ($debug_info as $info): ?>
                            <li><?php echo esc_html($info); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <?php if (get_option('insurance_crm_license_debug_mode', false)): ?>
                <div class="license-info-item" style="margin-top: 15px; background: #fff3cd; border-left-color: #ffc107;">
                    <h4>⚠️ Debug Modu Aktif</h4>
                    <p>Tüm lisans iletişim logları WordPress error.log dosyasında saklanmaktadır. 
                    Bu mod sadece sorun giderme amaçlı kullanılmalıdır.</p>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Server Settings Tab Content -->
            <div class="tab-content" id="server-settings" style="display: none;">
                <form method="post" action="">
                    <?php wp_nonce_field('server_form', 'server_nonce'); ?>
                    
                    <div class="form-row">
                        <label for="license_server_url">Lisans Sunucusu URL'si</label>
                        <input type="url" id="license_server_url" name="license_server_url" class="form-input" 
                               value="<?php echo esc_attr(get_option('insurance_crm_license_server_url', 'https://balkay.net/crm')); ?>" 
                               placeholder="https://balkay.net/crm" />
                        <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">
                            Bu URL'ye API istekleri gönderilir. Değiştirirken dikkatli olun.
                        </small>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" name="update_server" class="btn btn-primary">
                            <i class="fas fa-save"></i> Sunucu Ayarlarını Kaydet
                        </button>
                    </div>
                </form>
                
                <div class="license-info-grid" style="margin-top: 20px;">
                    <div class="license-info-item">
                        <h4>API Endpoint'leri</h4>
                        <ul style="font-size: 12px; margin: 5px 0; padding-left: 15px;">
                            <li><code>/api/validate_license</code> - Lisans doğrulama</li>
                            <li><code>/api/license_info</code> - Lisans bilgisi</li>
                            <li><code>/api/check_status</code> - Durum kontrolü</li>
                        </ul>
                    </div>
                    
                    <div class="license-info-item">
                        <h4>Beklenen Veri Formatı</h4>
                        <pre style="font-size: 11px; background: #f8f9fa; padding: 8px; border-radius: 3px; margin: 5px 0;">
{
  "license_key": "ANAHTAR",
  "domain": "site.com",
  "action": "validate"
}</pre>
                    </div>
                    
                    <div class="license-info-item">
                        <h4>Beklenen Yanıt Formatı</h4>
                        <pre style="font-size: 11px; background: #f8f9fa; padding: 8px; border-radius: 3px; margin: 5px 0;">
{
  "status": "active",
  "license_type": "monthly",
  "license_package": "premium",
  "license_type_description": "Premium Aylık Abonelik",
  "expires_on": "2025-02-01",
  "user_limit": 10,
  "message": "Başarılı"
}</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function validateLicense() {
    const btn = event.target;
    const originalText = btn.innerHTML;
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Doğrulanıyor...';
    
    // AJAX request to validate license
    const formData = new FormData();
    formData.append('action', 'validate_license');
    formData.append('license_key', document.getElementById('license_key').value);
    formData.append('nonce', '<?php echo wp_create_nonce('validate_license'); ?>');
    
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Lisans doğrulanmadı: ' + (data.message || 'Bilinmeyen hata'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Lisans doğrulama sırasında hata oluştu.');
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

// Advanced Sections Tabs Functionality
document.addEventListener('DOMContentLoaded', function() {
    const tabHeaders = document.querySelectorAll('.tab-header');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabHeaders.forEach(header => {
        header.addEventListener('click', function() {
            const targetTab = this.dataset.tab;
            const targetContent = document.getElementById(targetTab);
            const isActive = this.classList.contains('active');
            
            // Close all tabs first
            tabHeaders.forEach(h => h.classList.remove('active'));
            tabContents.forEach(c => c.style.display = 'none');
            
            // If tab wasn't active, open it
            if (!isActive) {
                this.classList.add('active');
                targetContent.style.display = 'block';
            }
        });
    });
});
</script>
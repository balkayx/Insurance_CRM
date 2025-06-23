<?php
// Doğrudan erişime izin verme
if (!defined('ABSPATH')) {
    exit;
}

// License processing logic
$message = '';
$message_type = '';

if (isset($_POST['insurance_crm_license_action']) && isset($_POST['insurance_crm_license_nonce']) && wp_verify_nonce($_POST['insurance_crm_license_nonce'], 'insurance_crm_license')) {
    global $insurance_crm_license_manager;
    
    $action = sanitize_text_field($_POST['insurance_crm_license_action']);
    $license_key = sanitize_text_field($_POST['insurance_crm_license_key']);
    
    if ($action === 'activate' && !empty($license_key)) {
        if ($insurance_crm_license_manager) {
            $result = $insurance_crm_license_manager->activate_license($license_key);
            if ($result['success']) {
                $message = 'Lisans başarıyla etkinleştirildi.';
                $message_type = 'success';
            } else {
                $message = 'Lisans etkinleştirilemedi: ' . $result['message'];
                $message_type = 'error';
            }
        } else {
            $message = 'Lisans yöneticisi yüklenemedi.';
            $message_type = 'error';
        }
    } elseif ($action === 'deactivate') {
        if ($insurance_crm_license_manager) {
            $result = $insurance_crm_license_manager->deactivate_license();
            if ($result['success']) {
                $message = 'Lisans başarıyla devre dışı bırakıldı.';
                $message_type = 'success';
            } else {
                $message = 'Lisans devre dışı bırakılamadı: ' . $result['message'];
                $message_type = 'error';
            }
        }
    } elseif ($action === 'check') {
        if ($insurance_crm_license_manager) {
            $insurance_crm_license_manager->perform_license_check();
            $message = 'Lisans durumu güncellendi.';
            $message_type = 'success';
        }
    } elseif ($action === 'toggle_debug') {
        $debug_mode = isset($_POST['debug_mode']) ? true : false;
        update_option('insurance_crm_license_debug_mode', $debug_mode);
        $message = 'Debug modu ' . ($debug_mode ? 'etkinleştirildi' : 'devre dışı bırakıldı') . '.';
        $message_type = 'success';
    } elseif ($action === 'toggle_bypass') {
        $bypass_license = isset($_POST['bypass_license']) ? true : false;
        update_option('insurance_crm_bypass_license', $bypass_license);
        $message = 'Lisans bypass ' . ($bypass_license ? 'etkinleştirildi' : 'devre dışı bırakıldı') . '.';
        $message_type = 'success';
    } elseif ($action === 'clear_cache') {
        // Clear all license-related transients and cache
        delete_transient('insurance_crm_license_check');
        delete_option('insurance_crm_license_last_check');
        wp_cache_delete('insurance_crm_license_data');
        $message = 'Lisans cache temizlendi.';
        $message_type = 'success';
    }
}

// Get current license information
global $insurance_crm_license_manager;
$license_key = get_option('insurance_crm_license_key', '');
$license_status = get_option('insurance_crm_license_status', 'inactive');
$license_data = get_option('insurance_crm_license_data', array());
?>

<div class="wrap insurance-crm-admin-license-page">
    <h1>Insurance CRM Lisans Yönetimi</h1>
    
    <?php if (!empty($message)): ?>
    <div class="notice notice-<?php echo $message_type; ?> is-dismissible">
        <p><?php echo esc_html($message); ?></p>
    </div>
    <?php endif; ?>
    
    <div class="admin-license-current-status">
        <h2>Mevcut Lisans Durumu</h2>
        
        <table class="widefat fixed">
            <tbody>
                <tr>
                    <th>Lisans Anahtarı</th>
                    <td><?php echo !empty($license_key) ? esc_html(substr($license_key, 0, 8) . '***') : 'Girilmemiş'; ?></td>
                </tr>
                <tr>
                    <th>Durum</th>
                    <td>
                        <?php 
                        $status_class = '';
                        $status_text = 'Etkin Değil';
                        if ($license_status === 'active') {
                            $status_text = 'Etkin';
                            $status_class = 'status-active';
                        } elseif ($license_status === 'expired') {
                            $status_text = 'Süresi Dolmuş';
                            $status_class = 'status-expired';
                        } elseif ($license_status === 'invalid') {
                            $status_text = 'Geçersiz';
                            $status_class = 'status-invalid';
                        }
                        ?>
                        <span class="license-status <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                    </td>
                </tr>
                <?php if (!empty($license_data)): ?>
                <tr>
                    <th>Lisans Sahibi</th>
                    <td><?php echo isset($license_data['customer_name']) ? esc_html($license_data['customer_name']) : 'Bilinmiyor'; ?></td>
                </tr>
                <tr>
                    <th>E-posta</th>
                    <td><?php echo isset($license_data['customer_email']) ? esc_html($license_data['customer_email']) : 'Bilinmiyor'; ?></td>
                </tr>
                <tr>
                    <th>Geçerlilik Tarihi</th>
                    <td><?php echo isset($license_data['expires']) ? esc_html($license_data['expires']) : 'Bilinmiyor'; ?></td>
                </tr>
                <tr>
                    <th>Kullanıcı Limiti</th>
                    <td><?php echo isset($license_data['license_limit']) ? esc_html($license_data['license_limit']) : 'Sınırsız'; ?></td>
                </tr>
                <tr>
                    <th>Son Kontrol</th>
                    <td><?php echo get_option('insurance_crm_license_last_check', 'Hiç kontrol edilmemiş'); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <div class="admin-license-form">
        <h2>Lisans Yönetimi</h2>
        
        <form method="post" action="">
            <?php wp_nonce_field('insurance_crm_license', 'insurance_crm_license_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">Lisans Anahtarı</th>
                    <td>
                        <input type="text" name="insurance_crm_license_key" class="large-text" 
                               value="<?php echo esc_attr($license_key); ?>" placeholder="Lisans anahtarınızı buraya girin..." />
                        <p class="description">
                            Yeni lisans anahtarı girin veya mevcut anahtarı güncelleyin.
                        </p>
                    </td>
                </tr>
            </table>
            
            <div class="license-actions">
                <p class="submit">
                    <input type="hidden" name="insurance_crm_license_action" value="activate" />
                    <input type="submit" class="button button-primary" value="Lisansı Etkinleştir / Güncelle" />
                </p>
                
                <?php if (!empty($license_key)): ?>
                <p class="submit">
                    <input type="hidden" name="insurance_crm_license_action" value="check" />
                    <input type="submit" class="button button-secondary" value="Lisans Durumunu Kontrol Et" onclick="this.form.elements['insurance_crm_license_action'].value='check';" />
                </p>
                
                <p class="submit">
                    <input type="hidden" name="insurance_crm_license_action" value="deactivate" />
                    <input type="submit" class="button button-secondary" value="Lisansı Devre Dışı Bırak" 
                           onclick="this.form.elements['insurance_crm_license_action'].value='deactivate'; return confirm('Lisansı devre dışı bırakmak istediğinizden emin misiniz?');" />
                </p>
                <?php endif; ?>
            </div>
        </form>
    </div>
    
    <div class="admin-license-tools">
        <h2>Yönetici Araçları</h2>
        
        <div class="license-tools-grid">
            <div class="tool-item">
                <h3>Lisans Ayarları</h3>
                <p>Gelişmiş lisans ayarları ve debug seçenekleri</p>
                <form method="post" action="">
                    <?php wp_nonce_field('insurance_crm_license', 'insurance_crm_license_nonce'); ?>
                    <input type="hidden" name="insurance_crm_license_action" value="toggle_debug" />
                    <label>
                        <input type="checkbox" name="debug_mode" value="1" <?php checked(get_option('insurance_crm_license_debug_mode', false), true); ?> />
                        Debug Modunu Etkinleştir
                    </label>
                    <p><input type="submit" class="button" value="Ayarları Kaydet" /></p>
                </form>
            </div>
            
            <div class="tool-item">
                <h3>Lisans Bypass</h3>
                <p>Geliştirme ve test amaçlı bypass ayarı</p>
                <form method="post" action="">
                    <?php wp_nonce_field('insurance_crm_license', 'insurance_crm_license_nonce'); ?>
                    <input type="hidden" name="insurance_crm_license_action" value="toggle_bypass" />
                    <label>
                        <input type="checkbox" name="bypass_license" value="1" <?php checked(get_option('insurance_crm_bypass_license', false), true); ?> />
                        Lisans Kontrolünü Bypass Et (Dikkatli kullanın!)
                    </label>
                    <p><input type="submit" class="button" value="Bypass Ayarını Güncelle" /></p>
                </form>
            </div>
            
            <div class="tool-item">
                <h3>Cache Temizleme</h3>
                <p>Lisans cache verilerini temizle</p>
                <form method="post" action="">
                    <?php wp_nonce_field('insurance_crm_license', 'insurance_crm_license_nonce'); ?>
                    <input type="hidden" name="insurance_crm_license_action" value="clear_cache" />
                    <p><input type="submit" class="button" value="Lisans Cache'ini Temizle" /></p>
                </form>
            </div>
        </div>
    </div>
    
    <div class="admin-license-info">
        <h2>Destek ve Yardım</h2>
        <ul>
            <li><strong>Lisans Desteği:</strong> <a href="https://www.balkay.net/crm" target="_blank">www.balkay.net/crm</a></li>
            <li><strong>Dokümantasyon:</strong> Frontend "Yardım & Destek" > "Lisanslama" sayfasında detaylı bilgiler</li>
            <li><strong>Debug Loglari:</strong> WordPress debug.log dosyasında "[LISANS DEBUG]" etiketiyle</li>
        </ul>
    </div>
    
    <style>
        .admin-license-current-status {
            background: white;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        
        .admin-license-current-status table {
            margin-top: 15px;
        }
        
        .license-status {
            padding: 4px 8px;
            border-radius: 3px;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
        }
        
        .license-status.status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .license-status.status-expired {
            background: #fff3cd;
            color: #856404;
        }
        
        .license-status.status-invalid {
            background: #f8d7da;
            color: #721c24;
        }
        
        .admin-license-form {
            background: white;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        
        .license-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .license-actions .submit {
            margin: 0;
        }
        
        .admin-license-tools {
            background: white;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        
        .license-tools-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }
        
        .tool-item {
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            padding: 15px;
        }
        
        .tool-item h3 {
            margin-top: 0;
            color: #23282d;
        }
        
        .tool-item p {
            color: #666;
            font-size: 13px;
        }
        
        .tool-item label {
            display: block;
            margin: 10px 0;
        }
        
        .admin-license-info {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-left: 4px solid #007cba;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            padding: 15px 20px;
        }
        
        .admin-license-info ul {
            margin-left: 20px;
        }
        
        .admin-license-info li {
            margin-bottom: 8px;
        }
        
        @media (max-width: 768px) {
            .license-tools-grid {
                grid-template-columns: 1fr;
            }
            
            .license-actions {
                flex-direction: column;
            }
        }
    </style>
</div>
<?php
/**
 * Yönetim Ayarları Sayfası
 * 
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/templates/representative-panel
 * @author     Anadolu Birlik
 * @since      1.0.0
 * @version    1.0.1 (2025-05-28)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/temsilci-girisi/'));
    exit;
}

$current_user = wp_get_current_user();
global $wpdb;

// Yetki kontrolü - patron ve müdür ayarlara erişebilir
if (!has_full_admin_access($current_user->ID)) {
    wp_die('Bu sayfaya erişim yetkiniz bulunmuyor.');
}

// Mevcut ayarları al
$settings = get_option('insurance_crm_settings', array());

// Varsayılan değerler
if (!isset($settings['company_name'])) {
    $settings['company_name'] = get_bloginfo('name');
}
if (!isset($settings['company_email'])) {
    $settings['company_email'] = get_bloginfo('admin_email');
}
if (!isset($settings['renewal_reminder_days'])) {
    $settings['renewal_reminder_days'] = 30;
}
if (!isset($settings['task_reminder_days'])) {
    $settings['task_reminder_days'] = 7;
}
if (!isset($settings['default_policy_types'])) {
    $settings['default_policy_types'] = array('Kasko', 'Trafik', 'Konut', 'DASK', 'Sağlık');
}
if (!isset($settings['insurance_companies'])) {
    $settings['insurance_companies'] = array('Allianz', 'Anadolu Sigorta', 'AXA', 'Axa Sigorta', 'Acıbadem', 'Ankara Sigorta', 'Groupama', 'Güneş Sigorta', 'HDI', 'Mapfre', 'Sompo Japan', 'Türkiye Sigorta');
}
if (!isset($settings['default_task_types'])) {
    $settings['default_task_types'] = array('Telefon Görüşmesi', 'Yüz Yüze Görüşme', 'Teklif Hazırlama', 'Evrak İmza', 'Dosya Takibi');
}
if (!isset($settings['payment_options'])) {
    $settings['payment_options'] = array('Peşin', '3 Taksit', '6 Taksit', '8 Taksit', '9 Taksit', '12 Taksit', 'Ödenmedi', 'Nakit', 'Kredi Kartı', 'Havale', 'Diğer');
}
if (!isset($settings['occupation_settings']['default_occupations'])) {
    $settings['occupation_settings']['default_occupations'] = array('Doktor', 'Mühendis', 'Öğretmen', 'Avukat', 'Muhasebeci', 'İşçi', 'Memur', 'Emekli');
}

// Form gönderildiğinde
if (isset($_POST['submit_settings']) && isset($_POST['settings_nonce']) && 
    wp_verify_nonce($_POST['settings_nonce'], 'save_settings')) {
    
    $tab = isset($_POST['active_tab']) ? $_POST['active_tab'] : 'general';
    $error_messages = array();
    $success_message = '';
    
    // Genel ayarlar
    if ($tab === 'general') {
        $settings['company_name'] = sanitize_text_field($_POST['company_name']);
        $settings['company_email'] = sanitize_email($_POST['company_email']);
        $settings['renewal_reminder_days'] = intval($_POST['renewal_reminder_days']);
        $settings['task_reminder_days'] = intval($_POST['task_reminder_days']);
    }
    // Poliçe türleri
    elseif ($tab === 'policy_types') {
        $settings['default_policy_types'] = array_map('sanitize_text_field', explode("\n", trim($_POST['default_policy_types'])));
    }
    // Sigorta şirketleri
    elseif ($tab === 'insurance_companies') {
        $settings['insurance_companies'] = array_map('sanitize_text_field', explode("\n", trim($_POST['insurance_companies'])));
    }
    // Görev türleri
    elseif ($tab === 'task_types') {
        $settings['default_task_types'] = array_map('sanitize_text_field', explode("\n", trim($_POST['default_task_types'])));
    }
    // Ödeme bilgileri
    elseif ($tab === 'payment_info') {
        $settings['payment_options'] = array_map('sanitize_text_field', explode("\n", trim($_POST['payment_options'])));
    }
    // Bildirim ayarları
    elseif ($tab === 'notifications') {
        $settings['notification_settings']['email_notifications'] = isset($_POST['email_notifications']);
        $settings['notification_settings']['renewal_notifications'] = isset($_POST['renewal_notifications']);
        $settings['notification_settings']['task_notifications'] = isset($_POST['task_notifications']);
    }
    // E-posta şablonları
    elseif ($tab === 'email_templates') {
        $settings['email_templates']['renewal_reminder'] = wp_kses_post($_POST['renewal_reminder_template']);
        $settings['email_templates']['task_reminder'] = wp_kses_post($_POST['task_reminder_template']);
        $settings['email_templates']['new_policy'] = wp_kses_post($_POST['new_policy_template']);
    }
    // Site görünümü
    elseif ($tab === 'site_appearance') {
        // Handle file upload for logo
        if (isset($_FILES['logo_upload']) && $_FILES['logo_upload']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = wp_upload_dir();
            $allowed_types = array('image/jpeg', 'image/jpg', 'image/png', 'image/gif');
            
            if (in_array($_FILES['logo_upload']['type'], $allowed_types)) {
                $filename = 'logo_' . time() . '_' . sanitize_file_name($_FILES['logo_upload']['name']);
                $file_path = $upload_dir['path'] . '/' . $filename;
                
                if (move_uploaded_file($_FILES['logo_upload']['tmp_name'], $file_path)) {
                    $settings['site_appearance']['login_logo'] = $upload_dir['url'] . '/' . $filename;
                } else {
                    $error_messages[] = 'Logo yüklenirken bir hata oluştu.';
                }
            } else {
                $error_messages[] = 'Sadece JPG, PNG ve GIF dosyaları yükleyebilirsiniz.';
            }
        } else {
            $settings['site_appearance']['login_logo'] = esc_url_raw($_POST['login_logo']);
        }
        
        $settings['site_appearance']['font_family'] = sanitize_text_field($_POST['font_family']);
        $settings['site_appearance']['primary_color'] = sanitize_hex_color($_POST['primary_color']);
        $settings['site_appearance']['secondary_color'] = sanitize_hex_color($_POST['secondary_color']);
        $settings['site_appearance']['sidebar_color'] = sanitize_hex_color($_POST['sidebar_color']);
    }
    // Dosya yükleme ayarları
    elseif ($tab === 'file_upload') {
        $settings['file_upload_settings']['allowed_file_types'] = isset($_POST['allowed_file_types']) ? array_map('sanitize_text_field', $_POST['allowed_file_types']) : array();
    }
    // Meslekler
    elseif ($tab === 'occupations') {
        $settings['occupation_settings']['default_occupations'] = array_map('sanitize_text_field', explode("\n", trim($_POST['default_occupations'])));
    }
    
    // Ayarları kaydet
    update_option('insurance_crm_settings', $settings);
    $success_message = 'Ayarlar başarıyla kaydedildi.';
}

// Aktif sekme
$active_tab = isset($_POST['active_tab']) ? $_POST['active_tab'] : 'general';

// Statistics for the modern cards
$total_settings = 10; // Number of setting categories (added payment_info)
$total_companies = count($settings['insurance_companies']);
$total_policy_types = count($settings['default_policy_types']);
$total_task_types = count($settings['default_task_types']);
?>

<div class="modern-settings-container">
    <!-- Modern Header -->
    <div class="page-header-modern">
        <div class="header-main">
            <div class="header-content">
                <div class="header-left">
                    <h1><i class="fas fa-cog"></i> Yönetim Ayarları</h1>
                </div>
                <div class="header-right">
                    <a href="<?php echo generate_panel_url('all_personnel'); ?>" class="btn-modern btn-primary">
                        <i class="fas fa-users"></i> Personel Yönetimi
                    </a>
                </div>
            </div>
        </div>
        <div class="header-subtitle-section">
            <p class="header-subtitle">Sistem ayarlarını yapılandırın ve özelleştirin</p>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <i class="fas fa-cogs"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo $total_settings; ?></h3>
                <p>Ayar Kategorisi</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #5ee7df 0%, #66a6ff 100%);">
                <i class="fas fa-building"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo $total_companies; ?></h3>
                <p>Sigorta Şirketi</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <i class="fas fa-file-invoice"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo $total_policy_types; ?></h3>
                <p>Poliçe Türü</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                <i class="fas fa-tasks"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo $total_task_types; ?></h3>
                <p>Görev Türü</p>
            </div>
        </div>
    </div>
    
    <?php if (!empty($error_messages)): ?>
        <div class="modern-message-box error-box">
            <i class="fas fa-exclamation-circle"></i>
            <div class="message-content">
                <h4>Hata</h4>
                <ul>
                    <?php foreach ($error_messages as $error): ?>
                        <li><?php echo esc_html($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($success_message)): ?>
        <div class="modern-message-box success-box">
            <i class="fas fa-check-circle"></i>
            <div class="message-content">
                <h4>Başarılı</h4>
                <p><?php echo esc_html($success_message); ?></p>
            </div>
        </div>
    <?php endif; ?>
    
    <div class="modern-settings-container-content">
        <div class="modern-settings-sidebar">
            <div class="sidebar-header-modern">
                <h3><i class="fas fa-sliders-h"></i> Ayar Kategorileri</h3>
            </div>
            <ul class="modern-settings-menu">
                <li class="<?php echo $active_tab === 'general' ? 'active' : ''; ?>" data-tab="general">
                    <i class="fas fa-home"></i> Genel Ayarlar
                </li>
                <li class="<?php echo $active_tab === 'policy_types' ? 'active' : ''; ?>" data-tab="policy_types">
                    <i class="fas fa-file-invoice"></i> Poliçe Türleri
                </li>
                <li class="<?php echo $active_tab === 'insurance_companies' ? 'active' : ''; ?>" data-tab="insurance_companies">
                    <i class="fas fa-building"></i> Sigorta Şirketleri
                </li>
                <li class="<?php echo $active_tab === 'task_types' ? 'active' : ''; ?>" data-tab="task_types">
                    <i class="fas fa-tasks"></i> Görev Türleri
                </li>
                <li class="<?php echo $active_tab === 'payment_info' ? 'active' : ''; ?>" data-tab="payment_info">
                    <i class="fas fa-credit-card"></i> Ödeme Bilgileri
                </li>
                <li class="<?php echo $active_tab === 'notifications' ? 'active' : ''; ?>" data-tab="notifications">
                    <i class="fas fa-bell"></i> Bildirim Ayarları
                </li>
                <li class="<?php echo $active_tab === 'email_templates' ? 'active' : ''; ?>" data-tab="email_templates">
                    <i class="fas fa-envelope"></i> E-posta Şablonları
                </li>
                <li class="<?php echo $active_tab === 'site_appearance' ? 'active' : ''; ?>" data-tab="site_appearance">
                    <i class="fas fa-paint-brush"></i> Site Görünümü
                </li>
                <li class="<?php echo $active_tab === 'file_upload' ? 'active' : ''; ?>" data-tab="file_upload">
                    <i class="fas fa-cloud-upload-alt"></i> Dosya Yükleme
                </li>
                <li class="<?php echo $active_tab === 'occupations' ? 'active' : ''; ?>" data-tab="occupations">
                    <i class="fas fa-briefcase"></i> Meslekler
                </li>
            </ul>
        </div>
        
        <div class="modern-settings-content">
            <form method="post" action="" class="modern-settings-form" enctype="multipart/form-data">
                <?php wp_nonce_field('save_settings', 'settings_nonce'); ?>
                <input type="hidden" name="active_tab" id="active_tab" value="<?php echo esc_attr($active_tab); ?>">
                
                <!-- Genel Ayarlar -->
                <div class="settings-tab <?php echo $active_tab === 'general' ? 'active' : ''; ?>" id="general-tab">
                    <div class="tab-header">
                        <h2><i class="fas fa-home"></i> Genel Ayarlar</h2>
                    </div>
                    <div class="tab-content">
                        <div class="form-group">
                            <label for="company_name">Şirket Adı</label>
                            <input type="text" name="company_name" id="company_name" class="form-control" 
                                  value="<?php echo esc_attr($settings['company_name']); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="company_email">Şirket E-posta</label>
                            <input type="email" name="company_email" id="company_email" class="form-control" 
                                  value="<?php echo esc_attr($settings['company_email']); ?>">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="renewal_reminder_days">Yenileme Hatırlatma (Gün)</label>
                                <input type="number" name="renewal_reminder_days" id="renewal_reminder_days" class="form-control" 
                                      value="<?php echo esc_attr($settings['renewal_reminder_days']); ?>" min="1" max="90">
                                <div class="form-hint">Poliçe yenileme hatırlatması için kaç gün önceden bildirim gönderilsin?</div>
                            </div>
                            
                            <div class="form-group col-md-6">
                                <label for="task_reminder_days">Görev Hatırlatma (Gün)</label>
                                <input type="number" name="task_reminder_days" id="task_reminder_days" class="form-control" 
                                      value="<?php echo esc_attr($settings['task_reminder_days']); ?>" min="1" max="30">
                                <div class="form-hint">Görev hatırlatması için kaç gün önceden bildirim gönderilsin?</div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="submit_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Ayarları Kaydet
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Poliçe Türleri -->
                <div class="settings-tab <?php echo $active_tab === 'policy_types' ? 'active' : ''; ?>" id="policy_types-tab">
                    <div class="tab-header">
                        <h2><i class="fas fa-file-invoice"></i> Poliçe Türleri</h2>
                    </div>
                    <div class="tab-content">
                        <div class="form-group">
                            <label for="default_policy_types">Varsayılan Poliçe Türleri</label>
                            <textarea name="default_policy_types" id="default_policy_types" class="form-control" rows="10"><?php echo esc_textarea(implode("\n", $settings['default_policy_types'])); ?></textarea>
                            <div class="form-hint">Her satıra bir poliçe türü yazın. Bu liste poliçe formlarında seçenek olarak sunulacaktır.</div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="submit_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Ayarları Kaydet
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Sigorta Şirketleri -->
                <div class="settings-tab <?php echo $active_tab === 'insurance_companies' ? 'active' : ''; ?>" id="insurance_companies-tab">
                    <div class="tab-header">
                        <h2><i class="fas fa-building"></i> Sigorta Şirketleri</h2>
                    </div>
                    <div class="tab-content">
                        <div class="form-group">
                            <label for="insurance_companies">Sigorta Firmaları Listesi</label>
                            <textarea name="insurance_companies" id="insurance_companies" class="form-control" rows="10"><?php echo esc_textarea(implode("\n", $settings['insurance_companies'])); ?></textarea>
                            <div class="form-hint">Her satıra bir sigorta firması adı yazın. Bu liste poliçe formlarında seçenek olarak sunulacaktır.</div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="submit_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Ayarları Kaydet
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Görev Türleri -->
                <div class="settings-tab <?php echo $active_tab === 'task_types' ? 'active' : ''; ?>" id="task_types-tab">
                    <div class="tab-header">
                        <h2><i class="fas fa-tasks"></i> Görev Türleri</h2>
                    </div>
                    <div class="tab-content">
                        <div class="form-group">
                            <label for="default_task_types">Varsayılan Görev Türleri</label>
                            <textarea name="default_task_types" id="default_task_types" class="form-control" rows="10"><?php echo esc_textarea(implode("\n", $settings['default_task_types'])); ?></textarea>
                            <div class="form-hint">Her satıra bir görev türü yazın. Bu liste görev formlarında seçenek olarak sunulacaktır.</div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="submit_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Ayarları Kaydet
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Ödeme Bilgileri -->
                <div class="settings-tab <?php echo $active_tab === 'payment_info' ? 'active' : ''; ?>" id="payment_info-tab">
                    <div class="tab-header">
                        <h2><i class="fas fa-credit-card"></i> Ödeme Bilgileri</h2>
                    </div>
                    <div class="tab-content">
                        <div class="form-group">
                            <label for="payment_options">Ödeme Seçenekleri</label>
                            <textarea name="payment_options" id="payment_options" class="form-control" rows="12"><?php echo esc_textarea(implode("\n", $settings['payment_options'])); ?></textarea>
                            <div class="form-hint">Her satıra bir ödeme seçeneği yazın. Bu liste poliçe formlarında ödeme bilgisi olarak sunulacaktır. Örnek: Peşin, 3 Taksit, 6 Taksit, 8 Taksit, 9 Taksit, 12 Taksit, Ödenmedi, Nakit, Kredi Kartı, Havale, Diğer</div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="submit_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Ayarları Kaydet
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Bildirim Ayarları -->
                <div class="settings-tab <?php echo $active_tab === 'notifications' ? 'active' : ''; ?>" id="notifications-tab">
                    <div class="tab-header">
                        <h2><i class="fas fa-bell"></i> Bildirim Ayarları</h2>
                    </div>
                    <div class="tab-content">
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="email_notifications" id="email_notifications" 
                                       <?php checked(isset($settings['notification_settings']['email_notifications']) ? $settings['notification_settings']['email_notifications'] : false); ?>>
                                <span class="checkbox-text">E-posta bildirimlerini etkinleştir</span>
                            </label>
                            <div class="form-hint">Sistem bildirimleri e-posta yoluyla da gönderilir.</div>
                        </div>
                        
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="renewal_notifications" id="renewal_notifications"
                                       <?php checked(isset($settings['notification_settings']['renewal_notifications']) ? $settings['notification_settings']['renewal_notifications'] : false); ?>>
                                <span class="checkbox-text">Poliçe yenileme bildirimlerini etkinleştir</span>
                            </label>
                            <div class="form-hint">Poliçe yenilemeleri için bildirim gönderilir.</div>
                        </div>
                        
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="task_notifications" id="task_notifications"
                                       <?php checked(isset($settings['notification_settings']['task_notifications']) ? $settings['notification_settings']['task_notifications'] : false); ?>>
                                <span class="checkbox-text">Görev bildirimlerini etkinleştir</span>
                            </label>
                            <div class="form-hint">Görevler için bildirim gönderilir.</div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="submit_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Ayarları Kaydet
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- E-posta Şablonları -->
                <div class="settings-tab <?php echo $active_tab === 'email_templates' ? 'active' : ''; ?>" id="email_templates-tab">
                    <div class="tab-header">
                        <h2><i class="fas fa-envelope"></i> E-posta Şablonları</h2>
                    </div>
                    <div class="tab-content">
                        <div class="form-group">
                            <label for="renewal_reminder_template">Yenileme Hatırlatma Şablonu</label>
                            <textarea name="renewal_reminder_template" id="renewal_reminder_template" class="form-control" rows="8"><?php 
                                echo isset($settings['email_templates']['renewal_reminder']) ? esc_textarea($settings['email_templates']['renewal_reminder']) : ''; 
                            ?></textarea>
                            <div class="form-hint">
                                Kullanılabilir değişkenler: {customer_name}, {policy_number}, {policy_type}, {end_date}, {premium_amount}
                            </div>
                            <div class="form-actions" style="margin-top: 10px;">
                                <button type="button" class="btn btn-secondary test-template-btn" data-template="renewal_reminder">
                                    <i class="fas fa-paper-plane"></i> Test E-postası Gönder
                                </button>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="task_reminder_template">Görev Hatırlatma Şablonu</label>
                            <textarea name="task_reminder_template" id="task_reminder_template" class="form-control" rows="8"><?php 
                                echo isset($settings['email_templates']['task_reminder']) ? esc_textarea($settings['email_templates']['task_reminder']) : ''; 
                            ?></textarea>
                            <div class="form-hint">
                                Kullanılabilir değişkenler: {customer_name}, {task_description}, {due_date}, {priority}
                            </div>
                            <div class="form-actions" style="margin-top: 10px;">
                                <button type="button" class="btn btn-secondary test-template-btn" data-template="task_reminder">
                                    <i class="fas fa-paper-plane"></i> Test E-postası Gönder
                                </button>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_policy_template">Yeni Poliçe Bildirimi</label>
                            <textarea name="new_policy_template" id="new_policy_template" class="form-control" rows="8"><?php 
                                echo isset($settings['email_templates']['new_policy']) ? esc_textarea($settings['email_templates']['new_policy']) : ''; 
                            ?></textarea>
                            <div class="form-hint">
                                Kullanılabilir değişkenler: {customer_name}, {policy_number}, {policy_type}, {start_date}, {end_date}, {premium_amount}
                            </div>
                            <div class="form-actions" style="margin-top: 10px;">
                                <button type="button" class="btn btn-secondary test-template-btn" data-template="new_policy">
                                    <i class="fas fa-paper-plane"></i> Test E-postası Gönder
                                </button>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="submit_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Ayarları Kaydet
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Site Görünümü -->
                <div class="settings-tab <?php echo $active_tab === 'site_appearance' ? 'active' : ''; ?>" id="site_appearance-tab">
                    <div class="tab-header">
                        <h2><i class="fas fa-paint-brush"></i> Site Görünümü</h2>
                    </div>
                    <div class="tab-content">
                        <div class="form-group">
                            <label for="login_logo">Giriş Paneli Logo</label>
                            <div class="logo-upload-section">
                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label for="logo_upload">Logo Dosyası Yükle</label>
                                        <input type="file" name="logo_upload" id="logo_upload" class="form-control" accept="image/jpeg,image/jpg,image/png,image/gif">
                                        <div class="form-hint">JPG, PNG veya GIF formatında maksimum 2MB dosya yükleyebilirsiniz.</div>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label for="login_logo">Veya Logo URL'si</label>
                                        <input type="text" name="login_logo" id="login_logo" class="form-control" 
                                              value="<?php echo esc_attr(isset($settings['site_appearance']['login_logo']) ? $settings['site_appearance']['login_logo'] : ''); ?>">
                                        <div class="form-hint">Giriş sayfasında görüntülenecek logo URL'si.</div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($settings['site_appearance']['login_logo'])): ?>
                                <div class="logo-preview" style="margin-top: 15px;">
                                    <strong>Mevcut Logo Önizleme:</strong><br>
                                    <img src="<?php echo esc_url($settings['site_appearance']['login_logo']); ?>" alt="Logo Önizleme" style="max-height: 100px; border: 1px solid #ddd; padding: 5px; border-radius: 5px; margin-top: 10px;">
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label for="font_family">Font Ailesi</label>
                                <input type="text" name="font_family" id="font_family" class="form-control" 
                                       value="<?php echo esc_attr(isset($settings['site_appearance']['font_family']) ? $settings['site_appearance']['font_family'] : 'Arial, sans-serif'); ?>">
                                <div class="form-hint">Örnek: "Arial, sans-serif" veya "Open Sans, sans-serif"</div>
                            </div>
                            
                            <div class="form-group col-md-4">
                                <label for="primary_color">Ana Renk</label>
                                <div class="color-picker-container">
                                    <input type="color" name="primary_color" id="primary_color" class="color-picker" 
                                           value="<?php echo esc_attr(isset($settings['site_appearance']['primary_color']) ? $settings['site_appearance']['primary_color'] : '#3498db'); ?>">
                                    <span class="color-value"><?php echo esc_attr(isset($settings['site_appearance']['primary_color']) ? $settings['site_appearance']['primary_color'] : '#3498db'); ?></span>
                                </div>
                                <div class="form-hint">Giriş paneli, butonlar ve firma adı için ana renk.</div>
                            </div>
                            
                            <div class="form-group col-md-4">
                                <label for="secondary_color">İkinci Ana Renk</label>
                                <div class="color-picker-container">
                                    <input type="color" name="secondary_color" id="secondary_color" class="color-picker" 
                                           value="<?php echo esc_attr(isset($settings['site_appearance']['secondary_color']) ? $settings['site_appearance']['secondary_color'] : '#ffd93d'); ?>">
                                    <span class="color-value"><?php echo esc_attr(isset($settings['site_appearance']['secondary_color']) ? $settings['site_appearance']['secondary_color'] : '#ffd93d'); ?></span>
                                </div>
                                <div class="form-hint">Doğum günü tablosu ve özel paneller için ikinci ana renk.</div>
                            </div>
                            
                            <div class="form-group col-md-4">
                                <label for="sidebar_color">Sol Menü Rengi</label>
                                <div class="color-picker-container">
                                    <input type="color" name="sidebar_color" id="sidebar_color" class="color-picker" 
                                           value="<?php echo esc_attr(isset($settings['site_appearance']['sidebar_color']) ? $settings['site_appearance']['sidebar_color'] : '#2c3e50'); ?>">
                                    <span class="color-value"><?php echo esc_attr(isset($settings['site_appearance']['sidebar_color']) ? $settings['site_appearance']['sidebar_color'] : '#2c3e50'); ?></span>
                                </div>
                                <div class="form-hint">Sol menü ve yan panel için ana renk.</div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="submit_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Ayarları Kaydet
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Dosya Yükleme Ayarları -->
                <div class="settings-tab <?php echo $active_tab === 'file_upload' ? 'active' : ''; ?>" id="file_upload-tab">
                    <div class="tab-header">
                        <h2><i class="fas fa-cloud-upload-alt"></i> Dosya Yükleme Ayarları</h2>
                    </div>
                    <div class="tab-content">
                        <div class="form-group">
                            <label>İzin Verilen Dosya Formatları</label>
                            <div class="file-types-grid">
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="jpg" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('jpg', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">JPEG Resim (.jpg)</span>
                                    </label>
                                </div>
                                
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="jpeg" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('jpeg', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">JPEG Resim (.jpeg)</span>
                                    </label>
                                </div>
                                
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="png" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('png', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">PNG Resim (.png)</span>
                                    </label>
                                </div>
                                
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="pdf" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('pdf', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">PDF Dokümanı (.pdf)</span>
                                    </label>
                                </div>
                                
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="doc" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('doc', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">Word Dokümanı (.doc)</span>
                                    </label>
                                </div>
                                
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="docx" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('docx', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">Word Dokümanı (.docx)</span>
                                    </label>
                                </div>
                                
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="xls" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('xls', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">Excel Tablosu (.xls)</span>
                                    </label>
                                </div>
                                
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="xlsx" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('xlsx', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">Excel Tablosu (.xlsx)</span>
                                    </label>
                                </div>
                                
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="txt" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('txt', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">Metin Dosyası (.txt)</span>
                                    </label>
                                </div>
                                
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="zip" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('zip', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">Arşiv Dosyası (.zip)</span>
                                    </label>
                                </div>
                            </div>
                            <div class="form-hint">Sistem içinde yüklenebilecek dosya türlerini seçin. Seçili olmayan dosya türleri yüklenemez.</div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="submit_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Ayarları Kaydet
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Meslekler -->
                <div class="settings-tab <?php echo $active_tab === 'occupations' ? 'active' : ''; ?>" id="occupations-tab">
                    <div class="tab-header">
                        <h2><i class="fas fa-briefcase"></i> Meslekler</h2>
                    </div>
                    <div class="tab-content">
                        <div class="form-group">
                            <label for="default_occupations">Varsayılan Meslekler</label>
                            <textarea name="default_occupations" id="default_occupations" class="form-control" rows="10"><?php echo esc_textarea(implode("\n", $settings['occupation_settings']['default_occupations'])); ?></textarea>
                            <div class="form-hint">Her satıra bir meslek adı yazın. Bu liste müşteri formlarında seçenek olarak sunulacaktır.</div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="submit_settings" class="btn-modern btn-save">
                                <i class="fas fa-save"></i> Ayarları Kaydet
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* Modern Settings Container */
.modern-settings-container {
    padding: 30px;
    background-color: #f8f9fa;
    min-height: 100vh;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

/* Modern Header */
.page-header-modern {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 20px;
    margin-bottom: 30px;
    color: white;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.header-main {
    padding: 40px 40px 20px 40px;
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.header-left h1 {
    font-size: 32px;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 15px;
}

.header-subtitle-section {
    padding: 0 40px 30px 40px;
    border-top: 1px solid rgba(255, 255, 255, 0.2);
    margin-top: 20px;
    padding-top: 20px;
}

.header-subtitle {
    font-size: 18px;
    opacity: 0.9;
    margin: 0;
    line-height: 1.4;
}

/* Modern Buttons */
.btn-modern {
    padding: 12px 24px;
    border-radius: 10px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.btn-primary {
    background: white;
    color: #667eea;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
}

.btn-save {
    background: #667eea;
    color: white;
}

.btn-save:hover {
    background: #5a67d8;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
}

.btn-secondary {
    background: #e2e8f0;
    color: #64748b;
}

.btn-secondary:hover {
    background: #cbd5e1;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(203, 213, 225, 0.3);
}

/* Statistics Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 15px;
    padding: 25px;
    display: flex;
    align-items: center;
    gap: 20px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    transition: transform 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 24px;
}

.stat-content h3 {
    font-size: 28px;
    font-weight: 700;
    margin: 0;
    color: #1e293b;
}

.stat-content p {
    font-size: 14px;
    color: #64748b;
    margin: 5px 0 0 0;
}

/* Modern Message Boxes */
.modern-message-box {
    display: flex;
    align-items: flex-start;
    padding: 20px;
    margin-bottom: 25px;
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    animation: fadeIn 0.3s ease;
}

.modern-message-box.error-box {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #991b1b;
}

.modern-message-box.success-box {
    background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
    color: #166534;
}

.modern-message-box i {
    margin-right: 15px;
    font-size: 20px;
    margin-top: 2px;
}

.message-content h4 {
    margin: 0 0 10px 0;
    font-size: 16px;
    font-weight: 600;
}

.message-content ul {
    margin: 0;
    padding-left: 20px;
}

.message-content p {
    margin: 0;
}

/* Modern Settings Content */
.modern-settings-container-content {
    display: flex;
    background: white;
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    overflow: hidden;
}

.modern-settings-sidebar {
    width: 280px;
    background: #f8fafc;
    border-right: 1px solid #e2e8f0;
    flex-shrink: 0;
}

.sidebar-header-modern {
    padding: 25px;
    border-bottom: 1px solid #e2e8f0;
    background: white;
}

.sidebar-header-modern h3 {
    margin: 0;
    font-size: 16px;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
}

.modern-settings-menu {
    list-style: none;
    padding: 0;
    margin: 0;
}

.modern-settings-menu li {
    padding: 15px 25px;
    font-size: 14px;
    cursor: pointer;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 12px;
    transition: all 0.3s ease;
    font-weight: 500;
}

.modern-settings-menu li i {
    width: 20px;
    text-align: center;
    color: #64748b;
}

.modern-settings-menu li:hover {
    background: #f1f5f9;
    padding-left: 30px;
}

.modern-settings-menu li.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: 600;
}

.modern-settings-menu li.active i {
    color: white;
}

.modern-settings-content {
    flex: 1;
    padding: 30px;
    max-height: 800px;
    overflow-y: auto;
}

.settings-tab {
    display: none;
}

.settings-tab.active {
    display: block;
}

.tab-header {
    margin-bottom: 25px;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 15px;
}

.tab-header h2 {
    margin: 0;
    font-size: 24px;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 700;
}

.tab-content {
    background: #f8fafc;
    border-radius: 10px;
    padding: 25px;
}

/* Form Styling */
.form-group {
    margin-bottom: 25px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #374151;
    font-size: 14px;
}

.form-control, .form-select {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 14px;
    transition: all 0.3s ease;
    background-color: white;
}

.form-control:focus, .form-select:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.form-hint {
    margin-top: 8px;
    font-size: 13px;
    color: #64748b;
    font-style: italic;
}

textarea.form-control {
    min-height: 120px;
    resize: vertical;
}

/* Form Layout */
.form-row {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
}

.col-md-6 {
    flex: 1;
}

.col-md-4 {
    flex: 1;
}

.form-actions {
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
}

/* Button Styling */
.btn {
    display: inline-flex;
    align-items: center;
    font-weight: 500;
    text-align: center;
    white-space: nowrap;
    vertical-align: middle;
    user-select: none;
    border: 1px solid transparent;
    padding: 10px 16px;
    font-size: 14px;
    line-height: 1.5;
    border-radius: 8px;
    transition: all 0.15s ease-in-out;
    cursor: pointer;
    text-decoration: none;
}

.btn i {
    margin-right: 8px;
}

.btn-primary {
    color: #fff;
    background-color: #667eea;
    border-color: #667eea;
}

.btn-primary:hover, .btn-primary:focus {
    background-color: #5a67d8;
    border-color: #5a67d8;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
}

/* Checkbox Styling */
.checkbox-group {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.checkbox-item {
    display: flex;
    align-items: center;
    gap: 10px;
}

.checkbox-item input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: #667eea;
}

.checkbox-item label {
    margin: 0;
    font-weight: 500;
    cursor: pointer;
}

/* File Types Grid */
.file-types-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

/* Color Picker Styling */
.color-picker-container {
    display: flex;
    align-items: center;
    gap: 15px;
}

.color-picker-container input[type="color"] {
    width: 50px;
    height: 50px;
    border: none;
    border-radius: 10px;
    cursor: pointer;
}

.color-value {
    background: #f1f5f9;
    padding: 8px 12px;
    border-radius: 6px;
    font-family: monospace;
    font-weight: 600;
    color: #374151;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Responsive Design */
@media (max-width: 768px) {
    .header-main {
        padding: 25px;
    }
    
    .header-subtitle-section {
        padding: 0 25px 25px 25px;
    }
    
    .modern-settings-container-content {
        flex-direction: column;
    }
    
    .modern-settings-sidebar {
        width: 100%;
    }
    
    .modern-settings-menu {
        display: flex;
        flex-wrap: wrap;
    }
    
    .modern-settings-menu li {
        flex: 0 0 50%;
        box-sizing: border-box;
    }
    
    .header-content {
        flex-direction: column;
        gap: 20px;
        text-align: center;
    }
    
    .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    }
    
    .form-row {
        flex-direction: column;
    }
    
    .col-md-6 {
        flex: none;
    }
}

@media (max-width: 480px) {
    .modern-settings-container {
        padding: 20px;
    }
    
    .header-main {
        padding: 20px;
    }
    
    .header-subtitle-section {
        padding: 0 20px 20px 20px;
    }
    
    .header-left h1 {
        font-size: 24px;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .modern-settings-menu li {
        flex: 0 0 100%;
    }
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Sekme değiştirme fonksiyonu
    const menuItems = document.querySelectorAll('.modern-settings-menu li');
    const tabs = document.querySelectorAll('.settings-tab');
    const activeTabInput = document.getElementById('active_tab');
    
    menuItems.forEach(item => {
        item.addEventListener('click', () => {
            const tabId = item.dataset.tab;
            
            // Aktif menü öğesini değiştir
            menuItems.forEach(i => i.classList.remove('active'));
            item.classList.add('active');
            
            // Aktif sekmeyi değiştir
            tabs.forEach(tab => {
                tab.classList.remove('active');
                if (tab.id === tabId + '-tab') {
                    tab.classList.add('active');
                    activeTabInput.value = tabId;
                }
            });
        });
    });
    
    // Renk seçici değiştiğinde değeri güncelle
    const colorPicker = document.getElementById('primary_color');
    const colorValue = document.querySelector('.color-value');
    const sidebarColorPicker = document.getElementById('sidebar_color');
    const sidebarColorValues = document.querySelectorAll('.color-value');
    
    if (colorPicker && colorValue) {
        colorPicker.addEventListener('input', function() {
            colorValue.textContent = this.value;
        });
    }
    
    if (sidebarColorPicker && sidebarColorValues[1]) {
        sidebarColorPicker.addEventListener('input', function() {
            sidebarColorValues[1].textContent = this.value;
        });
    }
    
    // Test email functionality
    const testButtons = document.querySelectorAll('.test-template-btn');
    
    testButtons.forEach(button => {
        button.addEventListener('click', function() {
            const templateType = this.dataset.template;
            const originalText = this.innerHTML;
            
            // Disable button and show loading
            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gönderiliyor...';
            
            // Prepare AJAX data
            const formData = new FormData();
            formData.append('action', 'insurance_crm_test_template_email');
            formData.append('nonce', '<?php echo wp_create_nonce('insurance_crm_test_template_email'); ?>');
            formData.append('template_type', templateType);
            
            // Send AJAX request
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                // Reset button
                this.disabled = false;
                this.innerHTML = originalText;
                
                // Show result
                if (data.success) {
                    alert('✅ ' + data.data);
                } else {
                    alert('❌ ' + data.data);
                }
            })
            .catch(error => {
                // Reset button on error
                this.disabled = false;
                this.innerHTML = originalText;
                alert('❌ Bir hata oluştu: ' + error.message);
            });
        });
    });
});
</script>
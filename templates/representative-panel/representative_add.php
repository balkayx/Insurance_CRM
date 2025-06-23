<?php
/**
 * Yeni Temsilci Ekleme Sayfası
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

// Email şablonları için gerekli dosyayı dahil et
require_once(dirname(dirname(dirname(__FILE__))) . '/includes/email-templates.php');

// Yetki kontrolü - sadece patron ve müdür yeni temsilci ekleyebilir
if (!is_patron($current_user->ID) && !is_manager($current_user->ID)) {
    wp_die('Bu sayfaya erişim yetkiniz bulunmuyor.');
}

// Form gönderildiğinde yeni temsilci oluştur
if (isset($_POST['submit_representative']) && isset($_POST['representative_nonce']) && 
    wp_verify_nonce($_POST['representative_nonce'], 'add_representative')) {
    
    $error_messages = array();
    $success_message = '';
    
    // Form verilerini doğrula
    $username = sanitize_user($_POST['username']);
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    $email = sanitize_email($_POST['email']);
    $first_name = sanitize_text_field($_POST['first_name']);
    $last_name = sanitize_text_field($_POST['last_name']);
    $phone = sanitize_text_field($_POST['phone']);
    $department = sanitize_text_field($_POST['department']);
    $monthly_target = floatval($_POST['monthly_target']);
    $target_policy_count = intval($_POST['target_policy_count']);
    $role = intval($_POST['role']);
    
    // Yetkilendirme değerlerini kontrol et
    $customer_edit = isset($_POST['customer_edit']) ? 1 : 0;
    $customer_delete = isset($_POST['customer_delete']) ? 1 : 0;
    $policy_edit = isset($_POST['policy_edit']) ? 1 : 0;
    $policy_delete = isset($_POST['policy_delete']) ? 1 : 0;
    
    // Zorunlu alanları kontrol et
    if (empty($username)) {
        $error_messages[] = 'Kullanıcı adı gereklidir.';
    }
    
    if (empty($password)) {
        $error_messages[] = 'Şifre gereklidir.';
    }
    
    if ($password !== $confirm_password) {
        $error_messages[] = 'Şifreler eşleşmiyor.';
    }
    
    if (empty($email)) {
        $error_messages[] = 'E-posta adresi gereklidir.';
    }
    
    if (empty($first_name) || empty($last_name)) {
        $error_messages[] = 'Ad ve soyad gereklidir.';
    }
    
    // Kullanıcı adı ve e-posta kontrolü
    if (username_exists($username)) {
        $error_messages[] = 'Bu kullanıcı adı zaten kullanımda.';
    }
    
    if (email_exists($email)) {
        $error_messages[] = 'Bu e-posta adresi zaten kullanımda.';
    }
    
    // Avatar dosya yükleme işlemi
    $avatar_url = '';
    if (isset($_FILES['avatar_file']) && !empty($_FILES['avatar_file']['name'])) {
        $file = $_FILES['avatar_file'];
        $allowed_types = array('image/jpeg', 'image/jpg', 'image/png', 'image/gif');

        if (!in_array($file['type'], $allowed_types)) {
            $error_messages[] = 'Geçersiz dosya türü. Sadece JPG, JPEG, PNG ve GIF dosyalarına izin veriliyor.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $error_messages[] = 'Dosya boyutu 5MB\'dan büyük olamaz.';
        } else {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');

            $attachment_id = media_handle_upload('avatar_file', 0);

            if (is_wp_error($attachment_id)) {
                $error_messages[] = 'Dosya yüklenemedi: ' . $attachment_id->get_error_message();
            } else {
                $avatar_url = wp_get_attachment_url($attachment_id);
            }
        }
    }
    
    // Hata yoksa yeni temsilci oluştur
    if (empty($error_messages)) {
        // WordPress kullanıcısı oluştur
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            $error_messages[] = 'Kullanıcı oluşturulurken hata: ' . $user_id->get_error_message();
        } else {
            // Kullanıcı detaylarını güncelle
            wp_update_user(
                array(
                    'ID' => $user_id,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'display_name' => $first_name . ' ' . $last_name
                )
            );
            
            // Kullanıcıya rol ata
            $user = new WP_User($user_id);
            $user->set_role('insurance_representative');
            
            // Müşteri temsilcisi kaydı oluştur
            $table_reps = $wpdb->prefix . 'insurance_crm_representatives';
            $insert_result = $wpdb->insert(
                $table_reps,
                array(
                    'user_id' => $user_id,
                    'role' => $role,
                    'title' => isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '',
                    'phone' => $phone,
                    'department' => $department,
                    'monthly_target' => $monthly_target,
                    'target_policy_count' => $target_policy_count,
                    'avatar_url' => $avatar_url,
                    'customer_edit' => $customer_edit,
                    'customer_delete' => $customer_delete,
                    'policy_edit' => $policy_edit,
                    'policy_delete' => $policy_delete,
                    'status' => 'active',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                )
            );
            
            if ($insert_result === false) {
                $error_messages[] = 'Temsilci kaydı oluşturulurken hata: ' . $wpdb->last_error;
                
                // Kullanıcı kaydını sil
                require_once(ABSPATH . 'wp-admin/includes/user.php');
                wp_delete_user($user_id);
            } else {
                $success_message = 'Müşteri temsilcisi başarıyla eklendi.';
                
                // Email bildirimini gönder
                try {
                    $email_template = insurance_crm_get_email_template('new_representative');
                    if (!empty($email_template)) {
                        $email_variables = array(
                            'first_name' => $first_name,
                            'last_name' => $last_name,
                            'username' => $username,
                            'password' => $password,
                            'login_url' => home_url('/temsilci-paneli/')
                        );
                        
                        $email_subject = 'Hoş Geldiniz! Yeni Hesap Bilgileriniz';
                        $email_sent = insurance_crm_send_template_email($email, $email_subject, $email_template, $email_variables);
                        
                        if (!$email_sent) {
                            // Email gönderim hatası durumunda log tutmak için
                            error_log('Yeni temsilci email bildirimi gönderilemedi: ' . $email);
                        }
                    }
                } catch (Exception $e) {
                    // Email hatası durumunda sessizce devam et - ana işlem başarılı
                    error_log('Email gönderim hatası: ' . $e->getMessage());
                }
                
                // Aktivite logu ekle
                $table_logs = $wpdb->prefix . 'insurance_crm_activity_log';
                $wpdb->insert(
                    $table_logs,
                    array(
                        'user_id' => $current_user->ID,
                        'username' => $current_user->display_name,
                        'action_type' => 'create',
                        'action_details' => json_encode(array(
                            'item_type' => 'representative',
                            'item_id' => $wpdb->insert_id,
                            'name' => $first_name . ' ' . $last_name,
                            'created_by' => $current_user->display_name
                        )),
                        'created_at' => current_time('mysql')
                    )
                );
            }
        }
    }
}
?>

<!-- Modern Representative Add Container -->
<div class="modern-representative-container">
    <!-- Modern Header -->
    <div class="page-header-modern">
        <div class="header-main">
            <div class="header-content">
                <div class="header-left">
                    <h1><i class="fas fa-user-plus"></i> Yeni Müşteri Temsilcisi Ekle</h1>
                </div>
                <div class="header-right">
                    <a href="<?php echo generate_panel_url('all_personnel'); ?>" class="btn-modern btn-primary">
                        <i class="fas fa-users"></i> Tüm Personel
                    </a>
                </div>
            </div>
        </div>
        <div class="header-subtitle-section">
            <p class="header-subtitle">Sisteme yeni bir müşteri temsilcisi kaydı oluşturun</p>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <i class="fas fa-user-plus"></i>
            </div>
            <div class="stat-content">
                <h3>Yeni</h3>
                <p>Temsilci Kaydı</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #5ee7df 0%, #66a6ff 100%);">
                <i class="fas fa-shield-alt"></i>
            </div>
            <div class="stat-content">
                <h3>5</h3>
                <p>Yetki Türü</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <i class="fas fa-user-cog"></i>
            </div>
            <div class="stat-content">
                <h3>4</h3>
                <p>Özel İzin</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                <i class="fas fa-image"></i>
            </div>
            <div class="stat-content">
                <h3>Avatar</h3>
                <p>Profil Fotoğrafı</p>
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
                <div class="action-buttons">
                    <a href="<?php echo home_url('?view=all_personnel'); ?>" class="btn-modern btn-primary">
                        <i class="fas fa-users"></i> Tüm Personeli Görüntüle
                    </a>
                    <a href="<?php echo home_url('?view=representative_add'); ?>" class="btn-modern btn-secondary">
                        <i class="fas fa-plus"></i> Başka Temsilci Ekle
                    </a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Modern Representative Form -->
        <div class="modern-form-section">
            <form method="post" action="" class="modern-representative-form" enctype="multipart/form-data">
                <?php wp_nonce_field('add_representative', 'representative_nonce'); ?>
                
                <div class="modern-form-card">
                    <div class="form-card-header">
                        <h2><i class="fas fa-user"></i> Kullanıcı Bilgileri</h2>
                    </div>
                    <div class="form-card-body">
                        <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="username">Kullanıcı Adı <span class="required">*</span></label>
                            <input type="text" name="username" id="username" class="modern-form-control" required>
                            <div class="form-hint">Giriş için kullanılacak kullanıcı adı. Bu sonradan değiştirilemez.</div>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="email">E-posta Adresi <span class="required">*</span></label>
                            <input type="email" name="email" id="email" class="modern-form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="password">Şifre <span class="required">*</span></label>
                            <div class="modern-password-field">
                                <input type="password" name="password" id="password" class="modern-form-control" required>
                                <button type="button" class="modern-password-toggle" tabindex="-1">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="form-hint">En az 8 karakter olmalıdır.</div>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="confirm_password">Şifre (Tekrar) <span class="required">*</span></label>
                            <div class="modern-password-field">
                                <input type="password" name="confirm_password" id="confirm_password" class="modern-form-control" required>
                                <button type="button" class="modern-password-toggle" tabindex="-1">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="first_name">Ad <span class="required">*</span></label>
                            <input type="text" name="first_name" id="first_name" class="modern-form-control" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="last_name">Soyad <span class="required">*</span></label>
                            <input type="text" name="last_name" id="last_name" class="modern-form-control" required>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modern-form-card">
                <div class="form-card-header">
                    <h2><i class="fas fa-id-card"></i> Temsilci Bilgileri</h2>
                </div>
                <div class="form-card-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="role">Rol <span class="required">*</span></label>
                            <select name="role" id="role" class="modern-form-control" required>
                                <option value="5">Müşteri Temsilcisi</option>
                                <option value="4">Ekip Lideri</option>
                                <option value="3">Müdür Yardımcısı</option>
                                <option value="2">Müdür</option>
                                <?php if (is_patron($current_user->ID)): ?>
                                <option value="1">Patron</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="title">Ünvan</label>
                            <input type="text" name="title" id="title" class="modern-form-control" placeholder="Örn: Satış Uzmanı">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="phone">Telefon <span class="required">*</span></label>
                            <input type="tel" name="phone" id="phone" class="modern-form-control" required placeholder="5XX XXX XXXX">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="department">Departman</label>
                            <input type="text" name="department" id="department" class="modern-form-control" placeholder="Örn: Satış, Müşteri İlişkileri">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="monthly_target">Aylık Hedef (₺) <span class="required">*</span></label>
                            <input type="number" step="0.01" min="0" name="monthly_target" id="monthly_target" class="modern-form-control" required>
                            <div class="form-hint">Temsilcinin aylık satış hedefi (₺)</div>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="target_policy_count">Hedef Poliçe Adedi <span class="required">*</span></label>
                            <input type="number" step="1" min="0" name="target_policy_count" id="target_policy_count" class="modern-form-control" required>
                            <div class="form-hint">Temsilcinin aylık hedef poliçe adedi</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modern-form-card">
                <div class="form-card-header">
                    <h2><i class="fas fa-key"></i> Yetkiler ve Profil Fotoğrafı</h2>
                </div>
                <div class="form-card-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <div class="modern-permissions-grid">
                                <h4>Müşteri ve Poliçe İşlem Yetkileri</h4>
                                
                                <div class="modern-permissions-row">
                                    <label class="modern-checkbox-container">
                                        <input type="checkbox" name="customer_edit" value="1">
                                        <span class="modern-checkmark"></span>
                                        <div class="label-text">
                                            <span class="label-title">Müşteri Düzenleme</span>
                                            <span class="label-desc">Müşteri bilgilerini düzenleyebilir</span>
                                        </div>
                                    </label>
                                </div>
                                
                                <div class="modern-permissions-row">
                                    <label class="modern-checkbox-container">
                                        <input type="checkbox" name="customer_delete" value="1">
                                        <span class="modern-checkmark"></span>
                                        <div class="label-text">
                                            <span class="label-title">Müşteri Silme</span>
                                            <span class="label-desc">Müşteri kaydını pasife alabilir/silebilir</span>
                                        </div>
                                    </label>
                                </div>
                                
                                <div class="modern-permissions-row">
                                    <label class="modern-checkbox-container">
                                        <input type="checkbox" name="policy_edit" value="1">
                                        <span class="modern-checkmark"></span>
                                        <div class="label-text">
                                            <span class="label-title">Poliçe Düzenleme</span>
                                            <span class="label-desc">Poliçe bilgilerini düzenleyebilir</span>
                                        </div>
                                    </label>
                                </div>
                                
                                <div class="modern-permissions-row">
                                    <label class="modern-checkbox-container">
                                        <input type="checkbox" name="policy_delete" value="1">
                                        <span class="modern-checkmark"></span>
                                        <div class="label-text">
                                            <span class="label-title">Poliçe Silme</span>
                                            <span class="label-desc">Poliçe kaydını pasife alabilir/silebilir</span>
                                        </div>
                                    </label>
                                </div>
                                
                                <div id="role_permission_message" class="permission-message"></div>
                            </div>
                        </div>
                        
                        <div class="form-group col-md-6">
                            <label for="avatar_file">Profil Fotoğrafı</label>
                            <div class="modern-file-upload-container">
                                <div class="modern-file-upload-preview">
                                    <img src="<?php echo plugins_url('assets/images/default-avatar.png', dirname(dirname(__FILE__))); ?>" alt="Avatar Önizleme" id="avatar-preview">
                                </div>
                                <div class="modern-file-upload-controls">
                                    <input type="file" name="avatar_file" id="avatar_file" accept="image/*" class="modern-inputfile">
                                    <label for="avatar_file" class="modern-file-label">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <span>Fotoğraf Seç</span>
                                    </label>
                                    <button type="button" class="btn-modern btn-remove remove-avatar" style="display:none">
                                        <i class="fas fa-times"></i> Kaldır
                                    </button>
                                </div>
                            </div>
                            <div class="form-hint">Önerilen boyut: 100x100 piksel. (JPG, PNG, GIF. Maks 5MB)</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modern-form-actions">
                <button type="submit" name="submit_representative" class="btn-modern btn-save">
                    <i class="fas fa-save"></i> Temsilciyi Kaydet
                </button>
                <a href="<?php echo home_url('?view=all_personnel'); ?>" class="btn-modern btn-cancel">
                    <i class="fas fa-times"></i> İptal
                </a>
            </div>
        </form>
        </div>
    <?php endif; ?>
</div>

<style>
/* Modern Representative Container */
.modern-representative-container {
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

.btn-secondary {
    background: #e2e8f0;
    color: #64748b;
}

.btn-secondary:hover {
    background: #cbd5e1;
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

.btn-cancel {
    background: #e2e8f0;
    color: #64748b;
}

.btn-cancel:hover {
    background: #cbd5e1;
}

.btn-remove {
    background: #ef4444;
    color: white;
    font-size: 12px;
    padding: 8px 12px;
}

.btn-remove:hover {
    background: #dc2626;
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

.action-buttons {
    margin-top: 15px;
    display: flex;
    gap: 10px;
}

/* Modern Form */
.modern-form-section {
    background: white;
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    overflow: hidden;
}

.modern-form-card {
    margin-bottom: 20px;
}

.modern-form-card:last-of-type {
    margin-bottom: 0;
}

.form-card-header {
    padding: 20px 25px 12px;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
}

.form-card-header h2 {
    margin: 0;
    font-size: 18px;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
}

.form-card-body {
    padding: 20px 25px;
}

/* Form Styling */
.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    color: #374151;
    font-size: 13px;
}

.required {
    color: #ef4444;
}

.modern-form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 14px;
    transition: all 0.3s ease;
    background-color: white;
}

.modern-form-control:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.form-hint {
    margin-top: 4px;
    font-size: 12px;
    color: #64748b;
    font-style: italic;
}

/* Password Field */
.modern-password-field {
    position: relative;
}

.modern-password-toggle {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #64748b;
    cursor: pointer;
    padding: 4px;
    transition: color 0.3s ease;
}

.modern-password-toggle:hover {
    color: #374151;
}

/* Form Layout */
.form-row {
    display: flex;
    gap: 16px;
    margin-bottom: 12px;
}

.col-md-6 {
    flex: 1;
}

.col-md-12 {
    flex: 1;
}

/* Permissions Grid */
.modern-permissions-grid {
    background: #f8fafc;
    border-radius: 12px;
    padding: 20px;
    border: 1px solid #e2e8f0;
}

.modern-permissions-grid h4 {
    margin: 0 0 20px 0;
    font-size: 16px;
    color: #1e293b;
    font-weight: 600;
}

.modern-permissions-row {
    margin-bottom: 15px;
}

.modern-checkbox-container {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    cursor: pointer;
    padding: 12px;
    border-radius: 8px;
    transition: background-color 0.3s ease;
}

.modern-checkbox-container:hover {
    background: #f1f5f9;
}

.modern-checkbox-container input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: #667eea;
    cursor: pointer;
}

.label-text {
    display: flex;
    flex-direction: column;
}

.label-title {
    font-weight: 600;
    color: #1e293b;
    font-size: 14px;
}

.label-desc {
    font-size: 12px;
    color: #64748b;
    margin-top: 2px;
}

/* File Upload */
.modern-file-upload-container {
    text-align: center;
    background: #f8fafc;
    border-radius: 12px;
    padding: 20px;
    border: 2px dashed #e2e8f0;
    transition: all 0.3s ease;
}

.modern-file-upload-container:hover {
    border-color: #667eea;
    background: #f0f4ff;
}

.modern-file-upload-preview {
    width: 120px;
    height: 120px;
    margin: 0 auto 15px;
    border-radius: 50%;
    overflow: hidden;
    background-color: #ffffff;
    border: 3px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
}

.modern-file-upload-preview:hover {
    border-color: #667eea;
    transform: scale(1.05);
}

.modern-file-upload-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.modern-file-upload-controls {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}

.modern-inputfile {
    width: 0.1px;
    height: 0.1px;
    opacity: 0;
    overflow: hidden;
    position: absolute;
    z-index: -1;
}

.modern-file-label {
    color: #ffffff;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 12px 20px;
    border-radius: 10px;
    font-size: 14px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    font-weight: 600;
}

.modern-file-label:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
}

.modern-file-label i {
    margin-right: 5px;
}

/* Form Actions */
.modern-form-actions {
    padding: 20px 25px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
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
    .modern-representative-container {
        padding: 20px;
    }
    
    .header-main {
        padding: 25px;
    }
    
    .header-subtitle-section {
        padding: 0 25px 25px 25px;
    }
    
    .header-content {
        flex-direction: column;
        gap: 20px;
        text-align: center;
    }
    
    .header-left h1 {
        font-size: 24px;
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
    
    .form-card-header, .form-card-body {
        padding: 20px;
    }
    
    .modern-form-actions {
        flex-direction: column;
    }
    
    .modern-form-actions .btn-modern {
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .modern-file-upload-preview {
        width: 100px;
        height: 100px;
    }
    
    .modern-permissions-grid {
        padding: 15px;
    }
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Şifre göster/gizle
    const toggleButtons = document.querySelectorAll('.modern-password-toggle');
    toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            const input = this.previousElementSibling;
            const icon = this.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    });
    
    // Şifre eşleşme kontrolü
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    
    if (confirmPassword) {
        confirmPassword.addEventListener('blur', function() {
            if (password.value && this.value && this.value !== password.value) {
                alert('Şifreler eşleşmiyor!');
                this.value = '';
            }
        });
    }
    
    // Avatar yükleme önizleme
    const avatarInput = document.getElementById('avatar_file');
    const avatarPreview = document.getElementById('avatar-preview');
    const removeAvatarBtn = document.querySelector('.remove-avatar');
    
    if (avatarInput) {
        avatarInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    avatarPreview.src = e.target.result;
                    removeAvatarBtn.style.display = 'inline-block';
                };
                
                reader.readAsDataURL(this.files[0]);
            }
        });
    }
    
    if (removeAvatarBtn) {
        removeAvatarBtn.addEventListener('click', function() {
            const defaultAvatar = plugins_url + '/assets/images/default-avatar.png';
            avatarPreview.src = defaultAvatar;
            avatarInput.value = '';
            this.style.display = 'none';
        });
    }
    
    // Rol seçimine göre yetkilendirmeler
    const roleSelect = document.getElementById('role');
    const permissionMessage = document.getElementById('role_permission_message');
    const permissionCheckboxes = document.querySelectorAll('.permissions-row input[type="checkbox"]');
    
    if (roleSelect) {
        roleSelect.addEventListener('change', function() {
            const role = parseInt(this.value);
            
            // Tüm mesaj stillerini temizle
            permissionMessage.classList.remove('patron', 'manager');
            permissionMessage.style.display = 'none';
            
            // Rol özelliklerine göre yetkiler
            if (role === 1) { // Patron
                permissionCheckboxes.forEach(checkbox => {
                    checkbox.checked = true;
                    checkbox.disabled = true;
                });
                
                permissionMessage.textContent = 'Patron rolü tüm yetkilere sahiptir. Bu ayarlar otomatik olarak seçilmiş ve kilitlenmiştir.';
                permissionMessage.classList.add('patron');
                permissionMessage.style.display = 'block';
                permissionMessage.style.backgroundColor = '#e3f2fd';
                permissionMessage.style.color = '#0d47a1';
                permissionMessage.style.border = '1px solid #bbdefb';
            } 
            else if (role === 2) { // Müdür
                permissionCheckboxes.forEach(checkbox => {
                    checkbox.checked = true;
                    checkbox.disabled = false;
                });
                
                permissionMessage.textContent = 'Müdür rolü için yetkileri özelleştirebilirsiniz.';
                permissionMessage.classList.add('manager');
                permissionMessage.style.display = 'block';
                permissionMessage.style.backgroundColor = '#fff3e0';
                permissionMessage.style.color = '#e65100';
                permissionMessage.style.border = '1px solid #ffe0b2';
            }
            else {
                permissionCheckboxes.forEach(checkbox => {
                    checkbox.checked = false;
                    checkbox.disabled = false;
                });
            }
        });
        
        // Sayfa yüklendiğinde rol kontrolü
        roleSelect.dispatchEvent(new Event('change'));
    }
});
</script>
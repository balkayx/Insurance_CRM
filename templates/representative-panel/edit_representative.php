<?php
if (!defined('ABSPATH')) {
    exit;
}

// Temsilci ID'sini al
$rep_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$rep_id) {
    echo '<div class="notice notice-error"><p>Temsilci ID bulunamadı.</p></div>';
    return;
}

global $wpdb;
$current_user = wp_get_current_user();

// Kullanıcı yetkisi kontrolü - Sadece patron veya yönetici değişiklik yapabilir
$user_role = get_user_role_in_hierarchy($current_user->ID);
if ($user_role !== 'patron' && $user_role !== 'manager') {
    echo '<div class="notice notice-error"><p>Bu sayfaya erişim yetkiniz bulunmamaktadır.</p></div>';
    return;
}

// Temsilci bilgilerini al
$representative = $wpdb->get_row($wpdb->prepare(
    "SELECT r.*, u.display_name, u.user_email, u.user_login, u.user_nicename, u.ID as wp_user_id
     FROM {$wpdb->prefix}insurance_crm_representatives r
     JOIN {$wpdb->users} u ON r.user_id = u.ID
     WHERE r.id = %d",
    $rep_id
));

if (!$representative) {
    echo '<div class="notice notice-error"><p>Temsilci bulunamadı.</p></div>';
    return;
}

// Form gönderildi mi kontrol et
$success_message = '';
$errors = array();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_representative_submit'])) {
    // Nonce kontrolü
    if (!isset($_POST['edit_representative_nonce']) || !wp_verify_nonce($_POST['edit_representative_nonce'], 'edit_representative_nonce')) {
        wp_die('Güvenlik kontrolü başarısız oldu. Lütfen sayfayı yenileyip tekrar deneyin.');
    }
    
    // Form verilerini al
    $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
    $last_name = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '';
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    $monthly_target = isset($_POST['monthly_target']) ? floatval(str_replace(',', '.', $_POST['monthly_target'])) : 0;
    $target_policy_count = isset($_POST['target_policy_count']) ? intval($_POST['target_policy_count']) : 0;
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'active';
    $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $role = isset($_POST['role']) ? intval($_POST['role']) : $representative->role;
    
    // Permission fields
    $customer_edit = isset($_POST['customer_edit']) ? 1 : 0;
    $customer_delete = isset($_POST['customer_delete']) ? 1 : 0;
    $policy_edit = isset($_POST['policy_edit']) ? 1 : 0;
    $policy_delete = isset($_POST['policy_delete']) ? 1 : 0;
    
    // Personal information fields
    $birth_date = isset($_POST['birth_date']) ? sanitize_text_field($_POST['birth_date']) : '';
    $wedding_anniversary = isset($_POST['wedding_anniversary']) ? sanitize_text_field($_POST['wedding_anniversary']) : '';
    $children_birthdays = array();
    
    // Process children birthdays
    if (isset($_POST['children_birthdays']) && is_array($_POST['children_birthdays'])) {
        foreach ($_POST['children_birthdays'] as $child_data) {
            if (!empty($child_data['name']) && !empty($child_data['birth_date'])) {
                $children_birthdays[] = array(
                    'name' => sanitize_text_field($child_data['name']),
                    'birth_date' => sanitize_text_field($child_data['birth_date'])
                );
            }
        }
    }
    $children_birthdays_json = !empty($children_birthdays) ? json_encode($children_birthdays) : '';
    
    // Form doğrulaması
    if (empty($first_name)) {
        $errors[] = 'Ad alanı zorunludur.';
    }
    if (empty($last_name)) {
        $errors[] = 'Soyad alanı zorunludur.';
    }
    if (empty($email)) {
        $errors[] = 'E-posta alanı zorunludur.';
    } elseif (!is_email($email)) {
        $errors[] = 'Geçerli bir e-posta adresi girin.';
    }
    if (empty($title)) {
        $errors[] = 'Unvan alanı zorunludur.';
    }
    
    // Şifre kontrolü (doldurulduysa)
    if (!empty($password) && strlen($password) < 8) {
        $errors[] = 'Şifre en az 8 karakter olmalıdır.';
    }
    
    // Avatar Yükleme İşlemi
    $avatar_url = $representative->avatar_url; // Mevcut avatarı koru
    
    if (isset($_FILES['avatar_file']) && !empty($_FILES['avatar_file']['name'])) {
        $file = $_FILES['avatar_file'];
        $allowed_types = array('image/jpeg', 'image/jpg', 'image/png', 'image/gif');

        if (!in_array($file['type'], $allowed_types)) {
            $errors[] = 'Geçersiz dosya türü. Sadece JPG, JPEG, PNG ve GIF dosyalarına izin veriliyor.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Dosya boyutu 5MB\'dan büyük olamaz.';
        } else {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');

            $attachment_id = media_handle_upload('avatar_file', 0);

            if (is_wp_error($attachment_id)) {
                $errors[] = 'Dosya yüklenemedi: ' . $attachment_id->get_error_message();
            } else {
                $avatar_url = wp_get_attachment_url($attachment_id);
            }
        }
    }
    
    // Hata yoksa güncelleme yap
    if (empty($errors)) {
        // WordPress kullanıcı bilgilerini güncelle
        $user_data = array(
            'ID' => $representative->wp_user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => $first_name . ' ' . $last_name
        );
        
        // E-posta güncellemesi
        if ($email !== $representative->user_email) {
            $user_data['user_email'] = $email;
        }
        
        // Şifre güncellemesi
        if (!empty($password)) {
            $user_data['user_pass'] = $password;
        }
        
        $user_update_result = wp_update_user($user_data);
        
        if (is_wp_error($user_update_result)) {
            $errors[] = 'Kullanıcı bilgileri güncellenirken hata oluştu: ' . $user_update_result->get_error_message();
        } else {
            // Temsilci tablosundaki bilgileri güncelle
            $wpdb->update(
                $wpdb->prefix . 'insurance_crm_representatives',
                array(
                    'title' => $title,
                    'phone' => $phone,
                    'monthly_target' => $monthly_target,
                    'target_policy_count' => $target_policy_count,
                    'status' => $status,
                    'notes' => $notes,
                    'avatar_url' => $avatar_url,
                    'role' => $role,
                    'customer_edit' => $customer_edit,
                    'customer_delete' => $customer_delete,
                    'policy_edit' => $policy_edit,
                    'policy_delete' => $policy_delete,
                    'birth_date' => !empty($birth_date) ? $birth_date : null,
                    'wedding_anniversary' => !empty($wedding_anniversary) ? $wedding_anniversary : null,
                    'children_birthdays' => $children_birthdays_json,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $rep_id)
            );
            
            if ($wpdb->last_error) {
                $errors[] = 'Temsilci bilgileri güncellenirken bir hata oluştu: ' . $wpdb->last_error;
            } else {
                $success_message = 'Temsilci bilgileri başarıyla güncellendi.';
                
                // Tekrar en güncel verileri al
                $representative = $wpdb->get_row($wpdb->prepare(
                    "SELECT r.*, u.display_name, u.user_email, u.user_login, u.user_nicename, u.ID as wp_user_id
                     FROM {$wpdb->prefix}insurance_crm_representatives r
                     JOIN {$wpdb->users} u ON r.user_id = u.ID
                     WHERE r.id = %d",
                    $rep_id
                ));
            }
        }
    }
}

// Rolleri al
$roles = array(
    1 => 'Patron',
    2 => 'Müdür',
    3 => 'Müdür Yardımcısı',
    4 => 'Ekip Lideri',
    5 => 'Müşteri Temsilcisi'
);

// Ekip bilgilerini al
$settings = get_option('insurance_crm_settings', array());
$teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();

$rep_team = null;
foreach ($teams as $team_id => $team) {
    if ($team['leader_id'] == $rep_id || in_array($rep_id, $team['members'])) {
        $rep_team = $team;
        $rep_team['id'] = $team_id;
        break;
    }
}

// Ekip lideri mi kontrol et
$is_team_leader = false;
foreach ($teams as $team) {
    if ($team['leader_id'] == $rep_id) {
        $is_team_leader = true;
        break;
    }
}
?>

<div class="edit-representative-container">
    <div class="rep-header">
        <div class="rep-header-left">
            <div class="rep-avatar">
                <?php if (!empty($representative->avatar_url)): ?>
                    <img src="<?php echo esc_url($representative->avatar_url); ?>" alt="<?php echo esc_attr($representative->display_name); ?>">
                <?php else: ?>
                    <div class="default-avatar">
                        <?php echo substr($representative->display_name, 0, 1); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="rep-info">
                <h1><?php echo esc_html($representative->display_name); ?> <small>(Düzenleme)</small></h1>
                <div class="rep-meta">
                    <span class="rep-email"><i class="dashicons dashicons-email"></i> <?php echo esc_html($representative->user_email); ?></span>
                    <span class="rep-username"><i class="dashicons dashicons-admin-users"></i> <?php echo esc_html($representative->user_login); ?></span>
                    <?php if ($rep_team): ?>
                        <span class="rep-team"><i class="dashicons dashicons-groups"></i> <?php echo esc_html($rep_team['name']); ?> Ekibi</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="rep-header-right">
            <a href="<?php echo generate_panel_url('representative_detail', '', $rep_id); ?>" class="button">
                <i class="dashicons dashicons-visibility"></i> Temsilci Detayına Dön
            </a>
            <a href="<?php echo generate_panel_url('all_personnel'); ?>" class="button">
                <i class="dashicons dashicons-list-view"></i> Tüm Personel
            </a>
            <a href="<?php echo generate_panel_url('dashboard'); ?>" class="button">
                <i class="dashicons dashicons-dashboard"></i> Dashboard'a Dön
            </a>
        </div>
    </div>
    
    <?php if (!empty($success_message)): ?>
    <div class="notice notice-success is-dismissible">
        <p><?php echo esc_html($success_message); ?></p>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($errors)): ?>
    <div class="notice notice-error is-dismissible">
        <?php foreach($errors as $error): ?>
        <p><?php echo esc_html($error); ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <form method="post" action="" enctype="multipart/form-data" class="edit-representative-form">
        <?php wp_nonce_field('edit_representative_nonce', 'edit_representative_nonce'); ?>
        
        <div class="form-tabs">
            <a href="#basic" class="tab-link active" data-tab="basic">
                <i class="dashicons dashicons-admin-users"></i> Temel Bilgiler
            </a>
            <a href="#targets" class="tab-link" data-tab="targets">
                <i class="dashicons dashicons-chart-bar"></i> Hedefler
            </a>
            <a href="#role" class="tab-link" data-tab="role">
                <i class="dashicons dashicons-businessperson"></i> Rol ve Yetki
            </a>
            <a href="#security" class="tab-link" data-tab="security">
                <i class="dashicons dashicons-shield"></i> Güvenlik
            </a>
        </div>
        
        <div class="form-content">
            <div class="tab-content active" id="basic">
                <div class="form-section">
                    <h2>Temel Bilgiler</h2>
                    <p>Temsilcinin kişisel ve iletişim bilgilerini güncelleyin.</p>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="avatar_file">Profil Fotoğrafı</label>
                            <input type="file" name="avatar_file" id="avatar_file" accept="image/jpeg,image/png,image/gif">
                            <p class="form-tip">Maksimum dosya boyutu: 5MB. İzin verilen türler: JPG, PNG, GIF</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="first_name">Ad <span class="required">*</span></label>
                            <input type="text" name="first_name" id="first_name" value="<?php echo esc_attr(get_user_meta($representative->wp_user_id, 'first_name', true)); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="last_name">Soyad <span class="required">*</span></label>
                            <input type="text" name="last_name" id="last_name" value="<?php echo esc_attr(get_user_meta($representative->wp_user_id, 'last_name', true)); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">E-posta <span class="required">*</span></label>
                            <input type="email" name="email" id="email" value="<?php echo esc_attr($representative->user_email); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Telefon</label>
                            <input type="text" name="phone" id="phone" value="<?php echo esc_attr($representative->phone); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="title">Unvan <span class="required">*</span></label>
                            <input type="text" name="title" id="title" value="<?php echo esc_attr($representative->title); ?>" required>
                            <p class="form-tip">Örnek: Müşteri Temsilcisi, Uzman, Yönetici vs.</p>
                        </div>
                        
                        <!-- Personal Information Section -->
                        <div class="form-group col-span-2">
                            <h3 style="margin: 20px 0 15px 0; color: #2c3e50; border-bottom: 2px solid #e9ecef; padding-bottom: 10px;">
                                <i class="fas fa-heart" style="color: #e74c3c; margin-right: 8px;"></i>
                                Kişisel ve Aile Bilgileri
                            </h3>
                        </div>
                        
                        <div class="form-group">
                            <label for="birth_date">
                                <i class="fas fa-birthday-cake" style="color: #f39c12; margin-right: 5px;"></i>
                                Doğum Tarihi
                            </label>
                            <input type="date" name="birth_date" id="birth_date" value="<?php echo esc_attr($representative->birth_date ?? ''); ?>">
                            <p class="form-tip">Doğum günü kutlamaları için kullanılacaktır</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="wedding_anniversary">
                                <i class="fas fa-ring" style="color: #9b59b6; margin-right: 5px;"></i>
                                Evlilik Yıl Dönümü
                            </label>
                            <input type="date" name="wedding_anniversary" id="wedding_anniversary" value="<?php echo esc_attr($representative->wedding_anniversary ?? ''); ?>">
                            <p class="form-tip">Evlilik yıl dönümü kutlamaları için kullanılacaktır</p>
                        </div>
                        
                        <div class="form-group col-span-2">
                            <label>
                                <i class="fas fa-child" style="color: #3498db; margin-right: 5px;"></i>
                                Çocukların Doğum Günleri
                            </label>
                            <div id="children-birthdays-container">
                                <?php 
                                $children_birthdays = [];
                                if (!empty($representative->children_birthdays)) {
                                    $children_birthdays = json_decode($representative->children_birthdays, true) ?: [];
                                }
                                
                                if (empty($children_birthdays)) {
                                    $children_birthdays = [['name' => '', 'birth_date' => '']]; // At least one empty row
                                }
                                
                                foreach ($children_birthdays as $index => $child): ?>
                                <div class="child-birthday-row" data-index="<?php echo $index; ?>">
                                    <div class="child-birthday-inputs">
                                        <input type="text" name="children_birthdays[<?php echo $index; ?>][name]" 
                                               placeholder="Çocuğun adı" value="<?php echo esc_attr($child['name'] ?? ''); ?>" 
                                               class="child-name-input">
                                        <input type="date" name="children_birthdays[<?php echo $index; ?>][birth_date]" 
                                               value="<?php echo esc_attr($child['birth_date'] ?? ''); ?>" 
                                               class="child-date-input">
                                        <button type="button" class="remove-child-btn" onclick="removeChildBirthday(<?php echo $index; ?>)">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" id="add-child-birthday" class="btn btn-secondary" style="margin-top: 10px;">
                                <i class="fas fa-plus"></i> Çocuk Ekle
                            </button>
                            <p class="form-tip">Çocukların doğum günü kutlamaları için kullanılacaktır</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="status">Durum</label>
                            <select name="status" id="status">
                                <option value="active" <?php selected($representative->status, 'active'); ?>>Aktif</option>
                                <option value="passive" <?php selected($representative->status, 'passive'); ?>>Pasif</option>
                            </select>
                        </div>
                        
                        <div class="form-group col-span-2">
                            <label for="notes">Notlar</label>
                            <textarea name="notes" id="notes" rows="4"><?php echo esc_textarea($representative->notes); ?></textarea>
                            <p class="form-tip">Temsilci ile ilgili özel notlarınız (sadece yöneticiler görebilir)</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="tab-content" id="targets">
                <div class="form-section">
                    <h2>Hedef Bilgileri</h2>
                    <p>Temsilcinin aylık satış hedeflerini belirleyin.</p>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="monthly_target">Aylık Prim Hedefi (₺) <span class="required">*</span></label>
                            <input type="number" step="0.01" min="0" name="monthly_target" id="monthly_target" value="<?php echo esc_attr($representative->monthly_target); ?>" required>
                            <p class="form-tip">Temsilcinin aylık ulaşması gereken prim tutarını girin</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="target_policy_count">Aylık Poliçe Hedefi <span class="required">*</span></label>
                            <input type="number" step="1" min="0" name="target_policy_count" id="target_policy_count" value="<?php echo esc_attr($representative->target_policy_count); ?>" required>
                            <p class="form-tip">Temsilcinin aylık satması gereken poliçe sayısı</p>
                        </div>
                    </div>
                    
                    <div class="performance-summary">
                        <h3>Mevcut Performans Özeti</h3>
                        <?php
                        // Bu ay için üretilen prim ve poliçe sayısı
                        $this_month_start = date('Y-m-01 00:00:00');
                        $this_month_end = date('Y-m-t 23:59:59');
                        
                        $this_month_premium = $wpdb->get_var($wpdb->prepare(
                            "SELECT COALESCE(SUM(premium_amount), 0) FROM {$wpdb->prefix}insurance_crm_policies 
                             WHERE representative_id = %d 
                             AND start_date BETWEEN %s AND %s
                             AND cancellation_date IS NULL",
                            $rep_id, $this_month_start, $this_month_end
                        )) ?: 0;
                        
                        $this_month_policies = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
                             WHERE representative_id = %d 
                             AND start_date BETWEEN %s AND %s
                             AND cancellation_date IS NULL",
                            $rep_id, $this_month_start, $this_month_end
                        )) ?: 0;
                        
                        $premium_achievement = $representative->monthly_target > 0 ? ($this_month_premium / $representative->monthly_target) * 100 : 0;
                        $policy_achievement = $representative->target_policy_count > 0 ? ($this_month_policies / $representative->target_policy_count) * 100 : 0;
                        ?>
                        
                        <div class="performance-grid">
                            <div class="performance-item">
                                <div class="performance-label">Aylık Prim Hedefi:</div>
                                <div class="performance-value">₺<?php echo number_format($representative->monthly_target, 2, ',', '.'); ?></div>
                            </div>
                            <div class="performance-item">
                                <div class="performance-label">Bu Ay Üretilen:</div>
                                <div class="performance-value">₺<?php echo number_format($this_month_premium, 2, ',', '.'); ?></div>
                            </div>
                            <div class="performance-item">
                                <div class="performance-label">Gerçekleşme Oranı:</div>
                                <div class="performance-value"><?php echo number_format($premium_achievement, 2); ?>%</div>
                            </div>
                        </div>
                        
                        <div class="performance-grid">
                            <div class="performance-item">
                                <div class="performance-label">Aylık Poliçe Hedefi:</div>
                                <div class="performance-value"><?php echo $representative->target_policy_count; ?> adet</div>
                            </div>
                            <div class="performance-item">
                                <div class="performance-label">Bu Ay Satılan:</div>
                                <div class="performance-value"><?php echo $this_month_policies; ?> adet</div>
                            </div>
                            <div class="performance-item">
                                <div class="performance-label">Gerçekleşme Oranı:</div>
                                <div class="performance-value"><?php echo number_format($policy_achievement, 2); ?>%</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="tab-content" id="role">
                <div class="form-section">
                    <h2>Rol ve Yetki Bilgileri</h2>
                    <p>Temsilcinin sistem içindeki rolünü ve yetkilerini belirleyin.</p>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="role">Sistem Rolü <span class="required">*</span></label>
                            <select name="role" id="role" class="role-select">
                                <?php foreach ($roles as $role_id => $role_name): ?>
                                    <option value="<?php echo $role_id; ?>" <?php selected($representative->role, $role_id); ?> <?php echo ($role_id == 1 && $user_role !== 'patron') ? 'disabled' : ''; ?>>
                                        <?php echo esc_html($role_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-tip">Temsilcinin sistemdeki rolünü belirler</p>
                        </div>
                    </div>
                    
                    <div class="permissions-section">
                        <h3>Müşteri ve Poliçe İşlem Yetkileri</h3>
                        <p>Temsilcinin müşteri ve poliçe işlemleri için yetkilerini belirleyin.</p>
                        
                        <div class="modern-permissions-grid">
                            <div class="modern-permissions-row">
                                <label class="modern-checkbox-container">
                                    <input type="checkbox" name="customer_edit" value="1" <?php checked(isset($representative->customer_edit) ? $representative->customer_edit : 0, 1); ?>>
                                    <span class="modern-checkmark"></span>
                                    <div class="label-text">
                                        <span class="label-title"><i class="fas fa-user-edit"></i> Müşteri Düzenleme</span>
                                        <span class="label-desc">Müşteri bilgilerini düzenleyebilir</span>
                                    </div>
                                </label>
                            </div>
                            
                            <div class="modern-permissions-row">
                                <label class="modern-checkbox-container">
                                    <input type="checkbox" name="customer_delete" value="1" <?php checked(isset($representative->customer_delete) ? $representative->customer_delete : 0, 1); ?>>
                                    <span class="modern-checkmark"></span>
                                    <div class="label-text">
                                        <span class="label-title"><i class="fas fa-user-times"></i> Müşteri Silme</span>
                                        <span class="label-desc">Müşteri kaydını pasife alabilir/silebilir</span>
                                    </div>
                                </label>
                            </div>
                            
                            <div class="modern-permissions-row">
                                <label class="modern-checkbox-container">
                                    <input type="checkbox" name="policy_edit" value="1" <?php checked(isset($representative->policy_edit) ? $representative->policy_edit : 0, 1); ?>>
                                    <span class="modern-checkmark"></span>
                                    <div class="label-text">
                                        <span class="label-title"><i class="fas fa-file-contract"></i> Poliçe Düzenleme</span>
                                        <span class="label-desc">Poliçe bilgilerini düzenleyebilir</span>
                                    </div>
                                </label>
                            </div>
                            
                            <div class="modern-permissions-row">
                                <label class="modern-checkbox-container">
                                    <input type="checkbox" name="policy_delete" value="1" <?php checked(isset($representative->policy_delete) ? $representative->policy_delete : 0, 1); ?>>
                                    <span class="modern-checkmark"></span>
                                    <div class="label-text">
                                        <span class="label-title"><i class="fas fa-file-times"></i> Poliçe Silme</span>
                                        <span class="label-desc">Poliçe kaydını pasife alabilir/silebilir</span>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="team-info">
                        <h3>Ekip Bilgileri</h3>
                        <?php if ($rep_team): ?>
                            <div class="team-detail">
                                <p><strong>Ekip:</strong> <?php echo esc_html($rep_team['name']); ?></p>
                                <p><strong>Rol:</strong> <?php echo $rep_team['leader_id'] == $rep_id ? 'Ekip Lideri' : 'Ekip Üyesi'; ?></p>
                                <p>Ekip bilgilerini güncellemek için <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=teams&action=edit_team&team_id=' . $rep_team['id']); ?>" target="_blank">Ekip Yönetimi</a> sayfasını kullanın.</p>
                            </div>
                        <?php else: ?>
                            <div class="empty-team">
                                <p>Bu temsilci henüz bir ekibe atanmamış.</p>
                                <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=teams'); ?>" target="_blank" class="button button-secondary">Ekip Yönetimine Git</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="tab-content" id="security">
                <div class="form-section">
                    <h2>Güvenlik Ayarları</h2>
                    <p>Temsilcinin giriş bilgilerini ve güvenlik ayarlarını değiştirin.</p>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="username">Kullanıcı Adı</label>
                            <input type="text" name="username" id="username" value="<?php echo esc_attr($representative->user_login); ?>" readonly>
                            <p class="form-tip">Kullanıcı adı değiştirilemez</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="password">Şifre</label>
                            <input type="password" name="password" id="password" placeholder="Yeni şifre belirlemek için doldurun">
                            <p class="form-tip">Şifreyi değiştirmek istemiyorsanız boş bırakın</p>
                        </div>
                    </div>
                    
                    <div class="last-login-info">
                        <h3>Son Aktivite Bilgileri</h3>
                        <div class="activity-grid">
                            <?php
                            // Get various activity timestamps with Turkey time (+3 hours)
                            $last_login = get_user_meta($representative->wp_user_id, 'last_login', true);
                            $last_activity = get_user_meta($representative->wp_user_id, '_user_last_activity', true);
                            
                            // Calculate Turkey time (+3 hours)
                            $turkey_time_offset = 3 * 3600; // 3 hours in seconds
                            
                            // Format times with Turkey timezone
                            $last_login_time = $last_login ? date_i18n('d.m.Y H:i:s', intval($last_login) + $turkey_time_offset) : 'Henüz giriş yapmamış';
                            $last_activity_time = $last_activity ? date_i18n('d.m.Y H:i:s', intval($last_activity) + $turkey_time_offset) : 'Bilinmiyor';
                            
                            // Get last operation time from user logs (if exists)
                            $last_operation = $wpdb->get_var($wpdb->prepare(
                                "SELECT MAX(created_at) FROM {$wpdb->prefix}insurance_user_logs WHERE user_id = %d",
                                $representative->wp_user_id
                            ));
                            $last_operation_time = $last_operation ? date_i18n('d.m.Y H:i:s', strtotime($last_operation) + $turkey_time_offset) : 'Hiç işlem yapmamış';
                            
                            // Calculate online status (active within last 15 minutes)
                            $is_online = $last_activity && (time() - intval($last_activity)) < 900; // 15 minutes
                            $online_status = $is_online ? '<span class="online-status online">Aktif</span>' : '<span class="online-status offline">Çevrimdışı</span>';
                            ?>
                            
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-sign-in-alt"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-label">Son Giriş</div>
                                    <div class="activity-value"><?php echo $last_login_time; ?></div>
                                </div>
                            </div>
                            
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-mouse-pointer"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-label">Son İşlem</div>
                                    <div class="activity-value"><?php echo $last_operation_time; ?></div>
                                </div>
                            </div>
                            
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-label">Son Online Görülme</div>
                                    <div class="activity-value"><?php echo $last_activity_time; ?></div>
                                </div>
                            </div>
                            
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-circle"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-label">Durum</div>
                                    <div class="activity-value"><?php echo $online_status; ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="time-note">
                            <i class="fas fa-info-circle"></i>
                            <small>Tüm saatler Türkiye saati (UTC+3) olarak gösterilmektedir.</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="submit" name="edit_representative_submit" class="button button-primary">
                <i class="dashicons dashicons-saved"></i> Değişiklikleri Kaydet
            </button>
        </div>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Sekme değiştirme
    $('.tab-link').on('click', function(e) {
        e.preventDefault();
        
        // Aktif sekme linkini değiştir
        $('.tab-link').removeClass('active');
        $(this).addClass('active');
        
        // İçeriği değiştir
        var tabId = $(this).data('tab');
        $('.tab-content').removeClass('active');
        $('#' + tabId).addClass('active');
    });
    
    // Avatar önizleme
    $('#avatar_file').on('change', function(e) {
        if (this.files && this.files[0]) {
            var file = this.files[0];
            
            // Dosya türü ve boyut kontrolü
            var allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            var maxSize = 5 * 1024 * 1024; // 5MB
            
            if (!allowedTypes.includes(file.type)) {
                alert('Geçersiz dosya türü! Sadece JPG, JPEG, PNG ve GIF formatları kabul edilir.');
                $(this).val('');
                return;
            }
            
            if (file.size > maxSize) {
                alert('Dosya boyutu çok büyük! Maksimum 5MB yükleyebilirsiniz.');
                $(this).val('');
                return;
            }
            
            // Dosya uygunsa önizlemeyi göster
            var reader = new FileReader();
            reader.onload = function(e) {
                $('.rep-avatar img').attr('src', e.target.result);
                if ($('.rep-avatar .default-avatar').length) {
                    $('.rep-avatar .default-avatar').hide();
                    $('.rep-avatar').append('<img src="' + e.target.result + '" alt="Profil Fotoğrafı">');
                }
            };
            reader.readAsDataURL(file);
        }
    });
    
    // Rol değiştirme uyarısı
    var originalRole = $('.role-select').val();
    $('.role-select').on('change', function() {
        var newRole = $(this).val();
        if (originalRole != newRole) {
            if (confirm('Dikkat: Rol değiştirmek, temsilcinin sistem içindeki yetkilerini değiştirecektir. Devam etmek istiyor musunuz?')) {
                // Devam et
            } else {
                $(this).val(originalRole);
            }
        }
    });
    
    // Children birthdays management
    let childIndex = <?php echo count($children_birthdays); ?>;
    
    $('#add-child-birthday').on('click', function() {
        addChildBirthday();
    });
    
    function addChildBirthday() {
        const container = $('#children-birthdays-container');
        const newRow = `
            <div class="child-birthday-row" data-index="${childIndex}">
                <div class="child-birthday-inputs">
                    <input type="text" name="children_birthdays[${childIndex}][name]" 
                           placeholder="Çocuğun adı" class="child-name-input">
                    <input type="date" name="children_birthdays[${childIndex}][birth_date]" 
                           class="child-date-input">
                    <button type="button" class="remove-child-btn" onclick="removeChildBirthday(${childIndex})">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        `;
        container.append(newRow);
        childIndex++;
    }
});

function removeChildBirthday(index) {
    const row = jQuery(`.child-birthday-row[data-index="${index}"]`);
    row.fadeOut(300, function() {
        jQuery(this).remove();
    });
}
</script>

<style>
.edit-representative-container {
    padding: 20px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
}

.rep-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    margin-bottom: 20px;
}

.rep-header-left {
    display: flex;
    align-items: center;
}

.rep-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    overflow: hidden;
    margin-right: 20px;
    background: #f0f0f0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    color: #666;
}

.rep-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.default-avatar {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
}

.rep-info h1 {
    margin: 0 0 10px;
    font-size: 24px;
    color: #333;
}

.rep-info h1 small {
    font-size: 16px;
    color: #666;
    font-weight: normal;
}

.rep-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    color: #666;
    font-size: 14px;
}

.rep-meta span {
    display: flex;
    align-items: center;
}

.rep-meta .dashicons {
    margin-right: 5px;
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.rep-header-right {
    display: flex;
    gap: 10px;
}

.notice {
    padding: 12px 15px;
    margin: 15px 0;
    border-left: 4px solid;
    border-radius: 3px;
    background: #fff;
}

.notice-success {
    border-color: #46b450;
}

.notice-error {
    border-color: #dc3232;
}

.edit-representative-form {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    overflow: hidden;
}

.form-tabs {
    display: flex;
    border-bottom: 1px solid #eee;
    background: #f8f9fa;
    overflow-x: auto;
}

.form-tabs .tab-link {
    padding: 15px 20px;
    color: #555;
    text-decoration: none;
    border-bottom: 2px solid transparent;
    font-weight: 500;
    display: flex;
    align-items: center;
    transition: all 0.2s;
    white-space: nowrap;
}

.form-tabs .tab-link .dashicons {
    margin-right: 8px;
}

.form-tabs .tab-link:hover {
    background: #f1f1f1;
    color: #333;
}

.form-tabs .tab-link.active {
    color: #0073aa;
    border-bottom-color: #0073aa;
    background: #fff;
}

.form-content {
    padding: 20px;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.form-section {
    margin-bottom: 30px;
}

.form-section h2 {
    font-size: 18px;
    font-weight: 600;
    margin: 0 0 10px;
    color: #333;
}

.form-section p {
    color: #666;
    margin: 0 0 20px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group.col-span-2 {
    grid-column: span 2;
}

.form-group label {
    display: block;
    font-weight: 500;
    margin-bottom: 5px;
    color: #333;
}

.form-group input[type="text"],
.form-group input[type="email"],
.form-group input[type="number"],
.form-group input[type="password"],
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.form-group input[type="file"] {
    padding: 10px 0;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: #0073aa;
    box-shadow: 0 0 0 1px #0073aa;
    outline: none;
}

.form-group input:read-only {
    background: #f9f9f9;
    cursor: not-allowed;
}

.form-tip {
    color: #666;
    font-size: 12px;
    margin: 5px 0 0;
}

.required {
    color: #dc3232;
}

.performance-summary {
    background: #f9f9f9;
    border-radius: 6px;
    padding: 20px;
    margin-top: 20px;
}

.performance-summary h3 {
    font-size: 16px;
    font-weight: 600;
    margin: 0 0 15px;
    color: #333;
}

.performance-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
    margin-bottom: 20px;
}

.performance-item {
    background: #fff;
    padding: 15px;
    border-radius: 4px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}

.performance-label {
    font-size: 13px;
    color: #666;
    margin-bottom: 5px;
}

.performance-value {
    font-size: 16px;
    font-weight: 600;
    color: #333;
}

.team-info {
    background: #f9f9f9;
    border-radius: 6px;
    padding: 20px;
    margin-top: 20px;
}

.team-info h3 {
    font-size: 16px;
    font-weight: 600;
    margin: 0 0 15px;
    color: #333;
}

.team-detail p {
    margin: 10px 0;
}

.empty-team {
    text-align: center;
    padding: 20px 0;
}

.last-login-info {
    background: #f9f9f9;
    border-radius: 6px;
    padding: 20px;
    margin-top: 20px;
}

.last-login-info h3 {
    font-size: 16px;
    font-weight: 600;
    margin: 0 0 15px;
    color: #333;
}

.form-actions {
    padding: 20px;
    border-top: 1px solid #eee;
    text-align: right;
}

.button {
    display: inline-flex;
    align-items: center;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    text-decoration: none;
    transition: all 0.2s;
}

.button .dashicons {
    margin-right: 8px;
}

.button-primary {
    background: #0073aa;
    color: #fff;
    border: 1px solid #0073aa;
}

.button-primary:hover {
    background: #005d8c;
}

.button-secondary {
    background: #f8f9fa;
    color: #555;
    border: 1px solid #ddd;
}

.button-secondary:hover {
    background: #f1f1f1;
    border-color: #ccc;
    color: #333;
}

@media screen and (max-width: 992px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .form-group.col-span-2 {
        grid-column: auto;
    }
    
    .performance-grid {
        grid-template-columns: 1fr;
    }
}

@media screen and (max-width: 768px) {
    .rep-header {
        flex-direction: column;
    }
    
    .rep-header-right {
        margin-top: 15px;
        width: 100%;
    }
    
    .form-tabs {
        flex-wrap: wrap;
    }
}

/* Personal Information Styles */
.child-birthday-row {
    margin-bottom: 10px;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #e9ecef;
}

.child-birthday-inputs {
    display: flex;
    gap: 10px;
    align-items: center;
}

.child-name-input,
.child-date-input {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.child-name-input {
    min-width: 150px;
}

.remove-child-btn {
    background: #dc3545;
    color: white;
    border: none;
    border-radius: 50%;
    width: 30px;
    height: 30px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    flex-shrink: 0;
}

.remove-child-btn:hover {
    background: #c82333;
    transform: scale(1.1);
}

.form-group h3 {
    display: flex;
    align-items: center;
    font-size: 16px;
    font-weight: 600;
}

#add-child-birthday {
    background: linear-gradient(135deg, #28a745, #20c997);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 25px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

#add-child-birthday:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
}

/* Modern Permissions Grid Styles */
.permissions-section {
    margin-top: 30px;
    padding: 20px;
    background: #f8fafc;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

.permissions-section h3 {
    margin: 0 0 8px 0;
    font-size: 16px;
    color: #1e293b;
    font-weight: 600;
}

.permissions-section p {
    margin: 0 0 20px 0;
    color: #64748b;
    font-size: 14px;
}

.modern-permissions-grid {
    display: grid;
    gap: 12px;
}

.modern-permissions-row {
    background: #ffffff;
    border-radius: 8px;
    padding: 12px 16px;
    border: 1px solid #e2e8f0;
    transition: all 0.2s ease;
}

.modern-permissions-row:hover {
    border-color: #cbd5e1;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.modern-checkbox-container {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    cursor: pointer;
    margin: 0;
    padding: 0;
}

.modern-checkbox-container input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: #667eea;
    cursor: pointer;
    margin: 0;
}

.modern-checkmark {
    display: none; /* Using browser default checkbox styling */
}

.label-text {
    display: flex;
    flex-direction: column;
    flex: 1;
}

.label-title {
    font-weight: 600;
    color: #1e293b;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.label-title i {
    color: #667eea;
    width: 16px;
}

.label-desc {
    font-size: 12px;
    color: #64748b;
    margin-top: 2px;
    line-height: 1.4;
}

/* Activity Grid Styles */
.activity-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}

.activity-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: #f8fafc;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

.activity-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 16px;
}

.activity-content {
    flex: 1;
}

.activity-label {
    font-size: 12px;
    color: #64748b;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}

.activity-value {
    font-size: 14px;
    color: #1e293b;
    font-weight: 600;
}

.online-status {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.online-status.online {
    background: #dcfce7;
    color: #166534;
}

.online-status.offline {
    background: #fef2f2;
    color: #991b1b;
}

.time-note {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
    background: #fef3c7;
    border: 1px solid #fbbf24;
    border-radius: 6px;
    color: #92400e;
    font-size: 13px;
}

.time-note i {
    color: #f59e0b;
}

@media screen and (max-width: 768px) {
    .child-birthday-inputs {
        flex-direction: column;
        align-items: stretch;
    }
    
    .child-name-input,
    .child-date-input {
        width: 100%;
        min-width: auto;
    }
    
    .remove-child-btn {
        align-self: flex-end;
        margin-top: 10px;
    }
    
    .activity-grid {
        grid-template-columns: 1fr;
    }
    
    .modern-permissions-grid {
        gap: 8px;
    }
    
    .modern-permissions-row {
        padding: 10px 12px;
    }
}
</style>
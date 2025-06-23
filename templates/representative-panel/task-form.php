<?php
/**
 * Görev Ekleme/Düzenleme Formu
 * @version 3.2.0
 * @date 2025-05-30 21:39:10
 * @author anadolubirlik
 * @description Modern UI güncellemesi - Form iyileştirmeleri
 */

// Veritabanı kontrolü ve task_title alanı ekleme
function insurance_crm_check_tasks_db_structure() {
    try {
        global $wpdb;
        $table_name = $wpdb->prefix . 'insurance_crm_tasks';
        
        // Tablonun varlığını kontrol et
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        
        if ($table_exists) {
            // task_title sütununun varlığını kontrol et
            $column_exists = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s 
                AND TABLE_NAME = %s
                AND COLUMN_NAME = 'task_title'",
                DB_NAME, 
                $table_name
            ));
            
            // Eğer task_title alanı yoksa ekle
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN task_title VARCHAR(255) AFTER id");
            }
            
            // task_type sütununun varlığını kontrol et
            $task_type_exists = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s 
                AND TABLE_NAME = %s
                AND COLUMN_NAME = 'task_type'",
                DB_NAME, 
                $table_name
            ));
            
            // Eğer task_type alanı yoksa ekle
            if (empty($task_type_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN task_type VARCHAR(50) DEFAULT NULL AFTER status");
            }
        }
    } catch (Exception $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('DB Structure Check Error: ' . $e->getMessage());
        }
    }
}

// Poliçe tablosuna insurance_company sütunu ekleme
function insurance_crm_check_policies_db_structure() {
    try {
        global $wpdb;
        $table_name = $wpdb->prefix . 'insurance_crm_policies';
        
        // Tablonun varlığını kontrol et
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        
        if ($table_exists) {
            // insurance_company sütununun varlığını kontrol et
            $column_exists = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s 
                AND TABLE_NAME = %s
                AND COLUMN_NAME = 'insurance_company'",
                DB_NAME, 
                $table_name
            ));
            
            // Eğer insurance_company alanı yoksa ekle
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN insurance_company VARCHAR(100) NULL AFTER policy_type");
            }
        }
    } catch (Exception $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Policies DB Structure Check Error: ' . $e->getMessage());
        }
    }
}

// Veritabanı yapılarını kontrol et
insurance_crm_check_tasks_db_structure();
insurance_crm_check_policies_db_structure();

// Yetki kontrolü
if (!is_user_logged_in()) {
    return;
}

$editing = isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']) && intval($_GET['id']) > 0;
$task_id = $editing ? intval($_GET['id']) : 0;

// Müşteri ID'si veya Poliçe ID'si varsa form açılışında seçili gelsin
$selected_customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$selected_policy_id = isset($_GET['policy_id']) ? intval($_GET['policy_id']) : 0;

// Yenileme görevi ise parametreleri al
$task_type = isset($_GET['task_type']) ? sanitize_text_field($_GET['task_type']) : '';

// Mevcut kullanıcı bilgilerini ve rolünü al
$current_user_id = get_current_user_id();
$current_user_rep_id = function_exists('get_current_user_rep_id') ? get_current_user_rep_id() : 0;

// Kullanıcının rolünü belirle (patron, müdür, ekip lideri)
$is_patron = function_exists('is_patron') ? is_patron($current_user_id) : false;
$is_manager = function_exists('is_manager') ? is_manager($current_user_id) : false;
$is_team_leader = function_exists('is_team_leader') ? is_team_leader($current_user_id) : false;

// Form gönderildiğinde işlem yap
if (isset($_POST['save_task']) && isset($_POST['task_nonce']) && wp_verify_nonce($_POST['task_nonce'], 'save_task')) {
    
    // Görevi düzenleyecek kişinin yetkisi var mı?
    $can_edit = true;
    
    // Görev verileri
    $task_data = array(
        'task_title' => sanitize_text_field($_POST['task_title']),
        'customer_id' => intval($_POST['customer_id']),
        'policy_id' => !empty($_POST['policy_id']) ? intval($_POST['policy_id']) : null,
        'task_description' => sanitize_textarea_field($_POST['task_description']),
        'due_date' => sanitize_text_field($_POST['due_date']),
        'priority' => sanitize_text_field($_POST['priority']),
        'status' => sanitize_text_field($_POST['status']),
        'representative_id' => !empty($_POST['representative_id']) ? intval($_POST['representative_id']) : null
    );
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_tasks';
    
    // Temsilci kontrolü - temsilciyse ve temsilci seçilmediyse kendi ID'sini ekle
    if (!$is_patron && !$is_manager && !$is_team_leader && empty($task_data['representative_id']) && $current_user_rep_id) {
        $task_data['representative_id'] = $current_user_rep_id;
    }
    
    if ($editing) {
        // Yetki kontrolü
        $is_admin = current_user_can('administrator') || current_user_can('insurance_manager');
        
        if (!$is_admin && !$is_patron && !$is_manager) {
            $task_check = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE id = %d", $task_id
            ));
            
            if ($task_check && $task_check->representative_id != $current_user_rep_id && 
                (!$is_team_leader || !in_array($task_check->representative_id, get_team_members($current_user_id)))) {
                $can_edit = false;
                $message = 'Bu görevi düzenleme yetkiniz yok.';
                $message_type = 'error';
            }
        }
        
        if ($can_edit) {
            $task_data['updated_at'] = current_time('mysql');
            $result = $wpdb->update($table_name, $task_data, ['id' => $task_id]);
            
            if ($result !== false) {
                $message = 'Görev başarıyla güncellendi.';
                $message_type = 'success';
                
                // Başarılı işlemden sonra yönlendirme
                $_SESSION['crm_notice'] = '<div class="notification-banner notification-success">
                    <div class="notification-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="notification-content">
                        ' . $message . '
                    </div>
                    <button class="notification-close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>';
                echo '<script>window.location.href = "?view=tasks&updated=true";</script>';
                exit;
            } else {
                $message = 'Görev güncellenirken bir hata oluştu.';
                $message_type = 'error';
            }
        }
    } else {
        // Yeni görev ekle
        $task_data['created_at'] = current_time('mysql');
        $task_data['updated_at'] = current_time('mysql');
        
        $result = $wpdb->insert($table_name, $task_data);
        
        if ($result !== false) {
            $new_task_id = $wpdb->insert_id;
            $message = 'Görev başarıyla eklendi.';
            $message_type = 'success';
            
            // Başarılı işlemden sonra yönlendirme
            $_SESSION['crm_notice'] = '<div class="notification-banner notification-success">
                <div class="notification-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="notification-content">
                    ' . $message . '
                </div>
                <button class="notification-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>';
            echo '<script>window.location.href = "?view=tasks&added=true";</script>';
            exit;
        } else {
            $message = 'Görev eklenirken bir hata oluştu.';
            $message_type = 'error';
        }
    }
}

// Görevi düzenlenecek verilerini al
$task = null;
if ($editing) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_tasks';
    
    $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $task_id));
    
    if (!$task) {
        echo '<div class="notification-banner notification-error">
            <div class="notification-icon">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <div class="notification-content">
                Görev bulunamadı.
            </div>
            <button class="notification-close">
                <i class="fas fa-times"></i>
            </button>
        </div>';
        return;
    }
    
    // Yetki kontrolü (temsilci sadece kendi görevlerini düzenleyebilir)
    if (!current_user_can('administrator') && !current_user_can('insurance_manager') && !$is_patron && !$is_manager) {
        if ($task->representative_id != $current_user_rep_id && 
            (!$is_team_leader || !in_array($task->representative_id, get_team_members($current_user_id)))) {
            echo '<div class="notification-banner notification-error">
                <div class="notification-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <div class="notification-content">
                    Bu görevi düzenleme yetkiniz yok.
                </div>
                <button class="notification-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>';
            return;
        }
    }
    
    // Eğer düzenleme modundaysa müşteri ID'sini alalım
    $selected_customer_id = $task->customer_id;
    $selected_policy_id = $task->policy_id;
}

// Görev türüne göre varsayılan değerleri ayarla
$default_task_title = '';
$default_task_description = '';

// Son tarih varsayılan olarak 3 gün sonrası
$default_due_date = date('Y-m-d\TH:i', strtotime('+3 days'));
$default_priority = 'medium';

// Eğer poliçe yenileme görevi ise
if ($task_type === 'renewal' && !empty($selected_policy_id)) {
    global $wpdb;
    $policies_table = $wpdb->prefix . 'insurance_crm_policies';
    
    $policy = $wpdb->get_row($wpdb->prepare("
        SELECT p.*, c.first_name, c.last_name 
        FROM $policies_table p
        LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
        WHERE p.id = %d
    ", $selected_policy_id));
    
    if ($policy) {
        $selected_customer_id = $policy->customer_id;
        $default_task_title = "Poliçe Yenileme: {$policy->policy_number}";
        $default_task_description = "Poliçe yenileme hatırlatması: {$policy->policy_number}\n\nMüşteri: {$policy->first_name} {$policy->last_name}\nPoliçe No: {$policy->policy_number}\nPoliçe Türü: {$policy->policy_type}\nBitiş Tarihi: " . date('d.m.Y', strtotime($policy->end_date));
        
        // Son tarih, poliçe bitiş tarihinden 1 hafta önce olsun
        $due_date = new DateTime($policy->end_date);
        $due_date->modify('-1 week');
        $default_due_date = $due_date->format('Y-m-d\TH:i');
        
        // Öncelik "yüksek" olsun
        $default_priority = 'high';
    }
}

// Seçili müşteri bilgilerini getir
$selected_customer_name = '';
if ($selected_customer_id > 0) {
    global $wpdb;
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    
    // tc_kimlik_no sütunu var mı kontrol et
    $has_tc_column = $wpdb->get_results($wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'tc_kimlik_no'",
        DB_NAME, $customers_table
    ));
    
    $customer = $wpdb->get_row($wpdb->prepare("
        SELECT first_name, last_name, phone" . ($has_tc_column ? ", tc_kimlik_no" : "") . "
        FROM $customers_table 
        WHERE id = %d
    ", $selected_customer_id));
    
    if ($customer) {
        $selected_customer_name = $customer->first_name . ' ' . $customer->last_name . 
                                (!empty($customer->phone) ? ' (' . $customer->phone . ')' : '') .
                                (!empty($customer->tc_kimlik_no) ? ' [TC: ' . $customer->tc_kimlik_no . ']' : '');
    }
}

// Tüm müşterileri al (dropdown ve filtreleme için)
$all_customers = [];
$all_customers_data = [];
$customers_table = $wpdb->prefix . 'insurance_crm_customers';

// tc_kimlik_no ve status sütunlarını kontrol et
$has_tc_column = $wpdb->get_results($wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'tc_kimlik_no'",
    DB_NAME, $customers_table
));
$has_status_column = $wpdb->get_results($wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'status'",
    DB_NAME, $customers_table
));

$status_condition = $has_status_column ? " WHERE status = 'aktif'" : "";
$customers = $wpdb->get_results(
    "SELECT id, first_name, last_name, phone" . ($has_tc_column ? ", tc_kimlik_no" : "") . " 
     FROM $customers_table" . $status_condition . "
     ORDER BY first_name, last_name
     LIMIT 100"
);

// Eğer düzenleme modundaysa ve seçili müşteri listede yoksa, onu da ekle
if ($editing && $selected_customer_id > 0 && !empty($customers)) {
    $customer_exists = false;
    foreach ($customers as $customer) {
        if ($customer->id == $selected_customer_id) {
            $customer_exists = true;
            break;
        }
    }
    
    if (!$customer_exists) {
        $selected_customer = $wpdb->get_row($wpdb->prepare("
            SELECT id, first_name, last_name, phone" . ($has_tc_column ? ", tc_kimlik_no" : "") . " 
            FROM $customers_table 
            WHERE id = %d
        ", $selected_customer_id));
        
        if ($selected_customer) {
            // Seçili müşteriyi listenin başına ekle
            array_unshift($customers, $selected_customer);
        }
    }
}

if ($customers) {
    foreach ($customers as $customer) {
        $customer_name = $customer->first_name . ' ' . $customer->last_name;
        $customer_phone = !empty($customer->phone) ? ' (' . $customer->phone . ')' : '';
        $customer_tc = !empty($customer->tc_kimlik_no) ? ' [TC: ' . $customer->tc_kimlik_no . ']' : '';
        $display_name = $customer_name . $customer_phone . $customer_tc;
        
        $all_customers[$customer->id] = $display_name;
        $all_customers_data[$customer->id] = [
            'id' => $customer->id,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'phone' => $customer->phone,
            'tc_kimlik_no' => !empty($customer->tc_kimlik_no) ? $customer->tc_kimlik_no : ''
        ];
    }
}

// Hata ayıklama için müşteri listesini log'la
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('Dropdown müşteri sayısı: ' . count($all_customers));
    error_log('Son SQL sorgusu (dropdown): ' . $wpdb->last_query);
    if ($wpdb->last_error) {
        error_log('SQL Hatası (dropdown): ' . $wpdb->last_error);
    }
}

// Tüm poliçeleri al (önyüze gömmek için)
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$all_policies = [];
if (!empty($all_customers)) {
    $customer_ids = array_keys($all_customers);
    $placeholder = implode(',', array_fill(0, count($customer_ids), '%d'));
    
    $policies = $wpdb->get_results($wpdb->prepare(
        "SELECT id, customer_id, policy_number, COALESCE(policy_type, '') AS policy_type, 
                COALESCE(insurance_company, '') AS insurance_company, start_date, end_date
         FROM $policies_table 
         WHERE customer_id IN ($placeholder) AND status != 'iptal'
         ORDER BY id DESC",
        $customer_ids
    ));
    
    // Poliçeleri müşteri ID'sine göre gruplandır
    foreach ($policies as $policy) {
        $all_policies[$policy->customer_id][] = [
            'id' => $policy->id,
            'policy_number' => $policy->policy_number,
            'policy_type' => $policy->policy_type,
            'insurance_company' => $policy->insurance_company,
            'start_date' => date('d.m.Y', strtotime($policy->start_date)),
            'end_date' => date('d.m.Y', strtotime($policy->end_date))
        ];
    }
    
    // Hata ayıklama için poliçe log'ları
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Toplam poliçe sayısı: ' . count($policies));
        error_log('Son poliçe SQL sorgusu: ' . $wpdb->last_query);
        if ($wpdb->last_error) {
            error_log('Poliçe SQL Hatası: ' . $wpdb->last_error);
        }
    }
}

// Temsilcileri rolüne göre filtrele
$representatives = [];
$reps_table = $wpdb->prefix . 'insurance_crm_representatives';

if ($is_patron || $is_manager || current_user_can('administrator')) {
    // Patron ve müdürler tüm temsilcileri görebilir
    $representatives = $wpdb->get_results("
        SELECT r.id, u.display_name 
        FROM $reps_table r
        LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
        WHERE r.status = 'active'
        ORDER BY u.display_name ASC
    ");
} elseif ($is_team_leader) {
    // Ekip lideri sadece kendi ekibindeki üyeleri görebilir
    $team_members = get_team_members($current_user_id);
    
    if (!empty($team_members) && is_array($team_members)) {
        // Ekip üyesi temsilcileri al
        $placeholder = implode(',', array_fill(0, count($team_members), '%d'));
        
        // PHP versiyon uyumluluğu
        $query = $wpdb->prepare(
            "SELECT r.id, u.display_name 
            FROM $reps_table r
            LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
            WHERE r.status = 'active' AND r.id IN ($placeholder)
            ORDER BY u.display_name ASC",
            $team_members
        );
        
        $representatives = $wpdb->get_results($query);
        
        // Ekip liderini de ekle
        if ($current_user_rep_id) {
            $leader = $wpdb->get_row($wpdb->prepare(
                "SELECT r.id, u.display_name 
                FROM $reps_table r
                LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
                WHERE r.status = 'active' AND r.id = %d",
                $current_user_rep_id
            ));
            
            if ($leader) {
                array_unshift($representatives, $leader);
            }
        }
    }
} else {
    // Normal temsilciler sadece kendilerini görebilir
    if ($current_user_rep_id) {
        $representatives = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, u.display_name 
            FROM $reps_table r
            LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
            WHERE r.status = 'active' AND r.id = %d
            ORDER BY u.display_name ASC",
            $current_user_rep_id
        ));
    }
}

// Varsayılan görevli temsilci belirle
$default_representative_id = 0;

// Eğer düzenlemede mevcut temsilci varsa onu kullan
if ($editing && !empty($task->representative_id)) {
    $default_representative_id = $task->representative_id;
}
// Yeni görev ise ve normal temsilci ise kendisini otomatik seç
elseif (!$editing && !$is_patron && !$is_manager && !$is_team_leader && $current_user_rep_id) {
    $default_representative_id = $current_user_rep_id;
}

// Öncelik renkleri
$priority_colors = [
    'low' => ['bg' => 'rgba(46, 125, 50, 0.1)', 'text' => '#22863a', 'border' => '#c8e1cb', 'shadow' => 'rgba(46, 125, 50, 0.2)'],
    'medium' => ['bg' => 'rgba(245, 124, 0, 0.1)', 'text' => '#bf8700', 'border' => '#f4d8a0', 'shadow' => 'rgba(245, 124, 0, 0.2)'],
    'high' => ['bg' => 'rgba(211, 47, 47, 0.1)', 'text' => '#cb2431', 'border' => '#f4b7bc', 'shadow' => 'rgba(211, 47, 47, 0.2)'],
    'urgent' => ['bg' => 'rgba(211, 47, 47, 0.15)', 'text' => '#b71c1c', 'border' => '#f4b7bc', 'shadow' => 'rgba(211, 47, 47, 0.3)']
];

// Aktif öncelik rengini seç
$current_priority = $editing && isset($task->priority) ? $task->priority : $default_priority;
$active_priority = $priority_colors[$current_priority] ?? $priority_colors['medium'];

// Form verilerini hazırla
$form_title = $editing ? 'Görev Düzenle' : 'Yeni Görev Ekle';
$form_icon = $editing ? 'edit' : 'plus-circle';
$form_action = $editing ? 'Güncelle' : 'Kaydet';
?>

<div class="task-form-container">
    <div class="task-form-header">
        <div class="header-content">
            <div class="breadcrumb">
                <a href="?view=tasks"><i class="fas fa-tasks"></i> Görevler</a>
                <i class="fas fa-angle-right"></i>
                <span><?php echo $form_title; ?></span>
            </div>
            <h1><?php echo $form_title; ?></h1>
        </div>
        <div class="header-actions">
            <a href="?view=tasks" class="btn btn-ghost">
                <i class="fas fa-arrow-left"></i>
                <span>Geri Dön</span>
            </a>
        </div>
    </div>

    <?php if (isset($message)): ?>
    <div class="notification-banner notification-<?php echo $message_type; ?>">
        <div class="notification-icon">
            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        </div>
        <div class="notification-content">
            <?php echo $message; ?>
        </div>
        <button class="notification-close">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php endif; ?>
    
    <form method="post" action="" class="modern-form priority-<?php echo $current_priority; ?>" autocomplete="off">
        <?php wp_nonce_field('save_task', 'task_nonce'); ?>
        
        <div class="form-card">
            <div class="card-header">
                <h2><i class="fas fa-clipboard-list"></i> Görev Detayları</h2>
            </div>
            <div class="card-body">
                <div class="form-section">
                    <div class="form-group full-width">
                        <label for="task_title">
                            <i class="fas fa-heading"></i>
                            Görev Başlığı <span class="required">*</span>
                        </label>
                        <input type="text" name="task_title" id="task_title" class="form-input" 
                            value="<?php echo $editing && isset($task->task_title) ? esc_attr($task->task_title) : esc_attr($default_task_title); ?>" 
                            placeholder="Görev için kısa başlık girin" required>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="task_description">
                            <i class="fas fa-file-alt"></i>
                            Görev Açıklaması <span class="required">*</span>
                        </label>
                        <textarea name="task_description" id="task_description" class="form-textarea" 
                            placeholder="Görevin detaylarını buraya yazın" required><?php 
                            echo $editing ? esc_textarea($task->task_description) : esc_textarea($default_task_description); 
                            ?></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="customer_filter">
                                <i class="fas fa-user"></i>
                                Müşteri <span class="required">*</span>
                            </label>
                            
                            <div class="customer-search-section">
                                <!-- Customer Search -->
                                <div class="customer-search-container">
                                    <input type="text" id="customer_search" class="form-input" 
                                           placeholder="Müşteri adı, TC kimlik no, telefon ile arama..." autocomplete="off">
                                </div>
                                
                                <!-- Search Results -->
                                <div id="customer_search_results" class="search-results" style="display: none;"></div>
                                
                                <!-- Selected Customer Display -->
                                <div id="selected_customer_display" class="selected-customer" style="display: none;">
                                    <div class="selected-customer-info">
                                        <div class="customer-avatar">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <div class="customer-details">
                                            <div class="customer-name"></div>
                                            <div class="customer-meta"></div>
                                        </div>
                                        <button type="button" class="btn btn-outline btn-sm" onclick="clearCustomerSelection()">
                                            <i class="fas fa-times"></i> Değiştir
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Hidden input for selected customer -->
                                <input type="hidden" name="customer_id" id="selected_customer_id" value="<?php echo $selected_customer_id; ?>">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Müşteri Poliçeleri (Radio Buttons) -->
                    <div id="customer-policies-container" class="form-group full-width" style="display: <?php echo $selected_customer_id ? 'block' : 'none'; ?>;">
                        <div class="loading-indicator">
                            <div class="spinner"></div>
                            <span>Poliçeler yükleniyor...</span>
                        </div>
                    </div>
                    
                    <div class="form-row two-columns">
                        <div class="form-group">
                            <label for="due_date">
                                <i class="fas fa-calendar-alt"></i>
                                Son Tarih <span class="required">*</span>
                            </label>
                            <input type="datetime-local" name="due_date" id="due_date" class="form-input" 
                                value="<?php echo $editing ? date('Y-m-d\TH:i', strtotime($task->due_date)) : esc_attr($default_due_date); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="priority">
                                <i class="fas fa-flag"></i>
                                Öncelik <span class="required">*</span>
                            </label>
                            <select name="priority" id="priority" class="form-select priority-selector" required>
                                <option value="low" <?php selected($current_priority, 'low'); ?>>Düşük</option>
                                <option value="medium" <?php selected($current_priority, 'medium'); ?>>Orta</option>
                                <option value="high" <?php selected($current_priority, 'high'); ?>>Yüksek</option>
                                <option value="urgent" <?php selected($current_priority, 'urgent'); ?>>Çok Acil</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row two-columns">
                        <div class="form-group">
                            <label for="status">
                                <i class="fas fa-spinner"></i>
                                Durum <span class="required">*</span>
                            </label>
                            <select name="status" id="status" class="form-select" required>
                                <option value="pending" <?php echo $editing && $task->status === 'pending' ? 'selected' : (!$editing ? 'selected' : ''); ?>>Beklemede</option>
                                <option value="in_progress" <?php echo $editing && $task->status === 'in_progress' ? 'selected' : ''; ?>>İşlemde</option>
                                <option value="completed" <?php echo $editing && $task->status === 'completed' ? 'selected' : ''; ?>>Tamamlandı</option>
                                <option value="cancelled" <?php echo $editing && $task->status === 'cancelled' ? 'selected' : ''; ?>>İptal Edildi</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="representative_id">
                                <i class="fas fa-user-tie"></i>
                                Sorumlu Temsilci<?php echo (!$is_patron && !$is_manager && !$is_team_leader) ? ' (Otomatik)' : ''; ?>
                            </label>
                            <select name="representative_id" id="representative_id" class="form-select" <?php echo (!$is_patron && !$is_manager && !$is_team_leader) ? 'disabled' : ''; ?>>
                                <option value="">Sorumlu Temsilci Seçin<?php echo (!$is_patron && !$is_manager && !$is_team_leader) ? ' (Otomatik)' : ' (Opsiyonel)'; ?></option>
                                <?php foreach ($representatives as $rep): ?>
                                    <option value="<?php echo esc_attr($rep->id); ?>" <?php selected($default_representative_id, $rep->id); ?>>
                                        <?php echo esc_html($rep->display_name); ?>
                                        <?php echo ($rep->id == $current_user_rep_id) ? ' (Ben)' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$is_patron && !$is_manager && !$is_team_leader): ?>
                                <input type="hidden" name="representative_id" value="<?php echo esc_attr($current_user_rep_id); ?>">
                                <p class="input-help"><i class="fas fa-info-circle"></i> Görev otomatik olarak size atanacak.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" onclick="window.location.href='?view=tasks'" class="btn btn-ghost">
                        <i class="fas fa-times"></i> İptal
                    </button>
                    <button type="submit" name="save_task" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?php echo $form_action; ?>
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Müşteri ve poliçe verilerini önyüze göm -->
<script id="customers-data" type="application/json">
<?php echo json_encode($all_customers_data); ?>
</script>
<script id="policies-data" type="application/json">
<?php echo json_encode($all_policies); ?>
</script>

<style>
:root {
    /* Colors */
    --primary: #1976d2;
    --primary-dark: #1565c0;
    --primary-light: #42a5f5;
    --secondary: #9c27b0;
    --success: #2e7d32;
    --warning: #f57c00;
    --danger: #d32f2f;
    --info: #0288d1;
    
    /* Neutral Colors */
    --surface: #ffffff;
    --surface-variant: #f5f5f5;
    --surface-container: #fafafa;
    --on-surface: #1c1b1f;
    --on-surface-variant: #49454f;
    --outline: #79747e;
    --outline-variant: #cac4d0;
    
    /* Typography */
    --font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    --font-size-xs: 0.75rem;
    --font-size-sm: 0.875rem;
    --font-size-base: 1rem;
    --font-size-lg: 1.125rem;
    --font-size-xl: 1.25rem;
    --font-size-2xl: 1.5rem;
    
    /* Spacing */
    --spacing-xs: 0.25rem;
    --spacing-sm: 0.5rem;
    --spacing-md: 1rem;
    --spacing-lg: 1.5rem;
    --spacing-xl: 2rem;
    --spacing-2xl: 3rem;
    
    /* Border Radius */
    --radius-sm: 0.25rem;
    --radius-md: 0.5rem;
    --radius-lg: 0.75rem;
    --radius-xl: 1rem;
    
    /* Shadows */
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
    
    /* Transitions */
    --transition-fast: 150ms ease;
    --transition-base: 250ms ease;
    --transition-slow: 350ms ease;
}

.task-form-container {
    font-family: var(--font-family);
    color: var(--on-surface);
    max-width: 1000px;
    margin: 0 auto;
    padding-bottom: var(--spacing-2xl);
}

/* Form Başlık */
.task-form-header {
    background: var(--surface);
    border-radius: var(--radius-xl);
    padding: var(--spacing-xl);
    margin-bottom: var(--spacing-xl);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.header-content {
    flex: 1;
}

.breadcrumb {
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
    margin-bottom: var(--spacing-sm);
    font-size: var(--font-size-sm);
    color: var(--on-surface-variant);
}

.breadcrumb a {
    color: var(--primary);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

.breadcrumb a:hover {
    text-decoration: underline;
}

.task-form-header h1 {
    margin: 0;
    font-size: var(--font-size-2xl);
    font-weight: 600;
    color: var(--on-surface);
}

.header-actions {
    display: flex;
    gap: var(--spacing-md);
}

/* Form Kartı */
.form-card {
    background-color: var(--surface);
    border-radius: var(--radius-xl);
    overflow: hidden;
    box-shadow: var(--shadow-md);
    border: 1px solid var(--outline-variant);
    margin-bottom: var(--spacing-xl);
    position: relative;
}

.modern-form.priority-low .form-card {
    border-top: 4px solid #22863a;
    box-shadow: var(--shadow-md), 0 0 0 1px #c8e1cb;
}

.modern-form.priority-medium .form-card {
    border-top: 4px solid #bf8700;
    box-shadow: var(--shadow-md), 0 0 0 1px #f4d8a0;
}

.modern-form.priority-high .form-card {
    border-top: 4px solid #cb2431;
    box-shadow: var(--shadow-md), 0 0 0 1px #f4b7bc;
}

.modern-form.priority-urgent .form-card {
    border-top: 4px solid #b71c1c;
    box-shadow: var(--shadow-md), 0 0 0 1px #f4b7bc;
}

.card-header {
    background-color: var(--surface-variant);
    padding: var(--spacing-lg) var(--spacing-xl);
    border-bottom: 1px solid var(--outline-variant);
}

.card-header h2 {
    margin: 0;
    font-size: var(--font-size-lg);
    font-weight: 600;
    color: var(--on-surface);
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.card-body {
    padding: var(--spacing-xl);
}

/* Form Bölümleri */
.form-section {
    margin-bottom: var(--spacing-xl);
}

.form-row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--spacing-lg);
    margin-bottom: var(--spacing-lg);
}

.form-row.two-columns {
    display: grid;
    grid-template-columns: 1fr 1fr;
}

.form-row:last-child {
    margin-bottom: 0;
}

.form-group {
    flex: 1;
    min-width: 200px;
    position: relative;
}

.form-group.full-width {
    flex-basis: 100%;
    width: 100%;
}

/* Form Etiketleri */
.form-group label {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    margin-bottom: var(--spacing-sm);
    font-weight: 500;
    color: var(--on-surface);
    font-size: var(--font-size-sm);
}

.form-group label i {
    color: var(--on-surface-variant);
    font-size: var(--font-size-sm);
}

.required {
    color: var(--danger);
    margin-left: var(--spacing-xs);
}

/* Input Stilleri */
.form-input,
.form-select,
.form-textarea {
    width: 100%;
    padding: var(--spacing-md);
    border: 1px solid var(--outline-variant);
    border-radius: var(--radius-lg);
    font-size: var(--font-size-base);
    line-height: 1.5;
    color: var(--on-surface);
    background-color: var(--surface);
    transition: all var(--transition-fast);
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.05);
}

.form-input:focus,
.form-select:focus,
.form-textarea:focus {
    border-color: var(--primary);
    outline: none;
    box-shadow: 0 0 0 4px rgba(25, 118, 210, 0.1);
}

.form-textarea {
    min-height: 150px;
    resize: vertical;
}

/* Müşteri Seçici */
.customer-search-section {
    position: relative;
}

.customer-search-container {
    margin-bottom: var(--spacing-md);
}

.customer-search-container input {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid var(--outline-variant);
    border-radius: var(--radius-md);
    font-size: 14px;
    transition: all 0.2s ease;
}

.customer-search-container input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(25, 118, 210, 0.2);
    outline: none;
}

/* Search Results Enhancement */
.search-results {
    background: var(--surface);
    border: 1px solid var(--outline-variant);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
    max-height: 300px;
    overflow-y: auto;
    margin-bottom: var(--spacing-md);
    z-index: 1000;
}

.search-no-results,
.search-error {
    padding: var(--spacing-md);
    text-align: center;
    color: var(--on-surface-variant);
    font-style: italic;
}

.search-error {
    color: var(--danger);
    background: rgba(244, 67, 54, 0.1);
}

.search-result-item {
    padding: var(--spacing-md);
    border-bottom: 1px solid var(--outline-variant);
    cursor: pointer;
    transition: background-color 0.2s ease;
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
}

.search-result-item:hover {
    background: var(--surface-variant);
}

.search-result-item:last-child {
    border-bottom: none;
}

.search-result-avatar {
    width: 40px;
    height: 40px;
    background: var(--primary);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.search-result-info {
    flex: 1;
}

.search-result-name {
    font-weight: 600;
    color: var(--on-surface);
    margin-bottom: 2px;
}

.search-result-meta {
    font-size: var(--font-size-sm);
    color: var(--on-surface-variant);
}

.selected-customer {
    background: var(--surface-variant);
    border: 1px solid var(--outline);
    border-radius: var(--radius-md);
    padding: var(--spacing-md);
}

.selected-customer-info {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
}

.customer-avatar {
    width: 48px;
    height: 48px;
    background: var(--primary);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}

.customer-details {
    flex: 1;
}

.customer-name {
    font-weight: 600;
    color: var(--on-surface);
    margin-bottom: 2px;
}

.customer-meta {
    font-size: var(--font-size-sm);
    color: var(--on-surface-variant);
}

.btn-sm {
    padding: 6px 12px;
    font-size: var(--font-size-sm);
}

.loading-indicator {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-md);
    padding: var(--spacing-lg);
    color: var(--on-surface-variant);
}

.spinner {
    width: 20px;
    height: 20px;
    border: 2px solid var(--outline-variant);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Poliçe Listesi */
.policies-list {
    border: 1px solid var(--outline-variant);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    margin-top: var(--spacing-sm);
}

.policy-option {
    border-bottom: 1px solid var(--outline-variant);
}

.policy-option:last-child {
    border-bottom: none;
}

.policy-radio {
    display: flex;
    align-items: center;
    padding: var(--spacing-md) var(--spacing-lg);
    font-size: var(--font-size-sm);
    cursor: pointer;
    transition: background-color var(--transition-fast);
}

.policy-radio:hover {
    background-color: var(--surface-variant);
}

.policy-radio input[type="radio"] {
    margin-right: var(--spacing-md);
    accent-color: var(--primary);
    width: 18px;
    height: 18px;
}

.policy-radio-content {
    flex: 1;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--spacing-md);
}

.policy-number-text {
    font-weight: 600;
    color: var(--on-surface);
}

.policy-type-tag,
.policy-company-tag,
.policy-date-tag {
    display: inline-flex;
    align-items: center;
    padding: 2px var(--spacing-sm);
    border-radius: var(--radius-sm);
    font-size: var(--font-size-xs);
    background-color: rgba(25, 118, 210, 0.1);
    color: var(--primary);
}

.policy-company-tag {
    background-color: rgba(156, 39, 176, 0.1);
    color: var(--secondary);
}

.policy-date-tag {
    background-color: rgba(46, 125, 50, 0.1);
    color: var(--success);
}

.policy-none {
    display: flex;
    align-items: center;
    padding: var(--spacing-md) var(--spacing-lg);
    font-size: var(--font-size-sm);
    cursor: pointer;
    color: var(--on-surface-variant);
    transition: background-color var(--transition-fast);
}

.policy-none:hover {
    background-color: var(--surface-variant);
}

.policy-none input[type="radio"] {
    margin-right: var(--spacing-md);
    accent-color: var(--primary);
    width: 18px;
    height: 18px;
}

.policy-empty {
    padding: var(--spacing-lg);
    text-align: center;
    color: var(--on-surface-variant);
    font-style: italic;
}

/* Öncelik Seçici */
.priority-selector option[value="low"] {
    color: #22863a;
}

.priority-selector option[value="medium"] {
    color: #bf8700;
}

.priority-selector option[value="high"] {
    color: #cb2431;
}

.priority-selector option[value="urgent"] {
    color: #b71c1c;
}

/* Priority Low */
.modern-form.priority-low .priority-selector {
    background-color: rgba(46, 125, 50, 0.1);
    color: #22863a;
    border-color: #c8e1cb;
}

/* Priority Medium */
.modern-form.priority-medium .priority-selector {
    background-color: rgba(245, 124, 0, 0.1);
    color: #bf8700;
    border-color: #f4d8a0;
}

/* Priority High */
.modern-form.priority-high .priority-selector {
    background-color: rgba(211, 47, 47, 0.1);
    color: #cb2431;
    border-color: #f4b7bc;
}

/* Priority Urgent */
.modern-form.priority-urgent .priority-selector {
    background-color: rgba(211, 47, 47, 0.15);
    color: #b71c1c;
    border-color: #f4b7bc;
}

/* Yardım Metni */
.input-help {
    font-size: var(--font-size-xs);
    color: var(--on-surface-variant);
    margin-top: var(--spacing-xs);
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

/* Form Eylemleri */
.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--spacing-md);
    padding-top: var(--spacing-lg);
    border-top: 1px solid var(--outline-variant);
    margin-top: var(--spacing-xl);
}

/* Butonlar */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-sm);
    padding: var(--spacing-sm) var(--spacing-md);
    border: 1px solid transparent;
    border-radius: var(--radius-lg);
    font-size: var(--font-size-sm);
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: all var(--transition-fast);
    position: relative;
    overflow: hidden;
    background: none;
    white-space: nowrap;
}

.btn:before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
    transition: left 0.5s;
}

.btn:hover:before {
    left: 100%;
}

.btn-primary {
    background: var(--primary);
    color: white;
    box-shadow: var(--shadow-sm);
    padding: var(--spacing-md) var(--spacing-xl);
}

.btn-primary:hover {
    background: var(--primary-dark);
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
}

.btn-ghost {
    background: transparent;
    color: var(--on-surface-variant);
    border: 1px solid var(--outline-variant);
}

.btn-ghost:hover {
    background: var(--surface-variant);
    color: var(--on-surface);
}

/* Hata Stili */
.input-error {
    border-color: var(--danger) !important;
}

.error-message {
    color: var(--danger);
    font-size: var(--font-size-xs);
    margin-top: var(--spacing-xs);
}

/* Bildirim Banner */
.notification-banner {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    padding: var(--spacing-md) var(--spacing-lg);
    border-radius: var(--radius-lg);
    margin-bottom: var(--spacing-lg);
    animation: slideInDown 0.3s ease;
    box-shadow: var(--shadow-md);
}

.notification-success {
    background-color: #e8f5e9;
    border-left: 4px solid var(--success);
}

.notification-error {
    background-color: #ffebee;
    border-left: 4px solid var(--danger);
}

.notification-warning {
    background-color: #fff3e0;
    border-left: 4px solid var(--warning);
}

.notification-info {
    background-color: #e1f5fe;
    border-left: 4px solid var(--info);
}

.notification-icon {
    font-size: var(--font-size-xl);
}

.notification-success .notification-icon {
    color: var(--success);
}

.notification-error .notification-icon {
    color: var(--danger);
}

.notification-warning .notification-icon {
    color: var(--warning);
}

.notification-info .notification-icon {
    color: var(--info);
}

.notification-content {
    flex-grow: 1;
    font-size: var(--font-size-base);
}

.notification-close {
    background: none;
    border: none;
    color: var(--on-surface-variant);
    cursor: pointer;
    font-size: var(--font-size-lg);
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    transition: background-color var(--transition-fast);
}

.notification-close:hover {
    background-color: rgba(0, 0, 0, 0.05);
}

@keyframes slideInDown {
    from {
        transform: translateY(-20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

/* Responsive Design */
@media (max-width: 768px) {
    .task-form-container {
        padding: var(--spacing-md);
    }
    
    .task-form-header {
        flex-direction: column;
        gap: var(--spacing-md);
    }
    
    .header-actions {
        width: 100%;
    }
    
    .form-row.two-columns {
        grid-template-columns: 1fr;
    }
    
    .form-actions {
        flex-direction: column-reverse;
    }
    
    .btn {
        width: 100%;
    }
    
    .policy-radio-content {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--spacing-xs);
    }
}
</style>

<script>
/**
 * Modern Task Form JavaScript
 * @version 3.2.0
 * @date 2025-05-30 21:39:10
 */
document.addEventListener('DOMContentLoaded', function() {
    // DOM elementleri
    const customerSearchInput = document.getElementById('customer_search');
    const customerSearchResults = document.getElementById('customer_search_results');
    const selectedCustomerDisplay = document.getElementById('selected_customer_display');
    const selectedCustomerId = document.getElementById('selected_customer_id');
    const policiesContainer = document.getElementById('customer-policies-container');
    const prioritySelect = document.getElementById('priority');
    const taskForm = document.querySelector('.modern-form');
    
    // Müşteri ve poliçe verilerini al
    const customersData = JSON.parse(document.getElementById('customers-data').textContent);
    const policiesData = JSON.parse(document.getElementById('policies-data').textContent);

    // Initialize customer selection if editing mode
    if (selectedCustomerId && selectedCustomerId.value) {
        const customerId = selectedCustomerId.value;
        const customerData = customersData.find(c => c.id == customerId);
        if (customerData) {
            displaySelectedCustomer({
                id: customerData.id,
                name: customerData.first_name + ' ' + customerData.last_name,
                tc_vkn: customerData.tc_kimlik_no || 'Belirtilmemiş',
                phone: customerData.phone || 'Belirtilmemiş',
                is_company: false
            });
            loadCustomerPolicies(customerId);
        }
    }
    
    // Enhanced interactive customer search functionality
    if (customerSearchInput) {
        // Live search on input with improved debouncing
        let searchTimeout;
        customerSearchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const searchTerm = this.value.trim();
            
            if (searchTerm.length < 2) {
                customerSearchResults.style.display = 'none';
                return;
            }
            
            // Show loading immediately for better UX
            customerSearchResults.innerHTML = '<div class="loading-indicator"><div class="spinner"></div><span>Aranıyor...</span></div>';
            customerSearchResults.style.display = 'block';
            
            searchTimeout = setTimeout(() => {
                performCustomerSearch();
            }, 300); // Debounce for 300ms
        });
        
        // Auto-trigger search on Enter key
        customerSearchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                performCustomerSearch();
            }
        });
        
        // Hide results when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.customer-search-section')) {
                customerSearchResults.style.display = 'none';
            }
        });
        
        // Focus enhancement
        customerSearchInput.addEventListener('focus', function() {
            if (this.value.trim().length >= 2) {
                customerSearchResults.style.display = 'block';
            }
        });
    }
    
    function performCustomerSearch() {
        const searchTerm = customerSearchInput.value.trim();
        if (searchTerm.length < 2) {
            alert('En az 2 karakter giriniz.');
            return;
        }
        
        // Show loading
        customerSearchResults.innerHTML = '<div class="loading-indicator"><div class="spinner"></div><span>Aranıyor...</span></div>';
        customerSearchResults.style.display = 'block';
        
        // Create search request
        const formData = new FormData();
        formData.append('action', 'search_customers_for_task');
        formData.append('search_term', searchTerm);
        formData.append('nonce', '<?php echo wp_create_nonce("customer_search"); ?>');
        
        // Enhanced debugging
        console.log('Searching for customers with term:', searchTerm);
        
        fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Response status:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('Search response:', data);
            if (data.success) {
                displaySearchResults(data.data);
            } else {
                console.error('Search failed:', data.data);
                customerSearchResults.innerHTML = '<div class="search-no-results">Müşteri bulunamadı: ' + (data.data || 'Bilinmeyen hata') + '</div>';
            }
        })
        .catch(error => {
            console.error('Search error:', error);
            customerSearchResults.innerHTML = '<div class="search-error">Arama sırasında hata oluştu: ' + error.message + '</div>';
        });
    }
    
    function displaySearchResults(customers) {
        if (!customers || customers.length === 0) {
            customerSearchResults.innerHTML = '<div class="search-no-results">Müşteri bulunamadı.</div>';
            return;
        }
        
        let html = '';
        customers.forEach(customer => {
            const displayName = customer.company_name || (customer.first_name + ' ' + customer.last_name);
            const tcVkn = customer.tax_number || customer.tc_identity || 'Belirtilmemiş';
            const phone = customer.phone || 'Belirtilmemiş';
            
            // Escape quotes to prevent JavaScript errors
            const safeDisplayName = displayName.replace(/'/g, "\\'").replace(/"/g, '\\"');
            const safeTcVkn = tcVkn.replace(/'/g, "\\'").replace(/"/g, '\\"');
            const safePhone = phone.replace(/'/g, "\\'").replace(/"/g, '\\"');
            const safeCompanyName = (customer.company_name || '').replace(/'/g, "\\'").replace(/"/g, '\\"');
            
            html += `
                <div class="search-result-item" onclick="selectCustomer(${customer.id}, '${safeDisplayName}', '${safeTcVkn}', '${safePhone}', '${safeCompanyName}')">
                    <div class="search-result-avatar">
                        <i class="fas fa-${customer.company_name ? 'building' : 'user'}"></i>
                    </div>
                    <div class="search-result-info">
                        <div class="search-result-name">${displayName}</div>
                        <div class="search-result-meta">
                            ${customer.company_name ? 'VKN' : 'TC'}: ${tcVkn} | Tel: ${phone}
                        </div>
                    </div>
                </div>
            `;
        });
        
        customerSearchResults.innerHTML = html;
    }
    
    window.selectCustomer = function(customerId, displayName, tcVkn, phone, companyName) {
        console.log('Selecting customer:', customerId, displayName);
        
        try {
            const customerData = {
                id: customerId,
                name: displayName,
                tc_vkn: tcVkn,
                phone: phone,
                is_company: !!companyName
            };
            
            selectedCustomerId.value = customerId;
            displaySelectedCustomer(customerData);
            customerSearchResults.style.display = 'none';
            customerSearchInput.value = '';
            
            // Load customer policies
            loadCustomerPolicies(customerId);
        } catch (error) {
            console.error('Error selecting customer:', error);
            alert('Müşteri seçiminde hata oluştu: ' + error.message);
        }
    };
    
    function displaySelectedCustomer(customerData) {
        const nameEl = selectedCustomerDisplay.querySelector('.customer-name');
        const metaEl = selectedCustomerDisplay.querySelector('.customer-meta');
        const avatarEl = selectedCustomerDisplay.querySelector('.customer-avatar i');
        
        nameEl.textContent = customerData.name;
        metaEl.textContent = `${customerData.is_company ? 'VKN' : 'TC'}: ${customerData.tc_vkn} | Tel: ${customerData.phone}`;
        avatarEl.className = `fas fa-${customerData.is_company ? 'building' : 'user'}`;
        
        selectedCustomerDisplay.style.display = 'block';
        customerSearchResults.style.display = 'none';
    }
    
    window.clearCustomerSelection = function() {
        selectedCustomerId.value = '';
        selectedCustomerDisplay.style.display = 'none';
        policiesContainer.style.display = 'none';
        policiesContainer.innerHTML = '';
        customerSearchInput.value = '';
    };

    // Bildirim kapatma
    document.querySelectorAll('.notification-close').forEach(button => {
        button.addEventListener('click', () => {
            const notification = button.closest('.notification-banner');
            if (notification) {
                notification.style.opacity = '0';
                setTimeout(() => {
                    notification.style.display = 'none';
                }, 300);
            }
        });
    });
    
    // Auto-hide notifications after 5 seconds
    const notifications = document.querySelectorAll('.notification-banner');
    if (notifications.length > 0) {
        setTimeout(() => {
            notifications.forEach(notification => {
                notification.style.opacity = '0';
                setTimeout(() => {
                    notification.style.display = 'none';
                }, 300);
            });
        }, 5000);
    }

    // Müşteri seçimi değiştiğinde poliçeleri göster
    if (customerDropdown) {
        customerDropdown.addEventListener('change', function() {
            const customerId = this.value;
            console.log('Müşteri seçildi: ID=' + customerId);
            
            if (customerId) {
                loadCustomerPolicies(customerId);
            } else {
                policiesContainer.style.display = 'none';
                policiesContainer.innerHTML = '';
                console.log('Müşteri seçilmedi, poliçeler gizlendi');
            }
        });
    } else {
        console.error('Customer dropdown bulunamadı');
    }

    // Poliçeleri yükleme fonksiyonu
    function loadCustomerPolicies(customerId) {
        policiesContainer.innerHTML = '<div class="loading-indicator"><div class="spinner"></div><span>Poliçeler yükleniyor...</span></div>';
        policiesContainer.style.display = 'block';
        
        const policies = policiesData[customerId] || [];
        console.log('Poliçeler: ', policies);
        
        let html = '';
        
        if (policies.length > 0) {
            html += '<label><i class="fas fa-file-contract"></i> İlgili Poliçe</label>';
            html += '<div class="policies-list">';
            html += '<div class="policy-option">';
            html += '<label class="policy-none">';
            html += '<input type="radio" name="policy_id" value="" checked>';
            html += '<span>Poliçe İlişkilendirme</span>';
            html += '</label>';
            html += '</div>';
            
            policies.forEach(policy => {
                html += '<div class="policy-option">';
                html += '<label class="policy-radio">';
                html += '<input type="radio" name="policy_id" value="' + policy.id + '"' + 
                        (policy.id == '<?php echo esc_attr($selected_policy_id); ?>' ? ' checked' : '') + '>';
                
                html += '<div class="policy-radio-content">';
                html += '<span class="policy-number-text">' + policy.policy_number + '</span>';
                
                if (policy.policy_type) {
                    html += '<span class="policy-type-tag">' + policy.policy_type + '</span>';
                }
                
                if (policy.insurance_company) {
                    html += '<span class="policy-company-tag">' + policy.insurance_company + '</span>';
                }
                
                html += '<span class="policy-date-tag">' + policy.start_date + ' - ' + policy.end_date + '</span>';
                html += '</div>'; // policy-radio-content end
                
                html += '</label>';
                html += '</div>';
            });
            
            html += '</div>'; // policies-list end
        } else {
            html += '<label><i class="fas fa-file-contract"></i> İlgili Poliçe</label>';
            html += '<div class="policies-list">';
            html += '<div class="policy-empty">Bu müşteriye ait poliçe bulunamadı.</div>';
            html += '</div>';
        }
        
        policiesContainer.innerHTML = html;
    }

    // Sayfa yüklendiğinde poliçeleri yükle
    if (customerDropdown && customerDropdown.value) {
        console.log('Sayfa yüklendi, poliçeler yükleniyor: Müşteri ID=' + customerDropdown.value);
        loadCustomerPolicies(customerDropdown.value);
    }

    // Öncelik seçimi için stil güncelleme
    if (prioritySelect) {
        console.log('Priority select bulundu, stil güncelleniyor');
        updatePriorityStyle();
        prioritySelect.addEventListener('change', function() {
            console.log('Öncelik değişti: ' + this.value);
            updatePriorityStyle();
        });
    } else {
        console.error('Priority select bulunamadı');
    }

    // Son tarih kontrolü
    const dueDateInput = document.getElementById('due_date');
    if (dueDateInput) {
        dueDateInput.addEventListener('change', () => {
            console.log('Son tarih değişti: ' + dueDateInput.value);
            validateDueDate(dueDateInput);
        });
        validateDueDate(dueDateInput);
    } else {
        console.error('Due date input bulunamadı');
    }

    function validateDueDate(input) {
        const warningElement = document.querySelector('.past-date-warning');
        if (warningElement) warningElement.remove();

        if (input.value) {
            const dueDate = new Date(input.value);
            const now = new Date();
            if (dueDate < now) {
                const warning = document.createElement('div');
                warning.className = 'notification-banner notification-warning past-date-warning';
                warning.innerHTML = `
                    <div class="notification-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="notification-content">
                        Girdiğiniz son tarih geçmişte kalmış.
                    </div>
                    <button class="notification-close">
                        <i class="fas fa-times"></i>
                    </button>`;
                input.parentElement.parentElement.appendChild(warning);
                console.log('Uyarı: Son tarih geçmişte');
                
                // Uyarı kapatma işlevi ekle
                warning.querySelector('.notification-close').addEventListener('click', function() {
                    warning.style.opacity = '0';
                    setTimeout(() => {
                        warning.style.display = 'none';
                    }, 300);
                });
            }
        }
    }

    // Öncelik stil güncelleme
    function updatePriorityStyle() {
        const value = prioritySelect.value;
        // Form sınıfını güncelle
        taskForm.className = 'modern-form priority-' + value;
        
        console.log('Öncelik stili güncellendi: value=' + value);
    }
    
    // Form zorunlu alan kontrolleri
    const form = document.querySelector('.modern-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const requiredInputs = form.querySelectorAll('[required]');
            let formValid = true;
            
            requiredInputs.forEach(input => {
                if (!input.value.trim()) {
                    formValid = false;
                    input.classList.add('input-error');
                    
                    // Hata mesajı göster
                    let errorMsg = input.parentElement.querySelector('.error-message');
                    if (!errorMsg) {
                        errorMsg = document.createElement('div');
                        errorMsg.className = 'error-message';
                        errorMsg.textContent = 'Bu alan zorunludur';
                        input.parentElement.appendChild(errorMsg);
                    }
                } else {
                    input.classList.remove('input-error');
                    const errorMsg = input.parentElement.querySelector('.error-message');
                    if (errorMsg) errorMsg.remove();
                }
            });
            
            if (!formValid) {
                e.preventDefault();
                // Form başına kaydır
                window.scrollTo({
                    top: form.offsetTop - 100,
                    behavior: 'smooth'
                });
                
                // Hata uyarısı göster
                const formErrorMsg = document.createElement('div');
                formErrorMsg.className = 'notification-banner notification-error';
                formErrorMsg.innerHTML = `
                    <div class="notification-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <div class="notification-content">
                        Lütfen formda eksik kalan zorunlu alanları doldurun.
                    </div>
                    <button class="notification-close">
                        <i class="fas fa-times"></i>
                    </button>`;
                
                const existingError = document.querySelector('.notification-banner.notification-error');
                if (existingError) existingError.remove();
                
                form.insertAdjacentElement('beforebegin', formErrorMsg);
                
                // Uyarı kapatma işlevi ekle
                formErrorMsg.querySelector('.notification-close').addEventListener('click', function() {
                    formErrorMsg.style.opacity = '0';
                    setTimeout(() => {
                        formErrorMsg.style.display = 'none';
                    }, 300);
                });
                
                // 5 saniye sonra otomatik kaybol
                setTimeout(() => {
                    if (formErrorMsg.parentElement) {
                        formErrorMsg.style.opacity = '0';
                        setTimeout(() => {
                            if (formErrorMsg.parentElement) {
                                formErrorMsg.style.display = 'none';
                            }
                        }, 300);
                    }
                }, 5000);
            }
        });
        
        // Input focus/blur olayları
        const formInputs = form.querySelectorAll('.form-input, .form-select, .form-textarea');
        formInputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.classList.add('input-focus');
            });
            
            input.addEventListener('blur', function() {
                this.classList.remove('input-focus');
                
                if (this.hasAttribute('required') && !this.value.trim()) {
                    this.classList.add('input-error');
                } else {
                    this.classList.remove('input-error');
                }
            });
        });
    }
});
</script>
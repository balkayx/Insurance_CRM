<?php
/**
 * Poliçe Ekleme/Düzenleme Formu
 * @version 5.0.0
 * @updated 2025-05-29 18:25
 */

include_once(dirname(__FILE__) . '/template-colors.php');

if (!is_user_logged_in()) {
    return;
}

// Veritabanında gerekli sütunların varlığını kontrol et ve yoksa ekle
global $wpdb;
$policies_table = $wpdb->prefix . 'insurance_crm_policies';

// insured_party sütunu kontrolü
$column_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'insured_party'");
if (!$column_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN insured_party VARCHAR(255) DEFAULT NULL AFTER status");
}

// İptal bilgileri için sütunlar
$cancellation_date_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'cancellation_date'");
if (!$cancellation_date_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN cancellation_date DATE DEFAULT NULL AFTER status");
}

$refunded_amount_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'refunded_amount'");
if (!$refunded_amount_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN refunded_amount DECIMAL(10,2) DEFAULT NULL AFTER cancellation_date");
}

// YENİ: İptal nedeni için sütun
$cancellation_reason_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'cancellation_reason'");
if (!$cancellation_reason_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN cancellation_reason VARCHAR(100) DEFAULT NULL AFTER refunded_amount");
}

// YENİ: Silinen poliçeler için sütunlar
$is_deleted_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'is_deleted'");
if (!$is_deleted_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN is_deleted TINYINT(1) DEFAULT 0");
}

$deleted_by_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'deleted_by'");
if (!$deleted_by_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN deleted_by INT(11) DEFAULT NULL");
}

$deleted_at_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'deleted_at'");
if (!$deleted_at_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN deleted_at DATETIME DEFAULT NULL");
}

// YENİ: Yeni İş - Yenileme bilgisi için sütun
$policy_category_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'policy_category'");
if (!$policy_category_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN policy_category VARCHAR(50) DEFAULT 'Yeni İş' AFTER policy_type");
}

// YENİ: Network bilgisi için sütun
$network_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'network'");
if (!$network_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN network VARCHAR(255) DEFAULT NULL AFTER premium_amount");
}

// YENİ: Durum bilgisi notu için sütun
$status_note_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'status_note'");
if (!$status_note_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN status_note TEXT DEFAULT NULL AFTER status");
}

// YENİ: Ödeme bilgisi için sütun
$payment_info_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'payment_info'");
if (!$payment_info_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN payment_info VARCHAR(255) DEFAULT NULL AFTER premium_amount");
}

// YENİ: Plaka bilgisi için sütun (Kasko/Trafik için gerekli)
$plate_number_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'plate_number'");
if (!$plate_number_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN plate_number VARCHAR(20) DEFAULT NULL AFTER insured_party");
}

// YENİ: Sigorta ettiren (müşteri) bilgisi için sütun
$insurer_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'insurer'");
if (!$insurer_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN insurer VARCHAR(255) DEFAULT NULL AFTER insured_party");
}

// YENİ: Sigortalılar listesi için sütun
$insured_list_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'insured_list'");
if (!$insured_list_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN insured_list TEXT DEFAULT NULL AFTER insurer");
}

// YENİ: Brüt prim için sütun (Kasko/Trafik için)
$gross_premium_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'gross_premium'");
if (!$gross_premium_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN gross_premium DECIMAL(10,2) DEFAULT NULL AFTER premium_amount");
}

$editing = isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']) && intval($_GET['id']) > 0;
$renewing = isset($_GET['action']) && $_GET['action'] === 'renew' && isset($_GET['id']) && intval($_GET['id']) > 0;
$cancelling = isset($_GET['action']) && $_GET['action'] === 'cancel' && isset($_GET['id']) && intval($_GET['id']) > 0;
$create_from_offer = isset($_GET['action']) && $_GET['action'] === 'create_from_offer' && isset($_GET['customer_id']);
$policy_id = $editing || $renewing || $cancelling ? intval($_GET['id']) : 0;

// YENİ: Tanımlı iptal nedenleri
$cancellation_reasons = ['Araç Satışı', 'İsteğe Bağlı', 'Tahsilattan İptal', 'Diğer Sebepler'];

// Teklif verilerini al
$offer_amount = isset($_GET['offer_amount']) ? floatval($_GET['offer_amount']) : '';
$offer_type = isset($_GET['offer_type']) ? sanitize_text_field(urldecode($_GET['offer_type'])) : '';
$offer_file_id = isset($_GET['file_id']) ? intval($_GET['file_id']) : 0;
$selected_customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$customer_search_value = isset($_GET['customer_search']) ? sanitize_text_field(urldecode($_GET['customer_search'])) : '';

// Oturum açmış temsilcinin ID'sini al
$current_user_rep_id = function_exists('get_current_user_rep_id') ? get_current_user_rep_id() : 0;

/**
 * Kullanıcının patron veya müdür olup olmadığını kontrol et
 * GÜNCELLEME: Artık temsilci tablosundaki role değerini kontrol ediyoruz
 */
function is_patron_or_manager() {
    global $wpdb;
    $current_user_rep_id = function_exists('get_current_user_rep_id') ? get_current_user_rep_id() : 0;
    
    if (!$current_user_rep_id) {
        return false;
    }
    
    $representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
    $role = $wpdb->get_var($wpdb->prepare(
        "SELECT role FROM $representatives_table WHERE id = %d",
        $current_user_rep_id
    ));
    
    // 1: Patron, 2: Müdür
    return ($role == 1 || $role == 2);
}

// Kullanıcının rolünü öğren (1: Patron, 2: Müdür, vb.)
function get_current_user_role() {
    global $wpdb;
    $current_user_rep_id = function_exists('get_current_user_rep_id') ? get_current_user_rep_id() : 0;
    
    if (!$current_user_rep_id) {
        return 0; // Temsilci değil
    }
    
    $representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
    $role = $wpdb->get_var($wpdb->prepare(
        "SELECT role FROM $representatives_table WHERE id = %d",
        $current_user_rep_id
    ));
    
    return intval($role);
}

// Kullanıcının poliçe düzenleme/silme yetkileri var mı?
function has_policy_permissions() {
    global $wpdb;
    $current_user_rep_id = function_exists('get_current_user_rep_id') ? get_current_user_rep_id() : 0;
    
    if (!$current_user_rep_id) {
        return false;
    }
    
    // Patron/müdür kontrolü
    $user_role = get_current_user_role();
    if ($user_role == 1 || $user_role == 2) {
        return true; // Patron ve müdür her türlü yetkiye sahip
    }
    
    // Diğer kullanıcılar için yetki kontrolü
    $representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
    $permissions = $wpdb->get_row($wpdb->prepare(
        "SELECT policy_edit, policy_delete FROM $representatives_table WHERE id = %d",
        $current_user_rep_id
    ));
    
    if ($permissions) {
        return ($permissions->policy_edit == 1 || $permissions->policy_delete == 1);
    }
    
    return false;
}

// Müşteri temsilcileri listesini al (Patron ve Müdür için)
$customer_representatives = [];
if (is_patron_or_manager()) {
    $representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
    $customer_representatives = $wpdb->get_results("
        SELECT r.id, u.display_name 
        FROM $representatives_table r
        LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
        WHERE r.status = 'active'
        ORDER BY u.display_name ASC
    ");
}

/**
 * Build redirect URL preserving filter parameters from HTTP_REFERER
 */
function build_redirect_url_with_filters($base_params = []) {
    $filter_params = [];
    
    // Referer URL'inden filtre parametrelerini çıkart
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $referer_parts = parse_url($_SERVER['HTTP_REFERER']);
        if (!empty($referer_parts['query'])) {
            parse_str($referer_parts['query'], $referer_params);
            
            // Korunacak filtre parametrelerinin listesi
            $preserve_params = [
                'policy_number', 'customer_id', 'policy_type', 'insurance_company', 
                'status', 'insured_party', 'policy_category', 'start_date', 'end_date',
                'date_range', 'period', 'representative_id_filter', 'payment_info',
                'status_note', 'expiring_soon', 'show_passive', 'show_cancelled',
                'cancellation_reason', 'include_passive_dates', 'view_type'
            ];
            
            foreach ($preserve_params as $param) {
                if (!empty($referer_params[$param])) {
                    $filter_params[$param] = $referer_params[$param];
                }
            }
        }
    }
    
    // Temel parametreleri filtre parametreleriyle birleştir
    $all_params = array_merge($filter_params, $base_params);
    
    // Çift HTML kodlamasını önlemek için URL'yi manuel olarak oluştur
    $base_url = strtok($_SERVER['REQUEST_URI'], '?');
    if (!empty($all_params)) {
        $query_string = http_build_query($all_params);
        return $base_url . '?' . $query_string;
    }
    
    return $base_url;
}

// Müşteri adını ID'ye göre getir
function get_customer_name_by_id($customer_id) {
    global $wpdb;
    $customer = $wpdb->get_row($wpdb->prepare(
        "SELECT first_name, last_name FROM {$wpdb->prefix}insurance_crm_customers WHERE id = %d",
        $customer_id
    ));
    return $customer ? trim($customer->first_name . ' ' . $customer->last_name) : '';
}

if (isset($_POST['save_policy']) && isset($_POST['policy_nonce']) && wp_verify_nonce($_POST['policy_nonce'], 'save_policy')) {
    $policy_data = array(
        'customer_id' => intval($_POST['customer_id']),
        'policy_number' => sanitize_text_field($_POST['policy_number']),
        'policy_type' => sanitize_text_field($_POST['policy_type']),
        'policy_category' => sanitize_text_field($_POST['policy_category']),
        'insurance_company' => sanitize_text_field($_POST['insurance_company']),
        'start_date' => sanitize_text_field($_POST['start_date']),
        'end_date' => sanitize_text_field($_POST['end_date']),
        'premium_amount' => floatval($_POST['premium_amount']),
        'gross_premium' => isset($_POST['gross_premium']) ? floatval($_POST['gross_premium']) : null,
        'payment_info' => isset($_POST['payment_info']) ? sanitize_text_field($_POST['payment_info']) : '',
        'network' => isset($_POST['network']) ? sanitize_text_field($_POST['network']) : '',
        'status' => sanitize_text_field($_POST['status']),
        'status_note' => isset($_POST['status_note']) ? sanitize_textarea_field($_POST['status_note']) : '',
        'insured_party' => isset($_POST['same_as_insured']) && $_POST['same_as_insured'] === 'yes' ? '' : sanitize_text_field($_POST['insured_party']),
        'insurer' => isset($_POST['customer_id']) ? get_customer_name_by_id(intval($_POST['customer_id'])) : '', // Sigorta Ettiren (Müşteri adı)
        'insured_list' => isset($_POST['insured_party_list']) ? sanitize_textarea_field($_POST['insured_party_list']) : '', // Sigortalılar listesi
        'representative_id' => $current_user_rep_id // Otomatik olarak mevcut temsilci
    );
    
    // Müşteri temsilcisi seçimi (sadece Patron ve Müdür için)
    if (is_patron_or_manager() && isset($_POST['customer_representative_id']) && !empty($_POST['customer_representative_id'])) {
        $policy_data['representative_id'] = intval($_POST['customer_representative_id']);
    }
    
    // Plaka bilgisi kontrolü (Kasko/Trafik için)
    if (in_array(strtolower($policy_data['policy_type']), ['kasko', 'trafik']) && isset($_POST['plate_number'])) {
        $policy_data['plate_number'] = sanitize_text_field($_POST['plate_number']);
    }

    // İptal bilgilerini ekle
    if (isset($_POST['is_cancelled']) && $_POST['is_cancelled'] === 'yes') {
        $policy_data['cancellation_date'] = sanitize_text_field($_POST['cancellation_date']);
        $policy_data['refunded_amount'] = !empty($_POST['refunded_amount']) ? floatval($_POST['refunded_amount']) : 0;
        $policy_data['cancellation_reason'] = sanitize_text_field($_POST['cancellation_reason']);
        $policy_data['status'] = 'Zeyil'; // İptal edilen poliçeyi Zeyil olarak işaretle
    }

    if (!empty($_FILES['document']['name'])) {
        $upload_dir = wp_upload_dir();
        $policy_upload_dir = $upload_dir['basedir'] . '/insurance-crm-docs';
        
        if (!file_exists($policy_upload_dir)) {
            wp_mkdir_p($policy_upload_dir);
        }
        
        $allowed_file_types = array('pdf', 'doc', 'docx');
        $file_ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        
        if (in_array($file_ext, $allowed_file_types)) {
            $file_name = 'policy-' . time() . '-' . sanitize_file_name($_FILES['document']['name']);
            $file_path = $policy_upload_dir . '/' . $file_name;
            
            if (move_uploaded_file($_FILES['document']['tmp_name'], $file_path)) {
                $policy_data['document_path'] = $upload_dir['baseurl'] . '/insurance-crm-docs/' . $file_name;
            } else {
                $upload_error = true;
            }
        } else {
            $file_type_error = true;
        }
    }
    
    // Teklif dosyası kullanılıyorsa
    if (isset($_POST['use_offer_file']) && $_POST['use_offer_file'] == 'yes' && !empty($_POST['offer_file_path'])) {
        $policy_data['document_path'] = $_POST['offer_file_path'];
    }

    $table_name = $wpdb->prefix . 'insurance_crm_policies';

    if ($editing || $cancelling) {
        // Yetki kontrolü - Patron ve Müdür için her zaman izin ver
        $can_edit = true;
        $user_role = get_current_user_role();
        
        // Patron/Müdür DEĞİLSE, yetki kontrolü ve kendi poliçesi mi kontrolü yap
        if ($user_role != 1 && $user_role != 2) {
            $policy_check = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $policy_id));
            
            // Kendi poliçesi değilse yetkisi yoksa işlemi reddet
            if ($policy_check && $policy_check->representative_id != $current_user_rep_id && !has_policy_permissions()) {
                $can_edit = false;
                $message = 'Bu poliçeyi düzenleme/iptal etme yetkiniz yok.';
                $message_type = 'error';
            }
        }

        if ($can_edit) {
            $policy_data['updated_at'] = current_time('mysql');
            $result = $wpdb->update($table_name, $policy_data, ['id' => $policy_id]);

            if ($result !== false) {
                $action_text = isset($_POST['is_cancelled']) && $_POST['is_cancelled'] === 'yes' ? 'iptal edildi' : 'güncellendi';
                $message = 'Poliçe başarıyla ' . $action_text . '.';
                $message_type = 'success';
                $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
                $redirect_url = build_redirect_url_with_filters(['view' => 'policies', 'action' => 'view', 'id' => $policy_id]);
                wp_redirect($redirect_url);
                exit;
            } else {
                $message = 'Poliçe işlenirken bir hata oluştu.';
                $message_type = 'error';
            }
        }
    } elseif ($renewing) {
        // Yenileme işlemi için eski poliçenin bilgilerini çek
        $old_policy = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $policy_id));
        
        if ($old_policy) {
            // Eski poliçeyi pasif olarak işaretle
            $wpdb->update($table_name, ['status' => 'pasif'], ['id' => $policy_id]);
            
            // Yeni poliçeye policy_category olarak 'Yenileme' ekle
            $policy_data['policy_category'] = 'Yenileme';
            $policy_data['created_at'] = current_time('mysql');
            $policy_data['updated_at'] = current_time('mysql');
            
            // İptal bilgileri varsa temizle
            $policy_data['cancellation_date'] = null;
            $policy_data['refunded_amount'] = null;
            $policy_data['cancellation_reason'] = null;
            
            $result = $wpdb->insert($table_name, $policy_data);
            
            if ($result) {
                $new_policy_id = $wpdb->insert_id;
                $message = 'Poliçe başarıyla yenilendi. Yeni poliçe numarası: ' . $policy_data['policy_number'];
                $message_type = 'success';
                $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
                $redirect_url = build_redirect_url_with_filters(['view' => 'policies', 'action' => 'view', 'id' => $new_policy_id]);
                wp_redirect($redirect_url);
                exit;
            } else {
                $message = 'Poliçe yenilenirken bir hata oluştu.';
                $message_type = 'error';
            }
        } else {
            $message = 'Yenilenecek poliçe bulunamadı.';
            $message_type = 'error';
        }
    } else {
        // Yeni poliçe ekleme
        $policy_data['created_at'] = current_time('mysql');
        $policy_data['updated_at'] = current_time('mysql');
        
        $result = $wpdb->insert($table_name, $policy_data);
        
        if ($result) {
            $new_policy_id = $wpdb->insert_id;
            
            // If this policy was created from an offer, update the customer's offer status
            if (!empty($customer_search_value) && (!empty($offer_type) || !empty($offer_amount))) {
                $customer_name_parts = explode(' ', trim($customer_search_value));
                if (count($customer_name_parts) >= 2) {
                    $first_name = $customer_name_parts[0];
                    $last_name = implode(' ', array_slice($customer_name_parts, 1));
                    
                    // Find customer by name and update offer status
                    $customer_for_offer_update = $wpdb->get_row($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}insurance_crm_customers 
                        WHERE first_name = %s AND last_name = %s AND has_offer = 1 
                        LIMIT 1",
                        $first_name, $last_name
                    ));
                    
                    if ($customer_for_offer_update) {
                        $wpdb->update(
                            $wpdb->prefix . 'insurance_crm_customers',
                            array(
                                'has_offer' => 2, // 2 = Completed/Converted to Policy
                                'offer_notes' => 'Teklif poliçeye dönüştürüldü. Poliçe ID: ' . $new_policy_id
                            ),
                            array('id' => $customer_for_offer_update->id)
                        );
                        error_log("Offer status updated for customer ID: " . $customer_for_offer_update->id . " to completed");
                    }
                }
            }
            
            $message = 'Poliçe başarıyla eklendi.';
            $message_type = 'success';
            $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
            $redirect_url = build_redirect_url_with_filters(['view' => 'policies', 'action' => 'view', 'id' => $new_policy_id]);
            wp_redirect($redirect_url);
            exit;
        } else {
            $message = 'Poliçe eklenirken bir hata oluştu.';
            $message_type = 'error';
        }
    }
}

// Müşterileri getir
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$customers = $wpdb->get_results("SELECT * FROM $customers_table ORDER BY first_name ASC");

// Düzenlenen veya iptal edilen poliçenin bilgilerini getir
$policy = null;
$customer = null; // Müşteri bilgisini sadece gerekli durumlarda set et
if ($policy_id > 0) {
    $policies_table = $wpdb->prefix . 'insurance_crm_policies';
    $policy = $wpdb->get_row($wpdb->prepare("SELECT * FROM $policies_table WHERE id = %d", $policy_id));
    
    if (!$policy) {
        echo '<div class="ab-notice ab-error">Poliçe bulunamadı.</div>';
        return;
    }
    
    if ($renewing) {
        // Yenileme işleminde yeni poliçe için bilgileri varsayılan olarak ayarla
        $policy->policy_number = '';  // Yeni poliçe numarası boş olmalı
        $policy->status = 'aktif';    // Yeni poliçe aktif olmalı
        $policy->start_date = date('Y-m-d', strtotime($policy->end_date . ' +1 day')); // Bitişten sonraki gün
        $policy->end_date = date('Y-m-d', strtotime($policy->end_date . ' +1 year')); // Bir yıl sonrası
        $policy->cancellation_date = null;
        $policy->refunded_amount = null;
        $policy->cancellation_reason = null;
    }
    
    // Poliçe sahibi müşteriyi getir (sadece düzenleme/iptal/yenileme modunda)
    if ($editing || $cancelling || $renewing) {
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customers_table WHERE id = %d", $policy->customer_id));
    }
}

// Varsayılan poliçe türleri
$settings = get_option('insurance_crm_settings', []);
$policy_types = $settings['default_policy_types'] ?? ['Kasko', 'Trafik', 'Konut', 'DASK', 'Sağlık', 'Hayat', 'Seyahat', 'Diğer'];

// Sigorta şirketleri
$insurance_companies = array_unique($settings['insurance_companies'] ?? ['Sompo']);
sort($insurance_companies);

// Poliçe kategorileri
$policy_categories = ['Yeni İş', 'Yenileme', 'Zeyil', 'Diğer'];

// Ödeme seçenekleri - ayarlardan al
$payment_options = $settings['payment_options'] ?? ['Peşin', '3 Taksit', '6 Taksit', '8 Taksit', '9 Taksit', '12 Taksit', 'Ödenmedi', 'Nakit', 'Kredi Kartı', 'Havale', 'Diğer'];

// Poliçe durumları
$policy_statuses = ['aktif', 'pasif', 'Zeyil'];

// Müşteri temsilcilerini getir (Patron ve Müdür için)
$customer_representatives = [];
if (is_patron_or_manager()) {
    $representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
    $customer_representatives = $wpdb->get_results("
        SELECT r.id, u.display_name, r.title 
        FROM $representatives_table r
        LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
        WHERE r.status = 'active'
        ORDER BY u.display_name
    ");
}

// Tüm müşterileri getir (eski select için gerekli - backward compatibility)
$customers = $wpdb->get_results("SELECT id, first_name, last_name, tc_identity FROM $customers_table WHERE status = 'aktif' ORDER BY first_name, last_name");

// Form action URL'sini hazırla
$form_action = sanitize_url($_SERVER['REQUEST_URI']);

// Başlık ve açıklama
$title = '';
$description = '';

if ($editing) {
    $title = 'Poliçe Düzenle';
    $description = 'Mevcut poliçe bilgilerini düzenleyebilirsiniz.';
} elseif ($renewing) {
    $title = 'Poliçe Yenile';
    $description = 'Mevcut poliçenin yeni versiyonunu oluşturabilirsiniz.';
} elseif ($cancelling) {
    $title = 'Poliçe İptal';
    $description = 'Poliçeyi iptal etmek için aşağıdaki bilgileri doldurunuz.';
} elseif ($create_from_offer) {
    $title = 'Tekliften Poliçe Oluştur';
    $description = 'Teklif bilgilerinden yeni bir poliçe oluşturabilirsiniz.';
} else {
    $title = 'Yeni Poliçe Ekle';
    $description = 'Yeni bir poliçe kaydı oluşturmak için aşağıdaki formu doldurunuz.';
}

// Müşteri bilgilerini getir (tekliften oluşturma durumunda)
if ($create_from_offer && $selected_customer_id > 0) {
    $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customers_table WHERE id = %d", $selected_customer_id));
}

// Temsilcinin müşteri olup olmadığını kontrol et
$is_customer = false;
if ($current_user_rep_id) {
    // Temsilcinin bağlı olduğu kullanıcı bilgisini getir
    $representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
    $rep = $wpdb->get_row($wpdb->prepare("SELECT * FROM $representatives_table WHERE id = %d", $current_user_rep_id));
    
    if ($rep) {
        $is_customer = true;
    }
}

// Kullanıcının rolünü ve izinlerini logla - hata ayıklama için
$user_role = get_current_user_role();
$has_permissions = has_policy_permissions();
error_log("Kullanıcı Rol: $user_role, Poliçe İzinleri: " . ($has_permissions ? 'Var' : 'Yok'));
?>

<style>
/* Ana Sayfa Stilleri - Customers form ile aynı tasarım */
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
    background-color: #f5f7fa;
    margin: 0;
    padding: 0;
    line-height: 1.6;
}

.ab-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.ab-form-wrapper {
    background-color: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
    overflow: hidden;
    border: 1px solid #e0e0e0;
}

.ab-form-header {
    background: linear-gradient(135deg, <?php echo $corporate_color; ?> 0%, <?php echo adjust_color_opacity($corporate_color, 0.9); ?> 100%);
    color: white;
    padding: 20px 25px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.ab-form-header h2 {
    margin: 0;
    font-size: 24px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.ab-form-header h2 i {
    font-size: 20px;
}

    .policy-form-container {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        padding: 20px 30px;
        max-width: 1200px;
        margin: 20px auto;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    
    .ab-form-actions {
        margin-top: 30px;
        padding: 20px 25px;
        background-color: #f8f9fa;
        border-top: 1px solid #e0e0e0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .ab-form-actions-left, .ab-form-actions-right {
        display: flex;
        gap: 15px;
        align-items: center;
    }
    
    .ab-btn {
        padding: 12px 24px;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        border: none;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s ease;
    }
    
    .ab-btn-primary {
        background-color: <?php echo $corporate_color; ?>;
        color: white;
    }
    
    .ab-btn-primary:hover {
        background-color: <?php echo adjust_color_opacity($corporate_color, 0.9); ?>;
        transform: translateY(-1px);
    }
    
    .ab-btn-secondary {
        background-color: #f8f9fa;
        color: #333;
        border: 1px solid #ddd;
    }
    
    .ab-btn-secondary:hover {
        background-color: #e9ecef;
    }
    
    .ab-btn-danger {
        background-color: #f44336;
        color: white;
    }
    
    .ab-btn-danger:hover {
        background-color: #d32f2f;
    }
    
    .ab-notice {
        padding: 12px 15px;
        margin-bottom: 15px;
        border-radius: 6px;
        border-left: 4px solid;
        font-size: 14px;
        line-height: 1.4;
    }
    
    .ab-success {
        background-color: <?php echo adjust_color_opacity($corporate_color, 0.1); ?>;
        border-left-color: <?php echo $corporate_color; ?>;
        color: <?php echo adjust_color_opacity($corporate_color, 0.8); ?>;
    }
    
    .ab-error {
        background-color: #fff5f5;
        border-left-color: #e53e3e;
        color: #c53030;
    }
    
    .ab-warning {
        background-color: #fffde7;
        border-left-color: #ffc107;
        color: #b45309;
    }
    
    .ab-form-content {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    
    .ab-section-wrapper {
        background-color: #fff;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        border: 1px solid #e0e0e0;
    }
    
    .ab-section-header {
        background-color: #f8f9fa;
        padding: 15px 20px;
        border-bottom: 1px solid #e0e0e0;
    }
    
    .ab-section-header h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
        color: #333;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .ab-section-header h3 i {
        color: <?php echo $corporate_color; ?>;
    }
    
    .ab-section-content {
        padding: 20px;
    }
    
    .ab-form-row {
        display: flex;
        flex-wrap: wrap;
        margin-bottom: 12px;
        gap: 12px;
    }
    
    .ab-form-row:last-child {
        margin-bottom: 0;
    }
    
    .ab-form-group {
        flex: 1;
        min-width: 200px;
        position: relative;
    }
    
    .ab-form-group.ab-full-width {
        flex-basis: 100%;
        width: 100%;
    }
    
    .ab-form-group label {
        display: block;
        margin-bottom: 6px;
        font-weight: 500;
        font-size: 14px;
        color: #333;
    }
    
    .ab-input, .ab-select, .ab-textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 6px;
        font-size: 14px;
        background-color: white;
        transition: all 0.2s ease;
        box-sizing: border-box;
    }
    
    .ab-input:focus, .ab-select:focus, .ab-textarea:focus {
        border-color: <?php echo $corporate_color; ?>;
        outline: none;
        box-shadow: 0 0 0 3px <?php echo adjust_color_opacity($corporate_color, 0.1); ?>;
    }
    
    .ab-textarea {
        min-height: 100px;
        resize: vertical;
    }
    
    .ab-form-help {
        font-size: 12px;
        color: #666;
        margin-top: 5px;
        line-height: 1.4;
    }
    
    .ab-checkbox-label {
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        padding: 8px 0;
    }
    
    .ab-checkbox-label input[type="checkbox"] {
        width: 16px;
        height: 16px;
        margin: 0;
    }
    
    /* Required field asterisk */
    .required {
        color: #f44336;
        font-weight: bold;
    }
    
    /* Search and Selection Styles */
    .customer-search-container {
        position: relative;
        z-index: 2000; /* Üst seviye z-index */
    }
    
    .search-results {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #ddd;
        border-top: none;
        border-radius: 0 0 8px 8px;
        max-height: 400px;
        overflow-y: auto;
        z-index: 10000; /* En yüksek z-index */
        display: none;
        min-width: 500px;
        width: 100%;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        margin-top: -1px; /* Input ile arasındaki boşluğu kapat */
    }
    
    .search-result-item {
        padding: 15px;
        cursor: pointer;
        border-bottom: 1px solid #f0f0f0;
        font-size: 14px;
        line-height: 1.4;
        transition: background-color 0.2s ease;
    }
    
    .search-result-item:hover, .search-result-item.search-result-hover {
        background-color: #f0f8ff;
        border-left: 3px solid <?php echo $corporate_color; ?>;
    }
    
    .search-result-item:last-child {
        border-bottom: none;
    }
    
    .customer-details {
        background-color: #f8f9fa;
        border-radius: 6px;
        padding: 12px;
        margin-top: 10px;
    }
    
    .insured-selection {
        margin-top: 12px;
    }
    
    .family-members {
        margin-top: 10px;
    }
    
    .family-member-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px;
        background-color: #f9f9f9;
        border-radius: 6px;
        margin-bottom: 8px;
        font-size: 13px;
    }
    
    .family-member-item input[type="checkbox"] {
        width: 16px;
        height: 16px;
    }
    
    .family-member-details {
        font-size: 13px;
    }
    
    /* Loading Spinner */
    .ab-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(0,0,0,.1);
        border-radius: 50%;
        border-top-color: <?php echo $corporate_color; ?>;
        animation: spin 1s ease-in-out infinite;
        margin-left: 10px;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    /* Keep some old styles for backward compatibility */
    .policy-form-container {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        padding: 20px 30px;
        max-width: 1200px;
        margin: 20px auto;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    
    .policy-form-header {
        margin-bottom: 30px;
        border-bottom: 1px solid #e0e0e0;
        padding-bottom: 15px;
    }
    
    .policy-form-header h2 {
        font-size: 24px;
        color: #333;
        margin-bottom: 5px;
        font-weight: 600;
    }
    
    .policy-form-header p {
        font-size: 14px;
        color: #666;
        margin: 0;
    }
    
    .form-actions {
        margin-top: 30px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    
    .btn {
        padding: 10px 20px;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        border: none;
        transition: all 0.2s ease;
    }
    
    .btn-primary {
        background-color: <?php echo $corporate_color; ?>;
        color: white;
    }
    
    .btn-primary:hover {
        background-color: <?php echo adjust_color_opacity($corporate_color, 0.9); ?>;
    }
    
    .btn-secondary {
        background-color: #f5f5f5;
        color: #333;
        border: 1px solid #ddd;
    }
    
    .btn-secondary:hover {
        background-color: #e0e0e0;
    }
    
    .btn-danger {
        background-color: #f44336;
        color: white;
    }
    
    .btn-danger:hover {
        background-color: #d32f2f;
    }
    
    .notification {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 4px;
    }
    
    .notification.success {
        background-color: <?php echo adjust_color_opacity($corporate_color, 0.1); ?>;
        border-left: 4px solid <?php echo $corporate_color; ?>;
        color: <?php echo adjust_color_opacity($corporate_color, 0.8); ?>;
    }
    
    .notification.error {
        background-color: #ffebee;
        border-left: 4px solid #f44336;
        color: #c62828;
    }
    
    .notification.warning {
        background-color: #fff3e0;
        border-left: 4px solid #ff9800;
        color: #e65100;
    }
    
    /* Responsive Design */
    @media (max-width: 768px) {
        .ab-form-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }
        
        .ab-form-row {
            flex-direction: column;
            gap: 15px;
        }
        
        .ab-form-group {
            width: 100%;
        }
        
        .ab-form-actions {
            flex-direction: column;
            gap: 15px;
        }
        
        .ab-form-actions-left, .ab-form-actions-right {
            width: 100%;
        }
        
        .ab-form-actions-left .ab-btn, .ab-form-actions-right .ab-btn {
            width: 100%;
        }
        
        .policy-form-container {
            padding: 15px;
        }
        
        .form-actions {
            flex-direction: column;
        }
        
        .btn {
            width: 100%;
        }
    }
    
    /* Special styles for interactive customer selection */
    .customer-selection-step {
        background-color: #e8f5e9;
        border: 1px solid #c8e6c9;
    }
    
    .customer-selection-step.completed {
        background-color: #f1f8e9;
        border: 1px solid #aed581;
    }
    
    .insured-question {
        background-color: #fff3e0;
        border: 1px solid #ffcc02;
        padding: 12px;
        border-radius: 6px;
        margin-top: 10px;
    }
    
    .insured-question h4 {
        margin: 0 0 10px 0;
        color: #f57c00;
    }
    
    .policy-details-step {
        display: none;
    }
    
    .policy-details-step.active {
        display: block;
    }
    
    /* Force show policy details in edit/renewal modes */
    body.edit-mode .policy-details-step,
    body.renewal-mode .policy-details-step {
        display: block !important;
    }
    
    .form-textarea {
        min-height: 100px;
    }
    
    .form-input:focus, .form-select:focus, .form-textarea:focus {
        border-color: #1976d2;
        outline: none;
        box-shadow: 0 0 0 2px rgba(25, 118, 210, 0.2);
    }
    
    .form-actions {
        margin-top: 30px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    
    .btn {
        padding: 10px 20px;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        border: none;
        transition: all 0.2s ease;
    }
    
    .btn-primary {
        background-color: #1976d2;
        color: white;
    }
    
    .btn-primary:hover {
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
    
    .btn-danger {
        background-color: #f44336;
        color: white;
    }
    
    .btn-danger:hover {
        background-color: #d32f2f;
    }
    
    .checkbox-row {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 10px;
    }
    
    .checkbox-row input[type="checkbox"] {
        width: 16px;
        height: 16px;
    }
    
    .notification {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 4px;
    }
    
    .notification.success {
        background-color: <?php echo adjust_color_opacity($corporate_color, 0.1); ?>;
        border-left: 4px solid <?php echo $corporate_color; ?>;
        color: <?php echo adjust_color_opacity($corporate_color, 0.8); ?>;
    }
    
    .notification.error {
        background-color: #ffebee;
        border-left: 4px solid #f44336;
        color: #c62828;
    }
    
    .notification.warning {
        background-color: #fff3e0;
        border-left: 4px solid #ff9800;
        color: #e65100;
    }
    
    /* Cancellation Section Styles */
    .cancellation-section {
        background-color: #ffebee;
        border: 1px solid #ffcdd2;
    }
    
    .cancellation-section h3 {
        color: #c62828;
    }
    
    .cancellation-section .form-row label {
        color: #c62828;
    }
    
    /* New Status Note Section */
    .status-note-row {
        margin-top: 15px;
    }

    .status-badge {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 50px;
        font-size: 12px;
        font-weight: 600;
        margin-left: 10px;
    }
    
    .status-aktif {
        background-color: #e8f5e9;
        color: #2e7d32;
    }
    
    .status-pasif {
        background-color: #f5f5f5;
        color: #757575;
    }
    
    .status-iptal {
        background-color: #ffebee;
        color: #c62828;
    }
    
    .badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        margin-left: 8px;
    }
    
    .badge-primary {
        background-color: #e3f2fd;
        color: #1976d2;
    }
    
    .badge-success {
        background-color: #e8f5e9;
        color: #2e7d32;
    }
    
    .badge-warning {
        background-color: #fff3e0;
        color: #f57c00;
    }

    /* Network Field */
    .network-field {
        margin-top: 15px;
    }
    
    /* Payment Info Field */
    .payment-info-field {
        margin-top: 15px;
    }
    
    /* Document Upload */
    .file-upload-wrapper {
        position: relative;
    }
    
    .section-description {
        color: #666;
        font-size: 14px;
        margin-bottom: 15px;
        line-height: 1.4;
        background: #f8f9fa;
        padding: 10px 15px;
        border-radius: 4px;
        border-left: 3px solid #007cba;
    }
    
    .file-upload-wrapper input[type=file] {
        opacity: 0;
        position: absolute;
        top: 0;
        right: 0;
        bottom: 0;
        left: 0;
        z-index: 99;
        height: 40px;
        cursor: pointer;
    }
    
    .file-upload-input {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
        background-color: white;
    }
    
    .file-upload-wrapper:hover .file-upload-input {
        border-color: #1976d2;
    }
    
    /* Responsive designs */
    @media (max-width: 768px) {
        .policy-form-container {
            padding: 15px;
        }
        
        .policy-form-content {
            grid-template-columns: 1fr;
        }
        
        .form-actions {
            flex-direction: column;
        }
        
        .btn {
            width: 100%;
        }
    }

    /* Tam genişlik bölümü için CSS */
    .full-width-section {
        grid-column: 1 / -1; /* Tüm grid sütunlarını kapla */
        margin-top: 20px;
    }

    /* Dosya upload alanını daha kullanışlı hale getir */
    .full-width-section .file-upload-wrapper {
        max-width: 600px; /* Dosya seçme alanını kontrollü bir genişlikte tutar */
    }

    /* Müşteri Bilgileri Yükleniyor Spinner */
    .customer-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(0,0,0,.1);
        border-radius: 50%;
        border-top-color: #1976d2;
        animation: spin 1s ease-in-out infinite;
        margin-left: 10px;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    /* Plaka alanı için özel stil */
    .plate-input {
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 1px;
    }

    /* Seçilmemiş alanlar için belirginlik */
    select:invalid, .form-select option:first-child {
        color: #757575;
    }
    
    /* Rol Bilgisi Banner */
    .role-info-banner {
        background-color: #e3f2fd;
        color: #0d47a1;
        padding: 8px 12px;
        margin-bottom: 15px;
        border-radius: 4px;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
        border-left: 4px solid #1976d2;
    }
    
    .role-info-banner.patron {
        background-color: #fff8e1;
        color: #ff6f00;
        border-left: 4px solid #ff9800;
    }
    
    .role-info-banner.mudur {
        background-color: <?php echo adjust_color_opacity($corporate_color, 0.1); ?>;
        color: <?php echo adjust_color_opacity($corporate_color, 0.8); ?>;
        border-left: 4px solid <?php echo $corporate_color; ?>;
    }
    
    .role-info-banner i {
        font-size: 18px;
    }
</style>

<?php if (isset($message)): ?>
<div class="ab-notice <?php echo $message_type === 'success' ? 'ab-success' : ($message_type === 'error' ? 'ab-error' : 'ab-warning'); ?>">
    <?php echo $message; ?>
</div>
<?php endif; ?>

<?php
// Rol bilgisi banner'ı göster
$user_role = get_current_user_role();
if ($user_role == 1 || $user_role == 2):
?>
<div class="role-info-banner <?php echo $user_role == 1 ? 'patron' : 'mudur'; ?>">
    <i class="fas fa-<?php echo $user_role == 1 ? 'crown' : 'user-tie'; ?>"></i>
    <div>
        <strong>
            <?php echo $user_role == 1 ? 'Patron' : 'Müdür'; ?> olarak yetkilendirildiniz.
        </strong>
        <span>Tüm poliçeleri düzenleme ve iptal etme yetkisine sahipsiniz.</span>
    </div>
</div>
<?php endif; ?>

<div class="ab-container">
    <div class="ab-form-wrapper">
        <div class="ab-form-header">
            <h2><i class="fas fa-file-contract"></i> <?php echo $title; ?></h2>
        </div>
        
        <form action="<?php echo $form_action; ?>" method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('save_policy', 'policy_nonce'); ?>
            <input type="hidden" name="save_policy" value="1">
            
            <div class="ab-form-content">
                
                <?php if ($cancelling || (isset($policy) && $policy->cancellation_date)): ?>
                <!-- İPTAL BİLGİLERİ BÖLÜMÜ -->
                <div class="ab-section-wrapper">
                    <div class="ab-section-header">
                        <h3><i class="fas fa-times-circle"></i> İptal Bilgileri</h3>
                    </div>
                    <div class="ab-section-content">
                        <input type="hidden" name="is_cancelled" value="yes">
                        
                        <div class="ab-form-row">
                            <div class="ab-form-group">
                                <label for="cancellation_date">İptal Tarihi <span class="required">*</span></label>
                                <input type="date" name="cancellation_date" id="cancellation_date" class="ab-input" 
                                       value="<?php echo isset($policy) && $policy->cancellation_date ? esc_attr($policy->cancellation_date) : date('Y-m-d'); ?>" 
                                       required>
                            </div>
                            
                            <div class="ab-form-group">
                                <label for="refunded_amount">İade Tutarı (₺)</label>
                                <input type="number" name="refunded_amount" id="refunded_amount" class="ab-input" 
                                       value="<?php echo isset($policy) && $policy->refunded_amount ? esc_attr($policy->refunded_amount) : ''; ?>" 
                                       step="0.01" min="0" placeholder="Varsa iade tutarı">
                            </div>
                        </div>
                        
                        <div class="ab-form-row">
                            <div class="ab-form-group">
                                <label for="cancellation_reason">İptal Nedeni <span class="required">*</span></label>
                                <select name="cancellation_reason" id="cancellation_reason" class="ab-select" required>
                                    <option value="">Seçiniz...</option>
                                    <?php foreach ($cancellation_reasons as $reason): ?>
                                    <option value="<?php echo esc_attr($reason); ?>" <?php if (isset($policy) && $policy->cancellation_reason === $reason) echo 'selected'; ?>>
                                        <?php echo esc_html($reason); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="ab-form-row">
                            <div class="ab-form-group ab-full-width">
                                <div class="ab-notice ab-warning">
                                    <i class="fas fa-exclamation-triangle"></i> 
                                    Dikkat: İptal işlemi geri alınamaz. İptal edilen poliçeler sistemde kalacak ancak Zeyil olarak işaretlenecektir.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- SİGORTA ETTİREN SEÇİMİ BÖLÜMÜ -->
                <div class="ab-section-wrapper customer-selection-step">
                    <div class="ab-section-header">
                        <h3><i class="fas fa-user-check"></i> Sigorta Ettiren Seçimi</h3>
                    </div>
                    <div class="ab-section-content">
                        <div class="ab-form-row">
                            <div class="ab-form-group ab-full-width">
                                <label for="customer_search">Sigorta Ettiren Ara <span class="required">*</span></label>
                                <div class="customer-search-container">
                                    <input type="text" id="customer_search" class="ab-input" 
                                           placeholder="Ad soyad, TC kimlik no, şirket adı veya vergi no ile arayın..."
                                           value="<?php 
                                           // URL'den gelen customer_search parametresi öncelik
                                           if (!empty($customer_search_value)) {
                                               echo esc_attr($customer_search_value);
                                           }
                                           // Sadece düzenleme, iptal, yenileme veya tekliften oluşturma modlarında müşteri adını göster
                                           elseif (($editing || $cancelling || $renewing || $create_from_offer) && isset($customer) && $customer) {
                                               echo esc_attr($customer->first_name . ' ' . $customer->last_name);
                                           } else {
                                               echo ''; // Yeni poliçe modunda boş bırak
                                           }
                                           ?>"
                                           <?php echo ($editing || $cancelling || $renewing || $create_from_offer) ? 'readonly' : ''; ?>>
                                    <div id="search_results" class="search-results"></div>
                                </div>
                                <input type="hidden" name="customer_id" id="selected_customer_id" value="<?php echo isset($policy) ? $policy->customer_id : ($selected_customer_id ?: ''); ?>">
                                <?php if ($editing || $cancelling || $renewing || $create_from_offer): ?>
                                    <div class="ab-form-help">Düzenleme modunda sigorta ettiren değiştirilemez.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div id="selected_customer_details" class="customer-details" style="display: block;">
                            <h4><i class="fas fa-user"></i> Seçilen Müşteri Bilgileri</h4>
                            <div class="ab-form-row">
                                <div class="ab-form-group">
                                    <label>Ad Soyad</label>
                                    <input type="text" class="ab-input" id="selected_customer_name" value="<?php 
                                    if (($editing || $cancelling || $renewing || $create_from_offer) && isset($customer) && $customer) {
                                        echo esc_attr($customer->first_name . ' ' . $customer->last_name);
                                    } else {
                                        echo '';
                                    }
                                    ?>" readonly>
                                </div>
                                <div class="ab-form-group">
                                    <label>TC Kimlik No</label>
                                    <input type="text" class="ab-input" id="selected_customer_tc" value="<?php 
                                    if (($editing || $cancelling || $renewing || $create_from_offer) && isset($customer) && $customer) {
                                        echo esc_attr(($customer->tc_identity ?? '') ?: 'Belirtilmemiş');
                                    } else {
                                        echo '';
                                    }
                                    ?>" readonly>
                                </div>
                            </div>
                            <div class="ab-form-row">
                                <div class="ab-form-group">
                                    <label>Telefon</label>
                                    <input type="text" class="ab-input" id="selected_customer_phone" value="<?php 
                                    if (($editing || $cancelling || $renewing || $create_from_offer) && isset($customer) && $customer) {
                                        echo esc_attr(($customer->phone ?? '') ?: 'Belirtilmemiş');
                                    } else {
                                        echo '';
                                    }
                                    ?>" readonly>
                                </div>
                                <div class="ab-form-group">
                                    <label>E-posta</label>
                                    <input type="text" class="ab-input" id="selected_customer_email" value="<?php 
                                    if (($editing || $cancelling || $renewing || $create_from_offer) && isset($customer) && $customer) {
                                        echo esc_attr(($customer->email ?? '') ?: 'Belirtilmemiş');
                                    } else {
                                        echo '';
                                    }
                                    ?>" readonly>
                                </div>
                            </div>
                        </div>
                        
                        <!-- SİGORTALI BELİRLEME SORUSU -->
                        <div id="insured_question" class="insured-question" style="display: <?php echo ($cancelling || $editing || $renewing) ? 'none' : 'block'; ?>;">
                            <h4><i class="fas fa-question-circle"></i> Sigorta Ettiren ile Sigortalı aynı kişi mi?</h4>
                            <div class="ab-form-row">
                                <div class="ab-form-group">
                                    <label class="ab-checkbox-label">
                                        <input type="radio" name="same_as_insured" value="yes" id="same_person_yes" <?php 
                                        // Varsayılan olarak "Evet" seçili gelsin (yeni poliçe modunda)
                                        if (!$editing && !$renewing && !$cancelling && !$create_from_offer) {
                                            echo 'checked';
                                        } else if ($editing && isset($policy) && $policy && $policy->insured_party && trim($policy->insured_party) !== '') {
                                            // Sadece düzenleme modunda pre-select yap
                                            $customer_name = isset($customer) ? trim($customer->first_name . ' ' . $customer->last_name) : '';
                                            $insured_party = trim($policy->insured_party);
                                            if ($customer_name === $insured_party || trim($insured_party) === '') {
                                                echo 'checked';
                                            }
                                        }
                                        ?>>
                                        <span>Evet, aynı kişi</span>
                                    </label>
                                </div>
                                <div class="ab-form-group">
                                    <label class="ab-checkbox-label">
                                        <input type="radio" name="same_as_insured" value="no" id="same_person_no" <?php 
                                        if ($editing && isset($policy) && $policy && $policy->insured_party && trim($policy->insured_party) !== '') {
                                            // Sadece düzenleme modunda pre-select yap
                                            $customer_name = isset($customer) ? trim($customer->first_name . ' ' . $customer->last_name) : '';
                                            $insured_party = trim($policy->insured_party);
                                            if ($customer_name !== $insured_party && trim($insured_party) !== '') {
                                                echo 'checked';
                                            }
                                        }
                                        ?>>
                                        <span>Hayır, farklı kişi(ler)</span>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- AİLE ÜYELERİ SEÇİMİ -->
                            <div id="family_members_selection" class="family-members" style="display: none;">
                                <h5 style="font-size: 14px;"><i class="fas fa-users"></i> Aile Üyelerinden Sigortalıları Seçin:</h5>
                                <div id="family_members_list">
                                    <!-- AJAX ile doldurulacak -->
                                </div>
                                <div class="ab-form-row" style="margin-top: 20px;">
                                    <button type="button" id="continue_to_policy" class="ab-btn ab-btn-primary" style="display: none;">
                                        <i class="fas fa-arrow-right"></i> Devam Et - Poliçe Bilgilerini Doldur
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Seçilen sigortalılar için gizli alan -->
                            <input type="hidden" name="insured_party" id="insured_party_hidden" value="<?php echo isset($policy) ? esc_attr($policy->insured_party) : ''; ?>">
                            <input type="hidden" name="insured_party_list" id="insured_party_list_hidden" value="<?php echo isset($policy) ? esc_attr($policy->insured_list) : ''; ?>">
                        </div>
                    </div>
                </div>
                
                <!-- POLİÇE DETAYLARI BÖLÜMÜ -->
                <div class="ab-section-wrapper policy-details-step">
                    <div class="ab-section-header">
                        <h3><i class="fas fa-file-contract"></i> Poliçe Detayları</h3>
                    </div>
                    <div class="ab-section-content">
                        <div class="ab-form-row">
                            <div class="ab-form-group">
                                <label for="policy_number">Poliçe Numarası <span class="required">*</span></label>
                                <input type="text" name="policy_number" id="policy_number" class="ab-input" 
                                       value="<?php echo isset($policy) ? esc_attr($policy->policy_number) : ''; ?>" 
                                       required>
                            </div>
                            
                            <div class="ab-form-group">
                                <label for="policy_type">Poliçe Türü <span class="required">*</span></label>
                                <select name="policy_type" id="policy_type" class="ab-select" required>
                                    <option value="">Seçiniz...</option>
                                    <?php foreach ($policy_types as $type): ?>
                                    <option value="<?php echo esc_attr($type); ?>" <?php if (isset($policy)) selected($policy->policy_type, $type); else if ($offer_type === $type) echo 'selected'; ?>>
                                        <?php echo esc_html($type); ?>
                                    </option>
                                    <?php endforeach; ?>
                                    <option value="Diğer">Diğer</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Plaka alanı (Kasko/Trafik için) -->
                        <div class="ab-form-row" id="plate_field" style="display: none;">
                            <div class="ab-form-group ab-full-width">
                                <label for="plate_number">Araç Plakası <span class="required">*</span></label>
                                <input type="text" name="plate_number" id="plate_number" class="ab-input plate-input" 
                                       value="<?php echo isset($policy) ? esc_attr($policy->plate_number) : ''; ?>"
                                       placeholder="34ABC123" maxlength="10">
                            </div>
                        </div>
                        
                        <div class="ab-form-row">
                            <div class="ab-form-group">
                                <label for="policy_category">Poliçe Kategorisi</label>
                                <select name="policy_category" id="policy_category" class="ab-select">
                                    <option value="">Seçiniz...</option>
                                    <?php foreach ($policy_categories as $category): ?>
                                    <option value="<?php echo esc_attr($category); ?>" <?php if (isset($policy)) selected($policy->policy_category, $category); else if ($renewing && $category === 'Yenileme') echo 'selected'; ?>>
                                        <?php echo esc_html($category); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="ab-form-group">
                                <label for="insurance_company">Sigorta Şirketi <span class="required">*</span></label>
                                <select name="insurance_company" id="insurance_company" class="ab-select" required>
                                    <option value="">Seçiniz...</option>
                                    <?php foreach ($insurance_companies as $company): ?>
                                    <option value="<?php echo esc_attr($company); ?>" <?php if (isset($policy)) selected($policy->insurance_company, $company); ?>>
                                        <?php echo esc_html($company); ?>
                                    </option>
                                    <?php endforeach; ?>
                                    <option value="Diğer">Diğer</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="ab-form-row">
                            <div class="ab-form-group">
                                <label for="start_date">Başlangıç Tarihi <span class="required">*</span></label>
                                <input type="date" name="start_date" id="start_date" class="ab-input" 
                                       value="<?php echo isset($policy) ? esc_attr($policy->start_date) : date('Y-m-d'); ?>" 
                                       required>
                            </div>
                            
                            <div class="ab-form-group">
                                <label for="end_date">Bitiş Tarihi <span class="required">*</span></label>
                                <input type="date" name="end_date" id="end_date" class="ab-input" 
                                       value="<?php echo isset($policy) ? esc_attr($policy->end_date) : date('Y-m-d', strtotime('+1 year')); ?>" 
                                       required>
                            </div>
                        </div>
                        
                        <?php if (is_patron_or_manager()): ?>
                        <div class="ab-form-row">
                            <div class="ab-form-group ab-full-width">
                                <label for="customer_representative_id">
                                    <i class="fas fa-user-tie"></i>
                                    Müşteri Temsilcisi
                                </label>
                                <select name="customer_representative_id" id="customer_representative_id" class="ab-select">
                                    <option value="">Müşteri Temsilcisi Seçin (Opsiyonel)</option>
                                    <?php foreach ($customer_representatives as $rep): ?>
                                        <option value="<?php echo esc_attr($rep->id); ?>" 
                                                <?php echo (isset($policy) && $policy->representative_id == $rep->id) ? 'selected' : ''; ?>>
                                            <?php echo esc_html($rep->display_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="ab-form-help"><i class="fas fa-info-circle"></i> Bu alan sadece Patron ve Müdür tarafından düzenlenebilir.</div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- ÖDEME VE DURUM BİLGİLERİ BÖLÜMÜ -->
                <div class="ab-section-wrapper policy-details-step">
                    <div class="ab-section-header">
                        <h3><i class="fas fa-money-bill-wave"></i> Ödeme ve Durum Bilgileri</h3>
                    </div>
                    <div class="ab-section-content">
                        <div class="ab-form-row">
                            <div class="ab-form-group">
                                <label for="premium_amount">Prim Tutarı (₺) <span class="required">*</span></label>
                                <input type="number" name="premium_amount" id="premium_amount" class="ab-input" 
                                       value="<?php echo isset($policy) && $policy->premium_amount > 0 ? esc_attr($policy->premium_amount) : ($offer_amount ?: ''); ?>" 
                                       step="0.01" min="0" required placeholder="Prim tutarı giriniz">
                            </div>
                            
                            <!-- Brüt Prim Alanı (Kasko/Trafik için) -->
                            <div class="ab-form-group" id="gross_premium_group" style="display: none;">
                                <label for="gross_premium">Brüt Prim (₺)</label>
                                <input type="number" name="gross_premium" id="gross_premium" class="ab-input" 
                                       value="<?php echo isset($policy) && $policy->gross_premium > 0 ? esc_attr($policy->gross_premium) : ''; ?>" 
                                       step="0.01" min="0" placeholder="Brüt prim tutarı giriniz">
                            </div>
                        </div>
                        
                        <div class="ab-form-row">
                            <div class="ab-form-group">
                                <label for="payment_info">Ödeme Bilgisi</label>
                                <select name="payment_info" id="payment_info" class="ab-select">
                                    <option value="">Seçiniz...</option>
                                    <?php foreach ($payment_options as $option): ?>
                                    <option value="<?php echo esc_attr($option); ?>" <?php if (isset($policy) && $policy->payment_info === $option) echo 'selected'; ?>><?php echo esc_html($option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="ab-form-row">
                            <div class="ab-form-group">
                                <label for="network">Network/Anlaşmalı Kurum</label>
                                <input type="text" name="network" id="network" class="ab-input" 
                                       value="<?php echo isset($policy) ? esc_attr($policy->network) : ''; ?>" 
                                       placeholder="Varsa anlaşmalı kurum bilgisi">
                            </div>
                            
                            <div class="ab-form-group">
                                <label for="status">Durum <span class="required">*</span></label>
                                <select name="status" id="status" class="ab-select" required>
                                    <option value="">Seçiniz...</option>
                                    <?php foreach ($policy_statuses as $status): ?>
                                    <option value="<?php echo esc_attr($status); ?>" <?php if (isset($policy)) selected($policy->status, $status); ?>>
                                        <?php echo esc_html($status); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="ab-form-row">
                            <div class="ab-form-group ab-full-width">
                                <label for="status_note">Durum Notu</label>
                                <textarea name="status_note" id="status_note" class="ab-textarea" rows="3" 
                                          placeholder="Varsa durum ile ilgili not giriniz"><?php echo isset($policy) ? esc_textarea($policy->status_note) : ''; ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- DÖKÜMAN YÜKLEME BÖLÜMÜ -->
                <div class="ab-section-wrapper policy-details-step">
                    <div class="ab-section-header">
                        <h3><i class="fas fa-file-upload"></i> Dökümanlar</h3>
                    </div>
                    <div class="ab-section-content">
                        <div class="ab-form-row">
                            <div class="ab-form-group ab-full-width">
                                <div class="ab-notice ab-warning">
                                    <i class="fas fa-info-circle"></i> 
                                    Bu bölümde poliçe ile ilgili dökümanları (poliçe kopyası, teklif formu, imzalı evraklar) yükleyebilirsiniz. PDF, DOC veya DOCX formatında dosyalar kabul edilmektedir.
                                </div>
                            </div>
                        </div>
                        
                        <div class="ab-form-row">
                            <div class="ab-form-group ab-full-width">
                                <label>Poliçe Dökümantasyonu</label>
                                <div class="file-upload-wrapper">
                                    <div class="file-upload-input">
                                        <?php if (isset($policy) && $policy->document_path): ?>
                                            Mevcut Döküman: 
                                            <a href="<?php echo esc_url($policy->document_path); ?>" target="_blank">
                                                <?php echo basename($policy->document_path); ?>
                                            </a>
                                        <?php else: ?>
                                            Döküman seçmek için tıklayın (PDF, DOC, DOCX)
                                        <?php endif; ?>
                                    </div>
                                    <input type="file" name="document" accept=".pdf,.doc,.docx">
                                </div>
                            </div>
                        </div>
                        
                        <?php if (isset($offer_file_id) && $offer_file_id > 0): ?>
                            <?php 
                            $offer_file_path = get_attached_file($offer_file_id);
                            $offer_file_url = wp_get_attachment_url($offer_file_id);
                            if ($offer_file_path && $offer_file_url):
                            ?>
                            <div class="ab-form-row">
                                <div class="ab-form-group ab-full-width">
                                    <label class="ab-checkbox-label">
                                        <input type="checkbox" name="use_offer_file" id="use_offer_file" value="yes">
                                        <span>Teklif dökümantasyonunu kullan</span>
                                    </label>
                                    <input type="hidden" name="offer_file_path" value="<?php echo esc_url($offer_file_url); ?>">
                                    <div style="margin-top: 5px;">
                                        <a href="<?php echo esc_url($offer_file_url); ?>" target="_blank">
                                            <?php echo basename($offer_file_path); ?>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- FORM AKSİYONLARI -->
                <div class="ab-form-actions">
                <div class="ab-form-actions-left">
                    <a href="<?php echo esc_url(build_redirect_url_with_filters(['view' => 'policies'])); ?>" class="ab-btn ab-btn-secondary">
                        <i class="fas fa-times"></i> İptal
                    </a>
                </div>
                <div class="ab-form-actions-right">
                    <?php if ($cancelling): ?>
                        <button type="submit" class="ab-btn ab-btn-danger" onclick="return confirm('Poliçeyi iptal etmek istediğinizden emin misiniz?');">
                            <i class="fas fa-ban"></i> Poliçeyi İptal Et
                        </button>
                    <?php else: ?>
                        <button type="submit" class="ab-btn ab-btn-primary" id="submit_button" style="display: none;">
                            <i class="fas fa-save"></i> <?php echo $editing ? 'Güncelle' : ($renewing ? 'Yenile' : 'Kaydet'); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Yeni Etkileşimli Poliçe Ekleme Sistemi
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Poliçe formu yükleniyor...');
    
    // Farklı modlar için değişkenler
    const isEditMode = <?php echo $editing ? 'true' : 'false'; ?>;
    const isRenewMode = <?php echo $renewing ? 'true' : 'false'; ?>;
    const isCancelMode = <?php echo $cancelling ? 'true' : 'false'; ?>;
    const isCreateFromOfferMode = <?php echo $create_from_offer ? 'true' : 'false'; ?>;
    
    // Body'ye mod class'ları ekle
    if (isEditMode) document.body.classList.add('edit-mode');
    if (isRenewMode) document.body.classList.add('renewal-mode');
    if (isCancelMode) document.body.classList.add('cancel-mode');
    if (isCreateFromOfferMode) document.body.classList.add('create-from-offer-mode');
    
    console.log('📋 Mod bilgileri:', {
        isEditMode, isRenewMode, isCancelMode, isCreateFromOfferMode
    });
    
    if (isEditMode || isCancelMode) {
        console.log('✏️ Düzenleme/İptal modunda - Tüm bölümler gösteriliyor');
        
        // Önce UI elementlerini göster
        const selectedCustomerDetails = document.getElementById('selected_customer_details');
        const insuredQuestion = document.getElementById('insured_question');
        if (selectedCustomerDetails) selectedCustomerDetails.style.display = 'block';
        if (insuredQuestion) insuredQuestion.style.display = 'block';
        
        // Poliçe detaylarını zorla göster
        showPolicyDetailsSteps();
        
        // Ek güvenlik için setTimeout ile tekrar çalıştır
        setTimeout(() => {
            showPolicyDetailsSteps();
            console.log('🔄 Düzenleme modunda poliçe detayları tekrar gösterildi');
        }, 100);
        
        setupExistingFunctionality();
        
        // Müşteri bilgilerini ve aile üyelerini otomatik yükle
        const customerId = document.getElementById('selected_customer_id').value;
        if (customerId) {
            console.log('📋 Düzenleme modunda müşteri verileri yükleniyor, ID:', customerId);
            setupExistingCustomerData(customerId);
        }
        return;
    }
    
    // Yenileme modunda da poliçe detaylarını göster
    if (isRenewMode) {
        console.log('🔄 Yenileme modunda - Müşteri seçili, poliçe detayları gösteriliyor');
        
        // Müşteri bilgileri zaten seçili
        if (selectedCustomerDetails) selectedCustomerDetails.style.display = 'block';
        if (insuredQuestion) insuredQuestion.style.display = 'block';
        
        // Poliçe detaylarını otomatik göster - zorla
        showPolicyDetailsSteps();
        
        // Ek güvenlik için setTimeout ile tekrar çalıştır
        setTimeout(() => {
            showPolicyDetailsSteps();
            console.log('🔄 Yenileme modunda poliçe detayları tekrar gösterildi');
        }, 100);
        
        // Etkileşimli akış ve mevcut işlevsellik
        setupInteractiveFlow();
        setupExistingFunctionality();
        
        // Müşteri bilgilerini ve aile üyelerini otomatik yükle
        const customerId = document.getElementById('selected_customer_id').value;
        if (customerId) {
            console.log('📋 Yenileme modunda müşteri verileri yükleniyor, ID:', customerId);
            setupExistingCustomerData(customerId);
        }
        return;
    }
    
    if (isRenewMode || isCreateFromOfferMode) {
        console.log('🔄 Yenileme/Teklif modunda - Müşteri bilgileri ve poliçe detayları gösteriliyor');
        // Müşteri bilgilerini ve poliçe detaylarını göster
        const selectedCustomerDetails = document.getElementById('selected_customer_details');
        const insuredQuestion = document.getElementById('insured_question');
        if (selectedCustomerDetails) selectedCustomerDetails.style.display = 'block';
        if (insuredQuestion) insuredQuestion.style.display = 'block';
        
        // Poliçe detaylarını otomatik göster
        showPolicyDetailsSteps();

        // Create from offer modunda müşteri verilerini yükle
        if (isCreateFromOfferMode) {
            const customerId = document.getElementById('selected_customer_id')?.value;
            if (customerId) {
                console.log('📝 Teklif modunda müşteri ID bulundu:', customerId);
                setupExistingCustomerData(customerId);
            }
        }
        
        // Etkileşimli akış ve mevcut işlevsellik
        setupInteractiveFlow();
        setupExistingFunctionality();
        return;
    }
    
    console.log('➕ Yeni poliçe modu - Etkileşimli akış başlatılıyor');
    // Yeni ekleme modunda etkileşimli akış
    setupInteractiveFlow();
    setupExistingFunctionality();
    
    // URL'den customer_search parametresi varsa otomatik arama başlat
    const customerSearchValue = document.getElementById('customer_search').value;
    if (customerSearchValue && customerSearchValue.trim() !== '') {
        console.log('🔍 URL parametresinden müşteri aranıyor:', customerSearchValue);
        setTimeout(() => {
            searchCustomers(customerSearchValue.trim(), true); // Auto-select flag
        }, 100);
    }
});

// Düzenleme/yenileme modunda mevcut müşteri verilerini kurulum
function setupExistingCustomerData(customerId) {
    console.log('🔧 Mevcut müşteri verileri kuruluyor, ID:', customerId);
    
    // UI elementlerini göster
    const selectedCustomerDetails = document.getElementById('selected_customer_details');
    const insuredQuestion = document.getElementById('insured_question');
    const familyMembersSelection = document.getElementById('family_members_selection');
    
    if (selectedCustomerDetails) selectedCustomerDetails.style.display = 'block';
    if (insuredQuestion) insuredQuestion.style.display = 'block';
    
    // Müşteri verilerini yükle
    loadExistingCustomerData(customerId);
    
    // Aile üyelerini yükle (düzenleme modunda da gerekli)
    loadFamilyMembers(customerId);
    
    // Sigortalı seçimlerini geri yükle
    setTimeout(() => {
        restorePreviousInsuredSelections();
    }, 500);
}

function setupInteractiveFlow() {
    console.log('🚀 Etkileşimli akış başlatılıyor...');
    
    const customerSearch = document.getElementById('customer_search');
    const searchResults = document.getElementById('search_results');
    const selectedCustomerDetails = document.getElementById('selected_customer_details');
    const insuredQuestion = document.getElementById('insured_question');
    const familyMembersSelection = document.getElementById('family_members_selection');
    const familyMembersList = document.getElementById('family_members_list');
    const submitButton = document.getElementById('submit_button');
    
    // Element varlığını kontrol et
    if (!customerSearch) {
        console.error('❌ Müşteri arama input elementi bulunamadı!');
        return;
    }
    if (!searchResults) {
        console.error('❌ Arama sonuçları elementi bulunamadı!');
        return;
    }
    
    console.log('✅ Gerekli elementler bulundu, event listener\'ları kuruluyor...');
    
    let searchTimeout;
    let selectedCustomer = null;
    
    // Müşteri arama
    customerSearch.addEventListener('input', function(e) {
        const query = e.target.value.trim();
        
        if (query.length === 0) {
            searchResults.style.display = 'none';
            // Müşteri seçimi temizlendiğinde ilgili alanları gizle
            selectedCustomerDetails.style.display = 'none';
            insuredQuestion.style.display = 'none';
            familyMembersSelection.style.display = 'none';
            document.getElementById('selected_customer_id').value = '';
            selectedCustomer = null;
            console.log('🧹 Arama temizlendi, form sıfırlandı');
            return;
        }
        
        if (query.length < 2) {
            searchResults.innerHTML = '<div class="search-result-item"><i class="fas fa-keyboard"></i> En az 2 karakter girin</div>';
            searchResults.style.display = 'block';
            return;
        }
        
        console.log('🔍 Arama sorgusu:', query);
        
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            searchCustomers(query);
        }, 200); // Daha hızlı yanıt için 200ms'ye düşürüldü
    });
    
    // Arama sonuçları dışına tıklandığında kapat
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.customer-search-container')) {
            searchResults.style.display = 'none';
        }
    });
    
    // Müşteri arama fonksiyonu
    function searchCustomers(query, autoSelect = false) {
        console.log('🔍 Müşteri aranıyor:', query, autoSelect ? '(otomatik seçim aktif)' : '');
        
        // AJAX URL kontrolü
        const ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
        console.log('AJAX URL:', ajaxUrl);
        
        searchResults.innerHTML = '<div class="search-result-item"><i class="fas fa-search fa-spin"></i> Aranıyor...</div>';
        searchResults.style.display = 'block';
        
        // WordPress AJAX çağrısı
        const formData = new FormData();
        formData.append('action', 'search_customers_for_policy');
        formData.append('query', query);
        formData.append('nonce', '<?php echo wp_create_nonce('search_customers_nonce'); ?>');
        
        console.log('📤 AJAX isteği gönderiliyor...');
        console.log('📋 Gönderilen veri:', {
            action: 'search_customers_for_policy',
            query: query,
            nonce: '<?php echo wp_create_nonce('search_customers_nonce'); ?>'
        });
        
        // AJAX çağrısı için timeout ekle
        const controller = new AbortController();
        const timeoutId = setTimeout(() => {
            controller.abort();
            console.warn('⏰ AJAX isteği zaman aşımına uğradı');
        }, 10000); // 10 saniye timeout
        
        fetch(ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            signal: controller.signal
        })
        .then(response => {
            clearTimeout(timeoutId);
            console.log('📥 AJAX yanıtı alındı:', response.status, response.statusText);
            if (!response.ok) {
                throw new Error(`HTTP hata: ${response.status}`);
            }
            return response.text(); // Önce text olarak al
        })
        .then(text => {
            console.log('📄 Ham yanıt:', text.substring(0, 200) + '...');
            try {
                const data = JSON.parse(text);
                console.log('✅ JSON parse başarılı:', data);
                if (data.success && data.data) {
                    displaySearchResults(data.data, autoSelect);
                } else {
                    console.log('❌ Arama başarısız:', data.data || 'Veri yok');
                    searchResults.innerHTML = '<div class="search-result-item"><i class="fas fa-exclamation-circle"></i> Müşteri bulunamadı</div>';
                }
            } catch (e) {
                console.error('❌ JSON parse hatası:', e);
                console.log('📄 Parse edilemeyen yanıt:', text);
                throw new Error('Geçersiz JSON yanıtı');
            }
        })
        .catch(error => {
            clearTimeout(timeoutId);
            console.error('❌ Arama hatası:', error);
            
            // Alternatif arama yöntemi - basit metin tabanlı arama
            console.log('🔄 Alternatif arama yöntemi deneniyor...');
            searchResults.innerHTML = '<div class="search-result-item"><i class="fas fa-sync-alt fa-spin"></i> Alternatif arama kullanılıyor...</div>';
            
            // PHP tabanlı alternatif arama için form submission
            tryAlternativeSearch(query);
        });
    }
    
    // Alternatif arama yöntemi (AJAX başarısız olduğunda)
    function tryAlternativeSearch(query) {
        console.log('🔄 Alternatif arama başlatılıyor:', query);
        
        // Önceden yüklenen müşteri listesinden arama (basit client-side arama)
        const allCustomers = <?php 
        // Get customers with phone and email data for alternative search
        $customers_for_js = $wpdb->get_results("SELECT id, first_name, last_name, tc_identity, phone, email, company_name, tax_number, spouse_name, spouse_tc_identity, spouse_birth_date, children_count, children_names, children_birth_dates, children_tc_identities, has_pet, pet_name, pet_type, pet_age FROM $customers_table WHERE status = 'aktif' ORDER BY first_name, last_name");
        echo json_encode($customers_for_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); 
        ?>;
        
        // Global değişkende sakla (aile üyesi fonksiyonu için)
        window.allCustomers = allCustomers;
        
        console.log('📋 Mevcut müşteri sayısı (aile bilgileri dahil):', allCustomers ? allCustomers.length : 0);
        
        if (allCustomers && allCustomers.length > 0) {
            const filteredCustomers = allCustomers.filter(customer => {
                const fullName = `${customer.first_name} ${customer.last_name}`.toLowerCase();
                const tcIdentity = customer.tc_identity || '';
                const phone = customer.phone || '';
                const email = customer.email || '';
                const companyName = customer.company_name || '';
                const taxNumber = customer.tax_number || '';
                const queryLower = query.toLowerCase();
                
                return fullName.includes(queryLower) || 
                       tcIdentity.includes(queryLower) ||
                       phone.includes(queryLower) ||
                       email.toLowerCase().includes(queryLower) ||
                       companyName.toLowerCase().includes(queryLower) ||
                       taxNumber.includes(queryLower);
            });
            
            console.log('✅ Alternatif arama sonucu:', filteredCustomers.length, 'müşteri bulundu');
            displaySearchResults(filteredCustomers);
        } else {
            console.error('❌ Müşteri listesi boş veya yüklenemedi');
            searchResults.innerHTML = '<div class="search-result-item error"><i class="fas fa-exclamation-triangle"></i> Müşteri listesi yüklenemiyor. <button onclick="location.reload()" class="ab-btn ab-btn-sm ab-btn-secondary">Sayfayı Yenile</button></div>';
        }
    }
    
    // Arama sonuçlarını göster
    function displaySearchResults(customers, autoSelect = false) {
        if (customers.length === 0) {
            searchResults.innerHTML = '<div class="search-result-item"><i class="fas fa-info-circle"></i> Müşteri bulunamadı</div>';
            return;
        }
        
        let html = '';
        customers.forEach(customer => {
            html += `
                <div class="search-result-item" data-customer-id="${customer.id}">
                    <div style="font-weight: 600; color: #333; margin-bottom: 4px;">
                        <i class="fas fa-user" style="color: <?php echo $corporate_color; ?>; margin-right: 6px;"></i>
                        ${customer.first_name} ${customer.last_name}
                    </div>
                    <div style="display: flex; flex-wrap: wrap; gap: 12px; font-size: 13px; color: #666;">
                        ${customer.tc_identity ? `<span><i class="fas fa-id-card" style="margin-right: 4px;"></i>TC: ${customer.tc_identity}</span>` : ''}
                        ${customer.phone ? `<span><i class="fas fa-phone" style="margin-right: 4px;"></i>${customer.phone}</span>` : ''}
                        ${customer.email ? `<span><i class="fas fa-envelope" style="margin-right: 4px;"></i>${customer.email}</span>` : ''}
                        ${customer.company_name ? `<span><i class="fas fa-building" style="margin-right: 4px;"></i>${customer.company_name}</span>` : ''}
                    </div>
                </div>
            `;
        });
        searchResults.innerHTML = html;
        
        // Auto-select first customer if requested and there's exactly one result
        if (autoSelect && customers.length === 1) {
            console.log('🎯 Tek sonuç bulundu, otomatik seçiliyor:', customers[0]);
            setTimeout(() => {
                selectCustomer(customers[0]);
                searchResults.style.display = 'none';
            }, 500);
            return;
        }
        
        // Müşteri seçim event'i
        searchResults.querySelectorAll('.search-result-item').forEach((item, index) => {
            item.addEventListener('click', function() {
                const customerId = this.dataset.customerId;
                if (customerId) {
                    const customerData = customers.find(c => c.id == customerId);
                    if (customerData) {
                        selectCustomer(customerData);
                    }
                }
            });
            
            // Klavye navigasyonu için hover efektleri
            item.addEventListener('mouseenter', function() {
                // Diğer öğelerden hover sınıfını kaldır
                searchResults.querySelectorAll('.search-result-item').forEach(el => {
                    el.classList.remove('search-result-hover');
                });
                this.classList.add('search-result-hover');
            });
        });
    }
    
    // Müşteri seç
    function selectCustomer(customer) {
        console.log('👤 Müşteri seçildi:', customer);
        
        // Global değişkende sakla
        window.selectedCustomer = customer;
        selectedCustomer = customer;
        
        document.getElementById('selected_customer_id').value = customer.id;
        customerSearch.value = `${customer.first_name} ${customer.last_name}`;
        searchResults.style.display = 'none';
        
        // Müşteri detaylarını göster
        displayCustomerDetails(customer);
        
        // Müşteri seçim bölümünü tamamlanmış olarak işaretle
        document.querySelector('.customer-selection-step').classList.add('completed');
        
        // Sigortalı soru bölümünü göster
        insuredQuestion.style.display = 'block';
        
        // Sigortalı soru bölümüne odaklan
        setTimeout(() => {
            const insuredQuestionElement = document.getElementById('insured_question');
            if (insuredQuestionElement) {
                insuredQuestionElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                const firstRadio = insuredQuestionElement.querySelector('input[name="same_as_insured"]');
                if (firstRadio) {
                    firstRadio.focus();
                }
            }
        }, 300);
        
        // Varsayılan "Evet" seçili olduğu için poliçe detaylarını da göster
        const defaultRadio = document.querySelector('input[name="same_as_insured"][value="yes"]');
        if (defaultRadio && defaultRadio.checked) {
            console.log('📋 Varsayılan "Evet" seçili - Poliçe detayları gösteriliyor');
            showPolicyDetailsSteps();
        }
        
        // Aile üyelerini yükle
        loadFamilyMembers(customer.id);
    }
    
    // Müşteri detaylarını göster
    function displayCustomerDetails(customer) {
        // Individual input alanlarını doldur
        document.getElementById('selected_customer_name').value = `${customer.first_name} ${customer.last_name}`;
        document.getElementById('selected_customer_tc').value = customer.tc_identity || 'Belirtilmemiş';
        document.getElementById('selected_customer_phone').value = customer.phone || 'Belirtilmemiş';
        document.getElementById('selected_customer_email').value = customer.email || 'Belirtilmemiş';
        
        selectedCustomerDetails.style.display = 'block';
    }
    
    // Düzenleme/yenileme modunda mevcut müşteri verilerini yükle
    function loadExistingCustomerData(customerId) {
        console.log('📋 Mevcut müşteri verileri yükleniyor, ID:', customerId);
        
        // AJAX isteği ile müşteri verilerini al
        const formData = new FormData();
        formData.append('action', 'get_customer_data');
        formData.append('customer_id', customerId);
        formData.append('nonce', '<?php echo wp_create_nonce('insurance_crm_nonce'); ?>');
        
        fetch(ajaxurl, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                console.log('✅ Müşteri verileri başarıyla yüklendi:', data.data);
                
                // Global selectedCustomer değişkenini ayarla
                selectedCustomer = data.data;
                window.selectedCustomer = data.data;
                
                // Müşteri detaylarını göster
                displayCustomerDetails(data.data);
                
                // Müşteri seçim bölümünü tamamlanmış olarak işaretle
                document.querySelector('.customer-selection-step').classList.add('completed');
                
                // Arama alanını da güncelle
                const customerSearch = document.getElementById('customer_search');
                if (customerSearch && !customerSearch.readOnly) {
                    customerSearch.value = `${data.data.first_name} ${data.data.last_name}`;
                }
            } else {
                console.error('❌ Müşteri verileri yüklenirken hata:', data);
            }
        })
        .catch(error => {
            console.error('❌ Müşteri verileri yükleme hatası:', error);
        });
    }
    
    // Aile üyelerini yükle - client-side alternatif yöntem
    function loadFamilyMembers(customerId) {
        console.log('🔍 Aile üyeleri yükleniyor (client-side), müşteri ID:', customerId);
        familyMembersList.innerHTML = '<p><i class="fas fa-spinner fa-spin"></i> Aile üyeleri yükleniyor...</p>';
        
        // Önce selectedCustomer objesinden veriyi almaya çalış
        if (window.selectedCustomer && window.selectedCustomer.id == customerId) {
            console.log('👨‍👩‍👧‍👦 Seçili müşteriden aile verisi alınıyor:', window.selectedCustomer);
            displayFamilyMembersFromCustomer(window.selectedCustomer);
            return;
        }
        
        // Alternatif: allCustomers listesinden veriyi bul
        if (window.allCustomers) {
            const customer = window.allCustomers.find(c => c.id == customerId);
            if (customer) {
                console.log('👨‍👩‍👧‍👦 AllCustomers listesinden aile verisi bulundu:', customer);
                displayFamilyMembersFromCustomer(customer);
                return;
            }
        }
        
        // Son seçenek: AJAX çağrısı
        console.log('📡 AJAX ile aile üyeleri yükleniyor...');
        const ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
        const formData = new FormData();
        formData.append('action', 'get_family_members');
        formData.append('customer_id', customerId);
        formData.append('nonce', '<?php echo wp_create_nonce('get_family_members_nonce'); ?>');
        
        fetch(ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => {
            console.log('📡 Aile üyeleri AJAX yanıtı:', response.status, response.statusText);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('👨‍👩‍👧‍👦 AJAX Aile üyeleri verisi:', data);
            if (data.success) {
                displayFamilyMembers(data.data);
            } else {
                console.error('❌ Aile üyeleri yüklenemedi:', data.data || 'Bilinmeyen hata');
                familyMembersList.innerHTML = '<p style="color: #666;">❌ Aile üyesi bilgisi yüklenemedi.</p>';
            }
        })
        .catch(error => {
            console.error('💥 AJAX Aile üyeleri yükleme hatası:', error);
            familyMembersList.innerHTML = '<p style="color: #666;">❌ Aile üyesi bilgisi yüklenirken hata oluştu.</p>';
        });
    }
    
    // Müşteri nesnesinden aile üyelerini göster
    function displayFamilyMembersFromCustomer(customer) {
        console.log('👨‍👩‍👧‍👦 Müşteri nesnesinden aile üyeleri işleniyor:', customer);
        
        let html = '';
        let familyMemberCount = 0;
        
        // Müşterinin kendisini ilk seçenek olarak ekle
        html += `
            <div class="family-member-item">
                <label class="family-member-label">
                    <input type="checkbox" name="insured_persons[]" value="${customer.first_name} ${customer.last_name}" data-type="customer">
                    <span class="family-member-details">
                        <strong>👤 ${customer.first_name} ${customer.last_name}</strong> (Müşteri)
                        <small>TC: ${customer.tc_identity || 'Belirtilmemiş'}</small>
                    </span>
                </label>
            </div>
        `;
        familyMemberCount++;
        
        // Eş bilgilerini ekle
        if (customer.spouse_name && customer.spouse_name.trim()) {
            html += `
                <div class="family-member-item">
                    <label class="family-member-label">
                        <input type="checkbox" name="insured_persons[]" value="${customer.spouse_name}" data-type="spouse">
                        <span class="family-member-details">
                            <strong>👥 ${customer.spouse_name}</strong> (Eş)
                            <small>TC: ${customer.spouse_tc_identity || 'Belirtilmemiş'}</small>
                        </span>
                    </label>
                </div>
            `;
            familyMemberCount++;
        }
        
        // Çocuk bilgilerini ekle
        if (customer.children_names && customer.children_names.trim()) {
            const childrenNames = customer.children_names.split(',');
            const childrenTcs = customer.children_tc_identities ? customer.children_tc_identities.split(',') : [];
            
            childrenNames.forEach((childName, index) => {
                const name = childName.trim();
                if (name) {
                    const tcIdentity = childrenTcs[index] ? childrenTcs[index].trim() : 'Belirtilmemiş';
                    html += `
                        <div class="family-member-item">
                            <label class="family-member-label">
                                <input type="checkbox" name="insured_persons[]" value="${name}" data-type="child">
                                <span class="family-member-details">
                                    <strong>👶 ${name}</strong> (Çocuk)
                                    <small>TC: ${tcIdentity}</small>
                                </span>
                            </label>
                        </div>
                    `;
                    familyMemberCount++;
                }
            });
        }

        // Evcil hayvan bilgisini ekle
        if (customer.has_pet == 1 && customer.pet_name && customer.pet_name.trim()) {
            html += `
                <div class="family-member-item">
                    <label class="family-member-label">
                        <input type="checkbox" name="insured_persons[]" value="${customer.pet_name}" data-type="pet">
                        <span class="family-member-details">
                            <strong>🐾 ${customer.pet_name}</strong> (Evcil Hayvan)
                            <small>${customer.pet_type || 'Tür belirtilmemiş'} - ${customer.pet_age || 'Yaş belirtilmemiş'}</small>
                        </span>
                    </label>
                </div>
            `;
            familyMemberCount++;
        }
        
        if (familyMemberCount <= 1) {
            html += `
                <div class="no-family-members">
                    <p style="color: #666; text-align: center; padding: 20px;">
                        <i class="fas fa-info-circle"></i> Bu müşterinin aile üyesi bilgisi bulunmuyor.
                        <br><small>Müşteri düzenleme sayfasından eş ve çocuk bilgilerini ekleyebilirsiniz.</small>
                    </p>
                </div>
            `;
        }
        
        familyMembersList.innerHTML = html;
        console.log('✅ Aile üyeleri başarıyla görüntülendi, toplam:', familyMemberCount);
        
        // Checkbox değişikliklerini dinle
        const checkboxes = familyMembersList.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateInsuredPersonsField);
        });
        
        // Düzenleme modunda mevcut seçimleri geri yükle
        if (isEditMode || isRenewMode) {
            restorePreviousInsuredSelections();
        }
    }
    
    // Mevcut sigortalı seçimlerini geri yükle
    function restorePreviousInsuredSelections() {
        const existingInsuredList = document.getElementById('insured_party_list_hidden').value;
        if (!existingInsuredList) return;
        
        console.log('🔄 Mevcut sigortalı seçimleri geri yükleniyor:', existingInsuredList);
        
        const insuredNames = existingInsuredList.split(',').map(name => name.trim());
        const checkboxes = document.querySelectorAll('input[name="insured_persons[]"]');
        
        checkboxes.forEach(checkbox => {
            const checkboxValue = checkbox.value.trim();
            if (insuredNames.includes(checkboxValue)) {
                checkbox.checked = true;
                console.log('✅ Seçim geri yüklendi:', checkboxValue);
            }
        });
        
        // Seçimleri güncelle
        updateInsuredPersonsField();
    }
    
    // Aile üyelerini göster
    function displayFamilyMembers(familyMembers) {
        console.log('👨‍👩‍👧‍👦 Aile üyelerini görüntüleniyor:', familyMembers);
        let html = '';
        let familyMemberCount = 0;
        
        // Müşterinin kendisini ilk seçenek olarak ekle
        if (selectedCustomer && selectedCustomer.first_name && selectedCustomer.last_name) {
            const customerFullName = `${selectedCustomer.first_name} ${selectedCustomer.last_name}`;
            const customerTC = selectedCustomer.tc_identity || '';
            html += `
                <div class="family-member-item">
                    <input type="checkbox" id="customer_self_insured" name="family_insured[]" value="self:${customerFullName}:${customerTC}">
                    <label for="customer_self_insured">
                        <strong>🙋‍♂️ Müşterinin Kendisi:</strong> ${customerFullName}
                        ${customerTC ? ` (TC: ${customerTC})` : ''}
                    </label>
                </div>
            `;
        }
        
        // Eş bilgileri
        if (familyMembers.spouse && familyMembers.spouse.name) {
            html += `
                <div class="family-member-item">
                    <input type="checkbox" id="spouse_insured" name="family_insured[]" value="spouse:${familyMembers.spouse.name}:${familyMembers.spouse.tc_identity || ''}">
                    <label for="spouse_insured">
                        <strong>👫 Eş:</strong> ${familyMembers.spouse.name} 
                        ${familyMembers.spouse.tc_identity ? `(TC: ${familyMembers.spouse.tc_identity})` : ''}
                    </label>
                </div>
            `;
            familyMemberCount++;
        }
        
        // Çocuk bilgileri
        if (familyMembers.children && familyMembers.children.length > 0) {
            familyMembers.children.forEach((child, index) => {
                html += `
                    <div class="family-member-item">
                        <input type="checkbox" id="child_${index}_insured" name="family_insured[]" value="child:${child.name}:${child.tc_identity || ''}">
                        <label for="child_${index}_insured">
                            <strong>👶 Çocuk:</strong> ${child.name} 
                            ${child.tc_identity ? `(TC: ${child.tc_identity})` : ''}
                        </label>
                    </div>
                `;
                familyMemberCount++;
            });
        }

        // Evcil hayvan bilgisi
        if (familyMembers.pet && familyMembers.pet.name) {
            html += `
                <div class="family-member-item">
                    <input type="checkbox" id="pet_insured" name="family_insured[]" value="pet:${familyMembers.pet.name}:">
                    <label for="pet_insured">
                        <strong>🐾 Evcil Hayvan:</strong> ${familyMembers.pet.name}
                        ${familyMembers.pet.type ? `(${familyMembers.pet.type})` : ''}
                        ${familyMembers.pet.age ? ` - ${familyMembers.pet.age} yaşında` : ''}
                    </label>
                </div>
            `;
            familyMemberCount++;
        }
        
        // Aile üyesi yoksa uyarı mesajı göster
        if (familyMemberCount === 0) {
            html += `
                <div style="background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 6px; padding: 15px; margin: 10px 0; color: #856404;">
                    <p style="margin: 0; font-weight: 500;">
                        <i class="fas fa-exclamation-triangle" style="color: #f39c12; margin-right: 8px;"></i>
                        Bu müşterinin kayıtlı aile üyesi bulunmamaktadır.
                    </p>
                    <p style="margin: 8px 0 0 0; font-size: 13px;">
                        Müşteri bilgilerini düzenleyerek eş ve çocuk bilgilerini ekleyebilirsiniz.
                        Şu an için sadece müşterinin kendisi sigortalı olarak seçilebilir.
                    </p>
                </div>
            `;
        }
        
        familyMembersList.innerHTML = html;
        console.log('✅ Aile üyeleri listesi güncellendi, toplam üye:', familyMemberCount);
    }
    
    // Sigortalı seçimi için radio button handler
    document.querySelectorAll('input[name="same_as_insured"]').forEach(radio => {
        radio.addEventListener('change', function() {
            console.log('🔄 Sigortalı seçimi değişti:', this.value);
            const continueButton = document.getElementById('continue_to_policy');
            
            if (this.value === 'yes') {
                // Aynı kişi seçildi - aile üyesi seçimi gizle
                familyMembersSelection.style.display = 'none';
                const customerName = document.getElementById('customer_search').value;
                document.getElementById('insured_party_hidden').value = customerName || '';
                document.getElementById('insured_party_list_hidden').value = customerName || '';
                console.log('✅ Sigortalı olarak müşteri seçildi:', customerName);
            } else {
                // Farklı kişi seçildi - aile üyesi seçimi göster
                familyMembersSelection.style.display = 'block';
                document.getElementById('insured_party_hidden').value = '';
                document.getElementById('insured_party_list_hidden').value = '';
                console.log('📋 Aile üyesi seçimi açıldı');
                
                // Eğer müşteri seçiliyse aile üyelerini yükle
                const customerId = document.getElementById('selected_customer_id').value;
                if (customerId) {
                    loadFamilyMembers(customerId);
                }
            }
            
            // ÖNEMLİ: Her iki durumda da poliçe detaylarını göster
            console.log('📋 Poliçe detayları her durumda gösteriliyor');
            showPolicyDetailsSteps();
        });
    });
    
    // Sigortalı seçimlerini güncelleyen fonksiyon
    function updateInsuredPersonsField() {
        const checkedBoxes = document.querySelectorAll('input[name="insured_persons[]"]:checked');
        const continueButton = document.getElementById('continue_to_policy');
        let insuredParties = [];
        
        console.log('👨‍👩‍👧‍👦 Sigortalı seçimi değişti, seçili üye sayısı:', checkedBoxes.length);
        
        checkedBoxes.forEach(checkbox => {
            insuredParties.push(checkbox.value);
            console.log('✅ Seçili üye:', checkbox.value);
        });
        
        document.getElementById('insured_party_hidden').value = insuredParties.join(', ');
        document.getElementById('insured_party_list_hidden').value = insuredParties.join(', ');
        console.log('📝 Sigortalı alanı güncellendi:', insuredParties.join(', '));
        
        // Devam butonunu göster/gizle
        if (checkedBoxes.length > 0) {
            continueButton.style.display = 'inline-flex';
        } else {
            continueButton.style.display = 'none';
        }
    }
    
    // Aile üyesi seçimi değiştiğinde - yeni format için
    document.addEventListener('change', function(e) {
        if (e.target.name === 'insured_persons[]') {
            updateInsuredPersonsField();
        }
        
        // Eski format için de koruma
        if (e.target.name === 'family_insured[]') {
            const checkedBoxes = document.querySelectorAll('input[name="family_insured[]"]:checked');
            const continueButton = document.getElementById('continue_to_policy');
            let insuredParties = [];
            
            console.log('👨‍👩‍👧‍👦 Aile üyesi seçimi değişti (eski format), seçili üye sayısı:', checkedBoxes.length);
            
            checkedBoxes.forEach(checkbox => {
                const [type, name, tc] = checkbox.value.split(':');
                insuredParties.push(name);
                console.log('✅ Seçili üye:', type, name, tc);
            });
            
            document.getElementById('insured_party_hidden').value = insuredParties.join(', ');
            document.getElementById('insured_party_list_hidden').value = insuredParties.join(', ');
            console.log('📝 Sigortalı alanı güncellendi:', insuredParties.join(', '));
            
            // Devam butonunu göster/gizle
            if (checkedBoxes.length > 0) {
                continueButton.style.display = 'inline-flex';
            } else {
                continueButton.style.display = 'none';
            }
        }
    });
    
    // Devam butonuna tıklandığında poliçe detaylarını göster
    document.getElementById('continue_to_policy').addEventListener('click', function() {
        console.log('⏭️ Devam butonu tıklandı, poliçe detaylarını gösteriliyor');
        showPolicyDetailsSteps();
    });
    
    // Düzenleme/yenileme modunda mevcut seçimleri kontrol et ve aile üyelerini yükle
    if (isRenewMode || isEditMode) {
        console.log('🔧 Düzenleme/Yenileme modu için başlangıç kontrolü');
        
        // Müşteri seçili ise sigortalı sorusunu göster
        const customerId = document.getElementById('selected_customer_id').value;
        if (customerId) {
            insuredQuestion.style.display = 'block';
            
            // Müşteri bilgilerini yükle ve göster
            loadExistingCustomerData(customerId);
            
            // Yenileme modunda aile üyelerini de yükle
            if (isRenewMode) {
                loadFamilyMembers(customerId);
            }
        }
        
        // Eğer "Hayır" seçili ve müşteri var ise aile üyelerini yükle
        const selectedRadio = document.querySelector('input[name="same_as_insured"]:checked');
        
        console.log('📋 Mevcut seçim:', selectedRadio?.value, 'Müşteri ID:', customerId);
        
        if (selectedRadio?.value === 'no' && customerId) {
            console.log('👨‍👩‍👧‍👦 "Hayır" seçili, aile üyelerini yüklüyor...');
            familyMembersSelection.style.display = 'block';
            loadFamilyMembers(customerId);
        }
    }
}

// Poliçe detayları bölümlerini göster (global fonksiyon)
function showPolicyDetailsSteps() {
    console.log('📋 Poliçe detayları bölümleri gösteriliyor');
    const policySteps = document.querySelectorAll('.policy-details-step');
    const submitButton = document.getElementById('submit_button');
    
    console.log('🔍 Bulunan policy-details-step sayısı:', policySteps.length);
    
    policySteps.forEach((el, index) => {
        console.log(`📋 Step ${index + 1} gösteriliyor:`, el);
        el.classList.add('active');
        el.style.display = 'block';
        el.style.visibility = 'visible';
        el.style.opacity = '1';
    });
    
    if (submitButton) {
        submitButton.style.display = 'inline-flex';
        console.log('✅ Submit butonu gösterildi');
    }
    
    // Brüt prim alanını kontrol et ve göster (edit/renewal modunda)
    updateGrossPremiumField();
    
    console.log('✅ Poliçe detayları bölümleri başarıyla gösterildi');
}

function setupExistingFunctionality() {
    // Kasko/Trafik seçiminde plaka alanını göster/gizle
    updatePlateField();
    
    // Kasko/Trafik seçiminde brüt prim alanını göster/gizle
    updateGrossPremiumField();
    
    // Sigorta şirketi ve poliçe kategorisi önceden seçili ise kontrol et
    const policyCategory = document.getElementById('policy_category');
    if (policyCategory && policyCategory.value === '') {
        const firstOption = policyCategory.querySelector('option:not([value=""])');
        if (firstOption) {
            policyCategory.value = firstOption.value;
        }
    }
    
    // Dosya seçildiğinde etiketi güncelle
    const fileUpload = document.querySelector('input[type="file"]');
    const fileUploadLabel = document.querySelector('.file-upload-input');
    
    if (fileUpload && fileUploadLabel) {
        fileUpload.addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                fileUploadLabel.textContent = this.files[0].name;
            }
        });
    }
    
    // İptal durumu seçildiğinde uyarı
    const statusSelect = document.getElementById('status');
    if (statusSelect) {
        statusSelect.addEventListener('change', function() {
            if (this.value === 'Zeyil') {
                alert('Dikkat: Zeyil durumu seçtiniz. Bu işlem genellikle İptal Et butonunu kullanarak yapılmalıdır. Buradan yapılan değişiklikler iptal bilgilerini içermeyecektir.');
            }
        });
    }
    
    // Tarih aralığı kontrolleri
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    
    if (startDateInput && endDateInput) {
        startDateInput.addEventListener('change', function() {
            // Başlangıç tarihi değiştiğinde, bitiş tarihini otomatik olarak 1 yıl sonraya ayarla
            const startDate = new Date(this.value);
            if (this.value) {
                const newEndDate = new Date(startDate);
                newEndDate.setFullYear(newEndDate.getFullYear() + 1);
                endDateInput.value = newEndDate.toISOString().split('T')[0];
            }
        });
    }
    
    // Poliçe türü değiştiğinde plaka alanını kontrol et
    const policyTypeSelect = document.getElementById('policy_type');
    if (policyTypeSelect) {
        policyTypeSelect.addEventListener('change', function() {
            updatePlateField();
            updateGrossPremiumField();
        });
    }
    
    // Diğer form olaylarını kur
    setupFormEvents();
}

// Form olaylarını kurma fonksiyonu  
function setupFormEvents() {
    // İptal tarihi kontrolü
    const cancellationDateInput = document.getElementById('cancellation_date');
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    
    if (cancellationDateInput && startDateInput && endDateInput) {
        cancellationDateInput.addEventListener('change', function() {
            const cancellationDate = new Date(this.value);
            const startDate = new Date(startDateInput.value);
            const endDate = new Date(endDateInput.value);
            
            if (cancellationDate < startDate || cancellationDate > endDate) {
                alert('İptal tarihi, poliçe başlangıç ve bitiş tarihleri arasında olmalıdır.');
                this.value = new Date().toISOString().split('T')[0];
            }
        });
    }
    
    // İade tutarı kontrolleri
    const premiumAmountInput = document.getElementById('premium_amount');
    const refundedAmountInput = document.getElementById('refunded_amount');
    
    if (premiumAmountInput && refundedAmountInput) {
        refundedAmountInput.addEventListener('change', function() {
            const premiumAmount = parseFloat(premiumAmountInput.value);
            const refundedAmount = parseFloat(this.value);
            
            if (refundedAmount > premiumAmount) {
                alert('İade tutarı, prim tutarından büyük olamaz.');
                this.value = premiumAmount;
            }
        });
    }
    
    // Plaka formatı kontrolü
    const plateInput = document.getElementById('plate_number');
    if (plateInput) {
        plateInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    }
    
    // Form validasyonu - sadece edit modda aktif
    const form = document.querySelector('form');
    const isEditMode = <?php echo ($editing || $cancelling || $renewing || $create_from_offer) ? 'true' : 'false'; ?>;
    
    if (form && isEditMode) {
        form.addEventListener('submit', function(e) {
            const requiredFields = form.querySelectorAll('[required]');
            let valid = true;
            
            requiredFields.forEach(function(field) {
                // Sadece görünür alanları kontrol et
                if (field.offsetParent !== null && !field.value.trim()) {
                    valid = false;
                    field.style.borderColor = '#f44336';
                    field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else {
                    field.style.borderColor = '';
                }
            });
            
            if (!valid) {
                e.preventDefault();
                alert('Lütfen tüm zorunlu alanları doldurun.');
            }
        });
    }
}

// Sigortalı ile Sigortalayan Aynı Kişi mi? seçeneği için
function setupInsuredCheckbox() {
    const sameAsInsuredCheckbox = document.getElementById('same_as_insured');
    const insuredPartyInput = document.getElementById('insured_party');
    
    if (sameAsInsuredCheckbox && insuredPartyInput) {
        sameAsInsuredCheckbox.addEventListener('change', function() {
            insuredPartyInput.disabled = this.checked;
            if (this.checked) {
                insuredPartyInput.value = '';
            }
        });
        
        // Sayfa yüklendiğinde kontrol
        if (sameAsInsuredCheckbox.checked) {
            insuredPartyInput.disabled = true;
        }
    }
}

// Müşteri detaylarını getir
function fetchCustomerDetails(customerId) {
    if (!customerId) return;
    
    // AJAX isteği için endpoint
    const endpoint = ajaxurl || (window.location.href.split('?')[0] + '?action=get_customer_info');
    
    // Form verisi oluştur
    const formData = new FormData();
    formData.append('action', 'get_customer_info');
    formData.append('customer_id', customerId);
    
    // AJAX isteği başlat
    fetch(endpoint, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateCustomerDetails(data.data);
        } else {
            console.error('Müşteri bilgileri alınamadı:', data.message);
        }
    })
    .catch(error => {
        console.error('AJAX isteği başarısız oldu:', error);
    });
}

// Müşteri bilgilerini güncelle
function updateCustomerDetails(customer) {
    const customerDetails = document.getElementById('customer_details');
    if (!customerDetails) return;
    
    customerDetails.innerHTML = `
        <div class="form-row">
            <label>TC Kimlik No</label>
            <input type="text" id="customer_tc" class="form-input" value="${customer.tc_identity || ''}" readonly>
        </div>
        
        <div class="form-row">
            <label>Telefon</label>
            <input type="text" id="customer_phone" class="form-input" value="${customer.phone || ''}" readonly>
        </div>
        
        <div class="form-row">
            <label>E-posta</label>
            <input type="text" id="customer_email" class="form-input" value="${customer.email || ''}" readonly>
        </div>
        
        <div class="form-row">
            <label for="insured_party">Sigortalayan</label>
            <input type="text" name="insured_party" id="insured_party" class="form-input" 
                   value="" 
                   placeholder="Sigortalayan farklıysa lütfen isim soyisim girin">
            
            <div class="checkbox-row">
                <input type="checkbox" name="same_as_insured" id="same_as_insured" value="yes" checked>
                <label for="same_as_insured">Sigortalı ile Sigortalayan Aynı Kişi mi?</label>
            </div>
        </div>
    `;
    
    // Checkbox işlevini tekrar tanımla
    setupInsuredCheckbox();
}

// Kasko/Trafik seçiminde plaka alanını göster/gizle
function updatePlateField() {
    const policyTypeSelect = document.getElementById('policy_type');
    const plateField = document.getElementById('plate_field');
    
    if (!policyTypeSelect || !plateField) return;
    
    const policyType = policyTypeSelect.value.toLowerCase();
    const plateInput = document.getElementById('plate_number');
    
    // Kasko veya Trafik seçiliyse plaka alanını göster ve zorunlu yap
    if (policyType === 'kasko' || policyType === 'trafik') {
        plateField.style.display = 'block';
        if (plateInput) {
            plateInput.setAttribute('required', 'required');
        }
    } else {
        // Diğer poliçe türleri için plaka alanını gizle ve zorunlu olma özelliğini kaldır
        plateField.style.display = 'none';
        if (plateInput) {
            plateInput.removeAttribute('required');
            plateInput.value = ''; // Plaka değerini temizle
        }
    }
}

// Kasko/Trafik seçiminde brüt prim alanını göster/gizle
function updateGrossPremiumField() {
    const policyTypeSelect = document.getElementById('policy_type');
    const grossPremiumGroup = document.getElementById('gross_premium_group');
    
    if (!policyTypeSelect || !grossPremiumGroup) return;
    
    const policyType = policyTypeSelect.value.toLowerCase();
    
    // Kasko veya Trafik seçiliyse brüt prim alanını göster
    if (policyType === 'kasko' || policyType === 'trafik') {
        grossPremiumGroup.style.display = 'block';
    } else {
        // Diğer poliçe türleri için brüt prim alanını gizle
        grossPremiumGroup.style.display = 'none';
        const grossPremiumInput = document.getElementById('gross_premium');
        if (grossPremiumInput) {
            grossPremiumInput.value = ''; // Brüt prim değerini temizle
        }
    }
}

// AJAX endpoint'i için destek
var ajaxurl = "<?php echo admin_url('admin-ajax.php'); ?>";

// Sayfa yüklendiğinde ve DOM hazır olduğunda yeniden çalıştır
window.addEventListener('load', function() {
    setTimeout(function() {
        updatePlateField(); // Poliçe türüne göre plaka alanını kontrol et
        updateGrossPremiumField(); // Poliçe türüne göre brüt prim alanını kontrol et
        
        // Sigorta şirketi ve poliçe kategorisi için varsayılan değerler
        const insuranceCompany = document.getElementById('insurance_company');
        const policyCategory = document.getElementById('policy_category');
        
        // Sigorta şirketi seçili değilse ve DB'den gelen değer varsa otomatik doldur
        <?php if (isset($policy) && !empty($policy->insurance_company)): ?>
        if (insuranceCompany && !insuranceCompany.value) {
            // Şirket adının tam eşleşmesini kontrol et
            const companyOptions = Array.from(insuranceCompany.options);
            for (let i = 0; i < companyOptions.length; i++) {
                if (companyOptions[i].value.toLowerCase() === '<?php echo strtolower($policy->insurance_company); ?>') {
                    insuranceCompany.selectedIndex = i;
                    break;
                }
            }
        }
        <?php endif; ?>
        
        // Poliçe kategorisi seçili değilse ve DB'den gelen değer varsa otomatik doldur
        <?php if (isset($policy) && !empty($policy->policy_category)): ?>
        if (policyCategory && !policyCategory.value) {
            // Kategori adının tam eşleşmesini kontrol et
            const categoryOptions = Array.from(policyCategory.options);
            for (let i = 0; i < categoryOptions.length; i++) {
                if (categoryOptions[i].value.toLowerCase() === '<?php echo strtolower($policy->policy_category); ?>') {
                    policyCategory.selectedIndex = i;
                    break;
                }
            }
        }
        <?php endif; ?>
    }, 100);
});
</script>

<!-- WordPress AJAX handler için gerekli kod -->
<?php
add_action('wp_ajax_get_customer_info', 'get_customer_info_callback');
function get_customer_info_callback() {
    global $wpdb;
    
    // Güvenlik kontrolü (isteğe bağlı)
    // check_ajax_referer('customer_nonce', 'nonce');
    
    $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
    
    if (!$customer_id) {
        wp_send_json_error(['message' => 'Geçersiz müşteri ID']);
        return;
    }
    
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customers_table WHERE id = %d", $customer_id));
    
    if (!$customer) {
        wp_send_json_error(['message' => 'Müşteri bulunamadı']);
        return;
    }
    
    // Müşteri verilerini döndür
    wp_send_json_success([
        'id' => $customer->id,
        'first_name' => $customer->first_name,
        'last_name' => $customer->last_name,
        'tc_identity' => $customer->tc_identity,
        'phone' => $customer->phone,
        'email' => $customer->email,
        'address' => $customer->address
    ]);
}

// Müşteri arama AJAX handler
add_action('wp_ajax_search_customers_for_policy', 'search_customers_for_policy_callback');
add_action('wp_ajax_nopriv_search_customers_for_policy', 'search_customers_for_policy_callback');
function search_customers_for_policy_callback() {
    global $wpdb;
    
    // Hata ayıklama için log
    error_log('🔍 Müşteri arama AJAX çağrısı başladı');
    
    // Güvenlik kontrolü
    if (!wp_verify_nonce($_POST['nonce'], 'search_customers_nonce')) {
        error_log('❌ Güvenlik kontrolü başarısız - nonce doğrulanamadı');
        wp_send_json_error(['message' => 'Güvenlik kontrolü başarısız']);
        return;
    }
    
    $query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';
    error_log('📝 Arama sorgusu: ' . $query);
    
    if (strlen($query) < 2) {
        error_log('❌ Arama sorgusu çok kısa: ' . strlen($query) . ' karakter');
        wp_send_json_error(['message' => 'En az 2 karakter girin']);
        return;
    }
    
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    
    // Arama sorgusu - ad soyad, TC, şirket adı, vergi no'ya göre ara
    $search_query = $wpdb->prepare("
        SELECT id, first_name, last_name, tc_identity, phone, email, company_name, tax_number,
               spouse_name, spouse_tc_identity, spouse_birth_date,
               children_count, children_names, children_birth_dates, children_tc_identities
        FROM $customers_table 
        WHERE status = 'aktif' 
        AND (
            CONCAT(first_name, ' ', last_name) LIKE %s 
            OR tc_identity LIKE %s 
            OR company_name LIKE %s 
            OR tax_number LIKE %s
            OR phone LIKE %s
        )
        ORDER BY first_name, last_name 
        LIMIT 10
    ", "%$query%", "%$query%", "%$query%", "%$query%", "%$query%");
    
    error_log('🗄️ SQL sorgusu: ' . $search_query);
    
    $customers = $wpdb->get_results($search_query);
    $customer_count = is_array($customers) ? count($customers) : 0;
    
    error_log('✅ Bulunan müşteri sayısı: ' . $customer_count);
    
    if ($customers) {
        wp_send_json_success($customers);
    } else {
        wp_send_json_success([]);
    }
}

// Aile üyelerini getir AJAX handler
add_action('wp_ajax_get_family_members', 'get_family_members_callback');
add_action('wp_ajax_nopriv_get_family_members', 'get_family_members_callback');
function get_family_members_callback() {
    global $wpdb;
    
    // Hata ayıklama için log
    error_log('👨‍👩‍👧‍👦 Aile üyeleri AJAX çağrısı başladı');
    error_log('POST verisi: ' . print_r($_POST, true));
    
    // Güvenlik kontrolü
    if (!wp_verify_nonce($_POST['nonce'], 'get_family_members_nonce')) {
        error_log('❌ Güvenlik kontrolü başarısız - nonce doğrulanamadı');
        wp_send_json_error(['message' => 'Güvenlik kontrolü başarısız']);
        return;
    }
    
    $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
    error_log('📝 Müşteri ID: ' . $customer_id);
    
    if (!$customer_id) {
        error_log('❌ Geçersiz müşteri ID');
        wp_send_json_error(['message' => 'Geçersiz müşteri ID']);
        return;
    }
    
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    $customer = $wpdb->get_row($wpdb->prepare("
        SELECT spouse_name, spouse_tc_identity, spouse_birth_date,
               children_count, children_names, children_birth_dates, children_tc_identities,
               has_pet, pet_name, pet_type, pet_age
        FROM $customers_table 
        WHERE id = %d
    ", $customer_id));
    
    error_log('👤 Bulunan müşteri verisi: ' . print_r($customer, true));
    
    if (!$customer) {
        wp_send_json_error(['message' => 'Müşteri bulunamadı']);
        return;
    }
    
    $family_members = ['spouse' => null, 'children' => [], 'pet' => null];
    
    // Eş bilgileri
    if (!empty($customer->spouse_name)) {
        $family_members['spouse'] = [
            'name' => $customer->spouse_name,
            'tc_identity' => $customer->spouse_tc_identity,
            'birth_date' => $customer->spouse_birth_date
        ];
    }
    
    // Çocuk bilgileri
    if (!empty($customer->children_names) && $customer->children_count > 0) {
        $children_names = explode(',', $customer->children_names);
        $children_tc_identities = !empty($customer->children_tc_identities) ? 
            explode(',', $customer->children_tc_identities) : [];
        $children_birth_dates = !empty($customer->children_birth_dates) ? 
            explode(',', $customer->children_birth_dates) : [];
        
        for ($i = 0; $i < count($children_names); $i++) {
            $child_name = trim($children_names[$i]);
            if (!empty($child_name)) {
                $family_members['children'][] = [
                    'name' => $child_name,
                    'tc_identity' => isset($children_tc_identities[$i]) ? trim($children_tc_identities[$i]) : '',
                    'birth_date' => isset($children_birth_dates[$i]) ? trim($children_birth_dates[$i]) : ''
                ];
            }
        }
    }

    // Evcil hayvan bilgisi
    if (!empty($customer->has_pet) && $customer->has_pet == 1 && !empty($customer->pet_name)) {
        $family_members['pet'] = [
            'name' => $customer->pet_name,
            'type' => $customer->pet_type,
            'age' => $customer->pet_age
        ];
    }
    
    error_log('✅ Aile üyeleri verisi hazırlandı: ' . print_r($family_members, true));
    wp_send_json_success($family_members);
}
?>
<?php
/**
 * Frontend Müşteri Yönetim Sayfası - Enhanced Premium Version
 * @version 5.1.1 - Filtreleme Hatası Düzeltildi
 * @date 2025-05-30 12:02:00
 * @author anadolubirlik
 * @description Filtreleme sistemi düzeltildi, modern UI korundu
 */

// Güvenlik kontrolü
if (!defined('ABSPATH') || !is_user_logged_in()) {
    echo '<div class="notification-banner notification-error">
        <div class="notification-icon"><i class="fas fa-exclamation-circle"></i></div>
        <div class="notification-content">Bu sayfayı görüntülemek için giriş yapmalısınız.</div>
    </div>';
    return;
}

// Global değişkenler
global $wpdb;
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
$teams_table = $wpdb->prefix . 'insurance_crm_teams';
$users_table = $wpdb->users;

/**
 * BACKWARD COMPATIBILITY FUNCTIONS - Sadece tanımlı değilse oluştur
 */
if (!function_exists('get_current_user_rep_data')) {
    function get_current_user_rep_data() {
        global $wpdb;
        $current_user_id = get_current_user_id();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, role, customer_edit, customer_delete, policy_edit, policy_delete 
             FROM {$wpdb->prefix}insurance_crm_representatives 
             WHERE user_id = %d AND status = 'active'",
            $current_user_id
        ));
    }
}

if (!function_exists('get_user_access_level')) {
    function get_user_access_level($user_role) {
        switch ($user_role) {
            case 'patron':
                return 'patron';
            case 'manager':
                return 'mudur';
            case 'assistant_manager':
                return 'mudur_yardimcisi';
            case 'team_leader':
                return 'ekip_lideri';
            case 'representative':
            default:
                return 'temsilci';
        }
    }
}

// Kullanıcının müşteri üzerinde düzenleme yetkisi var mı?
function can_edit_customer($rep_data, $customer = null) {
    if (!$rep_data) return false;
    
    // Patron her zaman düzenleyebilir
    if ($rep_data->role == 1) return true;
    
    // Müdür, yetki verilmişse düzenleyebilir
    if ($rep_data->role == 2 && $rep_data->customer_edit == 1) {
        return true;
    }
    
    // Müdür Yardımcısı, yetki verilmişse düzenleyebilir
    if ($rep_data->role == 3 && $rep_data->customer_edit == 1) {
        return true;
    }
    
    // Ekip lideri, yetki verilmişse düzenleyebilir
    if ($rep_data->role == 4 && $rep_data->customer_edit == 1) {
        // Ekip liderinin kendi ekibindeki müşterileri düzenleme yetkisi
        if ($customer) {
            // Basitleştirmek için sadece yetkiyi kontrol ediyoruz
            return true;
        }
    }
    
    // Müşteri temsilcisi sadece kendi müşterilerini düzenleyebilir
    if ($rep_data->role == 5 && $customer && $customer->representative_id == $rep_data->id) {
        return true;
    }
    
    return false;
}

// Kullanıcının müşteri üzerinde silme yetkisi var mı?
function can_delete_customer($rep_data, $customer = null) {
    if (!$rep_data) return false;
    
    // Patron her zaman silebilir
    if ($rep_data->role == 1) return true;
    
    // Müdür yetki verilmişse silebilir
    if ($rep_data->role == 2 && $rep_data->customer_delete == 1) {
        return true;
    }
    
    // Müdür Yardımcısı yetki verilmişse silebilir
    if ($rep_data->role == 3 && $rep_data->customer_delete == 1) {
        return true;
    }
    
    // Ekip lideri, yetki verilmişse kendi ekibindeki müşterileri silebilir
    if ($rep_data->role == 4 && $rep_data->customer_delete == 1 && $customer) {
        // Ekip liderinin ekip üyesi müşterilerini silme yetkisi
        return true;
    }
    
    return false;
}

// Ekip liderinin ekip ID'sini ve ekip üyelerini al
function get_team_for_leader($leader_rep_id) {
    if (!$leader_rep_id) return array('team_id' => null, 'members' => array());
    
    $settings = get_option('insurance_crm_settings', array());
    $teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();
    
    foreach ($teams as $team_id => $team) {
        if ($team['leader_id'] == $leader_rep_id) {
            $members = isset($team['members']) ? $team['members'] : array();
            // Kendisini de ekle
            if (!in_array($leader_rep_id, $members)) {
                $members[] = $leader_rep_id;
            }
            return array('team_id' => $team_id, 'members' => array_unique($members));
        }
    }
    
    return array('team_id' => null, 'members' => array($leader_rep_id)); // Sadece lider
}

$current_rep = get_current_user_rep_data();
$current_user_rep_id = $current_rep ? $current_rep->id : 0;
$current_user_id = get_current_user_id();

// Kullanıcı rolünü al - güvenli fonksiyon kontrolü
if (function_exists('get_user_role_in_hierarchy')) {
    $user_role = get_user_role_in_hierarchy($current_user_id);
} else {
    // Fallback - role alanından çevir
    if ($current_rep) {
        switch ($current_rep->role) {
            case 1: $user_role = 'patron'; break;
            case 2: $user_role = 'manager'; break;
            case 3: $user_role = 'assistant_manager'; break;
            case 4: $user_role = 'team_leader'; break;
            case 5: $user_role = 'representative'; break;
            default: $user_role = 'representative'; break;
        }
    } else {
        $user_role = 'representative';
    }
}

// Access level belirle
$access_level = get_user_access_level($user_role);

// Ekip liderinin ekip bilgilerini al
$team_info = array('team_id' => null, 'members' => array());
if ($access_level == 'ekip_lideri') {
    $team_info = get_team_for_leader($current_user_rep_id);
}

// Görünüm tipini kontrol etmek için
$view_type = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'customers';

// İstatistik hesaplamaları için filtreler
$stats_where = "";
$stats_join = "";

// Temsilcilere göre filtreleme (istatistiklerde kullanmak için)
if ($access_level == 'temsilci' && $current_user_rep_id) {
    // Müşteri Temsilcisi: Sadece kendi müşterilerini görebilir
    $stats_where .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
}
elseif ($access_level == 'ekip_lideri') {
    if ($view_type == 'team_customers' && !empty($team_info['members'])) {
        // Ekip Lideri + Ekip Görünümü: Tüm ekibin istatistiklerini göster
        $members = $team_info['members'];
        if (!empty($members)) {
            // Query parametrelerini oluştur - valid members only
            $stats_query_args = array();
            foreach ($members as $member_id) {
                if (!empty($member_id) && is_numeric($member_id)) {
                    $stats_query_args[] = (int) $member_id;
                }
            }
            
            // Placeholder'ları gerçek parametre sayısına göre oluştur
            if (!empty($stats_query_args)) {
                $placeholders = implode(',', array_fill(0, count($stats_query_args), '%d'));
                $stats_join .= " LEFT JOIN $representatives_table r ON c.representative_id = r.id";
                $stats_where .= $wpdb->prepare(" AND c.representative_id IN ($placeholders)", ...$stats_query_args);
            } else {
                $stats_where .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
            }
        } else {
            $stats_where .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
        }
    } else {
        // Müşteri Görünümü: Sadece kendi istatistiklerini göster
        $stats_where .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
    }
}
// Patron, Müdür ve Müdür Yardımcısı tüm müşterileri görebilir (ek filtreleme yok)

// İşlem Bildirileri için session kontrolü
$notice = '';
if (isset($_SESSION['crm_notice'])) {
    $notice = $_SESSION['crm_notice'];
    unset($_SESSION['crm_notice']);
}

// Müşteri silme işlemi
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $customer_id = intval($_GET['id']);
    if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_customer_' . $customer_id)) {
        // Silme yetkisi kontrolü
        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $customers_table WHERE id = %d", $customer_id
        ));
        
        if (!$customer) {
            $notice = '<div class="notification-banner notification-error">
                <div class="notification-icon"><i class="fas fa-exclamation-circle"></i></div>
                <div class="notification-content">Müşteri bulunamadı.</div>
                <button class="notification-close"><i class="fas fa-times"></i></button>
            </div>';
        } else {
            $can_delete = can_delete_customer($current_rep, $customer);
            
            if ($can_delete) {
                // Silme işlemi (pasife çekme)
                $wpdb->update(
                    $customers_table,
                    array('status' => 'pasif'),
                    array('id' => $customer_id)
                );
                
                // Log kaydı tutma
                $user_id = get_current_user_id();
                $user_info = get_userdata($user_id);
                $log_message = sprintf(
                    'Müşteri ID: %d, Ad: %s %s, %s (ID: %d) tarafından pasife alındı.',
                    $customer_id,
                    $customer->first_name,
                    $customer->last_name,
                    $user_info->display_name,
                    $user_id
                );
                error_log($log_message);
                
                $notice = '<div class="notification-banner notification-success">
                    <div class="notification-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="notification-content">Müşteri pasif duruma getirildi.</div>
                    <button class="notification-close"><i class="fas fa-times"></i></button>
                </div>';
            } else {
                $notice = '<div class="notification-banner notification-error">
                    <div class="notification-icon"><i class="fas fa-exclamation-circle"></i></div>
                    <div class="notification-content">Bu müşteriyi pasife alma yetkiniz yok.</div>
                    <button class="notification-close"><i class="fas fa-times"></i></button>
                </div>';
            }
        }
    }
}

// Filtreler ve Sayfalama
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 15;
$offset = ($current_page - 1) * $per_page;

// FİLTRELEME PARAMETRELERİ - Düzeltilmiş
$search = isset($_GET['customer_name']) ? sanitize_text_field($_GET['customer_name']) : '';

$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$category_filter = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';
$representative_filter = isset($_GET['rep_id']) ? intval($_GET['rep_id']) : 0;
$first_registrar_filter = isset($_GET['first_reg_id']) ? intval($_GET['first_reg_id']) : 0;

// GELİŞMİŞ FİLTRELER - Düzeltilmiş
$gender_filter = isset($_GET['gender']) ? sanitize_text_field($_GET['gender']) : '';
$is_pregnant_filter = isset($_GET['is_pregnant']) && $_GET['is_pregnant'] === '1' ? '1' : '';
$has_children_filter = isset($_GET['has_children']) && $_GET['has_children'] === '1' ? '1' : '';
$has_spouse_filter = isset($_GET['has_spouse']) && $_GET['has_spouse'] === '1' ? '1' : '';
$has_vehicle_filter = isset($_GET['has_vehicle']) && $_GET['has_vehicle'] === '1' ? '1' : '';
$owns_home_filter = isset($_GET['owns_home']) && $_GET['owns_home'] === '1' ? '1' : '';
$has_pet_filter = isset($_GET['has_pet']) && $_GET['has_pet'] === '1' ? '1' : '';
$child_tc_filter = isset($_GET['child_tc']) ? sanitize_text_field($_GET['child_tc']) : '';
$spouse_tc_filter = isset($_GET['spouse_tc']) ? sanitize_text_field($_GET['spouse_tc']) : '';
$customer_tc_filter = isset($_GET['customer_tc']) ? sanitize_text_field($_GET['customer_tc']) : '';
$customer_vkn_filter = isset($_GET['customer_vkn']) ? sanitize_text_field($_GET['customer_vkn']) : '';

// Sorgu oluştur
$base_query = "FROM $customers_table c 
               LEFT JOIN $representatives_table r ON c.representative_id = r.id
               LEFT JOIN $users_table u ON r.user_id = u.ID
               LEFT JOIN $representatives_table fr ON c.ilk_kayit_eden = fr.id
               LEFT JOIN $users_table fu ON fr.user_id = fu.ID
               WHERE 1=1";

// Rol tabanlı erişim kontrolü
if ($access_level == 'temsilci' && $current_user_rep_id) {
    // Müşteri Temsilcisi: Sadece kendi müşterilerini görebilir
    $base_query .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
}
elseif ($access_level == 'ekip_lideri') {
    if ($view_type == 'team_customers' && !empty($team_info['members'])) {
        // Ekip Görünümü: Ekip Lideri ekibindeki tüm temsilcilerin müşterilerini görebilir
        $members = $team_info['members'];
        if (!empty($members)) {
            // Query parametrelerini oluştur - valid members only
            $query_args = array();
            foreach ($members as $member_id) {
                if (!empty($member_id) && is_numeric($member_id)) {
                    $query_args[] = (int) $member_id;
                }
            }
            
            // Placeholder'ları gerçek parametre sayısına göre oluştur
            if (!empty($query_args)) {
                $placeholders = implode(',', array_fill(0, count($query_args), '%d'));
                $base_query .= $wpdb->prepare(" AND c.representative_id IN ($placeholders)", ...$query_args);
            } else {
                $base_query .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
            }
        } else {
            // Ekipte üye yoksa sadece kendi müşterilerini göster
            $base_query .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
        }
    } else {
        // Normal Görünüm: Ekip Lideri sadece kendi müşterilerini görür
        $base_query .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
    }
}

// Patron, Müdür ve Müdür Yardımcısı tüm müşterileri görebilir (ek filtreleme yok)

// Arama filtresi - Müşteri adı ile arama
if (!empty($search)) {
    $base_query .= $wpdb->prepare(
        " AND (
            c.first_name LIKE %s 
            OR c.last_name LIKE %s 
            OR CONCAT(c.first_name, ' ', c.last_name) LIKE %s
        )",
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%'
    );
}



// Durum ve kategori filtreleri
if (!empty($status_filter)) {
    $base_query .= $wpdb->prepare(" AND c.status = %s", $status_filter);
}

if (!empty($category_filter)) {
    $base_query .= $wpdb->prepare(" AND c.category = %s", $category_filter);
}

if ($representative_filter > 0) {
    // Temsilci filtreleme yetkisi kontrol et
    $can_filter_by_rep = true;
    
    if ($access_level == 'temsilci') {
        // Temsilci sadece kendini filtreleyebilir
        if ($representative_filter != $current_user_rep_id) {
            $can_filter_by_rep = false;
            $notice .= '<div class="notification-banner notification-warning">
                <div class="notification-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="notification-content">Sadece kendi müşterilerinizi görebilirsiniz. Filtreleme göz ardı edildi.</div>
                <button class="notification-close"><i class="fas fa-times"></i></button>
            </div>';
        }
    } 
    else if ($access_level == 'ekip_lideri') {
        // Ekip lideri sadece kendi ekibindeki temsilcileri filtreleyebilir
        if (!in_array($representative_filter, $team_info['members'])) {
            $can_filter_by_rep = false;
            $notice .= '<div class="notification-banner notification-warning">
                <div class="notification-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="notification-content">Seçtiğiniz temsilci sizin ekibinize ait değil. Filtreleme göz ardı edildi.</div>
                <button class="notification-close"><i class="fas fa-times"></i></button>
            </div>';
        }
    }
    
    if ($can_filter_by_rep) {
        $base_query .= $wpdb->prepare(" AND c.representative_id = %d", $representative_filter);
    }
}

// İlk Kayıt Eden filtresi
if ($first_registrar_filter > 0) {
    // İlk Kayıt Eden filtreleme yetkisi kontrol et
    $can_filter_by_first_reg = true;
    
    if ($access_level == 'temsilci') {
        // Temsilci sadece kendini filtreleyebilir
        if ($first_registrar_filter != $current_user_rep_id) {
            $can_filter_by_first_reg = false;
            $notice .= '<div class="notification-banner notification-warning">
                <div class="notification-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="notification-content">Sadece kendi kaydettiğiniz müşterileri görebilirsiniz. Filtreleme göz ardı edildi.</div>
                <button class="notification-close"><i class="fas fa-times"></i></button>
            </div>';
        }
    } 
    else if ($access_level == 'ekip_lideri') {
        // Ekip lideri sadece kendi ekibindeki temsilcileri filtreleyebilir
        if (!in_array($first_registrar_filter, $team_info['members'])) {
            $can_filter_by_first_reg = false;
            $notice .= '<div class="notification-banner notification-warning">
                <div class="notification-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="notification-content">Seçtiğiniz ilk kayıt eden sizin ekibinize ait değil. Filtreleme göz ardı edildi.</div>
                <button class="notification-close"><i class="fas fa-times"></i></button>
            </div>';
        }
    }
    
    if ($can_filter_by_first_reg) {
        $base_query .= $wpdb->prepare(" AND c.ilk_kayit_eden = %d", $first_registrar_filter);
    }
}

// GELİŞMİŞ FİLTRELER UYGULAMASI
if (!empty($gender_filter)) {
    $base_query .= $wpdb->prepare(" AND c.gender = %s", $gender_filter);
}

// Gebe müşteriler filtresi
if (!empty($is_pregnant_filter)) {
    $base_query .= " AND c.is_pregnant = 1 AND c.gender = 'female'";
}

// Çocuklu müşteriler filtresi
if (!empty($has_children_filter)) {
    $base_query .= " AND (c.children_count > 0 OR c.children_names IS NOT NULL)";
}

// Eşi olan müşteriler filtresi
if (!empty($has_spouse_filter)) {
    $base_query .= " AND c.spouse_name IS NOT NULL AND c.spouse_name != ''";
}

// Aracı olan müşteriler filtresi
if (!empty($has_vehicle_filter)) {
    $base_query .= " AND c.has_vehicle = 1";
}

// Ev sahibi olan müşteriler filtresi
if (!empty($owns_home_filter)) {
    $base_query .= " AND c.owns_home = 1";
}

// Evcil hayvan sahibi olan müşteriler filtresi
if (!empty($has_pet_filter)) {
    $base_query .= " AND c.has_pet = 1";
}

// Çocuk TC'si ile arama
if (!empty($child_tc_filter)) {
    $base_query .= $wpdb->prepare(" AND c.children_tc_identities LIKE %s", '%' . $wpdb->esc_like($child_tc_filter) . '%');
}

// Eş TC'si ile arama
if (!empty($spouse_tc_filter)) {
    $base_query .= $wpdb->prepare(" AND c.spouse_tc_identity = %s", $spouse_tc_filter);
}

// Müşteri TC'si ile arama
if (!empty($customer_tc_filter)) {
    $base_query .= $wpdb->prepare(" AND c.tc_identity = %s", $customer_tc_filter);
}

// Müşteri VKN'si ile arama
if (!empty($customer_vkn_filter)) {
    $base_query .= $wpdb->prepare(" AND c.tax_number = %s", $customer_vkn_filter);
}

// -----------------------------------------------------
// İSTATİSTİK VERİLERİ İÇİN SORGULAR
// -----------------------------------------------------

// 1. Toplam müşteri sayısı
$total_customers_query = "SELECT COUNT(*) FROM $customers_table c $stats_join WHERE 1=1 $stats_where";
$total_customers = $wpdb->get_var($total_customers_query);

// 2. Bu ay eklenen müşteriler
$this_month_start = date('Y-m-01 00:00:00');
$new_customers_query = $wpdb->prepare(
    "SELECT COUNT(*) FROM $customers_table c $stats_join 
    WHERE c.created_at >= %s $stats_where",
    $this_month_start
);
$new_customers_this_month = $wpdb->get_var($new_customers_query);

// 3. Aktif/pasif/belirsiz müşteri dağılımı
$status_aktif_query = "SELECT COUNT(*) FROM $customers_table c $stats_join WHERE c.status = 'aktif' $stats_where";
$status_aktif = $wpdb->get_var($status_aktif_query);

$status_pasif_query = "SELECT COUNT(*) FROM $customers_table c $stats_join WHERE c.status = 'pasif' $stats_where";
$status_pasif = $wpdb->get_var($status_pasif_query);

$status_belirsiz_query = "SELECT COUNT(*) FROM $customers_table c $stats_join WHERE c.status = 'belirsiz' $stats_where";
$status_belirsiz = $wpdb->get_var($status_belirsiz_query);

// 4. Bireysel/kurumsal müşteri dağılımı
$category_bireysel_query = "SELECT COUNT(*) FROM $customers_table c $stats_join WHERE c.category = 'bireysel' $stats_where";
$category_bireysel = $wpdb->get_var($category_bireysel_query);

$category_kurumsal_query = "SELECT COUNT(*) FROM $customers_table c $stats_join WHERE c.category = 'kurumsal' $stats_where";
$category_kurumsal = $wpdb->get_var($category_kurumsal_query);

// 5. Son 6 aydaki müşteri artış trendi
$months = array();
$trend_data = array();

for ($i = 5; $i >= 0; $i--) {
    $month_start = date('Y-m-01 00:00:00', strtotime("-$i month"));
    $month_end = date('Y-m-t 23:59:59', strtotime("-$i month"));
    $month_label = date('M', strtotime("-$i month"));
    
    $trend_query = $wpdb->prepare(
        "SELECT COUNT(*) FROM $customers_table c $stats_join
        WHERE c.created_at BETWEEN %s AND %s $stats_where",
        $month_start, $month_end
    );
    
    $customer_count = $wpdb->get_var($trend_query);
    
    $months[] = $month_label;
    $trend_data[] = intval($customer_count);
}

// Toplam müşteri sayısını al (filtreli sayfa için)
$total_items = $wpdb->get_var("SELECT COUNT(DISTINCT c.id) " . $base_query);

// Sıralama
$orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'c.created_at';
$order = isset($_GET['order']) && in_array(strtoupper($_GET['order']), array('ASC', 'DESC')) ? strtoupper($_GET['order']) : 'DESC';

// Müşterileri getir
$customers = $wpdb->get_results("
    SELECT c.*, CONCAT(c.first_name, ' ', c.last_name) AS customer_name, 
           u.display_name as representative_name, 
           fu.display_name as first_registrar_name
    " . $base_query . " 
    ORDER BY $orderby $order 
    LIMIT $per_page OFFSET $offset
");

// Temsilcileri al (erişim seviyesine göre filtrelenmiş)
$representatives = array();
if ($access_level == 'patron' || $access_level == 'mudur' || $access_level == 'mudur_yardimcisi') {
    // Patron, Müdür ve Müdür Yardımcısı tüm temsilcileri görebilir
    $representatives = $wpdb->get_results("
        SELECT r.id, u.display_name 
        FROM $representatives_table r
        JOIN $users_table u ON r.user_id = u.ID
        WHERE r.status = 'active'
        ORDER BY u.display_name ASC
    ");
} elseif ($access_level == 'ekip_lideri' && !empty($team_info['members'])) {
    // Ekip lideri sadece kendi ekibindeki temsilcileri görebilir
    $members = $team_info['members'];
    if (!empty($members)) {
        $placeholders = implode(',', array_fill(0, count($members), '%d'));
        
        // Query parametrelerini oluştur
        $query_args = array();
        foreach ($members as $member_id) {
            $query_args[] = $member_id;
        }
        
        $representatives = $wpdb->get_results($wpdb->prepare("
            SELECT r.id, u.display_name 
            FROM $representatives_table r
            JOIN $users_table u ON r.user_id = u.ID
            WHERE r.status = 'active' AND r.id IN ($placeholders)
            ORDER BY u.display_name ASC
        ", ...$query_args));
    }
} elseif ($access_level == 'temsilci' && $current_user_rep_id) {
    // Temsilci sadece kendisini görebilir
    $representatives = $wpdb->get_results($wpdb->prepare("
        SELECT r.id, u.display_name 
        FROM $representatives_table r
        JOIN $users_table u ON r.user_id = u.ID
        WHERE r.status = 'active' AND r.id = %d
        ORDER BY u.display_name ASC
    ", $current_user_rep_id));
}

// Sayfalama
$total_pages = ceil($total_items / $per_page);

// Aktif action belirle
$current_action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
$show_list = ($current_action !== 'view' && $current_action !== 'edit' && $current_action !== 'new');

// Filtreleme yapıldı mı kontrolü
$is_filtered = !empty($search) || 
               !empty($status_filter) || 
               !empty($category_filter) || 
               $representative_filter > 0 || 
               $first_registrar_filter > 0 ||
               !empty($gender_filter) || 
               !empty($is_pregnant_filter) || 
               !empty($has_children_filter) || 
               !empty($has_spouse_filter) || 
               !empty($has_vehicle_filter) || 
               !empty($owns_home_filter) || 
               !empty($has_pet_filter) || 
               !empty($child_tc_filter) || 
               !empty($spouse_tc_filter) ||
               !empty($customer_tc_filter) ||
               !empty($customer_vkn_filter);

// Aktif filtre sayısını hesapla
$active_filter_count = 0;
if (!empty($search)) $active_filter_count++;
if (!empty($status_filter)) $active_filter_count++;
if (!empty($category_filter)) $active_filter_count++;
if ($representative_filter > 0) $active_filter_count++;
if ($first_registrar_filter > 0) $active_filter_count++;
if (!empty($gender_filter)) $active_filter_count++;
if (!empty($is_pregnant_filter)) $active_filter_count++;
if (!empty($has_children_filter)) $active_filter_count++;
if (!empty($has_spouse_filter)) $active_filter_count++;
if (!empty($has_vehicle_filter)) $active_filter_count++;
if (!empty($owns_home_filter)) $active_filter_count++;
if (!empty($has_pet_filter)) $active_filter_count++;
if (!empty($child_tc_filter)) $active_filter_count++;
if (!empty($spouse_tc_filter)) $active_filter_count++;
if (!empty($customer_tc_filter)) $active_filter_count++;
if (!empty($customer_vkn_filter)) $active_filter_count++;

// İstatistikleri hesapla
$statistics = [
    'total' => $total_customers,
    'new_this_month' => $new_customers_this_month,
    'active' => $status_aktif,
    'passive' => $status_pasif,
    'uncertain' => $status_belirsiz,
    'individual' => $category_bireysel,
    'corporate' => $category_kurumsal
];

$debug_mode = false; // Geliştirici modu - aktifleştirirseniz SQL sorgularını gösterir
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Müşteri Yönetimi - Modern CRM v5.1.1</title>
    
    <!-- External Libraries -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Load jQuery BEFORE Chart.js -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.js"></script>
</head>
<body>

<div class="modern-crm-container" id="customers-container" <?php echo !$show_list ? 'style="display:none;"' : ''; ?>>
    
    <?php if ($notice): ?>
    <?php echo $notice; ?>
    <?php endif; ?>
    
    <?php if ($debug_mode): ?>
    <div class="debug-info">
        <h3>Debug Bilgileri</h3>
        <pre>
            Total Customers Query: <?php echo esc_html($total_customers_query); ?>
            Result: <?php echo $total_customers; ?>
            
            Status Aktif Query: <?php echo esc_html($status_aktif_query); ?>
            Result: <?php echo $status_aktif; ?>
            
            Category Bireysel Query: <?php echo esc_html($category_bireysel_query); ?>
            Result: <?php echo $category_bireysel; ?>
            
            Category Kurumsal Query: <?php echo esc_html($category_kurumsal_query); ?>
            Result: <?php echo $category_kurumsal; ?>
            
            Access Level: <?php echo esc_html($access_level); ?>
            User Role: <?php echo esc_html($user_role); ?>
            User Rep ID: <?php echo $current_user_rep_id; ?>
        </pre>
    </div>
    <?php endif; ?>
    
    <!-- Header Section -->
    <header class="crm-header">
        <div class="header-content">
            <div class="title-section">
                <div class="page-title">
                    <i class="fas fa-users"></i>
                    <h1>Müşteri Yönetimi</h1>
                    <span class="version-badge">v5.1.1</span>
                </div>
                <div class="user-badge">
                    <span class="role-badge">
                        <i class="fas fa-user-shield"></i>
                        <?php 
                        $role_names = [
                            'patron' => 'Patron',
                            'mudur' => 'Müdür', 
                            'mudur_yardimcisi' => 'Müdür Yardımcısı',
                            'ekip_lideri' => 'Ekip Lideri',
                            'temsilci' => 'Müşteri Temsilcisi'
                        ];
                        echo esc_html($role_names[$access_level] ?? 'Bilinmiyor');
                        ?>
                    </span>
                </div>
            </div>
            
            <div class="header-actions">
                <?php if ($access_level == 'patron' || $access_level == 'mudur' || $access_level == 'mudur_yardimcisi' || $access_level == 'ekip_lideri'): ?>
                <div class="view-toggle">
                    <a href="?view=customers" 
                       class="view-btn <?php echo $view_type !== 'team_customers' ? 'active' : ''; ?>">
                        <i class="fas fa-user"></i>
                        <span>Kişisel</span>
                    </a>
                    <a href="?view=team_customers" 
                       class="view-btn <?php echo $view_type === 'team_customers' ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i>
                        <span>Ekip</span>
                    </a>
                </div>
                <?php endif; ?>

                <div class="filter-controls">
                    <button type="button" id="filterToggle" class="btn btn-outline filter-toggle">
                        <i class="fas fa-filter"></i>
                        <span>Filtrele</span>
                        <?php if ($active_filter_count > 0): ?>
                        <span class="filter-count"><?php echo $active_filter_count; ?></span>
                        <?php endif; ?>
                        <i class="fas fa-chevron-down chevron"></i>
                    </button>
                    
                    <?php if ($active_filter_count > 0): ?>
                    <a href="?view=<?php echo esc_attr($view_type); ?>" class="btn btn-ghost clear-filters">
                        <i class="fas fa-times"></i>
                        <span>Temizle</span>
                    </a>
                    <?php endif; ?>
                </div>

                <?php if ($current_rep && ($current_rep->role == 1 || $current_rep->role == 2 || $current_rep->role == 3 || $current_rep->role == 4 || $current_rep->role == 5)): ?>
                <a href="?view=<?php echo esc_attr($view_type); ?>&action=new" class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    <span>Yeni Müşteri</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Date Filter Section - Same style as policies.php -->
    <div class="date-filter-section">
        <form method="GET" class="date-filter-form" id="customerSearchForm">
            <input type="hidden" name="view" value="<?php echo esc_attr($view_type); ?>">
            

        </form>
    </div>

    <!-- Filters Section -->
    <section class="filters-section <?php echo $active_filter_count === 0 ? 'hidden' : ''; ?>" id="filtersSection">
        <div class="filters-container">
            <form method="get" class="filters-form">
                <input type="hidden" name="view" value="<?php echo esc_attr($view_type); ?>">
                
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="filter_search">Müşteri Adı / Firma Adı</label>
                        <input type="text" id="filter_search" name="customer_name" 
                               value="<?php echo esc_attr($search); ?>" 
                               placeholder="Müşteri Ad Soyad veya Kurumsal Firma Adı..." class="form-input">
                    </div>

                    <div class="filter-group">
                        <label for="filter_customer_tc">TC Kimlik No / VKN</label>
                        <input type="text" id="filter_customer_tc" name="customer_tc" 
                               value="<?php echo esc_attr($customer_tc_filter); ?>" 
                               placeholder="TC Kimlik No veya VKN..." class="form-input">
                    </div>

                    <div class="filter-group">
                        <label for="filter_customer_vkn">VKN ile Ara</label>
                        <input type="text" id="filter_customer_vkn" name="customer_vkn" 
                               value="<?php echo esc_attr($customer_vkn_filter); ?>" 
                               placeholder="Vergi Kimlik Numarası ile ara..." class="form-input">
                    </div>

                    <div class="filter-group">
                        <label for="filter_status">Durum</label>
                        <select id="filter_status" name="status" class="form-select">
                            <option value="">Tüm Durumlar</option>
                            <option value="aktif" <?php selected($status_filter, 'aktif'); ?>>Aktif</option>
                            <option value="pasif" <?php selected($status_filter, 'pasif'); ?>>Pasif</option>
                            <option value="belirsiz" <?php selected($status_filter, 'belirsiz'); ?>>Belirsiz</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="filter_category">Kategori</label>
                        <select id="filter_category" name="category" class="form-select">
                            <option value="">Tüm Kategoriler</option>
                            <option value="bireysel" <?php selected($category_filter, 'bireysel'); ?>>Bireysel</option>
                            <option value="kurumsal" <?php selected($category_filter, 'kurumsal'); ?>>Kurumsal</option>
                        </select>
                    </div>

                    <?php if (!empty($representatives)): ?>
                    <div class="filter-group">
                        <label for="filter_rep_id">Temsilci</label>
                        <select id="filter_rep_id" name="rep_id" class="form-select">
                            <option value="">Tüm Temsilciler</option>
                            <?php foreach ($representatives as $rep): ?>
                            <option value="<?php echo $rep->id; ?>" <?php selected($representative_filter, $rep->id); ?>>
                                <?php echo esc_html($rep->display_name); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="filter_first_reg_id">İlk Kayıt Eden</label>
                        <select id="filter_first_reg_id" name="first_reg_id" class="form-select">
                            <option value="">Tüm Kayıt Edenler</option>
                            <?php foreach ($representatives as $rep): ?>
                            <option value="<?php echo $rep->id; ?>" <?php selected($first_registrar_filter, $rep->id); ?>>
                                <?php echo esc_html($rep->display_name); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="filter-group">
                        <label for="filter_gender">Cinsiyet</label>
                        <select id="filter_gender" name="gender" class="form-select">
                            <option value="">Seçiniz</option>
                            <option value="male" <?php selected($gender_filter, 'male'); ?>>Erkek</option>
                            <option value="female" <?php selected($gender_filter, 'female'); ?>>Kadın</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="filter_spouse_tc">Eş TC Kimlik No</label>
                        <input type="text" id="filter_spouse_tc" name="spouse_tc" 
                               value="<?php echo esc_attr($spouse_tc_filter); ?>" 
                               placeholder="Eş TC Kimlik ile ara..." class="form-input">
                    </div>

                    <div class="filter-group">
                        <label for="filter_child_tc">Çocuk TC Kimlik No</label>
                        <input type="text" id="filter_child_tc" name="child_tc" 
                               value="<?php echo esc_attr($child_tc_filter); ?>" 
                               placeholder="Çocuk TC Kimlik ile ara..." class="form-input">
                    </div>
                </div>

                <div class="advanced-filters">
                    <div class="filter-section">
                        <h4>Özel Filtreler</h4>
                        <div class="checkbox-filters">
                            <div class="checkbox-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="is_pregnant" value="1" <?php checked($is_pregnant_filter, '1'); ?>>
                                    <span class="checkmark"></span>
                                    Sadece gebe müşteriler
                                </label>
                            </div>
                            
                            <div class="checkbox-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="has_spouse" value="1" <?php checked($has_spouse_filter, '1'); ?>>
                                    <span class="checkmark"></span>
                                    Eşi olanlar
                                </label>
                            </div>
                            
                            <div class="checkbox-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="has_children" value="1" <?php checked($has_children_filter, '1'); ?>>
                                    <span class="checkmark"></span>
                                    Çocuğu olanlar
                                </label>
                            </div>
                            
                            <div class="checkbox-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="has_vehicle" value="1" <?php checked($has_vehicle_filter, '1'); ?>>
                                    <span class="checkmark"></span>
                                    Aracı olanlar
                                </label>
                            </div>
                            
                            <div class="checkbox-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="owns_home" value="1" <?php checked($owns_home_filter, '1'); ?>>
                                    <span class="checkmark"></span>
                                    Ev sahibi olanlar
                                </label>
                            </div>
                            
                            <div class="checkbox-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="has_pet" value="1" <?php checked($has_pet_filter, '1'); ?>>
                                    <span class="checkmark"></span>
                                    Evcil hayvanı olanlar
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="filters-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i>
                        <span>Filtrele</span>
                    </button>
                    <a href="?view=<?php echo esc_attr($view_type); ?>" class="btn btn-outline">
                        <i class="fas fa-undo"></i>
                        <span>Sıfırla</span>
                    </a>
                </div>
            </form>
        </div>
    </section>

    <!-- Statistics Dashboard -->
    <section class="dashboard-section" id="dashboardSection" <?php echo $active_filter_count > 0 ? 'style="display:none;"' : ''; ?>>
        <div class="stats-cards">
            <div class="stat-card primary">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-content">
                    <h3>Toplam Müşteri</h3>
                    <div class="stat-value"><?php echo number_format($statistics['total']); ?></div>
                    <div class="stat-subtitle">
                        <?php 
                        switch($access_level) {
                            case 'patron':
                            case 'mudur':
                            case 'mudur_yardimcisi':
                                echo 'Tüm müşteriler';
                                break;
                            case 'ekip_lideri':
                                if ($view_type == 'team_customers') {
                                    echo 'Ekibinizdeki müşteriler';
                                } else {
                                    echo 'Sizin müşterileriniz';
                                }
                                break;
                            case 'temsilci':
                                echo 'Sizin müşterileriniz';
                                break;
                        }
                        ?>
                    </div>
                </div>
            </div>

            <div class="stat-card success">
                <div class="stat-icon">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div class="stat-content">
                    <h3>Bu Ay Eklenen</h3>
                    <div class="stat-value"><?php echo number_format($statistics['new_this_month']); ?></div>
                    <div class="stat-subtitle">
                        <?php echo date('F Y'); ?> ayında
                    </div>
                </div>
            </div>

            <div class="stat-card info">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <h3>Aktif Müşteriler</h3>
                    <div class="stat-value">
                        <?php 
                        echo number_format($statistics['active']);
                        $aktif_oran = $statistics['total'] > 0 ? round($statistics['active'] / $statistics['total'] * 100) : 0;
                        echo ' <span class="stat-percent">(' . $aktif_oran . '%)</span>';
                        ?>
                    </div>
                    <div class="stat-subtitle">
                        Toplam müşterilerin oranı
                    </div>
                </div>
            </div>

            <div class="stat-card warning">
                <div class="stat-icon">
                    <i class="fas fa-building"></i>
                </div>
                <div class="stat-content">
                    <h3>Bireysel / Kurumsal</h3>
                    <div class="stat-value">
                        <?php echo number_format($statistics['individual']) . ' / ' . number_format($statistics['corporate']); ?>
                    </div>
                    <div class="stat-subtitle">
                        <?php 
                        $bireysel_oran = $statistics['total'] > 0 ? round($statistics['individual'] / $statistics['total'] * 100) : 0;
                        echo 'Bireysel: %' . $bireysel_oran . ' - Kurumsal: %' . (100 - $bireysel_oran);
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="charts-section">
            <div class="section-header">
                <h2>
                    <i class="fas fa-chart-pie"></i>
                    Detaylı İstatistikler
                </h2>
                <button type="button" id="chartsToggle" class="btn btn-ghost">
                    <i class="fas fa-chevron-up"></i>
                </button>
            </div>
            
            <div class="charts-container" id="chartsContainer">
                <div class="chart-grid">
                    <div class="chart-item">
                        <h4>Müşteri Türü Dağılımı</h4>
                        <div class="chart-canvas">
                            <canvas id="categoryChart"></canvas>
                        </div>
                    </div>
                    
                    <div class="chart-item">
                        <h4>Müşteri Durumu Dağılımı</h4>
                        <div class="chart-canvas">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                    
                    <div class="chart-item">
                        <h4>Son 6 Ay Müşteri Artışı</h4>
                        <div class="chart-canvas">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php 
    // Include dashboard functions for birthday functionality
    require_once(dirname(__FILE__) . '/../../includes/dashboard-functions.php');
    
    // Get birthday data for current user
    $birthday_stat_data = get_todays_birthday_customers_by_role(get_current_user_id());
    
    // Get site appearance settings for birthday panel colors
    $site_settings = get_option('insurance_crm_settings', array());
    $secondary_color = isset($site_settings['site_appearance']['secondary_color']) ? $site_settings['site_appearance']['secondary_color'] : '#ffd93d';
    ?>

    <!-- Birthday Celebrations Section -->
    <section class="birthday-celebrations-section">
        <div class="section-header">
            <h2>
                <i class="fas fa-birthday-cake"></i>
                Doğum Günü Kutlamaları
            </h2>
            <div class="birthday-header-controls">
                <span class="birthday-count-badge"><?php echo $birthday_stat_data['total']; ?> müşteri</span>
                <button type="button" id="birthdayToggle" class="btn btn-ghost">
                    <i class="fas fa-chevron-down"></i> Göster
                </button>
            </div>
        </div>
        
        <div class="birthday-panel-container" id="birthdayPanelContainer" style="display: none;">
            <?php if ($birthday_stat_data['total'] > 0): ?>
                <div class="birthday-cards-grid">
                    <?php foreach ($birthday_stat_data['customers'] as $customer): ?>
                        <div class="birthday-customer-card" data-customer-id="<?php echo esc_attr($customer->id); ?>">
                            <div class="birthday-card-header">
                                <div class="birthday-customer-avatar">
                                    <div class="birthday-avatar-circle">
                                        <i class="fas fa-birthday-cake"></i>
                                    </div>
                                </div>
                                <div class="birthday-card-title">
                                    <h4><?php echo esc_html($customer->full_name); ?></h4>
                                    <?php if ($birthday_stat_data['user_role'] === 'admin' && !empty($customer->rep_name)): ?>
                                        <span class="customer-representative">Temsilci: <?php echo esc_html($customer->rep_name); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="birthday-card-body">
                                <div class="birthday-card-info">
                                    <?php if ($customer->age): ?>
                                        <div class="info-item">
                                            <i class="fas fa-calendar-alt"></i>
                                            <span><?php echo esc_html($customer->age); ?> yaşında</span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="info-item">
                                        <i class="fas fa-calendar-day"></i>
                                        <span><?php echo esc_html($customer->birth_date_formatted); ?></span>
                                    </div>
                                    <?php if (!empty($customer->phone)): ?>
                                        <div class="info-item">
                                            <i class="fas fa-phone"></i>
                                            <span><?php echo esc_html($customer->phone); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($customer->email)): ?>
                                        <div class="info-item">
                                            <i class="fas fa-envelope"></i>
                                            <span><?php echo esc_html($customer->email); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="birthday-card-footer">
                                <?php if (!empty($customer->email)): ?>
                                    <button class="birthday-celebrate-btn" onclick="sendBirthdayEmail(<?php echo esc_attr($customer->id); ?>, '<?php echo esc_js($customer->full_name); ?>')">
                                        <i class="fas fa-gift"></i>
                                        <span>Kutla</span>
                                    </button>
                                <?php else: ?>
                                    <span class="no-email-notice">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        E-posta yok
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="birthday-empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-birthday-cake"></i>
                    </div>
                    <h4>Bugün doğum günü olan müşteri yok</h4>
                    <p>Müşterilerinizin doğum günü bilgilerini ekleyerek özel kutlama e-postaları gönderebilirsiniz.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <style>
    .birthday-celebrations-section {
        margin-bottom: 2rem;
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        padding: 0;
        overflow: hidden;
    }

    .birthday-celebrations-section .section-header {
        background: linear-gradient(135deg, <?php echo esc_attr($secondary_color); ?> 0%, #ff6b6b 100%);
        color: white;
        padding: 1.5rem;
        display: flex;
        justify-content: between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .birthday-celebrations-section .section-header h2 {
        color: white;
        margin: 0;
        font-size: 1.25rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .birthday-header-controls {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-left: auto;
    }

    .birthday-count-badge {
        background: rgba(255, 255, 255, 0.2);
        padding: 0.5rem 1rem;
        border-radius: 20px;
        font-size: 0.875rem;
        font-weight: 500;
        border: 1px solid rgba(255, 255, 255, 0.3);
    }

    .birthday-panel-container {
        padding: 1.5rem;
    }

    .birthday-cards-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 1.5rem;
    }

    .birthday-customer-card {
        background: #f8f9fa;
        border-radius: 12px;
        padding: 1.25rem;
        border-left: 4px solid #ff6b6b;
        transition: all 0.3s ease;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .birthday-customer-card:hover {
        background: #e9ecef;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .birthday-card-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .birthday-customer-avatar .birthday-avatar-circle {
        width: 50px;
        height: 50px;
        background: linear-gradient(135deg, #ff6b6b 0%, #ffd93d 100%);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.25rem;
        box-shadow: 0 3px 10px rgba(255, 107, 107, 0.3);
    }

    .birthday-card-title h4 {
        margin: 0;
        font-size: 1.125rem;
        font-weight: 600;
        color: #212529;
    }

    .customer-representative {
        font-size: 0.875rem;
        color: #6c757d;
        font-style: italic;
    }

    .birthday-card-info {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }

    .info-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.875rem;
        color: #495057;
    }

    .info-item i {
        width: 16px;
        color: #6c757d;
    }

    .birthday-celebrate-btn {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: 25px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 2px 10px rgba(40, 167, 69, 0.3);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.875rem;
        width: 100%;
        justify-content: center;
    }

    .birthday-celebrate-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.4);
    }

    .birthday-celebrate-btn:active {
        transform: translateY(0);
    }

    .birthday-celebrate-btn:disabled {
        background: #6c757d;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }

    .no-email-notice {
        color: #dc3545;
        font-size: 0.8rem;
        font-style: italic;
        text-align: center;
        padding: 0.75rem;
        background: #f8d7da;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .birthday-empty-state {
        text-align: center;
        padding: 3rem 2rem;
        color: #6c757d;
    }

    .empty-state-icon {
        font-size: 3rem;
        color: #dee2e6;
        margin-bottom: 1rem;
    }

    .birthday-empty-state h4 {
        margin: 0 0 0.5rem 0;
        color: #495057;
        font-size: 1.25rem;
    }

    .birthday-empty-state p {
        margin: 0;
        font-size: 0.95rem;
        line-height: 1.5;
    }

    @media (max-width: 768px) {
        .birthday-cards-grid {
            grid-template-columns: 1fr;
        }
        
        .birthday-celebrations-section .section-header {
            flex-direction: column;
            align-items: stretch;
        }
        
        .birthday-header-controls {
            margin-left: 0;
            justify-content: space-between;
        }
    }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const birthdayToggle = document.getElementById('birthdayToggle');
        const birthdayContainer = document.getElementById('birthdayPanelContainer');
        
        if (birthdayToggle && birthdayContainer) {
            birthdayToggle.addEventListener('click', function() {
                const isHidden = birthdayContainer.style.display === 'none';
                
                if (isHidden) {
                    birthdayContainer.style.display = 'block';
                    birthdayToggle.innerHTML = '<i class="fas fa-chevron-up"></i> Gizle';
                } else {
                    birthdayContainer.style.display = 'none';
                    birthdayToggle.innerHTML = '<i class="fas fa-chevron-down"></i> Göster';
                }
            });
        }
    });

    // Birthday email function
    function sendBirthdayEmail(customerId, customerName) {
        const button = event.target.closest('.birthday-celebrate-btn');
        if (!button) return;
        
        const originalContent = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gönderiliyor...';
        
        const formData = new FormData();
        formData.append('action', 'send_birthday_email');
        formData.append('customer_id', customerId);
        formData.append('nonce', '<?php echo wp_create_nonce("send_birthday_email"); ?>');
        
        fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                button.innerHTML = '<i class="fas fa-check"></i> Gönderildi!';
                button.style.background = 'linear-gradient(135deg, #28a745 0%, #20c997 100%)';
                
                // Show success notification
                showNotification('Doğum günü e-postası başarıyla gönderildi!', 'success');
                
                setTimeout(() => {
                    button.innerHTML = originalContent;
                    button.disabled = false;
                }, 3000);
            } else {
                button.innerHTML = originalContent;
                button.disabled = false;
                showNotification(data.data || 'E-posta gönderimi başarısız oldu.', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            button.innerHTML = originalContent;
            button.disabled = false;
            showNotification('E-posta gönderimi başarısız oldu. Lütfen tekrar deneyin.', 'error');
        });
    }

    function showNotification(message, type) {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification-banner notification-${type}`;
        notification.innerHTML = `
            <div class="notification-icon">
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
            </div>
            <div class="notification-content">${message}</div>
            <button class="notification-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        // Insert at top of page
        const mainContent = document.querySelector('.main-content') || document.body;
        mainContent.insertBefore(notification, mainContent.firstChild);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 5000);
    }
    </script>

    <!-- Customers Table -->
    <section class="table-section">
        <?php if (!empty($customers)): ?>
        <div class="table-wrapper">
            <div class="table-header">
                <div class="table-info">
                    <div class="table-meta">
                        <span>Toplam: <strong><?php echo number_format($total_items); ?></strong> müşteri</span>
                        <?php if ($view_type === 'team_customers'): ?>
                        <span class="view-badge team">
                            <i class="fas fa-users"></i>
                            Ekip Görünümü
                        </span>
                        <?php else: ?>
                        <span class="view-badge personal">
                            <i class="fas fa-user"></i>
                            Kişisel Görünüm
                        </span>
                        <?php endif; ?>
                        
                        <?php if ($is_filtered): ?>
                        <span class="view-badge filtered">
                            <i class="fas fa-filter"></i>
                            Filtrelenmiş
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="table-container">
                <table class="customers-table">
                    <thead>
                        <tr>
                            <th>
                                Ad Soyad
                            </th>
                            <th>TC / VKN</th>
                            <th>İletişim</th>
                            <th>
                                Kategori
                            </th>
                            <th>
                                Durum
                            </th>
                            <?php if ($access_level == 'patron' || $access_level == 'mudur' || $access_level == 'mudur_yardimcisi' || $access_level == 'ekip_lideri'): ?>
                            <th>Temsilci</th>
                            <?php endif; ?>
                            <th>
                                Kayıt Tarihi
                            </th>
                            <th class="actions-column">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $customer): ?>
                            <?php 
                            $row_class = '';
                            switch ($customer->status) {
                                case 'aktif': $row_class = 'active'; break;
                                case 'pasif': $row_class = 'passive'; break;
                                case 'belirsiz': $row_class = 'uncertain'; break;
                            }
                            // Kurumsal müşteriler için ek class ekleyelim
                            if ($customer->category === 'kurumsal') {
                                $row_class .= ' corporate';
                            }
                            ?>
                            <tr class="<?php echo $row_class; ?>" data-customer-id="<?php echo $customer->id; ?>">
                                <td class="customer-name" data-label="Müşteri">
                                    <a href="?view=<?php echo esc_attr($view_type); ?>&action=view&id=<?php echo $customer->id; ?>" class="customer-link">
                                        <?php 
                                        // Proper Turkish uppercase conversion
                                        $customer_name = mb_strtoupper($customer->customer_name, 'UTF-8');
                                        // Convert specific Turkish characters to proper uppercase
                                        $customer_name = str_replace(
                                            ['ı', 'i', 'İ'],
                                            ['I', 'İ', 'İ'],
                                            $customer_name
                                        );
                                        echo esc_html($customer_name); 
                                        ?>
                                    </a>
                                    <?php if (!empty($customer->company_name)): ?>
                                    <div class="company-name"><?php echo esc_html($customer->company_name); ?></div>
                                    <?php endif; ?>
                                    <div class="customer-badges">
                                        <?php if ($customer->is_pregnant == 1): ?>
                                        <span class="badge pregnancy"><i class="fas fa-baby"></i> Gebe</span>
                                        <?php endif; ?>
                                        <?php if ($customer->category === 'kurumsal'): ?>
                                        <span class="badge corporate"><i class="fas fa-building"></i> Kurumsal</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="tc-identity" data-label="TC / VKN">
                                    <?php 
                                    // Corporate customers with no TC but with Tax Number - show Tax Number
                                    if ($customer->category === 'kurumsal' && empty($customer->tc_identity) && !empty($customer->tax_number)) {
                                        echo esc_html($customer->tax_number);
                                    } else {
                                        echo esc_html($customer->tc_identity);
                                    }
                                    ?>
                                </td>
                                <td class="contact" data-label="İletişim">
                                    <div>
                                        <?php if (!empty($customer->email)): ?>
                                        <div class="contact-info"><i class="fas fa-envelope"></i> <?php echo esc_html($customer->email); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($customer->phone)): ?>
                                        <div class="contact-info"><i class="fas fa-phone"></i> <?php echo esc_html($customer->phone); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="category" data-label="Kategori">
                                    <span class="status-badge <?php echo $customer->category; ?>">
                                        <?php echo $customer->category === 'bireysel' ? 'Bireysel' : 'Kurumsal'; ?>
                                    </span>
                                </td>
                                <td class="status" data-label="Durum">
                                    <span class="status-badge <?php echo $customer->status; ?>">
                                        <?php 
                                        switch ($customer->status) {
                                            case 'aktif': echo 'Aktif'; break;
                                            case 'pasif': echo 'Pasif'; break;
                                            case 'belirsiz': echo 'Belirsiz'; break;
                                            default: echo ucfirst($customer->status);
                                        }
                                        ?>
                                    </span>
                                </td>
                                <?php if ($access_level == 'patron' || $access_level == 'mudur' || $access_level == 'mudur_yardimcisi' || $access_level == 'ekip_lideri'): ?>
                                <td class="representative" data-label="Temsilci">
                                    <?php echo !empty($customer->representative_name) ? esc_html($customer->representative_name) : '—'; ?>
                                </td>
                                <?php endif; ?>
                                <td class="created-date" data-label="Kayıt Tarihi"><?php echo date('d.m.Y', strtotime($customer->created_at)); ?></td>
                                <td class="actions" data-label="İşlemler">
                                    <div class="action-buttons-group">
                                        <div class="primary-actions">
                                            <a href="?view=<?php echo esc_attr($view_type); ?>&action=view&id=<?php echo $customer->id; ?>" 
                                               class="btn btn-xs btn-primary" title="Görüntüle">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <?php if (can_edit_customer($current_rep, $customer)): ?>
                                            <a href="?view=<?php echo esc_attr($view_type); ?>&action=edit&id=<?php echo $customer->id; ?>" 
                                               class="btn btn-xs btn-outline" title="Düzenle">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php endif; ?>
                                            
                                            <?php if (can_delete_customer($current_rep, $customer) && $customer->status !== 'pasif'): ?>
                                            <a href="<?php echo wp_nonce_url('?view=' . esc_attr($view_type) . '&action=delete&id=' . $customer->id, 'delete_customer_' . $customer->id); ?>" 
                                               onclick="return confirm('Bu müşteriyi pasif duruma getirmek istediğinizden emin misiniz?');" 
                                               title="Pasif Yap" class="btn btn-xs btn-danger">
                                                <i class="fas fa-ban"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($total_pages > 1): ?>
            <div class="pagination-wrapper">
                <nav class="pagination">
                    <?php
                    $pagination_args = array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => '<i class="fas fa-chevron-left"></i>',
                        'next_text' => '<i class="fas fa-chevron-right"></i>',
                        'total' => $total_pages,
                        'current' => $current_page,
                        'add_args' => array_filter($_GET, fn($key) => $key !== 'paged', ARRAY_FILTER_USE_KEY)
                    );
                    echo paginate_links($pagination_args);
                    ?>
                </nav>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon">
                <i class="fas fa-users"></i>
            </div>
            <h3>Müşteri bulunamadı</h3>
            <p>
                <?php 
                if ($is_filtered) {
                    echo 'Arama kriterlerinize uygun müşteri bulunamadı.';
                } elseif ($view_type === 'team_customers') {
                    echo 'Ekibinize ait müşteri bulunamadı.';
                } else {
                    echo 'Henüz hiç müşteri eklenmemiş.';
                }
                ?>
            </p>
            <a href="?view=<?php echo esc_attr($view_type); ?>" class="btn btn-primary">
                <i class="fas fa-refresh"></i>
                Tüm Müşterileri Göster
            </a>
        </div>
        <?php endif; ?>
    </section>
</div>

<style>
/* Modern CSS Styles with Material Design 3 Principles - Enhanced v5.1.1 - Filtreleme Sorunu Düzeltildi */
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

/* Reset & Base Styles */
* {
    box-sizing: border-box;
}

.modern-crm-container {
    font-family: var(--font-family);
    color: var(--on-surface);
    background-color: var(--surface-container);
    min-height: 100vh;
    padding: var(--spacing-lg);
    margin: 0;
}

.debug-info {
    background: #ffebee;
    border: 1px solid #e57373;
    color: #c62828;
    padding: var(--spacing-md);
    border-radius: var(--radius-lg);
    margin-bottom: var(--spacing-lg);
    font-family: monospace;
    font-size: var(--font-size-sm);
    overflow-x: auto;
}

/* Notification Banner */
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

/* Header Styles */
.crm-header {
    background: var(--surface);
    border-radius: var(--radius-xl);
    padding: var(--spacing-xl);
    margin-bottom: var(--spacing-xl);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
}

/* Quick Search Styles */
.quick-search-section {
    background: var(--surface);
    border-radius: var(--radius-xl);
    padding: var(--spacing-lg);
    margin-bottom: var(--spacing-xl);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
}

.quick-search-container {
    max-width: 600px;
    margin: 0 auto;
}

.quick-search-form {
    width: 100%;
}

.search-input-group {
    display: flex;
    align-items: stretch;
    gap: 0;
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

.quick-search-input {
    flex: 1;
    padding: var(--spacing-md) var(--spacing-lg);
    border: 2px solid var(--outline);
    border-right: none;
    border-radius: var(--radius-lg) 0 0 var(--radius-lg);
    font-size: var(--text-md);
    background: var(--surface);
    color: var(--on-surface);
    outline: none;
    transition: all var(--transition-base);
}

.quick-search-input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(var(--primary-rgb), 0.2);
}

.quick-search-input::placeholder {
    color: var(--on-surface-variant);
    opacity: 0.7;
}

.quick-search-btn {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    padding: var(--spacing-md) var(--spacing-xl);
    background: var(--primary);
    color: var(--on-primary);
    border: 2px solid var(--primary);
    border-radius: 0 var(--radius-lg) var(--radius-lg) 0;
    font-weight: 600;
    font-size: var(--text-md);
    cursor: pointer;
    transition: all var(--transition-base);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.quick-search-btn:hover {
    background: var(--primary-variant);
    border-color: var(--primary-variant);
    transform: translateY(-1px);
    box-shadow: var(--shadow-md);
}

.quick-search-btn:active {
    transform: translateY(0);
}

.quick-search-btn i {
    font-size: 1.1em;
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--spacing-lg);
    flex-wrap: wrap;
}

.title-section {
    display: flex;
    align-items: center;
    gap: var(--spacing-lg);
}

.page-title {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
}

.page-title i {
    font-size: var(--font-size-xl);
    color: var(--primary);
}

.page-title h1 {
    margin: 0;
    font-size: var(--font-size-2xl);
    font-weight: 600;
    color: var(--on-surface);
}

.version-badge {
    background: linear-gradient(135deg, #4caf50, #2e7d32);
    color: white;
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.5px;
}

.role-badge {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    padding: var(--spacing-sm) var(--spacing-md);
    border-radius: var(--radius-xl);
    font-size: var(--font-size-sm);
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

/* Header Actions */
.header-actions {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    flex-wrap: wrap;
}

.view-toggle {
    display: flex;
    background: var(--surface-variant);
    border-radius: var(--radius-lg);
    padding: var(--spacing-xs);
}

.view-btn {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    padding: var(--spacing-sm) var(--spacing-md);
    border-radius: var(--radius-md);
    text-decoration: none;
    font-size: var(--font-size-sm);
    font-weight: 500;
    color: var(--on-surface-variant);
    transition: all var(--transition-fast);
}

.view-btn:hover {
    background: var(--surface);
    color: var(--on-surface);
}

.view-btn.active {
    background: var(--primary);
    color: white;
    box-shadow: var(--shadow-sm);
}

.filter-controls {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

/* Enhanced Button Styles */
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
}

.btn-primary:hover {
    background: var(--primary-dark);
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
    text-decoration: none;
    color: white;
}

.btn-secondary {
    background: #757575;
    color: white;
}

.btn-secondary:hover {
    background: #616161;
    transform: translateY(-1px);
    text-decoration: none;
    color: white;
}

.btn-outline {
    background: transparent;
    color: var(--primary);
    border-color: var(--outline-variant);
}

.btn-outline:hover {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
    text-decoration: none;
}

.btn-ghost {
    background: transparent;
    color: var(--on-surface-variant);
}

.btn-ghost:hover {
    background: var(--surface-variant);
    color: var(--on-surface);
    text-decoration: none;
}

.btn-success {
    background: var(--success);
    color: white;
}

.btn-success:hover {
    background: #2e7d32;
    transform: translateY(-1px);
    text-decoration: none;
    color: white;
}

.btn-warning {
    background: var(--warning);
    color: white;
}

.btn-warning:hover {
    background: #ef6c00;
    transform: translateY(-1px);
    text-decoration: none;
    color: white;
}

.btn-danger {
    background: var(--danger);
    color: white;
}

.btn-danger:hover {
    background: #c62828;
    transform: translateY(-1px);
    text-decoration: none;
    color: white;
}

.btn-info {
    background: var(--info);
    color: white;
}

.btn-info:hover {
    background: #0277bd;
    transform: translateY(-1px);
    text-decoration: none;
    color: white;
}

.btn-xs {
    padding: 4px 8px;
    font-size: var(--font-size-xs);
    gap: 4px;
}

.btn-sm {
    padding: var(--spacing-xs) var(--spacing-sm);
    font-size: 0.75rem;
}

/* Filter Section */
.filters-section {
    background: var(--surface);
    border-radius: var(--radius-xl);
    margin-bottom: var(--spacing-xl);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
    overflow: hidden;
    transition: all var(--transition-base);
}

.filters-section.hidden {
    display: none;
}

.filters-container {
    padding: var(--spacing-xl);
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: var(--spacing-lg);
    margin-bottom: var(--spacing-xl);
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
}

.filter-group label {
    font-size: var(--font-size-sm);
    font-weight: 500;
    color: var(--on-surface);
}

.form-input,
.form-select {
    width: 100%;
    padding: var(--spacing-md);
    border: 1px solid var(--outline-variant);
    border-radius: var(--radius-lg);
    font-size: var(--font-size-base);
    background: var(--surface);
    color: var(--on-surface);
    transition: all var(--transition-fast);
}

.form-input:focus,
.form-select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
}

.advanced-filters {
    margin-top: var(--spacing-lg);
    padding-top: var(--spacing-lg);
    border-top: 1px solid var(--outline-variant);
}

.filter-section h4 {
    margin: 0 0 var(--spacing-md) 0;
    font-size: var(--font-size-base);
    font-weight: 600;
    color: var(--on-surface);
}

.checkbox-filters {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--spacing-md);
}

.checkbox-group {
    display: flex;
    align-items: center;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    cursor: pointer;
    font-size: var(--font-size-sm);
    user-select: none;
}

.checkbox-label input[type="checkbox"] {
    margin: 0;
    width: 18px;
    height: 18px;
    accent-color: var(--primary);
}

.filter-count {
    background: var(--danger);
    color: white;
    font-size: 0.75rem;
    font-weight: 600;
    padding: var(--spacing-xs) var(--spacing-sm);
    border-radius: var(--radius-xl);
    min-width: 20px;
    text-align: center;
}

.filters-actions {
    display: flex;
    gap: var(--spacing-md);
    justify-content: flex-end;
    padding-top: var(--spacing-lg);
    border-top: 1px solid var(--outline-variant);
}

/* Dashboard Section */
.dashboard-section {
    margin-bottom: var(--spacing-xl);
}

.stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: var(--spacing-lg);
    margin-bottom: var(--spacing-xl);
}

.stat-card {
    background: var(--surface);
    border-radius: var(--radius-xl);
    padding: var(--spacing-xl);
    display: flex;
    align-items: center;
    gap: var(--spacing-lg);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
    transition: all var(--transition-base);
    position: relative;
    overflow: hidden;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.stat-card:before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--primary), var(--primary-light));
}

.stat-card.success:before {
    background: linear-gradient(90deg, var(--success), #4caf50);
}

.stat-card.warning:before {
    background: linear-gradient(90deg, var(--warning), #ff9800);
}

.stat-card.info:before {
    background: linear-gradient(90deg, var(--info), #03a9f4);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: var(--radius-xl);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: var(--font-size-xl);
    color: white;
    flex-shrink: 0;
}

.stat-card.primary .stat-icon {
    background: linear-gradient(135deg, var(--primary), var(--primary-light));
}

.stat-card.success .stat-icon {
    background: linear-gradient(135deg, var(--success), #4caf50);
}

.stat-card.warning .stat-icon {
    background: linear-gradient(135deg, var(--warning), #ff9800);
}

.stat-card.info .stat-icon {
    background: linear-gradient(135deg, var(--info), #03a9f4);
}

.stat-content h3 {
    margin: 0 0 var(--spacing-sm) 0;
    font-size: var(--font-size-sm);
    font-weight: 500;
    color: var(--on-surface-variant);
}

.stat-value {
    font-size: var(--font-size-2xl);
    font-weight: 700;
    color: var(--on-surface);
    margin-bottom: var(--spacing-xs);
}

.stat-percent {
    font-size: var(--font-size-sm);
    color: var(--on-surface-variant);
    font-weight: normal;
}

.stat-subtitle {
    font-size: var(--font-size-sm);
    color: var(--on-surface-variant);
}

/* Charts Section */
.charts-section {
    background: var(--surface);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
    overflow: hidden;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--spacing-lg) var(--spacing-xl);
    background: var(--surface-variant);
    border-bottom: 1px solid var(--outline-variant);
}

.section-header h2 {
    margin: 0;
    font-size: var(--font-size-lg);
    font-weight: 600;
    color: var(--on-surface);
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.charts-container {
    padding: var(--spacing-xl);
}

.chart-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: var(--spacing-lg);
}

.chart-item {
    background: var(--surface-variant);
    border-radius: var(--radius-lg);
    padding: var(--spacing-lg);
    box-shadow: var(--shadow-sm);
}

.chart-item h4 {
    margin: 0 0 var(--spacing-md) 0;
    font-size: var(--font-size-base);
    font-weight: 500;
    color: var(--on-surface);
    text-align: center;
}

.chart-canvas {
    position: relative;
    height: 250px;
    width: 100%;
}

/* Enhanced Table Section */
.table-section {
    background: var(--surface);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
    overflow: hidden;
}

.table-header {
    padding: var(--spacing-lg) var(--spacing-xl);
    background: var(--surface-variant);
    border-bottom: 1px solid var(--outline-variant);
}

.table-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--spacing-md);
}

.table-meta {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    flex-wrap: wrap;
}

.view-badge {
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
    padding: var(--spacing-xs) var(--spacing-md);
    border-radius: var(--radius-lg);
    font-size: var(--font-size-sm);
    font-weight: 500;
}

.view-badge.team {
    background: rgba(25, 118, 210, 0.1);
    color: var(--primary);
}

.view-badge.personal {
    background: rgba(156, 39, 176, 0.1);
    color: var(--secondary);
}

.view-badge.filtered {
    background: rgba(245, 124, 0, 0.1);
    color: var(--warning);
}

.table-container {
    overflow-x: auto;
}

.customers-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1200px;
}

.customers-table th,
.customers-table td {
    padding: var(--spacing-md);
    text-align: left;
    border-bottom: 1px solid var(--outline-variant);
    font-size: var(--font-size-sm);
    vertical-align: middle;
}

.customers-table th {
    background: var(--surface-variant);
    font-weight: 600;
    color: var(--on-surface);
    position: sticky;
    top: 0;
    z-index: 1;
}

.customers-table th a {
    color: inherit;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

.customers-table th a:hover {
    color: var(--primary);
}

.customers-table tbody tr {
    transition: all var(--transition-fast);
}

.customers-table tbody tr:hover {
    background: var(--surface-variant);
}

/* Row Status Colors */
.customers-table tr.active td {
    background: rgba(46, 125, 50, 0.05) !important;
    border-left: 3px solid var(--success);
}

.customers-table tr.passive td {
    background: rgba(117, 117, 117, 0.05) !important;
    border-left: 3px solid #757575;
}

.customers-table tr.uncertain td {
    background: rgba(245, 124, 0, 0.05) !important;
    border-left: 3px solid var(--warning);
}

.customers-table tr.corporate td {
    background: rgba(156, 39, 176, 0.05) !important;
    border-left: 3px solid var(--secondary);
}

/* Table Cell Specific Styles */
.customer-name {
    min-width: 180px;
}

.customer-link {
    font-weight: 600;
    color: var(--primary);
    text-decoration: none;
    display: block;
    margin-bottom: var(--spacing-xs);
}

.customer-link:hover {
    text-decoration: underline;
}

.company-name {
    font-size: var(--font-size-base);
    color: var(--on-surface-variant);
    margin-top: var(--spacing-xs);
    font-weight: bold;
}

.customer-badges {
    display: flex;
    flex-wrap: wrap;
    gap: var(--spacing-xs);
    margin-top: var(--spacing-xs);
}

.badge {
    display: inline-flex;
    align-items: center;
    padding: 2px var(--spacing-xs);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge.pregnancy {
    background: rgba(194, 24, 91, 0.1);
    color: #c2185b;
    border: 1px solid rgba(194, 24, 91, 0.2);
}

.badge.corporate {
    background: rgba(156, 39, 176, 0.1);
    color: var(--secondary);
    border: 1px solid rgba(156, 39, 176, 0.2);
}

.tc-identity {
    font-family: monospace;
    font-size: var(--font-size-sm);
    color: var(--on-surface-variant);
}

.contact-info {
    font-size: var(--font-size-xs);
    margin-bottom: 3px;
    color: var(--on-surface-variant);
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

.contact-info:last-child {
    margin-bottom: 0;
}

.contact-info i {
    width: 14px;
    text-align: center;
    color: var(--on-surface-variant);
}

.status-badge {
    display: inline-flex;
    align-items: center;
    padding: var(--spacing-xs) var(--spacing-sm);
    border-radius: var(--radius-lg);
    font-size: var(--font-size-sm);
    font-weight: 500;
    text-transform: capitalize;
}

.status-badge.aktif {
    background: rgba(46, 125, 50, 0.1);
    color: var(--success);
}

.status-badge.pasif {
    background: rgba(117, 117, 117, 0.1);
    color: #757575;
}

.status-badge.belirsiz {
    background: rgba(245, 124, 0, 0.1);
    color: var(--warning);
}

.status-badge.bireysel {
    background: rgba(25, 118, 210, 0.1);
    color: var(--primary);
}

.status-badge.kurumsal {
    background: rgba(156, 39, 176, 0.1);
    color: var(--secondary);
}

.created-date {
    font-size: var(--font-size-sm);
    color: var(--on-surface-variant);
    white-space: nowrap;
}

/* Enhanced Action Buttons */
.actions-column {
    width: 120px;
    text-align: center;
}

.action-buttons-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
    align-items: center;
}

.primary-actions {
    display: flex;
    gap: 4px;
    justify-content: center;
}

/* Pagination */
.pagination-wrapper {
    padding: var(--spacing-lg) var(--spacing-xl);
    background: var(--surface-variant);
    border-top: 1px solid var(--outline-variant);
}

.pagination {
    display: flex;
    justify-content: center;
    gap: var(--spacing-xs);
}

.pagination .page-numbers {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 40px;
    height: 40px;
    padding: var(--spacing-sm);
    border: 1px solid var(--outline-variant);
    border-radius: var(--radius-md);
    color: var(--on-surface);
    text-decoration: none;
    font-size: var(--font-size-sm);
    font-weight: 500;
    transition: all var(--transition-fast);
}

.pagination .page-numbers:hover {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
    text-decoration: none;
}

.pagination .page-numbers.current {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: var(--spacing-2xl);
    color: var(--on-surface-variant);
}

.empty-icon {
    font-size: 4rem;
    color: var(--outline);
    margin-bottom: var(--spacing-lg);
}

.empty-state h3 {
    margin: 0 0 var(--spacing-md) 0;
    font-size: var(--font-size-xl);
    color: var(--on-surface);
}

.empty-state p {
    margin: 0 0 var(--spacing-xl) 0;
    font-size: var(--font-size-base);
}

/* Responsive Design */
@media (max-width: 1024px) {
    .action-buttons-group {
        flex-direction: row;
        gap: 2px;
    }
    
    .primary-actions {
        flex-direction: column;
        gap: 2px;
    }
    
    .actions-column {
        width: 100px;
    }
}

/* Tablet optimizations */
@media (max-width: 1200px) {
    .customers-table {
        min-width: 900px;
    }
    
    .customers-table th,
    .customers-table td {
        padding: calc(var(--spacing-md) * 0.8);
        font-size: calc(var(--font-size-sm) * 0.95);
    }
}

@media (max-width: 992px) {
    .customers-table {
        min-width: 800px;
    }
    
    .customers-table th:nth-child(3), /* İletişim */
    .customers-table td:nth-child(3) {
        display: none;
    }
}

@media (max-width: 768px) {
    .modern-crm-container {
        padding: var(--spacing-md);
    }

    .header-content {
        flex-direction: column;
        align-items: stretch;
    }
    
    .quick-search-section {
        padding: var(--spacing-md);
    }
    
    .search-input-group {
        flex-direction: column;
        border-radius: var(--radius-lg);
    }
    
    .quick-search-input,
    .quick-search-btn {
        border-radius: var(--radius-lg);
        border: 2px solid var(--outline);
    }
    
    .quick-search-input {
        margin-bottom: var(--spacing-sm);
        border-bottom: 2px solid var(--outline);
    }
    
    .quick-search-btn {
        justify-content: center;
        padding: var(--spacing-md);
    }

    .header-actions {
        justify-content: space-between;
    }

    .filters-grid {
        grid-template-columns: 1fr;
    }

    .stats-cards {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    }

    .chart-grid {
        grid-template-columns: 1fr;
    }

    .table-container {
        margin: 0 calc(-1 * var(--spacing-md));
        overflow-x: visible;
    }

    .table-info {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--spacing-sm);
    }

    /* Hide table headers on mobile */
    .customers-table thead {
        display: none;
    }
    
    /* Reset table styling for mobile */
    .customers-table {
        width: 100%;
        min-width: auto;
        border-collapse: separate;
        border-spacing: 0;
    }
    
    /* Convert table rows to cards */
    .customers-table tbody {
        display: block;
    }
    
    .customers-table tbody tr {
        display: block;
        background: white;
        border: 1px solid var(--outline-variant);
        border-radius: 12px;
        margin-bottom: var(--spacing-md);
        padding: var(--spacing-md);
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        transition: all var(--transition-fast);
    }
    
    .customers-table tbody tr:hover {
        box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        transform: translateY(-2px);
        background: white;
    }
    
    /* Style table cells as card content */
    .customers-table td {
        display: block;
        padding: var(--spacing-sm) 0;
        border-bottom: none;
        position: relative;
        font-size: var(--font-size-sm);
    }
    
    /* Add labels before each cell content */
    .customers-table td:before {
        content: attr(data-label);
        display: block;
        font-weight: 600;
        font-size: var(--font-size-xs);
        color: var(--on-surface-variant);
        margin-bottom: var(--spacing-xs);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    /* Special styling for first cell (customer name) */
    .customers-table td:first-child {
        border-bottom: 1px solid var(--outline-variant);
        padding-bottom: var(--spacing-md);
        margin-bottom: var(--spacing-sm);
    }
    
    .customers-table td:first-child:before {
        display: none;
    }
    
    /* Last cell (actions) styling */
    .customers-table td:last-child {
        padding-top: var(--spacing-md);
        border-top: 1px solid var(--outline-variant);
        margin-top: var(--spacing-sm);
    }
    
    .action-buttons-group {
        flex-direction: row;
        flex-wrap: wrap;
        gap: var(--spacing-xs);
        justify-content: flex-start;
    }
    
    .btn-xs {
        padding: 4px 8px;
        font-size: 0.7rem;
        flex: 0 0 auto;
    }
    
    /* Status-based row styling for mobile cards */
    .customers-table tr.active {
        border-left: 4px solid var(--success);
    }
    
    .customers-table tr.passive {
        border-left: 4px solid #757575;
    }
    
    .customers-table tr.uncertain {
        border-left: 4px solid var(--warning);
    }
    
    .customers-table tr.corporate {
        border-left: 4px solid var(--secondary);
    }
}

@media (max-width: 480px) {
    .stat-card {
        flex-direction: column;
        text-align: center;
    }

    .action-buttons-group {
        justify-content: flex-start;
    }
    
    .actions-column {
        width: 80px;
    }
}

/* Loading States */
.loading {
    opacity: 0.6;
    pointer-events: none;
}

.loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 20px;
    height: 20px;
    margin: -10px 0 0 -10px;
    border: 2px solid var(--outline-variant);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Animations */
@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.animate-slide-in {
    animation: slideIn var(--transition-base);
}

.animate-fade-in {
    animation: fadeIn var(--transition-base);
}

/* Print Styles */
@media print {
    .modern-crm-container {
        padding: 0;
        background: white;
    }
    
    .crm-header,
    .filters-section,
    .dashboard-section,
    .action-buttons-group {
        display: none;
    }
    
    .table-section {
        box-shadow: none;
        border: none;
    }
    
    .customers-table {
        width: 100%;
    }
    
    .customers-table th:last-child,
    .customers-table td:last-child {
        display: none;
    }
}
</style>

<script>
/**
 * Modern Customers Management JavaScript v5.1.1 - Filtreleme Hatası Düzeltildi
 * @author anadolubirlik
 * @date 2025-05-30 12:06:21
 * @description Filtreleme sistemi düzeltildi, tüm işlevsellik restore edildi
 */

class ModernCustomersApp {
    constructor() {
        this.activeFilterCount = <?php echo $active_filter_count; ?>;
        this.statisticsData = <?php echo json_encode($statistics); ?>;
        this.isInitialized = false;
        this.version = '5.1.1';
        this.viewType = '<?php echo esc_js($view_type); ?>';
        
        this.init();
    }

    async init() {
        try {
            this.initializeEventListeners();
            this.initializeFilters();
            this.initializeTableFeatures();
            
            if (typeof Chart !== 'undefined') {
                await this.initializeCharts();
            }
            
            this.isInitialized = true;
            this.logInitialization();
            
            // Check for notification banners
            this.handleNotificationBanners();
            
        } catch (error) {
            console.error('❌ Initialization failed:', error);
            this.showNotification('Uygulama başlatılamadı. Sayfayı yenileyin.', 'error');
        }
    }

    initializeEventListeners() {
        const filterToggle = document.getElementById('filterToggle');
        const filtersSection = document.getElementById('filtersSection');
        
        if (filterToggle && filtersSection) {
            filterToggle.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleFilters(filtersSection, filterToggle);
            });
        }

        const chartsToggle = document.getElementById('chartsToggle');
        const chartsContainer = document.getElementById('chartsContainer');
        
        if (chartsToggle && chartsContainer) {
            chartsToggle.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleCharts(chartsContainer, chartsToggle);
            });
        }

        this.handleDashboardVisibility();
        this.enhanceFormInputs();
        this.enhanceTable();
        this.initKeyboardShortcuts();
        
        // Close notification buttons
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
    }

    handleNotificationBanners() {
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
    }

    toggleFilters(filtersSection, filterToggle) {
        const isHidden = filtersSection.classList.contains('hidden');
        const chevron = filterToggle.querySelector('.chevron');
        const dashboardSection = document.getElementById('dashboardSection');
        
        if (isHidden) {
            filtersSection.classList.remove('hidden');
            filtersSection.classList.add('animate-slide-in');
            if (chevron) chevron.style.transform = 'rotate(180deg)';
            if (dashboardSection) dashboardSection.style.display = 'none';
        } else {
            filtersSection.classList.add('hidden');
            if (chevron) chevron.style.transform = 'rotate(0deg)';
            if (dashboardSection && this.activeFilterCount === 0) {
                dashboardSection.style.display = 'block';
            }
        }
    }

    toggleCharts(chartsContainer, chartsToggle) {
        const isHidden = chartsContainer.style.display === 'none';
        const icon = chartsToggle.querySelector('i');
        
        if (isHidden) {
            chartsContainer.style.display = 'block';
            chartsContainer.classList.add('animate-slide-in');
            if (icon) icon.className = 'fas fa-chevron-up';
        } else {
            chartsContainer.style.display = 'none';
            if (icon) icon.className = 'fas fa-chevron-down';
        }
    }

    handleDashboardVisibility() {
        const dashboardSection = document.getElementById('dashboardSection');
        const filtersSection = document.getElementById('filtersSection');
        
        if (this.activeFilterCount > 0 && dashboardSection) {
            dashboardSection.style.display = 'none';
            
            if (filtersSection) {
                filtersSection.classList.remove('hidden');
            }
        }
    }

    initializeFilters() {
        this.enhanceSelectBoxes();
        this.addFilterCounting();
        this.initializeFormSubmission();
        
        // Gebe checkbox kontrolü - Sadece kadın seçildiğinde aktif olsun
        const genderFilter = document.getElementById('filter_gender');
        const pregnantFilter = document.querySelector('input[name="is_pregnant"]');
        
        if (genderFilter && pregnantFilter) {
            const checkPregnancyState = () => {
                if (genderFilter.value === 'female') {
                    pregnantFilter.disabled = false;
                    pregnantFilter.parentElement.style.opacity = '1';
                } else {
                    pregnantFilter.checked = false;
                    pregnantFilter.disabled = true;
                    pregnantFilter.parentElement.style.opacity = '0.5';
                }
            };
            
            genderFilter.addEventListener('change', checkPregnancyState);
            
            // Sayfa yüklendiğinde kontrol et
            checkPregnancyState();
        }
    }

    initializeFormSubmission() {
        const filterForm = document.querySelector('.filters-form');
        if (filterForm) {
            filterForm.addEventListener('submit', (e) => {
                // Form gönderilmeden önce boş alanları temizle
                this.cleanEmptyFormFields(filterForm);
            });
        }
    }

    cleanEmptyFormFields(form) {
        const inputs = form.querySelectorAll('input, select');
        inputs.forEach(input => {
            if (input.type === 'hidden') return;
            if (input.name === 'customer_name') return; // Never disable the search field
            if (input.name === 'customer_tc') return; // Never disable the TC search field
            if (input.name === 'customer_vkn') return; // Never disable the VKN search field
            
            if (input.type === 'checkbox') {
                if (!input.checked) {
                    input.disabled = true;
                }
            } else if (input.type === 'text' || input.tagName.toLowerCase() === 'select') {
                if (!input.value || input.value === '' || input.value === '0') {
                    input.disabled = true;
                }
            }
        });
    }

    enhanceSelectBoxes() {
        const selects = document.querySelectorAll('.form-select');
        selects.forEach(select => {
            if (select.options.length > 10) {
                select.setAttribute('data-live-search', 'true');
                select.setAttribute('data-size', '8');
            }
        });
    }

    addFilterCounting() {
        const filterInputs = document.querySelectorAll('.filters-form input, .filters-form select');
        const filterToggle = document.getElementById('filterToggle');
        
        filterInputs.forEach(input => {
            input.addEventListener('change', this.debounce(() => {
                const count = this.countActiveFilters();
                this.updateFilterCount(filterToggle, count);
            }, 300));
        });
    }

    countActiveFilters() {
        const filterInputs = document.querySelectorAll('.filters-form input, .filters-form select');
        let count = 0;
        
        filterInputs.forEach(input => {
            if (input.type === 'hidden') return;
            if (input.type === 'checkbox' && input.checked) count++;
            else if (input.value && input.value !== '0' && input.value !== '') count++;
        });
        
        return count;
    }

    updateFilterCount(filterToggle, count) {
        if (!filterToggle) return;
        
        let countElement = filterToggle.querySelector('.filter-count');
        
        if (count > 0) {
            if (!countElement) {
                countElement = document.createElement('span');
                countElement.className = 'filter-count';
                filterToggle.insertBefore(countElement, filterToggle.querySelector('.chevron'));
            }
            countElement.textContent = count;
        } else if (countElement) {
            countElement.remove();
        }
    }

    async initializeCharts() {
        if (typeof Chart === 'undefined') {
            console.warn('Chart.js kütüphanesi yüklenmemiş, grafikler atlanıyor');
            return;
        }
        try {
            Chart.defaults.font.family = 'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
            Chart.defaults.font.size = 12;
            Chart.defaults.color = '#49454f';
            Chart.defaults.plugins.legend.position = 'bottom';
            Chart.defaults.plugins.legend.labels.usePointStyle = true;
            Chart.defaults.plugins.legend.labels.padding = 20;
            Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(0, 0, 0, 0.8)';
            Chart.defaults.plugins.tooltip.titleColor = '#ffffff';
            Chart.defaults.plugins.tooltip.bodyColor = '#ffffff';
            Chart.defaults.plugins.tooltip.cornerRadius = 8;

            this.renderCharts();
        } catch (error) {
            console.error('Grafik başlatma hatası:', error);
        }
    }

    renderCharts() {
        this.renderCategoryChart();
        this.renderStatusChart();
        this.renderTrendChart();
    }

    renderCategoryChart() {
        const ctx = document.getElementById('categoryChart');
        if (!ctx) return;

        const data = {
            labels: ['Bireysel', 'Kurumsal'],
            datasets: [{
                data: [
                    this.statisticsData.individual || 0,
                    this.statisticsData.corporate || 0
                ],
                backgroundColor: [
                    '#1976d2',
                    '#9c27b0'
                ],
                borderWidth: 3,
                borderColor: '#ffffff',
                hoverBorderWidth: 4,
                hoverOffset: 8
            }]
        };

        new Chart(ctx, {
            type: 'pie',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            font: {
                                size: 11
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const value = context.raw;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value * 100) / total) : 0;
                                return `${context.label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                },
                animation: {
                    animateRotate: true,
                    duration: 1000
                }
            }
        });
    }

    renderStatusChart() {
        const ctx = document.getElementById('statusChart');
        if (!ctx) return;

        const data = {
            labels: ['Aktif', 'Pasif', 'Belirsiz'],
            datasets: [{
                data: [
                    this.statisticsData.active || 0,
                    this.statisticsData.passive || 0,
                    this.statisticsData.uncertain || 0
                ],
                backgroundColor: [
                    '#2e7d32',
                    '#757575',
                    '#f57c00'
                ],
                borderWidth: 2,
                borderColor: '#ffffff',
                hoverOffset: 6
            }]
        };

        new Chart(ctx, {
            type: 'pie',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const value = context.raw;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value * 100) / total) : 0;
                                return `${context.label}: ${value} müşteri (${percentage}%)`;
                            }
                        }
                    }
                },
                animation: {
                    duration: 1200
                }
            }
        });
    }

    renderTrendChart() {
        const ctx = document.getElementById('trendChart');
        if (!ctx) return;

        const trendLabels = <?php echo json_encode($months); ?>;
        const trendData = <?php echo json_encode($trend_data); ?>;

        const data = {
            labels: trendLabels,
            datasets: [{
                label: 'Yeni Müşteriler',
                data: trendData,
                fill: true,
                backgroundColor: 'rgba(25, 118, 210, 0.1)',
                borderColor: '#1976d2',
                borderWidth: 3,
                tension: 0.4,
                pointBackgroundColor: '#1976d2',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 8,
                pointHoverBackgroundColor: '#1976d2',
                pointHoverBorderColor: '#ffffff',
                pointHoverBorderWidth: 3
            }]
        };

        new Chart(ctx, {
            type: 'line',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                return `${context.label}: ${context.raw} yeni müşteri`;
                            }
                        }
                    }
                },
                animation: {
                    duration: 1500,
                    easing: 'easeOutQuart'
                }
            }
        });
    }

    initializeTableFeatures() {
        this.addTableRowHoverEffects();
        this.addTableSorting();
        this.addTableQuickActions();
    }

    addTableRowHoverEffects() {
        const tableRows = document.querySelectorAll('.customers-table tbody tr');
        
        tableRows.forEach(row => {
            row.addEventListener('mouseenter', () => {
                row.style.transform = 'scale(1.002)';
                row.style.zIndex = '1';
                row.style.boxShadow = '0 2px 8px rgba(0,0,0,0.1)';
            });
            
            row.addEventListener('mouseleave', () => {
                row.style.transform = 'scale(1)';
                row.style.zIndex = 'auto';
                row.style.boxShadow = 'none';
            });
        });
    }

    addTableSorting() {
        const sortableHeaders = document.querySelectorAll('.customers-table th a');
        
        sortableHeaders.forEach(header => {
            header.addEventListener('click', (e) => {
                const table = header.closest('table');
                if (table) {
                    table.classList.add('loading');
                    setTimeout(() => {
                        table.classList.remove('loading');
                    }, 1000);
                }
            });
        });
    }

    addTableQuickActions() {
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'k':
                        e.preventDefault();
                        this.focusTableSearch();
                        break;
                    case 'n':
                        e.preventDefault();
                        window.location.href = `?view=${this.viewType}&action=new`;
                        break;
                }
            }
        });
    }

    enhanceFormInputs() {
        const inputs = document.querySelectorAll('.form-input, .form-select');
        
        inputs.forEach(input => {
            this.addFloatingLabelEffect(input);
            this.addValidationStyling(input);
        });
    }

    addFloatingLabelEffect(input) {
        input.addEventListener('focus', () => {
            input.parentElement.classList.add('focused');
        });
        
        input.addEventListener('blur', () => {
            if (!input.value) {
                input.parentElement.classList.remove('focused');
            }
        });
        
        if (input.value) {
            input.parentElement.classList.add('focused');
        }
    }

    addValidationStyling(input) {
        input.addEventListener('blur', () => {
            if (input.hasAttribute('required') && !input.value) {
                input.classList.add('error');
            } else {
                input.classList.remove('error');
            }
        });
    }

    enhanceTable() {
        this.addTableExport();
    }

    addTableExport() {
        const tableHeader = document.querySelector('.table-header');
        if (!tableHeader || tableHeader.querySelector('.export-btn')) return;

        const exportBtn = document.createElement('button');
        exportBtn.className = 'btn btn-sm btn-outline export-btn';
        exportBtn.innerHTML = '<i class="fas fa-download"></i> Dışa Aktar';
        exportBtn.addEventListener('click', () => this.exportTableToCSV());
        
        const tableControls = tableHeader.querySelector('.table-controls');
        if (tableControls) {
            tableControls.appendChild(exportBtn);
        } else {
            tableHeader.appendChild(exportBtn);
        }
    }

    exportTableToCSV() {
        const table = document.querySelector('.customers-table');
        if (!table) return;

        const rows = table.querySelectorAll('tr');
        const csvContent = [];
        
        rows.forEach(row => {
            const cols = row.querySelectorAll('th, td');
            const rowData = [];
            cols.forEach(col => {
                if (!col.classList.contains('actions-column') && !col.classList.contains('actions')) {
                    rowData.push('"' + col.textContent.trim().replace(/"/g, '""') + '"');
                }
            });
            csvContent.push(rowData.join(','));
        });

        const csvString = csvContent.join('\n');
        const blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        
        if (link.download !== undefined) {
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', `customers_${new Date().getTime()}.csv`);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }

    initKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
                return;
            }

            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'f':
                        e.preventDefault();
                        this.toggleFiltersShortcut();
                        break;
                    case 'r':
                        e.preventDefault();
                        this.refreshPage();
                        break;
                }
            }

            if (e.key === 'Escape') {
                this.closeFilters();
            }
        });
    }

    toggleFiltersShortcut() {
        const filterToggle = document.getElementById('filterToggle');
        if (filterToggle) {
            filterToggle.click();
        }
    }

    refreshPage() {
        window.location.reload();
    }

    focusTableSearch() {
        const searchFilter = document.getElementById('filter_search');
        if (searchFilter) {
            searchFilter.focus();
            // If the filter section is hidden, show it
            const filtersSection = document.getElementById('filtersSection');
            if (filtersSection && filtersSection.classList.contains('hidden')) {
                const filterToggle = document.getElementById('filterToggle');
                if (filterToggle) filterToggle.click();
            }
        }
    }

    closeFilters() {
        const filtersSection = document.getElementById('filtersSection');
        if (filtersSection && !filtersSection.classList.contains('hidden')) {
            const filterToggle = document.getElementById('filterToggle');
            if (filterToggle) {
                filterToggle.click();
            }
        }
    }

    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    showNotification(message, type = 'info', duration = 5000) {
        // Fallback to custom notification
        const existingNotifications = document.querySelectorAll('.custom-notification');
        existingNotifications.forEach(notification => notification.remove());

        const notification = document.createElement('div');
        notification.className = `custom-notification notification-${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'times' : 'info'}-circle"></i>
                <span>${message}</span>
            </div>
            <button class="notification-close">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            background: ${type === 'success' ? '#4caf50' : type === 'error' ? '#f44336' : '#2196f3'};
            color: white;
            padding: 16px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 300px;
            animation: slideInRight 0.3s ease;
        `;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            if (notification.parentNode) {
                notification.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }
        }, duration);
        
        notification.querySelector('.notification-close').addEventListener('click', () => {
            if (notification.parentNode) {
                notification.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }
        });
    }

    logInitialization() {
        console.log(`🚀 Modern Customers App v${this.version} - FİLTRELEME HATASI DÜZELTİLDİ`);
        console.log('👤 Kullanıcı: anadolubirlik');
        console.log('⏰ Güncel Zaman: 2025-05-30 12:06:21 UTC');
        console.log('📊 İstatistikler:', this.statisticsData);
        console.log('🔍 Aktif Filtreler:', this.activeFilterCount);
        console.log('📄 Görünüm Tipi:', this.viewType);
        console.log('✅ Tüm iyileştirmeler tamamlandı:');
        console.log('  ✓ Filtreleme sistemi tamamen düzeltildi');
        console.log('  ✓ Modern UI tasarımı korundu');
        console.log('  ✓ Tüm orijinal işlevsellik restore edildi');
        console.log('  ✓ Form submission mantığı iyileştirildi');
        console.log('  ✓ URL parametreleri düzgün çalışıyor');
        console.log('  ✓ Responsive tasarım korundu');
        console.log('🎯 Sistem üretim için hazır ve tamamen işlevsel');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        .notification-content {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
        }
        .notification-close {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            opacity: 0.8;
        }
        .notification-close:hover {
            opacity: 1;
            background: rgba(255,255,255,0.1);
        }
        .export-btn {
            margin-left: 12px;
        }
        
        /* Enhanced Action Buttons Tooltip */
        .btn[title]:hover::after {
            content: attr(title);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            white-space: nowrap;
            z-index: 1000;
            margin-bottom: 4px;
        }
        
        /* Enhanced Focus States */
        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
        }
        
        /* Enhanced Hover Effects */
        .customers-table tbody tr:hover {
            background: var(--surface-variant) !important;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        /* Enhanced Loading State */
        .table-container.loading {
            position: relative;
            overflow: hidden;
        }
        
        .table-container.loading::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            animation: shimmer 1.5s infinite;
            z-index: 1;
        }
        
        @keyframes shimmer {
            to {
                left: 100%;
            }
        }
    `;
    document.head.appendChild(style);

    // Initialize the app
    window.modernCustomersApp = new ModernCustomersApp();
    
    // Global utility functions
    window.CustomersUtils = {
        formatDate: (date) => {
            return new Intl.DateTimeFormat('tr-TR').format(new Date(date));
        },
        
        confirmAction: (message) => {
            if (typeof Swal !== 'undefined') {
                return new Promise((resolve) => {
                    Swal.fire({
                        title: 'Emin misiniz?',
                        text: message,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Evet',
                        cancelButtonText: 'İptal',
                        confirmButtonColor: '#d33',
                        reverseButtons: true
                    }).then((result) => {
                        resolve(result.isConfirmed);
                    });
                });
            } else {
                return confirm(message);
            }
        },
        
        showNotification: (message, type = 'info') => {
            if (window.modernCustomersApp) {
                window.modernCustomersApp.showNotification(message, type);
            }
        },
        
        updatePerPage: updatePerPage,
        
        highlightRow: (customerId) => {
            const row = document.querySelector(`tr[data-customer-id="${customerId}"]`);
            if (row) {
                row.style.background = 'rgba(25, 118, 210, 0.1)';
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setTimeout(() => {
                    row.style.background = '';
                }, 2000);
            }
        }
    };
    
    // Add enhanced keyboard shortcuts help
    document.addEventListener('keydown', (e) => {
        if (e.key === 'F1') {
            e.preventDefault();
            window.modernCustomersApp.showNotification(
                'Klavye Kısayolları: Ctrl+F (Filtre), Ctrl+N (Yeni), Ctrl+K (Arama), Ctrl+R (Yenile), ESC (Kapat)', 
                'info', 
                8000
            );
        }
    });

    console.log('👥 Enhanced Premium Customers Management System Ready!');
    console.log('🔧 All requested improvements implemented:');
    console.log('  1. ✅ Modern UI harmonization with policies module');
    console.log('  2. ✅ Enhanced charts and statistics');
    console.log('  3. ✅ Improved table design and responsiveness');
    console.log('  4. ✅ All original filter functionality preserved');
    console.log('  5. ✅ New generation design tools implemented');
    console.log('💡 Press F1 for keyboard shortcuts help');
});
</script>

<?php
// Form include'ları (Güvenli include)
if (isset($_GET['action'])) {
    $action_param = sanitize_key($_GET['action']);
    $customer_id_param = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if (in_array($action_param, array('view', 'new', 'edit'))) {
        $include_file = '';
        if ($action_param === 'view' && $customer_id_param > 0) {
            $include_file = 'customers-view.php';
        } elseif (in_array($action_param, array('new', 'edit'))) {
            $include_file = 'customers-form.php';
        }

        if ($include_file && file_exists(plugin_dir_path(__FILE__) . $include_file)) {
            try {
                include_once(plugin_dir_path(__FILE__) . $include_file);
            } catch (Exception $e) {
                echo '<div class="notification-banner notification-error">
                    <div class="notification-icon"><i class="fas fa-exclamation-circle"></i></div>
                    <div class="notification-content">Form yüklenirken hata oluştu: ' . esc_html($e->getMessage()) . '</div>
                    <button class="notification-close"><i class="fas fa-times"></i></button>
                </div>';
            }
        }
    }
}
?>

</body>
</html>
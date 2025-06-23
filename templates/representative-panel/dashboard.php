<?php
/**
 * Insurance CRM
 *
 * @package     Insurance_CRM
 * @author      Mehmet BALKAY | Anadolu Birlik
 * @copyright   2025 Anadolu Birlik
 * @license     GPL-2.0+
 *
 * Plugin Name: Insurance CRM
 * Plugin URI:  https://github.com/anadolubirlik/insurance-crm
 * Description: Sigorta acenteleri için müşteri, poliçe ve görev yönetim sistemi.
 * Plugin Version:     1.4.9
 * Pagename : dashboard.php
 * Page Version: 1.2.0
 * Author:      Mehmet BALKAY | Anadolu Birlik
 * Author URI:  https://www.balkay.net
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

// *** ENHANCED LICENSE VALIDATION ***
global $insurance_crm_license_manager;

// Perform license check on dashboard access
if ($insurance_crm_license_manager) {
    error_log('[LISANS DEBUG] Frontend dashboard access - performing license check for user: ' . $user->user_login);
    $insurance_crm_license_manager->perform_license_check();
    
    // Check if user can access data
    if (!$insurance_crm_license_manager->can_access_data()) {
        $license_status = get_option('insurance_crm_license_status', 'inactive');
        $is_restricted = get_option('insurance_crm_license_access_restricted', false);
        
        error_log('[LISANS DEBUG] Frontend access denied - Status: ' . $license_status . ', Restricted: ' . ($is_restricted ? 'Yes' : 'No'));
        
        // If license is completely invalid or expired beyond grace period, restrict access
        if (!$insurance_crm_license_manager->is_in_grace_period() && $license_status !== 'active') {
            // Only allow access to license management
            $current_view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'dashboard';
            if ($current_view !== 'license-management') {
                // Redirect to license management
                wp_safe_redirect(add_query_arg('view', 'license-management', get_permalink()));
                exit;
            }
        }
    }
}

$current_user = wp_get_current_user();
$current_view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'dashboard';

global $wpdb;
$representative = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}insurance_crm_representatives 
     WHERE user_id = %d AND status = 'active'",
    $current_user->ID
));

if (!$representative) {
    wp_die('Müşteri temsilcisi kaydınız bulunamadı veya hesabınız pasif durumda.');
}

// Include enhanced dashboard functions
require_once(dirname(__FILE__) . '/../../includes/dashboard-functions.php');

// Include template colors for consistent styling
include_once(dirname(__FILE__) . '/template-colors.php');

// Get site appearance settings for birthday panel colors
$site_settings = get_option('insurance_crm_settings', array());
$primary_color = isset($site_settings['site_appearance']['primary_color']) ? $site_settings['site_appearance']['primary_color'] : '#3498db';
$secondary_color = isset($site_settings['site_appearance']['secondary_color']) ? $site_settings['site_appearance']['secondary_color'] : '#ffd93d';

// Role management functions are now defined in the main plugin file

/**
 * Ekip üyeleri listesi
 */
function get_team_members($user_id) {
    global $wpdb;
    $settings = get_option('insurance_crm_settings', []);
    $teams = $settings['teams_settings']['teams'] ?? [];
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $user_id
    ));
    if (!$rep) return [];
    foreach ($teams as $team) {
        if ($team['leader_id'] == $rep->id) {
            $members = array_merge([$team['leader_id']], $team['members']);
            return array_unique($members);
        }
    }
    return [];
}

/**
 * Dashboard görünümü ve alt menüler için temsilci ID'lerini döndüren fonksiyon
 * Görünüm parametresine göre farklı davranış gösterir
 */
function get_dashboard_representatives($user_id, $current_view = 'dashboard') {
    global $wpdb;
    
    // Temsilci ID'sini al
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $user_id
    ));
    
    if (!$rep) return [];
    
    $rep_id = $rep->id;
    
    // Patron ve Müdür için (tam yetkili kullanıcılar):
    if (has_full_admin_access($user_id)) {
        // Dashboard, ana menüler ve yönetim ekranlarında tüm verileri görsün
        if ($current_view == 'dashboard' || 
            $current_view == 'customers' || 
            $current_view == 'policies' ||
            $current_view == 'tasks' ||
            $current_view == 'reports' ||
            $current_view == 'organization' ||
            $current_view == 'all_personnel' ||
            $current_view == 'manager_dashboard' || 
            $current_view == 'team_leaders') {
            
            return $wpdb->get_col("SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE status = 'active'");
        } 
        // Alt menülerde belirli bir ekip gösteriliyorsa sadece o ekibi göster
        else if (strpos($current_view, 'team_') === 0 && isset($_GET['team_id'])) {
            $selected_team_id = sanitize_text_field($_GET['team_id']);
            $settings = get_option('insurance_crm_settings', []);
            $teams = $settings['teams_settings']['teams'] ?? [];
            if (isset($teams[$selected_team_id])) {
                $team_members = array_merge([$teams[$selected_team_id]['leader_id']], $teams[$selected_team_id]['members']);
                return $team_members;
            }
        }
        // Diğer tüm görünümlerde tüm temsilcileri göster
        return $wpdb->get_col("SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE status = 'active'");
    }
    
    // Ekip lideri için
    if (is_team_leader($user_id)) {
        // Dashboard ve ana menülerde sadece kendi verilerini görür
        if ($current_view == 'dashboard') {
            return [$rep_id];
        }
        // Ekip lideri menülerinde ekibinin tüm verilerini görür
        else if ($current_view == 'team' || 
                strpos($current_view, 'team_') === 0 ||
                $current_view == 'organization' || // Organizasyon yönetimine erişim
                $current_view == 'all_personnel') { // Personel yönetimine erişim
            
            $team_members = get_team_members($user_id);
            return !empty($team_members) ? $team_members : [$rep_id];
        }
        // Ana menülerde (customers, policies, tasks) tüm ekip üyelerinin verilerini görsün (istenildiği gibi)
        else if ($current_view == 'customers' || 
                $current_view == 'policies' ||
                $current_view == 'tasks' ||
                $current_view == 'reports') {
                
            $team_members = get_team_members($user_id);
            return !empty($team_members) ? $team_members : [$rep_id];
        }
        // Diğer tüm durumlarda sadece kendi verilerini göster
        return [$rep_id];
    }
    
    // Normal müşteri temsilcisi sadece kendi verilerini görür
    return [$rep_id];
}

/**
 * Kullanıcının silme yetkisi olup olmadığını kontrol eder
 */
function can_delete_items($user_id) {
    return has_full_admin_access($user_id);
}

/**
 * Kullanıcının pasife çekme yetkisi olup olmadığını kontrol eder
 * Patron ve müdür hem silme hem pasife çekme yetkisine, 
 * Ekip lideri ve temsilciler sadece pasife çekme yetkisine sahiptir
 */
function can_deactivate_items($user_id) {
    return true; // Tüm kullanıcılar pasife çekebilir
}

/**
 * Silme işlemini loglar
 */
function log_delete_action($user_id, $item_id, $item_type) {
    global $wpdb;
    
    // Kullanıcı bilgilerini al
    $user = get_userdata($user_id);
    $user_name = $user ? $user->display_name : 'Bilinmeyen Kullanıcı';
    
    // Item türüne göre detayları al
    $item_details = '';
    if ($item_type == 'customer') {
        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT first_name, last_name FROM {$wpdb->prefix}insurance_crm_customers WHERE id = %d",
            $item_id
        ));
        if ($customer) {
            $item_details = $customer->first_name . ' ' . $customer->last_name;
        }
    } elseif ($item_type == 'policy') {
        $policy = $wpdb->get_row($wpdb->prepare(
            "SELECT policy_number FROM {$wpdb->prefix}insurance_crm_policies WHERE id = %d",
            $item_id
        ));
        if ($policy) {
            $item_details = $policy->policy_number;
        }
    }
    
    // Log mesajını hazırla
    $log_message = sprintf(
        'Kullanıcı %s (#%d) tarafından %s ID: %d, Detay: %s silindi.',
        $user_name,
        $user_id,
        $item_type == 'customer' ? 'müşteri' : 'poliçe',
        $item_id,
        $item_details
    );
    
    // Log tablosuna kaydet
    $wpdb->insert(
        $wpdb->prefix . 'insurance_crm_activity_logs',
        [
            'user_id' => $user_id,
            'action_type' => 'delete',
            'item_type' => $item_type,
            'item_id' => $item_id,
            'details' => $log_message,
            'created_at' => current_time('mysql')
        ]
    );
    
    // WordPress log dosyasına da yazdır
    error_log($log_message);
    
    return true;
}

/**
 * Müşteri veya poliçe için silme butonu oluşturur 
 */
function get_delete_button($item_id, $item_type, $user_id) {
    if (can_delete_items($user_id)) {
        $confirm_message = $item_type == 'customers' ? 'Bu müşteriyi silmek istediğinizden emin misiniz?' : 'Bu poliçeyi silmek istediğinizden emin misiniz?';
        
        return '<a href="' . generate_panel_url($item_type, 'delete', $item_id) . '" class="table-action" title="Sil" onclick="return confirm(\'' . $confirm_message . '\');">
                <i class="dashicons dashicons-trash"></i>
            </a>';
    }
    return '';
}

/**
 * Müşteri veya poliçe için pasife çekme butonu oluşturur 
 */
function get_deactivate_button($item_id, $item_type, $user_id, $current_status = 'active') {
    if (can_deactivate_items($user_id)) {
        $action = $current_status == 'active' ? 'deactivate' : 'activate';
        $icon = $current_status == 'active' ? 'dashicons-hidden' : 'dashicons-visibility';
        $title = $current_status == 'active' ? 'Pasife Al' : 'Aktif Et';
        $confirm_message = $current_status == 'active' ? 
            ($item_type == 'customers' ? 'Bu müşteriyi pasife almak istediğinizden emin misiniz?' : 'Bu poliçeyi pasife almak istediğinizden emin misiniz?') :
            ($item_type == 'customers' ? 'Bu müşteriyi aktif etmek istediğinizden emin misiniz?' : 'Bu poliçeyi aktif etmek istediğinizden emin misiniz?');
        
        return '<a href="' . generate_panel_url($item_type, $action, $item_id) . '" class="table-action" title="' . $title . '" onclick="return confirm(\'' . $confirm_message . '\');">
                <i class="dashicons ' . $icon . '"></i>
            </a>';
    }
    return '';
}


/**
 * Hedef performans bilgilerini getirir
 */
function get_representative_targets($rep_ids) {
    global $wpdb;
    if (empty($rep_ids)) return [];
    
    $placeholders = implode(',', array_fill(0, count($rep_ids), '%d'));
    $targets = $wpdb->get_results($wpdb->prepare(
        "SELECT r.id, r.monthly_target, r.target_policy_count, u.display_name, r.title
         FROM {$wpdb->prefix}insurance_crm_representatives r
         JOIN {$wpdb->users} u ON r.user_id = u.ID
         WHERE r.id IN ($placeholders)",
        ...$rep_ids
    ), ARRAY_A);
    
    return $targets;
}

/**
 * Temsilcinin bu ayki performans verilerini getirir
 */
function get_representative_performance($rep_id) {
    global $wpdb;
    
    $this_month_start = date('Y-m-01 00:00:00');
    $this_month_end = date('Y-m-t 23:59:59');
    
    $total_premium = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
         FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id = %d",
        $rep_id
    )) ?: 0;
    
    $current_month_premium = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
         FROM {$wpdb->prefix}insurance_crm_policies
         WHERE representative_id = %d 
         AND start_date BETWEEN %s AND %s",
        $rep_id, $this_month_start, $this_month_end
    )) ?: 0;
    
    $total_policy_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id = %d AND cancellation_date IS NULL",
        $rep_id
    )) ?: 0;
    
    $current_month_policy_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id = %d 
         AND start_date BETWEEN %s AND %s
         AND cancellation_date IS NULL",
        $rep_id, $this_month_start, $this_month_end
    )) ?: 0;
    
    return [
        'total_premium' => $total_premium,
        'current_month_premium' => $current_month_premium,
        'total_policy_count' => $total_policy_count,
        'current_month_policy_count' => $current_month_policy_count
    ];
}

/**
 * Ekip Detay sayfası için fonksiyon
 */
function generate_team_detail_url($team_id) {
    return generate_panel_url('team_detail', '', '', array('team_id' => $team_id));
}

// Kullanıcının rolünü belirle
$user_role = get_user_role_in_hierarchy($current_user->ID);

// Dashboard görünümü ve menülere göre yetkili temsilci ID'lerini al
$rep_ids = get_dashboard_representatives($current_user->ID, $current_view);

// Ekip hedefi hesaplama
$team_target = 0;
$team_policy_target = 0;
if ($current_view === 'team' || strpos($current_view, 'team_') === 0 || has_full_admin_access($current_user->ID)) {
    $targets = $wpdb->get_results($wpdb->prepare(
        "SELECT monthly_target, target_policy_count FROM {$wpdb->prefix}insurance_crm_representatives 
         WHERE id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")",
        ...$rep_ids
    ));
    foreach ($targets as $target) {
        $team_target += floatval($target->monthly_target);
        $team_policy_target += intval($target->target_policy_count);
    }
} else {
    $team_target = $representative->monthly_target;
    $team_policy_target = $representative->target_policy_count;
}

// Üye performans verileri
$member_performance = [];
if ($current_view === 'team' || has_full_admin_access($current_user->ID)) {
    foreach ($rep_ids as $rep_id) {
        $member_data = $wpdb->get_row($wpdb->prepare(
            "SELECT r.id, u.display_name, r.title, r.monthly_target, r.target_policy_count 
             FROM {$wpdb->prefix}insurance_crm_representatives r 
             JOIN {$wpdb->users} u ON r.user_id = u.ID 
             WHERE r.id = %d",
            $rep_id
        ));
        if ($member_data) {
            $customers = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers 
                 WHERE representative_id = %d",
                $rep_id
            ));
            $policies = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id = %d AND cancellation_date IS NULL",
                $rep_id
            ));
            $premium = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
                 FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id = %d",
                $rep_id
            ));
            
            // Bu ay eklenen poliçe ve prim
            $this_month_start = date('Y-m-01 00:00:00');
            $this_month_end = date('Y-m-t 23:59:59');
            
            $this_month_policies = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id = %d 
                 AND start_date BETWEEN %s AND %s
                 AND cancellation_date IS NULL",
                $rep_id, $this_month_start, $this_month_end
            ));
            
            $this_month_premium = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
                 FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id = %d 
                 AND start_date BETWEEN %s AND %s",
                $rep_id, $this_month_start, $this_month_end
            ));
            
            // Hedefe uzaklık hesaplama
            $premium_achievement_rate = $member_data->monthly_target > 0 ? ($this_month_premium / $member_data->monthly_target) * 100 : 0;
            $policy_achievement_rate = $member_data->target_policy_count > 0 ? ($this_month_policies / $member_data->target_policy_count) * 100 : 0;
            
            $member_performance[] = [
                'id' => $member_data->id,
                'name' => $member_data->display_name,
                'title' => $member_data->title,
                'customers' => $customers,
                'policies' => $policies,
                'premium' => $premium,
                'this_month_policies' => $this_month_policies,
                'this_month_premium' => $this_month_premium,
                'monthly_target' => $member_data->monthly_target,
                'target_policy_count' => $member_data->target_policy_count,
                'premium_achievement_rate' => $premium_achievement_rate,
                'policy_achievement_rate' => $policy_achievement_rate
            ];
        }
    }
}

// Ekip performans verilerini sıralama
if (!empty($member_performance)) {
    // Premium'a göre sıralama (en yüksekten en düşüğe)
    usort($member_performance, function($a, $b) {
        return $b['premium'] <=> $a['premium'];
    });
}

// Filtre tarihi belirleme - Varsayılan olarak bu yılı göster
$date_filter_period = isset($_GET['date_filter']) ? sanitize_text_field($_GET['date_filter']) : 'this_year';
$custom_start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
$custom_end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';

// Seçilen tarih aralığına göre başlangıç ve bitiş tarihlerini belirle
switch ($date_filter_period) {
    case 'last_3_months':
        $filter_start_date = date('Y-m-d', strtotime('-3 months'));
        $filter_end_date = date('Y-m-d');
        $filter_title = 'Son 3 Ay';
        break;
    case 'last_6_months':
        $filter_start_date = date('Y-m-d', strtotime('-6 months'));
        $filter_end_date = date('Y-m-d');
        $filter_title = 'Son 6 Ay';
        break;
    case 'this_year':
        $filter_start_date = date('Y-01-01');
        $filter_end_date = date('Y-m-d');
        $filter_title = 'Bu Yıl';
        break;
    case 'custom':
        $filter_start_date = !empty($custom_start_date) ? $custom_start_date : date('Y-m-01');
        $filter_end_date = !empty($custom_end_date) ? $custom_end_date : date('Y-m-d');
        $filter_title = date('d.m.Y', strtotime($filter_start_date)) . ' - ' . date('d.m.Y', strtotime($filter_end_date));
        break;
    case 'this_month':
        $filter_start_date = date('Y-m-01');
        $filter_end_date = date('Y-m-t');
        $filter_title = 'Bu Ay';
        break;
    case 'this_year':
    default:
        $filter_start_date = date('Y-01-01');
        $filter_end_date = date('Y-m-d');
        $filter_title = 'Bu Yıl';
        break;
}

// Filtre parametrelerini URL'e eklemek için yardımcı fonksiyon
function add_date_filter_to_url($url, $period, $start_date = '', $end_date = '') {
    $url = add_query_arg('date_filter', $period, $url);
    
    if ($period === 'custom') {
        if (!empty($start_date)) {
            $url = add_query_arg('start_date', $start_date, $url);
        }
        if (!empty($end_date)) {
            $url = add_query_arg('end_date', $end_date, $url);
        }
    }
    
    return $url;
}

// Mevcut sorguları ekip için uyarlama (tarih filtresi eklenmiş)
$total_customers = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")",
    ...$rep_ids
));

$new_customers = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND created_at BETWEEN %s AND %s",
    ...array_merge($rep_ids, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
));
$new_customers = $new_customers ?: 0;
$customer_increase_rate = $total_customers > 0 ? ($new_customers / $total_customers) * 100 : 0;

// Apply date filtering based on selected filter period
if ($date_filter_period === 'this_year' || empty($_GET['date_filter'])) {
    // Default: current year only
    $total_policies = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
         AND YEAR(start_date) = YEAR(CURDATE())
         AND cancellation_date IS NULL",
        ...$rep_ids
    ));
} else {
    // Use date filter range
    $total_policies = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
         AND start_date BETWEEN %s AND %s
         AND cancellation_date IS NULL",
        ...array_merge($rep_ids, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
    ));
}

$new_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND start_date BETWEEN %s AND %s
     AND cancellation_date IS NULL",
    ...array_merge($rep_ids, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
));
$new_policies = $new_policies ?: 0;

$this_period_cancelled_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND cancellation_date BETWEEN %s AND %s",
    ...array_merge($rep_ids, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
));
$this_period_cancelled_policies = $this_period_cancelled_policies ?: 0;

$policy_increase_rate = $total_policies > 0 ? ($new_policies / $total_policies) * 100 : 0;

// Detaylı poliçe istatistikleri - date filter'a göre
if ($date_filter_period === 'this_year' || empty($_GET['date_filter'])) {
    // Default: current year only
    $current_year_active_policies = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
         AND YEAR(start_date) = YEAR(CURDATE())
         AND status = 'aktif' 
         AND cancellation_date IS NULL",
        ...$rep_ids
    )) ?: 0;
} else {
    // Use date filter range
    $current_year_active_policies = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
         AND start_date BETWEEN %s AND %s
         AND status = 'aktif' 
         AND cancellation_date IS NULL",
        ...array_merge($rep_ids, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
    )) ?: 0;
}

if ($date_filter_period === 'this_year' || empty($_GET['date_filter'])) {
    // Default: current year only
    $current_year_cancelled_policies = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
         AND YEAR(start_date) = YEAR(CURDATE())
         AND cancellation_date IS NOT NULL",
        ...$rep_ids
    )) ?: 0;
} else {
    // Use date filter range
    $current_year_cancelled_policies = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
         AND start_date BETWEEN %s AND %s
         AND cancellation_date IS NOT NULL",
        ...array_merge($rep_ids, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
    )) ?: 0;
}

if ($date_filter_period === 'this_year' || empty($_GET['date_filter'])) {
    // Default: current year only
    $current_year_deleted_policies = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
         AND YEAR(start_date) = YEAR(CURDATE())
         AND is_deleted = 1",
        ...$rep_ids
    )) ?: 0;
} else {
    // Use date filter range
    $current_year_deleted_policies = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
         AND start_date BETWEEN %s AND %s
         AND is_deleted = 1",
        ...array_merge($rep_ids, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
    )) ?: 0;
}

if ($date_filter_period === 'this_year' || empty($_GET['date_filter'])) {
    // Default: current year only
    $current_year_total_premium = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(premium_amount), 0) 
         FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
         AND YEAR(start_date) = YEAR(CURDATE())
         AND cancellation_date IS NULL",
        ...$rep_ids
    )) ?: 0;
} else {
    // Use date filter range
    $current_year_total_premium = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(premium_amount), 0) 
         FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
         AND start_date BETWEEN %s AND %s
         AND cancellation_date IS NULL",
        ...array_merge($rep_ids, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
    )) ?: 0;
}

$total_refunded_amount = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(refunded_amount), 0) 
     FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")",
    ...$rep_ids
));
$total_refunded_amount = $total_refunded_amount ?: 0;

$period_refunded_amount = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(refunded_amount), 0) 
     FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
     AND cancellation_date BETWEEN %s AND %s",
    ...array_merge($rep_ids, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
));
$period_refunded_amount = $period_refunded_amount ?: 0;

// Use current year total for production display
$total_premium = $current_year_total_premium;
if ($total_premium === null) $total_premium = 0;

$new_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
     FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND start_date BETWEEN %s AND %s",
    ...array_merge($rep_ids, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
));
$new_premium = $new_premium ?: 0;
$premium_increase_rate = $total_premium > 0 ? ($new_premium / $total_premium) * 100 : 0;

$current_month = date('Y-m');
$current_month_start = date('Y-m-01');
$current_month_end = date('Y-m-t');

$current_month_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
     FROM {$wpdb->prefix}insurance_crm_policies
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND start_date BETWEEN %s AND %s",
    ...array_merge($rep_ids, [$current_month_start . ' 00:00:00', $current_month_end . ' 23:59:59'])
));

if ($current_month_premium === null) $current_month_premium = 0;

$monthly_target = $team_target > 0 ? $team_target : 1;
$achievement_rate = ($current_month_premium / $monthly_target) * 100;
$achievement_rate = min(100, $achievement_rate);

// Poliçe hedef gerçekleşme oranı
$policy_achievement_rate = ($team_policy_target > 0 && $new_policies > 0) ? 
    ($new_policies / $team_policy_target) * 100 : 0;
$policy_achievement_rate = min(100, $policy_achievement_rate);

// Performans Metrikleri - En çok üretim yapan, en çok yeni iş, en çok yeni müşteri, en çok iptali olan
$performance_metrics = get_performance_metrics($rep_ids, $date_filter_period, $filter_start_date, $filter_end_date);

/**
 * Performans metriklerini hesaplar
 */
function get_performance_metrics($rep_ids, $period = 'this_month', $start_date = null, $end_date = null) {
    global $wpdb;
    $table_policies = $wpdb->prefix . 'insurance_crm_policies';
    $table_customers = $wpdb->prefix . 'insurance_crm_customers';
    $table_reps = $wpdb->prefix . 'insurance_crm_representatives';
    
    if (empty($rep_ids)) {
        return [
            'top_producer' => null,
            'most_new_business' => null,
            'most_new_customers' => null,
            'most_cancellations' => null
        ];
    }
    
    $rep_ids_str = implode(',', array_map('intval', $rep_ids));
    
    // Varsayılan tarih değerlerini ayarla
    if (!$start_date) {
        switch ($period) {
            case 'last_3_months':
                $start_date = date('Y-m-d', strtotime('-3 months'));
                break;
            case 'last_6_months':
                $start_date = date('Y-m-d', strtotime('-6 months'));
                break;
            case 'this_year':
                $start_date = date('Y-01-01');
                break;
            case 'this_month':
            default:
                $start_date = date('Y-m-01');
                break;
        }
    }
    
    if (!$end_date) {
        $end_date = date('Y-m-d');
    }
    
    $start_datetime = $start_date . ' 00:00:00';
    $end_datetime = $end_date . ' 23:59:59';
    
    // En çok üretim yapan (toplam prim)
    $top_producer = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            p.representative_id,
            SUM(p.premium_amount) - COALESCE(SUM(p.refunded_amount), 0) as total_premium,
            COUNT(p.id) as policy_count,
            r.user_id,
            u.display_name,
            r.title
        FROM 
            {$table_policies} p
        LEFT JOIN 
            {$table_reps} r ON p.representative_id = r.id
        LEFT JOIN 
            {$wpdb->users} u ON r.user_id = u.ID
        WHERE 
            p.representative_id IN ({$rep_ids_str})
            AND p.status = 'active'
            AND p.start_date BETWEEN %s AND %s
        GROUP BY 
            p.representative_id
        ORDER BY 
            total_premium DESC
        LIMIT 1",
        $start_datetime, $end_datetime
    ));
    
    // En çok yeni iş (poliçe sayısı)
    $most_new_business = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            p.representative_id,
            COUNT(p.id) as policy_count,
            SUM(p.premium_amount) - COALESCE(SUM(p.refunded_amount), 0) as total_premium,
            r.user_id,
            u.display_name,
            r.title
        FROM 
            {$table_policies} p
        LEFT JOIN 
            {$table_reps} r ON p.representative_id = r.id
        LEFT JOIN 
            {$wpdb->users} u ON r.user_id = u.ID
        WHERE 
            p.representative_id IN ({$rep_ids_str})
            AND p.status = 'active'
            AND p.start_date BETWEEN %s AND %s
        GROUP BY 
            p.representative_id
        ORDER BY 
            policy_count DESC
        LIMIT 1",
        $start_datetime, $end_datetime
    ));
    
    // En çok yeni müşteri
    $most_new_customers = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            c.representative_id,
            COUNT(c.id) as customer_count,
            r.user_id,
            u.display_name,
            r.title
        FROM 
            {$table_customers} c
        LEFT JOIN
            {$table_reps} r ON c.representative_id = r.id
        LEFT JOIN 
            {$wpdb->users} u ON r.user_id = u.ID
        WHERE 
            c.representative_id IN ({$rep_ids_str})
            AND c.created_at BETWEEN %s AND %s
        GROUP BY 
            c.representative_id
        ORDER BY 
            customer_count DESC
        LIMIT 1",
        $start_datetime, $end_datetime
    ));
    
    // En çok iptal eden
    $most_cancellations = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            p.representative_id,
            COUNT(p.id) as cancellation_count,
            COALESCE(SUM(p.refunded_amount), 0) as refunded_amount,
            r.user_id,
            u.display_name,
            r.title
        FROM 
            {$table_policies} p
        LEFT JOIN 
            {$table_reps} r ON p.representative_id = r.id
        LEFT JOIN 
            {$wpdb->users} u ON r.user_id = u.ID
        WHERE 
            p.representative_id IN ({$rep_ids_str})
            AND p.cancellation_date BETWEEN %s AND %s
        GROUP BY 
            p.representative_id
        ORDER BY 
            cancellation_count DESC
        LIMIT 1",
        $start_datetime, $end_datetime
    ));
    
    return [
        'top_producer' => $top_producer,
        'most_new_business' => $most_new_business,
        'most_new_customers' => $most_new_customers,
        'most_cancellations' => $most_cancellations
    ];
}

$recent_policies = $wpdb->get_results($wpdb->prepare(
    "SELECT p.*, c.first_name, c.last_name, c.gender
     FROM {$wpdb->prefix}insurance_crm_policies p
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
     WHERE p.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
     AND p.cancellation_date IS NULL
     ORDER BY p.created_at DESC
     LIMIT 5",
    ...$rep_ids
));

$monthly_production_data = array();
$monthly_refunded_data = array();

// Initialize monthly data based on date filter period
if ($date_filter_period === 'this_year' || empty($_GET['date_filter'])) {
    // Current year: Initialize all months from January to current month
    $current_year = date('Y');
    $current_month = date('n');
    for ($i = 1; $i <= $current_month; $i++) {
        $month_year = $current_year . '-' . str_pad($i, 2, '0', STR_PAD_LEFT);
        $monthly_production_data[$month_year] = 0;
        $monthly_refunded_data[$month_year] = 0;
    }
} else {
    // For other date filters, use last 6 months approach
    for ($i = 5; $i >= 0; $i--) {
        $month_year = date('Y-m', strtotime("-$i months"));
        $monthly_production_data[$month_year] = 0;
        $monthly_refunded_data[$month_year] = 0;
    }
}

try {
    // Apply date filtering based on selected filter period for production data
    if ($date_filter_period === 'this_year' || empty($_GET['date_filter'])) {
        // Default: current year only
        $actual_data = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(start_date, '%%Y-%%m') as month_year, 
                    COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0) as total,
                    COALESCE(SUM(refunded_amount), 0) as refunded
             FROM {$wpdb->prefix}insurance_crm_policies 
             WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
             AND YEAR(start_date) = YEAR(CURDATE())
             GROUP BY month_year
             ORDER BY month_year ASC",
            ...$rep_ids
        ));
    } else {
        // Use date filter range
        $actual_data = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(start_date, '%%Y-%%m') as month_year, 
                    COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0) as total,
                    COALESCE(SUM(refunded_amount), 0) as refunded
             FROM {$wpdb->prefix}insurance_crm_policies 
             WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
             AND start_date BETWEEN %s AND %s
             GROUP BY month_year
             ORDER BY month_year ASC",
            ...array_merge($rep_ids, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
        ));
    }
    
    foreach ($actual_data as $data) {
        if (isset($monthly_production_data[$data->month_year])) {
            $monthly_production_data[$data->month_year] = (float)$data->total;
            $monthly_refunded_data[$data->month_year] = (float)$data->refunded;
        }
    }
} catch (Exception $e) {
    error_log('Üretim verileri çekilirken hata: ' . $e->getMessage());
}

$monthly_production = array();
foreach ($monthly_production_data as $month_year => $total) {
    $monthly_production[] = array(
        'month' => $month_year,
        'total' => $total
    );
}

if ($wpdb->last_error) {
    error_log('SQL Hatası: ' . $wpdb->last_error);
}

$upcoming_renewals = $wpdb->get_results($wpdb->prepare(
    "SELECT p.*, c.first_name, c.last_name, c.gender
     FROM {$wpdb->prefix}insurance_crm_policies p
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
     WHERE p.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND p.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
     AND p.cancellation_date IS NULL
     ORDER BY p.end_date ASC
     LIMIT 5",
    ...$rep_ids
));

$expired_policies = $wpdb->get_results($wpdb->prepare(
    "SELECT p.*, c.first_name, c.last_name, c.gender
     FROM {$wpdb->prefix}insurance_crm_policies p
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
     WHERE p.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND p.end_date < CURDATE()
     AND p.status != 'iptal'
     AND p.cancellation_date IS NULL
     ORDER BY p.end_date DESC
     LIMIT 5",
    ...$rep_ids
));

$notification_count = 0;
$notifications_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}insurance_crm_notifications'") === $wpdb->prefix . 'insurance_crm_notifications';

if ($notifications_table_exists) {
    $notification_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_notifications
         WHERE user_id = %d AND is_read = 0",
        $current_user->ID
    ));
    if ($notification_count === null) $notification_count = 0;
}

$upcoming_tasks_count = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_tasks
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND status = 'pending'
     AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)",
    ...$rep_ids
));
if ($upcoming_tasks_count === null) $upcoming_tasks_count = 0;

$total_notification_count = $notification_count + $upcoming_tasks_count;

$upcoming_tasks = $wpdb->get_results($wpdb->prepare(
    "SELECT t.*, c.first_name, c.last_name 
     FROM {$wpdb->prefix}insurance_crm_tasks t
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON t.customer_id = c.id
     WHERE t.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND t.status = 'pending'
     AND t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
     ORDER BY t.due_date ASC
     LIMIT 5",
    ...$rep_ids
));

$current_month_start = date('Y-m-01');
$next_month_end = date('Y-m-t', strtotime('+1 month'));

$calendar_tasks = $wpdb->get_results($wpdb->prepare(
    "SELECT DATE_FORMAT(DATE(due_date), '%Y-%m-%d') as task_date, COUNT(*) as task_count
     FROM {$wpdb->prefix}insurance_crm_tasks
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
     AND status IN ('pending', 'in_progress')
     AND due_date BETWEEN %s AND %s
     GROUP BY DATE(due_date)",
    ...array_merge($rep_ids, [$current_month_start . ' 00:00:00', $next_month_end . ' 23:59:59'])
));

if ($wpdb->last_error) {
    error_log('Takvim Görev Sorgusu Hatası: ' . $wpdb->last_error);
}

$upcoming_tasks_list = $wpdb->get_results($wpdb->prepare(
    "SELECT t.*, c.first_name, c.last_name 
     FROM {$wpdb->prefix}insurance_crm_tasks t
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON t.customer_id = c.id
     WHERE t.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND t.status IN ('pending', 'in_progress')
     AND t.due_date BETWEEN %s AND %s
     ORDER BY t.due_date ASC
     LIMIT 5",
    ...array_merge($rep_ids, [$current_month_start . ' 00:00:00', $next_month_end . ' 23:59:59'])
));

if ($wpdb->last_error) {
    error_log('Yaklaşan Görevler Sorgusu Hatası: ' . $wpdb->last_error);
}

// Patron ve müdür için özel veri - tüm ekipler
$all_teams = [];
if (has_full_admin_access($current_user->ID)) {
    $settings = get_option('insurance_crm_settings', []);
    $teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();
    
    foreach ($teams as $team_id => $team) {
        $leader_data = $wpdb->get_row($wpdb->prepare(
            "SELECT r.id, u.display_name, r.title, r.monthly_target, r.target_policy_count 
             FROM {$wpdb->prefix}insurance_crm_representatives r 
             JOIN {$wpdb->users} u ON r.user_id = u.ID 
             WHERE r.id = %d",
            $team['leader_id']
        ));
        
        if ($leader_data) {
            // Ekip üyelerinin sayısı
            $member_count = count($team['members']);
            
            // Ekip toplam primi hesaplama
            $team_ids = array_merge([$team['leader_id']], $team['members']);
            $team_premium = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
                 FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id IN (" . implode(',', array_fill(0, count($team_ids), '%d')) . ")",
                ...$team_ids
            )) ?: 0;
            
            // Ekip üyelerinin toplam hedefi
            $team_monthly_target = 0;
            $team_policy_target = 0;
            foreach ($team_ids as $id) {
                $member_target = $wpdb->get_row($wpdb->prepare(
                    "SELECT monthly_target, target_policy_count FROM {$wpdb->prefix}insurance_crm_representatives WHERE id = %d",
                    $id
                ));
                $team_monthly_target += $member_target ? floatval($member_target->monthly_target) : 0;
                $team_policy_target += $member_target ? intval($member_target->target_policy_count) : 0;
            }
            
            // Bu ay üretilen poliçe sayısı
            $month_start = date('Y-m-01 00:00:00');
            $month_end = date('Y-m-t 23:59:59');
            $team_month_policies = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
                WHERE representative_id IN (" . implode(',', array_fill(0, count($team_ids), '%d')) . ")
                AND start_date BETWEEN %s AND %s
                AND cancellation_date IS NULL",
                ...array_merge($team_ids, [$month_start, $month_end])
            )) ?: 0;
            
            // Bu ay üretilen prim
            $team_month_premium = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
                FROM {$wpdb->prefix}insurance_crm_policies 
                WHERE representative_id IN (" . implode(',', array_fill(0, count($team_ids), '%d')) . ")
                AND start_date BETWEEN %s AND %s",
                ...array_merge($team_ids, [$month_start, $month_end])
            )) ?: 0;
            
            // İptal poliçe sayısı ve tutarını al
            $cancelled_policies = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
                WHERE representative_id IN (" . implode(',', array_fill(0, count($team_ids), '%d')) . ")
                AND cancellation_date BETWEEN %s AND %s",
                ...array_merge($team_ids, [$month_start, $month_end])
            )) ?: 0;
            
            $cancelled_premium = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(refunded_amount), 0) FROM {$wpdb->prefix}insurance_crm_policies 
                WHERE representative_id IN (" . implode(',', array_fill(0, count($team_ids), '%d')) . ")
                AND cancellation_date BETWEEN %s AND %s",
                ...array_merge($team_ids, [$month_start, $month_end])
            )) ?: 0;
            
            // Net primi hesapla
            $team_month_net_premium = $team_month_premium - $cancelled_premium;
            
            // Hedef gerçekleşme oranı
            $premium_achievement = $team_monthly_target > 0 ? ($team_month_net_premium / $team_monthly_target) * 100 : 0;
            $policy_achievement = $team_policy_target > 0 ? ($team_month_policies / $team_policy_target) * 100 : 0;
            
            $all_teams[] = [
                'id' => $team_id,
                'name' => $team['name'],
                'leader_id' => $team['leader_id'],
                'leader_name' => $leader_data->display_name,
                'leader_title' => $leader_data->title,
                'member_count' => $member_count,
                'total_premium' => $team_premium,
                'monthly_target' => $team_monthly_target,
                'policy_target' => $team_policy_target,
                'month_policies' => $team_month_policies,
                'month_premium' => $team_month_premium,
                'cancelled_policies' => $cancelled_policies,
                'cancelled_premium' => $cancelled_premium,
                'month_net_premium' => $team_month_net_premium,
                'premium_achievement' => $premium_achievement,
                'policy_achievement' => $policy_achievement,
                'members' => $team['members']
            ];
        }
    }
    
    // Ekipleri toplam prim miktarına göre sırala (en yüksekten en düşüğe)
    usort($all_teams, function($a, $b) {
        return $b['total_premium'] <=> $a['total_premium'];
    });
}

// Tüm temsilcilerin hedef ve performans verileri (yalnızca patron ve müdür için)
$all_representatives_performance = [];
if (has_full_admin_access($current_user->ID)) {
    $all_representatives = $wpdb->get_results(
        "SELECT r.id, r.monthly_target, r.target_policy_count, r.role, u.display_name, r.title
         FROM {$wpdb->prefix}insurance_crm_representatives r 
         JOIN {$wpdb->users} u ON r.user_id = u.ID 
         WHERE r.status = 'active'
         ORDER BY r.role ASC, u.display_name ASC"
    );
    
    foreach ($all_representatives as $rep) {
        $performance = get_representative_performance($rep->id);
        
        // İptal poliçe sayısı ve tutarını al
        $cancelled_policies = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
             WHERE representative_id = %d 
             AND cancellation_date BETWEEN %s AND %s",
            $rep->id, date('Y-m-01 00:00:00'), date('Y-m-t 23:59:59')
        )) ?: 0;
        
        $cancelled_premium = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(refunded_amount), 0) FROM {$wpdb->prefix}insurance_crm_policies 
             WHERE representative_id = %d 
             AND cancellation_date BETWEEN %s AND %s",
            $rep->id, date('Y-m-01 00:00:00'), date('Y-m-t 23:59:59')
        )) ?: 0;
        
        // Net primi hesapla
        $net_premium = $performance['current_month_premium'] - $cancelled_premium;
        
        // Hedef gerçekleşme oranları (net prim üzerinden)
        $premium_achievement = $rep->monthly_target > 0 ? 
            ($net_premium / $rep->monthly_target) * 100 : 0;
        $policy_achievement = $rep->target_policy_count > 0 ? 
            ($performance['current_month_policy_count'] / $rep->target_policy_count) * 100 : 0;
        
        $all_representatives_performance[] = [
            'id' => $rep->id,
            'name' => $rep->display_name,
            'title' => $rep->title,
            'role' => $rep->role,
            'monthly_target' => $rep->monthly_target,
            'target_policy_count' => $rep->target_policy_count,
            'total_premium' => $performance['total_premium'],
            'current_month_premium' => $performance['current_month_premium'],
            'cancelled_policies' => $cancelled_policies,
            'cancelled_premium' => $cancelled_premium,
            'net_premium' => $net_premium, 
            'total_policy_count' => $performance['total_policy_count'],
            'current_month_policy_count' => $performance['current_month_policy_count'],
            'premium_achievement' => $premium_achievement,
            'policy_achievement' => $policy_achievement
        ];
    }
    
    // Managament hierarchy order is already preserved from SQL query (ORDER BY role ASC, display_name ASC)
    // Don't sort by performance to maintain management authority ranking
    // usort($all_representatives_performance, function($a, $b) {
    //     return $b['total_premium'] <=> $a['total_premium'];
    // });
    
    // Keep management hierarchy order intact
    
    // En iyi 3 performans verisi
    
    // En Çok Üretim Yapan
    $top_producers = $all_representatives_performance;
    usort($top_producers, function($a, $b) {
        return $b['total_premium'] <=> $a['total_premium'];
    });
    $top_producers = array_slice($top_producers, 0, 3);
    
    // En Çok Yeni İş
    $top_new_businesses = $all_representatives_performance;
    usort($top_new_businesses, function($a, $b) {
        return $b['current_month_premium'] <=> $a['current_month_premium'];
    });
    $top_new_businesses = array_slice($top_new_businesses, 0, 3);
    
    // En Çok Yeni Müşteri
    $top_new_customers = [];
    foreach ($all_representatives as $rep) {
        $new_customers_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers
             WHERE representative_id = %d 
             AND created_at BETWEEN %s AND %s",
            $rep->id, date('Y-m-01 00:00:00'), date('Y-m-t 23:59:59')
        )) ?: 0;
        
        $customers_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers
             WHERE representative_id = %d",
            $rep->id
        )) ?: 0;
        
        $top_new_customers[] = [
            'id' => $rep->id,
            'name' => $rep->display_name,
            'title' => $rep->title,
            'new_customers' => $new_customers_count,
            'total_customers' => $customers_count
        ];
    }
    
    usort($top_new_customers, function($a, $b) {
        return $b['new_customers'] <=> $a['new_customers'];
    });
    $top_new_customers = array_slice($top_new_customers, 0, 3);
    
    // En Çok İptali Olan
    $top_cancellations = $all_representatives_performance;
    usort($top_cancellations, function($a, $b) {
        return $b['cancelled_policies'] <=> $a['cancelled_policies'];
    });
    $top_cancellations = array_slice($top_cancellations, 0, 3);
}

$search_results = array();
if ($current_view == 'search' && isset($_GET['keyword']) && !empty(trim($_GET['keyword']))) {
    $keyword = sanitize_text_field($_GET['keyword']);
    
    // Safely handle the IN clause
    if (!empty($rep_ids)) {
        $placeholders = implode(',', array_fill(0, count($rep_ids), '%d'));
        $search_query = "
            SELECT c.*, p.policy_number, 
                   CASE 
                       WHEN TRIM(COALESCE(c.company_name, '')) != '' THEN CONCAT(TRIM(c.company_name), CASE WHEN TRIM(COALESCE(c.tax_number, '')) != '' THEN CONCAT(' - VKN: ', TRIM(c.tax_number)) ELSE '' END)
                       ELSE CONCAT(TRIM(c.first_name), ' ', TRIM(c.last_name))
                   END AS customer_name
            FROM {$wpdb->prefix}insurance_crm_customers c
            LEFT JOIN {$wpdb->prefix}insurance_crm_policies p ON c.id = p.customer_id
            WHERE c.representative_id IN ($placeholders)
            AND (
                CONCAT(TRIM(c.first_name), ' ', TRIM(c.last_name)) LIKE %s
                OR TRIM(COALESCE(c.tc_identity, '')) LIKE %s
                OR TRIM(COALESCE(c.spouse_name, '')) LIKE %s
                OR TRIM(COALESCE(c.spouse_tc_identity, '')) LIKE %s
                OR TRIM(COALESCE(c.children_names, '')) LIKE %s
                OR TRIM(COALESCE(c.children_tc_identities, '')) LIKE %s
                OR TRIM(COALESCE(c.company_name, '')) LIKE %s
                OR TRIM(COALESCE(c.tax_number, '')) LIKE %s
                OR TRIM(COALESCE(p.policy_number, '')) LIKE %s
            )
            GROUP BY c.id
            ORDER BY c.first_name ASC
            LIMIT %d
        ";
        
        $search_params = array_merge($rep_ids, [
            '%' . $wpdb->esc_like($keyword) . '%', // customer name
            '%' . $wpdb->esc_like($keyword) . '%', // customer tc
            '%' . $wpdb->esc_like($keyword) . '%', // spouse name  
            '%' . $wpdb->esc_like($keyword) . '%', // spouse tc
            '%' . $wpdb->esc_like($keyword) . '%', // children names
            '%' . $wpdb->esc_like($keyword) . '%', // children tc
            '%' . $wpdb->esc_like($keyword) . '%', // company name
            '%' . $wpdb->esc_like($keyword) . '%', // tax number (VKN)
            '%' . $wpdb->esc_like($keyword) . '%', // policy number
            20
        ]);
        
        $search_results = $wpdb->get_results($wpdb->prepare($search_query, ...$search_params));

        if ($wpdb->last_error) {
            error_log('Arama Sorgusu Hatası: ' . $wpdb->last_error);
        }
    }
}

function generate_panel_url($view, $action = '', $id = '', $additional_params = array()) {
    $base_url = get_permalink();
    $query_args = array();
    
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

// Activity Log tablosunu oluştur (eğer yoksa)
function create_activity_log_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'insurance_crm_activity_logs';
    
    // Tablo var mı kontrol et
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        // Tablo oluştur
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            action_type varchar(50) NOT NULL,
            item_type varchar(50) NOT NULL,
            item_id bigint(20) NOT NULL,
            details text NOT NULL,
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log('Activity log tablosu oluşturuldu.');
    }
}

// Activity Log tablosunu oluştur
create_activity_log_table();

// Yönetim Hiyerarşisini getiren fonksiyon
function get_management_hierarchy() {
    $settings = get_option('insurance_crm_settings', []);
    return isset($settings['management_hierarchy']) ? $settings['management_hierarchy'] : [
        'patron_id' => 0,
        'manager_id' => 0,
        'assistant_manager_ids' => []
    ];
}

// Yönetim hiyerarşisini güncelleme fonksiyonu
function update_management_hierarchy($hierarchy) {
    $settings = get_option('insurance_crm_settings', []);
    $settings['management_hierarchy'] = $hierarchy;
    return update_option('insurance_crm_settings', $settings);
}

add_action('wp_enqueue_scripts', 'insurance_crm_rep_panel_scripts');
function insurance_crm_rep_panel_scripts() {
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', array(), '3.9.1', true);
}
?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

  <!-- Dashboard Enhancement Assets -->
    <link rel="stylesheet" href="<?php echo plugins_url('insurance-crm/assets/css/dashboard-updates.css'); ?>">
    <script src="<?php echo plugins_url('insurance-crm/assets/js/dashboard-widgets.js'); ?>" defer></script>

    <!-- Sayfa Loader CSS ve JS -->
    <link rel="stylesheet" href="<?php echo plugin_dir_url(__FILE__) . 'loader.css'; ?>">
    <script src="<?php echo plugin_dir_url(__FILE__) . 'loader.js'; ?>"></script>

    <?php wp_head(); ?>
</head>

<body class="insurance-crm-page">

<?php 
// Sayfa Loader'ını dahil et
include_once __DIR__ . '/loader.php'; 
?>

    <div class="insurance-crm-sidenav">
        
        <div class="sidenav-user">
            <div class="user-avatar">
 <?php 
    // Avatar görselleri kaldırıldı - sadece kullanıcı adı ilk harfi gösterilecek
    $display_name = $current_user->display_name;
    $initials = '';
    
    // İsim varsa ilk harfleri al
    if (!empty($display_name)) {
        $name_parts = explode(' ', $display_name);
        foreach ($name_parts as $part) {
            if (!empty($part)) {
                $initials .= strtoupper(mb_substr($part, 0, 1, 'UTF-8'));
            }
        }
        // Maksimum 2 harf göster
        $initials = mb_substr($initials, 0, 2, 'UTF-8');
    }
    
    if (empty($initials)) {
        $initials = 'U'; // Varsayılan harf
    }
    ?>
    <div class="user-initials"><?php echo esc_html($initials); ?></div>
            </div>
            <div class="user-info">
                <h4><?php echo esc_html($current_user->display_name); ?></h4>
                <span><?php echo esc_html($representative->title); ?></span>
                <?php if ($user_role == 'patron'): ?>
                    <span class="user-role patron-role">Patron</span>
                <?php elseif ($user_role == 'manager'): ?>
                    <span class="user-role manager-role">Müdür</span>
                <?php elseif ($user_role == 'team_leader'): ?>
                    <span class="user-role leader-role">Ekip Lideri</span>
                <?php endif; ?>
            </div>
        </div>
        
        <nav class="sidenav-menu">
            <a href="<?php echo generate_panel_url('dashboard'); ?>" class="<?php echo $current_view == 'dashboard' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-dashboard"></i>
                <span>Dashboard</span>
            </a>
            <a href="<?php echo generate_panel_url('customers'); ?>" class="<?php echo $current_view == 'customers' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-groups"></i>
                <span>Müşterilerim</span>
            </a>
            <a href="<?php echo generate_panel_url('policies'); ?>" class="<?php echo $current_view == 'policies' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-portfolio"></i>
                <span>Poliçelerim</span>
            </a>
            <a href="<?php echo generate_panel_url('offers'); ?>" class="<?php echo $current_view == 'offers' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-clipboard"></i>
                <span>Tekliflerim</span>
            </a>
            <a href="<?php echo generate_panel_url('tasks'); ?>" class="<?php echo $current_view == 'tasks' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-calendar-alt"></i>
                <span>Görevlerim</span>
            </a>

            <a href="<?php echo generate_panel_url('reports'); ?>" class="<?php echo $current_view == 'reports' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-chart-area"></i>
                <span>Raporlar</span>
            </a>
            
            <?php if (has_full_admin_access($current_user->ID)): ?>
            <!-- Patron ve Müdür İçin Özel Menü -->
            <div class="sidenav-submenu">
                <a href="#" class="dropdown-toggle" data-target="organization-menu" 
                   aria-expanded="false" aria-controls="organization-submenu" role="button">
                    <i class="dashicons dashicons-networking"></i>
                    <span>Organizasyon <br> Yönetimi</span>
                </a>
                <div class="submenu-items" id="organization-submenu" role="menu">
                    <a href="<?php echo generate_panel_url('all_personnel'); ?>" class="<?php echo $current_view == 'all_personnel' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-groups"></i>
                        <span>Tüm Personel</span>
                    </a>
                    <a href="<?php echo generate_panel_url('all_teams'); ?>" class="<?php echo $current_view == 'all_teams' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-admin-users"></i>
                        <span>Tüm Ekipler</span>
                    </a>
                    <a href="<?php echo generate_panel_url('team_add'); ?>" class="<?php echo $current_view == 'team_add' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-groups"></i>
                        <span>Yeni Ekip Oluştur</span>
                    </a>
                    <a href="<?php echo generate_panel_url('representative_add'); ?>" class="<?php echo $current_view == 'representative_add' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-admin-users"></i>
                        <span>Yeni Temsilci Ekle</span>
                    </a>
                    <a href="<?php echo generate_panel_url('boss_settings'); ?>" class="<?php echo $current_view == 'boss_settings' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-admin-generic"></i>
                        <span>Yönetim Ayarları</span>
                    </a>

                    <a href="<?php echo generate_panel_url('patron_dashboard'); ?>" class="<?php echo $current_view == 'patron_dashboard' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-admin-generic"></i>
                        <span>Güvenlik Logları</span>
                    </a>

                </div>
            </div>
            <?php endif; ?>
            
            <?php if (is_team_leader($current_user->ID)): ?>
            <div class="sidenav-submenu">
                <a href="#" class="dropdown-toggle" data-target="team-menu" 
                   aria-expanded="false" aria-controls="team-submenu" role="button">
                    <i class="dashicons dashicons-groups"></i>
                    <span>Ekip Performansı</span>
                </a>
                <div class="submenu-items" id="team-submenu" role="menu">
                    <a href="<?php echo generate_panel_url('team_policies'); ?>" class="<?php echo $current_view == 'team_policies' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-portfolio"></i>
                        <span>Ekip Poliçeleri</span>
                    </a>
                    <a href="<?php echo generate_panel_url('team_customers'); ?>" class="<?php echo $current_view == 'team_customers' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-groups"></i>
                        <span>Ekip Müşterileri</span>
                    </a>
                    <a href="<?php echo generate_panel_url('team_tasks'); ?>" class="<?php echo $current_view == 'team_tasks' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-calendar-alt"></i>
                        <span>Ekip Görevleri</span>
                    </a>
                    <a href="<?php echo generate_panel_url('team_reports'); ?>" class="<?php echo $current_view == 'team_reports' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-chart-area"></i>
                        <span>Ekip Raporları</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <a href="<?php echo generate_panel_url('settings'); ?>" class="<?php echo $current_view == 'settings' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-admin-generic"></i>
                <span>Ayarlar</span>
            </a>

            <!-- Help & Support Submenu -->
            <div class="sidenav-submenu">
                <a href="#" class="dropdown-toggle" data-target="help-menu" 
                   aria-expanded="false" aria-controls="help-submenu" role="button">
                    <i class="dashicons dashicons-editor-help"></i>
                    <span>Yardım & Destek</span>
                </a>
                <div class="submenu-items" id="help-submenu" role="menu">
                    <a href="<?php echo generate_panel_url('helpdesk'); ?>" class="<?php echo $current_view == 'helpdesk' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-sos"></i>
                        <span>Destek Talebi</span>
                    </a>
                    <a href="<?php echo generate_panel_url('veri_aktar'); ?>" class="<?php echo $current_view == 'veri_aktar' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-upload"></i>
                        <span>Veri Aktar</span>
                    </a>
                    <a href="<?php echo generate_panel_url('license-management'); ?>" class="<?php echo $current_view == 'license-management' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-admin-network"></i>
                        <span>Lisanslama</span>
                    </a>
                </div>
            </div>


        </nav>
        



        <div class="sidenav-footer">
            <a href="<?php echo wp_logout_url(home_url('/temsilci-girisi')); ?>" class="logout-button">
                <i class="dashicons dashicons-exit"></i>
                <span>Çıkış Yap</span>
            </a>
        </div>
    </div>

    <div class="insurance-crm-main">
        <header class="main-header">
            <div class="header-left">
                <button id="sidenav-toggle">
                    <i class="dashicons dashicons-menu"></i>
                </button>
                

                 <!-- Modern header with logo and company name -->
                <div class="header-brand">
                    <?php
                    $settings = get_option('insurance_crm_settings', array());
                    $company_name = !empty($settings['company_name']) ? $settings['company_name'] : get_bloginfo('name');
                    $logo_url = !empty($settings['site_appearance']['login_logo']) ? $settings['site_appearance']['login_logo'] : plugins_url('/assets/images/Insurance-logo.png', dirname(__FILE__));
                    ?>
                    <div class="header-logo">
                        <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($company_name); ?> Logo">
                    </div>
                    <div class="header-info">
                        <h1 class="company-name"><?php echo esc_html($company_name); ?></h1>
                        <span class="page-title">
                            <?php 
                            switch($current_view) {
                                case 'customers':
                                    echo 'Müşteriler';
                                    break;
                                case 'policies':
                                    echo 'Poliçeler';
                                    break;
				    case 'offers':
                                    echo 'Teklifler';
                                    break;

                                case 'tasks':
                                    echo 'Görevler';
                                    break;
                                case 'helpdesk':
                                    echo 'Destek Talebi';
                                    break;
                                case 'license-management':
                                    echo 'Lisans Yönetimi';
                                    break;
                                case 'reports':
                                    echo 'Raporlar';
                                    break;
                                case 'settings':
                                    echo 'Ayarlar';
                                    break;
                                case 'search':
                                    echo 'Arama Sonuçları';
                                    break;
                                case 'team':
                                    echo 'Ekip Performansı';
                                    break;
                                case 'team_policies':
                                    echo 'Ekip Poliçeleri';
                                    break;
                                case 'team_customers':
                                    echo 'Ekip Müşterileri';
                                    break;
                                case 'team_tasks':
                                    echo 'Ekip Görevleri';
                                    break;
                                case 'team_reports':
                                    echo 'Ekip Raporları';
                                    break;
                                case 'organization':
                                    echo 'Organizasyon Yönetimi';
                                    break;
                                case 'all_personnel':
                                    echo 'Tüm Personel';
                                    break;
                                case 'all_teams':
                                    echo 'Tüm Ekipler';
                                    break;
                                case 'representative_add':
                                    echo 'Yeni Temsilci Ekle';
                                    break;
                                case 'team_add':
                                    echo 'Yeni Ekip Oluştur';
                                    break;
                                case 'manager_dashboard':
                                    echo 'Müdür Paneli';
                                    break;
                                case 'team_leaders':
                                    echo 'Ekip Liderleri';
                                    break;
                                case 'team_detail':
                                    echo 'Ekip Detayı';
                                    break;
                                case 'representative_detail':
                                    echo 'Temsilci Detayı';
                                    break;
                                case 'edit_representative':
                                    echo 'Temsilci Düzenle';
                                    break;
				    case 'all_teams':
                                    echo 'Tüm Ekipler';
                                    break;
                                case 'edit_team':
                                    echo 'Ekip Düzenle';
                                    break;
                                case 'boss_settings':
                                    echo 'Yönetim Ayarları';
                                    break;
                                case 'patron_dashboard':
                                    echo 'Yönetici Log Görme';
                                    break;
                                case 'veri_aktar':
                                    echo 'Veri Aktar';
                                    break;
                                default:
                                    echo ($user_role == 'patron') ? 'Patron Dashboard' : 
                                        (($user_role == 'manager') ? 'Müdür Dashboard' : 
                                        (($user_role == 'team_leader') ? 'Ekip Lideri Dashboard' : 'Dashboard'));
                            }
                            ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="header-right">
                <div class="search-box">
                    <form action="<?php echo generate_panel_url('search'); ?>" method="get">
                        <i class="dashicons dashicons-search"></i>
                        <input type="text" name="keyword" placeholder="Ad, TC No, Eş Adı, Çocuk Adı, Firma Adı, VKN..." value="<?php echo isset($_GET['keyword']) ? esc_attr($_GET['keyword']) : ''; ?>">
                        <input type="hidden" name="view" value="search">
                        <button type="submit" class="search-button">ARA</button>
                    </form>
                </div>
                
                <div class="notification-bell">
                    <a href="#" id="notifications-toggle" title="Bildirimler">
                        <i class="dashicons dashicons-bell"></i>
                        <?php if ($total_notification_count > 0): ?>
                        <span class="notification-badge"><?php echo $total_notification_count; ?></span>
                        <?php endif; ?>
                    </a>
                    
                    <div class="notifications-dropdown">
                        <div class="notifications-header">
                            <h3><i class="dashicons dashicons-bell"></i> Bildirimler</h3>
                        </div>
                        
                        <div class="notifications-list">
                            <?php if ($notifications_table_exists && $notification_count > 0): ?>
                                <?php 
                                $notifications = $wpdb->get_results($wpdb->prepare(
                                    "SELECT * FROM {$wpdb->prefix}insurance_crm_notifications
                                     WHERE user_id = %d AND is_read = 0
                                     ORDER BY created_at DESC
                                     LIMIT 5",
                                    $current_user->ID
                                ));
                                ?>
                                <?php foreach ($notifications as $notification): ?>
                                    <div class="notification-item unread" data-id="<?php echo esc_attr($notification->id); ?>">
                                        <div class="notification-icon">
                                            <i class="dashicons dashicons-warning"></i>
                                        </div>
                                        <div class="notification-content">
                                            <p><?php echo esc_html($notification->message); ?></p>
                                            <span class="notification-time">
                                                <i class="dashicons dashicons-clock"></i> <?php echo date_i18n('d.m.Y H:i', strtotime($notification->created_at)); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            
                            <?php if (!empty($upcoming_tasks)): ?>
                                <?php foreach ($upcoming_tasks as $task): ?>
                                    <div class="notification-item unread">
                                        <div class="notification-icon">
                                            <i class="dashicons dashicons-calendar-alt"></i>
                                        </div>
                                        <div class="notification-content">
                                            <p>
                                                <strong>Görev:</strong> <?php echo esc_html($task->task_title); ?>
                                            <span class="notification-time">Son Tarih: <?php echo date_i18n('d.m.Y', strtotime($task->due_date)); ?>
                                            </span></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="notification-item">
                                    <div class="notification-icon">
                                        <i class="dashicons dashicons-yes-alt"></i>
                                    </div>
                                    <div class="notification-content">
                                        <p>Yaklaşan görev bulunmuyor.</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="notifications-footer">
                            <a href="<?php echo generate_panel_url('notifications'); ?>"><i class="dashicons dashicons-visibility"></i> Tüm bildirimleri gör</a>
                        </div>
                    </div>
                </div>
                
                <div class="quick-actions">
                    <button class="quick-add-btn" id="quick-add-toggle">
                        <i class="dashicons dashicons-plus-alt"></i>
                        <span>Hızlı Ekle</span>
                    </button>
                    
                    <div class="quick-add-dropdown">
                        <a href="<?php echo generate_panel_url('customers', 'new'); ?>" class="add-customer">
                            <i class="dashicons dashicons-groups"></i>
                            <span>Yeni Müşteri</span>
                        </a>
                        <a href="<?php echo generate_panel_url('policies', 'new'); ?>" class="add-policy">
                            <i class="dashicons dashicons-portfolio"></i>
                            <span>Yeni Poliçe</span>
                        </a>
                        <a href="<?php echo generate_panel_url('tasks', 'new'); ?>" class="add-task">
                            <i class="dashicons dashicons-calendar-alt"></i>
                            <span>Yeni Görev</span>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <?php if ($current_view == 'dashboard' || $current_view == 'team'): ?>
        <div class="main-content">
            <!-- Enhanced Header Section -->
            <div class="dashboard-header-section">
                <div class="dashboard-header-content">
                    <div class="dashboard-title-section">
                        <div class="dashboard-title-icon">
                            <i class="fas fa-tachometer-alt"></i>
                        </div>
                        <div class="dashboard-title-content">
                            <h1>
                                Sigorta CRM Dashboard
                                <?php 
                                // Get version from main insurance-crm.php file
                                $main_file_path = dirname(dirname(dirname(__FILE__))) . '/insurance-crm.php';
                                $version = '1.0.0'; // Default fallback
                                
                                if (file_exists($main_file_path)) {
                                    $file_content = file_get_contents($main_file_path);
                                    if (preg_match('/Plugin Version:\s*([0-9.]+)/', $file_content, $matches)) {
                                        $version = $matches[1];
                                    } elseif (preg_match('/Page Version:\s*([0-9.]+)/', $file_content, $matches)) {
                                        $version = $matches[1];
                                    }
                                }
                                ?>
                                <span class="dashboard-version-badge">v<?php echo esc_html($version); ?></span>
                            </h1>
                            <p class="dashboard-subtitle">
                                <?php 
                                $user_role = get_user_role_in_hierarchy($current_user->ID);
                                echo ($user_role == 'patron') ? 'Genel Yönetim ve Organizasyon Kontrolü' : 
                                    (($user_role == 'manager') ? 'Departman Yönetimi ve Performans Takibi' : 
                                    (($user_role == 'team_leader') ? 'Ekip Performansı ve Hedef Takibi' : 'Kişisel Performans ve Görev Yönetimi'));
                                ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="dashboard-header-actions">
                        <div class="dashboard-user-role">
                            <i class="fas fa-user-shield"></i>
                            <span><?php 
                                $user_role = get_user_role_in_hierarchy($current_user->ID);
                                $role_names = [
                                    'patron' => 'Patron',
                                    'manager' => 'Müdür', 
                                    'assistant_manager' => 'Müdür Yardımcısı',
                                    'team_leader' => 'Ekip Lideri',
                                    'representative' => 'Müşteri Temsilcisi'
                                ];
                                echo esc_html($role_names[$user_role] ?? 'Bilinmiyor');
                            ?></span>
                        </div>
                        
                        <div class="dashboard-action-buttons">
                            <?php if (has_full_admin_access($current_user->ID)): ?>
                                <a href="<?php echo generate_panel_url('organization'); ?>" class="dashboard-action-btn">
                                    <i class="fas fa-sitemap"></i>
                                    <span>Organizasyon</span>
                                </a>
                                <a href="<?php echo generate_panel_url('reports'); ?>" class="dashboard-action-btn">
                                    <i class="fas fa-chart-bar"></i>
                                    <span>Raporlar</span>
                                </a>
                                <a href="<?php echo generate_panel_url('policies', '', '', ['expiring_soon' => '1']); ?>" class="dashboard-action-btn renewal-policies-btn">
                                    <i class="fas fa-clock"></i>
                                    <span>Yenilemesi Gelen Poliçeler</span>
                                </a>
                            <?php elseif (is_team_leader($current_user->ID)): ?>
                                <?php
                                $team_leader_team_id_for_button = null;
                                // $representative değişkeninin bu noktada tanımlı ve dolu olduğunu varsayıyoruz.
                                // Dosyanın başında $representative = $wpdb->get_row(...) ile çekiliyor.
                                if (is_team_leader($current_user->ID) && isset($representative->id)) {
                                    $crm_settings = get_option('insurance_crm_settings', []);
                                    $all_teams_data = $crm_settings['teams_settings']['teams'] ?? [];
                                    if (!empty($all_teams_data)) {
                                        foreach ($all_teams_data as $id_of_team => $team_details) {
                                            if (isset($team_details['leader_id']) && $team_details['leader_id'] == $representative->id) {
                                                $team_leader_team_id_for_button = $id_of_team;
                                                break; 
                                            }
                                        }
                                    }
                                }
                                ?>
                                <a href="<?php echo generate_panel_url('team_detail', '', '', ['team_id' => $team_leader_team_id_for_button]); ?>" class="dashboard-action-btn">
                                    <i class="fas fa-chart-line"></i>
                                    <span>Ekip Performansı</span>
                                </a>
                                <a href="<?php echo generate_panel_url('team_detail', '', '', ['team_id' => $team_leader_team_id_for_button]); ?>" class="dashboard-action-btn">
                                    <i class="fas fa-users"></i>
                                    <span>Ekibim</span>
                                </a>
                                <a href="<?php echo generate_panel_url('tasks'); ?>" class="dashboard-action-btn">
                                    <i class="fas fa-tasks"></i>
                                    <span>Görevler</span>
                                </a>
                            <?php else: ?>
                                <a href="<?php echo generate_panel_url('customers'); ?>" class="dashboard-action-btn">
                                    <i class="fas fa-users"></i>
                                    <span>Müşteriler</span>
                                </a>
                                <a href="<?php echo generate_panel_url('policies'); ?>" class="dashboard-action-btn">
                                    <i class="fas fa-file-contract"></i>
                                    <span>Poliçeler</span>
                                </a>

                                <a href="<?php echo generate_panel_url('offers'); ?>" class="dashboard-action-btn">
                                    <i class="fas fa-file-contract"></i>
                                    <span>Teklifler</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Lisans Uyarıları -->
            <?php 
            global $insurance_crm_license_manager;
            if ($insurance_crm_license_manager) {
                $license_notices = $insurance_crm_license_manager->get_frontend_license_notices();
                if (!empty($license_notices)) {
                    foreach ($license_notices as $notice) {
                        $notice_class = 'license-warning';
                        if ($notice['type'] === 'error') {
                            $notice_class = 'license-error';
                        }
                        echo '<div class="' . $notice_class . '">';
                        echo '<i class="fas fa-exclamation-triangle"></i>';
                        echo '<div class="license-notice-content">';
                        echo $notice['message'];
                        echo '</div>';
                        echo '</div>';
                    }
                }
            }
            ?>

            <?php if (has_full_admin_access($current_user->ID)): ?>
            
            
            <div class="stats-grid">
                <div class="stat-box customers-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-groups"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value"><?php echo number_format($total_customers); ?></div>
                        <div class="stat-label">Toplam Müşteri</div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            <?php echo $filter_title; ?> eklenen: +<?php echo $new_customers; ?> Müşteri
                        </div>
                        <div class="stat-rate positive">
                            <i class="dashicons dashicons-arrow-up-alt"></i>
                            <span><?php echo number_format($customer_increase_rate, 2); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box policies-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-portfolio"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value"><?php echo number_format($total_policies); ?></div>
                        <div class="stat-label">Toplam Poliçe (<?php echo date('Y'); ?> Yılı)</div>
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            <?php echo $filter_title; ?> eklenen: +<?php echo $new_policies; ?> Poliçe
                        </div>
                        <div class="stat-new refund-info">
                            <?php echo $filter_title; ?> iptal edilen: <?php echo $this_period_cancelled_policies; ?> Poliçe
                        </div>
                        <div class="stat-rate positive">
                            <i class="dashicons dashicons-arrow-up-alt"></i>
                            <span><?php echo number_format($policy_increase_rate, 2); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box production-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-chart-bar"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value">₺<?php echo number_format($total_premium, 2, ',', '.'); ?></div>
                        <div class="stat-label">Toplam Üretim (<?php echo date('Y'); ?> Yılı)</div>
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            <?php echo $filter_title; ?> eklenen: +₺<?php echo number_format($new_premium, 2, ',', '.'); ?>
                        </div>
                        <div class="stat-new refund-info">
                            <?php echo $filter_title; ?> iptal edilen: ₺<?php echo number_format($period_refunded_amount, 2, ',', '.'); ?>
                        </div>
                        <div class="stat-rate positive">
                            <i class="dashicons dashicons-arrow-up-alt"></i>
                            <span><?php echo number_format($premium_increase_rate, 2); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box target-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-performance"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value">₺<?php echo number_format($current_month_premium, 2, ',', '.'); ?></div>
                        <div class="stat-label">Bu Ay Üretim</div>
                    </div>
                    <div class="stat-target">
                        <div class="target-text">Toplam Hedef: ₺<?php echo number_format($monthly_target, 2, ',', '.'); ?></div>
                        <?php
                        $remaining_amount = max(0, $monthly_target - $current_month_premium);
                        ?>
                        <div class="target-text">Hedefe Kalan: ₺<?php echo number_format($remaining_amount, 2, ',', '.'); ?></div>
                        <div class="target-progress-mini">
                            <div class="target-bar" style="width: <?php echo $achievement_rate; ?>%"></div>
                        </div>
                    </div>
                </div>
                
                <?php 
                // Get today's birthday customers for the stat box based on user role
                require_once(INSURANCE_CRM_PATH . 'includes/dashboard-functions.php');
                $birthday_stat_data = get_todays_birthday_customers_by_role($current_user->ID);
                $user_role = get_user_role_in_hierarchy($current_user->ID);
                $rep = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
                    $current_user->ID
                ));
                ?>
                <div class="stat-box birthday-stat-box">
                    <div class="stat-icon">
                        <i class="fas fa-birthday-cake" style="color: #ff6b6b;"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value"><?php echo $birthday_stat_data['total']; ?></div>
                        <div class="stat-label">Bugün Doğum Günü Olan Müşteriler</div>
                    </div>
                    <div class="stat-change">
                        <!-- Only show count, no customer details in top panel -->
                    </div>
                </div>
            </div>



            <?php if (has_full_admin_access($current_user->ID)): ?>
            <!-- PATRON VE MÜDÜR DASHBOARD İÇERİĞİ -->
            
            <!-- Monthly Performance Chart and Organization Management - Before Team Performance Table -->
            <div class="dashboard-grid">
                <div class="upper-section">
                    <div class="dashboard-card chart-card" style="width: 65%;">
                        <div class="card-header">
                            <h3>Ekip Aylık Üretim Performansı</h3>
                            <div class="card-actions">
                                <div class="view-toggle-buttons">
                                    <button id="monthly-table-view" class="toggle-btn active" onclick="toggleMonthlyView('table')">
                                        <i class="fas fa-table"></i> Tablo
                                    </button>
                                    <button id="monthly-card-view" class="toggle-btn" onclick="toggleMonthlyView('cards')">
                                        <i class="fas fa-th-large"></i> Kartlar
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="chart-container mb-4">
                                <canvas id="productionChart"></canvas>
                            </div>
                            
                            <!-- Table View -->
                            <div id="monthly-table-container" class="production-table" style="margin-top: 10px;">
                                <div class="overflow-x-auto">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Ay-Yıl</th>
                                                <th>Hedef (₺)</th>
                                                <th>Üretilen (₺)</th>
                                                <th>İade Edilen (₺)</th>
                                                <th>Gerçekleşme Oranı (%)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($monthly_production_data as $month_year => $total): ?>
                                                <?php 
                                                $dateParts = explode('-', $month_year);
                                                $year = $dateParts[0];
                                                $month = (int)$dateParts[1];
                                                $months = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 
                                                           'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
                                                $month_name = $months[$month - 1] . ' ' . $year;
                                                $achievement_rate = $monthly_target > 0 ? ($total / $monthly_target) * 100 : 0;
                                                $achievement_rate = min(100, $achievement_rate);
                                                $refunded_amount = $monthly_refunded_data[$month_year];
                                                ?>
                                                <tr>
                                                    <td><?php echo esc_html($month_name); ?></td>
                                                    <td>₺<?php echo number_format($monthly_target, 2, ',', '.'); ?></td>
                                                    <td class="amount-cell">₺<?php echo number_format($total, 2, ',', '.'); ?></td>
                                                    <td class="refund-info">₺<?php echo number_format($refunded_amount, 2, ',', '.'); ?></td>
                                                    <td><?php echo number_format($achievement_rate, 2, ',', '.'); ?>%</td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Card View -->
                            <div id="monthly-cards-container" class="hidden">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <?php foreach ($monthly_production_data as $month_year => $total): ?>
                                        <?php 
                                        $dateParts = explode('-', $month_year);
                                        $year = $dateParts[0];
                                        $month = (int)$dateParts[1];
                                        $months = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 
                                                   'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
                                        $month_name = $months[$month - 1] . ' ' . $year;
                                        $achievement_rate = $monthly_target > 0 ? ($total / $monthly_target) * 100 : 0;
                                        $achievement_rate = min(100, $achievement_rate);
                                        $refunded_amount = $monthly_refunded_data[$month_year];
                                        ?>
                                        <div class="bg-white rounded-lg shadow border border-gray-200 p-4 hover:shadow-md transition-shadow">
                                            <div class="flex items-center justify-between mb-3">
                                                <h4 class="font-semibold text-gray-800"><?php echo esc_html($month_name); ?></h4>
                                                <span class="text-xs px-2 py-1 rounded-full font-medium <?php echo $achievement_rate >= 100 ? 'bg-blue-100 text-blue-800' : 'bg-orange-100 text-orange-800'; ?>">
                                                    <?php echo number_format($achievement_rate, 1); ?>%
                                                </span>
                                            </div>
                                            
                                            <div class="space-y-2">
                                                <div class="flex justify-between text-sm">
                                                    <span class="text-gray-600">Hedef:</span>
                                                    <span class="font-medium">₺<?php echo number_format($monthly_target, 0, ',', '.'); ?></span>
                                                </div>
                                                <div class="flex justify-between text-sm">
                                                    <span class="text-gray-600">Üretilen:</span>
                                                    <span class="font-medium text-green-600">₺<?php echo number_format($total, 0, ',', '.'); ?></span>
                                                </div>
                                                <div class="flex justify-between text-sm">
                                                    <span class="text-gray-600">İade:</span>
                                                    <span class="font-medium text-red-600">₺<?php echo number_format($refunded_amount, 0, ',', '.'); ?></span>
                                                </div>
                                            </div>
                                            
                                            <div class="mt-3">
                                                <div class="flex justify-between text-xs text-gray-500 mb-1">
                                                    <span>Gerçekleşme</span>
                                                    <span><?php echo number_format($achievement_rate, 1); ?>%</span>
                                                </div>
                                                <div class="w-full bg-gray-200 rounded-full h-2">
                                                    <div class="bg-gradient-to-r from-blue-400 to-blue-500 h-2 rounded-full transition-all duration-500" 
                                                         style="width: <?php echo min(100, $achievement_rate); ?>%"></div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="dashboard-card organization-management-widget-card" style="width: 35%;">
                        <div class="management-dropdown-container" style="padding: 20px;">
                            <h3 style="margin-bottom: 20px; color: #1f2937; font-size: 20px; font-weight: 600;">
                                <i class="fas fa-sitemap" style="margin-right: 10px; color: #3b82f6;"></i>
                                Organizasyon Yönetimi
                            </h3>
                            <div class="dropdown-menu-grid">
                                <?php 
                                $org_menu_items = get_organization_management_menu_items($current_user->ID);
                                if (!empty($org_menu_items)):
                                    foreach ($org_menu_items as $item): ?>
                                <a href="<?php echo esc_url($item['url']); ?>" 
                                   class="dropdown-menu-box bg-white rounded-lg shadow-sm border border-gray-200 hover:shadow-md hover:border-blue-300 transition-all duration-200 p-4 block mb-3">
                                    <div class="dropdown-box-header flex items-center space-x-3">
                                        <div class="dropdown-box-icon <?php echo esc_attr($item['class']); ?> flex-shrink-0 w-10 h-10 rounded-lg flex items-center justify-center">
                                            <?php 
                                            $icon_to_display = $item['icon'] ?? '';
                                            if (empty($icon_to_display) && (strpos($item['url'], 'view=all_teams') !== false || $item['title'] == 'Tüm Ekipler')) {
                                                $icon_to_display = 'dashicons-admin-users';
                                            }
                                            if (!empty($icon_to_display)):
                                            ?>
                                            <i class="dashicons <?php echo esc_attr($icon_to_display); ?> text-white text-lg"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <h4 class="dropdown-box-title text-gray-800 font-semibold text-sm leading-tight"><?php echo esc_html($item['title']); ?></h4>
                                            <p class="dropdown-box-description text-gray-600 text-xs mt-1 leading-tight"><?php echo esc_html($item['description']); ?></p>
                                        </div>
                                        <div class="flex-shrink-0">
                                            <i class="fas fa-chevron-right text-gray-400 text-sm"></i>
                                        </div>
                                    </div>
                                </a>
                                <?php endforeach; 
                                else: ?>
                                <div class="text-center text-gray-500 py-8">
                                    <i class="fas fa-info-circle text-2xl mb-2"></i>
                                    <p>Organizasyon yönetimi menü elemanı bulunamadı.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>




            <style>
            .birthday-panel {
                border-radius: 15px;
                overflow: hidden;
                box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
                border: none;
                background: white;
            }
            
            .birthday-panel .card-header {
                background: linear-gradient(135deg, <?php echo $primary_color; ?> 0%, <?php echo $sidebar_color; ?> 100%);
                color: white;
                border-radius: 0;
                padding: 25px;
                border: none;
            }
            
            .birthday-header-content {
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 15px;
            }
            
            .birthday-title {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .birthday-panel .card-header h3 {
                margin: 0;
                font-size: 20px;
                font-weight: 600;
                text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }
            
            .birthday-summary {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
            }
            
            .birthday-count {
                background: rgba(255, 255, 255, 0.2);
                padding: 8px 15px;
                border-radius: 20px;
                font-size: 14px;
                font-weight: 600;
                backdrop-filter: blur(10px);
            }
            
            .birthday-page-info {
                font-size: 12px;
                opacity: 0.9;
            }
            
            .birthday-toggle-btn {
                background: rgba(255, 255, 255, 0.2);
                color: white;
                border: none;
                padding: 8px 15px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                backdrop-filter: blur(10px);
            }
            
            .birthday-toggle-btn:hover {
                background: rgba(255, 255, 255, 0.3);
                transform: translateY(-1px);
            }
            
            .birthday-customers-list {
                padding: 0;
                margin: 0;
            }
            
            .birthday-customer-item {
                display: flex;
                align-items: center;
                padding: 20px;
                border-bottom: 1px solid #f0f0f0;
                transition: all 0.3s ease;
                gap: 15px;
            }
            
            .birthday-customer-item:last-child {
                border-bottom: none;
            }
            
            .birthday-customer-item:hover {
                background: linear-gradient(135deg, #fff8f0 0%, #fff0f8 100%);
                transform: translateX(5px);
            }
            
            .birthday-customer-avatar {
                flex-shrink: 0;
            }
            
            .birthday-avatar-circle {
                width: 50px;
                height: 50px;
                border-radius: 50%;
                background: linear-gradient(135deg, <?php echo $primary_color; ?>, <?php echo $sidebar_color; ?>);
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-size: 20px;
                box-shadow: 0 4px 15px rgba(255, 107, 107, 0.3);
            }
            
            .customer-info {
                flex: 1;
                min-width: 0;
            }
            
            .customer-name {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 8px;
                flex-wrap: wrap;
            }
            
            .customer-name strong {
                font-size: 16px;
                color: #2c3e50;
                font-weight: 600;
            }
            
            .customer-age {
                background: linear-gradient(135deg, #667eea, #764ba2);
                color: white;
                padding: 3px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 500;
            }
            
            .customer-details {
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
            
            .customer-details span {
                font-size: 13px;
                color: #6c757d;
                display: flex;
                align-items: center;
                gap: 5px;
            }
            
            .birthday-actions {
                flex-shrink: 0;
            }
            
            .birthday-celebrate-btn {
                background: linear-gradient(135deg, #11998e, #38ef7d);
                color: white;
                border: none;
                padding: 12px 20px;
                border-radius: 25px;
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                display: flex;
                align-items: center;
                gap: 8px;
                box-shadow: 0 4px 15px rgba(17, 153, 142, 0.3);
            }
            
            .birthday-celebrate-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(17, 153, 142, 0.4);
            }
            
            .birthday-celebrate-btn:active {
                transform: translateY(0);
            }
            
            .birthday-celebrate-btn:disabled {
                background: #ccc;
                cursor: not-allowed;
                transform: none;
                box-shadow: none;
            }
            
            .no-email-notice {
                background: #fff3cd;
                color: #856404;
                padding: 8px 15px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 500;
                display: flex;
                align-items: center;
                gap: 5px;
            }
            
            .birthday-pagination {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                padding: 20px;
                border-top: 1px solid #f0f0f0;
                margin-top: 10px;
            }
            
            .birthday-page-btn {
                background: linear-gradient(135deg, #667eea, #764ba2);
                color: white;
                border: none;
                padding: 8px 15px;
                border-radius: 20px;
                text-decoration: none;
                font-size: 12px;
                font-weight: 500;
                transition: all 0.3s ease;
                display: flex;
                align-items: center;
                gap: 5px;
            }
            
            .birthday-page-btn:hover {
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
                color: white;
                text-decoration: none;
            }
            
            .birthday-page-numbers {
                display: flex;
                gap: 5px;
            }
            
            .birthday-page-number {
                width: 30px;
                height: 30px;
                border-radius: 50%;
                background: #f8f9fa;
                color: #6c757d;
                text-decoration: none;
                font-size: 12px;
                font-weight: 500;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all 0.3s ease;
            }
            
            .birthday-page-number:hover,
            .birthday-page-number.active {
                background: linear-gradient(135deg, <?php echo $primary_color; ?>, <?php echo $sidebar_color; ?>);
                color: white;
                text-decoration: none;
                transform: scale(1.1);
            }
            
            @media (max-width: 768px) {
                .birthday-header-content {
                    flex-direction: column;
                    align-items: flex-start;
                }
                
                .birthday-customer-item {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 15px;
                    padding: 15px;
                }
                
                .birthday-customer-item:hover {
                    transform: none;
                }
                
                .customer-name {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 5px;
                }
                
                .birthday-actions {
                    width: 100%;
                }
                
                .birthday-celebrate-btn {
                    width: 100%;
                    justify-content: center;
                }
                
                .birthday-pagination {
                    flex-wrap: wrap;
                    gap: 5px;
                }
            }
            
            .birthday-empty-state {
                text-align: center;
                padding: 60px 20px;
                color: #6c757d;
            }
            
            .birthday-empty-state .empty-state-icon {
                font-size: 64px;
                color: #dee2e6;
                margin-bottom: 20px;
            }
            
            .birthday-empty-state .empty-state-icon i {
                background: linear-gradient(135deg, <?php echo $primary_color; ?>, <?php echo $sidebar_color; ?>);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                background-clip: text;
            }
            
            .birthday-empty-state h4 {
                font-size: 20px;
                margin-bottom: 10px;
                color: #495057;
            }
            
            .birthday-empty-state p {
                margin-bottom: 25px;
                line-height: 1.6;
            }
            
            .birthday-empty-state .btn {
                background: linear-gradient(135deg, #667eea, #764ba2);
                border: none;
                padding: 12px 25px;
                border-radius: 25px;
                color: white;
                text-decoration: none;
                font-weight: 600;
                transition: all 0.3s ease;
                display: inline-flex;
                align-items: center;
                gap: 8px;
            }
            
            .birthday-empty-state .btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
                color: white;
                text-decoration: none;
            }

            /* Birthday Cards Grid Layout */
            .birthday-cards-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 20px;
                padding: 20px;
            }
            
            .birthday-customer-card {
                background: white;
                border-radius: 15px;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
                overflow: hidden;
                transition: all 0.3s ease;
                border: 2px solid transparent;
                position: relative;
            }
            
            .birthday-customer-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
                border-color: #ff6b6b;
            }
            
            .birthday-customer-card::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 4px;
                background: linear-gradient(135deg, <?php echo $primary_color; ?> 0%, <?php echo $sidebar_color; ?> 100%);
            }
            
            .birthday-card-header {
                padding: 20px 20px 15px;
                display: flex;
                align-items: center;
                gap: 15px;
                border-bottom: 1px solid #f0f0f0;
            }
            
            .birthday-card-header .birthday-customer-avatar {
                flex-shrink: 0;
            }
            
            .birthday-card-header .birthday-avatar-circle {
                width: 45px;
                height: 45px;
                border-radius: 50%;
                background: linear-gradient(135deg, <?php echo $primary_color; ?>, <?php echo $sidebar_color; ?>);
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-size: 18px;
                box-shadow: 0 3px 10px rgba(255, 107, 107, 0.3);
            }
            
            .birthday-card-title h4 {
                margin: 0 0 5px;
                font-size: 16px;
                font-weight: 600;
                color: #333;
            }
            
            .customer-representative {
                font-size: 12px;
                color: #666;
                font-weight: 400;
            }
            
            .birthday-card-body {
                padding: 15px 20px;
            }
            
            .birthday-card-info {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            
            .info-item {
                display: flex;
                align-items: center;
                gap: 10px;
                font-size: 14px;
                color: #555;
            }
            
            .info-item i {
                width: 16px;
                color: #007cba;
                flex-shrink: 0;
            }
            
            .birthday-card-footer {
                padding: 15px 20px 20px;
            }
            
            .birthday-celebrate-btn {
                width: 100%;
                background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
                color: white;
                border: none;
                padding: 12px 20px;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
            }
            
            .birthday-celebrate-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
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
            
            .birthday-view-only {
                width: 100%;
                background: #f8f9fa;
                color: #6c757d;
                border: 1px solid #dee2e6;
                padding: 12px 20px;
                border-radius: 8px;
                font-weight: 500;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                text-align: center;
            }
            
            .no-email-notice {
                width: 100%;
                background: #fff3cd;
                color: #856404;
                border: 1px solid #ffeaa7;
                padding: 12px 20px;
                border-radius: 8px;
                font-weight: 500;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                text-align: center;
                font-size: 14px;
            }
            
            .birthday-mini-view-only {
                background: #f8f9fa;
                color: #6c757d;
                border: 1px solid #dee2e6;
                padding: 4px 8px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 500;
                white-space: nowrap;
            }
            
            .customer-rep {
                font-size: 12px;
                color: #666;
                font-weight: 400;
                margin-left: 8px;
            }
            
            /* Mobile responsive for birthday cards */
            @media (max-width: 768px) {
                .birthday-cards-grid {
                    grid-template-columns: 1fr;
                    gap: 15px;
                    padding: 15px;
                }
                
                .birthday-customer-card {
                    border-radius: 10px;
                }
                
                .birthday-card-header {
                    padding: 15px 15px 10px;
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 10px;
                }
                
                .birthday-card-header .birthday-avatar-circle {
                    width: 40px;
                    height: 40px;
                    font-size: 16px;
                }
                
                .birthday-card-title h4 {
                    font-size: 15px;
                }
                
                .birthday-card-body {
                    padding: 10px 15px;
                }
                
                .birthday-card-footer {
                    padding: 10px 15px 15px;
                }
                
                .info-item {
                    font-size: 13px;
                }
            }

            .birthday-panel .card-header h3 {
                margin: 0;
                font-size: 18px;
                font-weight: 600;
            }

            .birthday-count {
                background: rgba(255, 255, 255, 0.2);
                padding: 5px 12px;
                border-radius: 15px;
                font-size: 14px;
                font-weight: 500;
            }

            .birthday-customers-list {
                display: flex;
                flex-direction: column;
                gap: 15px;
            }

            .birthday-customer-item {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 15px;
                background: #f8f9fa;
                border-radius: 8px;
                border-left: 4px solid #ff6b6b;
                transition: all 0.3s ease;
            }

            .birthday-customer-item:hover {
                background: #e9ecef;
                transform: translateX(3px);
            }

            .customer-info {
                flex: 1;
            }

            .customer-name {
                font-size: 16px;
                font-weight: 600;
                color: #212529;
                margin-bottom: 5px;
            }

            .customer-details {
                display: flex;
                flex-direction: column;
                gap: 3px;
                font-size: 14px;
                color: #6c757d;
            }

            .birthday-celebrate-btn {
                background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
                color: white;
                border: none;
                padding: 10px 20px;
                border-radius: 25px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                box-shadow: 0 2px 10px rgba(40, 167, 69, 0.3);
            }

            .birthday-celebrate-btn:hover {
                transform: translateY(-2px);
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
                font-size: 12px;
                font-style: italic;
                padding: 8px 12px;
                background: #f8d7da;
                border-radius: 15px;
            }

            @media (max-width: 768px) {
                .birthday-customer-item {
                    flex-direction: column;
                    align-items: stretch;
                    gap: 10px;
                }

                .birthday-actions {
                    align-self: flex-end;
                }

                .customer-details {
                    flex-direction: column;
                    gap: 5px;
                }
            }

            .birthday-empty-state {
                text-align: center;
                padding: 40px 20px;
                color: #6c757d;
            }

            .birthday-empty-state .empty-state-icon {
                width: 80px;
                height: 80px;
                border-radius: 50%;
                background: linear-gradient(135deg, <?php echo $primary_color; ?>, <?php echo $sidebar_color; ?>);
                color: white;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 20px;
                font-size: 32px;
            }

            .birthday-empty-state h4 {
                color: #495057;
                margin-bottom: 10px;
                font-size: 18px;
            }

            .birthday-empty-state p {
                margin-bottom: 20px;
                line-height: 1.5;
            }

            .birthday-empty-state .btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 10px 20px;
                background: #007cba;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                transition: background 0.3s ease;
            }

            .birthday-empty-state .btn:hover {
                background: #005a87;
                color: white;
                text-decoration: none;
            }
            </style>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Monthly Production Chart for Admin Users
                const productionCtx = document.getElementById('productionChart');
                if (productionCtx) {
                    new Chart(productionCtx, {
                        type: 'bar',
                        data: {
                            labels: [<?php foreach ($monthly_production_data as $month_year => $data): ?>'<?php echo esc_js($month_year); ?>',<?php endforeach; ?>],
                            datasets: [{
                                label: 'Üretim (₺)',
                                data: [<?php foreach ($monthly_production_data as $data): ?><?php echo $data; ?>,<?php endforeach; ?>],
                                type: 'bar',
                                backgroundColor: function(context) {
                                    const gradient = context.chart.ctx.createLinearGradient(0, 0, 0, 400);
                                    gradient.addColorStop(0, 'rgba(16, 185, 129, 0.8)');
                                    gradient.addColorStop(1, 'rgba(16, 185, 129, 0.2)');
                                    return gradient;
                                },
                                borderColor: '#10b981',
                                borderWidth: 2,
                                borderRadius: 8,
                                borderSkipped: false,
                            }, {
                                label: 'Hedef (₺)',
                                data: [<?php foreach ($monthly_production_data as $month_year => $data): 
                                    $monthly_target_for_chart = ($current_view === 'team' || has_full_admin_access($current_user->ID)) ? $team_target : $representative->monthly_target;
                                    echo $monthly_target_for_chart;
                                ?>,<?php endforeach; ?>],
                                type: 'line',
                                borderColor: '#f59e0b',
                                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                                borderWidth: 3,
                                borderDash: [10, 5],
                                tension: 0.4,
                                fill: false,
                                pointBackgroundColor: '#f59e0b',
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                pointRadius: 6,
                                pointHoverRadius: 8
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                intersect: false,
                                mode: 'index'
                            },
                            animation: {
                                duration: 2000,
                                easing: 'easeOutQuart'
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    grid: {
                                        color: 'rgba(0, 0, 0, 0.1)',
                                        lineWidth: 1
                                    },
                                    ticks: {
                                        callback: function(value) {
                                            return '₺' + value.toLocaleString('tr-TR');
                                        },
                                        color: '#6b7280',
                                        font: {
                                            size: 12
                                        }
                                    }
                                },
                                x: {
                                    grid: {
                                        display: false
                                    },
                                    ticks: {
                                        color: '#6b7280',
                                        font: {
                                            size: 12
                                        }
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'top',
                                    align: 'center',
                                    labels: {
                                        usePointStyle: true,
                                        padding: 20,
                                        font: {
                                            size: 14,
                                            weight: '600'
                                        }
                                    }
                                },
                                tooltip: {
                                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                    titleColor: '#fff',
                                    bodyColor: '#fff',
                                    borderColor: '#e5e7eb',
                                    borderWidth: 1,
                                    cornerRadius: 8,
                                    displayColors: false,
                                    callbacks: {
                                        label: function(context) {
                                            return context.dataset.label + ': ₺' + context.parsed.y.toLocaleString('tr-TR');
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            });
            </script>

            <!-- PATRON/MÜDÜR - Yenilemesi Gelen Poliçeler -->
            <?php if (has_full_admin_access($current_user->ID)): ?>
            <?php
            // Get upcoming renewal policies for Patron/Manager (next 30 days)
            $renewal_policies = $wpdb->get_results($wpdb->prepare(
                "SELECT p.id, p.policy_number, p.policy_type, p.end_date, p.premium_amount, p.insurance_company,
                        c.first_name, c.last_name, c.phone,
                        r.title as rep_title, u.display_name as rep_name,
                        DATEDIFF(p.end_date, CURDATE()) as days_to_expiry
                 FROM {$wpdb->prefix}insurance_crm_policies p
                 LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
                 LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON p.representative_id = r.id
                 LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
                 WHERE p.status = 'aktif' 
                 AND p.cancellation_date IS NULL
                 AND p.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                 ORDER BY p.end_date ASC
                 LIMIT 12"
            ));
            ?>
            <div class="dashboard-card renewal-policies-card">
                <div class="card-header">
                    <h3><i class="fas fa-clock"></i> Yenilemesi Gelen Poliçeler (30 Gün)</h3>
                    <div class="card-actions">
                        <div class="view-toggle-buttons">
                            <button type="button" class="toggle-btn active" data-view="cards" title="Kart Görünümü">
                                <i class="fas fa-th-large"></i>
                            </button>
                            <button type="button" class="toggle-btn" data-view="table" title="Tablo Görünümü">
                                <i class="fas fa-table"></i>
                            </button>
                        </div>
                        <a href="<?php echo generate_panel_url('policies', '', '', ['expiring_soon' => '1']); ?>" class="text-button">Tümünü Gör</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($renewal_policies)): ?>
                    <div class="renewal-policies-grid" id="renewal-cards-view">
                        <?php foreach ($renewal_policies as $policy): ?>
                        <div class="renewal-policy-item <?php echo $policy->days_to_expiry <= 7 ? 'urgent' : ($policy->days_to_expiry <= 15 ? 'warning' : 'normal'); ?>">
                            <div class="renewal-policy-header">
                                <div class="policy-info">
                                    <h4><a href="#" class="policy-preview-link" data-policy-id="<?php echo $policy->id; ?>"><?php echo esc_html($policy->policy_number); ?></a></h4>
                                    <span class="policy-type"><?php echo esc_html($policy->policy_type); ?></span>
                                </div>
                                <div class="days-remaining">
                                    <?php if ($policy->days_to_expiry <= 0): ?>
                                        <span class="expired">Süresi Doldu</span>
                                    <?php else: ?>
                                        <span class="days"><?php echo $policy->days_to_expiry; ?> Gün</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="renewal-policy-details">
                                <div class="customer-info">
                                    <i class="fas fa-user"></i>
                                    <span><?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?></span>
                                </div>
                                <div class="company-info">
                                    <i class="fas fa-building"></i>
                                    <span><?php echo esc_html($policy->insurance_company); ?></span>
                                </div>
                                <div class="expiry-info">
                                    <i class="fas fa-calendar"></i>
                                    <span><?php echo date('d.m.Y', strtotime($policy->end_date)); ?></span>
                                </div>
                                <div class="premium-info">
                                    <i class="fas fa-lira-sign"></i>
                                    <span><?php echo number_format($policy->premium_amount, 2, ',', '.'); ?> TL</span>
                                </div>
                                <div class="rep-info">
                                    <i class="fas fa-user-tie"></i>
                                    <span><?php echo esc_html($policy->rep_name ?: 'Atanmamış'); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Table View -->
                    <div class="renewal-policies-table" id="renewal-table-view" style="display: none;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Poliçe No</th>
                                    <th>Müşteri</th>
                                    <th>Poliçe Türü</th>
                                    <th>Şirket</th>
                                    <th>Bitiş Tarihi</th>
                                    <th>Prim Tutarı</th>
                                    <th>Temsilci</th>
                                    <th>Kalan Gün</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($renewal_policies as $policy): ?>
                                <tr class="<?php echo $policy->days_to_expiry <= 7 ? 'urgent-row' : ($policy->days_to_expiry <= 15 ? 'warning-row' : ''); ?>">
                                    <td><a href="#" class="policy-preview-link" data-policy-id="<?php echo $policy->id; ?>"><?php echo esc_html($policy->policy_number); ?></a></td>
                                    <td><?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?></td>
                                    <td><?php echo esc_html($policy->policy_type); ?></td>
                                    <td><?php echo esc_html($policy->insurance_company); ?></td>
                                    <td><?php echo date('d.m.Y', strtotime($policy->end_date)); ?></td>
                                    <td>₺<?php echo number_format($policy->premium_amount, 2, ',', '.'); ?></td>
                                    <td><?php echo esc_html($policy->rep_name ?: 'Atanmamış'); ?></td>
                                    <td>
                                        <?php if ($policy->days_to_expiry <= 0): ?>
                                            <span class="expired-badge">Süresi Doldu</span>
                                        <?php else: ?>
                                            <span class="days-badge"><?php echo $policy->days_to_expiry; ?> Gün</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-check"></i>
                        <p>Yaklaşan 30 gün içinde yenilemesi gereken poliçe bulunmamaktadır.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- PATRON/MÜDÜR - Temsilci Hedefleri ve Performansları -->
            <div class="dashboard-card target-performance-card">
                <div class="card-header">
                    <h3>Temsilci Performansları</h3>
                    <div class="card-actions">
                        <div class="view-toggle-buttons ml-4">
                            <button id="rep-table-view" class="toggle-btn" onclick="toggleRepView('table')">
                                <i class="fas fa-table"></i> Tablo
                            </button>
                            <button id="rep-card-view" class="toggle-btn active" onclick="toggleRepView('cards')">
                                <i class="fas fa-th-large"></i> Kartlar
                            </button>
                        </div>
                        <a href="<?php echo generate_panel_url('all_personnel'); ?>" class="text-button">Tüm Personel</a>
                        <a href="<?php echo generate_panel_url('representative_add'); ?>" class="card-option" title="Yeni Temsilci">
                            <i class="dashicons dashicons-plus-alt"></i>
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($all_representatives_performance)): ?>
                    <!-- Table View -->
                    <div id="rep-table-container" class="hidden">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Temsilci</th>
                                <th>Unvan</th>
                                <th>Aylık Hedef (₺)</th>
                                <th>Bu Ay Üretim (₺)</th>
                                <th>İptal Poliçe (₺)</th>
                                <th>Net Prim (₺)</th>
                                <th>Gerçekleşme (%)</th>
                                <th>İptal Poliçe</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_representatives_performance as $rep): ?>
                            <tr>
                                <td><?php echo esc_html($rep['name']); ?></td>
                                <td><?php echo esc_html($rep['title']); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($rep['monthly_target'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($rep['current_month_premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell negative-value">₺<?php echo number_format($rep['cancelled_premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($rep['net_premium'], 2, ',', '.'); ?></td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $rep['premium_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($rep['premium_achievement'], 2); ?>%</div>
                                </td>
                                <td><?php echo $rep['cancelled_policies']; ?> adet</td>
                                <td>
                                    <a href="<?php echo generate_panel_url('representative_detail', '', $rep['id']); ?>" class="action-button view-button">
                                        <i class="dashicons dashicons-visibility"></i>
                                        <span>Detay</span>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <!-- Card View -->
                    <div id="rep-cards-container">
                        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4">
                            <?php foreach ($all_representatives_performance as $rep): ?>
                            <?php
                            // Get representative details for avatar and contact info
                            $rep_details = $wpdb->get_row($wpdb->prepare(
                                "SELECT r.*, u.user_email 
                                 FROM {$wpdb->prefix}insurance_crm_representatives r
                                 LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
                                 WHERE r.id = %d",
                                $rep['id']
                            ));
                            ?>
                            <div class="bg-white rounded-lg shadow-md border border-gray-200 overflow-hidden hover:shadow-lg transition-all duration-300 hover:-translate-y-1">
                                <!-- Card Header -->
                                <div class="bg-gradient-to-r from-slate-600 to-blue-700 px-4 py-3">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-3">
                                            <!-- Avatar -->
                                            <div class="relative">
                                                <?php if (!empty($rep_details->avatar_url)): ?>
                                                    <img src="<?php echo esc_url($rep_details->avatar_url); ?>" 
                                                         alt="<?php echo esc_attr($rep['name']); ?>" 
                                                         class="w-10 h-10 rounded-full border-2 border-white shadow-md">
                                                <?php else: ?>
                                                    <div class="w-10 h-10 rounded-full bg-white bg-opacity-20 border-2 border-white flex items-center justify-center shadow-md">
                                                        <span class="text-white font-bold text-sm">
                                                            <?php echo esc_html(strtoupper(substr($rep['name'], 0, 2))); ?>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <h4 class="text-white font-bold text-base"><?php echo esc_html($rep['name']); ?></h4>
                                                <p class="text-blue-100 text-xs mt-1"><?php echo esc_html($rep['title']); ?></p>
                                                <!-- Contact Info -->
                                                <div class="mt-2 space-y-1">
                                                    <?php if ($rep_details->user_email): ?>
                                                    <div class="flex items-center text-blue-100" style="font-size: 10px;">
                                                        <i class="fas fa-envelope mr-1" style="font-size: 10px;"></i>
                                                        <a href="mailto:<?php echo esc_attr($rep_details->user_email); ?>" 
                                                           class="text-blue-100 hover:text-white transition-colors duration-200 truncate" 
                                                           style="font-size: 10px; max-width: 120px;">
                                                            <?php echo esc_html($rep_details->user_email); ?>
                                                        </a>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if ($rep_details->phone): ?>
                                                    <div class="flex items-center text-blue-100 text-xs">
                                                        <i class="fas fa-phone mr-2 text-xs"></i>
                                                        <span><?php echo esc_html($rep_details->phone); ?></span>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Card Body -->
                                <div class="p-4">
                                    <!-- Target vs Achievement -->
                                    <div class="bg-gray-50 rounded-lg p-4 mb-4">
                                        <div class="flex justify-between items-center mb-2">
                                            <span class="text-xs text-gray-500 uppercase tracking-wide">Aylık Hedef</span>
                                            <span class="text-sm font-semibold text-gray-700">₺<?php echo number_format($rep['monthly_target'], 0, ',', '.'); ?></span>
                                        </div>
                                        <div class="flex justify-between items-center mb-3">
                                            <span class="text-xs text-gray-500 uppercase tracking-wide">Bu Ay Üretim</span>
                                            <span class="text-sm font-semibold text-green-600">₺<?php echo number_format($rep['current_month_premium'], 0, ',', '.'); ?></span>
                                        </div>
                                        <!-- Progress Bar -->
                                        <div class="w-full bg-gray-200 rounded-full h-2.5 mb-2">
                                            <div class="bg-gradient-to-r from-green-400 to-green-500 h-2.5 rounded-full transition-all duration-500" 
                                                 style="width: <?php echo min(100, $rep['premium_achievement']); ?>%"></div>
                                        </div>
                                        <div class="flex justify-between text-xs">
                                            <span class="text-gray-500">Hedefe Uzaklık</span>
                                            <span class="font-semibold <?php echo $rep['premium_achievement'] >= 100 ? 'text-green-600' : 'text-orange-600'; ?>">
                                                <?php echo number_format($rep['premium_achievement'], 1); ?>%
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Financial Performance -->
                                    <div class="grid grid-cols-2 gap-3 mb-4">
                                        <div class="bg-green-50 rounded-lg p-3 text-center">
                                            <div class="text-xs text-green-600 uppercase tracking-wide mb-1">Net Prim</div>
                                            <div class="text-sm font-bold text-green-700">₺<?php echo number_format($rep['net_premium'], 0, ',', '.'); ?></div>
                                        </div>
                                        <div class="bg-red-50 rounded-lg p-3 text-center">
                                            <div class="text-xs text-red-600 uppercase tracking-wide mb-1">İptal Prim</div>
                                            <div class="text-sm font-bold text-red-700">₺<?php echo number_format($rep['cancelled_premium'], 0, ',', '.'); ?></div>
                                        </div>
                                    </div>

                                    <!-- Policy Stats -->
                                    <div class="bg-orange-50 rounded-lg p-3 text-center mb-4">
                                        <div class="text-xs text-orange-600 uppercase tracking-wide mb-1">İptal Poliçe</div>
                                        <div class="text-lg font-bold text-orange-700"><?php echo $rep['cancelled_policies']; ?> adet</div>
                                    </div>

                                    <!-- Performance Badge -->
                                    <div class="text-center">
                                        <?php if ($rep['premium_achievement'] >= 100): ?>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">
                                                <i class="fas fa-trophy mr-1"></i>
                                                Hedef Aşıldı
                                            </span>
                                        <?php elseif ($rep['premium_achievement'] >= 75): ?>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                <i class="fas fa-star mr-1"></i>
                                                İyi Performans
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                                <i class="fas fa-target mr-1"></i>
                                                Hedefe Odaklan
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Card Footer -->
                                <div class="bg-gray-50 px-4 py-3">
                                    <a href="<?php echo generate_panel_url('representative_detail', '', $rep['id']); ?>" 
                                       class="w-full bg-blue-600 text-white font-medium py-2 px-3 rounded-md hover:bg-blue-700 transition-colors duration-200 text-center block text-sm">
                                        <i class="fas fa-user-circle mr-1"></i>
                                        Detayları Görüntüle
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <p>Henüz temsilci performans verisi bulunmamaktadır.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- PATRON/MÜDÜR - Ekip Performans Tablosu -->
            <div class="dashboard-card team-performance-card">
                <div class="card-header">
                    <h3>Ekip Performansları</h3>
                    <div class="card-actions">
                        <div class="view-toggle-buttons">
                            <button id="team-table-view" class="toggle-btn active" onclick="toggleTeamView('table')">
                                <i class="fas fa-table"></i> Tablo
                            </button>
                            <button id="team-card-view" class="toggle-btn" onclick="toggleTeamView('cards')">
                                <i class="fas fa-th-large"></i> Kartlar
                            </button>
                        </div>
                        <a href="<?php echo generate_panel_url('all_personnel'); ?>" class="text-button">Tüm Personel</a>
                        <a href="<?php echo generate_panel_url('team_add'); ?>" class="card-option" title="Yeni Ekip">
                            <i class="dashicons dashicons-plus-alt"></i>
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($all_teams)): ?>
                    <!-- Table View -->
                    <div id="team-table-container">
                    <table class="data-table teams-table">
                        <thead>
                            <tr>
                                <th>Ekip Adı</th>
                                <th>Ekip Lideri</th>
                                <th>Üye Sayısı</th>
                                <th>Toplam Prim (₺)</th>
                                <th>Aylık Hedef (₺)</th>
                                <th>Bu Ay Üretim (₺)</th>
                                <th>İptal Poliçe (₺)</th>
                                <th>Net Prim (₺)</th>
                                <th>Gerçekleşme</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_teams as $team): ?>
                            <tr>
                                <td><?php echo esc_html($team['name']); ?></td>
                                <td><?php echo esc_html($team['leader_name'] . ' (' . $team['leader_title'] . ')'); ?></td>
                                <td><?php echo $team['member_count']; ?> üye</td>
                                <td class="amount-cell">₺<?php echo number_format($team['total_premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($team['monthly_target'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($team['month_premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell negative-value">₺<?php echo number_format($team['cancelled_premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($team['month_net_premium'], 2, ',', '.'); ?></td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $team['premium_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($team['premium_achievement'], 2); ?>%</div>
                                </td>
                                <td>
                                    <a href="<?php echo generate_team_detail_url($team['id']); ?>" class="action-button view-button">
                                        <i class="dashicons dashicons-visibility"></i>
                                        <span>Detay</span>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <!-- Card View -->
                    <div id="team-cards-container" class="hidden">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <?php foreach ($all_teams as $team): ?>
                            <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden hover:shadow-xl transition-all duration-300 hover:-translate-y-1">
                                <!-- Card Header -->
                                <div class="bg-gradient-to-r from-blue-500 to-blue-600 px-6 py-4">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <h4 class="text-white font-bold text-lg"><?php echo esc_html($team['name']); ?></h4>
                                            <p class="text-blue-100 text-sm mt-1"><?php echo $team['member_count']; ?> üye</p>
                                        </div>
                                        <div class="bg-white bg-opacity-20 rounded-full p-3">
                                            <i class="fas fa-users text-white text-xl"></i>
                                        </div>
                                    </div>
                                </div>

                                <!-- Card Body -->
                                <div class="p-6">
                                    <!-- Team Leader -->
                                    <div class="mb-4 pb-4 border-b border-gray-100">
                                        <div class="flex items-center space-x-3">
                                            <div class="bg-gray-100 rounded-full p-2">
                                                <i class="fas fa-user-tie text-gray-600"></i>
                                            </div>
                                            <div>
                                                <p class="text-xs text-gray-500 uppercase tracking-wide">Ekip Lideri</p>
                                                <p class="font-semibold text-gray-800"><?php echo esc_html($team['leader_name']); ?></p>
                                                <p class="text-sm text-gray-600"><?php echo esc_html($team['leader_title']); ?></p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Performance Stats -->
                                    <div class="space-y-4">
                                        <!-- Monthly Target & Achievement -->
                                        <div class="bg-gray-50 rounded-lg p-4">
                                            <div class="flex justify-between items-center mb-2">
                                                <span class="text-xs text-gray-500 uppercase tracking-wide">Aylık Hedef</span>
                                                <span class="text-sm font-semibold text-gray-700">₺<?php echo number_format($team['monthly_target'], 0, ',', '.'); ?></span>
                                            </div>
                                            <div class="flex justify-between items-center mb-3">
                                                <span class="text-xs text-gray-500 uppercase tracking-wide">Bu Ay Üretim</span>
                                                <span class="text-sm font-semibold text-green-600">₺<?php echo number_format($team['month_premium'], 0, ',', '.'); ?></span>
                                            </div>
                                            <!-- Progress Bar -->
                                            <div class="w-full bg-gray-200 rounded-full h-2.5 mb-2">
                                                <div class="bg-gradient-to-r from-green-400 to-green-500 h-2.5 rounded-full transition-all duration-500" 
                                                     style="width: <?php echo min(100, $team['premium_achievement']); ?>%"></div>
                                            </div>
                                            <div class="flex justify-between text-xs">
                                                <span class="text-gray-500">Gerçekleşme</span>
                                                <span class="font-semibold <?php echo $team['premium_achievement'] >= 100 ? 'text-green-600' : 'text-orange-600'; ?>">
                                                    <?php echo number_format($team['premium_achievement'], 1); ?>%
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Financial Details -->
                                        <div class="grid grid-cols-2 gap-3">
                                            <div class="bg-green-50 rounded-lg p-3 text-center">
                                                <div class="text-xs text-green-600 uppercase tracking-wide mb-1">Toplam Prim</div>
                                                <div class="text-sm font-bold text-green-700">₺<?php echo number_format($team['total_premium'], 0, ',', '.'); ?></div>
                                            </div>
                                            <div class="bg-red-50 rounded-lg p-3 text-center">
                                                <div class="text-xs text-red-600 uppercase tracking-wide mb-1">İptal Poliçe</div>
                                                <div class="text-sm font-bold text-red-700">₺<?php echo number_format($team['cancelled_premium'], 0, ',', '.'); ?></div>
                                            </div>
                                        </div>

                                        <!-- Net Premium -->
                                        <div class="bg-blue-50 rounded-lg p-3 text-center">
                                            <div class="text-xs text-blue-600 uppercase tracking-wide mb-1">Net Prim</div>
                                            <div class="text-lg font-bold text-blue-700">₺<?php echo number_format($team['month_net_premium'], 0, ',', '.'); ?></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Card Footer -->
                                <div class="bg-gray-50 px-6 py-4">
                                    <a href="<?php echo generate_team_detail_url($team['id']); ?>" 
                                       class="w-full bg-gradient-to-r from-blue-500 to-blue-600 text-white font-medium py-2 px-4 rounded-lg hover:from-blue-600 hover:to-blue-700 transition-all duration-200 text-center block">
                                        <i class="fas fa-eye mr-2"></i>
                                        Ekip Detayını Görüntüle
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="dashicons dashicons-groups"></i>
                        </div>
                        <h4>Henüz ekip tanımlanmamış</h4>
                        <p>Organizasyon yapısını düzenlemek için ekip oluşturun.</p>
                        <a href="<?php echo generate_panel_url('team_add'); ?>" class="button button-primary">Yeni Ekip Oluştur</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php elseif ($current_view == 'team' && !is_team_leader($current_user->ID)): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="dashicons dashicons-groups"></i>
                </div>
                <h4>Yetkisiz Erişim</h4>
                <p>Ekip performansı sayfasını görüntülemek için ekip lideri olmalısınız.</p>
            </div>
           

<?php elseif ($current_view == 'team'): ?>
<header class="crm-header">
    <div class="header-content">
        <div class="title-section">
            <div class="page-title">
                <i class="fas fa-users"></i>
                <h1><?php echo esc_html($current_user->display_name); ?> Dashboard</h1>
                <span class="version-badge">v<?php echo defined('INSURANCE_CRM_VERSION') ? INSURANCE_CRM_VERSION : '1.1.3'; ?></span>
            </div>
            <div class="user-badge">
                <span class="role-badge">
                    <i class="fas fa-user-shield"></i>
                    <?php 
                    $user_role = get_user_role_in_hierarchy($current_user->ID);
                    $role_names = [
                        'patron' => 'Patron',
                        'manager' => 'Müdür', 
                        'assistant_manager' => 'Müdür Yardımcısı',
                        'team_leader' => 'Ekip Lideri',
                        'representative' => 'Müşteri Temsilcisi'
                    ];
                    echo esc_html($role_names[$user_role] ?? 'Bilinmiyor');
                    ?>
                </span>
            </div>
        </div>
    </div>
</header>

<div class="stats-grid">
    <div class="stat-box customers-box">
        <div class="stat-icon">
            <i class="dashicons dashicons-groups"></i>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?php echo number_format($total_customers); ?></div>
            <div class="stat-label">Ekip Toplam Müşteri</div>
        </div>
        <div class="stat-change positive">
            <div class="stat-new">
                <?php echo $filter_title; ?> eklenen: +<?php echo $new_customers; ?> Müşteri
            </div>
            <div class="stat-rate positive">
                <i class="dashicons dashicons-arrow-up-alt"></i>
                <span><?php echo number_format($customer_increase_rate, 2); ?>%</span>
            </div>
        </div>
    </div>
    
    <div class="stat-box policies-box">
        <div class="stat-icon">
            <i class="dashicons dashicons-portfolio"></i>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?php echo number_format($total_policies); ?></div>
            <div class="stat-label">Ekip Toplam Poliçe (<?php echo date('Y'); ?> Yılı)</div>
            <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
        </div>
        <div class="stat-change positive">
            <div class="stat-new">
                <?php echo $filter_title; ?> eklenen: +<?php echo $new_policies; ?> Poliçe
            </div>
            <div class="stat-new refund-info">
                <?php echo $filter_title; ?> iptal edilen: <?php echo $this_period_cancelled_policies; ?> Poliçe
            </div>
            <div class="stat-rate positive">
                <i class="dashicons dashicons-arrow-up-alt"></i>
                <span><?php echo number_format($policy_increase_rate, 2); ?>%</span>
            </div>
        </div>
    </div>
    
    <div class="stat-box production-box">
        <div class="stat-icon">
            <i class="dashicons dashicons-chart-bar"></i>
        </div>
        <div class="stat-details">
            <div class="stat-value">₺<?php echo number_format($total_premium, 2, ',', '.'); ?></div>
            <div class="stat-label">Ekip Toplam Üretim (<?php echo date('Y'); ?> Yılı)</div>
            <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
        </div>
        <div class="stat-change positive">
            <div class="stat-new">
                <?php echo $filter_title; ?> eklenen: +₺<?php echo number_format($new_premium, 2, ',', '.'); ?>
            </div>
            <div class="stat-new refund-info">
                <?php echo $filter_title; ?> iptal edilen: ₺<?php echo number_format($period_refunded_amount, 2, ',', '.'); ?>
            </div>
            <div class="stat-rate positive">
                <i class="dashicons dashicons-arrow-up-alt"></i>
                <span><?php echo number_format($premium_increase_rate, 2); ?>%</span>
            </div>
        </div>
    </div>
    
    <div class="stat-box target-box">
        <div class="stat-icon">
            <i class="dashicons dashicons-performance"></i>
        </div>
        <div class="stat-details">
            <div class="stat-value">₺<?php echo number_format($current_month_premium, 2, ',', '.'); ?></div>
            <div class="stat-label">Ekip Bu Ay Üretim</div>
        </div>
        <div class="stat-target">
            <div class="target-text">Prim Hedefi: ₺<?php echo number_format($monthly_target, 2, ',', '.'); ?></div>
            <?php
            $remaining_amount = max(0, $monthly_target - $current_month_premium);
            ?>
            <div class="target-text">Hedefe Kalan: ₺<?php echo number_format($remaining_amount, 2, ',', '.'); ?></div>
            <div class="target-progress-mini">
                <div class="target-bar" style="width: <?php echo $achievement_rate; ?>%"></div>
            </div>
            
            <div class="target-text">Poliçe Hedefi: <?php echo $team_policy_target; ?> Adet</div>
            <div class="target-text">Gerçekleşen: <?php echo $new_policies; ?> Adet (<?php echo number_format($policy_achievement_rate, 2); ?>%)</div>
            <div class="target-progress-mini">
                <div class="target-bar" style="width: <?php echo $policy_achievement_rate; ?>%"></div>
            </div>
        </div>
    </div>
    
    <?php 
    // Get today's birthday customers for the stat box
    if (!isset($birthday_stat_data)) {
        require_once(INSURANCE_CRM_PATH . 'includes/dashboard-functions.php');
        $birthday_stat_data = get_todays_birthday_customers($current_user->ID, 1, 1);
    }
    ?>
    <div class="stat-box birthday-stat-box">
        <div class="stat-icon">
            <i class="fas fa-birthday-cake" style="color: #ff6b6b;"></i>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?php echo $birthday_stat_data['total']; ?></div>
            <div class="stat-label">Bugün Doğum Günü Olan Müşteriler</div>
        </div>
        <div class="stat-change">
            <div class="birthday-preview">
                <?php if ($birthday_stat_data['total'] > 0): ?>
                    <div class="birthday-details-list">
                        <?php foreach ($birthday_stat_data['customers'] as $customer): ?>
                            <div class="birthday-detail-item">
                                <div class="birthday-customer-name"><?php echo esc_html($customer->full_name); ?></div>
                                <?php if ($customer->age): ?>
                                    <div class="birthday-customer-age"><?php echo esc_html($customer->age); ?> yaşında</div>
                                <?php endif; ?>
                                <?php if (!empty($customer->phone)): ?>
                                    <div class="birthday-customer-phone"><?php echo esc_html($customer->phone); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($customer->email)): ?>
                                    <div class="birthday-customer-action">
                                        <button class="birthday-mini-celebrate-btn" onclick="sendBirthdayEmail(<?php echo esc_attr($customer->id); ?>, '<?php echo esc_js($customer->full_name); ?>')">
                                            Kutla
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="birthday-empty">Bugün doğum günü yok</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>



            <?php endif; ?>

            <?php else: ?>
            <!-- NORMAL DASHBOARD VEYA EKİP LİDERİ DASHBOARD İÇERİĞİ -->
            
            <?php 
            $user_role = get_user_role_in_hierarchy($current_user->ID);
            if ($user_role != 'team_leader' && $user_role != 'representative'): 
            ?>
            <header class="crm-header">
                <div class="header-content">
                    <div class="title-section">
                        <div class="page-title">
                            <i class="fas fa-tachometer-alt"></i>
                            <h1><?php echo esc_html($current_user->display_name); ?> Dashboard</h1>
                            <span class="version-badge">v<?php echo defined('INSURANCE_CRM_VERSION') ? INSURANCE_CRM_VERSION : '1.1.3'; ?></span>
                        </div>
                        <div class="user-badge">
                            <span class="role-badge">
                                <i class="fas fa-user-shield"></i>
                                <?php 
                                $role_names = [
                                    'patron' => 'Patron',
                                    'manager' => 'Müdür', 
                                    'assistant_manager' => 'Müdür Yardımcısı',
                                    'team_leader' => 'Ekip Lideri',
                                    'representative' => 'Müşteri Temsilcisi'
                                ];
                                echo esc_html($role_names[$user_role] ?? 'Bilinmiyor');
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
            </header>
            <?php endif; ?>
            
            <div class="stats-grid">
                <div class="stat-box customers-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-groups"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value"><?php echo number_format($total_customers); ?></div>
                        <div class="stat-label"><?php echo $current_view == 'team' ? 'Ekip Toplam Müşteri' : 'Toplam Müşteri'; ?></div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            <?php echo $filter_title; ?> eklenen: +<?php echo $new_customers; ?> Müşteri
                        </div>
                        <div class="stat-rate positive">
                            <i class="dashicons dashicons-arrow-up-alt"></i>
                            <span><?php echo number_format($customer_increase_rate, 2); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box policies-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-portfolio"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value"><?php echo number_format($total_policies); ?></div>
                        <div class="stat-label"><?php echo $current_view == 'team' ? 'Ekip Toplam Poliçe' : 'Toplam Poliçe'; ?> (<?php echo date('Y'); ?> Yılı)</div>
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            <?php echo $filter_title; ?> eklenen: +<?php echo $new_policies; ?> Poliçe
                        </div>
                        <div class="stat-new refund-info">
                            <?php echo $filter_title; ?> iptal edilen: <?php echo $this_period_cancelled_policies; ?> Poliçe
                        </div>
                        <div class="stat-rate positive">
                            <i class="dashicons dashicons-arrow-up-alt"></i>
                            <span><?php echo number_format($policy_increase_rate, 2); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box production-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-chart-bar"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value">₺<?php echo number_format($total_premium, 2, ',', '.'); ?></div>
                        <div class="stat-label"><?php echo $current_view == 'team' ? 'Ekip Toplam Üretim (' . date('Y') . ' Yılı)' : 'Toplam Üretim (' . date('Y') . ' Yılı)'; ?></div>
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            <?php echo $filter_title; ?> eklenen: +₺<?php echo number_format($new_premium, 2, ',', '.'); ?>
                        </div>
                        <div class="stat-rate positive">
                            <i class="dashicons dashicons-arrow-up-alt"></i>
                            <span><?php echo number_format($premium_increase_rate, 2); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box target-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-performance"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value">₺<?php echo number_format($current_month_premium, 2, ',', '.'); ?></div>
                        <div class="stat-label"><?php echo $current_view == 'team' ? 'Ekip Bu Ay Üretim' : 'Bu Ay Üretim'; ?></div>
                    </div>
                    <div class="stat-target">
                        <div class="target-text">Prim Hedefi: ₺<?php echo number_format($monthly_target, 2, ',', '.'); ?></div>
                        <?php
                        $remaining_amount = max(0, $monthly_target - $current_month_premium);
                        ?>
                        <div class="target-text">Hedefe Kalan: ₺<?php echo number_format($remaining_amount, 2, ',', '.'); ?></div>
                        <div class="target-progress-mini">
                            <div class="target-bar" style="width: <?php echo $achievement_rate; ?>%"></div>
                        </div>
                        
                        <div class="target-text">Poliçe Hedefi: <?php echo $team_policy_target; ?> Adet</div>
                        <div class="target-text">Gerçekleşen: <?php echo $new_policies; ?> Adet (<?php echo number_format($policy_achievement_rate, 2); ?>%)</div>
                        <div class="target-progress-mini">
                            <div class="target-bar" style="width: <?php echo $policy_achievement_rate; ?>%"></div>
                        </div>
                    </div>
                </div>
                
                <?php 
                // Get today's birthday customers for the stat box
                if (!isset($birthday_stat_data)) {
                    require_once(INSURANCE_CRM_PATH . 'includes/dashboard-functions.php');
                    $birthday_stat_data = get_todays_birthday_customers($current_user->ID, 1, 1);
                }
                ?>
                <div class="stat-box birthday-stat-box">
                    <div class="stat-icon">
                        <i class="fas fa-birthday-cake" style="color: #ff6b6b;"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value"><?php echo $birthday_stat_data['total']; ?></div>
                        <div class="stat-label">Bugün Doğum Günü Olan Müşteriler</div>
                    </div>
                    <div class="stat-change">
                        <div class="birthday-preview">
                            <?php if ($birthday_stat_data['total'] > 0): ?>
                                <div class="birthday-details-list">
                                    <?php foreach ($birthday_stat_data['customers'] as $customer): ?>
                                        <div class="birthday-detail-item">
                                            <div class="birthday-customer-name"><?php echo esc_html($customer->full_name); ?></div>
                                            <?php if ($customer->age): ?>
                                                <div class="birthday-customer-age"><?php echo esc_html($customer->age); ?> yaşında</div>
                                            <?php endif; ?>
                                            <?php if (!empty($customer->phone)): ?>
                                                <div class="birthday-customer-phone"><?php echo esc_html($customer->phone); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($customer->email)): ?>
                                                <div class="birthday-customer-action">
                                                    <button class="birthday-mini-celebrate-btn" onclick="sendBirthdayEmail(<?php echo esc_attr($customer->id); ?>, '<?php echo esc_js($customer->full_name); ?>')">
                                                        Kutla
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="birthday-empty">Bugün doğum günü yok</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            

            
            <?php if ($current_view == 'team' && !empty($member_performance)): ?>
            <div class="dashboard-card member-performance-card">
                <div class="card-header">
                    <h3>Üye Performansı</h3>
                </div>
                <div class="card-body">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Üye Adı</th>
                                <th>Müşteri Sayısı</th>
                                <th>Poliçe Sayısı</th>
                                <th>Aylık Hedef (₺)</th>
                                <th>Bu Ay Üretim (₺)</th>
                                <th>Gerçekleşme</th>
                                <th>Hedef Poliçe</th>
                                <th>Bu Ay Poliçe</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($member_performance as $member): ?>
                            <tr>
                                <td><?php echo esc_html($member['name']); ?></td>
                                <td><?php echo number_format($member['customers']); ?></td>
                                <td><?php echo number_format($member['policies']); ?></td>
                                <td>₺<?php echo number_format($member['monthly_target'], 2, ',', '.'); ?></td>
                                <td>₺<?php echo number_format($member['this_month_premium'], 2, ',', '.'); ?></td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $member['premium_achievement_rate']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($member['premium_achievement_rate'], 2); ?>%</div>
                                </td>
                                <td><?php echo $member['target_policy_count']; ?> adet</td>
                                <td><?php echo $member['this_month_policies']; ?> adet</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="dashboard-grid">
                <div class="upper-section">
                    <div class="dashboard-card chart-card">
                        <div class="card-header">
                            <h3><?php echo $current_view == 'team' ? 'Ekip Aylık Üretim Performansı' : 'Aylık Üretim Performansı'; ?></h3>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="productionChart"></canvas>
                            </div>
                            <div class="production-table" style="margin-top: 20px;">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Ay-Yıl</th>
                                            <th>Hedef (₺)</th>
                                            <th>Üretilen (₺)</th>
                                            <th>İade Edilen (₺)</th>
                                            <th>Gerçekleşme Oranı (%)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($monthly_production_data as $month_year => $total): ?>
                                            <?php 
                                            $dateParts = explode('-', $month_year);
                                            $year = $dateParts[0];
                                            $month = (int)$dateParts[1];
                                            $months = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 
                                                       'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
                                            $month_name = $months[$month - 1] . ' ' . $year;
                                            $achievement_rate = $monthly_target > 0 ? ($total / $monthly_target) * 100 : 0;
                                            $achievement_rate = min(100, $achievement_rate);
                                            $refunded_amount = $monthly_refunded_data[$month_year];
                                            ?>
                                            <tr>
                                                <td><?php echo esc_html($month_name); ?></td>
                                                <td>₺<?php echo number_format($monthly_target, 2, ',', '.'); ?></td>
                                                <td class="amount-cell">₺<?php echo number_format($total, 2, ',', '.'); ?></td>
                                                <td class="refund-info">₺<?php echo number_format($refunded_amount, 2, ',', '.'); ?></td>
                                                <td><?php echo number_format($achievement_rate, 2, ',', '.'); ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>    

                    <?php
                    // Görev yönetimi fonksiyonlarını dahil et
                    if (file_exists(dirname(__FILE__) . '/modules/task-management/task-functions.php')) {
                        include_once dirname(__FILE__) . '/modules/task-management/task-functions.php';
                    }
                    
                    // Görev özeti widget'ını dahil et
                    if (file_exists(dirname(__FILE__) . '/tasks-dashboard-widget.php')) {
                        include_once dirname(__FILE__) . '/tasks-dashboard-widget.php';
                    }
                    ?>

                </div>
                
                <div class="lower-section">
                    <div class="dashboard-card renewals-card">
                        <div class="card-header">
                            <h3><?php echo $current_view == 'team' ? 'Ekip Yaklaşan Yenilemeler' : 'Yaklaşan Yenilemeler'; ?></h3>
                            <div class="card-actions">
                                <a href="<?php echo generate_panel_url('policies', '', '', array('filter' => 'renewals')); ?>" class="text-button">Tümünü Gör</a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($upcoming_renewals)): ?>
                                <table class="data-table renewals-table">
                                    <thead>
                                        <tr>
                                            <th>Poliçe No</th>
                                            <th>Müşteri</th>
                                            <th class="hide-mobile">Tür</th>
                                            <th class="hide-mobile">Başlangıç</th>
                                            <th class="hide-mobile">Bitiş</th>
                                            <th class="hide-mobile">Tutar</th>
                                            <th class="hide-mobile">Durum</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($upcoming_renewals as $policy): 
                                            $end_date = new DateTime($policy->end_date);
                                            $now = new DateTime();
                                            $days_remaining = $now->diff($end_date)->days;
                                            $urgency_class = '';
                                            if ($days_remaining <= 5) {
                                                $urgency_class = 'urgent';
                                            } elseif ($days_remaining <= 15) {
                                                $urgency_class = 'soon';
                                            }
                                        ?>
                                        <tr class="<?php echo $urgency_class; ?>">
                                            <td data-label="Poliçe No">
                                                <a href="<?php echo generate_panel_url('policies', 'edit', $policy->id); ?>">
                                                    <?php echo esc_html($policy->policy_number); ?>
                                                </a>
                                            </td>
                                            <td data-label="Müşteri">
                                                <a href="<?php echo generate_panel_url('customers', 'edit', $policy->customer_id); ?>">
                                                    <?php 
                                                    $gender_icon = '';
                                                    if (isset($policy->gender)) {
                                                        if ($policy->gender == 'male') {
                                                            $gender_icon = '<i class="fas fa-male" style="color: #2196F3; margin-right: 5px;"></i>';
                                                        } elseif ($policy->gender == 'female') {
                                                            $gender_icon = '<i class="fas fa-female" style="color: #E91E63; margin-right: 5px;"></i>';
                                                        }
                                                    }
                                                    echo $gender_icon . esc_html($policy->first_name . ' ' . $policy->last_name); 
                                                    ?>
                                                </a>
                                            </td>
                                            <td class="hide-mobile" data-label="Tür"><?php echo esc_html(ucfirst($policy->policy_type)); ?></td>
                                            <td class="hide-mobile" data-label="Başlangıç"><?php echo date_i18n('d.m.Y', strtotime($policy->start_date)); ?></td>
                                            <td class="hide-mobile" data-label="Bitiş"><?php echo date_i18n('d.m.Y', strtotime($policy->end_date)); ?></td>
                                            <td class="amount-cell hide-mobile" data-label="Tutar">₺<?php echo number_format($policy->premium_amount, 2, ',', '.'); ?></td>
                                            <td class="hide-mobile" data-label="Durum">
                                                <span class="status-badge status-<?php echo esc_attr($policy->status); ?>">
                                                    <?php echo esc_html(ucfirst($policy->status)); ?>
                                                </span>
                                            </td>
                                            <td data-label="İşlem">
                                                <a href="<?php echo generate_panel_url('policies', 'renew', $policy->id); ?>" class="action-button renew-button">
                                                    <i class="dashicons dashicons-update"></i>
                                                    <span>Yenile</span>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-icon">
                                        <i class="dashicons dashicons-calendar-alt"></i>
                                    </div>
                                    <h4>Yaklaşan yenileme bulunmuyor</h4>
                                    <p>Önümüzdeki 30 gün içinde yenilenecek poliçe yok.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="dashboard-card expired-policies-card">
                        <div class="card-header">
                            <h3><?php echo $current_view == 'team' ? 'Ekip Süresi Geçmiş Poliçeler' : 'Süresi Geçmiş Poliçeler'; ?></h3>
                            <div class="card-actions">
                                <a href="<?php echo generate_panel_url('policies', '', '', array('filter' => 'expired')); ?>" class="text-button">Tümünü Gör</a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($expired_policies)): ?>
                                <table class="data-table expired-policies-table">
                                    <thead>
                                        <tr>
                                            <th>Poliçe No</th>
                                            <th>Müşteri</th>
                                            <th class="hide-mobile">Tür</th>
                                            <th class="hide-mobile">Başlangıç</th>
                                            <th class="hide-mobile">Bitiş</th>
                                            <th class="hide-mobile">Tutar</th>
                                            <th class="hide-mobile">Durum</th>
                                            <th class="hide-mobile">Gecikme</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($expired_policies as $policy): 
                                            $end_date = new DateTime($policy->end_date);
                                            $now = new DateTime();
                                            $days_overdue = $end_date->diff($now)->days;
                                        ?>
                                        <tr>
                                            <td>
                                                <a href="<?php echo generate_panel_url('policies', 'edit', $policy->id); ?>">
                                                    <?php echo esc_html($policy->policy_number); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <a href="<?php echo generate_panel_url('customers', 'edit', $policy->customer_id); ?>">
                                                    <?php 
                                                    $gender_icon = '';
                                                    if (isset($policy->gender)) {
                                                        if ($policy->gender == 'male') {
                                                            $gender_icon = '<i class="fas fa-male" style="color: #2196F3; margin-right: 5px;"></i>';
                                                        } elseif ($policy->gender == 'female') {
                                                            $gender_icon = '<i class="fas fa-female" style="color: #E91E63; margin-right: 5px;"></i>';
                                                        }
                                                    }
                                                    echo $gender_icon . esc_html($policy->first_name . ' ' . $policy->last_name); 
                                                    ?>
                                                </a>
                                            </td>
                                            <td class="hide-mobile"><?php echo esc_html(ucfirst($policy->policy_type)); ?></td>
                                            <td class="hide-mobile"><?php echo date_i18n('d.m.Y', strtotime($policy->start_date)); ?></td>
                                            <td class="hide-mobile"><?php echo date_i18n('d.m.Y', strtotime($policy->end_date)); ?></td>
                                            <td class="amount-cell hide-mobile">₺<?php echo number_format($policy->premium_amount, 2, ',', '.'); ?></td>
                                            <td class="hide-mobile">
                                                <span class="status-badge status-<?php echo esc_attr($policy->status); ?>">
                                                    <?php echo esc_html(ucfirst($policy->status)); ?>
                                                </span>
                                            </td>
                                            <td class="days-overdue hide-mobile">
                                                <?php echo $days_overdue; ?> gün
                                            </td>
                                            <td>
                                                <a href="<?php echo generate_panel_url('policies', 'renew', $policy->id); ?>" class="action-button renew-button">
                                                    <i class="dashicons dashicons-update"></i>
                                                    <span>Yenile</span>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-icon">
                                        <i class="dashicons dashicons-portfolio"></i>
                                    </div>
                                    <h4>Süresi geçmiş poliçe bulunmuyor</h4>
                                    <p>Tüm poliçeleriniz güncel.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="dashboard-card recent-policies-card">
                        <div class="card-header">
                            <h3><?php echo $current_view == 'team' ? 'Ekip Son Eklenen Poliçeler' : 'Son Eklenen Poliçeler'; ?></h3>
                            <div class="card-actions">
                                <a href="<?php echo generate_panel_url('policies'); ?>" class="text-button">Tümünü Gör</a>
                                <a href="<?php echo generate_panel_url('policies', 'new'); ?>" class="card-option" title="Yeni Poliçe">
                                    <i class="dashicons dashicons-plus-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($recent_policies)): ?>
                                <table class="data-table policies-table">
                                    <thead>
                                        <tr>
                                            <th>Poliçe No</th>
                                            <th>Müşteri</th>
                                            <th class="hide-mobile">Tür</th>
                                            <th class="hide-mobile">Başlangıç</th>
                                            <th class="hide-mobile">Bitiş</th>
                                            <th class="hide-mobile">Tutar</th>
                                            <th class="hide-mobile">Durum</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_policies as $policy): ?>
                                        <tr>
                                            <td>
                                                <a href="<?php echo generate_panel_url('policies', 'edit', $policy->id); ?>" class="policy-link">
                                                    <?php echo esc_html($policy->policy_number); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div class="user-info-cell">
                                                    <div class="user-avatar-mini">
                                                        <?php 
                                                        if (isset($policy->gender) && $policy->gender == 'female') {
                                                            echo '<i class="fas fa-female"></i>';
                                                        } else {
                                                            echo '<i class="fas fa-male"></i>';
                                                        }
                                                        ?>
                                                    </div>
                                                    <span>
                                                        <a href="<?php echo generate_panel_url('customers', 'edit', $policy->customer_id); ?>">
                                                            <?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?>
                                                        </a>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="hide-mobile"><?php echo esc_html(ucfirst($policy->policy_type)); ?></td>
                                            <td class="hide-mobile"><?php echo date_i18n('d.m.Y', strtotime($policy->start_date)); ?></td>
                                            <td class="hide-mobile"><?php echo date_i18n('d.m.Y', strtotime($policy->end_date)); ?></td>
                                            <td class="amount-cell hide-mobile">₺<?php echo number_format($policy->premium_amount, 2, ',', '.'); ?></td>
                                            <td class="hide-mobile">
                                                <span class="status-badge status-<?php echo esc_attr($policy->status); ?>">
                                                    <?php echo esc_html(ucfirst($policy->status)); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="table-actions">
                                                    <a href="<?php echo generate_panel_url('policies', 'view', $policy->id); ?>" class="table-action" title="Görüntüle">
                                                        <i class="dashicons dashicons-visibility"></i>
                                                    </a>
                                                    <a href="<?php echo generate_panel_url('policies', 'edit', $policy->id); ?>" class="table-action" title="Düzenle">
                                                        <i class="dashicons dashicons-edit"></i>
                                                    </a>
                                                    <div class="table-action-dropdown-wrapper">
                                                        <button class="table-action table-action-more" title="Daha Fazla">
                                                            <i class="dashicons dashicons-ellipsis"></i>
                                                        </button>
                                                        <div class="table-action-dropdown">
                                                            <a href="<?php echo generate_panel_url('policies', 'renew', $policy->id); ?>">Yenile</a>
                                                            <a href="<?php echo generate_panel_url('policies', 'duplicate', $policy->id); ?>">Kopyala</a>
                                                            <?php if (can_delete_items($current_user->ID)): ?>
                                                            <a href="<?php echo generate_panel_url('policies', 'cancel', $policy->id); ?>" class="text-danger">İptal Et</a>
                                                            <?php else: ?>
                                                            <a href="<?php echo generate_panel_url('policies', 'deactivate', $policy->id); ?>" class="text-warning">Pasife Al</a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-icon">
                                        <i class="dashicons dashicons-portfolio"></i>
                                    </div>
                                    <h4>Henüz poliçe eklenmemiş</h4>
                                    <p>Sisteme poliçe ekleyerek müşterilerinizi takip edin.</p>
                                    <a href="<?php echo generate_panel_url('policies', 'new'); ?>" class="button button-primary">
                                        Yeni Poliçe Ekle
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php elseif ($current_view == 'search'): ?>
            <div class="main-content">
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3>Arama Sonuçları</h3>
                        <div class="card-actions">
                            <a href="<?php echo generate_panel_url('dashboard'); ?>" class="text-button">Dashboard'a Dön</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($search_results)): ?>
                            <table class="data-table search-results-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Ad Soyad', 'insurance-crm'); ?></th>
                                        <th><?php esc_html_e('TC Kimlik', 'insurance-crm'); ?></th>
                                        <th><?php esc_html_e('Çocuk Ad Soyad', 'insurance-crm'); ?></th>
                                        <th><?php esc_html_e('Çocuk TC Kimlik', 'insurance-crm'); ?></th>
                                        <th><?php esc_html_e('Poliçe No', 'insurance-crm'); ?></th>
                                        <th><?php esc_html_e('İşlemler', 'insurance-crm'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $row_count = 0;
                                    foreach ($search_results as $customer): 
                                        $row_count++;
                                        $is_corporate = !empty(trim($customer->company_name ?? ''));
                                        $row_class = ($row_count % 2 == 0) ? 'even-row' : 'odd-row';
                                    ?>
                                        <tr class="<?php echo $row_class; ?>">
                                            <td>
                                                <a href="?view=customers&action=view&id=<?php echo esc_attr($customer->id); ?>" 
                                                   class="ab-customer-name <?php echo $is_corporate ? 'corporate-customer' : ''; ?>">
                                                    <?php 
                                                    if ($is_corporate) {
                                                        echo '<strong>' . esc_html($customer->customer_name) . '</strong>';
                                                    } else {
                                                        echo esc_html($customer->customer_name);
                                                    }
                                                    ?>
                                                </a>
                                            </td>
                                            <td><?php echo esc_html($customer->tc_identity); ?></td>
                                            <td><?php echo esc_html($customer->children_names ?: '-'); ?></td>
                                            <td><?php echo esc_html($customer->children_tc_identities ?: '-'); ?></td>
                                            <td><?php echo esc_html($customer->policy_number ?: '-'); ?></td>
                                            <td>
                                                <div class="table-actions">
                                                    <a href="?view=customers&action=view&id=<?php echo esc_attr($customer->id); ?>" class="table-action" title="Görüntüle">
                                                        <i class="dashicons dashicons-visibility"></i>
                                                    </a>
                                                    <a href="?view=customers&action=edit&id=<?php echo esc_attr($customer->id); ?>" class="table-action" title="Düzenle">
                                                        <i class="dashicons dashicons-edit"></i>
                                                    </a>
                                                    <div class="table-action-dropdown-wrapper">
                                                        <button class="table-action table-action-more" title="Daha Fazla">
                                                            <i class="dashicons dashicons-ellipsis"></i>
                                                        </button>
                                                        <div class="table-action-dropdown">
                                                            <?php if (can_delete_items($current_user->ID)): ?>
                                                            <a href="?view=customers&action=delete&id=<?php echo esc_attr($customer->id); ?>" 
                                                               onclick="return confirm('Bu müşteriyi silmek istediğinizden emin misiniz? Bu işlem geri alınamaz.')" class="text-danger">
                                                                <?php esc_html_e('Sil', 'insurance-crm'); ?>
                                                            </a>
                                                            <?php else: ?>
                                                            <a href="?view=customers&action=deactivate&id=<?php echo esc_attr($customer->id); ?>" 
                                                               onclick="return confirm('Bu müşteriyi pasife almak istediğinizden emin misiniz?')" class="text-warning">
                                                                <?php esc_html_e('Pasife Al', 'insurance-crm'); ?>
                                                            </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon"><i class="dashicons dashicons-search"></i></div>
                                <h4><?php esc_html_e('Sonuç Bulunamadı', 'insurance-crm'); ?></h4>
                                <p><?php esc_html_e('Aradığınız kritere uygun bir sonuç bulunamadı.', 'insurance-crm'); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php elseif ($current_view == 'organization'): ?>
            <div class="main-content">
                <!-- Modern Header -->
                <div class="page-header-modern">
                    <div class="header-content">
                        <div class="header-left">
                            <h1><i class="fas fa-sitemap"></i> Organizasyon Yönetimi</h1>
                            <p class="header-subtitle">Genel organizasyon yapısını görüntüleyin ve yönetin</p>
                        </div>
                        <div class="header-right">
                            <a href="<?php echo generate_panel_url('dashboard'); ?>" class="btn-modern btn-secondary">
                                <i class="fas fa-arrow-left"></i> Dashboard'a Dön
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="dashboard-card">
                    <div class="card-body">
                        <div class="organization-management">
                            <h4>Yönetim Hiyerarşisi</h4>
                            
                            <?php
                            // Mevcut temsilciler ve rolleri al
                            $representatives = $wpdb->get_results(
                                "SELECT r.*, u.display_name, u.user_email 
                                 FROM {$wpdb->prefix}insurance_crm_representatives r 
                                 LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
                                 WHERE r.status = 'active'
                                 ORDER BY r.role ASC, u.display_name ASC"
                            );
                            
                            $current_hierarchy = get_management_hierarchy();
                            
                            // Mevcut rollerine göre temsilcileri ayır
                            $patrons = array_filter($representatives, function($rep) { return intval($rep->role) == 1; });
                            $managers = array_filter($representatives, function($rep) { return intval($rep->role) == 2; });
                            $assistants = array_filter($representatives, function($rep) { return intval($rep->role) == 3; });
                            $team_leaders = array_filter($representatives, function($rep) { return intval($rep->role) == 4; });
                            $representatives_only = array_filter($representatives, function($rep) { return intval($rep->role) == 5; });
                            
                            // Form işleme
                            $success_message = '';
                            $error_message = '';
                            
                            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_organization'])) {
                                // Güvenlik kontrolü
                                if (!has_full_admin_access($current_user->ID)) {
                                    $error_message = 'Bu işlem için yetkiniz bulunmuyor.';
                                } else {
                                    $new_hierarchy = [
                                        'patron_id' => intval($_POST['patron_id'] ?? 0),
                                        'manager_id' => intval($_POST['manager_id'] ?? 0),
                                        'assistant_manager_ids' => isset($_POST['assistant_manager_ids']) ? array_map('intval', (array)$_POST['assistant_manager_ids']) : []
                                    ];
                                    
                                    if (update_management_hierarchy($new_hierarchy)) {
                                        $success_message = 'Organizasyon yapısı başarıyla güncellendi.';
                                        $current_hierarchy = $new_hierarchy;
                                        
                                        // Rolleri güncelle
                                        foreach ($representatives as $rep) {
                                            $new_role = 5; // Varsayılan olarak temsilci
                                            
                                            if ($rep->id == $new_hierarchy['patron_id']) {
                                                $new_role = 1; // Patron
                                            } elseif ($rep->id == $new_hierarchy['manager_id']) {
                                                $new_role = 2; // Müdür
                                            } elseif (in_array($rep->id, $new_hierarchy['assistant_manager_ids'])) {
                                                $new_role = 3; // Müdür Yardımcısı
                                            }
                                            
                                            // Ekip lideri rolünü koruması için kontrol - teams tablosunu kontrol et
                                            if ($new_role == 5) { // Eğer diğer roller atanmamışsa ekip lideri olup olmadığını kontrol et
                                                $is_team_leader = false;
                                                $teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();
                                                foreach ($teams as $team) {
                                                    if ($team['leader_id'] == $rep->id) {
                                                        $is_team_leader = true;
                                                        break;
                                                    }
                                                }
                                                
                                                if ($is_team_leader) {
                                                    $new_role = 4; // Ekip Lideri
                                                }
                                            }
                                            
                                            // Rol değişikliği varsa güncelle
                                            if ($rep->role != $new_role) {
                                                $wpdb->update(
                                                    $wpdb->prefix . 'insurance_crm_representatives',
                                                    ['role' => $new_role],
                                                    ['id' => $rep->id]
                                                );
                                            }
                                        }
                                        
                                        // Sayfayı yenile
                                        echo '<meta http-equiv="refresh" content="1;url=' . generate_panel_url('organization') . '">';
                                    } else {
                                        $error_message = 'Organizasyon yapısı güncellenirken bir hata oluştu.';
                                    }
                                }
                            }
                            ?>
                            
                            <?php if ($success_message): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($error_message): ?>
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error_message; ?>
                                </div>
                            <?php endif; ?>
                            
                            <form action="<?php echo generate_panel_url('organization'); ?>" method="post" class="organization-form">
                                <div class="org-chart">
                                    <div class="org-level">
                                        <div class="org-box patron-box">
                                            <div class="org-title">Patron</div>
                                            <select name="patron_id" class="org-select">
                                                <option value="0">Seçiniz</option>
                                                <?php foreach ($representatives as $rep): ?>
                                                    <option value="<?php echo $rep->id; ?>" <?php selected($current_hierarchy['patron_id'], $rep->id); ?>>
                                                        <?php echo esc_html($rep->display_name); ?> (<?php echo esc_html($rep->title); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="org-connector"></div>
                                    
                                    <div class="org-level">
                                        <div class="org-box manager-box">
                                            <div class="org-title">Müdür</div>
                                            <select name="manager_id" class="org-select">
                                                <option value="0">Seçiniz</option>
                                                <?php foreach ($representatives as $rep): ?>
                                                    <option value="<?php echo $rep->id; ?>" <?php selected($current_hierarchy['manager_id'], $rep->id); ?>>
                                                        <?php echo esc_html($rep->display_name); ?> (<?php echo esc_html($rep->title); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="org-connector"></div>
                                    
                                    <div class="org-level">
                                        <div class="org-box assistant-manager-box">
                                            <div class="org-title">Müdür Yardımcıları</div>
                                            <select name="assistant_manager_ids[]" class="org-select" multiple size="3">
                                                <?php foreach ($representatives as $rep): ?>
                                                    <option value="<?php echo $rep->id; ?>" <?php if (in_array($rep->id, $current_hierarchy['assistant_manager_ids'])) echo 'selected'; ?>>
                                                        <?php echo esc_html($rep->display_name); ?> (<?php echo esc_html($rep->title); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="helper-text">Ctrl tuşu ile birden fazla seçim yapabilirsiniz.</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="org-actions">
                                    <button type="submit" name="update_organization" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Organizasyon Yapısını Kaydet
                                    </button>
                                    <a href="<?php echo generate_panel_url('dashboard'); ?>" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> Vazgeç
                                    </a>
                                </div>
                            </form>
                            
                            <div class="team-management">
                                <h4>Ekip Yönetimi</h4>
                                <div class="teams-container">
                                    <?php 
                                    $teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();
                                    if (!empty($teams)):
                                    ?>
                                    <div class="team-cards">
                                        <?php foreach ($teams as $team_id => $team): 
                                            $leader = null;
                                            foreach ($representatives as $rep) {
                                                if ($rep->id == $team['leader_id']) {
                                                    $leader = $rep;
                                                    break;
                                                }
                                            }
                                            if (!$leader) continue;
                                            
                                            $member_count = count($team['members']);
                                        ?>
                                        <div class="team-card">
                                            <div class="team-card-header">
                                                <h5><?php echo esc_html($team['name']); ?></h5>
                                                <div class="team-actions">
                                                    <a href="<?php echo generate_panel_url('edit_team', '', '', array('team_id' => $team_id)); ?>" class="btn-sm btn-outline">
                                                        <i class="fas fa-edit"></i> Düzenle
                                                    </a>
                                                </div>
                                            </div>
                                            <div class="team-card-body">
                                                <div class="team-leader">
                                                    <div class="member-avatar">
                                                        <?php if (!empty($leader->avatar_url)): ?>
                                                            <img src="<?php echo esc_url($leader->avatar_url); ?>" alt="<?php echo esc_attr($leader->display_name); ?>">
                                                        <?php else: ?>
                                                            <?php echo esc_html(substr($leader->display_name, 0, 2)); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="member-details">
                                                        <div class="member-name"><?php echo esc_html($leader->display_name); ?></div>
                                                        <div class="member-title"><?php echo esc_html($leader->title); ?></div>
                                                        <div class="member-role">Ekip Lideri</div>
                                                    </div>
                                                </div>
                                                <div class="team-members-count">
                                                    <i class="fas fa-users"></i> <?php echo $member_count; ?> Üye
                                                </div>
                                                <a href="<?php echo generate_team_detail_url($team_id); ?>" class="team-detail-link">
                                                    Ekip Detayı <i class="fas fa-chevron-right"></i>
                                                </a>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                        
                                        <div class="team-card add-team-card">
                                            <a href="<?php echo generate_panel_url('team_add'); ?>">
                                                <div class="add-team-icon">
                                                    <i class="fas fa-plus"></i>
                                                </div>
                                                <div class="add-team-text">Yeni Ekip Oluştur</div>
                                            </a>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div class="empty-state">
                                        <div class="empty-icon">
                                            <i class="fas fa-users"></i>
                                        </div>
                                        <h4>Henüz ekip oluşturulmadı</h4>
                                        <p>Yeni bir ekip oluşturmak için aşağıdaki butona tıklayın.</p>
                                        <a href="<?php echo generate_panel_url('team_add'); ?>" class="btn btn-primary">
                                            <i class="fas fa-plus"></i> Yeni Ekip Oluştur
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($current_view == 'representative_add'): ?>
            <?php include_once(dirname(__FILE__) . '/representative_add.php'); ?>
        <?php elseif ($current_view == 'team_add'): ?>
            <?php include_once(dirname(__FILE__) . '/team_add.php'); ?>
        <?php elseif ($current_view == 'boss_settings'): ?>
            <?php include_once(dirname(__FILE__) . '/boss_settings.php'); ?>
        <?php elseif ($current_view == 'patron_dashboard'): ?>
            <?php include_once(dirname(__FILE__) . '/patron_dashboard.php'); ?>
        <?php elseif ($current_view == 'all_personnel'): ?>
            <?php include_once(dirname(__FILE__) . '/all_personnel.php'); ?>
        <?php elseif ($current_view == 'representative_detail' || $current_view == 'team_detail'): ?>
            <?php 
            if ($current_view == 'representative_detail') {
                include_once(dirname(__FILE__) . '/representative_detail.php');
            } elseif ($current_view == 'team_detail') {
                include_once(dirname(__FILE__) . '/team_detail.php');
            }
            ?>
        <?php elseif ($current_view == 'edit_representative'): ?>
            <?php include_once(dirname(__FILE__) . '/edit_representative.php'); ?>
        <?php elseif ($current_view == 'edit_team'): ?>
            <?php include_once(dirname(__FILE__) . '/edit_team.php'); ?>
        <?php elseif ($current_view == 'all_teams'): ?>
            <?php include_once(dirname(__FILE__) . '/all_teams.php'); ?>
        <?php elseif ($current_view == 'customers' || $current_view == 'team_customers'): ?>
            <?php include_once(dirname(__FILE__) . '/customers.php'); ?>
        <?php elseif ($current_view == 'policies' || $current_view == 'team_policies'): ?>
            <?php include_once(dirname(__FILE__) . '/policies.php'); ?>
        <?php elseif ($current_view == 'offers' || $current_view == 'offers'): ?>
            <?php include_once(dirname(__FILE__) . '/offers.php'); ?>
        <?php elseif ($current_view == 'helpdesk' || $current_view == 'helpdesk'): ?>
            <?php include_once(dirname(__FILE__) . '/helpdesk.php'); ?>
        <?php elseif ($current_view == 'license-management'): ?>
            <?php include_once(dirname(__FILE__) . '/license-management.php'); ?>
        <?php elseif ($current_view == 'tasks' || $current_view == 'team_tasks'): ?>
            <?php include_once(dirname(__FILE__) . '/tasks.php'); ?>
        <?php elseif ($current_view == 'helpdesk'): ?>
            <?php include_once(dirname(__FILE__) . '/helpdesk.php'); ?>
        <?php elseif ($current_view == 'reports' || $current_view == 'team_reports'): ?>
            <?php include_once(dirname(__FILE__) . '/reports.php'); ?>
        <?php elseif ($current_view == 'settings'): ?>
            <?php include_once(dirname(__FILE__) . '/settings.php'); ?>
        <?php elseif ($current_view == 'notifications'): ?>
            <?php include_once(dirname(__FILE__) . '/notifications.php'); ?>
        <?php elseif ($current_view == 'veri_aktar'): ?>
            <div class="main-content">
                <?php include_once(dirname(__FILE__) . '/veri_aktar.php'); ?>
            </div>
        <?php elseif ($current_view == 'veri_aktar_facebook'): ?>
            <div class="main-content">
                <?php include_once(dirname(__FILE__) . '/veri_aktar_facebook.php'); ?>
            </div>
        <?php elseif ($current_view == 'iceri_aktarim'): ?>
            <?php include_once(dirname(__FILE__) . '/importx.php'); ?>
        <?php elseif ($current_view == 'iceri_aktarim_new'): ?>
            <div class="main-content">
                <?php include_once(dirname(__FILE__) . '/iceri_aktarim_new.php'); ?>
            </div>
        <?php elseif ($current_view == 'import-system'): ?>
            <div class="main-content">
                <?php include_once(dirname(__FILE__) . '/iceri_aktarim.php'); ?>
            </div>

        <?php endif; ?>

        <style>

<?php
// Ayarları al
$settings = get_option('insurance_crm_settings', array());
$sidebar_color = isset($settings['site_appearance']['sidebar_color']) ? $settings['site_appearance']['sidebar_color'] : '#1e293b';
?>

:root {
    --sidebar-color: <?php echo esc_attr($sidebar_color); ?>;
}

            /* Lisans uyarı stilleri */
            .license-warning, .license-error {
                display: flex;
                align-items: center;
                padding: 16px 20px;
                margin-bottom: 20px;
                border-radius: 8px;
                font-size: 14px;
                font-weight: 500;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
                animation: slideDown 0.3s ease-out;
            }

            /* Renewal Policies Styling */
            .renewal-policies-card {
                margin-bottom: 30px;
            }

            .renewal-policies-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
                gap: 20px;
                padding: 15px 0;
            }

            .renewal-policy-item {
                background: #ffffff;
                border-radius: 12px;
                padding: 20px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
                border-left: 5px solid #28a745;
                transition: all 0.3s ease;
            }

            .renewal-policy-item:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
            }

            .renewal-policy-item.warning {
                border-left-color: #ffc107;
            }

            .renewal-policy-item.urgent {
                border-left-color: #dc3545;
            }

            .renewal-policy-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 15px;
                padding-bottom: 12px;
                border-bottom: 1px solid #e9ecef;
            }

            .renewal-policy-header .policy-info h4 {
                margin: 0;
                font-size: 16px;
                font-weight: 600;
                color: #2c3e50;
            }

            .renewal-policy-header .policy-type {
                font-size: 12px;
                background: #f8f9fa;
                padding: 4px 8px;
                border-radius: 4px;
                color: #6c757d;
                font-weight: 500;
            }

            .days-remaining {
                text-align: center;
            }

            .days-remaining .days {
                background: linear-gradient(135deg, #28a745, #20c997);
                color: white;
                padding: 8px 12px;
                border-radius: 20px;
                font-weight: 600;
                font-size: 14px;
            }

            .renewal-policy-item.warning .days-remaining .days {
                background: linear-gradient(135deg, #ffc107, #fd7e14);
            }

            .renewal-policy-item.urgent .days-remaining .days {
                background: linear-gradient(135deg, #dc3545, #c82333);
            }

            .days-remaining .expired {
                background: #6c757d;
                color: white;
                padding: 8px 12px;
                border-radius: 20px;
                font-weight: 600;
                font-size: 14px;
            }

            .renewal-policy-details {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }

            .renewal-policy-details > div {
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 14px;
                color: #495057;
            }

            .renewal-policy-details i {
                color: #007cba;
                font-size: 13px;
                width: 16px;
                text-align: center;
            }

            .renewal-action-btn {
                background: linear-gradient(135deg, #007cba, #0056b3);
                color: white;
                border: none;
                padding: 10px 16px;
                border-radius: 20px;
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 6px;
            }

            .renewal-action-btn:hover {
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(0, 124, 186, 0.3);
                color: white;
                text-decoration: none;
            }

            @media (max-width: 768px) {
                .renewal-policies-grid {
                    grid-template-columns: 1fr;
                }
                
                .renewal-policy-details {
                    grid-template-columns: 1fr;
                    gap: 8px;
                }
            }

            /* View Toggle Buttons */
            .view-toggle-buttons {
                display: flex;
                gap: 5px;
                margin-right: 15px;
                border: 1px solid #ddd;
                border-radius: 6px;
                overflow: hidden;
            }

            .toggle-btn {
                background: #f8f9fa;
                border: none;
                padding: 8px 12px;
                cursor: pointer;
                transition: all 0.2s ease;
                color: #666;
                font-size: 14px;
            }

            .toggle-btn:hover {
                background: #e9ecef;
                color: #333;
            }

            .toggle-btn.active {
                background: #007cba;
                color: white;
            }

            /* Renewal Policies Table */
            .renewal-policies-table {
                margin-top: 15px;
            }

            .renewal-policies-table .data-table {
                width: 100%;
                border-collapse: collapse;
                background: white;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            }

            .renewal-policies-table .data-table th,
            .renewal-policies-table .data-table td {
                padding: 12px;
                text-align: left;
                border-bottom: 1px solid #eee;
            }

            .renewal-policies-table .data-table th {
                background: #f8f9fa;
                font-weight: 600;
                color: #333;
            }

            .renewal-policies-table .data-table tr:hover {
                background: #f8f9fa;
            }

            .renewal-policies-table .urgent-row {
                background: #ffebee;
            }

            .renewal-policies-table .warning-row {
                background: #fff8e1;
            }

            .policy-preview-link {
                color: #007cba;
                text-decoration: none;
                font-weight: 500;
            }

            .policy-preview-link:hover {
                text-decoration: underline;
            }

            .expired-badge {
                background: #dc3545;
                color: white;
                padding: 4px 8px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 500;
            }

            .days-badge {
                background: #28a745;
                color: white;
                padding: 4px 8px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 500;
            }

            .license-warning {
                background-color: #fff3cd;
                border: 1px solid #ffeaa7;
                color: #856404;
            }

            .license-error {
                background-color: #f8d7da;
                border: 1px solid #f5c6cb;
                color: #721c24;
            }

            .license-warning i, .license-error i {
                font-size: 18px;
                margin-right: 12px;
                flex-shrink: 0;
            }

            .license-warning i {
                color: #ffc107;
            }

            .license-error i {
                color: #dc3545;
            }

            .license-notice-content {
                flex: 1;
                line-height: 1.5;
            }

            @keyframes slideDown {
                from {
                    opacity: 0;
                    transform: translateY(-10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            @media (max-width: 768px) {
                .license-warning, .license-error {
                    padding: 12px 16px;
                    font-size: 13px;
                }
                
                .license-warning i, .license-error i {
                    font-size: 16px;
                    margin-right: 10px;
                }
            }



            .insurance-crm-page * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }
            
            body.insurance-crm-page {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                background-color: #f5f7fa;
                color: #333;
                margin: 0;
                padding: 0;
                min-height: 50vh;
            }
            
            .insurance-crm-sidenav {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                width: 260px;
                background: var(--sidebar-color);
                color: #fff;
                display: flex;
                flex-direction: column;
                z-index: 1000;
                transition: all 0.3s ease;
            }
            
            .sidenav-header {
                padding: 20px;
                display: flex;
                align-items: center;
                border-bottom: 1px solid rgba(255,255,255,0.1);
            }
            
            .sidenav-logo {
                width: 40px;
                height: 40px;
                margin-right: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .sidenav-logo img {
                max-width: 100%;
                max-height: 100%;
            }
            
            .sidenav-header h3 {
                font-weight: 600;
                font-size: 18px;
                color: #fff;
            }
            
            .sidenav-user {
                padding: 20px;
                display: flex;
                align-items: center;
                border-bottom: 1px solid rgba(255,255,255,0.1);
            }
            
            .user-avatar {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                overflow: hidden;
                margin-right: 12px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .user-initials {
                color: white;
                font-weight: 600;
                font-size: 16px;
                text-align: center;
                line-height: 1;
            }
            
            .user-avatar img {
                /* Avatar img kuralları kaldırıldı - sadece loader gösterilecek */
                display: none;
            }
            
            .user-info h4 {
                font-size: 14px;
                font-weight: 600;
                color: #fff;
                margin: 0;
            }
            
            .user-info span {
                font-size: 12px;
                color: rgba(255,255,255,0.7);
            }
            
            .user-role {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 10px;
                font-weight: 600;
                margin-top: 4px;
            }
            
            .patron-role {
                background: #4a148c;
                color: #fff;
            }
            
            .manager-role {
                background: #0d47a1;
                color: #fff;
            }
            
            .leader-role {
                background: #1b5e20;
                color: #fff;
            }
            
            .rep-role {
                background: #424242;
                color: #fff;
            }
            
            .role-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
            }
            
            .sidenav-menu {
                flex: 1;
                padding: 20px 0;
                overflow-y: auto;
            }
            
            .sidenav-menu a {
                display: flex;
                align-items: center;
                padding: 12px 20px;
                color: rgba(255,255,255,0.7);
                text-decoration: none;
                transition: all 0.2s ease;
            }
            
            .sidenav-menu a:hover {
                background: rgba(255,255,255,0.1);
                color: #fff;
            }
            
            .sidenav-menu a.active {
	        background: color-mix(in srgb, var(--sidebar-color) 80%, white 20%);
                color: #fff;
                border-right: 3px solid #fff;
            }
            
            .sidenav-menu a .dashicons {
                margin-right: 12px;
                font-size: 18px;
                width: 18px;
                height: 18px;
            }
            
            .sidenav-submenu {
                padding: 0;
            }
            
            .sidenav-submenu > a {
                font-weight: 600;
                position: relative;
                cursor: pointer;
                transition: background-color 0.2s ease;
            }
            
            .sidenav-submenu > a:hover {
                background: rgba(255,255,255,0.05);
            }
            
            .sidenav-submenu > a:focus {
                outline: 2px solid rgba(255,255,255,0.5);
                outline-offset: 2px;
            }
            
            .sidenav-submenu > a::after {
                content: '\f140';
                font-family: dashicons;
                position: absolute;
                right: 20px;
                top: 50%;
                transform: translateY(-50%);
                transition: transform 0.3s ease;
                font-size: 16px;
            }
            
            .sidenav-submenu.active > a {
                background: rgba(255,255,255,0.1);
            }
            
            .sidenav-submenu.active > a::after {
                transform: translateY(-50%) rotate(180deg);
            }
            
            /* Option 1: Click-to-expand dropdowns (current implementation) */
            .submenu-items {
                padding-left: 20px;
                background: color-mix(in srgb, var(--sidebar-color) 90%, black 10%);
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.3s ease, opacity 0.3s ease;
                opacity: 0;
            }
            
            /* Option 2: Always visible submenus (uncomment to enable) */
            /*
            .submenu-items {
                padding-left: 20px;
                background: color-mix(in srgb, var(--sidebar-color) 90%, black 10%);
                max-height: none !important;
                opacity: 1 !important;
                overflow: visible !important;
            }
            .sidenav-submenu > a::after {
                display: none !important;
            }
            */
            
            .sidenav-submenu.active .submenu-items {
                max-height: 300px !important;
                opacity: 1 !important;
            }
            
            .submenu-items a {
                padding: 10px 20px;
                font-size: 14px;
                transition: background-color 0.2s ease, padding-left 0.2s ease;
            }
            
            .submenu-items a:hover,
            .submenu-items a:focus {
                background: rgba(255,255,255,0.1);
                padding-left: 25px;
                outline: none;
            }
            
            .submenu-items a:focus {
                box-shadow: inset 3px 0 0 rgba(255,255,255,0.8);
            }
            
            .submenu-items a .dashicons {
                margin-right: 10px;
                font-size: 16px;
                width: 16px;
                height: 16px;
            }
            
            .sidenav-footer {
                padding: 20px;
                border-top: 1px solid rgba(255,255,255,0.1);
            }
            
            .logout-button {
                display: flex;
                align-items: center;
                color: rgba(255,255,255,0.7);
                padding: 10px;
                border-radius: 4px;
                text-decoration: none;
                transition: all 0.2s ease;
            }
            
            .logout-button:hover {
                background: rgba(255,255,255,0.1);
                color: #fff;
            }
            
            .logout-button .dashicons {
                margin-right: 8px;
                font-size: 16px;
                width: 16px;
                height: 16px;
            }
            
            .insurance-crm-main {
                margin-left: 260px;
                min-height: 100vh;
                background: #f5f7fa;
                transition: all 0.3s ease;
            }
            
            .main-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 15px 30px;
                background: #fff;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
                position: sticky;
                top: 0;
                z-index: 900;
            }
            
            .header-left {
                display: flex;
                align-items: center;
            }
            
            #sidenav-toggle {
                background: none;
                border: none;
                color: #555;
                font-size: 20px;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 5px;
                margin-right: 15px;
            }
            
            .header-left h2 {
                font-size: 18px;
                font-weight: 600;
                color: #333;
                margin: 0;
            }
            
            .header-branding {
                display: flex;
                align-items: center;
                margin-right: 15px;
            }
            
            .company-logo {
                width: 32px;
                height: 32px;
                background: #f0f0f0;
                border: 1px solid #ddd;
                border-radius: 4px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 12px;
                color: #666;
                margin-right: 8px;
            }
            
            .company-name {
                font-size: 16px;
                font-weight: 600;
                color: #000;
            }
            
            /* CRM Header Styles */
            .crm-header {
                background: #ffffff;
                border-radius: 12px;
                padding: 24px;
                margin-bottom: 24px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
                border: 1px solid #e5e7eb;
            }
            
            .header-content {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .title-section {
                display: flex;
                align-items: center;
                gap: 16px;
            }
            
            .page-title {
                display: flex;
                align-items: center;
                gap: 12px;
            }
            
            .page-title i {
                font-size: 24px;
                color: #1976d2;
            }
            
            .page-title h1 {
                margin: 0;
                font-size: 24px;
                font-weight: 600;
                color: #1c1b1f;
                line-height: 1.2;
            }
            
            .title-section {
                display: flex;
                align-items: center;
                gap: 16px;
                flex-wrap: wrap;
            }
            
            .version-badge {
                background: linear-gradient(135deg, #2196f3, #1976d2);
                color: white;
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
                letter-spacing: 0.5px;
            }
            
            .user-badge {
                display: flex;
                align-items: center;
            }
            
            .role-badge {
                background: linear-gradient(135deg, #1976d2, #1565c0);
                color: white;
                padding: 6px 14px;
                border-radius: 20px;
                font-size: 13px;
                font-weight: 500;
                display: flex;
                align-items: center;
                gap: 6px;
            }
            
            /* Button Styles */
            .btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 8px 16px;
                border: 1px solid transparent;
                border-radius: 6px;
                font-size: 14px;
                font-weight: 500;
                text-decoration: none;
                cursor: pointer;
                transition: all 0.2s ease;
                white-space: nowrap;
            }
            
            .btn-primary {
                background: #1976d2;
                color: white;
                border-color: #1976d2;
            }
            
            .btn-primary:hover {
                background: #1565c0;
                border-color: #1565c0;
                color: white;
            }
            
            .btn-outline {
                background: transparent;
                color: #1976d2;
                border-color: #1976d2;
            }
            
            .btn-outline:hover {
                background: #1976d2;
                color: white;
            }
            
            .admin-buttons {
                display: flex;
                gap: 8px;
                align-items: center;
            }
            
            .header-actions {
                display: flex;
                align-items: center;
            }
            
            .header-right {
                display: flex;
                align-items: center;
            }
            
            .search-box {
                position: relative;
                margin-right: 20px;
            }
            
            .search-box form {
                display: flex;
                align-items: center;
                position: relative;
            }
            
            .search-box input {
                padding: 8px 15px 8px 35px;
                border: 1px solid #e0e0e0;
                border-radius: 20px 0 0 20px;
                width: 250px;
                font-size: 14px;
                transition: all 0.3s;
                border-right: none;
            }
            
            .search-box input:focus {
                width: 300px;
                border-color: #0073aa;
                box-shadow: 0 0 0 2px rgba(0,115,170,0.2);
                outline: none;
            }
            
            .search-box .dashicons {
                position: absolute;
                left: 12px;
                top: 50%;
                transform: translateY(-50%);
                color: #666;
                z-index: 2;
            }
            
            .search-button {
                padding: 8px 15px;
                background: #0073aa;
                color: white;
                border: 1px solid #0073aa;
                border-radius: 0 20px 20px 0;
                font-size: 12px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s;
                border-left: none;
            }
            
            .search-button:hover {
                background: #005a8c;
                border-color: #005a8c;
            }
            
            .help-icon {
                background: none;
                border: none;
                padding: 8px;
                margin-left: 10px;
                border-radius: 50%;
                cursor: pointer;
                transition: all 0.2s ease;
                color: #666;
                font-size: 16px;
            }
            
            .help-icon:hover {
                background: #f0f0f0;
                color: #1976d2;
            }
            
            /* Help Popup Modal */
            .help-modal {
                display: none;
                position: fixed;
                z-index: 10000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0,0,0,0.5);
                animation: fadeIn 0.3s ease;
            }
            
            .help-modal-content {
                background-color: #fefefe;
                margin: 5% auto;
                padding: 0;
                border-radius: 12px;
                width: 90%;
                max-width: 600px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.3);
                animation: slideIn 0.3s ease;
                overflow: hidden;
            }
            
            .help-modal-header {
                background: linear-gradient(135deg, #1976d2, #1565c0);
                color: white;
                padding: 20px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .help-modal-header h3 {
                margin: 0;
                font-size: 20px;
                font-weight: 600;
            }
            
            .help-close {
                background: none;
                border: none;
                font-size: 24px;
                color: white;
                cursor: pointer;
                border-radius: 50%;
                width: 32px;
                height: 32px;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: background 0.2s ease;
            }
            
            .help-close:hover {
                background: rgba(255,255,255,0.2);
            }
            
            .help-modal-body {
                padding: 25px;
                line-height: 1.6;
            }
            
            .help-section {
                margin-bottom: 20px;
            }
            
            .help-section h4 {
                color: #1976d2;
                margin-bottom: 10px;
                font-weight: 600;
            }
            
            .help-section ul {
                list-style-type: none;
                padding-left: 0;
            }
            
            .help-section li {
                margin-bottom: 8px;
                padding-left: 20px;
                position: relative;
            }
            
            .help-section li:before {
                content: '•';
                color: #1976d2;
                position: absolute;
                left: 0;
                font-weight: bold;
            }
            
            .help-form-section {
                background: #f8f9fa;
                border-radius: 8px;
                padding: 20px;
                margin-top: 20px;
            }
            
            .help-form-section h4 {
                margin-top: 0;
                color: #333;
            }
            
            .help-form-section textarea {
                width: 100%;
                height: 100px;
                border: 1px solid #ddd;
                border-radius: 6px;
                padding: 10px;
                font-family: monospace;
                font-size: 14px;
                resize: vertical;
                margin-bottom: 10px;
            }
            
            .help-form-section button {
                background: #1976d2;
                color: white;
                border: none;
                padding: 10px 20px;
                border-radius: 6px;
                cursor: pointer;
                font-weight: 500;
                margin-right: 10px;
            }
            
            .help-form-section button:hover {
                background: #1565c0;
            }
            
            .help-form-result {
                background: white;
                border: 1px solid #ddd;
                border-radius: 6px;
                padding: 15px;
                margin-top: 10px;
                min-height: 50px;
                display: none;
            }
            
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            
            @keyframes slideIn {
                from { transform: translateY(-50px); opacity: 0; }
                to { transform: translateY(0); opacity: 1; }
            }

            .dashboard-header-container {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 20px;
            }
            
            .dashboard-header {
                flex: 1;
            }
            
            .performance-date-filter {
                background-color: #fff;
                border-radius: 10px;
                padding: 15px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.08);
                min-width: 320px;
                max-width: 400px;
                align-self: flex-start;
            }
            
            .performance-stats-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 20px;
                margin-bottom: 30px;
            }
            
            .notification-bell {
                position: relative;
                margin-right: 20px;
            }
            
            .notification-bell a {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 40px;
                height: 40px;
                border-radius: 20px;
                color: #555;
                transition: all 0.2s;
            }
            
            .notification-bell a:hover {
                background: #f0f0f0;
                color: #333;
            }
            
            .notification-badge {
                position: absolute;
                top: 5px;
                right: 5px;
                background: #dc3545;
                color: #fff;
                border-radius: 10px;
                min-width: 18px;
                height: 18px;
                font-size: 11px;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 0 6px;
            }
            
            .notifications-dropdown {
                position: absolute;
                top: 100%;
                right: 0;
                width: 320px;
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.15);
                margin-top: 10px;
                display: none;
                overflow: hidden;
                z-index: 1000;
            }
            
            .notifications-dropdown.show {
                display: block;
            }
            
            .notifications-header {
                padding: 15px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: 1px solid #eee;
            }
            
            .notifications-header h3 {
                font-size: 16px;
                font-weight: 600;
                margin: 0;
            }
            
            .notifications-list {
                max-height: 300px;
                overflow-y: auto;
            }
            
            .notification-item {
                display: flex;
                padding: 12px 15px;
                border-bottom: 1px solid #eee;
                transition: background 0.2s;
            }
            
            .notification-item:hover {
                background: #f9f9f9;
            }
            
            .notification-item.unread {
                background: #f0f7ff;
            }
            
            .notification-item .dashicons {
                margin-right: 12px;
                font-size: 20px;
                color: #0073aa;
            }
            
            .notification-content {
                flex: 1;
            }
            
            .notification-content p {
                margin: 0 0 5px;
                font-size: 14px;
                color: #333;
            }
            
            .notification-time {
                font-size: 12px;
                color: #777;
            }
            
            .notifications-footer {
                padding: 15px 10px;
                text-align: center;
                border-top: 1px solid #eee;
            }

            .notifications-footer a {
                display: block;
                width: 100%;
                padding: 8px 0;
                margin: 10px 0;
                text-decoration: none;
                color: #333;
                border-radius: 4px;
                font-size: 14px;
                letter-spacing: 0.5px;
                box-sizing: border-box;
            }

            .notifications-footer a:hover {
                background-color: #f5f5f5;
            }

            .notifications-list {
                margin-bottom: 15px;
            }

            .notification-item {
                padding: 10px;
                margin-bottom: 5px;
            }
            
            .quick-actions {
                position: relative;
            }
            
            .quick-add-btn {
                display: flex;
                align-items: center;
                background: #0073aa;
                color: #fff;
                border: none;
                padding: 8px 15px;
                border-radius: 4px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s ease;
            }
            
            .quick-add-btn:hover {
                background: #005a87;
            }
            
            .quick-add-btn .dashicons {
                margin-right: 5px;
            }
            
            .quick-add-dropdown {
                position: absolute;
                top: 100%;
                right: 0;
                width: 200px;
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.15);
                margin-top: 10px;
                display: none;
                overflow: hidden;
                z-index: 1000;
            }
            .quick-add-dropdown.show {
                display: block;
            }
            
            .quick-add-dropdown a {
                display: flex;
                align-items: center;
                padding: 12px 15px;
                color: #333;
                text-decoration: none;
                transition: background 0.2s;
            }
            
            .quick-add-dropdown a:hover {
                background: #f5f5f5;
            }
            
            .quick-add-dropdown a .dashicons {
                margin-right: 10px;
                color: #0073aa;
            }
            
            .main-content {
                padding: 30px;
            }
            
            /* Enhanced Dashboard Header Styles */
            .dashboard-header-section {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border-radius: 16px;
                padding: 32px;
                margin-bottom: 32px;
                box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
                border: 1px solid rgba(255, 255, 255, 0.1);
                position: relative;
                overflow: hidden;
            }
            
            .dashboard-header-section::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: linear-gradient(135deg, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0.05) 100%);
                pointer-events: none;
            }
            
            .dashboard-header-content {
                position: relative;
                z-index: 1;
                display: flex;
                justify-content: space-between;
                align-items: center;
                color: white;
            }
            
            .dashboard-title-section {
                display: flex;
                align-items: center;
                gap: 20px;
            }
            
            .dashboard-title-icon {
                width: 64px;
                height: 64px;
                background: rgba(255, 255, 255, 0.2);
                border-radius: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
                backdrop-filter: blur(10px);
                border: 1px solid rgba(255, 255, 255, 0.3);
            }
            
            .dashboard-title-icon i {
                font-size: 28px;
                color: white;
            }
            
            .dashboard-title-content h1 {
                margin: 0;
                font-size: 32px;
                font-weight: 700;
                color: white;
                line-height: 1.2;
                display: flex;
                align-items: center;
                gap: 16px;
            }
            
            .dashboard-version-badge {
                background: rgba(255, 255, 255, 0.25);
                color: white;
                padding: 6px 16px;
                border-radius: 20px;
                font-size: 14px;
                font-weight: 600;
                letter-spacing: 0.5px;
                backdrop-filter: blur(10px);
                border: 1px solid rgba(255, 255, 255, 0.3);
            }
            
            .dashboard-subtitle {
                margin: 8px 0 0 0;
                font-size: 16px;
                color: rgba(255, 255, 255, 0.9);
                font-weight: 400;
                line-height: 1.4;
            }
            
            .dashboard-header-actions {
                display: flex;
                align-items: center;
                gap: 24px;
            }
            
            .dashboard-user-role {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 12px 20px;
                background: rgba(255, 255, 255, 0.15);
                border-radius: 30px;
                backdrop-filter: blur(10px);
                border: 1px solid rgba(255, 255, 255, 0.2);
            }
            
            .dashboard-user-role i {
                font-size: 18px;
                color: white;
            }
            
            .dashboard-user-role span {
                font-weight: 600;
                color: white;
                font-size: 14px;
            }
            
            .dashboard-action-buttons {
                display: flex;
                gap: 12px;
            }
            
            .dashboard-action-btn {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 12px 20px;
                background: rgba(255, 255, 255, 0.1);
                border: 1px solid rgba(255, 255, 255, 0.2);
                border-radius: 10px;
                color: white;
                text-decoration: none;
                font-weight: 500;
                transition: all 0.3s ease;
                backdrop-filter: blur(10px);
            }
            
            .dashboard-action-btn:hover {
                background: rgba(255, 255, 255, 0.2);
                border-color: rgba(255, 255, 255, 0.4);
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                color: white;
            }
            
            .dashboard-action-btn i {
                font-size: 16px;
            }
            
            /* Dashboard Header Styles */
            .dashboard-header {
                margin-bottom: 20px;
            }
            
            .dashboard-header h3 {
                font-size: 24px;
                font-weight: 600;
                color: #333;
                margin-bottom: 5px;
            }
            
            .dashboard-subtitle {
                font-size: 16px;
                color: #666;
            }
            
            /* Tarih Filtresi Stilleri */
            .filter-group {
                margin-bottom: 15px;
            }
            
            .filter-group label {
                display: block;
                font-weight: 600;
                margin-bottom: 8px;
                color: #333;
            }
            
            .filter-buttons {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }
            
            .filter-btn {
                background: #f0f2f5;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 6px 12px;
                font-size: 14px;
                color: #333;
                cursor: pointer;
                transition: all 0.2s;
                text-decoration: none;
                display: inline-block;
            }
            
            .filter-btn:hover {
                background: #e4e6e9;
                border-color: #ccc;
            }
            
            .filter-btn.active {
                background: #0073aa;
                color: white;
                border-color: #005c88;
            }
            
            .custom-date-container {
                margin-top: 10px;
                display: flex;
                flex-direction: column;
                gap: 10px;
                background-color: #f9f9f9;
                padding: 15px;
                border-radius: 8px;
                border: 1px solid #eee;
            }
            
            .date-inputs {
                display: flex;
                flex-wrap: wrap;
                gap: 15px;
                align-items: flex-end;
            }
            
            .date-field {
                display: flex;
                flex-direction: column;
                gap: 5px;
                flex: 1;
            }
            
            .date-field label {
                font-size: 14px;
                color: #555;
            }
            
            .date-field input[type="date"] {
                padding: 8px 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 14px;
            }
            
            .submit-date-filter {
                background: #0073aa;
                color: white;
                border: none;
                border-radius: 4px;
                padding: 8px 16px;
                cursor: pointer;
                transition: background 0.2s;
                font-size: 14px;
            }
            
            .submit-date-filter:hover {
                background: #005c88;
            }
            
            .current-filter {
                margin-top: 10px;
                padding-top: 10px;
                border-top: 1px solid #eee;
                font-size: 14px;
                color: #666;
            }
            
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(5, 1fr);
                gap: 20px;
                margin-bottom: 30px;
            }
            
            .stat-box {
                background: white;
                border-radius: 10px;
                padding: 20px;
                display: flex;
                flex-direction: column;
                box-shadow: 0 1px 3px rgba(0,0,0,0.08);
                transition: transform 0.3s ease, box-shadow 0.3s ease;
            }
            
            .stat-box:hover {
                transform: translateY(-3px);
                box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            }
            
            .stat-icon {
                margin-bottom: 15px;
                width: 50px;
                height: 50px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .stat-icon .dashicons, .stat-icon .fas {
                font-size: 24px;
                color: white;
            }
            
            .customers-box .stat-icon {
                background: linear-gradient(135deg, #4e54c8, #8f94fb);
            }
            
            .policies-box .stat-icon {
                background: linear-gradient(135deg, #11998e, #38ef7d);
            }
            
            .production-box .stat-icon {
                background: linear-gradient(135deg, #F37335, #FDC830);
            }
            
            .target-box .stat-icon {
                background: linear-gradient(135deg, #536976, #292E49);
            }
            
            .top-producers-box .stat-icon {
                background: linear-gradient(135deg, #FF416C, #FF4B2B);
            }
            
            .top-new-business-box .stat-icon {
                background: linear-gradient(135deg, #6a11cb, #2575fc);
            }
            
            .top-new-customers-box .stat-icon {
                background: linear-gradient(135deg, #00b09b, #96c93d);
            }
            
            .top-cancellations-box .stat-icon {
                background: linear-gradient(135deg, #e44d26, #f16529);
            }
            
            .stat-details {
                margin-bottom: 15px;
            }
            
            .stat-value {
                font-size: 24px;
                font-weight: 700;
                margin-bottom: 5px;
                color: #333;
            }
            
            .stat-label {
                font-size: 14px;
                color: #666;
            }
            
            .stat-title {
                font-size: 16px;
                font-weight: 600;
                color: #333;
                margin-bottom: 15px;
            }
            
            .top-performers {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            
            .top-performer {
                display: flex;
                align-items: center;
                padding: 8px 10px;
                background: #f8f9fa;
                border-radius: 6px;
            }
            
            .rank {
                width: 24px;
                height: 24px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 50%;
                font-size: 12px;
                font-weight: bold;
                margin-right: 10px;
            }
            
            .top-performer:nth-child(1) .rank {
                background-color: #ffd700;
                color: #333;
            }
            
            .top-performer:nth-child(2) .rank {
                background-color: #c0c0c0;
                color: #333;
            }
            
            .top-performer:nth-child(3) .rank {
                background-color: #cd7f32;
                color: #fff;
            }
            
            .performer-info {
                flex: 1;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .performer-name {
                font-size: 14px;
                font-weight: 500;
            }
            
            .performer-value {
                font-size: 13px;
                font-weight: 600;
                color: #0073aa;
            }
            
            /* Birthday Stat Box Styling */
            .birthday-stat-box {
                background: linear-gradient(135deg, #ff6b6b 0%, <?php echo $secondary_color; ?> 100%);
                color: white;
                border: none;
                position: relative;
                overflow: hidden;
            }
            
            .birthday-stat-box .stat-icon {
                background: rgba(255, 255, 255, 0.2);
                color: white;
            }
            
            .birthday-stat-box .stat-value {
                color: white;
                font-size: 2.5rem;
                font-weight: 700;
                text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }
            
            .birthday-stat-box .stat-label {
                color: rgba(255, 255, 255, 0.9);
                font-weight: 600;
                text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
            }
            
            .birthday-preview {
                margin-top: 10px;
            }
            
            .birthday-names {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            
            .birthday-name {
                font-size: 12px;
                color: rgba(255, 255, 255, 0.9);
                font-weight: 500;
                text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            
            .birthday-more {
                font-size: 11px;
                color: rgba(255, 255, 255, 0.8);
                font-style: italic;
                text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
            }
            
            .birthday-empty {
                font-size: 12px;
                color: rgba(255, 255, 255, 0.8);
                font-style: italic;
                text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
            }
            
            /* New birthday details styles */
            .birthday-details-list {
                display: flex;
                flex-direction: column;
                gap: 8px;
                max-height: 300px;
                overflow-y: auto;
            }
            
            .birthday-detail-item {
                display: flex;
                flex-direction: column;
                gap: 2px;
                padding: 6px;
                background: rgba(255, 255, 255, 0.1);
                border-radius: 4px;
                border-left: 2px solid #ff6b6b;
            }
            
            .birthday-customer-name {
                font-size: 12px;
                color: rgba(255, 255, 255, 0.95);
                font-weight: 600;
                text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
            }
            
            .birthday-customer-age {
                font-size: 11px;
                color: rgba(255, 255, 255, 0.8);
                font-weight: 500;
            }
            
            .birthday-customer-phone {
                font-size: 11px;
                color: rgba(255, 255, 255, 0.8);
                font-family: monospace;
            }
            
            .birthday-customer-action {
                margin-top: 2px;
            }
            
            .birthday-mini-celebrate-btn {
                background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
                color: white;
                border: none;
                padding: 4px 8px;
                border-radius: 12px;
                font-size: 10px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s ease;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
            }
            
            .birthday-mini-celebrate-btn:hover {
                transform: translateY(-1px);
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3);
            }
            
            .birthday-mini-celebrate-btn:active {
                transform: translateY(0);
            }
            
            .birthday-stat-box::before {
                content: '';
                position: absolute;
                top: -50%;
                right: -50%;
                width: 100%;
                height: 100%;
                background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
                pointer-events: none;
            }
            
            /* Detailed Birthday Panel Styling */
            .birthday-detail-panel {
                border-radius: 15px;
                overflow: hidden;
                box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
                border: none;
                background: white;
            }
            
            .birthday-detail-panel .card-header {
                background: linear-gradient(135deg, <?php echo $primary_color; ?> 0%, <?php echo $sidebar_color; ?> 100%);
                color: white;
                border-radius: 0;
                padding: 25px;
                border: none;
            }
            
            .birthday-header-content {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 15px;
            }
            
            .birthday-title {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .birthday-detail-panel .card-header h3 {
                margin: 0;
                font-size: 20px;
                font-weight: 600;
                text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }
            
            .birthday-summary {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
            }
            
            .birthday-customers-list {
                display: flex;
                flex-direction: column;
                gap: 15px;
            }
            
            .birthday-customer-avatar {
                display: flex;
                align-items: center;
                margin-right: 15px;
            }
            
            .birthday-avatar-circle {
                width: 50px;
                height: 50px;
                border-radius: 50%;
                background: linear-gradient(135deg, <?php echo $primary_color; ?>, <?php echo $sidebar_color; ?>);
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-size: 20px;
                box-shadow: 0 4px 15px rgba(255, 107, 107, 0.3);
            }
            
            .birthday-pagination {
                display: flex;
                justify-content: center;
                align-items: center;
                gap: 10px;
                margin-top: 20px;
                padding: 20px 0;
            }
            
            .birthday-page-btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 10px 15px;
                background: #007cba;
                color: white;
                text-decoration: none;
                border-radius: 8px;
                font-weight: 500;
                transition: all 0.3s ease;
                border: none;
                cursor: pointer;
            }
            
            .birthday-page-btn:hover {
                background: #005a87;
                transform: translateY(-2px);
                box-shadow: 0 4px 15px rgba(0, 124, 186, 0.3);
                color: white;
                text-decoration: none;
            }
            
            .birthday-page-numbers {
                display: flex;
                gap: 5px;
            }
            
            .birthday-page-number {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 40px;
                height: 40px;
                background: #f8f9fa;
                color: #495057;
                text-decoration: none;
                border-radius: 8px;
                font-weight: 500;
                transition: all 0.3s ease;
                border: 2px solid transparent;
            }
            
            .birthday-page-number:hover {
                background: #e9ecef;
                color: #495057;
                text-decoration: none;
                transform: translateY(-1px);
            }
            
            .birthday-page-number.active {
                background: linear-gradient(135deg, <?php echo $primary_color; ?>, <?php echo $sidebar_color; ?>);
                color: white;
                border-color: #ff6b6b;
                box-shadow: 0 4px 15px rgba(255, 107, 107, 0.3);
            }
            
            @media (max-width: 768px) {
                .birthday-pagination {
                    flex-direction: column;
                    gap: 15px;
                }
                
                .birthday-page-numbers {
                    order: 2;
                }
                
                .birthday-prev-btn,
                .birthday-next-btn {
                    order: 1;
                }
            }
            
            .empty-performers {
                text-align: center;
                color: #666;
                padding: 10px;
                font-style: italic;
                font-size: 14px;
            }
            
            .stat-change {
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                font-size: 13px;
                margin-top: auto;
            }
            
            .stat-new {
                margin-bottom: 5px;
                color: #333;
            }
            
            .stat-rate.positive {
                display: flex;
                align-items: center;
                color: #28a745;
            }
            
            .stat-rate .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
                margin-right: 2px;
            }
            
            .stat-target {
                margin-top: auto;
            }
            
            .target-text {
                font-size: 13px;
                color: #666;
                margin-bottom: 5px;
            }
            
            .target-progress-mini {
                height: 5px;
                background: #e9ecef;
                border-radius: 3px;
                overflow: hidden;
                margin-bottom: 6px;
            }
            
            .target-bar {
                height: 100%;
                background: #4e54c8;
                border-radius: 3px;
                transition: width 1s ease-in-out;
            }
            
            .refund-info {
                font-size: 12px;
                color: #dc3545;
                margin-top: 5px;
            }
            
            .dashboard-grid {
                display: flex;
                flex-direction: column;
                gap: 20px;
                margin-bottom: 30px;
            }
            
            .upper-section {
                display: flex;
                flex-direction: row;
                gap: 20px;
                align-items: stretch;
                justify-content: space-between;
            }
            
            .dashboard-grid .upper-section .dashboard-card.chart-card {
                width: 65%;
                flex-shrink: 0;
            }
            
            .dashboard-grid .upper-section .dashboard-card.calendar-card {
                width: 35%;
                flex-shrink: 0;
            }
            
            .lower-section {
                display: flex;
                flex-direction: column;
                gap: 20px;
                width: 100%;
            }
            
            .dashboard-grid .lower-section .dashboard-card {
                width: 100%;
            }
            
            .dashboard-card {
                background: white;
                border-radius: 10px;
                overflow: hidden;
                box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            }
            
            .member-performance-card,
            .team-performance-card,
            .target-performance-card {
                margin-bottom: 20px;
            }
            
            .card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 16px 20px;
                border-bottom: 1px solid #f0f0f0;
            }
            
            .card-header h3 {
                font-size: 16px;
                font-weight: 600;
                color: #333;
                margin: 0;
            }
            
            .card-actions {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .card-option {
                background: none;
                border: none;
                color: #666;
                width: 28px;
                height: 28px;
                border-radius: 4px;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: all 0.2s;
                text-decoration: none;
            }
            
            .card-option:hover {
                background: #f5f5f5;
                color: #333;
            }
            
            .text-button {
                font-size: 13px;
                color: #0073aa;
                text-decoration: none;
                transition: color 0.2s;
            }
            
            .text-button:hover {
                color: #005a87;
                text-decoration: underline;
            }
            
            .card-body {
                padding: 20px;
            }
            
            .chart-container {
                height: 300px;
            }
            
            /* Organization Chart Styles */
            .org-chart {
                display: flex;
                flex-direction: column;
                align-items: center;
                margin: 20px 0;
            }
            
            .org-level {
                display: flex;
                justify-content: center;
                gap: 30px;
                width: 100%;
                margin-bottom: 10px;
            }
            
            .team-leaders-level {
                flex-wrap: wrap;
                gap: 15px;
            }
            
            .org-box {
                padding: 20px;
                border-radius: 8px;
                text-align: center;
                width: 260px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                background-color: #fff;
            }
            
            .patron-box {
                border: 2px solid #4a89dc;
                background-color: #f0f7ff;
            }
            
            .manager-box {
                border: 2px solid #e8864a;
                background-color: #fff5f0;
            }
            
            .assistant-manager-box {
                border: 2px solid #52acff;
                background-color: #f0f7ff;
                width: 100%;
                max-width: 500px;
            }
            
            .team-leader-box {
                background-color: #f0f8f0;
                border: 2px solid #5cb85c;
                margin-bottom: 15px;
            }
            
            .empty-box {
                background-color: #f9f9f9;
                border: 2px dashed #ccc;
            }
            
            .org-title {
                font-weight: bold;
                font-size: 16px;
                margin-bottom: 12px;
                color: #444;
            }
            
            .org-select {
                width: 100%;
                padding: 8px 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                background-color: #fff;
                font-size: 14px;
                color: #333;
            }
            
            .org-select[multiple] {
                height: 120px;
            }
            
            .helper-text {
                font-size: 12px;
                color: #666;
                margin-top: 8px;
                font-style: italic;
            }
            
            .org-name {
                font-size: 18px;
                font-weight: bold;
                margin-bottom: 2px;
            }
            
            .org-subtitle {
                font-style: italic;
                color: #666;
                font-size: 14px;
                margin-bottom: 5px;
            }
            
            .org-team-name {
                margin-top: 10px;
                font-weight: bold;
                color: #5cb85c;
            }
            
            .org-team-count {
                font-size: 12px;
                color: #777;
            }
            
            .org-connector {
                width: 2px;
                height: 30px;
                background-color: #999;
                margin: 5px 0;
            }
            
            .org-actions {
                display: flex;
                justify-content: center;
                gap: 15px;
                margin-top: 30px;
            }
            
            .org-actions .btn {
                padding: 10px 20px;
                font-size: 14px;
            }
            
            .team-management {
                margin-top: 50px;
            }
            
            .team-management h4 {
                font-size: 18px;
                margin-bottom: 20px;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            
            .team-cards {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 20px;
                margin-top: 20px;
            }
            
            .team-card {
                background-color: #fff;
                border-radius: 8px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                overflow: hidden;
                transition: transform 0.3s ease, box-shadow 0.3s ease;
            }
            
            .team-card:hover {
                transform: translateY(-3px);
                box-shadow: 0 4px 10px rgba(0,0,0,0.15);
            }
            
            .team-card-header {
                padding: 15px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                background-color: #f8f9fa;
                border-bottom: 1px solid #eee;
            }
            
            .team-card-header h5 {
                font-size: 16px;
                margin: 0;
                color: #333;
                font-weight: 600;
            }
            
            .team-card-body {
                padding: 15px;
            }
            
            .team-leader {
                display: flex;
                align-items: center;
                margin-bottom: 15px;
                padding-bottom: 15px;
                border-bottom: 1px solid #eee;
            }
            
            .member-avatar {
                width: 50px;
                height: 50px;
                border-radius: 25px;
                background-color: #e9ecef;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 18px;
                font-weight: 500;
                color: #495057;
                margin-right: 15px;
                overflow: hidden;
            }
            
            .member-avatar img {
                /* Avatar img kuralları kaldırıldı - sadece loader gösterilecek */
                display: none;
            }
            
            .member-details {
                flex: 1;
            }
            
            .member-name {
                font-weight: 500;
                font-size: 15px;
                color: #333;
            }
            
            .member-title {
                font-size: 13px;
                color: #666;
            }
            
            .member-role {
                font-size: 12px;
                color: #0073aa;
                margin-top: 2px;
            }
            
            .team-members-count {
                display: flex;
                align-items: center;
                color: #666;
                font-size: 14px;
                margin-bottom: 10px;
            }
            
            .team-members-count i {
                margin-right: 8px;
                color: #0073aa;
            }
            
            .team-detail-link {
                display: inline-block;
                color: #0073aa;
                text-decoration: none;
                font-size: 14px;
                margin-top: 10px;
                transition: color 0.2s;
            }
            
            .team-detail-link:hover {
                color: #005a87;
                text-decoration: underline;
            }
            
            .team-detail-link i {
                font-size: 10px;
                margin-left: 5px;
            }
            
            .add-team-card {
                display: flex;
                align-items: center;
                justify-content: center;
                border: 2px dashed #ddd;
                background-color: #f9f9f9;
                min-height: 200px;
                transition: all 0.3s ease;
            }
            
            .add-team-card:hover {
                border-color: #0073aa;
                background-color: #f0f7ff;
            }
            
            .add-team-card a {
                width: 100%;
                height: 100%;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                text-decoration: none;
                color: #666;
                padding: 30px;
            }
            
            .add-team-icon {
                width: 60px;
                height: 60px;
                border-radius: 30px;
                background-color: #e9ecef;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 15px;
            }
            
            .add-team-icon i {
                font-size: 24px;
                color: #0073aa;
            }
            
            .add-team-text {
                font-size: 16px;
                font-weight: 500;
                color: #333;
            }
            
            /* Alert Boxes */
            .alert {
                padding: 15px;
                border-radius: 8px;
                margin-bottom: 20px;
                display: flex;
                align-items: center;
            }
            
            .alert i {
                margin-right: 10px;
                font-size: 20px;
            }
            
            .alert-success {
                background-color: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }
            
            .alert-danger {
                background-color: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }

            /* Organization Management Page */
            .organization-form {
                margin-top: 20px;
            }

            .organization-management h4 {
                font-size: 18px;
                margin: 30px 0 15px;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
                color: #333;
            }

            /* Progress Bar Styles */
            .progress-mini {
                height: 5px;
                background: #e9ecef;
                border-radius: 3px;
                overflow: hidden;
                margin-bottom: 3px;
            }
            
            .progress-bar {
                height: 100%;
                background: #4e54c8;
                border-radius: 3px;
            }
            
            .progress-text {
                font-size: 12px;
                color: #666;
                text-align: right;
            }
            
            #calendar {
                width: 100%;
                height: 500px;
                margin: 0 auto;
                visibility: visible;
                font-size: 12px;
            }
            
            .fc {
                visibility: visible !important;
            }
            
            .fc-scroller {
                overflow-y: hidden !important;
            }
            
            .fc-daygrid-day {
                position: relative;
                height: 30px;
                width: 30px;
            }
            
            .fc-daygrid-day-frame {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                height: 100%;
            }
            
            .fc-daygrid-day-top {
                margin-bottom: 2px;
            }
            
            .fc-daygrid-day-number {
                color: #333;
                text-decoration: none;
                font-size: 10px;
            }
            
            .fc-daygrid-day-events {
                text-align: center;
            }
            
            .fc-task-count {
                background: #0073aa;
                color: #fff;
                border-radius: 10px;
                padding: 1px 4px;
                font-size: 9px;
                display: inline-block;
                text-decoration: none;
            }
            
            .fc-task-count:hover {
                background: #005a87;
            }
            
            .fc-header-toolbar {
                font-size: 12px;
            }
            
            .fc-button {
                padding: 2px 5px;
                font-size: 10px;
            }
            
            .data-table {
                width: 100%;
                border-collapse: collapse;
            }
            
            .data-table th {
                color: #666;
                font-weight: 500;
                font-size: 13px;
                text-align: left;
                padding: 12px 15px;
                border-bottom: 1px solid #f0f0f0;
            }
            
            .data-table td {
                padding: 12px 15px;
                font-size: 14px;
                border-bottom: 1px solid #f0f0f0;
            }
            
            .data-table tr:last-child td {
                border-bottom: none;
            }
            
            .data-table a {
                color: #0073aa;
                text-decoration: none;
            }
            
            .data-table a:hover {
                text-decoration: underline;
            }
            
            .days-remaining {
                font-weight: 500;
            }
            
            .urgent .days-remaining {
                color: #dc3545;
            }
            
            .soon .days-remaining {
                color: #fd7e14;
            }
            
            .action-button {
                display: inline-flex;
                align-items: center;
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 4px;
                padding: 5px 10px;
                font-size: 13px;
                cursor: pointer;
                transition: all 0.2s;
            }
            
            .action-button:hover {
                background: #e9ecef;
            }
            
            .action-button .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
                margin-right: 5px;
            }
            
            .renew-button {
                color: #0073aa;
                border-color: #0073aa;
                background: rgba(0,115,170,0.05);
            }
            
            .renew-button:hover {
                background: rgba(0,115,170,0.1);
            }
            
            .view-button {
                color: #0073aa;
                border-color: #0073aa;
                background: rgba(0,115,170,0.05);
            }
            
            .view-button:hover {
                background: rgba(0,115,170,0.1);
            }
            
            .days-overdue {
                font-weight: 500;
                color: #dc3545;
            }
            
            .user-info-cell {
                display: flex;
                align-items: center;
            }
            
            .user-avatar-mini {
                width: 28px;
                height: 28px;
                border-radius: 50%;
                background: #0073aa;
                color: white;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 12px;
                font-weight: 600;
                margin-right: 8px;
                flex-shrink: 0;
            }
            
            .status-badge {
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 500;
            }
            
            .status-active, .status-aktif {
                background: #d1e7dd;
                color: #198754;
            }
            
            .status-pending, .status-bekliyor {
                background: #fff3cd;
                color: #856404;
            }
            
            .status-cancelled, .status-iptal {
                background: #f8d7da;
                color: #dc3545;
            }
            
            .amount-cell {
                font-weight: 500;
                color: #333;
            }
            
            .negative-value {
                color: #dc3545;
            }
            
            .table-actions {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .table-action {
                width: 28px;
                height: 28px;
                border-radius: 4px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #666;
                transition: all 0.2s;
                text-decoration: none;
            }
            
            .table-action:hover {
                background: #f0f0f0;
                color: #333;
            }
            
            .table-action-dropdown-wrapper {
                position: relative;
            }
            
            .table-action-more {
                cursor: pointer;
            }
            
            .table-action-dropdown {
                position: absolute;
                top: 100%;
                right: 0;
                background: #fff;
                border-radius: 6px;
                box-shadow: 0 3px 10px rgba(0,0,0,0.15);
                z-index: 1000;
                min-width: 120px;
                display: none;
            }
            
            .table-action-dropdown.show {
                display: block;
            }
            
            .table-action-dropdown a {
                display: block;
                padding: 8px 15px;
                color: #333;
                text-decoration: none;
                font-size: 13px;
            }
            
            .table-action-dropdown a:hover {
                background: #f5f5f5;
            }
            
            .table-action-dropdown a.text-danger {
                color: #dc3545;
            }
            
            .table-action-dropdown a.text-danger:hover {
                background: #f8d7da;
            }
            
            .text-warning {
                color: #ffc107;
            }
            
            .empty-state {
                text-align: center;
                padding: 30px;
            }
            
            .empty-state .empty-icon {
                width: 60px;
                height: 60px;
                border-radius: 50%;
                background: #f0f7ff;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 15px;
            }
            
            .empty-state .empty-icon .dashicons {
                font-size: 30px;
                color: #0073aa;
            }
            
            .empty-state h4 {
                font-size: 16px;
                font-weight: 600;
                margin-bottom: 10px;
                color: #333;
            }
            
            .empty-state p {
                font-size: 14px;
                color: #666;
                margin-bottom: 20px;
            }
            
            .task-list {
                list-style-type: none;
                padding: 0;
            }
            
            .task-item {
                padding: 10px 0;
                border-bottom: 1px solid #f0f0f0;
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-size: 13px;
            }
            
            .task-item:last-child {
                border-bottom: none;
            }
            
            .task-link {
                margin-left: 10px;
                color: #0073aa;
                text-decoration: none;
                font-size: 12px;
            }
            
            .task-link:hover {
                text-decoration: underline;
            }
            
            /* Button Styles */
            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 8px 16px;
                border-radius: 4px;
                font-size: 14px;
                font-weight: 500;
                text-decoration: none;
                cursor: pointer;
                transition: all 0.2s;
                border: 1px solid transparent;
            }
            
            .btn i {
                margin-right: 8px;
                font-size: 14px;
            }
            
            .btn-sm {
                padding: 5px 10px;
                font-size: 13px;
            }
            
            .btn-primary {
                background-color: #0073aa;
                color: white;
            }
            
            .btn-primary:hover {
                background-color: #005c88;
                color: white;
            }
            
            .btn-secondary {
                background-color: #6c757d;
                color: white;
            }
            
            .btn-secondary:hover {
                background-color: #5a6268;
                color: white;
            }
            
            .btn-outline {
                background-color: transparent;
                border: 1px solid #ddd;
                color: #333;
            }
            
            .btn-outline:hover {
                background-color: #f8f9fa;
            }
            
            .btn-outline-warning {
                border-color: #ffc107;
                color: #ffc107;
            }
            
            .btn-outline-warning:hover {
                background-color: #fff3cd;
            }
            
            .btn-outline-success {
                border-color: #28a745;
                color: #28a745;
            }
            
            .btn-outline-success:hover {
                background-color: #d4edda;
            }
            
            /* Performance Distribution Chart */
            .performance-layout {
                display: flex;
                flex-wrap: wrap;
                gap: 25px;
            }
            
            .team-contribution-chart {
                flex: 1;
                min-width: 250px;
            }
            
            .team-performance-table {
                flex: 2;
                min-width: 350px;
            }
            
            .pie-chart {
                width: 100%;
                height: 300px;
                position: relative;
                margin-bottom: 15px;
            }
            
            .pie-chart-title {
                font-size: 14px;
                font-weight: 600;
                margin-top: 5px;
                margin-bottom: 15px;
                text-align: center;
                color: #333;
            }
            
            .performance-section {
                margin-top: 20px;
            }
            
            /* Responsive Adjustments */
            @media (max-width: 1200px) {
                .stats-grid,
                .performance-stats-grid {
                    grid-template-columns: repeat(3, 1fr);
                    gap: 15px;
                }
                
                .dashboard-header-container {
                    flex-direction: column;
                }
                
                .performance-date-filter {
                    margin-top: 15px;
                    width: 100%;
                    max-width: 100%;
                }
            }
            
            @media (max-width: 992px) {
                .dashboard-grid .upper-section {
                    flex-direction: column;
                }
                .dashboard-grid .upper-section .dashboard-card.chart-card,
                .dashboard-grid .upper-section .dashboard-card.calendar-card {
                    width: 100%;
                }
                .search-box input {
                    width: 150px;
                }
                .search-box input:focus {
                    width: 200px;
                }
                
                .team-cards {
                    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                }
            }
            
            @media (max-width: 768px) {
                .insurance-crm-sidenav {
                    width: 60px;
                    transform: translateX(0);
                    overflow: visible;
                }
                
                .insurance-crm-sidenav .sidenav-user {
                    display: none;
                }
                
                .insurance-crm-sidenav.expanded {
                    width: 260px;
                    box-shadow: 0 0 15px rgba(0,0,0,0.2);
                }
                
                .insurance-crm-sidenav.expanded .sidenav-user {
                    display: flex;
                }
                
                .sidenav-header h3, 
                .sidenav-menu a span, 
                .logout-button span,
                .sidenav-submenu > a::after {
                    display: none;
                }
                
                .sidenav-submenu .submenu-items {
                    max-height: 0;
                    opacity: 0;
                }
                
                .insurance-crm-sidenav.expanded .sidenav-header h3, 
                .insurance-crm-sidenav.expanded .sidenav-menu a span,
                .insurance-crm-sidenav.expanded .logout-button span,
                .insurance-crm-sidenav.expanded .sidenav-submenu > a::after {
                    display: block;
                }
                
                .insurance-crm-sidenav.expanded .sidenav-submenu.active .submenu-items {
                    max-height: 300px;
                    opacity: 1;
                }
                
                .insurance-crm-main {
                    margin-left: 60px;
                }
                
                .insurance-crm-sidenav.expanded + .insurance-crm-main {
                    margin-left: 260px;
                }
                
                .stats-grid,
                .performance-stats-grid {
                    grid-template-columns: 1fr;
                    gap: 15px;
                }
                
                .hide-mobile {
                    display: none;
                }
                
                /* Responsive Table Design */
                .data-table {
                    overflow-x: auto;
                    display: block;
                    white-space: nowrap;
                }
                
                .data-table thead,
                .data-table tbody,
                .data-table th,
                .data-table td,
                .data-table tr {
                    display: block;
                }
                
                .data-table thead tr {
                    position: absolute;
                    top: -9999px;
                    left: -9999px;
                }
                
                .data-table tr {
                    border: 1px solid #f0f0f0;
                    border-radius: 8px;
                    margin-bottom: 10px;
                    padding: 10px;
                    background: white;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                }
                
                .data-table td {
                    border: none;
                    border-bottom: 1px solid #eee;
                    position: relative;
                    padding-left: 50% !important;
                    padding-top: 8px;
                    padding-bottom: 8px;
                    white-space: normal;
                }
                
                .data-table td:before {
                    content: attr(data-label) ": ";
                    position: absolute;
                    left: 6px;
                    width: 45%;
                    padding-right: 10px;
                    white-space: nowrap;
                    font-weight: 600;
                    color: #333;
                }
                
                .data-table td:last-child {
                    border-bottom: none;
                }
                
                /* Alternative: Horizontal scroll for complex tables */
                .teams-table,
                .team-performance-table .data-table {
                    display: table;
                    overflow-x: auto;
                    white-space: nowrap;
                    width: 100%;
                    border: 1px solid #e0e0e0;
                    border-radius: 8px;
                }
                
                .teams-table thead,
                .teams-table tbody,
                .teams-table th,
                .teams-table td,
                .teams-table tr,
                .team-performance-table .data-table thead,
                .team-performance-table .data-table tbody,
                .team-performance-table .data-table th,
                .team-performance-table .data-table td,
                .team-performance-table .data-table tr {
                    display: table-cell;
                }
                
                .teams-table thead tr,
                .team-performance-table .data-table thead tr {
                    position: relative;
                    top: auto;
                    left: auto;
                }
                
                .teams-table td,
                .team-performance-table .data-table td {
                    padding-left: 15px !important;
                    white-space: nowrap;
                    min-width: 120px;
                }
                
                .teams-table td:before,
                .team-performance-table .data-table td:before {
                    display: none;
                }
                
                .performance-layout {
                    flex-direction: column;
                }
                
                .team-contribution-chart, 
                .team-performance-table {
                    flex: 1 100%;
                }
                
                .org-box {
                    width: 100%;
                }
                
                .org-level {
                    flex-direction: column;
                    gap: 15px;
                }
                
                .org-connector {
                    height: 20px;
                }
                
                .main-header {
                    padding: 10px 15px;
                }
                
                .header-left h2 {
                    font-size: 16px;
                }
                
                .search-box {
                    display: none;
                }
                
                .help-icon {
                    display: none;
                }
                
                /* Mobile help modal adjustments */
                .help-modal-content {
                    margin: 10% auto;
                    width: 95%;
                    max-height: 80vh;
                    overflow-y: auto;
                }
                
                .help-modal-body {
                    padding: 20px 15px;
                }
                
                .help-form-section textarea {
                    font-size: 16px; /* Prevent zoom on iOS */
                }
                
                .quick-add-btn span {
                    display: none;
                }
                
                /* Enhanced Header Responsive */
                .dashboard-header-content {
                    flex-direction: column;
                    gap: 20px;
                    text-align: center;
                }
                
                .dashboard-title-section {
                    flex-direction: column;
                    gap: 16px;
                }
                
                .dashboard-title-content h1 {
                    font-size: 24px;
                    flex-direction: column;
                    gap: 8px;
                }
                
                .dashboard-header-actions {
                    gap: 16px;
                    flex-wrap: wrap;
                    justify-content: center;
                }
                
                .dashboard-action-buttons {
                    flex-wrap: wrap;
                    gap: 8px;
                }
                
                .dashboard-action-btn {
                    padding: 10px 16px;
                    font-size: 13px;
                }
                
                /* Enhanced Chart Responsiveness */
                .chart-container {
                    height: 250px !important;
                    min-height: 200px;
                }
                
                canvas {
                    max-height: 250px !important;
                }
                
                /* Widget Grid Improvements */
                .dashboard-widgets-grid,
                .performance-widgets-grid,
                .tasks-widgets-grid {
                    grid-template-columns: 1fr !important;
                    gap: 15px;
                }
                
                .widget-card,
                .performance-card,
                .task-card {
                    padding: 15px;
                    margin-bottom: 15px;
                }
                
                .widget-card h3,
                .performance-card h3,
                .task-card h3 {
                    font-size: 16px;
                    margin-bottom: 12px;
                }
                
                /* Card Layout Improvements */
                .card-grid {
                    grid-template-columns: 1fr !important;
                    gap: 15px;
                }
                
                .card {
                    padding: 15px;
                    margin-bottom: 15px;
                }
                
                .card-header {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 12px;
                }
                
                .card-actions {
                    width: 100%;
                    justify-content: space-between;
                    flex-wrap: wrap;
                    gap: 8px;
                }
                
                /* Statistical Cards */
                .stat-card {
                    padding: 15px;
                    text-align: center;
                }
                
                .stat-number {
                    font-size: 20px;
                    margin: 8px 0;
                }
                
                .stat-label {
                    font-size: 12px;
                }
                
                /* Button Improvements */
                .btn-group {
                    flex-direction: column;
                    gap: 8px;
                    width: 100%;
                }
                
                .btn {
                    width: 100%;
                    justify-content: center;
                    padding: 12px 16px;
                    font-size: 14px;
                }
                
                /* Mobile Performance Chart */
                .performance-chart-container {
                    height: 200px;
                    margin-bottom: 20px;
                }
                
                /* Mobile Production Chart */
                .production-chart-container {
                    height: 200px;
                    margin-bottom: 20px;
                }
                
                /* Notification improvements */
                .notifications-dropdown {
                    position: fixed !important;
                    top: 60px !important;
                    right: 10px !important;
                    left: 10px !important;
                    width: auto !important;
                    max-height: 70vh;
                    overflow-y: auto;
                }
                
                .notification-item {
                    padding: 12px;
                    font-size: 14px;
                }
                
                /* Quick add dropdown improvements */
                .quick-add-dropdown {
                    position: fixed !important;
                    top: 60px !important;
                    right: 10px !important;
                    left: 10px !important;
                    width: auto !important;
                }
                
                /* Search improvements */
                .search-results {
                    margin: 15px;
                    padding: 15px;
                }
                
                .search-result-item {
                    padding: 12px;
                    margin-bottom: 10px;
                    border-radius: 8px;
                }
                
                /* Search Results Table Styling */
                .search-results-table .odd-row {
                    background-color: #f8f9fa;
                }
                
                .search-results-table .even-row {
                    background-color: #ffffff;
                }
                
                .search-results-table .odd-row:hover {
                    background-color: #e9ecef;
                }
                
                .search-results-table .even-row:hover {
                    background-color: #f1f3f4;
                }
                
                .search-results-table .corporate-customer {
                    font-weight: bold;
                    color: #0066cc;
                }
                
                .search-results-table .corporate-customer:hover {
                    color: #004499;
                    text-decoration: none;
                }
                
                /* Modal improvements */
                .modal-content {
                    margin: 5% auto;
                    width: 95%;
                    max-height: 90vh;
                    overflow-y: auto;
                }
                
                .modal-header {
                    padding: 15px;
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 10px;
                }
                
                .modal-body {
                    padding: 15px;
                }
                
                .modal-footer {
                    padding: 15px;
                    flex-direction: column;
                    gap: 10px;
                }
                
                .modal-footer .btn {
                    width: 100%;
                }
            }
            
            @media (max-width: 576px) {
                .main-content {
                    padding: 15px 10px;
                }
                
                .date-inputs {
                    flex-direction: column;
                }
                
                .card-header {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 10px;
                }
                
                .card-actions {
                    width: 100%;
                    justify-content: space-between;
                }
                
                .filter-buttons {
                    flex-wrap: wrap;
                }
                
                .filter-btn {
                    font-size: 12px;
                    padding: 5px 8px;
                }
                
                .org-actions {
                    flex-direction: column;
                    gap: 10px;
                }
                
                .team-cards {
                    grid-template-columns: 1fr;
                }
                
                /* Enhanced Header Mobile */
                .dashboard-header-section {
                    padding: 20px;
                    margin-bottom: 20px;
                }
                
                .dashboard-title-icon {
                    width: 48px;
                    height: 48px;
                }
                
                .dashboard-title-icon i {
                    font-size: 20px;
                }
                
                .dashboard-title-content h1 {
                    font-size: 20px;
                }
                
                .dashboard-version-badge {
                    font-size: 12px;
                    padding: 4px 12px;
                }
                
                .dashboard-subtitle {
                    font-size: 14px;
                }
                
                .dashboard-user-role {
                    padding: 8px 16px;
                }
                
                .dashboard-action-btn {
                    padding: 8px 12px;
                    font-size: 12px;
                }
                
                /* Mobile-specific dropdown improvements */
                .sidenav-submenu > a::after {
                    right: 15px;
                    font-size: 14px;
                }
                
                .submenu-items a {
                    padding: 12px 15px;
                    font-size: 15px;
                    min-height: 44px;
                    display: flex;
                    align-items: center;
                }
                
                .submenu-items a:hover,
                .submenu-items a:focus {
                    padding-left: 20px;
                }
                
                /* Extra small mobile improvements */
                .card {
                    padding: 12px;
                    margin-bottom: 12px;
                }
                
                .widget-card,
                .performance-card,
                .task-card {
                    padding: 12px;
                    margin-bottom: 12px;
                }
                
                .widget-card h3,
                .performance-card h3,
                .task-card h3 {
                    font-size: 14px;
                    margin-bottom: 10px;
                }
                
                .stat-number {
                    font-size: 18px;
                    margin: 6px 0;
                }
                
                .stat-label {
                    font-size: 11px;
                }
                
                .btn {
                    padding: 10px 12px;
                    font-size: 13px;
                }
                
                .chart-container {
                    height: 180px !important;
                    min-height: 150px;
                }
                
                canvas {
                    max-height: 180px !important;
                }
                
                .performance-chart-container,
                .production-chart-container {
                    height: 150px;
                    margin-bottom: 15px;
                }
                
                .main-content {
                    padding: 10px 5px;
                }
                
                .notifications-dropdown,
                .quick-add-dropdown {
                    top: 50px !important;
                    right: 5px !important;
                    left: 5px !important;
                }
                
                .modal-content {
                    margin: 2% auto;
                    width: 98%;
                    max-height: 96vh;
                }
                
                .modal-header,
                .modal-body,
                .modal-footer {
                    padding: 12px;
                }
            }
            
            /* Görev özetleri için stil */
            .tasks-summary-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 15px;
                margin-bottom: 25px;
            }

            .task-summary-card {
                background: #fff;
                border-radius: 10px;
                padding: 20px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                display: flex;
                flex-direction: column;
                position: relative;
                overflow: hidden;
                transition: transform 0.3s ease, box-shadow 0.3s ease;
            }

            .task-summary-card:hover {
                transform: translateY(-3px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            }

            .task-summary-card.today {
                border-left: 4px solid #e53935;
            }

            .task-summary-card.tomorrow {
                border-left: 4px solid #fb8c00;
            }

            .task-summary-card.this-week {
                border-left: 4px solid #43a047;
            }

            .task-summary-card.this-month {
                border-left: 4px solid #1e88e5;
            }

            .task-summary-icon {
                width: 40px;
                height: 40px;
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 10px;
            }

            .task-summary-card.today .task-summary-icon {
                background: rgba(229, 57, 53, 0.1);
                color: #e53935;
            }

            .task-summary-card.tomorrow .task-summary-icon {
                background: rgba(251, 140, 0, 0.1);
                color: #fb8c00;
            }

            .task-summary-card.this-week .task-summary-icon {
                background: rgba(67, 160, 71, 0.1);
                color: #43a047;
            }

            .task-summary-card.this-month .task-summary-icon {
                background: rgba(30, 136, 229, 0.1);
                color: #1e88e5;
            }

            .task-summary-icon .dashicons {
                font-size: 20px;
                width: 20px;
                height: 20px;
            }

            .task-summary-content {
                margin-top: auto;
            }

            .task-summary-content h3 {
                font-size: 28px;
                font-weight: 700;
                margin: 0;
                line-height: 1.2;
                color: #333;
            }

            .task-summary-content p {
                font-size: 14px;
                color: #666;
                margin: 0;
            }

            .task-summary-link {
                color: #0073aa;
                text-decoration: none;
                font-size: 13px;
                margin-top: 10px;
                display: inline-block;
                font-weight: 500;
                transition: color 0.2s;
            }

            .task-summary-link:hover {
                color: #005a87;
                text-decoration: underline;
            }

            .urgent-tasks {
                margin-top: 25px;
            }

            .urgent-tasks h4 {
                font-size: 16px;
                font-weight: 600;
                color: #333;
                margin-bottom: 15px;
                padding-bottom: 8px;
                border-bottom: 1px solid #eee;
            }

            .urgent-task-item {
                display: flex;
                align-items: center;
                padding: 15px;
                margin-bottom: 10px;
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.08);
                transition: transform 0.2s ease;
            }

            .urgent-task-item:hover {
                transform: translateY(-2px);
            }

            .urgent-task-item.very-urgent {
                border-left: 4px solid #e53935;
            }

            .urgent-task-item.urgent {
                border-left: 4px solid #fb8c00;
            }

            .urgent-task-item.normal {
                border-left: 4px solid #43a047;
            }

            .task-date {
                width: 50px;
                height: 50px;
                background: #f5f7fa;
                border-radius: 8px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                margin-right: 15px;
                flex-shrink: 0;
            }

            .date-number {
                font-size: 18px;
                font-weight: 700;
                color: #333;
                line-height: 1;
            }

            .date-month {
                font-size: 12px;
                color: #666;
                text-transform: uppercase;
            }

            .task-details {
                flex: 1;
            }

            .task-details h5 {
                font-size: 15px;
                font-weight: 600;
                margin: 0 0 5px;
                color: #333;
            }

            .task-details p {
                font-size: 13px;
                color: #666;
                margin: 0;
            }

            .task-action {
                margin-left: 15px;
            }

            .view-task-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 6px 12px;
                background: #0073aa;
                color: #fff;
                border-radius: 4px;
                font-size: 12px;
                text-decoration: none;
                transition: background 0.2s;
            }

            .view-task-btn:hover {
                background: #005a87;
                color: #fff;
            }

            .empty-tasks-message {
                text-align: center;
                padding: 30px 0;
            }

            .empty-tasks-message .empty-icon {
                width: 60px;
                height: 60px;
                border-radius: 50%;
                background: rgba(0,115,170,0.1);
                color: #0073aa;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 15px;
            }

            .empty-tasks-message .empty-icon .dashicons {
                font-size: 24px;
                width: 24px;
                height: 24px;
            }

            .empty-tasks-message p {
                color: #666;
                margin-bottom: 15px;
            }
            
            @media (max-width: 992px) {
                .tasks-summary-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
            }
            
            @media (max-width: 576px) {
                .tasks-summary-grid {
                    grid-template-columns: 1fr;
                }
            }



           /* Modern Header Styles */
            .header-brand {
                display: flex;
                align-items: center;
                gap: 16px;
                margin-left: 16px;
            }

            .header-logo {
                width: 40px;
                height: 40px;
                border-radius: 8px;
                overflow: hidden;
                background: var(--md-sys-color-surface-container);
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .header-logo img {
                max-width: 100%;
                max-height: 100%;
                object-fit: contain;
            }

            .header-info {
                display: flex;
                flex-direction: column;
                gap: 2px;
            }

            .header-brand .header-info .company-name {
                font-size: 20px !important;
                font-weight: 600 !important;
                color: var(--md-sys-color-on-surface) !important;
                margin: 0 !important;
                line-height: 1.2 !important;
            }
            
            /* Ensure consistency across all organizational pages */
            .company-name {
                font-size: 20px !important;
                font-weight: 600 !important;
                color: var(--md-sys-color-on-surface) !important;
                margin: 0 !important;
                line-height: 1.2 !important;
            }

            .header-brand .header-info .page-title {
                font-size: 14px !important;
                color: var(--md-sys-color-on-surface-variant) !important;
                font-weight: 400 !important;
            }
            
            /* Ensure page title consistency */
            .page-title {
                font-size: 14px !important;
                color: var(--md-sys-color-on-surface-variant) !important;
                font-weight: 400 !important;
            }



@media (max-width: 768px) {
    /* Şirket adı ve sayfa başlığını gizle */
    .header-info {
        display: none;
    }
    
    /* Logo boyutunu küçült */
    .header-logo {
        width: 32px;
        height: 32px;
    }
    
    /* Header brand alanını küçült */
    .header-brand {
        gap: 0; /* Logo ile yazı arasındaki boşluğu kaldır */
    }
    
    /* Diğer mevcut mobil kodlar... */
    .insurance-crm-sidenav {
        width: 60px;
        transform: translateX(0);
        overflow: visible;
    }
    
    .insurance-crm-sidenav .sidenav-user {
        display: none;
    }
}

/* Toggle button styles */
.view-toggle-buttons {
    display: flex;
    gap: 0.5rem;
    margin-right: 1rem;
}

.toggle-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border: 1px solid #d1d5db;
    background-color: #ffffff;
    color: #6b7280;
    border-radius: 0.375rem;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease-in-out;
}

.toggle-btn:hover {
    background-color: #f3f4f6;
    border-color: #9ca3af;
}

.toggle-btn.active {
    background-color: #3b82f6;
    border-color: #3b82f6;
    color: #ffffff;
}

.toggle-btn.active:hover {
    background-color: #2563eb;
    border-color: #2563eb;
}

.toggle-btn i {
    font-size: 0.875rem;
}

/* Mobile responsive for toggle buttons */
@media (max-width: 768px) {
    .view-toggle-buttons {
        margin-right: 0;
        margin-bottom: 0.5rem;
    }
    
    .toggle-btn {
        padding: 0.375rem 0.75rem;
        font-size: 0.75rem;
    }
    
    .card-actions {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
}

/* Ensure Tailwind utilities work properly */
.hidden {
    display: none !important;
}

/* Enhanced mobile responsiveness for dashboard sections */
@media (max-width: 768px) {
    .dashboard-grid .upper-section {
        flex-direction: column;
    }
    
    .dashboard-card.chart-card {
        width: 100% !important;
        margin-bottom: 1rem;
    }
    
    .dashboard-card.organization-management-widget-card {
        width: 100% !important;
    }
    
    /* Make cards responsive on mobile */
    .grid {
        grid-template-columns: 1fr !important;
    }
    
    /* Improve table responsiveness */
    .overflow-x-auto {
        -webkit-overflow-scrolling: touch;
    }
    
    /* Better button spacing on mobile */
    .card-actions {
        gap: 0.5rem;
        flex-wrap: wrap;
    }
}

/* Additional mobile optimizations */
@media (max-width: 480px) {
    .card-header h3 {
        font-size: 1.1rem;
    }
    
    .dashboard-card {
        margin-bottom: 1rem;
    }
    
    /* Smaller cards on very small screens */
    .bg-white.rounded-xl {
        border-radius: 0.5rem;
    }
    
    .p-6 {
        padding: 1rem;
    }
}

/* Organization Management Panel Enhancements */
.dropdown-menu-grid {
    display: flex;
    flex-direction: column;
    gap: 0;
}

.dropdown-menu-box {
    text-decoration: none !important;
    color: inherit !important;
}

.dropdown-menu-box:hover {
    text-decoration: none !important;
}

.dropdown-box-icon.representative {
    background: linear-gradient(135deg, #8b5cf6, #7c3aed) !important;
}

.dropdown-box-icon.manager {
    background: linear-gradient(135deg, #06b6d4, #0891b2) !important;
}

.dropdown-box-icon.team {
    background: linear-gradient(135deg, #10b981, #059669) !important;
}

.dropdown-box-icon.admin {
    background: linear-gradient(135deg, #f59e0b, #d97706) !important;
}

/* Mobile responsive organization panel */
@media (max-width: 768px) {
    .management-dropdown-container {
        padding: 15px !important;
    }
    
    .dropdown-box-header {
        flex-direction: row !important;
        align-items: center !important;
    }
    
    .dropdown-box-icon {
        width: 2.5rem !important;
        height: 2.5rem !important;
    }
    
    .dropdown-box-title {
        font-size: 0.875rem !important;
    }
    
    .dropdown-box-description {
        font-size: 0.75rem !important;
    }
}


        </style>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidenavToggle = document.getElementById('sidenav-toggle');
            const sidenav = document.querySelector('.insurance-crm-sidenav');
            const main = document.querySelector('.insurance-crm-main');
            
            if (sidenavToggle) {
                sidenavToggle.addEventListener('click', function() {
                    sidenav.classList.toggle('expanded');
                    
                    // Close all dropdowns when sidebar is collapsed
                    if (!sidenav.classList.contains('expanded')) {
                        document.querySelectorAll('.sidenav-submenu.active').forEach(submenu => {
                            submenu.classList.remove('active');
                            const toggle = submenu.querySelector('a[aria-expanded]');
                            if (toggle) {
                                toggle.setAttribute('aria-expanded', 'false');
                            }
                        });
                    }
                });
            }
            
            // Sidebar Dropdown Menu Functionality
            const submenuToggles = document.querySelectorAll('.sidenav-submenu > a');
            console.log('Found submenu toggles:', submenuToggles.length);
            
            submenuToggles.forEach(toggle => {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    console.log('Submenu toggle clicked:', this.textContent.trim());
                    const submenu = this.parentElement;
                    
                    // Close other open submenus
                    submenuToggles.forEach(otherToggle => {
                        const otherSubmenu = otherToggle.parentElement;
                        if (otherSubmenu !== submenu) {
                            otherSubmenu.classList.remove('active');
                            otherToggle.setAttribute('aria-expanded', 'false');
                        }
                    });
                    
                    // Toggle current submenu
                    submenu.classList.toggle('active');
                    this.setAttribute('aria-expanded', submenu.classList.contains('active') ? 'true' : 'false');
                    console.log('Submenu toggled:', submenu.classList.contains('active') ? 'opened' : 'closed');
                    
                    // Focus management
                    if (submenu.classList.contains('active')) {
                        const firstSubmenuItem = submenu.querySelector('.submenu-items a');
                        if (firstSubmenuItem) {
                            firstSubmenuItem.focus();
                        }
                    }
                });
                
                // Also handle touch events for mobile
                toggle.addEventListener('touchend', function(e) {
                    // Prevent the click event from firing after touchend
                    e.preventDefault();
                    this.click();
                });
            });
            
            // Keyboard navigation for dropdowns
            document.addEventListener('keydown', function(e) {
                const activeSubmenu = document.querySelector('.sidenav-submenu.active');
                
                if (e.key === 'Escape') {
                    // Close all dropdowns on Escape
                    document.querySelectorAll('.sidenav-submenu.active').forEach(submenu => {
                        submenu.classList.remove('active');
                        const toggle = submenu.querySelector('a[aria-expanded]');
                        if (toggle) {
                            toggle.setAttribute('aria-expanded', 'false');
                            toggle.focus();
                        }
                    });
                }
                
                if (activeSubmenu && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                    e.preventDefault();
                    const submenuItems = activeSubmenu.querySelectorAll('.submenu-items a');
                    const currentFocus = document.activeElement;
                    let currentIndex = Array.from(submenuItems).indexOf(currentFocus);
                    
                    if (e.key === 'ArrowDown') {
                        currentIndex = currentIndex < submenuItems.length - 1 ? currentIndex + 1 : 0;
                    } else {
                        currentIndex = currentIndex > 0 ? currentIndex - 1 : submenuItems.length - 1;
                    }
                    
                    submenuItems[currentIndex].focus();
                }
                
                if (e.key === 'Enter' && document.activeElement.closest('.sidenav-submenu > a')) {
                    e.preventDefault();
                    document.activeElement.click();
                }
            });
            
            // Close dropdowns when clicking outside sidebar
            document.addEventListener('click', function(e) {
                if (!sidenav.contains(e.target)) {
                    document.querySelectorAll('.sidenav-submenu.active').forEach(submenu => {
                        submenu.classList.remove('active');
                        const toggle = submenu.querySelector('a[aria-expanded]');
                        if (toggle) {
                            toggle.setAttribute('aria-expanded', 'false');
                        }
                    });
                }
            });
            
            // Notifications Dropdown Toggle
            const notificationsToggle = document.getElementById('notifications-toggle');
            const notificationsDropdown = document.querySelector('.notifications-dropdown');
            
            if (notificationsToggle && notificationsDropdown) {
                notificationsToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    notificationsDropdown.classList.toggle('show');
                });
                
                document.addEventListener('click', function(e) {
                    if (!notificationsToggle.contains(e.target) && !notificationsDropdown.contains(e.target)) {
                        notificationsDropdown.classList.remove('show');
                    }
                });
            }

            // Quick Add Dropdown Toggle
            const quickAddToggle = document.getElementById('quick-add-toggle');
            const quickAddDropdown = document.querySelector('.quick-add-dropdown');
            
            if (quickAddToggle && quickAddDropdown) {
                quickAddToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    quickAddDropdown.classList.toggle('show');
                });
                
                document.addEventListener('click', function(e) {
                    if (!quickAddToggle.contains(e.target) && !quickAddDropdown.contains(e.target)) {
                        quickAddDropdown.classList.remove('show');
                    }
                });
            }
            
            // Table Action Dropdowns
            const actionMoreButtons = document.querySelectorAll('.table-action-more');
            actionMoreButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const dropdown = button.parentElement.querySelector('.table-action-dropdown');
                    if (dropdown) {
                        dropdown.classList.toggle('show');
                    }
                });
            });
            
            document.addEventListener('click', function(e) {
                actionMoreButtons.forEach(button => {
                    const dropdown = button.parentElement.querySelector('.table-action-dropdown');
                    if (dropdown && !button.contains(e.target) && !dropdown.contains(e.target)) {
                        dropdown.classList.remove('show');
                    }
                });
            });
            
            // Production Chart
            const productionChartCanvas = document.querySelector('#productionChart');
            if (productionChartCanvas) {
                const monthlyProduction = <?php echo json_encode($monthly_production); ?>;
                
                const labels = monthlyProduction.map(item => {
                    const [year, month] = item.month.split('-');
                    const months = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 
                                  'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
                    return months[parseInt(month) - 1] + ' ' + year;
                });
                
                const data = monthlyProduction.map(item => item.total);
                
                new Chart(productionChartCanvas, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Aylık Üretim (₺)',
                            data: data,
                            backgroundColor: 'rgba(0,115,170,0.6)',
                            borderColor: 'rgba(0,115,170,1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '₺' + value.toLocaleString('tr-TR');
                                    }
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return '₺' + context.parsed.y.toLocaleString('tr-TR');
                                    }
                                }
                            }
                        }
                    }
                });
            }
            
            // Tarih filtresi özel tarih aralığı göster/gizle
            const customDateToggle = document.getElementById('custom-date-toggle');
            const customDateContainer = document.getElementById('custom-date-container');
            
            if (customDateToggle && customDateContainer) {
                customDateToggle.addEventListener('click', function() {
                    if (customDateContainer.style.display === 'none' || customDateContainer.style.display === '') {
                        customDateContainer.style.display = 'flex';
                        // Aktif sınıfını diğer butonlardan kaldır ve bu butona ekle
                        document.querySelectorAll('.filter-btn').forEach(btn => {
                            btn.classList.remove('active');
                        });
                        customDateToggle.classList.add('active');
                    } else {
                        customDateContainer.style.display = 'none';
                    }
                });
            }
            
            
            // Arama Formu Submit Kontrolü
            const searchForm = document.querySelector('.search-box form');
            if (searchForm) {
                searchForm.addEventListener('submit', function(e) {
                    const keywordInput = searchForm.querySelector('input[name="keyword"]');
                    if (!keywordInput.value.trim()) {
                        e.preventDefault();
                        alert('Lütfen bir arama kriteri girin.');
                    }
                });
            }
            
            // Mobil görünüm için özel kod - avatar gizleme/gösterme
            function adjustMobileLayout() {
                const isMobile = window.innerWidth <= 768;
                const sidenavExpanded = document.querySelector('.insurance-crm-sidenav').classList.contains('expanded');
                
                if (isMobile) {
                    // Menü daraltıldığında avatar gizle
                    document.querySelector('.sidenav-user').style.display = sidenavExpanded ? 'flex' : 'none';
                } else {
                    // Masaüstü görünümde her zaman göster
                    document.querySelector('.sidenav-user').style.display = 'flex';
                }
            }
            
            // Sayfa yüklendiğinde ve boyut değiştiğinde düzenlemeyi uygula
            window.addEventListener('load', adjustMobileLayout);
            window.addEventListener('resize', adjustMobileLayout);
            
            // Sidebar menüsü açılıp kapandığında da uygula
            if (sidenavToggle) {
                sidenavToggle.addEventListener('click', function() {
                    setTimeout(adjustMobileLayout, 10);
                });
            }
        });
        
        // Help Modal Functions
        function openHelpModal() {
            document.getElementById('help-modal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        
        function closeHelpModal() {
            document.getElementById('help-modal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        function processHTML() {
            const htmlInput = document.getElementById('html-input');
            const htmlResult = document.getElementById('html-result');
            const htmlCode = htmlInput.value.trim();
            
            if (!htmlCode) {
                alert('Lütfen HTML kodunuzu girin.');
                return;
            }
            
            // Basit HTML güvenlik kontrolü
            const dangerousPatterns = [
                /<script[^>]*>/i,
                /javascript:/i,
                /on\w+\s*=/i,
                /<iframe/i,
                /<object/i,
                /<embed/i
            ];
            
            let hasDangerousContent = false;
            dangerousPatterns.forEach(pattern => {
                if (pattern.test(htmlCode)) {
                    hasDangerousContent = true;
                }
            });
            
            if (hasDangerousContent) {
                htmlResult.innerHTML = '<div style="color: #d32f2f; font-weight: bold;">⚠️ Güvenlik nedeniyle bu HTML kodu işlenemez. Script tagları ve event handler\'lar desteklenmez.</div>';
            } else {
                htmlResult.innerHTML = htmlCode;
            }
            
            htmlResult.style.display = 'block';
        }
        
        function clearHTML() {
            document.getElementById('html-input').value = '';
            document.getElementById('html-result').innerHTML = '';
            document.getElementById('html-result').style.display = 'none';
        }
        
        // Help button event listener
        document.addEventListener('DOMContentLoaded', function() {
            const helpBtn = document.getElementById('search-help-btn');
            if (helpBtn) {
                helpBtn.addEventListener('click', openHelpModal);
            }
            
            // Close modal when clicking outside
            window.addEventListener('click', function(event) {
                const modal = document.getElementById('help-modal');
                if (event.target === modal) {
                    closeHelpModal();
                }
            });
        });

        // Toggle functions for team and representative views
        function toggleTeamView(view) {
            const tableContainer = document.getElementById('team-table-container');
            const cardsContainer = document.getElementById('team-cards-container');
            const tableBtn = document.getElementById('team-table-view');
            const cardBtn = document.getElementById('team-card-view');

            if (view === 'table') {
                tableContainer.style.display = 'block';
                cardsContainer.classList.add('hidden');
                tableBtn.classList.add('active');
                cardBtn.classList.remove('active');
                localStorage.setItem('teamViewMode', 'table');
            } else {
                tableContainer.style.display = 'none';
                cardsContainer.classList.remove('hidden');
                tableBtn.classList.remove('active');
                cardBtn.classList.add('active');
                localStorage.setItem('teamViewMode', 'cards');
            }
        }

        function toggleRepView(view) {
            const tableContainer = document.getElementById('rep-table-container');
            const cardsContainer = document.getElementById('rep-cards-container');
            const tableBtn = document.getElementById('rep-table-view');
            const cardBtn = document.getElementById('rep-card-view');

            if (view === 'table') {
                tableContainer.classList.remove('hidden');
                tableContainer.style.display = 'block';
                cardsContainer.classList.add('hidden');
                cardsContainer.style.display = 'none';
                tableBtn.classList.add('active');
                cardBtn.classList.remove('active');
                localStorage.setItem('repViewMode', 'table');
            } else {
                tableContainer.classList.add('hidden');
                tableContainer.style.display = 'none';
                cardsContainer.classList.remove('hidden');
                cardsContainer.style.display = 'block';
                tableBtn.classList.remove('active');
                cardBtn.classList.add('active');
                localStorage.setItem('repViewMode', 'cards');
            }
        }

        function toggleMonthlyView(view) {
            const tableContainer = document.getElementById('monthly-table-container');
            const cardsContainer = document.getElementById('monthly-cards-container');
            const tableBtn = document.getElementById('monthly-table-view');
            const cardBtn = document.getElementById('monthly-card-view');

            if (view === 'table') {
                tableContainer.style.display = 'block';
                cardsContainer.classList.add('hidden');
                tableBtn.classList.add('active');
                cardBtn.classList.remove('active');
                localStorage.setItem('monthlyViewMode', 'table');
            } else {
                tableContainer.style.display = 'none';
                cardsContainer.classList.remove('hidden');
                tableBtn.classList.remove('active');
                cardBtn.classList.add('active');
                localStorage.setItem('monthlyViewMode', 'cards');
            }
        }

        // Restore saved view modes on page load
        document.addEventListener('DOMContentLoaded', function() {
            const teamViewMode = localStorage.getItem('teamViewMode') || 'table';
            const repViewMode = localStorage.getItem('repViewMode') || 'cards';
            const monthlyViewMode = localStorage.getItem('monthlyViewMode') || 'table';
            
            if (typeof toggleTeamView === 'function') {
                toggleTeamView(teamViewMode);
            }
            if (typeof toggleRepView === 'function') {
                toggleRepView(repViewMode);
            }
            if (typeof toggleMonthlyView === 'function') {
                toggleMonthlyView(monthlyViewMode);
            }
        });

        // Birthday email function
        function sendBirthdayEmail(customerId, customerName) {
            if (!customerId) {
                alert('Geçersiz müşteri ID');
                return;
            }

            // Find and disable the button
            const button = document.querySelector(`[data-customer-id="${customerId}"] .birthday-celebrate-btn`);
            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gönderiliyor...';
            }

            // Prepare AJAX data
            const formData = new FormData();
            formData.append('action', 'insurance_crm_ajax');
            formData.append('action_type', 'send_birthday_email');
            formData.append('customer_id', customerId);
            formData.append('nonce', '<?php echo wp_create_nonce("insurance_crm_nonce"); ?>');

            // Send AJAX request
            fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Success feedback
                    if (button) {
                        button.innerHTML = '<i class="fas fa-check"></i> Gönderildi!';
                        button.style.background = '#28a745';
                        setTimeout(() => {
                            button.innerHTML = '<i class="fas fa-gift"></i> Kutla';
                            button.disabled = false;
                            button.style.background = '';
                        }, 3000);
                    }
                    
                    // Show success message
                    showBirthdayNotification(`${customerName} için doğum günü kutlama e-postası başarıyla gönderildi! 🎉`, 'success');
                } else {
                    // Error feedback
                    if (button) {
                        button.innerHTML = '<i class="fas fa-exclamation"></i> Hata!';
                        button.style.background = '#dc3545';
                        setTimeout(() => {
                            button.innerHTML = '<i class="fas fa-gift"></i> Kutla';
                            button.disabled = false;
                            button.style.background = '';
                        }, 3000);
                    }
                    
                    // Show error message
                    showBirthdayNotification(data.data || 'E-posta gönderimi başarısız oldu.', 'error');
                }
            })
            .catch(error => {
                console.error('Birthday email error:', error);
                
                if (button) {
                    button.innerHTML = '<i class="fas fa-exclamation"></i> Hata!';
                    button.style.background = '#dc3545';
                    setTimeout(() => {
                        button.innerHTML = '<i class="fas fa-gift"></i> Kutla';
                        button.disabled = false;
                        button.style.background = '';
                    }, 3000);
                }
                
                showBirthdayNotification('Bir hata oluştu. Lütfen tekrar deneyin.', 'error');
            });
        }

        // Show birthday notification
        function showBirthdayNotification(message, type = 'info') {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `birthday-notification birthday-notification-${type}`;
            notification.innerHTML = `
                <div class="birthday-notification-content">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
                    <span>${message}</span>
                </div>
                <button class="birthday-notification-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;

            // Add notification styles if not already added
            if (!document.querySelector('#birthday-notification-styles')) {
                const styles = document.createElement('style');
                styles.id = 'birthday-notification-styles';
                styles.textContent = `
                    .birthday-notification {
                        position: fixed;
                        top: 20px;
                        right: 20px;
                        min-width: 300px;
                        max-width: 500px;
                        padding: 15px;
                        border-radius: 8px;
                        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
                        z-index: 10000;
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        animation: slideInRight 0.3s ease;
                    }

                    .birthday-notification-success {
                        background: linear-gradient(135deg, #28a745, #20c997);
                        color: white;
                    }

                    .birthday-notification-error {
                        background: linear-gradient(135deg, #dc3545, #e74c3c);
                        color: white;
                    }

                    .birthday-notification-content {
                        display: flex;
                        align-items: center;
                        gap: 10px;
                        font-weight: 500;
                    }

                    .birthday-notification-close {
                        background: none;
                        border: none;
                        color: white;
                        cursor: pointer;
                        padding: 5px;
                        border-radius: 4px;
                        opacity: 0.8;
                        transition: opacity 0.2s;
                    }

                    .birthday-notification-close:hover {
                        opacity: 1;
                        background: rgba(255, 255, 255, 0.2);
                    }

                    @keyframes slideInRight {
                        from {
                            transform: translateX(100%);
                            opacity: 0;
                        }
                        to {
                            transform: translateX(0);
                            opacity: 1;
                        }
                    }
                `;
                document.head.appendChild(styles);
            }

            // Add to document
            document.body.appendChild(notification);

            // Auto remove after 5 seconds
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.style.animation = 'slideInRight 0.3s ease reverse';
                    setTimeout(() => notification.remove(), 300);
                }
            }, 5000);
        }

        // Toggle birthday panel function
        function toggleBirthdayPanel() {
            const content = document.getElementById('birthdayPanelContent');
            const toggleBtn = document.getElementById('birthdayToggleBtn');
            
            if (content.style.display === 'none' || content.style.display === '') {
                content.style.display = 'block';
                toggleBtn.innerHTML = '<i class="fas fa-chevron-up"></i> Gizle';
            } else {
                content.style.display = 'none';
                toggleBtn.innerHTML = '<i class="fas fa-chevron-down"></i> Göster';
            }
        }

        // Ensure birthday panel starts collapsed on page load
        document.addEventListener('DOMContentLoaded', function() {
            const birthdayPanelContent = document.getElementById('birthdayPanelContent');
            const birthdayToggleBtn = document.getElementById('birthdayToggleBtn');
            
            if (birthdayPanelContent && birthdayToggleBtn) {
                birthdayPanelContent.style.display = 'none';
                birthdayToggleBtn.innerHTML = '<i class="fas fa-chevron-down"></i> Göster';
            }
        });

        // Renewal Policies View Toggle
        document.addEventListener('DOMContentLoaded', function() {
            const renewalToggleBtns = document.querySelectorAll('.renewal-policies-card .toggle-btn');
            const renewalCardsView = document.getElementById('renewal-cards-view');
            const renewalTableView = document.getElementById('renewal-table-view');

            renewalToggleBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const viewType = this.getAttribute('data-view');
                    
                    // Remove active class from all buttons
                    renewalToggleBtns.forEach(b => b.classList.remove('active'));
                    
                    // Add active class to clicked button
                    this.classList.add('active');
                    
                    // Show/hide views
                    if (viewType === 'cards') {
                        renewalCardsView.style.display = 'grid';
                        renewalTableView.style.display = 'none';
                    } else {
                        renewalCardsView.style.display = 'none';
                        renewalTableView.style.display = 'block';
                    }
                });
            });

            // Policy Preview Links
            const policyPreviewLinks = document.querySelectorAll('.policy-preview-link');
            policyPreviewLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const policyId = this.getAttribute('data-policy-id');
                    
                    // Open policy preview modal or navigate to policy details
                    if (policyId) {
                        // Navigate to policies page with correct action=view&id format
                        const policyUrl = '<?php echo generate_panel_url("policies", "view"); ?>' + '&id=' + policyId;
                        window.open(policyUrl, '_blank');
                    }
                });
            });
        });
        </script>
        
        <?php wp_footer(); ?>
    <!-- Help Modal -->
    <div id="help-modal" class="help-modal">
        <div class="help-modal-content">
            <div class="help-modal-header">
                <h3><i class="dashicons dashicons-editor-help"></i> Arama Yardımı</h3>
                <button class="help-close" onclick="closeHelpModal()">&times;</button>
            </div>
            <div class="help-modal-body">
                <div class="help-section">
                    <h4>Arama Kriterleri</h4>
                    <ul>
                        <li><strong>Ad Soyad:</strong> Müşteri adı veya soyadı ile arama yapabilirsiniz</li>
                        <li><strong>TC Kimlik No:</strong> Müşterinin TC kimlik numarasıyla arama</li>
                        <li><strong>Çocuk TC No:</strong> Çocuklarının TC kimlik numaralarıyla arama</li>
                        <li><strong>Poliçe No:</strong> Poliçe numarası ile doğrudan arama</li>
                    </ul>
                </div>
                
                <div class="help-section">
                    <h4>Arama İpuçları</h4>
                    <ul>
                        <li>Kısmi arama yapabilirsiniz (örn: "Ahmet" yerine "Ahm")</li>
                        <li>Büyük/küçük harf duyarlılığı yoktur</li>
                        <li>Boşlukları dahil ederek tam isim arayabilirsiniz</li>
                        <li>Sonuçlar tüm eşleşen kayıtları gösterir</li>
                    </ul>
                </div>
                
                <div class="help-form-section">
                    <h4>HTML Form İşleme Aracı</h4>
                    <p>Aşağıya HTML kodunuzu yazarak önizleme yapabilirsiniz:</p>
                    <textarea id="html-input" placeholder="HTML kodunuzu buraya yazın..."></textarea>
                    <button onclick="processHTML()">HTML'i İşle</button>
                    <button onclick="clearHTML()">Temizle</button>
                    <div id="html-result" class="help-form-result"></div>
                </div>
            </div>
        </div>
    </div>

    </body>
</html>
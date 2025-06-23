<?php
/**
 * Frontend Görev Yönetim Sayfası
 * @version 3.1.0 - Modern UI Tasarım Güncellemesi
 * @date 2025-05-30 20:38:38
 * @author anadolubirlik
 * @description Kişisel ve Ekip Görevleri ayrımı, gelişmiş istatistikler ve modern UI
 */

// Renk ayarlarını dahil et
//include_once(dirname(__FILE__) . '/template-colors.php');

// Yetki kontrolü
if (!is_user_logged_in()) {
    echo '<div class="ab-notice ab-error">Bu sayfayı görüntülemek için giriş yapmalısınız.</div>';
    return;
}

// Değişkenleri tanımla
global $wpdb;
$tasks_table = $wpdb->prefix . 'insurance_crm_tasks';
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
$users_table = $wpdb->users;

// Mevcut kullanıcının temsilci ID'sini al
if (!function_exists('get_current_user_rep_id')) {
    function get_current_user_rep_id() {
        global $wpdb;
        $current_user_id = get_current_user_id();
        return $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'",
            $current_user_id
        ));
    }
}
$current_user_rep_id = get_current_user_rep_id();
$current_user_id = get_current_user_id();

// Yönetici yetkisini kontrol et (WordPress admin veya insurance_manager)
$is_wp_admin_or_manager = current_user_can('administrator') || current_user_can('insurance_manager');

// --- Role Helper Functions (Adapted from dashboard4365satir.php) ---
if (!function_exists('is_patron')) {
    function is_patron($user_id) {
        global $wpdb;
        $settings = get_option('insurance_crm_settings', []);
        $management_hierarchy = isset($settings['management_hierarchy']) ? $settings['management_hierarchy'] : array();
        if (empty($management_hierarchy['patron_id'])) return false;
        $rep = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d", $user_id));
        if (!$rep) return false;
        return ($management_hierarchy['patron_id'] == $rep->id);
    }
}

if (!function_exists('is_manager')) {
    function is_manager($user_id) {
        global $wpdb;
        $settings = get_option('insurance_crm_settings', []);
        $management_hierarchy = isset($settings['management_hierarchy']) ? $settings['management_hierarchy'] : array();
        if (empty($management_hierarchy['manager_id'])) return false;
        $rep = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d", $user_id));
        if (!$rep) return false;
        return ($management_hierarchy['manager_id'] == $rep->id);
    }
}

if (!function_exists('is_team_leader')) {
    function is_team_leader($user_id) {
        global $wpdb;
        $settings = get_option('insurance_crm_settings', []);
        $teams = $settings['teams_settings']['teams'] ?? [];
        $rep = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d", $user_id));
        if (!$rep) return false;
        foreach ($teams as $team) {
            if ($team['leader_id'] == $rep->id) return true;
        }
        return false;
    }
}

if (!function_exists('get_team_members_ids')) {
    function get_team_members_ids($team_leader_user_id) {
        global $wpdb;
        $leader_rep_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'",
            $team_leader_user_id
        ));
        
        if (!$leader_rep_id) {
            return array();
        }
        
        $settings = get_option('insurance_crm_settings', array());
        $teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();
        
        foreach ($teams as $team) {
            if (isset($team['leader_id']) && $team['leader_id'] == $leader_rep_id) {
                $members = isset($team['members']) ? $team['members'] : array();
                // Kendisini de ekle
                if (!in_array($leader_rep_id, $members)) {
                    $members[] = $leader_rep_id;
                }
                return array_unique($members);
            }
        }
        
        // Eğer ekip lideri bulunamazsa, sadece kendisini içeren bir dizi döndür
        return array($leader_rep_id);
    }
}
// --- End Role Helper Functions ---

// İşlem Bildirileri için session kontrolü
$notice = '';
if (isset($_SESSION['crm_notice'])) {
    $notice = $_SESSION['crm_notice'];
    unset($_SESSION['crm_notice']);
}

// Kullanıcının rolüne göre erişim düzeyi belirlenmesi
function get_user_role_level() {
    global $wpdb;
    $current_user_id = get_current_user_id();
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT id, role FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $current_user_id
    ));
    
    if (!$rep) return 5; // Varsayılan olarak en düşük yetki
    
    return intval($rep->role); // 1: Patron, 2: Müdür, 3: Müdür Yard., 4: Ekip Lideri, 5: Müş. Temsilcisi
}

$user_role_level = get_user_role_level();

// Aktif görünüm belirleme (kişisel veya ekip görevleri)
$current_view = isset($_GET['view_type']) ? sanitize_text_field($_GET['view_type']) : 'personal';
$is_team_view = ($current_view === 'team');

// Görev silme işlemi (Sadece WP Admin/Insurance Manager, Patron, Müdür silebilir)
$can_delete_tasks = $is_wp_admin_or_manager || is_patron($current_user_id) || is_manager($current_user_id);
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $task_id = intval($_GET['id']);
    if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_task_' . $task_id)) {
        if (!$can_delete_tasks) {
            $notice = '<div class="notification-banner notification-error">
                <div class="notification-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <div class="notification-content">
                    Görev silme yetkisine sahip değilsiniz.
                </div>
                <button class="notification-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>';
        } else {
            $delete_result = $wpdb->delete($tasks_table, array('id' => $task_id), array('%d'));
            if ($delete_result !== false) {
                $notice = '<div class="notification-banner notification-success">
                    <div class="notification-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="notification-content">
                        Görev başarıyla silindi.
                    </div>
                    <button class="notification-close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>';
            } else {
                $notice = '<div class="notification-banner notification-error">
                    <div class="notification-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <div class="notification-content">
                        Görev silinirken bir hata oluştu.
                    </div>
                    <button class="notification-close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>';
            }
        }
    }
}

// Görev tamamlama işlemi
if (isset($_GET['action']) && $_GET['action'] === 'complete' && isset($_GET['id'])) {
    $task_id = intval($_GET['id']);
    if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'complete_task_' . $task_id)) {
        $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tasks_table WHERE id = %d", $task_id));
        
        $can_complete = false;
        if ($is_wp_admin_or_manager || is_patron($current_user_id) || is_manager($current_user_id)) {
            $can_complete = true;
        } elseif ($current_user_rep_id && $task->representative_id == $current_user_rep_id) {
            $can_complete = true;
        } elseif (is_team_leader($current_user_id)) {
            $team_members = get_team_members_ids($current_user_id);
            if (in_array($task->representative_id, $team_members)) {
                $can_complete = true;
            }
        }

        if ($can_complete) {
            $update_result = $wpdb->update(
                $tasks_table,
                array('status' => 'completed', 'updated_at' => current_time('mysql')),
                array('id' => $task_id)
            );
            if ($update_result !== false) {
                $notice = '<div class="notification-banner notification-success">
                    <div class="notification-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="notification-content">
                        Görev başarıyla tamamlandı olarak işaretlendi.
                    </div>
                    <button class="notification-close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>';
            } else {
                $notice = '<div class="notification-banner notification-error">
                    <div class="notification-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <div class="notification-content">
                        Görev güncellenirken bir hata oluştu.
                    </div>
                    <button class="notification-close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>';
            }
        } else {
            $notice = '<div class="notification-banner notification-error">
                <div class="notification-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <div class="notification-content">
                    Bu görevi tamamlama yetkiniz yok.
                </div>
                <button class="notification-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>';
        }
    }
}

// Filtreler ve Sayfalama
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 15;
if (!in_array($per_page, [15, 25, 50, 100])) {
    $per_page = 15;
}
$offset = ($current_page - 1) * $per_page;

// Filtre parametrelerini al ve sanitize et
$filters = array(
    'customer_id' => isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0,
    'priority' => isset($_GET['priority']) ? sanitize_text_field($_GET['priority']) : '',
    'status' => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
    'task_description' => isset($_GET['task_description']) ? sanitize_text_field($_GET['task_description']) : '',
    'task_title' => isset($_GET['task_title']) ? sanitize_text_field($_GET['task_title']) : '',
    'due_date' => isset($_GET['due_date']) ? sanitize_text_field($_GET['due_date']) : '',
    'time_filter' => isset($_GET['time_filter']) ? sanitize_text_field($_GET['time_filter']) : '',
    'show_completed' => isset($_GET['show_completed']) ? true : false,
);

// Sorgu oluştur
$base_query = "FROM $tasks_table t 
               LEFT JOIN $customers_table c ON t.customer_id = c.id
               LEFT JOIN $policies_table p ON t.policy_id = p.id
               LEFT JOIN $representatives_table r ON t.representative_id = r.id
               LEFT JOIN $users_table u ON r.user_id = u.ID
               WHERE 1=1";

// Yetkilere göre sorguyu düzenle
if ($user_role_level === 1 || $user_role_level === 2 || $user_role_level === 3) {
    if (!$is_team_view) {
        // Kişisel görünüm: Sadece kendi görevlerini göster
        $base_query .= $wpdb->prepare(" AND t.representative_id = %d", $current_user_rep_id);
    }
    // Ekip görünümünde tüm görevleri görebilir (Patron, Müdür, Müdür Yardımcısı)
} else if ($user_role_level === 4) {
    // Ekip lideri ise
    if ($is_team_view) {
        // Ekip görünümü: Ekipteki tüm temsilcilerin görevlerini göster
        $team_member_ids = get_team_members_ids(get_current_user_id());
        if (!empty($team_member_ids)) {
            // Validate and filter team member IDs
            $valid_member_ids = array();
            foreach ($team_member_ids as $member_id) {
                if (!empty($member_id) && is_numeric($member_id)) {
                    $valid_member_ids[] = (int) $member_id;
                }
            }
            
            if (!empty($valid_member_ids)) {
                $member_placeholders = implode(',', array_fill(0, count($valid_member_ids), '%d'));
                $base_query .= $wpdb->prepare(" AND t.representative_id IN ($member_placeholders)", ...$valid_member_ids);
            } else {
                $base_query .= $wpdb->prepare(" AND t.representative_id = %d", $current_user_rep_id);
            }
        } else {
            // Ekip yoksa sadece kendi görevlerini görsün
            $base_query .= $wpdb->prepare(" AND t.representative_id = %d", $current_user_rep_id);
        }
    } else {
        // Kişisel görünüm: Sadece kendi görevlerini göster
        $base_query .= $wpdb->prepare(" AND t.representative_id = %d", $current_user_rep_id);
    }
} else {
    // Normal müşteri temsilcisi sadece kendi görevlerini görebilir
    $base_query .= $wpdb->prepare(" AND t.representative_id = %d", $current_user_rep_id);
}

// İstatistik kartları için sadece kullanıcı/ekip kapsamı olan ayrı sorgu (show_completed filtresi uygulanmadan önce)
$stat_base_query = $base_query;

// Show completed tasks filter (only applies to page display, not statistics)
if (!$filters['show_completed']) {
    $base_query .= " AND t.status != 'completed'";
}

// Filtreleri ekle
if (!empty($filters['customer_id'])) {
    $base_query .= $wpdb->prepare(" AND t.customer_id = %d", $filters['customer_id']);
}
if (!empty($filters['priority'])) {
    $base_query .= $wpdb->prepare(" AND t.priority = %s", $filters['priority']);
}
if (!empty($filters['status'])) {
    $base_query .= $wpdb->prepare(" AND t.status = %s", $filters['status']);
}
if (!empty($filters['task_title'])) {
    $base_query .= $wpdb->prepare(
        " AND (t.task_title LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s OR p.policy_number LIKE %s)",
        '%' . $wpdb->esc_like($filters['task_title']) . '%',
        '%' . $wpdb->esc_like($filters['task_title']) . '%',
        '%' . $wpdb->esc_like($filters['task_title']) . '%',
        '%' . $wpdb->esc_like($filters['task_title']) . '%'
    );
}
if (!empty($filters['due_date'])) {
    $base_query .= $wpdb->prepare(" AND DATE(t.due_date) = %s", $filters['due_date']);
}

// Zaman filtresi (bugün, bu hafta, bu ay)
if (!empty($filters['time_filter'])) {
    $today_date = date('Y-m-d');
    switch ($filters['time_filter']) {
        case 'today':
            $base_query .= $wpdb->prepare(" AND DATE(t.due_date) = %s", $today_date);
            break;
        case 'tomorrow':
            $tomorrow = date('Y-m-d', strtotime('+1 day'));
            $base_query .= $wpdb->prepare(" AND DATE(t.due_date) = %s", $tomorrow);
            break;
        case 'this_week':
            $week_start = date('Y-m-d', strtotime('monday this week'));
            $week_end = date('Y-m-d', strtotime('sunday this week'));
            $base_query .= $wpdb->prepare(" AND DATE(t.due_date) BETWEEN %s AND %s", $week_start, $week_end);
            break;
        case 'next_week':
            $week_start = date('Y-m-d', strtotime('monday next week'));
            $week_end = date('Y-m-d', strtotime('sunday next week'));
            $base_query .= $wpdb->prepare(" AND DATE(t.due_date) BETWEEN %s AND %s", $week_start, $week_end);
            break;
        case 'this_month':
            $month_start = date('Y-m-01');
            $month_end = date('Y-m-t');
            $base_query .= $wpdb->prepare(" AND DATE(t.due_date) BETWEEN %s AND %s", $month_start, $month_end);
            break;
        case 'next_month':
            $next_month_start = date('Y-m-01', strtotime('first day of next month'));
            $next_month_end = date('Y-m-t', strtotime('first day of next month'));
            $base_query .= $wpdb->prepare(" AND DATE(t.due_date) BETWEEN %s AND %s", $next_month_start, $next_month_end);
            break;
        case 'overdue':
            $base_query .= $wpdb->prepare(" AND DATE(t.due_date) < %s AND t.status NOT IN ('completed', 'cancelled')", $today_date);
            break;
    }
}

// İSTATİSTİK HESAPLAMALARI
// Toplam görev sayısı
$total_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query);

// Bugünkü görevler
$today_date = date('Y-m-d');
$today_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . $wpdb->prepare(" AND DATE(t.due_date) = %s", $today_date));

// Yarınki görevler
$tomorrow_date = date('Y-m-d', strtotime('+1 day'));
$tomorrow_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . $wpdb->prepare(" AND DATE(t.due_date) = %s", $tomorrow_date));

// Bu haftaki görevler
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));
$this_week_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . $wpdb->prepare(" AND DATE(t.due_date) BETWEEN %s AND %s", $week_start, $week_end));

// Gelecek haftaki görevler
$next_week_start = date('Y-m-d', strtotime('monday next week'));
$next_week_end = date('Y-m-d', strtotime('sunday next week'));
$next_week_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . $wpdb->prepare(" AND DATE(t.due_date) BETWEEN %s AND %s", $next_week_start, $next_week_end));

// Bu ayki görevler
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');
$this_month_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . $wpdb->prepare(" AND DATE(t.due_date) BETWEEN %s AND %s", $month_start, $month_end));

// Gelecek ayki görevler
$next_month_start = date('Y-m-01', strtotime('first day of next month'));
$next_month_end = date('Y-m-t', strtotime('first day of next month'));
$next_month_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . $wpdb->prepare(" AND DATE(t.due_date) BETWEEN %s AND %s", $next_month_start, $next_month_end));

// Gecikmiş görevler
$overdue_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . $wpdb->prepare(" AND DATE(t.due_date) < %s AND t.status NOT IN ('completed', 'cancelled')", $today_date));

// Bu ay eklenen görevler
$this_month_start_date = date('Y-m-01 00:00:00');
$created_this_month = $wpdb->get_var("SELECT COUNT(*) " . $base_query . $wpdb->prepare(" AND t.created_at >= %s", $this_month_start_date));

// Durum bazlı görev sayıları
// İstatistik kartı için doğru tamamlanan görev sayısı (sadece kullanıcı/ekip kapsamı)
$completed_tasks_for_stats = $wpdb->get_var("SELECT COUNT(*) " . $stat_base_query . " AND t.status = 'completed'");
$total_tasks_for_stats = $wpdb->get_var("SELECT COUNT(*) " . $stat_base_query);

// Sayfa filtreleri ile birlikte durum bazlı görev sayıları (grafik için)
$completed_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . " AND t.status = 'completed'");
$pending_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . " AND t.status = 'pending'");
$in_progress_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . " AND t.status = 'in_progress'");
$cancelled_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . " AND t.status = 'cancelled'");

// Öncelik bazlı görev sayıları
$high_priority_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . " AND t.priority = 'high'");
$medium_priority_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . " AND t.priority = 'medium'");
$low_priority_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . " AND t.priority = 'low'");

// Grafik verileri
$status_chart_data = array(
    'Beklemede' => (int)$pending_tasks,
    'İşlemde' => (int)$in_progress_tasks,
    'Tamamlandı' => (int)$completed_tasks,
    'İptal Edildi' => (int)$cancelled_tasks
);

$priority_chart_data = array(
    'Yüksek' => (int)$high_priority_tasks,
    'Orta' => (int)$medium_priority_tasks,
    'Düşük' => (int)$low_priority_tasks
);

$time_chart_data = array(
    'Bugün' => (int)$today_tasks,
    'Yarın' => (int)$tomorrow_tasks,
    'Bu Hafta' => (int)$this_week_tasks,
    'Gelecek Hafta' => (int)$next_week_tasks,
    'Bu Ay' => (int)$this_month_tasks,
    'Gelecek Ay' => (int)$next_month_tasks,
    'Gecikmiş' => (int)$overdue_tasks
);

// 0 değerlerini filtrele
$status_chart_data = array_filter($status_chart_data);
$priority_chart_data = array_filter($priority_chart_data);
$time_chart_data = array_filter($time_chart_data);

// Listede gösterilecek görevleri getir
$orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 't.due_date';
$order = isset($_GET['order']) && in_array(strtoupper($_GET['order']), array('ASC', 'DESC')) ? strtoupper($_GET['order']) : 'DESC';

$tasks = $wpdb->get_results("
    SELECT t.*, 
           c.first_name, c.last_name, 
           p.policy_number, 
           u.display_name as rep_name 
    " . $base_query . " 
    ORDER BY $orderby $order 
    LIMIT $per_page OFFSET $offset
");

// Toplam kayıt sayısını al (for pagination, uses the same $base_query)
$total_items = $total_tasks;

// Müşterileri al (for filter dropdown)
$customers_query = "";
if ($user_role_level === 5 || ($user_role_level === 4 && !$is_team_view)) {
    // Müşteri Temsilcisi veya Ekip Lideri (kendi görünümü) - sadece kendi müşterilerini görsün
    $customers_query .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
} elseif ($user_role_level === 4 && $is_team_view) {
    // Ekip Lideri (ekip görünümü) - ekip üyelerinin müşterilerini görsün
    $team_member_ids = get_team_members_ids(get_current_user_id());
    if (!empty($team_member_ids)) {
        // Validate and filter team member IDs
        $valid_member_ids = array();
        foreach ($team_member_ids as $member_id) {
            if (!empty($member_id) && is_numeric($member_id)) {
                $valid_member_ids[] = (int) $member_id;
            }
        }
        
        if (!empty($valid_member_ids)) {
            $placeholders = implode(',', array_fill(0, count($valid_member_ids), '%d'));
            $customers_query .= $wpdb->prepare(" AND c.representative_id IN ($placeholders)", ...$valid_member_ids);
        } else {
            $customers_query .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
        }
    } else {
        $customers_query .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
    }
}

$customers = $wpdb->get_results("
    SELECT id, first_name, last_name 
    FROM $customers_table c
    WHERE status = 'aktif' $customers_query
    ORDER BY first_name, last_name
");

// Sayfalama
$total_pages = ceil($total_items / $per_page);

// Aktif action belirle
$current_action = isset($_GET['action']) ? $_GET['action'] : '';
$show_list = ($current_action !== 'view' && $current_action !== 'edit' && $current_action !== 'new');

// Belirli bir gün için görev var mı kontrolü
$has_tasks_for_date = false;
$selected_date_formatted = '';
$no_tasks_message = '';

if (!empty($filters['due_date'])) {
    try {
        $selected_date = new DateTime($filters['due_date']);
        $selected_date_formatted = $selected_date->format('d.m.Y');
    } catch (Exception $e) {
        $selected_date_formatted = $filters['due_date']; // fallback to raw date
    }
    $has_tasks_for_date = !empty($tasks);
    
    if (!$has_tasks_for_date && empty($filters['customer_id']) && empty($filters['priority']) && empty($filters['status']) && empty($filters['task_title'])) {
        $no_tasks_message = '<div class="empty-state">
            <div class="empty-icon">
                <i class="fas fa-calendar-day"></i>
            </div>
            <h3>' . $selected_date_formatted . ' tarihi için görev bulunamadı</h3>
            <p>Bu tarih için henüz görev ataması yapılmamış veya filtrelerinize uyan görev yok.</p>
            <a href="?view=tasks&action=new&due_date=' . esc_attr($filters['due_date']) . '" class="btn btn-primary">
                <i class="fas fa-plus"></i> Yeni Görev Ekle
            </a>
        </div>';
    }
}

// Aktif filtre sayısı
$active_filter_count = count(array_filter($filters, function($value) { return !empty($value); }));

// Kullanıcının rol ismi
function get_role_name($role_level) {
    $roles = [1 => 'Patron', 2 => 'Müdür', 3 => 'Müdür Yardımcısı', 4 => 'Ekip Lideri', 5 => 'Müşteri Temsilcisi'];
    return $roles[$role_level] ?? 'Bilinmiyor';
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Görev Yönetimi - Modern CRM v3.1.0</title>
    
    <!-- External Libraries -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- jQuery for daterangepicker (if needed) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<div class="modern-crm-container" id="tasks-container" <?php echo !$show_list ? 'style="display:none;"' : ''; ?>>
    
    <?php echo $notice; ?>
    
    <!-- Header Section -->
    <header class="crm-header">
        <div class="header-content">
            <div class="title-section">
                <div class="page-title">
                    <i class="fas fa-tasks"></i>
                    <h1>Görev Yönetimi</h1>
                    <span class="version-badge">v3.1.0</span>
                </div>
                
                <div class="user-badge">
                    <span class="role-badge">
                        <i class="fas fa-user-shield"></i>
                        <?php echo esc_html(get_role_name($user_role_level)); ?>
                    </span>
                </div>
            </div>
            
            <div class="header-actions">
                <?php if ($user_role_level <= 4): // Ekip lideri ve üstü için görünüm seçimi ?>
                <div class="view-toggle">
                    <a href="?view=tasks&view_type=personal" class="view-btn <?php echo !$is_team_view ? 'active' : ''; ?>">
                        <i class="fas fa-user"></i>
                        <span>Kişisel</span>
                    </a>
                    <a href="?view=tasks&view_type=team" class="view-btn <?php echo $is_team_view ? 'active' : ''; ?>">
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
                    <a href="?view=tasks<?php echo $is_team_view ? '&view_type=team' : ''; ?>" class="btn btn-ghost clear-filters">
                        <i class="fas fa-times"></i>
                        <span>Temizle</span>
                    </a>
                    <?php endif; ?>
                </div>

                <a href="?view=tasks&action=new<?php echo !empty($filters['due_date']) ? '&due_date=' . esc_attr($filters['due_date']) : ''; ?><?php echo $is_team_view ? '&view_type=team' : ''; ?>" class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    <span>Yeni Görev</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Dashboard Section - Statistics -->
    <section class="dashboard-section" id="dashboardSection" <?php echo $active_filter_count > 0 ? 'style="display:none;"' : ''; ?>>
        <div class="stats-cards">
            <div class="stat-card primary">
                <div class="stat-icon">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="stat-content">
                    <h3>Toplam Görev</h3>
                    <div class="stat-value"><?php echo number_format($total_tasks); ?></div>
                    <div class="stat-subtitle">
                        <?php 
                        if ($is_team_view) echo 'Ekip Toplamı';
                        else echo 'Kişisel Toplam';
                        ?>
                    </div>
                </div>
            </div>

            <div class="stat-card success">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <h3>Tamamlanan</h3>
                    <div class="stat-value"><?php echo number_format($completed_tasks_for_stats); ?></div>
                    <div class="stat-subtitle">
                        <?php echo $total_tasks_for_stats > 0 ? number_format(($completed_tasks_for_stats / $total_tasks_for_stats) * 100, 1) : 0; ?>% Tamamlama
                    </div>
                </div>
            </div>

            <div class="stat-card warning">
                <div class="stat-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-content">
                    <h3>Gecikmiş Görev</h3>
                    <div class="stat-value"><?php echo number_format($overdue_tasks); ?></div>
                    <div class="stat-subtitle">
                        Acil ilgilenilmeli
                    </div>
                </div>
            </div>

            <div class="stat-card danger">
                <div class="stat-icon">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="stat-content">
                    <h3>Bugünkü Görevler</h3>
                    <div class="stat-value"><?php echo number_format($today_tasks); ?></div>
                    <div class="stat-subtitle">
                        <?php echo date('d.m.Y'); ?>
                    </div>
                </div>
            </div>

            <div class="stat-card info">
                <div class="stat-icon">
                    <i class="fas fa-calendar-plus"></i>
                </div>
                <div class="stat-content">
                    <h3>Yarınki Görevler</h3>
                    <div class="stat-value"><?php echo number_format($tomorrow_tasks); ?></div>
                    <div class="stat-subtitle">
                        <?php echo date('d.m.Y', strtotime('+1 day')); ?>
                    </div>
                </div>
            </div>

            <div class="stat-card secondary">
                <div class="stat-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-content">
                    <h3>Bu Ayki Görevler</h3>
                    <div class="stat-value"><?php echo number_format($this_month_tasks); ?></div>
                    <div class="stat-subtitle">
                        Bu Ay İçin
                    </div>
                </div>
            </div>
        </div>

        <div class="charts-section">
            <div class="section-header">
                <h2>
                    <i class="fas fa-chart-pie"></i>
                    <?php if ($is_team_view && $user_role_level <= 4): ?>
                        Ekip Görevi İstatistikleri
                    <?php else: ?>
                        <?php echo $user_role_level <= 3 && !$is_team_view ? 'Kişisel Görev İstatistikleri' : 'Görev İstatistikleri'; ?>
                    <?php endif; ?>
                </h2>
                <button type="button" id="chartsToggle" class="btn btn-ghost">
                    <i class="fas fa-chevron-up"></i>
                </button>
            </div>
            
            <div class="charts-container" id="chartsContainer">
                <div class="chart-grid">
                    <!-- Chart canvases will be populated by JavaScript -->
                    <div class="chart-item">
                        <h4>Durum Dağılımı</h4>
                        <div class="chart-canvas">
                            <canvas id="taskStatusChart"></canvas>
                        </div>
                    </div>
                    
                    <div class="chart-item">
                        <h4>Öncelik Dağılımı</h4>
                        <div class="chart-canvas">
                            <canvas id="taskPriorityChart"></canvas>
                        </div>
                    </div>
                    
                    <div class="chart-item">
                        <h4>Zaman Bazlı Görev Dağılımı</h4>
                        <div class="chart-canvas">
                            <canvas id="taskTimeChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Zaman Bazlı Görev Filtreleri -->
    <section class="time-filters-section">
        <div class="time-filter-tabs">
            <a href="?view=tasks<?php echo $is_team_view ? '&view_type=team' : ''; ?>&time_filter=today" class="time-filter-tab <?php echo $filters['time_filter'] === 'today' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-day"></i> Bugün
                <span class="tab-badge"><?php echo $today_tasks; ?></span>
            </a>
            <a href="?view=tasks<?php echo $is_team_view ? '&view_type=team' : ''; ?>&time_filter=tomorrow" class="time-filter-tab <?php echo $filters['time_filter'] === 'tomorrow' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i> Yarın
                <span class="tab-badge"><?php echo $tomorrow_tasks; ?></span>
            </a>
            <a href="?view=tasks<?php echo $is_team_view ? '&view_type=team' : ''; ?>&time_filter=this_week" class="time-filter-tab <?php echo $filters['time_filter'] === 'this_week' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-week"></i> Bu Hafta
                <span class="tab-badge"><?php echo $this_week_tasks; ?></span>
            </a>
            <a href="?view=tasks<?php echo $is_team_view ? '&view_type=team' : ''; ?>&time_filter=next_week" class="time-filter-tab <?php echo $filters['time_filter'] === 'next_week' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-plus"></i> Gelecek Hafta
                <span class="tab-badge"><?php echo $next_week_tasks; ?></span>
            </a>
            <a href="?view=tasks<?php echo $is_team_view ? '&view_type=team' : ''; ?>&time_filter=this_month" class="time-filter-tab <?php echo $filters['time_filter'] === 'this_month' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-check"></i> Bu Ay
                <span class="tab-badge"><?php echo $this_month_tasks; ?></span>
            </a>
            <a href="?view=tasks<?php echo $is_team_view ? '&view_type=team' : ''; ?>&time_filter=overdue" class="time-filter-tab <?php echo $filters['time_filter'] === 'overdue' ? 'active warning-tab' : ''; ?>">
                <i class="fas fa-exclamation-circle"></i> Gecikmiş
                <span class="tab-badge tab-badge-warning"><?php echo $overdue_tasks; ?></span>
            </a>
        </div>
    </section>

    <!-- Filters Section -->
    <section class="filters-section <?php echo $active_filter_count === 0 ? 'hidden' : ''; ?>" id="filtersSection">
        <div class="filters-container">
            <form method="get" class="filters-form" action="">
                <input type="hidden" name="view" value="tasks">
                <?php if ($is_team_view): ?>
                <input type="hidden" name="view_type" value="team">
                <?php endif; ?>
                
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="customer_id">Müşteri</label>
                        <select id="customer_id" name="customer_id" class="form-select">
                            <option value="">Tüm Müşteriler</option>
                            <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo $customer->id; ?>" <?php selected($filters['customer_id'], $customer->id); ?>>
                                <?php echo esc_html($customer->first_name . ' ' . $customer->last_name); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="priority">Öncelik</label>
                        <select id="priority" name="priority" class="form-select">
                            <option value="">Tüm Öncelikler</option>
                            <option value="low" <?php selected($filters['priority'], 'low'); ?>>Düşük</option>
                            <option value="medium" <?php selected($filters['priority'], 'medium'); ?>>Orta</option>
                            <option value="high" <?php selected($filters['priority'], 'high'); ?>>Yüksek</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="status">Durum</label>
                        <select id="status" name="status" class="form-select">
                            <option value="">Tüm Durumlar</option>
                            <option value="pending" <?php selected($filters['status'], 'pending'); ?>>Beklemede</option>
                            <option value="in_progress" <?php selected($filters['status'], 'in_progress'); ?>>İşlemde</option>
                            <option value="completed" <?php selected($filters['status'], 'completed'); ?>>Tamamlandı</option>
                            <option value="cancelled" <?php selected($filters['status'], 'cancelled'); ?>>İptal Edildi</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="due_date">Son Tarih</label>
                        <input type="date" name="due_date" id="due_date" class="form-input" value="<?php echo esc_attr($filters['due_date']); ?>">
                    </div>

                    <div class="filter-group">
                        <label for="task_title">Görev Tanımı</label>
                        <input type="text" name="task_title" id="task_title" class="form-input" value="<?php echo esc_attr($filters['task_title']); ?>" placeholder="Görev Ara...">
                    </div>

                    <div class="filter-group checkbox-group">
                        <label for="show_completed" class="checkbox-label">
                            <input type="checkbox" name="show_completed" id="show_completed" value="1" <?php checked($filters['show_completed']); ?>>
                            <span class="checkmark"></span>
                            Tamamlanan görevleri göster
                        </label>
                    </div>
                </div>

                <div class="filters-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i>
                        <span>Filtrele</span>
                    </button>
                    <a href="?view=tasks<?php echo $is_team_view ? '&view_type=team' : ''; ?>" class="btn btn-outline">
                        <i class="fas fa-undo"></i>
                        <span>Sıfırla</span>
                    </a>
                </div>
            </form>
        </div>
    </section>

    <!-- Tasks Table -->
    <section class="table-section">
        <?php if (!empty($no_tasks_message) && empty($tasks)): // Show only if no tasks and specific message exists ?>
            <?php echo $no_tasks_message; ?>
        <?php elseif (!empty($tasks)): ?>
        <div class="table-wrapper">
            <div class="table-header">
                <div class="table-info">
                    <div class="table-meta">
                        <span>Toplam: <strong><?php echo number_format($total_items); ?></strong> görev</span>
                        <?php if ($is_team_view): ?>
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
                    </div>
                    
                    <!-- PER PAGE SELECTOR -->
                    <div class="table-controls">
                        <div class="per-page-selector">
                            <label for="per_page">Sayfa başına:</label>
                            <select id="per_page" name="per_page" class="form-select" onchange="updatePerPage(this.value)">
                                <?php foreach ([15, 25, 50, 100] as $option): ?>
                                <option value="<?php echo $option; ?>" <?php selected($per_page, $option); ?>>
                                    <?php echo $option; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-header-scroll">
                <div style="width: 1200px;"></div>
            </div>

            <div class="table-container">
                <table class="tasks-table">
                    <thead>
                        <tr>
                            <th>
                                Görev Tanımı
                            </th>
                            <th>Müşteri</th>
                            <th>Poliçe</th>
                            <th>
                                Son Tarih
                            </th>
                            <th>
                                Öncelik
                            </th>
                            <th>
                                Durum
                            </th>
                            <?php if ($is_team_view || $user_role_level <= 3): ?>
                            <th>Temsilci</th>
                            <?php endif; ?>
                            <th class="actions-column">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tasks as $task): 
                            // Task status logic
                            $task_due_time = strtotime($task->due_date);
                            $is_overdue = $task_due_time < time() && $task->status !== 'completed' && $task->status !== 'cancelled';
                            $is_today = date('Y-m-d') == date('Y-m-d', $task_due_time);
                            
                            $row_class = '';
                            if ($is_overdue) {
                                $row_class = 'overdue';
                            } elseif ($is_today) {
                                $row_class = 'today';
                            }
                            
                            switch ($task->status) {
                                case 'completed': $row_class .= ' task-completed'; break;
                                case 'in_progress': $row_class .= ' task-in-progress'; break;
                                case 'cancelled': $row_class .= ' task-cancelled'; break;
                                default: $row_class .= ' task-pending';
                            }
                            
                            $row_class .= ' priority-' . $task->priority;
                        ?>
                        <tr class="<?php echo trim($row_class); ?>" data-task-id="<?php echo $task->id; ?>">
                            <td class="task-title" data-label="Görev">
                                <a href="?view=tasks&action=view&id=<?php echo $task->id; ?>" class="task-link">
                                    <?php echo esc_html($task->task_title); ?>
                                </a>
                                <div class="task-badges">
                                    <?php if ($is_overdue): ?>
                                    <span class="badge overdue">Gecikmiş!</span>
                                    <?php elseif ($is_today): ?>
                                    <span class="badge today">Bugün</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="customer" data-label="Müşteri">
                                <?php if (!empty($task->customer_id)): ?>
                                <a href="?view=customers&action=view&id=<?php echo $task->customer_id; ?>" class="customer-link">
                                    <?php echo esc_html($task->first_name . ' ' . $task->last_name); ?>
                                </a>
                                <?php else: ?>
                                <span class="no-value">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="policy" data-label="Poliçe">
                                <?php if (!empty($task->policy_id) && !empty($task->policy_number)): ?>
                                <a href="?view=policies&action=view&id=<?php echo $task->policy_id; ?>" class="policy-link">
                                    <?php echo esc_html($task->policy_number); ?>
                                </a>
                                <?php else: ?>
                                <span class="no-value">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="end-date" data-label="Son Tarih">
                                <span class="due-date <?php echo $is_overdue ? 'overdue' : ($is_today ? 'today' : ''); ?>">
                                    <?php echo date('d.m.Y H:i', $task_due_time); ?>
                                </span>
                            </td>
                            <td class="priority" data-label="Öncelik">
                                <span class="status-badge priority-<?php echo esc_attr($task->priority); ?>">
                                    <?php 
                                    switch ($task->priority) {
                                        case 'low': echo 'Düşük'; break;
                                        case 'medium': echo 'Orta'; break;
                                        case 'high': echo 'Yüksek'; break;
                                        default: echo ucfirst($task->priority); break;
                                    }
                                    ?>
                                </span>
                            </td>
                            <td class="status" data-label="Durum">
                                <span class="status-badge status-<?php echo esc_attr($task->status); ?>">
                                    <?php 
                                    switch ($task->status) {
                                        case 'pending': echo 'Beklemede'; break;
                                        case 'in_progress': echo 'İşlemde'; break;
                                        case 'completed': echo 'Tamamlandı'; break;
                                        case 'cancelled': echo 'İptal Edildi'; break;
                                        default: echo ucfirst($task->status); break;
                                    }
                                    ?>
                                </span>
                            </td>
                            <?php if ($is_team_view || $user_role_level <= 3): ?>
                            <td class="representative" data-label="Temsilci"><?php echo !empty($task->rep_name) ? esc_html($task->rep_name) : '<span class="no-value">—</span>'; ?></td>
                            <?php endif; ?>
                            <td class="actions" data-label="İşlemler">
                                <div class="action-buttons-group">
                                    <div class="all-actions">
                                        <!-- Temel Butonlar -->
                                        <a href="?view=tasks&action=view&id=<?php echo $task->id; ?>" 
                                        class="btn btn-xs btn-primary" title="Görüntüle">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <?php 
                                        // Düzenleme izni kontrolü
                                        $can_edit = $is_wp_admin_or_manager || is_patron($current_user_id) || is_manager($current_user_id);
                                        
                                        if (!$can_edit && $current_user_rep_id && $task->representative_id == $current_user_rep_id) {
                                            $can_edit = true; // Kendi görevlerini düzenleyebilir
                                        }
                                        
                                        if (!$can_edit && is_team_leader($current_user_id)) {
                                            $team_members = get_team_members_ids($current_user_id);
                                            if (in_array($task->representative_id, $team_members)) {
                                                $can_edit = true; // Ekip üyelerinin görevlerini düzenleyebilir
                                            }
                                        }
                                        
                                        // Tamamlama izni kontrolü
                                        $can_complete = $can_edit && $task->status !== 'completed';
                                        ?>
                                        
                                        <?php if ($can_edit): ?>
                                        <a href="?view=tasks&action=edit&id=<?php echo $task->id; ?>" 
                                        class="btn btn-xs btn-outline" title="Düzenle">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($can_complete): ?>
                                        <a href="<?php echo wp_nonce_url('?view=tasks&action=complete&id=' . $task->id . ($is_team_view ? '&view_type=team' : ''), 'complete_task_' . $task->id); ?>" 
                                        class="btn btn-xs btn-success" title="Tamamla"
                                        onclick="return confirm('Bu görevi tamamlandı olarak işaretlemek istediğinizden emin misiniz?');">
                                            <i class="fas fa-check"></i>
                                        </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($can_delete_tasks): ?>
                                        <a href="<?php echo wp_nonce_url('?view=tasks&action=delete&id=' . $task->id . ($is_team_view ? '&view_type=team' : ''), 'delete_task_' . $task->id); ?>" 
                                        class="btn btn-xs btn-danger" title="Sil"
                                        onclick="return confirm('Bu görevi silmek istediğinizden emin misiniz?');">
                                            <i class="fas fa-trash"></i>
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
                    $pagination_args = [
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => '<i class="fas fa-chevron-left"></i>',
                        'next_text' => '<i class="fas fa-chevron-right"></i>',
                        'total' => $total_pages,
                        'current' => $current_page,
                        'add_args' => array_filter($_GET, fn($key) => $key !== 'paged', ARRAY_FILTER_USE_KEY)
                    ];
                    echo paginate_links($pagination_args);
                    ?>
                </nav>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon">
                <i class="fas fa-tasks"></i>
            </div>
            <h3>Görev bulunamadı</h3>
            <p>Arama kriterlerinize uygun görev bulunamadı veya bu görünüm için atanmış görev yok.</p>
            <a href="?view=tasks<?php echo $is_team_view ? '&view_type=team' : ''; ?>" class="btn btn-primary">
                <i class="fas fa-refresh"></i>
                Tüm Görevleri Göster
            </a>
        </div>
        <?php endif; ?>
    </section>
</div>

<?php
// Eğer action=view, action=new veya action=edit ise ilgili dosyayı dahil et
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'view': if (isset($_GET['id'])) { include_once('task-view.php'); } break;
        case 'new': case 'edit': include_once('task-form.php'); break;
    }
}
?>

<style>
/* Modern CSS Styles with Material Design 3 Principles - v3.1.0 */
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
}

.btn-secondary {
    background: #757575;
    color: white;
}

.btn-secondary:hover {
    background: #616161;
    transform: translateY(-1px);
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
}

.btn-ghost {
    background: transparent;
    color: var(--on-surface-variant);
}

.btn-ghost:hover {
    background: var(--surface-variant);
    color: var(--on-surface);
}

.btn-success {
    background: var(--success);
    color: white;
}

.btn-success:hover {
    background: #2e7d32;
    transform: translateY(-1px);
}

.btn-warning {
    background: var(--warning);
    color: white;
}

.btn-warning:hover {
    background: #ef6c00;
    transform: translateY(-1px);
}

.btn-danger {
    background: var(--danger);
    color: white;
}

.btn-danger:hover {
    background: #c62828;
    transform: translateY(-1px);
}

.btn-info {
    background: var(--info);
    color: white;
}

.btn-info:hover {
    background: #0277bd;
    transform: translateY(-1px);
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

.stat-card.danger:before {
    background: linear-gradient(90deg, var(--danger), #f44336);
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

.stat-card.danger .stat-icon {
    background: linear-gradient(135deg, var(--danger), #f44336);
}

.stat-card.info .stat-icon {
    background: linear-gradient(135deg, var(--info), #03a9f4);
}

.stat-card.secondary .stat-icon {
    background: linear-gradient(135deg, var(--secondary), #ab47bc);
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
    grid-template-columns: repeat(3, 1fr);
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
    height: 300px;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.chart-canvas canvas {
    max-width: 100%;
    max-height: 100%;
}

/* Time Filter Tabs */
.time-filters-section {
    margin-bottom: var(--spacing-xl);
}

.time-filter-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: var(--spacing-sm);
    padding: var(--spacing-md);
    background-color: var(--surface);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
}

.time-filter-tab {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    padding: var(--spacing-sm) var(--spacing-md);
    border-radius: var(--radius-xl);
    font-size: var(--font-size-sm);
    text-decoration: none;
    color: var(--on-surface-variant);
    transition: all var(--transition-fast);
}

.time-filter-tab:hover {
    background: var(--surface-variant);
    transform: translateY(-1px);
}

.time-filter-tab.active {
    background: var(--primary);
    color: white;
}

.time-filter-tab.warning-tab {
    color: var(--warning);
}

.time-filter-tab.warning-tab.active {
    background: var(--warning);
    color: white;
}

.tab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 22px;
    padding: 0 6px;
    border-radius: 11px;
    font-size: 11px;
    font-weight: 600;
    background: rgba(0, 0, 0, 0.1);
}

.time-filter-tab.active .tab-badge {
    background: rgba(255, 255, 255, 0.3);
}

.tab-badge-warning {
    background: rgba(211, 47, 47, 0.1);
    color: var(--danger);
}

.time-filter-tab.active .tab-badge-warning {
    background: rgba(255, 255, 255, 0.3);
    color: white;
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

/* Checkbox styles for filters */
.filter-group.checkbox-group {
    justify-content: center;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    cursor: pointer;
    font-size: var(--font-size-sm);
    font-weight: 500;
    color: var(--on-surface);
}

.checkbox-label input[type="checkbox"] {
    appearance: none;
    width: 18px;
    height: 18px;
    border: 2px solid var(--outline);
    border-radius: var(--radius-sm);
    position: relative;
    cursor: pointer;
    transition: all var(--transition-fast);
}

.checkbox-label input[type="checkbox"]:checked {
    background: var(--primary);
    border-color: var(--primary);
}

.checkbox-label input[type="checkbox"]:checked::after {
    content: '✓';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: white;
    font-size: 12px;
    font-weight: bold;
}

.checkbox-label .checkmark {
    display: none;
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

/* Table Section */
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

.table-controls {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
}

.per-page-selector {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.per-page-selector label {
    font-size: var(--font-size-sm);
    font-weight: 500;
    color: var(--on-surface);
    white-space: nowrap;
}

.per-page-selector select {
    padding: 4px 8px;
    border: 1px solid var(--outline-variant);
    border-radius: var(--radius-md);
    font-size: var(--font-size-sm);
    background: var(--surface);
    color: var(--on-surface);
    min-width: 60px;
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

.table-container {
    overflow-x: auto;
}

.tasks-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1200px;
}

.tasks-table th,
.tasks-table td {
    padding: var(--spacing-md);
    text-align: left;
    border-bottom: 1px solid var(--outline-variant);
    font-size: var(--font-size-sm);
    vertical-align: middle;
}

.tasks-table th {
    background: var(--surface-variant);
    font-weight: 600;
    color: var(--on-surface);
    position: sticky;
    top: 0;
    z-index: 1;
}

.tasks-table th a {
    color: inherit;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

.tasks-table th a:hover {
    color: var(--primary);
}

.tasks-table tbody tr {
    transition: all var(--transition-fast);
}

.tasks-table tbody tr:hover {
    background: var(--surface-variant);
}

/* Row Status Colors */
.tasks-table tr.overdue td {
    background: rgba(211, 47, 47, 0.05) !important;
    border-left: 3px solid var(--danger);
}

.tasks-table tr.today td {
    background: rgba(245, 124, 0, 0.05) !important;
    border-left: 3px solid var(--warning);
}

.tasks-table tr.task-completed td {
    background: rgba(46, 125, 50, 0.05) !important;
    border-left: 3px solid var(--success);
}

.tasks-table tr.task-in-progress td {
    background: rgba(25, 118, 210, 0.05) !important;
    border-left: 3px solid var(--primary);
}

.tasks-table tr.task-cancelled td {
    background: rgba(117, 117, 117, 0.05) !important;
    border-left: 3px solid #757575;
}

/* Task Priority Colors */
.tasks-table tr.priority-high td {
    border-left-width: 4px;
}

/* Table Cell Specific Styles */
.task-title {
    min-width: 200px;
}

.task-link {
    font-weight: 600;
    color: var(--primary);
    text-decoration: none;
    display: block;
    margin-bottom: var(--spacing-xs);
}

.task-link:hover {
    text-decoration: underline;
}

.task-badges {
    display: flex;
    flex-wrap: wrap;
    gap: var(--spacing-xs);
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

.badge.overdue {
    background: rgba(211, 47, 47, 0.1);
    color: var(--danger);
    border: 1px solid rgba(211, 47, 47, 0.2);
}

.badge.today {
    background: rgba(245, 124, 0, 0.1);
    color: var(--warning);
    border: 1px solid rgba(245, 124, 0, 0.2);
}

.customer-link, .policy-link {
    color: var(--on-surface);
    text-decoration: none;
    font-weight: 500;
}

.customer-link:hover, .policy-link:hover {
    color: var(--primary);
    text-decoration: underline;
}

.end-date {
    white-space: nowrap;
}

.due-date {
    display: inline-block;
}

.due-date.overdue {
    color: var(--danger);
    font-weight: 500;
}

.due-date.today {
    color: var(--warning);
    font-weight: 500;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    padding: var(--spacing-xs) var(--spacing-sm);
    border-radius: var(--radius-lg);
    font-size: var(--font-size-sm);
    font-weight: 500;
}

/* Durum rozetleri */
.status-badge.status-pending {
    background: rgba(25, 118, 210, 0.1);
    color: var(--primary);
}

.status-badge.status-in_progress {
    background: rgba(245, 124, 0, 0.1);
    color: var(--warning);
}

.status-badge.status-completed {
    background: rgba(46, 125, 50, 0.1);
    color: var(--success);
}

.status-badge.status-cancelled {
    background: rgba(117, 117, 117, 0.1);
    color: #757575;
}

/* Öncelik rozetleri */
.status-badge.priority-high {
    background: rgba(211, 47, 47, 0.1);
    color: var(--danger);
}

.status-badge.priority-medium {
    background: rgba(245, 124, 0, 0.1);
    color: var(--warning);
}

.status-badge.priority-low {
    background: rgba(46, 125, 50, 0.1);
    color: var(--success);
}

.no-value {
    color: var(--on-surface-variant);
    font-style: italic;
}

/* Actions Column Styling */
.actions-column {
    width: 140px;
    text-align: center;
}

.action-buttons-group {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-xs);
    align-items: center;
}

.all-actions {
    display: flex;
    gap: 4px;
    flex-wrap: nowrap;
    justify-content: center;
    align-items: center;
}

.all-actions .btn {
    margin: 2px;
    min-width: 28px;
    height: 28px;
    padding: 4px;
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

/* Table Header Scroll */
@media (max-width: 1400px) {
    .table-container {
        position: relative;
        overflow-x: auto;
        margin-bottom: 0 !important;
    }

    .table-container::before {
        content: '';
        display: block;
        height: 15px;
        width: 100%;
        background: linear-gradient(to bottom, rgba(248, 249, 250, 1) 50%, rgba(248, 249, 250, 0) 100%);
        position: sticky;
        top: 0;
        z-index: 2;
    }

    .table-header-scroll {
        overflow-x: auto;
        overflow-y: hidden;
        position: sticky;
        top: 0;
        z-index: 3;
        margin-bottom: -15px;
        height: 15px;
    }

    .table-header-scroll div {
        height: 1px;
        width: 100%;
    }

    .table-container::-webkit-scrollbar,
    .table-header-scroll::-webkit-scrollbar {
        height: 8px;
    }

    .table-container::-webkit-scrollbar-thumb,
    .table-header-scroll::-webkit-scrollbar-thumb {
        background-color: #b0b0b0;
        border-radius: 4px;
    }

    .table-container::-webkit-scrollbar-track,
    .table-header-scroll::-webkit-scrollbar-track {
        background-color: #f0f0f0;
    }
}

/* Responsive Design */
@media (max-width: 1024px) {
    .action-buttons-group {
        flex-direction: row;
        gap: 2px;
    }
    
    .actions-column {
        width: 120px;
    }

    .chart-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .chart-item:nth-child(3) {
        grid-column: 1 / -1;
    }

    .chart-canvas {
        height: 280px;
    }
}

@media (max-width: 768px) {
    .chart-grid {
        grid-template-columns: 1fr;
    }

    .chart-item:nth-child(3) {
        grid-column: 1;
    }

    .chart-canvas {
        height: 250px;
    }
}

/* Tablet optimizations */
@media (max-width: 1200px) {
    .tasks-table {
        min-width: 900px;
    }
    
    .tasks-table th,
    .tasks-table td {
        padding: calc(var(--spacing-md) * 0.8);
        font-size: calc(var(--font-size-sm) * 0.95);
    }
}

@media (max-width: 992px) {
    .tasks-table {
        min-width: 800px;
    }
    
    .tasks-table th:nth-child(3), /* Poliçe */
    .tasks-table td:nth-child(3) {
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
    .tasks-table thead {
        display: none;
    }
    
    /* Reset table styling for mobile */
    .tasks-table {
        width: 100%;
        min-width: auto;
        border-collapse: separate;
        border-spacing: 0;
    }
    
    /* Convert table rows to cards */
    .tasks-table tbody {
        display: block;
    }
    
    .tasks-table tbody tr {
        display: block;
        background: white;
        border: 1px solid var(--outline-variant);
        border-radius: 12px;
        margin-bottom: var(--spacing-md);
        padding: var(--spacing-md);
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        transition: all var(--transition-fast);
    }
    
    .tasks-table tbody tr:hover {
        box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        transform: translateY(-2px);
        background: white;
    }
    
    /* Style table cells as card content */
    .tasks-table td {
        display: block;
        padding: var(--spacing-sm) 0;
        border-bottom: none;
        position: relative;
        font-size: var(--font-size-sm);
    }
    
    /* Add labels before each cell content */
    .tasks-table td:before {
        content: attr(data-label);
        display: block;
        font-weight: 600;
        font-size: var(--font-size-xs);
        color: var(--on-surface-variant);
        margin-bottom: var(--spacing-xs);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    /* Special styling for first cell (task title) */
    .tasks-table td:first-child {
        border-bottom: 1px solid var(--outline-variant);
        padding-bottom: var(--spacing-md);
        margin-bottom: var(--spacing-sm);
    }
    
    .tasks-table td:first-child:before {
        display: none;
    }
    
    /* Last cell (actions) styling */
    .tasks-table td:last-child {
        padding-top: var(--spacing-md);
        border-top: 1px solid var(--outline-variant);
        margin-top: var(--spacing-sm);
    }
    
    /* Status-based row styling for mobile cards */
    .tasks-table tr.overdue {
        border-left: 4px solid #d32f2f;
    }
    
    .tasks-table tr.today {
        border-left: 4px solid #ff9800;
    }
    
    .tasks-table tr.task-completed {
        border-left: 4px solid #4caf50;
        opacity: 0.8;
    }
    
    .tasks-table tr.task-in-progress {
        border-left: 4px solid #2196f3;
    }
    
    .tasks-table tr.task-cancelled {
        border-left: 4px solid #757575;
        opacity: 0.7;
    }
    
    .tasks-table tr.priority-high {
        border-right: 3px solid #f44336;
    }

    .time-filter-tabs {
        overflow-x: auto;
        padding-bottom: 10px;
    }
    
    .time-filter-tab {
        flex-shrink: 0;
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
        width: 100px;
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
    
    .tasks-table {
        width: 100%;
    }
    
    .tasks-table th:last-child,
    .tasks-table td:last-child {
        display: none;
    }
}
</style>

<script>
/**
 * Modern Tasks Management JavaScript v3.1.0
 * @author anadolubirlik
 * @date 2025-05-30 22:02:40
 * @description Kişisel ve Ekip Görevleri ayrımı, gelişmiş istatistikler ve modern UI
 */

class ModernTasksApp {
    constructor() {
        this.activeFilterCount = <?php echo $active_filter_count; ?>;
        this.statusChartData = <?php echo json_encode($status_chart_data); ?>;
        this.priorityChartData = <?php echo json_encode($priority_chart_data); ?>;
        this.timeChartData = <?php echo json_encode($time_chart_data); ?>;
        this.isInitialized = false;
        this.isTeamView = <?php echo $is_team_view ? 'true' : 'false'; ?>;
        this.version = '3.1.0';
        
        this.init();
    }

    async init() {
        try {
            this.initializeEventListeners();
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

    async initializeCharts() {
        if (typeof Chart === 'undefined') {
            console.warn('Chart.js not loaded, skipping charts initialization');
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
            console.error('Charts initialization failed:', error);
        }
    }

    renderCharts() {
        this.renderStatusChart();
        this.renderPriorityChart();
        this.renderTimeChart();
    }

    renderStatusChart() {
        const ctx = document.getElementById('taskStatusChart');
        if (!ctx) return;

        const labels = Object.keys(this.statusChartData);
        const data = Object.values(this.statusChartData);
        
        if (data.length === 0) return;

        const colors = {
            'Beklemede': '#1976d2',
            'İşlemde': '#f57c00',
            'Tamamlandı': '#2e7d32',
            'İptal Edildi': '#757575'
        };

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: labels.map(label => colors[label] || '#999'),
                    borderWidth: 3,
                    borderColor: '#ffffff',
                    hoverBorderWidth: 4,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
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

    renderPriorityChart() {
        const ctx = document.getElementById('taskPriorityChart');
        if (!ctx) return;

        const labels = Object.keys(this.priorityChartData);
        const data = Object.values(this.priorityChartData);
        
        if (data.length === 0) return;

        const colors = {
            'Yüksek': '#d32f2f',
            'Orta': '#f57c00',
            'Düşük': '#2e7d32'
        };

        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: labels.map(label => colors[label] || '#999'),
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 6
                }]
            },
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
                                return `${context.label}: ${value} görev (${percentage}%)`;
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

    renderTimeChart() {
        const ctx = document.getElementById('taskTimeChart');
        if (!ctx) return;

        const labels = Object.keys(this.timeChartData);
        const data = Object.values(this.timeChartData);
        
        if (data.length === 0) return;

        const colors = {
            'Bugün': '#ff8c00',
            'Yarın': '#2196f3',
            'Bu Hafta': '#4caf50',
            'Gelecek Hafta': '#9c27b0',
            'Bu Ay': '#607d8b',
            'Gelecek Ay': '#795548',
            'Gecikmiş': '#f44336'
        };

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: labels.map(label => colors[label] || '#999'),
                    borderWidth: 3,
                    borderColor: '#ffffff',
                    hoverBorderWidth: 4,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            usePointStyle: true,
                            font: {
                                size: 11
                            },
                            boxWidth: 12,
                            boxHeight: 12
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const value = context.raw;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value * 100) / total) : 0;
                                return `${context.label}: ${value} görev (${percentage}%)`;
                            }
                        }
                    }
                },
                animation: {
                    animateRotate: true,
                    duration: 1000
                },
                layout: {
                    padding: {
                        top: 10,
                        bottom: 10,
                        left: 10,
                        right: 10
                    }
                }
            }
        });
    }

    initializeTableFeatures() {
        this.addTableRowHoverEffects();
        this.addTableSorting();
        this.addTableQuickActions();
        this.initializeHeaderScroll();
    }
    
    initializeHeaderScroll() {
        const tableContainer = document.querySelector('.table-container');
        const headerScroll = document.querySelector('.table-header-scroll');
        
        if (tableContainer && headerScroll) {
            // Tablo genişliğini header scrollbar içeriğine uygula
            const tableWidth = document.querySelector('.tasks-table')?.scrollWidth || 1200;
            headerScroll.querySelector('div').style.width = tableWidth + 'px';
            
            // Scroll senkronizasyonu
            tableContainer.addEventListener('scroll', function() {
                headerScroll.scrollLeft = tableContainer.scrollLeft;
            });
            
            headerScroll.addEventListener('scroll', function() {
                tableContainer.scrollLeft = headerScroll.scrollLeft;
            });
        }
    }

    addTableRowHoverEffects() {
        const tableRows = document.querySelectorAll('.tasks-table tbody tr');
        
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
        const sortableHeaders = document.querySelectorAll('.tasks-table th a');
        
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
                        window.location.href = '?view=tasks&action=new' + (this.isTeamView ? '&view_type=team' : '');
                        break;
                }
            }
        });
    }

    enhanceFormInputs() {
        const inputs = document.querySelectorAll('.form-input, .form-select');
        
        inputs.forEach(input => {
            this.addValidationStyling(input);
        });
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
        // Gelecekte tablo için ekstra özellikler eklemek istediğimizde burayı kullanacağız
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
        const taskTitleFilter = document.getElementById('task_title');
        if (taskTitleFilter) {
            taskTitleFilter.focus();
            // Filtre bölümü gizliyse göster
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

    showNotification(message, type = 'info', duration = 5000) {
        // Use SweetAlert2 if available
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: type === 'error' ? 'error' : type === 'warning' ? 'warning' : type === 'success' ? 'success' : 'info',
                title: type === 'error' ? 'Hata' : type === 'warning' ? 'Uyarı' : type === 'success' ? 'Başarılı' : 'Bilgi',
                text: message,
                timer: duration,
                timerProgressBar: true,
                showConfirmButton: false,
                toast: true,
                position: 'top-end'
            });
            return;
        }
        
        // Fallback to custom notification
        const existingNotifications = document.querySelectorAll('.notification');
        existingNotifications.forEach(notification => notification.remove());

        const notification = document.createElement('div');
        notification.className = `notification-banner notification-${type}`;
        notification.innerHTML = `
            <div class="notification-icon">
                <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'times' : 'info'}-circle"></i>
            </div>
            <div class="notification-content">
                ${message}
            </div>
            <button class="notification-close">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            if (notification.parentNode) {
                notification.style.opacity = '0';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }
        }, duration);
        
        notification.querySelector('.notification-close').addEventListener('click', () => {
            notification.style.opacity = '0';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        });
    }

    logInitialization() {
        console.log(`🚀 Modern Tasks App v${this.version}`);
        console.log('👤 User: anadolubirlik');
        console.log('⏰ Current Time: 2025-05-30 22:02:40');
        console.log('📊 Status Chart Data:', this.statusChartData);
        console.log('📊 Priority Chart Data:', this.priorityChartData);
        console.log('📊 Time Chart Data:', this.timeChartData);
        console.log('🔍 Active Filters:', this.activeFilterCount);
        console.log('✅ All enhancements completed');
    }
}

// Per page selectorü için fonksiyon
function updatePerPage(newPerPage) {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', newPerPage);
    url.searchParams.delete('paged'); // İlk sayfaya dön
    window.location.href = url.toString();
}

document.addEventListener('DOMContentLoaded', () => {
    // Initialize the app
    window.modernTasksApp = new ModernTasksApp();
    
    // Add keyboard shortcuts help
    document.addEventListener('keydown', (e) => {
        if (e.key === 'F1') {
            e.preventDefault();
            window.modernTasksApp.showNotification(
                'Klavye Kısayolları: Ctrl+F (Filtre), Ctrl+N (Yeni), Ctrl+R (Yenile), ESC (Kapat)', 
                'info', 
                8000
            );
        }
    });
});
</script>

<?php
// Eğer action=view, action=new veya action=edit ise ilgili dosyayı dahil et
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'view': if (isset($_GET['id'])) { include_once('task-view.php'); } break;
        case 'new': case 'edit': include_once('task-form.php'); break;
    }
}
?>
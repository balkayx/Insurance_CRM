<?php
/**
 * Dashboard Functions - Enhanced Features and Performance Metrics
 * Version: 5.1.0
 * Author: Anadolu Birlik
 * Description: New dashboard functionality for improved UI and performance tracking
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get team performance data with photos
 * @param string $period Time period filter
 * @param int $user_id Current user ID
 * @return array Team performance data
 */
function get_team_performance_with_photos($period = 'this_month', $user_id = null) {
    global $wpdb;
    
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    $date_condition = get_date_condition_for_period($period);
    $rep_ids = get_dashboard_representatives($user_id);
    
    if (empty($rep_ids)) {
        return [];
    }
    
    $rep_ids_placeholder = implode(',', array_fill(0, count($rep_ids), '%d'));
    
    $query = $wpdb->prepare("
        SELECT 
            r.id,
            r.user_id,
            u.display_name,
            u.user_email,
            r.role,
            COUNT(DISTINCT p.id) as policy_count,
            SUM(CASE WHEN p.status = 'aktif' THEN p.premium_amount ELSE 0 END) as total_premium,
            SUM(CASE WHEN p.status = 'aktif' THEN 1 ELSE 0 END) as active_policies,
            COUNT(DISTINCT c.id) as customer_count,
            AVG(p.premium_amount) as avg_premium
        FROM {$wpdb->prefix}insurance_crm_representatives r
        LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
        LEFT JOIN {$wpdb->prefix}insurance_crm_policies p ON r.id = p.representative_id 
            AND p.created_date {$date_condition}
            AND p.status != 'deleted'
        LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON r.id = c.representative_id 
            AND c.created_date {$date_condition}
            AND c.status = 'aktif'
        WHERE r.id IN ($rep_ids_placeholder)
            AND r.status = 'active'
        GROUP BY r.id, r.user_id, u.display_name, u.user_email, r.role
        ORDER BY total_premium DESC
    ", ...$rep_ids);
    
    $results = $wpdb->get_results($query);
    
    // Add avatar URLs and additional metrics
    foreach ($results as &$result) {
        $result->avatar_url = get_user_avatar_url($result->user_id);
        $result->role_name = get_role_name_by_level($result->role);
        $result->performance_score = calculate_performance_score($result);
        $result->target_achievement = calculate_target_achievement($result->user_id, $period);
        $result->is_team_leader = ($result->role == 4);
    }
    
    return $results;
}

/**
 * Get representative performance data with photos
 * @param string $period Time period filter
 * @param int $user_id Current user ID
 * @return array Representative performance data
 */
function get_representative_performance_with_photos($period = 'this_month', $user_id = null) {
    global $wpdb;
    
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    $date_condition = get_date_condition_for_period($period);
    $rep_ids = get_dashboard_representatives($user_id);
    
    if (empty($rep_ids)) {
        return [];
    }
    
    $rep_ids_placeholder = implode(',', array_fill(0, count($rep_ids), '%d'));
    
    $query = $wpdb->prepare("
        SELECT 
            r.id,
            r.user_id,
            u.display_name,
            u.user_email,
            r.role,
            r.phone,
            r.hire_date,
            COUNT(DISTINCT p.id) as policy_count,
            SUM(CASE WHEN p.status = 'aktif' THEN p.premium_amount ELSE 0 END) as total_premium,
            COUNT(DISTINCT c.id) as customer_count,
            COUNT(DISTINCT CASE WHEN c.created_date {$date_condition} THEN c.id END) as new_customers,
            AVG(p.premium_amount) as avg_premium,
            COUNT(DISTINCT CASE WHEN p.created_date {$date_condition} THEN p.id END) as period_policies
        FROM {$wpdb->prefix}insurance_crm_representatives r
        LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
        LEFT JOIN {$wpdb->prefix}insurance_crm_policies p ON r.id = p.representative_id 
            AND p.status != 'deleted'
        LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON r.id = c.representative_id 
            AND c.status = 'aktif'
        WHERE r.id IN ($rep_ids_placeholder)
            AND r.status = 'active'
        GROUP BY r.id, r.user_id, u.display_name, u.user_email, r.role, r.phone, r.hire_date
        ORDER BY total_premium DESC
    ", ...$rep_ids);
    
    $results = $wpdb->get_results($query);
    
    // Add additional metrics and avatar
    foreach ($results as &$result) {
        $result->avatar_url = get_user_avatar_url($result->user_id);
        $result->role_name = get_role_name_by_level($result->role);
        $result->performance_score = calculate_performance_score($result);
        $result->target_achievement = calculate_target_achievement($result->user_id, $period);
        $result->success_rate = calculate_success_rate($result->user_id, $period);
        $result->monthly_growth = calculate_monthly_growth($result->user_id);
        $result->experience_years = calculate_experience_years($result->hire_date);
    }
    
    return $results;
}

/**
 * Get top sales personnel for current month
 * @param int $limit Number of top performers to return
 * @param int $user_id Current user ID for access control
 * @return array Top sales performers
 */
function get_top_sales_personnel_this_month($limit = 5, $user_id = null) {
    global $wpdb;
    
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    $current_month_start = date('Y-m-01');
    $current_month_end = date('Y-m-t');
    $previous_month_start = date('Y-m-01', strtotime('-1 month'));
    $previous_month_end = date('Y-m-t', strtotime('-1 month'));
    
    $rep_ids = get_dashboard_representatives($user_id);
    if (empty($rep_ids)) {
        return [];
    }
    
    $rep_ids_placeholder = implode(',', array_fill(0, count($rep_ids), '%d'));
    
    $query = $wpdb->prepare("
        SELECT 
            r.id,
            r.user_id,
            u.display_name,
            r.role,
            SUM(CASE WHEN p.created_date BETWEEN %s AND %s AND p.status = 'aktif' 
                THEN p.premium_amount ELSE 0 END) as current_month_sales,
            SUM(CASE WHEN p.created_date BETWEEN %s AND %s AND p.status = 'aktif' 
                THEN p.premium_amount ELSE 0 END) as previous_month_sales,
            COUNT(CASE WHEN p.created_date BETWEEN %s AND %s AND p.status = 'aktif' 
                THEN p.id END) as current_month_policies
        FROM {$wpdb->prefix}insurance_crm_representatives r
        LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
        LEFT JOIN {$wpdb->prefix}insurance_crm_policies p ON r.id = p.representative_id 
            AND p.status != 'deleted'
        WHERE r.id IN ($rep_ids_placeholder)
            AND r.status = 'active'
        GROUP BY r.id, r.user_id, u.display_name, r.role
        HAVING current_month_sales > 0
        ORDER BY current_month_sales DESC
        LIMIT %d
    ", 
        $current_month_start, $current_month_end,
        $previous_month_start, $previous_month_end,
        $current_month_start, $current_month_end,
        $limit,
        ...$rep_ids
    );
    
    $results = $wpdb->get_results($query);
    
    foreach ($results as &$result) {
        $result->avatar_url = get_user_avatar_url($result->user_id);
        $result->role_name = get_role_name_by_level($result->role);
        $result->change_percentage = calculate_percentage_change(
            $result->previous_month_sales, 
            $result->current_month_sales
        );
        $result->target_progress = calculate_monthly_target_progress($result->user_id);
    }
    
    return $results;
}

/**
 * Get top customer acquisition personnel for current month
 * @param int $limit Number of top performers to return
 * @param int $user_id Current user ID for access control
 * @return array Top customer acquisition performers
 */
function get_top_customer_acquisition_this_month($limit = 5, $user_id = null) {
    global $wpdb;
    
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    $current_month_start = date('Y-m-01');
    $current_month_end = date('Y-m-t');
    $previous_month_start = date('Y-m-01', strtotime('-1 month'));
    $previous_month_end = date('Y-m-t', strtotime('-1 month'));
    
    $rep_ids = get_dashboard_representatives($user_id);
    if (empty($rep_ids)) {
        return [];
    }
    
    $rep_ids_placeholder = implode(',', array_fill(0, count($rep_ids), '%d'));
    
    $query = $wpdb->prepare("
        SELECT 
            r.id,
            r.user_id,
            u.display_name,
            r.role,
            COUNT(CASE WHEN c.created_date BETWEEN %s AND %s 
                THEN c.id END) as current_month_customers,
            COUNT(CASE WHEN c.created_date BETWEEN %s AND %s 
                THEN c.id END) as previous_month_customers,
            AVG(CASE WHEN c.created_date BETWEEN %s AND %s 
                THEN 1 ELSE 0 END) as acquisition_rate
        FROM {$wpdb->prefix}insurance_crm_representatives r
        LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
        LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON r.id = c.representative_id 
            AND c.status = 'aktif'
        WHERE r.id IN ($rep_ids_placeholder)
            AND r.status = 'active'
        GROUP BY r.id, r.user_id, u.display_name, r.role
        HAVING current_month_customers > 0
        ORDER BY current_month_customers DESC
        LIMIT %d
    ", 
        $current_month_start, $current_month_end,
        $previous_month_start, $previous_month_end,
        $current_month_start, $current_month_end,
        $limit,
        ...$rep_ids
    );
    
    $results = $wpdb->get_results($query);
    
    foreach ($results as &$result) {
        $result->avatar_url = get_user_avatar_url($result->user_id);
        $result->role_name = get_role_name_by_level($result->role);
        $result->change_percentage = calculate_percentage_change(
            $result->previous_month_customers, 
            $result->current_month_customers
        );
        $result->trend_indicator = calculate_trend_indicator($result->change_percentage);
        $result->badge_level = calculate_badge_level($result->current_month_customers);
    }
    
    return $results;
}

/**
 * Get enhanced task summary for specific role
 * @param int $user_id User ID
 * @param string $role User role (patron, manager, team_leader, representative)
 * @return array Task summary data
 */
function get_enhanced_task_summary($user_id, $role) {
    global $wpdb;
    
    $rep_ids = get_dashboard_representatives($user_id);
    if (empty($rep_ids)) {
        return get_empty_task_summary();
    }
    
    $rep_ids_placeholder = implode(',', array_fill(0, count($rep_ids), '%d'));
    
    // Base query for tasks
    $base_query = "
        FROM {$wpdb->prefix}insurance_crm_tasks t
        LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON t.customer_id = c.id
        WHERE c.representative_id IN ($rep_ids_placeholder)
            AND t.status != 'deleted'
    ";
    
    // Get task counts by status
    $query = $wpdb->prepare("
        SELECT 
            SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
            SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks,
            SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
            SUM(CASE WHEN t.status = 'pending' AND t.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue_tasks,
            SUM(CASE WHEN t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) 
                AND t.status != 'completed' THEN 1 ELSE 0 END) as upcoming_tasks,
            COUNT(*) as total_tasks
        $base_query
    ", ...$rep_ids);
    
    $summary = $wpdb->get_row($query);
    
    if (!$summary) {
        return get_empty_task_summary();
    }
    
    // Calculate completion rate
    $completion_rate = $summary->total_tasks > 0 ? 
        round(($summary->completed_tasks / $summary->total_tasks) * 100, 1) : 0;
    
    // Get priority distribution
    $priority_query = $wpdb->prepare("
        SELECT 
            priority,
            COUNT(*) as count
        $base_query
            AND t.status != 'completed'
        GROUP BY priority
    ", ...$rep_ids);
    
    $priority_distribution = $wpdb->get_results($priority_query);
    
    // Format for role-specific display
    return array(
        'pending' => array(
            'count' => (int)$summary->pending_tasks,
            'label' => get_task_label_for_role($role, 'pending'),
            'icon' => 'dashicons-clock',
            'color' => '#f59e0b'
        ),
        'in_progress' => array(
            'count' => (int)$summary->in_progress_tasks,
            'label' => get_task_label_for_role($role, 'in_progress'),
            'icon' => 'dashicons-update',
            'color' => '#3b82f6'
        ),
        'completed' => array(
            'count' => (int)$summary->completed_tasks,
            'label' => get_task_label_for_role($role, 'completed'),
            'icon' => 'dashicons-yes-alt',
            'color' => '#10b981'
        ),
        'overdue' => array(
            'count' => (int)$summary->overdue_tasks,
            'label' => get_task_label_for_role($role, 'overdue'),
            'icon' => 'dashicons-warning',
            'color' => '#ef4444'
        ),
        'upcoming' => array(
            'count' => (int)$summary->upcoming_tasks,
            'label' => 'Bu Hafta Bitenler',
            'icon' => 'dashicons-calendar-alt',
            'color' => '#8b5cf6'
        ),
        'completion_rate' => $completion_rate,
        'priority_distribution' => $priority_distribution,
        'total_tasks' => (int)$summary->total_tasks
    );
}

/**
 * Helper Functions
 */

function get_user_avatar_url($user_id) {
    $avatar_url = get_avatar_url($user_id, array('size' => 96));
    
    // Check for custom avatar in user meta
    $custom_avatar = get_user_meta($user_id, 'profile_photo', true);
    if ($custom_avatar) {
        return wp_get_attachment_url($custom_avatar);
    }
    
    return $avatar_url;
}

function get_role_name_by_level($role_level) {
    $roles = array(
        1 => 'Patron',
        2 => 'Müdür', 
        3 => 'Müdür Yardımcısı',
        4 => 'Ekip Lideri',
        5 => 'Müşteri Temsilcisi'
    );
    return $roles[$role_level] ?? 'Bilinmiyor';
}

function get_date_condition_for_period($period) {
    switch ($period) {
        case 'this_week':
            return ">= '" . date('Y-m-d', strtotime('monday this week')) . "'";
        case 'this_month':
            return ">= '" . date('Y-m-01') . "'";
        case 'last_3_months':
            return ">= '" . date('Y-m-01', strtotime('-3 months')) . "'";
        case 'last_6_months':
            return ">= '" . date('Y-m-01', strtotime('-6 months')) . "'";
        case 'this_year':
            return ">= '" . date('Y-01-01') . "'";
        default:
            return ">= '" . date('Y-m-01') . "'";
    }
}

function calculate_performance_score($result) {
    $premium_weight = 0.4;
    $policy_weight = 0.3;
    $customer_weight = 0.3;
    
    $max_premium = 500000; // Adjust based on your data
    $max_policies = 100;
    $max_customers = 50;
    
    $premium_score = min(($result->total_premium / $max_premium) * 100, 100);
    $policy_score = min(($result->policy_count / $max_policies) * 100, 100);
    $customer_score = min(($result->customer_count / $max_customers) * 100, 100);
    
    return round(
        ($premium_score * $premium_weight) + 
        ($policy_score * $policy_weight) + 
        ($customer_score * $customer_weight), 
        1
    );
}

function calculate_target_achievement($user_id, $period) {
    // Get user's target for the period
    $target = get_user_meta($user_id, 'monthly_target', true) ?: 100000;
    
    global $wpdb;
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $user_id
    ));
    
    if (!$rep) return 0;
    
    $date_condition = get_date_condition_for_period($period);
    
    $actual = $wpdb->get_var($wpdb->prepare("
        SELECT SUM(premium_amount) 
        FROM {$wpdb->prefix}insurance_crm_policies 
        WHERE representative_id = %d 
            AND status = 'aktif' 
            AND created_date $date_condition
    ", $rep->id));
    
    return $target > 0 ? round(($actual / $target) * 100, 1) : 0;
}

function calculate_percentage_change($old_value, $new_value) {
    if ($old_value == 0) {
        return $new_value > 0 ? 100 : 0;
    }
    return round((($new_value - $old_value) / $old_value) * 100, 1);
}

function calculate_success_rate($user_id, $period) {
    global $wpdb;
    
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $user_id
    ));
    
    if (!$rep) return 0;
    
    $date_condition = get_date_condition_for_period($period);
    
    $result = $wpdb->get_row($wpdb->prepare("
        SELECT 
            COUNT(*) as total_policies,
            SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END) as active_policies
        FROM {$wpdb->prefix}insurance_crm_policies 
        WHERE representative_id = %d 
            AND created_date $date_condition
            AND status != 'deleted'
    ", $rep->id));
    
    return $result->total_policies > 0 ? 
        round(($result->active_policies / $result->total_policies) * 100, 1) : 0;
}

function calculate_monthly_growth($user_id) {
    global $wpdb;
    
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $user_id
    ));
    
    if (!$rep) return 0;
    
    $current_month = $wpdb->get_var($wpdb->prepare("
        SELECT SUM(premium_amount) 
        FROM {$wpdb->prefix}insurance_crm_policies 
        WHERE representative_id = %d 
            AND status = 'aktif'
            AND created_date >= %s
    ", $rep->id, date('Y-m-01')));
    
    $previous_month = $wpdb->get_var($wpdb->prepare("
        SELECT SUM(premium_amount) 
        FROM {$wpdb->prefix}insurance_crm_policies 
        WHERE representative_id = %d 
            AND status = 'aktif'
            AND created_date >= %s 
            AND created_date < %s
    ", $rep->id, date('Y-m-01', strtotime('-1 month')), date('Y-m-01')));
    
    return calculate_percentage_change($previous_month, $current_month);
}

function calculate_experience_years($hire_date) {
    if (!$hire_date) return 0;
    
    $hire_timestamp = strtotime($hire_date);
    $current_timestamp = time();
    
    return round(($current_timestamp - $hire_timestamp) / (365 * 24 * 60 * 60), 1);
}

function calculate_monthly_target_progress($user_id) {
    $target = get_user_meta($user_id, 'monthly_target', true) ?: 100000;
    
    global $wpdb;
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $user_id
    ));
    
    if (!$rep) return 0;
    
    $current_month_sales = $wpdb->get_var($wpdb->prepare("
        SELECT SUM(premium_amount) 
        FROM {$wpdb->prefix}insurance_crm_policies 
        WHERE representative_id = %d 
            AND status = 'aktif'
            AND created_date >= %s
    ", $rep->id, date('Y-m-01')));
    
    return $target > 0 ? min(round(($current_month_sales / $target) * 100, 1), 100) : 0;
}

function calculate_trend_indicator($change_percentage) {
    if ($change_percentage > 10) return 'strong_up';
    if ($change_percentage > 0) return 'up';
    if ($change_percentage < -10) return 'strong_down';
    if ($change_percentage < 0) return 'down';
    return 'stable';
}

function calculate_badge_level($customer_count) {
    if ($customer_count >= 50) return 'diamond';
    if ($customer_count >= 30) return 'gold';
    if ($customer_count >= 15) return 'silver';
    if ($customer_count >= 5) return 'bronze';
    return 'starter';
}

function get_task_label_for_role($role, $status) {
    $labels = array(
        'patron' => array(
            'pending' => 'Bekleyen Stratejik Görevler',
            'in_progress' => 'Devam Eden Projeler',
            'completed' => 'Tamamlanan İnisiyatifler',
            'overdue' => 'Geciken Kritik Görevler'
        ),
        'manager' => array(
            'pending' => 'Bekleyen Departman Görevleri',
            'in_progress' => 'Devam Eden Departman İşleri',
            'completed' => 'Tamamlanan Departman Görevleri',
            'overdue' => 'Geciken Departman Görevleri'
        ),
        'team_leader' => array(
            'pending' => 'Bekleyen Ekip Görevleri',
            'in_progress' => 'Devam Eden Ekip İşleri',
            'completed' => 'Tamamlanan Ekip Görevleri',
            'overdue' => 'Geciken Ekip Görevleri'
        ),
        'representative' => array(
            'pending' => 'Bekleyen Kişisel Görevler',
            'in_progress' => 'Devam Eden İşler',
            'completed' => 'Tamamlanan Görevler',
            'overdue' => 'Geciken Görevler'
        )
    );
    
    return $labels[$role][$status] ?? ucfirst($status) . ' Görevler';
}

function get_empty_task_summary() {
    return array(
        'pending' => array('count' => 0, 'label' => 'Bekleyen Görevler', 'icon' => 'dashicons-clock', 'color' => '#f59e0b'),
        'in_progress' => array('count' => 0, 'label' => 'Devam Eden Görevler', 'icon' => 'dashicons-update', 'color' => '#3b82f6'),
        'completed' => array('count' => 0, 'label' => 'Tamamlanan Görevler', 'icon' => 'dashicons-yes-alt', 'color' => '#10b981'),
        'overdue' => array('count' => 0, 'label' => 'Geciken Görevler', 'icon' => 'dashicons-warning', 'color' => '#ef4444'),
        'upcoming' => array('count' => 0, 'label' => 'Bu Hafta Bitenler', 'icon' => 'dashicons-calendar-alt', 'color' => '#8b5cf6'),
        'completion_rate' => 0,
        'priority_distribution' => array(),
        'total_tasks' => 0
    );
}

/**
 * Get organization management menu items based on user role
 * @param int $user_id User ID
 * @return array Menu items
 */
function get_organization_management_menu_items($user_id) {
    $role = get_user_role_in_hierarchy($user_id);
    
    $menu_items = array();
    
    if (has_full_admin_access($user_id)) {
        $menu_items = array(
            array(
                'title' => 'Organizasyon Yönetimi',
                'description' => 'Genel organizasyon yapısını görüntüle ve yönet',
                'url' => generate_panel_url('organization'),
                'icon' => 'dashicons-networking',
                'class' => 'organization'
            ),
            array(
                'title' => 'Tüm Personel',
                'description' => 'Tüm personel listesini görüntüle ve düzenle',
                'url' => generate_panel_url('all_personnel'),
                'icon' => 'dashicons-groups',
                'class' => 'personnel'
            ),
            array(
                'title' => 'Yeni Ekip Oluştur',
                'description' => 'Yeni ekip oluştur ve ekip liderini ata',
                'url' => generate_panel_url('team_add'),
                'icon' => 'dashicons-admin-users',
                'class' => 'team'
            ),
            array(
                'title' => 'Yeni Temsilci Ekle',
                'description' => 'Sisteme yeni temsilci kaydı oluştur',
                'url' => generate_panel_url('representative_add'),
                'icon' => 'dashicons-admin-users',
                'class' => 'representative'
            ),
            array(
                'title' => 'Yönetim Ayarları',
                'description' => 'Sistem yönetim parametrelerini düzenle',
                'url' => generate_panel_url('boss_settings'),
                'icon' => 'dashicons-admin-generic',
                'class' => 'settings'
            )
        );
    }
    
    return $menu_items;
}

/**
 * Get customers with birthday today for the current user
 * @param int $user_id Current user ID
 * @param int $page Page number for pagination (default: 1)
 * @param int $per_page Number of items per page (default: 5)
 * @return array Array containing customers and pagination info
 */
function get_todays_birthday_customers($user_id = null, $page = 1, $per_page = 5) {
    global $wpdb;
    
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    // Get representative ID for current user
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $user_id
    ));
    
    if (!$rep) {
        return [
            'customers' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => 0
        ];
    }
    
    // Get customers with birthday today
    $today_month_day = date('m-d');
    
    // First, get total count
    $total_query = $wpdb->prepare("
        SELECT COUNT(*) 
        FROM {$wpdb->prefix}insurance_crm_customers 
        WHERE representative_id = %d
            AND status = 'aktif'
            AND birth_date IS NOT NULL
            AND DATE_FORMAT(birth_date, '%%m-%%d') = %s
    ", $rep->id, $today_month_day);
    
    $total = $wpdb->get_var($total_query);
    $total_pages = ceil($total / $per_page);
    $offset = ($page - 1) * $per_page;
    
    // Get paginated results
    $query = $wpdb->prepare("
        SELECT 
            id,
            first_name,
            last_name,
            email,
            birth_date,
            phone
        FROM {$wpdb->prefix}insurance_crm_customers 
        WHERE representative_id = %d
            AND status = 'aktif'
            AND birth_date IS NOT NULL
            AND DATE_FORMAT(birth_date, '%%m-%%d') = %s
        ORDER BY first_name, last_name
        LIMIT %d OFFSET %d
    ", $rep->id, $today_month_day, $per_page, $offset);
    
    $customers = $wpdb->get_results($query);
    
    // Debug information (only for admin users and when WP_DEBUG is enabled)
    if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('administrator')) {
        $total_customers = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers WHERE representative_id = %d AND status = 'aktif'",
            $rep->id
        ));
        $customers_with_birthdate = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers WHERE representative_id = %d AND status = 'aktif' AND birth_date IS NOT NULL",
            $rep->id
        ));
        
        error_log("Birthday Debug - Total customers: $total_customers, With birth_date: $customers_with_birthdate, Today ($today_month_day): $total, Page: $page/$total_pages");
    }
    
    // Format the data
    foreach ($customers as &$customer) {
        $customer->full_name = trim($customer->first_name . ' ' . $customer->last_name);
        $customer->birth_date_formatted = !empty($customer->birth_date) ? 
            date('d.m.Y', strtotime($customer->birth_date)) : '';
        $customer->age = !empty($customer->birth_date) ? 
            date('Y') - date('Y', strtotime($customer->birth_date)) : '';
    }
    
    return [
        'customers' => $customers,
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => $total_pages
    ];
}

/**
 * Send birthday celebration email to customer
 * @param int $customer_id Customer ID
 * @param int $user_id Sender user ID
 * @return bool Success status
 */
function send_birthday_celebration_email($customer_id, $user_id = null) {
    global $wpdb;
    
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    // Get customer data
    $customer = $wpdb->get_row($wpdb->prepare(
        "SELECT c.*, r.user_id as rep_user_id
         FROM {$wpdb->prefix}insurance_crm_customers c
         LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON c.representative_id = r.id
         WHERE c.id = %d AND c.status = 'aktif'",
        $customer_id
    ));
    
    if (!$customer) {
        return false;
    }
    
    // Security check - role-based access control
    $current_user = wp_get_current_user();
    $user_roles = $current_user->roles;
    
    // Get representative info for current user
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $user_id
    ));
    
    $is_representative = in_array('insurance_representative', $user_roles);
    $is_admin_role = in_array('administrator', $user_roles) || 
                     ($rep && in_array($rep->role, [1, 2, 3])); // Patron, Manager, MD Assistant roles
    
    // Representatives can only send to their own customers
    // Admin roles can send to any customer
    if ($is_representative && !$is_admin_role && $customer->rep_user_id != $user_id) {
        return false;
    }
    
    if (empty($customer->email)) {
        return false;
    }
    
    // Get company settings
    $settings = get_option('insurance_crm_settings', array());
    $company_name = isset($settings['company_name']) ? $settings['company_name'] : get_bloginfo('name');
    
    // Prepare email variables
    $customer_name = trim($customer->first_name . ' ' . $customer->last_name);
    $variables = array(
        'customer_name' => $customer_name,
        'company_name' => $company_name,
        'first_name' => $customer->first_name,
        'last_name' => $customer->last_name
    );
    
    // Get birthday email template
    require_once(INSURANCE_CRM_PATH . 'includes/email-templates.php');
    $template_content = insurance_crm_get_email_template('birthday_celebration');
    
    $subject = '🎉 Doğum Gününüz Kutlu Olsun! - ' . $company_name;
    
    // Send email
    $result = insurance_crm_send_template_email(
        $customer->email,
        $subject,
        $template_content,
        $variables
    );
    
    // Log the birthday email send action
    if ($result) {
        error_log("Birthday email sent successfully to {$customer->email} for customer ID: {$customer_id}");
        
        // Optional: Log to user logs or system logs
        if (function_exists('insurance_crm_log_user_action')) {
            insurance_crm_log_user_action($user_id, 'birthday_email_sent', 
                "Doğum günü kutlama e-postası gönderildi: {$customer_name}");
        }
    } else {
        error_log("Failed to send birthday email to {$customer->email} for customer ID: {$customer_id}");
    }
    
    return $result;
}

/**
 * Get customers with birthday today based on user role
 * @param int $user_id Current user ID
 * @return array Array containing customers and total count
 */
function get_todays_birthday_customers_by_role($user_id = null) {
    global $wpdb;
    
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    $today_month_day = date('m-d');
    
    // Check if user has full admin access (Patron or Manager)
    if (has_full_admin_access($user_id)) {
        // Get all active representatives
        $all_rep_ids = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE status = 'active'");
        
        if (empty($all_rep_ids)) {
            return [
                'customers' => [],
                'total' => 0,
                'user_role' => 'admin'
            ];
        }
        
        $rep_ids_placeholder = implode(',', array_fill(0, count($all_rep_ids), '%d'));
        
        // Get all customers with birthday today from all representatives
        $query = $wpdb->prepare("
            SELECT 
                c.id,
                c.first_name,
                c.last_name,
                c.email,
                c.birth_date,
                c.phone,
                c.representative_id,
                r.user_id as rep_user_id,
                u.display_name as rep_name
            FROM {$wpdb->prefix}insurance_crm_customers c
            LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON c.representative_id = r.id
            LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
            WHERE c.representative_id IN ($rep_ids_placeholder)
                AND c.status = 'aktif'
                AND c.birth_date IS NOT NULL
                AND DATE_FORMAT(c.birth_date, '%%m-%%d') = %s
            ORDER BY c.first_name, c.last_name
        ", ...array_merge($all_rep_ids, [$today_month_day]));
        
        $customers = $wpdb->get_results($query);
        
        // Format the data
        foreach ($customers as &$customer) {
            $customer->full_name = trim($customer->first_name . ' ' . $customer->last_name);
            $customer->birth_date_formatted = !empty($customer->birth_date) ? 
                date('d.m.Y', strtotime($customer->birth_date)) : '';
            $customer->age = !empty($customer->birth_date) ? 
                date('Y') - date('Y', strtotime($customer->birth_date)) : '';
        }
        
        return [
            'customers' => $customers,
            'total' => count($customers),
            'user_role' => 'admin'
        ];
    } else {
        // For regular users, get only their own customers
        $rep = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
            $user_id
        ));
        
        if (!$rep) {
            return [
                'customers' => [],
                'total' => 0,
                'user_role' => 'representative'
            ];
        }
        
        $query = $wpdb->prepare("
            SELECT 
                c.id,
                c.first_name,
                c.last_name,
                c.email,
                c.birth_date,
                c.phone,
                c.representative_id
            FROM {$wpdb->prefix}insurance_crm_customers c
            WHERE c.representative_id = %d
                AND c.status = 'aktif'
                AND c.birth_date IS NOT NULL
                AND DATE_FORMAT(c.birth_date, '%%m-%%d') = %s
            ORDER BY c.first_name, c.last_name
        ", $rep->id, $today_month_day);
        
        $customers = $wpdb->get_results($query);
        
        // Format the data
        foreach ($customers as &$customer) {
            $customer->full_name = trim($customer->first_name . ' ' . $customer->last_name);
            $customer->birth_date_formatted = !empty($customer->birth_date) ? 
                date('d.m.Y', strtotime($customer->birth_date)) : '';
            $customer->age = !empty($customer->birth_date) ? 
                date('Y') - date('Y', strtotime($customer->birth_date)) : '';
        }
        
        return [
            'customers' => $customers,
            'total' => count($customers),
            'user_role' => 'representative'
        ];
    }
}

/**
 * Create a test customer with today's birthday (for testing purposes)
 * @param int $user_id User ID to assign the customer to
 * @return int|false Customer ID on success, false on failure
 */
function create_test_birthday_customer($user_id = null) {
    global $wpdb;
    
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    // Get representative ID for current user
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $user_id
    ));
    
    if (!$rep) {
        return false;
    }
    
    // Create a customer with today's birthday but different year
    $birth_year = rand(1970, 2000);
    $today_month_day = date('m-d');
    $birth_date = $birth_year . '-' . $today_month_day;
    
    $customer_data = array(
        'representative_id' => $rep->id,
        'first_name' => 'Test',
        'last_name' => 'Birthday',
        'email' => 'test.birthday@example.com',
        'phone' => '555-0123',
        'birth_date' => $birth_date,
        'status' => 'aktif',
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    );
    
    $result = $wpdb->insert(
        $wpdb->prefix . 'insurance_crm_customers',
        $customer_data
    );
    
    if ($result !== false) {
        return $wpdb->insert_id;
    }
    
    return false;
}

/**
 * Get team leader menu items based on user role
 * @param int $user_id User ID
 * @return array Menu items
 */
function get_team_leader_menu_items($user_id) {
    $role = get_user_role_in_hierarchy($user_id);
    
    $menu_items = array();
    
    if (is_team_leader($user_id) || has_full_admin_access($user_id)) {
        $menu_items = array(
            array(
                'title' => 'Ekip Yönetimi',
                'description' => 'Ekip üyelerini görüntüle ve yönet',
                'url' => generate_panel_url('team_detail'),
                'icon' => 'fas fa-users-cog',
                'class' => 'team'
            ),
            array(
                'title' => 'Ekip Performansı',
                'description' => 'Ekip performans raporlarını incele',
                'url' => generate_panel_url('reports') . '&type=team',
                'icon' => 'fas fa-chart-line',
                'class' => 'organization'
            ),
            array(
                'title' => 'Görev Ataması',
                'description' => 'Ekip üyelerine görev ata',
                'url' => generate_panel_url('tasks') . '&action=assign',
                'icon' => 'fas fa-tasks',
                'class' => 'representative'
            ),
            array(
                'title' => 'Hedef Belirleme',
                'description' => 'Ekip ve bireysel hedefleri belirle',
                'url' => generate_panel_url('targets'),
                'icon' => 'fas fa-bullseye',
                'class' => 'settings'
            )
        );
    }
    
    return $menu_items;
}
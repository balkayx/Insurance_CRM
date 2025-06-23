<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

function insurance_crm_get_dashboard_statistics() {
    global $wpdb;
    $current_user_id = get_current_user_id();
    
    // Active policies count and total premium
    if (current_user_can('manage_options')) {
        $policy_stats = $wpdb->get_row("
            SELECT COUNT(*) as total_policies,
                   SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END) as active_policies,
                   SUM(premium_amount) as total_premium,
                   SUM(CASE WHEN status = 'aktif' THEN premium_amount ELSE 0 END) as active_premium
            FROM {$wpdb->prefix}insurance_policies
        ");
    } else {
        $policy_stats = $wpdb->get_row($wpdb->prepare("
            SELECT COUNT(*) as total_policies,
                   SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END) as active_policies,
                   SUM(premium_amount) as total_premium,
                   SUM(CASE WHEN status = 'aktif' THEN premium_amount ELSE 0 END) as active_premium
            FROM {$wpdb->prefix}insurance_policies
            WHERE created_by = %d
        ", $current_user_id));
    }
    
    // Customers statistics
    if (current_user_can('manage_options')) {
        $customer_stats = $wpdb->get_row("
            SELECT COUNT(*) as total_customers,
                   SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END) as active_customers,
                   SUM(CASE WHEN category = 'bireysel' THEN 1 ELSE 0 END) as individual_customers,
                   SUM(CASE WHEN category = 'kurumsal' THEN 1 ELSE 0 END) as corporate_customers
            FROM {$wpdb->prefix}insurance_customers
        ");
    } else {
        $customer_stats = $wpdb->get_row($wpdb->prepare("
            SELECT COUNT(*) as total_customers,
                   SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END) as active_customers,
                   SUM(CASE WHEN category = 'bireysel' THEN 1 ELSE 0 END) as individual_customers,
                   SUM(CASE WHEN category = 'kurumsal' THEN 1 ELSE 0 END) as corporate_customers
            FROM {$wpdb->prefix}insurance_customers
            WHERE created_by = %d
        ", $current_user_id));
    }
    
    // Upcoming policy renewals
    if (current_user_can('manage_options')) {
        $upcoming_renewals = $wpdb->get_results("
            SELECT p.*, c.first_name, c.last_name 
            FROM {$wpdb->prefix}insurance_policies p
            JOIN {$wpdb->prefix}insurance_customers c ON p.customer_id = c.id
            WHERE p.status = 'aktif' 
            AND p.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY p.end_date ASC
            LIMIT 5
        ");
    } else {
        $upcoming_renewals = $wpdb->get_results($wpdb->prepare("
            SELECT p.*, c.first_name, c.last_name 
            FROM {$wpdb->prefix}insurance_policies p
            JOIN {$wpdb->prefix}insurance_customers c ON p.customer_id = c.id
            WHERE p.status = 'aktif' 
            AND p.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            AND p.created_by = %d
            ORDER BY p.end_date ASC
            LIMIT 5
        ", $current_user_id));
    }
    
    // Pending tasks
    if (current_user_can('manage_options')) {
        $pending_tasks = $wpdb->get_results("
            SELECT t.*, c.first_name, c.last_name
            FROM {$wpdb->prefix}insurance_tasks t
            JOIN {$wpdb->prefix}insurance_customers c ON t.customer_id = c.id
            WHERE t.status = 'pending'
            ORDER BY t.due_date ASC
            LIMIT 5
        ");
    } else {
        $pending_tasks = $wpdb->get_results($wpdb->prepare("
            SELECT t.*, c.first_name, c.last_name
            FROM {$wpdb->prefix}insurance_tasks t
            JOIN {$wpdb->prefix}insurance_customers c ON t.customer_id = c.id
            WHERE t.status = 'pending'
            AND t.created_by = %d
            ORDER BY t.due_date ASC
            LIMIT 5
        ", $current_user_id));
    }
    
    return [
        'policy_stats' => $policy_stats,
        'customer_stats' => $customer_stats,
        'upcoming_renewals' => $upcoming_renewals,
        'pending_tasks' => $pending_tasks
    ];
}

function insurance_crm_get_customer_report($start_date = null, $end_date = null, $customer_type = null) {
    global $wpdb;
    $current_user_id = get_current_user_id();
    
    $where_conditions = array("1=1");
    $where_values = array();
    
    if (!current_user_can('manage_options')) {
        $where_conditions[] = "c.created_by = %d";
        $where_values[] = $current_user_id;
    }
    
    if ($start_date) {
        $where_conditions[] = "c.created_at >= %s";
        $where_values[] = $start_date;
    }
    if ($end_date) {
        $where_conditions[] = "c.created_at <= %s";
        $where_values[] = $end_date;
    }
    if ($customer_type) {
        $where_conditions[] = "c.category = %s";
        $where_values[] = $customer_type;
    }
    
    $where_clause = implode(" AND ", $where_conditions);
    $query = "
        SELECT c.*, 
               COUNT(p.id) as total_policies,
               SUM(p.premium_amount) as total_premium
        FROM {$wpdb->prefix}insurance_customers c
        LEFT JOIN {$wpdb->prefix}insurance_policies p ON c.id = p.customer_id
        WHERE {$where_clause}
        GROUP BY c.id
        ORDER BY total_premium DESC
    ";
    
    if (!empty($where_values)) {
        return $wpdb->get_results($wpdb->prepare($query, $where_values));
    } else {
        return $wpdb->get_results($query);
    }
}

function insurance_crm_get_policy_report($start_date = null, $end_date = null, $policy_type = null) {
    global $wpdb;
    $current_user_id = get_current_user_id();
    
    $where_conditions = array("1=1");
    $where_values = array();
    
    if (!current_user_can('manage_options')) {
        $where_conditions[] = "p.created_by = %d";
        $where_values[] = $current_user_id;
    }
    
    if ($start_date) {
        $where_conditions[] = "p.created_at >= %s";
        $where_values[] = $start_date;
    }
    if ($end_date) {
        $where_conditions[] = "p.created_at <= %s";
        $where_values[] = $end_date;
    }
    if ($policy_type) {
        $where_conditions[] = "p.policy_type = %s";
        $where_values[] = $policy_type;
    }
    
    $where_clause = implode(" AND ", $where_conditions);
    $query = "
        SELECT p.*, 
               c.first_name,
               c.last_name,
               c.category as customer_type
        FROM {$wpdb->prefix}insurance_policies p
        JOIN {$wpdb->prefix}insurance_customers c ON p.customer_id = c.id
        WHERE {$where_clause}
        ORDER BY p.created_at DESC
    ";
    
    if (!empty($where_values)) {
        return $wpdb->get_results($wpdb->prepare($query, $where_values));
    } else {
        return $wpdb->get_results($query);
    }
}
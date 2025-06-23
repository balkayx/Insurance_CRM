<?php
/**
 * System operations logging class for Insurance CRM
 *
 * @package Insurance_CRM
 * @subpackage Insurance_CRM/includes/logging
 */

if (!defined('WPINC')) {
    die;
}

/**
 * System operations logging class
 */
class Insurance_CRM_System_Logger {
    
    /**
     * Log customer creation
     */
    public function log_customer_created($customer_id, $customer_data) {
        $this->log_system_action(
            'create_customer',
            'insurance_crm_customers',
            $customer_id,
            null,
            $customer_data,
            "Customer created: {$customer_data['first_name']} {$customer_data['last_name']}"
        );
    }
    
    /**
     * Log customer update
     */
    public function log_customer_updated($customer_id, $old_data, $new_data) {
        $this->log_system_action(
            'update_customer',
            'insurance_crm_customers',
            $customer_id,
            $old_data,
            $new_data,
            "Customer updated: {$new_data['first_name']} {$new_data['last_name']}"
        );
    }
    
    /**
     * Log customer deletion
     */
    public function log_customer_deleted($customer_id, $customer_data) {
        $this->log_system_action(
            'delete_customer',
            'insurance_crm_customers',
            $customer_id,
            $customer_data,
            null,
            "Customer deleted: {$customer_data['first_name']} {$customer_data['last_name']}"
        );
    }
    
    /**
     * Log policy creation
     */
    public function log_policy_created($policy_id, $policy_data) {
        $this->log_system_action(
            'create_policy',
            'insurance_crm_policies',
            $policy_id,
            null,
            $policy_data,
            "Policy created: {$policy_data['policy_number']}"
        );
    }
    
    /**
     * Log policy update
     */
    public function log_policy_updated($policy_id, $old_data, $new_data) {
        $this->log_system_action(
            'update_policy',
            'insurance_crm_policies',
            $policy_id,
            $old_data,
            $new_data,
            "Policy updated: {$new_data['policy_number']}"
        );
    }
    
    /**
     * Log policy deletion
     */
    public function log_policy_deleted($policy_id, $policy_data) {
        $this->log_system_action(
            'delete_policy',
            'insurance_crm_policies',
            $policy_id,
            $policy_data,
            null,
            "Policy deleted: {$policy_data['policy_number']}"
        );
    }
    
    /**
     * Log task creation
     */
    public function log_task_created($task_id, $task_data) {
        $this->log_system_action(
            'create_task',
            'insurance_crm_tasks',
            $task_id,
            null,
            $task_data,
            "Task created: {$task_data['title']}"
        );
    }
    
    /**
     * Log task update
     */
    public function log_task_updated($task_id, $old_data, $new_data) {
        $this->log_system_action(
            'update_task',
            'insurance_crm_tasks',
            $task_id,
            $old_data,
            $new_data,
            "Task updated: {$new_data['title']}"
        );
    }
    
    /**
     * Log task deletion
     */
    public function log_task_deleted($task_id, $task_data) {
        $this->log_system_action(
            'delete_task',
            'insurance_crm_tasks',
            $task_id,
            $task_data,
            null,
            "Task deleted: {$task_data['title']}"
        );
    }
    
    /**
     * Log file upload
     */
    public function log_file_uploaded($file_path, $file_data) {
        $this->log_system_action(
            'upload_file',
            'files',
            null,
            null,
            $file_data,
            "File uploaded: {$file_data['filename']}"
        );
    }
    
    /**
     * Log file deletion
     */
    public function log_file_deleted($file_path, $file_data) {
        $this->log_system_action(
            'delete_file',
            'files',
            null,
            $file_data,
            null,
            "File deleted: {$file_data['filename']}"
        );
    }
    
    /**
     * Main system action logging method
     */
    public function log_system_action($action, $table_name, $record_id = null, $old_values = null, $new_values = null, $details = '') {
        global $wpdb;
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            $user_id = 0; // System action
        }
        
        $ip_address = Insurance_CRM_Logger::get_client_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Prepare old and new values as JSON
        $old_values_json = null;
        $new_values_json = null;
        
        if ($old_values) {
            $old_values_json = wp_json_encode($this->sanitize_sensitive_data($old_values));
        }
        
        if ($new_values) {
            $new_values_json = wp_json_encode($this->sanitize_sensitive_data($new_values));
        }
        
        $wpdb->insert(
            $wpdb->prefix . 'insurance_system_logs',
            array(
                'user_id' => $user_id,
                'action' => $action,
                'table_name' => $table_name,
                'record_id' => $record_id,
                'old_values' => $old_values_json,
                'new_values' => $new_values_json,
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'details' => $details,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }
    
    /**
     * Sanitize sensitive data before logging
     */
    private function sanitize_sensitive_data($data) {
        if (!is_array($data)) {
            return $data;
        }
        
        $sensitive_fields = array(
            'password',
            'pass',
            'pwd',
            'user_pass',
            'tc_identity',
            'identity',
            'ssn',
            'credit_card',
            'bank_account'
        );
        
        $sanitized = $data;
        
        foreach ($sensitive_fields as $field) {
            if (isset($sanitized[$field])) {
                // For TC identity, keep last 2 digits for reference
                if ($field === 'tc_identity' && strlen($sanitized[$field]) === 11) {
                    $sanitized[$field] = '***' . substr($sanitized[$field], -2);
                } else {
                    $sanitized[$field] = '***REDACTED***';
                }
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Encrypt sensitive data (if encryption is enabled)
     */
    private function encrypt_sensitive_data($data) {
        // Check if encryption service is available
        if (class_exists('Insurance_CRM_Encryption_Service')) {
            $encryption = new Insurance_CRM_Encryption_Service();
            return $encryption->encrypt($data);
        }
        
        // Fallback to base64 encoding (not secure, but better than plain text)
        return base64_encode($data);
    }
    
    /**
     * Get system activity statistics
     */
    public function get_activity_stats($days = 30) {
        global $wpdb;
        
        $start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $stats = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                action,
                table_name,
                COUNT(*) as count
             FROM {$wpdb->prefix}insurance_system_logs 
             WHERE created_at >= %s
             GROUP BY action, table_name
             ORDER BY count DESC",
            $start_date
        ));
        
        return $stats;
    }
    
    /**
     * Get user activity summary
     */
    public function get_user_activity_summary($user_id, $days = 30) {
        global $wpdb;
        
        $start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $activity = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                action,
                table_name,
                COUNT(*) as count,
                MAX(created_at) as last_activity
             FROM {$wpdb->prefix}insurance_system_logs 
             WHERE user_id = %d 
             AND created_at >= %s
             GROUP BY action, table_name
             ORDER BY last_activity DESC",
            $user_id,
            $start_date
        ));
        
        return $activity;
    }
    
    /**
     * Get recent activities
     */
    public function get_recent_activities($limit = 50, $user_id = null) {
        global $wpdb;
        
        $where_clause = '';
        $params = array();
        
        if ($user_id) {
            $where_clause = 'WHERE sl.user_id = %d';
            $params[] = $user_id;
        }
        
        $params[] = $limit;
        
        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                sl.*,
                u.display_name as user_name
             FROM {$wpdb->prefix}insurance_system_logs sl
             LEFT JOIN {$wpdb->users} u ON sl.user_id = u.ID
             {$where_clause}
             ORDER BY sl.created_at DESC
             LIMIT %d",
            ...$params
        ));
        
        return $activities;
    }
    
    /**
     * Search logs
     */
    public function search_logs($filters = array(), $limit = 100, $offset = 0) {
        global $wpdb;
        
        $where_conditions = array();
        $params = array();
        
        // Date range filter
        if (!empty($filters['start_date'])) {
            $where_conditions[] = 'sl.created_at >= %s';
            $params[] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $where_conditions[] = 'sl.created_at <= %s';
            $params[] = $filters['end_date'];
        }
        
        // User filter
        if (!empty($filters['user_id'])) {
            $where_conditions[] = 'sl.user_id = %d';
            $params[] = $filters['user_id'];
        }
        
        // Action filter
        if (!empty($filters['action'])) {
            $where_conditions[] = 'sl.action LIKE %s';
            $params[] = '%' . $wpdb->esc_like($filters['action']) . '%';
        }
        
        // Table filter
        if (!empty($filters['table_name'])) {
            $where_conditions[] = 'sl.table_name = %s';
            $params[] = $filters['table_name'];
        }
        
        // Search term
        if (!empty($filters['search'])) {
            $where_conditions[] = '(sl.details LIKE %s OR sl.action LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($filters['search']) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }
        
        $params[] = $limit;
        $params[] = $offset;
        
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                sl.*,
                u.display_name as user_name
             FROM {$wpdb->prefix}insurance_system_logs sl
             LEFT JOIN {$wpdb->users} u ON sl.user_id = u.ID
             {$where_clause}
             ORDER BY sl.created_at DESC
             LIMIT %d OFFSET %d",
            ...$params
        ));
        
        return $logs;
    }
    
    /**
     * Count logs for search
     */
    public function count_logs($filters = array()) {
        global $wpdb;
        
        $where_conditions = array();
        $params = array();
        
        // Date range filter
        if (!empty($filters['start_date'])) {
            $where_conditions[] = 'created_at >= %s';
            $params[] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $where_conditions[] = 'created_at <= %s';
            $params[] = $filters['end_date'];
        }
        
        // User filter
        if (!empty($filters['user_id'])) {
            $where_conditions[] = 'user_id = %d';
            $params[] = $filters['user_id'];
        }
        
        // Action filter
        if (!empty($filters['action'])) {
            $where_conditions[] = 'action LIKE %s';
            $params[] = '%' . $wpdb->esc_like($filters['action']) . '%';
        }
        
        // Table filter
        if (!empty($filters['table_name'])) {
            $where_conditions[] = 'table_name = %s';
            $params[] = $filters['table_name'];
        }
        
        // Search term
        if (!empty($filters['search'])) {
            $where_conditions[] = '(details LIKE %s OR action LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($filters['search']) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_system_logs {$where_clause}",
            ...$params
        ));
        
        return $count;
    }
}
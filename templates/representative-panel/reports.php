<?php
/**
 * Modern Role-Based Reports System - Enhanced Version
 * @version 9.0.0 - Policies Design Integration + Advanced Role-Based Analytics
 * @created 2025-05-31
 * @author Anadolu Birlik CRM Team
 * @description Unified design with policies.php + comprehensive role-based reporting
 */

// Güvenlik kontrolü
if (!defined('ABSPATH') || !is_user_logged_in()) {
    wp_die(__('Bu sayfaya erişim yetkiniz bulunmamaktadır.', 'insurance-crm'), __('Erişim Engellendi', 'insurance-crm'), array('response' => 403));
}

// Global değişkenler
global $wpdb;
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
$users_table = $wpdb->users;

/**
 * BACKWARD COMPATIBILITY FUNCTIONS - Enhanced for Reports
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

if (!function_exists('get_user_role_level')) {
    function get_user_role_level() {
        global $wpdb;
        $current_user_id = get_current_user_id();
        $rep = $wpdb->get_row($wpdb->prepare(
            "SELECT role FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'",
            $current_user_id
        ));
        return $rep ? intval($rep->role) : 5;
    }
}

if (!function_exists('get_role_name')) {
    function get_role_name($role_level) {
        $roles = [1 => 'Patron', 2 => 'Müdür', 3 => 'Müdür Yardımcısı', 4 => 'Ekip Lideri', 5 => 'Müşteri Temsilcisi'];
        return $roles[$role_level] ?? 'Bilinmiyor';
    }
}

if (!function_exists('get_team_members_ids')) {
    function get_team_members_ids($team_leader_user_id) {
        $current_user_rep_id = get_current_user_rep_data();
        $current_rep_id = $current_user_rep_id ? $current_user_rep_id->id : 0;
        
        $settings = get_option('insurance_crm_settings', []);
        $teams = $settings['teams_settings']['teams'] ?? [];
        
        foreach ($teams as $team) {
            if (($team['leader_id'] ?? 0) == $current_rep_id) {
                $members = $team['members'] ?? [];
                return array_unique(array_merge($members, [$current_rep_id]));
            }
        }
        
        return [$current_rep_id];
    }
}

/**
 * Enhanced Reports Manager Class - Unified with Policies Design
 */
class ModernReportsManager {
    private $wpdb;
    private $user_id;
    private $user_rep_id;
    private $user_role_level;
    public $is_team_view;
    private $tables;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->user_id = get_current_user_id();
        
        $this->tables = [
            'policies' => $wpdb->prefix . 'insurance_crm_policies',
            'customers' => $wpdb->prefix . 'insurance_crm_customers',
            'representatives' => $wpdb->prefix . 'insurance_crm_representatives',
            'users' => $wpdb->users
        ];
        
        $this->user_rep_id = $this->getCurrentUserRepId();
        $this->user_role_level = $this->getUserRoleLevel();
        $this->is_team_view = (isset($_GET['view_type']) && $_GET['view_type'] === 'team');
    }

    public function getUserRoleLevel(): int {
        $rep = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT role FROM {$this->tables['representatives']} WHERE user_id = %d AND status = 'active'",
            $this->user_id
        ));
        return $rep ? intval($rep->role) : 5;
    }

    public function getCurrentUserRepId(): int {
        $rep_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$this->tables['representatives']} WHERE user_id = %d AND status = 'active'",
            $this->user_id
        ));
        return $rep_id ? intval($rep_id) : 0;
    }

    public function getRoleName(int $role_level): string {
        $roles = [1 => 'Patron', 2 => 'Müdür', 3 => 'Müdür Yardımcısı', 4 => 'Ekip Lideri', 5 => 'Müşteri Temsilcisi'];
        return $roles[$role_level] ?? 'Bilinmiyor';
    }

    private function getTeamMemberIds(): array {
        if ($this->user_role_level > 4 || $this->user_rep_id === 0) {
            return [$this->user_rep_id];
        }

        $settings = get_option('insurance_crm_settings', []);
        $teams = $settings['teams_settings']['teams'] ?? [];
        
        foreach ($teams as $team) {
            if (($team['leader_id'] ?? 0) == $this->user_rep_id) {
                $members = $team['members'] ?? [];
                return array_unique(array_merge($members, [$this->user_rep_id]));
            }
        }
        
        return [$this->user_rep_id];
    }

    private function buildWhereClause(array $additional_filters = [], string $context = 'policies'): array {
        $where_conditions = ['1=1'];
        $params = [];

        // Role-based access control
        if ($this->user_role_level <= 3) {
            // Patron, Müdür, Müdür Yrd. - hepsini görebilir
        } elseif ($this->user_role_level === 4) {
            $team_ids = $this->getTeamMemberIds();
            if ($this->is_team_view && !empty($team_ids)) {
                $placeholders = implode(',', array_fill(0, count($team_ids), '%d'));
                $where_conditions[] = "representative_id IN ({$placeholders})";
                $params = array_merge($params, $team_ids);
            } elseif (!$this->is_team_view) {
                $where_conditions[] = 'representative_id = %d';
                $params[] = $this->user_rep_id;
            }
        } else {
            $where_conditions[] = 'representative_id = %d';
            $params[] = $this->user_rep_id;
        }

        // Apply additional filters
        foreach ($additional_filters as $filter => $value) {
            if (!empty($value)) {
                switch ($filter) {
                    case 'start_date':
                        // Use appropriate date column based on context
                        if ($context === 'customers') {
                            $where_conditions[] = 'created_at >= %s';
                        } else {
                            $where_conditions[] = 'start_date >= %s';
                        }
                        $params[] = $value;
                        break;
                    case 'end_date':
                        // Use appropriate date column based on context
                        if ($context === 'customers') {
                            $where_conditions[] = 'created_at <= %s';
                        } else {
                            $where_conditions[] = 'start_date <= %s';
                        }
                        $params[] = $value;
                        break;
                    case 'policy_type':
                        $where_conditions[] = 'policy_type = %s';
                        $params[] = $value;
                        break;
                    case 'status':
                        $where_conditions[] = 'status = %s';
                        $params[] = $value;
                        break;
                }
            }
        }

        return ['where' => implode(' AND ', $where_conditions), 'params' => $params];
    }

    /**
     * Build WHERE clause for specific date field (e.g., cancellation_date)
     */
    private function buildWhereClauseForDateField(array $additional_filters = [], string $date_field = 'start_date'): array {
        $where_conditions = ['1=1'];
        $params = [];

        // Role-based access control
        if ($this->user_role_level <= 3) {
            // Patron, Müdür, Müdür Yrd. - hepsini görebilir
        } elseif ($this->user_role_level === 4) {
            $team_ids = $this->getTeamMemberIds();
            if ($this->is_team_view && !empty($team_ids)) {
                $placeholders = implode(',', array_fill(0, count($team_ids), '%d'));
                $where_conditions[] = "representative_id IN ({$placeholders})";
                $params = array_merge($params, $team_ids);
            } elseif (!$this->is_team_view) {
                $where_conditions[] = 'representative_id = %d';
                $params[] = $this->user_rep_id;
            }
        } else {
            $where_conditions[] = 'representative_id = %d';
            $params[] = $this->user_rep_id;
        }

        // Apply additional filters
        foreach ($additional_filters as $filter => $value) {
            if (!empty($value)) {
                switch ($filter) {
                    case 'start_date':
                        $where_conditions[] = "{$date_field} >= %s";
                        $params[] = $value;
                        break;
                    case 'end_date':
                        $where_conditions[] = "{$date_field} <= %s";
                        $params[] = $value;
                        break;
                    case 'policy_type':
                        $where_conditions[] = 'policy_type = %s';
                        $params[] = $value;
                        break;
                    case 'status':
                        $where_conditions[] = 'status = %s';
                        $params[] = $value;
                        break;
                }
            }
        }

        return ['where' => implode(' AND ', $where_conditions), 'params' => $params];
    }

    public function getDashboardStatistics(array $date_filters = []): array {
        $where_clause = $this->buildWhereClause($date_filters, 'policies');
        
        // Total premium for the selected period
        $total_premium = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COALESCE(SUM(premium_amount), 0) FROM {$this->tables['policies']} 
             WHERE {$where_clause['where']}",
            ...$where_clause['params']
        ));

        // Completed tasks (new policies) - now filtered by date range
        $completed_tasks = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tables['policies']} 
             WHERE {$where_clause['where']}",
            ...$where_clause['params']
        ));

        // Cancelled policies - filtered by cancellation_date within the selected period
        $cancelled_where_clause = $this->buildWhereClauseForDateField($date_filters, 'cancellation_date');
        $cancelled_policies = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tables['policies']} 
             WHERE {$cancelled_where_clause['where']} 
             AND cancellation_date IS NOT NULL",
            ...$cancelled_where_clause['params']
        ));

        // Renewal approaching (next 30 days)
        $renewal_approaching = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tables['policies']} 
             WHERE {$where_clause['where']} 
             AND status = 'aktif' 
             AND end_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)",
            ...$where_clause['params']
        ));

        // New customers - now filtered by date range
        $customers_where_clause = $this->buildWhereClause($date_filters, 'customers');
        $new_customers = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tables['customers']} 
             WHERE {$customers_where_clause['where']}",
            ...$customers_where_clause['params']
        ));

        // Quote count - actual count from customers table with has_offer = 1
        $quotes_where_clause = $this->buildWhereClause($date_filters, 'customers');
        $quote_count = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tables['customers']} 
             WHERE {$quotes_where_clause['where']} 
             AND has_offer = 1",
            ...$quotes_where_clause['params']
        ));

        return [
            'total_premium' => (float)$total_premium,
            'completed_tasks' => (int)$completed_tasks,
            'cancelled_policies' => (int)$cancelled_policies,
            'renewal_approaching' => (int)$renewal_approaching,
            'new_customers' => (int)$new_customers,
            'quote_count' => (int)$quote_count
        ];
    }

    /**
     * Check if date filters are active
     */
    private function hasActiveFilters(array $date_filters = []): bool {
        return !empty($date_filters['start_date']) || !empty($date_filters['end_date']) || !empty($date_filters['policy_type']);
    }

    /**
     * Get dynamic subtitle text based on filter status
     */
    public function getSubtitleText(string $default_text, array $date_filters = []): string {
        if ($this->hasActiveFilters($date_filters)) {
            // Map of replacements for different contexts
            $replacements = [
                'Bu ay yeni poliçeler' => 'Seçilen dönem yeni poliçeler',
                'Bu ay iptal edilenler' => 'Seçilen dönem iptal edilenler', 
                'Bu ay eklenenler' => 'Seçilen dönem eklenenler',
                'Tahmini bu ay' => 'Seçilen dönem',
                'Bu ay' => 'Seçilen dönem',
                'bu ay' => 'seçilen dönem'
            ];
            
            foreach ($replacements as $search => $replace) {
                if (strpos($default_text, $search) !== false) {
                    return str_replace($search, $replace, $default_text);
                }
            }
            
            return $default_text;
        }
        return $default_text;
    }

    public function getMonthlyTrend(array $date_filters = []): array {
        $where_clause = $this->buildWhereClause($date_filters, 'policies');
        
        $months_data = [];
        for ($i = 5; $i >= 0; $i--) {
            $month_start = date('Y-m-01', strtotime("-$i month"));
            $month_end = date('Y-m-t', strtotime("-$i month"));
            $month_name = date('M Y', strtotime("-$i month"));
            
            $policies_count = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tables['policies']} 
                 WHERE {$where_clause['where']} 
                 AND start_date BETWEEN %s AND %s",
                array_merge($where_clause['params'], [$month_start, $month_end])
            ));
            
            $customers_count = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tables['customers']} 
                 WHERE {$this->buildWhereClause($date_filters, 'customers')['where']} 
                 AND created_at BETWEEN %s AND %s",
                array_merge($this->buildWhereClause($date_filters, 'customers')['params'], [$month_start, $month_end])
            ));
            
            $months_data[] = [
                'month' => $month_name,
                'policies' => (int)$policies_count,
                'customers' => (int)$customers_count
            ];
        }
        
        return $months_data;
    }

    public function getPolicyTypeDistribution(array $date_filters = []): array {
        $where_clause = $this->buildWhereClause($date_filters, 'policies');
        
        $results = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT policy_type, COUNT(*) as count 
             FROM {$this->tables['policies']} 
             WHERE {$where_clause['where']} 
             GROUP BY policy_type 
             ORDER BY count DESC 
             LIMIT 10",
            ...$where_clause['params']
        ));
        
        $distribution = [];
        foreach ($results as $result) {
            $distribution[esc_html($result->policy_type)] = (int)$result->count;
        }
        
        return $distribution;
    }

    public function getTopRepresentatives(array $date_filters = []): array {
        if ($this->user_role_level > 2) {
            return []; // Only Patron and Manager can see this
        }
        
        $results = $this->wpdb->get_results(
            "SELECT r.id, u.display_name, 
                    COUNT(p.id) as policy_count,
                    COALESCE(SUM(p.premium_amount), 0) as total_premium
             FROM {$this->tables['representatives']} r
             LEFT JOIN {$this->tables['users']} u ON r.user_id = u.ID
             LEFT JOIN {$this->tables['policies']} p ON r.id = p.representative_id 
                   AND p.start_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
             WHERE r.status = 'active'
             GROUP BY r.id, u.display_name
             ORDER BY policy_count DESC, total_premium DESC
             LIMIT 10"
        );
        
        $top_reps = [];
        foreach ($results as $result) {
            $top_reps[] = [
                'name' => esc_html($result->display_name),
                'policy_count' => (int)$result->policy_count,
                'total_premium' => (float)$result->total_premium
            ];
        }
        
        return $top_reps;
    }

    public function getRecentActivities(array $date_filters = []): array {
        $where_clause = $this->buildWhereClause($date_filters, 'policies');
        
        $policies = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT p.policy_number, p.start_date, c.first_name, c.last_name, p.policy_type
             FROM {$this->tables['policies']} p
             LEFT JOIN {$this->tables['customers']} c ON p.customer_id = c.id
             WHERE {$where_clause['where']}
             ORDER BY p.start_date DESC
             LIMIT 10",
            ...$where_clause['params']
        ));
        
        $activities = [];
        foreach ($policies as $policy) {
            $activities[] = [
                'type' => 'policy',
                'description' => "Yeni poliçe: {$policy->policy_number} - {$policy->policy_type}",
                'customer' => esc_html($policy->first_name . ' ' . $policy->last_name),
                'date' => $policy->start_date
            ];
        }
        
        return $activities;
    }

    public function getPerformanceMetrics(array $date_filters = []): array {
        $where_clause = $this->buildWhereClause($date_filters, 'policies');
        
        // This month
        $this_month_start = date('Y-m-01');
        $this_month_end = date('Y-m-t');
        
        // Last month
        $last_month_start = date('Y-m-01', strtotime('-1 month'));
        $last_month_end = date('Y-m-t', strtotime('-1 month'));
        
        $this_month_policies = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tables['policies']} 
             WHERE {$where_clause['where']} 
             AND start_date BETWEEN %s AND %s",
            array_merge($where_clause['params'], [$this_month_start, $this_month_end])
        ));
        
        $last_month_policies = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tables['policies']} 
             WHERE {$where_clause['where']} 
             AND start_date BETWEEN %s AND %s",
            array_merge($where_clause['params'], [$last_month_start, $last_month_end])
        ));
        
        $this_month_premium = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COALESCE(SUM(premium_amount), 0) FROM {$this->tables['policies']} 
             WHERE {$where_clause['where']} 
             AND start_date BETWEEN %s AND %s",
            array_merge($where_clause['params'], [$this_month_start, $this_month_end])
        ));
        
        $growth_rate = 0;
        if ($last_month_policies > 0) {
            $growth_rate = round((($this_month_policies - $last_month_policies) / $last_month_policies) * 100, 1);
        }
        
        return [
            'this_month_policies' => (int)$this_month_policies,
            'last_month_policies' => (int)$last_month_policies,
            'this_month_premium' => (float)$this_month_premium,
            'growth_rate' => $growth_rate
        ];
    }
}

// Initialize the manager
try {
    $reports_manager = new ModernReportsManager();
} catch (Exception $e) {
    echo '<div class="notification-banner notification-error">
        <div class="notification-icon"><i class="fas fa-exclamation-circle"></i></div>
        <div class="notification-content">Sistem başlatılamadı: ' . esc_html($e->getMessage()) . '</div>
    </div>';
    return;
}

// Get date filters from URL parameters
$date_filters = [];
if (isset($_GET['start_date']) && !empty($_GET['start_date'])) {
    $date_filters['start_date'] = sanitize_text_field($_GET['start_date']);
}
if (isset($_GET['end_date']) && !empty($_GET['end_date'])) {
    $date_filters['end_date'] = sanitize_text_field($_GET['end_date']);
}
if (isset($_GET['policy_type']) && !empty($_GET['policy_type'])) {
    $date_filters['policy_type'] = sanitize_text_field($_GET['policy_type']);
}

// Get statistics for dashboard
$dashboard_stats = $reports_manager->getDashboardStatistics($date_filters);
$monthly_trend = $reports_manager->getMonthlyTrend($date_filters);
$policy_distribution = $reports_manager->getPolicyTypeDistribution($date_filters);
$top_representatives = $reports_manager->getTopRepresentatives($date_filters);
$recent_activities = $reports_manager->getRecentActivities($date_filters);
$performance_metrics = $reports_manager->getPerformanceMetrics($date_filters);

// Current action and tab
$current_action = sanitize_key($_GET['action'] ?? '');
$show_list = !in_array($current_action, ['export', 'detail']);
$active_tab = sanitize_key($_GET['tab'] ?? 'dashboard');

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raporlar - Modern CRM v9.0.0</title>
    
    <!-- External Libraries - Same as policies.php -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Load jQuery BEFORE Chart.js -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.js"></script>
</head>
<body>

<div class="modern-crm-container" id="reports-container" <?php echo !$show_list ? 'style="display:none;"' : ''; ?>>
    
    <!-- Header Section - Unified with policies.php -->
    <header class="crm-header">
        <div class="header-content">
            <div class="title-section">
                <div class="page-title">
                    <i class="fas fa-chart-bar"></i>
                    <h1>Raporlar</h1>
                    <span class="version-badge">v9.0.0</span>
                </div>
                <div class="user-badge">
                    <span class="role-badge">
                        <i class="fas fa-user-shield"></i>
                        <?php echo esc_html($reports_manager->getRoleName($reports_manager->getUserRoleLevel())); ?>
                    </span>
                </div>
            </div>
            
            <div class="header-actions">
                <?php if ($reports_manager->getUserRoleLevel() <= 4): ?>
                <div class="view-toggle">
                    <a href="<?php echo esc_url(add_query_arg(['view' => 'reports', 'view_type' => 'personal'], remove_query_arg(['view_type']))); ?>" 
                       class="view-btn <?php echo !$reports_manager->is_team_view ? 'active' : ''; ?>">
                        <i class="fas fa-user"></i>
                        <span>Kişisel</span>
                    </a>
                    <a href="<?php echo esc_url(add_query_arg(['view' => 'reports', 'view_type' => 'team'])); ?>" 
                       class="view-btn <?php echo $reports_manager->is_team_view ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i>
                        <span>Ekip</span>
                    </a>
                </div>
                <?php endif; ?>

                <div class="filter-controls">
                    <button type="button" id="dateRangeToggle" class="btn btn-outline filter-toggle <?php echo (!empty($_GET['start_date']) || !empty($_GET['end_date']) || !empty($_GET['policy_type'])) ? 'has-filters' : ''; ?>">
                        <i class="fas fa-calendar"></i>
                        <span>Tarih Filtresi</span>
                        <?php if (!empty($_GET['start_date']) || !empty($_GET['end_date']) || !empty($_GET['policy_type'])): ?>
                            <span class="filter-indicator">●</span>
                        <?php endif; ?>
                        <i class="fas fa-chevron-down chevron"></i>
                    </button>
                    
                    <button type="button" id="exportToggle" class="btn btn-success">
                        <i class="fas fa-download"></i>
                        <span>Dışa Aktar</span>
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- Date Filter Form -->
    <div id="dateFilterPanel" class="date-filter-panel" style="display: <?php echo (!empty($_GET['start_date']) || !empty($_GET['end_date']) || !empty($_GET['policy_type'])) ? 'block' : 'none'; ?>;">
        <?php if (!empty($_GET['start_date']) || !empty($_GET['end_date']) || !empty($_GET['policy_type'])): ?>
        <div class="active-filters">
            <span class="filter-label">Aktif Filtreler:</span>
            <?php if (!empty($_GET['start_date'])): ?>
                <span class="filter-chip">Başlangıç: <?php echo esc_html($_GET['start_date']); ?></span>
            <?php endif; ?>
            <?php if (!empty($_GET['end_date'])): ?>
                <span class="filter-chip">Bitiş: <?php echo esc_html($_GET['end_date']); ?></span>
            <?php endif; ?>
            <?php if (!empty($_GET['policy_type'])): ?>
                <span class="filter-chip">Tür: <?php echo esc_html(ucfirst($_GET['policy_type'])); ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <form method="get" class="date-filter-form" id="dateFilterForm">
            <input type="hidden" name="view" value="reports">
            <input type="hidden" name="tab" value="<?php echo esc_attr($active_tab); ?>">
            <?php if (isset($_GET['view_type'])): ?>
                <input type="hidden" name="view_type" value="<?php echo esc_attr($_GET['view_type']); ?>">
            <?php endif; ?>
            
            <div class="filter-fields">
                <div class="field-group">
                    <label for="start_date">Başlangıç Tarihi:</label>
                    <input type="date" id="start_date" name="start_date" 
                           value="<?php echo isset($_GET['start_date']) ? esc_attr($_GET['start_date']) : date('Y-m-01'); ?>">
                </div>
                
                <div class="field-group">
                    <label for="end_date">Bitiş Tarihi:</label>
                    <input type="date" id="end_date" name="end_date" 
                           value="<?php echo isset($_GET['end_date']) ? esc_attr($_GET['end_date']) : date('Y-m-d'); ?>">
                </div>
                
                <div class="field-group">
                    <label for="policy_type">Poliçe Türü:</label>
                    <select name="policy_type" id="policy_type">
                        <option value="">Tümü</option>
                        <option value="trafik" <?php echo (isset($_GET['policy_type']) && $_GET['policy_type'] === 'trafik') ? 'selected' : ''; ?>>Trafik</option>
                        <option value="kasko" <?php echo (isset($_GET['policy_type']) && $_GET['policy_type'] === 'kasko') ? 'selected' : ''; ?>>Kasko</option>
                        <option value="konut" <?php echo (isset($_GET['policy_type']) && $_GET['policy_type'] === 'konut') ? 'selected' : ''; ?>>Konut</option>
                        <option value="dask" <?php echo (isset($_GET['policy_type']) && $_GET['policy_type'] === 'dask') ? 'selected' : ''; ?>>DASK</option>
                        <option value="saglik" <?php echo (isset($_GET['policy_type']) && $_GET['policy_type'] === 'saglik') ? 'selected' : ''; ?>>Sağlık</option>
                    </select>
                </div>
                
                <div class="field-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i>
                        Filtrele
                    </button>
                    <button type="button" class="btn btn-outline" id="resetFilters">
                        <i class="fas fa-undo"></i>
                        Sıfırla
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Quick Stats Cards - Role-based metrics -->
    <section class="dashboard-section">
        <div class="stats-cards">
            <div class="stat-card primary">
                <div class="stat-icon">
                    <i class="fas fa-lira-sign"></i>
                </div>
                <div class="stat-content">
                    <h3>Toplam Prim Tutarı</h3>
                    <div class="stat-value"><?php echo number_format($dashboard_stats['total_premium'], 0, ',', '.'); ?> ₺</div>
                    <div class="stat-subtitle">Seçilen dönem toplam primi</div>
                </div>
            </div>

            <div class="stat-card success">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <h3>Yeni Poliçeler</h3>
                    <div class="stat-value"><?php echo number_format($dashboard_stats['completed_tasks']); ?></div>
                    <div class="stat-subtitle"><?php echo $reports_manager->getSubtitleText('Bu ay yeni poliçeler', $date_filters); ?></div>
                </div>
            </div>

            <div class="stat-card danger">
                <div class="stat-icon">
                    <i class="fas fa-ban"></i>
                </div>
                <div class="stat-content">
                    <h3>İptal Olan Poliçeler</h3>
                    <div class="stat-value"><?php echo number_format($dashboard_stats['cancelled_policies']); ?></div>
                    <div class="stat-subtitle"><?php echo $reports_manager->getSubtitleText('Bu ay iptal edilenler', $date_filters); ?></div>
                </div>
            </div>

            <div class="stat-card warning">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-content">
                    <h3>Yenilemesi Yaklaşan</h3>
                    <div class="stat-value"><?php echo number_format($dashboard_stats['renewal_approaching']); ?></div>
                    <div class="stat-subtitle">Önümüzdeki 30 gün</div>
                </div>
            </div>

            <div class="stat-card info">
                <div class="stat-icon">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div class="stat-content">
                    <h3>Yeni Müşteriler</h3>
                    <div class="stat-value"><?php echo number_format($dashboard_stats['new_customers']); ?></div>
                    <div class="stat-subtitle"><?php echo $reports_manager->getSubtitleText('Bu ay eklenenler', $date_filters); ?></div>
                </div>
            </div>

            <div class="stat-card secondary">
                <div class="stat-icon">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="stat-content">
                    <h3>Teklif Sayısı</h3>
                    <div class="stat-value"><?php echo number_format($dashboard_stats['quote_count']); ?></div>
                    <div class="stat-subtitle"><?php echo $reports_manager->getSubtitleText('Tahmini bu ay', $date_filters); ?></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Navigation Tabs -->
    <div class="reports-nav">
        <nav class="nav-tabs">
            <button class="nav-tab <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>" data-tab="dashboard">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </button>
            <button class="nav-tab <?php echo $active_tab === 'performance' ? 'active' : ''; ?>" data-tab="performance">
                <i class="fas fa-chart-line"></i>
                <span>Performans</span>
            </button>
            <button class="nav-tab <?php echo $active_tab === 'analysis' ? 'active' : ''; ?>" data-tab="analysis">
                <i class="fas fa-analytics"></i>
                <span>Analiz</span>
            </button>
            <?php if ($reports_manager->getUserRoleLevel() <= 2): ?>
            <button class="nav-tab <?php echo $active_tab === 'management' ? 'active' : ''; ?>" data-tab="management">
                <i class="fas fa-users-cog"></i>
                <span>Yönetim</span>
            </button>
            <?php endif; ?>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="reports-content">
        <!-- Dashboard Tab -->
        <div id="dashboard-content" class="tab-content <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>">
            <div class="content-row">
                <!-- Monthly Trend Chart -->
                <div class="report-card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-chart-area"></i>
                            Aylık Trend
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="monthlyTrendChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Policy Type Distribution -->
                <div class="report-card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-chart-pie"></i>
                            Poliçe Türü Dağılımı
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="policyTypeChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-row">
                <!-- Recent Activities -->
                <div class="report-card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-history"></i>
                            Son Aktiviteler
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="activities-list">
                            <?php if (!empty($recent_activities)): ?>
                                <?php foreach (array_slice($recent_activities, 0, 5) as $activity): ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class="fas fa-file-contract"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-description"><?php echo esc_html($activity['description']); ?></div>
                                        <div class="activity-meta">
                                            <span class="activity-customer"><?php echo esc_html($activity['customer']); ?></span>
                                            <span class="activity-date"><?php echo date('d.m.Y', strtotime($activity['date'])); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state-small">
                                    <i class="fas fa-clock"></i>
                                    <p>Henüz aktivite yok</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Performance Summary -->
                <div class="report-card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-trophy"></i>
                            Performans Özeti
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="performance-metrics">
                            <div class="metric-item">
                                <div class="metric-value"><?php echo number_format($performance_metrics['this_month_policies']); ?></div>
                                <div class="metric-label">Bu Ay Poliçe</div>
                            </div>
                            <div class="metric-item">
                                <div class="metric-value growth-<?php echo $performance_metrics['growth_rate'] >= 0 ? 'positive' : 'negative'; ?>">
                                    <?php echo $performance_metrics['growth_rate']; ?>%
                                </div>
                                <div class="metric-label">Büyüme Oranı</div>
                            </div>
                            <div class="metric-item">
                                <div class="metric-value">₺<?php echo number_format($performance_metrics['this_month_premium'], 0, ',', '.'); ?></div>
                                <div class="metric-label">Bu Ay Prim</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Performance Tab -->
        <div id="performance-content" class="tab-content <?php echo $active_tab === 'performance' ? 'active' : ''; ?>">
            <div class="content-row">
                <div class="report-card full-width">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-chart-line"></i>
                            Detaylı Performans Analizi
                        </h3>
                        <div class="card-actions">
                            <button class="btn btn-sm btn-outline" onclick="exportChart('performanceChart')">
                                <i class="fas fa-download"></i>
                                Grafik İndir
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container large">
                            <canvas id="performanceChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-row">
                <!-- Performance Metrics Table -->
                <div class="report-card full-width">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-table"></i>
                            Aylık Performans Tablosu
                        </h3>
                        <div class="card-actions">
                            <button class="btn btn-sm btn-secondary" onclick="exportTableToCSV('performanceTable')">
                                <i class="fas fa-file-csv"></i>
                                CSV İndir
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-wrapper">
                            <table id="performanceTable" class="policies-table">
                                <thead>
                                    <tr>
                                        <th>Ay</th>
                                        <th>Yeni Poliçeler</th>
                                        <th>Yeni Müşteriler</th>
                                        <th>Hedef (10)</th>
                                        <th>Başarı Oranı</th>
                                        <th>Durum</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($monthly_trend as $month_data): ?>
                                    <?php 
                                    $target = 10;
                                    $achievement = $month_data['policies'] > 0 ? round(($month_data['policies'] / $target) * 100, 1) : 0;
                                    $status_class = $achievement >= 100 ? 'success' : ($achievement >= 70 ? 'warning' : 'danger');
                                    ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($month_data['month']); ?></strong></td>
                                        <td><?php echo number_format($month_data['policies']); ?></td>
                                        <td><?php echo number_format($month_data['customers']); ?></td>
                                        <td><?php echo $target; ?></td>
                                        <td>
                                            <span class="achievement <?php echo $status_class; ?>">
                                                <?php echo $achievement; ?>%
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php 
                                                if ($achievement >= 100) echo 'Hedef Aşıldı';
                                                elseif ($achievement >= 70) echo 'İyi';
                                                else echo 'Gelişim Gerekli';
                                                ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Analysis Tab -->
        <div id="analysis-content" class="tab-content <?php echo $active_tab === 'analysis' ? 'active' : ''; ?>">
            <div class="content-row">
                <!-- Policy Analysis -->
                <div class="report-card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-chart-donut"></i>
                            Poliçe Analizi
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="analysis-summary">
                            <div class="summary-item">
                                <span class="summary-label">En Popüler Tür</span>
                                <span class="summary-value">
                                    <?php 
                                    $top_policy_type = !empty($policy_distribution) ? array_keys($policy_distribution)[0] : 'Belirsiz';
                                    echo esc_html($top_policy_type);
                                    ?>
                                </span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Toplam Çeşit</span>
                                <span class="summary-value"><?php echo count($policy_distribution); ?></span>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="analysisChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Trend Analysis -->
                <div class="report-card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-trending-up"></i>
                            Trend Analizi
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="trend-indicators">
                            <?php 
                            $trend_growth = 0;
                            if (count($monthly_trend) >= 2) {
                                $latest = end($monthly_trend);
                                $previous = prev($monthly_trend);
                                if ($previous['policies'] > 0) {
                                    $trend_growth = round((($latest['policies'] - $previous['policies']) / $previous['policies']) * 100, 1);
                                }
                            }
                            ?>
                            <div class="trend-item <?php echo $trend_growth >= 0 ? 'positive' : 'negative'; ?>">
                                <div class="trend-icon">
                                    <i class="fas fa-arrow-<?php echo $trend_growth >= 0 ? 'up' : 'down'; ?>"></i>
                                </div>
                                <div class="trend-content">
                                    <div class="trend-value"><?php echo abs($trend_growth); ?>%</div>
                                    <div class="trend-label">Son Ay Değişim</div>
                                </div>
                            </div>
                            
                            <div class="trend-item">
                                <div class="trend-icon">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div class="trend-content">
                                    <div class="trend-value">
                                        <?php 
                                        $avg_monthly = count($monthly_trend) > 0 ? 
                                            round(array_sum(array_column($monthly_trend, 'policies')) / count($monthly_trend), 1) : 0;
                                        echo $avg_monthly;
                                        ?>
                                    </div>
                                    <div class="trend-label">Aylık Ortalama</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-row">
                <div class="report-card full-width">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-chart-bar"></i>
                            Karşılaştırmalı Analiz
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="comparison-chart">
                            <canvas id="comparisonChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($reports_manager->getUserRoleLevel() <= 2): ?>
        <!-- Management Tab - Only for Patron and Manager -->
        <div id="management-content" class="tab-content <?php echo $active_tab === 'management' ? 'active' : ''; ?>">
            <div class="content-row">
                <!-- Top Representatives -->
                <div class="report-card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-star"></i>
                            En İyi Temsilciler
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="representatives-list">
                            <?php if (!empty($top_representatives)): ?>
                                <?php foreach (array_slice($top_representatives, 0, 5) as $index => $rep): ?>
                                <div class="rep-item">
                                    <div class="rep-rank">#<?php echo $index + 1; ?></div>
                                    <div class="rep-info">
                                        <div class="rep-name"><?php echo esc_html($rep['name']); ?></div>
                                        <div class="rep-stats">
                                            <?php echo $rep['policy_count']; ?> poliçe • 
                                            ₺<?php echo number_format($rep['total_premium'], 0, ',', '.'); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state-small">
                                    <i class="fas fa-users"></i>
                                    <p>Veri bulunamadı</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Team Performance -->
                <div class="report-card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-users-cog"></i>
                            Ekip Performansı
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="team-performance">
                            <div class="performance-ring">
                                <div class="ring-chart" data-percentage="<?php echo min(100, $performance_metrics['growth_rate'] + 50); ?>">
                                    <div class="ring-text">
                                        <span class="ring-value"><?php echo $performance_metrics['growth_rate']; ?>%</span>
                                        <span class="ring-label">Büyüme</span>
                                    </div>
                                </div>
                            </div>
                            <div class="performance-details">
                                <div class="detail-item">
                                    <span class="detail-label">Bu Ay Toplam</span>
                                    <span class="detail-value"><?php echo $performance_metrics['this_month_policies']; ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Geçen Ay</span>
                                    <span class="detail-value"><?php echo $performance_metrics['last_month_policies']; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-row">
                <div class="report-card full-width">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-chart-network"></i>
                            Yönetim Dashboard
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="management-grid">
                            <div class="management-card">
                                <div class="management-icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="management-content">
                                    <h4>Aktif Temsilciler</h4>
                                    <div class="management-value"><?php echo count($top_representatives); ?></div>
                                </div>
                            </div>
                            
                            <div class="management-card">
                                <div class="management-icon">
                                    <i class="fas fa-target"></i>
                                </div>
                                <div class="management-content">
                                    <h4>Hedef Başarı</h4>
                                    <div class="management-value">
                                        <?php 
                                        $avg_achievement = 0;
                                        if (!empty($monthly_trend)) {
                                            $total_policies = array_sum(array_column($monthly_trend, 'policies'));
                                            $total_target = count($monthly_trend) * 10;
                                            $avg_achievement = $total_target > 0 ? round(($total_policies / $total_target) * 100, 1) : 0;
                                        }
                                        echo $avg_achievement; 
                                        ?>%
                                    </div>
                                </div>
                            </div>
                            
                            <div class="management-card">
                                <div class="management-icon">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div class="management-content">
                                    <h4>Toplam Prim</h4>
                                    <div class="management-value">₺<?php echo number_format($performance_metrics['this_month_premium'], 0, ',', '.'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Export Modal -->
    <div id="exportModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-download"></i> Rapor Dışa Aktar</h3>
                <button class="close-modal">×</button>
            </div>
            <div class="modal-body">
                <div class="export-options">
                    <div class="export-option" onclick="exportCurrentTab('pdf')">
                        <div class="export-icon">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                        <div class="export-details">
                            <h4>PDF Rapor</h4>
                            <p>Mevcut sekmeyi PDF olarak kaydet</p>
                        </div>
                    </div>
                    
                    <div class="export-option" onclick="exportCurrentTab('csv')">
                        <div class="export-icon">
                            <i class="fas fa-file-csv"></i>
                        </div>
                        <div class="export-details">
                            <h4>CSV Veri</h4>
                            <p>Tablo verilerini Excel için kaydet</p>
                        </div>
                    </div>
                    
                    <div class="export-option" onclick="exportCurrentTab('png')">
                        <div class="export-icon">
                            <i class="fas fa-image"></i>
                        </div>
                        <div class="export-details">
                            <h4>PNG Görsel</h4>
                            <p>Grafikleri resim olarak kaydet</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Modern CSS Styles - Same as policies.php with reports enhancements */
:root {
    --primary: #1976d2;
    --primary-dark: #1565c0;
    --primary-light: #42a5f5;
    --secondary: #9c27b0;
    --success: #2e7d32;
    --warning: #f57c00;
    --danger: #d32f2f;
    --info: #0288d1;
    --surface: #ffffff;
    --surface-variant: #f5f5f5;
    --surface-container: #fafafa;
    --on-surface: #1c1b1f;
    --on-surface-variant: #49454f;
    --outline: #79747e;
    --outline-variant: #cac4d0;
    --font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    --transition-fast: 150ms ease;
    --transition-base: 250ms ease;
    --radius-lg: 0.75rem;
    --radius-xl: 1rem;
    --spacing-md: 1rem;
    --spacing-lg: 1.5rem;
    --spacing-xl: 2rem;
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
}

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

/* Header Styles - Same as policies.php */
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
    font-size: 1.25rem;
    color: var(--primary);
}

.page-title h1 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--on-surface);
}

.version-badge {
    background: linear-gradient(135deg, #4caf50, #2e7d32);
    color: white;
    padding: 2px 8px;
    border-radius: 0.25rem;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.5px;
}

.role-badge {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    padding: var(--spacing-sm) var(--spacing-md);
    border-radius: var(--radius-xl);
    font-size: 0.875rem;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

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
    padding: 0.25rem;
}

.view-btn {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem var(--spacing-md);
    border-radius: 0.5rem;
    text-decoration: none;
    font-size: 0.875rem;
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
    gap: 0.5rem;
}

/* Date Filter Panel */
.date-filter-panel {
    background: var(--surface);
    border: 1px solid var(--outline-variant);
    border-radius: var(--radius-lg);
    padding: var(--spacing-lg);
    margin-bottom: var(--spacing-lg);
    box-shadow: var(--shadow-sm);
}

.active-filters {
    background: rgba(25, 118, 210, 0.1);
    border: 1px solid rgba(25, 118, 210, 0.2);
    border-radius: var(--radius-md);
    padding: var(--spacing-md);
    margin-bottom: var(--spacing-md);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.filter-label {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--primary);
}

.filter-chip {
    background: var(--primary);
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 500;
}

.date-filter-form {
    display: block;
}

.filter-fields {
    display: flex;
    align-items: flex-end;
    gap: var(--spacing-md);
    flex-wrap: wrap;
}

.field-group {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    min-width: 150px;
}

.field-group label {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--on-surface);
    margin-bottom: 0.25rem;
}

.field-group input,
.field-group select {
    padding: 0.5rem;
    border: 1px solid var(--outline-variant);
    border-radius: var(--radius-md);
    font-size: 0.875rem;
    background: var(--surface);
    color: var(--on-surface);
    transition: border-color var(--transition-fast);
}

.field-group input:focus,
.field-group select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(25, 118, 210, 0.1);
}

.filter-toggle.active {
    background: var(--primary);
    color: white;
}

.filter-toggle.has-filters {
    border-color: var(--primary);
    background: rgba(25, 118, 210, 0.1);
}

.filter-indicator {
    color: var(--primary);
    font-size: 0.5rem;
    margin-left: 0.25rem;
}

.chevron {
    transition: transform var(--transition-fast);
}

/* Enhanced Button Styles */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.5rem var(--spacing-md);
    border: 1px solid transparent;
    border-radius: var(--radius-lg);
    font-size: 0.875rem;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: all var(--transition-fast);
    background: none;
    white-space: nowrap;
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

.btn-success {
    background: var(--success);
    color: white;
}

.btn-success:hover {
    background: #2e7d32;
    transform: translateY(-1px);
}

.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

/* Dashboard Stats Cards */
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

.stat-card.info:before {
    background: linear-gradient(90deg, var(--info), #03a9f4);
}

.stat-card.secondary:before {
    background: linear-gradient(90deg, var(--secondary), #e91e63);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: var(--radius-xl);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
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
    background: linear-gradient(135deg, var(--secondary), #e91e63);
}

.stat-content h3 {
    margin: 0 0 0.5rem 0;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--on-surface-variant);
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--on-surface);
    margin-bottom: 0.25rem;
    white-space: nowrap;
    display: inline-block;
}

.stat-subtitle {
    font-size: 0.875rem;
    color: var(--on-surface-variant);
}

/* Navigation Tabs */
.reports-nav {
    margin-bottom: var(--spacing-xl);
}

.nav-tabs {
    display: flex;
    background: var(--surface);
    border-radius: var(--radius-lg);
    padding: 4px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
    overflow-x: auto;
}

.nav-tab {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    border: none;
    background: none;
    color: var(--on-surface-variant);
    font-size: 0.875rem;
    font-weight: 500;
    border-radius: 6px;
    cursor: pointer;
    transition: all var(--transition-fast);
    white-space: nowrap;
}

.nav-tab:hover {
    background: var(--surface-variant);
    color: var(--on-surface);
}

.nav-tab.active {
    background: var(--primary);
    color: white;
    box-shadow: var(--shadow-sm);
}

/* Content */
.reports-content {
    min-height: 500px;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.content-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: var(--spacing-lg);
    margin-bottom: var(--spacing-lg);
}

.content-row .full-width {
    grid-column: 1 / -1;
}

/* Report Cards */
.report-card {
    background: var(--surface);
    border-radius: var(--radius-lg);
    border: 1px solid var(--outline-variant);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--spacing-lg);
    border-bottom: 1px solid var(--outline-variant);
    background: var(--surface-variant);
}

.card-header h3 {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--on-surface);
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}

.card-header h3 i {
    color: var(--primary);
}

.card-actions {
    display: flex;
    gap: 8px;
}

.card-body {
    padding: var(--spacing-lg);
}

/* Charts */
.chart-container {
    height: 250px;
    margin: var(--spacing-lg) 0;
}

.chart-container.large {
    height: 400px;
}

/* Activities List */
.activities-list {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-md);
}

.activity-item {
    display: flex;
    align-items: flex-start;
    gap: var(--spacing-md);
    padding: var(--spacing-md);
    background: var(--surface-variant);
    border-radius: var(--radius-lg);
    border: 1px solid var(--outline-variant);
    transition: all var(--transition-fast);
}

.activity-item:hover {
    background: var(--surface);
    box-shadow: var(--shadow-sm);
}

.activity-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--primary);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.activity-content {
    flex: 1;
}

.activity-description {
    font-weight: 500;
    color: var(--on-surface);
    margin-bottom: 4px;
}

.activity-meta {
    display: flex;
    gap: var(--spacing-md);
    font-size: 0.875rem;
    color: var(--on-surface-variant);
}

.activity-customer {
    font-weight: 500;
}

/* Performance Metrics */
.performance-metrics {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: var(--spacing-lg);
    text-align: center;
}

.metric-item {
    padding: var(--spacing-md);
    background: var(--surface-variant);
    border-radius: var(--radius-lg);
    border: 1px solid var(--outline-variant);
}

.metric-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--on-surface);
    margin-bottom: 4px;
}

.metric-value.growth-positive {
    color: var(--success);
}

.metric-value.growth-negative {
    color: var(--danger);
}

.metric-label {
    font-size: 0.875rem;
    color: var(--on-surface-variant);
}

/* Analysis Summary */
.analysis-summary {
    display: flex;
    justify-content: space-around;
    gap: var(--spacing-lg);
    margin-bottom: var(--spacing-lg);
    padding: var(--spacing-lg);
    background: var(--surface-variant);
    border-radius: var(--radius-lg);
}

.summary-item {
    text-align: center;
}

.summary-label {
    display: block;
    font-size: 0.875rem;
    color: var(--on-surface-variant);
    margin-bottom: 4px;
}

.summary-value {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--on-surface);
}

/* Trend Indicators */
.trend-indicators {
    display: flex;
    gap: var(--spacing-lg);
    justify-content: space-around;
}

.trend-item {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    padding: var(--spacing-md);
    background: var(--surface-variant);
    border-radius: var(--radius-lg);
    border: 1px solid var(--outline-variant);
}

.trend-item.positive {
    border-color: var(--success);
    background: rgba(46, 125, 50, 0.05);
}

.trend-item.negative {
    border-color: var(--danger);
    background: rgba(211, 47, 47, 0.05);
}

.trend-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.125rem;
}

.trend-item.positive .trend-icon {
    background: var(--success);
    color: white;
}

.trend-item.negative .trend-icon {
    background: var(--danger);
    color: white;
}

.trend-item:not(.positive):not(.negative) .trend-icon {
    background: var(--info);
    color: white;
}

.trend-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--on-surface);
}

.trend-label {
    font-size: 0.875rem;
    color: var(--on-surface-variant);
}

/* Representatives List */
.representatives-list {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-md);
}

.rep-item {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    padding: var(--spacing-md);
    background: var(--surface-variant);
    border-radius: var(--radius-lg);
    border: 1px solid var(--outline-variant);
    transition: all var(--transition-fast);
}

.rep-item:hover {
    background: var(--surface);
    box-shadow: var(--shadow-sm);
}

.rep-rank {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--primary);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.875rem;
}

.rep-info {
    flex: 1;
}

.rep-name {
    font-weight: 600;
    color: var(--on-surface);
    margin-bottom: 2px;
}

.rep-stats {
    font-size: 0.875rem;
    color: var(--on-surface-variant);
}

/* Team Performance */
.team-performance {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--spacing-lg);
}

.performance-ring {
    position: relative;
}

.ring-chart {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: conic-gradient(var(--primary) 0deg, var(--primary) calc(var(--percentage, 0) * 3.6deg), var(--outline-variant) calc(var(--percentage, 0) * 3.6deg));
    display: flex;
    align-items: center;
    justify-content: center;
}

.ring-chart::before {
    content: '';
    width: 90px;
    height: 90px;
    border-radius: 50%;
    background: var(--surface);
}

.ring-text {
    position: absolute;
    text-align: center;
}

.ring-value {
    display: block;
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--on-surface);
}

.ring-label {
    font-size: 0.875rem;
    color: var(--on-surface-variant);
}

.performance-details {
    width: 100%;
}

.detail-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--spacing-sm) 0;
    border-bottom: 1px solid var(--outline-variant);
}

.detail-item:last-child {
    border-bottom: none;
}

.detail-label {
    font-size: 0.875rem;
    color: var(--on-surface-variant);
}

.detail-value {
    font-weight: 600;
    color: var(--on-surface);
}

/* Management Grid */
.management-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--spacing-lg);
}

.management-card {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    padding: var(--spacing-lg);
    background: var(--surface-variant);
    border-radius: var(--radius-lg);
    border: 1px solid var(--outline-variant);
    transition: all var(--transition-fast);
}

.management-card:hover {
    background: var(--surface);
    box-shadow: var(--shadow-sm);
    transform: translateY(-2px);
}

.management-icon {
    width: 50px;
    height: 50px;
    border-radius: var(--radius-lg);
    background: var(--primary);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

.management-content h4 {
    margin: 0 0 4px 0;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--on-surface-variant);
}

.management-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--on-surface);
}

/* Table Styles */
.table-wrapper {
    overflow-x: auto;
    border-radius: var(--radius-lg);
    border: 1px solid var(--outline-variant);
}

.policies-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--surface);
    min-width: 600px;
}

.policies-table th,
.policies-table td {
    padding: var(--spacing-md);
    text-align: left;
    border-bottom: 1px solid var(--outline-variant);
    font-size: 0.875rem;
    vertical-align: middle;
}

.policies-table th {
    background: var(--surface-variant);
    font-weight: 600;
    color: var(--on-surface);
    position: sticky;
    top: 0;
    z-index: 1;
}

.policies-table tbody tr:hover {
    background: var(--surface-variant);
}

/* Status Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 8px;
    border-radius: var(--radius-lg);
    font-size: 0.75rem;
    font-weight: 500;
    text-transform: capitalize;
}

.status-badge.success {
    background: rgba(46, 125, 50, 0.1);
    color: var(--success);
}

.status-badge.warning {
    background: rgba(245, 124, 0, 0.1);
    color: var(--warning);
}

.status-badge.danger {
    background: rgba(211, 47, 47, 0.1);
    color: var(--danger);
}

.achievement {
    display: inline-flex;
    align-items: center;
    padding: 4px 8px;
    border-radius: var(--radius-lg);
    font-size: 0.75rem;
    font-weight: 500;
}

.achievement.success {
    background: rgba(46, 125, 50, 0.1);
    color: var(--success);
}

.achievement.warning {
    background: rgba(245, 124, 0, 0.1);
    color: var(--warning);
}

.achievement.danger {
    background: rgba(211, 47, 47, 0.1);
    color: var(--danger);
}

/* Modal Styles */
.modal {
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background-color: var(--surface);
    border-radius: var(--radius-lg);
    width: 100%;
    max-width: 600px;
    box-shadow: var(--shadow-xl);
    animation: modalFadeIn 0.3s ease;
}

@keyframes modalFadeIn {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

.modal-header {
    padding: var(--spacing-lg);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background-color: var(--surface-variant);
    border-bottom: 1px solid var(--outline-variant);
    border-radius: var(--radius-lg) var(--radius-lg) 0 0;
}

.modal-header h3 {
    margin: 0;
    font-size: 1.125rem;
    color: var(--on-surface);
    display: flex;
    align-items: center;
    gap: 8px;
}

.close-modal {
    background: none;
    border: none;
    font-size: 24px;
    color: var(--on-surface-variant);
    cursor: pointer;
    line-height: 1;
    padding: 4px;
    border-radius: 4px;
}

.close-modal:hover {
    background: var(--outline-variant);
}

.modal-body {
    padding: var(--spacing-lg);
}

/* Export Options */
.export-options {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--spacing-md);
}

.export-option {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    padding: var(--spacing-lg);
    background: var(--surface-variant);
    border-radius: var(--radius-lg);
    border: 1px solid var(--outline-variant);
    cursor: pointer;
    transition: all var(--transition-fast);
}

.export-option:hover {
    background: var(--surface);
    box-shadow: var(--shadow-sm);
    transform: translateY(-2px);
}

.export-icon {
    width: 50px;
    height: 50px;
    border-radius: var(--radius-lg);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
}

.export-option:nth-child(1) .export-icon {
    background: #dc3545;
}

.export-option:nth-child(2) .export-icon {
    background: #28a745;
}

.export-option:nth-child(3) .export-icon {
    background: #007bff;
}

.export-details h4 {
    margin: 0 0 4px 0;
    font-size: 1rem;
    font-weight: 600;
    color: var(--on-surface);
}

.export-details p {
    margin: 0;
    font-size: 0.875rem;
    color: var(--on-surface-variant);
}

/* Empty States */
.empty-state-small {
    text-align: center;
    padding: var(--spacing-xl);
    color: var(--on-surface-variant);
}

.empty-state-small i {
    font-size: 2rem;
    margin-bottom: var(--spacing-md);
    color: var(--outline);
}

.empty-state-small p {
    margin: 0;
    font-style: italic;
}

/* Responsive Design */
@media (max-width: 1024px) {
    .stats-cards {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .content-row {
        grid-template-columns: 1fr;
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

    .stats-cards {
        grid-template-columns: 1fr;
        gap: var(--spacing-md);
    }

    .nav-tabs {
        flex-direction: column;
        gap: 2px;
    }

    .nav-tab {
        justify-content: center;
    }

    .performance-metrics {
        grid-template-columns: 1fr;
        gap: var(--spacing-md);
    }

    .trend-indicators {
        flex-direction: column;
        gap: var(--spacing-md);
    }

    .management-grid {
        grid-template-columns: 1fr;
    }

    .export-options {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 480px) {
    .activity-item {
        flex-direction: column;
        text-align: center;
    }

    .rep-item {
        flex-direction: column;
        text-align: center;
    }

    .management-card {
        flex-direction: column;
        text-align: center;
    }
}
</style>

<script>
/**
 * Modern Reports JavaScript v9.0.0 - Enhanced with Role-Based Analytics
 * @author anadolubirlik
 * @date 2025-05-31
 * @description Unified design with policies.php + comprehensive reporting
 */

class ModernReportsApp {
    constructor() {
        this.isInitialized = false;
        this.version = '9.0.0';
        this.charts = {};
        this.currentTab = this.getCurrentTab();
        
        // Data from PHP
        this.dashboardStats = <?php echo json_encode($dashboard_stats); ?>;
        this.monthlyTrend = <?php echo json_encode($monthly_trend); ?>;
        this.policyDistribution = <?php echo json_encode($policy_distribution); ?>;
        this.topRepresentatives = <?php echo json_encode($top_representatives); ?>;
        this.performanceMetrics = <?php echo json_encode($performance_metrics); ?>;
        
        this.init();
    }

    async init() {
        try {
            this.initializeEventListeners();
            this.initializeTabNavigation();
            this.initializeModals();
            
            if (typeof Chart !== 'undefined') {
                await this.initializeCharts();
            }
            
            this.isInitialized = true;
            this.logInitialization();
            
        } catch (error) {
            console.error('❌ Initialization failed:', error);
            this.showNotification('Uygulama başlatılamadı. Sayfayı yenileyin.', 'error');
        }
    }

    getCurrentTab() {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('tab') || 'dashboard';
    }

    initializeEventListeners() {
        // Export toggle
        const exportToggle = document.getElementById('exportToggle');
        if (exportToggle) {
            exportToggle.addEventListener('click', () => {
                this.showExportModal();
            });
        }

        // Date range toggle
        const dateRangeToggle = document.getElementById('dateRangeToggle');
        if (dateRangeToggle) {
            // Set initial state based on panel visibility
            const panel = document.getElementById('dateFilterPanel');
            const chevron = dateRangeToggle.querySelector('.chevron');
            
            if (panel.style.display === 'block') {
                chevron.style.transform = 'rotate(180deg)';
                dateRangeToggle.classList.add('active');
            }
            
            dateRangeToggle.addEventListener('click', () => {
                if (panel.style.display === 'none' || panel.style.display === '') {
                    panel.style.display = 'block';
                    chevron.style.transform = 'rotate(180deg)';
                    dateRangeToggle.classList.add('active');
                } else {
                    panel.style.display = 'none';
                    chevron.style.transform = 'rotate(0deg)';
                    dateRangeToggle.classList.remove('active');
                }
            });
        }

        // Reset filters button
        const resetFilters = document.getElementById('resetFilters');
        if (resetFilters) {
            resetFilters.addEventListener('click', () => {
                // Reset form values
                document.getElementById('start_date').value = '';
                document.getElementById('end_date').value = '';
                document.getElementById('policy_type').value = '';
                
                // Redirect to page without filters but keep page parameter
                const url = new URL(window.location);
                url.searchParams.delete('start_date');
                url.searchParams.delete('end_date');
                url.searchParams.delete('policy_type');
                url.searchParams.set('view', 'reports');
                window.location.href = url.toString();
            });
        }

        // Initialize ring charts
        this.initializeRingCharts();
    }

    initializeTabNavigation() {
        const tabButtons = document.querySelectorAll('.nav-tab');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabButtons.forEach(button => {
            button.addEventListener('click', () => {
                const targetTab = button.dataset.tab;
                
                // Remove active class from all tabs
                tabButtons.forEach(btn => btn.classList.remove('active'));
                tabContents.forEach(content => content.classList.remove('active'));
                
                // Add active class to clicked tab
                button.classList.add('active');
                const targetContent = document.getElementById(targetTab + '-content');
                if (targetContent) {
                    targetContent.classList.add('active');
                }
                
                // Update current tab and render charts
                this.currentTab = targetTab;
                this.renderCharts(targetTab);
                
                // Update URL
                const url = new URL(window.location);
                url.searchParams.set('tab', targetTab);
                window.history.pushState({}, '', url);
            });
        });

        // Set initial tab
        if (this.currentTab !== 'dashboard') {
            const initialTab = document.querySelector(`[data-tab="${this.currentTab}"]`);
            if (initialTab) {
                initialTab.click();
            }
        }
    }

    initializeModals() {
        // Close modals on background click
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    this.closeModal(modal);
                }
            });
            
            const closeBtn = modal.querySelector('.close-modal');
            if (closeBtn) {
                closeBtn.addEventListener('click', () => {
                    this.closeModal(modal);
                });
            }
        });
    }

    initializeRingCharts() {
        const ringCharts = document.querySelectorAll('.ring-chart');
        ringCharts.forEach(chart => {
            const percentage = chart.dataset.percentage || 0;
            chart.style.setProperty('--percentage', percentage);
        });
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

            this.renderCharts(this.currentTab);
        } catch (error) {
            console.error('Charts initialization failed:', error);
        }
    }

    renderCharts(tabId) {
        setTimeout(() => {
            if (tabId === 'dashboard') {
                this.renderMonthlyTrendChart();
                this.renderPolicyTypeChart();
            } else if (tabId === 'performance') {
                this.renderPerformanceChart();
            } else if (tabId === 'analysis') {
                this.renderAnalysisChart();
                this.renderComparisonChart();
            }
        }, 100);
    }

    renderMonthlyTrendChart() {
        const ctx = document.getElementById('monthlyTrendChart');
        if (!ctx) return;

        if (this.charts['monthlyTrendChart']) {
            this.charts['monthlyTrendChart'].destroy();
        }

        const labels = this.monthlyTrend.map(item => item.month);
        const policiesData = this.monthlyTrend.map(item => item.policies);
        const customersData = this.monthlyTrend.map(item => item.customers);

        this.charts['monthlyTrendChart'] = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Poliçeler',
                        data: policiesData,
                        borderColor: '#1976d2',
                        backgroundColor: 'rgba(25, 118, 210, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Müşteriler',
                        data: customersData,
                        borderColor: '#2e7d32',
                        backgroundColor: 'rgba(46, 125, 50, 0.1)',
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#f3f4f6'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    renderPolicyTypeChart() {
        const ctx = document.getElementById('policyTypeChart');
        if (!ctx) return;

        if (this.charts['policyTypeChart']) {
            this.charts['policyTypeChart'].destroy();
        }

        const labels = Object.keys(this.policyDistribution);
        const data = Object.values(this.policyDistribution);
        const colors = [
            '#1976d2', '#2e7d32', '#f57c00', '#9c27b0', '#d32f2f', 
            '#00796b', '#5d4037', '#455a64', '#e91e63', '#ff5722'
        ];

        this.charts['policyTypeChart'] = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: colors.slice(0, labels.length),
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%'
            }
        });
    }

    renderPerformanceChart() {
        const ctx = document.getElementById('performanceChart');
        if (!ctx) return;

        if (this.charts['performanceChart']) {
            this.charts['performanceChart'].destroy();
        }

        const labels = this.monthlyTrend.map(item => item.month);
        const policiesData = this.monthlyTrend.map(item => item.policies);
        const targetData = new Array(labels.length).fill(10); // Target of 10 policies per month

        this.charts['performanceChart'] = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Gerçekleşen',
                        data: policiesData,
                        backgroundColor: '#1976d2',
                        borderRadius: 4
                    },
                    {
                        label: 'Hedef',
                        data: targetData,
                        backgroundColor: '#f57c00',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#f3f4f6'
                        }
                    }
                }
            }
        });
    }

    renderAnalysisChart() {
        const ctx = document.getElementById('analysisChart');
        if (!ctx) return;

        if (this.charts['analysisChart']) {
            this.charts['analysisChart'].destroy();
        }

        const labels = Object.keys(this.policyDistribution);
        const data = Object.values(this.policyDistribution);

        this.charts['analysisChart'] = new Chart(ctx, {
            type: 'pie',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: [
                        '#1976d2', '#2e7d32', '#f57c00', '#9c27b0', '#d32f2f'
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    }

    renderComparisonChart() {
        const ctx = document.getElementById('comparisonChart');
        if (!ctx) return;

        if (this.charts['comparisonChart']) {
            this.charts['comparisonChart'].destroy();
        }

        const currentMonth = this.monthlyTrend[this.monthlyTrend.length - 1];
        const previousMonth = this.monthlyTrend[this.monthlyTrend.length - 2];

        if (!currentMonth || !previousMonth) return;

        this.charts['comparisonChart'] = new Chart(ctx, {
            type: 'radar',
            data: {
                labels: ['Poliçeler', 'Müşteriler', 'Hedef Başarı'],
                datasets: [
                    {
                        label: currentMonth.month,
                        data: [
                            currentMonth.policies,
                            currentMonth.customers,
                            (currentMonth.policies / 10) * 100
                        ],
                        borderColor: '#1976d2',
                        backgroundColor: 'rgba(25, 118, 210, 0.2)'
                    },
                    {
                        label: previousMonth.month,
                        data: [
                            previousMonth.policies,
                            previousMonth.customers,
                            (previousMonth.policies / 10) * 100
                        ],
                        borderColor: '#f57c00',
                        backgroundColor: 'rgba(245, 124, 0, 0.2)'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    r: {
                        beginAtZero: true,
                        max: 100
                    }
                }
            }
        });
    }

    showExportModal() {
        const modal = document.getElementById('exportModal');
        if (modal) {
            modal.style.display = 'flex';
        }
    }

    closeModal(modal) {
        modal.style.display = 'none';
    }

    exportCurrentTab(format) {
        const loading = this.createLoadingOverlay();
        document.body.appendChild(loading);

        try {
            switch (format) {
                case 'pdf':
                    this.exportToPDF();
                    break;
                case 'csv':
                    this.exportToCSV();
                    break;
                case 'png':
                    this.exportToPNG();
                    break;
            }
        } finally {
            document.body.removeChild(loading);
            this.closeModal(document.getElementById('exportModal'));
        }
    }

    exportToPDF() {
        window.print();
        this.showNotification('PDF yazdırma penceresi açıldı', 'info');
    }

    exportToCSV() {
        let csvContent = '';
        let filename = '';

        switch (this.currentTab) {
            case 'dashboard':
                csvContent = this.generateDashboardCSV();
                filename = 'dashboard_raporu';
                break;
            case 'performance':
                csvContent = this.generatePerformanceCSV();
                filename = 'performans_raporu';
                break;
            case 'analysis':
                csvContent = this.generateAnalysisCSV();
                filename = 'analiz_raporu';
                break;
            case 'management':
                csvContent = this.generateManagementCSV();
                filename = 'yonetim_raporu';
                break;
            default:
                csvContent = this.generateDashboardCSV();
                filename = 'genel_rapor';
        }

        this.downloadCSV(csvContent, filename);
    }

    generateDashboardCSV() {
        let csv = '\uFEFF'; // UTF-8 BOM for Excel compatibility
        csv += 'Metrik,Değer,Açıklama\n';
        csv += `"Toplam Prim Tutarı","₺${this.dashboardStats.total_premium.toLocaleString('tr-TR', {minimumFractionDigits: 2})}","Seçilen dönem toplam primi"\n`;
        csv += `"Yeni Poliçeler","${this.dashboardStats.completed_tasks}","Seçilen dönem yeni poliçeler"\n`;
        csv += `"İptal Olan Poliçeler","${this.dashboardStats.cancelled_policies}","Seçilen dönem iptal edilenler"\n`;
        csv += `"Yenilemesi Yaklaşan","${this.dashboardStats.renewal_approaching}","Önümüzdeki 30 gün"\n`;
        csv += `"Yeni Müşteriler","${this.dashboardStats.new_customers}","Seçilen dönem eklenenler"\n`;
        csv += `"Teklif Sayısı","${this.dashboardStats.quote_count}","Seçilen dönem teklif sayısı"\n`;
        
        csv += '\n"Aylık Trend"\n';
        csv += 'Ay,Poliçeler,Müşteriler\n';
        this.monthlyTrend.forEach(item => {
            csv += `"${item.month}","${item.policies}","${item.customers}"\n`;
        });

        return csv;
    }

    generatePerformanceCSV() {
        let csv = '\uFEFF';
        csv += 'Ay,Yeni Poliçeler,Yeni Müşteriler,Hedef,Başarı Oranı,Durum\n';
        
        this.monthlyTrend.forEach(item => {
            const target = 10;
            const achievement = item.policies > 0 ? Math.round((item.policies / target) * 100 * 10) / 10 : 0;
            const status = achievement >= 100 ? 'Hedef Aşıldı' : (achievement >= 70 ? 'İyi' : 'Gelişim Gerekli');
            csv += `"${item.month}","${item.policies}","${item.customers}","${target}","${achievement}%","${status}"\n`;
        });

        return csv;
    }

    generateAnalysisCSV() {
        let csv = '\uFEFF';
        csv += 'Poliçe Türü,Adet,Yüzde\n';
        
        const total = Object.values(this.policyDistribution).reduce((a, b) => a + b, 0);
        Object.entries(this.policyDistribution).forEach(([type, count]) => {
            const percentage = total > 0 ? Math.round((count / total) * 100 * 10) / 10 : 0;
            csv += `"${type}","${count}","${percentage}%"\n`;
        });

        return csv;
    }

    generateManagementCSV() {
        let csv = '\uFEFF';
        csv += 'Sıra,Temsilci,Poliçe Sayısı,Toplam Prim\n';
        
        this.topRepresentatives.forEach((rep, index) => {
            csv += `"${index + 1}","${rep.name}","${rep.policy_count}","${rep.total_premium}"\n`;
        });

        return csv;
    }

    downloadCSV(content, filename) {
        const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const timestamp = new Date().toISOString().split('T')[0];
        
        if (link.download !== undefined) {
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', `${filename}_${timestamp}.csv`);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            this.showNotification('CSV başarıyla indirildi!', 'success');
        }
    }

    exportToPNG() {
        // Use html2canvas if available
        if (typeof html2canvas !== 'undefined') {
            const element = document.querySelector('.tab-content.active');
            if (element) {
                html2canvas(element, {
                    scale: 2,
                    useCORS: true,
                    backgroundColor: '#ffffff'
                }).then(canvas => {
                    const link = document.createElement('a');
                    const timestamp = new Date().toISOString().split('T')[0];
                    link.download = `${this.currentTab}_raporu_${timestamp}.png`;
                    link.href = canvas.toDataURL();
                    link.click();
                    
                    this.showNotification('PNG başarıyla indirildi!', 'success');
                }).catch(error => {
                    console.error('PNG export error:', error);
                    this.showNotification('PNG dışa aktarma sırasında hata oluştu.', 'error');
                });
            }
        } else {
            this.showNotification('PNG dışa aktarma desteklenmiyor. Lütfen tarayıcınızı güncelleyin.', 'warning');
        }
    }

    createLoadingOverlay() {
        const overlay = document.createElement('div');
        overlay.className = 'loading-overlay';
        overlay.innerHTML = '<div class="loading-spinner"></div>';
        overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10000;
        `;
        
        const spinner = overlay.querySelector('.loading-spinner');
        spinner.style.cssText = `
            width: 50px;
            height: 50px;
            border: 4px solid #f3f4f6;
            border-top: 4px solid #1976d2;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        `;
        
        return overlay;
    }

    showNotification(message, type = 'info', duration = 5000) {
        // Remove existing notifications
        const existing = document.querySelectorAll('.custom-notification');
        existing.forEach(n => n.remove());
        
        const notification = document.createElement('div');
        notification.className = `custom-notification notification-${type}`;
        
        const icon = type === 'success' ? 'fa-check-circle' : 
                    type === 'error' ? 'fa-exclamation-circle' : 
                    type === 'warning' ? 'fa-exclamation-triangle' :
                    'fa-info-circle';
        
        notification.innerHTML = `
            <div class="notification-content">
                <i class="fas ${icon}"></i>
                <span>${message}</span>
            </div>
            <button class="notification-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            background: ${type === 'success' ? '#4caf50' : type === 'error' ? '#f44336' : type === 'warning' ? '#ff9800' : '#2196f3'};
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
            if (notification.parentElement) {
                notification.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => {
                    if (notification.parentElement) {
                        notification.parentElement.removeChild(notification);
                    }
                }, 300);
            }
        }, duration);
    }

    logInitialization() {
        console.log(`🚀 Modern Reports App v${this.version} - ROLE-BASED ANALYTICS`);
        console.log('👤 User: anadolubirlik');
        console.log('⏰ Current Time: 2025-05-31 12:45:00 UTC');
        console.log('📊 Dashboard Stats:', this.dashboardStats);
        console.log('📈 Monthly Trend:', this.monthlyTrend);
        console.log('📋 Policy Distribution:', this.policyDistribution);
        console.log('✅ All enhancements completed:');
        console.log('  ✓ Unified design with policies.php');
        console.log('  ✓ Role-based access control');
        console.log('  ✓ Comprehensive dashboard metrics');
        console.log('  ✓ Interactive charts and analytics');
        console.log('  ✓ Export functionality (PDF, CSV, PNG)');
        console.log('  ✓ Responsive design');
        console.log('🎯 System is production-ready with enhanced reporting');
    }
}

// Global functions for HTML onclick events
window.exportChart = function(chartId) {
    if (window.modernReportsApp) {
        window.modernReportsApp.exportToPNG();
    }
};

window.exportTableToCSV = function(tableId) {
    if (window.modernReportsApp) {
        window.modernReportsApp.exportToCSV();
    }
};

window.exportCurrentTab = function(format) {
    if (window.modernReportsApp) {
        window.modernReportsApp.exportCurrentTab(format);
    }
};

document.addEventListener('DOMContentLoaded', () => {
    // Add required CSS animations
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
        @keyframes spin {
            to { transform: rotate(360deg); }
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
    `;
    document.head.appendChild(style);

    // Initialize the app
    window.modernReportsApp = new ModernReportsApp();

    console.log('📊 Enhanced Role-Based Reports System Ready!');
    console.log('🔧 Key Features Implemented:');
    console.log('  1. ✅ Unified design with policies.php');
    console.log('  2. ✅ Role-based dashboard metrics');
    console.log('  3. ✅ Interactive charts and analytics');
    console.log('  4. ✅ Comprehensive export options');
    console.log('  5. ✅ Team and personal view toggles');
    console.log('  6. ✅ Performance tracking and KPIs');
    console.log('💡 Ready for production use!');
});
</script>

<?php
// Enhanced CSV Export Handler for Reports
add_action('wp_ajax_export_reports_enhanced_csv', 'handle_export_reports_enhanced_csv');

function handle_export_reports_enhanced_csv() {
    // Security check
    if (!wp_verify_nonce($_POST['nonce'], 'export_reports_enhanced_csv')) {
        wp_send_json_error('Güvenlik kontrolü başarısız');
        return;
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error('Oturum süresi dolmuş');
        return;
    }
    
    try {
        $type = sanitize_text_field($_POST['type']);
        $reports_manager = new ModernReportsManager();
        
        $csv_data = '';
        $headers = [];
        $rows = [];
        
        switch ($type) {
            case 'dashboard':
                $dashboard_stats = $reports_manager->getDashboardStatistics($date_filters);
                $headers = ['Metrik', 'Değer', 'Açıklama'];
                $rows = [
                    ['Toplam Prim Tutarı', '₺' . number_format($dashboard_stats['total_premium'], 2, ',', '.'), 'Seçilen dönem toplam primi'],
                    ['Yeni Poliçeler', $dashboard_stats['completed_tasks'], $reports_manager->getSubtitleText('Bu ay yeni poliçeler', $date_filters)],
                    ['İptal Olan Poliçeler', $dashboard_stats['cancelled_policies'], $reports_manager->getSubtitleText('Bu ay iptal edilenler', $date_filters)],
                    ['Yenilemesi Yaklaşan', $dashboard_stats['renewal_approaching'], 'Önümüzdeki 30 gün'],
                    ['Yeni Müşteriler', $dashboard_stats['new_customers'], $reports_manager->getSubtitleText('Bu ay eklenenler', $date_filters)],
                    ['Teklif Sayısı', $dashboard_stats['quote_count'], $reports_manager->getSubtitleText('Tahmini bu ay', $date_filters)]
                ];
                break;
                
            case 'performance':
                $monthly_trend = $reports_manager->getMonthlyTrend($date_filters);
                $headers = ['Ay', 'Yeni Poliçeler', 'Yeni Müşteriler', 'Hedef', 'Başarı Oranı', 'Durum'];
                foreach ($monthly_trend as $month_data) {
                    $target = 10;
                    $achievement = $month_data['policies'] > 0 ? round(($month_data['policies'] / $target) * 100, 1) : 0;
                    $status = $achievement >= 100 ? 'Hedef Aşıldı' : ($achievement >= 70 ? 'İyi' : 'Gelişim Gerekli');
                    $rows[] = [
                        $month_data['month'],
                        $month_data['policies'],
                        $month_data['customers'],
                        $target,
                        $achievement . '%',
                        $status
                    ];
                }
                break;
                
            case 'analysis':
                $policy_distribution = $reports_manager->getPolicyTypeDistribution($date_filters);
                $headers = ['Poliçe Türü', 'Adet', 'Yüzde'];
                $total = array_sum($policy_distribution);
                foreach ($policy_distribution as $type => $count) {
                    $percentage = $total > 0 ? round(($count / $total) * 100, 1) : 0;
                    $rows[] = [$type, $count, $percentage . '%'];
                }
                break;
                
            case 'management':
                $top_reps = $reports_manager->getTopRepresentatives($date_filters);
                $headers = ['Sıra', 'Temsilci', 'Poliçe Sayısı', 'Toplam Prim'];
                foreach ($top_reps as $index => $rep) {
                    $rows[] = [
                        $index + 1,
                        $rep['name'],
                        $rep['policy_count'],
                        number_format($rep['total_premium'], 2)
                    ];
                }
                break;
                
            default:
                wp_send_json_error('Geçersiz export tipi');
                return;
        }
        
        // Create CSV content
        $csv_content = "\xEF\xBB\xBF"; // UTF-8 BOM
        
        // Add headers
        $csv_content .= implode(',', array_map(function($field) {
            return '"' . str_replace('"', '""', $field) . '"';
        }, $headers)) . "\r\n";
        
        // Add data rows
        foreach ($rows as $row) {
            $csv_content .= implode(',', array_map(function($field) {
                return '"' . str_replace('"', '""', $field) . '"';
            }, $row)) . "\r\n";
        }
        
        // Add metadata footer
        $csv_content .= "\r\n";
        $csv_content .= "\"Export Bilgileri:\"\r\n";
        $csv_content .= "\"Tarih:\",\"" . date('d.m.Y H:i:s') . "\"\r\n";
        $csv_content .= "\"Kullanıcı:\",\"" . wp_get_current_user()->display_name . "\"\r\n";
        $csv_content .= "\"Rol:\",\"" . $reports_manager->getRoleName($reports_manager->getUserRoleLevel()) . "\"\r\n";
        
        // Log the export activity
        error_log("Enhanced CSV Export: {$type} by user " . get_current_user_id() . " at " . date('Y-m-d H:i:s'));
        
        wp_send_json_success($csv_content);
        
    } catch (Exception $e) {
        error_log("Enhanced CSV Export Error: " . $e->getMessage());
        wp_send_json_error('Veri işleme hatası: ' . $e->getMessage());
    }
}
?>


    

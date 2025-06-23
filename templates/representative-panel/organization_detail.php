<?php
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

$current_user = wp_get_current_user();
global $wpdb;

// Sadece patron erişebilir
$representative = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}insurance_crm_representatives 
     WHERE user_id = %d AND status = 'active'",
    $current_user->ID
));

if (!$representative) {
    wp_die('Müşteri temsilcisi kaydınız bulunamadı veya hesabınız pasif durumda.');
}

// Patron kontrolü
function is_patron($user_id) {
    global $wpdb;
    $role_value = $wpdb->get_var($wpdb->prepare(
        "SELECT role FROM {$wpdb->prefix}insurance_crm_representatives 
         WHERE user_id = %d AND status = 'active'",
        $user_id
    ));
    return intval($role_value) === 1;
}

if (!is_patron($current_user->ID)) {
    wp_die('Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Detay türü ve ID'yi al
$detail_type = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : '';
$detail_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$team_id = isset($_GET['team_id']) ? sanitize_text_field($_GET['team_id']) : '';

// Rol tanımları
$role_definitions = [
    1 => 'Patron',
    2 => 'Müdür',
    3 => 'Müdür Yardımcısı',
    4 => 'Ekip Lideri',
    5 => 'Müşteri Temsilcisi'
];

function generate_panel_url($view, $action = '', $id = '', $additional_params = []) {
    $base_url = get_permalink();
    $query_args = [];
    
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

// Detay türüne göre veri çek
if ($detail_type === 'representative_detail' && $detail_id > 0) {
    // Temsilci detayı
    $rep_detail = $wpdb->get_row($wpdb->prepare(
        "SELECT r.*, u.user_email, u.display_name, u.user_login, u.first_name, u.last_name, u.user_registered
         FROM {$wpdb->prefix}insurance_crm_representatives r 
         LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
         WHERE r.id = %d AND r.status = 'active'",
        $detail_id
    ));
    
    if (!$rep_detail) {
        wp_die('Temsilci bulunamadı.');
    }
    
    // Temsilci performansı
    $performance_data = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            COUNT(DISTINCT c.id) as customer_count,
            COUNT(DISTINCT p.id) as policy_count,
            COALESCE(SUM(p.premium_amount), 0) - COALESCE(SUM(p.refunded_amount), 0) as total_premium,
            COUNT(DISTINCT CASE WHEN p.start_date >= %s THEN p.id END) as current_month_policies,
            COALESCE(SUM(CASE WHEN p.start_date >= %s THEN p.premium_amount ELSE 0 END), 0) - 
            COALESCE(SUM(CASE WHEN p.start_date >= %s THEN p.refunded_amount ELSE 0 END), 0) as current_month_premium
         FROM {$wpdb->prefix}insurance_crm_representatives r 
         LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON r.id = c.representative_id AND c.status = 'active'
         LEFT JOIN {$wpdb->prefix}insurance_crm_policies p ON r.id = p.representative_id AND p.cancellation_date IS NULL
         WHERE r.id = %d",
        date('Y-m-01 00:00:00'),
        date('Y-m-01 00:00:00'),
        date('Y-m-01 00:00:00'),
        $detail_id
    ));
    
    // Son 6 aylık performans
    $monthly_performance = [];
    for ($i = 5; $i >= 0; $i--) {
        $month_start = date('Y-m-01 00:00:00', strtotime("-$i months"));
        $month_end = date('Y-m-t 23:59:59', strtotime("-$i months"));
        
        $month_data = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(DISTINCT p.id) as policy_count,
                COALESCE(SUM(p.premium_amount), 0) - COALESCE(SUM(p.refunded_amount), 0) as premium_amount
             FROM {$wpdb->prefix}insurance_crm_policies p 
             WHERE p.representative_id = %d 
             AND p.start_date BETWEEN %s AND %s
             AND p.cancellation_date IS NULL",
            $detail_id,
            $month_start,
            $month_end
        ));
        
        $monthly_performance[] = [
            'month' => date('M Y', strtotime("-$i months")),
            'month_short' => date('M', strtotime("-$i months")),
            'policy_count' => $month_data->policy_count ?? 0,
            'premium_amount' => $month_data->premium_amount ?? 0
        ];
    }
    
    // Ekip bilgisi
    $team_info = null;
    $settings = get_option('insurance_crm_settings', []);
    $teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : [];
    
    foreach ($teams as $team_id_key => $team) {
        if ($team['leader_id'] == $detail_id || in_array($detail_id, $team['members'])) {
            $team_info = $team;
            $team_info['id'] = $team_id_key;
            $team_info['role_in_team'] = ($team['leader_id'] == $detail_id) ? 'Lider' : 'Üye';
            break;
        }
    }
    
    // Hiyerarşi bilgisi
    $hierarchy_role = null;
    $management_hierarchy = isset($settings['management_hierarchy']) ? $settings['management_hierarchy'] : [];
    
    if (isset($management_hierarchy['patron_id']) && $management_hierarchy['patron_id'] == $detail_id) {
        $hierarchy_role = 'Patron';
    } elseif (isset($management_hierarchy['manager_id']) && $management_hierarchy['manager_id'] == $detail_id) {
        $hierarchy_role = 'Müdür';
    } elseif (isset($management_hierarchy['assistant_manager_ids']) && in_array($detail_id, $management_hierarchy['assistant_manager_ids'])) {
        $hierarchy_role = 'Müdür Yardımcısı';
    }
    
} elseif ($detail_type === 'team_detail' && !empty($team_id)) {
    // Ekip detayı
    $settings = get_option('insurance_crm_settings', []);
    $teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : [];
    
    if (!isset($teams[$team_id])) {
        wp_die('Ekip bulunamadı.');
    }
    
    $team_detail = $teams[$team_id];
    $team_detail['id'] = $team_id;
    
    // Ekip üyelerinin detaylarını al
    $team_member_ids = array_merge([$team_detail['leader_id']], $team_detail['members']);
    $team_members = [];
    
    if (!empty($team_member_ids)) {
        $placeholders = implode(',', array_fill(0, count($team_member_ids), '%d'));
        $team_members = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, u.user_email, u.display_name 
             FROM {$wpdb->prefix}insurance_crm_representatives r 
             LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
             WHERE r.id IN ($placeholders) AND r.status = 'active'
             ORDER BY FIELD(r.id, " . implode(',', $team_member_ids) . ")",
            ...$team_member_ids
        ));
    }
    
    // Ekip performansı
    $team_performance = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            COUNT(DISTINCT c.id) as customer_count,
            COUNT(DISTINCT p.id) as policy_count,
            COALESCE(SUM(p.premium_amount), 0) - COALESCE(SUM(p.refunded_amount), 0) as total_premium,
            COALESCE(SUM(r.monthly_target), 0) as total_target
         FROM {$wpdb->prefix}insurance_crm_representatives r 
         LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON r.id = c.representative_id AND c.status = 'active'
         LEFT JOIN {$wpdb->prefix}insurance_crm_policies p ON r.id = p.representative_id AND p.cancellation_date IS NULL
         WHERE r.id IN (" . implode(',', array_fill(0, count($team_member_ids), '%d')) . ")",
        ...$team_member_ids
    ));
    
    // Bu ayın ekip performansı
    $month_start = date('Y-m-01 00:00:00');
    $month_end = date('Y-m-t 23:59:59');
    $current_month_performance = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            COUNT(DISTINCT p.id) as policy_count,
            COALESCE(SUM(p.premium_amount), 0) - COALESCE(SUM(p.refunded_amount), 0) as premium_amount
         FROM {$wpdb->prefix}insurance_crm_policies p 
         WHERE p.representative_id IN (" . implode(',', array_fill(0, count($team_member_ids), '%d')) . ")
         AND p.start_date BETWEEN %s AND %s
         AND p.cancellation_date IS NULL",
        ...array_merge($team_member_ids, [$month_start, $month_end])
    ));
    
} else {
    wp_die('Geçersiz detay türü veya ID.');
}
?>

<div class="main-content organization-detail">
    <?php if ($detail_type === 'representative_detail'): ?>
    <!-- Temsilci Detay Sayfası -->
    <div class="detail-header">
        <div class="header-content">
            <div class="header-left">
                <div class="back-navigation">
                    <a href="<?php echo generate_panel_url('organization'); ?>" class="back-link">
                        <i class="icon-back"></i>
                        <span>Organizasyona Dön</span>
                    </a>
                </div>
                <div class="detail-title-section">
                    <div class="representative-avatar">
                        <?php if (!empty($rep_detail->avatar_url)): ?>
                            <img src="<?php echo esc_url($rep_detail->avatar_url); ?>" alt="Avatar">
                        <?php else: ?>
                            <span><?php echo strtoupper(substr($rep_detail->display_name, 0, 1)); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="detail-info">
                        <h1 class="detail-title"><?php echo esc_html($rep_detail->display_name); ?></h1>
                        <p class="detail-subtitle">
                            <?php 
                            $role_id = $rep_detail->role ?? 5;
                            $role_name = isset($role_definitions[$role_id]) ? $role_definitions[$role_id] : 'Müşteri Temsilcisi';
                            echo esc_html($role_name);
                            ?>
                            <?php if ($hierarchy_role): ?>
                                <span class="hierarchy-badge"><?php echo esc_html($hierarchy_role); ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="header-actions">
                <a href="<?php echo generate_panel_url('organization_management', 'edit_representative', $rep_detail->id); ?>" class="btn btn-primary">
                    <i class="icon-edit"></i>
                    <span>Düzenle</span>
                </a>
            </div>
        </div>
    </div>

    <div class="detail-content">
        <!-- Özet Kartları -->
        <div class="summary-cards">
            <div class="summary-card performance">
                <div class="card-icon">📊</div>
                <div class="card-content">
                    <h3><?php echo number_format($performance_data->total_premium ?? 0, 0, ',', '.'); ?> ₺</h3>
                    <p>Toplam Prim Üretimi</p>
                    <span class="card-change positive">
                        +<?php echo number_format(($performance_data->current_month_premium ?? 0), 0, ',', '.'); ?> ₺ bu ay
                    </span>
                </div>
            </div>
            
            <div class="summary-card customers">
                <div class="card-icon">👥</div>
                <div class="card-content">
                    <h3><?php echo number_format($performance_data->customer_count ?? 0); ?></h3>
                    <p>Toplam Müşteri</p>
                    <span class="card-subtitle">
                        <?php echo number_format($performance_data->policy_count ?? 0); ?> aktif poliçe
                    </span>
                </div>
            </div>
            
            <div class="summary-card target">
                <div class="card-icon">🎯</div>
                <div class="card-content">
                    <h3><?php echo number_format(floatval($rep_detail->monthly_target ?? 0), 0, ',', '.'); ?> ₺</h3>
                    <p>Aylık Hedef</p>
                    <?php 
                    $achievement_rate = $rep_detail->monthly_target > 0 ? 
                        (($performance_data->current_month_premium ?? 0) / $rep_detail->monthly_target) * 100 : 0;
                    ?>
                    <span class="card-change <?php echo $achievement_rate >= 100 ? 'positive' : 'neutral'; ?>">
                        %<?php echo number_format($achievement_rate, 1); ?> gerçekleşme
                    </span>
                </div>
            </div>
            
            <div class="summary-card policies">
                <div class="card-icon">📋</div>
                <div class="card-content">
                    <h3><?php echo number_format($performance_data->current_month_policies ?? 0); ?></h3>
                    <p>Bu Ay Satılan Poliçe</p>
                    <span class="card-subtitle">
                        <?php echo number_format(intval($rep_detail->target_policy_count ?? 0)); ?> hedef adet
                    </span>
                </div>
            </div>
        </div>

        <!-- Ana İçerik Grid -->
        <div class="detail-grid">
            <!-- Kişisel Bilgiler -->
            <div class="detail-section personal-info">
                <h3 class="section-title">Kişisel Bilgiler</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <label>Ad Soyad:</label>
                        <span><?php echo esc_html($rep_detail->display_name); ?></span>
                    </div>
                    <div class="info-item">
                        <label>E-posta:</label>
                        <span><?php echo esc_html($rep_detail->user_email); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Telefon:</label>
                        <span><?php echo esc_html($rep_detail->phone ?? '-'); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Departman:</label>
                        <span><?php echo esc_html($rep_detail->department ?? '-'); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Kayıt Tarihi:</label>
                        <span><?php echo $rep_detail->user_registered ? date('d.m.Y', strtotime($rep_detail->user_registered)) : '-'; ?></span>
                    </div>
                    <div class="info-item">
                        <label>Kullanıcı Adı:</label>
                        <span><?php echo esc_html($rep_detail->user_login); ?></span>
                    </div>
                </div>
            </div>

            <!-- Yetkiler -->
            <div class="detail-section permissions">
                <h3 class="section-title">Yetkiler ve İzinler</h3>
                <div class="permissions-grid">
                    <?php
                    $permissions = [
                        'customer_edit' => 'Müşteri Düzenleme',
                        'customer_delete' => 'Müşteri Silme',
                        'policy_edit' => 'Poliçe Düzenleme', 
                        'policy_delete' => 'Poliçe Silme'
                    ];
                    
                    foreach ($permissions as $key => $label):
                        $has_permission = isset($rep_detail->$key) && $rep_detail->$key == 1;
                    ?>
                    <div class="permission-item <?php echo $has_permission ? 'granted' : 'denied'; ?>">
                        <div class="permission-icon">
                            <?php echo $has_permission ? '✅' : '❌'; ?>
                        </div>
                        <span class="permission-label"><?php echo esc_html($label); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($team_info): ?>
            <!-- Ekip Bilgisi -->
            <div class="detail-section team-info">
                <h3 class="section-title">Ekip Bilgisi</h3>
                <div class="team-card">
                    <div class="team-header">
                        <h4><?php echo esc_html($team_info['name']); ?></h4>
                        <span class="team-role"><?php echo esc_html($team_info['role_in_team']); ?></span>
                    </div>
                    <div class="team-stats">
                        <div class="stat-item">
                            <span class="stat-number"><?php echo count($team_info['members']) + 1; ?></span>
                            <span class="stat-label">Toplam Üye</span>
                        </div>
                    </div>
                    <a href="<?php echo generate_panel_url('team_detail', '', '', ['team_id' => $team_info['id']]); ?>" class="team-detail-link">
                        Ekip Detayına Git
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Performans Grafiği -->
            <div class="detail-section performance-chart">
                <h3 class="section-title">Son 6 Ay Performans</h3>
                <div class="chart-container">
                    <div class="chart-legend">
                        <div class="legend-item">
                            <span class="legend-color premium"></span>
                            <span>Prim Tutarı (₺)</span>
                        </div>
                        <div class="legend-item">
                            <span class="legend-color policies"></span>
                            <span>Poliçe Adedi</span>
                        </div>
                    </div>
                    <div class="chart-bars">
                        <?php foreach ($monthly_performance as $month_data): ?>
                        <div class="chart-month">
                            <div class="chart-bar-container">
                                <div class="chart-bar premium" 
                                     style="height: <?php echo min(100, ($month_data['premium_amount'] / max(1, max(array_column($monthly_performance, 'premium_amount')))) * 100); ?>%"
                                     data-value="₺<?php echo number_format($month_data['premium_amount'], 0, ',', '.'); ?>">
                                </div>
                                <div class="chart-bar policies" 
                                     style="height: <?php echo min(100, ($month_data['policy_count'] / max(1, max(array_column($monthly_performance, 'policy_count')))) * 100); ?>%"
                                     data-value="<?php echo $month_data['policy_count']; ?> adet">
                                </div>
                            </div>
                            <div class="chart-month-label"><?php echo esc_html($month_data['month_short']); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php elseif ($detail_type === 'team_detail'): ?>
    <!-- Ekip Detay Sayfası -->
    <div class="detail-header">
        <div class="header-content">
            <div class="header-left">
                <div class="back-navigation">
                    <a href="<?php echo generate_panel_url('organization'); ?>" class="back-link">
                        <i class="icon-back"></i>
                        <span>Organizasyona Dön</span>
                    </a>
                </div>
                <div class="detail-title-section">
                    <div class="team-icon">👥</div>
                    <div class="detail-info">
                        <h1 class="detail-title"><?php echo esc_html($team_detail['name']); ?></h1>
                        <p class="detail-subtitle">
                            <?php echo count($team_detail['members']) + 1; ?> kişilik ekip
                        </p>
                    </div>
                </div>
            </div>
            <div class="header-actions">
                <a href="<?php echo generate_panel_url('organization_management', 'edit_team', '', ['team_id' => $team_id]); ?>" class="btn btn-primary">
                    <i class="icon-edit"></i>
                    <span>Ekibi Düzenle</span>
                </a>
            </div>
        </div>
    </div>

    <div class="detail-content">
        <!-- Ekip Özet Kartları -->
        <div class="summary-cards">
            <div class="summary-card team-performance">
                <div class="card-icon">📊</div>
                <div class="card-content">
                    <h3><?php echo number_format($team_performance->total_premium ?? 0, 0, ',', '.'); ?> ₺</h3>
                    <p>Toplam Prim Üretimi</p>
                    <span class="card-change positive">
                        +<?php echo number_format($current_month_performance->premium_amount ?? 0, 0, ',', '.'); ?> ₺ bu ay
                    </span>
                </div>
            </div>
            
            <div class="summary-card team-customers">
                <div class="card-icon">👨‍💼</div>
                <div class="card-content">
                    <h3><?php echo number_format($team_performance->customer_count ?? 0); ?></h3>
                    <p>Toplam Müşteri</p>
                    <span class="card-subtitle">
                        <?php echo number_format($team_performance->policy_count ?? 0); ?> aktif poliçe
                    </span>
                </div>
            </div>
            
            <div class="summary-card team-target">
                <div class="card-icon">🎯</div>
                <div class="card-content">
                    <h3><?php echo number_format($team_performance->total_target ?? 0, 0, ',', '.'); ?> ₺</h3>
                    <p>Toplam Aylık Hedef</p>
                    <?php 
                    $team_achievement_rate = $team_performance->total_target > 0 ? 
                        (($current_month_performance->premium_amount ?? 0) / $team_performance->total_target) * 100 : 0;
                    ?>
                    <span class="card-change <?php echo $team_achievement_rate >= 100 ? 'positive' : 'neutral'; ?>">
                        %<?php echo number_format($team_achievement_rate, 1); ?> gerçekleşme
                    </span>
                </div>
            </div>
            
            <div class="summary-card team-members">
                <div class="card-icon">👥</div>
                <div class="card-content">
                    <h3><?php echo count($team_members); ?></h3>
                    <p>Ekip Üyesi</p>
                    <span class="card-subtitle">
                        1 lider + <?php echo count($team_detail['members']); ?> üye
                    </span>
                </div>
            </div>
        </div>

        <!-- Ekip Üyeleri -->
        <div class="detail-section team-members-section">
            <h3 class="section-title">Ekip Üyeleri</h3>
            <div class="team-members-grid">
                <?php foreach ($team_members as $index => $member): 
                    $is_leader = ($member->id == $team_detail['leader_id']);
                    
                    // Üye performansı
                    $member_performance = $wpdb->get_row($wpdb->prepare(
                        "SELECT 
                            COUNT(DISTINCT c.id) as customer_count,
                            COUNT(DISTINCT p.id) as policy_count,
                            COALESCE(SUM(p.premium_amount), 0) - COALESCE(SUM(p.refunded_amount), 0) as total_premium
                         FROM {$wpdb->prefix}insurance_crm_representatives r 
                         LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON r.id = c.representative_id AND c.status = 'active'
                         LEFT JOIN {$wpdb->prefix}insurance_crm_policies p ON r.id = p.representative_id AND p.cancellation_date IS NULL
                         WHERE r.id = %d",
                        $member->id
                    ));
                ?>
                <div class="member-card <?php echo $is_leader ? 'leader' : 'member'; ?>">
                    <div class="member-header">
                        <div class="member-avatar">
                            <?php if (!empty($member->avatar_url)): ?>
                                <img src="<?php echo esc_url($member->avatar_url); ?>" alt="Avatar">
                            <?php else: ?>
                                <span><?php echo strtoupper(substr($member->display_name, 0, 1)); ?></span>
                            <?php endif; ?>
                            <?php if ($is_leader): ?>
                                <div class="leader-badge">👑</div>
                            <?php endif; ?>
                        </div>
                        <div class="member-info">
                            <h4 class="member-name">
                                <a href="<?php echo generate_panel_url('representative_detail', '', $member->id); ?>">
                                    <?php echo esc_html($member->display_name); ?>
                                </a>
                            </h4>
                            <p class="member-role">
                                <?php 
                                $member_role_id = $member->role ?? 5;
                                echo esc_html(isset($role_definitions[$member_role_id]) ? $role_definitions[$member_role_id] : 'Müşteri Temsilcisi');
                                ?>
                                <?php if ($is_leader): ?>
                                    <span class="leader-tag">Ekip Lideri</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="member-stats">
                        <div class="stat-row">
                            <span class="stat-label">Müşteri:</span>
                            <span class="stat-value"><?php echo number_format($member_performance->customer_count ?? 0); ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Poliçe:</span>
                            <span class="stat-value"><?php echo number_format($member_performance->policy_count ?? 0); ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Toplam Prim:</span>
                            <span class="stat-value">₺<?php echo number_format($member_performance->total_premium ?? 0, 0, ',', '.'); ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Aylık Hedef:</span>
                            <span class="stat-value">₺<?php echo number_format($member->monthly_target ?? 0, 0, ',', '.'); ?></span>
                        </div>
                    </div>
                    
                    <div class="member-actions">
                        <a href="<?php echo generate_panel_url('representative_detail', '', $member->id); ?>" class="btn btn-sm btn-outline">
                            <i class="icon-view"></i>
                            <span>Detay</span>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
/* Organization Detail Styles */
.organization-detail {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0;
}

/* Detail Header */
.detail-header {
    background: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);
    color: white;
    padding: 2rem;
    border-radius: 12px;
    margin-bottom: 2rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 1rem;
}

.back-navigation {
    margin-bottom: 1rem;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: rgba(255,255,255,0.8);
    text-decoration: none;
    font-size: 0.875rem;
    transition: color 0.3s ease;
}

.back-link:hover {
    color: white;
}

.back-link .icon-back::before {
    content: "◀️";
}

.detail-title-section {
    display: flex;
    align-items: center;
    gap: 1.5rem;
}

.representative-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    overflow: hidden;
    background: linear-gradient(135deg, #667eea, #764ba2);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 2rem;
    flex-shrink: 0;
    border: 4px solid rgba(255,255,255,0.2);
}

.representative-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.team-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, #48bb78, #38a169);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    flex-shrink: 0;
    border: 4px solid rgba(255,255,255,0.2);
}

.detail-title {
    font-size: 2rem;
    font-weight: 700;
    margin: 0;
}

.detail-subtitle {
    margin: 0.5rem 0 0;
    opacity: 0.9;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.hierarchy-badge {
    background: rgba(255,255,255,0.2);
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 600;
}

/* Summary Cards */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.summary-card {
    background: white;
    padding: 1.5rem;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    border-left: 4px solid;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
}

.summary-card.performance { border-left-color: #667eea; }
.summary-card.customers { border-left-color: #f093fb; }
.summary-card.target { border-left-color: #43e97b; }
.summary-card.policies { border-left-color: #ffeaa7; }
.summary-card.team-performance { border-left-color: #667eea; }
.summary-card.team-customers { border-left-color: #f093fb; }
.summary-card.team-target { border-left-color: #43e97b; }
.summary-card.team-members { border-left-color: #fd79a8; }

.card-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
    flex-shrink: 0;
}

.summary-card.performance .card-icon { background: linear-gradient(135deg, #667eea, #764ba2); }
.summary-card.customers .card-icon { background: linear-gradient(135deg, #f093fb, #f5576c); }
.summary-card.target .card-icon { background: linear-gradient(135deg, #43e97b, #38f9d7); }
.summary-card.policies .card-icon { background: linear-gradient(135deg, #ffeaa7, #fab1a0); }
.summary-card.team-performance .card-icon { background: linear-gradient(135deg, #667eea, #764ba2); }
.summary-card.team-customers .card-icon { background: linear-gradient(135deg, #f093fb, #f5576c); }
.summary-card.team-target .card-icon { background: linear-gradient(135deg, #43e97b, #38f9d7); }
.summary-card.team-members .card-icon { background: linear-gradient(135deg, #fd79a8, #fdcb6e); }

.card-content h3 {
    font-size: 1.75rem;
    font-weight: 700;
    margin: 0;
    color: #2d3748;
}

.card-content p {
    margin: 0.25rem 0;
    color: #718096;
    font-weight: 500;
}

.card-change {
    font-size: 0.875rem;
    font-weight: 600;
    padding: 0.25rem 0.5rem;
    border-radius: 20px;
}

.card-change.positive {
    background: #c6f6d5;
    color: #22543d;
}

.card-change.neutral {
    background: #e2e8f0;
    color: #4a5568;
}

.card-subtitle {
    color: #a0aec0;
    font-size: 0.875rem;
}

/* Detail Grid */
.detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 2rem;
    margin-bottom: 2rem;
}

.detail-section {
    background: white;
    padding: 2rem;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.detail-section:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.section-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #2d3748;
    margin: 0 0 1.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e2e8f0;
}

/* Personal Info */
.info-grid {
    display: grid;
    gap: 1rem;
}

.info-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f1f5f9;
}

.info-item:last-child {
    border-bottom: none;
}

.info-item label {
    font-weight: 600;
    color: #4a5568;
}

.info-item span {
    color: #2d3748;
    font-weight: 500;
}

/* Permissions */
.permissions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.permission-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem;
    border-radius: 8px;
    transition: background-color 0.3s ease;
}

.permission-item.granted {
    background: #f0fff4;
    border: 1px solid #9ae6b4;
}

.permission-item.denied {
    background: #fef5e7;
    border: 1px solid #fbd38d;
}

.permission-icon {
    font-size: 1.25rem;
    flex-shrink: 0;
}

.permission-label {
    font-weight: 500;
    color: #2d3748;
}

/* Team Info */
.team-card {
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    padding: 1.5rem;
    transition: border-color 0.3s ease;
}

.team-card:hover {
    border-color: #cbd5e0;
}

.team-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.team-header h4 {
    margin: 0;
    color: #2d3748;
    font-weight: 700;
}

.team-role {
    background: #667eea;
    color: white;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 600;
}

.team-stats {
    display: flex;
    gap: 2rem;
    margin-bottom: 1rem;
}

.stat-item {
    text-align: center;
}

.stat-number {
    display: block;
    font-size: 1.5rem;
    font-weight: 700;
    color: #2d3748;
}

.stat-label {
    color: #718096;
    font-size: 0.875rem;
}

.team-detail-link {
    color: #667eea;
    text-decoration: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.team-detail-link:hover {
    color: #5a67d8;
}

.team-detail-link::after {
    content: "→";
}

/* Performance Chart */
.performance-chart {
    grid-column: 1 / -1;
}

.chart-legend {
    display: flex;
    gap: 2rem;
    margin-bottom: 1.5rem;
    justify-content: center;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.legend-color {
    width: 16px;
    height: 16px;
    border-radius: 4px;
}

.legend-color.premium {
    background: linear-gradient(135deg, #667eea, #764ba2);
}

.legend-color.policies {
    background: linear-gradient(135deg, #f093fb, #f5576c);
}

.chart-bars {
    display: flex;
    justify-content: space-around;
    align-items: flex-end;
    height: 200px;
    padding: 1rem;
    background: #f7fafc;
    border-radius: 8px;
    position: relative;
}

.chart-month {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    flex: 1;
    max-width: 80px;
}

.chart-bar-container {
    display: flex;
    gap: 4px;
    align-items: flex-end;
    height: 150px;
    width: 100%;
    justify-content: center;
}

.chart-bar {
    width: 20px;
    border-radius: 4px 4px 0 0;
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
    min-height: 4px;
}

.chart-bar.premium {
    background: linear-gradient(135deg, #667eea, #764ba2);
}

.chart-bar.policies {
    background: linear-gradient(135deg, #f093fb, #f5576c);
}

.chart-bar:hover {
    transform: scaleY(1.05);
    opacity: 0.8;
}

.chart-bar:hover::after {
    content: attr(data-value);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: #2d3748;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    white-space: nowrap;
    z-index: 10;
}

.chart-month-label {
    color: #718096;
    font-size: 0.875rem;
    font-weight: 500;
}

/* Team Members Section */
.team-members-section {
    grid-column: 1 / -1;
}

.team-members-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.5rem;
}

.member-card {
    background: white;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.5rem;
    transition: all 0.3s ease;
}

.member-card:hover {
    border-color: #cbd5e0;
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.member-card.leader {
    border-color: #fbbf24;
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
}

.member-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.member-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    overflow: hidden;
    background: linear-gradient(135deg, #4facfe, #00f2fe);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 1.5rem;
    flex-shrink: 0;
    position: relative;
}

.member-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.leader-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background: #fbbf24;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.875rem;
    border: 2px solid white;
}

.member-name {
    margin: 0 0 0.25rem;
    font-size: 1.125rem;
    font-weight: 700;
}

.member-name a {
    color: #2d3748;
    text-decoration: none;
}

.member-name a:hover {
    color: #667eea;
}

.member-role {
    margin: 0;
    color: #718096;
    font-size: 0.875rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.leader-tag {
    background: #fbbf24;
    color: white;
    padding: 0.125rem 0.5rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.member-stats {
    margin-bottom: 1.5rem;
}

.stat-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 0;
    border-bottom: 1px solid #f1f5f9;
}

.stat-row:last-child {
    border-bottom: none;
}

.stat-label {
    color: #718096;
    font-weight: 500;
    font-size: 0.875rem;
}

.stat-value {
    color: #2d3748;
    font-weight: 600;
}

.member-actions {
    display: flex;
    gap: 0.5rem;
}

/* Icons */
.btn .icon-edit::before { content: "✏️"; }
.btn .icon-view::before { content: "👁️"; }

/* Responsive Design */
@media (max-width: 768px) {
    .organization-detail {
        padding: 0 1rem;
    }
    
    .detail-header {
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }
    
    .header-content {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .detail-title-section {
        flex-direction: column;
        text-align: center;
        gap: 1rem;
    }
    
    .detail-title {
        font-size: 1.5rem;
    }
    
    .summary-cards {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
    }
    
    .summary-card {
        padding: 1rem;
        flex-direction: column;
        text-align: center;
    }
    
    .detail-grid {
        grid-template-columns: 1fr;
        gap: 1.5rem;
    }
    
    .detail-section {
        padding: 1.5rem;
    }
    
    .permissions-grid {
        grid-template-columns: 1fr;
    }
    
    .chart-legend {
        flex-direction: column;
        align-items: center;
        gap: 1rem;
    }
    
    .team-members-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .member-card {
        padding: 1rem;
    }
    
    .member-header {
        flex-direction: column;
        text-align: center;
        gap: 0.75rem;
    }
}

@media (max-width: 480px) {
    .representative-avatar,
    .team-icon {
        width: 60px;
        height: 60px;
        font-size: 1.5rem;
    }
    
    .detail-title {
        font-size: 1.25rem;
    }
    
    .summary-card {
        padding: 0.75rem;
    }
    
    .card-content h3 {
        font-size: 1.5rem;
    }
    
    .chart-bars {
        height: 150px;
    }
    
    .chart-bar-container {
        height: 100px;
    }
    
    .info-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.25rem;
    }
}

/* Animation */
@keyframes slideInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.summary-card,
.detail-section {
    animation: slideInUp 0.5s ease forwards;
}

.summary-card:nth-child(1) { animation-delay: 0.1s; }
.summary-card:nth-child(2) { animation-delay: 0.2s; }
.summary-card:nth-child(3) { animation-delay: 0.3s; }
.summary-card:nth-child(4) { animation-delay: 0.4s; }

.chart-bar {
    animation: chartGrow 1s ease forwards;
    animation-delay: 0.5s;
    transform: scaleY(0);
    transform-origin: bottom;
}

@keyframes chartGrow {
    to {
        transform: scaleY(1);
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Chart bar animations
    const chartBars = document.querySelectorAll('.chart-bar');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.animationPlayState = 'running';
            }
        });
    });
    
    chartBars.forEach(bar => {
        observer.observe(bar);
    });
    
    // Hover effects
    const cards = document.querySelectorAll('.summary-card, .detail-section, .member-card');
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
    
    // Back link smooth scroll
    const backLinks = document.querySelectorAll('.back-link');
    backLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const url = this.getAttribute('href');
            window.location.href = url;
        });
    });
});
</script>
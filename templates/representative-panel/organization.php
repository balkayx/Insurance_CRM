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

// Patron kontrolü function is now defined in the main plugin file

if (!is_patron($current_user->ID)) {
    wp_die('Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Rol tanımları
$role_definitions = array(
    1 => 'Patron',
    2 => 'Müdür',
    3 => 'Müdür Yardımcısı',
    4 => 'Ekip Lideri',
    5 => 'Müşteri Temsilcisi'
);

// Ekipleri al
$settings = get_option('insurance_crm_settings', []);
$teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : [];

// Yönetim hiyerarşisini al
$management_hierarchy = isset($settings['management_hierarchy']) ? $settings['management_hierarchy'] : [
    'patron_id' => 0, 
    'manager_id' => 0,
    'assistant_manager_ids' => []
];

// Müdür yardımcıları için array oluştur, eğer yoksa boş array kullan
if (!isset($management_hierarchy['assistant_manager_ids'])) {
    $management_hierarchy['assistant_manager_ids'] = [];
}

// Tüm temsilcileri al
$representatives = $wpdb->get_results(
    "SELECT r.*, u.user_email as email, u.display_name 
     FROM {$wpdb->prefix}insurance_crm_representatives r 
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
     WHERE r.status = 'active' 
     ORDER BY r.created_at DESC"
);

// Ekip performans verilerini hazırla (sadece özet için)
$organization_stats = [
    'total_teams' => count($teams),
    'total_representatives' => count($representatives),
    'total_leaders' => 0,
    'total_premium' => 0
];

foreach ($teams as $team) {
    if (!empty($team['leader_id'])) {
        $organization_stats['total_leaders']++;
    }
}

// Toplam prim hesapla
$organization_stats['total_premium'] = $wpdb->get_var(
    "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
     FROM {$wpdb->prefix}insurance_crm_policies"
) ?: 0;

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

// Temsilci avatar URL'i al
function get_representative_avatar($rep) {
    if (!empty($rep->avatar_url)) {
        return esc_url($rep->avatar_url);
    }
    return false;
}
?>


<div class="main-content organization-hierarchy-page">
    <!-- Enhanced Page Header -->
    <div class="page-header-modern">
        <div class="header-background">
            <div class="header-pattern"></div>
            <div class="header-gradient"></div>
        </div>
        <div class="header-content">
            <div class="header-main">
                <div class="page-icon">
                    <svg class="organization-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M8 9l3 3-3 3m5 0h3"/>
                    </svg>
                </div>
                <div class="page-info">
                    <h1 class="page-title">Organizasyon Hiyerarşisi</h1>
                    <p class="page-subtitle">Eksiksiz organizasyon şeması ve yönetim yapısı</p>
                </div>
            </div>
            
            <!-- Organization Quick Stats -->
            <div class="org-quick-stats">
                <div class="quick-stat">
                    <div class="stat-number"><?php echo $organization_stats['total_teams']; ?></div>
                    <div class="stat-label">Ekip</div>
                </div>
                <div class="quick-stat">
                    <div class="stat-number"><?php echo $organization_stats['total_representatives']; ?></div>
                    <div class="stat-label">Temsilci</div>
                </div>
                <div class="quick-stat">
                    <div class="stat-number"><?php echo $organization_stats['total_leaders']; ?></div>
                    <div class="stat-label">Lider</div>
                </div>
                <div class="quick-stat">
                    <div class="stat-number">₺<?php echo number_format($organization_stats['total_premium'] / 1000000, 1); ?>M</div>
                    <div class="stat-label">Toplam Üretim</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Comprehensive Hierarchy Visualization -->
    <div class="hierarchy-visualization-container">
        <div class="hierarchy-controls">
            <div class="control-group">
                <button class="view-mode-btn active" data-mode="detailed">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                        <line x1="9" y1="9" x2="15" y2="9"/>
                        <line x1="9" y1="15" x2="15" y2="15"/>
                    </svg>
                    Detaylı Görünüm
                </button>
                <button class="view-mode-btn" data-mode="compact">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <circle cx="18" cy="5" r="3"/>
                        <circle cx="6" cy="12" r="3"/>
                        <circle cx="18" cy="19" r="3"/>
                        <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/>
                        <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                    </svg>
                    Kompakt Görünüm
                </button>
            </div>
            <div class="action-group">
                <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=hierarchy'); ?>" 
                   class="control-btn primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="m18.5 2.5 A2.121 2.121 0 0 1 21 5.621v0A2.121 2.121 0 0 1 18.5 8.121L10 16.621 6 18l1.379-4L16.5 5.621z"/>
                    </svg>
                    Düzenle
                </a>
                <button class="control-btn" id="refresh-org">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <polyline points="23 4 23 10 17 10"/>
                        <polyline points="1 20 1 14 7 14"/>
                        <path d="m3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                    </svg>
                    Yenile
                </button>
            </div>
        </div>

        <!-- Enhanced Organization Chart -->
        <div class="enhanced-org-chart" id="organizationChart">
            <?php
            $patron_data = null;
            $manager_data = null;
            $assistant_managers = [];
            
            // Patron bilgilerini al
            if (!empty($management_hierarchy['patron_id'])) {
                foreach ($representatives as $rep) {
                    if ($rep->id == $management_hierarchy['patron_id']) {
                        $patron_data = $rep;
                        break;
                    }
                }
            }
            
            // Müdür bilgilerini al
            if (!empty($management_hierarchy['manager_id'])) {
                foreach ($representatives as $rep) {
                    if ($rep->id == $management_hierarchy['manager_id']) {
                        $manager_data = $rep;
                        break;
                    }
                }
            }
            
            // Müdür yardımcılarını al
            if (!empty($management_hierarchy['assistant_manager_ids'])) {
                foreach ($management_hierarchy['assistant_manager_ids'] as $assistant_id) {
                    foreach ($representatives as $rep) {
                        if ($rep->id == $assistant_id) {
                            $assistant_managers[] = $rep;
                            break;
                        }
                    }
                }
            }
            ?>
            
            <!-- PATRON LEVEL -->
            <div class="org-level level-1">
                <div class="level-title">
                    <h3>ÜST YÖNETİM</h3>
                    <div class="level-line"></div>
                </div>
                <div class="org-positions">
                    <div class="org-card patron-card <?php echo $patron_data ? 'filled' : 'empty'; ?>" 
                         data-position="patron" 
                         data-rep-id="<?php echo $patron_data ? $patron_data->id : ''; ?>">
                        <div class="card-header">
                            <div class="position-avatar">
                                <?php if ($patron_data && get_representative_avatar($patron_data)): ?>
                                    <img src="<?php echo get_representative_avatar($patron_data); ?>" alt="<?php echo esc_attr($patron_data->display_name); ?>">
                                <?php else: ?>
                                    <div class="avatar-placeholder">
                                        <?php echo $patron_data ? strtoupper(substr($patron_data->display_name, 0, 1)) : 'P'; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="position-badge patron">PATRON</div>
                            </div>
                            <div class="position-info">
                                <h4 class="position-name">
                                    <?php echo $patron_data ? esc_html($patron_data->display_name) : 'Atanmamış'; ?>
                                </h4>
                                <?php if ($patron_data): ?>
                                    <p class="position-title"><?php echo esc_html($patron_data->title ?? $role_definitions[$patron_data->role] ?? 'Patron'); ?></p>
                                    <p class="position-contact">
                                        <span class="contact-item">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                                                <polyline points="22,6 12,13 2,6"/>
                                            </svg>
                                            <?php echo esc_html($patron_data->email); ?>
                                        </span>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$patron_data): ?>
                            <div class="empty-state">
                                <p>Patron pozisyonu boş</p>
                                <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=hierarchy'); ?>" class="assign-btn">Ata</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($patron_data && $manager_data): ?>
            <div class="org-connector main-connector">
                <div class="connector-line"></div>
                <div class="connector-dot"></div>
            </div>
            <?php endif; ?>

            <!-- MANAGER LEVEL -->
            <div class="org-level level-2">
                <div class="level-title">
                    <h3>OPERASYONEL YÖNETİM</h3>
                    <div class="level-line"></div>
                </div>
                <div class="org-positions">
                    <div class="org-card manager-card <?php echo $manager_data ? 'filled' : 'empty'; ?>" 
                         data-position="manager" 
                         data-rep-id="<?php echo $manager_data ? $manager_data->id : ''; ?>">
                        <div class="card-header">
                            <div class="position-avatar">
                                <?php if ($manager_data && get_representative_avatar($manager_data)): ?>
                                    <img src="<?php echo get_representative_avatar($manager_data); ?>" alt="<?php echo esc_attr($manager_data->display_name); ?>">
                                <?php else: ?>
                                    <div class="avatar-placeholder">
                                        <?php echo $manager_data ? strtoupper(substr($manager_data->display_name, 0, 1)) : 'M'; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="position-badge manager">MÜDÜR</div>
                            </div>
                            <div class="position-info">
                                <h4 class="position-name">
                                    <?php echo $manager_data ? esc_html($manager_data->display_name) : 'Atanmamış'; ?>
                                </h4>
                                <?php if ($manager_data): ?>
                                    <p class="position-title"><?php echo esc_html($manager_data->title ?? $role_definitions[$manager_data->role] ?? 'Müdür'); ?></p>
                                    <p class="position-contact">
                                        <span class="contact-item">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                                                <polyline points="22,6 12,13 2,6"/>
                                            </svg>
                                            <?php echo esc_html($manager_data->email); ?>
                                        </span>
                                    </p>
                                    <div class="position-stats">
                                        <div class="stat">
                                            <span class="stat-label">Ekip Sayısı</span>
                                            <span class="stat-value"><?php echo count($teams); ?></span>
                                        </div>
                                        <div class="stat">
                                            <span class="stat-label">Toplam Temsilci</span>
                                            <span class="stat-value"><?php echo count($representatives) - 1; ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$manager_data): ?>
                            <div class="empty-state">
                                <p>Müdür pozisyonu boş</p>
                                <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=hierarchy'); ?>" class="assign-btn">Ata</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($assistant_managers)): ?>
            <?php if ($manager_data): ?>
            <div class="org-connector assistant-connector">
                <div class="connector-line"></div>
                <div class="connector-dot"></div>
            </div>
            <?php endif; ?>
            
            <!-- ASSISTANT MANAGERS LEVEL -->
            <div class="org-level level-3">
                <div class="level-title">
                    <h3>YARDIMCI YÖNETİM</h3>
                    <div class="level-line"></div>
                </div>
                <div class="org-positions multi-position">
                    <?php foreach ($assistant_managers as $assistant): ?>
                    <div class="org-card assistant-card filled" 
                         data-position="assistant" 
                         data-rep-id="<?php echo $assistant->id; ?>">
                        <div class="card-header compact">
                            <div class="position-avatar small">
                                <?php if (get_representative_avatar($assistant)): ?>
                                    <img src="<?php echo get_representative_avatar($assistant); ?>" alt="<?php echo esc_attr($assistant->display_name); ?>">
                                <?php else: ?>
                                    <div class="avatar-placeholder">
                                        <?php echo strtoupper(substr($assistant->display_name, 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="position-badge assistant">YRD.</div>
                            </div>
                            <div class="position-info">
                                <h4 class="position-name"><?php echo esc_html($assistant->display_name); ?></h4>
                                <p class="position-title"><?php echo esc_html($assistant->title ?? 'Müdür Yardımcısı'); ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($teams)): ?>
            <?php if ($manager_data || !empty($assistant_managers)): ?>
            <div class="org-connector teams-connector">
                <div class="connector-line"></div>
                <div class="connector-branches">
                    <?php for ($i = 0; $i < count($teams); $i++): ?>
                        <div class="branch-line"></div>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- TEAMS LEVEL -->
            <div class="org-level level-4">
                <div class="level-title">
                    <h3>EKİP LİDERLİĞİ</h3>
                    <div class="level-line"></div>
                </div>
                <div class="teams-container">
                    <?php foreach ($teams as $team_id => $team): 
                        $leader_info = null;
                        foreach ($representatives as $rep) {
                            if ($rep->id == $team['leader_id']) {
                                $leader_info = $rep;
                                break;
                            }
                        }
                        
                        // Ekip üyelerinin bilgilerini al
                        $team_members = [];
                        if (!empty($team['members'])) {
                            foreach ($team['members'] as $member_id) {
                                foreach ($representatives as $rep) {
                                    if ($rep->id == $member_id) {
                                        $team_members[] = $rep;
                                        break;
                                    }
                                }
                            }
                        }
                    ?>
                    <div class="team-section" data-team-id="<?php echo esc_attr($team_id); ?>">
                        <!-- Team Leader Card -->
                        <div class="org-card team-leader-card filled" 
                             data-position="team-leader" 
                             data-rep-id="<?php echo $leader_info ? $leader_info->id : ''; ?>"
                             data-team-id="<?php echo esc_attr($team_id); ?>">
                            <div class="card-header">
                                <div class="position-avatar">
                                    <?php if ($leader_info && get_representative_avatar($leader_info)): ?>
                                        <img src="<?php echo get_representative_avatar($leader_info); ?>" alt="<?php echo esc_attr($leader_info->display_name); ?>">
                                    <?php else: ?>
                                        <div class="avatar-placeholder">
                                            <?php echo $leader_info ? strtoupper(substr($leader_info->display_name, 0, 1)) : 'L'; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="position-badge team-leader">LİDER</div>
                                </div>
                                <div class="position-info">
                                    <h4 class="position-name">
                                        <?php echo $leader_info ? esc_html($leader_info->display_name) : 'Lider Atanmamış'; ?>
                                    </h4>
                                    <p class="position-title team-name"><?php echo esc_html($team['name']); ?> Ekibi</p>
                                    <?php if ($leader_info): ?>
                                        <p class="position-contact">
                                            <span class="contact-item">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                                                    <polyline points="22,6 12,13 2,6"/>
                                                </svg>
                                                <?php echo esc_html($leader_info->email); ?>
                                            </span>
                                        </p>
                                        <div class="position-stats">
                                            <div class="stat">
                                                <span class="stat-label">Ekip Üyesi</span>
                                                <span class="stat-value"><?php echo count($team_members); ?></span>
                                            </div>
                                            <div class="stat">
                                                <span class="stat-label">Aylık Hedef</span>
                                                <span class="stat-value">₺<?php echo number_format($leader_info->monthly_target ?? 0); ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Team Members -->
                        <?php if (!empty($team_members)): ?>
                        <div class="team-members-connector">
                            <div class="members-line"></div>
                            <div class="members-branches">
                                <?php for ($i = 0; $i < count($team_members); $i++): ?>
                                    <div class="member-branch"></div>
                                <?php endfor; ?>
                            </div>
                        </div>
                        
                        <div class="team-members">
                            <div class="members-title">
                                <h5>EKİP ÜYELERİ</h5>
                                <span class="member-count"><?php echo count($team_members); ?> üye</span>
                            </div>
                            <div class="members-grid">
                                <?php foreach ($team_members as $member): ?>
                                <div class="member-card" data-rep-id="<?php echo $member->id; ?>">
                                    <div class="member-avatar">
                                        <?php if (get_representative_avatar($member)): ?>
                                            <img src="<?php echo get_representative_avatar($member); ?>" alt="<?php echo esc_attr($member->display_name); ?>">
                                        <?php else: ?>
                                            <div class="avatar-placeholder small">
                                                <?php echo strtoupper(substr($member->display_name, 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="member-info">
                                        <h6 class="member-name"><?php echo esc_html($member->display_name); ?></h6>
                                        <p class="member-title"><?php echo esc_html($member->title ?? 'Müşteri Temsilcisi'); ?></p>
                                        <p class="member-contact"><?php echo esc_html($member->email); ?></p>
                                        <div class="member-stats">
                                            <span class="member-target">₺<?php echo number_format($member->monthly_target ?? 0); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="empty-members">
                            <p>Bu ekipte henüz üye bulunmamaktadır.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <!-- Empty Teams State -->
            <div class="org-level level-4 empty-level">
                <div class="level-title">
                    <h3>EKİP LİDERLİĞİ</h3>
                    <div class="level-line"></div>
                </div>
                <div class="empty-teams-state">
                    <div class="empty-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                            <circle cx="9" cy="7" r="4"/>
                            <path d="m22 21-3-3m0 0-3-3m3 3 3-3m-3 3-3 3"/>
                        </svg>
                    </div>
                    <h4>Henüz Ekip Oluşturulmamış</h4>
                    <p>Organizasyonunuza ekip yapısı kazandırmak için ilk ekibinizi oluşturun.</p>
                    <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=teams&action=new_team'); ?>" class="create-team-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="16"/>
                            <line x1="8" y1="12" x2="16" y2="12"/>
                        </svg>
                        İlk Ekibi Oluştur
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Organization Summary & Actions -->
    <div class="organization-summary">
        <div class="summary-cards">
            <div class="summary-card">
                <div class="card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="m22 21-3-3"/>
                    </svg>
                </div>
                <div class="card-content">
                    <h4>Toplam Pozisyon</h4>
                    <div class="card-stats">
                        <div class="stat-item">
                            <span class="number"><?php echo (!empty($management_hierarchy['patron_id']) ? 1 : 0) + (!empty($management_hierarchy['manager_id']) ? 1 : 0) + count($assistant_managers); ?></span>
                            <span class="label">Yönetici</span>
                        </div>
                        <div class="stat-item">
                            <span class="number"><?php echo $organization_stats['total_leaders']; ?></span>
                            <span class="label">Ekip Lideri</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="summary-card">
                <div class="card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="m22 21-3-3m0 0-3-3m3 3 3-3m-3 3-3 3"/>
                    </svg>
                </div>
                <div class="card-content">
                    <h4>Ekip Dağılımı</h4>
                    <div class="card-stats">
                        <div class="stat-item">
                            <span class="number"><?php echo $organization_stats['total_teams']; ?></span>
                            <span class="label">Aktif Ekip</span>
                        </div>
                        <div class="stat-item">
                            <span class="number"><?php 
                                $total_members = 0;
                                foreach ($teams as $team) {
                                    $total_members += count($team['members'] ?? []);
                                }
                                echo $total_members;
                            ?></span>
                            <span class="label">Ekip Üyesi</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="summary-card">
                <div class="card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <line x1="12" y1="1" x2="12" y2="23"/>
                        <path d="m17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                    </svg>
                </div>
                <div class="card-content">
                    <h4>Performans Özeti</h4>
                    <div class="card-stats">
                        <div class="stat-item">
                            <span class="number">₺<?php echo number_format($organization_stats['total_premium'] / 1000000, 1); ?>M</span>
                            <span class="label">Toplam Üretim</span>
                        </div>
                        <div class="stat-item">
                            <span class="number"><?php echo number_format($organization_stats['total_premium'] / max(1, $organization_stats['total_representatives'])); ?></span>
                            <span class="label">Ortalama/Kişi</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="action-panel">
            <h4>Hızlı İşlemler</h4>
            <div class="action-buttons">
                <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=hierarchy'); ?>" class="action-btn primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="m18 15 4-4-4-4"/>
                        <path d="m6 9 4 4-4 4"/>
                    </svg>
                    Hiyerarşiyi Düzenle
                </a>
                <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=teams'); ?>" class="action-btn secondary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="m22 21-3-3m0 0-3-3m3 3 3-3m-3 3-3 3"/>
                    </svg>
                    Ekipleri Yönet
                </a>
                <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives'); ?>" class="action-btn secondary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M20 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M4 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4Z"/>
                        <path d="M9 9h6"/>
                        <path d="M9 15h6"/>
                    </svg>
                    Temsilcileri Yönet
                </a>
            </div>
        </div>
    </div>
</div>

<style>
/* Modern Organization Hierarchy Page Styles */
.organization-hierarchy-page {
    max-width: 1600px;
    margin: 0 auto;
    padding: 20px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

/* Enhanced Page Header */
.page-header-modern {
    position: relative;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 20px;
    overflow: hidden;
    margin-bottom: 30px;
    box-shadow: 0 20px 40px rgba(102, 126, 234, 0.3);
}

.header-background {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    opacity: 0.1;
}

.header-pattern {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-image: radial-gradient(circle at 25% 25%, white 2px, transparent 2px);
    background-size: 30px 30px;
}

.header-gradient {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(45deg, rgba(255,255,255,0.1) 0%, transparent 100%);
}

.header-content {
    position: relative;
    padding: 40px;
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 20px;
}

.header-main {
    display: flex;
    align-items: center;
    gap: 20px;
}

.page-icon {
    width: 80px;
    height: 80px;
    background: rgba(255,255,255,0.2);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(10px);
}

.organization-icon {
    width: 40px;
    height: 40px;
    stroke-width: 2;
}

.page-title {
    font-size: 2.5rem;
    font-weight: 700;
    margin: 0;
    line-height: 1.2;
}

.page-subtitle {
    font-size: 1.2rem;
    opacity: 0.9;
    margin: 8px 0 0;
    font-weight: 400;
}

.org-quick-stats {
    display: flex;
    gap: 30px;
    flex-wrap: wrap;
}

.quick-stat {
    text-align: center;
    background: rgba(255,255,255,0.15);
    padding: 20px;
    border-radius: 16px;
    backdrop-filter: blur(10px);
    min-width: 100px;
}

.stat-number {
    display: block;
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
}

.stat-label {
    font-size: 0.9rem;
    opacity: 0.9;
    margin-top: 5px;
    font-weight: 500;
}

/* Hierarchy Visualization Container */
.hierarchy-visualization-container {
    background: white;
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    margin-bottom: 30px;
    overflow: hidden;
}

.hierarchy-controls {
    background: #f8fafc;
    padding: 20px 30px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

.control-group, .action-group {
    display: flex;
    gap: 10px;
    align-items: center;
}

.view-mode-btn, .control-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
    border: 1px solid #e2e8f0;
    background: white;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    color: #4a5568;
    cursor: pointer;
    transition: all 0.2s ease;
}

.view-mode-btn:hover, .control-btn:hover {
    border-color: #667eea;
    color: #667eea;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.view-mode-btn.active {
    background: #667eea;
    color: white;
    border-color: #667eea;
}

.control-btn.primary {
    background: #667eea;
    color: white;
    border-color: #667eea;
}

.control-btn.primary:hover {
    background: #5a67d8;
    color: white;
}

.view-mode-btn svg, .control-btn svg {
    width: 16px;
    height: 16px;
}

/* Enhanced Organization Chart */
.enhanced-org-chart {
    padding: 40px;
    background: linear-gradient(to bottom, #f8fafc 0%, white 100%);
}

.org-level {
    margin-bottom: 50px;
    position: relative;
}

.level-title {
    text-align: center;
    margin-bottom: 30px;
    position: relative;
}

.level-title h3 {
    font-size: 18px;
    font-weight: 700;
    color: #2d3748;
    text-transform: uppercase;
    letter-spacing: 2px;
    margin: 0;
    background: white;
    padding: 0 20px;
    display: inline-block;
    position: relative;
    z-index: 2;
}

.level-line {
    position: absolute;
    top: 50%;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(to right, transparent, #e2e8f0, transparent);
    z-index: 1;
}

.org-positions {
    display: flex;
    justify-content: center;
    gap: 30px;
    flex-wrap: wrap;
}

.org-positions.multi-position {
    justify-content: center;
    gap: 20px;
}

/* Organization Cards */
.org-card {
    background: white;
    border-radius: 20px;
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    padding: 30px;
    min-width: 320px;
    max-width: 400px;
    transition: all 0.3s ease;
    position: relative;
    border: 2px solid transparent;
}

.org-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 40px rgba(0,0,0,0.15);
}

.org-card.patron-card {
    border-color: #e53e3e;
    background: linear-gradient(135deg, #fff5f5 0%, white 100%);
}

.org-card.manager-card {
    border-color: #3182ce;
    background: linear-gradient(135deg, #ebf8ff 0%, white 100%);
}

.org-card.assistant-card {
    border-color: #38a169;
    background: linear-gradient(135deg, #f0fff4 0%, white 100%);
    min-width: 280px;
}

.org-card.team-leader-card {
    border-color: #d69e2e;
    background: linear-gradient(135deg, #fffaf0 0%, white 100%);
}

.org-card.empty {
    border: 2px dashed #cbd5e0;
    background: #f7fafc;
    opacity: 0.7;
}

.card-header {
    display: flex;
    align-items: flex-start;
    gap: 20px;
    margin-bottom: 20px;
}

.card-header.compact {
    gap: 15px;
    margin-bottom: 0;
}

.position-avatar {
    position: relative;
    flex-shrink: 0;
}

.position-avatar img, .avatar-placeholder {
    width: 80px;
    height: 80px;
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    font-weight: 700;
    color: white;
    background: linear-gradient(135deg, #667eea, #764ba2);
}

.position-avatar.small img, .position-avatar.small .avatar-placeholder {
    width: 60px;
    height: 60px;
    font-size: 1.5rem;
}

.position-badge {
    position: absolute;
    bottom: -5px;
    right: -5px;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: white;
}

.position-badge.patron { background: #e53e3e; }
.position-badge.manager { background: #3182ce; }
.position-badge.assistant { background: #38a169; }
.position-badge.team-leader { background: #d69e2e; }

.position-info {
    flex: 1;
}

.position-name {
    font-size: 1.4rem;
    font-weight: 700;
    color: #2d3748;
    margin: 0 0 8px;
    line-height: 1.3;
}

.position-title {
    font-size: 1rem;
    color: #4a5568;
    margin: 0 0 12px;
    font-weight: 500;
}

.position-title.team-name {
    color: #d69e2e;
    font-weight: 600;
}

.position-contact {
    margin: 0 0 15px;
}

.contact-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    color: #718096;
}

.contact-item svg {
    width: 16px;
    height: 16px;
}

.position-stats {
    display: flex;
    gap: 20px;
}

.stat {
    text-align: center;
}

.stat-label {
    display: block;
    font-size: 0.8rem;
    color: #718096;
    margin-bottom: 4px;
    font-weight: 500;
}

.stat-value {
    display: block;
    font-size: 1.1rem;
    font-weight: 700;
    color: #2d3748;
}

.empty-state {
    text-align: center;
    padding: 20px;
    color: #718096;
}

.assign-btn {
    display: inline-block;
    margin-top: 10px;
    padding: 8px 16px;
    background: #667eea;
    color: white;
    text-decoration: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s ease;
}

.assign-btn:hover {
    background: #5a67d8;
    transform: translateY(-1px);
}

/* Connectors */
.org-connector {
    display: flex;
    justify-content: center;
    margin: 20px 0;
}

.connector-line {
    width: 3px;
    height: 40px;
    background: linear-gradient(to bottom, #667eea, #764ba2);
    border-radius: 2px;
    position: relative;
}

.connector-dot {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 12px;
    height: 12px;
    background: #667eea;
    border: 3px solid white;
    border-radius: 50%;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

.connector-branches {
    display: flex;
    justify-content: center;
    gap: 100px;
    margin-top: 10px;
}

.branch-line {
    width: 2px;
    height: 30px;
    background: #cbd5e0;
}

/* Teams Container */
.teams-container {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 40px;
    max-width: 100%;
}

.team-section {
    display: flex;
    flex-direction: column;
    align-items: center;
    max-width: 400px;
}

/* Team Members */
.team-members-connector {
    margin: 20px 0;
    text-align: center;
}

.members-line {
    width: 3px;
    height: 30px;
    background: #cbd5e0;
    margin: 0 auto 10px;
}

.members-branches {
    display: flex;
    justify-content: center;
    gap: 40px;
}

.member-branch {
    width: 2px;
    height: 20px;
    background: #e2e8f0;
}

.team-members {
    width: 100%;
}

.members-title {
    text-align: center;
    margin-bottom: 20px;
    padding: 15px;
    background: #f7fafc;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
}

.members-title h5 {
    font-size: 14px;
    font-weight: 700;
    color: #4a5568;
    margin: 0 0 5px;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.member-count {
    font-size: 12px;
    color: #718096;
    background: white;
    padding: 4px 8px;
    border-radius: 6px;
    font-weight: 500;
}

.members-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 15px;
}

.member-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    transition: all 0.2s ease;
}

.member-card:hover {
    border-color: #cbd5e0;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.member-avatar {
    margin-bottom: 12px;
}

.member-avatar img, .member-avatar .avatar-placeholder {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    margin: 0 auto;
}

.avatar-placeholder.small {
    width: 50px;
    height: 50px;
    font-size: 1.2rem;
    background: linear-gradient(135deg, #cbd5e0, #a0aec0);
    color: white;
}

.member-name {
    font-size: 14px;
    font-weight: 600;
    color: #2d3748;
    margin: 0 0 4px;
}

.member-title {
    font-size: 12px;
    color: #718096;
    margin: 0 0 8px;
}

.member-contact {
    font-size: 11px;
    color: #a0aec0;
    margin: 0 0 10px;
    word-break: break-all;
}

.member-target {
    font-size: 13px;
    font-weight: 600;
    color: #38a169;
    background: #f0fff4;
    padding: 4px 8px;
    border-radius: 6px;
    display: inline-block;
}

.empty-members {
    text-align: center;
    padding: 30px;
    color: #718096;
    font-style: italic;
}

/* Empty Teams State */
.empty-teams-state {
    text-align: center;
    padding: 60px 40px;
    color: #718096;
}

.empty-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto 20px;
    background: #f7fafc;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.empty-icon svg {
    width: 40px;
    height: 40px;
    stroke: #cbd5e0;
}

.empty-teams-state h4 {
    font-size: 1.5rem;
    color: #4a5568;
    margin: 0 0 10px;
}

.empty-teams-state p {
    margin: 0 0 25px;
    max-width: 400px;
    margin-left: auto;
    margin-right: auto;
}

.create-team-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: #667eea;
    color: white;
    text-decoration: none;
    border-radius: 12px;
    font-weight: 600;
    transition: all 0.2s ease;
}

.create-team-btn:hover {
    background: #5a67d8;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

.create-team-btn svg {
    width: 18px;
    height: 18px;
}

/* Organization Summary */
.organization-summary {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 30px;
    margin-top: 30px;
}

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.summary-card {
    background: white;
    padding: 25px;
    border-radius: 16px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    border: 1px solid #f1f5f9;
}

.card-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 20px;
}

.card-icon svg {
    width: 24px;
    height: 24px;
    stroke: white;
}

.card-content h4 {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2d3748;
    margin: 0 0 15px;
}

.card-stats {
    display: flex;
    gap: 20px;
}

.stat-item {
    text-align: center;
}

.stat-item .number {
    display: block;
    font-size: 1.5rem;
    font-weight: 700;
    color: #667eea;
    line-height: 1;
}

.stat-item .label {
    font-size: 0.85rem;
    color: #718096;
    margin-top: 4px;
    font-weight: 500;
}

.action-panel {
    background: white;
    padding: 25px;
    border-radius: 16px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    border: 1px solid #f1f5f9;
}

.action-panel h4 {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2d3748;
    margin: 0 0 20px;
}

.action-buttons {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.action-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 500;
    transition: all 0.2s ease;
}

.action-btn.primary {
    background: #667eea;
    color: white;
}

.action-btn.secondary {
    background: #f7fafc;
    color: #4a5568;
    border: 1px solid #e2e8f0;
}

.action-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.action-btn.primary:hover {
    background: #5a67d8;
    color: white;
}

.action-btn.secondary:hover {
    background: #edf2f7;
    color: #2d3748;
}

.action-btn svg {
    width: 18px;
    height: 18px;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .organization-summary {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .organization-hierarchy-page {
        padding: 15px;
    }
    
    .header-content {
        padding: 30px 25px;
        flex-direction: column;
        text-align: center;
    }
    
    .page-title {
        font-size: 2rem;
    }
    
    .org-quick-stats {
        justify-content: center;
    }
    
    .hierarchy-controls {
        padding: 15px 20px;
        flex-direction: column;
        align-items: stretch;
    }
    
    .control-group, .action-group {
        justify-content: center;
    }
    
    .enhanced-org-chart {
        padding: 20px;
    }
    
    .org-card {
        min-width: 280px;
        max-width: 100%;
        margin: 0 auto;
    }
    
    .teams-container {
        flex-direction: column;
        align-items: center;
    }
    
    .members-grid {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    }
    
    .summary-cards {
        grid-template-columns: 1fr;
    }
    
    .connector-branches {
        gap: 60px;
    }
    
    .members-branches {
        gap: 30px;
    }
}

@media (max-width: 480px) {
    .header-main {
        flex-direction: column;
        text-align: center;
    }
    
    .page-title {
        font-size: 1.8rem;
    }
    
    .org-quick-stats {
        gap: 15px;
    }
    
    .quick-stat {
        padding: 15px;
        min-width: 80px;
    }
    
    .stat-number {
        font-size: 1.5rem;
    }
    
    .org-card {
        padding: 20px;
        min-width: 260px;
    }
    
    .card-header {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .position-stats {
        justify-content: center;
    }
    
    .members-grid {
        grid-template-columns: 1fr;
    }
    
    .control-group {
        flex-direction: column;
        width: 100%;
    }
    
    .view-mode-btn, .control-btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // View mode switching
    const viewModeButtons = document.querySelectorAll('.view-mode-btn');
    const orgChart = document.getElementById('organizationChart');
    
    viewModeButtons.forEach(button => {
        button.addEventListener('click', function() {
            viewModeButtons.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            
            const mode = this.dataset.mode;
            orgChart.classList.toggle('compact-mode', mode === 'compact');
        });
    });
    
    // Refresh functionality
    const refreshBtn = document.getElementById('refresh-org');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function() {
            this.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M23 4v6l-6-6"/><path d="M1 20v-6l6 6"/><path d="m3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>Yenileniyor...';
            this.disabled = true;
            
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        });
    }
    
    // Card hover animations
    const orgCards = document.querySelectorAll('.org-card, .member-card, .summary-card');
    orgCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
    
    // Progressive enhancement for animations
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    });
    
    document.querySelectorAll('.org-level').forEach(level => {
        level.style.opacity = '0';
        level.style.transform = 'translateY(30px)';
        level.style.transition = 'all 0.6s ease';
        observer.observe(level);
    });
});
</script>

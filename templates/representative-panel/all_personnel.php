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
 * Pagename : all_personnel.php
 * Page Version: 2.0.3
 * Author:      Mehmet BALKAY | Anadolu Birlik
 * Author URI:  https://www.balkay.net
 */

/**
 * Tüm Personel Görünümü - Modern Tasarım
 * 
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/templates/representative-panel
 * @author     Anadolu Birlik
 * @since      1.0.0
 * @version    2.0.2 (2025-06-01)
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$user_role = get_user_role_in_hierarchy($current_user->ID);

// Sadece patron ve müdür tüm personeli görebilir
if (!is_patron($current_user->ID) && !is_manager($current_user->ID)) {
    wp_die('Bu sayfaya erişim yetkiniz bulunmuyor.');
}

// DB sütunlarını kontrol et ve gerekirse ekle
function check_and_create_personnel_columns() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_representatives';
    
    // deleted_at sütunu var mı kontrol et
    $deleted_at_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'deleted_at'");
    if (empty($deleted_at_exists)) {
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL");
    }
    
    // deleted_by sütunu var mı kontrol et  
    $deleted_by_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'deleted_by'");
    if (empty($deleted_by_exists)) {
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN deleted_by INT NULL DEFAULT NULL");
    }
}

// Sütunları kontrol et
check_and_create_personnel_columns();

// Filtreler
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'active';
$role_filter = isset($_GET['role']) ? sanitize_text_field($_GET['role']) : 'all';
$team_filter = isset($_GET['team_id']) ? sanitize_text_field($_GET['team_id']) : 'all';
$show_inactive = isset($_GET['show_inactive']) && $_GET['show_inactive'] === '1';

// Mevcut temsilcileri al - filtrelemeyi SQL düzeyinde yap
$table_reps = $wpdb->prefix . 'insurance_crm_representatives';

// Varsayılan olarak sadece aktif temsilciler
$where_status = "r.status != 'deleted'";
if (!$show_inactive) {
    $where_status .= " AND r.status = 'active'";
}

$representatives = $wpdb->get_results(
    "SELECT r.*, u.display_name, u.user_email 
     FROM {$table_reps} r 
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
     WHERE {$where_status}
     ORDER BY r.role ASC, u.display_name ASC"
);

// Mevcut ekipler
$settings = get_option('insurance_crm_settings', array());
$teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();

// Aktif ekipleri say (leader_id veya members'ı olan ekipler)
$active_teams_count = 0;
foreach ($teams as $team) {
    if (!empty($team['leader_id']) || (!empty($team['members']) && is_array($team['members']) && count($team['members']) > 0)) {
        $active_teams_count++;
    }
}

// Rol adlarını harita
$role_map = array(
    1 => 'Patron',
    2 => 'Müdür',
    3 => 'Müdür Yardımcısı',
    4 => 'Ekip Lideri',
    5 => 'Müşteri Temsilcisi'
);

// Temsilci statüleri
$status_map = array(
    'active' => 'Aktif',
    'inactive' => 'Pasif',
    'deleted' => 'Silindi'
);

// İstatistikler - Tüm temsilcileri say (filtrelemeden bağımsız)
$total_active = $wpdb->get_var("SELECT COUNT(*) FROM {$table_reps} r WHERE r.status = 'active' AND r.status != 'deleted'");
$total_inactive = $wpdb->get_var("SELECT COUNT(*) FROM {$table_reps} r WHERE r.status = 'inactive' AND r.status != 'deleted'");
$role_counts = array();

// Görüntülenen temsilcilerden rol sayılarını hesapla
foreach ($representatives as $rep) {
    $role_key = isset($role_map[$rep->role]) ? $role_map[$rep->role] : 'Diğer';
    if (!isset($role_counts[$role_key])) {
        $role_counts[$role_key] = 0;
    }
    $role_counts[$role_key]++;
}
?>

<div class="modern-personnel-container">
    <!-- Header Section -->
    <div class="page-header-modern">
        <div class="header-main">
            <div class="header-content">
                <div class="header-left">
                    <h1><i class="fas fa-users"></i> Personel Yönetimi</h1>
                </div>
                <div class="header-right">
                    <div class="header-buttons">
                        <?php if (is_patron($current_user->ID)): ?>
                        <a href="<?php echo generate_panel_url('representative_add'); ?>" class="btn-modern btn-primary">
                            <i class="fas fa-user-plus"></i> Yeni Personel
                        </a>
                        <?php endif; ?>
                        

                    </div>
                </div>
            </div>
        </div>
        <div class="header-subtitle-section">
            <p class="header-subtitle">Tüm personeli görüntüleyin ve yönetin</p>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo count($representatives); ?></h3>
                <p>Toplam Personel</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #5ee7df 0%, #66a6ff 100%);">
                <i class="fas fa-user-check"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo $total_active; ?></h3>
                <p>Aktif Personel</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <i class="fas fa-user-times"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo $total_inactive; ?></h3>
                <p>Pasif Personel</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                <i class="fas fa-users-cog"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo $active_teams_count; ?></h3>
                <p>Toplam Ekip</p>
            </div>
        </div>
    </div>
    
    <!-- Filter Section -->
    <div class="filter-section">
        <form class="modern-filter-form" method="get" action="<?php echo generate_panel_url('all_personnel'); ?>">
            <input type="hidden" name="view" value="all_personnel">
            
            <div class="filter-row">
                <div class="filter-group">
                    <select name="role" class="modern-select">
                        <option value="all" <?php selected($role_filter, 'all'); ?>>Tüm Roller</option>
                        <?php foreach ($role_map as $role_id => $role_name): ?>
                        <option value="<?php echo $role_id; ?>" <?php selected($role_filter, (string)$role_id); ?>>
                            <?php echo $role_name; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <select name="status" class="modern-select">
                        <option value="all" <?php selected($status_filter, 'all'); ?>>Tüm Durumlar</option>
                        <?php foreach ($status_map as $status_id => $status_name): ?>
                        <option value="<?php echo $status_id; ?>" <?php selected($status_filter, $status_id); ?>>
                            <?php echo $status_name; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if (!empty($teams)): ?>
                <div class="filter-group">
                    <select name="team_id" class="modern-select">
                        <option value="all" <?php selected($team_filter, 'all'); ?>>Tüm Ekipler</option>
                        <?php foreach ($teams as $team_id => $team): ?>
                        <option value="<?php echo $team_id; ?>" <?php selected($team_filter, $team_id); ?>>
                            <?php echo $team['name']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="filter-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="show_inactive" value="1" 
                               <?php checked($show_inactive, true); ?> 
                               onchange="this.form.submit()">
                        <span class="checkbox-text">Pasif temsilcileri de göster</span>
                    </label>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn-modern btn-filter">
                        <i class="fas fa-filter"></i> Filtrele
                    </button>
                    <a href="<?php echo generate_panel_url('all_personnel'); ?>" class="btn-modern btn-reset">
                        <i class="fas fa-redo"></i> Sıfırla
                    </a>
                </div>
            </div>
        </form>
        
        <div class="view-options">
            <button class="view-btn active" data-view="grid">
                <i class="fas fa-th"></i>
            </button>
            <button class="view-btn" data-view="list">
                <i class="fas fa-list"></i>
            </button>
        </div>
    </div>
    
    <!-- Personnel Grid -->
    <div class="personnel-grid-modern" id="personnelGrid">
        <?php 
        $filtered_representatives = array();
        
        foreach ($representatives as $rep):
            // Rol filtresi
            if ($role_filter !== 'all' && (string)$rep->role !== $role_filter) continue;
            
            // Ekip filtresi
            if ($team_filter !== 'all') {
                $is_in_filtered_team = false;
                
                if (isset($teams[$team_filter])) {
                    if ($teams[$team_filter]['leader_id'] == $rep->id) {
                        $is_in_filtered_team = true;
                    } elseif (in_array($rep->id, $teams[$team_filter]['members'])) {
                        $is_in_filtered_team = true;
                    }
                }
                
                if (!$is_in_filtered_team) continue;
            }
            
            $filtered_representatives[] = $rep;
        endforeach;
        
        if (empty($filtered_representatives)): ?>
            <div class="empty-state">
                <i class="fas fa-filter"></i>
                <h3>Sonuç Bulunamadı</h3>
                <p>Filtreleme kriterlerinize uygun personel bulunamadı.</p>
                <a href="<?php echo generate_panel_url('all_personnel'); ?>" class="btn-modern btn-primary">
                    Tüm Personeli Göster
                </a>
            </div>
        <?php else:
            foreach ($filtered_representatives as $rep):
                // Ekip bilgisi
                $team_info = null;
                foreach ($teams as $team_id => $team) {
                    if ($team['leader_id'] == $rep->id) {
                        $team_info = array('name' => $team['name'], 'role' => 'Ekip Lideri');
                        break;
                    } elseif (in_array($rep->id, $team['members'])) {
                        $team_info = array('name' => $team['name'], 'role' => 'Üye');
                        break;
                    }
                }
                
                // İstatistikler
                $customer_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers WHERE representative_id = %d",
                    $rep->id
                )) ?: 0;
                
                $policy_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
                     WHERE representative_id = %d AND cancellation_date IS NULL",
                    $rep->id
                )) ?: 0;
                
                $role_name = isset($role_map[$rep->role]) ? $role_map[$rep->role] : 'Temsilci';
                $role_class = 'role-' . $rep->role;
        ?>
            <div class="personnel-card-modern <?php echo $rep->status === 'active' ? 'active' : 'inactive'; ?>">
                <div class="card-header">
                    <div class="avatar-section">
                        <?php if (!empty($rep->avatar_url)): ?>
                            <img src="<?php echo esc_url($rep->avatar_url); ?>" alt="<?php echo esc_attr($rep->display_name); ?>" class="avatar">
                        <?php else: ?>
                            <div class="avatar-placeholder">
                                <?php echo esc_html(strtoupper(substr($rep->display_name, 0, 2))); ?>
                            </div>
                        <?php endif; ?>
                        <span class="status-indicator <?php echo $rep->status; ?>"></span>
                    </div>
                    
                    <div class="role-badge <?php echo $role_class; ?>">
                        <?php echo esc_html($role_name); ?>
                    </div>
                </div>
                
                <div class="card-body">
                    <h3 class="personnel-name"><?php echo esc_html($rep->display_name); ?></h3>
                    <p class="personnel-title"><?php echo esc_html($rep->title ?: '-'); ?></p>
                    
                    <?php if ($team_info): ?>
                    <div class="team-info">
                        <i class="fas fa-users"></i>
                        <span><?php echo esc_html($team_info['name']); ?></span>
                        <small>(<?php echo esc_html($team_info['role']); ?>)</small>
                    </div>
                    <?php endif; ?>
                    
                    <div class="contact-info">
                        <div class="contact-item">
                            <i class="fas fa-envelope"></i>
                            <span><?php echo esc_html($rep->user_email); ?></span>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-phone"></i>
                            <span><?php echo esc_html($rep->phone ?: '-'); ?></span>
                        </div>
                    </div>
                    
                    <div class="stats-row">
                        <div class="stat-item">
                            <span class="stat-value"><?php echo number_format($customer_count); ?></span>
                            <span class="stat-label">Müşteri</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-value"><?php echo number_format($policy_count); ?></span>
                            <span class="stat-label">Poliçe</span>
                        </div>
                    </div>
                </div>
                
                <div class="card-footer">
                    <div class="action-buttons">
                        <a href="<?php echo generate_panel_url('representative_detail', '', $rep->id); ?>" 
                           class="btn-action btn-view" title="Detayları Görüntüle">
                            <i class="fas fa-eye"></i>
                        </a>
                        
                        <?php if (is_patron($current_user->ID)): ?>
                        <a href="<?php echo generate_panel_url('edit_representative', '', '', array('id' => $rep->id)); ?>" 
                           class="btn-action btn-edit" title="Düzenle">
                            <i class="fas fa-edit"></i>
                        </a>
                        <?php endif; ?>
                        
                        <?php if ((is_patron($current_user->ID) || is_manager($current_user->ID))): ?>
                        <?php if ($rep->status === 'active'): ?>
                        <button class="btn-action btn-deactivate status-toggle" 
                                data-id="<?php echo $rep->id; ?>" 
                                data-status="inactive" 
                                title="Pasife Al">
                            <i class="fas fa-ban"></i>
                        </button>
                        <?php else: ?>
                        <button class="btn-action btn-activate status-toggle" 
                                data-id="<?php echo $rep->id; ?>" 
                                data-status="active" 
                                title="Aktif Et">
                            <i class="fas fa-check-circle"></i>
                        </button>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php 
            endforeach;
        endif; 
        ?>
    </div>
    
    <!-- Personnel List (Hidden by default) -->
    <div class="personnel-list-modern" id="personnelList" style="display: none;">
        <table class="modern-table">
            <thead>
                <tr>
                    <th>Personel</th>
                    <th>Rol</th>
                    <th>İletişim</th>
                    <th>Ekip</th>
                    <th>İstatistikler</th>
                    <th>Durum</th>
                    <th>İşlemler</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filtered_representatives as $rep):
                    // Ekip bilgisi
                    $team_info = null;
                    foreach ($teams as $team_id => $team) {
                        if ($team['leader_id'] == $rep->id) {
                            $team_info = array('name' => $team['name'], 'role' => 'Ekip Lideri');
                            break;
                        } elseif (in_array($rep->id, $team['members'])) {
                            $team_info = array('name' => $team['name'], 'role' => 'Üye');
                            break;
                        }
                    }
                    
                    // İstatistikler
                    $customer_count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers WHERE representative_id = %d",
                        $rep->id
                    )) ?: 0;
                    
                    $policy_count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
                         WHERE representative_id = %d AND cancellation_date IS NULL",
                        $rep->id
                    )) ?: 0;
                    
                    $role_name = isset($role_map[$rep->role]) ? $role_map[$rep->role] : 'Temsilci';
                    $role_class = 'role-' . $rep->role;
                ?>
                <tr>
                    <td>
                        <div class="user-cell">
                            <?php if (!empty($rep->avatar_url)): ?>
                                <img src="<?php echo esc_url($rep->avatar_url); ?>" alt="<?php echo esc_attr($rep->display_name); ?>" class="user-avatar">
                            <?php else: ?>
                                <div class="user-avatar-placeholder">
                                    <?php echo esc_html(strtoupper(substr($rep->display_name, 0, 2))); ?>
                                </div>
                            <?php endif; ?>
                            <div class="user-info">
                                <a href="<?php echo generate_panel_url('representative_detail', '', $rep->id); ?>" class="user-name">
                                    <?php echo esc_html($rep->display_name); ?>
                                </a>
                                <span class="user-title"><?php echo esc_html($rep->title ?: '-'); ?></span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="role-badge-inline <?php echo $role_class; ?>">
                            <?php echo esc_html($role_name); ?>
                        </span>
                    </td>
                    <td>
                        <div class="contact-cell">
                            <div><i class="fas fa-envelope"></i> <?php echo esc_html($rep->user_email); ?></div>
                            <div><i class="fas fa-phone"></i> <?php echo esc_html($rep->phone ?: '-'); ?></div>
                        </div>
                    </td>
                    <td>
                        <?php if ($team_info): ?>
                            <div class="team-cell">
                                <span class="team-name"><?php echo esc_html($team_info['name']); ?></span>
                                <span class="team-role"><?php echo esc_html($team_info['role']); ?></span>
                            </div>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="stats-cell">
                            <span class="stat-badge">
                                <i class="fas fa-users"></i> <?php echo number_format($customer_count); ?>
                            </span>
                            <span class="stat-badge">
                                <i class="fas fa-file-alt"></i> <?php echo number_format($policy_count); ?>
                            </span>
                        </div>
                    </td>
                    <td>
                        <span class="status-badge <?php echo $rep->status; ?>">
                            <?php echo $rep->status === 'active' ? 'Aktif' : 'Pasif'; ?>
                        </span>
                    </td>
                    <td>
                        <div class="table-actions">
                            <a href="<?php echo generate_panel_url('representative_detail', '', $rep->id); ?>" 
                               class="btn-table-action" title="Detay">
                                <i class="fas fa-eye"></i>
                            </a>
                            
                            <?php if (is_patron($current_user->ID)): ?>
                            <a href="<?php echo generate_panel_url('edit_representative', '', '', array('id' => $rep->id)); ?>" 
                               class="btn-table-action" title="Düzenle">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php endif; ?>
                            
                            <?php if ((is_patron($current_user->ID) || is_manager($current_user->ID))): ?>
                            <?php if ($rep->status === 'active'): ?>
                            <button class="btn-table-action status-toggle" 
                                    data-id="<?php echo $rep->id; ?>" 
                                    data-status="inactive" 
                                    title="Pasife Al">
                                <i class="fas fa-ban"></i>
                            </button>
                            <?php else: ?>
                            <button class="btn-table-action status-toggle" 
                                    data-id="<?php echo $rep->id; ?>" 
                                    data-status="active" 
                                    title="Aktif Et">
                                <i class="fas fa-check-circle"></i>
                            </button>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
/* Modern Personnel Container */
.modern-personnel-container {
    padding: 30px;
    background-color: #f8f9fa;
    min-height: 100vh;
}

/* Modern Header */
.page-header-modern {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 20px;
    margin-bottom: 30px;
    color: white;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.header-main {
    padding: 40px 40px 20px 40px;
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.header-left {
    flex: 1;
}

.header-left h1 {
    font-size: 28px;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 15px;
}

.header-subtitle-section {
    padding: 0 40px 30px 40px;
    border-top: 1px solid rgba(255, 255, 255, 0.2);
    margin-top: 20px;
    padding-top: 20px;
}

.header-subtitle {
    font-size: 16px;
    opacity: 0.9;
    margin: 0;
    font-weight: 400;
    line-height: 1.4;
    display: block;
}

/* Header buttons section */
.header-buttons {
    display: flex;
    flex-direction: column;
    gap: 10px;
    align-items: flex-end;
}

/* Modern Buttons */
.btn-modern {
    padding: 12px 24px;
    border-radius: 10px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
    white-space: nowrap;
}

.btn-primary {
    background: white;
    color: #667eea;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
}

.btn-secondary {
    background: #f1f5f9;
    color: #475569;
}

.btn-secondary:hover {
    background: #e2e8f0;
    transform: translateY(-2px);
}

.btn-filter {
    background: #667eea;
    color: white;
}

.btn-filter:hover {
    background: #5a67d8;
}

.btn-reset {
    background: #e2e8f0;
    color: #64748b;
}

.btn-reset:hover {
    background: #cbd5e1;
}

/* Statistics Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 15px;
    padding: 25px;
    display: flex;
    align-items: center;
    gap: 20px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    transition: transform 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 24px;
}

.stat-content h3 {
    font-size: 28px;
    font-weight: 700;
    margin: 0;
    color: #1e293b;
}

.stat-content p {
    font-size: 14px;
    color: #64748b;
    margin: 5px 0 0 0;
}

/* Filter Section */
.filter-section {
    background: white;
    border-radius: 15px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modern-filter-form {
    flex: 1;
}

.filter-row {
    display: flex;
    gap: 15px;
    align-items: center;
    flex-wrap: wrap;
}

.modern-select {
    padding: 12px 20px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 14px;
    min-width: 150px;
    transition: all 0.3s ease;
    background-color: white;
}

.modern-select:focus {
    outline: none;
    border-color: #667eea;
}

.filter-actions {
    display: flex;
    gap: 10px;
}

/* View Options */
.view-options {
    display: flex;
    gap: 5px;
    background: #f1f5f9;
    padding: 5px;
    border-radius: 10px;
}

.view-btn {
    padding: 8px 12px;
    border: none;
    background: transparent;
    color: #64748b;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.view-btn.active {
    background: white;
    color: #667eea;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

/* Personnel Grid */
.personnel-grid-modern {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 25px;
}

.personnel-card-modern {
    background: white;
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    transition: all 0.3s ease;
}

.personnel-card-modern:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
}

.personnel-card-modern.inactive {
    opacity: 0.7;
}

.personnel-card-modern .card-header {
    padding: 20px;
    background: #f8fafc;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.avatar-section {
    position: relative;
}

.avatar, .avatar-placeholder {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    object-fit: cover;
}

.avatar-placeholder {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 20px;
}

.status-indicator {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: 3px solid white;
}

.status-indicator.active {
    background: #10b981;
}

.status-indicator.inactive {
    background: #ef4444;
}

.status-indicator.deleted {
    background: #6b7280;
}

/* Role Badges */
.role-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.role-badge.role-1 { /* Patron */
    background: #fef3c7;
    color: #d97706;
}

.role-badge.role-2 { /* Müdür */
    background: #ddd6fe;
    color: #7c3aed;
}

.role-badge.role-3 { /* Müdür Yardımcısı */
    background: #fce7f3;
    color: #ec4899;
}

.role-badge.role-4 { /* Ekip Lideri */
    background: #e0e7ff;
    color: #3730a3;
}

.role-badge.role-5 { /* Temsilci */
    background: #e0e7ff;
    color: #4338ca;
}

/* Card Body */
.personnel-card-modern .card-body {
    padding: 20px;
}

.personnel-name {
    font-size: 18px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 5px 0;
}

.personnel-title {
    font-size: 14px;
    color: #64748b;
    margin: 0 0 15px 0;
}

.team-info {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 15px;
    padding: 8px 12px;
    background: #f1f5f9;
    border-radius: 8px;
    font-size: 14px;
}

.team-info i {
    color: #667eea;
}

.team-info small {
    color: #64748b;
}

.contact-info {
    margin-bottom: 20px;
}

.contact-item {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
    font-size: 14px;
    color: #475569;
}

.contact-item i {
    color: #94a3b8;
    width: 16px;
}

.stats-row {
    display: flex;
    gap: 20px;
    padding-top: 15px;
    border-top: 1px solid #e2e8f0;
}

.stat-item {
    text-align: center;
    flex: 1;
}

.stat-value {
    display: block;
    font-size: 20px;
    font-weight: 700;
    color: #1e293b;
}

.stat-label {
    display: block;
    font-size: 12px;
    color: #64748b;
    margin-top: 2px;
}

/* Card Footer */
.personnel-card-modern .card-footer {
    padding: 15px 20px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
}

.action-buttons {
    display: flex;
    gap: 10px;
    justify-content: center;
}

.btn-action {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
}

.btn-view {
    background: #e0e7ff;
    color: #4338ca;
}

.btn-view:hover {
    background: #4338ca;
    color: white;
}

.btn-edit {
    background: #fef3c7;
    color: #d97706;
}

.btn-edit:hover {
    background: #d97706;
    color: white;
}

.btn-deactivate {
    background: #fee2e2;
    color: #dc2626;
}

.btn-deactivate:hover {
    background: #dc2626;
    color: white;
}

.btn-activate {
    background: #d1fae5;
    color: #065f46;
}

.btn-activate:hover {
    background: #065f46;
    color: white;
}

.btn-delete {
    background: #fecaca;
    color: #dc2626;
}

.btn-delete:hover {
    background: #dc2626;
    color: white;
}

.btn-restore {
    background: #dbeafe;
    color: #2563eb;
}

.btn-restore:hover {
    background: #2563eb;
    color: white;
}

/* Modern Table */
.modern-table {
    width: 100%;
    background: white;
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
}

.modern-table thead {
    background: #f8fafc;
}

.modern-table th {
    padding: 15px 20px;
    text-align: left;
    font-weight: 600;
    color: #475569;
    font-size: 14px;
    border-bottom: 2px solid #e2e8f0;
}

.modern-table td {
    padding: 15px 20px;
    border-bottom: 1px solid #f1f5f9;
}

.modern-table tbody tr:hover {
    background: #f8fafc;
}

.user-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-avatar, .user-avatar-placeholder {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
}

.user-avatar-placeholder {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 14px;
}

.user-info {
    display: flex;
    flex-direction: column;
}

.user-name {
    font-weight: 600;
    color: #1e293b;
    text-decoration: none;
}

.user-name:hover {
    color: #667eea;
}

.user-title {
    font-size: 13px;
    color: #64748b;
}

.role-badge-inline {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: 600;
}

.contact-cell {
    font-size: 13px;
    color: #475569;
}

.contact-cell i {
    color: #94a3b8;
    margin-right: 5px;
}

.team-cell {
    display: flex;
    flex-direction: column;
}

.team-name {
    font-weight: 600;
    color: #1e293b;
}

.team-role {
    font-size: 12px;
    color: #64748b;
}

.stats-cell {
    display: flex;
    gap: 10px;
}

.stat-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 8px;
    background: #f1f5f9;
    border-radius: 6px;
    font-size: 13px;
    color: #475569;
}

.stat-badge i {
    font-size: 12px;
    color: #94a3b8;
}

.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: 600;
}

.status-badge.active {
    background: #d1fae5;
    color: #065f46;
}

.status-badge.inactive {
    background: #fee2e2;
    color: #dc2626;
}

.status-badge.deleted {
    background: #f3f4f6;
    color: #6b7280;
}

.table-actions {
    display: flex;
    gap: 8px;
}

.btn-table-action {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    background: white;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
}

.btn-table-action:hover {
    background: #667eea;
    color: white;
    border-color: #667eea;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
}

.empty-state i {
    font-size: 64px;
    color: #cbd5e1;
    margin-bottom: 20px;
}

.empty-state h3 {
    font-size: 24px;
    color: #1e293b;
    margin: 0 0 10px 0;
}

.empty-state p {
    color: #64748b;
    margin: 0 0 30px 0;
}

/* Responsive */
@media (max-width: 1200px) {
    .personnel-grid-modern {
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    }
}

@media (max-width: 768px) {
    .header-main {
        padding: 25px;
    }
    
    .header-subtitle-section {
        padding: 0 25px 25px 25px;
    }
    
    .header-content {
        flex-direction: column;
        align-items: flex-start;
        gap: 20px;
    }
    
    .header-buttons {
        align-items: flex-start;
        width: 100%;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .filter-row {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-section {
        flex-direction: column;
        gap: 20px;
    }
    
    .personnel-grid-modern {
        grid-template-columns: 1fr;
    }
    
    .modern-table {
        font-size: 14px;
    }
    
    .modern-table th, 
    .modern-table td {
        padding: 10px;
    }
    
    .contact-cell div:last-child {
        display: none;
    }
}

@media (max-width: 480px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .header-main {
        padding: 20px;
    }
    
    .header-subtitle-section {
        padding: 0 20px 20px 20px;
    }
    
    .header-left h1 {
        font-size: 24px;
    }
    
    .header-subtitle {
        font-size: 14px;
    }
}
</style>

<!-- WordPress AJAX değişkenlerini tanımla -->
<script>
window.insurance_crm_ajax = {
    ajaxurl: '<?php echo admin_url('admin-ajax.php'); ?>',
    nonce: '<?php echo wp_create_nonce('insurance_crm_ajax'); ?>'
};

</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // View Toggle
    const viewButtons = document.querySelectorAll('.view-btn');
    const gridView = document.getElementById('personnelGrid');
    const listView = document.getElementById('personnelList');
    
    viewButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            viewButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            const view = this.getAttribute('data-view');
            
            if (view === 'grid') {
                gridView.style.display = 'grid';
                listView.style.display = 'none';
            } else {
                gridView.style.display = 'none';
                listView.style.display = 'block';
            }
        });
    });
    
    // Status Toggle
    document.querySelectorAll('.status-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const status = this.getAttribute('data-status');
            const message = status === 'inactive' ? 
                'Bu personeli pasife almak istediğinizden emin misiniz?' : 
                'Bu personeli aktif etmek istediğinizden emin misiniz?';
            
            if (confirm(message)) {
                // FormData kullan
                const formData = new FormData();
                formData.append('action', 'toggle_representative_status');
                formData.append('id', id);
                formData.append('status', status);
                formData.append('_wpnonce', window.insurance_crm_ajax.nonce);
                
                fetch(window.insurance_crm_ajax.ajaxurl, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text()) // önce text al
                .then(text => {
                    console.log('Response:', text); // debug için
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('JSON Parse Error:', e);
                        throw new Error('Geçersiz JSON yanıtı');
                    }
                })
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Hata: ' + (data.data ? data.data.message : 'Bilinmeyen hata'));
                    }
                })
                .catch(error => {
                    console.error('AJAX Error:', error);
                    alert('Bağlantı hatası oluştu: ' + error.message);
                });
            }
        });
    });
});
</script>

<!-- CSS for checkbox -->
<style>
.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-size: 14px;
    color: #374151;
    padding: 8px 0;
}

.checkbox-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: #667eea;
    cursor: pointer;
}

.checkbox-text {
    user-select: none;
}

.checkbox-label:hover .checkbox-text {
    color: #667eea;
}
</style>

<?php ?>
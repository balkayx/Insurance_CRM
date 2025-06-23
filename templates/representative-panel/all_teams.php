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
 * Pagename : all_teams.php
 * Page Version: 1.0.1
 * Author:      Mehmet BALKAY | Anadolu Birlik
 * Author URI:  https://www.balkay.net
 */

/**
 * Tüm Ekipler Görünümü - Modern Tasarım
 * 
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/templates/representative-panel
 * @author     Anadolu Birlik
 * @since      1.0.0
 * @version    1.0.0 (2025-06-01)
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$user_role = get_user_role_in_hierarchy($current_user->ID);

// Sadece patron ve müdür tüm ekipleri görebilir
if (!is_patron($current_user->ID) && !is_manager($current_user->ID)) {
    wp_die('Bu sayfaya erişim yetkiniz bulunmuyor.');
}

// Ekip silme işlemi (Soft Delete)
$success_message = '';
$error_message = '';

if (isset($_POST['delete_team']) && isset($_POST['team_id']) && isset($_POST['team_nonce'])) {
    $team_id = sanitize_text_field($_POST['team_id']);
    
    if (wp_verify_nonce($_POST['team_nonce'], 'delete_team_' . $team_id)) {
        $settings = get_option('insurance_crm_settings', array());
        
        if (isset($settings['teams_settings']['teams'][$team_id])) {
            $team = $settings['teams_settings']['teams'][$team_id];
            
            // Ekipte üye var mı kontrol et
            if (!empty($team['members'])) {
                $error_message = 'Ekipte üye bulunduğu için ekip silinemez. Önce tüm üyeleri ekipten çıkarın.';
            } else {
                // Get team leader ID before deletion
                $team_leader_id = $team['leader_id'];
                
                // Soft delete işlemi
                $settings['teams_settings']['teams'][$team_id]['status'] = 'deleted';
                $settings['teams_settings']['teams'][$team_id]['deleted_at'] = current_time('mysql');
                $settings['teams_settings']['teams'][$team_id]['deleted_by'] = $current_user->ID;
                
                update_option('insurance_crm_settings', $settings);
                
                // Clear team leader role - set back to regular representative
                if ($team_leader_id > 0) {
                    $table_reps = $wpdb->prefix . 'insurance_crm_representatives';
                    $wpdb->update(
                        $table_reps,
                        array(
                            'role' => 5, // Müşteri Temsilcisi rolü
                            'updated_at' => current_time('mysql')
                        ),
                        array('id' => $team_leader_id)
                    );
                }
                
                $success_message = 'Ekip başarıyla silindi ve ekip lideri rolü temizlendi.';
                
                // Aktivite logu ekle
                $table_logs = $wpdb->prefix . 'insurance_crm_activity_logs';
                $wpdb->insert(
                    $table_logs,
                    array(
                        'user_id' => $current_user->ID,
                        'action_type' => 'team_delete',
                        'item_type' => 'team',
                        'item_id' => 0, // Teams don't have numeric IDs
                        'details' => 'Ekip silindi: ' . $team['name'],
                        'created_at' => current_time('mysql')
                    )
                );
            }
        } else {
            $error_message = 'Silinecek ekip bulunamadı.';
        }
    } else {
        $error_message = 'Güvenlik doğrulaması başarısız.';
    }
}

// Mevcut temsilcileri al
$table_reps = $wpdb->prefix . 'insurance_crm_representatives';
$representatives = $wpdb->get_results(
    "SELECT r.*, u.display_name, u.user_email 
     FROM {$table_reps} r 
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
     WHERE r.status = 'active'
     ORDER BY r.role ASC, u.display_name ASC"
);

// Mevcut ekipler - sadece aktif olanları al
$settings = get_option('insurance_crm_settings', array());
$all_teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();

// Soft delete filtresi - sadece aktif ekipleri göster
$teams = array();
foreach ($all_teams as $team_id => $team) {
    if (!isset($team['status']) || $team['status'] !== 'deleted') {
        $teams[$team_id] = $team;
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

// Filtreler
$search_filter = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$leader_filter = isset($_GET['leader']) ? sanitize_text_field($_GET['leader']) : 'all';

// İstatistikler
$total_teams = count($teams);
$total_leaders = count(array_filter($representatives, function($rep) { return $rep->role == 4; }));
$total_members = count(array_filter($representatives, function($rep) { return $rep->role == 5; }));

// Müsait personel hesapla
$available_personnel = 0;
foreach ($representatives as $rep) {
    $is_in_team = false;
    foreach ($teams as $team) {
        if ($team['leader_id'] == $rep->id || in_array($rep->id, $team['members'])) {
            $is_in_team = true;
            break;
        }
    }
    if (!$is_in_team && $rep->role >= 3) {
        $available_personnel++;
    }
}

// Temsilci bilgilerini harita haline getir
$representatives_map = array();
foreach ($representatives as $rep) {
    $representatives_map[$rep->id] = $rep;
}
?>

<div class="modern-teams-container">
    <!-- Header Section -->
    <div class="page-header-modern">
        <div class="header-main">
            <div class="header-content">
                <div class="header-left">
                    <h1><i class="fas fa-users-cog"></i> Ekip Yönetimi</h1>
                </div>
                <div class="header-right">
                    <div class="header-buttons">
                        <?php if (is_patron($current_user->ID) || is_manager($current_user->ID)): ?>
                        <a href="<?php echo generate_panel_url('team_add'); ?>" class="btn-modern btn-primary">
                            <i class="fas fa-plus"></i> Yeni Ekip Oluştur
                        </a>
                        <?php endif; ?>
                        <a href="<?php echo generate_panel_url('all_personnel'); ?>" class="btn-modern btn-secondary">
                            <i class="fas fa-users"></i> Personel Listesi
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <div class="header-subtitle-section">
            <p class="header-subtitle">Tüm ekipleri görüntüleyin ve yönetin</p>
        </div>
    </div>
    
    <!-- Success/Error Messages -->
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo esc_html($success_message); ?>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-triangle"></i> <?php echo esc_html($error_message); ?>
        </div>
    <?php endif; ?>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <i class="fas fa-project-diagram"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo $total_teams; ?></h3>
                <p>Toplam Ekip</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #5ee7df 0%, #66a6ff 100%);">
                <i class="fas fa-user-tie"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo $total_leaders; ?></h3>
                <p>Ekip Lideri</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo $total_members; ?></h3>
                <p>Ekip Üyesi</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                <i class="fas fa-user-plus"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo $available_personnel; ?></h3>
                <p>Müsait Personel</p>
            </div>
        </div>
    </div>
    
    <!-- Filter Section -->
    <div class="filter-section">
        <form class="modern-filter-form" method="get" action="<?php echo generate_panel_url('all_teams'); ?>">
            <input type="hidden" name="view" value="all_teams">
            
            <div class="filter-row">
                <div class="filter-group">
                    <input type="text" name="search" placeholder="Ekip adı ara..." 
                           value="<?php echo esc_attr($search_filter); ?>" class="modern-search-input">
                </div>
                
                <div class="filter-group">
                    <select name="leader" class="modern-select">
                        <option value="all" <?php selected($leader_filter, 'all'); ?>>Tüm Liderler</option>
                        <?php foreach ($representatives as $rep): 
                            if ($rep->role != 4) continue; // Sadece ekip liderleri ?>
                        <option value="<?php echo $rep->id; ?>" <?php selected($leader_filter, (string)$rep->id); ?>>
                            <?php echo esc_html($rep->display_name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn-modern btn-filter">
                        <i class="fas fa-filter"></i> Filtrele
                    </button>
                    <a href="<?php echo generate_panel_url('all_teams'); ?>" class="btn-modern btn-reset">
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
    
    <!-- Teams Grid -->
    <div class="personnel-grid-modern" id="teamsGrid">
        <?php 
        $filtered_teams = array();
        
        foreach ($teams as $team_id => $team):
            // Arama filtresi
            if (!empty($search_filter)) {
                if (stripos($team['name'], $search_filter) === false) {
                    continue;
                }
            }
            
            // Lider filtresi
            if ($leader_filter !== 'all' && (string)$team['leader_id'] !== $leader_filter) {
                continue;
            }
            
            $filtered_teams[$team_id] = $team;
        endforeach;
        
        if (empty($filtered_teams)): ?>
            <div class="empty-state">
                <i class="fas fa-users-cog"></i>
                <h3>Ekip Bulunamadı</h3>
                <p>Filtreleme kriterlerinize uygun ekip bulunamadı.</p>
                <a href="<?php echo generate_panel_url('team_add'); ?>" class="btn-modern btn-primary">
                    <i class="fas fa-plus"></i> Yeni Ekip Oluştur
                </a>
            </div>
        <?php else:
            foreach ($filtered_teams as $team_id => $team):
                // Ekip lideri bilgilerini al
                $leader_name = '(Lider tanımlanmamış)';
                $leader_role = '';
                $leader_email = '';
                $leader_avatar = '';
                $member_count = count($team['members']);
                
                if (isset($representatives_map[$team['leader_id']])) {
                    $leader = $representatives_map[$team['leader_id']];
                    $leader_name = $leader->display_name;
                    $leader_email = $leader->user_email;
                    $leader_role = isset($role_map[$leader->role]) ? $role_map[$leader->role] : $leader->title;
                    $leader_avatar = !empty($leader->avatar_url) ? $leader->avatar_url : '';
                }
                
                // Ekip üyelerinin bilgilerini al
                $team_members_info = array();
                foreach ($team['members'] as $member_id) {
                    if (isset($representatives_map[$member_id])) {
                        $team_members_info[] = $representatives_map[$member_id];
                    }
                }
        ?>
            <div class="personnel-card-modern">
                <div class="card-header">
                    <div class="avatar-section">
                        <?php if (!empty($leader_avatar)): ?>
                            <img src="<?php echo esc_url($leader_avatar); ?>" alt="<?php echo esc_attr($leader_name); ?>" class="avatar">
                        <?php else: ?>
                            <div class="avatar-placeholder">
                                <?php echo esc_html(strtoupper(substr($leader_name, 0, 2))); ?>
                            </div>
                        <?php endif; ?>
                        <span class="status-indicator active"></span>
                    </div>
                    
                    <div class="role-badge role-4">
                        Ekip Lideri
                    </div>
                </div>
                
                <div class="card-body">
                    <h3 class="personnel-name"><?php echo esc_html($team['name']); ?></h3>
                    <p class="personnel-title"><?php echo esc_html($leader_name); ?></p>
                    
                    <div class="team-info">
                        <i class="fas fa-users"></i>
                        <span><?php echo $member_count + 1; ?> Üye</span>
                        <small>(Lider + <?php echo $member_count; ?> Üye)</small>
                    </div>
                    
                    <div class="contact-info">
                        <div class="contact-item">
                            <i class="fas fa-envelope"></i>
                            <span><?php echo esc_html($leader_email); ?></span>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-user-tie"></i>
                            <span><?php echo esc_html($leader_role); ?></span>
                        </div>
                    </div>
                    
                    <?php if (!empty($team_members_info)): ?>
                    <div class="team-members-preview">
                        <div class="members-avatars">
                            <?php foreach (array_slice($team_members_info, 0, 4) as $member): ?>
                                <div class="member-avatar-small" title="<?php echo esc_attr($member->display_name); ?>">
                                    <?php if (!empty($member->avatar_url)): ?>
                                        <img src="<?php echo esc_url($member->avatar_url); ?>" alt="<?php echo esc_attr($member->display_name); ?>">
                                    <?php else: ?>
                                        <div class="avatar-placeholder-small">
                                            <?php echo esc_html(strtoupper(substr($member->display_name, 0, 1))); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (count($team_members_info) > 4): ?>
                                <div class="member-avatar-small more-members">
                                    <span>+<?php echo count($team_members_info) - 4; ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="stats-row">
                        <div class="stat-item">
                            <span class="stat-value"><?php echo $member_count + 1; ?></span>
                            <span class="stat-label">Toplam</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-value"><?php echo $member_count; ?></span>
                            <span class="stat-label">Üye</span>
                        </div>
                    </div>
                </div>
                
                <div class="card-footer">
                    <div class="action-buttons">
                        <a href="<?php echo generate_panel_url('team_detail', '', '', array('team_id' => $team_id)); ?>" 
                           class="btn-action btn-view" title="Detayları Görüntüle">
                            <i class="fas fa-eye"></i>
                        </a>
                        
                        <?php if (is_patron($current_user->ID) || is_manager($current_user->ID)): ?>
                        <a href="<?php echo generate_panel_url('edit_team', '', '', array('team_id' => $team_id)); ?>" 
                           class="btn-action btn-edit" title="Düzenle">
                            <i class="fas fa-edit"></i>
                        </a>
                        <?php endif; ?>
                        
                        <?php if ((is_patron($current_user->ID) || is_manager($current_user->ID)) && empty($team['members'])): ?>
                        <button type="button" class="btn-action btn-delete" 
                                onclick="confirmDeleteTeam('<?php echo esc_js($team_id); ?>', '<?php echo esc_js($team['name']); ?>')" 
                                data-nonce="<?php echo wp_create_nonce('delete_team_' . $team_id); ?>"
                                title="Ekibi Sil">
                            <i class="fas fa-trash"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php 
            endforeach;
        endif; 
        ?>
    </div>
    
    <!-- Teams List (Hidden by default) -->
    <div class="teams-list-modern" id="teamsList" style="display: none;">
        <table class="modern-table">
            <thead>
                <tr>
                    <th>Ekip Adı</th>
                    <th>Lider</th>
                    <th>Üye Sayısı</th>
                    <th>Durum</th>
                    <th>İşlemler</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filtered_teams as $team_id => $team):
                    $leader_name = '(Lider tanımlanmamış)';
                    $leader_role = '';
                    
                    if (isset($representatives_map[$team['leader_id']])) {
                        $leader = $representatives_map[$team['leader_id']];
                        $leader_name = $leader->display_name;
                        $leader_role = isset($role_map[$leader->role]) ? $role_map[$leader->role] : $leader->title;
                    }
                    
                    $member_count = count($team['members']);
                ?>
                    <tr>
                        <td>
                            <div class="table-team-info">
                                <strong><?php echo esc_html($team['name']); ?></strong>
                            </div>
                        </td>
                        <td>
                            <div class="table-leader-info">
                                <strong><?php echo esc_html($leader_name); ?></strong>
                                <small><?php echo esc_html($leader_role); ?></small>
                            </div>
                        </td>
                        <td>
                            <span class="member-count-badge">
                                <?php echo $member_count + 1; ?> üye
                            </span>
                        </td>
                        <td>
                            <span class="status-badge active">Aktif</span>
                        </td>
                        <td>
                            <div class="table-actions">
                                <a href="<?php echo generate_panel_url('team_detail', '', '', array('team_id' => $team_id)); ?>" 
                                   class="btn-action btn-view" title="Detayları Görüntüle">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if (is_patron($current_user->ID) || is_manager($current_user->ID)): ?>
                                <a href="<?php echo generate_panel_url('edit_team', '', '', array('team_id' => $team_id)); ?>" 
                                   class="btn-action btn-edit" title="Düzenle">
                                    <i class="fas fa-edit"></i>
                                </a>
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
/* Modern Teams Container */
.modern-teams-container {
    padding: 30px;
    background-color: #f8f9fa;
    min-height: 100vh;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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
    align-items: center;
}

.header-left h1 {
    font-size: 32px;
    font-weight: 700;
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
    font-size: 18px;
    opacity: 0.9;
    margin: 0;
    line-height: 1.4;
}

.header-buttons {
    display: flex;
    gap: 15px;
    align-items: center;
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
    background: rgba(255, 255, 255, 0.2);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.3);
}

.btn-secondary:hover {
    background: rgba(255, 255, 255, 0.3);
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
    flex-wrap: wrap;
    gap: 20px;
}

.modern-filter-form {
    flex: 1;
}

.filter-row {
    display: flex;
    gap: 20px;
    align-items: center;
    flex-wrap: wrap;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.modern-search-input, .modern-select {
    padding: 12px 16px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 14px;
    transition: all 0.3s ease;
    background-color: white;
    min-width: 200px;
}

.modern-search-input:focus, .modern-select:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.filter-actions {
    display: flex;
    gap: 10px;
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

.view-options {
    display: flex;
    gap: 10px;
}

.view-btn {
    width: 40px;
    height: 40px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    background: white;
    color: #64748b;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
}

.view-btn.active {
    border-color: #667eea;
    background: #667eea;
    color: white;
}

.view-btn:hover {
    border-color: #667eea;
    color: #667eea;
}

.view-btn.active:hover {
    color: white;
}

/* Teams Grid */
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

/* Role Badges */
.role-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.role-badge.role-4 { /* Ekip Lideri */
    background: #e0e7ff;
    color: #3730a3;
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

.team-members-preview {
    margin-bottom: 15px;
}

.members-avatars {
    display: flex;
    gap: 8px;
    align-items: center;
}

.member-avatar-small {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    overflow: hidden;
    border: 2px solid white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.member-avatar-small img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.avatar-placeholder-small {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 12px;
}

.more-members {
    background: #e2e8f0;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 600;
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

.btn-delete {
    background: #fee2e2;
    color: #dc2626;
}

.btn-delete:hover {
    background: #dc2626;
    color: white;
}

/* Legacy Teams Grid (kept for backward compatibility but hidden) */
.teams-grid-modern {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 25px;
}



/* Teams List */
.teams-list-modern {
    background: white;
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
}

.modern-table {
    width: 100%;
    border-collapse: collapse;
}

.modern-table th {
    background: #f8fafc;
    padding: 15px 20px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 1px solid #e2e8f0;
}

.modern-table td {
    padding: 15px 20px;
    border-bottom: 1px solid #e2e8f0;
}

.table-team-info strong {
    color: #1e293b;
    font-size: 16px;
}

.table-leader-info strong {
    display: block;
    color: #1e293b;
    margin-bottom: 2px;
}

.table-leader-info small {
    color: #64748b;
    font-size: 12px;
}

.member-count-badge {
    background: #e0e7ff;
    color: #4338ca;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-badge.active {
    background: #dcfce7;
    color: #166534;
}

.table-actions {
    display: flex;
    gap: 8px;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #64748b;
    grid-column: 1 / -1;
}

.empty-state i {
    font-size: 64px;
    margin-bottom: 20px;
    opacity: 0.5;
}

.empty-state h3 {
    font-size: 24px;
    margin: 0 0 10px 0;
    color: #1e293b;
}

.empty-state p {
    font-size: 16px;
    margin: 0 0 30px 0;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .personnel-grid-modern {
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    }
}

@media (max-width: 768px) {
    .modern-teams-container {
        padding: 20px;
    }
    
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
        padding: 12px 15px;
    }
}

@media (max-width: 480px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .filter-row {
        flex-direction: column;
        align-items: stretch;
    }
    
    .modern-search-input, .modern-select {
        min-width: auto;
        width: 100%;
    }
    
    .filter-actions {
        flex-direction: column;
    }
    
    .team-leader-info {
        flex-direction: column;
        align-items: flex-start;
        text-align: center;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // View toggle functionality
    const viewButtons = document.querySelectorAll('.view-btn');
    const teamsGrid = document.getElementById('teamsGrid');
    const teamsList = document.getElementById('teamsList');
    
    viewButtons.forEach(button => {
        button.addEventListener('click', function() {
            const view = this.dataset.view;
            
            // Update active button
            viewButtons.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            
            // Toggle views
            if (view === 'grid') {
                teamsGrid.style.display = 'grid';
                teamsList.style.display = 'none';
            } else {
                teamsGrid.style.display = 'none';
                teamsList.style.display = 'block';
            }
        });
    });
    
    // Hover effects for team cards
    const teamCards = document.querySelectorAll('.team-card-modern');
    teamCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
});

// Ekip silme onayı
function confirmDeleteTeam(teamId, teamName) {
    if (confirm('"' + teamName + '" adlı ekibi kalıcı olarak silmek istediğinizden emin misiniz?\n\nBu işlem geri alınamaz.')) {
        // Nonce değerini button'dan al
        const deleteButton = document.querySelector(`button[onclick*="${teamId}"]`);
        const nonce = deleteButton ? deleteButton.getAttribute('data-nonce') : '';
        
        // Gizli form oluştur ve gönder
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        const teamIdInput = document.createElement('input');
        teamIdInput.type = 'hidden';
        teamIdInput.name = 'team_id';
        teamIdInput.value = teamId;
        
        const deleteInput = document.createElement('input');
        deleteInput.type = 'hidden';
        deleteInput.name = 'delete_team';
        deleteInput.value = '1';
        
        const nonceInput = document.createElement('input');
        nonceInput.type = 'hidden';
        nonceInput.name = 'team_nonce';
        nonceInput.value = nonce;
        
        form.appendChild(teamIdInput);
        form.appendChild(deleteInput);
        form.appendChild(nonceInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<!-- CSS for alert messages and delete button -->
<style>
.alert {
    padding: 15px;
    margin: 20px 0;
    border-radius: 10px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 500;
}

.alert-success {
    background: #d1f2eb;
    color: #0d5540;
    border: 1px solid #a7f3d0;
}

.alert-error {
    background: #fee2e2;
    color: #7f1d1d;
    border: 1px solid #fca5a5;
}

.btn-delete {
    background: #ef4444;
    color: white;
}

.btn-delete:hover {
    background: #dc2626;
    transform: scale(1.05);
}
</style>
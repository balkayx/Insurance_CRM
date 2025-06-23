<?php
/**
 * Yeni Ekip Ekleme Sayfası
 * 
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/templates/representative-panel
 * @author     Anadolu Birlik
 * @since      1.0.0
 * @version    1.0.1 (2025-05-28)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/temsilci-girisi/'));
    exit;
}

$current_user = wp_get_current_user();
global $wpdb;

// Yetki kontrolü - sadece patron ve müdür yeni ekip ekleyebilir
if (!is_patron($current_user->ID) && !is_manager($current_user->ID)) {
    wp_die('Bu sayfaya erişim yetkiniz bulunmuyor.');
}

// Mevcut temsilcileri ve ekipleri al
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

// Soft delete filtresi - sadece aktif ekipleri kontrol et
$teams = array();
foreach ($all_teams as $team_id => $team) {
    if (!isset($team['status']) || $team['status'] !== 'deleted') {
        $teams[$team_id] = $team;
    }
}

// Form gönderildiğinde ekip oluştur
$error_messages = array();
$success_message = '';

if (isset($_POST['submit_team']) && isset($_POST['team_nonce']) && 
    wp_verify_nonce($_POST['team_nonce'], 'add_team')) {
    
    // Form verilerini al
    $team_name = sanitize_text_field($_POST['team_name']);
    $team_leader_id = intval($_POST['team_leader_id']);
    
    // Validasyon
    if (empty($team_name)) {
        $error_messages[] = 'Ekip adı gereklidir.';
    }
    
    if ($team_leader_id <= 0) {
        $error_messages[] = 'Ekip lideri seçilmelidir.';
    }
    
    // Aynı isimde başka bir ekip var mı kontrol et
    foreach ($teams as $team) {
        if (strtolower($team['name']) === strtolower($team_name)) {
            $error_messages[] = 'Bu isimde bir ekip zaten mevcut.';
            break;
        }
    }
    
    // Allow same representative to lead multiple teams - removed restriction
    
    // Hata yoksa ekibi kaydet
    if (empty($error_messages)) {
        // Benzersiz ekip ID'si oluştur
        $team_id = 'team_' . uniqid();
        
        // Ekibi ayarlara ekle
        if (!isset($settings['teams_settings'])) {
            $settings['teams_settings'] = array();
        }
        
        if (!isset($settings['teams_settings']['teams'])) {
            $settings['teams_settings']['teams'] = array();
        }
        
        $settings['teams_settings']['teams'][$team_id] = array(
            'name' => $team_name,
            'leader_id' => $team_leader_id,
            'members' => array() // Start with empty members array
        );
        
        update_option('insurance_crm_settings', $settings);
        
        // Seçilen temsilcinin rolünü ekip lideri olarak güncelle
        if ($team_leader_id > 0) {
            $wpdb->update(
                $table_reps,
                array(
                    'role' => 4, // Ekip Lideri rolü
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $team_leader_id)
            );
        }
        
        // Aktivite logu ekle
        $table_logs = $wpdb->prefix . 'insurance_crm_activity_log';
        $wpdb->insert(
            $table_logs,
            array(
                'user_id' => $current_user->ID,
                'username' => $current_user->display_name,
                'action_type' => 'create',
                'action_details' => json_encode(array(
                    'item_type' => 'team',
                    'item_id' => $team_id,
                    'name' => $team_name,
                    'created_by' => $current_user->display_name
                )),
                'created_at' => current_time('mysql')
            )
        );
        
        // Ekip düzenleme sayfasına yönlendir
        $redirect_url = generate_panel_url('edit_team') . '&team_id=' . urlencode($team_id) . '&tab=members&created=1';
        wp_safe_redirect($redirect_url);
        exit;
    }
}

?>

<!-- Modern Team Add Container -->
<div class="modern-team-container">
    <!-- Modern Header -->
    <div class="page-header-modern">
        <div class="header-main">
            <div class="header-content">
                <div class="header-left">
                    <h1><i class="fas fa-users-cog"></i> Yeni Ekip Oluştur</h1>
                </div>
                <div class="header-right">
                    <a href="<?php echo generate_panel_url('all_teams'); ?>" class="btn-modern btn-primary">
                        <i class="fas fa-users-cog"></i> Tüm Ekipler
                    </a>
                    <a href="<?php echo generate_panel_url('all_personnel'); ?>" class="btn-modern btn-secondary">
                        <i class="fas fa-users"></i> Tüm Personel
                    </a>
                </div>
            </div>
        </div>
        <div class="header-subtitle-section">
            <p class="header-subtitle">Yeni bir çalışma ekibi oluşturun ve ekip liderini belirleyin</p>
        </div>
    </div>
    

    
    <?php if (!empty($error_messages)): ?>
        <div class="modern-message-box error-box">
            <i class="fas fa-exclamation-circle"></i>
            <div class="message-content">
                <h4>Hata</h4>
                <ul>
                    <?php foreach ($error_messages as $error): ?>
                        <li><?php echo esc_html($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($success_message)): ?>
        <div class="modern-message-box success-box">
            <i class="fas fa-check-circle"></i>
            <div class="message-content">
                <h4>Başarılı</h4>
                <p><?php echo esc_html($success_message); ?></p>
                <div class="action-buttons">
                    <a href="<?php echo generate_panel_url('all_teams'); ?>" class="btn-modern btn-primary">
                        <i class="fas fa-users-cog"></i> Tüm Ekipleri Görüntüle
                    </a>
                    <a href="<?php echo generate_panel_url('team_add'); ?>" class="btn-modern btn-secondary">
                        <i class="fas fa-plus"></i> Başka Ekip Ekle
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Modern Team Creation Form -->
    <div class="modern-form-section">
        <form method="post" action="" class="modern-team-form">
            <?php wp_nonce_field('add_team', 'team_nonce'); ?>
            
            <div class="modern-form-card">
                <div class="form-card-header">
                    <h2><i class="fas fa-users"></i> Yeni Ekip Bilgileri</h2>
                </div>
                <div class="form-card-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="team_name">Ekip Adı <span class="required">*</span></label>
                            <input type="text" name="team_name" id="team_name" class="modern-form-control" required>
                            <div class="form-hint">Örnek: Satış Ekibi, Müşteri İlişkileri Ekibi</div>
                        </div>
                        
                        <div class="form-group col-md-6">
                            <label for="team_leader_id">Ekip Lideri <span class="required">*</span></label>
                            <select name="team_leader_id" id="team_leader_id" class="modern-form-control" required>
                                <option value="">-- Ekip Lideri Seçin --</option>
                            <?php
                            foreach ($representatives as $rep):
                                // Patron ve Müdür rolleri ekip lideri olamaz
                                if ($rep->role == 1 || $rep->role == 2) {
                                    continue;
                                }
                            ?>
                                <option value="<?php echo $rep->id; ?>">
                                    <?php 
                                        echo esc_html($rep->display_name);
                                        if (!empty($rep->title)) {
                                            echo ' (' . esc_html($rep->title) . ')';
                                        }
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-hint">Ekip üyelerini eklemek için, ekip oluşturduktan sonra düzenleme sayfasına yönlendirileceksiniz.</div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="modern-form-actions">
            <button type="submit" name="submit_team" class="btn-modern btn-save">
                <i class="fas fa-save"></i> Ekibi Oluştur
            </button>
            <a href="<?php echo generate_panel_url('all_teams'); ?>" class="btn-modern btn-cancel">
                <i class="fas fa-times"></i> İptal
            </a>
        </div>
    </form>
    </div>
</div>

<style>
/* Modern Team Container */
.modern-team-container {
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
    background: #e2e8f0;
    color: #64748b;
}

.btn-secondary:hover {
    background: #cbd5e1;
}

.btn-save {
    background: #667eea;
    color: white;
}

.btn-save:hover {
    background: #5a67d8;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
}

.btn-cancel {
    background: #e2e8f0;
    color: #64748b;
}

.btn-cancel:hover {
    background: #cbd5e1;
}

/* Statistics Grid - Removed, moved to all_teams.php */

/* Modern Message Boxes */
.modern-message-box {
    display: flex;
    align-items: flex-start;
    padding: 20px;
    margin-bottom: 25px;
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    animation: fadeIn 0.3s ease;
}

.modern-message-box.error-box {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #991b1b;
}

.modern-message-box.success-box {
    background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
    color: #166534;
}

.modern-message-box i {
    margin-right: 15px;
    font-size: 20px;
    margin-top: 2px;
}

.message-content h4 {
    margin: 0 0 10px 0;
    font-size: 16px;
    font-weight: 600;
}

.message-content ul {
    margin: 0;
    padding-left: 20px;
}

.message-content p {
    margin: 0;
}

.action-buttons {
    margin-top: 15px;
    display: flex;
    gap: 10px;
}

/* Modern Teams Section - Removed, moved to all_teams.php */

/* Modern Form */
.modern-form-section {
    background: white;
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    overflow: hidden;
}

.modern-form-card {
    padding: 30px;
}

.form-card-header {
    margin-bottom: 25px;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 15px;
}

.form-card-header h2 {
    margin: 0;
    font-size: 24px;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 700;
}

.form-card-body {
    background: #f8fafc;
    border-radius: 10px;
    padding: 25px;
}

/* Form Styling */
.form-group {
    margin-bottom: 25px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #374151;
    font-size: 14px;
}

.required {
    color: #ef4444;
}

.modern-form-control {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 14px;
    transition: all 0.3s ease;
    background-color: white;
}

.modern-form-control:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.form-hint {
    margin-top: 8px;
    font-size: 13px;
    color: #64748b;
    font-style: italic;
}

/* Form Layout */
.form-row {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
}

.col-md-6 {
    flex: 1;
}

.col-md-12 {
    flex: 1;
}

/* Member Selection - Removed - Members will be added via edit page */

/* Form Actions */
.modern-form-actions {
    padding: 25px 30px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 15px;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Responsive Design */
@media (max-width: 768px) {
    .modern-team-container {
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
        gap: 20px;
        text-align: center;
    }
    
    .header-left h1 {
        font-size: 24px;
    }
    
    .form-row {
        flex-direction: column;
    }
    
    .col-md-6 {
        flex: none;
    }
    
    /* Member selection removed - using edit page for member addition */
    
    .modern-form-actions {
        flex-direction: column;
    }
    
    .modern-form-actions .btn-modern {
        justify-content: center;
    }
}

@media (max-width: 480px) {
    /* Member selection removed - simplified mobile view */
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // DOM elementlerini al
    const teamLeaderSelect = document.getElementById('team_leader_id');
    
    // Ekip lideri değiştiğinde altta bir not göster
    if (teamLeaderSelect) {
        teamLeaderSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                const leaderName = selectedOption.textContent.trim();
                
                // Mevcut bilgi notunu temizle
                const existingInfo = this.parentNode.querySelector('.form-hint.leader-info');
                if (existingInfo) {
                    existingInfo.remove();
                }
                
                // Yeni bilgi notu ekle
                const infoElement = document.createElement('div');
                infoElement.className = 'form-hint leader-info';
                infoElement.innerHTML = `<i class="fas fa-info-circle"></i> <strong>${leaderName}</strong> ekip lideri olarak seçildi ve otomatik olarak rol değişikliği yapılacak.`;
                
                this.parentNode.appendChild(infoElement);
            } else {
                // Lider seçimi temizlendiğinde notu kaldır
                const existingInfo = this.parentNode.querySelector('.form-hint.leader-info');
                if (existingInfo) {
                    existingInfo.remove();
                }
            }
        });
    }
    
    // Form validation
    const teamForm = document.querySelector('.modern-team-form');
    if (teamForm) {
        teamForm.addEventListener('submit', function(e) {
            const teamName = document.getElementById('team_name');
            const teamLeader = document.getElementById('team_leader_id');
            
            let hasErrors = false;
            
            // Team name validation
            if (teamName && teamName.value.trim() === '') {
                alert('Ekip adı gereklidir.');
                teamName.focus();
                hasErrors = true;
            }
            
            // Team leader validation
            if (!hasErrors && teamLeader && teamLeader.value === '') {
                alert('Ekip lideri seçilmelidir.');
                teamLeader.focus();
                hasErrors = true;
            }
            
            if (hasErrors) {
                e.preventDefault();
                return false;
            }
        });
    }
});
</script>
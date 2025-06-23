<?php
/**
 * Görev Detay Sayfası
 * @version 3.1.0
 * @date 2025-05-30 21:16:11
 * @author anadolubirlik
 * @description Modern Material UI tasarım güncellemesi
 */

// Yetki kontrolü
if (!is_user_logged_in() || !isset($_GET['id'])) {
    return;
}

$task_id = intval($_GET['id']);
global $wpdb;
$tasks_table = $wpdb->prefix . 'insurance_crm_tasks';

// Yönetici kontrolü
$is_admin = current_user_can('administrator') || current_user_can('insurance_manager');

// Temsilci yetkisi kontrolü
$current_user_rep_id = get_current_user_rep_id();
$where_clause = "";
$where_params = array($task_id);

// Temsilcinin rolünü kontrol et
$patron_access = false;
if ($current_user_rep_id) {
    $rep_role = $wpdb->get_var($wpdb->prepare(
        "SELECT role FROM {$wpdb->prefix}insurance_crm_representatives WHERE id = %d",
        $current_user_rep_id
    ));
    
    // Eğer role 1 (Patron) veya 2 (Müdür) ise, tüm verilere erişim sağla
    if ($rep_role == 1 || $rep_role == 2) {
        $patron_access = true;
    }
}

if (!$is_admin && !$patron_access && $current_user_rep_id) {
    $where_clause = " AND t.representative_id = %d";
    $where_params[] = $current_user_rep_id;
}

// Görev bilgilerini al
$task = $wpdb->get_row($wpdb->prepare("
    SELECT t.*,
           c.first_name, c.last_name,
           p.policy_number, p.policy_type, p.insurance_company,
           u.display_name AS rep_name
    FROM $tasks_table t
    LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON t.customer_id = c.id
    LEFT JOIN {$wpdb->prefix}insurance_crm_policies p ON t.policy_id = p.id
    LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON t.representative_id = r.id
    LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
    WHERE t.id = %d
    {$where_clause}
", $where_params));

if (!$task) {
    echo '<div class="notification-banner notification-error">
        <div class="notification-icon">
            <i class="fas fa-exclamation-circle"></i>
        </div>
        <div class="notification-content">
            Görev bulunamadı veya görüntüleme yetkiniz yok.
        </div>
    </div>';
    return;
}

// Görev son tarih kontrolü
$current_date = date('Y-m-d H:i:s');
$is_overdue = strtotime($task->due_date) < strtotime($current_date) && $task->status !== 'completed' && $task->status !== 'cancelled';

// Görev içeriğini doğru şekilde biçimlendir
$task_description_formatted = nl2br(esc_html($task->task_description));

// Öncelik ve durum bilgilerini oluştur
function get_task_priority_text($priority) {
    switch ($priority) {
        case 'low': return 'Düşük Öncelik';
        case 'medium': return 'Orta Öncelik';
        case 'high': return 'Yüksek Öncelik';
        case 'urgent': return 'Acil Öncelik';
        default: return ucfirst($priority) . ' Öncelik';
    }
}

function get_task_status_text($status) {
    switch ($status) {
        case 'pending': return 'Beklemede';
        case 'in_progress': return 'İşlemde';
        case 'completed': return 'Tamamlandı';
        case 'cancelled': return 'İptal Edildi';
        default: return ucfirst($status);
    }
}

function get_status_color($status) {
    switch ($status) {
        case 'pending': return 'var(--primary)';
        case 'in_progress': return 'var(--warning)';
        case 'completed': return 'var(--success)';
        case 'cancelled': return 'var(--outline)';
        default: return 'var(--outline)';
    }
}

function get_priority_color($priority) {
    switch ($priority) {
        case 'low': return 'var(--success)';
        case 'medium': return 'var(--warning)';
        case 'high': return 'var(--danger)';
        case 'urgent': return 'var(--danger)';
        default: return 'var(--outline)';
    }
}

// Gecikme süresi hesaplama
$delay_text = "";
if ($is_overdue) {
    $diff = strtotime($current_date) - strtotime($task->due_date);
    $days = floor($diff / (60 * 60 * 24));
    $hours = floor(($diff - ($days * 60 * 60 * 24)) / (60 * 60));
    $delay_text = ($days > 0) ? "$days gün $hours saat" : "$hours saat";
}

?>

<div class="task-detail-container">
    <div class="task-header">
        <div class="header-content">
            <div class="breadcrumb">
                <a href="?view=tasks"><i class="fas fa-tasks"></i> Görevler</a>
                <i class="fas fa-angle-right"></i>
                <span>Görev Detayı</span>
            </div>
            
            <h1 class="task-title"><?php echo esc_html($task->task_title); ?></h1>
            
            <div class="task-meta">
                <div class="status-badge status-<?php echo esc_attr($task->status); ?>">
                    <?php echo get_task_status_text($task->status); ?>
                </div>
                
                <div class="status-badge priority-<?php echo esc_attr($task->priority); ?>">
                    <?php echo get_task_priority_text($task->priority); ?>
                </div>
                
                <?php if ($is_overdue): ?>
                <div class="status-badge overdue">
                    <i class="fas fa-exclamation-circle"></i> Gecikmiş
                </div>
                <?php endif; ?>
            </div>
        </div>
            
        <div class="task-actions">
            <a href="?view=tasks" class="btn btn-ghost">
                <i class="fas fa-arrow-left"></i>
                <span>Listeye Dön</span>
            </a>
            
            <a href="?view=tasks&action=edit&id=<?php echo $task_id; ?>" class="btn btn-outline">
                <i class="fas fa-edit"></i>
                <span>Düzenle</span>
            </a>
            
            <?php if ($task->status !== 'completed' && ($is_admin || $patron_access || $task->representative_id == $current_user_rep_id)): ?>
            <a href="<?php echo wp_nonce_url('?view=tasks&action=complete&id=' . $task_id, 'complete_task_' . $task_id); ?>" class="btn btn-primary"
                onclick="return confirm('Bu görevi tamamlandı olarak işaretlemek istediğinizden emin misiniz?');">
                <i class="fas fa-check"></i>
                <span>Tamamla</span>
            </a>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Görev İçeriği -->
    <div class="task-content">
        <!-- Ana Bilgiler Kartı -->
        <div class="card details-card">
            <div class="card-header">
                <h2><i class="fas fa-clipboard-list"></i> Görev Detayları</h2>
            </div>
            <div class="card-body">
                <div class="description-container">
                    <h3>Görev Açıklaması</h3>
                    <div class="description-box">
                        <?php echo $task_description_formatted; ?>
                    </div>
                </div>
                
                <div class="info-grid">
                    <div class="info-group">
                        <h3>Müşteri Bilgileri</h3>
                        <div class="info-item">
                            <div class="info-label">Müşteri:</div>
                            <div class="info-value">
                                <a href="?view=customers&action=view&id=<?php echo $task->customer_id; ?>" class="link">
                                    <?php echo esc_html($task->first_name . ' ' . $task->last_name); ?>
                                </a>
                            </div>
                        </div>
                        
                        <?php if (!empty($task->policy_id) && !empty($task->policy_number)): ?>
                        <div class="info-item">
                            <div class="info-label">İlgili Poliçe:</div>
                            <div class="info-value">
                                <a href="?view=policies&action=view&id=<?php echo $task->policy_id; ?>" class="link">
                                    <?php echo esc_html($task->policy_number); ?>
                                </a>
                                <?php if (!empty($task->policy_type)): ?>
                                <span class="policy-type-tag"><?php echo esc_html($task->policy_type); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($task->insurance_company)): ?>
                                <span class="policy-company-tag"><?php echo esc_html($task->insurance_company); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="info-item">
                            <div class="info-label">İlgili Poliçe:</div>
                            <div class="info-value no-value">Poliçe belirtilmemiş</div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="info-group">
                        <h3>Görev Bilgileri</h3>
                        <div class="info-item">
                            <div class="info-label">Son Tarih:</div>
                            <div class="info-value <?php echo $is_overdue ? 'danger-text' : ''; ?>">
                                <?php echo date('d.m.Y H:i', strtotime($task->due_date)); ?>
                                <?php if ($is_overdue): ?>
                                <span class="overdue-tag"><?php echo $delay_text; ?> gecikme</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Sorumlu Temsilci:</div>
                            <div class="info-value">
                                <?php echo !empty($task->rep_name) ? esc_html($task->rep_name) : '<span class="no-value">Atanmamış</span>'; ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Oluşturulma:</div>
                            <div class="info-value">
                                <?php echo date('d.m.Y H:i', strtotime($task->created_at)); ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Son Güncelleme:</div>
                            <div class="info-value">
                                <?php echo date('d.m.Y H:i', strtotime($task->updated_at)); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Durum Kartı -->
        <div class="card status-card status-<?php echo esc_attr($task->status); ?>">
            <div class="card-header">
                <h2><i class="fas fa-chart-line"></i> Durum</h2>
            </div>
            <div class="card-body">
                <div class="status-info">
                    <?php 
                    switch ($task->status) {
                        case 'pending':
                            $status_icon = 'clock';
                            $status_text = 'Bu görev beklemede. Henüz çalışmaya başlanmadı.';
                            break;
                        case 'in_progress':
                            $status_icon = 'spinner fa-spin';
                            $status_text = 'Bu görev üzerinde şu anda çalışılıyor.';
                            break;
                        case 'completed':
                            $status_icon = 'check-circle';
                            $status_text = 'Bu görev tamamlandı.';
                            break;
                        case 'cancelled':
                            $status_icon = 'ban';
                            $status_text = 'Bu görev iptal edildi.';
                            break;
                        default:
                            $status_icon = 'question-circle';
                            $status_text = 'Bu görevin durumu belirsiz.';
                    }
                    ?>
                    
                    <div class="status-icon">
                        <i class="fas fa-<?php echo $status_icon; ?>"></i>
                    </div>
                    
                    <div class="status-details">
                        <h3><?php echo get_task_status_text($task->status); ?></h3>
                        <p><?php echo $status_text; ?></p>
                        
                        <?php if ($task->status === 'completed'): ?>
                            <p class="completion-date">
                                <i class="fas fa-calendar-check"></i> 
                                Tamamlanma: <?php echo date('d.m.Y H:i', strtotime($task->updated_at)); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($is_overdue && $task->status !== 'completed' && $task->status !== 'cancelled'): ?>
                <div class="overdue-alert">
                    <div class="overdue-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="overdue-details">
                        <h3>Görev Gecikmiş</h3>
                        <p>Bu görevin son tarihi <?php echo date('d.m.Y H:i', strtotime($task->due_date)); ?> idi.</p>
                        <p>Gecikme: <?php echo $delay_text; ?></p>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($task->status !== 'completed' && $task->status !== 'cancelled'): ?>
                <div class="status-actions">
                    <?php if ($is_admin || $patron_access || $task->representative_id == $current_user_rep_id): ?>
                    <a href="<?php echo wp_nonce_url('?view=tasks&action=complete&id=' . $task_id, 'complete_task_' . $task_id); ?>" class="btn btn-primary btn-lg">
                        <i class="fas fa-check"></i> Görevi Tamamla
                    </a>
                    <?php endif; ?>
                    
                    <a href="?view=tasks&action=edit&id=<?php echo $task_id; ?>" class="btn btn-outline btn-lg">
                        <i class="fas fa-cog"></i> Durumu Değiştir
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- İlişkili Bilgiler -->
    <div class="related-section">
        <h2><i class="fas fa-link"></i> İlişkili İçerikler</h2>
        
        <div class="related-cards">
            <div class="related-card">
                <div class="related-icon customer-icon">
                    <i class="fas fa-user"></i>
                </div>
                <div class="related-content">
                    <h3>Müşteri</h3>
                    <p><?php echo esc_html($task->first_name . ' ' . $task->last_name); ?></p>
                    <a href="?view=customers&action=view&id=<?php echo $task->customer_id; ?>" class="btn btn-sm btn-outline">
                        <i class="fas fa-external-link-alt"></i> Müşteri Detayları
                    </a>
                </div>
            </div>
            
            <?php if (!empty($task->policy_id) && !empty($task->policy_number)): ?>
            <div class="related-card">
                <div class="related-icon policy-icon">
                    <i class="fas fa-file-contract"></i>
                </div>
                <div class="related-content">
                    <h3>İlgili Poliçe</h3>
                    <p><?php echo esc_html($task->policy_number); ?></p>
                    <a href="?view=policies&action=view&id=<?php echo $task->policy_id; ?>" class="btn btn-sm btn-outline">
                        <i class="fas fa-external-link-alt"></i> Poliçe Detayları
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="related-card">
                <div class="related-icon task-icon">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="related-content">
                    <h3>Müşteri Görevleri</h3>
                    <p>Bu müşteriye ait diğer görevleri görüntüleyin</p>
                    <a href="?view=tasks&customer_id=<?php echo $task->customer_id; ?>" class="btn btn-sm btn-outline">
                        <i class="fas fa-list"></i> Müşterinin Görevleri
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
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

.task-detail-container {
    max-width: 1200px;
    margin: 0 auto;
    font-family: var(--font-family);
    color: var(--on-surface);
    padding-bottom: var(--spacing-2xl);
}

/* Başlık alanı */
.task-header {
    background-color: var(--surface);
    border-radius: var(--radius-xl);
    padding: var(--spacing-xl);
    margin-bottom: var(--spacing-xl);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
}

.header-content {
    margin-bottom: var(--spacing-lg);
}

.breadcrumb {
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
    margin-bottom: var(--spacing-md);
    font-size: var(--font-size-sm);
    color: var(--on-surface-variant);
}

.breadcrumb a {
    color: var(--primary);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

.breadcrumb a:hover {
    text-decoration: underline;
}

.task-title {
    font-size: var(--font-size-2xl);
    font-weight: 600;
    margin: 0 0 var(--spacing-md) 0;
    color: var(--on-surface);
    line-height: 1.3;
}

.task-meta {
    display: flex;
    gap: var(--spacing-md);
    flex-wrap: wrap;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: var(--spacing-xs);
    padding: var(--spacing-xs) var(--spacing-sm);
    border-radius: var(--radius-lg);
    font-size: var(--font-size-sm);
    font-weight: 500;
}

.status-badge.status-pending {
    background-color: rgba(25, 118, 210, 0.1);
    color: var(--primary);
}

.status-badge.status-in_progress {
    background-color: rgba(245, 124, 0, 0.1);
    color: var(--warning);
}

.status-badge.status-completed {
    background-color: rgba(46, 125, 50, 0.1);
    color: var(--success);
}

.status-badge.status-cancelled {
    background-color: rgba(117, 117, 117, 0.1);
    color: var(--outline);
}

.status-badge.priority-low {
    background-color: rgba(46, 125, 50, 0.1);
    color: var(--success);
}

.status-badge.priority-medium {
    background-color: rgba(245, 124, 0, 0.1);
    color: var(--warning);
}

.status-badge.priority-high,
.status-badge.priority-urgent {
    background-color: rgba(211, 47, 47, 0.1);
    color: var(--danger);
}

.status-badge.overdue {
    background-color: rgba(211, 47, 47, 0.1);
    color: var(--danger);
}

.task-actions {
    display: flex;
    gap: var(--spacing-md);
    flex-wrap: wrap;
    border-top: 1px solid var(--outline-variant);
    padding-top: var(--spacing-lg);
}

/* İçerik alanı */
.task-content {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: var(--spacing-xl);
    margin-bottom: var(--spacing-xl);
}

.card {
    background-color: var(--surface);
    border-radius: var(--radius-xl);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
}

.card-header {
    background-color: var(--surface-variant);
    padding: var(--spacing-lg) var(--spacing-xl);
    border-bottom: 1px solid var(--outline-variant);
}

.card-header h2 {
    margin: 0;
    font-size: var(--font-size-lg);
    font-weight: 600;
    color: var(--on-surface);
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.card-body {
    padding: var(--spacing-xl);
}

/* Durum kartı renkleri */
.status-card.status-pending {
    border-left: 4px solid var(--primary);
}

.status-card.status-in_progress {
    border-left: 4px solid var(--warning);
}

.status-card.status-completed {
    border-left: 4px solid var(--success);
}

.status-card.status-cancelled {
    border-left: 4px solid var(--outline);
}

/* Açıklama alanı */
.description-container {
    margin-bottom: var(--spacing-xl);
}

.description-container h3 {
    font-size: var(--font-size-base);
    font-weight: 600;
    margin: 0 0 var(--spacing-sm) 0;
    color: var(--on-surface);
}

.description-box {
    background-color: var(--surface-variant);
    border-radius: var(--radius-lg);
    padding: var(--spacing-lg);
    line-height: 1.6;
    color: var(--on-surface);
    white-space: pre-line;
    border: 1px solid var(--outline-variant);
}

/* Bilgi grid */
.info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--spacing-xl);
}

.info-group h3 {
    font-size: var(--font-size-base);
    font-weight: 600;
    margin: 0 0 var(--spacing-md) 0;
    color: var(--on-surface);
    padding-bottom: var(--spacing-xs);
    border-bottom: 1px dashed var(--outline-variant);
}

.info-item {
    display: flex;
    margin-bottom: var(--spacing-md);
    gap: var(--spacing-md);
    align-items: baseline;
}

.info-label {
    font-weight: 500;
    color: var(--on-surface-variant);
    min-width: 120px;
    flex-shrink: 0;
}

.info-value {
    flex: 1;
}

.info-value.danger-text {
    color: var(--danger);
}

.no-value {
    color: var(--outline);
    font-style: italic;
}

.link {
    color: var(--primary);
    text-decoration: none;
}

.link:hover {
    text-decoration: underline;
}

.policy-type-tag,
.policy-company-tag {
    display: inline-block;
    padding: var(--spacing-xs) var(--spacing-sm);
    border-radius: var(--radius-sm);
    font-size: var(--font-size-xs);
    background-color: rgba(25, 118, 210, 0.1);
    color: var(--primary);
    margin-left: var(--spacing-sm);
}

.policy-company-tag {
    background-color: rgba(156, 39, 176, 0.1);
    color: var(--secondary);
}

.overdue-tag {
    display: inline-block;
    margin-left: var(--spacing-sm);
    padding: var(--spacing-xs) var(--spacing-sm);
    background-color: rgba(211, 47, 47, 0.1);
    color: var(--danger);
    border-radius: var(--radius-sm);
    font-size: var(--font-size-xs);
}

/* Durum bilgileri */
.status-info {
    display: flex;
    gap: var(--spacing-xl);
    margin-bottom: var(--spacing-xl);
}

.status-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    flex-shrink: 0;
    background-color: var(--surface-variant);
    box-shadow: var(--shadow-sm);
}

.status-pending .status-icon {
    color: var(--primary);
}

.status-in_progress .status-icon {
    color: var(--warning);
}

.status-completed .status-icon {
    color: var(--success);
}

.status-cancelled .status-icon {
    color: var(--outline);
}

.status-details h3 {
    font-size: var(--font-size-lg);
    font-weight: 600;
    margin: 0 0 var(--spacing-sm) 0;
    color: var(--on-surface);
}

.status-details p {
    margin: 0 0 var(--spacing-sm) 0;
    color: var(--on-surface-variant);
    line-height: 1.5;
}

.completion-date {
    background-color: rgba(46, 125, 50, 0.1);
    color: var(--success) !important;
    padding: var(--spacing-sm) var(--spacing-md);
    border-radius: var(--radius-lg);
    display: inline-flex;
    align-items: center;
    gap: var(--spacing-sm);
    font-weight: 500;
    margin-top: var(--spacing-sm) !important;
}

.overdue-alert {
    background-color: rgba(211, 47, 47, 0.05);
    border: 1px solid rgba(211, 47, 47, 0.2);
    border-radius: var(--radius-lg);
    padding: var(--spacing-lg);
    margin-top: var(--spacing-xl);
    display: flex;
    gap: var(--spacing-lg);
    align-items: flex-start;
}

.overdue-icon {
    font-size: 2rem;
    color: var(--danger);
    flex-shrink: 0;
}

.overdue-details h3 {
    font-size: var(--font-size-base);
    font-weight: 600;
    margin: 0 0 var(--spacing-sm) 0;
    color: var(--danger);
}

.overdue-details p {
    margin: 0 0 var(--spacing-sm) 0;
    color: var(--on-surface-variant);
    line-height: 1.5;
}

.overdue-details p:last-child {
    margin-bottom: 0;
}

.status-actions {
    margin-top: var(--spacing-xl);
    padding-top: var(--spacing-lg);
    border-top: 1px solid var(--outline-variant);
    display: flex;
    gap: var(--spacing-md);
    flex-wrap: wrap;
}

/* İlişkili içerikler */
.related-section {
    margin-top: var(--spacing-xl);
}

.related-section h2 {
    font-size: var(--font-size-xl);
    font-weight: 600;
    margin: 0 0 var(--spacing-lg) 0;
    color: var(--on-surface);
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.related-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: var(--spacing-lg);
}

.related-card {
    background-color: var(--surface);
    border-radius: var(--radius-xl);
    padding: var(--spacing-xl);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
    display: flex;
    gap: var(--spacing-lg);
    transition: transform var(--transition-base), box-shadow var(--transition-base);
}

.related-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md);
}

.related-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    flex-shrink: 0;
}

.customer-icon {
    background: linear-gradient(135deg, #2196F3, #0D47A1);
    color: white;
}

.policy-icon {
    background: linear-gradient(135deg, #9C27B0, #4A148C);
    color: white;
}

.task-icon {
    background: linear-gradient(135deg, #4CAF50, #1B5E20);
    color: white;
}

.related-content {
    flex: 1;
}

.related-content h3 {
    font-size: var(--font-size-base);
    font-weight: 600;
    margin: 0 0 var(--spacing-sm) 0;
    color: var(--on-surface);
}

.related-content p {
    margin: 0 0 var(--spacing-md) 0;
    color: var(--on-surface-variant);
    line-height: 1.5;
    font-size: var(--font-size-sm);
}

/* Butonlar */
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

.btn-lg {
    padding: var(--spacing-md) var(--spacing-xl);
    font-size: var(--font-size-base);
}

.btn-sm {
    padding: 4px 8px;
    font-size: var(--font-size-xs);
}

/* Responsive design */
@media (max-width: 1024px) {
    .task-content {
        grid-template-columns: 1fr;
        gap: var(--spacing-lg);
    }
    
    .info-grid {
        grid-template-columns: 1fr;
    }
    
    .related-cards {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .task-header {
        padding: var(--spacing-lg);
    }
    
    .task-actions {
        flex-direction: column-reverse;
    }
    
    .btn {
        width: 100%;
    }
    
    .status-info {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    
    .related-card {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
}

@media (max-width: 480px) {
    .task-meta {
        flex-direction: column;
    }
    
    .status-badge {
        width: 100%;
        justify-content: center;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Notification close functionality
    const closeButtons = document.querySelectorAll('.notification-close');
    if (closeButtons) {
        closeButtons.forEach(button => {
            button.addEventListener('click', function() {
                const notification = this.closest('.notification-banner');
                if (notification) {
                    notification.style.opacity = '0';
                    setTimeout(() => {
                        notification.style.display = 'none';
                    }, 300);
                }
            });
        });
    }
    
    // Auto-hide notifications after 5 seconds
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
});
</script>
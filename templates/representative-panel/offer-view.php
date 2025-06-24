<?php
/**
 * Offer Detail View Page - Similar to policies-view.php
 * @version 1.0.0
 * @date 2025-05-30
 */

// Security check
if (!is_user_logged_in() || !isset($_GET['id'])) {
    return;
}

$customer_id = intval($_GET['id']);
global $wpdb;
$customers_table = $wpdb->prefix . 'insurance_crm_customers';

// Get customer data with offer information
$customer = $wpdb->get_row($wpdb->prepare("
    SELECT c.*, r.user_id as representative_user_id 
    FROM $customers_table c
    LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON c.representative_id = r.id
    WHERE c.id = %d AND c.has_offer = 1
", $customer_id));

if (!$customer) {
    echo '<div class="ab-notice ab-error">Teklif bulunamadı veya bu müşterinin aktif teklifi yok.</div>';
    return;
}

// Get representative info
$representative_name = 'Belirtilmemiş';
if ($customer->representative_user_id) {
    $user_info = get_userdata($customer->representative_user_id);
    if ($user_info) {
        $representative_name = $user_info->display_name;
    }
}

// Calculate days remaining
function calculate_days_remaining($expiry_date) {
    if (empty($expiry_date)) return null;
    
    $today = new DateTime();
    $expiry = new DateTime($expiry_date);
    $diff = $today->diff($expiry);
    
    return $expiry < $today ? -$diff->days : $diff->days;
}

$days_remaining = calculate_days_remaining($customer->offer_expiry_date);
?>

<div class="ab-container">
    <div class="ab-header">
        <div class="ab-header-content">
            <h1><i class="fas fa-file-invoice-dollar"></i> Teklif Detayları</h1>
            <p>Müşteri teklif bilgileri ve detayları</p>
        </div>
        <div class="ab-header-actions">
            <a href="?view=offers" class="ab-btn ab-btn-secondary">
                <i class="fas fa-arrow-left"></i> Teklifler
            </a>
            <a href="?view=customers&action=view&id=<?php echo $customer->id; ?>" class="ab-btn ab-btn-info">
                <i class="fas fa-user"></i> Müşteri Detayı
            </a>
            <a href="?view=policies&action=new&customer_search=<?php echo urlencode($customer->first_name . ' ' . $customer->last_name); ?>&offer_type=<?php echo urlencode($customer->offer_insurance_type); ?>&offer_amount=<?php echo urlencode($customer->offer_amount); ?>" class="ab-btn ab-btn-success">
                <i class="fas fa-exchange-alt"></i> Poliçeye Çevir
            </a>
        </div>
    </div>

    <div class="ab-content-grid">
        <!-- Customer Information -->
        <div class="ab-panel ab-panel-customer">
            <div class="ab-panel-header">
                <h3><i class="fas fa-user"></i> Müşteri Bilgileri</h3>
            </div>
            <div class="ab-panel-body">
                <div class="ab-info-grid">
                    <div class="ab-info-item">
                        <div class="ab-info-label">Ad Soyad / Firma Adı</div>
                        <div class="ab-info-value">
                            <?php 
                            if (!empty($customer->company_name)) {
                                echo '<strong>' . esc_html($customer->company_name) . '</strong>';
                            } else {
                                echo esc_html($customer->first_name . ' ' . $customer->last_name);
                            }
                            ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">TC Kimlik / VKN</div>
                        <div class="ab-info-value">
                            <?php 
                            if (!empty($customer->tax_number)) {
                                echo esc_html($customer->tax_number);
                            } else {
                                echo esc_html($customer->tc_identity);
                            }
                            ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Telefon</div>
                        <div class="ab-info-value">
                            <a href="tel:<?php echo esc_attr($customer->phone); ?>" class="ab-phone-link">
                                <i class="fas fa-phone"></i> <?php echo esc_html($customer->phone ?: 'Belirtilmemiş'); ?>
                            </a>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">E-posta</div>
                        <div class="ab-info-value">
                            <?php if (!empty($customer->email)): ?>
                                <a href="mailto:<?php echo esc_attr($customer->email); ?>" class="ab-email-link">
                                    <i class="fas fa-envelope"></i> <?php echo esc_html($customer->email); ?>
                                </a>
                            <?php else: ?>
                                <span class="ab-no-value">Belirtilmemiş</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Temsilci</div>
                        <div class="ab-info-value">
                            <?php echo esc_html($representative_name); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Offer Information -->
        <div class="ab-panel ab-panel-offer">
            <div class="ab-panel-header">
                <h3><i class="fas fa-file-invoice-dollar"></i> Teklif Bilgileri</h3>
                <?php if ($days_remaining !== null): ?>
                <div class="ab-panel-header-badge">
                    <?php 
                    if ($days_remaining < 0) {
                        echo '<span class="ab-badge ab-badge-expired">Süresi Dolmuş</span>';
                    } elseif ($days_remaining <= 7) {
                        echo '<span class="ab-badge ab-badge-warning">' . $days_remaining . ' Gün Kaldı</span>';
                    } else {
                        echo '<span class="ab-badge ab-badge-success">' . $days_remaining . ' Gün Kaldı</span>';
                    }
                    ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="ab-panel-body">
                <div class="ab-info-grid">
                    <div class="ab-info-item">
                        <div class="ab-info-label">Sigorta Türü</div>
                        <div class="ab-info-value ab-text-primary">
                            <i class="fas fa-shield-alt"></i>
                            <?php echo esc_html($customer->offer_insurance_type ?: 'Belirtilmemiş'); ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Teklif Tutarı</div>
                        <div class="ab-info-value ab-amount">
                            <i class="fas fa-lira-sign"></i>
                            <?php 
                            if (!empty($customer->offer_amount)) {
                                echo number_format($customer->offer_amount, 2, ',', '.') . ' ₺';
                            } else {
                                echo '<span class="ab-no-value">Belirtilmemiş</span>';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Geçerlilik Tarihi</div>
                        <div class="ab-info-value">
                            <i class="fas fa-calendar-alt"></i>
                            <?php 
                            if (!empty($customer->offer_expiry_date)) {
                                echo date('d.m.Y', strtotime($customer->offer_expiry_date));
                            } else {
                                echo '<span class="ab-no-value">Belirtilmemiş</span>';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Hatırlatma</div>
                        <div class="ab-info-value">
                            <?php 
                            if ($customer->offer_reminder == 1) {
                                echo '<span class="ab-badge ab-badge-info"><i class="fas fa-bell"></i> Aktif</span>';
                            } else {
                                echo '<span class="ab-badge ab-badge-light"><i class="fas fa-bell-slash"></i> Pasif</span>';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($customer->offer_notes)): ?>
                    <div class="ab-info-item ab-info-item-wide">
                        <div class="ab-info-label">Teklif Notları</div>
                        <div class="ab-info-value ab-text-content">
                            <?php echo nl2br(esc_html($customer->offer_notes)); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Customer Status -->
        <div class="ab-panel ab-panel-status">
            <div class="ab-panel-header">
                <h3><i class="fas fa-info-circle"></i> Durum Bilgileri</h3>
            </div>
            <div class="ab-panel-body">
                <div class="ab-info-grid">
                    <div class="ab-info-item">
                        <div class="ab-info-label">Müşteri Durumu</div>
                        <div class="ab-info-value">
                            <?php 
                            switch ($customer->status) {
                                case 'aktif':
                                    echo '<span class="ab-badge ab-badge-success">Aktif</span>';
                                    break;
                                case 'pasif':
                                    echo '<span class="ab-badge ab-badge-warning">Pasif</span>';
                                    break;
                                default:
                                    echo '<span class="ab-badge ab-badge-light">Belirsiz</span>';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Kayıt Tarihi</div>
                        <div class="ab-info-value">
                            <i class="fas fa-calendar-plus"></i>
                            <?php echo date('d.m.Y H:i', strtotime($customer->created_at)); ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Son Güncelleme</div>
                        <div class="ab-info-value">
                            <i class="fas fa-clock"></i>
                            <?php 
                            if (!empty($customer->updated_at)) {
                                echo date('d.m.Y H:i', strtotime($customer->updated_at));
                            } else {
                                echo 'Hiç güncellenmemiş';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Modern Material Design styling - matching policies-view.php */
:root {
    --color-primary: #1976d2;
    --color-primary-dark: #0d47a1;
    --color-primary-light: #42a5f5;
    --color-secondary: #9c27b0;
    --color-success: #2e7d32;
    --color-success-light: #e8f5e9;
    --color-warning: #f57c00;
    --color-warning-light: #fff8e0;
    --color-danger: #d32f2f;
    --color-danger-light: #ffebee;
    --color-info: #0288d1;
    --color-info-light: #e1f5fe;
    --color-text-primary: #212121;
    --color-text-secondary: #757575;
    --color-background: #f5f5f5;
    --color-surface: #ffffff;
    --color-border: #e0e0e0;
    --font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
    --shadow-md: 0 3px 6px rgba(0,0,0,0.15), 0 2px 4px rgba(0,0,0,0.12);
    --shadow-lg: 0 10px 20px rgba(0,0,0,0.15), 0 3px 6px rgba(0,0,0,0.1);
    --radius-sm: 4px;
    --radius-md: 8px;
    --radius-lg: 12px;
    --spacing-xs: 4px;
    --spacing-sm: 8px;
    --spacing-md: 16px;
    --spacing-lg: 24px;
    --spacing-xl: 32px;
}

.ab-container {
    font-family: var(--font-family);
    color: var(--color-text-primary);
    background-color: var(--color-background);
    max-width: 1280px;
    margin: 0 auto;
    padding: var(--spacing-md);
}

.ab-header {
    background: var(--color-surface);
    border-radius: var(--radius-lg);
    padding: var(--spacing-lg);
    box-shadow: var(--shadow-md);
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--spacing-lg);
}

.ab-header-content h1 {
    margin: 0;
    font-size: 28px;
    font-weight: 700;
    color: var(--color-text-primary);
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
}

.ab-header-content h1 i {
    color: var(--color-primary);
}

.ab-header-content p {
    margin: var(--spacing-sm) 0 0 0;
    color: var(--color-text-secondary);
    font-size: 16px;
}

.ab-header-actions {
    display: flex;
    gap: var(--spacing-sm);
    flex-wrap: wrap;
}

.ab-btn {
    display: inline-flex;
    align-items: center;
    gap: var(--spacing-sm);
    padding: 10px 16px;
    border: none;
    border-radius: var(--radius-md);
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.ab-btn-secondary {
    background: #666;
    color: white;
}

.ab-btn-secondary:hover {
    background: #555;
    transform: translateY(-1px);
}

.ab-btn-info {
    background: var(--color-info);
    color: white;
}

.ab-btn-info:hover {
    background: #0277bd;
    transform: translateY(-1px);
}

.ab-btn-success {
    background: var(--color-success);
    color: white;
}

.ab-btn-success:hover {
    background: #1b5e20;
    transform: translateY(-1px);
}

.ab-content-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: var(--spacing-lg);
    margin-top: var(--spacing-lg);
}

.ab-panel {
    background: var(--color-surface);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    overflow: hidden;
}

.ab-panel-header {
    background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
    color: white;
    padding: var(--spacing-md) var(--spacing-lg);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.ab-panel-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.ab-panel-offer .ab-panel-header {
    background: linear-gradient(135deg, #f57c00, #e65100);
}

.ab-panel-status .ab-panel-header {
    background: linear-gradient(135deg, #9c27b0, #6a1b99);
}

.ab-panel-body {
    padding: var(--spacing-lg);
}

.ab-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: var(--spacing-md);
}

.ab-info-item {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-xs);
}

.ab-info-item-wide {
    grid-column: 1 / -1;
}

.ab-info-label {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--color-text-secondary);
}

.ab-info-value {
    font-size: 16px;
    font-weight: 500;
    color: var(--color-text-primary);
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

.ab-amount {
    font-size: 20px;
    font-weight: 700;
    color: var(--color-success);
}

.ab-text-primary {
    color: var(--color-primary);
    font-weight: 600;
}

.ab-text-content {
    line-height: 1.6;
    margin-top: var(--spacing-xs);
}

.ab-no-value {
    color: var(--color-text-secondary);
    font-style: italic;
}

.ab-phone-link, .ab-email-link {
    color: var(--color-info);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

.ab-phone-link:hover, .ab-email-link:hover {
    color: var(--color-primary);
    text-decoration: underline;
}

.ab-badge {
    display: inline-flex;
    align-items: center;
    gap: var(--spacing-xs);
    padding: 4px 8px;
    border-radius: var(--radius-sm);
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.ab-badge-success {
    background: var(--color-success-light);
    color: var(--color-success);
}

.ab-badge-warning {
    background: var(--color-warning-light);
    color: var(--color-warning);
}

.ab-badge-expired {
    background: var(--color-danger-light);
    color: var(--color-danger);
}

.ab-badge-info {
    background: var(--color-info-light);
    color: var(--color-info);
}

.ab-badge-light {
    background: #f5f5f5;
    color: var(--color-text-secondary);
}

.ab-panel-header-badge {
    flex-shrink: 0;
}

@media (max-width: 768px) {
    .ab-header {
        flex-direction: column;
        gap: var(--spacing-md);
        text-align: center;
    }
    
    .ab-content-grid {
        grid-template-columns: 1fr;
    }
    
    .ab-info-grid {
        grid-template-columns: 1fr;
    }
}
</style>
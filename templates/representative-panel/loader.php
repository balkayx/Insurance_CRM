<?php
/**
 * Sayfa Yükleme Loader'ı - Firma Logolu
 * Tüm representative panel sayfalarında kullanılan logo loader
 * 
 * @author anadolubirlik
 * @version 1.0.0
 * @date 2025-06-02
 */

// Güvenlik kontrolü
if (!defined('ABSPATH')) {
    exit;
}

// Firma ayarlarını al
$settings = get_option('insurance_crm_settings', array());
$company_logo = isset($settings['site_appearance']['login_logo']) ? $settings['site_appearance']['login_logo'] : '';
$company_name = isset($settings['company_name']) ? $settings['company_name'] : get_bloginfo('name');
$primary_color = isset($settings['site_appearance']['primary_color']) ? $settings['site_appearance']['primary_color'] : '#667eea';
?>

<!-- Sayfa Loader'ı -->
<div id="page-loader" class="page-loader-overlay" style="background: linear-gradient(135deg, <?php echo esc_attr($primary_color); ?> 0%, <?php echo esc_attr($primary_color); ?>aa 100%);">
    <div class="page-loader-container">
        <div class="page-loader-content">
            <?php if (!empty($company_logo)): ?>
                <!-- Firma Logosu -->
                <div class="loader-logo">
                    <img src="<?php echo esc_url($company_logo); ?>" 
                         alt="<?php echo esc_attr($company_name); ?>" 
                         class="company-logo-img" />
                </div>
            <?php else: ?>
                <!-- Logo bulunamazsa varsayılan ikon -->
                <div class="loader-logo">
                    <div class="default-logo-icon">
                        <i class="fas fa-building"></i>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Yükleme Animasyonu -->
            <div class="loader-animation">
                <div class="loader-spinner">
                    <div class="spinner-ring"></div>
                    <div class="spinner-ring"></div>
                    <div class="spinner-ring"></div>
                </div>
            </div>
            
            <!-- Yükleme Metni -->
            <div class="loader-text">
                <p>Sayfa yükleniyor...</p>
            </div>
        </div>
    </div>
</div>
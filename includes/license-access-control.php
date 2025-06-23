<?php
/**
 * License Access Control Functions
 * 
 * Handles access control based on license status
 * 
 * @package Insurance_CRM
 * @author  Anadolu Birlik
 * @since   1.1.3
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if user can access CRM data
 * 
 * @return bool True if access is allowed
 */
function insurance_crm_can_access_data() {
    global $insurance_crm_license_manager;
    
    if (!$insurance_crm_license_manager) {
        return true; // Allow access if license manager is not available
    }
    
    return $insurance_crm_license_manager->can_access_data();
}

/**
 * Check if specific module is accessible
 * 
 * @param string $module Module name
 * @return bool True if module is accessible
 */
function insurance_crm_can_access_module($module) {
    global $insurance_crm_license_manager;
    
    if (!$insurance_crm_license_manager) {
        return true; // Allow access if license manager is not available
    }
    
    return $insurance_crm_license_manager->is_module_allowed($module);
}

/**
 * Display license restriction message
 * 
 * @param string $type Type of restriction (data, module, user_limit)
 */
function insurance_crm_display_license_restriction($type = 'data') {
    global $insurance_crm_license_manager;
    
    $license_info = $insurance_crm_license_manager ? $insurance_crm_license_manager->get_license_info() : array();
    
    echo '<div class="wrap">';
    echo '<div class="license-restriction-notice">';
    
    if ($type === 'data') {
        if ($license_info['status'] === 'expired' && !$license_info['in_grace_period']) {
            echo '<h2>Lisans Süresi Dolmuş</h2>';
            echo '<p>Lisansınızın süresi dolmuştur ve ek kullanım süreniz sona ermiştir.</p>';
            echo '<p><strong>Uygulamamızı kullanabilmek için lütfen ödemenizi yapın ve lisansınızı yenileyin.</strong></p>';
        } else {
            echo '<h2>Lisans Gerekli</h2>';
            echo '<p>Bu verilere erişebilmek için geçerli bir lisansa ihtiyacınız var.</p>';
        }
    } elseif ($type === 'module') {
        echo '<h2>Modül Erişimi Kısıtlı</h2>';
        echo '<p>Bu modüle erişim için lisansınız yeterli değil.</p>';
        echo '<p>Lütfen lisansınızı yükseltin veya uygun modülleri içeren bir lisans satın alın.</p>';
    } elseif ($type === 'user_limit') {
        echo '<h2>Kullanıcı Limiti Aşıldı</h2>';
        echo '<p>Kullanıcı sayısı limiti aşıldı. Yeni kullanıcı ekleyemezsiniz.</p>';
        echo '<p>Mevcut: ' . ($license_info['current_users'] ?? 0) . ' / ' . ($license_info['user_limit'] ?? 5) . ' kullanıcı</p>';
    }
    
    echo '<p><a href="' . admin_url('admin.php?page=insurance-crm-license') . '" class="button button-primary">Lisans Yönetimine Git</a></p>';
    echo '</div>';
    echo '</div>';
    
    // Add CSS styling
    echo '<style>
        .license-restriction-notice {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 5px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }
        .license-restriction-notice h2 {
            color: #856404;
            margin-top: 0;
        }
        .license-restriction-notice p {
            color: #856404;
            font-size: 16px;
        }
    </style>';
}

/**
 * Hook to check access before displaying admin pages
 */
function insurance_crm_check_admin_page_access() {
    // Only check on CRM pages
    if (!isset($_GET['page']) || strpos($_GET['page'], 'insurance-crm') === false) {
        return;
    }
    
    // Skip license page itself
    if ($_GET['page'] === 'insurance-crm-license') {
        return;
    }
    
    global $insurance_crm_license_manager;
    
    // Perform real-time license check on page access
    if ($insurance_crm_license_manager) {
        $insurance_crm_license_manager->perform_license_check();
    }
    
    // Check if data access is allowed
    if (!insurance_crm_can_access_data()) {
        error_log('[LISANS DEBUG] Admin page access denied - redirecting to license page');
        // Redirect to license page with restriction notice
        wp_redirect(admin_url('admin.php?page=insurance-crm-license&restriction=data'));
        exit;
    }
    
    // Check module access for specific pages
    $page_modules = array(
        'insurance-crm-customers' => 'customers',
        'insurance-crm-policies' => 'policies',
        'insurance-crm-tasks' => 'tasks',
        'insurance-crm-reports' => 'reports'
    );
    
    if (isset($page_modules[$_GET['page']])) {
        if (!insurance_crm_can_access_module($page_modules[$_GET['page']])) {
            error_log('[LISANS DEBUG] Module access denied: ' . $page_modules[$_GET['page']]);
            insurance_crm_display_license_restriction('module');
            exit;
        }
    }
}

// Hook into admin initialization
add_action('admin_init', 'insurance_crm_check_admin_page_access', 5);

/**
 * Filter to restrict data queries
 */
function insurance_crm_filter_data_access($query) {
    // Only apply on admin pages and for our tables
    if (!is_admin() || !insurance_crm_can_access_data()) {
        return $query;
    }
    
    return $query;
}

/**
 * Hook to show license warning banner
 */
function insurance_crm_show_license_warning_banner() {
    global $insurance_crm_license_manager;
    
    // Only show on CRM pages
    if (!isset($_GET['page']) || strpos($_GET['page'], 'insurance-crm') === false) {
        return;
    }
    
    if (!$insurance_crm_license_manager) {
        return;
    }
    
    $license_info = $insurance_crm_license_manager->get_license_info();
    
    // Show warning for expired license in grace period
    if ($license_info['status'] === 'expired' && $license_info['in_grace_period']) {
        echo '<div class="notice notice-warning license-grace-banner" style="border-left-color: #ffc107; margin: 0; padding: 10px 20px; position: sticky; top: 32px; z-index: 999; background: #fff3cd;">';
        echo '<p style="margin: 0; font-weight: bold; color: #856404;">';
        echo '⚠️ Lisansınızın süresi dolmuştur. Lütfen ' . $license_info['grace_days_remaining'] . ' gün içinde ödemenizi yaparak yenileyiniz.';
        echo ' <a href="' . admin_url('admin.php?page=insurance-crm-license') . '" style="color: #856404; text-decoration: underline;">Lisansı Yenile</a>';
        echo '</p>';
        echo '</div>';
    }
}

// Hook to show license banner
add_action('admin_notices', 'insurance_crm_show_license_warning_banner', 1);

/**
 * Check user limit before creating new users
 * 
 * @param array $errors Registration errors
 * @param string $user_login User login
 * @param string $user_email User email
 * @return array Modified errors
 */
function insurance_crm_check_user_limit_on_registration($errors, $user_login, $user_email) {
    global $insurance_crm_license_manager;
    
    if (!$insurance_crm_license_manager) {
        return $errors;
    }
    
    // Check if this is an insurance representative registration
    if (isset($_POST['role']) && $_POST['role'] === 'insurance_representative') {
        if ($insurance_crm_license_manager->is_user_limit_exceeded()) {
            $license_info = $insurance_crm_license_manager->get_license_info();
            $errors->add('user_limit_exceeded', 
                sprintf('Kullanıcı sayısı limiti aşıldı. Mevcut: %d / %d kullanıcı. Lütfen lisansınızı yükseltin.',
                    $license_info['current_users'], $license_info['user_limit']
                )
            );
        }
    }
    
    return $errors;
}

// Hook user registration validation
add_filter('registration_errors', 'insurance_crm_check_user_limit_on_registration', 10, 3);

/**
 * Prevent access to restricted content via AJAX
 */
function insurance_crm_check_ajax_access() {
    // Check if this is a CRM AJAX request
    if (!isset($_POST['action']) || strpos($_POST['action'], 'insurance_crm') === false) {
        return;
    }
    
    global $insurance_crm_license_manager;
    
    // Perform real-time license check on AJAX requests
    if ($insurance_crm_license_manager) {
        $insurance_crm_license_manager->perform_license_check();
    }
    
    if (!insurance_crm_can_access_data()) {
        error_log('[LISANS DEBUG] AJAX access denied for action: ' . $_POST['action']);
        wp_send_json_error(array(
            'message' => 'Lisans süresi dolmuş. Lütfen lisansınızı yenileyin.',
            'redirect_url' => admin_url('admin.php?page=insurance-crm-license'),
            'license_error' => true
        ));
    }
}

// Hook AJAX requests
add_action('wp_ajax_nopriv_insurance_crm_check_access', 'insurance_crm_check_ajax_access');
add_action('wp_ajax_insurance_crm_check_access', 'insurance_crm_check_ajax_access');

/**
 * Modify database queries to return empty results when access is restricted
 * 
 * @param array $results Query results
 * @param string $table_name Table name
 * @return array Modified results
 */
function insurance_crm_filter_database_results($results, $table_name = '') {
    // Check if this is a CRM table and access is restricted
    if (strpos($table_name, 'insurance_crm') !== false && !insurance_crm_can_access_data()) {
        return array(); // Return empty results
    }
    
    return $results;
}

/**
 * Add license status to footer for logged-in users
 */
function insurance_crm_add_license_status_footer() {
    global $insurance_crm_license_manager;
    
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }
    
    if (!$insurance_crm_license_manager) {
        return;
    }
    
    $license_info = $insurance_crm_license_manager->get_license_info();
    
    // Get license type display name - prefer server description over hardcoded mapping
    $license_type_display = '';
    
    if (!empty($license_info['type_description'])) {
        // Use license type description from server
        $license_type_display = ' (' . $license_info['type_description'] . ')';
    } else {
        // Fallback to hardcoded mapping if server doesn't provide description
        $license_type = $license_info['type'];
        $type_map = array(
            'monthly' => 'Aylık',
            'yearly' => 'Yıllık', 
            'lifetime' => 'Ömürlük',
            'trial' => 'Deneme'
        );
        
        if (!empty($license_type) && isset($type_map[$license_type])) {
            $license_type_display = ' (' . $type_map[$license_type] . ')';
        }
    }
    
    echo '<script>
        jQuery(document).ready(function($) {
            if ($("#footer-left").length) {
                var licenseStatus = "' . $license_info['status'] . '";
                var licenseTypeDisplay = "' . esc_js($license_type_display) . '";
                var statusText = "";
                var statusColor = "#666";
                
                switch(licenseStatus) {
                    case "active":
                        statusText = "Lisans: Aktif" + licenseTypeDisplay;
                        statusColor = "#67c23a";
                        break;
                    case "expired":
                        statusText = "Lisans: Süresi Dolmuş" + licenseTypeDisplay;
                        statusColor = "#f56c6c";
                        break;
                    default:
                        statusText = "Lisans: Etkin Değil";
                        statusColor = "#f56c6c";
                }
                
                $("#footer-left").append(" | <span style=\"color: " + statusColor + "\">" + statusText + "</span>");
            }
        });
    </script>';
}

// Add license status to footer
add_action('admin_footer', 'insurance_crm_add_license_status_footer');
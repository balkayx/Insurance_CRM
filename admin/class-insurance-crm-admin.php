<?php

/**
 * Admin işlevselliği için sınıf
 */

if (!class_exists('Insurance_CRM_Admin')) {
    class Insurance_CRM_Admin {
        /**
         * The ID of this plugin.
         *
         * @since    1.0.0
         * @access   private
         * @var      string    $plugin_name    The ID of this plugin.
         */
        private $plugin_name;

        /**
         * The version of this plugin.
         *
         * @since    1.0.0
         * @access   private
         * @var      string    $version    The current version of this plugin.
         */
        private $version;

        /**
         * Initialize the class and set its properties.
         *
         * @since    1.0.0
         * @param    string    $plugin_name    The name of this plugin.
         * @param    string    $version        The version of this plugin.
         */
        public function __construct($plugin_name, $version) {
            $this->plugin_name = $plugin_name;
            $this->version = $version;

            add_action('admin_menu', array($this, 'add_plugin_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        }

        /**
         * Register the stylesheets for the admin area.
         *
         * @since    1.0.0
         */
        public function enqueue_styles() {
            wp_enqueue_style(
                $this->plugin_name,
                plugin_dir_url(__FILE__) . 'css/insurance-crm-admin.css',
                array(),
                $this->version,
                'all'
            );
        }

        /**
         * Register the JavaScript for the admin area.
         *
         * @since    1.0.0
         */
        public function enqueue_scripts() {
            wp_enqueue_script(
                $this->plugin_name,
                plugin_dir_url(__FILE__) . 'js/insurance-crm-admin.js',
                array('jquery'),
                $this->version,
                false
            );
            
            // Enqueue logging scripts on logs page
            if (isset($_GET['page']) && $_GET['page'] === 'insurance-crm-logs') {
                wp_enqueue_script(
                    $this->plugin_name . '-logs',
                    plugin_dir_url(__FILE__) . 'js/insurance-crm-logs.js',
                    array('jquery'),
                    $this->version,
                    false
                );
                
                // Localize script for AJAX
                wp_localize_script($this->plugin_name . '-logs', 'insuranceCrmLogs', array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('insurance_crm_logs_nonce')
                ));
            }
        }

        /**
         * Add menu items
         */
        public function add_plugin_admin_menu() {
            add_menu_page(
                'Insurance CRM',
                'Insurance CRM',
                'manage_insurance_crm',
                'insurance-crm',
                array($this, 'display_plugin_setup_page'),
                'dashicons-businessman',
                6
            );

            add_submenu_page(
                'insurance-crm',
                'Müşteriler',
                'Müşteriler',
                'manage_insurance_crm',
                'insurance-crm-customers',
                array($this, 'display_customers_page')
            );

            add_submenu_page(
                'insurance-crm',
                'Poliçeler',
                'Poliçeler',
                'manage_insurance_crm',
                'insurance-crm-policies',
                array($this, 'display_policies_page')
            );

            add_submenu_page(
                'insurance-crm',
                'Görevler',
                'Görevler',
                'manage_insurance_crm',
                'insurance-crm-tasks',
                array($this, 'display_tasks_page')
            );

            add_submenu_page(
                'insurance-crm',
                'Raporlar',
                'Raporlar',
                'manage_insurance_crm',
                'insurance-crm-reports',
                array($this, 'display_reports_page')
            );

            add_submenu_page(
                'insurance-crm',
                'Loglar',
                'Loglar',
                'manage_insurance_crm',
                'insurance-crm-logs',
                array($this, 'display_logs_page')
            );

            add_submenu_page(
                'insurance-crm',
                'Yönetim Ayarları',
                'Yönetim Ayarları',
                'manage_insurance_crm',
                'insurance-crm-settings',
                array($this, 'display_settings_page')
            );

            // Yönetim Ayarları menüsü altına Lisans Bilgisi sayfası ekle
            add_submenu_page(
                'insurance-crm',
                'Lisans Bilgisi',
                'Lisans Bilgisi',
                'manage_options',
                'insurance-crm-license',
                array($this, 'display_license_page')
            );
        }

        /**
         * Ana sayfa görüntüleme
         */
        public function display_plugin_setup_page() {
            include_once('partials/insurance-crm-admin-display.php');
        }

        /**
         * Müşteriler sayfası görüntüleme
         */
        public function display_customers_page() {
            include_once('partials/insurance-crm-admin-customers.php');
        }

        /**
         * Poliçeler sayfası görüntüleme
         */
        public function display_policies_page() {
            include_once('partials/insurance-crm-admin-policies.php');
        }

        /**
         * Görevler sayfası görüntüleme
         */
        public function display_tasks_page() {
            include_once('partials/insurance-crm-admin-tasks.php');
        }

        /**
         * Raporlar sayfası görüntüleme
         */
        public function display_reports_page() {
            include_once('partials/insurance-crm-admin-reports.php');
        }

        /**
         * Loglar sayfası görüntüleme
         */
        public function display_logs_page() {
            // Restrict access to admin and patron roles only
            $current_user_id = get_current_user_id();
            $is_admin = current_user_can('administrator');
            $is_patron = function_exists('is_patron') && is_patron($current_user_id);
            
            if (!$is_admin && !$is_patron) {
                echo '<div class="wrap">';
                echo '<h1>Erişim Reddedildi</h1>';
                echo '<p>Bu sayfayı görüntülemek için yetkiniz yok. Sadece sistem yöneticileri ve patronlar log kayıtlarını görüntüleyebilir.</p>';
                echo '<p><a href="' . admin_url('admin.php?page=insurance-crm') . '" class="button">Ana Sayfaya Dön</a></p>';
                echo '</div>';
                return;
            }
            
            try {
                // Check if INSURANCE_CRM_PATH is defined
                if (!defined('INSURANCE_CRM_PATH')) {
                    echo '<div class="wrap"><h1>Yapılandırma Hatası</h1><p>INSURANCE_CRM_PATH tanımlanmamış.</p></div>';
                    return;
                }
                
                // Load all required logging classes
                $logger_file = INSURANCE_CRM_PATH . 'includes/logging/class-insurance-crm-logger.php';
                $system_logger_file = INSURANCE_CRM_PATH . 'includes/logging/class-insurance-crm-system-logger.php';
                $user_logger_file = INSURANCE_CRM_PATH . 'includes/logging/class-insurance-crm-user-logger.php';
                $log_viewer_file = INSURANCE_CRM_PATH . 'includes/logging/class-insurance-crm-log-viewer.php';
                
                // Check if files exist
                if (!file_exists($logger_file)) {
                    echo '<div class="wrap"><h1>Dosya Hatası</h1><p>Logger dosyası bulunamadı: ' . $logger_file . '</p></div>';
                    return;
                }
                
                if (!file_exists($system_logger_file)) {
                    echo '<div class="wrap"><h1>Dosya Hatası</h1><p>System Logger dosyası bulunamadı: ' . $system_logger_file . '</p></div>';
                    return;
                }
                
                if (!file_exists($user_logger_file)) {
                    echo '<div class="wrap"><h1>Dosya Hatası</h1><p>User Logger dosyası bulunamadı: ' . $user_logger_file . '</p></div>';
                    return;
                }
                
                if (!file_exists($log_viewer_file)) {
                    echo '<div class="wrap"><h1>Dosya Hatası</h1><p>Log Viewer dosyası bulunamadı: ' . $log_viewer_file . '</p></div>';
                    return;
                }
                
                require_once($logger_file);
                require_once($system_logger_file);
                require_once($user_logger_file);
                require_once($log_viewer_file);
                
                // Check if classes are available
                if (!class_exists('Insurance_CRM_Log_Viewer')) {
                    echo '<div class="wrap"><h1>Sınıf Hatası</h1><p>Insurance_CRM_Log_Viewer sınıfı yüklenemedi.</p></div>';
                    return;
                }
                
                $log_viewer = new Insurance_CRM_Log_Viewer();
                $log_viewer->display_logs_page();
                
            } catch (Exception $e) {
                echo '<div class="wrap"><h1>Hata Oluştu</h1><p>Loglar görüntülenirken bir hata oluştu: ' . esc_html($e->getMessage()) . '</p></div>';
            } catch (Error $e) {
                echo '<div class="wrap"><h1>Fatal Hata</h1><p>Kritik bir hata oluştu: ' . esc_html($e->getMessage()) . '</p></div>';
            }
        }

        /**
         * Ayarlar sayfası görüntüleme
         */
        public function display_settings_page() {
            include_once('partials/insurance-crm-admin-settings.php');
        }

        /**
         * Lisans yönetim sayfası görüntüleme
         */
        public function display_license_page() {
            include_once('partials/license-settings.php');
        }
    }
}
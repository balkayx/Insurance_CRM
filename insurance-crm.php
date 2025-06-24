<?php
/**
 * Insurance CRM
 *
 * @package   Insurance_CRM
 * @author    Mehmet BALKAY | Anadolu Birlik
 * @copyright 2025 Anadolu Birlik
 * @license   GPL-2.0+
 *
 * Plugin Name: Insurance CRM
 * Plugin URI: https://github.com/anadolubirlik/insurance-crm
 * Description: Sigorta acenteleri için müşteri, poliçe ve görev yönetim sistemi.
 * Version: 1.5.4
 * Pagename: insurance-crm.php
 * Page Version: 1.5.4
 * Author: Mehmet BALKAY | Anadolu Birlik
 * Author URI: https://www.balkay.net
 */

if (!defined('WPINC')) {
    die;
}


/**
 * Lisans ayarlarını başlat
 */
function insurance_crm_initialize_license_settings() {
    // Bypass lisans ayarını kontrol et
    $bypass_license_exists = get_option('insurance_crm_bypass_license', null) !== null;
    
    // Eğer ayar yoksa, varsayılan değeri ekle
    if (!$bypass_license_exists) {
        update_option('insurance_crm_bypass_license', false);
    }
    
    // Diğer lisans ayarlarını da kontrol et ve yoksa ekle
    if (get_option('insurance_crm_license_debug_mode', null) === null) {
        update_option('insurance_crm_license_debug_mode', false);
    }
}
// Plugin yüklenir yüklenmez ayarları başlat
insurance_crm_initialize_license_settings();



/**
 * Lisans yönetimi sınıfını yükle
 */
// Dosyaların var olup olmadığını kontrol et
$api_file = plugin_dir_path(__FILE__) . 'includes/class-license-api.php';
$license_manager_file = plugin_dir_path(__FILE__) . 'includes/class-license-manager.php';
$notifications_file = plugin_dir_path(__FILE__) . 'includes/class-license-notifications.php';

if (file_exists($api_file) && file_exists($license_manager_file)) {
    require_once $api_file;
    require_once $license_manager_file;
    
    // Bildirim sistemi dosyasını yükle
    if (file_exists($notifications_file)) {
        require_once $notifications_file;
    }

    // Plugin'in ana dosyasının tam yolunu tanımla
    if (!defined('INSURANCE_CRM_FILE')) {
        define('INSURANCE_CRM_FILE', __FILE__);
    }

    // Plugin sürümünü doğrudan al veya sabit bir değer kullan
    $plugin_version = defined('INSURANCE_CRM_VERSION') ? INSURANCE_CRM_VERSION : '1.1.3';
    
    // Lisans yöneticisini başlat - versiyon numarasını doğrudan geç
    global $insurance_crm_license_manager;
    $insurance_crm_license_manager = new Insurance_CRM_License_Manager($plugin_version);
    
    // Bildirim sistemini başlat
    global $insurance_crm_license_notifications;
    if (class_exists('Insurance_CRM_License_Notifications')) {
        $insurance_crm_license_notifications = new Insurance_CRM_License_Notifications();
    }
    
    // Plugin özelliklerini kontrol eden yardımcı fonksiyon
    function insurance_crm_is_licensed() {
        global $insurance_crm_license_manager;
        return $insurance_crm_license_manager->is_license_valid();
    }

    // Lisans erişim kontrolü dosyasını yükle
    $access_control_file = plugin_dir_path(__FILE__) . 'includes/license-access-control.php';
    if (file_exists($access_control_file)) {
        require_once $access_control_file;
    }

    // Deaktive edildiğinde cron işlerini temizle
    register_deactivation_hook(__FILE__, array('Insurance_CRM_License_Manager', 'deactivation_cleanup'));
}


/**
 * Teklif bilgileri için sütunların doğrudan eklenmesini sağla
 * Bu fonksiyon her sayfa yüklenişinde çalışır ve sütunların var olup olmadığını kontrol eder
 */
function force_update_crm_db() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_customers';
    
    // Tablonun var olduğundan emin olalım
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        return; // Tablo yoksa bir şey yapma
    }
    
    // has_offer sütununu doğrudan kontrol et
    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM `{$table_name}` LIKE 'has_offer'");
    
    // Sütun yoksa ekle
    if (empty($column_exists)) {
        $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `has_offer` TINYINT(1) DEFAULT 0");
        $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `offer_insurance_type` VARCHAR(100) DEFAULT NULL");
        $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `offer_amount` DECIMAL(10,2) DEFAULT NULL");
        $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `offer_expiry_date` DATE DEFAULT NULL");
        $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `offer_notes` TEXT DEFAULT NULL");
    }
    
    // **FIX**: Add phone2 column for second phone number
    $phone2_exists = $wpdb->get_results("SHOW COLUMNS FROM `{$table_name}` LIKE 'phone2'");
    if (empty($phone2_exists)) {
        $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `phone2` VARCHAR(20) DEFAULT NULL");
        error_log('phone2 column added to customers table');
    }
    
    // **FIX**: Add ilk_kayit_eden column for İlk Kayıt Eden information
    $ilk_kayit_eden_exists = $wpdb->get_results("SHOW COLUMNS FROM `{$table_name}` LIKE 'ilk_kayit_eden'");
    if (empty($ilk_kayit_eden_exists)) {
        $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `ilk_kayit_eden` BIGINT(20) DEFAULT NULL");
        error_log('ilk_kayit_eden column added to customers table');
    }
    
    // **NEW**: Add offer_reminder column for quote reminder functionality
    $offer_reminder_exists = $wpdb->get_results("SHOW COLUMNS FROM `{$table_name}` LIKE 'offer_reminder'");
    if (empty($offer_reminder_exists)) {
        $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `offer_reminder` TINYINT(1) DEFAULT 0");
        error_log('offer_reminder column added to customers table');
    }
    
    // **NEW**: Add gross_premium column for Kasko/Trafik policies
    $policies_table = $wpdb->prefix . 'insurance_crm_policies';
    if ($wpdb->get_var("SHOW TABLES LIKE '$policies_table'") == $policies_table) {
        $gross_premium_exists = $wpdb->get_results("SHOW COLUMNS FROM `{$policies_table}` LIKE 'gross_premium'");
        if (empty($gross_premium_exists)) {
            $wpdb->query("ALTER TABLE `{$policies_table}` ADD COLUMN `gross_premium` DECIMAL(10,2) DEFAULT NULL AFTER `premium_amount`");
            error_log('gross_premium column added to policies table');
        }
    }
    
    // **NEW**: Add personal information fields to representatives table
    $representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
    if ($wpdb->get_var("SHOW TABLES LIKE '$representatives_table'") == $representatives_table) {
        // Add birth_date column
        $birth_date_exists = $wpdb->get_results("SHOW COLUMNS FROM `{$representatives_table}` LIKE 'birth_date'");
        if (empty($birth_date_exists)) {
            $wpdb->query("ALTER TABLE `{$representatives_table}` ADD COLUMN `birth_date` DATE DEFAULT NULL");
            error_log('birth_date column added to representatives table');
        }
        
        // Add wedding_anniversary column
        $wedding_anniversary_exists = $wpdb->get_results("SHOW COLUMNS FROM `{$representatives_table}` LIKE 'wedding_anniversary'");
        if (empty($wedding_anniversary_exists)) {
            $wpdb->query("ALTER TABLE `{$representatives_table}` ADD COLUMN `wedding_anniversary` DATE DEFAULT NULL");
            error_log('wedding_anniversary column added to representatives table');
        }
        
        // Add children_birthdays column (JSON format to store multiple dates)
        $children_birthdays_exists = $wpdb->get_results("SHOW COLUMNS FROM `{$representatives_table}` LIKE 'children_birthdays'");
        if (empty($children_birthdays_exists)) {
            $wpdb->query("ALTER TABLE `{$representatives_table}` ADD COLUMN `children_birthdays` TEXT DEFAULT NULL");
            error_log('children_birthdays column added to representatives table');
        }
    }
}
// Her sayfa yüklendiğinde bu fonksiyonu çalıştır - böylece sütunların varlığından emin oluruz
add_action('init', 'force_update_crm_db', 5);

/**
 * Plugin version.
 */
define('INSURANCE_CRM_VERSION', '1.1.3');

/**
 * Plugin base path
 */
define('INSURANCE_CRM_PATH', plugin_dir_path(__FILE__));

/**
 * Plugin URL
 */
define('INSURANCE_CRM_URL', plugin_dir_url(__FILE__));

/**
 * Vendor directory path
 */
define('INSURANCE_CRM_VENDOR_DIR', plugin_dir_path(__FILE__) . 'vendor/');

/**
 * Plugin activation.
 */
function activate_insurance_crm() {
    require_once plugin_dir_path(__FILE__) . 'includes/class-insurance-crm-activator.php';
    Insurance_CRM_Activator::activate();
    
    add_option('insurance_crm_activation_time', time());
    update_option('insurance_crm_menu_initialized', 'no');
    update_option('insurance_crm_menu_cache_cleared', 'no');
}

/**
 * Plugin deactivation.
 */
function deactivate_insurance_crm() {
    require_once plugin_dir_path(__FILE__) . 'includes/class-insurance-crm-deactivator.php';
    Insurance_CRM_Deactivator::deactivate();
    
    delete_option('insurance_crm_menu_initialized');
    delete_option('insurance_crm_menu_cache_cleared');
}

register_activation_hook(__FILE__, 'activate_insurance_crm');
register_deactivation_hook(__FILE__, 'deactivate_insurance_crm');

/**
 * The core plugin class
 */
require plugin_dir_path(__FILE__) . 'includes/class-insurance-crm.php';
require plugin_dir_path(__FILE__) . 'includes/email-templates.php';
require plugin_dir_path(__FILE__) . 'includes/helpdesk-functions.php';

/**
 * Begins execution of the plugin.
 */
function run_insurance_crm() {
    $plugin = new Insurance_CRM();
    $plugin->run();
}

run_insurance_crm();

/**
 * Custom capabilities for the plugin
 */
function insurance_crm_add_capabilities() {
    $roles = array('administrator', 'editor');
    $capabilities = array(
        'read_insurance_crm',
        'edit_insurance_crm',
        'edit_others_insurance_crm',
        'publish_insurance_crm',
        'read_private_insurance_crm',
        'manage_insurance_crm'
    );

    foreach ($roles as $role) {
        $role_obj = get_role($role);
        if (!empty($role_obj)) {
            foreach ($capabilities as $cap) {
                $role_obj->add_cap($cap);
            }
        }
    }
}
register_activation_hook(__FILE__, 'insurance_crm_add_capabilities');

/**
 * Database tables creation
 */
function insurance_crm_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    // Tablo adını doğru şekilde tanımla
    $table_customers = $wpdb->prefix . 'insurance_crm_customers';

    $sql_customers = "CREATE TABLE IF NOT EXISTS $table_customers (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        first_name varchar(100) NOT NULL,
        last_name varchar(100) NOT NULL,
        tc_identity varchar(11) NOT NULL,
        email varchar(100) NOT NULL,
        phone varchar(20) NOT NULL,
        address text,
        category varchar(20) DEFAULT 'bireysel',
        status varchar(20) DEFAULT 'aktif',
        representative_id bigint(20) DEFAULT NULL,
        birth_date DATE DEFAULT NULL,
        gender varchar(10) DEFAULT NULL,
        is_pregnant TINYINT(1) DEFAULT 0,
        pregnancy_week INT DEFAULT NULL,
        occupation varchar(100) DEFAULT NULL,
        marital_status varchar(50) DEFAULT NULL,
        spouse_name varchar(100) DEFAULT NULL,
        spouse_tc_identity varchar(11) DEFAULT NULL,
        spouse_birth_date DATE DEFAULT NULL,
        children_count INT DEFAULT 0,
        children_names TEXT DEFAULT NULL,
        children_birth_dates TEXT DEFAULT NULL,
        children_tc_identities TEXT DEFAULT NULL,
        has_vehicle TINYINT(1) DEFAULT 0,
        vehicle_plate varchar(20) DEFAULT NULL,
        has_pet TINYINT(1) DEFAULT 0,
        pet_name varchar(50) DEFAULT NULL,
        pet_type varchar(50) DEFAULT NULL,
        pet_age varchar(20) DEFAULT NULL,
        owns_home TINYINT(1) DEFAULT 0,
        has_dask_policy TINYINT(1) DEFAULT 0,
        dask_policy_expiry DATE DEFAULT NULL,
        has_home_policy TINYINT(1) DEFAULT 0,
        home_policy_expiry DATE DEFAULT NULL,
        has_offer TINYINT(1) DEFAULT 0,
        offer_insurance_type VARCHAR(100) DEFAULT NULL,
        offer_amount DECIMAL(10,2) DEFAULT NULL,
        offer_expiry_date DATE DEFAULT NULL,
        offer_notes TEXT DEFAULT NULL,
        offer_reminder TINYINT(1) DEFAULT 0,
        first_recorder varchar(100) DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY tc_identity (tc_identity),
        KEY email (email),
        KEY status (status),
        KEY representative_id (representative_id)
    ) $charset_collate;";

    $table_policies = $wpdb->prefix . 'insurance_crm_policies';
    $sql_policies = "CREATE TABLE IF NOT EXISTS $table_policies (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        customer_id bigint(20) NOT NULL,
        policy_number varchar(50) NOT NULL,
        policy_type varchar(50) NOT NULL,
        start_date date NOT NULL,
        end_date date NOT NULL,
        premium_amount decimal(10,2) NOT NULL,
        status varchar(20) DEFAULT 'aktif',
        document_path varchar(255),
        representative_id bigint(20) DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY policy_number (policy_number),
        KEY customer_id (customer_id),
        KEY status (status),
        KEY end_date (end_date),
        KEY representative_id (representative_id)
    ) $charset_collate;";

    $table_tasks = $wpdb->prefix . 'insurance_crm_tasks';
    $sql_tasks = "CREATE TABLE IF NOT EXISTS $table_tasks (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        customer_id bigint(20) NOT NULL,
        policy_id bigint(20),
        task_description text NOT NULL,
        due_date datetime NOT NULL,
        priority varchar(20) DEFAULT 'medium',
        status varchar(20) DEFAULT 'pending',
        representative_id bigint(20) DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY customer_id (customer_id),
        KEY policy_id (policy_id),
        KEY status (status),
        KEY due_date (due_date),
        KEY representative_id (representative_id)
    ) $charset_collate;";

    $table_representatives = $wpdb->prefix . 'insurance_crm_representatives';
    $sql_representatives = "CREATE TABLE IF NOT EXISTS $table_representatives (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        title varchar(100) NOT NULL,
        phone varchar(20) NOT NULL,
        department varchar(100) NOT NULL,
        monthly_target decimal(10,2) DEFAULT 0.00,
        status varchar(20) DEFAULT 'active',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY user_id (user_id),
        KEY status (status)
    ) $charset_collate;";

    // Import mapping preferences table
    $table_import_mappings = $wpdb->prefix . 'insurance_crm_import_mappings';
    $sql_import_mappings = "CREATE TABLE IF NOT EXISTS $table_import_mappings (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        file_format varchar(50) NOT NULL,
        mapping_data TEXT NOT NULL,
        representative_id bigint(20) DEFAULT NULL,
        is_default TINYINT(1) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY file_format (file_format),
        KEY representative_id (representative_id)
    ) $charset_collate;";

    // Import history table
    $table_import_history = $wpdb->prefix . 'insurance_crm_import_history';
    $sql_import_history = "CREATE TABLE IF NOT EXISTS $table_import_history (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        file_format varchar(50) NOT NULL,
        file_name varchar(255) NOT NULL,
        records_processed int(11) DEFAULT 0,
        records_successful int(11) DEFAULT 0,
        records_failed int(11) DEFAULT 0,
        processing_time int(11) DEFAULT 0,
        status varchar(20) DEFAULT 'completed',
        error_log TEXT DEFAULT NULL,
        representative_id bigint(20) DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY file_format (file_format),
        KEY representative_id (representative_id),
        KEY status (status)
    ) $charset_collate;";

    // User login logs table
    $table_user_logs = $wpdb->prefix . 'insurance_user_logs';
    $sql_user_logs = "CREATE TABLE IF NOT EXISTS $table_user_logs (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        action varchar(50) NOT NULL,
        ip_address varchar(45) NOT NULL,
        user_agent text,
        browser varchar(100),
        device varchar(100),
        location varchar(100),
        session_duration int(11) DEFAULT NULL,
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY action (action),
        KEY created_at (created_at)
    ) $charset_collate;";

    // System operation logs table
    $table_system_logs = $wpdb->prefix . 'insurance_system_logs';
    $sql_system_logs = "CREATE TABLE IF NOT EXISTS $table_system_logs (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        action varchar(100) NOT NULL,
        table_name varchar(50) NOT NULL,
        record_id bigint(20),
        old_values longtext,
        new_values longtext,
        ip_address varchar(45) NOT NULL,
        user_agent text,
        details text,
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY action (action),
        KEY table_name (table_name),
        KEY record_id (record_id),
        KEY created_at (created_at)
    ) $charset_collate;";

    // Helpdesk tickets table
    $table_helpdesk_tickets = $wpdb->prefix . 'insurance_crm_helpdesk_tickets';
    $sql_helpdesk_tickets = "CREATE TABLE IF NOT EXISTS $table_helpdesk_tickets (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        ticket_number varchar(50) NOT NULL,
        user_id bigint(20) NOT NULL,
        representative_id bigint(20) DEFAULT NULL,
        category varchar(50) NOT NULL,
        priority varchar(20) NOT NULL,
        subject varchar(255) NOT NULL,
        description text NOT NULL,
        status varchar(20) DEFAULT 'open',
        attachments text DEFAULT NULL,
        debug_log_included tinyint(1) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY ticket_number (ticket_number),
        KEY user_id (user_id),
        KEY representative_id (representative_id),
        KEY status (status),
        KEY priority (priority),
        KEY category (category)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_customers);
    dbDelta($sql_policies);
    dbDelta($sql_tasks);
    dbDelta($sql_representatives);
    dbDelta($sql_import_mappings);
    dbDelta($sql_import_history);
    dbDelta($sql_user_logs);
    dbDelta($sql_system_logs);
    dbDelta($sql_helpdesk_tickets);

    $wpdb->query("ALTER TABLE {$wpdb->prefix}insurance_crm_customers ADD COLUMN IF NOT EXISTS representative_id bigint(20) DEFAULT NULL");
    
    // Add first_recorder column for İlk Kayıt Eden information
    $wpdb->query("ALTER TABLE {$wpdb->prefix}insurance_crm_customers ADD COLUMN IF NOT EXISTS first_recorder varchar(100) DEFAULT NULL");
    $wpdb->query("ALTER TABLE {$wpdb->prefix}insurance_crm_customers ADD KEY IF NOT EXISTS representative_id (representative_id)");

    $wpdb->query("ALTER TABLE {$wpdb->prefix}insurance_crm_policies ADD COLUMN IF NOT EXISTS representative_id bigint(20) DEFAULT NULL");
    $wpdb->query("ALTER TABLE {$wpdb->prefix}insurance_crm_policies ADD KEY IF NOT EXISTS representative_id (representative_id)");

    $wpdb->query("ALTER TABLE {$wpdb->prefix}insurance_crm_tasks ADD COLUMN IF NOT EXISTS representative_id bigint(20) DEFAULT NULL");
    $wpdb->query("ALTER TABLE {$wpdb->prefix}insurance_crm_tasks ADD KEY IF NOT EXISTS representative_id (representative_id)");
}
register_activation_hook(__FILE__, 'insurance_crm_create_tables');

/**
 * Otomatik yenileme hatırlatmaları için cron job
 */
function insurance_crm_schedule_cron_job() {
    if (!wp_next_scheduled('insurance_crm_daily_cron')) {
        wp_schedule_event(time(), 'daily', 'insurance_crm_daily_cron');
    }
}
register_activation_hook(__FILE__, 'insurance_crm_schedule_cron_job');

/**
 * Cron job kaldırma
 */
function insurance_crm_unschedule_cron_job() {
    wp_clear_scheduled_hook('insurance_crm_daily_cron');
}
register_deactivation_hook(__FILE__, 'insurance_crm_unschedule_cron_job');

/**
 * Veritabanı kurulumu için tablo kontrolü
 */
function insurance_crm_check_db_tables() {
    global $wpdb;
    
    $tables = array(
        'insurance_crm_customers',
        'insurance_crm_policies',
        'insurance_crm_tasks',
        'insurance_crm_representatives',
        'insurance_crm_helpdesk_tickets',
        'insurance_user_logs',
        'insurance_system_logs'
    );
    
    $missing_tables = array();
    
    foreach ($tables as $table) {
        $table_name = $wpdb->prefix . $table;
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $missing_tables[] = $table;
        }
    }
    
    if (!empty($missing_tables)) {
        error_log('Insurance CRM: Missing tables detected: ' . implode(', ', $missing_tables));
        insurance_crm_create_tables();
    }
    
    // Her durumda teklif alanlarının olduğundan emin olalım
    force_update_crm_db();

    // notes sütunu var mı kontrol et
    $notes_column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}insurance_crm_representatives LIKE 'notes'");
    
    // Sütun yoksa ekle
    if (empty($notes_column_exists)) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}insurance_crm_representatives ADD COLUMN notes TEXT NULL AFTER title");
        error_log('insurance_crm_representatives tablosuna notes sütunu eklendi.');
    }
    
    // Minimum policy count ve minimum premium amount sütunlarını kontrol et ve ekle
    $min_policy_count_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}insurance_crm_representatives LIKE 'minimum_policy_count'");
    $min_premium_amount_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}insurance_crm_representatives LIKE 'minimum_premium_amount'");
    
    if (empty($min_policy_count_exists)) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}insurance_crm_representatives ADD COLUMN minimum_policy_count INT DEFAULT 10 AFTER monthly_target");
        error_log('insurance_crm_representatives tablosuna minimum_policy_count sütunu eklendi.');
    }
    
    if (empty($min_premium_amount_exists)) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}insurance_crm_representatives ADD COLUMN minimum_premium_amount DECIMAL(10,2) DEFAULT 300000.00 AFTER minimum_policy_count");
        error_log('insurance_crm_representatives tablosuna minimum_premium_amount sütunu eklendi.');
    }
    
    // Müşteri tablosuna yeni alanları ekle
    $vehicle_document_serial_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}insurance_crm_customers LIKE 'vehicle_document_serial'");
    $uavt_code_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}insurance_crm_customers LIKE 'uavt_code'");
    
    if (empty($vehicle_document_serial_exists)) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}insurance_crm_customers ADD COLUMN vehicle_document_serial VARCHAR(50) DEFAULT NULL AFTER vehicle_plate");
        error_log('insurance_crm_customers tablosuna vehicle_document_serial sütunu eklendi.');
    }
    
    if (empty($uavt_code_exists)) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}insurance_crm_customers ADD COLUMN uavt_code VARCHAR(50) DEFAULT NULL AFTER address");
        error_log('insurance_crm_customers tablosuna uavt_code sütunu eklendi.');
    }
}

add_action('plugins_loaded', 'insurance_crm_check_db_tables');

/**
 * Günlük kontroller ve bildirimler
 */
function insurance_crm_daily_tasks() {
    global $wpdb;
    $settings = get_option('insurance_crm_settings');
    $renewal_days = isset($settings['renewal_reminder_days']) ? intval($settings['renewal_reminder_days']) : 30;
    $task_days = isset($settings['task_reminder_days']) ? intval($settings['task_reminder_days']) : 1;

    // Poliçe durum güncellemesi: 30 günü geçmiş aktif poliçeleri pasif yap
    $thirty_days_ago = date('Y-m-d', strtotime('-30 days'));
    $updated_policies = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}insurance_crm_policies 
         SET status = 'pasif' 
         WHERE status = 'aktif' 
         AND cancellation_date IS NULL 
         AND end_date < %s",
        $thirty_days_ago
    ));
    
    if ($updated_policies > 0) {
        error_log("Insurance CRM: {$updated_policies} policies auto-updated to passive status for expiry > 30 days");
    }

    $upcoming_renewals = $wpdb->get_results($wpdb->prepare(
        "SELECT p.*, c.first_name, c.last_name, c.email 
         FROM {$wpdb->prefix}insurance_crm_policies p
         JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
         WHERE p.status = 'aktif' 
         AND p.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL %d DAY)",
        $renewal_days
    ));

    $upcoming_tasks = $wpdb->get_results($wpdb->prepare(
        "SELECT t.*, c.first_name, c.last_name, c.email 
         FROM {$wpdb->prefix}insurance_crm_tasks t
         JOIN {$wpdb->prefix}insurance_crm_customers c ON t.customer_id = c.id
         WHERE t.status = 'pending'
         AND t.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL %d DAY)",
        $task_days
    ));

    if (!empty($upcoming_renewals) || !empty($upcoming_tasks)) {
        $notification_email = isset($settings['notification_email']) ? $settings['notification_email'] : get_option('admin_email');
        $company_name = isset($settings['company_name']) ? $settings['company_name'] : get_bloginfo('name');

        // Build HTML content for daily summary
        $html_content = '<h2>Günlük Sistem Hatırlatmaları</h2>';
        $html_content .= '<p>Merhaba,</p>';
        $html_content .= '<p>Aşağıda günlük sistem hatırlatmalarınız bulunmaktadır:</p>';

        if (!empty($upcoming_renewals)) {
            $html_content .= '<h3 style="color: #1976d2; margin-top: 30px;">Yaklaşan Poliçe Yenilemeleri</h3>';
            
            foreach ($upcoming_renewals as $renewal) {
                $days_left = ceil((strtotime($renewal->end_date) - time()) / (60 * 60 * 24));
                $urgency_class = $days_left <= 7 ? 'style="border-left-color: #dc3545;"' : '';
                
                $html_content .= '<div class="info-card" ' . $urgency_class . '>';
                $html_content .= '<div class="info-row">';
                $html_content .= '<span class="info-label">Müşteri:</span>';
                $html_content .= '<span class="info-value">' . esc_html($renewal->first_name . ' ' . $renewal->last_name) . '</span>';
                $html_content .= '</div>';
                $html_content .= '<div class="info-row">';
                $html_content .= '<span class="info-label">Poliçe No:</span>';
                $html_content .= '<span class="info-value">' . esc_html($renewal->policy_number) . '</span>';
                $html_content .= '</div>';
                $html_content .= '<div class="info-row">';
                $html_content .= '<span class="info-label">Poliçe Türü:</span>';
                $html_content .= '<span class="info-value">' . esc_html($renewal->policy_type) . '</span>';
                $html_content .= '</div>';
                $html_content .= '<div class="info-row">';
                $html_content .= '<span class="info-label">Bitiş Tarihi:</span>';
                $html_content .= '<span class="info-value">' . date('d.m.Y', strtotime($renewal->end_date)) . ' (' . $days_left . ' gün kaldı)</span>';
                $html_content .= '</div>';
                if (!empty($renewal->premium_amount)) {
                    $html_content .= '<div class="info-row">';
                    $html_content .= '<span class="info-label">Prim Tutarı:</span>';
                    $html_content .= '<span class="info-value">' . number_format($renewal->premium_amount, 2) . ' TL</span>';
                    $html_content .= '</div>';
                }
                $html_content .= '</div>';
            }
        }

        if (!empty($upcoming_tasks)) {
            $html_content .= '<h3 style="color: #1976d2; margin-top: 30px;">Yaklaşan Görevler</h3>';
            
            foreach ($upcoming_tasks as $task) {
                $days_left = ceil((strtotime($task->due_date) - time()) / (60 * 60 * 24));
                $urgency_class = $days_left <= 1 ? 'style="border-left-color: #dc3545;"' : '';
                
                $html_content .= '<div class="info-card" ' . $urgency_class . '>';
                $html_content .= '<div class="info-row">';
                $html_content .= '<span class="info-label">Müşteri:</span>';
                $html_content .= '<span class="info-value">' . esc_html($task->first_name . ' ' . $task->last_name) . '</span>';
                $html_content .= '</div>';
                $html_content .= '<div class="info-row">';
                $html_content .= '<span class="info-label">Görev:</span>';
                $html_content .= '<span class="info-value">' . esc_html($task->task_description) . '</span>';
                $html_content .= '</div>';
                $html_content .= '<div class="info-row">';
                $html_content .= '<span class="info-label">Tamamlanma Tarihi:</span>';
                $html_content .= '<span class="info-value">' . date('d.m.Y H:i', strtotime($task->due_date)) . ' (' . $days_left . ' gün kaldı)</span>';
                $html_content .= '</div>';
                if (!empty($task->priority)) {
                    $html_content .= '<div class="info-row">';
                    $html_content .= '<span class="info-label">Öncelik:</span>';
                    $html_content .= '<span class="info-value">' . esc_html($task->priority) . '</span>';
                    $html_content .= '</div>';
                }
                $html_content .= '</div>';
            }
        }

        $html_content .= '<p style="margin-top: 30px;">Bu hatırlatmaları sistem panelinden kontrol edebilirsiniz.</p>';
        $html_content .= '<p>Saygılarımızla.</p>';

        // Send HTML email using template system
        insurance_crm_send_template_email(
            $notification_email,
            'Insurance CRM - Günlük Hatırlatmalar',
            $html_content,
            array(
                'company_name' => $company_name
            )
        );
    }
}
add_action('insurance_crm_daily_cron', 'insurance_crm_daily_tasks');

/**
 * Müşteri Temsilcisi rolü ve yetkileri
 */
function insurance_crm_add_representative_role() {
    add_role('insurance_representative', 'Müşteri Temsilcisi', array(
        'read' => true,
        'upload_files' => true,
        'read_insurance_crm' => true,
        'edit_insurance_crm' => true,
        'publish_insurance_crm' => true
    ));
}
register_activation_hook(__FILE__, 'insurance_crm_add_representative_role');

/**
 * Yeni müşteri temsilcisi oluşturulduğunda otomatik kullanıcı oluştur
 */
function insurance_crm_create_representative_user($rep_id, $data) {
    $username = sanitize_user(strtolower($data['first_name'] . '.' . $data['last_name']));
    
    $original_username = $username;
    $count = 1;
    while (username_exists($username)) {
        $username = $original_username . $count;
        $count++;
    }

    $password = wp_generate_password();
    
    $user_data = array(
        'user_login' => $username,
        'user_pass' => $password,
        'user_email' => $data['email'],
        'first_name' => $data['first_name'],
        'last_name' => $data['last_name'],
        'role' => 'insurance_representative'
    );

    $user_id = wp_insert_user($user_data);

    if (!is_wp_error($user_id)) {
        update_user_meta($user_id, '_insurance_representative_id', $rep_id);
        wp_send_new_user_notifications($user_id, 'both');
        return $user_id;
    }

    return false;
}

/**
 * Admin initialize
 */
function insurance_crm_admin_init() {
    if (!class_exists('Insurance_CRM_Admin')) {
        require_once plugin_dir_path(__FILE__) . 'admin/class-insurance-crm-admin.php';
    }
    
    // Handle manual table creation request
    if (isset($_GET['insurance_crm_create_tables']) && $_GET['insurance_crm_create_tables'] === '1') {
        if (current_user_can('manage_options') && wp_verify_nonce($_GET['_wpnonce'], 'insurance_crm_create_tables')) {
            insurance_crm_create_tables();
            Insurance_CRM_Activator::activate();
            wp_redirect(admin_url('admin.php?page=insurance-crm&tables_created=1'));
            exit;
        }
    }
    
    // Handle test logs creation
    if (isset($_GET['test_logs']) && $_GET['test_logs'] === '1') {
        if (current_user_can('manage_options') && wp_verify_nonce($_GET['_wpnonce'], 'test_logs')) {
            global $wpdb;
            $user = wp_get_current_user();
            
            // Test user login log
            $wpdb->insert(
                $wpdb->prefix . 'insurance_user_logs',
                array(
                    'user_id' => $user->ID,
                    'action' => 'login',
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Test Browser',
                    'browser' => 'Test Browser',
                    'device' => 'Desktop',
                    'location' => 'Test Location',
                    'created_at' => current_time('mysql')
                )
            );
            
            // Test system log
            $wpdb->insert(
                $wpdb->prefix . 'insurance_system_logs',
                array(
                    'user_id' => $user->ID,
                    'action' => 'test_action',
                    'table_name' => 'test_table',
                    'record_id' => 1,
                    'old_values' => json_encode(array('test' => 'old_value')),
                    'new_values' => json_encode(array('test' => 'new_value')),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Test Browser',
                    'details' => 'Test log entry created for demonstration',
                    'created_at' => current_time('mysql')
                )
            );
            
            wp_redirect(admin_url('admin.php?page=insurance-crm&test_logs_created=1'));
            exit;
        }
    }
    
    $admin_dir = plugin_dir_path(__FILE__) . 'admin/';
    if (!file_exists($admin_dir)) {
        mkdir($admin_dir, 0755, true);
    }
    
    $users_page_path = $admin_dir . 'users-page.php';
    if (!file_exists($users_page_path)) {
        $users_page_content = '<?php
if (!defined("ABSPATH")) {
    exit;
}

function insurance_crm_users_page() {
    echo "<div class=\"wrap\">";
    echo "<h1>Kullanıcı Yönetimi</h1>";
    echo "</div>";
}';
        file_put_contents($users_page_path, $users_page_content);
    }
}
add_action('admin_init', 'insurance_crm_admin_init');

/**
 * Get total premium amount
 */
function insurance_crm_get_total_premium($customer_id = null) {
    global $wpdb;
    
    if ($customer_id) {
        $query = $wpdb->prepare(
            "SELECT SUM(premium_amount) as total_premium 
             FROM {$wpdb->prefix}insurance_crm_policies 
             WHERE customer_id = %d",
            $customer_id
        );
    } else {
        $query = "SELECT SUM(premium_amount) as total_premium 
                  FROM {$wpdb->prefix}insurance_crm_policies";
    }
    
    return $wpdb->get_var($query);
}

/**
 * Plugin'i etkinleştirirken örnek veri ekle
 */
function insurance_crm_add_sample_data() {
    if (get_option('insurance_crm_sample_data_added')) {
        return;
    }
    
    if (!get_role('insurance_representative')) {
        add_role('insurance_representative', 'Müşteri Temsilcisi', array(
            'read' => true,
            'upload_files' => true,
            'read_insurance_crm' => true,
            'edit_insurance_crm' => true,
            'publish_insurance_crm' => true
        ));
    }
    
    $username = 'temsilci';
    if (!username_exists($username) && !email_exists('temsilci@example.com')) {
        $user_id = wp_create_user(
            $username,
            'temsilci123',
            'temsilci@example.com'
        );
        
        if (!is_wp_error($user_id)) {
            $user = new WP_User($user_id);
            $user->set_role('insurance_representative');
            
            global $wpdb;
            $wpdb->insert(
                $wpdb->prefix . 'insurance_crm_representatives',
                array(
                    'user_id' => $user_id,
                    'title' => 'Kıdemli Müşteri Temsilcisi',
                    'phone' => '5551234567',
                    'department' => 'Bireysel Satış',
                    'monthly_target' => 50000.00,
                    'status' => 'active'
                )
            );
        }
    }
    
    update_option('insurance_crm_sample_data_added', true);
}
register_activation_hook(__FILE__, 'insurance_crm_add_sample_data');

/**
 * Plugin güncelleme kontrolü ve işlemleri
 */
function insurance_crm_update_check() {
    $current_version = get_option('insurance_crm_version');
    
    if ($current_version !== INSURANCE_CRM_VERSION) {
        delete_option('insurance_crm_menu_initialized');
        delete_option('insurance_crm_menu_cache_cleared');
        update_option('insurance_crm_version', INSURANCE_CRM_VERSION);
        
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%transient%menu%'");
        
        insurance_crm_create_required_files();
    }
}
add_action('plugins_loaded', 'insurance_crm_update_check');

/**
 * 1.0.3 versiyonu için gerekli dosyaları oluştur
 */
function insurance_crm_create_required_files() {
    $admin_dir = INSURANCE_CRM_PATH . 'admin/';
    $partials_dir = $admin_dir . 'partials/';
    
    if (!file_exists($admin_dir)) {
        mkdir($admin_dir, 0755, true);
    }
    
    if (!file_exists($partials_dir)) {
        mkdir($partials_dir, 0755, true);
    }
    
    $representatives_content = '<?php
/**
 * Müşteri Temsilcileri Sayfası
 *
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/admin/partials
 * @author     Anadolu Birlik
 * @since      1.0.3
 */

if (!defined("WPINC")) {
    die;
}

$rep_id = isset($_GET["edit"]) ? intval($_GET["edit"]) : 0;
$editing = ($rep_id > 0);
$edit_rep = null;

if ($editing) {
    global $wpdb;
    $table_reps = $wpdb->prefix . "insurance_crm_representatives";
    $table_users = $wpdb->users;
    
    $edit_rep = $wpdb->get_row($wpdb->prepare(
        "SELECT r.*, u.user_email as email, u.display_name, u.user_login as username,
                u.first_name, u.last_name
         FROM $table_reps r 
         LEFT JOIN $table_users u ON r.user_id = u.ID 
         WHERE r.id = %d",
        $rep_id
    ));
    
    if (!$edit_rep) {
        $editing = false;
    }
}

if (isset($_POST["submit_representative"]) && isset($_POST["representative_nonce"]) && 
    wp_verify_nonce($_POST["representative_nonce"], "add_edit_representative")) {
    
    if ($editing) {
        $rep_data = array(
            "title" => sanitize_text_field($_POST["title"]),
            "phone" => sanitize_text_field($_POST["phone"]),
            "department" => sanitize_text_field($_POST["department"]),
            "monthly_target" => floatval($_POST["monthly_target"]),
            "updated_at" => current_time("mysql")
        );
        
        global $wpdb;
        $table_reps = $wpdb->prefix . "insurance_crm_representatives";
        
        $wpdb->update(
            $table_reps,
            $rep_data,
            array("id" => $rep_id)
        );
        
        if (isset($_POST["first_name"]) && isset($_POST["last_name"]) && isset($_POST["email"])) {
            $user_id = $edit_rep->user_id;
            wp_update_user(array(
                "ID" => $user_id,
                "first_name" => sanitize_text_field($_POST["first_name"]),
                "last_name" => sanitize_text_field($_POST["last_name"]),
                "display_name" => sanitize_text_field($_POST["first_name"]) . " " . sanitize_text_field($_POST["last_name"]),
                "user_email" => sanitize_email($_POST["email"])
            ));
        }
        
        if (!empty($_POST["password"]) && !empty($_POST["confirm_password"]) && $_POST["password"] == $_POST["confirm_password"]) {
            wp_set_password($_POST["password"], $edit_rep->user_id);
        }
        
        echo \'<div class="notice notice-success"><p>Müşteri temsilcisi güncellendi.</p></div>\';
        
        echo \'<script>window.location.href = "\' . admin_url("admin.php?page=insurance-crm-representatives") . \'";</script>\';
    } else {
        if (isset($_POST["username"]) && isset($_POST["password"]) && isset($_POST["confirm_password"])) {
            $username = sanitize_user($_POST["username"]);
            $password = $_POST["password"];
            $confirm_password = $_POST["confirm_password"];
            
            if (empty($username) || empty($password) || empty($confirm_password)) {
                echo \'<div class="notice notice-error"><p>Kullanıcı adı ve şifre alanlarını doldurunuz.</p></div>\';
            } else if ($password !== $confirm_password) {
                echo \'<div class="notice notice-error"><p>Şifreler eşleşmiyor.</p></div>\';
            } else if (username_exists($username)) {
                echo \'<div class="notice notice-error"><p>Bu kullanıcı adı zaten kullanımda.</p></div>\';
            } else if (email_exists($_POST["email"])) {
                echo \'<div class="notice notice-error"><p>Bu e-posta adresi zaten kullanımda.</p></div>\';
            } else {
                $user_id = wp_create_user($username, $password, sanitize_email($_POST["email"]));
                
                if (!is_wp_error($user_id)) {
                    wp_update_user(
                        array(
                            "ID" => $user_id,
                            "first_name" => sanitize_text_field($_POST["first_name"]),
                            "last_name" => sanitize_text_field($_POST["last_name"]),
                            "display_name" => sanitize_text_field($_POST["first_name"]) . " " . sanitize_text_field($_POST["last_name"])
                        )
                    );
                    
                    $user = new WP_User($user_id);
                    $user->set_role("insurance_representative");
                    
                    global $wpdb;
                    $table_name = $wpdb->prefix . "insurance_crm_representatives";
                    
                    $wpdb->insert(
                        $table_name,
                        array(
                            "user_id" => $user_id,
                            "title" => sanitize_text_field($_POST["title"]),
                            "phone" => sanitize_text_field($_POST["phone"]),
                            "department" => sanitize_text_field($_POST["department"]),
                            "monthly_target" => floatval($_POST["monthly_target"]),
                            "status" => "active",
                            "created_at" => current_time("mysql"),
                            "updated_at" => current_time("mysql")
                        )
                    );
                    
                    echo \'<div class="notice notice-success"><p>Müşteri temsilcisi başarıyla eklendi.</p></div>\';
                } else {
                    echo \'<div class="notice notice-error"><p>Kullanıcı oluşturulurken bir hata oluştu: \' . $user_id->get_error_message() . \'</p></div>\';
                }
            }
        } else {
            echo \'<div class="notice notice-error"><p>Gerekli alanlar doldurulmadı.</p></div>\';
        }
    }
}

global $wpdb;
$table_name = $wpdb->prefix . "insurance_crm_representatives";
$representatives = $wpdb->get_results(
    "SELECT r.*, u.user_email as email, u.display_name 
     FROM $table_name r 
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
     WHERE r.status = \'active\' 
     ORDER BY r.created_at DESC"
);
?>

<div class="wrap">
    <h1>Müşteri Temsilcileri</h1>
    
    <h2>Mevcut Müşteri Temsilcileri</h2>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Ad Soyad</th>
                <th>E-posta</th>
                <th>Ünvan</th>
                <th>Telefon</th>
                <th>Departman</th>
                <th>Aylık Hedef</th>
                <th>İşlemler</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($representatives as $rep): ?>
            <tr>
                <td><?php echo esc_html($rep->display_name); ?></td>
                <td><?php echo esc_html($rep->email); ?></td>
                <td><?php echo esc_html($rep->title); ?></td>
                <td><?php echo esc_html($rep->phone); ?></td>
                <td><?php echo esc_html($rep->department); ?></td>
                <td>₺<?php echo number_format($rep->monthly_target, 2); ?></td>
                <td>
                    <a href="<?php echo admin_url(\'admin.php?page=insurance-crm-representatives&edit=\' . $rep->id); ?>" 
                       class="button button-small">
                        Düzenle
                    </a>
                    <a href="<?php echo wp_nonce_url(admin_url(\'admin.php?page=insurance-crm-representatives&action=delete&id=\' . $rep->id), \'delete_representative_\' . $rep->id); ?>" 
                       class="button button-small" 
                       onclick="return confirm(\'Bu müşteri temsilcisini silmek istediğinizden emin misiniz?\');">
                        Sil
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <hr>
    
    <?php if ($editing): ?>
        <h2>Müşteri Temsilcisini Düzenle</h2>
    <?php else: ?>
        <h2>Yeni Müşteri Temsilcisi Ekle</h2>
    <?php endif; ?>
    
    <form method="post" action="">
        <?php wp_nonce_field("add_edit_representative", "representative_nonce"); ?>
        <?php if ($editing): ?>
            <input type="hidden" name="rep_id" value="<?php echo $rep_id; ?>">
        <?php endif; ?>
        
        <table class="form-table">
            <?php if (!$editing): ?>
                <tr>
                    <th><label for="username">Kullanıcı Adı</label></th>
                    <td><input type="text" name="username" id="username" class="regular-text" required></td>
                </tr>
            <?php endif; ?>
                
            <tr>
                <th><label for="password">Şifre</label></th>
                <td>
                    <input type="password" name="password" id="password" class="regular-text" <?php echo !$editing ? "required" : ""; ?>>
                    <p class="description">
                        <?php echo $editing ? "Değiştirmek istemiyorsanız boş bırakın." : "En az 8 karakter uzunluğunda olmalıdır."; ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="confirm_password">Şifre (Tekrar)</label></th>
                <td><input type="password" name="confirm_password" id="confirm_password" class="regular-text" <?php echo !$editing ? "required" : ""; ?>></td>
            </tr>
            <tr>
                <th><label for="first_name">Ad</label></th>
                <td>
                    <input type="text" name="first_name" id="first_name" class="regular-text" required
                           value="<?php echo $editing ? esc_attr($edit_rep->first_name) : ""; ?>">
                </td>
            </tr>
            <tr>
                <th><label for="last_name">Soyad</label></th>
                <td>
                    <input type="text" name="last_name" id="last_name" class="regular-text" required
                           value="<?php echo $editing ? esc_attr($edit_rep->last_name) : ""; ?>">
                </td>
            </tr>
            <tr>
                <th><label for="email">E-posta</label></th>
                <td>
                    <input type="email" name="email" id="email" class="regular-text" required
                           value="<?php echo $editing ? esc_attr($edit_rep->email) : ""; ?>">
                </td>
            </tr>
            <tr>
                <th><label for="title">Ünvan</label></th>
                <td>
                    <input type="text" name="title" id="title" class="regular-text" required
                           value="<?php echo $editing ? esc_attr($edit_rep->title) : ""; ?>">
                </td>
            </tr>
            <tr>
                <th><label for="phone">Telefon</label></th>
                <td>
                    <input type="tel" name="phone" id="phone" class="regular-text" required
                           value="<?php echo $editing ? esc_attr($edit_rep->phone) : ""; ?>">
                </td>
            </tr>
            <tr>
                <th><label for="department">Departman</label></th>
                <td>
                    <input type="text" name="department" id="department" class="regular-text"
                           value="<?php echo $editing ? esc_attr($edit_rep->department) : ""; ?>">
                </td>
            </tr>
            <tr>
                <th><label for="monthly_target">Aylık Hedef (₺)</label></th>
                <td>
                    <input type="number" step="0.01" name="monthly_target" id="monthly_target" class="regular-text" required
                           value="<?php echo $editing ? esc_attr($edit_rep->monthly_target) : ""; ?>">
                    <p class="description">Temsilcinin aylık satış hedefi (₺)</p>
                </td>
            </tr>
        </table>
        <p class="submit">
            <input type="submit" name="submit_representative" class="button button-primary" 
                   value="<?php echo $editing ? "Temsilciyi Güncelle" : "Müşteri Temsilcisi Ekle"; ?>">
            <?php if ($editing): ?>
                <a href="<?php echo admin_url("admin.php?page=insurance-crm-representatives"); ?>" class="button">İptal</a>
            <?php endif; ?>
        </p>
    </form>
</div>';
    
    file_put_contents($partials_dir . 'insurance-crm-representatives.php', $representatives_content);
    
    $templates_dir = INSURANCE_CRM_PATH . 'templates/';
    if (!file_exists($templates_dir)) {
        mkdir($templates_dir, 0755, true);
    }
}

/**
 * Admin menüleri ekle
 */
function insurance_crm_admin_menu() {
    static $menu_initialized = false;
    
    if ($menu_initialized) {
        return;
    }
    
    $menu_initialized = true;
    
    add_menu_page(
        'Insurance CRM',
        'Insurance CRM',
        'manage_options',
        'insurance-crm',
        'insurance_crm_dashboard',
        'dashicons-businessman',
        30
    );

    add_submenu_page(
        'insurance-crm',
        'Gösterge Paneli',
        'Gösterge Paneli',
        'manage_options',
        'insurance-crm',
        'insurance_crm_dashboard'
    );

    add_submenu_page(
        'insurance-crm',
        'Müşteriler',
        'Müşteriler',
        'manage_options',
        'insurance-crm-customers',
        'insurance_crm_customers'
    );

    add_submenu_page(
        'insurance-crm',
        'Müşteri Temsilcileri',
        'Müşteri Temsilcileri',
        'manage_options',
        'insurance-crm-representatives',
        'insurance_crm_display_representatives_page'
    );

    add_submenu_page(
        'insurance-crm',
        'Genel Duyurular',
        'Genel Duyurular',
        'manage_options',
        'insurance-crm-announcements',
        'insurance_crm_announcements'
    );

    update_option('insurance_crm_menu_initialized', 'yes');
}
add_action('admin_menu', 'insurance_crm_admin_menu');

/**
 * Tekrarlanan menüleri temizle
 */
function insurance_crm_remove_duplicate_menus() {
    global $submenu, $menu;
    
    if (isset($submenu) && isset($menu)) {
        $insurance_crm_menu_positions = array();
        
        foreach ($menu as $position => $menu_item) {
            if (isset($menu_item[2]) && $menu_item[2] === 'insurance-crm') {
                $insurance_crm_menu_positions[] = $position;
            }
        }
        
        if (count($insurance_crm_menu_positions) > 1) {
            $first_position = array_shift($insurance_crm_menu_positions);
            foreach ($insurance_crm_menu_positions as $position) {
                unset($menu[$position]);
            }
        }
        
        if (isset($submenu['insurance-crm'])) {
            $seen_items = array();
            $new_submenu = array();
            
            foreach ($submenu['insurance-crm'] as $position => $item) {
                $menu_slug = $item[2];
                
                if (!isset($seen_items[$menu_slug])) {
                    $seen_items[$menu_slug] = true;
                    $new_submenu[$position] = $item;
                }
            }
            
            if (!empty($new_submenu)) {
                $submenu['insurance-crm'] = $new_submenu;
            }
        }
    }
}
add_action('admin_menu', 'insurance_crm_remove_duplicate_menus', 9999);

/**
 * Admin sayfaları için callback fonksiyonları
 */
function insurance_crm_dashboard() {
    require_once INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-admin-dashboard.php';
}

/**
 * Duyuru sayfaları için callback fonksiyonları
 */
function insurance_crm_announcements() {
    require_once INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-admin-announcements.php';
}

/**
 * Müşteriler sayfası
 */
function insurance_crm_customers() {
    insurance_crm_check_customer_db_updates();
    
    if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])) {
        if (file_exists(INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-customer-view.php')) {
            require_once INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-customer-view.php';
            return;
        }
    }
    
    if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
        if (file_exists(INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-customer-edit.php')) {
            require_once INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-customer-edit.php';
            return;
        }
    }
    
    if (isset($_GET['action']) && $_GET['action'] === 'new') {
        if (file_exists(INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-customer-edit.php')) {
            require_once INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-customer-edit.php';
            return;
        }
    }
    
    require_once INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-admin-customers.php';
}

/**
 * Müşteri veritabanı tablo güncellemelerini kontrol et
 */
function insurance_crm_check_customer_db_updates() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    $table_name = $wpdb->prefix . 'insurance_crm_customers';
    
    $columns_to_add = array(
        'birth_date' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS birth_date DATE DEFAULT NULL",
        'gender' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS gender VARCHAR(10) DEFAULT NULL",
        'is_pregnant' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS is_pregnant TINYINT(1) DEFAULT 0",
        'pregnancy_week' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS pregnancy_week INT DEFAULT NULL",
        'occupation' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS occupation VARCHAR(100) DEFAULT NULL",
        'spouse_name' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS spouse_name VARCHAR(100) DEFAULT NULL",
        'spouse_birth_date' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS spouse_birth_date DATE DEFAULT NULL",
        'children_count' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS children_count INT DEFAULT 0",
        'children_names' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS children_names TEXT DEFAULT NULL",
        'children_birth_dates' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS children_birth_dates TEXT DEFAULT NULL",
        'has_vehicle' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS has_vehicle TINYINT(1) DEFAULT 0",
        'vehicle_plate' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS vehicle_plate VARCHAR(20) DEFAULT NULL",
        'has_pet' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS has_pet TINYINT(1) DEFAULT 0",
        'pet_name' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS pet_name VARCHAR(50) DEFAULT NULL",
        'pet_type' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS pet_type VARCHAR(50) DEFAULT NULL",
        'pet_age' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS pet_age VARCHAR(20) DEFAULT NULL",
        'owns_home' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS owns_home TINYINT(1) DEFAULT 0",
        'has_dask_policy' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS has_dask_policy TINYINT(1) DEFAULT 0",
        'dask_policy_expiry' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS dask_policy_expiry DATE DEFAULT NULL",
        'has_home_policy' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS has_home_policy TINYINT(1) DEFAULT 0",
        'home_policy_expiry' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS home_policy_expiry DATE DEFAULT NULL",
        'has_offer' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS has_offer TINYINT(1) DEFAULT 0",
        'offer_insurance_type' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS offer_insurance_type VARCHAR(100) DEFAULT NULL",
        'offer_amount' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS offer_amount DECIMAL(10,2) DEFAULT NULL",
        'offer_expiry_date' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS offer_expiry_date DATE DEFAULT NULL",
        'offer_notes' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS offer_notes TEXT DEFAULT NULL",
        'ilk_kayit_eden' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS ilk_kayit_eden BIGINT(20) DEFAULT NULL",
        'phone2' => "ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS phone2 VARCHAR(20) DEFAULT NULL"
    );
    
    foreach ($columns_to_add as $column => $query) {
        $wpdb->query($query);
    }
    
    $notes_table = $wpdb->prefix . 'insurance_crm_customer_notes';
    
    $sql = "CREATE TABLE IF NOT EXISTS $notes_table (
        id INT NOT NULL AUTO_INCREMENT,
        customer_id INT NOT NULL,
        note_content TEXT NOT NULL,
        note_type VARCHAR(20) NOT NULL,
        rejection_reason VARCHAR(50) DEFAULT NULL,
        created_by BIGINT(20) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY customer_id (customer_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    update_option('insurance_crm_customer_db_updated', 'yes');
}

/**
 * Mevcut kullanıcının temsilci ID'sini al
 */
if (!function_exists('get_current_user_rep_id')) {
    function get_current_user_rep_id() {
        global $wpdb;
        $current_user_id = get_current_user_id();
        return $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'",
            $current_user_id
        ));
    }
}

/**
 * Admin script ve stilleri ekle
 */
function insurance_crm_admin_scripts() {
    wp_enqueue_style('insurance-crm-admin-style', INSURANCE_CRM_URL . 'admin/css/insurance-crm-admin.css', array(), INSURANCE_CRM_VERSION, 'all');
    wp_enqueue_script('insurance-crm-admin-script', INSURANCE_CRM_URL . 'admin/js/insurance-crm-admin.js', array('jquery'), INSURANCE_CRM_VERSION, false);
}
add_action('admin_enqueue_scripts', 'insurance_crm_admin_scripts');

/**
 * Public script ve stilleri ekle
 */
function insurance_crm_public_scripts() {
    wp_enqueue_style('insurance-crm-public-style', INSURANCE_CRM_URL . 'public/css/insurance-crm-public.css', array(), INSURANCE_CRM_VERSION, 'all');
    wp_enqueue_script('insurance-crm-public-script', INSURANCE_CRM_URL . 'public/js/insurance-crm-public.js', array('jquery'), INSURANCE_CRM_VERSION, false);
    
    wp_enqueue_style('dashicons');
}
add_action('wp_enqueue_scripts', 'insurance_crm_public_scripts');

/**
 * AJAX işlemleri için hooks
 */
function insurance_crm_ajax_handler() {
    // Check nonce for security
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'insurance_crm_nonce')) {
        wp_die('Security check failed');
    }
    
    $action = sanitize_text_field($_POST['action_type'] ?? '');
    
    switch ($action) {
        case 'update_hierarchy':
            insurance_crm_ajax_update_hierarchy();
            break;
        case 'update_representative_role':
            insurance_crm_ajax_update_representative_role();
            break;
        case 'send_birthday_email':
            insurance_crm_ajax_send_birthday_email();
            break;
        default:
            wp_send_json_error('Invalid action');
    }
    
    wp_die();
}
add_action('wp_ajax_insurance_crm_ajax', 'insurance_crm_ajax_handler');

/**
 * AJAX handler for fixing all customer names
 */
function insurance_crm_fix_all_names() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'fix_names_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    // Check user permissions - Only Patron and Müdür roles
    $current_user = wp_get_current_user();
    if (!in_array('insurance_representative', (array)$current_user->roles)) {
        wp_send_json_error('Insufficient permissions');
    }
    
    // Get representative info and check role
    global $wpdb;
    $reps_table = $wpdb->prefix . 'insurance_crm_reps';
    $current_rep = $wpdb->get_row($wpdb->prepare(
        "SELECT role FROM $reps_table WHERE user_id = %d", 
        $current_user->ID
    ));
    
    // Only Patron (role 1) and Müdür (role 2) can fix names
    if (!$current_rep || !in_array($current_rep->role, [1, 2])) {
        wp_send_json_error('Bu işlem için Patron veya Müdür yetkisi gereklidir.');
    }
    
    global $wpdb;
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    
    // Get all customers
    $customers = $wpdb->get_results("SELECT id, first_name, last_name, spouse_name, children_names, company_name FROM $customers_table");
    
    $fixed_count = 0;
    
    foreach ($customers as $customer) {
        $updates = array();
        
        // Fix first name
        if (!empty($customer->first_name)) {
            $fixed_first_name = formatName($customer->first_name);
            if ($fixed_first_name !== $customer->first_name) {
                $updates['first_name'] = $fixed_first_name;
            }
        }
        
        // Fix last name
        if (!empty($customer->last_name)) {
            $fixed_last_name = formatLastName($customer->last_name);
            if ($fixed_last_name !== $customer->last_name) {
                $updates['last_name'] = $fixed_last_name;
            }
        }
        
        // Fix spouse name
        if (!empty($customer->spouse_name)) {
            $fixed_spouse_name = formatName($customer->spouse_name);
            if ($fixed_spouse_name !== $customer->spouse_name) {
                $updates['spouse_name'] = $fixed_spouse_name;
            }
        }
        
        // Fix children names
        if (!empty($customer->children_names)) {
            $children_names = explode(',', $customer->children_names);
            $fixed_children_names = array();
            $changed = false;
            
            foreach ($children_names as $child_name) {
                $child_name = trim($child_name);
                $fixed_child_name = formatName($child_name);
                $fixed_children_names[] = $fixed_child_name;
                if ($fixed_child_name !== $child_name) {
                    $changed = true;
                }
            }
            
            if ($changed) {
                $updates['children_names'] = implode(', ', $fixed_children_names);
            }
        }
        
        // Fix company name
        if (!empty($customer->company_name)) {
            $fixed_company_name = formatName($customer->company_name);
            if ($fixed_company_name !== $customer->company_name) {
                $updates['company_name'] = $fixed_company_name;
            }
        }
        
        // Update if there are changes
        if (!empty($updates)) {
            $wpdb->update($customers_table, $updates, array('id' => $customer->id));
            $fixed_count++;
        }
    }
    
    wp_send_json_success(array('fixed_count' => $fixed_count));
}

/**
 * Helper functions for name formatting
 */
function formatName($name) {
    if (empty($name)) return $name;
    
    // Convert to lowercase and split by spaces
    $words = explode(' ', mb_strtolower(trim($name), 'UTF-8'));
    $formatted_words = array();
    
    foreach ($words as $word) {
        if (!empty($word)) {
            // Capitalize first letter, handle Turkish characters
            $formatted_word = mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($word, 1, null, 'UTF-8');
            $formatted_words[] = $formatted_word;
        }
    }
    
    return implode(' ', $formatted_words);
}

function formatLastName($name) {
    if (empty($name)) return $name;
    
    // Convert to uppercase, handle Turkish characters
    return mb_strtoupper(trim($name), 'UTF-8');
}

add_action('wp_ajax_fix_all_names', 'insurance_crm_fix_all_names');

/**
 * AJAX handler for toggling offer status
 */
function insurance_crm_toggle_offer_status() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'toggle_offer_status')) {
        wp_send_json_error('Security check failed');
    }
    
    global $wpdb;
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    $reps_table = $wpdb->prefix . 'insurance_crm_representatives';
    $current_user_id = get_current_user_id();
    
    $customer_id = intval($_POST['customer_id']);
    $has_offer = intval($_POST['has_offer']);
    
    // Get customer data to check permissions
    $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customers_table WHERE id = %d", $customer_id));
    
    if (!$customer) {
        wp_send_json_error('Müşteri bulunamadı');
    }
    
    // Check permissions using the same function as the view
    // Administrator always has permission
    $can_edit = false;
    if (current_user_can('administrator')) {
        $can_edit = true;
    } else {
        // Get representative data
        $rep_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $reps_table WHERE user_id = %d",
            $current_user_id
        ));
        
        if ($rep_data) {
            // Role-based permission check
            if ($rep_data->role == 1) { // Patron
                $can_edit = true;
            } elseif ($rep_data->role == 2 && $rep_data->customer_edit == 1) { // Müdür with edit permission
                $can_edit = true;
            } elseif ($rep_data->role == 3 && $rep_data->customer_edit == 1) { // Müdür Yardımcısı with edit permission
                $can_edit = true;
            } elseif ($rep_data->role == 4 && $rep_data->customer_edit == 1) { // Ekip Lideri with edit permission
                // Team leader can only edit their team's customers
                if (function_exists('get_team_members')) {
                    $members = get_team_members($current_user_id);
                    $can_edit = in_array($customer->representative_id, $members);
                } else {
                    $can_edit = ($customer->representative_id == $rep_data->id);
                }
            } elseif ($rep_data->role == 5 && $rep_data->customer_edit == 1) { // Temsilci with edit permission
                $can_edit = ($customer->representative_id == $rep_data->id);
            }
        }
    }
    
    if (!$can_edit) {
        wp_send_json_error('Bu müşteriyi düzenleme yetkiniz yok');
    }
    
    $update_data = array('has_offer' => $has_offer);
    
    // If setting to no offer, clear offer fields
    if ($has_offer == 0) {
        $update_data['offer_insurance_type'] = null;
        $update_data['offer_amount'] = null;
        $update_data['offer_expiry_date'] = null;
        $update_data['offer_notes'] = null;
        $update_data['offer_reminder'] = 0;
    }
    
    $result = $wpdb->update($customers_table, $update_data, array('id' => $customer_id));
    
    if ($result !== false) {
        wp_send_json_success('Teklif durumu güncellendi');
    } else {
        wp_send_json_error('Güncelleme hatası');
    }
}

add_action('wp_ajax_toggle_offer_status', 'insurance_crm_toggle_offer_status');

/**
 * AJAX handler for customer search in task form
 */
function insurance_crm_search_customers_for_task() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'customer_search')) {
        wp_send_json_error('Security check failed');
    }
    
    // Check user permissions
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    global $wpdb;
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    
    $search_term = sanitize_text_field($_POST['search_term']);
    
    // Check if search term is provided
    if (empty($search_term)) {
        wp_send_json_error('Search term is required');
    }
    
    // Build search query
    $search_query = "
        SELECT id, first_name, last_name, tc_identity, tax_number, phone, company_name
        FROM $customers_table 
        WHERE (
            CONCAT(first_name, ' ', last_name) LIKE %s
            OR tc_identity LIKE %s
            OR tax_number LIKE %s
            OR phone LIKE %s
            OR company_name LIKE %s
        )
        AND status = 'aktif'
        ORDER BY 
            CASE 
                WHEN company_name IS NOT NULL AND company_name != '' THEN company_name
                ELSE CONCAT(first_name, ' ', last_name)
            END
        LIMIT 10
    ";
    
    $search_param = '%' . $wpdb->esc_like($search_term) . '%';
    $customers = $wpdb->get_results($wpdb->prepare(
        $search_query,
        $search_param,
        $search_param,
        $search_param,
        $search_param,
        $search_param
    ));
    
    // Check for database errors
    if ($wpdb->last_error) {
        error_log('Customer search database error: ' . $wpdb->last_error);
        wp_send_json_error('Database error occurred');
    }
    
    if ($customers) {
        wp_send_json_success($customers);
    } else {
        wp_send_json_error('No customers found');
    }
}

add_action('wp_ajax_search_customers_for_task', 'insurance_crm_search_customers_for_task');

/**
 * AJAX handler for getting customer policies for task form
 */
function insurance_crm_get_customer_policies_for_tasks() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'get_policies_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    // Check user permissions
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    global $wpdb;
    $policies_table = $wpdb->prefix . 'insurance_crm_policies';
    
    $customer_id = intval($_POST['customer_id']);
    
    // Check if customer ID is provided
    if (empty($customer_id)) {
        wp_send_json_error('Customer ID is required');
    }
    
    // Get active policies for the customer
    $policies = $wpdb->get_results($wpdb->prepare(
        "SELECT id, policy_number, policy_type, insurance_company, status, start_date, end_date 
         FROM {$policies_table} 
         WHERE customer_id = %d AND status != 'iptal' 
         ORDER BY created_at DESC",
        $customer_id
    ));
    
    if ($policies) {
        wp_send_json_success($policies);
    } else {
        wp_send_json_success(array()); // Empty array but still success
    }
}

add_action('wp_ajax_get_customer_policies_for_tasks', 'insurance_crm_get_customer_policies_for_tasks');

/**
 * AJAX handler for hierarchy updates
 */
function insurance_crm_ajax_update_hierarchy() {
    // Check permissions
    if (!current_user_can('manage_insurance_crm') && !is_patron(get_current_user_id())) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $patron_id = intval($_POST['patron_id'] ?? 0);
    $manager_id = intval($_POST['manager_id'] ?? 0);
    $assistant_manager_ids = array_map('intval', $_POST['assistant_manager_ids'] ?? []);
    
    // Validate representative IDs exist
    global $wpdb;
    $valid_rep_ids = $wpdb->get_col(
        "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE status = 'active'"
    );
    
    if ($patron_id && !in_array($patron_id, $valid_rep_ids)) {
        wp_send_json_error('Invalid patron ID');
    }
    
    if ($manager_id && !in_array($manager_id, $valid_rep_ids)) {
        wp_send_json_error('Invalid manager ID');
    }
    
    foreach ($assistant_manager_ids as $assistant_id) {
        if ($assistant_id && !in_array($assistant_id, $valid_rep_ids)) {
            wp_send_json_error('Invalid assistant manager ID');
        }
    }
    
    // Update hierarchy
    $settings = get_option('insurance_crm_settings', []);
    $settings['management_hierarchy'] = [
        'patron_id' => $patron_id,
        'manager_id' => $manager_id,
        'assistant_manager_ids' => array_filter($assistant_manager_ids),
        'updated_at' => current_time('mysql')
    ];
    
    $result = update_option('insurance_crm_settings', $settings);
    
    if ($result) {
        // Also update representative roles in database
        insurance_crm_sync_hierarchy_roles($patron_id, $manager_id, $assistant_manager_ids);
        wp_send_json_success('Hierarchy updated successfully');
    } else {
        wp_send_json_error('Failed to update hierarchy');
    }
}

/**
 * AJAX handler for individual representative role updates
 */
function insurance_crm_ajax_update_representative_role() {
    // Check permissions
    if (!current_user_can('manage_insurance_crm') && !is_patron(get_current_user_id())) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $rep_id = intval($_POST['rep_id'] ?? 0);
    $new_role = intval($_POST['new_role'] ?? 5);
    
    // Validate role
    $valid_roles = [1, 2, 3, 4, 5]; // Patron, Müdür, Müdür Yardımcısı, Ekip Lideri, Müşteri Temsilcisi
    if (!in_array($new_role, $valid_roles)) {
        wp_send_json_error('Invalid role');
    }
    
    // Update representative role
    global $wpdb;
    $result = $wpdb->update(
        $wpdb->prefix . 'insurance_crm_representatives',
        ['role' => $new_role],
        ['id' => $rep_id],
        ['%d'],
        ['%d']
    );
    
    if ($result !== false) {
        wp_send_json_success('Representative role updated successfully');
    } else {
        wp_send_json_error('Failed to update representative role');
    }
}

/**
 * AJAX handler for sending birthday celebration emails
 */
function insurance_crm_ajax_send_birthday_email() {
    // Check if user is logged in
    if (!is_user_logged_in()) {
        wp_send_json_error('User not logged in');
    }
    
    $customer_id = intval($_POST['customer_id'] ?? 0);
    
    if (!$customer_id) {
        wp_send_json_error('Invalid customer ID');
    }
    
    // Include dashboard functions for birthday email function
    require_once(INSURANCE_CRM_PATH . 'includes/dashboard-functions.php');
    
    $result = send_birthday_celebration_email($customer_id, get_current_user_id());
    
    if ($result) {
        wp_send_json_success('Doğum günü kutlama e-postası başarıyla gönderildi!');
    } else {
        wp_send_json_error('E-posta gönderimi başarısız oldu. Lütfen tekrar deneyin.');
    }
}

/**
 * Sync hierarchy changes with representative roles
 */
function insurance_crm_sync_hierarchy_roles($patron_id, $manager_id, $assistant_manager_ids) {
    global $wpdb;
    
    // Reset all roles to default (5 = Müşteri Temsilcisi) except team leaders
    $wpdb->query("
        UPDATE {$wpdb->prefix}insurance_crm_representatives 
        SET role = 5 
        WHERE role IN (1, 2, 3)
    ");
    
    // Set patron role
    if ($patron_id) {
        $wpdb->update(
            $wpdb->prefix . 'insurance_crm_representatives',
            ['role' => 1],
            ['id' => $patron_id],
            ['%d'],
            ['%d']
        );
    }
    
    // Set manager role
    if ($manager_id) {
        $wpdb->update(
            $wpdb->prefix . 'insurance_crm_representatives',
            ['role' => 2],
            ['id' => $manager_id],
            ['%d'],
            ['%d']
        );
    }
    
    // Set assistant manager roles
    foreach ($assistant_manager_ids as $assistant_id) {
        if ($assistant_id) {
            $wpdb->update(
                $wpdb->prefix . 'insurance_crm_representatives',
                ['role' => 3],
                ['id' => $assistant_id],
                ['%d'],
                ['%d']
            );
        }
    }
}

/**
 * Müşteri Temsilcileri sayfasını görüntüle
 */
function insurance_crm_display_representatives_page() {
    if (file_exists(INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-representatives.php')) {
        require_once INSURANCE_CRM_PATH . 'admin/partials/insurance-crm-representatives.php';
    } else {
        if (!current_user_can('manage_insurance_crm')) {
            wp_die(__('Bu sayfaya erişim izniniz yok.'));
        }

        if (isset($_POST['submit_representative']) && isset($_POST['representative_nonce']) && 
            wp_verify_nonce($_POST['representative_nonce'], 'add_edit_representative')) {
            
            $rep_data = array(
                'first_name' => sanitize_text_field($_POST['first_name']),
                'last_name' => sanitize_text_field($_POST['last_name']),
                'email' => sanitize_email($_POST['email']),
                'title' => sanitize_text_field($_POST['title']),
                'phone' => sanitize_text_field($_POST['phone']),
                'department' => sanitize_text_field($_POST['department']),
                'monthly_target' => floatval($_POST['monthly_target'])
            );

            global $wpdb;
            $table_name = $wpdb->prefix . 'insurance_crm_representatives';

            if (isset($_POST['rep_id']) && !empty($_POST['rep_id'])) {
                $wpdb->update(
                    $table_name,
                    array(
                        'title' => $rep_data['title'],
                        'phone' => $rep_data['phone'],
                        'department' => $rep_data['department'],
                        'monthly_target' => $rep_data['monthly_target'],
                        'updated_at' => current_time('mysql')
                    ),
                    array('id' => intval($_POST['rep_id']))
                );
                echo '<div class="notice notice-success"><p>Müşteri temsilcisi güncellendi.</p></div>';
            } else {
                if (isset($_POST['username']) && isset($_POST['password']) && isset($_POST['confirm_password'])) {
                    $username = sanitize_user($_POST['username']);
                    $password = $_POST['password'];
                    $confirm_password = $_POST['confirm_password'];
                    
                    if (empty($username) || empty($password) || empty($confirm_password)) {
                        echo '<div class="notice notice-error"><p>Kullanıcı adı ve şifre alanlarını doldurunuz.</p></div>';
                    } else if ($password !== $confirm_password) {
                        echo '<div class="notice notice-error"><p>Şifreler eşleşmiyor.</p></div>';
                    } else if (username_exists($username)) {
                        echo '<div class="notice notice-error"><p>Bu kullanıcı adı zaten kullanımda.</p></div>';
                    } else if (email_exists($rep_data['email'])) {
                        echo '<div class="notice notice-error"><p>Bu e-posta adresi zaten kullanımda.</p></div>';
                    } else {
                        $user_id = wp_create_user($username, $password, $rep_data['email']);
                        
                        if (!is_wp_error($user_id)) {
                            wp_update_user(
                                array(
                                    'ID' => $user_id,
                                    'first_name' => $rep_data['first_name'],
                                    'last_name' => $rep_data['last_name'],
                                    'display_name' => $rep_data['first_name'] . ' ' . $rep_data['last_name']
                                )
                            );
                            
                            $user = new WP_User($user_id);
                            $user->set_role('insurance_representative');
                            
                            $wpdb->insert(
                                $table_name,
                                array(
                                    'user_id' => $user_id,
                                    'title' => $rep_data['title'],
                                    'phone' => $rep_data['phone'],
                                    'department' => $rep_data['department'],
                                    'monthly_target' => $rep_data['monthly_target'],
                                    'status' => 'active',
                                    'created_at' => current_time('mysql'),
                                    'updated_at' => current_time('mysql')
                                )
                            );
                            
                            echo '<div class="notice notice-success"><p>Müşteri temsilcisi başarıyla eklendi.</p></div>';
                        } else {
                            echo '<div class="notice notice-error"><p>Kullanıcı oluşturulurken bir hata oluştu: ' . $user_id->get_error_message() . '</p></div>';
                        }
                    }
                } else {
                    echo '<div class="notice notice-error"><p>Gerekli alanlar doldurulmadı.</p></div>';
                }
            }
        }

        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_representative_' . $_GET['id'])) {
                global $wpdb;
                $rep_id = intval($_GET['id']);
                $table_name = $wpdb->prefix . 'insurance_crm_representatives';
                
                $user_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT user_id FROM $table_name WHERE id = %d",
                    $rep_id
                ));
                
                if ($user_id) {
                    require_once(ABSPATH . 'wp-admin/includes/user.php');
                    wp_delete_user($user_id);
                }
                
                $wpdb->delete($table_name, array('id' => $rep_id));
                
                echo '<div class="notice notice-success"><p>Müşteri temsilcisi silindi.</p></div>';
            }
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'insurance_crm_representatives';
        $representatives = $wpdb->get_results(
            "SELECT r.*, u.user_email as email, u.display_name 
             FROM $table_name r 
             LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
             WHERE r.status = 'active' 
             ORDER BY r.created_at DESC"
        );
        ?>
        <div class="wrap">
            <h1>Müşteri Temsilcileri</h1>
            
            <h2>Mevcut Müşteri Temsilcileri</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Ad Soyad</th>
                        <th>E-posta</th>
                        <th>Ünvan</th>
                        <th>Telefon</th>
                        <th>Departman</th>
                        <th>Aylık Hedef</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($representatives)): ?>
                        <?php foreach ($representatives as $rep): ?>
                        <tr>
                            <td><?php echo esc_html($rep->display_name); ?></td>
                            <td><?php echo esc_html($rep->email); ?></td>
                            <td><?php echo esc_html($rep->title); ?></td>
                            <td><?php echo esc_html($rep->phone); ?></td>
                            <td><?php echo esc_html($rep->department); ?></td>
                            <td>₺<?php echo number_format($rep->monthly_target, 2); ?></td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&action=edit&id=' . $rep->id); ?>" 
                                   class="button button-small">
                                    Düzenle
                                </a>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=insurance-crm-representatives&action=delete&id=' . $rep->id), 'delete_representative_' . $rep->id); ?>" 
                                   class="button button-small" 
                                   onclick="return confirm('Bu müşteri temsilcisini silmek istediğinizden emin misiniz?');">
                                    Sil
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7">Müşteri temsilcisi bulunamadı.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <hr>
            
            <h2>Yeni Müşteri Temsilcisi Ekle</h2>
            <form method="post" action="">
                <?php wp_nonce_field('add_edit_representative', 'representative_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="username">Kullanıcı Adı</label></th>
                        <td><input type="text" name="username" id="username" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="password">Şifre</label></th>
                        <td>
                            <input type="password" name="password" id="password" class="regular-text" required>
                            <p class="description">En az 8 karakter uzunluğunda olmalıdır.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="confirm_password">Şifre (Tekrar)</label></th>
                        <td><input type="password" name="confirm_password" id="confirm_password" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="first_name">Ad</label></th>
                        <td><input type="text" name="first_name" id="first_name" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="last_name">Soyad</label></th>
                        <td><input type="text" name="last_name" id="last_name" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="email">E-posta</label></th>
                        <td><input type="email" name="email" id="email" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="title">Ünvan</label></th>
                        <td><input type="text" name="title" id="title" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="phone">Telefon</label></th>
                        <td><input type="tel" name="phone" id="phone" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="department">Departman</label></th>
                        <td><input type="text" name="department" id="department" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="monthly_target">Aylık Hedef (₺)</label></th>
                        <td>
                            <input type="number" step="0.01" name="monthly_target" id="monthly_target" class="regular-text" required>
                            <p class="description">Temsilcinin aylık satış hedefi (₺)</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="submit_representative" class="button button-primary" value="Müşteri Temsilcisi Ekle">
                </p>
            </form>
        </div>
        <?php
    }
}

/**
 * Sayfa şablonlarını kaydet
 */
function insurance_crm_create_pages() {
    $pages = array(
        array(
            'post_title'    => 'Temsilci Girişi',
            'post_content'  => '[temsilci_login]',
            'post_name'     => 'temsilci-girisi'
        ),
        array(
            'post_title'    => 'Temsilci Paneli',
            'post_content'  => '[temsilci_dashboard]',
            'post_name'     => 'temsilci-paneli'
        )
    );

    foreach ($pages as $page) {
        if (!get_page_by_path($page['post_name'], OBJECT, 'page')) {
            wp_insert_post(array(
                'post_title'    => $page['post_title'],
                'post_content'  => $page['post_content'],
                'post_status'   => 'publish',
                'post_type'     => 'page',
                'post_name'     => $page['post_name']
            ));
        }
    }
}
register_activation_hook(__FILE__, 'insurance_crm_create_pages');

/**
 * License notification system activation
 */
function insurance_crm_activate_license_notifications() {
    // Initialize license notifications
    global $insurance_crm_license_notifications;
    if ($insurance_crm_license_notifications && method_exists($insurance_crm_license_notifications, 'send_activation_email')) {
        $insurance_crm_license_notifications->send_activation_email();
    }
}
register_activation_hook(__FILE__, 'insurance_crm_activate_license_notifications');

/**
 * Shortcode'ları ekle
 */
function insurance_crm_add_shortcodes() {
    require_once plugin_dir_path(__FILE__) . 'includes/shortcodes-representative-panel.php';
}
add_action('init', 'insurance_crm_add_shortcodes');

/**
 * Login işlemini yönet
 */
function insurance_crm_process_login() {
    if (isset($_POST['insurance_crm_login']) && isset($_POST['insurance_crm_login_nonce'])) {
        if (!wp_verify_nonce($_POST['insurance_crm_login_nonce'], 'insurance_crm_login')) {
            error_log('Insurance CRM Login Error: Invalid nonce');
            wp_safe_redirect(add_query_arg('login', 'failed', home_url('/temsilci-girisi/')));
            exit;
        }
        
        $username = sanitize_user($_POST['username']);
        $password = $_POST['password'];
        $remember = isset($_POST['remember']) ? true : false;
        
        if (is_email($username)) {
            $user_data = get_user_by('email', $username);
            if ($user_data) {
                $username = $user_data->user_login;
            }
        }
        
        $creds = array(
            'user_login' => $username,
            'user_password' => $password,
            'remember' => $remember
        );
        
        $user = wp_signon($creds, is_ssl());
        
        if (is_wp_error($user)) {
            error_log('Insurance CRM Login Error: ' . $user->get_error_message());
            wp_safe_redirect(add_query_arg('login', 'failed', home_url('/temsilci-girisi/')));
            exit;
        }
        
        if (!in_array('insurance_representative', (array)$user->roles)) {
            wp_logout();
            error_log('Insurance CRM Login Error: User is not a representative');
            wp_safe_redirect(add_query_arg('login', 'failed', home_url('/temsilci-girisi/')));
            exit;
        }
        
        global $wpdb;
        $rep_status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
            $user->ID
        ));
        
        if ($rep_status !== 'active') {
            wp_logout();
            error_log('Insurance CRM Login Error: Representative status is not active');
            wp_safe_redirect(add_query_arg('login', 'inactive', home_url('/temsilci-girisi/')));
            exit;
        }
        
        error_log('Insurance CRM Login Success: User ID ' . $user->ID);
        wp_safe_redirect(home_url('/temsilci-paneli/'));
        exit;
    }
}
add_action('init', 'insurance_crm_process_login', 5);

/**
 * Admin notifikasyonu ekle
 */
function insurance_crm_admin_notice() {
    if (isset($_GET['page']) && strpos($_GET['page'], 'insurance-crm') !== false) {
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>Insurance CRM v1.1.3:</strong> Yönetici Paneline Hoşgeldiniz.</p>';
        echo '</div>';
    }
}
add_action('admin_notices', 'insurance_crm_admin_notice');

require_once plugin_dir_path(__FILE__) . 'includes/shortcodes-representative-panel.php';

// Load logging functions if file exists
$logging_functions_file = plugin_dir_path(__FILE__) . 'includes/logging-functions.php';
if (file_exists($logging_functions_file)) {
    require_once $logging_functions_file;
}

add_action('template_redirect', 'insurance_crm_template_redirect');
function insurance_crm_template_redirect() {
}

/**
 * Diagnostic function for troubleshooting logging system
 */
function insurance_crm_diagnostic() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Only show on Insurance CRM pages
    if (!isset($_GET['page']) || strpos($_GET['page'], 'insurance-crm') === false) {
        return;
    }

    global $wpdb;
    
    // Check if tables were just created
    if (isset($_GET['tables_created'])) {
        echo '<div class="notice notice-success" style="margin: 20px 0; padding: 15px;">';
        echo '<h3>✅ Tablolar başarıyla oluşturuldu!</h3>';
        echo '<p>Logging sistemi artık çalışmaya hazır. Bu mesajı tekrar görmek istemiyorsanız sayfayı yenileyin.</p>';
        echo '</div>';
    }
    
    echo '<div class="notice notice-info" style="margin: 20px 0; padding: 15px;">';
    echo '<h3>🔍 Insurance CRM Sistem Tanılama</h3>';
    
    // Check if logging tables exist
    $required_tables = array(
        $wpdb->prefix . 'insurance_user_logs' => 'Kullanıcı Logları',
        $wpdb->prefix . 'insurance_system_logs' => 'Sistem Logları',
        $wpdb->prefix . 'insurance_crm_customers' => 'Müşteriler',
        $wpdb->prefix . 'insurance_crm_policies' => 'Poliçeler',
        $wpdb->prefix . 'insurance_crm_tasks' => 'Görevler',
        $wpdb->prefix . 'insurance_crm_representatives' => 'Temsilciler',
        $wpdb->prefix . 'insurance_crm_helpdesk_tickets' => 'Helpdesk Biletleri'
    );
    
    echo '<p><strong>Veritabanı Tabloları:</strong></p>';
    echo '<ul>';
    $missing_tables = 0;
    foreach ($required_tables as $table => $description) {
        $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") == $table;
        $status = $exists ? '✅' : '❌';
        echo '<li>' . $status . ' ' . $description . ' (' . $table . ')</li>';
        if (!$exists) $missing_tables++;
    }
    echo '</ul>';
    
    if ($missing_tables > 0) {
        echo '<p><strong style="color: red;">⚠️ ' . $missing_tables . ' tablo eksik!</strong></p>';
        echo '<p>Aşağıdaki butonla tabloları manuel olarak oluşturabilirsiniz:</p>';
        $create_url = wp_nonce_url(
            admin_url('admin.php?page=insurance-crm&insurance_crm_create_tables=1'), 
            'insurance_crm_create_tables'
        );
        echo '<p><a href="' . $create_url . '" class="button button-primary">Tabloları Oluştur</a> ';
        echo '<a href="' . admin_url('plugins.php') . '" class="button">Plugin\'leri Yönet</a></p>';
    } else {
        echo '<p><strong style="color: green;">✅ Tüm tablolar mevcut!</strong></p>';
        
        // Count logs
        $user_logs = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}insurance_user_logs");
        $system_logs = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}insurance_system_logs");
        echo '<p>📊 <strong>Log İstatistikleri:</strong></p>';
        echo '<ul>';
        echo '<li>Kullanıcı logları: ' . $user_logs . ' kayıt</li>';
        echo '<li>Sistem logları: ' . $system_logs . ' kayıt</li>';
        echo '</ul>';
        
        if ($user_logs == 0 && $system_logs == 0) {
            echo '<p><em>💡 Henüz log kaydı yok. Sistemi kullanmaya başladığınızda loglar otomatik olarak kaydedilecek.</em></p>';
        }
        
        echo '<p><a href="' . admin_url('admin.php?page=insurance-crm-logs') . '" class="button">Log Görüntüleyiciyi Aç</a>';
        echo ' <a href="' . wp_nonce_url(admin_url('admin.php?page=insurance-crm&test_logs=1'), 'test_logs') . '" class="button">Test Logları Oluştur</a></p>';
    }
    
    // Show success message if test logs were created
    if (isset($_GET['test_logs_created'])) {
        echo '<div class="notice notice-success" style="margin: 20px 0; padding: 15px;">';
        echo '<h3>✅ Test logları oluşturuldu!</h3>';
        echo '<p>Artık log görüntüleyicide test verilerini görebilirsiniz.</p>';
        echo '</div>';
    }
    
    echo '</div>';
}
// Diagnostic panel removed as requested - no longer displays on admin pages
// add_action('admin_notices', 'insurance_crm_diagnostic');

/**
 * Helper functions for role management
 */
function get_user_role_in_hierarchy($user_id) {
    global $wpdb;
    
    // Temsilcinin role değerini al
    $role_value = $wpdb->get_var($wpdb->prepare(
        "SELECT role FROM {$wpdb->prefix}insurance_crm_representatives 
         WHERE user_id = %d AND status = 'active'",
        $user_id
    ));
    
    // Role değeri yoksa varsayılan olarak temsilci döndür
    if ($role_value === null) {
        return 'representative';
    }
    
    // Role değerine göre rol adını belirle
    switch (intval($role_value)) {
        case 1:
            return 'patron';
        case 2:
            return 'manager';
        case 3:
            return 'assistant_manager';
        case 4:
            return 'team_leader';
        case 5:
        default:
            return 'representative';
    }
}

/**
 * Patron kontrolü
 */
function is_patron($user_id) {
    return get_user_role_in_hierarchy($user_id) === 'patron';
}

/**
 * Müdür kontrolü
 */
function is_manager($user_id) {
    return get_user_role_in_hierarchy($user_id) === 'manager';
}

/**
 * Müdür Yardımcısı kontrolü
 */
function is_assistant_manager($user_id) {
    return get_user_role_in_hierarchy($user_id) === 'assistant_manager';
}

/**
 * Takım Lideri kontrolü
 */
function is_team_leader($user_id) {
    return get_user_role_in_hierarchy($user_id) === 'team_leader';
}

/**
 * Boss kontrolü (Patron alias)
 */
function is_boss($user_id) {
    return is_patron($user_id);
}

/**
 * Tam yönetici erişimi kontrolü (Patron ve Müdür)
 */
function has_full_admin_access($user_id) {
    return is_patron($user_id) || is_manager($user_id);
}

/**
 * AJAX Handlers for Personnel Management
 */
add_action('wp_ajax_toggle_representative_status', 'handle_toggle_representative_status');

// Policy prompt dismiss handler
add_action('wp_ajax_dismiss_policy_prompt', 'handle_dismiss_policy_prompt');

function handle_dismiss_policy_prompt() {
    // Check nonce for security
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'dismiss_policy_prompt')) {
        wp_die('Security check failed');
    }
    
    // Clear session variables
    unset($_SESSION['show_policy_prompt']);
    unset($_SESSION['new_customer_id']);
    unset($_SESSION['new_customer_name']);
    
    wp_send_json_success('Policy prompt dismissed');
}

function handle_toggle_representative_status() {
    check_ajax_referer('insurance_crm_ajax', '_wpnonce');
    
    global $wpdb;
    $current_user = wp_get_current_user();
    $rep_id = intval($_POST['id']);
    $new_status = sanitize_text_field($_POST['status']);
    
    // Yetki kontrolü
    if (!is_patron($current_user->ID) && !is_manager($current_user->ID)) {
        wp_send_json_error(array('message' => 'Bu işlem için yetkiniz bulunmuyor.'));
        return;
    }
    
    $table_name = $wpdb->prefix . 'insurance_crm_representatives';
    $result = $wpdb->update(
        $table_name,
        array('status' => $new_status),
        array('id' => $rep_id),
        array('%s'),
        array('%d')
    );
    
    if ($result !== false) {
        wp_send_json_success(array('message' => 'Durum başarıyla güncellendi.'));
    } else {
        wp_send_json_error(array('message' => 'Güncelleme sırasında bir hata oluştu: ' . $wpdb->last_error));
    }
}
?>
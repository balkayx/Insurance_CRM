<?php
/**
 * Frontend Bildirimler Sayfası
 * @version 1.0.10 - Modal ve panel tasarımı modernize edildi, okundu işaretleme düzeltildi
 */

if (!defined('ABSPATH')) {
    exit;
}

// Kullanıcı oturum kontrolü
if (!is_user_logged_in()) {
    echo '<div class="ab-notice ab-error">Bu sayfayı görüntülemek için giriş yapmalısınız.</div>';
    return;
}

// Bildirim tablosunu oluştur (eğer yoksa)
function create_notifications_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'insurance_crm_notifications';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            type varchar(50) NOT NULL,
            category varchar(50) DEFAULT NULL,
            title varchar(255) NOT NULL,
            message text NOT NULL,
            related_id bigint(20) DEFAULT NULL,
            related_type varchar(50) DEFAULT NULL,
            is_read tinyint(1) DEFAULT 0 NOT NULL,
            expires_at datetime DEFAULT NULL,
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log('Notifications tablosu oluşturuldu.');
    }
}

create_notifications_table();

// Okunma durumlarını takip eden tabloyu oluştur (eğer yoksa)
function create_notification_reads_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'insurance_crm_notification_reads';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            notification_id BIGINT(20) NOT NULL,
            user_id BIGINT(20) NOT NULL,
            read_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_read (notification_id, user_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log('Notification reads tablosu oluşturuldu: ' . $table_name);
    }
}

create_notification_reads_table();

// Bildirim tablosuna category ve expires_at sütunlarını ekle (eğer yoksa)
function alter_notifications_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'insurance_crm_notifications';
    
    // category sütununu kontrol et ve ekle
    if (!$wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'category'")) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN category VARCHAR(50) DEFAULT NULL AFTER type");
        error_log('Notifications tablosuna category sütunu eklendi.');
    }
    
    // expires_at sütununu kontrol et ve ekle
    if (!$wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'expires_at'")) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN expires_at DATETIME DEFAULT NULL AFTER is_read");
        error_log('Notifications tablosuna expires_at sütunu eklendi.');
    }
}

alter_notifications_table();

// Veritabanı tablolarını tanımlama
global $wpdb;
$notifications_table = $wpdb->prefix . 'insurance_crm_notifications';
$current_user = wp_get_current_user();

// Debug: Kullanıcı ve tablo bilgilerini logla
error_log('notifications.php: Kullanıcı ID: ' . $current_user->ID);
error_log('notifications.php: Tablo öneki: ' . $wpdb->prefix);

// Include enhanced dashboard functions if not already included
if (!function_exists('get_dashboard_representatives')) {
    if (file_exists(dirname(__FILE__) . '/../../includes/dashboard-functions.php')) {
        require_once(dirname(__FILE__) . '/../../includes/dashboard-functions.php');
    } else {
        // Fallback function if file doesn't exist
        function get_dashboard_representatives($user_id, $current_view = 'notifications') {
            global $wpdb;
            
            // Temsilci ID'sini al
            $rep = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
                $user_id
            ));
            
            if (!$rep) return [];
            
            return [$rep->id];
        }
    }
}

// Function get_current_user_rep_id is already defined in the main plugin file
$current_user_rep_id = get_current_user_rep_id();

// Bildirim mesajı
$notice = '';
if (isset($_SESSION['crm_notice'])) {
    $notice = $_SESSION['crm_notice'];
    unset($_SESSION['crm_notice']);
}

// AJAX ile tek bir bildirimi okundu olarak işaretleme
function handle_mark_notification_read() {
    global $wpdb;
    
    if (!isset($_POST['notification_id']) || !isset($_POST['nonce']) || 
        !wp_verify_nonce($_POST['nonce'], 'mark_notification_read')) {
        wp_send_json_error(['message' => 'Geçersiz istek']);
        return;
    }
    
    $notification_id = intval($_POST['notification_id']);
    $user_id = get_current_user_id();
    
    // Okundu bilgisini ekle
    $inserted = $wpdb->insert(
        $wpdb->prefix . 'insurance_crm_notification_reads',
        [
            'notification_id' => $notification_id,
            'user_id' => $user_id,
            'read_at' => current_time('mysql')
        ],
        ['%d', '%d', '%s']
    );
    
    if ($inserted !== false) {
        wp_send_json_success(['message' => 'Bildirim okundu olarak işaretlendi']);
    } else {
        wp_send_json_error(['message' => 'Bildirim güncellenemedi']);
    }
}
add_action('wp_ajax_mark_notification_read', 'handle_mark_notification_read');

// Tüm bildirimleri okundu olarak işaretleme
function handle_mark_all_notifications_read() {
    global $wpdb;
    
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mark_all_notifications_read')) {
        wp_send_json_error(['message' => 'Geçersiz istek']);
        return;
    }
    
    $user_id = get_current_user_id();
    
    // Okunmamış genel bildirimleri al
    $unread_notifications = $wpdb->get_results($wpdb->prepare(
        "SELECT n.id 
         FROM {$wpdb->prefix}insurance_crm_notifications n
         LEFT JOIN {$wpdb->prefix}insurance_crm_notification_reads nr 
         ON n.id = nr.notification_id AND nr.user_id = %d
         WHERE n.user_id = 0 AND nr.read_at IS NULL
         AND (n.expires_at IS NULL OR n.expires_at >= %s)",
        $user_id,
        current_time('mysql')
    ));
    
    if (empty($unread_notifications)) {
        wp_send_json_success(['message' => 'Okunmamış bildirim bulunamadı']);
        return;
    }
    
    $success = true;
    foreach ($unread_notifications as $notification) {
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'insurance_crm_notification_reads',
            [
                'notification_id' => $notification->id,
                'user_id' => $user_id,
                'read_at' => current_time('mysql')
            ],
            ['%d', '%d', '%s']
        );
        
        if ($inserted === false) {
            $success = false;
        }
    }
    
    if ($success) {
        wp_send_json_success(['message' => 'Tüm bildirimler okundu olarak işaretlendi']);
    } else {
        wp_send_json_error(['message' => 'Bildirimler güncellenemedi']);
    }
}
add_action('wp_ajax_mark_all_notifications_read', 'handle_mark_all_notifications_read');

// AJAX ile görev işleme alındı olarak işaretleme
function handle_mark_task_processed() {
    global $wpdb;
    
    if (!isset($_POST['task_id']) || !isset($_POST['nonce']) || 
        !wp_verify_nonce($_POST['nonce'], 'mark_task_processed')) {
        wp_send_json_error(['message' => 'Geçersiz istek']);
        return;
    }
    
    $task_id = intval($_POST['task_id']);
    $user_id = get_current_user_id();
    
    // Görevin var olduğunu ve kullanıcıya ait olduğunu kontrol et
    $task = $wpdb->get_row($wpdb->prepare(
        "SELECT t.*, r.user_id 
         FROM {$wpdb->prefix}insurance_crm_tasks t
         JOIN {$wpdb->prefix}insurance_crm_representatives r ON t.representative_id = r.id
         WHERE t.id = %d AND r.user_id = %d",
        $task_id,
        $user_id
    ));
    
    if (!$task) {
        wp_send_json_error(['message' => 'Görev bulunamadı veya yetkiniz yok']);
        return;
    }
    
    // Görevi tamamlandı olarak işaretle
    $updated = $wpdb->update(
        $wpdb->prefix . 'insurance_crm_tasks',
        ['status' => 'completed'],
        ['id' => $task_id],
        ['%s'],
        ['%d']
    );
    
    if ($updated !== false) {
        wp_send_json_success(['message' => 'Görev Tamamlandı']);
    } else {
        wp_send_json_error(['message' => 'Görev güncellenemedi']);
    }
}
add_action('wp_ajax_mark_task_processed', 'handle_mark_task_processed');

// Filtreleme parametreleri
$category_filter = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';

// Genel Bildirimleri Çekme (crmx_insurance_crm_notifications)
$query = "
    SELECT n.*,
            c.first_name, c.last_name,
            p.policy_number,
            nr.read_at
     FROM {$wpdb->prefix}insurance_crm_notifications n
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON n.related_id = c.id AND n.related_type = 'customer'
     LEFT JOIN {$wpdb->prefix}insurance_crm_policies p ON n.related_id = p.id AND n.related_type = 'policy'
     LEFT JOIN {$wpdb->prefix}insurance_crm_notification_reads nr ON n.id = nr.notification_id AND nr.user_id = %d
     WHERE n.user_id = 0
     AND (n.expires_at IS NULL OR n.expires_at >= %s)
";

$where_conditions = array();
$where_values = array($current_user->ID, current_time('mysql'));

if (!empty($category_filter)) {
    $where_conditions[] = "n.category = %s";
    $where_values[] = $category_filter;
}

if (!empty($where_conditions)) {
    $query .= " AND " . implode(" AND ", $where_conditions);
}

// Öncelik sıralaması: alert türü duyurular en üstte
$query .= " ORDER BY CASE WHEN n.type = 'alert' THEN 0 ELSE 1 END, n.created_at DESC LIMIT 50";

$general_notifications = $wpdb->get_results($wpdb->prepare($query, ...$where_values));

// Genel bildirimlerin okunma durumunu belirle
if (!empty($general_notifications)) {
    foreach ($general_notifications as $notification) {
        $notification->is_read = !is_null($notification->read_at) ? 1 : 0;
    }
} else {
    $general_notifications = [];
}

// Debug için genel bildirim sayısını logla
error_log('notifications.php: Genel bildirim sayısı: ' . count($general_notifications));

// Yaklaşan Görevleri Çekme (crmx_insurance_crm_tasks - Kişisel Bildirimler için)
$personal_tasks = [];
$notifications_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}insurance_crm_notifications'") === $wpdb->prefix . 'insurance_crm_notifications';

if ($notifications_table_exists) {
    $rep_ids = get_dashboard_representatives($current_user->ID, 'notifications');
    // Debug için rep_ids logla
    error_log('notifications.php: rep_ids: ' . (empty($rep_ids) ? 'Boş' : implode(',', $rep_ids)));
    
    if (!empty($rep_ids)) {
        $placeholders = implode(',', array_fill(0, count($rep_ids), '%d'));
        $query = "SELECT t.*, c.first_name, c.last_name 
                  FROM {$wpdb->prefix}insurance_crm_tasks t
                  LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON t.customer_id = c.id
                  WHERE t.representative_id IN ($placeholders) 
                  AND t.status = 'pending'
                  AND t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                  ORDER BY t.due_date ASC
                  LIMIT 50";
        $personal_tasks = $wpdb->get_results($wpdb->prepare($query, ...$rep_ids));
    }
}

// Debug için kişisel görev sayısını logla
error_log('notifications.php: Kişisel görev sayısı: ' . count($personal_tasks));

// Toplam bildirim sayısı (genel bildirimler + kişisel görevler)
$total_notifications = count($general_notifications) + count($personal_tasks);

// Okunmamış genel bildirim sayısını hesapla (sağ üstteki bildirim simgesi için)
$unread_general_notifications = array_filter($general_notifications, function($notification) {
    return $notification->is_read == 0;
});
$notification_count = count($unread_general_notifications);
$upcoming_tasks_count = count($personal_tasks);
$total_notification_count = $notification_count + $upcoming_tasks_count;

// Sağ üstteki bildirim simgesini güncelle
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const badge = document.querySelector('.notification-badge');
    if (badge) {
        const newCount = <?php echo $total_notification_count; ?>;
        if (newCount > 0) {
            badge.textContent = newCount;
        } else {
            badge.remove();
        }
    }
});
</script>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<div class="ab-crm-container" id="notifications-list-container">
    <?php echo $notice; ?>

    <div class="ab-crm-header">
        <h1><i class="fas fa-bell"></i> Bildirimler</h1>
    </div>
    <!-- Bildirim Alanları Konteyneri - Stacked Layout -->
    <div class="ab-notifications-container ab-stacked">
        <!-- Genel Bildirimler - Always on top -->
        <div class="ab-crm-section ab-notification-section">
            <h3>Genel Bildirimler</h3>
        <?php if (!empty($general_notifications)): ?>
            <div class="ab-crm-table-wrapper">
                <table class="ab-crm-table">
                    <thead>
                        <tr>
                            <th>Başlık</th>
                            <th>Mesaj</th>
                            <th>Tür</th>
                            <th>Kategori</th>
                            <th>İlgili Nesne</th>
                            <th>Tarih</th>
                            <th>Durum</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($general_notifications as $notification): ?>
                            <tr class="<?php echo $notification->is_read ? '' : 'unread'; ?>" data-notification-id="<?php echo $notification->id; ?>">
                                <td class="announcement-link"><?php echo esc_html($notification->title); ?></td>
                                <td><?php echo esc_html($notification->message); ?></td>
                                <td>
                                    <span class="ab-badge type-<?php echo esc_attr($notification->type); ?>">
                                        <?php 
                                            switch ($notification->type) {
                                                case 'general': echo 'Genel'; break;
                                                case 'update': echo 'Güncelleme'; break;
                                                case 'alert': echo 'Uyarı'; break;
                                                default: echo esc_html($notification->type); break;
                                            }
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="ab-badge category-<?php echo esc_attr($notification->category); ?>">
                                        <?php 
                                            switch ($notification->category) {
                                                case 'system': echo 'Sistem'; break;
                                                case 'campaign': echo 'Kampanya'; break;
                                                case 'warning': echo 'Uyarı'; break;
                                                default: echo esc_html($notification->category ?: '—'); break;
                                            }
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    if ($notification->related_type === 'customer' && $notification->first_name) {
                                        echo esc_html($notification->first_name . ' ' . $notification->last_name);
                                    } elseif ($notification->related_type === 'policy' && $notification->policy_number) {
                                        echo esc_html($notification->policy_number);
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                                <td class="ab-date-cell"><?php echo date_i18n('d.m.Y H:i', strtotime($notification->created_at)); ?></td>
                                <td>
                                    <span class="ab-badge ab-badge-status-<?php echo $notification->is_read ? 'aktif' : 'pasif'; ?>">
                                        <?php echo $notification->is_read ? 'Okundu' : 'Okunmadı'; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="ab-empty-state">
                <i class="fas fa-megaphone"></i>
                <h3>Genel bildirim bulunamadı</h3>
                <p>Yeni genel bildirimler burada listelenecek.</p>
            </div>
        <?php endif; ?>
        </div>

        <!-- Kişisel Bildirimler (Görevler) - Below General -->
        <div class="ab-crm-section ab-notification-section">
            <h3>Kişisel Bildirimler</h3>
            <?php if (!empty($personal_tasks)): ?>
                <div class="ab-crm-table-wrapper">
                    <table class="ab-crm-table">
                        <thead>
                            <tr>
                                <th>Görev Başlığı</th>
                                <th>Açıklama</th>
                                <th>Müşteri</th>
                                <th>Öncelik</th>
                                <th>Son Tarih</th>
                                <th>Durum</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($personal_tasks as $task): ?>
                                <tr data-task-id="<?php echo $task->id; ?>">
                                    <td><?php echo esc_html($task->task_title); ?></td>
                                    <td><?php echo esc_html($task->task_description); ?></td>
                                    <td>
                                        <?php echo esc_html($task->first_name . ' ' . $task->last_name); ?>
                                    </td>
                                    <td>
                                        <span class="ab-badge ab-badge-status-<?php echo esc_attr(strtolower($task->priority)); ?>">
                                            <?php echo esc_html(ucfirst($task->priority)); ?>
                                        </span>
                                    </td>
                                    <td class="ab-date-cell"><?php echo date_i18n('d.m.Y', strtotime($task->due_date)); ?></td>
                                    <td>
                                        <span class="ab-badge ab-badge-status-<?php echo esc_attr($task->status); ?>">
                                            <?php echo esc_html(ucfirst($task->status)); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="ab-empty-state">
                    <i class="fas fa-bell"></i>
                    <h3>Kişisel bildirim bulunamadı</h3>
                    <p>Yaklaşan görevler burada listelenecek.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Bildirim Önizleme Modal -->
<div id="announcement-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="modal-close"><i class="fas fa-times"></i></span>
        <div class="modal-header">
            <h2 id="modal-title"></h2>
        </div>
        <div class="modal-body">
            <div class="modal-field">
                <span class="modal-label">Tür:</span>
                <span id="modal-type" class="modal-value"></span>
            </div>
            <div class="modal-field">
                <span class="modal-label">Kategori:</span>
                <span id="modal-category" class="modal-value"></span>
            </div>
            <div class="modal-field">
                <span class="modal-label">Mesaj:</span>
                <span id="modal-message" class="modal-value"></span>
            </div>
            <div class="modal-field">
                <span class="modal-label">İlgili Nesne:</span>
                <span id="modal-related" class="modal-value"></span>
            </div>
            <div class="modal-field">
                <span class="modal-label">Tarih:</span>
                <span id="modal-date" class="modal-value"></span>
            </div>
            <div class="modal-field">
                <span class="modal-label">Durum:</span>
                <span id="modal-status" class="modal-value"></span>
            </div>
        </div>
        <div class="modal-actions">
            <button id="modal-mark-read" class="ab-btn ab-btn-primary" style="display: none;" data-nonce="<?php echo wp_create_nonce('mark_notification_read'); ?>">
                Okundu İşaretle
            </button>
            <button class="ab-btn ab-btn-secondary modal-close-btn">Kapat</button>
        </div>
    </div>
</div>

<style>
/* Genel Container */
.ab-crm-container {
    width: 95%;
    max-width: 95%;
    margin: 0 auto;
    padding: 20px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: linear-gradient(145deg, #ffffff, #f8f9fa);
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
    transition: all 0.3s ease;
}

/* Header */
.ab-crm-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e5e7eb;
}

.ab-crm-header h1 {
    font-size: 24px;
    font-weight: 700;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}

.ab-crm-header h1 i {
    color: #4b5563;
    font-size: 22px;
}

.ab-crm-header-actions {
    display: flex;
    gap: 12px;
}

/* Bildirim Mesajları */
.ab-notice {
    padding: 12px 16px;
    margin-bottom: 20px;
    border-radius: 6px;
    font-size: 14px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.ab-success {
    background: #ecfdf5;
    border-left: 4px solid #10b981;
    color: #064e3b;
}

.ab-error {
    background: #fef2f2;
    border-left: 4px solid #ef4444;
    color: #991b1b;
}

/* Butonlar */
.ab-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    font-size: 14px;
    font-weight: 500;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s ease;
    background: #f3f4f6;
    color: #374151;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
}

.ab-btn:hover {
    background: #e5e7eb;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
}

.ab-btn-primary {
    background: #3b82f6;
    color: #fff;
    box-shadow: 0 2px 5px rgba(59, 130, 246, 0.2);
}

.ab-btn-primary:hover {
    background: #2563eb;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3);
}

.ab-btn-secondary {
    background: #e5e7eb;
    color: #374151;
}

.ab-btn-secondary:hover {
    background: #d1d5db;
}

/* Section */
.ab-crm-section {
    margin-bottom: 30px;
}

.ab-crm-section h3 {
    font-size: 18px;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #e5e7eb;
}

/* Tablo */
.ab-crm-table-wrapper {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    overflow-x: auto;
    margin-bottom: 20px;
}

.ab-crm-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.ab-crm-table th,
.ab-crm-table td {
    padding: 12px 16px;
    text-align: left;
    font-size: 14px;
    border-bottom: 1px solid #e5e7eb;
}

.ab-crm-table th {
    background: #f9fafb;
    font-weight: 600;
    color: #374151;
    text-transform: uppercase;
    font-size: 12px;
    letter-spacing: 0.5px;
}

.ab-crm-table tr:hover td {
    background: #f9fafb;
    transition: background 0.2s ease;
}

.ab-crm-table tr.unread td {
    background: #f0f7ff;
    position: relative;
}

.ab-crm-table tr.unread td:first-child:before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: #3b82f6;
}

.ab-crm-table tr:last-child td {
    border-bottom: none;
}

.ab-date-cell {
    font-size: 13px;
    color: #6b7280;
    white-space: nowrap;
}

/* Etiketler */
.ab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    line-height: 1;
    transition: all 0.2s ease;
}

.ab-badge-status-aktif {
    background: #ecfdf5;
    color: #059669;
    box-shadow: 0 1px 3px rgba(5, 150, 105, 0.1);
}

.ab-badge-status-pasif {
    background: #fefce8;
    color: #d97706;
    box-shadow: 0 1px 3px rgba(217, 119, 6, 0.1);
}

.ab-badge-status-pending {
    background: #fefce8;
    color: #d97706;
    box-shadow: 0 1px 3px rgba(217, 119, 6, 0.1);
}

.ab-badge-status-high {
    background: #fee2e2;
    color: #ef4444;
    box-shadow: 0 1px 3px rgba(239, 68, 68, 0.1);
}

.ab-badge-status-medium {
    background: #fefce8;
    color: #d97706;
    box-shadow: 0 1px 3px rgba(217, 119, 6, 0.1);
}

.ab-badge-status-low {
    background: #ecfdf5;
    color: #059669;
    box-shadow: 0 1px 3px rgba(5, 150, 105, 0.1);
}

.ab-badge.type-general {
    background: #ecfdf5;
    color: #059669;
    box-shadow: 0 1px 3px rgba(5, 150, 105, 0.1);
}

.ab-badge.type-update {
    background: #e0f2fe;
    color: #0284c7;
    box-shadow: 0 1px 3px rgba(2, 132, 199, 0.1);
}

.ab-badge.type-alert {
    background: #fee2e2;
    color: #ef4444;
    box-shadow: 0 1px 3px rgba(239, 68, 68, 0.1);
}

.ab-badge.category-system {
    background: #e0f2fe;
    color: #0284c7;
    box-shadow: 0 1px 3px rgba(2, 132, 199, 0.1);
}

.ab-badge.category-campaign {
    background: #ecfdf5;
    color: #059669;
    box-shadow: 0 1px 3px rgba(5, 150, 105, 0.1);
}

.ab-badge.category-warning {
    background: #fee2e2;
    color: #ef4444;
    box-shadow: 0 1px 3px rgba(239, 68, 68, 0.1);
}

/* Stacked Notifications Layout */
.ab-notifications-container.ab-stacked {
    display: block;
    width: 100%;
    max-width: 100%;
    margin: 0;
}

.ab-notifications-container.ab-stacked .ab-notification-section {
    width: 100%;
    max-width: 100%;
    margin-bottom: 30px;
    min-width: auto;
}

/* Remove old flex layout styles */
.ab-notifications-container {
    display: block;
    width: 100%;
    max-width: 100%;
    margin: 0;
}

.ab-notification-section {
    width: 100%;
    max-width: 100%;
    margin-bottom: 30px;
    min-width: auto;
}

/* Responsive Tasarım */
@media (max-width: 1200px) {
    .ab-crm-container {
        width: 95%;
        max-width: 95%;
        padding: 15px;
    }
}

@media (max-width: 992px) {
    .ab-crm-container {
        width: 95%;
        max-width: 95%;
        margin: 0 auto;
        padding: 15px;
    }
    
    .ab-notification-section {
        width: 100%;
        max-width: 100%;
    }

    .ab-crm-table th:nth-child(3),
    .ab-crm-table td:nth-child(3) {
        display: none;
    }
}

@media (max-width: 768px) {
    .ab-crm-container {
        padding: 15px;
    }

    .ab-crm-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }

    .ab-crm-header-actions {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }

    .ab-crm-table th,
    .ab-crm-table td {
        padding: 10px 8px;
        font-size: 13px;
    }

    .ab-filter-row {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .modal-content {
        width: 95%;
        margin: 15% auto;
    }

    .modal-header h2 {
        font-size: 16px;
    }

    .modal-field {
        font-size: 13px;
    }

    .modal-label {
        min-width: 100px;
    }

    .modal-actions {
        flex-direction: column;
        gap: 8px;
    }

    .modal-actions .ab-btn {
        width: 100%;
        text-align: center;
    }
}

@media (max-width: 576px) {
    .ab-crm-container {
        margin: 0 auto;
        border-radius: 0;
        box-shadow: none;
        padding: 10px;
        width: 95%;
        max-width: 95%;
    }

    .ab-crm-table th:nth-child(4),
    .ab-crm-table td:nth-child(4),
    .ab-crm-table th:nth-child(5),
    .ab-crm-table td:nth-child(5) {
        display: none;
    }

    .ab-crm-table th,
    .ab-crm-table td {
        font-size: 12px;
        padding: 8px 6px;
    }

    .ab-crm-header h1 {
        font-size: 20px;
    }

    .ab-btn, .ab-btn-primary, .ab-btn-filter, .ab-btn-reset {
        padding: 8px 12px;
        font-size: 12px;
    }

    .ab-select {
        padding: 8px 10px;
        font-size: 13px;
        height: 36px;
    }
}
</style>

<script>
// WordPress AJAX URL for frontend
var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';

document.addEventListener('DOMContentLoaded', function() {
    // Filtreleme alanını açma/kapama
    const toggleFiltersBtn = document.getElementById('toggle-filters-btn');
    const filtersContainer = document.getElementById('notifications-filters-container');

    if (toggleFiltersBtn && filtersContainer) {
        toggleFiltersBtn.addEventListener('click', function() {
            filtersContainer.classList.toggle('ab-filters-hidden');
            toggleFiltersBtn.classList.toggle('active');
        });
    }

    // Modal açma/kapama
    const modal = document.getElementById('announcement-modal');
    const modalTitle = document.getElementById('modal-title');
    const modalType = document.getElementById('modal-type');
    const modalCategory = document.getElementById('modal-category');
    const modalMessage = document.getElementById('modal-message');
    const modalRelated = document.getElementById('modal-related');
    const modalDate = document.getElementById('modal-date');
    const modalStatus = document.getElementById('modal-status');
    const modalMarkReadBtn = document.getElementById('modal-mark-read');
    const closeButtons = document.querySelectorAll('.modal-close, .modal-close-btn');

    // Duyuru satırlarına tıklama olayı ekle
    const announcementLinks = document.querySelectorAll('.announcement-link');
    announcementLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const row = this.closest('tr');
            const notificationId = row.dataset.notificationId;

            // Satırdaki bilgileri al
            const title = this.textContent;
            const message = row.cells[1].textContent;
            const type = row.cells[2].querySelector('.ab-badge').textContent;
            const category = row.cells[3].querySelector('.ab-badge').textContent;
            const related = row.cells[4].textContent;
            const date = row.cells[5].textContent;
            const status = row.cells[6].querySelector('.ab-badge').textContent;
            const isRead = row.classList.contains('unread') ? false : true;

            // Modal içeriğini doldur
            modalTitle.textContent = title;
            modalType.textContent = type;
            modalCategory.textContent = category;
            modalMessage.textContent = message;
            modalRelated.textContent = related;
            modalDate.textContent = date;
            modalStatus.textContent = status;

            // Okundu işaretleme butonunu göster/gizle
            if (!isRead) {
                modalMarkReadBtn.style.display = 'inline-flex';
                modalMarkReadBtn.dataset.notificationId = notificationId;
            } else {
                modalMarkReadBtn.style.display = 'none';
            }

            // Modal'ı aç
            modal.style.display = 'block';
        });
    });

    // Modal kapatma
    closeButtons.forEach(button => {
        button.addEventListener('click', function() {
            modal.style.display = 'none';
        });
    });

    // Modal dışında bir yere tıklandığında kapatma
    window.addEventListener('click', function(event) {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });

    // Modal'dan Okundu İşaretle
    if (modalMarkReadBtn) {
        modalMarkReadBtn.addEventListener('click', function() {
            const notificationId = this.dataset.notificationId;
            const nonce = this.dataset.nonce;

            console.log('AJAX isteği gönderiliyor: action=mark_notification_read, notification_id=' + notificationId + ', nonce=' + nonce);

            if (confirm('Bu bildirimi okundu olarak işaretlemek istediğinizden emin misiniz?')) {
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=mark_notification_read&notification_id=${notificationId}&nonce=${nonce}`
                })
                .then(response => {
                    console.log('AJAX yanıtı alındı:', response);
                    return response.json();
                })
                .then(data => {
                    console.log('AJAX veri:', data);
                    if (data.success) {
                        const row = document.querySelector(`tr[data-notification-id="${notificationId}"]`);
                        row.classList.remove('unread');
                        const statusBadge = row.querySelector('.ab-badge');
                        if (statusBadge) {
                            statusBadge.textContent = 'Okundu';
                            statusBadge.classList.remove('ab-badge-status-pasif');
                            statusBadge.classList.add('ab-badge-status-aktif');
                        }
                        const actionBtn = row.querySelector('.mark-read-action');
                        if (actionBtn) actionBtn.remove();
                        modalMarkReadBtn.style.display = 'none';
                        modalStatus.textContent = 'Okundu';

                        // Bildirim sayısını güncelle
                        const badge = document.querySelector('.notification-badge');
                        if (badge) {
                            let count = parseInt(badge.textContent) - 1;
                            if (count <= 0) {
                                badge.remove();
                            } else {
                                badge.textContent = count;
                            }
                        }
                        alert('Bildirim okundu olarak işaretlendi.');
                    } else {
                        alert('Hata: ' + (data.data.message || 'İşlem başarısız.'));
                    }
                })
                .catch(error => {
                    console.error('AJAX hatası:', error);
                    alert('Bir hata oluştu: ' + error.message);
                });
            }
        });
    }

    // Tek bir bildirimi okundu olarak işaretleme (tablodan) - Disabled since action buttons removed
    
    // Görev işleme alındı olarak işaretleme - Disabled since action buttons removed
});
</script>

<?php
/**
 * Görev Ekleme/Düzenleme Formu
 * @version 2.1.0
 * @date 2025-06-23
 * @author anadolubirlik
 * @description Validasyon hatası ve poliçe hizalama iyileştirmeleri
 */

include_once(dirname(__FILE__) . '/template-colors.php');

if (!is_user_logged_in()) {
    return;
}

// Veritabanı kontrolü ve task tablosuna gerekli sütunlar eklenmesi
global $wpdb;
$tasks_table = $wpdb->prefix . 'insurance_crm_tasks';

// Tablonun varlığını kontrol et
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$tasks_table'");
if (!$table_exists) {
    // Tablo yoksa oluştur
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $tasks_table (
        id int(11) NOT NULL AUTO_INCREMENT,
        task_title varchar(255) NOT NULL,
        customer_id int(11) NOT NULL,
        policy_id int(11) DEFAULT NULL,
        assigned_to int(11) NOT NULL,
        description text,
        status varchar(50) DEFAULT 'beklemede',
        priority varchar(20) DEFAULT 'normal',
        due_date date DEFAULT NULL,
        created_by int(11) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Gerekli sütunların varlığını kontrol et ve ekle
$columns_to_check = [
    'task_title' => "ALTER TABLE $tasks_table ADD COLUMN task_title VARCHAR(255) AFTER id",
    'customer_id' => "ALTER TABLE $tasks_table ADD COLUMN customer_id INT(11) AFTER task_title",
    'policy_id' => "ALTER TABLE $tasks_table ADD COLUMN policy_id INT(11) DEFAULT NULL AFTER customer_id",
    'priority' => "ALTER TABLE $tasks_table ADD COLUMN priority VARCHAR(20) DEFAULT 'normal' AFTER status",
    'due_date' => "ALTER TABLE $tasks_table ADD COLUMN due_date DATETIME DEFAULT NULL AFTER priority"
];

foreach ($columns_to_check as $column => $sql) {
    $column_exists = $wpdb->get_row("SHOW COLUMNS FROM $tasks_table LIKE '$column'");
    if (!$column_exists) {
        $wpdb->query($sql);
    }
}

// Existing due_date column type migration - change from DATE to DATETIME if needed
$due_date_column_info = $wpdb->get_row("SHOW COLUMNS FROM $tasks_table LIKE 'due_date'");
if ($due_date_column_info && strtolower($due_date_column_info->Type) === 'date') {
    error_log("Migrating due_date column from DATE to DATETIME");
    $wpdb->query("ALTER TABLE $tasks_table MODIFY COLUMN due_date DATETIME DEFAULT NULL");
}

// Form verilerini işle
$message = '';
$message_type = '';

if ($_POST && isset($_POST['action']) && $_POST['action'] === 'add_task') {
    $task_title = sanitize_text_field($_POST['task_title']);
    $customer_id = intval($_POST['customer_id']);
    $policy_id = !empty($_POST['policy_id']) ? intval($_POST['policy_id']) : null;
    $assigned_to = intval($_POST['assigned_to']);
    $description = sanitize_textarea_field($_POST['description']);
    $due_date = !empty($_POST['due_date']) ? sanitize_text_field($_POST['due_date']) : null;
    $priority = sanitize_text_field($_POST['priority']);
    $current_user_id = get_current_user_id();

    // Debug logging
    error_log("Task form submission - task_title: '$task_title', customer_id: '$customer_id', assigned_to: '$assigned_to'");

    // Validation
    $errors = [];
    if (empty($task_title)) {
        $errors[] = 'Görev başlığı gereklidir.';
        error_log("Task form validation error: empty task_title");
    }
    if (empty($customer_id)) {
        $errors[] = 'Müşteri seçimi gereklidir.';
        error_log("Task form validation error: empty customer_id");
    }
    if (empty($assigned_to)) {
        $errors[] = 'Görev atanacak kişi seçimi gereklidir.';
        error_log("Task form validation error: empty assigned_to");
    }

    if (empty($errors)) {
        $result = $wpdb->insert(
            $tasks_table,
            [
                'task_title' => $task_title,
                'customer_id' => $customer_id,
                'policy_id' => $policy_id,
                'assigned_to' => $assigned_to,
                'description' => $description,
                'status' => 'beklemede',
                'priority' => $priority,
                'due_date' => $due_date,
                'created_by' => $current_user_id,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            [
                '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
            ]
        );

        if ($result) {
            $message = 'Görev başarıyla eklendi.';
            $message_type = 'success';
        } else {
            $message = 'Görev eklenirken bir hata oluştu.';
            $message_type = 'error';
        }
    } else {
        $message = implode('<br>', $errors);
        $message_type = 'error';
    }
}

// Müşteri temsilcilerini çek (policies-form.php referansı ile)
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
$users = $wpdb->get_results("
    SELECT r.id, u.display_name, r.title 
    FROM $representatives_table r
    LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
    WHERE r.status = 'active'
    ORDER BY u.display_name
");

// Debug için log ekle
error_log("Task-form temsilci sorgusu çalıştırıldı. Bulunan temsilci sayısı: " . count($users));
if (empty($users)) {
    error_log("Hiç temsilci bulunamadı, alternatif sorgu deneniyor...");
    $users = $wpdb->get_results("
        SELECT r.id, r.user_id, 
               CONCAT(r.first_name, ' ', r.last_name) as display_name, r.title
        FROM $representatives_table r
        WHERE r.status = 'active'
        ORDER BY r.first_name, r.last_name
    ");
    error_log("Alternatif sorgu sonucu: " . count($users) . " temsilci bulundu");
}

// Müşterileri çek - doğru tablo adıyla
$customers = $wpdb->get_results("
    SELECT id, first_name, last_name, tc_identity, phone, category, company_name 
    FROM {$wpdb->prefix}insurance_crm_customers 
    WHERE status = 'aktif' 
    ORDER BY first_name, last_name ASC
");

// Eğer müşteri bulunamazsa alternatif sorgu dene
if (empty($customers)) {
    $customers = $wpdb->get_results("
        SELECT id, first_name, last_name, tc_identity, phone, category, company_name 
        FROM {$wpdb->prefix}insurance_crm_customers 
        ORDER BY first_name, last_name ASC
    ");
}

// Tüm poliçeleri çek - JavaScript değişkenine aktarmak için
$all_policies = $wpdb->get_results("
    SELECT id, customer_id, policy_number, policy_type, insurance_company, status, end_date
    FROM {$wpdb->prefix}insurance_crm_policies 
    WHERE status != 'iptal'
    ORDER BY customer_id, end_date ASC
");

error_log("Task form - Found " . count($users) . " assignable users");
error_log("Task form - Found " . count($customers) . " customers");
error_log("Task form - Found " . count($all_policies) . " policies");
?>

<style>
    /* Policies-form.php tarzında stil */
    .ab-task-form-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }

    .ab-form-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid <?php echo $corporate_color; ?>;
    }

    .ab-form-header h2 {
        color: <?php echo $corporate_color; ?>;
        font-size: 28px;
        font-weight: 600;
        margin: 0;
    }

    .ab-form-section {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        margin-bottom: 25px;
        padding: 25px;
    }

    .ab-form-section h3 {
        margin: 0 0 20px 0;
        color: #333;
        border-bottom: 2px solid <?php echo $corporate_color; ?>;
        padding-bottom: 10px;
        font-size: 18px;
        font-weight: 600;
    }

    .ab-form-row {
        display: flex;
        gap: 20px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .ab-form-group {
        flex: 1;
        min-width: 250px;
        display: flex;
        flex-direction: column;
    }

    .ab-form-group.full-width {
        flex: 100%;
    }

    .ab-form-group label {
        font-weight: 600;
        margin-bottom: 8px;
        color: #333;
        font-size: 14px;
    }

    .ab-form-group label.required::after {
        content: ' *';
        color: #dc3545;
    }

    .ab-input, .ab-select, .ab-textarea {
        padding: 12px;
        border: 2px solid #e9ecef;
        border-radius: 6px;
        font-size: 14px;
        transition: all 0.3s ease;
        background: #fff;
    }

    .ab-input:focus, .ab-select:focus, .ab-textarea:focus {
        border-color: <?php echo $corporate_color; ?>;
        outline: none;
        box-shadow: 0 0 0 3px <?php echo adjust_color_opacity($corporate_color, 0.1); ?>;
    }

    .ab-textarea {
        min-height: 100px;
        resize: vertical;
    }

    .selected-customer {
        background: <?php echo adjust_color_opacity($corporate_color, 0.1); ?>;
        border: 1px solid <?php echo $corporate_color; ?>;
        border-radius: 6px;
        padding: 15px;
        margin: 10px 0;
    }

    .selected-customer-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }

    .clear-selection-btn {
        background: #dc3545;
        color: white;
        border: none;
        border-radius: 4px;
        padding: 5px 8px;
        cursor: pointer;
        font-size: 12px;
        transition: all 0.3s ease;
    }

    .clear-selection-btn:hover {
        background: #c82333;
    }

    .priority-section {
        border: 2px solid <?php echo $corporate_color; ?>;
        border-radius: 8px;
        background: <?php echo adjust_color_opacity($corporate_color, 0.05); ?>;
    }

    .section-description {
        color: #666;
        font-size: 14px;
        margin-bottom: 15px;
        font-style: italic;
    }

    .policies-section {
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid #ddd;
    }

    .policies-section h4 {
        color: <?php echo $corporate_color; ?>;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .policy-hint {
        font-size: 13px;
        color: #666;
        margin-bottom: 10px;
    }

    .continue-without-policy {
        margin-top: 10px;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        cursor: pointer;
    }

    .policy-radio-item {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 4px;
        margin-bottom: 8px;
        transition: all 0.3s ease;
        display: flex;
        align-items: flex-start;
        padding: 10px;
    }

    .policy-radio-item:hover {
        background: #e9ecef;
        border-color: <?php echo $corporate_color; ?>;
    }

    .policy-radio-item.selected {
        background: <?php echo adjust_color_opacity($corporate_color, 0.1); ?>;
        border-color: <?php echo $corporate_color; ?>;
    }

    .policy-radio {
        margin: 0;
        margin-right: 12px;
        margin-top: 2px;
        flex-shrink: 0;
    }

    .policy-label {
        cursor: pointer;
        margin: 0;
        font-weight: normal;
        flex: 1;
        line-height: 1.4;
    }

    .policy-radio:checked + .policy-label {
        color: <?php echo $corporate_color; ?>;
        font-weight: 500;
    }

    .task-details-section {
        opacity: 0.5;
        pointer-events: none;
        transition: all 0.3s ease;
    }

    .task-details-section.enabled {
        opacity: 1;
        pointer-events: auto;
    }

    .selected-customer-name {
        font-weight: 600;
        color: <?php echo $corporate_color; ?>;
        font-size: 16px;
    }

    .selected-customer-info {
        color: #666;
        font-size: 14px;
        margin-top: 5px;
    }

    .policies-list {
        margin-top: 15px;
    }

    .ab-form-actions {
        text-align: center;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid #e9ecef;
    }

    .ab-btn {
        display: inline-block;
        padding: 12px 30px;
        margin: 0 10px;
        border: none;
        border-radius: 6px;
        text-decoration: none;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        text-align: center;
    }

    .ab-btn-primary {
        background: linear-gradient(135deg, <?php echo $corporate_color; ?>, <?php echo adjust_color_opacity($corporate_color, 0.8); ?>);
        color: #fff;
    }

    .ab-btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px <?php echo adjust_color_opacity($corporate_color, 0.3); ?>;
    }

    .ab-btn-secondary {
        background: #6c757d;
        color: #fff;
    }

    .ab-btn-secondary:hover {
        background: #5a6268;
        transform: translateY(-2px);
    }

    .notification {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 6px;
        border-left: 4px solid;
    }

    .notification.success {
        background-color: #d4edda;
        border-color: #28a745;
        color: #155724;
    }

    .notification.error {
        background-color: #f8d7da;
        border-color: #dc3545;
        color: #721c24;
    }

    .priority-high { border-left-color: #dc3545 !important; }
    .priority-normal { border-left-color: #28a745 !important; }
    .priority-low { border-left-color: #6c757d !important; }

    @media (max-width: 768px) {
        .ab-form-row {
            flex-direction: column;
        }
        
        .ab-form-group {
            min-width: 100%;
        }
    }
</style>

<div class="ab-task-form-container">
    <div class="ab-form-header">
        <h2><i class="fas fa-tasks"></i> Yeni Görev Ekle</h2>
        <a href="?view=tasks" class="ab-btn ab-btn-secondary">
            <i class="fas fa-arrow-left"></i> Görevlere Dön
        </a>
    </div>

    <?php if ($message): ?>
        <div class="notification <?php echo $message_type; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <form method="post" id="taskForm">
        <input type="hidden" name="action" value="add_task">
        <input type="hidden" name="customer_id" id="selected_customer_id" value="">
        <input type="hidden" name="policy_id" id="selected_policy_id" value="">

        <!-- Müşteri Seçimi -->
        <div class="ab-form-section priority-section">
            <h3><i class="fas fa-user-search"></i> 1. Müşteri Seçimi</h3>
            <div class="section-description">
                Önce görevi ilişkilendireceğiniz müşteriyi seçin
            </div>
            
            <div class="ab-form-group">
                <label for="customer_select" class="required">Müşteri Seçimi</label>
                <select name="customer_select" id="customer_select" class="ab-select" required>
                    <option value="">Müşteri Seçiniz...</option>
                    <?php foreach ($customers as $customer): ?>
                        <?php 
                        $display_name = $customer->first_name . ' ' . $customer->last_name;
                        if ($customer->category === 'kurumsal' && !empty($customer->company_name)) {
                            $display_name = $customer->company_name . ' (' . $customer->first_name . ' ' . $customer->last_name . ')';
                        }
                        ?>
                        <option value="<?php echo esc_attr($customer->id); ?>" 
                                data-type="<?php echo esc_attr($customer->category); ?>"
                                data-name="<?php echo esc_attr($display_name); ?>">
                            <?php echo esc_html($display_name); ?> 
                            (<?php echo esc_html($customer->category === 'kurumsal' ? 'Kurumsal' : 'Bireysel'); ?>)
                            <?php if (!empty($customer->tc_identity)): ?>
                                - TC: <?php echo esc_html($customer->tc_identity); ?>
                            <?php endif; ?>
                            <?php if (!empty($customer->phone)): ?>
                                - Tel: <?php echo esc_html($customer->phone); ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="selectedCustomerInfo" class="selected-customer" style="display: none;">
                <div class="selected-customer-header">
                    <div class="selected-customer-name"></div>
                    <button type="button" class="clear-selection-btn" onclick="clearCustomerSelection()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="selected-customer-info"></div>
                <div class="policies-section">
                    <h4><i class="fas fa-file-contract"></i> İlgili Poliçe Seçin (Opsiyonel):</h4>
                    <p class="policy-hint">Görev belirli bir poliçe ile ilgiliyse seçin, aksi takdirde boş bırakabilirsiniz.</p>
                    <div id="customerPolicies"></div>
                    <label class="continue-without-policy">
                        <input type="checkbox" id="continueWithoutPolicy"> 
                        Poliçe seçmeden devam et
                    </label>
                </div>
            </div>
        </div>

        <!-- Görev Bilgileri -->
        <div class="ab-form-section task-details-section" style="display: none;">
            <h3><i class="fas fa-tasks"></i> 2. Görev Bilgileri</h3>
            <div class="section-description">
                Görev detaylarını doldurun
            </div>
            
            <div class="ab-form-row">
                <div class="ab-form-group">
                    <label for="task_title" class="required">Görev Başlığı</label>
                    <input type="text" id="task_title" name="task_title" class="ab-input" 
                           placeholder="Görev başlığını girin..." required>
                </div>
                
                <div class="ab-form-group">
                    <label for="priority" class="required">Öncelik</label>
                    <select id="priority" name="priority" class="ab-select" required>
                        <option value="">Öncelik Seçin</option>
                        <option value="low">Düşük</option>
                        <option value="normal" selected>Normal</option>
                        <option value="high">Yüksek</option>
                    </select>
                </div>
            </div>

            <div class="ab-form-row">
                <div class="ab-form-group">
                    <label for="assigned_to" class="required">Atanacak Kişi</label>
                    <select id="assigned_to" name="assigned_to" class="ab-select" required>
                        <option value="">Kişi Seçin</option>
                        <?php if (!empty($users)): ?>
                            <?php foreach ($users as $user): 
                                $title = !empty($user->title) ? $user->title : '';
                                $display_name = !empty($user->display_name) ? $user->display_name : '';
                            ?>
                                <option value="<?php echo $user->id; ?>">
                                    <?php echo esc_html($display_name); ?> 
                                    <?php if ($title): ?>
                                        (<?php echo esc_html($title); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="" disabled>Müşteri temsilcisi bulunamadı</option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="ab-form-group">
                    <label for="due_date">Görev Son Tarihi</label>
                    <input type="datetime-local" id="due_date" name="due_date" class="ab-input" 
                           min="<?php echo date('Y-m-d\TH:i'); ?>">
                </div>
            </div>

            <div class="ab-form-row">
                <div class="ab-form-group full-width">
                    <label for="description">Görev Açıklaması</label>
                    <textarea id="description" name="description" class="ab-textarea" 
                              placeholder="Görev detaylarını girin..."></textarea>
                </div>
            </div>
        </div>

        <div class="ab-form-actions">
            <input type="submit" class="ab-btn ab-btn-primary" value="Görev Ekle" disabled id="submitBtn">
            <a href="?view=tasks" class="ab-btn ab-btn-secondary">İptal</a>
        </div>
    </form>
</div>

<script>
const ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';

// Tüm poliçe verilerini JavaScript değişkenine aktar
const allPolicies = <?php echo json_encode($all_policies); ?>;

jQuery(document).ready(function($) {
    let selectedCustomer = null;
    
    // Müşteri seçimi dropdown
    $('#customer_select').on('change', function() {
        const customerId = $(this).val();
        const selectedOption = $(this).find('option:selected');
        const customerName = selectedOption.data('name');
        const customerType = selectedOption.data('type');
        
        console.log('Müşteri seçildi:', customerId, customerName, customerType);
        
        if (customerId && customerName) {
            selectCustomer(customerId, customerName, customerType);
        } else {
            clearCustomerSelection();
        }
    });
    
    function searchCustomers(term) {
        console.log('🔍 AJAX çağrısı başlatılıyor. Arama terimi:', term);
        console.log('🌐 AJAX URL:', ajaxurl);
        
        const formData = new FormData();
        formData.append('action', 'search_customers_for_policy');
        formData.append('query', term);
        formData.append('nonce', '<?php echo wp_create_nonce("search_customers_nonce"); ?>');
        
        console.log('📤 Gönderilen veri:', {
            action: 'search_customers_for_policy',
            query: term,
            nonce: '<?php echo wp_create_nonce("search_customers_nonce"); ?>'
        });
        
        $('#customerSearchResults').html('<div class="customer-search-item">Aranıyor...</div>').show();
        
        fetch(ajaxurl, {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('📥 Ham yanıt alındı:', response);
            return response.json();
        })
        .then(data => {
            console.log('✅ JSON parse başarılı:', data);
            
            if (data.success && data.data && Array.isArray(data.data)) {
                console.log('📋 Müşteri sayısı:', data.data.length);
                displaySearchResults(data.data);
            } else {
                console.log('❌ Arama başarısız veya veri bulunamadı:', data);
                $('#customerSearchResults').html('<div class="customer-search-item">Müşteri bulunamadı</div>').show();
            }
        })
        .catch(error => {
            console.error('❌ Fetch hatası:', error);
            $('#customerSearchResults').html('<div class="customer-search-item">Bağlantı hatası oluştu</div>').show();
        });
    }
    
    function displaySearchResults(customers) {
        console.log('📋 Arama sonuçları işleniyor. Müşteri sayısı:', customers.length);
        console.log('👥 Müşteri verileri:', customers);
        
        if (!Array.isArray(customers) || customers.length === 0) {
            console.log('⚠️ Müşteri verisi boş veya geçersiz');
            $('#customerSearchResults').html('<div class="customer-search-item">Müşteri bulunamadı</div>').show();
            return;
        }
        
        let html = '';
        customers.forEach(function(customer, index) {
            console.log(`👤 Müşteri ${index + 1}:`, customer);
            
            // Determine customer type and display name
            const customerType = customer.customer_type === 'kurumsal' ? 'Kurumsal' : 'Bireysel';
            const customerName = customer.customer_type === 'kurumsal' ? 
                (customer.company_name || 'Şirket adı belirtilmemiş') : 
                ((customer.first_name || '') + ' ' + (customer.last_name || '')).trim();
            
            const customerInfo = customer.customer_type === 'kurumsal' ? 
                'VKN: ' + (customer.tax_number || 'Belirtilmemiş') : 
                'TC: ' + (customer.tc_identity || 'Belirtilmemiş');
            
            console.log(`  📝 Görüntü adı: "${customerName}"`);
            console.log(`  🏢 Tür: ${customerType}`);
            console.log(`  📄 Bilgi: ${customerInfo}`);
            
            if (customerName.trim() === '') {
                console.log('⚠️ Müşteri adı boş, atlaniyor');
                return;
            }
            
            html += `<div class="customer-search-item" 
                           data-customer-id="${customer.id}" 
                           data-customer-name="${customerName}" 
                           data-customer-type="${customer.customer_type}">
                        <strong>${customerName}</strong> (${customerType})<br>
                        <small>${customerInfo}</small>
                     </div>`;
        });
        
        if (html === '') {
            console.log('❌ HTML içeriği boş');
            $('#customerSearchResults').html('<div class="customer-search-item">Gösterilecek müşteri bulunamadı</div>').show();
        } else {
            console.log('✅ HTML içeriği oluşturuldu, gösteriliyor');
            $('#customerSearchResults').html(html).show();
        }
    }
    
    // Müşteri seçimi
    $(document).on('click', '.customer-search-item', function() {
        console.log('🖱️ Müşteri öğesine tıklandı');
        console.log('🎯 Tıklanan element:', this);
        
        const customerId = $(this).data('customer-id');
        const customerName = $(this).data('customer-name');
        const customerType = $(this).data('customer-type');
        
        console.log('📦 Okunan veri attributeleri:');
        console.log('  🆔 ID:', customerId);
        console.log('  👤 İsim:', customerName);
        console.log('  🏢 Tür:', customerType);
        
        if (customerId && customerName) {
            console.log('✅ Veriler geçerli, müşteri seçiliyor');
            selectCustomer(customerId, customerName, customerType);
            $('#customerSearchResults').hide();
            $('#customerSearch').val(customerName);
        } else {
            console.error('❌ Müşteri ID veya isim bulunamadı');
            console.error('  Mevcut data attributeleri:', $(this).data());
        }
    });
    
    function selectCustomer(customerId, customerName, customerType) {
        console.log('👤 Müşteri seçme işlemi başlatılıyor:');
        console.log('  🆔 Müşteri ID:', customerId);
        console.log('  👤 Müşteri Adı:', customerName);
        console.log('  🏢 Müşteri Türü:', customerType);
        
        selectedCustomer = {
            id: customerId,
            name: customerName,
            type: customerType
        };
        
        // Hidden field'ı güncelle
        $('#selected_customer_id').val(customerId);
        console.log('🔑 Hidden field güncellendi. Değer:', $('#selected_customer_id').val());
        
        // Display customer info
        $('.selected-customer-name').text(customerName);
        $('.selected-customer-info').text(`Müşteri Tipi: ${customerType === 'kurumsal' ? 'Kurumsal' : 'Bireysel'}`);
        $('#selectedCustomerInfo').show();
        
        // Enable task details section
        $('.task-details-section').show().addClass('enabled');
        $('#submitBtn').prop('disabled', false);
        
        console.log('🎯 UI güncellendi:');
        console.log('  📊 Müşteri bilgisi paneli gösterildi');
        console.log('  ⚙️ Görev detayları bölümü etkinleştirildi');
        console.log('  🔘 Submit butonu etkinleştirildi');
        
        // Müşterinin poliçelerini yükle
        console.log('📋 Müşteri poliçeleri yükleniyor...');
        loadCustomerPolicies(customerId);
    }
    
    // Clear customer selection function
    window.clearCustomerSelection = function() {
        selectedCustomer = null;
        $('#selected_customer_id').val('');
        $('#selected_policy_id').val('');
        $('#selectedCustomerInfo').hide();
        $('#customer_select').val('');
        $('.task-details-section').hide().removeClass('enabled');
        $('#submitBtn').prop('disabled', true);
        $('#continueWithoutPolicy').prop('checked', false);
        $('.policy-radio').prop('checked', false);
    };
    
    function loadCustomerPolicies(customerId) {
        console.log('🔍 Müşteri poliçeleri yükleniyor - ID:', customerId);
        
        // Tüm poliçeler arasından ilgili müşterinin poliçelerini filtrele
        const customerPolicies = allPolicies.filter(policy => policy.customer_id == customerId);
        
        console.log('📋 Bulunan poliçe sayısı:', customerPolicies.length);
        displayCustomerPolicies(customerPolicies);
    }
    
    function displayCustomerPolicies(policies) {
        let html = '';
        console.log('🖼️ Poliçe listesi oluşturuluyor:', policies);
        
        if (policies.length > 0) {
            policies.forEach(function(policy, index) {
                console.log('📄 Poliçe işleniyor:', policy);
                const endDate = policy.end_date ? new Date(policy.end_date).toLocaleDateString('tr-TR') : 'Belirtilmemiş';
                const radioId = 'policy_' + policy.id;
                
                html += `<div class="policy-radio-item">
                            <input type="radio" id="${radioId}" name="policy_selection" value="${policy.id}" class="policy-radio">
                            <label for="${radioId}" class="policy-label">
                                <strong>${policy.policy_number || 'Poliçe No Belirtilmemiş'}</strong> - ${policy.policy_type || 'Tip Belirtilmemiş'}<br>
                                <small>Şirket: ${policy.insurance_company || 'Belirtilmemiş'} | Durum: ${policy.status || 'Belirtilmemiş'} | 
                                Bitiş: ${endDate}</small>
                            </label>
                         </div>`;
            });
            console.log('✅ HTML oluşturuldu, toplam poliçe:', policies.length);
        } else {
            html = '<p class="no-policies">Bu müşteriye ait aktif poliçe bulunamadı.</p>';
            console.log('ℹ️ Poliçe bulunamadı mesajı gösteriliyor');
        }
        
        $('#customerPolicies').html(html);
    }
    
    // Poliçe seçimi - radio button değişikliği
    $(document).on('change', '.policy-radio', function() {
        const selectedPolicyId = $(this).val();
        $('#selected_policy_id').val(selectedPolicyId);
        console.log('📋 Seçilen poliçe ID:', selectedPolicyId);
        
        // Remove selected class from all policy items and add to current one
        $('.policy-radio-item').removeClass('selected');
        $(this).closest('.policy-radio-item').addClass('selected');
        
        // Uncheck continue without policy
        $('#continueWithoutPolicy').prop('checked', false);
    });
    
    // Continue without policy checkbox
    $('#continueWithoutPolicy').on('change', function() {
        if ($(this).is(':checked')) {
            $('.policy-radio').prop('checked', false);
            $('.policy-radio-item').removeClass('selected');
            $('#selected_policy_id').val('');
            console.log('✅ Poliçe seçmeden devam et işaretlendi');
        }
    });
    
    // Form gönderimi kontrolü
    $('#taskForm').on('submit', function(e) {
        console.log('📝 Form gönderimi kontrol ediliyor...');
        console.log('Seçili müşteri ID:', $('#selected_customer_id').val());
        console.log('Görev başlığı:', $('#task_title').val());
        console.log('Atanacak kişi:', $('#assigned_to').val());
        console.log('Poliçe ID:', $('#selected_policy_id').val());
        console.log('Poliçe seçmeden devam:', $('#continueWithoutPolicy').is(':checked'));
        
        if (!$('#selected_customer_id').val()) {
            e.preventDefault();
            alert('Lütfen önce bir müşteri seçin.');
            $('#customer_select').focus();
            return false;
        }
        
        if (!$('#task_title').val().trim()) {
            e.preventDefault();
            alert('Lütfen görev başlığını girin.');
            $('#task_title').focus();
            return false;
        }
        
        if (!$('#assigned_to').val()) {
            e.preventDefault();
            alert('Lütfen görevin atanacağı kişiyi seçin.');
            $('#assigned_to').focus();
            return false;
        }
        
        // Policy selection is optional - either select a policy or check continue without policy
        console.log('✅ Form validasyonu başarılı, gönderiliyor...');
        return true;
    });
    
    // Dış tıklamada arama sonuçlarını gizle
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.customer-search-container').length) {
            $('#customerSearchResults').hide();
        }
    });
});
</script>

<?php
// AJAX handlers - Keep only the ones we still need
add_action('wp_ajax_search_customers_for_tasks', 'handle_search_customers_for_tasks');

function handle_search_customers_for_tasks() {
    if (!wp_verify_nonce($_POST['nonce'], 'search_customers_nonce')) {
        wp_die('Security check failed');
    }
    
    global $wpdb;
    $search_term = sanitize_text_field($_POST['search_term']);
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    
    $search_term_like = '%' . $wpdb->esc_like($search_term) . '%';
    
    $query = $wpdb->prepare("
        SELECT id, customer_type, first_name, last_name, company_name, tc_identity, tax_number
        FROM $customers_table 
        WHERE (
            CONCAT(first_name, ' ', last_name) LIKE %s OR
            company_name LIKE %s OR
            tc_identity LIKE %s OR
            tax_number LIKE %s OR
            phone LIKE %s
        )
        ORDER BY 
            CASE 
                WHEN customer_type = 'kurumsal' THEN company_name 
                ELSE CONCAT(first_name, ' ', last_name) 
            END
        LIMIT 20
    ", $search_term_like, $search_term_like, $search_term_like, $search_term_like, $search_term_like);
    
    $customers = $wpdb->get_results($query, ARRAY_A);
    
    if ($customers) {
        wp_send_json_success($customers);
    } else {
        wp_send_json_error('Müşteri bulunamadı');
    }
}
?>
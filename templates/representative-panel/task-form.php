<?php
/**
 * Görev Ekleme/Düzenleme Formu
 * @version 2.0.0
 * @date 2025-06-23
 * @author anadolubirlik
 * @description Policies-form.php tarzında yeniden tasarlandı
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
    'due_date' => "ALTER TABLE $tasks_table ADD COLUMN due_date DATE DEFAULT NULL AFTER priority"
];

foreach ($columns_to_check as $column => $sql) {
    $column_exists = $wpdb->get_row("SHOW COLUMNS FROM $tasks_table LIKE '$column'");
    if (!$column_exists) {
        $wpdb->query($sql);
    }
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

    // Validation
    $errors = [];
    if (empty($task_title)) {
        $errors[] = 'Görev başlığı gereklidir.';
    }
    if (empty($customer_id)) {
        $errors[] = 'Müşteri seçimi gereklidir.';
    }
    if (empty($assigned_to)) {
        $errors[] = 'Görev atanacak kişi seçimi gereklidir.';
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

// Kullanıcıları çek (görev atanabilir kişiler)
$users = get_users([
    'meta_key' => 'insurance_crm_role',
    'meta_value' => '',
    'meta_compare' => '!='
]);
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

    .customer-search-container {
        position: relative;
        margin-bottom: 20px;
    }

    .customer-search-input {
        width: 100%;
        padding: 12px;
        border: 2px solid #e9ecef;
        border-radius: 6px;
        font-size: 14px;
    }

    .customer-search-results {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #ddd;
        border-radius: 6px;
        max-height: 300px;
        overflow-y: auto;
        z-index: 1000;
        display: none;
    }

    .customer-search-item {
        padding: 12px;
        cursor: pointer;
        border-bottom: 1px solid #eee;
        transition: background-color 0.2s;
    }

    .customer-search-item:hover {
        background-color: #f8f9fa;
    }

    .customer-search-item:last-child {
        border-bottom: none;
    }

    .selected-customer {
        background: <?php echo adjust_color_opacity($corporate_color, 0.1); ?>;
        border: 1px solid <?php echo $corporate_color; ?>;
        border-radius: 6px;
        padding: 15px;
        margin: 10px 0;
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

    .policy-item {
        background: white;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 10px;
        margin-bottom: 8px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .policy-item:hover {
        border-color: <?php echo $corporate_color; ?>;
        background: <?php echo adjust_color_opacity($corporate_color, 0.05); ?>;
    }

    .policy-item.selected {
        border-color: <?php echo $corporate_color; ?>;
        background: <?php echo adjust_color_opacity($corporate_color, 0.1); ?>;
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

        <!-- Görev Bilgileri -->
        <div class="ab-form-section">
            <h3><i class="fas fa-info-circle"></i> Görev Bilgileri</h3>
            
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
                        <?php foreach ($users as $user): 
                            $role_name = get_user_meta($user->ID, 'insurance_crm_role_name', true);
                        ?>
                            <option value="<?php echo $user->ID; ?>">
                                <?php echo esc_html($user->display_name); ?> 
                                <?php if ($role_name): ?>
                                    (<?php echo esc_html($role_name); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="ab-form-group">
                    <label for="due_date">Teslim Tarihi</label>
                    <input type="date" id="due_date" name="due_date" class="ab-input" 
                           min="<?php echo date('Y-m-d'); ?>">
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

        <!-- Müşteri Seçimi -->
        <div class="ab-form-section">
            <h3><i class="fas fa-user"></i> Müşteri Seçimi</h3>
            
            <div class="customer-search-container">
                <input type="text" id="customerSearch" class="customer-search-input" 
                       placeholder="Müşteri adı, TC kimlik no, telefon ile arayın..." 
                       autocomplete="off">
                <div id="customerSearchResults" class="customer-search-results"></div>
            </div>

            <div id="selectedCustomerInfo" class="selected-customer" style="display: none;">
                <div class="selected-customer-name"></div>
                <div class="selected-customer-info"></div>
                <div class="policies-list">
                    <h4>İlgili Poliçe Seçin (Opsiyonel):</h4>
                    <div id="customerPolicies"></div>
                </div>
            </div>
        </div>

        <div class="ab-form-actions">
            <input type="submit" class="ab-btn ab-btn-primary" value="Görev Ekle">
            <a href="?view=tasks" class="ab-btn ab-btn-secondary">İptal</a>
        </div>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    let searchTimeout;
    let selectedCustomer = null;
    
    // Müşteri arama
    $('#customerSearch').on('input', function() {
        const searchTerm = $(this).val().trim();
        
        clearTimeout(searchTimeout);
        
        if (searchTerm.length < 2) {
            $('#customerSearchResults').hide();
            return;
        }
        
        searchTimeout = setTimeout(function() {
            searchCustomers(searchTerm);
        }, 500);
    });
    
    function searchCustomers(term) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'search_customers_for_tasks',
                search_term: term,
                nonce: '<?php echo wp_create_nonce("search_customers_nonce"); ?>'
            },
            success: function(response) {
                if (response.success && response.data) {
                    displaySearchResults(response.data);
                } else {
                    $('#customerSearchResults').html('<div class="customer-search-item">Müşteri bulunamadı</div>').show();
                }
            },
            error: function() {
                $('#customerSearchResults').html('<div class="customer-search-item">Arama sırasında hata oluştu</div>').show();
            }
        });
    }
    
    function displaySearchResults(customers) {
        let html = '';
        customers.forEach(function(customer) {
            const customerType = customer.customer_type === 'kurumsal' ? 'Kurumsal' : 'Bireysel';
            const customerName = customer.customer_type === 'kurumsal' ? 
                customer.company_name : 
                customer.first_name + ' ' + customer.last_name;
            const customerInfo = customer.customer_type === 'kurumsal' ? 
                'VKN: ' + (customer.tax_number || 'Belirtilmemiş') : 
                'TC: ' + (customer.tc_identity || 'Belirtilmemiş');
            
            html += `<div class="customer-search-item" data-customer-id="${customer.id}" 
                           data-customer-name="${customerName}" 
                           data-customer-type="${customer.customer_type}">
                        <strong>${customerName}</strong> (${customerType})<br>
                        <small>${customerInfo}</small>
                     </div>`;
        });
        
        $('#customerSearchResults').html(html).show();
    }
    
    // Müşteri seçimi
    $(document).on('click', '.customer-search-item', function() {
        if ($(this).data('customer-id')) {
            const customerId = $(this).data('customer-id');
            const customerName = $(this).data('customer-name');
            const customerType = $(this).data('customer-type');
            
            selectCustomer(customerId, customerName, customerType);
            $('#customerSearchResults').hide();
            $('#customerSearch').val(customerName);
        }
    });
    
    function selectCustomer(customerId, customerName, customerType) {
        selectedCustomer = {
            id: customerId,
            name: customerName,
            type: customerType
        };
        
        $('#selected_customer_id').val(customerId);
        $('.selected-customer-name').text(customerName);
        $('.selected-customer-info').text(`Müşteri Tipi: ${customerType === 'kurumsal' ? 'Kurumsal' : 'Bireysel'}`);
        $('#selectedCustomerInfo').show();
        
        // Müşterinin poliçelerini yükle
        loadCustomerPolicies(customerId);
    }
    
    function loadCustomerPolicies(customerId) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'get_customer_policies_for_tasks',
                customer_id: customerId,
                nonce: '<?php echo wp_create_nonce("get_policies_nonce"); ?>'
            },
            success: function(response) {
                if (response.success && response.data) {
                    displayCustomerPolicies(response.data);
                } else {
                    $('#customerPolicies').html('<p>Bu müşteriye ait aktif poliçe bulunamadı.</p>');
                }
            },
            error: function() {
                $('#customerPolicies').html('<p>Poliçeler yüklenirken hata oluştu.</p>');
            }
        });
    }
    
    function displayCustomerPolicies(policies) {
        let html = '';
        policies.forEach(function(policy) {
            html += `<div class="policy-item" data-policy-id="${policy.id}">
                        <strong>${policy.policy_number}</strong> - ${policy.policy_type}<br>
                        <small>Şirket: ${policy.insurance_company} | Durum: ${policy.status}</small>
                     </div>`;
        });
        
        $('#customerPolicies').html(html);
    }
    
    // Poliçe seçimi
    $(document).on('click', '.policy-item', function() {
        $('.policy-item').removeClass('selected');
        $(this).addClass('selected');
        $('#selected_policy_id').val($(this).data('policy-id'));
    });
    
    // Form gönderimi kontrolü
    $('#taskForm').on('submit', function(e) {
        if (!$('#selected_customer_id').val()) {
            e.preventDefault();
            alert('Lütfen bir müşteri seçin.');
            return false;
        }
        
        if (!$('#task_title').val().trim()) {
            e.preventDefault();
            alert('Lütfen görev başlığını girin.');
            return false;
        }
        
        if (!$('#assigned_to').val()) {
            e.preventDefault();
            alert('Lütfen görevin atanacağı kişiyi seçin.');
            return false;
        }
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
// AJAX handlers
add_action('wp_ajax_search_customers_for_tasks', 'handle_search_customers_for_tasks');
add_action('wp_ajax_get_customer_policies_for_tasks', 'handle_get_customer_policies_for_tasks');

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

function handle_get_customer_policies_for_tasks() {
    if (!wp_verify_nonce($_POST['nonce'], 'get_policies_nonce')) {
        wp_die('Security check failed');
    }
    
    global $wpdb;
    $customer_id = intval($_POST['customer_id']);
    $policies_table = $wpdb->prefix . 'insurance_crm_policies';
    
    $policies = $wpdb->get_results($wpdb->prepare("
        SELECT id, policy_number, policy_type, insurance_company, status
        FROM $policies_table 
        WHERE customer_id = %d AND status != 'iptal'
        ORDER BY created_at DESC
        LIMIT 10
    ", $customer_id), ARRAY_A);
    
    if ($policies) {
        wp_send_json_success($policies);
    } else {
        wp_send_json_error('Poliçe bulunamadı');
    }
}
?>
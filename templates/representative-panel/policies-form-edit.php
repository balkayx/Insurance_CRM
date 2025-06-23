<?php
/**
 * Dedicated Policy Edit/Renewal Form - Simplified Version
 * @version 1.0.0
 * @updated 2025-05-30
 */

include_once(dirname(__FILE__) . '/template-colors.php');

if (!is_user_logged_in()) {
    return;
}

global $wpdb;
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$customers_table = $wpdb->prefix . 'insurance_crm_customers';

// Ensure gross_premium column exists
$gross_premium_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'gross_premium'");
if (!$gross_premium_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN gross_premium DECIMAL(10,2) DEFAULT NULL AFTER premium_amount");
}

// Determine action and policy ID
$editing = isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']) && intval($_GET['id']) > 0;
$renewing = isset($_GET['action']) && $_GET['action'] === 'renew' && isset($_GET['id']) && intval($_GET['id']) > 0;
$policy_id = $editing || $renewing ? intval($_GET['id']) : 0;

if (!$policy_id) {
    echo '<div class="ab-notice ab-error">Geçersiz poliçe ID.</div>';
    return;
}

// Get current user rep ID
$current_user_rep_id = function_exists('get_current_user_rep_id') ? get_current_user_rep_id() : 0;

// Permission check functions
function get_current_user_role() {
    global $wpdb;
    $current_user_rep_id = function_exists('get_current_user_rep_id') ? get_current_user_rep_id() : 0;
    
    if (!$current_user_rep_id) {
        return 0;
    }
    
    $representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
    $role = $wpdb->get_var($wpdb->prepare(
        "SELECT role FROM $representatives_table WHERE id = %d",
        $current_user_rep_id
    ));
    
    return intval($role);
}

// Handle form submission
if (isset($_POST['save_policy']) && isset($_POST['policy_nonce']) && wp_verify_nonce($_POST['policy_nonce'], 'save_policy_edit')) {
    $policy_data = array(
        'policy_number' => sanitize_text_field($_POST['policy_number']),
        'policy_type' => sanitize_text_field($_POST['policy_type']),
        'policy_category' => sanitize_text_field($_POST['policy_category']),
        'insurance_company' => sanitize_text_field($_POST['insurance_company']),
        'start_date' => sanitize_text_field($_POST['start_date']),
        'end_date' => sanitize_text_field($_POST['end_date']),
        'premium_amount' => floatval($_POST['premium_amount']),
        'gross_premium' => isset($_POST['gross_premium']) ? floatval($_POST['gross_premium']) : null,
        'payment_info' => isset($_POST['payment_info']) ? sanitize_text_field($_POST['payment_info']) : '',
        'insured_list' => isset($_POST['selected_insured']) ? implode(', ', array_map('sanitize_text_field', $_POST['selected_insured'])) : '',
        'status' => sanitize_text_field($_POST['status']),
        'updated_at' => current_time('mysql')
    );
    
    if (in_array(strtolower($policy_data['policy_type']), ['kasko', 'trafik']) && isset($_POST['plate_number'])) {
        $policy_data['plate_number'] = sanitize_text_field($_POST['plate_number']);
    }
    
    // Handle document upload
    if (!empty($_FILES['document']['name'])) {
        $upload_dir = wp_upload_dir();
        $policy_upload_dir = $upload_dir['basedir'] . '/insurance-crm-docs';
        
        if (!file_exists($policy_upload_dir)) {
            wp_mkdir_p($policy_upload_dir);
        }
        
        $allowed_file_types = array('jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx');
        $file_ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        
        if (in_array($file_ext, $allowed_file_types)) {
            $file_name = 'policy-' . time() . '-' . sanitize_file_name($_FILES['document']['name']);
            $file_path = $policy_upload_dir . '/' . $file_name;
            
            if (move_uploaded_file($_FILES['document']['tmp_name'], $file_path)) {
                $policy_data['document_path'] = $upload_dir['baseurl'] . '/insurance-crm-docs/' . $file_name;
            }
        }
    }
    
    if ($editing) {
        $result = $wpdb->update($policies_table, $policy_data, array('id' => $policy_id));
        $message = 'Poliçe başarıyla güncellendi.';
        $redirect_url = '?view=policies&action=view&id=' . $policy_id;
    } else { // renewing
        // Remove ID-related fields for renewal
        unset($policy_data['updated_at']);
        $policy_data['created_at'] = current_time('mysql');
        $policy_data['representative_id'] = $current_user_rep_id;
        
        // Get original policy to copy customer_id
        $original_policy = $wpdb->get_row($wpdb->prepare("SELECT customer_id FROM $policies_table WHERE id = %d", $policy_id));
        if ($original_policy) {
            $policy_data['customer_id'] = $original_policy->customer_id;
        }
        
        $result = $wpdb->insert($policies_table, $policy_data);
        $new_policy_id = $wpdb->insert_id;
        $message = 'Poliçe yenileme başarıyla oluşturuldu.';
        $redirect_url = '?view=policies&action=view&id=' . $new_policy_id;
    }
    
    if ($result !== false) {
        echo '<script>
            alert("' . $message . '");
            window.location.href = "' . $redirect_url . '";
        </script>';
        return;
    } else {
        echo '<div class="ab-notice ab-error">İşlem sırasında bir hata oluştu.</div>';
    }
}

// Fetch policy and customer data
$policy = $wpdb->get_row($wpdb->prepare("SELECT * FROM $policies_table WHERE id = %d", $policy_id));

if (!$policy) {
    echo '<div class="ab-notice ab-error">Poliçe bulunamadı.</div>';
    return;
}

$customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customers_table WHERE id = %d", $policy->customer_id));

if (!$customer) {
    echo '<div class="ab-notice ab-error">Müşteri bulunamadı.</div>';
    return;
}

// For renewal, adjust dates and clear certain fields
if ($renewing) {
    $policy->policy_number = '';
    $policy->status = 'aktif';
    $policy->start_date = date('Y-m-d', strtotime($policy->end_date . ' +1 day'));
    $policy->end_date = date('Y-m-d', strtotime($policy->end_date . ' +1 year'));
}

// Prepare family members for insured selection
$family_members = array();
$family_members[] = array(
    'name' => trim($customer->first_name . ' ' . $customer->last_name),
    'tc' => $customer->tc_identity,
    'relation' => 'Müşteri'
);

if (!empty($customer->spouse_name)) {
    $family_members[] = array(
        'name' => $customer->spouse_name,
        'tc' => $customer->spouse_tc_identity,
        'relation' => 'Eş'
    );
}

if (!empty($customer->children_names)) {
    $children_names = explode(',', $customer->children_names);
    $children_tcs = !empty($customer->children_tc_identities) ? explode(',', $customer->children_tc_identities) : array();
    
    foreach ($children_names as $index => $child_name) {
        $child_tc = isset($children_tcs[$index]) ? trim($children_tcs[$index]) : '';
        $family_members[] = array(
            'name' => trim($child_name),
            'tc' => $child_tc,
            'relation' => 'Çocuk'
        );
    }
}

$selected_insured = !empty($policy->insured_list) ? explode(', ', $policy->insured_list) : array();
?>

<div class="wrap">
    <div class="ab-container">
        <div class="ab-header">
            <div class="ab-header-content">
                <h1><i class="fas fa-edit"></i> <?php echo $editing ? 'Poliçe Düzenle' : 'Poliçe Yenile'; ?></h1>
                <p>Poliçe bilgilerini düzenleyin veya yenileyin</p>
            </div>
            <div class="ab-header-actions">
                <a href="?view=policies" class="ab-btn ab-btn-secondary">
                    <i class="fas fa-arrow-left"></i> Poliçeler
                </a>
            </div>
        </div>

        <form method="post" enctype="multipart/form-data" class="ab-form">
            <?php wp_nonce_field('save_policy_edit', 'policy_nonce'); ?>
            
            <!-- Customer Information (Read-only) -->
            <div class="ab-form-section">
                <h3><i class="fas fa-user"></i> Müşteri Bilgileri</h3>
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label>Ad Soyad / Firma Adı</label>
                        <input type="text" class="ab-input" value="<?php 
                        echo esc_attr(!empty($customer->company_name) ? $customer->company_name : trim($customer->first_name . ' ' . $customer->last_name)); 
                        ?>" readonly>
                    </div>
                    <div class="ab-form-group">
                        <label>TC / VKN</label>
                        <input type="text" class="ab-input" value="<?php 
                        echo esc_attr(!empty($customer->tax_number) ? $customer->tax_number : $customer->tc_identity); 
                        ?>" readonly>
                    </div>
                </div>
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label>Telefon</label>
                        <input type="text" class="ab-input" value="<?php echo esc_attr($customer->phone ?: 'Belirtilmemiş'); ?>" readonly>
                    </div>
                    <div class="ab-form-group">
                        <label>E-posta</label>
                        <input type="text" class="ab-input" value="<?php echo esc_attr($customer->email ?: 'Belirtilmemiş'); ?>" readonly>
                    </div>
                </div>
            </div>

            <!-- Insured Persons Selection -->
            <div class="ab-form-section">
                <h3><i class="fas fa-users"></i> Sigortalı Seçimi</h3>
                <div class="family-selection">
                    <?php foreach ($family_members as $member): ?>
                        <div class="family-member">
                            <label class="ab-checkbox-label">
                                <input type="checkbox" name="selected_insured[]" 
                                       value="<?php echo esc_attr($member['name'] . ' (' . $member['relation'] . ')'); ?>"
                                       <?php echo in_array($member['name'] . ' (' . $member['relation'] . ')', $selected_insured) ? 'checked' : ''; ?>>
                                <span class="family-member-info">
                                    <strong><?php echo esc_html($member['name']); ?></strong>
                                    <small><?php echo esc_html($member['relation'] . ($member['tc'] ? ' - TC: ' . $member['tc'] : '')); ?></small>
                                </span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Policy Information -->
            <div class="ab-form-section">
                <h3><i class="fas fa-file-alt"></i> Poliçe Bilgileri</h3>
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="policy_number">Poliçe Numarası *</label>
                        <input type="text" name="policy_number" id="policy_number" class="ab-input" 
                               value="<?php echo esc_attr($policy->policy_number); ?>" required>
                    </div>
                    <div class="ab-form-group">
                        <label for="policy_type">Poliçe Türü *</label>
                        <select name="policy_type" id="policy_type" class="ab-input" required onchange="updateGrossPremiumField()">
                            <option value="">Seçiniz</option>
                            <option value="TSS" <?php selected($policy->policy_type, 'TSS'); ?>>TSS</option>
                            <option value="Kasko" <?php selected($policy->policy_type, 'Kasko'); ?>>Kasko</option>
                            <option value="Trafik" <?php selected($policy->policy_type, 'Trafik'); ?>>Trafik</option>
                            <option value="Konut" <?php selected($policy->policy_type, 'Konut'); ?>>Konut</option>
                            <option value="İşyeri" <?php selected($policy->policy_type, 'İşyeri'); ?>>İşyeri</option>
                            <option value="Seyahat" <?php selected($policy->policy_type, 'Seyahat'); ?>>Seyahat</option>
                            <option value="Diğer" <?php selected($policy->policy_type, 'Diğer'); ?>>Diğer</option>
                        </select>
                    </div>
                </div>
                
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="policy_category">Kategori</label>
                        <select name="policy_category" id="policy_category" class="ab-input">
                            <option value="Yeni İş" <?php selected($policy->policy_category, 'Yeni İş'); ?>>Yeni İş</option>
                            <option value="Yenileme" <?php selected($policy->policy_category, 'Yenileme'); ?>>Yenileme</option>
                        </select>
                    </div>
                    <div class="ab-form-group">
                        <label for="insurance_company">Sigorta Şirketi *</label>
                        <select name="insurance_company" id="insurance_company" class="ab-input" required>
                            <option value="">Seçiniz</option>
                            <option value="Anadolu Birlik" <?php selected($policy->insurance_company, 'Anadolu Birlik'); ?>>Anadolu Birlik</option>
                            <option value="Allianz" <?php selected($policy->insurance_company, 'Allianz'); ?>>Allianz</option>
                            <option value="Axa" <?php selected($policy->insurance_company, 'Axa'); ?>>Axa</option>
                            <option value="HDI" <?php selected($policy->insurance_company, 'HDI'); ?>>HDI</option>
                            <option value="Zurich" <?php selected($policy->insurance_company, 'Zurich'); ?>>Zurich</option>
                            <option value="Diğer" <?php selected($policy->insurance_company, 'Diğer'); ?>>Diğer</option>
                        </select>
                    </div>
                </div>
                
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="start_date">Başlangıç Tarihi *</label>
                        <input type="date" name="start_date" id="start_date" class="ab-input" 
                               value="<?php echo esc_attr($policy->start_date); ?>" required>
                    </div>
                    <div class="ab-form-group">
                        <label for="end_date">Bitiş Tarihi *</label>
                        <input type="date" name="end_date" id="end_date" class="ab-input" 
                               value="<?php echo esc_attr($policy->end_date); ?>" required>
                    </div>
                </div>
                
                <!-- Plate number for Kasko/Trafik -->
                <div class="ab-form-row" id="plate_number_group" style="<?php echo in_array(strtolower($policy->policy_type), ['kasko', 'trafik']) ? 'display: block;' : 'display: none;'; ?>">
                    <div class="ab-form-group">
                        <label for="plate_number">Plaka Numarası</label>
                        <input type="text" name="plate_number" id="plate_number" class="ab-input" 
                               value="<?php echo esc_attr($policy->plate_number ?? ''); ?>" placeholder="Örn: 34 ABC 123">
                    </div>
                </div>
                
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="status">Durum</label>
                        <select name="status" id="status" class="ab-input">
                            <option value="aktif" <?php selected($policy->status, 'aktif'); ?>>Aktif</option>
                            <option value="pasif" <?php selected($policy->status, 'pasif'); ?>>Pasif</option>
                            <option value="iptal" <?php selected($policy->status, 'iptal'); ?>>İptal</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Payment Information -->
            <div class="ab-form-section">
                <h3><i class="fas fa-money-bill-wave"></i> Ödeme Bilgileri</h3>
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="premium_amount">Prim Tutarı (₺) *</label>
                        <input type="number" name="premium_amount" id="premium_amount" class="ab-input" 
                               step="0.01" min="0" value="<?php echo esc_attr($policy->premium_amount); ?>" required>
                    </div>
                    <div class="ab-form-group" id="gross_premium_group" style="<?php echo in_array(strtolower($policy->policy_type), ['kasko', 'trafik']) ? 'display: block;' : 'display: none;'; ?>">
                        <label for="gross_premium">Brüt Prim Tutarı (₺)</label>
                        <input type="number" name="gross_premium" id="gross_premium" class="ab-input" 
                               step="0.01" min="0" value="<?php echo esc_attr($policy->gross_premium ?? ''); ?>">
                    </div>
                </div>
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="payment_info">Ödeme Bilgisi</label>
                        <input type="text" name="payment_info" id="payment_info" class="ab-input" 
                               value="<?php echo esc_attr($policy->payment_info ?? ''); ?>" placeholder="Ödeme şekli, taksit sayısı vb.">
                    </div>
                </div>
            </div>

            <!-- Document Upload -->
            <div class="ab-form-section">
                <h3><i class="fas fa-paperclip"></i> Döküman</h3>
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="document">Poliçe Dokumanı</label>
                        <input type="file" name="document" id="document" class="ab-input" 
                               accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx">
                        <small class="ab-form-help">İzin verilen dosya türleri: JPG, PNG, PDF, DOC, XLS</small>
                        <?php if (!empty($policy->document_path)): ?>
                            <p><strong>Mevcut dosya:</strong> <a href="<?php echo esc_url($policy->document_path); ?>" target="_blank">Dosyayı Görüntüle</a></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ab-form-actions">
                <button type="submit" name="save_policy" class="ab-btn ab-btn-primary">
                    <i class="fas fa-save"></i> <?php echo $editing ? 'Güncelle' : 'Yenile'; ?>
                </button>
                <a href="?view=policies&action=view&id=<?php echo $policy_id; ?>" class="ab-btn ab-btn-secondary">
                    <i class="fas fa-times"></i> İptal
                </a>
            </div>
        </form>
    </div>
</div>

<script>
function updateGrossPremiumField() {
    const policyTypeSelect = document.getElementById('policy_type');
    const grossPremiumGroup = document.getElementById('gross_premium_group');
    const plateNumberGroup = document.getElementById('plate_number_group');
    
    if (policyTypeSelect && grossPremiumGroup && plateNumberGroup) {
        const policyType = policyTypeSelect.value.toLowerCase();
        const showFields = policyType === 'kasko' || policyType === 'trafik';
        
        grossPremiumGroup.style.display = showFields ? 'block' : 'none';
        plateNumberGroup.style.display = showFields ? 'block' : 'none';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateGrossPremiumField();
});
</script>

<style>
.family-selection {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 15px;
    margin-top: 10px;
}

.family-member {
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 15px;
    background: #f9f9f9;
}

.family-member:hover {
    background: #f0f0f0;
}

.ab-checkbox-label {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    cursor: pointer;
}

.family-member-info {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.family-member-info strong {
    font-size: 14px;
    color: #333;
}

.family-member-info small {
    color: #666;
    font-size: 12px;
}

.ab-form-section {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    margin-bottom: 20px;
    padding: 20px;
}

.ab-form-section h3 {
    margin: 0 0 20px 0;
    color: #333;
    border-bottom: 2px solid #007cba;
    padding-bottom: 10px;
}

.ab-form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 15px;
}

.ab-form-group {
    display: flex;
    flex-direction: column;
}

.ab-form-group label {
    font-weight: 600;
    margin-bottom: 5px;
    color: #555;
}

.ab-input {
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.ab-input:focus {
    border-color: #007cba;
    outline: none;
    box-shadow: 0 0 0 1px #007cba;
}

.ab-form-actions {
    text-align: center;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #ddd;
}

.ab-btn {
    display: inline-block;
    padding: 12px 24px;
    margin: 0 10px;
    border: none;
    border-radius: 4px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}

.ab-btn-primary {
    background: #007cba;
    color: #fff;
}

.ab-btn-primary:hover {
    background: #005a87;
}

.ab-btn-secondary {
    background: #666;
    color: #fff;
}

.ab-btn-secondary:hover {
    background: #444;
}

.ab-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid #ddd;
}

.ab-header-content h1 {
    margin: 0;
    color: #333;
}

.ab-header-content p {
    margin: 5px 0 0 0;
    color: #666;
}

.ab-form-help {
    color: #666;
    font-size: 12px;
    margin-top: 5px;
}
</style>
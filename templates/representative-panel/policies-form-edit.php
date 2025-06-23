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

// Get insurance companies from settings (like policies-form.php)
$settings = get_option('insurance_crm_settings', []);
$insurance_companies = array_unique($settings['insurance_companies'] ?? ['Sompo']);
sort($insurance_companies);

// Payment options from settings
$payment_options = $settings['payment_options'] ?? ['Peşin', '3 Taksit', '6 Taksit', '8 Taksit', '9 Taksit', '12 Taksit', 'Ödenmedi', 'Nakit', 'Kredi Kartı', 'Havale', 'Diğer'];

// Cancellation reasons
$cancellation_reasons = ['Araç Satışı', 'İsteğe Bağlı', 'Tahsilattan İptal', 'Diğer Sebepler'];

// Ensure gross_premium column exists
$gross_premium_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'gross_premium'");
if (!$gross_premium_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN gross_premium DECIMAL(10,2) DEFAULT NULL AFTER premium_amount");
}

// Determine action and policy ID
$editing = isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']) && intval($_GET['id']) > 0;
$renewing = isset($_GET['action']) && $_GET['action'] === 'renew' && isset($_GET['id']) && intval($_GET['id']) > 0;
$cancelling = isset($_GET['action']) && $_GET['action'] === 'cancel' && isset($_GET['id']) && intval($_GET['id']) > 0;
$policy_id = $editing || $renewing || $cancelling ? intval($_GET['id']) : 0;

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
    
    // Handle cancellation
    if ($cancelling) {
        $policy_data['status'] = 'iptal';
        $policy_data['cancellation_reason'] = isset($_POST['cancellation_reason']) ? sanitize_text_field($_POST['cancellation_reason']) : '';
        $policy_data['cancellation_date'] = isset($_POST['cancellation_date']) ? sanitize_text_field($_POST['cancellation_date']) : current_time('mysql');
        $policy_data['refund_amount'] = isset($_POST['refund_amount']) && !empty($_POST['refund_amount']) ? floatval($_POST['refund_amount']) : null;
    }
    
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
    // Generate next policy number
    $base_policy_number = preg_replace('/\/\d+\/?\d*$/', '', $policy->policy_number); // Remove existing renewal suffix
    
    // Find the highest renewal number for this base policy number
    $existing_renewals = $wpdb->get_col($wpdb->prepare("
        SELECT policy_number FROM $policies_table 
        WHERE policy_number LIKE %s
        ORDER BY policy_number DESC
    ", $base_policy_number . '/%'));
    
    $next_renewal_number = '02'; // Default start
    if (!empty($existing_renewals)) {
        foreach ($existing_renewals as $renewal_number) {
            if (preg_match('/\/(\d+)\/?\d*$/', $renewal_number, $matches)) {
                $current_renewal = intval($matches[1]);
                if ($current_renewal >= intval($next_renewal_number)) {
                    $next_renewal_number = str_pad($current_renewal + 1, 2, '0', STR_PAD_LEFT);
                }
            }
        }
    }
    
    $policy->policy_number = $base_policy_number . '/' . $next_renewal_number . '/00';
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
                <h1><i class="fas fa-<?php echo $cancelling ? 'ban' : 'edit'; ?>"></i> <?php echo $cancelling ? 'Poliçe İptal Et' : ($editing ? 'Poliçe Düzenle' : 'Poliçe Yenile'); ?></h1>
                <p><?php echo $cancelling ? 'Poliçeyi iptal edin' : 'Poliçe bilgilerini düzenleyin veya yenileyin'; ?></p>
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
                        <label for="insurance_company">Sigorta Şirketi <span class="required">*</span></label>
                        <select name="insurance_company" id="insurance_company" class="ab-select" required>
                            <option value="">Seçiniz...</option>
                            <?php foreach ($insurance_companies as $company): ?>
                            <option value="<?php echo esc_attr($company); ?>" <?php selected($policy->insurance_company, $company); ?>>
                                <?php echo esc_html($company); ?>
                            </option>
                            <?php endforeach; ?>
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
                        <select name="status" id="status" class="ab-input" <?php echo $cancelling ? 'readonly' : ''; ?>>
                            <option value="aktif" <?php selected($cancelling ? 'iptal' : $policy->status, 'aktif'); ?>>Aktif</option>
                            <option value="pasif" <?php selected($cancelling ? 'iptal' : $policy->status, 'pasif'); ?>>Pasif</option>
                            <option value="iptal" <?php selected($cancelling ? 'iptal' : $policy->status, 'iptal'); ?>>İptal</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Cancellation Details - Only show when cancelling -->
            <?php if ($cancelling): ?>
            <div class="ab-form-section ab-form-section-warning">
                <h3><i class="fas fa-exclamation-triangle"></i> İptal Bilgileri</h3>
                <div class="ab-form-warning">
                    <div class="ab-warning-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="ab-warning-content">
                        <strong>Dikkat:</strong> İptal işlemi geri alınamaz. İptal edilen poliçeler sistemde kalacak ancak Zeyil olarak işaretlenecektir.
                    </div>
                </div>
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="cancellation_date">İptal Tarihi *</label>
                        <input type="date" name="cancellation_date" id="cancellation_date" class="ab-input" 
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="ab-form-group">
                        <label for="cancellation_reason">İptal Nedeni *</label>
                        <select name="cancellation_reason" id="cancellation_reason" class="ab-input" required>
                            <option value="">Seçiniz...</option>
                            <option value="Araç Satışı">Araç Satışı</option>
                            <option value="İsteğe Bağlı">İsteğe Bağlı</option>
                            <option value="Tahsilattan İptal">Tahsilattan İptal</option>
                            <option value="Diğer Nedenler">Diğer Nedenler</option>
                        </select>
                    </div>
                </div>
                <div class="ab-form-row">
                    <div class="ab-form-group">
                        <label for="refund_amount">İade Tutarı (₺)</label>
                        <input type="number" name="refund_amount" id="refund_amount" class="ab-input" 
                               step="0.01" min="0" placeholder="Varsa iade tutarını giriniz">
                        <small class="ab-form-help">İade edilecek tutar varsa giriniz. Boş bırakılabilir.</small>
                    </div>
                </div>
            </div>
            <?php endif; ?>
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
                        <select name="payment_info" id="payment_info" class="ab-input">
                            <option value="">Seçiniz...</option>
                            <?php foreach ($payment_options as $option): ?>
                            <option value="<?php echo esc_attr($option); ?>" <?php selected($policy->payment_info ?? '', $option); ?>><?php echo esc_html($option); ?></option>
                            <?php endforeach; ?>
                        </select>
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
                <?php if ($cancelling): ?>
                <button type="submit" name="save_policy" class="ab-btn ab-btn-danger" onclick="return confirm('Poliçeyi iptal etmek istediğinizden emin misiniz?');">
                    <i class="fas fa-ban"></i> Poliçeyi İptal Et
                </button>
                <?php else: ?>
                <button type="submit" name="save_policy" class="ab-btn ab-btn-primary">
                    <i class="fas fa-save"></i> <?php echo $editing ? 'Güncelle' : 'Yenile'; ?>
                </button>
                <?php endif; ?>
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

// Auto-update end date when start date changes
function updateEndDate() {
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    
    if (startDateInput && endDateInput && startDateInput.value) {
        const startDate = new Date(startDateInput.value);
        const endDate = new Date(startDate);
        endDate.setFullYear(endDate.getFullYear() + 1); // Add 1 year
        
        // Format date as YYYY-MM-DD
        const endDateString = endDate.toISOString().split('T')[0];
        endDateInput.value = endDateString;
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateGrossPremiumField();
    
    // Add event listener for start date changes
    const startDateInput = document.getElementById('start_date');
    if (startDateInput) {
        startDateInput.addEventListener('change', updateEndDate);
    }
});
</script>

<style>
/* Modern Material Design Form Styling - Matching policies-view.php */
:root {
    /* Primary Colors */
    --color-primary: #1976d2;
    --color-primary-dark: #0d47a1;
    --color-primary-light: #42a5f5;
    --color-secondary: #9c27b0;
    
    /* Status Colors */
    --color-success: #2e7d32;
    --color-success-light: #e8f5e9;
    --color-warning: #f57c00;
    --color-warning-light: #fff8e0;
    --color-danger: #d32f2f;
    --color-danger-light: #ffebee;
    --color-info: #0288d1;
    --color-info-light: #e1f5fe;
    
    /* Neutral Colors */
    --color-text-primary: #212121;
    --color-text-secondary: #757575;
    --color-background: #f5f5f5;
    --color-surface: #ffffff;
    --color-border: #e0e0e0;
    
    /* Typography */
    --font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    
    /* Shadows */
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
    --shadow-md: 0 3px 6px rgba(0,0,0,0.15), 0 2px 4px rgba(0,0,0,0.12);
    --shadow-lg: 0 10px 20px rgba(0,0,0,0.15), 0 3px 6px rgba(0,0,0,0.1);
    
    /* Border Radius */
    --radius-sm: 4px;
    --radius-md: 8px;
    --radius-lg: 12px;
    
    /* Spacing */
    --spacing-xs: 4px;
    --spacing-sm: 8px;
    --spacing-md: 16px;
    --spacing-lg: 24px;
    --spacing-xl: 32px;
}

/* Base container styling */
.ab-container {
    font-family: var(--font-family);
    color: var(--color-text-primary);
    background-color: var(--color-background);
    max-width: 1280px;
    margin: 0 auto;
    padding: var(--spacing-md);
}

/* Header styling */
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

/* Form sections */
.ab-form-section {
    background: var(--color-surface);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    margin-bottom: var(--spacing-lg);
    overflow: hidden;
}

.ab-form-section-warning {
    border-left: 4px solid var(--color-warning);
}

.ab-form-warning {
    background: var(--color-warning-light);
    border: 1px solid var(--color-warning);
    border-radius: var(--radius-md);
    padding: var(--spacing-md);
    margin: var(--spacing-md) var(--spacing-lg);
    display: flex;
    align-items: flex-start;
    gap: var(--spacing-md);
}

.ab-warning-icon {
    color: var(--color-warning);
    font-size: 20px;
    flex-shrink: 0;
    margin-top: 2px;
}

.ab-warning-content {
    color: var(--color-text-primary);
    font-size: 14px;
    line-height: 1.5;
}

.ab-warning-content strong {
    font-weight: 700;
    color: var(--color-warning);
}

.ab-form-section h3 {
    background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
    color: white;
    margin: 0;
    padding: var(--spacing-md) var(--spacing-lg);
    font-size: 18px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.ab-form-section h3 i {
    font-size: 20px;
}

/* Form rows and groups */
.ab-form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: var(--spacing-md);
    padding: var(--spacing-lg);
}

.ab-form-group {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
}

.ab-form-group.ab-full-width {
    grid-column: 1 / -1;
}

.ab-form-group label {
    font-weight: 600;
    color: var(--color-text-primary);
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

.ab-form-group label .required {
    color: var(--color-danger);
}

/* Input styling */
.ab-input, .ab-select {
    padding: 12px 16px;
    border: 2px solid var(--color-border);
    border-radius: var(--radius-md);
    font-size: 16px;
    font-family: var(--font-family);
    background: var(--color-surface);
    color: var(--color-text-primary);
    transition: all 0.3s ease;
}

.ab-input:focus, .ab-select:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
}

.ab-input[readonly] {
    background: #f8f9fa;
    color: var(--color-text-secondary);
    cursor: not-allowed;
}

/* Button styling */
.ab-btn {
    padding: 12px 24px;
    border: none;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 16px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: var(--spacing-sm);
    text-decoration: none;
    transition: all 0.3s ease;
    box-shadow: var(--shadow-sm);
}

.ab-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.ab-btn-primary {
    background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
    color: white;
}

.ab-btn-primary:hover {
    background: linear-gradient(135deg, var(--color-primary-dark), var(--color-primary));
}

.ab-btn-secondary {
    background: var(--color-surface);
    color: var(--color-text-primary);
    border: 2px solid var(--color-border);
}

.ab-btn-secondary:hover {
    background: var(--color-background);
    border-color: var(--color-primary);
}

.ab-btn-danger {
    background: #dc3545;
    color: #fff;
    border: 2px solid #dc3545;
}

.ab-btn-danger:hover {
    background: #c82333;
    border-color: #c82333;
}

/* Form actions */
.ab-form-actions {
    display: flex;
    gap: var(--spacing-md);
    justify-content: flex-end;
    padding: var(--spacing-lg);
    background: var(--color-background);
    border-top: 1px solid var(--color-border);
}

/* Family selection styling */
.family-selection {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: var(--spacing-md);
    padding: var(--spacing-lg);
}

.family-member {
    background: var(--color-surface);
    border: 2px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--spacing-md);
    transition: all 0.3s ease;
}

.family-member:hover {
    border-color: var(--color-primary);
    box-shadow: var(--shadow-sm);
}

.ab-checkbox-label {
    display: flex;
    align-items: flex-start;
    gap: var(--spacing-sm);
    cursor: pointer;
}

.ab-checkbox-label input[type="checkbox"] {
    width: 20px;
    height: 20px;
    margin: 0;
    cursor: pointer;
}

.family-member-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: var(--spacing-xs);
}

.family-member-info strong {
    font-size: 16px;
    font-weight: 600;
    color: var(--color-text-primary);
}

.family-member-info small {
    color: var(--color-text-secondary);
    font-size: 13px;
}

/* Responsive design */
@media (max-width: 768px) {
    .ab-header {
        flex-direction: column;
        gap: var(--spacing-md);
        text-align: center;
    }
    
    .ab-form-row {
        grid-template-columns: 1fr;
    }
    
    .ab-form-actions {
        flex-direction: column;
    }
}

/* Small form help text */
.ab-form-help {
    font-size: 12px;
    color: var(--color-text-secondary);
    margin-top: var(--spacing-xs);
}
</style>
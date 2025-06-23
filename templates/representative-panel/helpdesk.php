<?php
/**
 * Helpdesk Support System
 * @version 2.0.0
 * @updated 2025-01-06
 * @description Modern helpdesk form with policies-form.php design patterns
 */

include_once(dirname(__FILE__) . '/template-colors.php');

if (!is_user_logged_in()) {
    return;
}

// Include helpdesk functions
require_once(dirname(__FILE__) . '/../../includes/helpdesk-functions.php');
require_once(dirname(__FILE__) . '/../../includes/email-templates-helpdesk.php');

global $wpdb;

// Add get_current_user_rep_id function if it doesn't exist
if (!function_exists('get_current_user_rep_id')) {
    function get_current_user_rep_id() {
        global $wpdb;
        $current_user = wp_get_current_user();
        
        if (!$current_user->ID) {
            return 0;
        }
        
        $representative_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
            $current_user->ID
        ));
        
        return $representative_id ? intval($representative_id) : 0;
    }
}

// Get current user representative ID (automatic detection)
$current_user_rep_id = get_current_user_rep_id();

// Process form submission
$form_result = null;
if (isset($_POST['helpdesk_submit']) && isset($_POST['helpdesk_nonce']) && wp_verify_nonce($_POST['helpdesk_nonce'], 'helpdesk_form')) {
    $form_result = insurance_crm_process_helpdesk_form();
}

// Get current user info
$current_user = wp_get_current_user();

// Get representative data with error handling
$representative = null;
if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}insurance_crm_representatives'") == $wpdb->prefix . 'insurance_crm_representatives') {
    $representative = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $current_user->ID
    ));
}

// Get helpdesk data
$categories = insurance_crm_get_helpdesk_categories();
$priorities = insurance_crm_get_helpdesk_priorities();
$allowed_file_types = insurance_crm_get_helpdesk_allowed_file_types();
$allowed_mime_types = insurance_crm_get_helpdesk_allowed_mime_types();
?>

<style>
    .helpdesk-form-container {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        padding: 20px 30px;
        max-width: 1200px;
        margin: 20px auto;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    
    .helpdesk-form-header {
        margin-bottom: 30px;
        border-bottom: 1px solid #e0e0e0;
        padding-bottom: 15px;
    }
    
    .helpdesk-form-header h2 {
        font-size: 24px;
        color: #333;
        margin-bottom: 5px;
        font-weight: 600;
    }
    
    .helpdesk-form-header p {
        font-size: 14px;
        color: #666;
        margin: 0;
    }
    
    .helpdesk-info-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .helpdesk-info-card {
        background: #f9f9f9;
        border-radius: 6px;
        padding: 20px;
        border: 1px solid #eee;
    }
    
    .helpdesk-info-card h3 {
        margin-top: 0;
        font-size: 16px;
        color: #333;
        margin-bottom: 15px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .helpdesk-info-card ul {
        margin: 0;
        padding-left: 0;
        list-style: none;
    }
    
    .helpdesk-info-card li {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
        font-size: 14px;
        color: #555;
    }
    
    .helpdesk-form-content {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
    }
    
    .helpdesk-form-section {
        background: #f9f9f9;
        border-radius: 6px;
        padding: 20px;
        border: 1px solid #eee;
    }
    
    .helpdesk-form-section h3 {
        margin-top: 0;
        font-size: 18px;
        color: #333;
        margin-bottom: 15px;
        font-weight: 600;
        border-bottom: 1px solid #ddd;
        padding-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .form-row {
        margin-bottom: 15px;
    }
    
    .form-row label {
        display: block;
        margin-bottom: 5px;
        font-weight: 500;
        font-size: 14px;
        color: #444;
    }
    
    .form-input, .form-select, .form-textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
        font-family: inherit;
    }
    
    .form-textarea {
        min-height: 150px;
        resize: vertical;
    }
    
    .form-input:focus, .form-select:focus, .form-textarea:focus {
        border-color: #1976d2;
        outline: none;
        box-shadow: 0 0 0 2px rgba(25, 118, 210, 0.2);
    }
    
    .form-input[readonly] {
        background-color: #f5f5f5;
        color: #666;
    }
    
    .required {
        color: #f44336;
    }
    
    .form-actions {
        margin-top: 30px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    
    .btn {
        padding: 10px 20px;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        border: none;
        transition: all 0.2s ease;
        text-decoration: none;
        display: inline-block;
        text-align: center;
    }
    
    .btn-primary {
        background-color: #1976d2;
        color: white;
    }
    
    .btn-primary:hover:not(:disabled) {
        background-color: #1565c0;
    }
    
    .btn-primary:disabled {
        background-color: #ccc;
        cursor: not-allowed;
        opacity: 0.6;
    }
    
    .btn-secondary {
        background-color: #f5f5f5;
        color: #333;
        border: 1px solid #ddd;
    }
    
    .btn-secondary:hover {
        background-color: #e0e0e0;
    }
    
    .notification {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 4px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .notification.success {
        background-color: #e8f5e9;
        border-left: 4px solid #4caf50;
        color: #2e7d32;
    }
    
    .notification.error {
        background-color: #ffebee;
        border-left: 4px solid #f44336;
        color: #c62828;
    }
    
    .file-upload-wrapper {
        position: relative;
        border: 2px dashed #ddd;
        border-radius: 4px;
        padding: 20px;
        text-align: center;
        background-color: #fafafa;
        transition: all 0.2s ease;
    }
    
    .file-upload-wrapper:hover {
        border-color: #1976d2;
        background-color: #f8f9ff;
    }
    
    .file-upload-wrapper input[type=file] {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
    }
    
    .file-upload-label {
        font-size: 14px;
        color: #666;
    }
    
    .full-width-section {
        grid-column: 1 / -1;
        margin-top: 20px;
    }
    
    .character-counter {
        font-size: 12px;
        color: #666;
        text-align: right;
        margin-top: 5px;
    }
    
    .character-counter.warning {
        color: #f57c00;
    }
    
    .character-counter.danger {
        color: #f44336;
    }
    
    .priority-preview {
        margin-top: 8px;
        padding: 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 500;
    }
    
    .priority-low {
        background-color: #e8f5e9;
        color: #2e7d32;
    }
    
    .priority-medium {
        background-color: #fff3e0;
        color: #f57c00;
    }
    
    .priority-high {
        background-color: #ffebee;
        color: #c62828;
    }
    
    .priority-urgent {
        background-color: #fce4ec;
        color: #ad1457;
    }
    
    .checkbox-container {
        margin-top: 20px;
    }
    
    .checkbox-label {
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        font-size: 14px;
        color: #444;
        font-weight: 500;
    }
    
    .checkbox-label input[type="checkbox"] {
        margin: 0;
        width: 16px;
        height: 16px;
        cursor: pointer;
    }
    
    .checkbox-info {
        font-size: 12px;
        color: #666;
        margin-top: 5px;
        margin-left: 24px;
        line-height: 1.4;
    }
    
    .checkbox-info i {
        margin-right: 4px;
        color: #1976d2;
    }
    
    /* Responsive designs */
    @media (max-width: 768px) {
        .helpdesk-form-container {
            padding: 15px;
            margin: 10px;
        }
        
        .helpdesk-form-content {
            grid-template-columns: 1fr;
        }
        
        .helpdesk-info-cards {
            grid-template-columns: 1fr;
        }
        
        .form-actions {
            flex-direction: column;
        }
        
        .btn {
            width: 100%;
        }
    }
</style>

<?php if ($form_result): ?>
<div class="notification <?php echo $form_result['success'] ? 'success' : 'error'; ?>">
    <i class="fas fa-<?php echo $form_result['success'] ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <span><?php echo esc_html($form_result['message']); ?></span>
</div>
<?php endif; ?>

<div class="helpdesk-form-container" id="helpdesk-form">
    <div class="helpdesk-form-header">
        <h2><i class="fas fa-headset"></i> Destek Talebi</h2>
        <p>Sistem ile ilgili sorunlarınızı ve destek taleplerinizi buradan iletebilirsiniz.</p>
    </div>
    
    <!-- Information Cards -->
    <div class="helpdesk-info-cards">
        <div class="helpdesk-info-card">
            <h3><i class="fas fa-info-circle"></i> Nasıl Çalışır?</h3>
            <ul>
                <li><i class="fas fa-edit"></i> Formu doldurun ve sorunuzu detaylı açıklayın</li>
                <li><i class="fas fa-paperclip"></i> Gerekirse ekran görüntüsü veya dosya ekleyin</li>
                <li><i class="fas fa-envelope"></i> Talebiniz otomatik olarak teknik ekibe iletilir</li>
                <li><i class="fas fa-clock"></i> En kısa sürede geri dönüş alırsınız</li>
            </ul>
        </div>
        
        <div class="helpdesk-info-card">
            <h3><i class="fas fa-file-upload"></i> Dosya Yükleme</h3>
            <ul>
                <li><i class="fas fa-check"></i> Maksimum 5 dosya yükleyebilirsiniz</li>
                <li><i class="fas fa-check"></i> Her dosya en fazla 5MB olabilir</li>
                <li><i class="fas fa-check"></i> Desteklenen formatlar: JPG, PNG, PDF, DOC, TXT, ZIP</li>
                <li><i class="fas fa-check"></i> Ekran görüntüleri sorunu çözmeyi hızlandırır</li>
            </ul>
        </div>
    </div>
    
    <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('helpdesk_form', 'helpdesk_nonce'); ?>
        <input type="hidden" name="helpdesk_submit" value="1">
        
        <div class="helpdesk-form-content">
            
            <!-- Kullanıcı Bilgileri -->
            <div class="helpdesk-form-section">
                <h3><i class="fas fa-user"></i> Kullanıcı Bilgileri</h3>
                <div class="form-row">
                    <label>Adınız Soyadınız</label>
                    <input type="text" class="form-input" value="<?php echo esc_attr($current_user->display_name); ?>" readonly>
                </div>
                
                <div class="form-row">
                    <label>E-posta Adresiniz</label>
                    <input type="email" class="form-input" value="<?php echo esc_attr($current_user->user_email); ?>" readonly>
                </div>
                
                <input type="hidden" name="representative_id" value="<?php echo $current_user_rep_id; ?>">
            </div>
            
            <!-- Sorun Detayları -->
            <div class="helpdesk-form-section">
                <h3><i class="fas fa-exclamation-triangle"></i> Sorun Detayları</h3>
                <div class="form-row">
                    <label for="category">Sorun Kategorisi <span class="required">*</span></label>
                    <select id="category" name="category" class="form-select" required>
                        <option value="">Kategori seçiniz...</option>
                        <?php foreach ($categories as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-row">
                    <label for="priority">Öncelik Seviyesi <span class="required">*</span></label>
                    <select id="priority" name="priority" class="form-select" required>
                        <option value="">Öncelik seçiniz...</option>
                        <?php foreach ($priorities as $key => $priority): ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($priority['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="priority-preview" class="priority-preview" style="display: none;"></div>
                </div>
                
                <div class="form-row">
                    <label for="subject">Sorun Konusu <span class="required">*</span></label>
                    <input type="text" id="subject" name="subject" class="form-input" placeholder="Sorunun kısa özetini yazınız..." maxlength="200" required>
                    <div id="subject-counter" class="character-counter">0/200</div>
                </div>
            </div>
            
            <!-- Detaylı Açıklama -->
            <div class="helpdesk-form-section">
                <h3><i class="fas fa-edit"></i> Detaylı Açıklama</h3>
                <div class="form-row">
                    <label for="description">Detaylı Açıklama <span class="required">*</span></label>
                    <textarea id="description" name="description" class="form-textarea" rows="6" placeholder="Sorunu detaylı olarak açıklayınız. Ne yapmaya çalışıyordunuz, hangi hata mesajını aldınız, hangi adımları izlediniz..." required></textarea>
                    <div id="description-counter" class="character-counter">0/2000</div>
                </div>
            </div>
            
            <!-- Dosya Yükleme - Tam Genişlik -->
            <div class="helpdesk-form-section full-width-section">
                <h3><i class="fas fa-file-upload"></i> Dosya Yükleme</h3>
                <div class="form-row">
                    <label>Ekler (Opsiyonel)</label>
                    <div class="file-upload-wrapper">
                        <input type="file" id="helpdesk-files" name="helpdesk_files[]" multiple accept="<?php echo esc_attr(implode(',', $allowed_mime_types)); ?>">
                        <div class="file-upload-label">
                            <i class="fas fa-cloud-upload-alt"></i><br>
                            Dosya seçmek için tıklayın veya sürükleyip bırakın<br>
                            <small>Desteklenen formatlar: <?php echo esc_html(implode(', ', $allowed_file_types)); ?></small>
                        </div>
                    </div>
                    <div id="file-list" style="margin-top: 10px;"></div>
                </div>
                
                <div class="form-row checkbox-container">
                    <label class="checkbox-label" for="include_debug_log">
                        <input type="checkbox" id="include_debug_log" name="include_debug_log" value="1">
                        <span>Debug log dosyasını da ekle (sorun teşhisi için önerilir)</span>
                    </label>
                    <div class="checkbox-info">
                        <i class="fas fa-info-circle"></i> Sistem hatalarının tespiti için son 100 satır debug log ekte gönderilir.
                    </div>
                </div>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="button" class="btn btn-secondary" onclick="window.history.back();">
                İptal
            </button>
            
            <button type="submit" id="submit-btn" class="btn btn-primary" disabled>
                <i class="fas fa-paper-plane"></i> Destek Talebi Gönder
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Form validation
    function validateFormState() {
        const submitBtn = document.getElementById('submit-btn');
        let isValid = true;
        
        // Check required fields
        const requiredFields = ['category', 'priority', 'subject', 'description'];
        requiredFields.forEach(function(fieldId) {
            const field = document.getElementById(fieldId);
            if (!field.value.trim()) {
                isValid = false;
            }
        });
        
        submitBtn.disabled = !isValid;
    }
    
    // Character counters
    function setupCharacterCounter(inputId, counterId, maxLength) {
        const input = document.getElementById(inputId);
        const counter = document.getElementById(counterId);
        
        if (input && counter) {
            input.addEventListener('input', function() {
                const length = this.value.length;
                counter.textContent = length + '/' + maxLength;
                
                counter.className = 'character-counter';
                if (length > maxLength * 0.8) {
                    counter.classList.add('warning');
                }
                if (length > maxLength * 0.95) {
                    counter.classList.add('danger');
                }
                
                validateFormState();
            });
        }
    }
    
    // Setup character counters
    setupCharacterCounter('subject', 'subject-counter', 200);
    setupCharacterCounter('description', 'description-counter', 2000);
    
    // Priority preview
    const prioritySelect = document.getElementById('priority');
    const priorityPreview = document.getElementById('priority-preview');
    
    if (prioritySelect && priorityPreview) {
        prioritySelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (this.value) {
                const priorities = <?php echo json_encode($priorities); ?>;
                const priority = priorities[this.value];
                
                priorityPreview.style.display = 'block';
                priorityPreview.className = 'priority-preview priority-' + this.value;
                priorityPreview.textContent = priority.description;
            } else {
                priorityPreview.style.display = 'none';
            }
            validateFormState();
        });
    }
    
    // File upload handling
    const fileInput = document.getElementById('helpdesk-files');
    const fileList = document.getElementById('file-list');
    
    if (fileInput && fileList) {
        fileInput.addEventListener('change', function() {
            const files = Array.from(this.files);
            fileList.innerHTML = '';
            
            if (files.length > 0) {
                files.forEach(function(file, index) {
                    const fileItem = document.createElement('div');
                    fileItem.style.cssText = 'display: flex; align-items: center; gap: 8px; margin-bottom: 5px; font-size: 14px;';
                    fileItem.innerHTML = '<i class="fas fa-file"></i> ' + file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
                    fileList.appendChild(fileItem);
                });
            }
        });
    }
    
    // Add event listeners for validation
    const validationFields = ['category', 'priority', 'subject', 'description'];
    validationFields.forEach(function(fieldId) {
        const field = document.getElementById(fieldId);
        if (field) {
            field.addEventListener('change', validateFormState);
            field.addEventListener('input', validateFormState);
            field.addEventListener('blur', validateFormState);
        }
    });
    
    // Initial validation
    validateFormState();
    
    // Form submit handling
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submit-btn');
            if (submitBtn.disabled) {
                e.preventDefault();
                alert('Lütfen tüm zorunlu alanları doldurun.');
                return false;
            }
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gönderiliyor...';
        });
    }
});
</script>
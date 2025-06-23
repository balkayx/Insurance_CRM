<?php
/**
 * Veri Aktar - Enhanced CSV and Excel Import System
 * @version 3.0.0
 * @date 2025-01-11
 * Includes Yeni İş/Yenileme column mapping and improved preview design
 */

if (!is_user_logged_in()) {
    echo '<div class="ab-notice ab-error">Bu sayfayı görüntülemek için giriş yapmalısınız.</div>';
    return;
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load import libraries
$csv_importer_path = dirname(__FILE__) . '/import/csv-importer.php';
if (file_exists($csv_importer_path)) {
    require_once($csv_importer_path);
}

$excel_importer_path = dirname(dirname(dirname(__FILE__))) . '/includes/libraries/excel-importer.php';
if (file_exists($excel_importer_path)) {
    require_once($excel_importer_path);
}

$validators_path = dirname(dirname(dirname(__FILE__))) . '/includes/libraries/import-validators.php';
if (file_exists($validators_path)) {
    require_once($validators_path);
}

$mapping_path = dirname(dirname(dirname(__FILE__))) . '/includes/libraries/column-mapping.php';
if (file_exists($mapping_path)) {
    require_once($mapping_path);
}

global $wpdb;
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';

if (!function_exists('get_current_user_rep_id')) {
    function get_current_user_rep_id() {
        global $wpdb;
        $current_user_id = get_current_user_id();
        return $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'", $current_user_id));
    }
}

if (!function_exists('generate_unique_policy_number')) {
    /**
     * Generate a unique policy number by appending /NN/00 suffix if original number exists
     * @param string $original_policy_number Original policy number from CSV
     * @param string $policies_table Database table name for policies
     * @return array Array with 'policy_number' and 'was_renamed' keys
     */
    function generate_unique_policy_number($original_policy_number, $policies_table) {
        global $wpdb;
        
        // First check if original number is available
        $existing_policy = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $policies_table WHERE policy_number = %s",
            $original_policy_number
        ));
        
        if (!$existing_policy) {
            // Original number is available
            return [
                'policy_number' => $original_policy_number,
                'was_renamed' => false
            ];
        }
        
        // Original number exists, try with suffixes /02/00, /03/00, etc.
        for ($counter = 2; $counter <= 99; $counter++) {
            $new_policy_number = $original_policy_number . '/' . sprintf('%02d', $counter) . '/00';
            
            $existing_with_suffix = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $policies_table WHERE policy_number = %s",
                $new_policy_number
            ));
            
            if (!$existing_with_suffix) {
                // Found a unique number
                return [
                    'policy_number' => $new_policy_number,
                    'was_renamed' => true
                ];
            }
        }
        
        // If we reach here, all numbers from /02/00 to /99/00 are taken
        return [
            'policy_number' => null,
            'was_renamed' => false,
            'error' => 'Tüm poliçe numarası varyasyonları dolu (02-99)'
        ];
    }
}

if (!function_exists('clean_and_validate_import_row')) {
    function clean_and_validate_import_row($raw_data) {
        $cleaned_data = array();
        $issues = array();
        
        foreach ($raw_data as $key => $value) {
            // Clean the value
            $cleaned_value = trim($value);
            $cleaned_value = mb_convert_encoding($cleaned_value, 'UTF-8', ['UTF-8', 'ISO-8859-9', 'Windows-1254']);
            
            // Remove any null bytes
            $cleaned_value = str_replace("\0", '', $cleaned_value);
            
            // Basic validation based on field type
            switch ($key) {
                case 'tc_identity':
                    // Turkish ID validation (11 digits)
                    $cleaned_value = preg_replace('/[^0-9]/', '', $cleaned_value);
                    if (!empty($cleaned_value) && strlen($cleaned_value) !== 11) {
                        $issues[] = "TC Kimlik No geçersiz: $cleaned_value";
                    }
                    break;
                    
                case 'phone':
                case 'phone_number':
                    // Phone number cleaning
                    $cleaned_value = preg_replace('/[^0-9]/', '', $cleaned_value);
                    if (!empty($cleaned_value) && strlen($cleaned_value) < 10) {
                        $issues[] = "Telefon numarası geçersiz: $cleaned_value";
                    }
                    break;
                    
                case 'email':
                    // Email validation
                    if (!empty($cleaned_value) && !filter_var($cleaned_value, FILTER_VALIDATE_EMAIL)) {
                        $issues[] = "E-posta adresi geçersiz: $cleaned_value";
                    }
                    break;
                    
                case 'policy_date':
                case 'start_date':
                case 'end_date':
                    // Date format validation
                    if (!empty($cleaned_value)) {
                        $date = DateTime::createFromFormat('Y-m-d', $cleaned_value);
                        if (!$date) {
                            $date = DateTime::createFromFormat('d.m.Y', $cleaned_value);
                            if ($date) {
                                $cleaned_value = $date->format('Y-m-d');
                            } else {
                                $issues[] = "Tarih formatı geçersiz: $cleaned_value";
                            }
                        }
                    }
                    break;
                    
                case 'policy_amount':
                case 'amount':
                case 'premium':
                case 'premium_amount':
                    // Amount cleaning - Handle Turkish format (46.989,00 TL)
                    if (!empty($cleaned_value)) {
                        // Remove currency symbols and spaces
                        $cleaned_value = preg_replace('/[^0-9.,]/', '', $cleaned_value);
                        
                        // Handle Turkish number format: thousands separator (.) and decimal separator (,)
                        if (strpos($cleaned_value, ',') !== false && strpos($cleaned_value, '.') !== false) {
                            // Both comma and dot present - assume Turkish format (46.989,00)
                            $last_comma_pos = strrpos($cleaned_value, ',');
                            $last_dot_pos = strrpos($cleaned_value, '.');
                            
                            if ($last_comma_pos > $last_dot_pos) {
                                // Turkish format: 46.989,00 -> remove dots and replace comma with dot
                                $cleaned_value = str_replace('.', '', $cleaned_value);
                                $cleaned_value = str_replace(',', '.', $cleaned_value);
                            } else {
                                // English format: 46,989.00 -> remove commas
                                $cleaned_value = str_replace(',', '', $cleaned_value);
                            }
                        } elseif (strpos($cleaned_value, ',') !== false) {
                            // Only comma present - could be decimal separator
                            $comma_count = substr_count($cleaned_value, ',');
                            if ($comma_count == 1 && strlen(substr($cleaned_value, strrpos($cleaned_value, ',') + 1)) <= 2) {
                                // Looks like decimal separator: 46,00 -> 46.00
                                $cleaned_value = str_replace(',', '.', $cleaned_value);
                            } else {
                                // Multiple commas, treat as thousands separator
                                $cleaned_value = str_replace(',', '', $cleaned_value);
                            }
                        } elseif (strpos($cleaned_value, '.') !== false) {
                            // Only dot present - check if it's thousands separator or decimal
                            $dot_count = substr_count($cleaned_value, '.');
                            $last_dot_pos = strrpos($cleaned_value, '.');
                            $decimal_part = substr($cleaned_value, $last_dot_pos + 1);
                            
                            if ($dot_count == 1 && strlen($decimal_part) > 2) {
                                // More than 2 digits after dot, likely thousands separator: 1.000 -> 1000
                                $cleaned_value = str_replace('.', '', $cleaned_value);
                            }
                            // Otherwise keep as decimal separator
                        }
                        
                        if (!is_numeric($cleaned_value)) {
                            $issues[] = "Tutar formatı geçersiz: $cleaned_value";
                        }
                    }
                    break;
            }
            
            $cleaned_data[$key] = $cleaned_value;
        }
        
        return array(
            'cleaned_data' => $cleaned_data,
            'issues' => $issues
        );
    }
}

$current_user_rep_id = get_current_user_rep_id();
$notice = '';
$csv_data = null;
$import_step = isset($_GET['step']) ? (int)$_GET['step'] : 1;

// Get all representatives for dropdown
$representatives = $wpdb->get_results("SELECT r.id, u.display_name, r.title FROM {$representatives_table} r JOIN {$wpdb->users} u ON r.user_id = u.ID WHERE r.status = 'active' ORDER BY u.display_name");

function generate_column_select($field_name, $csv_data, $auto_selected = '') {
    $output = '<select name="mapping_' . esc_attr($field_name) . '" class="ab-select column-mapping-select">';
    $output .= '<option value="">-- Sütun Seçin --</option>';
    
    if ($csv_data && !empty($csv_data['headers'])) {
        foreach ($csv_data['headers'] as $index => $header) {
            $selected = ($auto_selected == $index) ? 'selected' : '';
            $output .= '<option value="' . esc_attr($index) . '" ' . $selected . '>' . esc_html($header) . '</option>';
        }
    }
    
    $output .= '</select>';
    return $output;
}

// Auto-detect column mappings with enhanced Turkish patterns
function get_auto_mapping_suggestions($headers) {
    $suggestions = [];
    $patterns = [
        'tc_identity' => ['tc', 'kimlik', 'tcno', 'tc_no', 'tc_kimlik', 'tckimlik', 'vergi', 'tc kimlik'],
        'full_name' => ['ad soyad', 'adsoyad', 'ad_soyad', 'full_name', 'fullname', 'tam_ad', 'isim_soyisim', 'müşteri adı', 'musteri adi'],
        'phone' => ['telefon', 'tel', 'phone', 'gsm', 'cep', 'telefon no', 'tel no', 'cep telefonu'],
        'email' => ['email', 'e-mail', 'mail', 'eposta', 'e_mail', 'e posta', 'elektronik posta'],
        'birth_date' => ['dogum', 'birth', 'doğum', 'tarih', 'dt', 'birth_date', 'doğum tarihi', 'dogum tarihi', 'dogum_tarihi'],
        'address' => ['adres', 'address', 'ev_adresi', 'ikametgah', 'ev adresi', 'ikamet'],
        'policy_number' => ['poliçe no', 'police no', 'poliçe', 'police', 'policy', 'policy_no', 'polise_no', 'poliçe numarası', 'police numarası'],
        'policy_type' => ['tur', 'tür', 'type', 'urun', 'ürün', 'sigorta türü', 'sigorta turu', 'sigorta_turu'],
        'premium_amount' => ['prim', 'tutar', 'amount', 'miktar', 'ucret', 'ücret', 'net prim', 'prim tutari', 'prim_tutari'],
        'start_date' => ['başlangıç tarihi', 'baslangic tarihi', 'baslangic_tarihi', 'başlangıç_tarihi', 'başlangıç', 'baslangic', 'start', 'başlama', 'tanzim tarihi', 'tanzim_tarihi'],
        'end_date' => ['bitiş tarihi', 'bitis tarihi', 'bitis_tarihi', 'bitiş', 'bitis', 'end', 'son', 'vade tarihi', 'vade_tarihi'],
        'is_new_business' => ['yenileme /yeni iş', 'yenileme / yeni iş', 'yenileme yeni iş', 'yenileme/yeni iş', 'yeni iş', 'yeni is', 'yenileme', 'renewal', 'new business', 'business_type'],
        'insurance_company' => ['sigorta', 'şirket', 'company', 'sirket', 'firma', 'kurum', 'sigorta şirketi', 'sigorta sirketi', 'şirket adı', 'sirket adi'],
        'occupation' => ['meslek', 'profession', 'occupation', 'iş', 'is', 'job', 'mesleği', 'meslek bilgisi'],
        'branch' => ['branş', 'brans', 'branch', 'alan', 'kategori', 'sector', 'sektör', 'sigorta branşı'],
        'network' => ['network', 'ağ', 'ag', 'kanal', 'channel', 'satış kanalı', 'satis kanali'],
        'insured_party' => ['sigorta ettiren', 'sigorta_ettiren', 'sigortali', 'sigortalı', 'insured', 'sigorta_ettiren_adi', 'sigortalı adı'],
        'payment_type' => ['ödeme tipi', 'ödeme_tipi', 'odeme_tipi', 'ödeme', 'odeme', 'payment', 'ödeme şekli', 'odeme sekli'],
        'status_note' => ['durum notu', 'durum_notu', 'status note', 'status_note', 'not', 'açıklama', 'aciklama', 'durum açıklama', 'durum aciklama', 'note', 'status comment']
    ];
    
    foreach ($headers as $index => $header) {
        $header_clean = mb_strtolower(trim($header), 'UTF-8');
        // Normalize Turkish characters for better matching
        $header_normalized = str_replace(
            ['ı', 'ğ', 'ü', 'ş', 'ö', 'ç', 'İ', 'Ğ', 'Ü', 'Ş', 'Ö', 'Ç'], 
            ['i', 'g', 'u', 's', 'o', 'c', 'i', 'g', 'u', 's', 'o', 'c'], 
            $header_clean
        );
        
        foreach ($patterns as $field => $keywords) {
            foreach ($keywords as $keyword) {
                $keyword_normalized = str_replace(
                    ['ı', 'ğ', 'ü', 'ş', 'ö', 'ç'], 
                    ['i', 'g', 'u', 's', 'o', 'c'], 
                    mb_strtolower($keyword, 'UTF-8')
                );
                
                // Check for exact match or contains match
                if ($header_normalized === $keyword_normalized || strpos($header_normalized, $keyword_normalized) !== false) {
                    if (!isset($suggestions[$field])) { // Only take first match to avoid conflicts
                        $suggestions[$field] = $index;
                        break 2; // Break both loops
                    }
                }
            }
        }
    }
    
    return $suggestions;
}

// Handle file upload and processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['upload_file']) && isset($_FILES['csv_file'])) {
        $uploaded_file = $_FILES['csv_file'];
        
        // Debug logging
        error_log('Veri Aktar - File upload başladı');
        error_log('Dosya adı: ' . $uploaded_file['name']);
        error_log('Dosya hatası: ' . $uploaded_file['error']);
        
        if ($uploaded_file['error'] === UPLOAD_ERR_OK) {
            $file_extension = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['csv', 'xlsx', 'xls'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                $upload_dir = wp_upload_dir();
                $filename = 'import_' . time() . '_' . $uploaded_file['name'];
                $file_path = $upload_dir['path'] . '/' . $filename;
                
                error_log('Dosya yolu: ' . $file_path);
                
                if (move_uploaded_file($uploaded_file['tmp_name'], $file_path)) {
                    $csv_data = null;
                    
                    try {
                        if ($file_extension === 'csv') {
                            $csv_data = read_csv_headers($file_path);
                        } else {
                            if (function_exists('read_excel_headers')) {
                                $csv_data = read_excel_headers($file_path);
                            } else {
                                $notice = '<div class="ab-notice ab-error">Excel dosyası işleme desteği yüklü değil.</div>';
                            }
                        }
                        
                        if ($csv_data && isset($csv_data['headers']) && !empty($csv_data['headers'])) {
                            $_SESSION['import_file_path'] = $file_path;
                            $_SESSION['import_file_type'] = $file_extension;
                            $_SESSION['csv_data'] = $csv_data;
                            
                            error_log('Session verisi kaydedildi, yönlendirme yapılıyor...');
                            
                            // Use current page URL with step parameter instead of home_url()
                            $redirect_url = add_query_arg(array('view' => 'veri_aktar', 'step' => '2'), $_SERVER['REQUEST_URI']);
                            $redirect_url = strtok($redirect_url, '?') . '?' . http_build_query(array('view' => 'veri_aktar', 'step' => '2'));
                            
                            // Use PHP header redirect instead of wp_redirect
                            header('Location: ' . $redirect_url);
                            exit;
                        } else {
                            $notice = '<div class="ab-notice ab-error">Dosya okunamadı veya başlık satırı bulunamadı.</div>';
                            error_log('CSV data boş veya başlık yok');
                        }
                    } catch (Exception $e) {
                        $notice = '<div class="ab-notice ab-error">Dosya işleme hatası: ' . esc_html($e->getMessage()) . '</div>';
                        error_log('Dosya işleme hatası: ' . $e->getMessage());
                    }
                } else {
                    $notice = '<div class="ab-notice ab-error">Dosya sunucuya yüklenemedi.</div>';
                    error_log('move_uploaded_file başarısız');
                }
            } else {
                $notice = '<div class="ab-notice ab-error">Sadece CSV ve Excel dosyaları (.csv, .xlsx, .xls) kabul edilir.</div>';
            }
        } else {
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => 'Dosya boyutu çok büyük (sunucu limiti).',
                UPLOAD_ERR_FORM_SIZE => 'Dosya boyutu çok büyük (form limiti).',
                UPLOAD_ERR_PARTIAL => 'Dosya kısmen yüklendi.',
                UPLOAD_ERR_NO_FILE => 'Dosya seçilmedi.',
                UPLOAD_ERR_NO_TMP_DIR => 'Geçici klasör bulunamadı.',
                UPLOAD_ERR_CANT_WRITE => 'Dosya yazılamadı.',
                UPLOAD_ERR_EXTENSION => 'Dosya uzantısı engellenmiş.'
            ];
            $error_msg = isset($error_messages[$uploaded_file['error']]) ? $error_messages[$uploaded_file['error']] : 'Bilinmeyen hata.';
            $notice = '<div class="ab-notice ab-error">Dosya yükleme hatası: ' . $error_msg . '</div>';
        }
    }
    
    // Handle column mapping and preview
    elseif (isset($_POST['confirm_mapping'])) {
        if (isset($_SESSION['csv_data'])) {
            $csv_data = $_SESSION['csv_data'];
            $file_path = $_SESSION['import_file_path'];
            $file_type = $_SESSION['import_file_type'];
            
            // Store mapping in session
            $_SESSION['column_mapping'] = [];
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'mapping_') === 0 && !empty($value)) {
                    $field = str_replace('mapping_', '', $key);
                    $_SESSION['column_mapping'][$field] = (int)$value;
                }
            }
            
            // Load full data for preview if not already loaded
            if (!isset($csv_data['data']) || empty($csv_data['data'])) {
                try {
                    if ($file_type === 'csv') {
                        $full_csv_data = read_csv_full_data($file_path);
                    } else {
                        if (function_exists('read_excel_full_data')) {
                            $full_csv_data = read_excel_full_data($file_path);
                        } else {
                            // Fallback: use headers from existing data and set empty data array
                            $full_csv_data = $csv_data;
                            $full_csv_data['data'] = $full_csv_data['sample_data'];
                        }
                    }
                    $_SESSION['csv_data'] = $full_csv_data;
                } catch (Exception $e) {
                    // If full data loading fails, use existing sample data as full data
                    $_SESSION['csv_data']['data'] = $_SESSION['csv_data']['sample_data'];
                }
            }
            
            // Redirect to preview step
            $redirect_url = strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query(array('view' => 'veri_aktar', 'step' => '3'));
            header('Location: ' . $redirect_url);
            exit;
        } else {
            $notice = '<div class="ab-notice ab-error">Oturum verisi bulunamadı. Lütfen dosyayı yeniden yükleyin.</div>';
            $redirect_url = strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query(array('view' => 'veri_aktar', 'step' => '1'));
            header('Location: ' . $redirect_url);
            exit;
        }
    }
    
    // Handle final import
    elseif (isset($_POST['final_import'])) {
        if (isset($_SESSION['csv_data']) && isset($_SESSION['column_mapping'])) {
            $file_path = $_SESSION['import_file_path'];
            $file_type = $_SESSION['import_file_type'];
            $column_mapping = $_SESSION['column_mapping'];
            
            // Get the raw data from session and process it
            $csv_data = $_SESSION['csv_data'];
            $raw_data = isset($csv_data['data']) ? $csv_data['data'] : [];
            $headers = isset($csv_data['headers']) ? $csv_data['headers'] : [];
            
            // Convert raw CSV data to customers and policies using column mapping
            $customers_data = [];
            $policies_data = [];
            $all_cleaning_issues = [];
            $all_cleaning_warnings = [];
            
            if (!empty($raw_data) && !empty($headers)) {
                foreach ($raw_data as $row_index => $row) {
                    $customer_key = 'customer_' . $row_index;
                    $raw_customer_data = [];
                    $raw_policy_data = [];
                    
                    // Map CSV data to customer and policy fields using column mapping
                    foreach ($column_mapping as $field => $csv_column_index) {
                        if ($csv_column_index !== '' && isset($row[$csv_column_index])) {
                            $value = trim($row[$csv_column_index]);
                            
                            // Map to customer fields
                            if (in_array($field, ['tc_identity', 'first_name', 'last_name', 'full_name', 'phone', 'email', 'birth_date', 'address', 'occupation', 'representative_id'])) {
                                if ($field == 'tc_identity') {
                                    $raw_customer_data['tc_identity'] = $value; // Use consistent field name
                                } elseif ($field == 'full_name') {
                                    $raw_customer_data['full_name'] = $value; // Use consistent field name
                                } else {
                                    $raw_customer_data[$field] = $value;
                                }
                            }
                            // Map to policy fields
                            elseif (in_array($field, ['policy_number', 'start_date', 'end_date', 'premium_amount', 'payment_type', 'status_note', 'insurance_company', 'policy_type', 'insured_party'])) {
                                if ($field == 'premium_amount') {
                                    $raw_policy_data['premium_amount'] = $value;
                                } elseif ($field == 'insurance_company') {
                                    $raw_policy_data['insurance_company'] = $value;
                                } elseif ($field == 'policy_type') {
                                    $raw_policy_data['policy_type'] = $value;
                                } elseif ($field == 'insured_party') {
                                    $raw_policy_data['insured_party'] = $value;
                                } elseif ($field == 'status_note') {
                                    $raw_policy_data['status_note'] = $value;
                                } else {
                                    $raw_policy_data[$field] = $value;
                                }
                            }
                            // Map additional fields (excluding customer_representative and created_by_user)
                            elseif (in_array($field, ['meslek', 'brans', 'network', 'yeni_is_yenileme', 'occupation', 'branch', 'is_new_business'])) {
                                // Normalize field names
                                if ($field == 'occupation') {
                                    $raw_customer_data['occupation'] = $value;  // Store in customer data, not policy
                                } elseif ($field == 'branch') {
                                    $raw_policy_data['branch'] = $value;
                                } elseif ($field == 'is_new_business') {
                                    $raw_policy_data['yeni_is_yenileme'] = $value;
                                } else {
                                    $raw_policy_data[$field] = $value;
                                }
                            }
                        }
                    }
                    
                    // Clean and validate customer data
                    $customer_cleaning_result = clean_and_validate_import_row($raw_customer_data);
                    $customer_data = $customer_cleaning_result['cleaned_data'];
                    
                    // Process full_name field: split into first_name and last_name if needed
                    if (!empty($customer_data['full_name']) && (empty($customer_data['first_name']) || empty($customer_data['last_name']))) {
                        $full_name = trim($customer_data['full_name']);
                        $name_parts = explode(' ', $full_name, 2); // Split into max 2 parts
                        
                        if (empty($customer_data['first_name']) && !empty($name_parts[0])) {
                            $customer_data['first_name'] = $name_parts[0];
                        }
                        
                        if (empty($customer_data['last_name']) && !empty($name_parts[1])) {
                            $customer_data['last_name'] = $name_parts[1];
                        }
                        
                        error_log("Row $row_index - Split full_name '$full_name' into first_name='{$customer_data['first_name']}', last_name='{$customer_data['last_name']}'");
                    }
                    
                    // Clean and validate policy data
                    $policy_cleaning_result = clean_and_validate_import_row($raw_policy_data);
                    $policy_data = $policy_cleaning_result['cleaned_data'];
                    $policy_data['customer_key'] = $customer_key;
                    
                    // Debug: Log the data after cleaning
                    error_log("Row $row_index - Raw customer data: " . print_r($raw_customer_data, true));
                    error_log("Row $row_index - Cleaned customer data: " . print_r($customer_data, true));
                    error_log("Row $row_index - Raw policy data: " . print_r($raw_policy_data, true));
                    error_log("Row $row_index - Cleaned policy data: " . print_r($policy_data, true));
                    
                    // Collect cleaning issues and warnings
                    if (!empty($customer_cleaning_result['issues'])) {
                        $all_cleaning_issues[] = "Satır " . ($row_index + 2) . " (Müşteri): " . implode(', ', $customer_cleaning_result['issues']);
                    }
                    if (!empty($policy_cleaning_result['issues'])) {
                        $all_cleaning_issues[] = "Satır " . ($row_index + 2) . " (Poliçe): " . implode(', ', $policy_cleaning_result['issues']);
                    }
                    if (!empty($customer_cleaning_result['warnings'])) {
                        $all_cleaning_warnings[] = "Satır " . ($row_index + 2) . ": " . implode(', ', $customer_cleaning_result['warnings']);
                    }
                    if (!empty($policy_cleaning_result['warnings'])) {
                        $all_cleaning_warnings[] = "Satır " . ($row_index + 2) . ": " . implode(', ', $policy_cleaning_result['warnings']);
                    }
                    
                    // Get current user's representative ID and display name for fallback
                    $current_user_rep_id = get_current_user_rep_id();
                    $current_user = wp_get_current_user();
                    
                    // Get assignment data from form
                    $customer_representative = null;
                    $first_recorder = null;
                    
                    // Check if individual assignments were provided for this row
                    if (isset($_POST['customer_representative'][$row_index])) {
                        $customer_representative = $_POST['customer_representative'][$row_index];
                    }
                    if (isset($_POST['first_recorder'][$row_index])) {
                        $first_recorder = $_POST['first_recorder'][$row_index];
                    }
                    
                    // Fall back to defaults if individual assignments not provided
                    if (empty($customer_representative) && isset($_POST['default_customer_representative'])) {
                        $customer_representative = $_POST['default_customer_representative'];
                    }
                    if (empty($first_recorder) && isset($_POST['default_first_recorder'])) {
                        $first_recorder = $_POST['default_first_recorder'];
                    }
                    
                    // Final fallback to current user if no assignments provided
                    if (empty($customer_representative)) {
                        $customer_representative = $current_user_rep_id;
                    }
                    if (empty($first_recorder)) {
                        $first_recorder = $current_user->display_name;
                    }
                    
                    // Only add customer if it has essential data - check for cleaned field names
                    $has_first_name = !empty($customer_data['first_name']);
                    $has_tc_identity = !empty($customer_data['tc_identity']);
                    $has_phone = !empty($customer_data['phone']);
                    
                    error_log("Row $row_index - Customer check: first_name='$has_first_name', tc_identity='$has_tc_identity', phone='$has_phone'");
                    
                    if ($has_first_name || $has_tc_identity || $has_phone) {
                        // Add policy start_date to customer data for registration date
                        if (!empty($policy_data['start_date'])) {
                            $customer_data['registration_date'] = $policy_data['start_date'];
                        }
                        
                        // Set representative_id and first_recorder from form data
                        $customer_data['representative_id'] = $customer_representative;
                        $customer_data['first_recorder'] = $first_recorder;
                        
                        // Add row index for individual assignment processing
                        $customer_data['row_index'] = $row_index;
                        $customers_data[$customer_key] = $customer_data;
                        error_log("Row $row_index - Customer added to customers_data array");
                    } else {
                        error_log("Row $row_index - Customer NOT added - missing essential data");
                    }
                    
                    // Only add policy if it has essential data
                    $has_policy_number = !empty($policy_data['policy_number']);
                    error_log("Row $row_index - Policy check: policy_number='$has_policy_number'");
                    
                    if ($has_policy_number) {
                        // Process business type from the yeni_is_yenileme field (initial classification)
                        if (isset($policy_data['yeni_is_yenileme'])) {
                            $business_value = strtolower(trim($policy_data['yeni_is_yenileme']));
                            if (in_array($business_value, ['yenileme', 'renewal', '0', 'renew'])) {
                                $policy_data['business_type'] = 'Yenileme';
                                $policy_data['policy_category'] = 'Yenileme';
                            } elseif (in_array($business_value, ['yeni', 'new', '1', 'yeni iş', 'new business'])) {
                                $policy_data['business_type'] = 'Yeni İş';
                                $policy_data['policy_category'] = 'Yeni İş';
                            } else {
                                $policy_data['business_type'] = 'Yeni İş'; // Default
                                $policy_data['policy_category'] = 'Yeni İş'; // Default
                            }
                        } else {
                            $policy_data['business_type'] = 'Yeni İş'; // Default if no business type field
                            $policy_data['policy_category'] = 'Yeni İş'; // Default if no business type field
                        }
                        
                        // Use the same assignment logic as customers
                        $policy_data['representative_id'] = $customer_representative;
                        $policy_data['first_recorder'] = $first_recorder;
                        
                        // Add row index for individual assignment processing
                        $policy_data['row_index'] = $row_index;
                        $policies_data[] = $policy_data;
                        error_log("Row $row_index - Policy added to policies_data array with business_type: " . $policy_data['business_type'] . ", policy_category: " . $policy_data['policy_category']);
                    } else {
                        error_log("Row $row_index - Policy NOT added - missing policy number");
                    }
                }
            }
            
            // Debug logging
            error_log('Processing CSV Data - Raw data rows: ' . count($raw_data));
            error_log('Processing CSV Data - Customers processed: ' . count($customers_data));
            error_log('Processing CSV Data - Policies processed: ' . count($policies_data));
            
            // Initialize counters and messages
            $detailed_success_info = [];
            $detailed_error_info = [];
            $detailed_warning_info = array_merge($all_cleaning_warnings); // Start with cleaning warnings
            $detailed_renumbered_policies_info = []; // Track renumbered policies
            $customer_success_names = [];
            $policy_success_names = [];
            
            $success_count = 0;
            $error_count = 0;
            $customer_success = 0;
            $error_messages = [];
            $debug_messages = [];
            $customer_ids = [];
            
            // Process customers first
            if (!empty($customers_data)) {
                foreach ($customers_data as $customer_key => $customer) {
                    $debug_messages[] = 'Processing customer: ' . $customer_key;
                    
                    // Check if customer already exists by TC or phone
                    $existing_customer = null;
                    if (!empty($customer['tc_identity'])) {
                        $existing_customer = $wpdb->get_row($wpdb->prepare(
                            "SELECT id FROM $customers_table WHERE tc_identity = %s",
                            $customer['tc_identity']
                        ));
                    }
                    
                    if (!$existing_customer && !empty($customer['phone'])) {
                        $existing_customer = $wpdb->get_row($wpdb->prepare(
                            "SELECT id FROM $customers_table WHERE phone = %s",
                            $customer['phone']
                        ));
                    }
                    
                    if (!$existing_customer) {
                        // Use policy end_date - 365 days for customer created_at
                        $created_at = current_time('mysql');
                        
                        // Find the policy end_date for this customer to calculate created_at
                        $customer_policies = array_filter($policies_data, function($policy) use ($customer_key) {
                            return $policy['customer_key'] === $customer_key;
                        });
                        
                        if (!empty($customer_policies)) {
                            $policy = reset($customer_policies);
                            if (isset($policy['end_date']) && !empty($policy['end_date'])) {
                                try {
                                    $end_date_value = trim($policy['end_date']);
                                    
                                    // Handle various Turkish date formats for end_date
                                    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $end_date_value, $matches)) {
                                        $end_date_obj = DateTime::createFromFormat('d.m.Y', $end_date_value);
                                    } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $end_date_value, $matches)) {
                                        $end_date_obj = DateTime::createFromFormat('d/m/Y', $end_date_value);
                                    } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $end_date_value)) {
                                        $end_date_obj = DateTime::createFromFormat('Y-m-d', $end_date_value);
                                    } else {
                                        $end_date_obj = new DateTime($end_date_value);
                                    }
                                    
                                    if ($end_date_obj) {
                                        // Calculate created_at as end_date - 365 days
                                        $start_date_obj = clone $end_date_obj;
                                        $start_date_obj->sub(new DateInterval('P365D'));
                                        $created_at = $start_date_obj->format('Y-m-d H:i:s');
                                        error_log('Müşteri kayıt tarihi hesaplandı (bitiş tarihi - 365 gün): ' . $end_date_value . ' -> ' . $created_at);
                                    }
                                } catch (Exception $e) {
                                    error_log('Müşteri tarih hesaplama hatası: ' . $e->getMessage());
                                }
                            }
                        }
                        
                        // Insert new customer
                        $customer_insert_data = [
                            'first_name' => isset($customer['first_name']) ? sanitize_text_field($customer['first_name']) : '',
                            'last_name' => isset($customer['last_name']) ? sanitize_text_field($customer['last_name']) : '',
                            'phone' => '',  // Will be set below with validation
                            'email' => isset($customer['email']) ? sanitize_email($customer['email']) : '',
                            'address' => isset($customer['address']) ? sanitize_textarea_field($customer['address']) : '',
                            'created_at' => $created_at,
                            'updated_at' => current_time('mysql'),
                        ];
                        
                        // **FIX**: Ensure phone is within field limits (varchar(20))
                        if (isset($customer['phone']) && !empty($customer['phone'])) {
                            $phone_clean = preg_replace('/[^0-9+]/', '', $customer['phone']); // Keep only numbers and +
                            if (strlen($phone_clean) > 20) {
                                $phone_clean = substr($phone_clean, 0, 20); // Truncate to 20 characters
                                error_log("Telefon kesildi (satır {$customer['row_index']}): {$customer['phone']} -> $phone_clean");
                            }
                            $customer_insert_data['phone'] = $phone_clean;
                        }
                        
                        // Store "İlk Kayıt Eden" information using current user's display name
                        // **FIX**: Store first recorder information only if column exists
                        $columns = $wpdb->get_col("DESCRIBE $customers_table", 0);
                        if (in_array('first_recorder', $columns)) {
                            if (!empty($customer['first_recorder'])) {
                                $customer_insert_data['first_recorder'] = sanitize_text_field($customer['first_recorder']);
                            }
                        }
                        
                        if (!empty($customer['tc_identity'])) {
                            // **FIX**: Ensure TC identity is within field limits (varchar(11))
                            $tc_clean = preg_replace('/[^0-9]/', '', $customer['tc_identity']); // Remove non-numeric
                            if (strlen($tc_clean) > 11) {
                                $tc_clean = substr($tc_clean, 0, 11); // Truncate to 11 characters
                                error_log("TC kimlik kesildi (satır {$customer['row_index']}): {$customer['tc_identity']} -> $tc_clean");
                            }
                            if (!empty($tc_clean)) {
                                $customer_insert_data['tc_identity'] = $tc_clean;
                            }
                        }
                        
                        // Add birth_date field (field exists in database)
                        if (!empty($customer['birth_date'])) {
                            $customer_insert_data['birth_date'] = $customer['birth_date'];
                        }
                        
                        // Add occupation field (field exists in database)
                        if (!empty($customer['occupation'])) {
                            $customer_insert_data['occupation'] = sanitize_text_field($customer['occupation']);
                        }
                        
                        // Add representative using current user's representative ID  
                        $representative_id = null;
                        if (!empty($customer['representative_id'])) {
                            $representative_id = $customer['representative_id'];
                        }
                        
                        if ($representative_id) {
                            $customer_insert_data['representative_id'] = $representative_id;
                        }
                        
                        // Debug the customer data before insert
                        error_log('Customer insert data fields: ' . implode(', ', array_keys($customer_insert_data)));
                        
                        // Generate format array for insert
                        $customer_format = array();
                        foreach ($customer_insert_data as $key => $value) {
                            switch ($key) {
                                case 'representative_id':
                                    $customer_format[] = '%d';
                                    break;
                                default:
                                    $customer_format[] = '%s';
                                    break;
                            }
                        }
                        
                        $result = $wpdb->insert($customers_table, $customer_insert_data, $customer_format);
                        
                        if ($result !== false) {
                            $customer_ids[$customer_key] = $wpdb->insert_id;
                            $customer_success++;
                            $customer_name = ($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '');
                            $customer_success_names[] = trim($customer_name);
                            $detailed_success_info[] = [
                                'type' => 'customer_new',
                                'name' => trim($customer_name),
                                'tc' => $customer['tc_identity'] ?? '',
                                'phone' => $customer['phone'] ?? '',
                                'id' => $wpdb->insert_id,
                                'row' => $customer['row_index'] ?? 'N/A'
                            ];
                            $debug_messages[] = 'New customer added - ID: ' . $wpdb->insert_id . ' Name: ' . trim($customer_name);
                        } else {
                            $customer_ids[$customer_key] = null;
                            $error_count++;
                            $customer_name = ($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '');
                            $error_reason = $wpdb->last_error ?: 'Bilinmeyen veritabanı hatası';
                            $error_messages[] = 'Müşteri eklenemedi: ' . trim($customer_name);
                            $detailed_error_info[] = [
                                'type' => 'customer_insert_failed',
                                'name' => trim($customer_name),
                                'tc' => $customer['tc_identity'] ?? '',
                                'phone' => $customer['phone'] ?? '',
                                'reason' => $error_reason,
                                'row' => $customer['row_index'] ?? 'N/A',
                                'data_fields' => array_keys($customer_insert_data),
                                'field_values' => $customer_insert_data // Include actual data being inserted
                            ];
                            $debug_messages[] = 'Customer insert error: ' . $error_reason . ' for ' . trim($customer_name);
                            // Log detailed error for debugging
                            error_log('Müşteri ekleme hatası - Ad: ' . trim($customer_name) . ', TC: ' . ($customer['tc_identity'] ?? '') . ', Telefon: ' . ($customer['phone'] ?? '') . ', Hata: ' . $error_reason . ', Veri: ' . print_r($customer_insert_data, true));
                        }
                    } else {
                        // Update existing customer if needed
                        $customer_ids[$customer_key] = $existing_customer->id;
                        $customer_update_data = ['updated_at' => current_time('mysql')];
                        
                        // Update representative if provided using current user's representative ID
                        if (!empty($customer['representative_id'])) {
                            $customer_update_data['representative_id'] = $customer['representative_id'];
                        }
                        
                        if (count($customer_update_data) > 1) { // More than just updated_at
                            // Generate format array for update
                            $update_format = array();
                            foreach ($customer_update_data as $key => $value) {
                                switch ($key) {
                                    case 'representative_id':
                                        $update_format[] = '%d';
                                        break;
                                    default:
                                        $update_format[] = '%s';
                                        break;
                                }
                            }
                            
                            $wpdb->update($customers_table, $customer_update_data, ['id' => $existing_customer->id], $update_format, ['%d']);
                        }
                        
                        $customer_name = ($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '');
                        $detailed_success_info[] = [
                            'type' => 'customer_updated',
                            'name' => trim($customer_name),
                            'tc' => $customer['tc_identity'] ?? '',
                            'phone' => $customer['phone'] ?? '',
                            'id' => $existing_customer->id,
                            'row' => $customer['row_index'] ?? 'N/A',
                            'updated_fields' => array_keys($customer_update_data)
                        ];
                        $debug_messages[] = 'Existing customer updated - ID: ' . $existing_customer->id . ' Name: ' . trim($customer_name);
                    }
                }
            }
            
            // Process policies
            if (!empty($policies_data)) {
                foreach ($policies_data as $policy_data) {
                    $debug_messages[] = 'Processing policy: ' . ($policy_data['policy_number'] ?? 'Unknown');
                    
                    $customer_key = isset($policy_data['customer_key']) ? $policy_data['customer_key'] : null;
                    $customer_id = ($customer_key && isset($customer_ids[$customer_key])) ? $customer_ids[$customer_key] : null;
                    
                    // Get customer name for reporting
                    $customer_name = 'N/A';
                    if ($customer_key && isset($customers_data[$customer_key])) {
                        $customer = $customers_data[$customer_key];
                        $customer_name = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
                        if (empty($customer_name)) {
                            $customer_name = 'N/A';
                        }
                    }
                    
                    if (!$customer_id) {
                        $error_count++;
                        $error_messages[] = 'Poliçe atlandı: Müşteri ID bulunamadı (Poliçe No: ' . ($policy_data['policy_number'] ?? 'Bilinmeyen') . ')';
                        continue;
                    }
                    
                    $policy_number = isset($policy_data['policy_number']) ? sanitize_text_field($policy_data['policy_number']) : '';
                    if (empty($policy_number)) {
                        $error_count++;
                        $error_messages[] = 'Poliçe atlandı: Poliçe numarası boş';
                        continue;
                    }
                    
                    // Generate unique policy number (handle conflicts)
                    $original_policy_number = $policy_number;
                    $unique_policy_result = generate_unique_policy_number($original_policy_number, $policies_table);
                    
                    if (isset($unique_policy_result['error'])) {
                        // All policy number variations are taken, skip this policy
                        $error_count++;
                        $error_messages[] = 'Poliçe atlandı: ' . $unique_policy_result['error'] . ' (Orijinal No: ' . $original_policy_number . ')';
                        $detailed_error_info[] = [
                            'type' => 'policy_number_exhausted',
                            'policy_number' => $original_policy_number,
                            'customer_name' => $customer_name,
                            'reason' => $unique_policy_result['error'],
                            'row' => $policy_data['row_index'] ?? 'N/A'
                        ];
                        continue;
                    }
                    
                    $policy_to_insert_number = $unique_policy_result['policy_number'];
                    $was_renamed = $unique_policy_result['was_renamed'];
                    
                    // Track renumbered policies for reporting
                    if ($was_renamed) {
                        $detailed_renumbered_policies_info[] = [
                            'row_index' => $policy_data['row_index'] ?? 'N/A',
                            'customer_name' => $customer_name,
                            'original_policy_number' => $original_policy_number,
                            'new_policy_number' => $policy_to_insert_number
                        ];
                        
                        // Override category to "Yenileme" for renumbered policies as per requirement
                        // This overrides any initial classification from CSV data
                        $policy_data['yeni_is_yenileme'] = 'Yenileme';
                        $policy_data['business_type'] = 'Yenileme';
                        $policy_data['policy_category'] = 'Yenileme';
                        error_log("Policy number renamed - overriding category to 'Yenileme' for policy: " . $policy_to_insert_number);
                    }
                    
                    // **FIX**: Use policy end_date - 365 days for policy start_date
                    $policy_start_date = current_time('Y-m-d'); // Default fallback
                    if (isset($policy_data['end_date']) && !empty($policy_data['end_date'])) {
                        // Calculate start_date as end_date - 365 days
                        try {
                            $end_date_value = trim($policy_data['end_date']);
                            
                            // Handle various Turkish date formats for end_date
                            if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $end_date_value, $matches)) {
                                $end_date_obj = DateTime::createFromFormat('d.m.Y', $end_date_value);
                            } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $end_date_value, $matches)) {
                                $end_date_obj = DateTime::createFromFormat('d/m/Y', $end_date_value);
                            } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $end_date_value)) {
                                $end_date_obj = DateTime::createFromFormat('Y-m-d', $end_date_value);
                            } else {
                                $end_date_obj = new DateTime($end_date_value);
                            }
                            
                            if ($end_date_obj) {
                                // Calculate start_date as end_date - 365 days
                                $start_date_obj = clone $end_date_obj;
                                $start_date_obj->sub(new DateInterval('P365D'));
                                $policy_start_date = $start_date_obj->format('Y-m-d');
                                error_log('Poliçe başlangıç tarihi hesaplandı (bitiş tarihi - 365 gün): ' . $end_date_value . ' -> ' . $policy_start_date);
                            }
                        } catch (Exception $e) {
                            error_log('Poliçe başlangıç tarih hesaplama hatası: ' . $e->getMessage());
                        }
                    }
                    
                    // **FIX**: Use CSV end date instead of current date for policy end_date  
                    $policy_end_date = date('Y-m-d', strtotime($policy_start_date . ' + 1 year')); // Default: 1 year from start
                    if (isset($policy_data['end_date']) && !empty($policy_data['end_date'])) {
                        try {
                            $date_value = trim($policy_data['end_date']);
                            
                            // Handle various Turkish date formats
                            if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $date_value, $matches)) {
                                $date_obj = DateTime::createFromFormat('d.m.Y', $date_value);
                            } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date_value, $matches)) {
                                $date_obj = DateTime::createFromFormat('d/m/Y', $date_value);
                            } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date_value)) {
                                $date_obj = DateTime::createFromFormat('Y-m-d', $date_value);
                            } else {
                                $date_obj = new DateTime($date_value);
                            }
                            
                            if ($date_obj && $date_obj->format('Y') >= 1900 && $date_obj->format('Y') <= date('Y') + 10) {
                                $policy_end_date = $date_obj->format('Y-m-d');
                                error_log('Poliçe bitiş tarihi dönüştürüldü: ' . $date_value . ' -> ' . $policy_end_date);
                            }
                        } catch (Exception $e) {
                            error_log('Poliçe bitiş tarih dönüştürme hatası: ' . $policy_data['end_date'] . ' - ' . $e->getMessage());
                        }
                    }
                    
                    $policy_insert_data = [
                        'policy_number' => $policy_to_insert_number, // Use the unique policy number
                        'customer_id' => $customer_id,
                        'policy_type' => isset($policy_data['policy_type']) ? sanitize_text_field($policy_data['policy_type']) : 'TSS',
                        'start_date' => $policy_start_date,
                        'end_date' => $policy_end_date,
                        'premium_amount' => isset($policy_data['premium_amount']) ? floatval($policy_data['premium_amount']) : 0,
                        'updated_at' => current_time('mysql'),
                    ];
                    
                    // **FIX**: Handle status field properly with length limits (varchar(20))
                    $status_value = 'aktif'; // Default
                    if (isset($policy_data['status']) && !empty($policy_data['status'])) {
                        $status_clean = sanitize_text_field(trim($policy_data['status']));
                        // Limit to valid status values and field length
                        if (in_array(strtolower($status_clean), ['aktif', 'pasif', 'iptal', 'süresi dolmuş']) && strlen($status_clean) <= 20) {
                            $status_value = $status_clean;
                        } elseif (strlen($status_clean) > 20) {
                            // Truncate long status to prevent database error
                            $status_value = substr($status_clean, 0, 20);
                            error_log("Policy status truncated: '$status_clean' -> '$status_value'");
                        }
                    }
                    $policy_insert_data['status'] = $status_value;
                    
                    // **FIX**: Store policy fields in proper columns instead of document_path
                    if (!empty($policy_data['insurance_company'])) {
                        $policy_insert_data['insurance_company'] = sanitize_text_field($policy_data['insurance_company']);
                    }
                    if (!empty($policy_data['network'])) {
                        $policy_insert_data['network'] = sanitize_text_field($policy_data['network']);
                    }
                    if (!empty($policy_data['payment_type'])) {
                        $policy_insert_data['payment_type'] = sanitize_text_field($policy_data['payment_type']);
                    }
                    if (!empty($policy_data['brans'])) {
                        $policy_insert_data['policy_branch'] = sanitize_text_field($policy_data['brans']);
                    }
                    if (!empty($policy_data['meslek'])) {
                        $policy_insert_data['profession'] = sanitize_text_field($policy_data['meslek']);
                    }
                    if (!empty($policy_data['yeni_is_yenileme'])) {
                        $policy_insert_data['new_business_renewal'] = sanitize_text_field($policy_data['yeni_is_yenileme']);
                    }
                    if (!empty($policy_data['status_note'])) {
                        $policy_insert_data['status_note'] = sanitize_text_field($policy_data['status_note']);
                    }
                    
                    // **NEW**: Handle policy_category field as per requirement
                    if (!empty($policy_data['policy_category'])) {
                        $policy_insert_data['policy_category'] = sanitize_text_field($policy_data['policy_category']);
                    }
                    
                    // Store additional CSV data in document_path field only if needed for backward compatibility
                    $additional_data = [];
                    // Only store data that doesn't have proper columns
                    if (!empty($additional_data)) {
                        $policy_insert_data['document_path'] = implode('|', $additional_data);
                    }
                    
                    // Add representative using current user's representative ID
                    $policy_representative_id = null;
                    if (!empty($policy_data['representative_id'])) {
                        $policy_representative_id = $policy_data['representative_id'];
                    }
                    
                    if ($policy_representative_id) {
                        $policy_insert_data['representative_id'] = $policy_representative_id;
                    }
                    
                    // Store additional fields in notes when notes field is available
                    // Currently commented out as notes field doesn't exist in policies table
                    /*
                    $notes = [];
                    if (!empty($policy_data['network'])) {
                        $notes[] = 'Network: ' . $policy_data['network'];
                    }
                    if (!empty($policy_data['meslek'])) {
                        $notes[] = 'Meslek: ' . $policy_data['meslek'];
                    }
                    if (!empty($policy_data['brans'])) {
                        $notes[] = 'Branş: ' . $policy_data['brans'];
                    }
                    if (!empty($policy_data['yeni_is_yenileme'])) {
                        $notes[] = 'Tür: ' . $policy_data['yeni_is_yenileme'];
                    }
                    if (!empty($notes)) {
                        $policy_insert_data['notes'] = implode(' | ', $notes);
                    }
                    */
                    
                    // Always insert new policy (never update existing ones)
                    $policy_insert_data['created_at'] = current_time('mysql');
                    
                    // Generate format array for policy insert
                    $policy_format = array();
                    foreach ($policy_insert_data as $key => $value) {
                        switch ($key) {
                            case 'customer_id':
                            case 'representative_id':
                                $policy_format[] = '%d';
                                break;
                            case 'premium_amount':
                                $policy_format[] = '%f';
                                break;
                            default:
                                $policy_format[] = '%s';
                                break;
                        }
                    }
                    
                    $result = $wpdb->insert($policies_table, $policy_insert_data, $policy_format);
                    if ($result !== false) {
                        $success_count++;
                        $policy_success_names[] = $policy_to_insert_number;
                        $detailed_success_info[] = [
                            'type' => 'policy_new',
                            'policy_number' => $policy_to_insert_number,
                            'customer_name' => $customer_name,
                            'id' => $wpdb->insert_id,
                            'customer_id' => $policy_insert_data['customer_id'] ?? 'N/A',
                            'was_renamed' => $was_renamed
                        ];
                        $debug_messages[] = 'New policy added - ID: ' . $wpdb->insert_id . ' Number: ' . $policy_to_insert_number . ($was_renamed ? ' (renamed from ' . $original_policy_number . ')' : '');
                    } else {
                        $error_count++;
                        $error_reason = $wpdb->last_error ?: 'Bilinmeyen veritabanı hatası';
                        $error_messages[] = 'Poliçe eklenemedi: ' . $policy_to_insert_number;
                        $detailed_error_info[] = [
                            'type' => 'policy_insert_failed',
                            'policy_number' => $policy_to_insert_number,
                            'customer_name' => $customer_name,
                            'reason' => $error_reason,
                            'customer_id' => $policy_insert_data['customer_id'] ?? 'N/A',
                            'data_fields' => array_keys($policy_insert_data)
                        ];
                        $debug_messages[] = 'Policy insert error: ' . $error_reason . ' for policy: ' . $policy_to_insert_number;
                    }
                }
            }
            
            // Generate success/error notice
            $notice = '';
            
            // Show data quality issues and warnings first
            if (!empty($all_cleaning_issues)) {
                $notice .= '<div class="ab-notice ab-error">';
                $notice .= '<strong>Veri Kalitesi Sorunları:</strong><ul>';
                foreach (array_slice($all_cleaning_issues, 0, 10) as $issue) {
                    $notice .= '<li>' . esc_html($issue) . '</li>';
                }
                if (count($all_cleaning_issues) > 10) {
                    $notice .= '<li>Ve ' . (count($all_cleaning_issues) - 10) . ' diğer sorun...</li>';
                }
                $notice .= '</ul></div>';
            }
            
            if (!empty($all_cleaning_warnings)) {
                $notice .= '<div class="ab-notice ab-warning" style="background-color: #fff3cd; border-color: #ffeaa7; color: #856404;">';
                $notice .= '<strong>Veri Uyarıları:</strong><ul>';
                foreach (array_slice($all_cleaning_warnings, 0, 5) as $warning) {
                    $notice .= '<li>' . esc_html($warning) . '</li>';
                }
                if (count($all_cleaning_warnings) > 5) {
                    $notice .= '<li>Ve ' . (count($all_cleaning_warnings) - 5) . ' diğer uyarı...</li>';
                }
                $notice .= '</ul></div>';
            }
            
            // Create comprehensive import report
            $notice = '';
            
            // Summary section
            $total_processed = $success_count + $customer_success + $error_count;
            $notice .= '<div class="ab-notice ab-info">';
            $notice .= '<h3>Veri Aktarım Raporu</h3>';
            $notice .= '<p><strong>Toplam İşlem:</strong> ' . $total_processed . '</p>';
            $notice .= '<p><strong>Başarılı:</strong> ' . ($success_count + $customer_success) . ' - <strong>Başarısız:</strong> ' . $error_count . '</p>';
            $notice .= '</div>';
            
            // Success details
            if ($success_count > 0 || $customer_success > 0) {
                $notice .= '<div class="ab-notice ab-success">';
                $notice .= '<h4>✅ Başarılı Aktarımlar (' . ($success_count + $customer_success) . ')</h4>';
                
                // Customer successes
                if (!empty($customer_success_names)) {
                    $notice .= '<p><strong>Yeni Müşteriler (' . count($customer_success_names) . '):</strong></p>';
                    $notice .= '<ul>';
                    foreach (array_slice($customer_success_names, 0, 10) as $name) {
                        $notice .= '<li>' . esc_html($name) . '</li>';
                    }
                    if (count($customer_success_names) > 10) {
                        $notice .= '<li><em>... ve ' . (count($customer_success_names) - 10) . ' diğer müşteri</em></li>';
                    }
                    $notice .= '</ul>';
                }
                
                // Policy successes  
                if (!empty($policy_success_names)) {
                    $notice .= '<p><strong>Poliçeler (' . count($policy_success_names) . '):</strong></p>';
                    $notice .= '<ul>';
                    foreach (array_slice($policy_success_names, 0, 10) as $policy_num) {
                        $notice .= '<li>Poliçe No: ' . esc_html($policy_num) . '</li>';
                    }
                    if (count($policy_success_names) > 10) {
                        $notice .= '<li><em>... ve ' . (count($policy_success_names) - 10) . ' diğer poliçe</em></li>';
                    }
                    $notice .= '</ul>';
                }
                
                $notice .= '</div>';
            }
            
            // Renumbered policies information
            if (!empty($detailed_renumbered_policies_info)) {
                $notice .= '<div class="ab-notice ab-warning" style="background-color: #fff8e1; border-color: #ffcc02; color: #856404;">';
                $notice .= '<h4>🔄 Poliçe Numarası Değiştirilen Kayıtlar (' . count($detailed_renumbered_policies_info) . ')</h4>';
                $notice .= '<p><strong>Aşağıdaki poliçelerin numaraları sistem tarafından çakışma nedeniyle değiştirilmiştir:</strong></p>';
                
                $notice .= '<div style="max-height: 300px; overflow-y: auto; border: 1px solid #ffcc02; padding: 10px; margin: 10px 0; background: #fffef7;">';
                foreach ($detailed_renumbered_policies_info as $renamed_policy) {
                    $notice .= '<div style="margin-bottom: 10px; padding: 5px; background: #fff; border-left: 3px solid #ffcc02;">';
                    $notice .= '<strong>Satır:</strong> ' . esc_html($renamed_policy['row_index']);
                    $notice .= ' | <strong>Müşteri:</strong> ' . esc_html($renamed_policy['customer_name']);
                    $notice .= '<br><strong>Orijinal Poliçe No:</strong> ' . esc_html($renamed_policy['original_policy_number']);
                    $notice .= '<br><strong>Yeni Poliçe No:</strong> ' . esc_html($renamed_policy['new_policy_number']);
                    $notice .= '</div>';
                }
                $notice .= '</div>';
                $notice .= '</div>';
            }
            
            // Detailed error information
            if ($error_count > 0) {
                $notice .= '<div class="ab-notice ab-error">';
                $notice .= '<h4>❌ Başarısız İşlemler (' . $error_count . ')</h4>';
                $notice .= '<p><strong>Detaylı Hata Bilgileri:</strong></p>';
                
                if (!empty($detailed_error_info)) {
                    $notice .= '<div style="max-height: 300px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; margin: 10px 0;">';
                    foreach (array_slice($detailed_error_info, 0, 20) as $error_detail) {
                        $notice .= '<div style="margin-bottom: 10px; padding: 5px; background: #f9f9f9; border-left: 3px solid #dc3545;">';
                        
                        if ($error_detail['type'] === 'customer_insert_failed') {
                            $notice .= '<strong>Müşteri Eklenemedi:</strong> ' . esc_html($error_detail['name']);
                            if (!empty($error_detail['tc'])) {
                                $notice .= ' (TC: ' . esc_html($error_detail['tc']) . ')';
                            }
                            if (!empty($error_detail['phone'])) {
                                $notice .= ' (Tel: ' . esc_html($error_detail['phone']) . ')';
                            }
                            $notice .= '<br><strong>Satır:</strong> ' . esc_html($error_detail['row']);
                            $notice .= '<br><strong>Hata:</strong> ' . esc_html($error_detail['reason']);
                            $notice .= '<br><strong>Dolu Alanlar:</strong> ' . implode(', ', $error_detail['data_fields']);
                            
                            // Show problematic field values for debugging
                            if (isset($error_detail['field_values'])) {
                                $notice .= '<br><strong>Problemli Değerler:</strong>';
                                if (isset($error_detail['field_values']['tc_identity']) && !empty($error_detail['field_values']['tc_identity'])) {
                                    $notice .= ' TC: ' . esc_html($error_detail['field_values']['tc_identity']);
                                }
                                if (isset($error_detail['field_values']['phone']) && !empty($error_detail['field_values']['phone'])) {
                                    $notice .= ' Tel: ' . esc_html($error_detail['field_values']['phone']);
                                }
                                if (isset($error_detail['field_values']['first_recorder']) && !empty($error_detail['field_values']['first_recorder'])) {
                                    $notice .= ' İlk Kayıt: ' . esc_html($error_detail['field_values']['first_recorder']);
                                }
                            }
                        } 
                        elseif ($error_detail['type'] === 'policy_insert_failed' || $error_detail['type'] === 'policy_update_failed') {
                            $notice .= '<strong>Poliçe İşlenemedi:</strong> ' . esc_html($error_detail['policy_number']);
                            $notice .= ' (Müşteri: ' . esc_html($error_detail['customer_name']) . ')';
                            $notice .= '<br><em>Hata: ' . esc_html($error_detail['reason']) . '</em>';
                            $notice .= '<br><em>Dolu Alanlar: ' . implode(', ', $error_detail['data_fields']) . '</em>';
                        }
                        
                        $notice .= '</div>';
                    }
                    
                    if (count($detailed_error_info) > 20) {
                        $notice .= '<p><em>... ve ' . (count($detailed_error_info) - 20) . ' diğer hata</em></p>';
                    }
                    $notice .= '</div>';
                } else {
                    $notice .= '<ul>';
                    foreach (array_slice($error_messages, 0, 10) as $msg) {
                        $notice .= '<li>' . esc_html($msg) . '</li>';
                    }
                    if (count($error_messages) > 10) {
                        $notice .= '<li><em>... ve ' . (count($error_messages) - 10) . ' diğer hata</em></li>';
                    }
                    $notice .= '</ul>';
                }
                
                $notice .= '</div>';
            }
            
            // Warnings section
            if (!empty($detailed_warning_info)) {
                $notice .= '<div class="ab-notice ab-warning">';
                $notice .= '<h4>⚠️ Uyarılar (' . count($detailed_warning_info) . ')</h4>';
                $notice .= '<ul>';
                foreach (array_slice($detailed_warning_info, 0, 10) as $warning) {
                    $notice .= '<li>' . esc_html($warning) . '</li>';
                }
                if (count($detailed_warning_info) > 10) {
                    $notice .= '<li><em>... ve ' . (count($detailed_warning_info) - 10) . ' diğer uyarı</em></li>';
                }
                $notice .= '</ul>';
                $notice .= '</div>';
            }
            
            // Add debug information for troubleshooting
            if (defined('WP_DEBUG') && WP_DEBUG && !empty($debug_messages)) {
                $notice .= '<div class="ab-notice ab-info">';
                $notice .= '<h4>🔧 Debug Bilgileri (Son 10)</h4>';
                $notice .= '<div style="max-height: 200px; overflow-y: auto; font-family: monospace; font-size: 12px;">';
                foreach (array_slice($debug_messages, -10) as $debug_msg) {
                    $notice .= '<div>' . esc_html($debug_msg) . '</div>';
                }
                $notice .= '</div>';
                $notice .= '</div>';
            }
            
            // Clean up session
            unset($_SESSION['csv_data'], $_SESSION['import_file_path'], $_SESSION['import_file_type'], $_SESSION['column_mapping']);
            
            // Add success message to session for display
            $_SESSION['import_success_message'] = $notice;
            
            $redirect_url = strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query(array('view' => 'veri_aktar', 'step' => '1', 'import_complete' => '1'));
            header('Location: ' . $redirect_url);
            exit;
        } else {
            $notice = '<div class="ab-notice ab-error">İçe aktarma verisi bulunamadı. Lütfen işlemi baştan başlatın.</div>';
            $redirect_url = strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query(array('view' => 'veri_aktar', 'step' => '1'));
            header('Location: ' . $redirect_url);
            exit;
        }
    }
}

// Handle import completion message
if (isset($_GET['import_complete']) && isset($_SESSION['import_success_message'])) {
    $notice = $_SESSION['import_success_message'];
    unset($_SESSION['import_success_message']);
}

// Load existing session data if available and needed
if (isset($_SESSION['csv_data']) && $import_step >= 2) {
    $csv_data = $_SESSION['csv_data'];
}

// Debug information (remove in production)
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('Veri Aktar Debug - Step: ' . $import_step . ', Session CSV Data: ' . (isset($_SESSION['csv_data']) ? 'exists' : 'missing') . ', Session Mapping: ' . (isset($_SESSION['column_mapping']) ? 'exists' : 'missing'));
    if (isset($_SESSION['csv_data'])) {
        error_log('CSV Data headers: ' . print_r($_SESSION['csv_data']['headers'] ?? 'no headers', true));
        error_log('CSV Data rows: ' . (isset($_SESSION['csv_data']['data']) ? count($_SESSION['csv_data']['data']) : 'no data') . ' rows');
    }
}
?>

<div class="veri-aktar-container">
    <div class="import-header">
        <h1><i class="dashicons dashicons-upload"></i> Veri Aktar</h1>
        <p>CSV ve Excel dosyalarından müşteri ve poliçe verilerini sisteme aktarın</p>
    </div>

    <?php if ($notice): ?>
        <?php echo $notice; ?>
    <?php endif; ?>

    <?php if ($import_step == 1): ?>
        <!-- Facebook Import Option -->
        <div class="import-step">
            <div class="step-header">
                <h2><i class="dashicons dashicons-facebook"></i> Özel İçe Aktarım Seçenekleri</h2>
                <p>Önceden tanımlanmış veri kaynaklarını kullanın</p>
            </div>
            
            <div class="special-import-options">
                <a href="?view=veri_aktar_facebook" class="special-import-button facebook-import">
                    <div class="import-icon">
                        <i class="dashicons dashicons-facebook-alt"></i>
                    </div>
                    <div class="import-content">
                        <h3>Facebook Veri aktar</h3>
                        <p>Facebook lead verilerini sisteme aktarın</p>
                    </div>
                    <div class="import-arrow">
                        <i class="dashicons dashicons-arrow-right-alt2"></i>
                    </div>
                </a>
            </div>
        </div>

        <!-- Step 1: File Upload -->
        <div class="import-step">
            <div class="step-header">
                <h2><span class="step-number">1</span> Manuel Dosya Yükleme</h2>
                <p>Aktarmak istediğiniz CSV veya Excel dosyasını seçin</p>
            </div>
            
            <form method="post" enctype="multipart/form-data" class="upload-form">
                <div class="upload-area">
                    <div class="upload-icon">
                        <i class="dashicons dashicons-media-document"></i>
                    </div>
                    <div class="upload-content">
                        <h3>Dosya Seçin</h3>
                        <p>CSV, XLS veya XLSX formatında dosyalar desteklenir</p>
                        <input type="file" name="csv_file" accept=".csv,.xlsx,.xls" required class="file-input">
                        <button type="submit" name="upload_file" class="btn btn-primary">
                            <i class="dashicons dashicons-upload"></i> Dosyayı Yükle
                        </button>
                    </div>
                </div>
            </form>
        </div>

    <?php elseif ($import_step == 2 && $csv_data): ?>
        <!-- Step 2: Column Mapping -->
        <div class="import-step">
            <div class="step-header">
                <h2><span class="step-number">2</span> Sütun Eşleştirme</h2>
                <p>Dosyanızdaki sütunları sistem alanlarıyla eşleştirin</p>
            </div>
            
            <?php 
            $auto_suggestions = get_auto_mapping_suggestions($csv_data && isset($csv_data['headers']) ? $csv_data['headers'] : []);
            ?>
            
            <form method="post" class="mapping-form">
                <div class="mapping-container">
                    <div class="auto-suggestions" style="margin-bottom: 20px;">
                        <div class="suggestion-header">
                            <h3><i class="dashicons dashicons-lightbulb"></i> Otomatik Eşleştirme Önerileri</h3>
                            <?php if (!empty($auto_suggestions)): ?>
                                <button type="button" class="btn btn-secondary apply-suggestions">Önerileri Uygula</button>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($auto_suggestions)): ?>
                            <p class="suggestion-info">
                                <i class="dashicons dashicons-info"></i>
                                <?php echo count($auto_suggestions); ?> sütun için otomatik eşleştirme önerisi bulundu.
                            </p>
                        <?php else: ?>
                            <p class="suggestion-info">
                                <i class="dashicons dashicons-warning"></i>
                                Otomatik eşleştirme önerisi bulunamadı. Manuel olarak eşleştirin.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="mapping-grid">
                        <div class="field-group">
                            <h4>Müşteri Bilgileri</h4>
                            <div class="field-row">
                                <label>TC Kimlik No *</label>
                                <?php echo generate_column_select('tc_identity', $csv_data, $auto_suggestions['tc_identity'] ?? ''); ?>
                            </div>
                            <div class="field-row">
                                <label>Ad Soyad (Tam Ad)</label>
                                <?php echo generate_column_select('full_name', $csv_data, $auto_suggestions['full_name'] ?? ''); ?>
                                <small style="color: #666; font-style: italic;">Eğer CSV'de "Ad Soyad" sütunu varsa bunu kullanın</small>
                            </div>
                            <div class="field-row">
                                <label>Telefon</label>
                                <?php echo generate_column_select('phone', $csv_data, $auto_suggestions['phone'] ?? ''); ?>
                            </div>
                            <div class="field-row">
                                <label>E-posta</label>
                                <?php echo generate_column_select('email', $csv_data, $auto_suggestions['email'] ?? ''); ?>
                            </div>
                            <div class="field-row">
                                <label>Doğum Tarihi</label>
                                <?php echo generate_column_select('birth_date', $csv_data, $auto_suggestions['birth_date'] ?? ''); ?>
                            </div>
                            <div class="field-row">
                                <label>Adres</label>
                                <?php echo generate_column_select('address', $csv_data, $auto_suggestions['address'] ?? ''); ?>
                            </div>
                        </div>

                        <div class="field-group">
                            <h4>Poliçe Bilgileri</h4>
                            <div class="field-row">
                                <label>Poliçe Numarası *</label>
                                <?php echo generate_column_select('policy_number', $csv_data, $auto_suggestions['policy_number'] ?? ''); ?>
                            </div>
                            <div class="field-row">
                                <label>Poliçe Türü</label>
                                <?php echo generate_column_select('policy_type', $csv_data, $auto_suggestions['policy_type'] ?? ''); ?>
                            </div>
                            <div class="field-row">
                                <label>Prim Tutarı</label>
                                <?php echo generate_column_select('premium_amount', $csv_data, $auto_suggestions['premium_amount'] ?? ''); ?>
                            </div>
                            <div class="field-row">
                                <label>Başlangıç Tarihi</label>
                                <?php echo generate_column_select('start_date', $csv_data, $auto_suggestions['start_date'] ?? ''); ?>
                            </div>
                            <div class="field-row">
                                <label>Bitiş Tarihi</label>
                                <?php echo generate_column_select('end_date', $csv_data, $auto_suggestions['end_date'] ?? ''); ?>
                            </div>
                            <div class="field-row">
                                <label>Yeni İş / Yenileme</label>
                                <?php echo generate_column_select('is_new_business', $csv_data, $auto_suggestions['is_new_business'] ?? ''); ?>
                                <small class="field-note">Yeni iş için "Yeni", "New", "1" - Yenileme için "Yenileme", "Renewal", "0" değerleri</small>
                            </div>
                            <div class="field-row">
                                <label>Sigorta Şirketi</label>
                                <?php echo generate_column_select('insurance_company', $csv_data, $auto_suggestions['insurance_company'] ?? ''); ?>
                                <small class="field-note">Poliçenin bağlı olduğu sigorta şirketi</small>
                            </div>
                            <div class="field-row">
                                <label>Meslek</label>
                                <?php echo generate_column_select('occupation', $csv_data, $auto_suggestions['occupation'] ?? ''); ?>
                                <small class="field-note">Müşterinin meslek bilgisi</small>
                            </div>
                            <div class="field-row">
                                <label>Branş</label>
                                <?php echo generate_column_select('branch', $csv_data, $auto_suggestions['branch'] ?? ''); ?>
                                <small class="field-note">Sigorta branşı/kategori bilgisi</small>
                            </div>
                            <div class="field-row">
                                <label>Network</label>
                                <?php echo generate_column_select('network', $csv_data, $auto_suggestions['network'] ?? ''); ?>
                                <small class="field-note">Network/kanal bilgisi</small>
                            </div>
                            <div class="field-row">
                                <label>Sigorta Ettiren</label>
                                <?php echo generate_column_select('insured_party', $csv_data, $auto_suggestions['insured_party'] ?? ''); ?>
                                <small class="field-note">Sigorta ettiren kişi/kurum</small>
                            </div>
                            <div class="field-row">
                                <label>Ödeme Tipi</label>
                                <?php echo generate_column_select('payment_type', $csv_data, $auto_suggestions['payment_type'] ?? ''); ?>
                                <small class="field-note">Ödeme yöntemi bilgisi</small>
                            </div>
                            <div class="field-row">
                                <label>Durum Notu</label>
                                <?php echo generate_column_select('status_note', $csv_data, $auto_suggestions['status_note'] ?? ''); ?>
                                <small class="field-note">Poliçe durum açıklama notu</small>
                            </div>
                        </div>
                    </div>

                    <div class="file-preview">
                        <h4>Dosya Önizleme (İlk 5 Satır)</h4>
                        <div class="preview-table-container">
                            <table class="preview-table">
                                <thead>
                                    <tr>
                                        <?php 
                                        if ($csv_data && isset($csv_data['headers']) && is_array($csv_data['headers'])) {
                                            foreach ($csv_data['headers'] as $header): ?>
                                                <th><?php echo esc_html($header); ?></th>
                                            <?php endforeach;
                                        } else { ?>
                                            <th>Başlık bulunamadı</th>
                                        <?php } ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    if ($csv_data && isset($csv_data['sample_data']) && is_array($csv_data['sample_data'])) {
                                        $preview_rows = array_slice($csv_data['sample_data'], 0, 5);
                                        foreach ($preview_rows as $row): ?>
                                            <tr>
                                                <?php foreach ($row as $cell): ?>
                                                    <td><?php echo esc_html($cell); ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; 
                                    } else { ?>
                                        <tr>
                                            <td colspan="100%" style="text-align: center; padding: 20px; color: #666;">
                                                Önizleme verisi bulunamadı. Lütfen dosyayı yeniden yükleyin.
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" name="confirm_mapping" class="btn btn-primary">
                        <i class="dashicons dashicons-yes"></i> Eşleştirmeyi Onayla ve Ön İzle
                    </button>
                </div>
            </form>
        </div>

    <?php elseif ($import_step == 3): ?>
        <!-- Step 3: Preview and Final Import -->
        <?php if (!isset($_SESSION['column_mapping'])): ?>
            <div class="ab-notice ab-error">
                Sütun eşleştirme bilgileri bulunamadı. Lütfen dosyayı yeniden yükleyin ve eşleştirme işlemini tekrar yapın.
                <a href="<?php echo strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query(array('view' => 'veri_aktar', 'step' => '1')); ?>" class="btn btn-secondary">Baştan Başla</a>
            </div>
        <?php else: ?>
        <div class="import-step">
            <div class="step-header">
                <h2><span class="step-number">3</span> Önizleme ve Son Onay</h2>
                <p>Aktarılacak verileri kontrol edin ve varsayılan atamaları yapın</p>
            </div>

            <form method="post" class="final-import-form">
                <!-- Data Preview Table -->
                <div class="data-preview">
                    <h3><i class="dashicons dashicons-list-view"></i> Veri Önizleme</h3>
                    
                    <div class="preview-summary">
                        <span class="summary-item">
                            <strong><?php 
                                if ($csv_data && isset($csv_data['data'])) {
                                    echo count($csv_data['data']);
                                } elseif ($csv_data && isset($csv_data['sample_data'])) {
                                    echo count($csv_data['sample_data']) . ' (örnek)';
                                } else {
                                    echo '0';
                                }
                            ?></strong> kayıt bulundu
                        </span>
                        <span class="summary-item">
                            Tüm kayıtlar önizleniyor ve işlenecek.
                        </span>
                        <?php 
                        // Add warning if CSV has more than 500 rows
                        $total_rows = 0;
                        if ($csv_data && isset($csv_data['data'])) {
                            $total_rows = count($csv_data['data']);
                        } elseif ($csv_data && isset($csv_data['sample_data'])) {
                            $total_rows = count($csv_data['sample_data']);
                        }
                        
                        if ($total_rows > 500): ?>
                        <div class="ab-notice ab-warning" style="margin-top: 10px;">
                            <strong>BİLGİLENDİRME:</strong> Yüklenen dosya <?php echo $total_rows; ?> satır veri içermektedir. 
                            Performans sorunları yaşamamak ve olası hataları önlemek için dosyayı bölerek daha küçük parçalar halinde 
                            (örneğin 500'er satırlık) yüklemeniz önerilir.
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Default Assignment Panel -->
                    <div class="default-assignments-panel">
                        <h4><i class="dashicons dashicons-admin-users"></i> Varsayılan Atamalar</h4>
                        <p>Tüm kayıtlar için varsayılan müşteri temsilcisi ve ilk kaydeden bilgisini seçin:</p>
                        
                        <div class="assignment-row">
                            <div class="assignment-field">
                                <label for="default_customer_representative">Müşteri Temsilcisi:</label>
                                <select id="default_customer_representative" name="default_customer_representative">
                                    <option value="">Seçiniz...</option>
                                    <?php
                                    // Get all active representatives
                                    $representatives = $wpdb->get_results(
                                        "SELECT r.id, r.title, u.display_name 
                                         FROM {$wpdb->prefix}insurance_crm_representatives r 
                                         LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
                                         WHERE r.status = 'active' 
                                         ORDER BY u.display_name"
                                    );
                                    
                                    $current_user_rep_id = get_current_user_rep_id();
                                    
                                    foreach ($representatives as $rep) {
                                        $display_name = !empty($rep->display_name) ? $rep->display_name : $rep->title;
                                        $selected = ($rep->id == $current_user_rep_id) ? 'selected' : '';
                                        echo '<option value="' . esc_attr($rep->id) . '" ' . $selected . '>' . esc_html($display_name) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            
                            <div class="assignment-field">
                                <label for="default_first_recorder">İlk Kaydeden:</label>
                                <select id="default_first_recorder" name="default_first_recorder">
                                    <option value="">Seçiniz...</option>
                                    <?php
                                    // Get all users who can be first recorder (including passive representatives)
                                    $users = $wpdb->get_results(
                                        "SELECT u.ID, u.display_name, u.user_login 
                                         FROM {$wpdb->users} u 
                                         INNER JOIN {$wpdb->prefix}insurance_crm_representatives r ON u.ID = r.user_id 
                                         WHERE r.status IN ('active', 'passive')
                                         ORDER BY u.display_name"
                                    );
                                    
                                    $current_user_id = get_current_user_id();
                                    $current_user_display_name = get_userdata($current_user_id)->display_name;
                                    
                                    foreach ($users as $user) {
                                        $display_name = !empty($user->display_name) ? $user->display_name : $user->user_login;
                                        $selected = ($user->ID == $current_user_id) ? 'selected' : '';
                                        echo '<option value="' . esc_attr($display_name) . '" ' . $selected . '>' . esc_html($display_name) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            
                            <div class="assignment-actions">
                                <button type="button" id="apply_defaults_to_all" class="button button-secondary">
                                    <i class="dashicons dashicons-yes"></i> Varsayılanları Tümüne Uygula
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="data-table-container">
                        <table class="data-table preview-data-table">
                            <thead>
                                <tr>
                                    <th style="width: 20%;">Müşteri</th>
                                    <th style="width: 15%;">Poliçe</th>
                                    <th style="width: 10%;">İş Türü</th>
                                    <th style="width: 12%;">Sigorta Şirketi</th>
                                    <th style="width: 8%;">Meslek</th>
                                    <th style="width: 10%;">Durum Notu</th>
                                    <th style="width: 12%;">Müşteri Temsilcisi</th>
                                    <th style="width: 13%;">İlk Kaydeden</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                // Debug information - show data state
                                if (defined('WP_DEBUG') && WP_DEBUG) {
                                    echo '<!-- Debug: CSV Data exists: ' . (isset($csv_data) ? 'yes' : 'no') . ' -->';
                                    echo '<!-- Debug: CSV Data has data array: ' . (isset($csv_data['data']) ? 'yes' : 'no') . ' -->';
                                    echo '<!-- Debug: CSV Data has sample_data array: ' . (isset($csv_data['sample_data']) ? 'yes' : 'no') . ' -->';
                                    echo '<!-- Debug: Session mapping exists: ' . (isset($_SESSION['column_mapping']) ? 'yes' : 'no') . ' -->';
                                    if (isset($csv_data['data'])) {
                                        echo '<!-- Debug: Full data row count: ' . count($csv_data['data']) . ' -->';
                                    }
                                    if (isset($csv_data['sample_data'])) {
                                        echo '<!-- Debug: Sample data row count: ' . count($csv_data['sample_data']) . ' -->';
                                    }
                                }
                                
                                // Use full data if available, otherwise fall back to sample data
                                $data_source = null;
                                if ($csv_data && isset($csv_data['data']) && is_array($csv_data['data'])) {
                                    $data_source = $csv_data['data'];
                                } elseif ($csv_data && isset($csv_data['sample_data']) && is_array($csv_data['sample_data'])) {
                                    $data_source = $csv_data['sample_data'];
                                }
                                
                                if ($data_source && isset($_SESSION['column_mapping'])) {
                                    $column_mapping = $_SESSION['column_mapping'];
                                    // Show all data instead of limiting to 20 rows
                                    $preview_rows = $data_source;
                                
                                foreach ($preview_rows as $index => $row):
                                    // Extract data based on mapping
                                    $customer_name = '';
                                    if (isset($column_mapping['first_name']) && isset($row[$column_mapping['first_name']])) {
                                        $customer_name .= $row[$column_mapping['first_name']];
                                    }
                                    if (isset($column_mapping['last_name']) && isset($row[$column_mapping['last_name']])) {
                                        $customer_name .= ' ' . $row[$column_mapping['last_name']];
                                    }
                                    
                                    $tc_identity = isset($column_mapping['tc_identity']) && isset($row[$column_mapping['tc_identity']]) ? $row[$column_mapping['tc_identity']] : '';
                                    $phone = isset($column_mapping['phone']) && isset($row[$column_mapping['phone']]) ? $row[$column_mapping['phone']] : '';
                                    $birth_date = isset($column_mapping['birth_date']) && isset($row[$column_mapping['birth_date']]) ? $row[$column_mapping['birth_date']] : '';
                                    
                                    $policy_number = isset($column_mapping['policy_number']) && isset($row[$column_mapping['policy_number']]) ? $row[$column_mapping['policy_number']] : '';
                                    $policy_type = isset($column_mapping['policy_type']) && isset($row[$column_mapping['policy_type']]) ? $row[$column_mapping['policy_type']] : '';
                                    $premium = isset($column_mapping['premium_amount']) && isset($row[$column_mapping['premium_amount']]) ? $row[$column_mapping['premium_amount']] : '';
                                    
                                    // New fields
                                    $insurance_company = isset($column_mapping['insurance_company']) && isset($row[$column_mapping['insurance_company']]) ? $row[$column_mapping['insurance_company']] : '';
                                    $occupation = isset($column_mapping['occupation']) && isset($row[$column_mapping['occupation']]) ? $row[$column_mapping['occupation']] : '';
                                    $status_note = isset($column_mapping['status_note']) && isset($row[$column_mapping['status_note']]) ? $row[$column_mapping['status_note']] : '';
                                    
                                    // Business type detection
                                    $business_type = 'Belirsiz';
                                    if (isset($column_mapping['is_new_business']) && isset($row[$column_mapping['is_new_business']])) {
                                        $business_value = strtolower(trim($row[$column_mapping['is_new_business']]));
                                        if (in_array($business_value, ['yeni', 'new', '1', 'yeni iş', 'new business'])) {
                                            $business_type = 'Yeni İş';
                                        } elseif (in_array($business_value, ['yenileme', 'renewal', '0', 'renew'])) {
                                            $business_type = 'Yenileme';
                                        }
                                    }
                                ?>
                                    <tr data-row="<?php echo $index; ?>">
                                        <td>
                                            <div class="customer-info">
                                                <strong><?php echo esc_html(trim($customer_name)); ?></strong>
                                                <?php if ($tc_identity || $phone || $birth_date): ?>
                                                    <div class="customer-details">
                                                        <?php if ($tc_identity): ?>
                                                            <span>TC: <?php echo esc_html($tc_identity); ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($phone): ?>
                                                            <span>Tel: <?php echo esc_html($phone); ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($birth_date): ?>
                                                            <span>Doğum: <?php echo esc_html($birth_date); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="policy-info">
                                                <?php if ($policy_number): ?>
                                                    <strong><?php echo esc_html($policy_number); ?></strong>
                                                <?php endif; ?>
                                                <?php if ($policy_type || $premium): ?>
                                                    <div class="policy-details">
                                                        <?php if ($policy_type): ?>
                                                            <span><?php echo esc_html($policy_type); ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($premium): ?>
                                                            <span><?php echo esc_html($premium); ?> ₺</span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="business-type-badge <?php echo $business_type == 'Yeni İş' ? 'new-business' : ($business_type == 'Yenileme' ? 'renewal' : 'unknown'); ?>">
                                                <?php echo esc_html($business_type); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="company-info">
                                                <?php echo esc_html($insurance_company); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="occupation-info">
                                                <?php echo esc_html($occupation); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-note-info">
                                                <?php echo esc_html($status_note); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <select class="customer-representative-select" name="customer_representative[<?php echo $index; ?>]" data-row="<?php echo $index; ?>">
                                                <option value="">Seçiniz...</option>
                                                <?php
                                                foreach ($representatives as $rep) {
                                                    $display_name = !empty($rep->display_name) ? $rep->display_name : $rep->title;
                                                    $selected = ($rep->id == $current_user_rep_id) ? 'selected' : '';
                                                    echo '<option value="' . esc_attr($rep->id) . '" ' . $selected . '>' . esc_html($display_name) . '</option>';
                                                }
                                                ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="first-recorder-select" name="first_recorder[<?php echo $index; ?>]" data-row="<?php echo $index; ?>">
                                                <option value="">Seçiniz...</option>
                                                <?php
                                                foreach ($users as $user) {
                                                    $display_name = !empty($user->display_name) ? $user->display_name : $user->user_login;
                                                    $selected = ($user->ID == $current_user_id) ? 'selected' : '';
                                                    echo '<option value="' . esc_attr($display_name) . '" ' . $selected . '>' . esc_html($display_name) . '</option>';
                                                }
                                                ?>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; 
                                } else { ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; padding: 20px; color: #666;">
                                            Önizleme verisi bulunamadı. Lütfen dosyayı yeniden yükleyin.
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php 
                    $total_rows = 0;
                    if ($csv_data && isset($csv_data['data'])) {
                        $total_rows = count($csv_data['data']);
                    } elseif ($csv_data && isset($csv_data['sample_data'])) {
                        $total_rows = count($csv_data['sample_data']);
                    }
                    
                    if ($total_rows > 0): ?>
                        <div class="preview-note">
                            <i class="dashicons dashicons-info"></i>
                            Tüm kayıtlar görüntüleniyor. Toplam <?php echo $total_rows; ?> kayıt aktarılacak.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='<?php echo strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query(array('view' => 'veri_aktar', 'step' => '2')); ?>'">
                        <i class="dashicons dashicons-arrow-left-alt"></i> Geri Dön
                    </button>
                    <button type="submit" name="final_import" class="btn btn-primary">
                        <i class="dashicons dashicons-database-import"></i> Verileri Aktar
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<style>
.veri-aktar-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.special-import-options {
    display: flex;
    gap: 20px;
    margin-bottom: 30px;
}

.special-import-button {
    display: flex;
    align-items: center;
    padding: 20px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    text-decoration: none;
    color: #333;
    background: #fff;
    transition: all 0.3s ease;
    flex: 1;
    min-height: 80px;
}

.special-import-button:hover {
    border-color: #0073aa;
    background: #f8f9fa;
    color: #0073aa;
    text-decoration: none;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,115,170,0.1);
}

.facebook-import {
    border-color: #1877f2;
}

.facebook-import:hover {
    border-color: #1877f2;
    background: #f0f4ff;
    color: #1877f2;
}

.special-import-button .import-icon {
    margin-right: 15px;
}

.special-import-button .import-icon i {
    font-size: 32px;
}

.facebook-import .import-icon i {
    color: #1877f2;
}

.special-import-button .import-content h3 {
    margin: 0 0 5px 0;
    font-size: 18px;
    font-weight: 600;
}

.special-import-button .import-content p {
    margin: 0;
    font-size: 14px;
    color: #666;
}

.special-import-button .import-arrow {
    margin-left: auto;
}

.special-import-button .import-arrow i {
    font-size: 20px;
    color: #999;
}

.special-import-button:hover .import-arrow i {
    color: inherit;
}

.import-header {
    text-align: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e0e0e0;
}

.import-header h1 {
    font-size: 28px;
    color: #333;
    margin-bottom: 10px;
}

.import-header i {
    margin-right: 10px;
    color: #0073aa;
}

.import-step {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.step-header {
    background: linear-gradient(135deg, #0073aa, #005a87);
    color: white;
    padding: 20px;
    border-radius: 8px 8px 0 0;
}

.step-header h2 {
    font-size: 22px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
}

.step-number {
    background: rgba(255,255,255,0.2);
    border-radius: 50%;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    font-weight: bold;
}

.step-header p {
    margin: 0;
    opacity: 0.9;
}

.upload-form,
.mapping-form,
.final-import-form {
    padding: 30px;
}

.upload-area {
    border: 2px dashed #ddd;
    border-radius: 8px;
    padding: 40px;
    text-align: center;
    transition: border-color 0.3s;
    background: #fafafa;
}

.upload-area:hover {
    border-color: #0073aa;
}

.upload-icon i {
    font-size: 48px;
    color: #0073aa;
    margin-bottom: 20px;
}

.upload-content h3 {
    font-size: 20px;
    margin-bottom: 10px;
    color: #333;
}

.file-input {
    margin: 20px 0;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    width: 300px;
}

.auto-suggestions {
    background: #f8f9fa;
    border: 1px solid #e0e4e7;
    border-radius: 6px;
    padding: 20px;
}

.suggestion-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.suggestion-header h3 {
    margin: 0;
    color: #333;
    font-size: 16px;
}

.suggestion-info {
    margin: 0;
    color: #666;
    font-size: 14px;
}

.mapping-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    margin: 20px 0;
}

.field-group h4 {
    color: #0073aa;
    border-bottom: 2px solid #0073aa;
    padding-bottom: 8px;
    margin-bottom: 20px;
}

.field-row {
    margin-bottom: 15px;
}

.field-row label {
    display: block;
    font-weight: 600;
    margin-bottom: 5px;
    color: #333;
}

.field-note {
    display: block;
    color: #666;
    font-size: 12px;
    margin-top: 5px;
    font-style: italic;
}

.ab-select,
.column-mapping-select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.file-preview {
    margin-top: 30px;
    border-top: 1px solid #e0e0e0;
    padding-top: 20px;
}

.preview-table-container {
    overflow-x: auto;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    max-height: 300px;
    overflow-y: auto;
    margin: 0 -20px; /* Allow table to use more horizontal space */
    padding: 0 20px;
}

.preview-table {
    width: 100%;
    min-width: 800px; /* Increased minimum width for better visibility */
    border-collapse: collapse;
    font-size: 13px;
}

.preview-table th,
.preview-table td {
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid #f0f0f0;
    white-space: nowrap;
}

.preview-table th {
    background: #f8f9fa;
    font-weight: 600;
    position: sticky;
    top: 0;
    z-index: 1;
}

.default-assignment-panel {
    background: #f8f9fa;
    border: 1px solid #e0e4e7;
    border-radius: 6px;
    padding: 20px;
    margin-bottom: 30px;
}

.default-assignment-panel h3 {
    margin: 0 0 10px 0;
    color: #333;
    font-size: 18px;
}

.assignment-row {
    display: flex;
    gap: 20px;
    align-items: end;
    margin-top: 15px;
}

.assignment-field {
    flex: 1;
}

.assignment-field label {
    display: block;
    font-weight: 600;
    margin-bottom: 5px;
    color: #333;
}

.assignment-actions {
    flex-shrink: 0;
}

.data-preview {
    margin-top: 20px;
}

.preview-summary {
    display: flex;
    gap: 20px;
    margin-bottom: 15px;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 6px;
}

.summary-item {
    font-size: 14px;
    color: #333;
}

.data-table-container {
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    overflow: hidden;
    max-height: 500px;
    overflow-y: auto;
    overflow-x: auto;
    width: 100%;
    margin: 0 -20px; /* Allow table to use more horizontal space */
    padding: 0 20px;
}

.data-table {
    width: 100%;
    min-width: 1400px; /* Increased minimum width for better content display */
    border-collapse: collapse;
    font-size: 13px;
}

.data-table th {
    background: #f8f9fa;
    color: #333;
    font-weight: 600;
    padding: 12px 15px;
    text-align: left;
    border-bottom: 2px solid #e0e0e0;
    position: sticky;
    top: 0;
    z-index: 1;
}

.data-table td {
    padding: 12px 15px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: top;
}

.data-table tbody tr:hover {
    background: #f5f5f5;
}

.customer-info,
.policy-info {
    line-height: 1.4;
}

.customer-details,
.policy-details {
    font-size: 12px;
    color: #666;
    margin-top: 4px;
}

.customer-details span,
.policy-details span {
    display: block;
}

.business-type-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}

.business-type-badge.new-business {
    background: #d1e7dd;
    color: #198754;
}

.business-type-badge.renewal {
    background: #fff3cd;
    color: #856404;
}

.business-type-badge.unknown {
    background: #f8d7da;
    color: #721c24;
}

.representative-select,
.created-by-select {
    width: 100%;
    padding: 5px 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 13px;
}

.company-info,
.occupation-info,
.branch-info {
    font-size: 13px;
    color: #333;
    padding: 2px 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100px; /* Limit width to prevent overflow */
}

.preview-note {
    padding: 15px;
    background: #e8f4f8;
    border: 1px solid #b8daff;
    border-radius: 4px;
    margin-top: 15px;
    color: #0c5460;
}

.form-actions {
    display: flex;
    gap: 15px;
    justify-content: flex-end;
    padding-top: 20px;
    border-top: 1px solid #e0e0e0;
    margin-top: 30px;
}

.btn {
    display: inline-flex;
    align-items: center;
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.3s;
}

.btn i {
    margin-right: 8px;
    font-size: 16px;
}

.btn-primary {
    background: #0073aa;
    color: white;
}

.btn-primary:hover {
    background: #005a87;
    color: white;
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #5a6268;
    color: white;
}

.ab-notice {
    padding: 12px 20px;
    border-radius: 4px;
    margin-bottom: 20px;
}

.ab-notice.ab-success {
    background: #d1e7dd;
    border: 1px solid #badbcc;
    color: #0f5132;
}

.ab-notice.ab-error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

/* Default Assignments Panel */
.default-assignments-panel {
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 20px;
    margin-bottom: 20px;
}

.default-assignments-panel h4 {
    margin: 0 0 10px 0;
    color: #333;
    font-size: 16px;
}

.assignment-row {
    display: flex;
    gap: 20px;
    align-items: flex-end;
    flex-wrap: wrap;
}

.assignment-field {
    flex: 1;
    min-width: 200px;
}

.assignment-field label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #333;
}

.assignment-field select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.assignment-actions {
    flex: 0 0 auto;
}

#apply_defaults_to_all {
    background: #0073aa;
    color: white;
    border: none;
    padding: 10px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 5px;
}

#apply_defaults_to_all:hover {
    background: #005a87;
}

/* Individual row selects */
.customer-representative-select,
.first-recorder-select {
    width: 100%;
    padding: 5px 8px;
    border: 1px solid #ddd;
    border-radius: 3px;
    font-size: 12px;
}

.customer-representative-select:focus,
.first-recorder-select:focus {
    border-color: #0073aa;
    outline: none;
    box-shadow: 0 0 0 1px #0073aa;
}

/* Responsive Design */
/* Responsive Design */
@media (max-width: 1600px) {
    .data-table {
        min-width: 1300px;
    }
    
    .data-table-container {
        margin: 0 -15px;
        padding: 0 15px;
    }
}

@media (max-width: 1400px) {
    .data-table {
        min-width: 1200px;
    }
    
    .data-table-container {
        margin: 0 -20px;
        padding: 0 20px;
    }
}

@media (max-width: 1200px) {
    .data-table {
        min-width: 1100px;
    }
    
    .data-table-container {
        margin: 0 -25px;
        padding: 0 25px;
    }
}

@media (max-width: 992px) {
    .mapping-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .assignment-row {
        flex-direction: column;
        gap: 15px;
    }
    
    .data-table {
        min-width: 1000px;
        font-size: 12px;
    }
    
    .data-table th,
    .data-table td {
        padding: 8px 10px;
    }
    
    .data-table-container {
        margin: 0 -30px;
        padding: 0 30px;
    }
}

@media (max-width: 768px) {
    .form-actions {
        flex-direction: column;
    }
    
    .data-table-container {
        overflow-x: auto;
        margin: 0 -35px; /* Allow table to use more space on mobile */
        padding: 0 35px;
    }
    
    .data-table {
        min-width: 900px;
        font-size: 11px;
    }
    
    .data-table th,
    .data-table td {
        padding: 6px 8px;
    }
    
    /* Make select dropdowns smaller on mobile */
    .customer-representative-select,
    .first-recorder-select {
        font-size: 11px;
        padding: 4px 6px;
    }
}

@media (max-width: 576px) {
    .data-table {
        min-width: 800px;
        font-size: 10px;
    }
    
    .data-table-container {
        margin: 0 -40px; /* Use maximum horizontal space */
        padding: 0 40px;
    }
    
    .data-table th,
    .data-table td {
        padding: 4px 6px;
    }
    
    /* Stack form elements on very small screens */
    .assignment-row {
        flex-direction: column;
        gap: 10px;
    }
    
    .assignment-field {
        min-width: auto;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Apply suggestions functionality
    const applySuggestionsBtn = document.querySelector('.apply-suggestions');
    if (applySuggestionsBtn) {
        applySuggestionsBtn.addEventListener('click', function() {
            const autoSuggestions = <?php echo json_encode($auto_suggestions ?? []); ?>;
            
            Object.keys(autoSuggestions).forEach(field => {
                const select = document.querySelector(`select[name="mapping_${field}"]`);
                if (select) {
                    select.value = autoSuggestions[field];
                }
            });
            
            alert('Otomatik öneriler uygulandı!');
        });
    }
    
    // Apply defaults to all functionality
    const applyDefaultsBtn = document.getElementById('apply_defaults_to_all');
    if (applyDefaultsBtn) {
        applyDefaultsBtn.addEventListener('click', function() {
            const defaultCustomerRep = document.getElementById('default_customer_representative').value;
            const defaultFirstRecorder = document.getElementById('default_first_recorder').value;
            
            // Apply to all customer representative selects
            if (defaultCustomerRep) {
                const customerRepSelects = document.querySelectorAll('.customer-representative-select');
                customerRepSelects.forEach(select => {
                    select.value = defaultCustomerRep;
                });
            }
            
            // Apply to all first recorder selects
            if (defaultFirstRecorder) {
                const firstRecorderSelects = document.querySelectorAll('.first-recorder-select');
                firstRecorderSelects.forEach(select => {
                    select.value = defaultFirstRecorder;
                });
            }
            
            if (defaultCustomerRep || defaultFirstRecorder) {
                alert('Varsayılan değerler tüm kayıtlara uygulandı!');
            } else {
                alert('Lütfen önce varsayılan değerleri seçin.');
            }
        });
    }
});
</script>
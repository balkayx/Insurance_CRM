<?php
/**
 * CSV İçe Aktarım Fonksiyonları - Manuel Eşleştirme Özellikli
 * @version 2.2.4
 * @date 2025-06-11
 */

if (!defined('ABSPATH')) {
    exit;
}

function get_rep_id_from_display_name($display_name, $wpdb) {
    if (empty($display_name)) {
        return ['success' => false, 'message' => 'Müşteri temsilcisi adı boş.'];
    }
    $display_name = mb_convert_encoding(trim($display_name), 'UTF-8', ['UTF-8', 'ISO-8859-9', 'Windows-1254']);
    $normalized_name = str_replace(['ı', 'İ', 'ğ', 'Ğ', 'ü', 'Ü', 'ş', 'Ş', 'ö', 'Ö', 'ç', 'Ç'], 
                                  ['i', 'I', 'g', 'G', 'u', 'U', 's', 'S', 'o', 'O', 'c', 'C'], 
                                  strtolower($display_name));

    $user = $wpdb->get_row($wpdb->prepare(
        "SELECT ID FROM $wpdb->users WHERE LOWER(display_name) LIKE %s",
        '%' . $wpdb->esc_like($normalized_name) . '%'
    ));

    if ($user) {
        $rep_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'",
            $user->ID
        ));
        if ($rep_id) {
            return ['success' => true, 'rep_id' => $rep_id];
        }
    }

    return ['success' => false, 'message' => "Müşteri temsilcisi '$display_name' bulunamadı. Lütfen manuel seçin."];
}

function get_user_id_from_display_name($display_name, $wpdb) {
    if (empty($display_name)) {
        return ['success' => false, 'message' => 'Satış temsilcisi adı boş.'];
    }
    $display_name = mb_convert_encoding(trim($display_name), 'UTF-8', ['UTF-8', 'ISO-8859-9', 'Windows-1254']);
    $normalized_name = str_replace(['ı', 'İ', 'ğ', 'Ğ', 'ü', 'Ü', 'ş', 'Ş', 'ö', 'Ö', 'ç', 'Ç'], 
                                  ['i', 'I', 'g', 'G', 'u', 'U', 's', 'S', 'o', 'O', 'c', 'C'], 
                                  strtolower($display_name));

    $user_id = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM $wpdb->users WHERE LOWER(display_name) LIKE %s",
        '%' . $wpdb->esc_like($normalized_name) . '%'
    ));

    if ($user_id) {
        return ['success' => true, 'user_id' => $user_id];
    }

    return ['success' => false, 'message' => "Satış temsilcisi '$display_name' bulunamadı. Lütfen manuel seçin."];
}

function read_csv_headers($file_path) {
    if (!file_exists($file_path)) {
        throw new Exception('CSV dosyası bulunamadı: ' . esc_html($file_path));
    }

    if (!is_readable($file_path)) {
        throw new Exception('CSV dosyası okunamıyor, izin sorunu olabilir: ' . esc_html($file_path));
    }

    $file_size = filesize($file_path);
    if ($file_size === 0) {
        throw new Exception('CSV dosyası boş.');
    }

    if ($file_size === false) {
        throw new Exception('CSV dosya boyutu okunamadı.');
    }

    $file = @fopen($file_path, 'r');
    if (!$file) {
        $last_error = error_get_last();
        throw new Exception('CSV dosyası açılamadı: ' . esc_html($last_error ? $last_error['message'] : 'Bilinmeyen hata'));
    }

    $bom = fread($file, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($file);
    }

    $first_line = fgets($file);
    if ($first_line === false) {
        fclose($file);
        throw new Exception('CSV dosyasından ilk satır okunamadı.');
    }

    $first_line = trim($first_line);
    if (empty($first_line)) {
        fclose($file);
        throw new Exception('CSV dosyasının ilk satırı boş.');
    }

    $delimiters = [';', ',', "\t"];
    $delimiter_counts = [];
    foreach ($delimiters as $delim) {
        $delimiter_counts[$delim] = substr_count($first_line, $delim);
    }
    arsort($delimiter_counts);
    $delimiter = key($delimiter_counts);
    if ($delimiter_counts[$delimiter] === 0) {
        fclose($file);
        throw new Exception('CSV dosyasında uygun bir ayırıcı bulunamadı (denenenler: ;, ,, \\t). İlk satır: ' . substr($first_line, 0, 100));
    }

    rewind($file);
    if ($bom === "\xEF\xBB\xBF") {
        fread($file, 3);
    }

    $header = fgetcsv($file, 0, $delimiter);
    if ($header === false || empty($header)) {
        fclose($file);
        throw new Exception('CSV başlıkları okunamadı. Ayırıcı: "' . esc_html($delimiter) . '", İlk satır: ' . substr($first_line, 0, 100));
    }

    if (count($header) === 1 && empty(trim($header[0]))) {
        fclose($file);
        throw new Exception('CSV dosyasında geçerli başlık bulunamadı. Tek sütun ve boş.');
    }

    $header = array_map(function($item) {
        if (is_string($item)) {
            if (!mb_check_encoding($item, 'UTF-8')) {
                $item = mb_convert_encoding($item, 'UTF-8', ['UTF-8', 'ISO-8859-9', 'Windows-1254', 'ASCII']);
            }
            $item = preg_replace('/[\x00-\x1F\x7F]/u', '', $item);
        }
        return trim($item);
    }, $header);

    foreach ($header as $key => $value) {
        if (empty($value)) {
            $header[$key] = 'Sütun_' . ($key + 1);
        }
    }

    $sample_data = [];
    $rows_read = 0;

    while (($row = fgetcsv($file, 0, $delimiter)) !== false && $rows_read < 5) {
        if (empty(array_filter($row, function($val) { return !empty(trim($val)); }))) {
            continue;
        }

        $row = array_pad($row, count($header), null);

        $row = array_map(function($item) {
            if (is_string($item) && !mb_check_encoding($item, 'UTF-8')) {
                $item = mb_convert_encoding($item, 'UTF-8', ['UTF-8', 'ISO-8859-9', 'Windows-1254', 'ASCII']);
            }
            return $item;
        }, $row);

        $sample_data[] = $row;
        $rows_read++;
    }

    fclose($file);

    return [
        'headers' => $header,
        'sample_data' => $sample_data,
        'delimiter' => $delimiter,
        'total_columns' => count($header),
        'sample_rows' => count($sample_data)
    ];
}

function read_csv_full_data($file_path) {
    if (!file_exists($file_path)) {
        throw new Exception('CSV dosyası bulunamadı: ' . esc_html($file_path));
    }

    $file = @fopen($file_path, 'r');
    if (!$file) {
        throw new Exception('CSV dosyası açılamadı: ' . esc_html($file_path));
    }

    // Handle BOM
    $bom = fread($file, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($file);
    }

    // Detect delimiter
    $first_line = fgets($file);
    $delimiters = [';', ',', "\t"];
    $delimiter_counts = [];
    foreach ($delimiters as $delim) {
        $delimiter_counts[$delim] = substr_count($first_line, $delim);
    }
    arsort($delimiter_counts);
    $delimiter = key($delimiter_counts);

    // Reset file pointer
    rewind($file);
    if ($bom === "\xEF\xBB\xBF") {
        fread($file, 3);
    }

    // Read headers
    $header = fgetcsv($file, 0, $delimiter);
    if ($header === false || empty($header)) {
        fclose($file);
        throw new Exception('CSV başlıkları okunamadı.');
    }

    // Clean headers
    $header = array_map(function($item) {
        if (is_string($item)) {
            if (!mb_check_encoding($item, 'UTF-8')) {
                $item = mb_convert_encoding($item, 'UTF-8', ['UTF-8', 'ISO-8859-9', 'Windows-1254', 'ASCII']);
            }
            $item = preg_replace('/[\x00-\x1F\x7F]/u', '', $item);
        }
        return trim($item);
    }, $header);

    // Read all data
    $full_data = [];
    while (($row = fgetcsv($file, 0, $delimiter)) !== false) {
        if (empty(array_filter($row, function($val) { return !empty(trim($val)); }))) {
            continue;
        }

        $row = array_pad($row, count($header), null);
        $row = array_map(function($item) {
            if (is_string($item) && !mb_check_encoding($item, 'UTF-8')) {
                $item = mb_convert_encoding($item, 'UTF-8', ['UTF-8', 'ISO-8859-9', 'Windows-1254', 'ASCII']);
            }
            return $item;
        }, $row);

        $full_data[] = $row;
    }

    fclose($file);

    return [
        'headers' => $header,
        'data' => $full_data,
        'sample_data' => array_slice($full_data, 0, 5), // Keep sample for compatibility
        'delimiter' => $delimiter,
        'total_columns' => count($header),
        'total_rows' => count($full_data)
    ];
}

function process_csv_file_with_mapping($file_path, $column_mapping, $current_user_rep_id, $wpdb) {
    $debug_info = [
        'total_policies' => 0,
        'processed_policies' => 0,
        'processed_customers' => 0,
        'matched_customers' => 0,
        'failed_matches' => 0,
        'last_error' => '',
        'process_start' => date('Y-m-d H:i:s'),
        'invalid_rows' => [],
        'data_size' => 0,
        'manual_selection_needed' => []
    ];

    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    $representatives_table = $wpdb->prefix . 'insurance_crm_representatives';

    $file = @fopen($file_path, 'r');
    if (!$file) {
        throw new Exception('CSV dosyası açılamadı: ' . esc_html($file_path));
    }

    $bom = fread($file, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($file);
    }

    $delimiter = isset($column_mapping['delimiter']) ? $column_mapping['delimiter'] : ';';
    $header = fgetcsv($file, 0, $delimiter);
    if ($header === false) {
        fclose($file);
        throw new Exception('CSV başlıkları okunamadı.');
    }

    $preview_data = [
        'customers' => [],
        'policies' => [],
        'debug' => $debug_info,
        'column_mapping' => $column_mapping,
        'original_headers' => $header,
        'invalid_rows' => []
    ];

    $processed_policies = 0;
    $row_index = 1;

    while (($row = fgetcsv($file, 0, $delimiter)) !== false) {
        $row_index++;
        if (empty(array_filter($row, function($val) { return !empty(trim($val)); }))) {
            $debug_info['invalid_rows'][] = "Satır $row_index: Boş satır atlandı.";
            $preview_data['invalid_rows'][] = "Satır $row_index: Boş satır atlandı.";
            continue;
        }

        $row = array_pad($row, count($header), null);

        try {
            $policy_data_list = extract_policy_data($row, $column_mapping, $current_user_rep_id, $wpdb, $customers_table, $representatives_table, $row_index, $debug_info);

            foreach ($policy_data_list as $policy_data) {
                $customer_key = $policy_data['customer_key'];

                if (!isset($preview_data['customers'][$customer_key])) {
                    $preview_data['customers'][$customer_key] = $policy_data['customer'];
                }

                $preview_data['policies'][] = $policy_data['policy'];
                $processed_policies++;
            }
        } catch (Exception $e) {
            $debug_info['failed_matches']++;
            $debug_info['last_error'] = $e->getMessage();
            $debug_info['invalid_rows'][] = "Satır $row_index: " . esc_html($e->getMessage());
            $preview_data['invalid_rows'][] = "Satır $row_index: " . esc_html($e->getMessage());
            continue;
        }

        $debug_info['data_size'] += strlen(json_encode($row, JSON_UNESCAPED_UNICODE));
    }

    fclose($file);

    $debug_info['total_policies'] = $row_index - 1;
    $debug_info['processed_policies'] = $processed_policies;
    $debug_info['processed_customers'] = count($preview_data['customers']);
    $preview_data['debug'] = $debug_info;

    if (!empty($debug_info['manual_selection_needed'])) {
        // Manuel seçim ekranına yönlendir
        display_manual_selection_form($debug_info['manual_selection_needed'], $wpdb, $file_path, $column_mapping);
        exit;
    }

    return $preview_data;
}

function display_manual_selection_form($manual_selection_needed, $wpdb, $file_path, $column_mapping) {
    echo '<div class="ab-crm-container">';
    echo '<h2>Müşteri Temsilcisi ve Satış Temsilcisi Eşleştirme</h2>';
    echo '<p>Bazı müşteri temsilcileri ve satış temsilcileri veritabanında bulunamadı. Lütfen aşağıdaki listeden uygun eşleştirmeleri seçin.</p>';

    $representatives = $wpdb->get_results("SELECT r.id, u.display_name FROM {$wpdb->prefix}insurance_crm_representatives r JOIN $wpdb->users u ON r.user_id = u.ID WHERE r.status = 'active'");
    $users = $wpdb->get_results("SELECT ID, display_name FROM $wpdb->users");

    echo '<form method="post" id="manual-selection-form" class="ab-filter-form">';
    wp_nonce_field('csv_manual_selection_action', 'csv_manual_selection_nonce');
    echo '<input type="hidden" name="temp_csv_file" value="' . esc_attr($file_path) . '">';
    echo '<input type="hidden" name="column_mapping" value="' . esc_attr(serialize($column_mapping)) . '">';

    foreach ($manual_selection_needed as $index => $item) {
        echo '<div class="ab-filter-row">';
        echo '<div class="ab-filter-col">';
        echo '<label>' . esc_html($item['type'] === 'rep' ? 'Müşteri Temsilcisi' : 'Satış Temsilcisi') . ': ' . esc_html($item['name']) . '</label>';

        if ($item['type'] === 'rep') {
            echo '<select name="manual_rep[' . esc_attr($index) . '][id]" class="ab-select" required>';
            echo '<option value="">-- Temsilci Seçin --</option>';
            foreach ($representatives as $rep) {
                echo '<option value="' . esc_attr($rep->id) . '">' . esc_html($rep->display_name) . '</option>';
            }
            echo '</select>';
            echo '<input type="hidden" name="manual_rep[' . esc_attr($index) . '][name]" value="' . esc_attr($item['name']) . '">';
        } else {
            echo '<select name="manual_user[' . esc_attr($index) . '][id]" class="ab-select" required>';
            echo '<option value="">-- Kullanıcı Seçin --</option>';
            foreach ($users as $user) {
                echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($user->display_name) . '</option>';
            }
            echo '</select>';
            echo '<input type="hidden" name="manual_user[' . esc_attr($index) . '][name]" value="' . esc_attr($item['name']) . '">';
        }

        echo '</div>';
        echo '</div>';
    }

    echo '<div class="ab-filter-row">';
    echo '<div class="ab-filter-col ab-button-col">';
    echo '<button type="submit" name="submit_manual_selection" class="ab-btn ab-btn-filter">Seçimleri Onayla</button>';
    echo '<a href="?view=iceri_aktarim_new&type=csv" class="ab-btn ab-btn-reset">İptal</a>';
    echo '</div>';
    echo '</div>';
    echo '</form>';
    echo '</div>';

    // CSS ve JS (önceki iceri_aktarim_new.php'den alınmış)
    echo '<style>';
    echo file_get_contents(dirname(__FILE__) . '/iceri_aktarim_new.php', false, null, strpos(file_get_contents(dirname(__FILE__) . '/iceri_aktarim_new.php'), '<style>'), strpos(file_get_contents(dirname(__FILE__) . '/iceri_aktarim_new.php'), '</style>') - strpos(file_get_contents(dirname(__FILE__) . '/iceri_aktarim_new.php'), '<style>') + 8);
    echo '</style>';
}

function extract_policy_data($row, $mapping, $current_user_rep_id, $wpdb, $customers_table, $representatives_table, $row_index, &$debug_info) {
    // Initialize warnings array if not exists
    if (!isset($debug_info['warnings'])) {
        $debug_info['warnings'] = [];
    }
    $policy_number = isset($mapping['police_no']) && isset($row[$mapping['police_no']]) ? trim($row[$mapping['police_no']]) : null;
    $first_name = isset($mapping['ad']) && isset($row[$mapping['ad']]) ? trim($row[$mapping['ad']]) : null;
    $last_name = isset($mapping['soyad']) && isset($row[$mapping['soyad']]) ? trim($row[$mapping['soyad']]) : null;
    $tc_kimlik = isset($mapping['tc_kimlik']) && isset($row[$mapping['tc_kimlik']]) ? trim($row[$mapping['tc_kimlik']]) : null;
    $phone = isset($mapping['telefon']) && isset($row[$mapping['telefon']]) ? trim($row[$mapping['telefon']]) : null;
    $email = isset($mapping['email']) && isset($row[$mapping['email']]) ? trim($row[$mapping['email']]) : null;
    $address = isset($mapping['adres']) && isset($row[$mapping['adres']]) ? trim($row[$mapping['adres']]) : null;
    $birth_date = isset($mapping['dogum_tarih']) && isset($row[$mapping['dogum_tarih']]) ? trim($row[$mapping['dogum_tarih']]) : null;
    $policy_type = isset($mapping['police_turu']) && isset($row[$mapping['police_turu']]) ? trim($row[$mapping['police_turu']]) : 'TSS';
    $insurance_company = isset($mapping['sigorta_sirketi']) && isset($row[$mapping['sigorta_sirketi']]) ? trim($row[$mapping['sigorta_sirketi']]) : 'Belirtilmemiş';
    $start_date = isset($mapping['baslangic_tarih']) && isset($row[$mapping['baslangic_tarih']]) ? trim($row[$mapping['baslangic_tarih']]) : null;
    $end_date = isset($mapping['bitis_tarih']) && isset($row[$mapping['bitis_tarih']]) ? trim($row[$mapping['bitis_tarih']]) : null;
    $premium_amount = isset($mapping['prim_tutari']) && isset($row[$mapping['prim_tutari']]) ? trim($row[$mapping['prim_tutari']]) : '0';
    $insured_party = isset($mapping['sigorta_ettiren']) && isset($row[$mapping['sigorta_ettiren']]) ? trim($row[$mapping['sigorta_ettiren']]) : null;
    $network = isset($mapping['network']) && isset($row[$mapping['network']]) ? trim($row[$mapping['network']]) : null;
    $status_note = isset($mapping['status_note']) && isset($row[$mapping['status_note']]) ? trim($row[$mapping['status_note']]) : null;
    $payment_type = isset($mapping['odeme_tipi']) && isset($row[$mapping['odeme_tipi']]) ? trim($row[$mapping['odeme_tipi']]) : null;
    $policy_status = isset($mapping['durum']) && isset($row[$mapping['durum']]) ? trim($row[$mapping['durum']]) : null;
    $created_at = isset($mapping['baslangic_tarih']) && isset($row[$mapping['baslangic_tarih']]) ? trim($row[$mapping['baslangic_tarih']]) : null;
    $occupation = isset($mapping['meslek']) && isset($row[$mapping['meslek']]) ? trim($row[$mapping['meslek']]) : null;
    
    // Business type processing
    $business_type = 'Yeni İş'; // Default to new business
    if (isset($mapping['is_new_business']) && isset($row[$mapping['is_new_business']])) {
        $business_value = strtolower(trim($row[$mapping['is_new_business']]));
        if (in_array($business_value, ['yenileme', 'renewal', '0', 'renew'])) {
            $business_type = 'Yenileme';
        } elseif (in_array($business_value, ['yeni', 'new', '1', 'yeni iş', 'new business'])) {
            $business_type = 'Yeni İş';
        }
    }

    $debug_data = [
        'row_index' => $row_index,
        'policy_number' => $policy_number,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'tc_kimlik' => $tc_kimlik,
        'mapping' => $mapping
    ];

    if (is_null($policy_number) || $policy_number === '') {
        // Generate a temporary policy number if none exists
        $policy_number = 'TEMP_' . time() . '_' . $row_index;
        error_log("Poliçe numarası boş, geçici numara atandı: " . $policy_number);
    }
    
    // Allow processing even if customer name is missing - create a placeholder
    if ((is_null($first_name) || $first_name === '') && (is_null($last_name) || $last_name === '')) {
        if (!is_null($insured_party) && $insured_party !== '') {
            // Use insured party name if available
            $name_parts = explode(' ', trim($insured_party), 2);
            $first_name = $name_parts[0];
            $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
        } else {
            // Create placeholder name
            $first_name = 'İsimsiz';
            $last_name = 'Müşteri_' . $row_index;
            error_log("Müşteri adı boş, placeholder oluşturuldu: " . $first_name . ' ' . $last_name);
        }
    }

    $rep_id = $current_user_rep_id; // Use current user's rep ID
    $first_recorder_user_id = get_current_user_id(); // Use current WordPress user ID

    $first_name = is_string($first_name) ? mb_convert_encoding($first_name, 'UTF-8', ['UTF-8', 'ISO-8859-9', 'Windows-1254', 'ASCII']) : $first_name;
    $last_name = is_string($last_name) ? mb_convert_encoding($last_name, 'UTF-8', ['UTF-8', 'ISO-8859-9', 'Windows-1254', 'ASCII']) : $last_name;
    $address = is_string($address) ? mb_convert_encoding($address, 'UTF-8', ['UTF-8', 'ISO-8859-9', 'Windows-1254', 'ASCII']) : $address;
    $policy_type = is_string($policy_type) ? mb_convert_encoding($policy_type, 'UTF-8', ['UTF-8', 'ISO-8859-9', 'Windows-1254', 'ASCII']) : $policy_type;
    $insurance_company = is_string($insurance_company) ? mb_convert_encoding($insurance_company, 'UTF-8', ['UTF-8', 'ISO-8859-9', 'Windows-1254', 'ASCII']) : $insurance_company;
    $insured_party = is_string($insured_party) ? mb_convert_encoding($insured_party, 'UTF-8', ['UTF-8', 'ISO-8859-9', 'Windows-1254', 'ASCII']) : $insured_party;
    $network = is_string($network) ? mb_convert_encoding($network, 'UTF-8', ['UTF-8', 'ISO-8859-9', 'Windows-1254', 'ASCII']) : $network;
    $status_note = is_string($status_note) ? mb_convert_encoding($status_note, 'UTF-8', ['UTF-8', 'ISO-8859-9', 'Windows-1254', 'ASCII']) : $status_note;
    $payment_type = is_string($payment_type) ? mb_convert_encoding($payment_type, 'UTF-8', ['UTF-8', 'ISO-8859-9', 'Windows-1254', 'ASCII']) : $payment_type;
    $policy_status = is_string($policy_status) ? mb_convert_encoding($policy_status, 'UTF-8', ['UTF-8', 'ISO-8859-9', 'Windows-1254', 'ASCII']) : $policy_status;

    if (preg_match('/^[\d\.]+E\+[\d]+$/', $policy_number)) {
        $policy_number = number_format((float)$policy_number, 0, '', '');
    }

    $policy_number = preg_replace('/[^\w\-]/', '', $policy_number);

    // Enhanced multiple person separation logic
    $people_data = [];
    
    // Parse names (can be separated by -)
    $names = [];
    if (!is_null($insured_party) && strpos($insured_party, '-') !== false) {
        $names = array_filter(array_map('trim', explode('-', $insured_party)));
        if (count($names) > 1) {
            $debug_info['warnings'][] = "Satır $row_index: Sigortalı '{$insured_party}' birden fazla kişi içeriyor, ayrı müşteriler oluşturulacak";
        }
    } elseif (!is_null($first_name) && strpos($first_name, '-') !== false) {
        $names = array_filter(array_map('trim', explode('-', $first_name)));
        if (count($names) > 1) {
            $debug_info['warnings'][] = "Satır $row_index: Ad Soyad '{$first_name}' birden fazla kişi içeriyor, ayrı müşteriler oluşturulacak";
        }
    } else {
        $names = [$first_name . ' ' . $last_name];
    }
    
    // **FIX**: Enhanced TC number parsing for multiple people detection
    $tc_kimlik_list = [];
    if (!is_null($tc_kimlik) && $tc_kimlik !== '') {
        // Check for 11+ digit numbers which indicate multiple TCs
        if (strlen(preg_replace('/[^0-9]/', '', $tc_kimlik)) > 11) {
            // Multiple TC numbers detected - could be concatenated or separated
            if (strpos($tc_kimlik, '-') !== false) {
                // Separated by dash
                $tc_kimlik_list = array_filter(array_map('trim', explode('-', $tc_kimlik)));
            } elseif (strpos($tc_kimlik, '/') !== false) {
                // Separated by slash  
                $tc_kimlik_list = array_filter(array_map('trim', explode('/', $tc_kimlik)));
            } else {
                // Concatenated without separator - try to split by 11-digit chunks
                $tc_clean = preg_replace('/[^0-9]/', '', $tc_kimlik);
                if (strlen($tc_clean) === 22 && strlen($tc_clean) % 11 === 0) {
                    // Exactly two 11-digit numbers
                    $tc_kimlik_list = [
                        substr($tc_clean, 0, 11),
                        substr($tc_clean, 11, 11)
                    ];
                } elseif (strlen($tc_clean) > 11) {
                    // Take first 11 digits and log a warning
                    $tc_kimlik_list = [substr($tc_clean, 0, 11)];
                    $debug_info['warnings'][] = "Satır $row_index: TC Kimlik '$tc_kimlik' 11 haneden uzun, ilk 11 hane kullanıldı";
                } else {
                    $tc_kimlik_list = [$tc_clean];
                }
            }
            
            if (count($tc_kimlik_list) > 1) {
                $debug_info['warnings'][] = "Satır $row_index: TC Kimlik '{$tc_kimlik}' birden fazla değer içeriyor, her müşteriye sırasıyla atanacak";
            }
        } else {
            // Single TC or invalid format
            $tc_kimlik_list = [preg_replace('/[^0-9]/', '', $tc_kimlik)];
        }
    }
    
    // Parse birth dates (can be separated by -)
    $birth_dates = [];
    if (!is_null($birth_date) && $birth_date !== '') {
        if (strpos($birth_date, '-') !== false) {
            $birth_dates = array_filter(array_map('trim', explode('-', $birth_date)));
            if (count($birth_dates) > 1) {
                $debug_info['warnings'][] = "Satır $row_index: Doğum Tarihi '{$birth_date}' birden fazla değer içeriyor, her müşteriye sırasıyla atanacak";
            }
        } else {
            $birth_dates = array_filter(array_map('trim', explode('/', $birth_date))); // Keep old logic as fallback
            if (count($birth_dates) > 1) {
                $debug_info['warnings'][] = "Satır $row_index: Doğum Tarihi '{$birth_date}' birden fazla değer içeriyor (/ ile ayrılmış), her müşteriye sırasıyla atanacak";
            }
        }
    }
    
    // Parse phone numbers (can be separated by -)
    $phone_list = [];
    if (!is_null($phone) && $phone !== '') {
        if (strpos($phone, '-') !== false) {
            $phone_list = array_filter(array_map('trim', explode('-', $phone)));
            if (count($phone_list) > 1) {
                $debug_info['warnings'][] = "Satır $row_index: Telefon '{$phone}' birden fazla değer içeriyor, her müşteriye sırasıyla atanacak";
            }
        } else {
            $phone_list = [$phone]; // Single phone number
        }
    }
    
    // TC is optional - if no TC, still allow processing
    if (empty($tc_kimlik_list)) {
        $tc_kimlik_list = ['']; // At least one empty TC to create one customer
        error_log("TC kimlik numarası yok, boş değer ile devam ediliyor. Satır: " . $row_index);
    }
    
    // Create person data for each TC number
    $max_people = max(count($names), count($tc_kimlik_list), count($birth_dates), count($phone_list));
    for ($i = 0; $i < $max_people; $i++) {
        $person_name = isset($names[$i]) ? $names[$i] : (isset($names[0]) ? $names[0] : '');
        $person_tc = isset($tc_kimlik_list[$i]) ? $tc_kimlik_list[$i] : (isset($tc_kimlik_list[0]) ? $tc_kimlik_list[0] : '');
        $person_birth_date = isset($birth_dates[$i]) ? $birth_dates[$i] : (isset($birth_dates[0]) ? $birth_dates[0] : '');
        $person_phone = isset($phone_list[$i]) ? $phone_list[$i] : (isset($phone_list[0]) ? $phone_list[0] : '');
        
        // **FIX**: Ensure TC identity is within database field limits (varchar(11))
        if (!empty($person_tc)) {
            $person_tc = preg_replace('/[^0-9]/', '', $person_tc); // Remove non-numeric characters
            if (strlen($person_tc) > 11) {
                $person_tc = substr($person_tc, 0, 11); // Truncate to 11 characters
                $debug_info['warnings'][] = "Satır $row_index: TC Kimlik kesildi, ilk 11 hanesi kullanıldı: $person_tc";
            }
        }
        
        // **FIX**: Ensure phone is exactly 10 digits (Turkish mobile format)
        if (!empty($person_phone)) {
            $person_phone = preg_replace('/[^0-9]/', '', $person_phone); // Keep only numbers
            if (strlen($person_phone) > 10) {
                $person_phone = substr($person_phone, -10); // Take last 10 digits
                $debug_info['warnings'][] = "Satır $row_index: Telefon 10 haneli format için son 10 hane kullanıldı: $person_phone";
            }
        }
        
        // Split name into first and last name
        $name_parts = explode(' ', trim($person_name), 2);
        $person_first_name = $name_parts[0];
        $person_last_name = isset($name_parts[1]) ? $name_parts[1] : '';
        
        $people_data[] = [
            'first_name' => $person_first_name,
            'last_name' => $person_last_name,
            'tc_kimlik' => $person_tc,
            'birth_date' => format_date_for_db($person_birth_date),
            'phone' => $person_phone, // Each person can have their own phone
            'email' => $i === 0 ? $email : '', // Only first person gets email
            'address' => $i === 0 ? $address : '', // Only first person gets address
            'occupation' => $occupation, // All people can have the same occupation
            'representative_id' => $rep_id,
            'customer_representative' => $musteri_temsilcisi,
            'created_by_user' => $satis_temsilcisi,
            'registration_date' => $created_at,
            'row_index' => $row_index
        ];
    }

    // Clean phone numbers to exactly 10 digits
    foreach ($people_data as &$person) {
        if (!empty($person['phone'])) {
            $person['phone'] = preg_replace('/[^0-9]/', '', $person['phone']);
            if (strlen($person['phone']) > 10) {
                $person['phone'] = substr($person['phone'], -10); // Take last 10 digits
            }
        }
    }

    // Format dates
    $start_date = format_date_for_db($start_date);
    $end_date = format_date_for_db($end_date);
    $created_at = format_date_for_db($created_at);

    if (empty($end_date) && !empty($start_date)) {
        $end_date = date('Y-m-d', strtotime($start_date . ' + 1 year'));
    }

    $premium_amount = parse_amount($premium_amount);
    $status = 'aktif'; // Default status for all policies

    // Gelişmiş veri doğrulama
    $validation_errors = [];
    
    // TC Kimlik doğrulama for all people
    foreach ($people_data as $person_index => $person) {
        if (!empty($person['tc_kimlik']) && function_exists('validate_tc_kimlik')) {
            if (!validate_tc_kimlik($person['tc_kimlik'])) {
                $validation_errors[] = "Geçersiz TC Kimlik: {$person['tc_kimlik']} (Kişi " . ($person_index + 1) . ")";
            }
        }
        
        // Birth date validation for each person
        if (!empty($person['birth_date']) && function_exists('validate_and_normalize_date')) {
            $birth_validation = validate_and_normalize_date($person['birth_date']);
            if (!$birth_validation['valid']) {
                $validation_errors[] = "Geçersiz doğum tarihi: {$person['birth_date']} (Kişi " . ($person_index + 1) . ")";
            } else {
                $people_data[$person_index]['birth_date'] = $birth_validation['date']; // Normalize
            }
        }
    }
    
    // Telefon numarası doğrulama
    if (!empty($phone) && function_exists('validate_phone_number')) {
        if (!validate_phone_number($phone)) {
            $validation_errors[] = "Geçersiz telefon numarası: $phone";
        }
    }
    
    // Tarih doğrulama (start_date ve end_date)
    if (!empty($start_date) && function_exists('validate_and_normalize_date')) {
        $start_validation = validate_and_normalize_date($start_date);
        if (!$start_validation['valid']) {
            $validation_errors[] = "Geçersiz başlangıç tarihi: $start_date";
        } else {
            $start_date = $start_validation['date'];
        }
    }
    
    if (!empty($end_date) && function_exists('validate_and_normalize_date')) {
        $end_validation = validate_and_normalize_date($end_date);
        if (!$end_validation['valid']) {
            $validation_errors[] = "Geçersiz bitiş tarihi: $end_date";
        } else {
            $end_date = $end_validation['date'];
        }
    }
    
    // Para birimi doğrulama
    if (!empty($premium_amount) && function_exists('validate_and_normalize_currency')) {
        $currency_validation = validate_and_normalize_currency($premium_amount);
        if (!$currency_validation['valid']) {
            $validation_errors[] = "Geçersiz prim tutarı: $premium_amount";
        } else {
            $premium_amount = $currency_validation['amount'];
        }
    }
    
    // Email doğrulama (eğer email alanı varsa)
    if (isset($mapping['email']) && isset($row[$mapping['email']])) {
        $email = trim($row[$mapping['email']]);
        if (!empty($email) && function_exists('validate_email_format')) {
            if (!validate_email_format($email)) {
                $validation_errors[] = "Geçersiz email adresi: $email";
            }
        }
    }
    
    // Doğrulama hatası varsa exception fırlat
    if (!empty($validation_errors)) {
        // Hata loglarını kaydet (eğer session_id varsa)
        if (function_exists('log_import_validation_error')) {
            $session_id = uniqid('import_'); // Bu gerçek implementasyonda dışarıdan gelecek
            foreach ($validation_errors as $error) {
                if (strpos($error, 'TC Kimlik') !== false) {
                    log_import_validation_error($session_id, $row_index, 'tc_kimlik', implode(',', $tc_kimlik_list), $error, 'tc_kimlik_invalid');
                } elseif (strpos($error, 'telefon') !== false) {
                    log_import_validation_error($session_id, $row_index, 'telefon', $phone, $error, 'phone_invalid');
                } elseif (strpos($error, 'email') !== false) {
                    log_import_validation_error($session_id, $row_index, 'email', $email ?? '', $error, 'email_invalid');
                } elseif (strpos($error, 'tarih') !== false) {
                    $date_field = 'dogum_tarih';
                    if (strpos($error, 'başlangıç') !== false) $date_field = 'baslangic_tarih';
                    if (strpos($error, 'bitiş') !== false) $date_field = 'bitis_tarih';
                    log_import_validation_error($session_id, $row_index, $date_field, ${str_replace('_tarih', '_date', $date_field)} ?? '', $error, 'date_invalid');
                } elseif (strpos($error, 'prim') !== false) {
                    log_import_validation_error($session_id, $row_index, 'prim_tutari', $premium_amount, $error, 'currency_invalid');
                }
            }
        }
        
        throw new Exception("Veri doğrulama hataları: " . implode(', ', $validation_errors));
    }

    $policy_data_list = [];
    $primary_customer_key = null;

    foreach ($people_data as $index => $person) {
        $customer_id = null;
        if (!empty($person['tc_kimlik'])) {
            $customer_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $customers_table WHERE tc_identity = %s",
                $person['tc_kimlik']
            ));
        }

        if (!$customer_id && !empty($person['first_name']) && !empty($person['last_name'])) {
            if (!empty($person['phone'])) {
                $customer_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $customers_table WHERE first_name = %s AND last_name = %s AND phone = %s",
                    $person['first_name'], $person['last_name'], $person['phone']
                ));
            } else {
                $customer_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $customers_table WHERE first_name = %s AND last_name = %s",
                    $person['first_name'], $person['last_name']
                ));
            }
        }

        $customer_status = $customer_id ? 'Mevcut' : 'Yeni';

        $customer_key = md5(($person['tc_kimlik'] ?: '') . ($person['first_name'] ?: '') . ($person['last_name'] ?: '') . ($person['phone'] ?: '') . $index);
        
        // The first customer becomes the primary one for the policy
        if ($index === 0) {
            $primary_customer_key = $customer_key;
        }

        $policy_data_list[] = [
            'customer_key' => $customer_key,
            'is_primary' => ($index === 0), // Mark first customer as primary
            'customer' => [
                'first_name' => $person['first_name'] ?: 'Bilinmeyen',
                'last_name' => $person['last_name'] ?: '',
                'phone' => $person['phone'] ?: '',
                'email' => $person['email'] ?: '',
                'address' => $person['address'] ?: '',
                'tc_kimlik' => $person['tc_kimlik'] ?: '',
                'birth_date' => $person['birth_date'],
                'status' => $customer_status,
                'customer_id' => $customer_id,
                'representative_id' => $rep_id,
                'first_recorder' => $first_recorder_user_id,
                'created_at' => $created_at,
                'occupation' => $occupation ?: '', // Add occupation field
                'registration_date' => $created_at,
                'row_index' => $row_index
            ],
            // Only include policy data for the primary customer to avoid duplicates
            'policy' => ($index === 0) ? [
                'policy_number' => $policy_number,
                'customer_key' => $primary_customer_key, // Always link to primary customer
                'policy_type' => $policy_type,
                'insurance_company' => $insurance_company,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'premium_amount' => $premium_amount,
                'insured_party' => $insured_party ?: '',
                'status' => $status,
                'network' => $network ?: '',
                'payment_type' => $payment_type ?: '',
                'policy_status' => $policy_status ?: '',
                'status_note' => $status_note ?: '',
                'business_type' => $business_type ?: 'Yeni İş',
                'representative_id' => $rep_id,
                'first_recorder' => $first_recorder_user_id,
                'row_index' => $row_index
            ] : null
        ];
    }

    return $policy_data_list;
}

function format_date_for_db($date) {
    if (empty($date) || trim($date) === '') {
        return null; // **FIX**: Return null instead of current date for empty dates
    }

    $date = trim($date);
    
    // Enhanced Turkish date handling with more formats
    $month_map = [
        'ocak' => '01', 'şubat' => '02', 'mart' => '03', 'nisan' => '04', 'mayıs' => '05', 'haziran' => '06',
        'temmuz' => '07', 'ağustos' => '08', 'eylül' => '09', 'ekim' => '10', 'kasım' => '11', 'aralık' => '12',
        'oca' => '01', 'şub' => '02', 'mar' => '03', 'nis' => '04', 'may' => '05', 'haz' => '06',
        'tem' => '07', 'ağu' => '08', 'eyl' => '09', 'eki' => '10', 'kas' => '11', 'ara' => '12',
        'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04', 'may' => '05', 'jun' => '06',
        'jul' => '07', 'aug' => '08', 'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12'
    ];

    foreach ($month_map as $name => $num) {
        $date = preg_replace("/\b$name\b/i", $num, $date);
    }

    // Try various date formats - Handle dot, slash, and dash separators
    
    // DD.MM.YYYY format (Turkish standard)
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $date, $matches)) {
        $day = sprintf('%02d', $matches[1]);
        $month = sprintf('%02d', $matches[2]);
        $year = $matches[3];
        if (checkdate($month, $day, $year)) {
            return "$year-$month-$day";
        }
    }
    
    // DD/MM/YYYY format  
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date, $matches)) {
        $day = sprintf('%02d', $matches[1]);
        $month = sprintf('%02d', $matches[2]);
        $year = $matches[3];
        if (checkdate($month, $day, $year)) {
            return "$year-$month-$day";
        }
    }

    // DD-MM-YYYY format
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{2,4})$/', $date, $matches)) {
        $day = sprintf('%02d', $matches[1]);
        $month = sprintf('%02d', $matches[2]);
        $year = $matches[3];

        if (strlen($year) == 2) {
            $year = $year < 50 ? "20$year" : "19$year";
        }

        if (checkdate($month, $day, $year)) {
            return "$year-$month-$day";
        }
    }

    // YYYY-MM-DD format (SQL standard)
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $matches)) {
        $year = $matches[1];
        $month = sprintf('%02d', $matches[2]);
        $day = sprintf('%02d', $matches[3]);

        if (checkdate($month, $day, $year)) {
            return "$year-$month-$day";
        }
    }

    // Last resort: try PHP's strtotime for other formats
    $timestamp = strtotime($date);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }

    // **FIX**: Log the failed date conversion and return null instead of current date
    error_log("Date conversion failed for: '$date' - returning null instead of current date");
    return null;
}

function parse_amount($amount) {
    if (empty($amount)) {
        return 0.0;
    }

    $amount = preg_replace('/[^\d\.,\-]/', '', $amount);
    $amount = str_replace(' ', '', $amount);

    if (strpos($amount, ',') !== false && strpos($amount, '.') !== false) {
        $amount = str_replace('.', '', $amount);
        $amount = str_replace(',', '.', $amount);
    } elseif (strpos($amount, ',') !== false) {
        $amount = str_replace(',', '.', $amount);
    }

    if (!is_numeric($amount)) {
        return 0.0;
    }

    return (float)$amount;
}
?>
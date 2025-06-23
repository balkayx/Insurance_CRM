<?php
/**
 * Facebook CSV İçe Aktarım Fonksiyonları
 * UTF-16 LE formatındaki Facebook CSV dosyasını işleyerek müşteri ve görev oluşturur
 * @version 1.0.0
 * @date 2025-06-15
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Facebook CSV dosyasını işle ve müşteri/görev verilerini oluştur
 * 
 * @param string $file_path CSV dosya yolu
 * @param int $assign_to_rep_id Müşteri ve görevlerin atanacağı temsilci ID'si
 * @param object $wpdb WordPress veritabanı nesnesi
 * @return array İşlem sonucu
 */
function process_facebook_csv($file_path, $assign_to_rep_id, $wpdb) {
    $result = [
        'success' => false,
        'message' => '',
        'customers_created' => 0,
        'tasks_created' => 0,
        'notes_created' => 0,
        'errors' => []
    ];

    try {
        // CSV dosyasını oku ve temizle
        $csv_data = read_facebook_csv($file_path);
        
        if (empty($csv_data['data'])) {
            $result['message'] = 'CSV dosyasında veri bulunamadı.';
            return $result;
        }

        // Veritabanı tablolarını tanımla
        $customers_table = $wpdb->prefix . 'insurance_crm_customers';
        $tasks_table = $wpdb->prefix . 'insurance_crm_tasks';
        $interactions_table = $wpdb->prefix . 'insurance_crm_interactions';

        // Her CSV satırını işle
        foreach ($csv_data['data'] as $row_index => $row) {
            try {
                // CSV verilerini map et (row_index'i geç)
                $customer_data = map_facebook_row_to_customer($row, $csv_data['headers'], $row_index);
                
                // Sadece ad soyad kontrolü yap - TC otomatik oluşturulur
                if (empty($customer_data['full_name'])) {
                    $result['errors'][] = "Satır " . ($row_index + 2) . ": Ad soyad eksik";
                    continue;
                }

                // Müşteriyi oluştur veya güncelle
                $customer_id = create_or_update_customer($customer_data, $assign_to_rep_id, $wpdb);
                
                if ($customer_id) {
                    $result['customers_created']++;
                    
                    // Görev oluştur
                    if (create_facebook_task($customer_id, $customer_data, $assign_to_rep_id, $wpdb)) {
                        $result['tasks_created']++;
                    }
                    
                    // Kampanya notunu ekle
                    if (create_campaign_note($customer_id, $customer_data, $assign_to_rep_id, $wpdb)) {
                        $result['notes_created']++;
                    }
                } else {
                    $result['errors'][] = "Satır " . ($row_index + 2) . ": Müşteri oluşturulamadı";
                }
                
            } catch (Exception $e) {
                $result['errors'][] = "Satır " . ($row_index + 2) . ": " . $e->getMessage();
                error_log('Facebook CSV row processing error: ' . $e->getMessage());
            }
        }

        $result['success'] = true;
        $result['message'] = 'İşlem tamamlandı.';
        
        if (!empty($result['errors'])) {
            $result['message'] .= ' ' . count($result['errors']) . ' hata ile karşılaşıldı.';
        }

    } catch (Exception $e) {
        $result['message'] = 'CSV işleme hatası: ' . $e->getMessage();
        error_log('Facebook CSV processing error: ' . $e->getMessage());
    }

    return $result;
}

/**
 * Facebook CSV dosyasını temsilci atamaları ile işle
 * 
 * @param string $file_path CSV dosya yolu
 * @param array $csv_data Önceden yüklenmiş CSV verisi (opsiyonel)
 * @param array $row_assignments Satır bazında temsilci atamaları
 * @param int $default_assignment Varsayılan temsilci ID'si
 * @param object $wpdb WordPress veritabanı nesnesi
 * @return array İşlem sonucu
 */
function process_facebook_csv_with_assignments($file_path, $csv_data, $row_assignments, $default_assignment, $wpdb) {
    $result = [
        'success' => false,
        'message' => '',
        'customers_created' => 0,
        'tasks_created' => 0,
        'notes_created' => 0,
        'errors' => []
    ];

    try {
        // CSV dosyasını oku (eğer önceden yüklenmemişse)
        if (!$csv_data) {
            $csv_data = read_facebook_csv($file_path);
        }
        
        if (empty($csv_data['data'])) {
            $result['message'] = 'CSV dosyasında veri bulunamadı.';
            return $result;
        }

        // Veritabanı tablolarını tanımla
        $customers_table = $wpdb->prefix . 'insurance_crm_customers';
        $tasks_table = $wpdb->prefix . 'insurance_crm_tasks';
        $interactions_table = $wpdb->prefix . 'insurance_crm_interactions';

        // Her CSV satırını işle
        foreach ($csv_data['data'] as $row_index => $row) {
            try {
                // CSV verilerini map et (row_index'i geç)
                $customer_data = map_facebook_row_to_customer($row, $csv_data['headers'], $row_index);
                
                // Sadece ad soyad kontrolü yap - TC otomatik oluşturulur
                if (empty($customer_data['full_name'])) {
                    $result['errors'][] = "Satır " . ($row_index + 2) . ": Ad soyad eksik";
                    continue;
                }

                // Bu satır için temsilci belirle
                $assign_to_rep_id = $default_assignment; // Varsayılan
                
                // Satır bazında atama varsa onu kullan
                if (isset($row_assignments[$row_index]) && !empty($row_assignments[$row_index])) {
                    $assign_to_rep_id = intval($row_assignments[$row_index]);
                }

                // Müşteriyi oluştur veya güncelle
                $customer_id = create_or_update_customer($customer_data, $assign_to_rep_id, $wpdb);
                
                if ($customer_id) {
                    $result['customers_created']++;
                    
                    // Görev oluştur
                    if (create_facebook_task($customer_id, $customer_data, $assign_to_rep_id, $wpdb)) {
                        $result['tasks_created']++;
                    }
                    
                    // Kampanya notunu ekle
                    if (create_campaign_note($customer_id, $customer_data, $assign_to_rep_id, $wpdb)) {
                        $result['notes_created']++;
                    }
                } else {
                    $result['errors'][] = "Satır " . ($row_index + 2) . ": Müşteri oluşturulamadı";
                }
                
            } catch (Exception $e) {
                $result['errors'][] = "Satır " . ($row_index + 2) . ": " . $e->getMessage();
                error_log('Facebook CSV row processing error: ' . $e->getMessage());
            }
        }

        $result['success'] = true;
        $result['message'] = 'İşlem tamamlandı.';
        
        if (!empty($result['errors'])) {
            $result['message'] .= ' ' . count($result['errors']) . ' hata ile karşılaşıldı.';
        }

    } catch (Exception $e) {
        $result['message'] = 'CSV işleme hatası: ' . $e->getMessage();
        error_log('Facebook CSV processing error: ' . $e->getMessage());
    }

    return $result;
}

/**
 * Facebook CSV dosyasını oku ve farklı kodlamaları işle
 * UTF-16 LE, UTF-8 ve diğer yaygın kodlamaları destekler
 * 
 * @param string $file_path Dosya yolu
 * @return array CSV headers ve data
 */
function read_facebook_csv($file_path) {
    if (!file_exists($file_path)) {
        throw new Exception('CSV dosyası bulunamadı: ' . $file_path);
    }

    // Dosyayı binary modda oku
    $raw_content = file_get_contents($file_path);
    if ($raw_content === false) {
        throw new Exception('CSV dosyası okunamadı.');
    }

    $utf8_content = '';
    
    // Farklı BOM'ları kontrol et ve kodlamayı belirle
    if (substr($raw_content, 0, 3) === "\xEF\xBB\xBF") {
        // UTF-8 BOM
        $utf8_content = substr($raw_content, 3);
    } elseif (substr($raw_content, 0, 2) === "\xFF\xFE") {
        // UTF-16 LE BOM
        $raw_content = substr($raw_content, 2);
        $utf8_content = mb_convert_encoding($raw_content, 'UTF-8', 'UTF-16LE');
        if ($utf8_content === false) {
            throw new Exception('UTF-16 LE kodlaması UTF-8\'e çevrilemedi.');
        }
    } elseif (substr($raw_content, 0, 2) === "\xFE\xFF") {
        // UTF-16 BE BOM
        $raw_content = substr($raw_content, 2);
        $utf8_content = mb_convert_encoding($raw_content, 'UTF-8', 'UTF-16BE');
        if ($utf8_content === false) {
            throw new Exception('UTF-16 BE kodlaması UTF-8\'e çevrilemedi.');
        }
    } else {
        // BOM yok, kodlamayı tespit etmeye çalış
        $detected_encoding = mb_detect_encoding($raw_content, ['UTF-8', 'UTF-16LE', 'UTF-16BE', 'ISO-8859-9', 'Windows-1254'], true);
        
        if ($detected_encoding === 'UTF-8') {
            $utf8_content = $raw_content;
        } elseif ($detected_encoding) {
            $utf8_content = mb_convert_encoding($raw_content, 'UTF-8', $detected_encoding);
            if ($utf8_content === false) {
                throw new Exception($detected_encoding . ' kodlaması UTF-8\'e çevrilemedi.');
            }
        } else {
            // Son çare olarak UTF-8 olarak varsay
            $utf8_content = $raw_content;
        }
    }

    // Null byte'ları temizle
    $clean_content = str_replace("\0", '', $utf8_content);

    // Satırlara böl
    $lines = explode("\n", $clean_content);
    $lines = array_map('trim', $lines);
    $lines = array_filter($lines, function($line) {
        return !empty($line);
    });

    if (empty($lines)) {
        throw new Exception('CSV dosyasında veri bulunamadı.');
    }

    // İlk satır başlıklar
    $header_line = array_shift($lines);
    $headers = explode("\t", $header_line);
    $headers = array_map('trim', $headers);

    if (empty($headers)) {
        throw new Exception('CSV başlıkları okunamadı.');
    }

    // Veri satırlarını işle
    $data = [];
    foreach ($lines as $line_num => $line) {
        if (empty($line)) continue;
        
        $row = explode("\t", $line);
        $row = array_map('trim', $row);
        
        // Sütun sayısını başlık sayısına uyarla
        $row = array_pad($row, count($headers), '');
        
        $data[] = $row;
    }

    return [
        'headers' => $headers,
        'data' => $data
    ];
}

/**
 * Facebook CSV satırını müşteri verisine dönüştür
 * TC kimlik numarası yoksa otomatik olarak oluştur
 * 
 * @param array $row CSV satır verisi
 * @param array $headers CSV başlıkları
 * @param int $row_index Satır indexi (TC oluşturmak için)
 * @return array Müşteri verisi
 */
function map_facebook_row_to_customer($row, $headers, $row_index = 0) {
    // Başlık-değer eşleştirmesi yap
    $data = array_combine($headers, $row);
    
    // TC kimlik numarası kontrolü ve otomatik oluşturma
    $tc_identity = trim($data['TC'] ?? '');
    if (empty($tc_identity)) {
        // TC sütunu yoksa veya boşsa, 99 ile başlayan 11 haneli random TC oluştur
        $tc_identity = '99' . str_pad(mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);
    }
    
    // Gerekli alanları map et
    $customer_data = [
        'tc_identity' => $tc_identity,
        'full_name' => trim($data['full_name'] ?? ''),
        'email' => trim($data['email'] ?? ''),
        'phone_number' => trim($data['phone_number'] ?? ''),
        'city' => trim($data['city'] ?? ''),
        'campaign_name' => trim($data['campaign_name'] ?? ''),
        'lead_status' => trim($data['lead_status'] ?? ''),
        'created_time' => trim($data['created_time'] ?? ''),
        'platform' => trim($data['platform'] ?? 'facebook'),
        'raw_data' => $data, // Orijinal veriyi sakla
        'has_real_tc' => !empty(trim($data['TC'] ?? '')) // Gerçek TC olup olmadığını işaretle
    ];

    // Ad soyadı ayır
    if (!empty($customer_data['full_name'])) {
        $name_parts = explode(' ', $customer_data['full_name'], 2);
        $customer_data['first_name'] = $name_parts[0];
        $customer_data['last_name'] = isset($name_parts[1]) ? $name_parts[1] : '';
    }

    // Telefonu temizle
    if (!empty($customer_data['phone_number'])) {
        $phone = preg_replace('/[^\d]/', '', $customer_data['phone_number']);
        if (strlen($phone) == 10) {
            $phone = '0' . $phone;
        }
        $customer_data['phone_clean'] = $phone;
    }

    return $customer_data;
}

/**
 * Müşteriyi oluştur veya güncelle
 * 
 * @param array $customer_data Müşteri verisi
 * @param int $rep_id Temsilci ID
 * @param object $wpdb WordPress DB nesnesi
 * @return int|false Müşteri ID veya false
 */
function create_or_update_customer($customer_data, $rep_id, $wpdb) {
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    
    // TC kimlik ile mevcut müşteriyi kontrol et
    $existing_customer = null;
    if (!empty($customer_data['tc_identity'])) {
        $existing_customer = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $customers_table WHERE tc_identity = %s",
                $customer_data['tc_identity']
            )
        );
    }

    $customer_insert_data = [
        'first_name' => $customer_data['first_name'] ?? '',
        'last_name' => $customer_data['last_name'] ?? '',
        'tc_identity' => $customer_data['tc_identity'],
        'email' => $customer_data['email'] ?? '',
        'phone' => $customer_data['phone_clean'] ?? $customer_data['phone_number'] ?? '',
        'address' => $customer_data['city'] ?? '',
        'category' => 'bireysel',
        'status' => 'aktif',
        'representative_id' => $rep_id,
        'first_recorder' => wp_get_current_user()->display_name ?? 'Facebook Import',
        'updated_at' => current_time('mysql')
    ];

    if ($existing_customer) {
        // Mevcut müşteriyi güncelle
        $update_result = $wpdb->update(
            $customers_table,
            $customer_insert_data,
            ['id' => $existing_customer->id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'],
            ['%d']
        );
        
        return $update_result !== false ? $existing_customer->id : false;
    } else {
        // Yeni müşteri oluştur
        $customer_insert_data['created_at'] = current_time('mysql');
        
        $insert_result = $wpdb->insert(
            $customers_table,
            $customer_insert_data,
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );
        
        return $insert_result !== false ? $wpdb->insert_id : false;
    }
}

/**
 * Facebook müşterisi için görev oluştur
 * 
 * @param int $customer_id Müşteri ID
 * @param array $customer_data Müşteri verisi
 * @param int $rep_id Temsilci ID
 * @param object $wpdb WordPress DB nesnesi
 * @return bool Başarı durumu
 */
function create_facebook_task($customer_id, $customer_data, $rep_id, $wpdb) {
    $tasks_table = $wpdb->prefix . 'insurance_crm_tasks';
    
    // Görev açıklamasını oluştur
    $task_description = "Ad Soyad: " . $customer_data['full_name'] . "\n" .
                       "Telefon numarası: " . $customer_data['phone_number'] . "\n" .
                       "Kampanya: " . $customer_data['campaign_name'];

    // Görev son tarihi: yarın saat 18:00
    $due_date = new DateTime('tomorrow');
    $due_date->setTime(18, 0, 0);
    
    $task_data = [
        'customer_id' => $customer_id,
        'task_title' => 'Facebook Müşterisi aranacak.',
        'task_description' => $task_description,
        'due_date' => $due_date->format('Y-m-d H:i:s'),
        'priority' => 'high',
        'status' => 'pending',
        'representative_id' => $rep_id,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    ];

    $result = $wpdb->insert(
        $tasks_table,
        $task_data,
        ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
    );

    return $result !== false;
}

/**
 * Kampanya bilgisini müşteri notu olarak ekle
 * 
 * @param int $customer_id Müşteri ID
 * @param array $customer_data Müşteri verisi
 * @param int $rep_id Temsilci ID
 * @param object $wpdb WordPress DB nesnesi
 * @return bool Başarı durumu
 */
function create_campaign_note($customer_id, $customer_data, $rep_id, $wpdb) {
    $interactions_table = $wpdb->prefix . 'insurance_crm_interactions';
    
    // Not içeriğini oluştur
    $note_content = "Facebook Kampanya Bilgileri:\n";
    $note_content .= "Kampanya: " . ($customer_data['campaign_name'] ?? 'Bilinmiyor') . "\n";
    $note_content .= "Platform: " . ($customer_data['platform'] ?? 'Facebook') . "\n";
    $note_content .= "Durum: " . ($customer_data['lead_status'] ?? 'Bilinmiyor') . "\n";
    
    if (!empty($customer_data['created_time'])) {
        $note_content .= "Lead Tarihi: " . $customer_data['created_time'] . "\n";
    }
    
    $note_content .= "Otomatik olarak Facebook CSV'den aktarıldı.";

    $interaction_data = [
        'representative_id' => $rep_id,
        'customer_id' => $customer_id,
        'type' => 'note',
        'notes' => $note_content,
        'interaction_date' => current_time('mysql'),
        'created_at' => current_time('mysql')
    ];

    $result = $wpdb->insert(
        $interactions_table,
        $interaction_data,
        ['%d', '%d', '%s', '%s', '%s', '%s']
    );

    return $result !== false;
}
<?php
/**
 * Helpdesk System Functions
 * 
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/includes
 * @author     Anadolu Birlik
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generate unique ticket number
 */
function insurance_crm_generate_ticket_number() {
    $prefix = 'CRM';
    $timestamp = date('ymd');
    $random = sprintf('%04d', rand(0, 9999));
    return $prefix . '-' . $timestamp . '-' . $random;
}

/**
 * Get helpdesk categories
 */
function insurance_crm_get_helpdesk_categories() {
    return array(
        'teknik' => 'Teknik Sorunlar',
        'kullanici' => 'Kullanıcı Sorunları',
        'sistem' => 'Sistem Sorunları',
        'veri' => 'Veri Sorunları',
        'diger' => 'Diğer'
    );
}

/**
 * Get helpdesk priority levels
 */
function insurance_crm_get_helpdesk_priorities() {
    return array(
        'dusuk' => array(
            'label' => 'Düşük',
            'color' => '#28a745',
            'bg_color' => 'rgba(40, 167, 69, 0.1)'
        ),
        'orta' => array(
            'label' => 'Orta',
            'color' => '#ffc107',
            'bg_color' => 'rgba(255, 193, 7, 0.1)'
        ),
        'yuksek' => array(
            'label' => 'Yüksek',
            'color' => '#fd7e14',
            'bg_color' => 'rgba(253, 126, 20, 0.1)'
        ),
        'kritik' => array(
            'label' => 'Kritik',
            'color' => '#dc3545',
            'bg_color' => 'rgba(220, 53, 69, 0.1)'
        )
    );
}

/**
 * Get allowed file types for helpdesk
 */
function insurance_crm_get_helpdesk_allowed_file_types() {
    return array(
        'jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 
        'txt', 'log', 'zip', 'rar'
    );
}

/**
 * Get allowed MIME types for helpdesk
 */
function insurance_crm_get_helpdesk_allowed_mime_types() {
    return array(
        'image/jpeg', 'image/jpg', 'image/png', 'image/gif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
        'application/zip',
        'application/x-rar-compressed'
    );
}

/**
 * Read WordPress debug log
 */
function insurance_crm_get_debug_log($lines = 100) {
    $debug_log_path = WP_CONTENT_DIR . '/debug.log';
    
    if (!file_exists($debug_log_path) || !is_readable($debug_log_path)) {
        return false;
    }
    
    $file = new SplFileObject($debug_log_path);
    $file->seek(PHP_INT_MAX);
    $total_lines = $file->key();
    
    if ($total_lines == 0) {
        return '';
    }
    
    $start_line = max(0, $total_lines - $lines);
    $file->seek($start_line);
    
    $log_content = '';
    while (!$file->eof()) {
        $log_content .= $file->current();
        $file->next();
    }
    
    return $log_content;
}

/**
 * Process helpdesk form submission
 */
function insurance_crm_process_helpdesk_form() {
    if (!isset($_POST['helpdesk_submit']) || !wp_verify_nonce($_POST['helpdesk_nonce'], 'helpdesk_form')) {
        return array('success' => false, 'message' => 'Güvenlik doğrulaması başarısız.');
    }
    
    // Sanitize form data
    $form_data = array(
        'category' => sanitize_text_field($_POST['category']),
        'priority' => sanitize_text_field($_POST['priority']),
        'subject' => sanitize_text_field($_POST['subject']),
        'description' => sanitize_textarea_field($_POST['description']),
        'include_debug_log' => isset($_POST['include_debug_log']) ? 1 : 0
    );
    
    // Validate required fields
    if (empty($form_data['category']) || empty($form_data['priority']) || 
        empty($form_data['subject']) || empty($form_data['description'])) {
        return array('success' => false, 'message' => 'Lütfen tüm zorunlu alanları doldurun.');
    }
    
    // Generate ticket number
    $ticket_number = insurance_crm_generate_ticket_number();
    
    // Handle file uploads
    $uploaded_files = array();
    if (isset($_FILES['helpdesk_files']) && !empty($_FILES['helpdesk_files']['name'][0])) {
        $upload_result = insurance_crm_handle_helpdesk_files($_FILES['helpdesk_files'], $ticket_number);
        if ($upload_result['success']) {
            $uploaded_files = $upload_result['files'];
        } else {
            return array('success' => false, 'message' => $upload_result['message']);
        }
    }
    
    // Get debug log if requested
    $debug_log_content = '';
    if ($form_data['include_debug_log']) {
        $debug_log_content = insurance_crm_get_debug_log(100);
        if ($debug_log_content === false) {
            $debug_log_content = 'Debug log dosyası okunamadı.';
        }
    }
    
    // Get current user info
    $current_user = wp_get_current_user();
    global $wpdb;
    $representative = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $current_user->ID
    ));
    
    // Get representative ID from form or auto-detect
    $representative_id = isset($_POST['representative_id']) ? intval($_POST['representative_id']) : ($representative ? $representative->id : null);
    
    // Save ticket to database
    $helpdesk_table = $wpdb->prefix . 'insurance_crm_helpdesk_tickets';
    $insert_result = $wpdb->insert(
        $helpdesk_table,
        array(
            'ticket_number' => $ticket_number,
            'user_id' => $current_user->ID,
            'representative_id' => $representative_id,
            'category' => $form_data['category'],
            'priority' => $form_data['priority'],
            'subject' => $form_data['subject'],
            'description' => $form_data['description'],
            'status' => 'open',
            'attachments' => !empty($uploaded_files) ? json_encode($uploaded_files) : null,
            'debug_log_included' => $form_data['include_debug_log'],
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ),
        array('%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
    );
    
    if ($insert_result === false) {
        return array('success' => false, 'message' => 'Ticket veritabanına kaydedilemedi.');
    }
    
    // Prepare email data
    $email_data = array(
        'ticket_number' => $ticket_number,
        'user_name' => $current_user->display_name,
        'user_email' => $current_user->user_email,
        'rep_name' => $representative ? $representative->name . ' ' . $representative->surname : 'Bilinmiyor',
        'category' => $form_data['category'],
        'priority' => $form_data['priority'],
        'subject' => $form_data['subject'],
        'description' => $form_data['description'],
        'debug_log' => $debug_log_content,
        'uploaded_files' => $uploaded_files,
        'date' => current_time('d.m.Y H:i')
    );
    
    // Send email
    $email_result = insurance_crm_send_helpdesk_email($email_data);
    
    if ($email_result) {
        // Send confirmation email to user
        insurance_crm_send_helpdesk_confirmation($email_data);
        
        return array(
            'success' => true, 
            'message' => 'Destek talebiniz başarıyla gönderildi. Ticket numaranız: ' . $ticket_number,
            'ticket_number' => $ticket_number
        );
    } else {
        return array('success' => false, 'message' => 'Email gönderilirken bir hata oluştu.');
    }
}

/**
 * Handle file uploads for helpdesk
 */
function insurance_crm_handle_helpdesk_files($files, $ticket_number) {
    $allowed_types = insurance_crm_get_helpdesk_allowed_file_types();
    $allowed_mimes = insurance_crm_get_helpdesk_allowed_mime_types();
    $max_file_size = 5 * 1024 * 1024; // 5MB
    $max_files = 5;
    
    $file_count = count($files['name']);
    
    if ($file_count > $max_files) {
        return array('success' => false, 'message' => "En fazla {$max_files} dosya yükleyebilirsiniz.");
    }
    
    $upload_dir = wp_upload_dir();
    $helpdesk_dir = $upload_dir['basedir'] . '/helpdesk/' . $ticket_number;
    
    if (!wp_mkdir_p($helpdesk_dir)) {
        return array('success' => false, 'message' => 'Dosya yükleme dizini oluşturulamadı.');
    }
    
    $uploaded_files = array();
    
    for ($i = 0; $i < $file_count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        
        $file_name = sanitize_file_name($files['name'][$i]);
        $file_size = $files['size'][$i];
        $file_type = $files['type'][$i];
        $file_tmp = $files['tmp_name'][$i];
        
        // Check file size
        if ($file_size > $max_file_size) {
            return array('success' => false, 'message' => "Dosya boyutu 5MB'dan büyük olamaz: {$file_name}");
        }
        
        // Check file type
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        if (!in_array($file_ext, $allowed_types) || !in_array($file_type, $allowed_mimes)) {
            return array('success' => false, 'message' => "Desteklenmeyen dosya türü: {$file_name}");
        }
        
        // Generate unique filename
        $new_filename = time() . '_' . $i . '_' . $file_name;
        $file_path = $helpdesk_dir . '/' . $new_filename;
        
        if (move_uploaded_file($file_tmp, $file_path)) {
            $uploaded_files[] = array(
                'original_name' => $file_name,
                'filename' => $new_filename,
                'path' => $file_path,
                'size' => $file_size,
                'type' => $file_type
            );
        }
    }
    
    return array('success' => true, 'files' => $uploaded_files);
}
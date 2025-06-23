<?php
/**
 * Helpdesk Email Templates
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
 * Send helpdesk support email
 */
function insurance_crm_send_helpdesk_email($data) {
    // Get email settings
    $settings = get_option('insurance_crm_settings', array());
    $company_name = isset($settings['company_name']) ? $settings['company_name'] : get_bloginfo('name');
    
    // Email addresses
    $admin_email = get_option('admin_email');
    $support_email = isset($settings['support_email']) ? $settings['support_email'] : $admin_email;
    
    // Get base template
    $base_template = insurance_crm_get_email_base_template();
    
    // Get helpdesk content
    $email_content = insurance_crm_get_helpdesk_email_content($data);
    
    // Replace placeholders
    $email_html = str_replace(
        array('{email_subject}', '{email_content}'),
        array('Yeni Destek Talebi: ' . $data['ticket_number'], $email_content),
        $base_template
    );
    
    $subject = '[' . $company_name . '] Yeni Destek Talebi - ' . $data['ticket_number'];
    
    // Email headers
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $company_name . ' <' . $admin_email . '>',
        'Reply-To: ' . $data['user_email']
    );
    
    // Attachments
    $attachments = array();
    
    // Add uploaded files as attachments
    if (!empty($data['uploaded_files'])) {
        foreach ($data['uploaded_files'] as $file) {
            $attachments[] = $file['path'];
        }
    }
    
    // Create debug log file if included
    if (!empty($data['debug_log'])) {
        $debug_file = insurance_crm_create_debug_log_attachment($data['debug_log'], $data['ticket_number']);
        if ($debug_file) {
            $attachments[] = $debug_file;
        }
    }
    
    // Send email
    $email_sent = wp_mail($support_email, $subject, $email_html, $headers, $attachments);
    
    // Clean up debug log file
    if (!empty($debug_file) && file_exists($debug_file)) {
        unlink($debug_file);
    }
    
    return $email_sent;
}

/**
 * Get helpdesk email content
 */
function insurance_crm_get_helpdesk_email_content($data) {
    $categories = insurance_crm_get_helpdesk_categories();
    $priorities = insurance_crm_get_helpdesk_priorities();
    
    $category_name = isset($categories[$data['category']]) ? $categories[$data['category']] : $data['category'];
    $priority_info = isset($priorities[$data['priority']]) ? $priorities[$data['priority']] : array('label' => $data['priority'], 'color' => '#666666');
    
    $content = '
    <div class="email-section">
        <h2 class="section-title">🎫 Yeni Destek Talebi</h2>
        <div class="info-card">
            <div class="info-row">
                <span class="info-label">Ticket Numarası:</span>
                <span class="info-value"><strong>' . esc_html($data['ticket_number']) . '</strong></span>
            </div>
            <div class="info-row">
                <span class="info-label">Tarih:</span>
                <span class="info-value">' . esc_html($data['date']) . '</span>
            </div>
            <div class="info-row">
                <span class="info-label">Kullanıcı:</span>
                <span class="info-value">' . esc_html($data['user_name']) . ' (' . esc_html($data['user_email']) . ')</span>
            </div>
            <div class="info-row">
                <span class="info-label">Temsilci:</span>
                <span class="info-value">' . esc_html($data['rep_name']) . '</span>
            </div>
        </div>
    </div>

    <div class="email-section">
        <h3 class="section-title">📋 Talep Detayları</h3>
        <div class="info-card">
            <div class="info-row">
                <span class="info-label">Kategori:</span>
                <span class="info-value">' . esc_html($category_name) . '</span>
            </div>
            <div class="info-row">
                <span class="info-label">Öncelik:</span>
                <span class="info-value">
                    <span style="background-color: ' . $priority_info['bg_color'] . '; color: ' . $priority_info['color'] . '; padding: 4px 8px; border-radius: 4px; font-weight: 500;">
                        ' . esc_html($priority_info['label']) . '
                    </span>
                </span>
            </div>
            <div class="info-row">
                <span class="info-label">Konu:</span>
                <span class="info-value">' . esc_html($data['subject']) . '</span>
            </div>
        </div>
    </div>

    <div class="email-section">
        <h3 class="section-title">📝 Açıklama</h3>
        <div class="info-card">
            <div style="white-space: pre-wrap; line-height: 1.6;">' . esc_html($data['description']) . '</div>
        </div>
    </div>';

    // Add uploaded files info
    if (!empty($data['uploaded_files'])) {
        $content .= '
        <div class="email-section">
            <h3 class="section-title">📎 Ekli Dosyalar</h3>
            <div class="info-card">
                <ul style="margin: 0; padding-left: 20px;">';
        
        foreach ($data['uploaded_files'] as $file) {
            $file_size = size_format($file['size']);
            $content .= '<li>' . esc_html($file['original_name']) . ' (' . $file_size . ')</li>';
        }
        
        $content .= '
                </ul>
            </div>
        </div>';
    }

    // Add debug log info
    if (!empty($data['debug_log'])) {
        $content .= '
        <div class="email-section">
            <h3 class="section-title">🔧 Debug Log</h3>
            <div class="info-card">
                <p>Son 100 satır debug log dosyası ekte bulunmaktadır.</p>
            </div>
        </div>';
    }

    $content .= '
    <div class="email-section">
        <div style="background-color: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px; padding: 15px; margin: 20px 0;">
            <p style="margin: 0; color: #6c757d; font-size: 14px;">
                <strong>Not:</strong> Bu destek talebi CRM sistemi üzerinden otomatik olarak oluşturulmuştur. 
                Lütfen yanıtlarken ticket numarasını belirtiniz.
            </p>
        </div>
    </div>';

    return $content;
}

/**
 * Create debug log attachment file
 */
function insurance_crm_create_debug_log_attachment($log_content, $ticket_number) {
    $upload_dir = wp_upload_dir();
    $temp_dir = $upload_dir['basedir'] . '/temp';
    
    if (!wp_mkdir_p($temp_dir)) {
        return false;
    }
    
    $filename = 'debug_log_' . $ticket_number . '_' . date('Y-m-d_H-i-s') . '.txt';
    $file_path = $temp_dir . '/' . $filename;
    
    $header = "Debug Log - Ticket: {$ticket_number}\n";
    $header .= "Generated: " . current_time('d.m.Y H:i:s') . "\n";
    $header .= str_repeat("=", 50) . "\n\n";
    
    $content = $header . $log_content;
    
    if (file_put_contents($file_path, $content) !== false) {
        return $file_path;
    }
    
    return false;
}

/**
 * Send confirmation email to user
 */
function insurance_crm_send_helpdesk_confirmation($data) {
    $settings = get_option('insurance_crm_settings', array());
    $company_name = isset($settings['company_name']) ? $settings['company_name'] : get_bloginfo('name');
    
    $base_template = insurance_crm_get_email_base_template();
    
    $content = '
    <div class="email-section">
        <h2 class="section-title">✅ Destek Talebiniz Alındı</h2>
        <div class="info-card">
            <p>Sayın <strong>' . esc_html($data['user_name']) . '</strong>,</p>
            <p>Destek talebiniz başarıyla alınmıştır. En kısa sürede geri dönüş yapılacaktır.</p>
            
            <div class="info-row">
                <span class="info-label">Ticket Numarası:</span>
                <span class="info-value"><strong>' . esc_html($data['ticket_number']) . '</strong></span>
            </div>
            <div class="info-row">
                <span class="info-label">Konu:</span>
                <span class="info-value">' . esc_html($data['subject']) . '</span>
            </div>
            <div class="info-row">
                <span class="info-label">Tarih:</span>
                <span class="info-value">' . esc_html($data['date']) . '</span>
            </div>
        </div>
    </div>
    
    <div class="email-section">
        <div style="background-color: #e7f3ff; border: 1px solid #b3d7ff; border-radius: 6px; padding: 15px; margin: 20px 0;">
            <p style="margin: 0; color: #004085; font-size: 14px;">
                <strong>💡 Önemli:</strong> Bu ticket numarasını saklayınız. 
                Konuyla ilgili tüm iletişimde bu numarayı belirtiniz.
            </p>
        </div>
    </div>';

    $email_html = str_replace(
        array('{email_subject}', '{email_content}'),
        array('Destek Talebiniz Alındı - ' . $data['ticket_number'], $content),
        $base_template
    );

    $subject = '[' . $company_name . '] Destek Talebiniz Alındı - ' . $data['ticket_number'];
    
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $company_name . ' <' . get_option('admin_email') . '>'
    );

    return wp_mail($data['user_email'], $subject, $email_html, $headers);
}
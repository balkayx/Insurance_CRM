<?php
/**
 * Email Template System
 * 
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/includes
 * @author     Anadolu Birlik
 * @since      1.1.3
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get HTML email base template
 */
function insurance_crm_get_email_base_template() {
    $settings = get_option('insurance_crm_settings', array());
    $company_name = isset($settings['company_name']) ? $settings['company_name'] : get_bloginfo('name');
    $primary_color = isset($settings['site_appearance']['primary_color']) ? $settings['site_appearance']['primary_color'] : '#1976d2';
    
    // Logo URL with fallback to default logo if not set
    $logo_url = !empty($settings['site_appearance']['login_logo']) 
        ? $settings['site_appearance']['login_logo'] 
        : plugins_url('assets/images/Insurance-logo.png', dirname(dirname(__FILE__)));
    
    // Add error logging for debugging
    if (empty($settings['site_appearance']['login_logo'])) {
        error_log('Insurance CRM Email: Using default logo fallback: ' . $logo_url);
    } else {
        error_log('Insurance CRM Email: Using custom logo: ' . $settings['site_appearance']['login_logo']);
    }
    
    $logo_html = '';
    if (!empty($logo_url)) {
        $logo_html = '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($company_name) . '" style="max-height: 35px; max-width: 120px; width: auto; height: auto; margin-bottom: 10px; display: block; margin-left: auto; margin-right: auto;">';
    } else {
        error_log('Insurance CRM Email: No logo URL available');
    }
    
    return '
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{email_subject}</title>
        <style>
            body {
                margin: 0;
                padding: 0;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                line-height: 1.6;
                color: #333333;
                background-color: #f8f9fa;
            }
            .email-container {
                max-width: 600px;
                margin: 0 auto;
                background-color: #ffffff;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            }
            .email-header {
                background: linear-gradient(135deg, ' . $primary_color . ', ' . $primary_color . 'cc);
                color: #ffffff;
                padding: 30px 20px;
                text-align: center;
            }
            .email-header h1 {
                margin: 0;
                font-size: 24px;
                font-weight: 600;
            }
            .email-content {
                padding: 30px 20px;
            }
            .email-content h2 {
                color: ' . $primary_color . ';
                font-size: 20px;
                margin-bottom: 20px;
                border-bottom: 2px solid #e9ecef;
                padding-bottom: 10px;
            }
            .info-card {
                background-color: #f8f9fa;
                border-left: 4px solid ' . $primary_color . ';
                padding: 15px 20px;
                margin: 20px 0;
                border-radius: 4px;
            }
            .info-row {
                display: flex;
                justify-content: space-between;
                margin-bottom: 8px;
                padding: 5px 0;
                border-bottom: 1px solid #e9ecef;
            }
            .info-row:last-child {
                border-bottom: none;
                margin-bottom: 0;
            }
            .info-label {
                font-weight: 600;
                color: #495057;
            }
            .info-value {
                color: #212529;
            }
            .email-footer {
                background-color: #f8f9fa;
                padding: 20px;
                text-align: center;
                color: #6c757d;
                font-size: 14px;
                border-top: 1px solid #e9ecef;
            }
            .button {
                display: inline-block;
                background-color: ' . $primary_color . ';
                color: #ffffff;
                padding: 12px 24px;
                text-decoration: none;
                border-radius: 6px;
                font-weight: 600;
                margin: 10px 0;
            }
            @media only screen and (max-width: 600px) {
                .email-container {
                    margin: 0;
                    border-radius: 0;
                }
                .email-content {
                    padding: 20px 15px;
                }
                .info-row {
                    flex-direction: column;
                }
            }
        </style>
    </head>
    <body>
        <div class="email-container">
            <div class="email-header">
                ' . $logo_html . '
                <h1>' . esc_html($company_name) . '</h1>
            </div>
            <div class="email-content">
                {email_content}
            </div>
            <div class="email-footer">
                <p>Bu e-posta ' . esc_html($company_name) . ' sisteminden otomatik olarak gönderilmiştir.</p>
                <p>Bu e-postayı almak istemiyorsanız, lütfen bizimle iletişime geçin.</p>
            </div>
        </div>
    </body>
    </html>';
}

/**
 * Replace variables in email template
 */
function insurance_crm_replace_email_variables($template, $variables) {
    foreach ($variables as $key => $value) {
        $template = str_replace('{' . $key . '}', esc_html($value), $template);
    }
    return $template;
}

/**
 * Get default renewal reminder template
 */
function insurance_crm_get_default_renewal_template() {
    return '<h2>Poliçe Yenileme Hatırlatması</h2>
    <p>Sayın {customer_name},</p>
    <p>Poliçenizin bitiş tarihi yaklaşmaktadır. Aşağıdaki bilgileri kontrol ederek yenileme işlemlerinizi zamanında tamamlayınız.</p>
    
    <div class="info-card">
        <div class="info-row">
            <span class="info-label">Poliçe Numarası:</span>
            <span class="info-value">{policy_number}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Poliçe Türü:</span>
            <span class="info-value">{policy_type}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Bitiş Tarihi:</span>
            <span class="info-value">{end_date}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Prim Tutarı:</span>
            <span class="info-value">{premium_amount} TL</span>
        </div>
    </div>
    
    <p>Yenileme işlemleri için bizimle iletişime geçebilirsiniz.</p>
    <p>Saygılarımızla.</p>';
}

/**
 * Get default task reminder template
 */
function insurance_crm_get_default_task_template() {
    return '<h2>Görev Hatırlatması</h2>
    <p>Sayın {customer_name},</p>
    <p>Sizinle ilgili bir görevimiz bulunmaktadır:</p>
    
    <div class="info-card">
        <div class="info-row">
            <span class="info-label">Görev:</span>
            <span class="info-value">{task_description}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Tamamlanma Tarihi:</span>
            <span class="info-value">{due_date}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Öncelik:</span>
            <span class="info-value">{priority}</span>
        </div>
    </div>
    
    <p>Konuyla ilgili detaylı bilgi için bizimle iletişime geçebilirsiniz.</p>
    <p>Saygılarımızla.</p>';
}

/**
 * Get default new representative template
 */
function insurance_crm_get_default_new_representative_template() {
    return '<h2>Hoş Geldiniz! Yeni Hesap Bilgileriniz</h2>
    <p>Sayın {first_name} {last_name},</p>
    <p>Müşteri temsilcisi hesabınız başarıyla oluşturulmuştur. Sisteme giriş yapabilmeniz için aşağıdaki bilgileri kullanabilirsiniz:</p>
    
    <div class="info-card">
        <div class="info-row">
            <span class="info-label">Kullanıcı Adı:</span>
            <span class="info-value">{username}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Şifre:</span>
            <span class="info-value">{password}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Giriş Adresi:</span>
            <span class="info-value"><a href="{login_url}" class="button">Sisteme Giriş Yap</a></span>
        </div>
    </div>
    
    <p><strong>Güvenlik için önemli:</strong> İlk girişinizden sonra şifrenizi değiştirmenizi öneririz.</p>
    <p>Herhangi bir sorunuz olursa bizimle iletişime geçmekten çekinmeyin.</p>
    <p>Saygılarımızla.</p>';
}

/**
 * Get default new policy template
 */
function insurance_crm_get_default_policy_template() {
    return '<h2>Yeni Poliçe Bildirimi</h2>
    <p>Sayın {customer_name},</p>
    <p>Yeni poliçeniz başarıyla oluşturulmuştur. Detaylar aşağıdadır:</p>
    
    <div class="info-card">
        <div class="info-row">
            <span class="info-label">Poliçe Numarası:</span>
            <span class="info-value">{policy_number}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Poliçe Türü:</span>
            <span class="info-value">{policy_type}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Başlangıç Tarihi:</span>
            <span class="info-value">{start_date}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Bitiş Tarihi:</span>
            <span class="info-value">{end_date}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Prim Tutarı:</span>
            <span class="info-value">{premium_amount} TL</span>
        </div>
    </div>
    
    <p>Poliçenizle ilgili herhangi bir sorunuz varsa bizimle iletişime geçebilirsiniz.</p>
    <p>Saygılarımızla.</p>';
}

/**
 * Get default birthday celebration template
 */
function insurance_crm_get_default_birthday_template() {
    return '<div style="text-align: center; padding: 30px; background-color: #f8f9fa; border-radius: 8px; margin-bottom: 30px;">
        <div style="font-size: 36px; margin-bottom: 20px; color: #6c757d;">🎂</div>
        <h2 style="color: #2c3e50; font-size: 24px; margin-bottom: 8px; font-weight: 600;">Doğum Gününüz Kutlu Olsun</h2>
        <h3 style="color: #34495e; font-size: 20px; margin-bottom: 0; font-weight: 400;">Sayın {customer_name}</h3>
    </div>
    
    <div style="padding: 40px 30px; text-align: center; border-left: 4px solid #3498db; background-color: #ffffff; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); margin: 30px 0;">
        <h3 style="margin: 0 0 20px 0; font-size: 18px; color: #2c3e50; font-weight: 600;">🎉 Bu Özel Gününüzde</h3>
        <p style="margin: 0; font-size: 16px; color: #5a6c7d; line-height: 1.6;">
            Doğum gününüzü en içten dileklerimizle kutluyor, yaşamınızın bu yeni yılında sağlık, mutluluk ve başarılarla dolu günler diliyoruz.
        </p>
    </div>
    
    <div style="text-align: center; padding: 30px; background-color: #ecf0f1; border-radius: 8px; margin: 30px 0;">
        <p style="font-size: 15px; color: #34495e; line-height: 1.7; margin-bottom: 20px;">
            Sizinle çalışmaktan büyük bir memnuniyet duyuyoruz. Bu güzel günün ailenizle birlikte 
            huzur ve neşe içinde geçmesini dileriz. Önümüzdeki yıl da birlikte daha nice başarılara imza atacağımıza inanıyoruz.
        </p>
        <div style="border-top: 2px solid #bdc3c7; padding-top: 20px; margin-top: 20px;">
            <p style="font-size: 14px; color: #7f8c8d; margin: 0; font-style: italic;">
                Profesyonel hizmet anlayışımızla her zaman yanınızdayız.
            </p>
        </div>
    </div>
    
    <div style="text-align: center; margin-top: 40px; padding: 20px;">
        <p style="font-size: 14px; color: #7f8c8d; margin-bottom: 8px;">Saygı ve sevgilerimizle,</p>
        <p style="font-size: 16px; font-weight: 600; color: #2c3e50; margin: 0;">{company_name} Ekibi</p>
    </div>';
}

/**
 * Send HTML email using templates
 */
function insurance_crm_send_template_email($to, $subject, $template_content, $variables = array()) {
    // Get base template
    $base_template = insurance_crm_get_email_base_template();
    
    // Replace content in base template
    $email_html = str_replace('{email_content}', $template_content, $base_template);
    $email_html = str_replace('{email_subject}', esc_html($subject), $email_html);
    
    // Replace variables
    $email_html = insurance_crm_replace_email_variables($email_html, $variables);
    
    // Set headers for HTML email
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_bloginfo('name') . ' <' . get_bloginfo('admin_email') . '>'
    );
    
    return wp_mail($to, $subject, $email_html, $headers);
}

/**
 * Get email template from settings or default
 */
function insurance_crm_get_email_template($template_type) {
    $settings = get_option('insurance_crm_settings', array());
    
    if (isset($settings['email_templates'][$template_type]) && !empty($settings['email_templates'][$template_type])) {
        return $settings['email_templates'][$template_type];
    }
    
    // Return default templates
    switch ($template_type) {
        case 'renewal_reminder':
            return insurance_crm_get_default_renewal_template();
        case 'task_reminder':
            return insurance_crm_get_default_task_template();
        case 'new_policy':
            return insurance_crm_get_default_policy_template();
        case 'new_representative':
            return insurance_crm_get_default_new_representative_template();
        case 'birthday_celebration':
            return insurance_crm_get_default_birthday_template();
        default:
            return '';
    }
}

/**
 * Test function to verify email template logo functionality
 * This can be called for debugging purposes
 */
function insurance_crm_test_email_logo() {
    $base_template = insurance_crm_get_email_base_template();
    $test_content = '<h2>Test Email</h2><p>This is a test email to verify logo functionality.</p>';
    $email_html = str_replace('{email_content}', $test_content, $base_template);
    $email_html = str_replace('{email_subject}', 'Test Email', $email_html);
    
    // Log the generated HTML for debugging
    error_log('Insurance CRM Email Test HTML: ' . substr($email_html, 0, 500) . '...');
    
    return $email_html;
}
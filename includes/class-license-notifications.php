<?php
/**
 * License Notification System
 * 
 * Handles email notifications for license activities
 * 
 * @package Insurance_CRM
 * @author  Anadolu Birlik
 * @since   1.1.3
 */

if (!defined('ABSPATH')) {
    exit;
}

class Insurance_CRM_License_Notifications {
    
    /**
     * Notification email address
     */
    private $notification_email = 'insurance.crmx@gmail.com';
    
    /**
     * Constructor
     */
    public function __construct() {
        // Hook into WordPress actions
        add_action('init', array($this, 'init'));
        add_action('wp_login', array($this, 'track_daily_login'), 10, 2);
    }
    
    /**
     * Initialize the notification system
     */
    public function init() {
        // Check if we need to send activation email (in case hook missed)
        $this->maybe_send_activation_email();
    }
    
    /**
     * Send plugin activation email
     */
    public function send_activation_email() {
        // Check if activation email was already sent
        $activation_sent = get_option('insurance_crm_activation_email_sent', false);
        
        if (!$activation_sent) {
            $domain = parse_url(home_url(), PHP_URL_HOST);
            $site_name = get_bloginfo('name');
            
            $subject = 'Insurance-CRM Plugini Aktif Edildi';
            $message = $this->get_activation_email_template($domain, $site_name);
            
            $sent = $this->send_notification_email($subject, $message);
            
            if ($sent) {
                update_option('insurance_crm_activation_email_sent', true);
                update_option('insurance_crm_activation_email_date', current_time('mysql'));
                error_log('[LISANS NOTIFICATION] Aktivasyon e-postası gönderildi: ' . $domain);
            } else {
                error_log('[LISANS NOTIFICATION] Aktivasyon e-postası gönderilemedi: ' . $domain);
            }
        }
    }
    
    /**
     * Maybe send activation email (fallback)
     */
    private function maybe_send_activation_email() {
        // If activation email was never sent, send it now
        $activation_sent = get_option('insurance_crm_activation_email_sent', false);
        
        if (!$activation_sent) {
            $this->send_activation_email();
        }
    }
    
    /**
     * Track daily login and send notification
     * 
     * @param string $user_login Username
     * @param WP_User $user User object
     */
    public function track_daily_login($user_login, $user) {
        // Only track insurance users and admins
        if (!in_array('insurance_representative', (array)$user->roles) && 
            !in_array('administrator', (array)$user->roles)) {
            return;
        }
        
        $today = date('Y-m-d');
        $last_login_date = get_option('insurance_crm_last_login_notification_date', '');
        
        // If no notification sent today, send one
        if ($last_login_date !== $today) {
            $domain = parse_url(home_url(), PHP_URL_HOST);
            $site_name = get_bloginfo('name');
            
            $subject = 'Insurance-CRM Plugini Kullanıcı Girişi';
            $message = $this->get_login_email_template($domain, $site_name, $user_login);
            
            $sent = $this->send_notification_email($subject, $message);
            
            if ($sent) {
                update_option('insurance_crm_last_login_notification_date', $today);
                update_option('insurance_crm_last_login_notification_time', current_time('mysql'));
                error_log('[LISANS NOTIFICATION] Günlük giriş e-postası gönderildi: ' . $domain . ' - ' . $user_login);
            } else {
                error_log('[LISANS NOTIFICATION] Günlük giriş e-postası gönderilemedi: ' . $domain);
            }
        }
    }
    
    /**
     * Send notification email
     * 
     * @param string $subject Email subject
     * @param string $message Email message
     * @return bool True if email was sent successfully
     */
    private function send_notification_email($subject, $message) {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <noreply@' . parse_url(home_url(), PHP_URL_HOST) . '>'
        );
        
        return wp_mail($this->notification_email, $subject, $message, $headers);
    }
    
    /**
     * Get activation email template
     * 
     * @param string $domain Domain name
     * @param string $site_name Site name
     * @return string Email content
     */
    private function get_activation_email_template($domain, $site_name) {
        $template = '
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Insurance-CRM Plugin Aktivasyonu</title>
        </head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
            <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
                <h2 style="color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px;">
                    🚀 Insurance-CRM Plugin Aktivasyonu
                </h2>
                
                <p>Merhaba,</p>
                
                <p><strong>Insurance-CRM</strong> plugini yeni bir sitede aktif edildi:</p>
                
                <div style="background-color: #f8f9fa; padding: 15px; border-left: 4px solid #3498db; margin: 20px 0;">
                    <strong>Domain:</strong> <a href="https://' . esc_html($domain) . '" target="_blank">' . esc_html($domain) . '</a><br>
                    <strong>Site Adı:</strong> ' . esc_html($site_name) . '<br>
                    <strong>Aktivasyon Tarihi:</strong> ' . date('d.m.Y H:i:s') . '<br>
                    <strong>IP Adresi:</strong> ' . $this->get_client_ip() . '
                </div>
                
                <p>Bu aktivasyon bilgilendirme amaçlıdır. Lisans kontrolü ve yönetimi için admin paneline erişebilirsiniz.</p>
                
                <div style="background-color: #e8f5e8; padding: 10px; border-radius: 5px; margin: 20px 0;">
                    <strong>Önemli:</strong> Eğer bu aktivasyon sizin tarafınızdan yapılmadıysa, lütfen lisans yönetim panelinden kontrol edin.
                </div>
                
                <p>Saygılarımızla,<br>
                <strong>Insurance-CRM Otomatik Bildirim Sistemi</strong></p>
                
                <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
                <p style="font-size: 12px; color: #666;">
                    Bu e-posta otomatik olarak gönderilmiştir. Lütfen yanıtlamayın.
                </p>
            </div>
        </body>
        </html>';
        
        return $template;
    }
    
    /**
     * Get login email template
     * 
     * @param string $domain Domain name
     * @param string $site_name Site name
     * @param string $username Username who logged in
     * @return string Email content
     */
    private function get_login_email_template($domain, $site_name, $username) {
        $license_info = $this->get_license_summary();
        
        $template = '
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Insurance-CRM Günlük Kullanım Raporu</title>
        </head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
            <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
                <h2 style="color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px;">
                    📊 Insurance-CRM Günlük Kullanım Raporu
                </h2>
                
                <p>Merhaba,</p>
                
                <p><strong>Insurance-CRM</strong> plugini bugün kullanılmaya başlandı:</p>
                
                <div style="background-color: #f8f9fa; padding: 15px; border-left: 4px solid #3498db; margin: 20px 0;">
                    <strong>Domain:</strong> <a href="https://' . esc_html($domain) . '" target="_blank">' . esc_html($domain) . '</a><br>
                    <strong>Site Adı:</strong> ' . esc_html($site_name) . '<br>
                    <strong>İlk Giriş Yapan:</strong> ' . esc_html($username) . '<br>
                    <strong>Giriş Tarihi:</strong> ' . date('d.m.Y H:i:s') . '<br>
                    <strong>IP Adresi:</strong> ' . $this->get_client_ip() . '
                </div>
                
                <div style="background-color: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 20px 0;">
                    <h3 style="margin-top: 0; color: #856404;">📋 Lisans Durumu</h3>
                    ' . $license_info . '
                </div>
                
                <div style="background-color: #d4edda; padding: 10px; border-radius: 5px; margin: 20px 0;">
                    <strong>Tavsiye:</strong> Düzenli lisans kontrolü yaparak sistem güvenliğini sağlayın.
                </div>
                
                <p>Saygılarımızla,<br>
                <strong>Insurance-CRM Otomatik Bildirim Sistemi</strong></p>
                
                <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
                <p style="font-size: 12px; color: #666;">
                    Bu e-posta otomatik olarak gönderilmiştir. Lütfen yanıtlamayın.
                </p>
            </div>
        </body>
        </html>';
        
        return $template;
    }
    
    /**
     * Get license summary information
     * 
     * @return string License summary HTML
     */
    private function get_license_summary() {
        $license_key = get_option('insurance_crm_license_key', '');
        $license_status = get_option('insurance_crm_license_status', 'inactive');
        $license_expiry = get_option('insurance_crm_license_expiry', '');
        $user_limit = get_option('insurance_crm_license_user_limit', 5);
        
        // Get current user count
        global $wpdb, $insurance_crm_license_manager;
        $current_users = 0;
        if ($insurance_crm_license_manager) {
            $current_users = $insurance_crm_license_manager->get_current_user_count();
        }
        
        $status_color = '#dc3545'; // Red for inactive
        if ($license_status === 'active') {
            $status_color = '#28a745'; // Green for active
        } elseif ($license_status === 'expired') {
            $status_color = '#ffc107'; // Yellow for expired
        }
        
        $summary = '<strong>Lisans Anahtarı:</strong> ' . (!empty($license_key) ? substr($license_key, 0, 8) . '...' : 'Tanımlanmamış') . '<br>';
        $summary .= '<strong>Durum:</strong> <span style="color: ' . $status_color . ';">' . strtoupper($license_status) . '</span><br>';
        $summary .= '<strong>Kullanıcı Sayısı:</strong> ' . $current_users . ' / ' . $user_limit . '<br>';
        
        if (!empty($license_expiry)) {
            $expiry_date = date('d.m.Y', strtotime($license_expiry));
            $summary .= '<strong>Son Kullanma:</strong> ' . $expiry_date . '<br>';
        }
        
        return $summary;
    }
    
    /**
     * Get client IP address
     * 
     * @return string Client IP address
     */
    private function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'Bilinmiyor';
    }
    
    /**
     * Get notification statistics
     * 
     * @return array Notification statistics
     */
    public function get_notification_stats() {
        return array(
            'activation_email_sent' => get_option('insurance_crm_activation_email_sent', false),
            'activation_email_date' => get_option('insurance_crm_activation_email_date', ''),
            'last_login_notification_date' => get_option('insurance_crm_last_login_notification_date', ''),
            'last_login_notification_time' => get_option('insurance_crm_last_login_notification_time', ''),
            'notification_email' => $this->notification_email
        );
    }
}
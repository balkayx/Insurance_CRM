<?php
/**
 * Şifre Sıfırlama İşlemleri
 * 
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/includes
 * @author     Anadolu Birlik
 * @since      1.1.3
 */

if (!defined('ABSPATH')) {
    exit;
}

class Insurance_CRM_Password_Reset {
    
    /**
     * Token süre limiti (24 saat)
     */
    const TOKEN_EXPIRY = 24 * 60 * 60; // 24 hours in seconds
    
    /**
     * Sınıf örneklemesini başlat
     */
    public function __construct() {
        // AJAX işlemlerini dinle
        add_action('wp_ajax_insurance_crm_forgot_password', array($this, 'handle_forgot_password'));
        add_action('wp_ajax_nopriv_insurance_crm_forgot_password', array($this, 'handle_forgot_password'));
        
        add_action('wp_ajax_insurance_crm_reset_password', array($this, 'handle_reset_password'));
        add_action('wp_ajax_nopriv_insurance_crm_reset_password', array($this, 'handle_reset_password'));
        
        // Form işlemlerini dinle
        add_action('init', array($this, 'process_forgot_password_form'));
        add_action('init', array($this, 'process_reset_password_form'));
    }
    
    /**
     * Şifremi unuttum form işlemi
     */
    public function process_forgot_password_form() {
        if (isset($_POST['insurance_crm_forgot_password']) && isset($_POST['insurance_crm_forgot_password_nonce'])) {
            
            // Nonce doğrulama
            if (!wp_verify_nonce($_POST['insurance_crm_forgot_password_nonce'], 'insurance_crm_forgot_password')) {
                wp_redirect(add_query_arg('error', 'security', wp_get_referer()));
                exit;
            }
            
            $email = sanitize_email($_POST['email']);
            $result = $this->send_password_reset_email($email);
            
            if ($result['success']) {
                wp_redirect(add_query_arg('success', 'email_sent', wp_get_referer()));
            } else {
                wp_redirect(add_query_arg('error', $result['error'], wp_get_referer()));
            }
            exit;
        }
    }
    
    /**
     * Şifre sıfırlama form işlemi
     */
    public function process_reset_password_form() {
        if (isset($_POST['insurance_crm_reset_password']) && isset($_POST['insurance_crm_reset_password_nonce'])) {
            
            // Nonce doğrulama
            if (!wp_verify_nonce($_POST['insurance_crm_reset_password_nonce'], 'insurance_crm_reset_password')) {
                wp_redirect(add_query_arg('error', 'security', wp_get_referer()));
                exit;
            }
            
            $token = sanitize_text_field($_POST['token']);
            $password = $_POST['password'];
            $password_confirm = $_POST['password_confirm'];
            
            $result = $this->reset_password($token, $password, $password_confirm);
            
            if ($result['success']) {
                wp_redirect(home_url('/temsilci-girisi/?reset=success'));
            } else {
                wp_redirect(add_query_arg('error', $result['error'], wp_get_referer()));
            }
            exit;
        }
    }
    
    /**
     * AJAX şifremi unuttum işlemi
     */
    public function handle_forgot_password() {
        // Nonce doğrulama
        if (!wp_verify_nonce($_POST['nonce'], 'insurance_crm_nonce')) {
            wp_send_json_error('Güvenlik doğrulaması başarısız.');
        }
        
        $email = sanitize_email($_POST['email']);
        $result = $this->send_password_reset_email($email);
        
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * AJAX şifre sıfırlama işlemi
     */
    public function handle_reset_password() {
        // Nonce doğrulama
        if (!wp_verify_nonce($_POST['nonce'], 'insurance_crm_nonce')) {
            wp_send_json_error('Güvenlik doğrulaması başarısız.');
        }
        
        $token = sanitize_text_field($_POST['token']);
        $password = $_POST['password'];
        $password_confirm = $_POST['password_confirm'];
        
        $result = $this->reset_password($token, $password, $password_confirm);
        
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Şifre sıfırlama e-postası gönder
     */
    public function send_password_reset_email($email) {
        // Email validasyonu
        if (empty($email) || !is_email($email)) {
            return array(
                'success' => false,
                'error' => 'invalid_email',
                'message' => 'Geçerli bir e-posta adresi girin.'
            );
        }
        
        // Kullanıcıyı bul
        $user = get_user_by('email', $email);
        if (!$user) {
            return array(
                'success' => false,
                'error' => 'user_not_found',
                'message' => 'Bu e-posta adresi ile kayıtlı kullanıcı bulunamadı.'
            );
        }
        
        // Müşteri temsilcisi kontrolü
        if (!in_array('insurance_representative', (array)$user->roles)) {
            return array(
                'success' => false,
                'error' => 'not_representative',
                'message' => 'Bu kullanıcı müşteri temsilcisi değil.'
            );
        }
        
        // Token oluştur
        $token = $this->generate_reset_token($user->ID);
        
        // E-posta gönder
        $email_sent = $this->send_reset_email($user, $token);
        
        if ($email_sent) {
            return array(
                'success' => true,
                'data' => array(
                    'masked_email' => $this->mask_email($email),
                    'message' => 'Şifre sıfırlama bağlantısı e-posta adresinize gönderildi.'
                )
            );
        } else {
            return array(
                'success' => false,
                'error' => 'email_failed',
                'message' => 'E-posta gönderilirken hata oluştu. Lütfen tekrar deneyin.'
            );
        }
    }
    
    /**
     * Şifre sıfırlama işlemi
     */
    public function reset_password($token, $password, $password_confirm) {
        // Token doğrulama
        $user_id = $this->validate_reset_token($token);
        if (!$user_id) {
            return array(
                'success' => false,
                'error' => 'invalid_token',
                'message' => 'Geçersiz veya süresi dolmuş token.'
            );
        }
        
        // Şifre validasyonu
        if (empty($password) || strlen($password) < 6) {
            return array(
                'success' => false,
                'error' => 'weak_password',
                'message' => 'Şifre en az 6 karakter olmalı.'
            );
        }
        
        if ($password !== $password_confirm) {
            return array(
                'success' => false,
                'error' => 'password_mismatch',
                'message' => 'Şifreler eşleşmiyor.'
            );
        }
        
        // Şifreyi güncelle
        wp_set_password($password, $user_id);
        
        // Token'ı sil
        $this->delete_reset_token($user_id);
        
        return array(
            'success' => true,
            'data' => array(
                'message' => 'Şifreniz başarıyla güncellendi.'
            )
        );
    }
    
    /**
     * Sıfırlama token'ı oluştur
     */
    private function generate_reset_token($user_id) {
        $token = wp_generate_password(32, false);
        $expiry = time() + self::TOKEN_EXPIRY;
        
        // Eski token'ları temizle
        $this->delete_reset_token($user_id);
        
        // Yeni token'ı kaydet
        update_user_meta($user_id, '_password_reset_token', $token);
        update_user_meta($user_id, '_password_reset_expiry', $expiry);
        
        return $token;
    }
    
    /**
     * Token doğrulama
     */
    private function validate_reset_token($token) {
        global $wpdb;
        
        // Token'a sahip kullanıcıyı bul
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} 
             WHERE meta_key = '_password_reset_token' 
             AND meta_value = %s",
            $token
        ));
        
        if (!$user_id) {
            return false;
        }
        
        // Token süresini kontrol et
        $expiry = get_user_meta($user_id, '_password_reset_expiry', true);
        if (!$expiry || time() > $expiry) {
            $this->delete_reset_token($user_id);
            return false;
        }
        
        return $user_id;
    }
    
    /**
     * Token'ı sil
     */
    private function delete_reset_token($user_id) {
        delete_user_meta($user_id, '_password_reset_token');
        delete_user_meta($user_id, '_password_reset_expiry');
    }
    
    /**
     * E-posta adresi maskeleme
     */
    private function mask_email($email) {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email;
        }
        
        $username = $parts[0];
        $domain = $parts[1];
        
        // İlk 4 karakter görünür, geri kalanı '*' ile maskelenir
        if (strlen($username) <= 4) {
            $masked = $username;
        } else {
            $visible = substr($username, 0, 4);
            $masked = $visible . str_repeat('*', strlen($username) - 4);
        }
        
        return $masked . '@' . $domain;
    }
    
    /**
     * Şifre sıfırlama e-postası gönder
     */
    private function send_reset_email($user, $token) {
        $reset_url = home_url('/sifre-sifirla/?token=' . $token);
        
        $template_vars = array(
            'user_name' => $user->display_name,
            'reset_link' => $reset_url,
            'expiry_hours' => '24'
        );
        
        // Get template content
        $template_content = insurance_crm_get_email_template('password_reset');
        
        return insurance_crm_send_template_email(
            $user->user_email,
            'Şifre Sıfırlama Talebi',
            $template_content,
            $template_vars
        );
    }
}

// Sınıfı başlat
new Insurance_CRM_Password_Reset();
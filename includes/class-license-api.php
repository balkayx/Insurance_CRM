<?php
/**
 * License API Communication Class
 * 
 * Handles communication with the central license server
 * 
 * @package Insurance_CRM
 * @author  Anadolu Birlik
 * @since   1.1.3
 */

if (!defined('ABSPATH')) {
    exit;
}

class Insurance_CRM_License_API {
    
    /**
     * Default license server URL
     */
    private $license_server_url;
    
    /**
     * API timeout in seconds
     */
    private $timeout;
    
    /**
     * Debug mode flag
     */
    private $debug_mode;

    /**
     * Constructor
     */
    public function __construct() {
        $this->license_server_url = get_option('insurance_crm_license_server_url', 'https://balkay.net/crm');
        $this->timeout = 30;
        $this->debug_mode = get_option('insurance_crm_license_debug_mode', false);
    }

    /**
     * Validate license key with central server
     * 
     * @param string $license_key The license key to validate
     * @return array Validation response
     */
    public function validate_license($license_key) {
        if (empty($license_key)) {
            return array(
                'status' => 'invalid',
                'message' => 'Lisans anahtarı boş olamaz'
            );
        }

        // Reload server URL in case it was updated
        $this->license_server_url = get_option('insurance_crm_license_server_url', 'https://balkay.net/crm');
        
        $domain = parse_url(home_url(), PHP_URL_HOST);
        
        $request_data = array(
            'license_key' => sanitize_text_field($license_key),
            'domain' => sanitize_text_field($domain)
        );

        // Öncelikle çalıştığı doğrulanan endpoint'i dene
        $primary_endpoint = '/api/validate_license';
        
        error_log('[LISANS DEBUG] Birincil endpoint deneniyor: ' . $primary_endpoint);
        $response = $this->make_request($primary_endpoint, $request_data, 'GET');
        
        if (!is_wp_error($response)) {
            error_log('[LISANS DEBUG] Birincil endpoint başarılı: ' . $primary_endpoint);
            return $response;
        }
        
        error_log('[LISANS DEBUG] Birincil endpoint başarısız: ' . $response->get_error_message());

        // Yedek endpoint'leri dene
        $fallback_endpoints = array(
            '/wp-json/balkay-license/v1/validate',  // WordPress REST API pattern
            '/wp-admin/admin-ajax.php',             // WordPress AJAX pattern
            '/?action=validate_license',            // Query parameter pattern
        );

        $last_error = $response;
        
        foreach ($fallback_endpoints as $endpoint) {
            error_log('[LISANS DEBUG] Yedek endpoint deneniyor: ' . $endpoint);
            
            if ($endpoint === '/wp-admin/admin-ajax.php') {
                // WordPress AJAX requires different data structure
                $ajax_data = array_merge($request_data, array('action' => 'validate_license'));
                $response = $this->make_request($endpoint, $ajax_data, 'POST');
            } elseif ($endpoint === '/?action=validate_license') {
                // Query parameter method
                $response = $this->make_request($endpoint, $request_data, 'GET');
            } else {
                // Standard API call
                $response = $this->make_request($endpoint, $request_data, 'POST');
            }
            
            if (!is_wp_error($response)) {
                error_log('[LISANS DEBUG] Yedek endpoint başarılı: ' . $endpoint);
                return $response;
            } else {
                $last_error = $response;
                error_log('[LISANS DEBUG] Yedek endpoint başarısız: ' . $endpoint . ' - ' . $response->get_error_message());
            }
        }
        
        // If all endpoints failed, return the last error
        if (is_wp_error($last_error)) {
            return array(
                'status' => 'error',
                'message' => 'Tüm API endpoint\'leri başarısız oldu. Son hata: ' . $last_error->get_error_message()
            );
        }

        return array(
            'status' => 'error',
            'message' => 'Sunucu ile iletişim kurulamadı'
        );
    }

    /**
     * Get license information from server
     * 
     * @param string $license_key The license key
     * @return array License information
     */
    public function get_license_info($license_key) {
        if (empty($license_key)) {
            return array(
                'status' => 'invalid',
                'message' => 'Lisans anahtarı boş olamaz'
            );
        }

        $request_data = array(
            'license_key' => sanitize_text_field($license_key),
            'action' => 'get_info'
        );

        // Try different API endpoint patterns
        $endpoints_to_try = array(
            '/wp-json/balkay-license/v1/info',
            '/api/license_info',
            '/wp-admin/admin-ajax.php',
            '/?action=license_info',
        );

        foreach ($endpoints_to_try as $endpoint) {
            if ($endpoint === '/wp-admin/admin-ajax.php') {
                $ajax_data = array_merge($request_data, array('action' => 'license_info'));
                $response = $this->make_request($endpoint, $ajax_data, 'POST');
            } elseif ($endpoint === '/?action=license_info') {
                $response = $this->make_request($endpoint, $request_data, 'GET');
            } else {
                $response = $this->make_request($endpoint, $request_data, 'GET');
            }
            
            if (!is_wp_error($response)) {
                return $response;
            }
        }
        
        return array(
            'status' => 'error',
            'message' => 'Sunucu ile iletişim kurulamadı'
        );
    }

    /**
     * Check license status (for periodic validation)
     * 
     * @param string $license_key The license key
     * @return array Status response
     */
    public function check_license_status($license_key) {
        if (empty($license_key)) {
            return array(
                'status' => 'invalid',
                'message' => 'Lisans anahtarı boş olamaz'
            );
        }

        $domain = parse_url(home_url(), PHP_URL_HOST);
        
        $request_data = array(
            'license_key' => sanitize_text_field($license_key),
            'domain' => sanitize_text_field($domain)
        );

        // Öncelikle çalışan endpoint'i dene
        $primary_endpoint = '/api/validate_license';
        $response = $this->make_request($primary_endpoint, $request_data, 'GET');
        
        if (!is_wp_error($response)) {
            return $response;
        }

        // Yedek endpoint'leri dene
        $fallback_endpoints = array(
            '/wp-json/balkay-license/v1/status',
            '/api/check_status',
            '/wp-admin/admin-ajax.php',
            '/?action=check_status',
        );

        foreach ($fallback_endpoints as $endpoint) {
            if ($endpoint === '/wp-admin/admin-ajax.php') {
                $ajax_data = array_merge($request_data, array('action' => 'check_license_status'));
                $response = $this->make_request($endpoint, $ajax_data, 'POST');
            } elseif ($endpoint === '/?action=check_status') {
                $response = $this->make_request($endpoint, $request_data, 'GET');
            } else {
                $response = $this->make_request($endpoint, $request_data, 'POST');
            }
            
            if (!is_wp_error($response)) {
                return $response;
            }
        }
        
        // In case of communication error, return last known status
        $last_status = get_option('insurance_crm_license_status', 'inactive');
        return array(
            'status' => $last_status,
            'message' => 'Sunucu ile iletişim kurulamadı, son bilinen durum kullanılıyor',
            'offline' => true
        );
    }

    /**
     * Make HTTP request to license server
     * 
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @param string $method HTTP method (GET, POST)
     * @return array|WP_Error Response data or error
     */
    private function make_request($endpoint, $data = array(), $method = 'GET') {
        $url = rtrim($this->license_server_url, '/') . $endpoint;
        
        $args = array(
            'method' => $method,
            'timeout' => $this->timeout,
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'Insurance-CRM/' . INSURANCE_CRM_VERSION . '; ' . home_url(),
                'Accept' => 'application/json'
            ),
            'sslverify' => false, // SSL doğrulamayı devre dışı bırak (test için)
            'redirection' => 5
        );

        if ($method === 'POST') {
            if (strpos($endpoint, 'admin-ajax.php') !== false) {
                // WordPress AJAX expects form data, not JSON
                $args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
                $args['body'] = http_build_query($data);
            } else {
                $args['body'] = json_encode($data);
            }
        } else {
            if (!empty($data)) {
                $url = add_query_arg($data, $url);
            }
        }

        // Her zaman debug bilgilerini logla
        error_log('[LISANS DEBUG] İstek URL: ' . $url);
        error_log('[LISANS DEBUG] İstek Metodu: ' . $method);
        error_log('[LISANS DEBUG] İstek Verisi: ' . json_encode($data));
        error_log('[LISANS DEBUG] İstek Başlıkları: ' . json_encode($args['headers']));

        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('[LISANS HATA] API İsteği Başarısız: ' . $error_message);
            
            // Daha detaylı hata mesajı
            return new WP_Error(
                'api_connection_error',
                sprintf('Lisans sunucusuna bağlanılamadı: %s (URL: %s)', $error_message, $url)
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response);

        // Her zaman response bilgilerini logla
        error_log('[LISANS DEBUG] Yanıt Kodu: ' . $response_code);
        error_log('[LISANS DEBUG] Yanıt Boyutu: ' . strlen($response_body) . ' byte');
        error_log('[LISANS DEBUG] Yanıt İçeriği (ilk 500 karakter): ' . substr($response_body, 0, 500));
        error_log('[LISANS DEBUG] Yanıt Başlıkları: ' . json_encode($response_headers));

        // Başarılı HTTP kodlarını genişlet
        $successful_codes = array(200, 201, 202);
        if (!in_array($response_code, $successful_codes)) {
            error_log('[LISANS HATA] HTTP Hatası: ' . $response_code);
            
            // 404 hatası özel olarak işle
            if ($response_code == 404) {
                return new WP_Error(
                    'api_endpoint_not_found',
                    sprintf('API endpoint bulunamadı (404): %s', $url)
                );
            }
            
            return new WP_Error(
                'api_http_error',
                sprintf('Lisans sunucusundan HTTP hatası (Kod: %d): %s', $response_code, substr($response_body, 0, 200))
            );
        }

        // Boş yanıt kontrolü
        if (empty($response_body)) {
            error_log('[LISANS HATA] Boş yanıt alındı');
            return new WP_Error(
                'api_empty_response',
                'Lisans sunucusundan boş yanıt alındı'
            );
        }

        // HTML yanıt kontrolü (JSON bekliyoruz)
        if (strpos($response_body, '<!DOCTYPE') === 0 || strpos($response_body, '<html') !== false) {
            error_log('[LISANS HATA] HTML yanıt alındı, JSON bekliyordu');
            return new WP_Error(
                'api_html_response',
                'Sunucu HTML yanıt döndürdü, JSON bekliyordu. Bu endpoint mevcut değil olabilir.'
            );
        }

        $decoded_response = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[LISANS HATA] JSON Parse Hatası: ' . json_last_error_msg());
            return new WP_Error(
                'json_parse_error',
                sprintf('Sunucu yanıtı çözümlenemedi (JSON Hatası: %s): %s', json_last_error_msg(), substr($response_body, 0, 200))
            );
        }

        error_log('[LISANS DEBUG] Başarılı yanıt alındı: ' . json_encode($decoded_response));
        return $decoded_response;
    }

    /**
     * Get fallback/demo license data for testing
     * 
     * @return array Demo license data
     */
    public function get_demo_license_data() {
        return array(
            'status' => 'active',
            'user_limit' => 5,
            'expires_on' => date('Y-m-d', strtotime('+30 days')),
            'license_type' => 'monthly',
            'modules' => array('customers', 'policies', 'tasks', 'reports'),
            'message' => 'Demo lisans - test amaçlı'
        );
    }

    /**
     * Check if bypass license is enabled (for development)
     * 
     * @return bool True if bypass is enabled
     */
    public function is_license_bypassed() {
        return get_option('insurance_crm_bypass_license', false);
    }

    /**
     * Set license server URL
     * 
     * @param string $url License server URL
     */
    public function set_license_server_url($url) {
        $this->license_server_url = $url;
        update_option('insurance_crm_license_server_url', $url);
    }

    /**
     * Enable/disable debug mode
     * 
     * @param bool $enabled Debug mode status
     */
    public function set_debug_mode($enabled) {
        $this->debug_mode = $enabled;
        update_option('insurance_crm_license_debug_mode', $enabled);
    }

    /**
     * Test basic connectivity to license server
     * 
     * @return array Test results
     */
    public function test_server_connection() {
        $server_url = $this->license_server_url;
        $results = array();
        
        // Test 1: Basic HTTP connectivity
        error_log('[LISANS DEBUG] Temel bağlantı testi başlatılıyor: ' . $server_url);
        
        $response = wp_remote_get($server_url, array(
            'timeout' => 15,
            'sslverify' => false,
            'redirection' => 5
        ));
        
        if (is_wp_error($response)) {
            $results['connectivity'] = array(
                'success' => false,
                'message' => 'Temel bağlantı başarısız: ' . $response->get_error_message()
            );
        } else {
            $code = wp_remote_retrieve_response_code($response);
            $results['connectivity'] = array(
                'success' => true,
                'message' => 'Temel bağlantı başarılı (HTTP ' . $code . ')',
                'code' => $code
            );
        }
        
        // Test 2: Check available endpoints
        $endpoints_to_test = array(
            '/wp-json/balkay-license/v1/validate',
            '/api/validate_license',
            '/wp-admin/admin-ajax.php',
            '/wp-json/wp/v2'  // WordPress REST API base
        );
        
        $results['endpoints'] = array();
        
        foreach ($endpoints_to_test as $endpoint) {
            $test_url = rtrim($server_url, '/') . $endpoint;
            $test_response = wp_remote_get($test_url, array(
                'timeout' => 10,
                'sslverify' => false
            ));
            
            if (!is_wp_error($test_response)) {
                $test_code = wp_remote_retrieve_response_code($test_response);
                $test_body = wp_remote_retrieve_body($test_response);
                
                $results['endpoints'][$endpoint] = array(
                    'code' => $test_code,
                    'accessible' => in_array($test_code, array(200, 201, 400, 405)), // 400/405 means endpoint exists but wrong method/params
                    'body_preview' => substr($test_body, 0, 100)
                );
            } else {
                $results['endpoints'][$endpoint] = array(
                    'code' => null,
                    'accessible' => false,
                    'error' => $test_response->get_error_message()
                );
            }
        }
        
        // Test 3: Check if it's a WordPress site
        $wp_json_url = rtrim($server_url, '/') . '/wp-json/wp/v2';
        $wp_response = wp_remote_get($wp_json_url, array('timeout' => 10, 'sslverify' => false));
        
        if (!is_wp_error($wp_response) && wp_remote_retrieve_response_code($wp_response) == 200) {
            $results['is_wordpress'] = true;
            $results['wordpress_api'] = 'Erişilebilir';
        } else {
            $results['is_wordpress'] = false;
            $results['wordpress_api'] = 'Erişilemez';
        }
        
        return $results;
    }
}
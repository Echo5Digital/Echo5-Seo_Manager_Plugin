<?php
/**
 * Security Handler - API key verification and rate limiting
 */

class Echo5_SEO_Security {
    
    /**
     * Verify API key from request
     */
    public function verify_api_key($request) {
        $api_key = $this->get_api_key_from_request($request);
        $stored_key = get_option('echo5_seo_api_key');
        
        if (!$api_key || !$stored_key) {
            return new WP_Error(
                'missing_api_key',
                'API key is required',
                array('status' => 401)
            );
        }
        
        if ($api_key !== $stored_key) {
            // Log failed attempt
            $this->log_failed_attempt();
            
            return new WP_Error(
                'invalid_api_key',
                'Invalid API key',
                array('status' => 403)
            );
        }
        
        // Check rate limiting
        if (!$this->check_rate_limit()) {
            return new WP_Error(
                'rate_limit_exceeded',
                'Rate limit exceeded. Please try again later.',
                array('status' => 429)
            );
        }
        
        // Check IP whitelist if enabled
        if (get_option('echo5_seo_enable_ip_whitelist') === '1') {
            if (!$this->is_ip_whitelisted()) {
                return new WP_Error(
                    'ip_not_whitelisted',
                    'Your IP address is not whitelisted',
                    array('status' => 403)
                );
            }
        }
        
        return true;
    }
    
    /**
     * Verify admin permission
     */
    public function verify_admin($request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'forbidden',
                'You do not have permission to perform this action',
                array('status' => 403)
            );
        }
        
        return true;
    }
    
    /**
     * Get API key from request headers or query params
     */
    private function get_api_key_from_request($request) {
        // Try Authorization header first
        $auth_header = $request->get_header('authorization');
        if ($auth_header && strpos($auth_header, 'Bearer ') === 0) {
            return substr($auth_header, 7);
        }
        
        // Try X-API-Key header
        $api_key_header = $request->get_header('x-api-key');
        if ($api_key_header) {
            return $api_key_header;
        }
        
        // Try query parameter from WP_REST_Request
        $api_key = $request->get_param('api_key');
        if ($api_key) {
            return $api_key;
        }
        
        // Try $_GET directly as fallback
        if (isset($_GET['api_key']) && !empty($_GET['api_key'])) {
            return sanitize_text_field($_GET['api_key']);
        }
        
        return null;
    }
    
    /**
     * Check rate limiting
     */
    private function check_rate_limit() {
        if (get_option('echo5_seo_enable_rate_limit') !== '1') {
            return true;
        }
        
        $ip = $this->get_client_ip();
        $transient_key = 'echo5_rate_limit_' . md5($ip);
        $requests = get_transient($transient_key);
        
        $max_requests = intval(get_option('echo5_seo_rate_limit_max', 60));
        $time_window = intval(get_option('echo5_seo_rate_limit_window', 60)); // seconds
        
        if ($requests === false) {
            // First request in window
            set_transient($transient_key, 1, $time_window);
            return true;
        }
        
        if ($requests >= $max_requests) {
            return false;
        }
        
        // Increment counter
        set_transient($transient_key, $requests + 1, $time_window);
        return true;
    }
    
    /**
     * Check if IP is whitelisted
     */
    private function is_ip_whitelisted() {
        $ip = $this->get_client_ip();
        $whitelist = get_option('echo5_seo_ip_whitelist', '');
        
        if (empty($whitelist)) {
            return true;
        }
        
        $allowed_ips = array_map('trim', explode("\n", $whitelist));
        
        foreach ($allowed_ips as $allowed_ip) {
            if (empty($allowed_ip)) {
                continue;
            }
            
            // Support CIDR notation
            if (strpos($allowed_ip, '/') !== false) {
                if ($this->ip_in_range($ip, $allowed_ip)) {
                    return true;
                }
            } else {
                if ($ip === $allowed_ip) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_keys as $key) {
            if (isset($_SERVER[$key]) && !empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                
                // Handle multiple IPs in X-Forwarded-For
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }
    
    /**
     * Check if IP is in CIDR range
     */
    private function ip_in_range($ip, $cidr) {
        list($subnet, $mask) = explode('/', $cidr);
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip_long = ip2long($ip);
            $subnet_long = ip2long($subnet);
            $mask_long = -1 << (32 - (int)$mask);
            
            return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
        }
        
        // IPv6 support would go here
        return false;
    }
    
    /**
     * Log failed authentication attempt
     */
    private function log_failed_attempt() {
        $ip = $this->get_client_ip();
        $attempts = get_transient('echo5_failed_attempts_' . md5($ip));
        
        if ($attempts === false) {
            $attempts = 0;
        }
        
        $attempts++;
        set_transient('echo5_failed_attempts_' . md5($ip), $attempts, 3600);
        
        // Log to WordPress
        error_log("Echo5 SEO Exporter: Failed API authentication attempt from IP: {$ip}");
    }
}

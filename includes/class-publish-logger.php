<?php
/**
 * Echo5 Publish Logger - Audit Trail & Activity Logging
 * 
 * Enterprise features:
 * - Structured logging with correlation IDs
 * - Publish action audit trail
 * - Error tracking with context
 * - Webhook notifications
 * 
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Echo5_Publish_Logger {
    
    // Log table name
    private $table_name;
    
    // Current correlation ID
    private $correlation_id;
    
    // Webhook URL for notifications
    private $webhook_url;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'echo5_publish_logs';
        $this->correlation_id = $this->generate_correlation_id();
        $this->webhook_url = get_option('echo5_publish_webhook_url', '');
    }
    
    /**
     * Create log table on plugin activation
     */
    public static function create_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'echo5_publish_logs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            correlation_id varchar(36) NOT NULL,
            action varchar(50) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'info',
            page_id bigint(20) unsigned DEFAULT NULL,
            page_slug varchar(200) DEFAULT NULL,
            user_ip varchar(45) DEFAULT NULL,
            request_data longtext DEFAULT NULL,
            response_data longtext DEFAULT NULL,
            error_message text DEFAULT NULL,
            response_time_ms int(11) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_correlation_id (correlation_id),
            KEY idx_action (action),
            KEY idx_page_id (page_id),
            KEY idx_created_at (created_at),
            KEY idx_status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Generate unique correlation ID
     * 
     * @return string UUID v4
     */
    private function generate_correlation_id() {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }
        
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Get current correlation ID
     * 
     * @return string
     */
    public function get_correlation_id() {
        return $this->correlation_id;
    }
    
    /**
     * Set correlation ID (for request tracing)
     * 
     * @param string $id
     */
    public function set_correlation_id($id) {
        $this->correlation_id = $id;
    }
    
    /**
     * Log an action
     * 
     * @param string $action Action name
     * @param array $data Additional data
     * @param string $status Status (info, success, warning, error)
     * @return int|false Insert ID or false on failure
     */
    public function log($action, $data = array(), $status = 'info') {
        global $wpdb;
        
        $log_data = array(
            'correlation_id' => $this->correlation_id,
            'action' => sanitize_text_field($action),
            'status' => sanitize_text_field($status),
            'page_id' => isset($data['page_id']) ? intval($data['page_id']) : null,
            'page_slug' => isset($data['slug']) ? sanitize_text_field($data['slug']) : null,
            'user_ip' => $this->get_client_ip(),
            'response_time_ms' => isset($data['response_time_ms']) ? intval($data['response_time_ms']) : null,
            'error_message' => isset($data['error']) ? sanitize_text_field($data['error']) : null,
            'created_at' => current_time('mysql'),
        );
        
        // Store request/response data (sanitized)
        if (isset($data['request'])) {
            $log_data['request_data'] = wp_json_encode($this->sanitize_log_data($data['request']));
        }
        
        if (isset($data['response'])) {
            $log_data['response_data'] = wp_json_encode($this->sanitize_log_data($data['response']));
        }
        
        // Also write to error_log for immediate visibility
        $log_message = sprintf(
            '[Echo5 Publisher] [%s] [%s] %s - %s',
            $this->correlation_id,
            strtoupper($status),
            $action,
            json_encode(array_filter($data))
        );
        error_log($log_message);
        
        // Insert to database
        $result = $wpdb->insert($this->table_name, $log_data);
        
        // Send webhook for errors or important actions
        if ($status === 'error' || in_array($action, array('publish_success', 'publish_failed', 'rollback_success'))) {
            $this->send_webhook($action, $status, $data);
        }
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Log publish start
     * 
     * @param array $request_data
     * @return int Log ID
     */
    public function log_publish_start($request_data) {
        return $this->log('publish_start', array(
            'slug' => isset($request_data['page']['slug']) ? $request_data['page']['slug'] : 'unknown',
            'request' => $request_data,
        ), 'info');
    }
    
    /**
     * Log publish success
     * 
     * @param int $page_id
     * @param string $slug
     * @param string $action Created or updated
     * @param int $response_time_ms
     * @return int Log ID
     */
    public function log_publish_success($page_id, $slug, $action, $response_time_ms = 0) {
        return $this->log('publish_success', array(
            'page_id' => $page_id,
            'slug' => $slug,
            'action' => $action,
            'response_time_ms' => $response_time_ms,
        ), 'success');
    }
    
    /**
     * Log publish error
     * 
     * @param string $slug
     * @param string $error Error message
     * @param array $context Additional context
     * @return int Log ID
     */
    public function log_publish_error($slug, $error, $context = array()) {
        return $this->log('publish_error', array_merge(array(
            'slug' => $slug,
            'error' => $error,
        ), $context), 'error');
    }
    
    /**
     * Get logs for a specific page
     * 
     * @param int $page_id
     * @param int $limit
     * @return array
     */
    public function get_logs_for_page($page_id, $limit = 50) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE page_id = %d ORDER BY created_at DESC LIMIT %d",
            $page_id,
            $limit
        ), ARRAY_A);
    }
    
    /**
     * Get recent logs
     * 
     * @param int $limit
     * @param string $status Filter by status
     * @return array
     */
    public function get_recent_logs($limit = 100, $status = null) {
        global $wpdb;
        
        $sql = "SELECT * FROM {$this->table_name}";
        
        if ($status) {
            $sql .= $wpdb->prepare(" WHERE status = %s", $status);
        }
        
        $sql .= $wpdb->prepare(" ORDER BY created_at DESC LIMIT %d", $limit);
        
        return $wpdb->get_results($sql, ARRAY_A);
    }
    
    /**
     * Get logs by correlation ID
     * 
     * @param string $correlation_id
     * @return array
     */
    public function get_logs_by_correlation($correlation_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE correlation_id = %s ORDER BY created_at ASC",
            $correlation_id
        ), ARRAY_A);
    }
    
    /**
     * Get publish statistics
     * 
     * @param string $period Period (day, week, month)
     * @return array
     */
    public function get_statistics($period = 'day') {
        global $wpdb;
        
        $date_filter = '';
        switch ($period) {
            case 'day':
                $date_filter = "AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
                break;
            case 'week':
                $date_filter = "AND created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
                break;
            case 'month':
                $date_filter = "AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
                break;
        }
        
        // Total publishes
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE action = 'publish_success' {$date_filter}");
        
        // Success rate
        $success = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE action = 'publish_success' {$date_filter}");
        $errors = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE action = 'publish_error' {$date_filter}");
        
        $success_rate = ($success + $errors) > 0 ? round(($success / ($success + $errors)) * 100, 1) : 100;
        
        // Average response time
        $avg_time = $wpdb->get_var("SELECT AVG(response_time_ms) FROM {$this->table_name} WHERE response_time_ms IS NOT NULL {$date_filter}");
        
        // Pages created vs updated
        $created = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE action = 'publish_success' AND response_data LIKE '%\"action\":\"created\"%' {$date_filter}");
        $updated = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE action = 'publish_success' AND response_data LIKE '%\"action\":\"updated\"%' {$date_filter}");
        
        // Recent errors
        $recent_errors = $wpdb->get_results(
            "SELECT page_slug, error_message, created_at FROM {$this->table_name} WHERE status = 'error' {$date_filter} ORDER BY created_at DESC LIMIT 5",
            ARRAY_A
        );
        
        return array(
            'period' => $period,
            'total_publishes' => intval($total),
            'success_count' => intval($success),
            'error_count' => intval($errors),
            'success_rate' => $success_rate,
            'avg_response_time_ms' => round(floatval($avg_time)),
            'pages_created' => intval($created),
            'pages_updated' => intval($updated),
            'recent_errors' => $recent_errors,
        );
    }
    
    /**
     * Cleanup old logs
     * 
     * @param int $days_to_keep
     * @return int Number of rows deleted
     */
    public function cleanup_old_logs($days_to_keep = 90) {
        global $wpdb;
        
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days_to_keep
        ));
    }
    
    /**
     * Send webhook notification
     * 
     * @param string $action
     * @param string $status
     * @param array $data
     */
    private function send_webhook($action, $status, $data) {
        if (empty($this->webhook_url)) {
            return;
        }
        
        $payload = array(
            'event' => 'echo5_publish_' . $action,
            'status' => $status,
            'correlation_id' => $this->correlation_id,
            'timestamp' => current_time('c'),
            'site_url' => get_site_url(),
            'data' => $this->sanitize_log_data($data),
        );
        
        wp_remote_post($this->webhook_url, array(
            'body' => wp_json_encode($payload),
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Echo5-Event' => $action,
                'X-Echo5-Correlation-ID' => $this->correlation_id,
            ),
            'timeout' => 5,
            'blocking' => false, // Non-blocking for performance
        ));
    }
    
    /**
     * Sanitize log data (remove sensitive info)
     * 
     * @param mixed $data
     * @return mixed
     */
    private function sanitize_log_data($data) {
        if (!is_array($data)) {
            return $data;
        }
        
        $sensitive_keys = array('api_key', 'password', 'secret', 'token', 'authorization');
        
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $sensitive_keys)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize_log_data($value);
            }
        }
        
        return $data;
    }
    
    /**
     * Get client IP address
     * 
     * @return string
     */
    private function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
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
     * Export logs as JSON
     * 
     * @param array $filters
     * @return string JSON
     */
    public function export_logs($filters = array()) {
        global $wpdb;
        
        $where = array('1=1');
        
        if (!empty($filters['start_date'])) {
            $where[] = $wpdb->prepare("created_at >= %s", $filters['start_date']);
        }
        
        if (!empty($filters['end_date'])) {
            $where[] = $wpdb->prepare("created_at <= %s", $filters['end_date']);
        }
        
        if (!empty($filters['status'])) {
            $where[] = $wpdb->prepare("status = %s", $filters['status']);
        }
        
        if (!empty($filters['action'])) {
            $where[] = $wpdb->prepare("action = %s", $filters['action']);
        }
        
        $sql = "SELECT * FROM {$this->table_name} WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC";
        
        if (!empty($filters['limit'])) {
            $sql .= $wpdb->prepare(" LIMIT %d", $filters['limit']);
        }
        
        $logs = $wpdb->get_results($sql, ARRAY_A);
        
        return wp_json_encode(array(
            'exported_at' => current_time('c'),
            'site_url' => get_site_url(),
            'total_records' => count($logs),
            'logs' => $logs,
        ), JSON_PRETTY_PRINT);
    }
}

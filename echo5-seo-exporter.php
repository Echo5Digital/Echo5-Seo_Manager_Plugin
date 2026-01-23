<?php
/**
 * Plugin Name: Echo5 Seo Manager Plugin
 * Plugin URI: https://echo5digital.com
 * Description: Exports complete SEO data via REST API for Echo5 SEO Management Platform - Now with Publishing support!
 * Version: 2.1.7
 * Author: Echo5 Digital
 * Author URI: https://echo5digital.com
 * License: GPL v2 or later
 * Text Domain: echo5-seo-manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ECHO5_SEO_VERSION', '2.1.7');
define('ECHO5_SEO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ECHO5_SEO_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files - Core
require_once ECHO5_SEO_PLUGIN_DIR . 'includes/class-api-handler.php';
require_once ECHO5_SEO_PLUGIN_DIR . 'includes/class-data-exporter.php';
require_once ECHO5_SEO_PLUGIN_DIR . 'includes/class-security.php';
require_once ECHO5_SEO_PLUGIN_DIR . 'includes/class-updater.php';
require_once ECHO5_SEO_PLUGIN_DIR . 'admin/class-settings.php';

// Include required files - Publisher (v2.0)
require_once ECHO5_SEO_PLUGIN_DIR . 'includes/class-publisher.php';
require_once ECHO5_SEO_PLUGIN_DIR . 'includes/class-media-handler.php';
require_once ECHO5_SEO_PLUGIN_DIR . 'includes/class-seo-meta-handler.php';
require_once ECHO5_SEO_PLUGIN_DIR . 'includes/class-publish-logger.php';

// Include required files - Brand Extraction (v2.1)
require_once ECHO5_SEO_PLUGIN_DIR . 'includes/class-brand-extractor.php';

/**
 * Main Plugin Class
 */
class Echo5_SEO_Exporter {
    
    private static $instance = null;
    private static $plugin_file = null;
    private $api_handler;
    private $data_exporter;
    private $security;
    private $settings;
    private $updater;
    
    // Publisher components (v2.0)
    private $publisher;
    private $media_handler;
    private $seo_meta_handler;
    private $publish_logger;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Set plugin file
     */
    public static function set_plugin_file($file) {
        self::$plugin_file = $file;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init();
    }
    
    /**
     * Initialize plugin
     */
    private function init() {
        // Initialize core classes
        $this->security = new Echo5_SEO_Security();
        $this->data_exporter = new Echo5_SEO_Data_Exporter();
        $this->api_handler = new Echo5_SEO_API_Handler($this->data_exporter, $this->security);
        $this->settings = new Echo5_SEO_Settings();
        $this->updater = new Echo5_SEO_Updater(self::$plugin_file);
        
        // Initialize publisher components (v2.0)
        $this->media_handler = new Echo5_Media_Handler();
        $this->seo_meta_handler = new Echo5_SEO_Meta_Handler();
        $this->publish_logger = new Echo5_Publish_Logger();
        $this->publisher = new Echo5_Publisher(
            $this->security,
            $this->media_handler,
            $this->seo_meta_handler,
            $this->publish_logger
        );
        
        // Register hooks
        add_action('rest_api_init', array($this->api_handler, 'register_routes'));
        add_action('rest_api_init', array($this->publisher, 'register_routes'));
        add_action('admin_menu', array($this->settings, 'add_settings_page'));
        add_action('admin_init', array($this->settings, 'register_settings'));
        
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Generate initial API key
        if (!get_option('echo5_seo_api_key')) {
            update_option('echo5_seo_api_key', $this->generate_api_key());
        }
        
        // Set default options
        if (!get_option('echo5_seo_enable_caching')) {
            update_option('echo5_seo_enable_caching', '1');
        }
        
        // Enable HMAC verification by default (v2.0)
        if (!get_option('echo5_publisher_hmac_enabled')) {
            update_option('echo5_publisher_hmac_enabled', '1');
        }
        
        // Create publish logs table (v2.0)
        $this->create_publish_logs_table();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Create publish logs database table
     */
    private function create_publish_logs_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'echo5_publish_logs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            correlation_id varchar(100) NOT NULL,
            action varchar(50) NOT NULL,
            page_id bigint(20) UNSIGNED DEFAULT NULL,
            page_slug varchar(255) DEFAULT NULL,
            page_title text DEFAULT NULL,
            status varchar(50) NOT NULL DEFAULT 'pending',
            message text DEFAULT NULL,
            request_data longtext DEFAULT NULL,
            response_data longtext DEFAULT NULL,
            user_ip varchar(100) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY correlation_id (correlation_id),
            KEY action (action),
            KEY status (status),
            KEY page_id (page_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up transients
        delete_transient('echo5_seo_pages_cache');
        delete_transient('echo5_seo_posts_cache');
        
        // Clear scheduled publish jobs
        wp_clear_scheduled_hook('echo5_scheduled_publish');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Generate secure API key
     */
    private function generate_api_key() {
        return 'echo5_' . bin2hex(random_bytes(32));
    }
}

// Initialize plugin
function echo5_seo_exporter_init() {
    Echo5_SEO_Exporter::set_plugin_file(__FILE__);
    return Echo5_SEO_Exporter::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'echo5_seo_exporter_init');

<?php
/**
 * Plugin Name: Echo5 SEO Data Exporter
 * Plugin URI: https://echo5digital.com
 * Description: Exports complete SEO data via REST API for Echo5 SEO Management Platform
 * Version: 1.0.0
 * Author: Echo5 Digital
 * Author URI: https://echo5digital.com
 * License: GPL v2 or later
 * Text Domain: echo5-seo-exporter
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ECHO5_SEO_VERSION', '1.0.0');
define('ECHO5_SEO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ECHO5_SEO_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once ECHO5_SEO_PLUGIN_DIR . 'includes/class-api-handler.php';
require_once ECHO5_SEO_PLUGIN_DIR . 'includes/class-data-exporter.php';
require_once ECHO5_SEO_PLUGIN_DIR . 'includes/class-security.php';
require_once ECHO5_SEO_PLUGIN_DIR . 'admin/class-settings.php';

/**
 * Main Plugin Class
 */
class Echo5_SEO_Exporter {
    
    private static $instance = null;
    private $api_handler;
    private $data_exporter;
    private $security;
    private $settings;
    
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
     * Constructor
     */
    private function __construct() {
        $this->init();
    }
    
    /**
     * Initialize plugin
     */
    private function init() {
        // Initialize classes
        $this->security = new Echo5_SEO_Security();
        $this->data_exporter = new Echo5_SEO_Data_Exporter();
        $this->api_handler = new Echo5_SEO_API_Handler($this->data_exporter, $this->security);
        $this->settings = new Echo5_SEO_Settings();
        
        // Register hooks
        add_action('rest_api_init', array($this->api_handler, 'register_routes'));
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
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up transients
        delete_transient('echo5_seo_pages_cache');
        delete_transient('echo5_seo_posts_cache');
        
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
    return Echo5_SEO_Exporter::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'echo5_seo_exporter_init');

<?php
/**
 * GitHub Auto Updater - Checks GitHub for plugin updates
 */

class Echo5_SEO_Updater {
    
    private $plugin_slug;
    private $plugin_basename;
    private $github_repo;
    private $version;
    private $cache_key;
    private $cache_allowed;
    
    /**
     * Constructor
     */
    public function __construct($plugin_file) {
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->plugin_basename = dirname($this->plugin_slug);
        $this->version = ECHO5_SEO_VERSION;
        $this->github_repo = 'Echo5Digital/Echo5-Seo_Manager_Plugin';
        $this->cache_key = 'echo5_seo_updater';
        $this->cache_allowed = true;
        
        // Hook into WordPress update system
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
        
        // Add custom update message
        add_action('in_plugin_update_message-' . $this->plugin_slug, array($this, 'update_message'), 10, 2);
    }
    
    /**
     * Check for plugin updates
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        // Get remote version info
        $remote_version = $this->get_remote_version();
        
        if ($remote_version && version_compare($this->version, $remote_version->version, '<')) {
            $obj = new stdClass();
            $obj->slug = $this->plugin_basename;
            $obj->plugin = $this->plugin_slug;
            $obj->new_version = $remote_version->version;
            $obj->url = $remote_version->html_url;
            $obj->package = $remote_version->download_url;
            $obj->tested = $remote_version->tested;
            $obj->requires = $remote_version->requires;
            $obj->requires_php = $remote_version->requires_php;
            
            $transient->response[$this->plugin_slug] = $obj;
        }
        
        return $transient;
    }
    
    /**
     * Get plugin information for WordPress update screen
     */
    public function plugin_info($false, $action, $response) {
        // Check if this is for our plugin
        if ($action !== 'plugin_information') {
            return $false;
        }
        
        if ($response->slug !== $this->plugin_basename) {
            return $false;
        }
        
        // Get remote version info
        $remote_version = $this->get_remote_version();
        
        if (!$remote_version) {
            return $false;
        }
        
        $obj = new stdClass();
        $obj->name = $remote_version->name;
        $obj->slug = $this->plugin_basename;
        $obj->version = $remote_version->version;
        $obj->author = '<a href="https://echo5digital.com">Echo5 Digital</a>';
        $obj->homepage = $remote_version->html_url;
        $obj->requires = $remote_version->requires;
        $obj->tested = $remote_version->tested;
        $obj->requires_php = $remote_version->requires_php;
        $obj->downloaded = 0;
        $obj->last_updated = $remote_version->published_at;
        $obj->sections = array(
            'description' => $remote_version->description,
            'changelog' => $remote_version->changelog,
        );
        $obj->download_link = $remote_version->download_url;
        
        return $obj;
    }
    
    /**
     * Get remote version information from GitHub
     */
    private function get_remote_version() {
        // Check cache first
        if ($this->cache_allowed) {
            $cached = get_transient($this->cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        // Fetch latest release from GitHub
        $api_url = "https://api.github.com/repos/{$this->github_repo}/releases/latest";
        
        $response = wp_remote_get($api_url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
            ),
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);
        
        if (empty($data) || !isset($data->tag_name)) {
            return false;
        }
        
        // Parse version info
        $version_info = new stdClass();
        $version_info->version = ltrim($data->tag_name, 'v');
        $version_info->name = $data->name ?: 'Echo5 Seo Manager Plugin';
        $version_info->html_url = "https://github.com/{$this->github_repo}";
        $version_info->download_url = $data->zipball_url;
        $version_info->published_at = $data->published_at;
        $version_info->description = $data->body ?: 'Update available for Echo5 Seo Manager Plugin.';
        $version_info->changelog = $this->format_changelog($data->body);
        
        // WordPress compatibility (you can set these in release notes or use defaults)
        $version_info->tested = '6.4';
        $version_info->requires = '5.0';
        $version_info->requires_php = '7.4';
        
        // Parse readme if available to get compatibility info
        $this->parse_readme_for_compatibility($version_info);
        
        // Cache for 12 hours
        if ($this->cache_allowed) {
            set_transient($this->cache_key, $version_info, 12 * HOUR_IN_SECONDS);
        }
        
        return $version_info;
    }
    
    /**
     * Format changelog from GitHub release notes
     */
    private function format_changelog($body) {
        if (empty($body)) {
            return '<h4>New Update Available</h4><p>Please check GitHub for details.</p>';
        }
        
        // Convert markdown to basic HTML
        $changelog = '<div class="echo5-changelog">';
        $changelog .= wpautop($body);
        $changelog .= '</div>';
        
        return $changelog;
    }
    
    /**
     * Parse readme.txt for WordPress compatibility info
     */
    private function parse_readme_for_compatibility(&$version_info) {
        $readme_url = "https://raw.githubusercontent.com/{$this->github_repo}/main/readme.txt";
        
        $response = wp_remote_get($readme_url, array('timeout' => 5));
        
        if (is_wp_error($response)) {
            return;
        }
        
        $readme_content = wp_remote_retrieve_body($response);
        
        // Parse tested up to
        if (preg_match('/Tested up to:\s*([0-9.]+)/i', $readme_content, $matches)) {
            $version_info->tested = $matches[1];
        }
        
        // Parse requires at least
        if (preg_match('/Requires at least:\s*([0-9.]+)/i', $readme_content, $matches)) {
            $version_info->requires = $matches[1];
        }
        
        // Parse requires PHP
        if (preg_match('/Requires PHP:\s*([0-9.]+)/i', $readme_content, $matches)) {
            $version_info->requires_php = $matches[1];
        }
    }
    
    /**
     * After install/update, rename the GitHub folder to correct plugin name
     * GitHub downloads come with folder names like "Echo5Digital-Echo5-Seo_Manager_Plugin-abc1234"
     * We need to rename this to "echo5-seo-exporter" for WordPress to recognize it
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;
        
        // Make sure we're working with the right plugin
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_slug) {
            return $result;
        }
        
        // The correct plugin folder name
        $proper_folder = WP_PLUGIN_DIR . '/echo5-seo-exporter';
        
        // The destination folder from GitHub (has weird name like Echo5Digital-Echo5-Seo_Manager_Plugin-abc1234)
        $installed_folder = $result['destination'];
        
        // Log for debugging
        error_log('Echo5 Updater: Installed folder: ' . $installed_folder);
        error_log('Echo5 Updater: Proper folder: ' . $proper_folder);
        
        // If already correct, skip
        if ($installed_folder === $proper_folder) {
            error_log('Echo5 Updater: Folder already correct');
            return $result;
        }
        
        // Remove the old plugin folder first (if exists and is different)
        if ($wp_filesystem->exists($proper_folder)) {
            error_log('Echo5 Updater: Removing old folder');
            $wp_filesystem->delete($proper_folder, true);
        }
        
        // Move/rename the new folder to the correct name
        $moved = $wp_filesystem->move($installed_folder, $proper_folder);
        
        if ($moved) {
            error_log('Echo5 Updater: Folder moved successfully');
            $result['destination'] = $proper_folder;
            
            // Reactivate the plugin
            activate_plugin('echo5-seo-exporter/echo5-seo-exporter.php');
            error_log('Echo5 Updater: Plugin reactivated');
        } else {
            error_log('Echo5 Updater: Failed to move folder');
        }
        
        return $result;
    }
    
    /**
     * Display custom update message
     */
    public function update_message($plugin_data, $response) {
        if (empty($response->new_version)) {
            return;
        }
        
        echo '<br><strong>⚠️ Important:</strong> Please backup your site before updating. ';
        echo sprintf(
            '<a href="%s" target="_blank">View release notes on GitHub</a>',
            esc_url("https://github.com/{$this->github_repo}/releases")
        );
    }
    
    /**
     * Clear update cache (useful for testing)
     */
    public function clear_cache() {
        delete_transient($this->cache_key);
    }
    
    /**
     * Force check for updates (for debugging)
     */
    public function force_check() {
        $this->cache_allowed = false;
        delete_transient($this->cache_key);
        delete_site_transient('update_plugins');
        wp_update_plugins();
    }
}

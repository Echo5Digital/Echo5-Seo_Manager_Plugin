<?php
/**
 * Admin Settings Page
 */

class Echo5_SEO_Settings {
    
    /**
     * Add settings page to WordPress admin
     */
    public function add_settings_page() {
        add_options_page(
            'Echo5 SEO Exporter Settings',
            'Echo5 SEO Exporter',
            'manage_options',
            'echo5-seo-exporter',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('echo5_seo_settings', 'echo5_seo_api_key');
        register_setting('echo5_seo_settings', 'echo5_seo_enable_caching');
        register_setting('echo5_seo_settings', 'echo5_seo_enable_rate_limit');
        register_setting('echo5_seo_settings', 'echo5_seo_rate_limit_max');
        register_setting('echo5_seo_settings', 'echo5_seo_rate_limit_window');
        register_setting('echo5_seo_settings', 'echo5_seo_enable_ip_whitelist');
        register_setting('echo5_seo_settings', 'echo5_seo_ip_whitelist');
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle API key regeneration
        if (isset($_POST['regenerate_key']) && check_admin_referer('echo5_regenerate_key')) {
            $new_key = 'echo5_' . bin2hex(random_bytes(32));
            update_option('echo5_seo_api_key', $new_key);
            echo '<div class="notice notice-success"><p>API key regenerated successfully!</p></div>';
        }
        
        $api_key = get_option('echo5_seo_api_key');
        $site_url = get_site_url();
        $api_base = rest_url('echo5-seo/v1');
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>🔑 API Key</h2>
                <p>Use this API key to authenticate requests to the Echo5 SEO Exporter API.</p>
                
                <table class="form-table">
                    <tr>
                        <th>API Key:</th>
                        <td>
                            <input type="text" value="<?php echo esc_attr($api_key); ?>" readonly style="width: 100%; font-family: monospace;" onclick="this.select();" />
                            <p class="description">Click to select and copy</p>
                        </td>
                    </tr>
                    <tr>
                        <th>API Base URL:</th>
                        <td>
                            <code style="display: block; padding: 10px; background: #f0f0f1; border-radius: 4px;">
                                <?php echo esc_url($api_base); ?>
                            </code>
                        </td>
                    </tr>
                </table>
                
                <form method="post" style="margin-top: 20px;">
                    <?php wp_nonce_field('echo5_regenerate_key'); ?>
                    <button type="submit" name="regenerate_key" class="button button-secondary" 
                            onclick="return confirm('Are you sure? This will invalidate the current API key.');">
                        🔄 Regenerate API Key
                    </button>
                </form>
            </div>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>📡 API Endpoints</h2>
                <p>Available REST API endpoints:</p>
                
                <table class="widefat striped" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th>Endpoint</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>GET /content/all</code></td>
                            <td>Get all pages and posts with full SEO data</td>
                        </tr>
                        <tr>
                            <td><code>GET /pages</code></td>
                            <td>Get all pages with SEO data</td>
                        </tr>
                        <tr>
                            <td><code>GET /pages/{id}</code></td>
                            <td>Get single page with full data</td>
                        </tr>
                        <tr>
                            <td><code>GET /posts</code></td>
                            <td>Get all posts with SEO data</td>
                        </tr>
                        <tr>
                            <td><code>GET /structure</code></td>
                            <td>Get site navigation structure</td>
                        </tr>
                        <tr>
                            <td><code>GET /links/internal</code></td>
                            <td>Get internal links map</td>
                        </tr>
                        <tr>
                            <td><code>GET /seo-plugins</code></td>
                            <td>Get active SEO plugins info</td>
                        </tr>
                        <tr>
                            <td><code>GET /health</code></td>
                            <td>Health check endpoint</td>
                        </tr>
                    </tbody>
                </table>
                
                <h3 style="margin-top: 30px;">Example Request:</h3>
                <pre style="background: #23282d; color: #f0f0f1; padding: 15px; border-radius: 4px; overflow-x: auto;">
curl -X GET "<?php echo esc_url($api_base); ?>/content/all?per_page=50" \
  -H "X-API-Key: <?php echo esc_attr($api_key); ?>"</pre>
            </div>
            
            <form method="post" action="options.php" style="margin-top: 20px;">
                <?php settings_fields('echo5_seo_settings'); ?>
                
                <div class="card" style="max-width: 800px;">
                    <h2>⚙️ Settings</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th>Enable Caching:</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="echo5_seo_enable_caching" value="1" 
                                           <?php checked(get_option('echo5_seo_enable_caching'), '1'); ?> />
                                    Cache API responses for 5 minutes (improves performance)
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>Enable Rate Limiting:</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="echo5_seo_enable_rate_limit" value="1" 
                                           <?php checked(get_option('echo5_seo_enable_rate_limit'), '1'); ?> />
                                    Limit API requests per IP address
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>Max Requests:</th>
                            <td>
                                <input type="number" name="echo5_seo_rate_limit_max" 
                                       value="<?php echo esc_attr(get_option('echo5_seo_rate_limit_max', 60)); ?>" 
                                       min="1" style="width: 100px;" />
                                <p class="description">Maximum requests per time window</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Time Window:</th>
                            <td>
                                <input type="number" name="echo5_seo_rate_limit_window" 
                                       value="<?php echo esc_attr(get_option('echo5_seo_rate_limit_window', 60)); ?>" 
                                       min="1" style="width: 100px;" /> seconds
                            </td>
                        </tr>
                        <tr>
                            <th>Enable IP Whitelist:</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="echo5_seo_enable_ip_whitelist" value="1" 
                                           <?php checked(get_option('echo5_seo_enable_ip_whitelist'), '1'); ?> />
                                    Only allow specific IP addresses
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>Allowed IP Addresses:</th>
                            <td>
                                <textarea name="echo5_seo_ip_whitelist" rows="5" style="width: 100%; font-family: monospace;"><?php 
                                    echo esc_textarea(get_option('echo5_seo_ip_whitelist', '')); 
                                ?></textarea>
                                <p class="description">One IP per line. Supports CIDR notation (e.g., 192.168.1.0/24)</p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button(); ?>
                </div>
            </form>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>📊 Status</h2>
                <table class="form-table">
                    <tr>
                        <th>Plugin Version:</th>
                        <td><?php echo esc_html(ECHO5_SEO_VERSION); ?></td>
                    </tr>
                    <tr>
                        <th>WordPress Version:</th>
                        <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                    </tr>
                    <tr>
                        <th>PHP Version:</th>
                        <td><?php echo esc_html(PHP_VERSION); ?></td>
                    </tr>
                    <tr>
                        <th>Active SEO Plugins:</th>
                        <td>
                            <?php
                            $seo_plugins = array();
                            if (defined('WPSEO_VERSION')) $seo_plugins[] = 'Yoast SEO ' . WPSEO_VERSION;
                            if (defined('RANK_MATH_VERSION')) $seo_plugins[] = 'Rank Math ' . RANK_MATH_VERSION;
                            if (defined('AIOSEO_VERSION')) $seo_plugins[] = 'All in One SEO ' . AIOSEO_VERSION;
                            
                            echo $seo_plugins ? esc_html(implode(', ', $seo_plugins)) : 'None detected';
                            ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }
}

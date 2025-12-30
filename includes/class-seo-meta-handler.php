<?php
/**
 * Echo5 SEO Meta Handler - Multi-Plugin SEO Meta Management
 * 
 * Supports:
 * - Yoast SEO
 * - RankMath
 * - All in One SEO (AIOSEO)
 * - SEOPress
 * - The SEO Framework
 * - Generic fallback (wp_postmeta)
 * 
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Echo5_SEO_Meta_Handler {
    
    // Detected SEO plugin
    private $active_plugin = null;
    
    // Plugin detection flags
    private $plugins_checked = false;
    
    // Meta key mappings per plugin
    private $meta_keys = array(
        'yoast' => array(
            'title' => '_yoast_wpseo_title',
            'description' => '_yoast_wpseo_metadesc',
            'focus_keyword' => '_yoast_wpseo_focuskw',
            'canonical' => '_yoast_wpseo_canonical',
            'robots_noindex' => '_yoast_wpseo_meta-robots-noindex',
            'robots_nofollow' => '_yoast_wpseo_meta-robots-nofollow',
            'og_title' => '_yoast_wpseo_opengraph-title',
            'og_description' => '_yoast_wpseo_opengraph-description',
            'og_image' => '_yoast_wpseo_opengraph-image',
            'twitter_title' => '_yoast_wpseo_twitter-title',
            'twitter_description' => '_yoast_wpseo_twitter-description',
            'twitter_image' => '_yoast_wpseo_twitter-image',
            'schema' => '_yoast_wpseo_schema_page_type',
        ),
        'rankmath' => array(
            'title' => 'rank_math_title',
            'description' => 'rank_math_description',
            'focus_keyword' => 'rank_math_focus_keyword',
            'canonical' => 'rank_math_canonical_url',
            'robots' => 'rank_math_robots',
            'og_title' => 'rank_math_facebook_title',
            'og_description' => 'rank_math_facebook_description',
            'og_image' => 'rank_math_facebook_image',
            'twitter_title' => 'rank_math_twitter_title',
            'twitter_description' => 'rank_math_twitter_description',
            'twitter_image' => 'rank_math_twitter_image',
            'schema' => 'rank_math_rich_snippet',
        ),
        'aioseo' => array(
            'title' => '_aioseo_title',
            'description' => '_aioseo_description',
            'focus_keyword' => '_aioseo_keywords',
            'canonical' => '_aioseo_canonical_url',
            'robots_noindex' => '_aioseo_noindex',
            'robots_nofollow' => '_aioseo_nofollow',
            'og_title' => '_aioseo_og_title',
            'og_description' => '_aioseo_og_description',
            'og_image' => '_aioseo_og_image_custom_url',
            'twitter_title' => '_aioseo_twitter_title',
            'twitter_description' => '_aioseo_twitter_description',
            'twitter_image' => '_aioseo_twitter_image_custom_url',
        ),
        'seopress' => array(
            'title' => '_seopress_titles_title',
            'description' => '_seopress_titles_desc',
            'focus_keyword' => '_seopress_analysis_target_kw',
            'canonical' => '_seopress_robots_canonical',
            'robots_noindex' => '_seopress_robots_index',
            'robots_nofollow' => '_seopress_robots_follow',
            'og_title' => '_seopress_social_fb_title',
            'og_description' => '_seopress_social_fb_desc',
            'og_image' => '_seopress_social_fb_img',
            'twitter_title' => '_seopress_social_twitter_title',
            'twitter_description' => '_seopress_social_twitter_desc',
            'twitter_image' => '_seopress_social_twitter_img',
        ),
        'tsf' => array( // The SEO Framework
            'title' => '_genesis_title',
            'description' => '_genesis_description',
            'canonical' => '_genesis_canonical_uri',
            'robots_noindex' => '_genesis_noindex',
            'robots_nofollow' => '_genesis_nofollow',
            'og_title' => '_open_graph_title',
            'og_description' => '_open_graph_description',
            'twitter_title' => '_twitter_title',
            'twitter_description' => '_twitter_description',
        ),
        'generic' => array(
            'title' => '_echo5_seo_title',
            'description' => '_echo5_seo_description',
            'focus_keyword' => '_echo5_focus_keyword',
            'canonical' => '_echo5_canonical',
            'robots' => '_echo5_robots',
            'og_title' => '_echo5_og_title',
            'og_description' => '_echo5_og_description',
            'og_image' => '_echo5_og_image',
            'schema' => '_echo5_schema',
        ),
    );
    
    /**
     * Constructor - detect active SEO plugin
     */
    public function __construct() {
        $this->detect_seo_plugin();
    }
    
    /**
     * Detect which SEO plugin is active
     * 
     * @return string Plugin identifier
     */
    public function detect_seo_plugin() {
        if ($this->plugins_checked) {
            return $this->active_plugin;
        }
        
        $this->plugins_checked = true;
        
        // Check Yoast SEO
        if (defined('WPSEO_VERSION') || class_exists('WPSEO_Options')) {
            $this->active_plugin = 'yoast';
            return $this->active_plugin;
        }
        
        // Check RankMath
        if (class_exists('RankMath') || defined('RANK_MATH_VERSION')) {
            $this->active_plugin = 'rankmath';
            return $this->active_plugin;
        }
        
        // Check AIOSEO
        if (defined('AIOSEO_VERSION') || function_exists('aioseo')) {
            $this->active_plugin = 'aioseo';
            return $this->active_plugin;
        }
        
        // Check SEOPress
        if (defined('SEOPRESS_VERSION') || function_exists('seopress_init')) {
            $this->active_plugin = 'seopress';
            return $this->active_plugin;
        }
        
        // Check The SEO Framework
        if (defined('THE_SEO_FRAMEWORK_VERSION') || function_exists('the_seo_framework')) {
            $this->active_plugin = 'tsf';
            return $this->active_plugin;
        }
        
        // No known SEO plugin - use generic
        $this->active_plugin = 'generic';
        return $this->active_plugin;
    }
    
    /**
     * Get active plugin info
     * 
     * @return array Plugin information
     */
    public function get_active_plugin_info() {
        $plugin = $this->detect_seo_plugin();
        
        $plugin_names = array(
            'yoast' => 'Yoast SEO',
            'rankmath' => 'RankMath',
            'aioseo' => 'All in One SEO',
            'seopress' => 'SEOPress',
            'tsf' => 'The SEO Framework',
            'generic' => 'None (using Echo5 meta fields)',
        );
        
        return array(
            'id' => $plugin,
            'name' => isset($plugin_names[$plugin]) ? $plugin_names[$plugin] : 'Unknown',
            'meta_keys' => $this->get_meta_keys(),
        );
    }
    
    /**
     * Get meta keys for active plugin
     * 
     * @return array Meta keys
     */
    private function get_meta_keys() {
        $plugin = $this->detect_seo_plugin();
        return isset($this->meta_keys[$plugin]) ? $this->meta_keys[$plugin] : $this->meta_keys['generic'];
    }
    
    /**
     * Save all SEO meta at once
     * 
     * @param int $post_id Post ID
     * @param array $meta_data SEO meta data
     * @return array Results
     */
    public function save_all_meta($post_id, $meta_data) {
        $results = array();
        
        // Save title
        if (!empty($meta_data['meta_title'])) {
            $results['title'] = $this->save_meta_title($post_id, $meta_data['meta_title']);
        }
        
        // Save description
        if (!empty($meta_data['meta_description'])) {
            $results['description'] = $this->save_meta_description($post_id, $meta_data['meta_description']);
            
            // Also save to WP excerpt for fallback
            wp_update_post(array(
                'ID' => $post_id,
                'post_excerpt' => sanitize_text_field($meta_data['meta_description']),
            ));
        }
        
        // Save focus keyword
        if (!empty($meta_data['focus_keyword'])) {
            $results['focus_keyword'] = $this->save_focus_keyword($post_id, $meta_data['focus_keyword']);
        }
        
        // Save secondary keywords (if supported)
        if (!empty($meta_data['secondary_keywords'])) {
            $results['secondary_keywords'] = $this->save_secondary_keywords($post_id, $meta_data['secondary_keywords']);
        }
        
        // Save canonical (if provided)
        if (!empty($meta_data['canonical'])) {
            $results['canonical'] = $this->save_canonical($post_id, $meta_data['canonical']);
        }
        
        // Save Open Graph meta
        if (!empty($meta_data['og_title']) || !empty($meta_data['og_description'])) {
            $results['og'] = $this->save_og_meta($post_id, $meta_data);
        }
        
        // Mark as Echo5 managed
        update_post_meta($post_id, '_echo5_seo_managed', true);
        update_post_meta($post_id, '_echo5_seo_updated', current_time('mysql'));
        update_post_meta($post_id, '_echo5_seo_plugin_used', $this->active_plugin);
        
        return $results;
    }
    
    /**
     * Save meta title
     * 
     * @param int $post_id Post ID
     * @param string $title Meta title
     * @return bool Success
     */
    public function save_meta_title($post_id, $title) {
        $keys = $this->get_meta_keys();
        $title = sanitize_text_field($title);
        
        if (!empty($keys['title'])) {
            update_post_meta($post_id, $keys['title'], $title);
        }
        
        // Always save to generic key as backup
        update_post_meta($post_id, '_echo5_seo_title', $title);
        
        return true;
    }
    
    /**
     * Save meta description
     * 
     * @param int $post_id Post ID
     * @param string $description Meta description
     * @return bool Success
     */
    public function save_meta_description($post_id, $description) {
        $keys = $this->get_meta_keys();
        $description = sanitize_textarea_field($description);
        
        if (!empty($keys['description'])) {
            update_post_meta($post_id, $keys['description'], $description);
        }
        
        // Always save to generic key as backup
        update_post_meta($post_id, '_echo5_seo_description', $description);
        
        return true;
    }
    
    /**
     * Save focus keyword
     * 
     * @param int $post_id Post ID
     * @param string $keyword Focus keyword
     * @return bool Success
     */
    public function save_focus_keyword($post_id, $keyword) {
        $keys = $this->get_meta_keys();
        $keyword = sanitize_text_field($keyword);
        
        if (!empty($keys['focus_keyword'])) {
            // RankMath supports multiple keywords comma-separated
            if ($this->active_plugin === 'rankmath') {
                update_post_meta($post_id, $keys['focus_keyword'], $keyword);
            } else {
                update_post_meta($post_id, $keys['focus_keyword'], $keyword);
            }
        }
        
        update_post_meta($post_id, '_echo5_focus_keyword', $keyword);
        
        return true;
    }
    
    /**
     * Save secondary keywords
     * 
     * @param int $post_id Post ID
     * @param array $keywords Secondary keywords
     * @return bool Success
     */
    public function save_secondary_keywords($post_id, $keywords) {
        if (!is_array($keywords)) {
            $keywords = array($keywords);
        }
        
        $keywords = array_map('sanitize_text_field', $keywords);
        
        // RankMath supports additional focus keywords
        if ($this->active_plugin === 'rankmath') {
            // RankMath stores multiple keywords comma-separated in focus_keyword
            $existing = get_post_meta($post_id, 'rank_math_focus_keyword', true);
            $all_keywords = $existing ? $existing . ',' . implode(',', $keywords) : implode(',', $keywords);
            update_post_meta($post_id, 'rank_math_focus_keyword', $all_keywords);
        }
        
        // Always store in Echo5 format
        update_post_meta($post_id, '_echo5_secondary_keywords', $keywords);
        
        return true;
    }
    
    /**
     * Save canonical URL
     * 
     * @param int $post_id Post ID
     * @param string $url Canonical URL
     * @return bool Success
     */
    public function save_canonical($post_id, $url) {
        $keys = $this->get_meta_keys();
        $url = esc_url_raw($url);
        
        if (!empty($keys['canonical'])) {
            update_post_meta($post_id, $keys['canonical'], $url);
        }
        
        update_post_meta($post_id, '_echo5_canonical', $url);
        
        return true;
    }
    
    /**
     * Save Open Graph meta
     * 
     * @param int $post_id Post ID
     * @param array $og_data OG data
     * @return bool Success
     */
    public function save_og_meta($post_id, $og_data) {
        $keys = $this->get_meta_keys();
        
        if (!empty($og_data['og_title']) && !empty($keys['og_title'])) {
            update_post_meta($post_id, $keys['og_title'], sanitize_text_field($og_data['og_title']));
        }
        
        if (!empty($og_data['og_description']) && !empty($keys['og_description'])) {
            update_post_meta($post_id, $keys['og_description'], sanitize_textarea_field($og_data['og_description']));
        }
        
        if (!empty($og_data['og_image']) && !empty($keys['og_image'])) {
            update_post_meta($post_id, $keys['og_image'], esc_url_raw($og_data['og_image']));
        }
        
        // Twitter cards (often same as OG)
        if (!empty($og_data['twitter_title']) && !empty($keys['twitter_title'])) {
            update_post_meta($post_id, $keys['twitter_title'], sanitize_text_field($og_data['twitter_title']));
        } elseif (!empty($og_data['og_title']) && !empty($keys['twitter_title'])) {
            update_post_meta($post_id, $keys['twitter_title'], sanitize_text_field($og_data['og_title']));
        }
        
        if (!empty($og_data['twitter_description']) && !empty($keys['twitter_description'])) {
            update_post_meta($post_id, $keys['twitter_description'], sanitize_textarea_field($og_data['twitter_description']));
        } elseif (!empty($og_data['og_description']) && !empty($keys['twitter_description'])) {
            update_post_meta($post_id, $keys['twitter_description'], sanitize_textarea_field($og_data['og_description']));
        }
        
        return true;
    }
    
    /**
     * Save robots meta
     * 
     * @param int $post_id Post ID
     * @param array $robots Robots settings (noindex, nofollow, etc.)
     * @return bool Success
     */
    public function save_robots_meta($post_id, $robots) {
        $keys = $this->get_meta_keys();
        
        $noindex = isset($robots['noindex']) && $robots['noindex'];
        $nofollow = isset($robots['nofollow']) && $robots['nofollow'];
        
        switch ($this->active_plugin) {
            case 'yoast':
                update_post_meta($post_id, $keys['robots_noindex'], $noindex ? '1' : '');
                update_post_meta($post_id, $keys['robots_nofollow'], $nofollow ? '1' : '');
                break;
                
            case 'rankmath':
                $robots_array = array();
                if ($noindex) $robots_array[] = 'noindex';
                if ($nofollow) $robots_array[] = 'nofollow';
                update_post_meta($post_id, $keys['robots'], $robots_array);
                break;
                
            case 'aioseo':
            case 'seopress':
                if (!empty($keys['robots_noindex'])) {
                    update_post_meta($post_id, $keys['robots_noindex'], $noindex ? '1' : '0');
                }
                if (!empty($keys['robots_nofollow'])) {
                    update_post_meta($post_id, $keys['robots_nofollow'], $nofollow ? '1' : '0');
                }
                break;
                
            default:
                $robots_string = ($noindex ? 'noindex' : 'index') . ',' . ($nofollow ? 'nofollow' : 'follow');
                update_post_meta($post_id, '_echo5_robots', $robots_string);
        }
        
        return true;
    }
    
    /**
     * Get all SEO meta for a post
     * 
     * @param int $post_id Post ID
     * @return array SEO meta data
     */
    public function get_all_meta($post_id) {
        $keys = $this->get_meta_keys();
        $meta = array();
        
        foreach ($keys as $field => $key) {
            $value = get_post_meta($post_id, $key, true);
            if (!empty($value)) {
                $meta[$field] = $value;
            }
        }
        
        // Include Echo5 generic fields as fallback
        $echo5_fields = array(
            '_echo5_seo_title' => 'echo5_title',
            '_echo5_seo_description' => 'echo5_description',
            '_echo5_focus_keyword' => 'echo5_focus_keyword',
            '_echo5_secondary_keywords' => 'echo5_secondary_keywords',
        );
        
        foreach ($echo5_fields as $key => $field) {
            $value = get_post_meta($post_id, $key, true);
            if (!empty($value)) {
                $meta[$field] = $value;
            }
        }
        
        $meta['seo_plugin'] = $this->active_plugin;
        $meta['echo5_managed'] = (bool) get_post_meta($post_id, '_echo5_seo_managed', true);
        
        return $meta;
    }
    
    /**
     * Inject JSON-LD schema into page head
     * Creates filter for wp_head
     * 
     * @param int $post_id Post ID
     * @param array $schemas Array of schema objects
     * @return bool Success
     */
    public function save_schemas($post_id, $schemas) {
        if (empty($schemas)) {
            return false;
        }
        
        // Store schemas as post meta
        update_post_meta($post_id, '_echo5_schemas', $schemas);
        
        return true;
    }
    
    /**
     * Output schemas in wp_head (called via action hook)
     * Register this in plugin init
     */
    public static function output_schemas_in_head() {
        if (!is_singular()) {
            return;
        }
        
        global $post;
        $schemas = get_post_meta($post->ID, '_echo5_schemas', true);
        
        if (empty($schemas) || !is_array($schemas)) {
            return;
        }
        
        echo "\n<!-- Echo5 SEO Manager - JSON-LD Schemas -->\n";
        
        foreach ($schemas as $type => $schema) {
            if (!empty($schema)) {
                echo '<script type="application/ld+json">' . "\n";
                echo json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                echo "\n</script>\n";
            }
        }
        
        echo "<!-- /Echo5 SEO Manager -->\n\n";
    }
}

// Register schema output hook
add_action('wp_head', array('Echo5_SEO_Meta_Handler', 'output_schemas_in_head'), 1);

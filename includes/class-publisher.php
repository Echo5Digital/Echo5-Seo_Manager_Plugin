<?php
/**
 * Echo5 Publisher - Page Publishing API Handler
 * 
 * Enterprise-grade page publishing with:
 * - HMAC signature verification
 * - Safe/Full update modes with section markers
 * - Version snapshots for rollback
 * - Retry support with idempotency
 * 
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Echo5_Publisher {
    
    private $namespace = 'echo5/v1';
    private $security;
    private $media_handler;
    private $seo_handler;
    private $logger;
    
    // Section marker pattern
    const MARKER_PATTERN = '/<!-- ECHO5:START (\w+) -->(.*?)<!-- ECHO5:END \1 -->/s';
    
    // Request expiry time (5 minutes)
    const REQUEST_EXPIRY_SECONDS = 300;
    
    /**
     * Constructor
     */
    public function __construct($security, $media_handler = null, $seo_handler = null, $logger = null) {
        $this->security = $security;
        $this->media_handler = $media_handler;
        $this->seo_handler = $seo_handler;
        $this->logger = $logger;
    }
    
    /**
     * Register REST API routes for publishing
     */
    public function register_routes() {
        // Main publish endpoint
        register_rest_route($this->namespace, '/publish-page', array(
            'methods' => 'POST',
            'callback' => array($this, 'publish_page'),
            'permission_callback' => array($this, 'verify_publish_request'),
            'args' => $this->get_publish_args(),
        ));
        
        // Dry run / validation endpoint
        register_rest_route($this->namespace, '/validate-page', array(
            'methods' => 'POST',
            'callback' => array($this, 'validate_page'),
            'permission_callback' => array($this, 'verify_publish_request'),
            'args' => $this->get_publish_args(),
        ));
        
        // Get page by slug (for sync checking)
        register_rest_route($this->namespace, '/page-by-slug/(?P<slug>[a-zA-Z0-9-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_page_by_slug'),
            'permission_callback' => array($this->security, 'verify_api_key'),
        ));
        
        // Rollback endpoint
        register_rest_route($this->namespace, '/rollback/(?P<page_id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'rollback_page'),
            'permission_callback' => array($this, 'verify_publish_request'),
            'args' => array(
                'version' => array(
                    'required' => true,
                    'type' => 'integer',
                ),
            ),
        ));
        
        // Get version history
        register_rest_route($this->namespace, '/versions/(?P<page_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_versions'),
            'permission_callback' => array($this->security, 'verify_api_key'),
        ));
        
        // Scheduled publish endpoint
        register_rest_route($this->namespace, '/schedule-page', array(
            'methods' => 'POST',
            'callback' => array($this, 'schedule_page'),
            'permission_callback' => array($this, 'verify_publish_request'),
            'args' => array_merge($this->get_publish_args(), array(
                'publish_at' => array(
                    'required' => true,
                    'type' => 'string',
                    'description' => 'ISO 8601 datetime for scheduled publish',
                ),
            )),
        ));
    }
    
    /**
     * Verify publish request with HMAC signature
     */
    public function verify_publish_request($request) {
        // First verify API key
        $api_key_result = $this->security->verify_api_key($request);
        if (is_wp_error($api_key_result)) {
            return $api_key_result;
        }
        
        // Check for HMAC signature (optional but recommended)
        $signature = $request->get_header('X-Echo5-Signature');
        $timestamp = $request->get_header('X-Echo5-Timestamp');
        
        if ($signature && $timestamp) {
            // Verify timestamp is within expiry window
            $request_time = intval($timestamp);
            $current_time = time();
            
            if (abs($current_time - $request_time) > self::REQUEST_EXPIRY_SECONDS) {
                $this->log_action('signature_expired', array(
                    'timestamp' => $timestamp,
                    'current_time' => $current_time,
                ));
                
                return new WP_Error(
                    'request_expired',
                    'Request timestamp expired. Ensure server clocks are synchronized.',
                    array('status' => 401)
                );
            }
            
            // Verify HMAC signature
            $secret = get_option('echo5_seo_api_key');
            $body = $request->get_body();
            $expected_signature = hash_hmac('sha256', $timestamp . $body, $secret);
            
            if (!hash_equals($expected_signature, $signature)) {
                $this->log_action('signature_invalid', array(
                    'provided' => substr($signature, 0, 16) . '...',
                ));
                
                return new WP_Error(
                    'invalid_signature',
                    'HMAC signature verification failed',
                    array('status' => 401)
                );
            }
        }
        
        return true;
    }
    
    /**
     * Get publish endpoint arguments schema
     */
    private function get_publish_args() {
        return array(
            'page' => array(
                'required' => true,
                'type' => 'object',
                'properties' => array(
                    'title' => array('type' => 'string', 'required' => true),
                    'slug' => array('type' => 'string', 'required' => true),
                    'status' => array(
                        'type' => 'string',
                        'enum' => array('draft', 'publish', 'pending', 'private'),
                        'default' => 'draft',
                    ),
                    'parent_slug' => array('type' => 'string'),
                    'template' => array('type' => 'string'),
                    'update_mode' => array(
                        'type' => 'string',
                        'enum' => array('safe', 'full'),
                        'default' => 'safe',
                    ),
                ),
            ),
            'content' => array(
                'required' => true,
                'type' => 'object',
                'properties' => array(
                    'html' => array('type' => 'string', 'required' => true),
                ),
            ),
            'seo' => array(
                'type' => 'object',
                'properties' => array(
                    'meta_title' => array('type' => 'string'),
                    'meta_description' => array('type' => 'string'),
                    'focus_keyword' => array('type' => 'string'),
                    'secondary_keywords' => array('type' => 'array'),
                ),
            ),
            'images' => array(
                'type' => 'object',
                'properties' => array(
                    'featured_image_url' => array('type' => 'string'),
                    'gallery_images' => array('type' => 'array'),
                ),
            ),
            'schema' => array(
                'type' => 'object',
                'properties' => array(
                    'localbusiness' => array('type' => 'object'),
                    'service' => array('type' => 'object'),
                    'faqpage' => array('type' => 'object'),
                    'breadcrumblist' => array('type' => 'object'),
                ),
            ),
            'options' => array(
                'type' => 'object',
                'properties' => array(
                    'idempotency_key' => array('type' => 'string'),
                    'skip_validation' => array('type' => 'boolean', 'default' => false),
                    'notify_on_complete' => array('type' => 'boolean', 'default' => false),
                ),
            ),
        );
    }
    
    /**
     * Main publish page endpoint
     */
    public function publish_page($request) {
        $start_time = microtime(true);
        $params = $request->get_json_params();
        
        // Check idempotency
        $idempotency_key = isset($params['options']['idempotency_key']) ? $params['options']['idempotency_key'] : null;
        if ($idempotency_key) {
            $cached_result = get_transient('echo5_idem_' . md5($idempotency_key));
            if ($cached_result !== false) {
                return rest_ensure_response(array_merge($cached_result, array(
                    'cached' => true,
                )));
            }
        }
        
        try {
            $page_data = $params['page'];
            $content_data = $params['content'];
            $seo_data = isset($params['seo']) ? $params['seo'] : array();
            $images_data = isset($params['images']) ? $params['images'] : array();
            $schema_data = isset($params['schema']) ? $params['schema'] : array();
            
            $warnings = array();
            $uploaded_media = array();
            
            // Step 1: Find or prepare page
            $existing_page = $this->find_page_by_slug($page_data['slug']);
            $action = $existing_page ? 'updated' : 'created';
            
            // Step 2: Prepare content
            // If updating existing page with safe mode, merge content first
            if ($existing_page && $page_data['update_mode'] === 'safe') {
                $merged_content = $this->apply_safe_update(
                    $existing_page->post_content,
                    $content_data['html']
                );
                $final_content = $this->wrap_with_tailwind($merged_content);
            } else {
                // Full update mode - wrap new content with Tailwind
                $final_content = $this->wrap_with_tailwind($content_data['html']);
            }
            
            // Step 3: Inject schemas into content
            if (!empty($schema_data)) {
                $final_content = $this->inject_schemas($final_content, $schema_data);
            }
            
            // Step 4: Handle images
            if ($this->media_handler) {
                // Upload featured image
                if (!empty($images_data['featured_image_url'])) {
                    $featured_result = $this->media_handler->upload_image(
                        $images_data['featured_image_url'],
                        $page_data['title'] . ' - Featured Image'
                    );
                    if (!is_wp_error($featured_result)) {
                        $uploaded_media[] = $featured_result;
                    } else {
                        $warnings[] = 'Featured image upload failed: ' . $featured_result->get_error_message();
                    }
                }
                
                // Upload gallery images
                if (!empty($images_data['gallery_images'])) {
                    foreach ($images_data['gallery_images'] as $img) {
                        $img_result = $this->media_handler->upload_image(
                            $img['url'],
                            isset($img['alt']) ? $img['alt'] : '',
                            isset($img['caption']) ? $img['caption'] : ''
                        );
                        if (!is_wp_error($img_result)) {
                            $uploaded_media[] = $img_result;
                        } else {
                            $warnings[] = 'Gallery image upload failed: ' . $img_result->get_error_message();
                        }
                    }
                    
                    // Generate gallery HTML and inject
                    if (!empty($uploaded_media)) {
                        $gallery_html = $this->media_handler->create_gallery_html(
                            array_slice($uploaded_media, 1) // Exclude featured image
                        );
                        $final_content = $this->inject_gallery($final_content, $gallery_html);
                    }
                }
            }
            
            // Step 5: Create or update page
            $parent_id = 0;
            if (!empty($page_data['parent_slug'])) {
                $parent = $this->find_page_by_slug($page_data['parent_slug']);
                if ($parent) {
                    $parent_id = $parent->ID;
                }
            }
            
            // Get author ID - look for "Echo5-Seo" user or use first admin
            $author_id = $this->get_echo5_author_id();
            
            $post_data = array(
                'post_title' => sanitize_text_field($page_data['title']),
                'post_name' => sanitize_title($page_data['slug']),
                'post_content' => $final_content,
                'post_status' => $page_data['status'],
                'post_type' => 'page',
                'post_parent' => $parent_id,
                'post_author' => $author_id,
            );
            
            // Allow unfiltered HTML for script/style tags (Tailwind CDN)
            // Disable kses filtering temporarily
            remove_filter('content_save_pre', 'wp_filter_post_kses');
            remove_filter('content_filtered_save_pre', 'wp_filter_post_kses');
            add_filter('user_has_cap', array($this, 'allow_unfiltered_html'), 10, 3);
            
            if (!empty($page_data['template'])) {
                $post_data['page_template'] = $page_data['template'];
            }
            
            if ($existing_page) {
                // Save version snapshot before updating
                $this->save_version_snapshot($existing_page->ID, $existing_page->post_content);
                
                $post_data['ID'] = $existing_page->ID;
                $page_id = wp_update_post($post_data, true);
            } else {
                $page_id = wp_insert_post($post_data, true);
            }
            
            // Restore filters after save
            remove_filter('user_has_cap', array($this, 'allow_unfiltered_html'), 10);
            add_filter('content_save_pre', 'wp_filter_post_kses');
            add_filter('content_filtered_save_pre', 'wp_filter_post_kses');
            
            if (is_wp_error($page_id)) {
                throw new Exception('Failed to save page: ' . $page_id->get_error_message());
            }
            
            // Step 6: Set featured image
            if (!empty($uploaded_media) && isset($uploaded_media[0]['id'])) {
                set_post_thumbnail($page_id, $uploaded_media[0]['id']);
            }
            
            // Step 7: Save SEO meta
            if ($this->seo_handler && !empty($seo_data)) {
                $this->seo_handler->save_all_meta($page_id, $seo_data);
            }
            
            // Step 8: Save Echo5 metadata
            update_post_meta($page_id, '_echo5_managed', true);
            update_post_meta($page_id, '_echo5_last_sync', current_time('mysql'));
            update_post_meta($page_id, '_echo5_update_mode', $page_data['update_mode']);
            
            // Step 9: Convert to Elementor format if Elementor is active
            $use_elementor = isset($page_data['use_elementor']) ? $page_data['use_elementor'] : true;
            if ($use_elementor && $this->is_elementor_active()) {
                $elementor_data = $this->convert_html_to_elementor($final_content);
                if (!empty($elementor_data)) {
                    update_post_meta($page_id, '_elementor_data', wp_slash($elementor_data));
                    update_post_meta($page_id, '_elementor_edit_mode', 'builder');
                    update_post_meta($page_id, '_elementor_template_type', 'wp-page');
                    update_post_meta($page_id, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0.0');
                    
                    // Clear Elementor cache for this page
                    if (class_exists('\Elementor\Plugin')) {
                        \Elementor\Plugin::$instance->files_manager->clear_cache();
                    }
                }
            }
            
            // Build response
            $response_time = round((microtime(true) - $start_time) * 1000);
            
            $result = array(
                'success' => true,
                'page_id' => $page_id,
                'page_url' => get_permalink($page_id),
                'action' => $action,
                'uploaded_media' => $uploaded_media,
                'warnings' => $warnings,
                'response_time_ms' => $response_time,
                'timestamp' => current_time('c'),
            );
            
            // Cache result for idempotency
            if ($idempotency_key) {
                set_transient('echo5_idem_' . md5($idempotency_key), $result, 3600);
            }
            
            // Log success
            $this->log_action('publish_success', array(
                'page_id' => $page_id,
                'action' => $action,
                'slug' => $page_data['slug'],
                'response_time_ms' => $response_time,
            ));
            
            return rest_ensure_response($result);
            
        } catch (Exception $e) {
            $this->log_action('publish_error', array(
                'error' => $e->getMessage(),
                'slug' => isset($page_data['slug']) ? $page_data['slug'] : 'unknown',
            ));
            
            return new WP_Error(
                'publish_failed',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }
    
    /**
     * Validate page without publishing (dry run)
     */
    public function validate_page($request) {
        $params = $request->get_json_params();
        $issues = array();
        $warnings = array();
        
        $page_data = $params['page'];
        $content_data = $params['content'];
        $seo_data = isset($params['seo']) ? $params['seo'] : array();
        
        // Check if page exists
        $existing = $this->find_page_by_slug($page_data['slug']);
        
        // Validate title
        if (empty($page_data['title']) || strlen($page_data['title']) < 10) {
            $issues[] = array('field' => 'title', 'message' => 'Title is too short (min 10 chars)');
        }
        if (strlen($page_data['title']) > 60) {
            $warnings[] = array('field' => 'title', 'message' => 'Title exceeds 60 chars, may be truncated in SERPs');
        }
        
        // Validate meta description
        if (!empty($seo_data['meta_description'])) {
            if (strlen($seo_data['meta_description']) < 120) {
                $warnings[] = array('field' => 'meta_description', 'message' => 'Meta description is short (recommended 120-160 chars)');
            }
            if (strlen($seo_data['meta_description']) > 160) {
                $warnings[] = array('field' => 'meta_description', 'message' => 'Meta description exceeds 160 chars');
            }
        } else {
            $warnings[] = array('field' => 'meta_description', 'message' => 'No meta description provided');
        }
        
        // Validate content
        if (empty($content_data['html']) || strlen($content_data['html']) < 500) {
            $issues[] = array('field' => 'content', 'message' => 'Content is too short (min 500 chars)');
        }
        
        // Check for H1
        if (strpos($content_data['html'], '<h1') === false) {
            $warnings[] = array('field' => 'content', 'message' => 'No H1 tag found in content');
        }
        
        // Check for section markers in safe mode
        if ($page_data['update_mode'] === 'safe') {
            preg_match_all(self::MARKER_PATTERN, $content_data['html'], $markers);
            if (empty($markers[0])) {
                $warnings[] = array('field' => 'content', 'message' => 'No Echo5 section markers found for safe update mode');
            }
        }
        
        // Calculate SEO score
        $seo_score = $this->calculate_seo_score($page_data, $content_data, $seo_data);
        
        return rest_ensure_response(array(
            'valid' => empty($issues),
            'would_create' => !$existing,
            'would_update' => (bool) $existing,
            'existing_page_id' => $existing ? $existing->ID : null,
            'issues' => $issues,
            'warnings' => $warnings,
            'seo_score' => $seo_score,
            'content_stats' => array(
                'html_length' => strlen($content_data['html']),
                'word_count' => str_word_count(strip_tags($content_data['html'])),
                'heading_count' => preg_match_all('/<h[1-6]/i', $content_data['html']),
            ),
        ));
    }
    
    /**
     * Get page by slug
     */
    public function get_page_by_slug($request) {
        $slug = $request->get_param('slug');
        $page = $this->find_page_by_slug($slug);
        
        if (!$page) {
            return new WP_Error('not_found', 'Page not found', array('status' => 404));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'id' => $page->ID,
                'title' => $page->post_title,
                'slug' => $page->post_name,
                'url' => get_permalink($page->ID),
                'status' => $page->post_status,
                'modified' => $page->post_modified,
                'echo5_managed' => (bool) get_post_meta($page->ID, '_echo5_managed', true),
                'last_sync' => get_post_meta($page->ID, '_echo5_last_sync', true),
                'version_count' => $this->get_version_count($page->ID),
            ),
        ));
    }
    
    /**
     * Rollback to previous version
     */
    public function rollback_page($request) {
        $page_id = intval($request->get_param('page_id'));
        $version = intval($request->get_param('version'));
        
        $page = get_post($page_id);
        if (!$page || $page->post_type !== 'page') {
            return new WP_Error('not_found', 'Page not found', array('status' => 404));
        }
        
        // Get version content
        $version_content = get_post_meta($page_id, '_echo5_version_' . $version, true);
        if (empty($version_content)) {
            return new WP_Error('version_not_found', 'Version not found', array('status' => 404));
        }
        
        // Save current as new version before rollback
        $this->save_version_snapshot($page_id, $page->post_content);
        
        // Update with version content
        $result = wp_update_post(array(
            'ID' => $page_id,
            'post_content' => $version_content,
        ), true);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        $this->log_action('rollback_success', array(
            'page_id' => $page_id,
            'rolled_back_to_version' => $version,
        ));
        
        return rest_ensure_response(array(
            'success' => true,
            'page_id' => $page_id,
            'rolled_back_to_version' => $version,
            'page_url' => get_permalink($page_id),
        ));
    }
    
    /**
     * Get version history
     */
    public function get_versions($request) {
        $page_id = intval($request->get_param('page_id'));
        
        $page = get_post($page_id);
        if (!$page) {
            return new WP_Error('not_found', 'Page not found', array('status' => 404));
        }
        
        global $wpdb;
        $versions = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, LENGTH(meta_value) as content_length 
             FROM {$wpdb->postmeta} 
             WHERE post_id = %d AND meta_key LIKE '_echo5_version_%%'
             ORDER BY meta_key DESC",
            $page_id
        ));
        
        $version_list = array();
        foreach ($versions as $v) {
            preg_match('/_echo5_version_(\d+)/', $v->meta_key, $matches);
            if ($matches) {
                $version_list[] = array(
                    'version' => intval($matches[1]),
                    'timestamp' => date('c', intval($matches[1])),
                    'content_length' => $v->content_length,
                );
            }
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'page_id' => $page_id,
            'versions' => $version_list,
            'total' => count($version_list),
        ));
    }
    
    /**
     * Schedule page for future publishing
     */
    public function schedule_page($request) {
        $params = $request->get_json_params();
        $publish_at = $params['publish_at'];
        
        // Parse the scheduled time
        $scheduled_time = strtotime($publish_at);
        if (!$scheduled_time || $scheduled_time <= time()) {
            return new WP_Error(
                'invalid_schedule',
                'Scheduled time must be in the future',
                array('status' => 400)
            );
        }
        
        // Store the publish data for later
        $schedule_key = 'echo5_scheduled_' . md5($params['page']['slug'] . $scheduled_time);
        
        set_transient($schedule_key, array(
            'params' => $params,
            'scheduled_for' => $scheduled_time,
            'created_at' => time(),
        ), $scheduled_time - time() + 3600);
        
        // Schedule WordPress cron event
        wp_schedule_single_event($scheduled_time, 'echo5_publish_scheduled_page', array($schedule_key));
        
        $this->log_action('schedule_success', array(
            'slug' => $params['page']['slug'],
            'scheduled_for' => date('c', $scheduled_time),
        ));
        
        return rest_ensure_response(array(
            'success' => true,
            'scheduled' => true,
            'publish_at' => date('c', $scheduled_time),
            'schedule_key' => $schedule_key,
            'slug' => $params['page']['slug'],
        ));
    }
    
    /**
     * Find page by slug
     */
    private function find_page_by_slug($slug) {
        $args = array(
            'name' => sanitize_title($slug),
            'post_type' => 'page',
            'post_status' => array('publish', 'draft', 'pending', 'private'),
            'numberposts' => 1,
        );
        
        $pages = get_posts($args);
        return !empty($pages) ? $pages[0] : null;
    }
    
    /**
     * Apply safe update - only replace content within Echo5 markers
     */
    private function apply_safe_update($existing_content, $new_content) {
        // Extract all markers from new content
        preg_match_all(self::MARKER_PATTERN, $new_content, $new_sections, PREG_SET_ORDER);
        
        if (empty($new_sections)) {
            // No markers in new content, return new content with warning
            return $new_content;
        }
        
        $updated_content = $existing_content;
        
        foreach ($new_sections as $section) {
            $marker_name = $section[1];
            $full_section = $section[0];
            
            // Build pattern for this specific marker
            $pattern = '/<!-- ECHO5:START ' . preg_quote($marker_name, '/') . ' -->.*?<!-- ECHO5:END ' . preg_quote($marker_name, '/') . ' -->/s';
            
            // Check if marker exists in existing content
            if (preg_match($pattern, $updated_content)) {
                // Replace existing marker section
                $updated_content = preg_replace($pattern, $full_section, $updated_content);
            } else {
                // Marker doesn't exist - append before closing body or at end
                if (strpos($updated_content, '</body>') !== false) {
                    $updated_content = str_replace('</body>', $full_section . "\n</body>", $updated_content);
                } else {
                    $updated_content .= "\n" . $full_section;
                }
            }
        }
        
        return $updated_content;
    }
    
    /**
     * Inject JSON-LD schemas into content
     */
    private function inject_schemas($content, $schema_data) {
        $schema_html = "\n<!-- ECHO5:START SCHEMA -->\n";
        
        foreach ($schema_data as $type => $schema) {
            if (!empty($schema)) {
                $schema_html .= '<script type="application/ld+json">' . "\n";
                $schema_html .= json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $schema_html .= "\n</script>\n";
            }
        }
        
        $schema_html .= "<!-- ECHO5:END SCHEMA -->\n";
        
        // Check if schema marker exists
        if (preg_match('/<!-- ECHO5:START SCHEMA -->.*?<!-- ECHO5:END SCHEMA -->/s', $content)) {
            $content = preg_replace(
                '/<!-- ECHO5:START SCHEMA -->.*?<!-- ECHO5:END SCHEMA -->/s',
                $schema_html,
                $content
            );
        } else {
            // Append at end of content
            $content .= $schema_html;
        }
        
        return $content;
    }
    
    /**
     * Inject gallery HTML into content
     */
    private function inject_gallery($content, $gallery_html) {
        $gallery_section = "\n<!-- ECHO5:START GALLERY -->\n" . $gallery_html . "\n<!-- ECHO5:END GALLERY -->\n";
        
        // Check if gallery marker exists
        if (preg_match('/<!-- ECHO5:START GALLERY -->.*?<!-- ECHO5:END GALLERY -->/s', $content)) {
            $content = preg_replace(
                '/<!-- ECHO5:START GALLERY -->.*?<!-- ECHO5:END GALLERY -->/s',
                $gallery_section,
                $content
            );
        } else {
            // Insert before FAQ section if exists, otherwise before schema or at end
            if (strpos($content, '<!-- ECHO5:START FAQ -->') !== false) {
                $content = str_replace('<!-- ECHO5:START FAQ -->', $gallery_section . "\n<!-- ECHO5:START FAQ -->", $content);
            } elseif (strpos($content, '<!-- ECHO5:START SCHEMA -->') !== false) {
                $content = str_replace('<!-- ECHO5:START SCHEMA -->', $gallery_section . "\n<!-- ECHO5:START SCHEMA -->", $content);
            } else {
                $content .= $gallery_section;
            }
        }
        
        return $content;
    }
    
    /**
     * Save version snapshot
     */
    private function save_version_snapshot($page_id, $content) {
        $version_key = '_echo5_version_' . time();
        update_post_meta($page_id, $version_key, $content);
        
        // Increment version count
        $count = intval(get_post_meta($page_id, '_echo5_version_count', true));
        update_post_meta($page_id, '_echo5_version_count', $count + 1);
        
        // Cleanup old versions (keep last 10)
        $this->cleanup_old_versions($page_id, 10);
    }
    
    /**
     * Get version count
     */
    private function get_version_count($page_id) {
        return intval(get_post_meta($page_id, '_echo5_version_count', true));
    }
    
    /**
     * Cleanup old versions
     */
    private function cleanup_old_versions($page_id, $keep = 10) {
        global $wpdb;
        
        $versions = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_key FROM {$wpdb->postmeta} 
             WHERE post_id = %d AND meta_key LIKE '_echo5_version_%%'
             ORDER BY meta_key DESC",
            $page_id
        ));
        
        if (count($versions) > $keep) {
            $to_delete = array_slice($versions, $keep);
            foreach ($to_delete as $key) {
                delete_post_meta($page_id, $key);
            }
        }
    }
    
    /**
     * Calculate basic SEO score
     */
    private function calculate_seo_score($page_data, $content_data, $seo_data) {
        $score = 100;
        
        // Title checks
        if (strlen($page_data['title']) < 30) $score -= 10;
        if (strlen($page_data['title']) > 60) $score -= 5;
        
        // Meta description
        if (empty($seo_data['meta_description'])) $score -= 15;
        elseif (strlen($seo_data['meta_description']) < 120) $score -= 5;
        elseif (strlen($seo_data['meta_description']) > 160) $score -= 5;
        
        // Content length
        $word_count = str_word_count(strip_tags($content_data['html']));
        if ($word_count < 300) $score -= 20;
        elseif ($word_count < 500) $score -= 10;
        
        // H1 check
        if (strpos($content_data['html'], '<h1') === false) $score -= 15;
        
        // Focus keyword
        if (empty($seo_data['focus_keyword'])) $score -= 10;
        
        return max(0, $score);
    }
    
    /**
     * Wrap content with Tailwind CSS
     * Adds Tailwind CDN and wraps content in a styled container
     */
    private function wrap_with_tailwind($html) {
        // Check if content already has Tailwind wrapper
        if (strpos($html, 'echo5-tailwind-content') !== false) {
            return $html;
        }
        
        // Tailwind CDN script with config for WordPress compatibility
        $tailwind_cdn = '
<!-- Echo5 Tailwind CSS -->
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
    prefix: "",
    important: ".echo5-tailwind-content",
    corePlugins: {
        preflight: false, // Disable base reset to avoid conflicts with WordPress
    },
    theme: {
        extend: {
            colors: {
                primary: "#3b82f6",
                secondary: "#64748b",
            }
        }
    }
}
</script>
<style>
.echo5-tailwind-content {
    font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    line-height: 1.6;
}
.echo5-tailwind-content * {
    box-sizing: border-box;
}
.echo5-tailwind-content h1, 
.echo5-tailwind-content h2, 
.echo5-tailwind-content h3, 
.echo5-tailwind-content h4, 
.echo5-tailwind-content h5, 
.echo5-tailwind-content h6 {
    font-weight: 700;
    line-height: 1.25;
    margin-bottom: 0.5em;
}
.echo5-tailwind-content h1 { font-size: 2.25rem; }
.echo5-tailwind-content h2 { font-size: 1.875rem; }
.echo5-tailwind-content h3 { font-size: 1.5rem; }
.echo5-tailwind-content p { margin-bottom: 1rem; }
.echo5-tailwind-content ul, .echo5-tailwind-content ol { margin-bottom: 1rem; padding-left: 1.5rem; }
.echo5-tailwind-content li { margin-bottom: 0.25rem; }
.echo5-tailwind-content a { color: #3b82f6; text-decoration: underline; }
.echo5-tailwind-content a:hover { color: #2563eb; }
.echo5-tailwind-content img { max-width: 100%; height: auto; }
.echo5-tailwind-content .bg-gradient-to-r { background-image: linear-gradient(to right, var(--tw-gradient-stops)); }
.echo5-tailwind-content .bg-gradient-to-br { background-image: linear-gradient(to bottom right, var(--tw-gradient-stops)); }
</style>
<!-- End Echo5 Tailwind CSS -->
';
        
        // Wrap content in container
        $wrapped = $tailwind_cdn . '
<div class="echo5-tailwind-content">
' . $html . '
</div>';
        
        return $wrapped;
    }
    
    /**
     * Log action
     */
    private function log_action($action, $data = array()) {
        if ($this->logger) {
            $this->logger->log($action, $data);
        } else {
            error_log('Echo5 Publisher [' . $action . ']: ' . json_encode($data));
        }
    }
    
    /**
     * Temporarily allow unfiltered HTML for script/style tags
     */
    public function allow_unfiltered_html($allcaps, $caps, $args) {
        $allcaps['unfiltered_html'] = true;
        return $allcaps;
    }
    
    /**
     * Get Echo5-Seo author ID - find existing user or create one
     */
    private function get_echo5_author_id() {
        // Try to find user by login/slug
        $user = get_user_by('login', 'echo5-seo');
        if ($user) {
            return $user->ID;
        }
        
        // Try by display name
        $users = get_users(array(
            'search' => 'Echo5-Seo',
            'search_columns' => array('display_name'),
            'number' => 1,
        ));
        if (!empty($users)) {
            return $users[0]->ID;
        }
        
        // Create the user if it doesn't exist
        $user_id = wp_insert_user(array(
            'user_login' => 'echo5-seo',
            'user_pass' => wp_generate_password(24),
            'user_email' => 'seo@echo5digital.com',
            'display_name' => 'Echo5-Seo',
            'first_name' => 'Echo5',
            'last_name' => 'SEO',
            'role' => 'author',
        ));
        
        if (!is_wp_error($user_id)) {
            return $user_id;
        }
        
        // Fallback to first admin
        $admins = get_users(array('role' => 'administrator', 'number' => 1));
        if (!empty($admins)) {
            return $admins[0]->ID;
        }
        
        // Last resort: current user or user ID 1
        return get_current_user_id() ?: 1;
    }
    
    /**
     * Check if Elementor is active
     */
    private function is_elementor_active() {
        return defined('ELEMENTOR_VERSION') || class_exists('\Elementor\Plugin');
    }
    
    /**
     * Convert HTML content to Elementor JSON format
     * Creates a single-section layout with the HTML content
     * 
     * @param string $html The HTML content to convert
     * @return string JSON encoded Elementor data
     */
    private function convert_html_to_elementor($html) {
        // Generate unique IDs for Elementor elements
        $section_id = $this->generate_elementor_id();
        $column_id = $this->generate_elementor_id();
        $widget_id = $this->generate_elementor_id();
        
        // Parse the HTML and try to create structured Elementor elements
        $elements = $this->parse_html_to_elementor_elements($html);
        
        if (empty($elements)) {
            // Fallback: wrap entire HTML in a single text widget
            $elements = array(
                array(
                    'id' => $widget_id,
                    'elType' => 'widget',
                    'widgetType' => 'text-editor',
                    'settings' => array(
                        'editor' => $html,
                    ),
                    'elements' => array(),
                ),
            );
        }
        
        // Build the Elementor structure
        $elementor_data = array(
            array(
                'id' => $section_id,
                'elType' => 'section',
                'settings' => array(
                    'structure' => '10', // Single column
                    'content_width' => 'full',
                    'gap' => 'default',
                    'padding' => array(
                        'unit' => 'px',
                        'top' => '40',
                        'right' => '20',
                        'bottom' => '40',
                        'left' => '20',
                        'isLinked' => false,
                    ),
                ),
                'elements' => array(
                    array(
                        'id' => $column_id,
                        'elType' => 'column',
                        'settings' => array(
                            '_column_size' => 100,
                            '_inline_size' => null,
                        ),
                        'elements' => $elements,
                    ),
                ),
            ),
        );
        
        return json_encode($elementor_data);
    }
    
    /**
     * Parse HTML content and convert to Elementor elements
     * Attempts to break down HTML into proper widgets (headings, text, images, etc.)
     * 
     * @param string $html The HTML content
     * @return array Array of Elementor element definitions
     */
    private function parse_html_to_elementor_elements($html) {
        $elements = array();
        
        // Use DOMDocument to parse HTML
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        
        // Add wrapper to handle fragments
        $html = '<div id="echo5-wrapper">' . $html . '</div>';
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        $wrapper = $dom->getElementById('echo5-wrapper');
        if (!$wrapper) {
            return array();
        }
        
        // Buffer for accumulating text content
        $text_buffer = '';
        
        foreach ($wrapper->childNodes as $node) {
            // Skip text-only nodes with just whitespace
            if ($node->nodeType === XML_TEXT_NODE) {
                $text = trim($node->textContent);
                if (!empty($text)) {
                    $text_buffer .= $dom->saveHTML($node);
                }
                continue;
            }
            
            if ($node->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            
            $tagName = strtolower($node->tagName);
            
            // Handle different tag types
            switch ($tagName) {
                case 'h1':
                case 'h2':
                case 'h3':
                case 'h4':
                case 'h5':
                case 'h6':
                    // Flush text buffer first
                    if (!empty($text_buffer)) {
                        $elements[] = $this->create_text_widget($text_buffer);
                        $text_buffer = '';
                    }
                    $elements[] = $this->create_heading_widget($node->textContent, $tagName);
                    break;
                    
                case 'img':
                    // Flush text buffer first
                    if (!empty($text_buffer)) {
                        $elements[] = $this->create_text_widget($text_buffer);
                        $text_buffer = '';
                    }
                    $src = $node->getAttribute('src');
                    $alt = $node->getAttribute('alt');
                    if (!empty($src)) {
                        $elements[] = $this->create_image_widget($src, $alt);
                    }
                    break;
                    
                case 'section':
                case 'div':
                    // For divs/sections, we need to parse recursively or treat as HTML block
                    // For now, add as HTML content
                    $inner_html = $this->get_inner_html($dom, $node);
                    if (!empty(trim($inner_html))) {
                        // Flush text buffer first
                        if (!empty($text_buffer)) {
                            $elements[] = $this->create_text_widget($text_buffer);
                            $text_buffer = '';
                        }
                        $elements[] = $this->create_html_widget($dom->saveHTML($node));
                    }
                    break;
                    
                case 'ul':
                case 'ol':
                    // Lists - treat as text editor content
                    if (!empty($text_buffer)) {
                        $elements[] = $this->create_text_widget($text_buffer);
                        $text_buffer = '';
                    }
                    $elements[] = $this->create_text_widget($dom->saveHTML($node));
                    break;
                    
                case 'p':
                case 'span':
                case 'a':
                case 'strong':
                case 'em':
                case 'blockquote':
                default:
                    // Accumulate in text buffer
                    $text_buffer .= $dom->saveHTML($node);
                    break;
            }
        }
        
        // Flush remaining text buffer
        if (!empty(trim($text_buffer))) {
            $elements[] = $this->create_text_widget($text_buffer);
        }
        
        return $elements;
    }
    
    /**
     * Create Elementor heading widget
     */
    private function create_heading_widget($text, $tag = 'h2') {
        $size_map = array(
            'h1' => 'xl',
            'h2' => 'large',
            'h3' => 'medium',
            'h4' => 'small',
            'h5' => 'small',
            'h6' => 'small',
        );
        
        return array(
            'id' => $this->generate_elementor_id(),
            'elType' => 'widget',
            'widgetType' => 'heading',
            'settings' => array(
                'title' => $text,
                'header_size' => $tag,
                'size' => isset($size_map[$tag]) ? $size_map[$tag] : 'default',
            ),
            'elements' => array(),
        );
    }
    
    /**
     * Create Elementor text editor widget
     */
    private function create_text_widget($html) {
        return array(
            'id' => $this->generate_elementor_id(),
            'elType' => 'widget',
            'widgetType' => 'text-editor',
            'settings' => array(
                'editor' => $html,
            ),
            'elements' => array(),
        );
    }
    
    /**
     * Create Elementor image widget
     */
    private function create_image_widget($url, $alt = '') {
        return array(
            'id' => $this->generate_elementor_id(),
            'elType' => 'widget',
            'widgetType' => 'image',
            'settings' => array(
                'image' => array(
                    'url' => $url,
                    'alt' => $alt,
                ),
                'image_size' => 'full',
                'align' => 'center',
            ),
            'elements' => array(),
        );
    }
    
    /**
     * Create Elementor HTML widget for raw HTML content
     */
    private function create_html_widget($html) {
        return array(
            'id' => $this->generate_elementor_id(),
            'elType' => 'widget',
            'widgetType' => 'html',
            'settings' => array(
                'html' => $html,
            ),
            'elements' => array(),
        );
    }
    
    /**
     * Generate random Elementor element ID
     */
    private function generate_elementor_id() {
        return substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 7);
    }
    
    /**
     * Get inner HTML of a DOMNode
     */
    private function get_inner_html($dom, $node) {
        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= $dom->saveHTML($child);
        }
        return $inner;
    }
}

// Hook for scheduled publishing
add_action('echo5_publish_scheduled_page', function($schedule_key) {
    $scheduled_data = get_transient($schedule_key);
    if (!$scheduled_data) {
        error_log('Echo5: Scheduled publish data not found for key: ' . $schedule_key);
        return;
    }
    
    // Create a mock request and execute publish
    $publisher = new Echo5_Publisher(new Echo5_SEO_Security());
    
    // We need to manually execute the publish logic here
    // For now, log the attempt
    error_log('Echo5: Executing scheduled publish for: ' . $scheduled_data['params']['page']['slug']);
    
    delete_transient($schedule_key);
});

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
        // Regex allows alphanumeric, hyphens, underscores, dots, and percent-encoded chars
        register_rest_route($this->namespace, '/page-by-slug/(?P<slug>[a-zA-Z0-9\-_\.%]+)', array(
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
        
        // Capabilities endpoint - reports installed Elementor addons
        register_rest_route($this->namespace, '/capabilities', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_capabilities'),
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
     * Report installed Elementor addons and widget capabilities.
     * Called by the backend before publishing to determine which widget tier to use.
     */
    public function get_capabilities($request) {
        $has_elementor = defined('ELEMENTOR_VERSION') || class_exists('\Elementor\Plugin');
        $elementor_version = defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null;

        $has_elementor_pro = defined('ELEMENTOR_PRO_VERSION');
        $elementor_pro_version = defined('ELEMENTOR_PRO_VERSION') ? ELEMENTOR_PRO_VERSION : null;

        $has_pro_elements = defined('PRO_ELEMENTS_VERSION')
            || (function_exists('is_plugin_active') && is_plugin_active('pro-elements/pro-elements.php'));

        $has_royal = defined('WPR_ADDONS_VERSION')
            || (function_exists('is_plugin_active') && is_plugin_active('royal-elementor-addons/royal-elementor-addons.php'));

        $pro_capable = $has_elementor_pro || $has_pro_elements;

        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'elementor' => array(
                    'active' => $has_elementor,
                    'version' => $elementor_version,
                ),
                'addon_families' => array(
                    'royal_elements' => $has_royal,
                    'pro_elements' => $has_pro_elements,
                    'elementor_pro' => $has_elementor_pro,
                ),
                'widget_families' => array(
                    'core_elementor' => $has_elementor,
                    'wpr' => $has_royal,
                    'pro_elements' => $pro_capable,
                    'elementor_pro' => $pro_capable,
                ),
                'versions' => array(
                    'elementor' => $elementor_version,
                    'elementor_pro' => $elementor_pro_version,
                    'pro_elements' => defined('PRO_ELEMENTS_VERSION') ? PRO_ELEMENTS_VERSION : null,
                    'royal_elementor_addons' => defined('WPR_ADDONS_VERSION') ? WPR_ADDONS_VERSION : null,
                ),
            ),
        ));
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
            
            // NOTE: Do NOT set page_template in $post_data — WordPress validates it
            // against theme templates and will reject Elementor-specific templates like
            // 'elementor_canvas'. Instead, we set _wp_page_template via post meta after save.
            
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
            
            // Step 8a: Save schema data to meta for wp_head output (backup to content injection)
            if (!empty($schema_data)) {
                update_post_meta($page_id, '_echo5_structured_data', $schema_data);
                update_post_meta($page_id, '_echo5_schemas', $schema_data);
            }
            
            // Step 8.5: Handle page template and layout options
            // Set via post meta to bypass wp_insert_post template validation.
            // This allows Elementor-specific templates (elementor_canvas, elementor_header_footer)
            // which aren't theme template files.
            if (!empty($page_data['template'])) {
                update_post_meta($page_id, '_wp_page_template', $page_data['template']);
            }
            
            // Hide page title if requested (for landing pages)
            if (!empty($page_data['hide_title'])) {
                // Elementor way - set page settings
                $page_settings = get_post_meta($page_id, '_elementor_page_settings', true);
                if (!is_array($page_settings)) {
                    $page_settings = array();
                }
                $page_settings['hide_title'] = 'yes';
                update_post_meta($page_id, '_elementor_page_settings', $page_settings);
                
                // Also set common theme meta for hiding title
                update_post_meta($page_id, '_echo5_hide_title', true);
            }
            
            // Step 8.6: Handle custom CSS for page
            $custom_css = isset($content_data['custom_css']) ? $content_data['custom_css'] : null;
            if (!empty($custom_css)) {
                // Add to page settings for Elementor
                $page_settings = get_post_meta($page_id, '_elementor_page_settings', true);
                if (!is_array($page_settings)) {
                    $page_settings = array();
                }
                $page_settings['custom_css'] = $custom_css;
                update_post_meta($page_id, '_elementor_page_settings', $page_settings);
                
                // Also save as post meta for non-Elementor themes
                update_post_meta($page_id, '_echo5_custom_css', $custom_css);
            }
            
            // Step 9: Handle Elementor data
            // Option 1: Direct elementor_data provided in content
            $direct_elementor_data = isset($content_data['elementor_data']) ? $content_data['elementor_data'] : null;
            
            if (!empty($direct_elementor_data) && $this->is_elementor_active()) {
                $elementor_json = is_string($direct_elementor_data) ? $direct_elementor_data : json_encode($direct_elementor_data);
                
                // Validate widget compatibility and warn about missing addons
                $compat = $this->validate_widget_compatibility($elementor_json);
                if (!empty($compat['warnings'])) {
                    $warnings = array_merge($warnings, $compat['warnings']);
                }
                
                update_post_meta($page_id, '_elementor_data', wp_slash($elementor_json));
                update_post_meta($page_id, '_elementor_edit_mode', 'builder');
                update_post_meta($page_id, '_elementor_template_type', 'wp-page');
                update_post_meta($page_id, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0.0');
                
                // Clear cache and regenerate page CSS for addon widgets
                if (class_exists('\Elementor\Plugin')) {
                    \Elementor\Plugin::$instance->files_manager->clear_cache();
                    if (class_exists('\Elementor\Core\Files\CSS\Post')) {
                        $post_css = \Elementor\Core\Files\CSS\Post::create($page_id);
                        $post_css->update();
                    }
                }
            } else {
                // Option 2: Convert HTML to Elementor format if enabled
                $use_elementor = isset($page_data['use_elementor']) ? $page_data['use_elementor'] : true;
                if ($use_elementor && $this->is_elementor_active()) {
                    $elementor_data = $this->convert_html_to_elementor($final_content);
                    if (!empty($elementor_data)) {
                        update_post_meta($page_id, '_elementor_data', wp_slash($elementor_data));
                        update_post_meta($page_id, '_elementor_edit_mode', 'builder');
                        update_post_meta($page_id, '_elementor_template_type', 'wp-page');
                        update_post_meta($page_id, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0.0');
                        
                        if (class_exists('\Elementor\Plugin')) {
                            \Elementor\Plugin::$instance->files_manager->clear_cache();
                            if (class_exists('\Elementor\Core\Files\CSS\Post')) {
                                $post_css = \Elementor\Core\Files\CSS\Post::create($page_id);
                                $post_css->update();
                            }
                        }
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
     * Rollback to previous version (includes SEO meta restoration)
     */
    public function rollback_page($request) {
        $page_id = intval($request->get_param('page_id'));
        $version = intval($request->get_param('version'));
        
        $page = get_post($page_id);
        if (!$page || ($page->post_type !== 'page' && $page->post_type !== 'post')) {
            return new WP_Error('not_found', 'Page not found', array('status' => 404));
        }
        
        // Get version data
        $version_data = get_post_meta($page_id, '_echo5_version_' . $version, true);
        if (empty($version_data)) {
            return new WP_Error('version_not_found', 'Version not found', array('status' => 404));
        }
        
        // Save current as new version before rollback
        $this->save_version_snapshot($page_id, $page->post_content);
        
        // Decode snapshot - handle both old format (string content) and new format (JSON with seo_meta)
        $snapshot = json_decode($version_data, true);
        $content_to_restore = null;
        $seo_meta_to_restore = null;
        $title_to_restore = null;
        
        if (is_array($snapshot) && isset($snapshot['content'])) {
            // New format with SEO meta
            $content_to_restore = $snapshot['content'];
            $seo_meta_to_restore = isset($snapshot['seo_meta']) ? $snapshot['seo_meta'] : null;
            $title_to_restore = isset($snapshot['post_title']) ? $snapshot['post_title'] : null;
        } else {
            // Old format - just content string
            $content_to_restore = $version_data;
        }
        
        // Update post content
        $update_data = array('ID' => $page_id);
        if ($content_to_restore !== null) {
            $update_data['post_content'] = $content_to_restore;
        }
        if ($title_to_restore !== null) {
            $update_data['post_title'] = $title_to_restore;
        }
        
        $result = wp_update_post($update_data, true);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Restore SEO meta if available
        $seo_restored = false;
        if ($seo_meta_to_restore) {
            $this->restore_seo_meta_snapshot($page_id, $seo_meta_to_restore);
            $seo_restored = true;
        }
        
        $this->log_action('rollback_success', array(
            'page_id' => $page_id,
            'rolled_back_to_version' => $version,
            'seo_meta_restored' => $seo_restored,
        ));
        
        return rest_ensure_response(array(
            'success' => true,
            'page_id' => $page_id,
            'rolled_back_to_version' => $version,
            'seo_meta_restored' => $seo_restored,
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
     * Find page by slug (searches pages first, then posts)
     */
    private function find_page_by_slug($slug) {
        // First try pages
        $args = array(
            'name' => sanitize_title($slug),
            'post_type' => 'page',
            'post_status' => array('publish', 'draft', 'pending', 'private'),
            'numberposts' => 1,
        );
        
        $pages = get_posts($args);
        if (!empty($pages)) {
            return $pages[0];
        }
        
        // Then try posts
        $args['post_type'] = 'post';
        $posts = get_posts($args);
        if (!empty($posts)) {
            return $posts[0];
        }
        
        // Try any public post type as fallback
        $args['post_type'] = 'any';
        $any = get_posts($args);
        return !empty($any) ? $any[0] : null;
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
     * Save version snapshot including SEO meta
     */
    private function save_version_snapshot($page_id, $content) {
        $version_key = '_echo5_version_' . time();
        
        // Build snapshot with content AND SEO meta
        $snapshot = array(
            'content' => $content,
            'seo_meta' => $this->get_seo_meta_snapshot($page_id),
            'post_title' => get_the_title($page_id),
        );
        
        update_post_meta($page_id, $version_key, wp_json_encode($snapshot));
        
        // Increment version count
        $count = intval(get_post_meta($page_id, '_echo5_version_count', true));
        update_post_meta($page_id, '_echo5_version_count', $count + 1);
        
        // Cleanup old versions (keep last 10)
        $this->cleanup_old_versions($page_id, 10);
    }
    
    /**
     * Get SEO meta snapshot for versioning
     */
    private function get_seo_meta_snapshot($page_id) {
        $meta_keys = array(
            // RankMath
            'rank_math_title',
            'rank_math_description',
            'rank_math_focus_keyword',
            'rank_math_canonical_url',
            'rank_math_facebook_title',
            'rank_math_facebook_description',
            'rank_math_facebook_image',
            'rank_math_twitter_title',
            'rank_math_twitter_description',
            // Yoast
            '_yoast_wpseo_title',
            '_yoast_wpseo_metadesc',
            '_yoast_wpseo_focuskw',
            '_yoast_wpseo_canonical',
            '_yoast_wpseo_opengraph-title',
            '_yoast_wpseo_opengraph-description',
            '_yoast_wpseo_opengraph-image',
            '_yoast_wpseo_twitter-title',
            '_yoast_wpseo_twitter-description',
            // AIOSEO
            '_aioseo_title',
            '_aioseo_description',
            '_aioseo_keywords',
            // SEOPress
            '_seopress_titles_title',
            '_seopress_titles_desc',
            '_seopress_analysis_target_kw',
            // The SEO Framework
            '_genesis_title',
            '_genesis_description',
            'focus_keyword',
            // Echo5 fallbacks
            '_echo5_seo_title',
            '_echo5_seo_description',
            '_echo5_focus_keyword',
            '_echo5_canonical',
        );
        
        $snapshot = array();
        foreach ($meta_keys as $key) {
            $value = get_post_meta($page_id, $key, true);
            if ($value !== '' && $value !== false) {
                $snapshot[$key] = $value;
            }
        }
        
        return $snapshot;
    }
    
    /**
     * Restore SEO meta from snapshot
     */
    private function restore_seo_meta_snapshot($page_id, $seo_meta) {
        if (empty($seo_meta) || !is_array($seo_meta)) {
            return;
        }
        
        foreach ($seo_meta as $key => $value) {
            if ($value === '' || $value === null) {
                delete_post_meta($page_id, $key);
            } else {
                update_post_meta($page_id, $key, $value);
            }
        }
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
     * Scan elementor_data for widget types and check if required addons are installed.
     * Returns warnings (not errors) as the backend should have already mapped fallbacks.
     */
    private function validate_widget_compatibility($elementor_json) {
        $warnings = array();
        $decoded = is_string($elementor_json) ? json_decode($elementor_json, true) : $elementor_json;
        if (!is_array($decoded)) {
            return array('warnings' => $warnings);
        }

        $has_wpr = false;
        $has_pro = false;

        $pro_widget_types = array(
            'form', 'posts', 'portfolio', 'slides', 'flip-box', 'price-table',
            'price-list', 'testimonial-carousel', 'animated-headline',
            'call-to-action', 'media-carousel', 'countdown', 'share-buttons',
            'blockquote', 'login', 'hotspot', 'reviews', 'lottie'
        );

        $scan = function($elements) use (&$scan, &$has_wpr, &$has_pro, $pro_widget_types) {
            if (!is_array($elements)) return;
            foreach ($elements as $el) {
                $type = isset($el['widgetType']) ? $el['widgetType'] : '';
                if (strpos($type, 'wpr-') === 0) $has_wpr = true;
                if (in_array($type, $pro_widget_types, true)) $has_pro = true;
                if (!empty($el['elements'])) $scan($el['elements']);
            }
        };
        $scan($decoded);

        $royal_active = defined('WPR_ADDONS_VERSION')
            || (function_exists('is_plugin_active') && is_plugin_active('royal-elementor-addons/royal-elementor-addons.php'));
        $pro_active = defined('ELEMENTOR_PRO_VERSION') || defined('PRO_ELEMENTS_VERSION')
            || (function_exists('is_plugin_active') && is_plugin_active('pro-elements/pro-elements.php'));

        if ($has_wpr && !$royal_active) {
            $warnings[] = 'Page contains Royal Elementor Addons widgets (wpr-*) but the plugin is not active. These widgets may not render correctly.';
        }
        if ($has_pro && !$pro_active) {
            $warnings[] = 'Page contains Elementor Pro / Pro Elements widgets but neither plugin is active. These widgets may not render correctly.';
        }

        return array('warnings' => $warnings);
    }

    /**
     * Convert HTML content to Elementor JSON format
     * Creates proper sections, containers, and widgets from HTML structure
     * 
     * @param string $html The HTML content to convert
     * @return string JSON encoded Elementor data
     */
    private function convert_html_to_elementor($html) {
        // Use DOMDocument to parse HTML
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        
        // Add wrapper to handle fragments
        $wrapped_html = '<div id="echo5-wrapper">' . $html . '</div>';
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $wrapped_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        $wrapper = $dom->getElementById('echo5-wrapper');
        if (!$wrapper) {
            // Fallback: create single section with HTML widget
            return $this->create_fallback_elementor_data($html);
        }
        
        // Parse top-level elements into sections
        $sections = array();
        $current_widgets = array();
        
        foreach ($wrapper->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $text = trim($node->textContent);
                if (!empty($text)) {
                    $current_widgets[] = $this->create_text_widget($text);
                }
                continue;
            }
            
            if ($node->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            
            $tagName = strtolower($node->tagName);
            
            // Check if this is a section-level element (should become its own Elementor section)
            if (in_array($tagName, array('section', 'header', 'footer', 'article', 'main'))) {
                // Flush current widgets as a section first
                if (!empty($current_widgets)) {
                    $sections[] = $this->create_elementor_section($current_widgets);
                    $current_widgets = array();
                }
                
                // Parse this section's children into widgets
                $section_widgets = $this->parse_node_children($dom, $node);
                if (!empty($section_widgets)) {
                    $sections[] = $this->create_elementor_section($section_widgets, $node);
                }
            } else {
                // Parse this node into widgets
                $widgets = $this->parse_node_to_widgets($dom, $node);
                $current_widgets = array_merge($current_widgets, $widgets);
            }
        }
        
        // Flush remaining widgets
        if (!empty($current_widgets)) {
            $sections[] = $this->create_elementor_section($current_widgets);
        }
        
        // If no sections created, fallback
        if (empty($sections)) {
            return $this->create_fallback_elementor_data($html);
        }
        
        return json_encode($sections);
    }
    
    /**
     * Create fallback Elementor data with single HTML widget
     */
    private function create_fallback_elementor_data($html) {
        $section_id = $this->generate_elementor_id();
        $column_id = $this->generate_elementor_id();
        $widget_id = $this->generate_elementor_id();
        
        $elementor_data = array(
            array(
                'id' => $section_id,
                'elType' => 'section',
                'settings' => array(
                    'structure' => '10',
                    'content_width' => 'boxed',
                ),
                'elements' => array(
                    array(
                        'id' => $column_id,
                        'elType' => 'column',
                        'settings' => array(
                            '_column_size' => 100,
                        ),
                        'elements' => array(
                            array(
                                'id' => $widget_id,
                                'elType' => 'widget',
                                'widgetType' => 'text-editor',
                                'settings' => array(
                                    'editor' => $html,
                                ),
                                'elements' => array(),
                            ),
                        ),
                    ),
                ),
            ),
        );
        
        return json_encode($elementor_data);
    }
    
    /**
     * Create an Elementor section with widgets
     */
    private function create_elementor_section($widgets, $source_node = null) {
        $section_id = $this->generate_elementor_id();
        $column_id = $this->generate_elementor_id();
        
        $settings = array(
            'structure' => '10',
            'content_width' => 'boxed',
            'gap' => 'default',
        );
        
        // Extract background color or class from source node if available
        if ($source_node && $source_node instanceof DOMElement) {
            $class = $source_node->getAttribute('class');
            $style = $source_node->getAttribute('style');
            
            // Check for common background patterns
            if (preg_match('/bg-(?:gray|slate|zinc|neutral|stone)-(\d+)/i', $class, $matches)) {
                // Tailwind gray backgrounds
                $shade = intval($matches[1]);
                if ($shade >= 800) {
                    $settings['background_background'] = 'classic';
                    $settings['background_color'] = '#1f2937';
                } elseif ($shade >= 600) {
                    $settings['background_background'] = 'classic';
                    $settings['background_color'] = '#4b5563';
                } elseif ($shade >= 100) {
                    $settings['background_background'] = 'classic';
                    $settings['background_color'] = '#f3f4f6';
                }
            }
            
            // Check for padding classes
            if (preg_match('/py-(\d+)/i', $class, $matches)) {
                $padding = intval($matches[1]) * 4; // Tailwind uses 4px units
                $settings['padding'] = array(
                    'unit' => 'px',
                    'top' => strval($padding),
                    'bottom' => strval($padding),
                    'left' => '0',
                    'right' => '0',
                    'isLinked' => false,
                );
            }
        }
        
        return array(
            'id' => $section_id,
            'elType' => 'section',
            'settings' => $settings,
            'elements' => array(
                array(
                    'id' => $column_id,
                    'elType' => 'column',
                    'settings' => array(
                        '_column_size' => 100,
                    ),
                    'elements' => $widgets,
                ),
            ),
        );
    }
    
    /**
     * Parse a node's children into widgets
     */
    private function parse_node_children($dom, $parent_node) {
        $widgets = array();
        $text_buffer = '';
        
        foreach ($parent_node->childNodes as $node) {
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
            
            // Flush text buffer before processing element
            if (!empty(trim($text_buffer))) {
                $widgets[] = $this->create_text_widget($text_buffer);
                $text_buffer = '';
            }
            
            $node_widgets = $this->parse_node_to_widgets($dom, $node);
            $widgets = array_merge($widgets, $node_widgets);
        }
        
        // Flush remaining text buffer
        if (!empty(trim($text_buffer))) {
            $widgets[] = $this->create_text_widget($text_buffer);
        }
        
        return $widgets;
    }
    
    /**
     * Parse a single DOM node into Elementor widgets
     */
    private function parse_node_to_widgets($dom, $node) {
        $widgets = array();
        $tagName = strtolower($node->tagName);
        
        // FIRST: Check for data-elementor attribute for specialized widgets
        $elementor_type = $node->getAttribute('data-elementor');
        if (!empty($elementor_type)) {
            $widget = $this->create_elementor_widget_from_data($dom, $node, $elementor_type);
            if ($widget) {
                $widgets[] = $widget;
                return $widgets;
            }
        }
        
        switch ($tagName) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $widgets[] = $this->create_heading_widget(trim($node->textContent), $tagName, $node);
                break;
                
            case 'p':
                $inner = $this->get_inner_html($dom, $node);
                if (!empty(trim($inner))) {
                    $widgets[] = $this->create_text_widget('<p>' . $inner . '</p>');
                }
                break;
                
            case 'img':
                $src = $node->getAttribute('src');
                $alt = $node->getAttribute('alt');
                if (!empty($src)) {
                    $widgets[] = $this->create_image_widget($src, $alt);
                }
                break;
                
            case 'ul':
            case 'ol':
                $widgets[] = $this->create_text_widget($dom->saveHTML($node));
                break;
                
            case 'blockquote':
                $inner = $this->get_inner_html($dom, $node);
                $widgets[] = $this->create_text_widget('<blockquote>' . $inner . '</blockquote>');
                break;
                
            case 'a':
                // Check if it's a button-like link
                $class = $node->getAttribute('class');
                $elementor_attr = $node->getAttribute('data-elementor');
                if ($elementor_attr === 'button' || strpos($class, 'btn') !== false || strpos($class, 'button') !== false) {
                    $widgets[] = $this->create_button_widget($node);
                } else {
                    // Inline link, add as text
                    $widgets[] = $this->create_text_widget($dom->saveHTML($node));
                }
                break;
                
            case 'section':
            case 'article':
            case 'aside':
            case 'main':
                // These become their own sections with children parsed
                $child_widgets = $this->parse_node_children($dom, $node);
                $widgets = array_merge($widgets, $child_widgets);
                break;
                
            case 'div':
                // Check if it's a container with specific structure
                $class = $node->getAttribute('class');
                
                // If it has grid/flex classes, try to parse children
                if (preg_match('/grid|flex|container|max-w-/i', $class)) {
                    $child_widgets = $this->parse_node_children($dom, $node);
                    $widgets = array_merge($widgets, $child_widgets);
                } else {
                    // Parse children directly
                    $child_widgets = $this->parse_node_children($dom, $node);
                    if (!empty($child_widgets)) {
                        $widgets = array_merge($widgets, $child_widgets);
                    } else {
                        // Empty div with just text content
                        $text = trim($node->textContent);
                        if (!empty($text)) {
                            $widgets[] = $this->create_text_widget($dom->saveHTML($node));
                        }
                    }
                }
                break;
                
            case 'span':
            case 'strong':
            case 'em':
            case 'b':
            case 'i':
                // Inline elements - wrap in paragraph
                $widgets[] = $this->create_text_widget('<p>' . $dom->saveHTML($node) . '</p>');
                break;
                
            case 'hr':
                $widgets[] = $this->create_divider_widget();
                break;
                
            case 'table':
                // Tables go as HTML widget
                $widgets[] = $this->create_html_widget($dom->saveHTML($node));
                break;
                
            case 'form':
            case 'iframe':
            case 'video':
            case 'audio':
            case 'canvas':
            case 'svg':
                // Special elements go as HTML widget
                $widgets[] = $this->create_html_widget($dom->saveHTML($node));
                break;
                
            default:
                // Try to parse children, or add as HTML widget
                $child_widgets = $this->parse_node_children($dom, $node);
                if (!empty($child_widgets)) {
                    $widgets = array_merge($widgets, $child_widgets);
                } else {
                    $html = $dom->saveHTML($node);
                    if (!empty(trim($html))) {
                        $widgets[] = $this->create_text_widget($html);
                    }
                }
                break;
        }
        
        return $widgets;
    }
    
    /**
     * Create Elementor heading widget
     */
    private function create_heading_widget($text, $tag = 'h2', $source_node = null) {
        $size_map = array(
            'h1' => 'xl',
            'h2' => 'large',
            'h3' => 'medium',
            'h4' => 'small',
            'h5' => 'small',
            'h6' => 'small',
        );
        
        $settings = array(
            'title' => $text,
            'header_size' => $tag,
            'size' => isset($size_map[$tag]) ? $size_map[$tag] : 'default',
        );
        
        // Check for alignment classes
        if ($source_node && $source_node instanceof DOMElement) {
            $class = $source_node->getAttribute('class');
            if (strpos($class, 'text-center') !== false) {
                $settings['align'] = 'center';
            } elseif (strpos($class, 'text-right') !== false) {
                $settings['align'] = 'right';
            }
        }
        
        return array(
            'id' => $this->generate_elementor_id(),
            'elType' => 'widget',
            'widgetType' => 'heading',
            'settings' => $settings,
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
                'width' => array('unit' => '%', 'size' => 100),
                'width_mobile' => array('unit' => '%', 'size' => 100),
                'height' => array('unit' => 'px', 'size' => 400),
                'height_tablet' => array('unit' => 'px', 'size' => 320),
                'height_mobile' => array('unit' => 'px', 'size' => 240),
                'object_fit' => 'cover',
            ),
            'elements' => array(),
        );
    }
    
    /**
     * Create Elementor button widget
     */
    private function create_button_widget($node) {
        $text = trim($node->textContent);
        $url = $node->getAttribute('href');
        
        return array(
            'id' => $this->generate_elementor_id(),
            'elType' => 'widget',
            'widgetType' => 'button',
            'settings' => array(
                'text' => $text,
                'link' => array(
                    'url' => $url,
                    'is_external' => strpos($url, 'http') === 0,
                    'nofollow' => false,
                ),
                'align' => 'center',
            ),
            'elements' => array(),
        );
    }
    
    /**
     * Create Elementor divider widget
     */
    private function create_divider_widget() {
        return array(
            'id' => $this->generate_elementor_id(),
            'elType' => 'widget',
            'widgetType' => 'divider',
            'settings' => array(
                'style' => 'solid',
                'weight' => array(
                    'unit' => 'px',
                    'size' => 1,
                ),
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
    
    /**
     * Create Elementor widget based on data-elementor attribute
     * Supports: icon-box, icon-list, counter, testimonial, call-to-action, accordion, star-rating, button
     */
    private function create_elementor_widget_from_data($dom, $node, $type) {
        switch ($type) {
            case 'icon-box':
                return $this->create_icon_box_widget($dom, $node);
                
            case 'icon-list':
                return $this->create_icon_list_widget($dom, $node);
                
            case 'counter':
                return $this->create_counter_widget($node);
                
            case 'testimonial':
                return $this->create_testimonial_widget($node);
                
            case 'call-to-action':
                return $this->create_cta_widget($dom, $node);
                
            case 'accordion':
                return $this->create_accordion_widget($dom, $node);
                
            case 'star-rating':
                return $this->create_star_rating_widget($node);
                
            case 'button':
                return $this->create_button_widget($node);
                
            case 'container':
            case 'section':
                // These are structural, parse children
                return null;
                
            default:
                return null;
        }
    }
    
    /**
     * Create Elementor Icon Box widget
     */
    private function create_icon_box_widget($dom, $node) {
        $icon = $node->getAttribute('data-icon') ?: 'fa fa-star';
        
        // Map common icon names to Font Awesome
        $icon_map = array(
            'wrench' => 'fa fa-wrench',
            'check' => 'fa fa-check',
            'star' => 'fa fa-star',
            'phone' => 'fa fa-phone',
            'clock' => 'fa fa-clock',
            'shield' => 'fa fa-shield',
            'home' => 'fa fa-home',
            'users' => 'fa fa-users',
            'cog' => 'fa fa-cog',
            'bolt' => 'fa fa-bolt',
            'heart' => 'fa fa-heart',
            'thumbs-up' => 'fa fa-thumbs-up',
            'award' => 'fa fa-award',
            'certificate' => 'fa fa-certificate',
        );
        
        if (isset($icon_map[$icon])) {
            $icon = $icon_map[$icon];
        }
        
        // Extract title (first h3 or h4) and description
        $title = '';
        $description = '';
        
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $childTag = strtolower($child->tagName);
                if (in_array($childTag, array('h3', 'h4', 'h5')) && empty($title)) {
                    $title = trim($child->textContent);
                } elseif (in_array($childTag, array('p', 'div', 'span'))) {
                    $description .= trim($child->textContent) . ' ';
                }
            }
        }
        
        if (empty($title)) {
            $title = trim($node->textContent);
        }
        
        return array(
            'id' => $this->generate_elementor_id(),
            'elType' => 'widget',
            'widgetType' => 'icon-box',
            'settings' => array(
                'selected_icon' => array(
                    'value' => $icon,
                    'library' => 'fa-solid',
                ),
                'title_text' => $title,
                'description_text' => trim($description),
                'position' => 'top',
                'title_size' => 'h3',
            ),
            'elements' => array(),
        );
    }
    
    /**
     * Create Elementor Icon List widget
     */
    private function create_icon_list_widget($dom, $node) {
        $items = array();
        $default_icon = $node->getAttribute('data-icon') ?: 'check';
        
        // Map icon names
        $icon_map = array(
            'check' => 'fa fa-check',
            'check-circle' => 'fa fa-check-circle',
            'star' => 'fa fa-star',
            'arrow-right' => 'fa fa-arrow-right',
            'chevron-right' => 'fa fa-chevron-right',
        );
        
        $fa_icon = isset($icon_map[$default_icon]) ? $icon_map[$default_icon] : 'fa fa-check';
        
        // Find list items (divs with data-icon or li elements)
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $text = trim($child->textContent);
                if (!empty($text)) {
                    $child_icon = $child->getAttribute('data-icon');
                    $icon_to_use = !empty($child_icon) && isset($icon_map[$child_icon]) 
                        ? $icon_map[$child_icon] 
                        : $fa_icon;
                    
                    $items[] = array(
                        'text' => $text,
                        'selected_icon' => array(
                            'value' => $icon_to_use,
                            'library' => 'fa-solid',
                        ),
                    );
                }
            }
        }
        
        return array(
            'id' => $this->generate_elementor_id(),
            'elType' => 'widget',
            'widgetType' => 'icon-list',
            'settings' => array(
                'icon_list' => $items,
                'view' => 'traditional',
            ),
            'elements' => array(),
        );
    }
    
    /**
     * Create Elementor Counter widget
     */
    private function create_counter_widget($node) {
        $number = $node->getAttribute('data-number') ?: '0';
        $suffix = $node->getAttribute('data-suffix') ?: '';
        $prefix = $node->getAttribute('data-prefix') ?: '';
        $title = trim($node->textContent);
        
        return array(
            'id' => $this->generate_elementor_id(),
            'elType' => 'widget',
            'widgetType' => 'counter',
            'settings' => array(
                'starting_number' => 0,
                'ending_number' => intval($number),
                'prefix' => $prefix,
                'suffix' => $suffix,
                'title' => $title,
                'duration' => 2000,
            ),
            'elements' => array(),
        );
    }
    
    /**
     * Create Elementor Testimonial widget
     */
    private function create_testimonial_widget($node) {
        $name = $node->getAttribute('data-name') ?: 'Customer';
        $company = $node->getAttribute('data-company') ?: '';
        $rating = $node->getAttribute('data-rating') ?: '';
        $image = $node->getAttribute('data-image') ?: '';
        $content = trim($node->textContent);
        
        $settings = array(
            'testimonial_content' => $content,
            'testimonial_name' => $name,
            'testimonial_job' => $company,
            'testimonial_alignment' => 'center',
        );
        
        if (!empty($image)) {
            $settings['testimonial_image'] = array(
                'url' => $image,
            );
        }
        
        return array(
            'id' => $this->generate_elementor_id(),
            'elType' => 'widget',
            'widgetType' => 'testimonial',
            'settings' => $settings,
            'elements' => array(),
        );
    }
    
    /**
     * Create Elementor Call to Action widget
     */
    private function create_cta_widget($dom, $node) {
        // Extract title, description, and button from children
        $title = '';
        $description = '';
        $button_text = 'Learn More';
        $button_url = '#';
        
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $childTag = strtolower($child->tagName);
                if (in_array($childTag, array('h2', 'h3', 'h4')) && empty($title)) {
                    $title = trim($child->textContent);
                } elseif ($childTag === 'p') {
                    $description .= trim($child->textContent) . ' ';
                } elseif ($childTag === 'a') {
                    $button_text = trim($child->textContent);
                    $button_url = $child->getAttribute('href') ?: '#';
                }
            }
        }
        
        return array(
            'id' => $this->generate_elementor_id(),
            'elType' => 'widget',
            'widgetType' => 'call-to-action',
            'settings' => array(
                'title' => $title,
                'description' => trim($description),
                'button' => $button_text,
                'link' => array(
                    'url' => $button_url,
                ),
                'skin' => 'classic',
            ),
            'elements' => array(),
        );
    }
    
    /**
     * Create Elementor Accordion widget (for FAQs)
     */
    private function create_accordion_widget($dom, $node) {
        $items = array();
        
        // Look for question/answer pairs
        // Expected structure: divs or details elements with question in h* or summary, answer in p/div
        $current_question = '';
        $current_answer = '';
        
        foreach ($node->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            
            $childTag = strtolower($child->tagName);
            
            if ($childTag === 'details') {
                // HTML5 details/summary pattern
                $question = '';
                $answer = '';
                foreach ($child->childNodes as $detailChild) {
                    if ($detailChild->nodeType === XML_ELEMENT_NODE) {
                        if (strtolower($detailChild->tagName) === 'summary') {
                            $question = trim($detailChild->textContent);
                        } else {
                            $answer .= trim($detailChild->textContent) . ' ';
                        }
                    }
                }
                if (!empty($question)) {
                    $items[] = array(
                        'tab_title' => $question,
                        'tab_content' => trim($answer),
                    );
                }
            } elseif ($childTag === 'div') {
                // Div-based FAQ pattern
                $question = '';
                $answer = '';
                foreach ($child->childNodes as $faqChild) {
                    if ($faqChild->nodeType === XML_ELEMENT_NODE) {
                        $faqChildTag = strtolower($faqChild->tagName);
                        if (in_array($faqChildTag, array('h3', 'h4', 'h5', 'strong', 'b'))) {
                            $question = trim($faqChild->textContent);
                        } elseif (in_array($faqChildTag, array('p', 'div', 'span'))) {
                            $answer .= trim($faqChild->textContent) . ' ';
                        }
                    }
                }
                if (!empty($question)) {
                    $items[] = array(
                        'tab_title' => $question,
                        'tab_content' => trim($answer),
                    );
                }
            }
        }
        
        // If no structured items found, try to create from text content
        if (empty($items)) {
            $items[] = array(
                'tab_title' => 'Frequently Asked Questions',
                'tab_content' => trim($node->textContent),
            );
        }
        
        return array(
            'id' => $this->generate_elementor_id(),
            'elType' => 'widget',
            'widgetType' => 'accordion',
            'settings' => array(
                'tabs' => $items,
                'selected_icon' => array(
                    'value' => 'fa fa-plus',
                    'library' => 'fa-solid',
                ),
                'selected_active_icon' => array(
                    'value' => 'fa fa-minus',
                    'library' => 'fa-solid',
                ),
            ),
            'elements' => array(),
        );
    }
    
    /**
     * Create Elementor Star Rating widget
     */
    private function create_star_rating_widget($node) {
        $rating = floatval($node->getAttribute('data-rating') ?: '5');
        $scale = intval($node->getAttribute('data-scale') ?: '5');
        
        return array(
            'id' => $this->generate_elementor_id(),
            'elType' => 'widget',
            'widgetType' => 'star-rating',
            'settings' => array(
                'rating_scale' => $scale,
                'rating' => $rating,
                'star_style' => 'star_fontawesome',
                'unmarked_star_style' => 'outline',
            ),
            'elements' => array(),
        );
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

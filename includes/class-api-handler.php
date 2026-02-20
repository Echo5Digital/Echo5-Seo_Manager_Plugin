<?php
/**
 * API Handler - Registers and handles all REST API endpoints
 */

class Echo5_SEO_API_Handler {
    
    private $namespace = 'echo5-seo/v1';
    private $data_exporter;
    private $security;
    private $brand_extractor;
    
    public function __construct($data_exporter, $security) {
        $this->data_exporter = $data_exporter;
        $this->security = $security;
        $this->brand_extractor = new Echo5_Brand_Extractor();
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        
        // Get all pages with SEO data
        register_rest_route($this->namespace, '/pages', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_pages'),
            'permission_callback' => array($this->security, 'verify_api_key'),
            'args' => array(
                'per_page' => array(
                    'default' => 20,
                    'sanitize_callback' => 'absint',
                ),
                'page' => array(
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ),
                'fields' => array(
                    'default' => 'all',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
        
        // Get single page with full SEO data
        register_rest_route($this->namespace, '/pages/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_page'),
            'permission_callback' => array($this->security, 'verify_api_key'),
        ));
        
        // Get all posts with SEO data
        register_rest_route($this->namespace, '/posts', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_posts'),
            'permission_callback' => array($this->security, 'verify_api_key'),
            'args' => array(
                'per_page' => array(
                    'default' => 20,
                    'sanitize_callback' => 'absint',
                ),
                'page' => array(
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));
        
        // Get site structure
        register_rest_route($this->namespace, '/structure', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_site_structure'),
            'permission_callback' => array($this->security, 'verify_api_key'),
        ));
        
        // Get all content (pages + posts) - MAIN ENDPOINT
        register_rest_route($this->namespace, '/content/all', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_all_content'),
            'permission_callback' => array($this->security, 'verify_api_key'),
            'args' => array(
                'api_key' => array(
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'per_page' => array(
                    'default' => 50,
                    'sanitize_callback' => 'absint',
                ),
                'page' => array(
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ),
                'include_content' => array(
                    'default' => true,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ),
            ),
        ));
        
        // Get internal links map
        register_rest_route($this->namespace, '/links/internal', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_internal_links'),
            'permission_callback' => array($this->security, 'verify_api_key'),
        ));
        
        // Get SEO plugin data (Yoast/RankMath)
        register_rest_route($this->namespace, '/seo-plugins', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_seo_plugin_data'),
            'permission_callback' => array($this->security, 'verify_api_key'),
        ));
        
        // Health check endpoint
        register_rest_route($this->namespace, '/health', array(
            'methods' => 'GET',
            'callback' => array($this, 'health_check'),
            'permission_callback' => array($this->security, 'verify_api_key'),
            'args' => array(
                'api_key' => array(
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function($param) {
                        return is_string($param);
                    }
                ),
            ),
        ));

        // Renderer capabilities endpoint for publisher/native Elementor mode
        register_rest_route('echo5/v1', '/capabilities', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_capabilities'),
            'permission_callback' => array($this->security, 'verify_api_key'),
            'args' => array(
                'api_key' => array(
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function($param) {
                        return is_string($param);
                    }
                ),
            ),
        ));
        
        // Regenerate API key (admin only)
        register_rest_route($this->namespace, '/admin/regenerate-key', array(
            'methods' => 'POST',
            'callback' => array($this, 'regenerate_api_key'),
            'permission_callback' => array($this->security, 'verify_admin'),
        ));
        
        // Extract brand tokens (colors, typography) from Elementor
        register_rest_route($this->namespace, '/brand-tokens/extract', array(
            'methods' => 'GET',
            'callback' => array($this, 'extract_brand_tokens'),
            'permission_callback' => array($this->security, 'verify_api_key'),
            'args' => array(
                'page_limit' => array(
                    'default' => 10,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));
        
        // Update SEO data for a page/post
        register_rest_route($this->namespace, '/update-seo/(?P<id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_seo'),
            'permission_callback' => array($this->security, 'verify_api_key'),
            'args' => array(
                'meta_title' => array(
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'meta_description' => array(
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'focus_keyword' => array(
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'canonical' => array(
                    'sanitize_callback' => 'esc_url_raw',
                ),
                'og_title' => array(
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'og_description' => array(
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'og_image' => array(
                    'sanitize_callback' => 'esc_url_raw',
                ),
                'schema' => array(
                    'sanitize_callback' => 'wp_kses_post',
                ),
            ),
        ));
    }
    
    /**
     * Get all pages with SEO data
     */
    public function get_pages($request) {
        $per_page = $request->get_param('per_page');
        $page = $request->get_param('page');
        $fields = $request->get_param('fields');
        
        $pages = $this->data_exporter->get_pages($per_page, $page, $fields);
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => $pages['items'],
            'pagination' => array(
                'total' => $pages['total'],
                'pages' => $pages['total_pages'],
                'current_page' => $page,
                'per_page' => $per_page,
            ),
            'timestamp' => current_time('mysql'),
        ));
    }
    
    /**
     * Get single page with full SEO data
     */
    public function get_page($request) {
        $id = $request->get_param('id');
        $page = $this->data_exporter->get_single_page($id);
        
        if (!$page) {
            return new WP_Error('not_found', 'Page not found', array('status' => 404));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => $page,
            'timestamp' => current_time('mysql'),
        ));
    }
    
    /**
     * Get all posts with SEO data
     */
    public function get_posts($request) {
        $per_page = $request->get_param('per_page');
        $page = $request->get_param('page');
        
        $posts = $this->data_exporter->get_posts($per_page, $page);
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => $posts['items'],
            'pagination' => array(
                'total' => $posts['total'],
                'pages' => $posts['total_pages'],
                'current_page' => $page,
                'per_page' => $per_page,
            ),
            'timestamp' => current_time('mysql'),
        ));
    }
    
    /**
     * Get all content (pages + posts combined)
     */
    public function get_all_content($request) {
        $per_page = $request->get_param('per_page');
        $page = $request->get_param('page');
        $include_content = $request->get_param('include_content');
        
        $content = $this->data_exporter->get_all_content($per_page, $page, $include_content);
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => $content['items'],
            'pagination' => array(
                'total' => $content['total'],
                'pages' => $content['total_pages'],
                'current_page' => $page,
                'per_page' => $per_page,
            ),
            'site_info' => array(
                'name' => get_bloginfo('name'),
                'url' => get_site_url(),
                'description' => get_bloginfo('description'),
                'language' => get_bloginfo('language'),
            ),
            'timestamp' => current_time('mysql'),
        ));
    }
    
    /**
     * Get site structure
     */
    public function get_site_structure($request) {
        $structure = $this->data_exporter->get_site_structure();
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => $structure,
            'timestamp' => current_time('mysql'),
        ));
    }
    
    /**
     * Get internal links map
     */
    public function get_internal_links($request) {
        $links = $this->data_exporter->get_internal_links_map();
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => $links,
            'timestamp' => current_time('mysql'),
        ));
    }
    
    /**
     * Get SEO plugin data
     */
    public function get_seo_plugin_data($request) {
        $data = $this->data_exporter->get_seo_plugin_info();
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => $data,
            'timestamp' => current_time('mysql'),
        ));
    }
    
    /**
     * Health check
     */
    public function health_check($request) {
        return rest_ensure_response(array(
            'success' => true,
            'status' => 'healthy',
            'version' => ECHO5_SEO_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'timestamp' => current_time('mysql'),
        ));
    }

    /**
     * Get publishing/render capabilities for Echo5 publisher.
     * Exposes Elementor + addon family availability.
     */
    public function get_capabilities($request) {
        $active_plugins = $this->get_active_plugins();

        $elementor_active = (
            did_action('elementor/loaded') ||
            class_exists('\Elementor\Plugin') ||
            $this->is_plugin_active_by_fragment($active_plugins, array('elementor/elementor.php'))
        );

        $royal_elements_active = (
            defined('WPR_ADDONS_VERSION') ||
            did_action('wpr-addons/init') ||
            $this->is_plugin_active_by_fragment($active_plugins, array(
                'royal-elementor-addons',
                'wpr-addons'
            ))
        );

        $pro_elements_active = $this->is_plugin_active_by_fragment($active_plugins, array(
            'pro-elements',
            'proelements'
        ));

        $elementor_pro_active = (
            defined('ELEMENTOR_PRO_VERSION') ||
            $this->is_plugin_active_by_fragment($active_plugins, array(
                'elementor-pro/elementor-pro.php'
            ))
        );

        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'elementor' => array(
                    'active' => $elementor_active,
                    'version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
                ),
                'addon_families' => array(
                    'royal_elements' => $royal_elements_active,
                    'pro_elements' => $pro_elements_active,
                    'elementor_pro' => $elementor_pro_active,
                ),
                'widget_families' => array(
                    'core_elementor' => $elementor_active,
                    'wpr' => $royal_elements_active,
                    'pro_elements' => $pro_elements_active,
                    'elementor_pro' => $elementor_pro_active,
                ),
                'timestamp' => current_time('mysql'),
            ),
        ));
    }

    /**
     * Get active plugin file paths (single-site + multisite network-activated).
     */
    private function get_active_plugins() {
        $active_plugins = (array) get_option('active_plugins', array());
        if (is_multisite()) {
            $network_plugins = array_keys((array) get_site_option('active_sitewide_plugins', array()));
            $active_plugins = array_merge($active_plugins, $network_plugins);
        }
        return array_values(array_unique($active_plugins));
    }

    /**
     * Check if any active plugin path contains one of the fragments.
     */
    private function is_plugin_active_by_fragment($active_plugins, $fragments) {
        foreach ((array) $active_plugins as $plugin_path) {
            foreach ((array) $fragments as $fragment) {
                if (strpos($plugin_path, $fragment) !== false) {
                    return true;
                }
            }
        }
        return false;
    }
    
    /**
     * Regenerate API key (admin only)
     */
    public function regenerate_api_key($request) {
        $new_key = 'echo5_' . bin2hex(random_bytes(32));
        update_option('echo5_seo_api_key', $new_key);
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'API key regenerated successfully',
            'new_key' => $new_key,
            'timestamp' => current_time('mysql'),
        ));
    }
    
    /**
     * Extract brand tokens (colors, typography) from Elementor
     * 
     * Uses hybrid approach:
     * 1. Get global colors/fonts from Elementor Kit settings
     * 2. Supplement by scanning pages for most-used colors/fonts
     */
    public function extract_brand_tokens($request) {
        $page_limit = $request->get_param('page_limit');
        
        // Check if Elementor is active
        if (!did_action('elementor/loaded')) {
            return new WP_Error(
                'elementor_not_active',
                'Elementor is not active on this site. Brand token extraction requires Elementor.',
                array('status' => 400)
            );
        }
        
        try {
            $tokens = $this->brand_extractor->extract_brand_tokens($page_limit);
            
            return rest_ensure_response(array(
                'success' => true,
                'data' => $tokens,
                'message' => 'Brand tokens extracted successfully',
                'timestamp' => current_time('mysql'),
            ));
        } catch (Exception $e) {
            return new WP_Error(
                'extraction_failed',
                'Failed to extract brand tokens: ' . $e->getMessage(),
                array('status' => 500)
            );
        }
    }
    
    /**
     * Update SEO data for a page/post
     * Applies fix suggestions directly to the WordPress page
     */
    public function update_seo($request) {
        $post_id = absint($request->get_param('id'));
        
        // Verify post exists
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error(
                'post_not_found',
                'Page or post not found',
                array('status' => 404)
            );
        }
        
        // Initialize SEO Meta Handler
        $seo_handler = new Echo5_SEO_Meta_Handler();
        
        // Collect updates to apply
        $updates = array();
        $results = array();
        
        // Update title
        if ($request->has_param('meta_title')) {
            $updates['meta_title'] = $request->get_param('meta_title');
        }
        
        // Update meta description
        if ($request->has_param('meta_description')) {
            $updates['meta_description'] = $request->get_param('meta_description');
        }
        
        // Update focus keyword
        if ($request->has_param('focus_keyword')) {
            $updates['focus_keyword'] = $request->get_param('focus_keyword');
        }
        
        // Update canonical URL
        if ($request->has_param('canonical')) {
            $updates['canonical'] = $request->get_param('canonical');
        }
        
        // Update Open Graph
        if ($request->has_param('og_title')) {
            $updates['og_title'] = $request->get_param('og_title');
        }
        if ($request->has_param('og_description')) {
            $updates['og_description'] = $request->get_param('og_description');
        }
        if ($request->has_param('og_image')) {
            $updates['og_image'] = $request->get_param('og_image');
        }
        
        // Update page title (H1/post_title) if provided
        if ($request->has_param('page_title')) {
            $page_title = sanitize_text_field($request->get_param('page_title'));
            
            // Update WordPress post_title
            wp_update_post(array(
                'ID' => $post_id,
                'post_title' => $page_title
            ));
            $results['page_title'] = true;
            
            // Also try to update/create Elementor H1 heading widget
            $elementor_data = get_post_meta($post_id, '_elementor_data', true);
            if (!empty($elementor_data)) {
                $data = is_string($elementor_data) ? json_decode($elementor_data, true) : $elementor_data;
                if (is_array($data) && !empty($data)) {
                    $updated = $this->update_elementor_h1($data, $page_title);
                    if ($updated['found']) {
                        update_post_meta($post_id, '_elementor_data', wp_slash(json_encode($updated['data'])));
                        $results['elementor_h1'] = true;
                        
                        // Report how the H1 was handled
                        if (!empty($updated['created'])) {
                            $results['elementor_h1_action'] = 'created';
                        } elseif (!empty($updated['converted'])) {
                            $results['elementor_h1_action'] = 'converted_from_h2';
                        } else {
                            $results['elementor_h1_action'] = 'updated';
                        }
                    }
                }
            }
        }
        
        // Update schema/structured data
        if ($request->has_param('schema')) {
            $schema = $request->get_param('schema');
            
            // Parse schema if it's a string
            $schema_data = $schema;
            if (is_string($schema)) {
                $parsed = json_decode($schema, true);
                if ($parsed) {
                    $schema_data = $parsed;
                }
            }
            
            // Store in both meta keys for compatibility
            update_post_meta($post_id, '_echo5_structured_data', $schema_data);
            update_post_meta($post_id, '_echo5_schemas', array('main' => $schema_data));
            
            // Also inject schema into page content (HTML widget) for Elementor compatibility
            $current_content = $post->post_content;
            $schema_html = $this->build_schema_html($schema_data);
            
            // Check if schema marker exists in content
            if (preg_match('/<!-- ECHO5:START SCHEMA -->.*?<!-- ECHO5:END SCHEMA -->/s', $current_content)) {
                // Replace existing schema
                $new_content = preg_replace(
                    '/<!-- ECHO5:START SCHEMA -->.*?<!-- ECHO5:END SCHEMA -->/s',
                    $schema_html,
                    $current_content
                );
            } else {
                // Append schema to content
                $new_content = $current_content . "\n" . $schema_html;
            }
            
            // Update post content with schema
            wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $new_content
            ));
            
            $results['schema'] = true;
            $results['schema_injected'] = true;
        }
        
        // Apply SEO meta updates using the handler
        if (!empty($updates)) {
            $seo_results = $seo_handler->save_all_meta($post_id, $updates);
            $results = array_merge($results, $seo_results);
        }
        
        // Build the list of all updates applied (including page_title and schema)
        $all_updates = array_keys($updates);
        if (!empty($results['page_title'])) {
            $all_updates[] = 'page_title';
        }
        if (!empty($results['schema'])) {
            $all_updates[] = 'schema';
        }
        
        // Log the update
        update_post_meta($post_id, '_echo5_last_seo_update', current_time('mysql'));
        update_post_meta($post_id, '_echo5_seo_update_source', 'manager_api');
        
        return rest_ensure_response(array(
            'success' => true,
            'post_id' => $post_id,
            'updates_applied' => $all_updates,
            'results' => $results,
            'message' => 'SEO data updated successfully',
            'timestamp' => current_time('mysql'),
        ));
    }
    
    /**
     * Build schema HTML for injection into page content
     * Creates JSON-LD script tags wrapped in Echo5 markers
     */
    private function build_schema_html($schema_data) {
        $html = "\n<!-- ECHO5:START SCHEMA -->\n";
        
        if (is_array($schema_data)) {
            // Check if it's a single schema object or multiple
            if (isset($schema_data['@context']) || isset($schema_data['@type']) || isset($schema_data['@graph'])) {
                // Single schema object
                $html .= '<script type="application/ld+json">' . "\n";
                $html .= json_encode($schema_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $html .= "\n</script>\n";
            } else {
                // Multiple schemas keyed by type
                foreach ($schema_data as $type => $schema) {
                    if (!empty($schema)) {
                        $html .= '<script type="application/ld+json">' . "\n";
                        $html .= json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        $html .= "\n</script>\n";
                    }
                }
            }
        } elseif (is_string($schema_data) && !empty($schema_data)) {
            // Already a JSON string, wrap it
            $html .= '<script type="application/ld+json">' . "\n";
            $html .= $schema_data;
            $html .= "\n</script>\n";
        }
        
        $html .= "<!-- ECHO5:END SCHEMA -->\n";
        
        return $html;
    }
    
    /**
     * Update or create an H1 heading widget in Elementor data
     * First tries to find and update existing H1, then tries to convert first H2/H3 to H1,
     * and finally adds a new H1 section at the top if none exists
     * 
     * @param array $elements Elementor elements array
     * @param string $new_title New H1 title text
     * @return array ['found' => bool, 'created' => bool, 'converted' => bool, 'data' => modified elements]
     */
    private function update_elementor_h1($elements, $new_title) {
        // First, try to find and update existing H1
        $result = $this->find_and_update_h1($elements, $new_title);
        if ($result['found']) {
            return array(
                'found' => true,
                'created' => false,
                'converted' => false,
                'data' => $result['data']
            );
        }
        
        // No H1 found - try to convert the first H2 or H3 to H1
        $result = $this->convert_first_heading_to_h1($elements, $new_title);
        if ($result['found']) {
            return array(
                'found' => true,
                'created' => false,
                'converted' => true,
                'data' => $result['data']
            );
        }
        
        // No headings found at all - add H1 section at the top
        $h1_section = $this->create_h1_section($new_title);
        array_unshift($elements, $h1_section);
        
        return array(
            'found' => true,
            'created' => true,
            'converted' => false,
            'data' => $elements
        );
    }
    
    /**
     * Find and update existing H1 heading
     */
    private function find_and_update_h1(&$elements, $new_title) {
        $found = false;
        
        foreach ($elements as &$element) {
            if (isset($element['widgetType']) && $element['widgetType'] === 'heading') {
                $tag = isset($element['settings']['header_size']) ? $element['settings']['header_size'] : 'h2';
                if ($tag === 'h1') {
                    $element['settings']['title'] = $new_title;
                    $found = true;
                    break;
                }
            }
            
            if (!$found && isset($element['elements']) && is_array($element['elements'])) {
                $child_result = $this->find_and_update_h1($element['elements'], $new_title);
                if ($child_result['found']) {
                    $element['elements'] = $child_result['data'];
                    $found = true;
                    break;
                }
            }
        }
        
        return array('found' => $found, 'data' => $elements);
    }
    
    /**
     * Convert the first H2 or H3 heading to H1
     */
    private function convert_first_heading_to_h1(&$elements, $new_title) {
        $found = false;
        
        foreach ($elements as &$element) {
            if (isset($element['widgetType']) && $element['widgetType'] === 'heading') {
                $tag = isset($element['settings']['header_size']) ? $element['settings']['header_size'] : 'h2';
                if ($tag === 'h2' || $tag === 'h3') {
                    // Convert to H1 and update title
                    $element['settings']['header_size'] = 'h1';
                    $element['settings']['title'] = $new_title;
                    $found = true;
                    break;
                }
            }
            
            if (!$found && isset($element['elements']) && is_array($element['elements'])) {
                $child_result = $this->convert_first_heading_to_h1($element['elements'], $new_title);
                if ($child_result['found']) {
                    $element['elements'] = $child_result['data'];
                    $found = true;
                    break;
                }
            }
        }
        
        return array('found' => $found, 'data' => $elements);
    }
    
    /**
     * Create a new H1 section for Elementor
     */
    private function create_h1_section($title) {
        $section_id = $this->generate_elementor_id();
        $column_id = $this->generate_elementor_id();
        $widget_id = $this->generate_elementor_id();
        
        return array(
            'id' => $section_id,
            'elType' => 'section',
            'settings' => array(),
            'elements' => array(
                array(
                    'id' => $column_id,
                    'elType' => 'column',
                    'settings' => array('_column_size' => 100),
                    'elements' => array(
                        array(
                            'id' => $widget_id,
                            'elType' => 'widget',
                            'widgetType' => 'heading',
                            'settings' => array(
                                'title' => $title,
                                'header_size' => 'h1',
                                'align' => 'left'
                            ),
                            'elements' => array()
                        )
                    )
                )
            )
        );
    }
    
    /**
     * Generate a unique Elementor element ID (8 character hex)
     */
    private function generate_elementor_id() {
        return substr(md5(uniqid(mt_rand(), true)), 0, 8);
    }
}

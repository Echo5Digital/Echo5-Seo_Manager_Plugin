<?php
/**
 * API Handler - Registers and handles all REST API endpoints
 */

class Echo5_SEO_API_Handler {
    
    private $namespace = 'echo5-seo/v1';
    private $data_exporter;
    private $security;
    
    public function __construct($data_exporter, $security) {
        $this->data_exporter = $data_exporter;
        $this->security = $security;
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
        
        // Regenerate API key (admin only)
        register_rest_route($this->namespace, '/admin/regenerate-key', array(
            'methods' => 'POST',
            'callback' => array($this, 'regenerate_api_key'),
            'permission_callback' => array($this->security, 'verify_admin'),
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
}

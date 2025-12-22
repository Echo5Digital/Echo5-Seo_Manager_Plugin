<?php
/**
 * Data Exporter - Extracts and formats SEO data from WordPress
 */

class Echo5_SEO_Data_Exporter {
    
    /**
     * Get pages with full SEO data
     */
    public function get_pages($per_page = 20, $page = 1, $fields = 'all') {
        // Check cache first
        $cache_key = "echo5_pages_{$per_page}_{$page}_{$fields}";
        $cached = get_transient($cache_key);
        
        if ($cached && get_option('echo5_seo_enable_caching') === '1') {
            return $cached;
        }
        
        $offset = ($page - 1) * $per_page;
        
        $args = array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'offset' => $offset,
            'orderby' => 'menu_order',
            'order' => 'ASC',
        );
        
        $query = new WP_Query($args);
        $total = $query->found_posts;
        $items = array();
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $items[] = $this->format_page_data(get_post(), $fields);
            }
            wp_reset_postdata();
        }
        
        $result = array(
            'items' => $items,
            'total' => $total,
            'total_pages' => ceil($total / $per_page),
        );
        
        // Cache for 5 minutes
        set_transient($cache_key, $result, 300);
        
        return $result;
    }
    
    /**
     * Get single page with full SEO data
     */
    public function get_single_page($id) {
        $post = get_post($id);
        
        if (!$post || $post->post_type !== 'page') {
            return null;
        }
        
        return $this->format_page_data($post, 'all');
    }
    
    /**
     * Get posts with full SEO data
     */
    public function get_posts($per_page = 20, $page = 1) {
        $offset = ($page - 1) * $per_page;
        
        $args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'offset' => $offset,
            'orderby' => 'date',
            'order' => 'DESC',
        );
        
        $query = new WP_Query($args);
        $total = $query->found_posts;
        $items = array();
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $items[] = $this->format_page_data(get_post(), 'all');
            }
            wp_reset_postdata();
        }
        
        return array(
            'items' => $items,
            'total' => $total,
            'total_pages' => ceil($total / $per_page),
        );
    }
    
    /**
     * Get all content (pages + posts)
     */
    public function get_all_content($per_page = 50, $page = 1, $include_content = true) {
        $offset = ($page - 1) * $per_page;
        
        $args = array(
            'post_type' => array('page', 'post'),
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'offset' => $offset,
            'orderby' => 'modified',
            'order' => 'DESC',
        );
        
        $query = new WP_Query($args);
        $total = $query->found_posts;
        $items = array();
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $fields = $include_content ? 'all' : 'minimal';
                $items[] = $this->format_page_data(get_post(), $fields);
            }
            wp_reset_postdata();
        }
        
        return array(
            'items' => $items,
            'total' => $total,
            'total_pages' => ceil($total / $per_page),
        );
    }
    
    /**
     * Format page/post data with comprehensive SEO information
     */
    private function format_page_data($post, $fields = 'all') {
        $url = get_permalink($post->ID);
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'] ?? '/';
        $slug = ($path === '/' || $path === '') ? '__root__' : ltrim($path, '/');
        
        // Base data (always included)
        $data = array(
            'id' => $post->ID,
            'type' => $post->post_type,
            'url' => $url,
            'slug' => $slug,
            'path' => $path,
            'title' => get_the_title($post->ID),
            'status' => $post->post_status,
            'published_date' => $post->post_date,
            'modified_date' => $post->post_modified,
        );
        
        // Return minimal data if requested
        if ($fields === 'minimal') {
            return $data;
        }
        
        // Full content and SEO data
        $content = apply_filters('the_content', $post->post_content);
        $content_text = wp_strip_all_tags($content);
        
        // Extract page builder content (Elementor, Divi, WPBakery, Beaver Builder)
        $page_builder_text = $this->extract_page_builder_content($post->ID);
        
        // Combine post_content text with page builder text
        $combined_text = trim($content_text . ' ' . $page_builder_text);
        $word_count = str_word_count($combined_text);
        
        // Extract headings from content
        $headings = $this->extract_headings($content);
        
        // Extract content blocks (headings + paragraphs in document order)
        // Use Elementor JSON data if available (preserves structure), otherwise parse HTML
        $content_blocks = $this->extract_content_blocks_smart($post->ID, $content);
        
        // Extract images from post_content
        $images = $this->extract_images($content, $post->ID);
        
        // Extract images from page builders (Elementor, Divi, Beaver Builder)
        $page_builder_images = $this->extract_page_builder_images($post->ID);
        $images = array_merge($images, $page_builder_images);
        
        // Extract links
        $links = $this->extract_links($content, $url);
        
        // Get SEO plugin data (Yoast, RankMath, etc.)
        $seo_data = $this->get_post_seo_data($post->ID);
        
        // Get featured image
        $featured_image = get_the_post_thumbnail_url($post->ID, 'full');
        
        // Get excerpt
        $excerpt = has_excerpt($post->ID) ? get_the_excerpt($post->ID) : wp_trim_words($content_text, 30);
        
        // Categories and tags (for posts)
        $categories = array();
        $tags = array();
        if ($post->post_type === 'post') {
            $post_categories = get_the_category($post->ID);
            foreach ($post_categories as $cat) {
                $categories[] = array(
                    'id' => $cat->term_id,
                    'name' => $cat->name,
                    'slug' => $cat->slug,
                );
            }
            
            $post_tags = get_the_tags($post->ID);
            if ($post_tags) {
                foreach ($post_tags as $tag) {
                    $tags[] = array(
                        'id' => $tag->term_id,
                        'name' => $tag->name,
                        'slug' => $tag->slug,
                    );
                }
            }
        }
        
        // Build comprehensive data
        $data = array_merge($data, array(
            'content' => array(
                'html' => $content,
                'text' => $content_text,
                'excerpt' => $excerpt,
                'word_count' => $word_count,
                'reading_time' => ceil($word_count / 200),
                'has_blocks' => has_blocks($post->post_content),
            ),
            'seo' => array(
                'meta_title' => $seo_data['title'] ?: get_the_title($post->ID),
                'meta_description' => $seo_data['description'] ?: $excerpt,
                'focus_keyword' => $seo_data['focus_keyword'],
                'canonical_url' => $seo_data['canonical'] ?: $url,
                'robots' => $seo_data['robots'],
                'og_title' => $seo_data['og_title'],
                'og_description' => $seo_data['og_description'],
                'og_image' => $seo_data['og_image'] ?: $featured_image,
                'twitter_title' => $seo_data['twitter_title'],
                'twitter_description' => $seo_data['twitter_description'],
                'twitter_image' => $seo_data['twitter_image'] ?: $featured_image,
                'schema' => $seo_data['schema'],
            ),
            'headings' => $headings,
            'content_blocks' => $content_blocks,
            'images' => $images,
            'links' => $links,
            'featured_image' => $featured_image,
            'author' => array(
                'id' => $post->post_author,
                'name' => get_the_author_meta('display_name', $post->post_author),
                'email' => get_the_author_meta('email', $post->post_author),
            ),
            'categories' => $categories,
            'tags' => $tags,
            'template' => get_page_template_slug($post->ID),
        ));
        
        return $data;
    }
    
    /**
     * Smart content block extraction - uses rendered Elementor content or falls back to HTML parsing
     */
    private function extract_content_blocks_smart($post_id, $html_content) {
        // Try to get Elementor's rendered HTML - this gives us proper document order
        if (class_exists('\Elementor\Plugin') && \Elementor\Plugin::$instance->documents->get($post_id)) {
            $elementor_html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($post_id);
            if (!empty($elementor_html)) {
                $blocks = $this->extract_content_blocks($elementor_html);
                if (!empty($blocks)) {
                    return $blocks;
                }
            }
        }
        
        // Fallback to the_content HTML parsing
        return $this->extract_content_blocks($html_content);
    }
    
    /**
     * Extract content blocks from Elementor JSON in document order (legacy method - kept for reference)
     */
    private function extract_elementor_content_blocks($elements, &$blocks = array(), &$seen = array()) {
        if (!is_array($elements)) {
            return $blocks;
        }
        
        foreach ($elements as $element) {
            if (count($blocks) >= 100) break;
            
            // Check widget type for headings
            $widget_type = isset($element['widgetType']) ? $element['widgetType'] : '';
            $settings = isset($element['settings']) ? $element['settings'] : array();
            
            // Handle heading widgets
            if ($widget_type === 'heading') {
                $text = isset($settings['title']) ? wp_strip_all_tags($settings['title']) : '';
                $tag = isset($settings['header_size']) ? strtolower($settings['header_size']) : 'h2';
                if (!empty($text) && strlen($text) >= 10) {
                    $normalized = $this->normalize_text($text);
                    if (!isset($seen[$normalized])) {
                        $blocks[] = array('tag' => $tag, 'text' => trim($text));
                        $seen[$normalized] = true;
                    }
                }
            }
            
            // Handle text-editor widgets (paragraphs)
            if ($widget_type === 'text-editor') {
                $editor_content = isset($settings['editor']) ? $settings['editor'] : '';
                if (!empty($editor_content)) {
                    // Parse the HTML content to extract paragraphs and headings
                    $this->parse_editor_content($editor_content, $blocks, $seen);
                }
            }
            
            // Handle icon-box and image-box widgets specially (title as heading, description as paragraph)
            if (in_array($widget_type, array('icon-box', 'image-box'))) {
                // First add the title as a heading
                if (isset($settings['title_text']) && !empty($settings['title_text'])) {
                    $title = wp_strip_all_tags($settings['title_text']);
                    if (strlen($title) >= 5) {
                        $normalized = $this->normalize_text($title);
                        if (!isset($seen[$normalized])) {
                            // Use h3 for icon-box titles (they're usually sub-headings)
                            $blocks[] = array('tag' => 'h3', 'text' => trim($title));
                            $seen[$normalized] = true;
                        }
                    }
                }
                // Then add the description as a paragraph
                if (isset($settings['description_text']) && !empty($settings['description_text'])) {
                    $desc = wp_strip_all_tags($settings['description_text']);
                    if (strlen($desc) >= 20) {
                        $normalized = $this->normalize_text($desc);
                        if (!isset($seen[$normalized])) {
                            $blocks[] = array('tag' => 'p', 'text' => trim($desc));
                            $seen[$normalized] = true;
                        }
                    }
                }
            }
            
            // Handle call-to-action widgets (title as heading, description as paragraph)
            if ($widget_type === 'call-to-action') {
                if (isset($settings['title']) && !empty($settings['title'])) {
                    $title = wp_strip_all_tags($settings['title']);
                    if (strlen($title) >= 5) {
                        $normalized = $this->normalize_text($title);
                        if (!isset($seen[$normalized])) {
                            $blocks[] = array('tag' => 'h3', 'text' => trim($title));
                            $seen[$normalized] = true;
                        }
                    }
                }
                if (isset($settings['description']) && !empty($settings['description'])) {
                    $desc = wp_strip_all_tags($settings['description']);
                    if (strlen($desc) >= 20) {
                        $normalized = $this->normalize_text($desc);
                        if (!isset($seen[$normalized])) {
                            $blocks[] = array('tag' => 'p', 'text' => trim($desc));
                            $seen[$normalized] = true;
                        }
                    }
                }
            }
            
            // Handle other simple text widgets (all as paragraphs)
            $simple_text_widgets = array(
                'text' => array('text', 'description'),
                'testimonial' => array('testimonial_content'),
                'blockquote' => array('blockquote_content'),
            );
            
            if (isset($simple_text_widgets[$widget_type])) {
                foreach ($simple_text_widgets[$widget_type] as $field) {
                    if (isset($settings[$field]) && !empty($settings[$field])) {
                        $text = wp_strip_all_tags($settings[$field]);
                        if (strlen($text) >= 20) {
                            $normalized = $this->normalize_text($text);
                            if (!isset($seen[$normalized])) {
                                $blocks[] = array('tag' => 'p', 'text' => trim($text));
                                $seen[$normalized] = true;
                            }
                        }
                    }
                }
            }
            
            // Recursively process nested elements (sections, columns, etc.)
            if (isset($element['elements']) && is_array($element['elements'])) {
                $this->extract_elementor_content_blocks($element['elements'], $blocks, $seen);
            }
        }
        
        return $blocks;
    }
    
    /**
     * Parse editor content (rich text) to extract blocks
     */
    private function parse_editor_content($html, &$blocks, &$seen) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6 | //p');
        
        foreach ($nodes as $node) {
            if (count($blocks) >= 100) break;
            
            $tag_name = strtolower($node->nodeName);
            $text = $this->get_node_text($node);
            
            $min_length = ($tag_name === 'p') ? 20 : 10;
            if (empty($text) || strlen($text) < $min_length) continue;
            
            $normalized = $this->normalize_text($text);
            if (isset($seen[$normalized])) continue;
            
            $blocks[] = array('tag' => $tag_name, 'text' => $text);
            $seen[$normalized] = true;
        }
    }

    /**
     * Extract content blocks (headings + paragraphs) in document order from HTML
     */
    private function extract_content_blocks($content) {
        $blocks = array();
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $content);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Use XPath to find ALL headings and paragraphs in document order
        // This handles deeply nested Elementor/page builder content
        $nodes = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6 | //p');
        
        $seen_texts = array();
        foreach ($nodes as $node) {
            if (count($blocks) >= 100) break;
            
            $tag_name = strtolower($node->nodeName);
            $text = $this->get_node_text($node);
            
            // Skip empty or very short content
            $min_length = ($tag_name === 'p') ? 20 : 10;
            if (empty($text) || strlen($text) < $min_length) continue;
            
            // Skip duplicate content
            $normalized = $this->normalize_text($text);
            if (isset($seen_texts[$normalized])) continue;
            
            $blocks[] = array('tag' => $tag_name, 'text' => $text);
            $seen_texts[$normalized] = true;
        }
        
        return $blocks;
    }
    
    private function get_node_text($node) {
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) $text .= $child->nodeValue;
            elseif ($child->nodeType === XML_ELEMENT_NODE) $text .= $this->get_node_text($child);
        }
        return preg_replace('/\s+/', ' ', trim($text));
    }
    
    private function normalize_text($text) {
        return strtolower(preg_replace('/[^a-z0-9]+/', '', $text));
    }
    
    private function is_node_container_only($node) {
        $direct_text_length = 0;
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) $direct_text_length += strlen(trim($child->nodeValue));
        }
        return $direct_text_length < 10;
    }

    /**
     * Extract headings from content
     */
    private function extract_headings($content) {
        $headings = array(
            'h1' => array(),
            'h2' => array(),
            'h3' => array(),
            'h4' => array(),
            'h5' => array(),
            'h6' => array(),
        );
        
        for ($i = 1; $i <= 6; $i++) {
            preg_match_all("/<h{$i}[^>]*>(.*?)<\/h{$i}>/is", $content, $matches);
            if (!empty($matches[1])) {
                $headings["h{$i}"] = array_map('wp_strip_all_tags', $matches[1]);
            }
        }
        
        return $headings;
    }
    
    /**
     * Extract images from content
     */
    private function extract_images($content, $post_id) {
        $images = array();
        
        preg_match_all('/<img[^>]+>/i', $content, $img_tags);
        
        foreach ($img_tags[0] as $img_tag) {
            preg_match('/src="([^"]+)"/i', $img_tag, $src);
            preg_match('/alt="([^"]*)"/i', $img_tag, $alt);
            preg_match('/width="([^"]+)"/i', $img_tag, $width);
            preg_match('/height="([^"]+)"/i', $img_tag, $height);
            preg_match('/loading="([^"]+)"/i', $img_tag, $loading);
            
            $images[] = array(
                'src' => $src[1] ?? '',
                'alt' => $alt[1] ?? '',
                'width' => $width[1] ?? '',
                'height' => $height[1] ?? '',
                'loading' => $loading[1] ?? '',
                'has_alt' => !empty($alt[1]),
                'has_lazy_loading' => isset($loading[1]) && $loading[1] === 'lazy',
            );
        }
        
        return $images;
    }
    
    /**
     * Extract internal and external links
     */
    private function extract_links($content, $base_url) {
        $internal = array();
        $external = array();
        
        preg_match_all('/<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER);
        
        $site_url = get_site_url();
        $site_host = parse_url($site_url, PHP_URL_HOST);
        
        foreach ($matches as $match) {
            $href = $match[1];
            $text = wp_strip_all_tags($match[2]);
            
            // Skip anchors and javascript
            if (strpos($href, '#') === 0 || strpos($href, 'javascript:') === 0) {
                continue;
            }
            
            $link_host = parse_url($href, PHP_URL_HOST);
            
            $link_data = array(
                'url' => $href,
                'text' => $text,
            );
            
            if (!$link_host || $link_host === $site_host) {
                $internal[] = $link_data;
            } else {
                $external[] = $link_data;
            }
        }
        
        return array(
            'internal' => $internal,
            'external' => $external,
            'internal_count' => count($internal),
            'external_count' => count($external),
        );
    }
    
    /**
     * Get SEO data from popular SEO plugins (Yoast, RankMath, etc.)
     */
    private function get_post_seo_data($post_id) {
        $seo_data = array(
            'title' => '',
            'description' => '',
            'focus_keyword' => '',
            'canonical' => '',
            'robots' => 'index, follow',
            'og_title' => '',
            'og_description' => '',
            'og_image' => '',
            'twitter_title' => '',
            'twitter_description' => '',
            'twitter_image' => '',
            'schema' => array(),
        );
        
        // Yoast SEO - Use Yoast's helper to get the rendered title (with site name)
        if (defined('WPSEO_VERSION')) {
            // Get rendered title using Yoast's replace_vars function
            $raw_title = get_post_meta($post_id, '_yoast_wpseo_title', true);
            
            // If empty or contains template vars, use Yoast's title generation
            if (empty($raw_title) || strpos($raw_title, '%%') !== false) {
                // Use Yoast's internal title generation if available
                if (class_exists('WPSEO_Replace_Vars')) {
                    $replace_vars = new WPSEO_Replace_Vars();
                    $post = get_post($post_id);
                    
                    // Get default title template if custom is empty
                    if (empty($raw_title)) {
                        $post_type = get_post_type($post_id);
                        $raw_title = WPSEO_Options::get('title-' . $post_type, '%%title%% %%sep%% %%sitename%%');
                    }
                    
                    // Replace variables to get actual title
                    $seo_data['title'] = $replace_vars->replace($raw_title, $post);
                } else {
                    // Fallback: Post title + separator + site name
                    $seo_data['title'] = get_the_title($post_id) . ' - ' . get_bloginfo('name');
                }
            } else {
                $seo_data['title'] = $raw_title;
            }
            
            // Get description with Yoast's variable replacement
            $raw_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
            if (!empty($raw_desc) && strpos($raw_desc, '%%') !== false && class_exists('WPSEO_Replace_Vars')) {
                $replace_vars = new WPSEO_Replace_Vars();
                $post = get_post($post_id);
                $seo_data['description'] = $replace_vars->replace($raw_desc, $post);
            } else {
                $seo_data['description'] = $raw_desc;
            }
            
            $seo_data['focus_keyword'] = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
            $seo_data['canonical'] = get_post_meta($post_id, '_yoast_wpseo_canonical', true);
            $seo_data['og_title'] = get_post_meta($post_id, '_yoast_wpseo_opengraph-title', true);
            $seo_data['og_description'] = get_post_meta($post_id, '_yoast_wpseo_opengraph-description', true);
            $seo_data['og_image'] = get_post_meta($post_id, '_yoast_wpseo_opengraph-image', true);
            $seo_data['twitter_title'] = get_post_meta($post_id, '_yoast_wpseo_twitter-title', true);
            $seo_data['twitter_description'] = get_post_meta($post_id, '_yoast_wpseo_twitter-description', true);
            $seo_data['twitter_image'] = get_post_meta($post_id, '_yoast_wpseo_twitter-image', true);
        }
        
        // RankMath - Handle templates with variables
        if (defined('RANK_MATH_VERSION')) {
            $raw_title = get_post_meta($post_id, 'rank_math_title', true);
            
            // If empty or contains template vars
            if (empty($raw_title) || strpos($raw_title, '%') !== false) {
                // Fallback: Post title + site name
                $seo_data['title'] = get_the_title($post_id) . ' - ' . get_bloginfo('name');
            } else {
                $seo_data['title'] = $raw_title;
            }
            
            $seo_data['description'] = get_post_meta($post_id, 'rank_math_description', true);
            $seo_data['focus_keyword'] = get_post_meta($post_id, 'rank_math_focus_keyword', true);
            $seo_data['canonical'] = get_post_meta($post_id, 'rank_math_canonical_url', true);
            $seo_data['robots'] = get_post_meta($post_id, 'rank_math_robots', true);
        }
        
        // All in One SEO - Handle templates
        if (defined('AIOSEO_VERSION')) {
            $raw_title = get_post_meta($post_id, '_aioseo_title', true);
            
            if (empty($raw_title) || strpos($raw_title, '#') !== false) {
                // AIOSEO uses # placeholders, fallback to post title + site name
                $seo_data['title'] = get_the_title($post_id) . ' - ' . get_bloginfo('name');
            } else {
                $seo_data['title'] = $raw_title;
            }
            
            $seo_data['description'] = get_post_meta($post_id, '_aioseo_description', true);
            $seo_data['canonical'] = get_post_meta($post_id, '_aioseo_canonical_url', true);
        }
        
        // Final fallback: If no SEO plugin set a title, use post title + site name
        if (empty($seo_data['title'])) {
            $seo_data['title'] = get_the_title($post_id) . ' - ' . get_bloginfo('name');
        }
        
        return $seo_data;
    }
    
    /**
     * Get site structure (menu hierarchy)
     */
    public function get_site_structure() {
        $menus = wp_get_nav_menus();
        $structure = array();
        
        foreach ($menus as $menu) {
            $menu_items = wp_get_nav_menu_items($menu->term_id);
            $structure[$menu->name] = array();
            
            if ($menu_items) {
                foreach ($menu_items as $item) {
                    $structure[$menu->name][] = array(
                        'id' => $item->ID,
                        'title' => $item->title,
                        'url' => $item->url,
                        'parent_id' => $item->menu_item_parent,
                        'order' => $item->menu_order,
                    );
                }
            }
        }
        
        return $structure;
    }
    
    /**
     * Get internal links map
     */
    public function get_internal_links_map() {
        // This would build a graph of internal links
        // Simplified version for now
        $pages = $this->get_all_content(100, 1, false);
        
        return array(
            'total_pages' => count($pages['items']),
            'note' => 'Full internal linking graph requires additional processing',
        );
    }
    
    /**
     * Get SEO plugin information
     */
    public function get_seo_plugin_info() {
        $plugins = array(
            'yoast' => defined('WPSEO_VERSION') ? WPSEO_VERSION : false,
            'rankmath' => defined('RANK_MATH_VERSION') ? RANK_MATH_VERSION : false,
            'aioseo' => defined('AIOSEO_VERSION') ? AIOSEO_VERSION : false,
        );
        
        return array(
            'active_plugins' => array_filter($plugins),
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
        );
    }
    
    /**
     * Extract text content from page builders (Elementor, Divi, WPBakery, Beaver Builder)
     * 
     * @param int $post_id The post ID
     * @return string Combined text from all page builder widgets
     */
    private function extract_page_builder_content($post_id) {
        $text_parts = array();
        
        // =====================
        // ELEMENTOR
        // =====================
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        if (!empty($elementor_data)) {
            $elementor_json = is_string($elementor_data) ? json_decode($elementor_data, true) : $elementor_data;
            if (is_array($elementor_json)) {
                $text_parts[] = $this->extract_elementor_text($elementor_json);
            }
        }
        
        // =====================
        // DIVI BUILDER
        // =====================
        // Divi stores content in post_content with shortcodes, which are already processed
        // But also check for Divi-specific meta
        $divi_content = get_post_meta($post_id, '_et_pb_post_content', true);
        if (!empty($divi_content)) {
            $text_parts[] = wp_strip_all_tags(do_shortcode($divi_content));
        }
        
        // =====================
        // BEAVER BUILDER
        // =====================
        $beaver_data = get_post_meta($post_id, '_fl_builder_data', true);
        if (!empty($beaver_data) && is_array($beaver_data)) {
            $text_parts[] = $this->extract_beaver_text($beaver_data);
        }
        
        // =====================
        // WPBAKERY (Visual Composer)
        // =====================
        // WPBakery uses shortcodes in post_content, already processed by the_content filter
        // Check for custom elements stored in meta
        $vc_custom = get_post_meta($post_id, '_wpb_shortcodes_custom_css', true);
        if (!empty($vc_custom)) {
            // WPBakery content is in post_content, no additional extraction needed
        }
        
        return implode(' ', array_filter($text_parts));
    }
    
    /**
     * Recursively extract text from Elementor JSON data
     * 
     * @param array $elements Elementor elements array
     * @return string Extracted text
     */
    private function extract_elementor_text($elements) {
        $texts = array();
        
        if (!is_array($elements)) {
            return '';
        }
        
        foreach ($elements as $element) {
            // Extract text from widget settings
            if (isset($element['settings'])) {
                $settings = $element['settings'];
                
                // Common text fields in Elementor widgets
                $text_fields = array(
                    'title', 'title_text', 'heading', 'text', 'description',
                    'description_text', 'editor', 'content', 'tab_content',
                    'testimonial_content', 'quote', 'alert_description',
                    'inner_text', 'prefix', 'suffix', 'button_text',
                    'before_text', 'after_text', 'highlighted_text',
                    'rotating_text', 'fallback', 'item_description',
                    'accordion_tab_content', 'tab_title', 'tab_icon',
                    'list_items', 'items', 'price', 'period',
                    'features_list', 'feature_text',
                );
                
                foreach ($text_fields as $field) {
                    if (isset($settings[$field])) {
                        $value = $settings[$field];
                        if (is_string($value) && !empty($value)) {
                            $texts[] = wp_strip_all_tags($value);
                        } elseif (is_array($value)) {
                            // Handle repeater fields (like list items)
                            foreach ($value as $item) {
                                if (is_array($item)) {
                                    foreach ($item as $item_value) {
                                        if (is_string($item_value) && !empty($item_value)) {
                                            $texts[] = wp_strip_all_tags($item_value);
                                        }
                                    }
                                } elseif (is_string($item) && !empty($item)) {
                                    $texts[] = wp_strip_all_tags($item);
                                }
                            }
                        }
                    }
                }
            }
            
            // Recursively process nested elements
            if (isset($element['elements']) && is_array($element['elements'])) {
                $texts[] = $this->extract_elementor_text($element['elements']);
            }
        }
        
        return implode(' ', array_filter($texts));
    }
    
    /**
     * Extract text from Beaver Builder data
     * 
     * @param array $data Beaver Builder data array
     * @return string Extracted text
     */
    private function extract_beaver_text($data) {
        $texts = array();
        
        if (!is_array($data)) {
            return '';
        }
        
        foreach ($data as $node) {
            if (isset($node->settings)) {
                $settings = (array) $node->settings;
                
                // Common Beaver Builder text fields
                $text_fields = array(
                    'text', 'heading', 'content', 'description',
                    'title', 'subtitle', 'btn_text', 'link_text',
                );
                
                foreach ($text_fields as $field) {
                    if (isset($settings[$field]) && is_string($settings[$field])) {
                        $texts[] = wp_strip_all_tags($settings[$field]);
                    }
                }
            }
        }
        
        return implode(' ', array_filter($texts));
    }
    
    /**
     * Extract images from page builders (Elementor, Divi, Beaver Builder)
     * 
     * @param int $post_id The post ID
     * @return array Array of image data
     */
    private function extract_page_builder_images($post_id) {
        $images = array();
        
        // =====================
        // ELEMENTOR IMAGES
        // =====================
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        if (!empty($elementor_data)) {
            $elementor_json = is_string($elementor_data) ? json_decode($elementor_data, true) : $elementor_data;
            if (is_array($elementor_json)) {
                $images = array_merge($images, $this->extract_elementor_images($elementor_json));
            }
        }
        
        // =====================
        // DIVI IMAGES
        // =====================
        $divi_content = get_post_meta($post_id, '_et_pb_post_content', true);
        if (!empty($divi_content)) {
            // Parse Divi content for images
            $divi_images = $this->extract_images_from_html(do_shortcode($divi_content));
            $images = array_merge($images, $divi_images);
        }
        
        // =====================
        // BEAVER BUILDER IMAGES
        // =====================
        $beaver_data = get_post_meta($post_id, '_fl_builder_data', true);
        if (!empty($beaver_data) && is_array($beaver_data)) {
            $images = array_merge($images, $this->extract_beaver_images($beaver_data));
        }
        
        return $images;
    }
    
    /**
     * Recursively extract images from Elementor JSON data
     * 
     * @param array $elements Elementor elements array
     * @return array Array of image data
     */
    private function extract_elementor_images($elements) {
        $images = array();
        
        if (!is_array($elements)) {
            return $images;
        }
        
        foreach ($elements as $element) {
            if (isset($element['settings'])) {
                $settings = $element['settings'];
                
                // Image widget fields
                $image_fields = array(
                    'image', 'background_image', 'image_custom_dimension',
                    'wp_gallery', 'gallery', 'slides', 'carousel',
                    'testimonial_image', 'person_image', 'team_member_image',
                    'icon_image', 'graphic_image', 'image_overlay',
                );
                
                foreach ($image_fields as $field) {
                    if (isset($settings[$field])) {
                        $value = $settings[$field];
                        
                        // Handle single image object (most common)
                        if (is_array($value) && isset($value['url'])) {
                            $image_data = $this->build_image_data_from_elementor($value);
                            if ($image_data) {
                                $images[] = $image_data;
                            }
                        }
                        // Handle gallery/repeater of images
                        elseif (is_array($value)) {
                            foreach ($value as $item) {
                                if (is_array($item)) {
                                    // Check for nested image in repeater
                                    if (isset($item['url'])) {
                                        $image_data = $this->build_image_data_from_elementor($item);
                                        if ($image_data) {
                                            $images[] = $image_data;
                                        }
                                    }
                                    // Check for image field within repeater item
                                    foreach ($item as $sub_value) {
                                        if (is_array($sub_value) && isset($sub_value['url'])) {
                                            $image_data = $this->build_image_data_from_elementor($sub_value);
                                            if ($image_data) {
                                                $images[] = $image_data;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Also check for background images in style settings
                if (isset($settings['_background_image']['url'])) {
                    $image_data = $this->build_image_data_from_elementor($settings['_background_image']);
                    if ($image_data) {
                        $images[] = $image_data;
                    }
                }
            }
            
            // Recursively process nested elements
            if (isset($element['elements']) && is_array($element['elements'])) {
                $images = array_merge($images, $this->extract_elementor_images($element['elements']));
            }
        }
        
        return $images;
    }
    
    /**
     * Build standardized image data from Elementor image object
     * 
     * @param array $image_obj Elementor image object with url, id, alt, etc.
     * @return array|null Standardized image data or null
     */
    private function build_image_data_from_elementor($image_obj) {
        if (empty($image_obj['url'])) {
            return null;
        }
        
        $image_id = isset($image_obj['id']) ? $image_obj['id'] : 0;
        $alt = '';
        $width = '';
        $height = '';
        
        // Try to get alt text from attachment
        if ($image_id) {
            $alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
            $metadata = wp_get_attachment_metadata($image_id);
            if ($metadata) {
                $width = isset($metadata['width']) ? $metadata['width'] : '';
                $height = isset($metadata['height']) ? $metadata['height'] : '';
            }
        }
        
        // Fallback to alt from image object if provided
        if (empty($alt) && isset($image_obj['alt'])) {
            $alt = $image_obj['alt'];
        }
        
        return array(
            'src' => $image_obj['url'],
            'alt' => $alt,
            'width' => $width,
            'height' => $height,
            'loading' => '',
            'has_alt' => !empty($alt),
            'has_lazy_loading' => false,
            'source' => 'elementor',
        );
    }
    
    /**
     * Extract images from HTML string
     * 
     * @param string $html HTML content
     * @return array Array of image data
     */
    private function extract_images_from_html($html) {
        $images = array();
        
        preg_match_all('/<img[^>]+>/i', $html, $img_tags);
        
        foreach ($img_tags[0] as $img_tag) {
            preg_match('/src="([^"]+)"/i', $img_tag, $src);
            preg_match('/alt="([^"]*)"/i', $img_tag, $alt);
            preg_match('/width="([^"]+)"/i', $img_tag, $width);
            preg_match('/height="([^"]+)"/i', $img_tag, $height);
            preg_match('/loading="([^"]+)"/i', $img_tag, $loading);
            
            $images[] = array(
                'src' => $src[1] ?? '',
                'alt' => $alt[1] ?? '',
                'width' => $width[1] ?? '',
                'height' => $height[1] ?? '',
                'loading' => $loading[1] ?? '',
                'has_alt' => !empty($alt[1]),
                'has_lazy_loading' => isset($loading[1]) && $loading[1] === 'lazy',
                'source' => 'divi',
            );
        }
        
        return $images;
    }
    
    /**
     * Extract images from Beaver Builder data
     * 
     * @param array $data Beaver Builder data array
     * @return array Array of image data
     */
    private function extract_beaver_images($data) {
        $images = array();
        
        if (!is_array($data)) {
            return $images;
        }
        
        foreach ($data as $node) {
            if (isset($node->settings)) {
                $settings = (array) $node->settings;
                
                // Common Beaver Builder image fields
                $image_fields = array(
                    'photo', 'bg_image', 'photo_source', 'image',
                    'poster', 'ss_photo',
                );
                
                foreach ($image_fields as $field) {
                    if (isset($settings[$field])) {
                        $value = $settings[$field];
                        
                        // If it's a numeric ID, get attachment data
                        if (is_numeric($value)) {
                            $image_url = wp_get_attachment_url($value);
                            if ($image_url) {
                                $alt = get_post_meta($value, '_wp_attachment_image_alt', true);
                                $metadata = wp_get_attachment_metadata($value);
                                
                                $images[] = array(
                                    'src' => $image_url,
                                    'alt' => $alt ?: '',
                                    'width' => isset($metadata['width']) ? $metadata['width'] : '',
                                    'height' => isset($metadata['height']) ? $metadata['height'] : '',
                                    'loading' => '',
                                    'has_alt' => !empty($alt),
                                    'has_lazy_loading' => false,
                                    'source' => 'beaver_builder',
                                );
                            }
                        }
                        // If it's a URL string
                        elseif (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                            $images[] = array(
                                'src' => $value,
                                'alt' => '',
                                'width' => '',
                                'height' => '',
                                'loading' => '',
                                'has_alt' => false,
                                'has_lazy_loading' => false,
                                'source' => 'beaver_builder',
                            );
                        }
                    }
                }
            }
        }
        
        return $images;
    }
}

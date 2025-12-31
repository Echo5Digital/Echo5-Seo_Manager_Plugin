<?php
/**
 * Brand Extractor - Extracts brand tokens (colors, typography) from Elementor sites
 * 
 * Uses hybrid approach:
 * 1. First tries to get global colors/fonts from Elementor Kit settings
 * 2. Supplements by parsing pages to find most-used colors/fonts
 * 
 * @since 1.3.0
 */

class Echo5_Brand_Extractor {
    
    /**
     * Color properties to look for in Elementor widgets
     */
    private $color_properties = [
        'title_color', 'text_color', 'color', 'background_color', 
        'button_background_color', 'button_text_color', 'link_color',
        'icon_color', 'border_color', 'background', 'heading_color',
        'primary_color', 'secondary_color', 'hover_color', 'active_color',
        'tabs_title_color', 'tabs_content_color', 'icon_primary_color',
        'icon_secondary_color', 'testimonial_text_color', 'quote_color',
        '__globals__' // Global color references
    ];
    
    /**
     * Typography properties to look for
     */
    private $typography_properties = [
        'typography_font_family', 'font_family', 'title_typography',
        'text_typography', 'description_typography', 'heading_typography',
        'typography', '__globals__' // Global typography references
    ];
    
    /**
     * Extract all brand tokens from the site
     * 
     * @param int $page_limit Number of pages to analyze
     * @return array Brand tokens data
     */
    public function extract_brand_tokens($page_limit = 10) {
        $result = [
            'colors' => [],
            'typography' => [],
            'globalSettings' => [],
            'pagesAnalyzed' => 0,
            'extractedAt' => current_time('mysql'),
            'source' => 'elementor'
        ];
        
        // Step 1: Get Elementor global/kit settings
        $global_settings = $this->get_elementor_global_settings();
        $result['globalSettings'] = $global_settings;
        
        // Add global colors with 'global' source
        if (!empty($global_settings['colors'])) {
            foreach ($global_settings['colors'] as $id => $color) {
                $result['colors'][$id] = [
                    'value' => $color['color'] ?? $color,
                    'source' => 'global',
                    'name' => $color['title'] ?? ucfirst($id),
                    'frequency' => 999 // High frequency so they rank first
                ];
            }
        }
        
        // Add global typography
        if (!empty($global_settings['typography'])) {
            foreach ($global_settings['typography'] as $id => $typo) {
                $result['typography'][$id] = [
                    'family' => $typo['font_family'] ?? null,
                    'source' => 'global',
                    'name' => $typo['title'] ?? ucfirst($id),
                    'weights' => $this->extract_weights($typo),
                    'sizes' => $typo['sizes'] ?? []
                ];
            }
        }
        
        // Step 2: Scan pages for additional colors/typography
        $page_analysis = $this->analyze_pages($page_limit);
        $result['pagesAnalyzed'] = $page_analysis['pagesAnalyzed'];
        
        // Merge extracted colors (lower priority than globals)
        foreach ($page_analysis['colors'] as $hex => $data) {
            $normalized_hex = $this->normalize_hex($hex);
            if (!$normalized_hex) continue;
            
            // Check if similar color already exists from globals
            $is_duplicate = false;
            foreach ($result['colors'] as $existing_hex => $existing_data) {
                if ($this->colors_are_similar($normalized_hex, $existing_data['value'] ?? $existing_hex)) {
                    $is_duplicate = true;
                    break;
                }
            }
            
            if (!$is_duplicate) {
                $result['colors'][$normalized_hex] = [
                    'value' => $normalized_hex,
                    'source' => 'extracted',
                    'name' => $this->suggest_color_name($normalized_hex, $data['contexts']),
                    'frequency' => $data['count'],
                    'contexts' => array_slice($data['contexts'], 0, 5) // Top 5 contexts
                ];
            }
        }
        
        // Merge extracted typography
        foreach ($page_analysis['typography'] as $family => $data) {
            $normalized_family = trim($family);
            if (!$normalized_family || strtolower($normalized_family) === 'inherit') continue;
            
            // Check if already exists from globals
            $exists = false;
            foreach ($result['typography'] as $existing) {
                if (strtolower($existing['family'] ?? '') === strtolower($normalized_family)) {
                    $exists = true;
                    break;
                }
            }
            
            if (!$exists) {
                $result['typography'][$normalized_family] = [
                    'family' => $normalized_family,
                    'source' => 'extracted',
                    'name' => 'Font: ' . $normalized_family,
                    'weights' => $data['weights'] ?? [400],
                    'frequency' => $data['count']
                ];
            }
        }
        
        // Step 3: Process and categorize
        $result['colors'] = $this->categorize_colors($result['colors']);
        $result['typography'] = $this->categorize_typography($result['typography']);
        
        // Step 4: Add spacing tokens from analysis
        $result['spacing'] = $page_analysis['spacing'] ?? [];
        
        // Step 5: Add version info
        $result['version'] = 1;
        $result['status'] = 'draft';
        
        return $result;
    }
    
    /**
     * Get Elementor global/kit settings
     */
    private function get_elementor_global_settings() {
        $settings = [
            'colors' => [],
            'typography' => [],
            'buttons' => [],
            'kit_id' => null
        ];
        
        // Get active Elementor kit
        $kit_id = get_option('elementor_active_kit');
        if (!$kit_id) {
            return $settings;
        }
        
        $settings['kit_id'] = $kit_id;
        
        // Get kit settings from post meta
        $kit_settings = get_post_meta($kit_id, '_elementor_page_settings', true);
        if (empty($kit_settings)) {
            return $settings;
        }
        
        // Extract system colors
        if (!empty($kit_settings['system_colors'])) {
            foreach ($kit_settings['system_colors'] as $color) {
                if (!empty($color['_id']) && !empty($color['color'])) {
                    $settings['colors'][$color['_id']] = [
                        'color' => $color['color'],
                        'title' => $color['title'] ?? ucfirst($color['_id'])
                    ];
                }
            }
        }
        
        // Extract custom colors
        if (!empty($kit_settings['custom_colors'])) {
            foreach ($kit_settings['custom_colors'] as $color) {
                if (!empty($color['_id']) && !empty($color['color'])) {
                    $settings['colors']['custom_' . $color['_id']] = [
                        'color' => $color['color'],
                        'title' => $color['title'] ?? 'Custom'
                    ];
                }
            }
        }
        
        // Extract system typography
        if (!empty($kit_settings['system_typography'])) {
            foreach ($kit_settings['system_typography'] as $typo) {
                if (!empty($typo['_id'])) {
                    $settings['typography'][$typo['_id']] = [
                        'font_family' => $typo['typography_font_family'] ?? null,
                        'font_weight' => $typo['typography_font_weight'] ?? null,
                        'font_size' => $typo['typography_font_size'] ?? null,
                        'line_height' => $typo['typography_line_height'] ?? null,
                        'title' => $typo['title'] ?? ucfirst($typo['_id'])
                    ];
                }
            }
        }
        
        // Extract custom typography
        if (!empty($kit_settings['custom_typography'])) {
            foreach ($kit_settings['custom_typography'] as $typo) {
                if (!empty($typo['_id'])) {
                    $settings['typography']['custom_' . $typo['_id']] = [
                        'font_family' => $typo['typography_font_family'] ?? null,
                        'font_weight' => $typo['typography_font_weight'] ?? null,
                        'font_size' => $typo['typography_font_size'] ?? null,
                        'title' => $typo['title'] ?? 'Custom'
                    ];
                }
            }
        }
        
        // Extract button settings
        if (!empty($kit_settings['button_typography_typography'])) {
            $settings['buttons']['typography'] = [
                'font_family' => $kit_settings['button_typography_font_family'] ?? null,
                'font_weight' => $kit_settings['button_typography_font_weight'] ?? null,
            ];
        }
        if (!empty($kit_settings['button_text_color'])) {
            $settings['buttons']['text_color'] = $kit_settings['button_text_color'];
        }
        if (!empty($kit_settings['button_background_color'])) {
            $settings['buttons']['background_color'] = $kit_settings['button_background_color'];
        }
        if (!empty($kit_settings['button_border_radius'])) {
            $settings['buttons']['border_radius'] = $kit_settings['button_border_radius'];
        }
        
        return $settings;
    }
    
    /**
     * Analyze pages for colors and typography
     */
    private function analyze_pages($limit = 10) {
        $colors = [];
        $typography = [];
        $spacing = [];
        $pages_analyzed = 0;
        
        // Get important pages first (home, about, services, contact)
        $priority_slugs = ['', 'home', 'about', 'about-us', 'services', 'contact', 'contact-us'];
        
        // Get pages with Elementor data
        $args = [
            'post_type' => ['page', 'post'],
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'meta_query' => [
                [
                    'key' => '_elementor_data',
                    'compare' => 'EXISTS'
                ]
            ],
            'orderby' => 'menu_order',
            'order' => 'ASC'
        ];
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                
                $elementor_data = get_post_meta($post_id, '_elementor_data', true);
                if (empty($elementor_data)) continue;
                
                $data = is_string($elementor_data) ? json_decode($elementor_data, true) : $elementor_data;
                if (empty($data)) continue;
                
                // Recursively extract colors and typography from elements
                $this->extract_from_elements($data, $colors, $typography, $spacing);
                $pages_analyzed++;
            }
            wp_reset_postdata();
        }
        
        // Sort by frequency
        uasort($colors, function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        uasort($typography, function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        return [
            'colors' => array_slice($colors, 0, 20, true), // Top 20 colors
            'typography' => array_slice($typography, 0, 10, true), // Top 10 fonts
            'spacing' => $spacing,
            'pagesAnalyzed' => $pages_analyzed
        ];
    }
    
    /**
     * Recursively extract colors and typography from Elementor elements
     */
    private function extract_from_elements($elements, &$colors, &$typography, &$spacing) {
        if (!is_array($elements)) return;
        
        foreach ($elements as $element) {
            if (!is_array($element)) continue;
            
            $settings = $element['settings'] ?? [];
            $widget_type = $element['widgetType'] ?? $element['elType'] ?? 'unknown';
            
            // Extract colors from settings
            foreach ($settings as $key => $value) {
                if ($this->is_color_property($key) && $this->is_valid_color($value)) {
                    $color = $this->normalize_hex($value);
                    if ($color) {
                        if (!isset($colors[$color])) {
                            $colors[$color] = ['count' => 0, 'contexts' => []];
                        }
                        $colors[$color]['count']++;
                        $colors[$color]['contexts'][] = $key;
                    }
                }
                
                // Handle global color references
                if ($key === '__globals__' && is_array($value)) {
                    foreach ($value as $prop => $global_ref) {
                        // Global refs are like "globals/colors?id=primary"
                        if (strpos($global_ref, 'colors') !== false) {
                            // This is a global color reference - already captured in global settings
                        }
                    }
                }
                
                // Extract typography
                if ($this->is_typography_property($key)) {
                    if (is_array($value) && isset($value['font_family'])) {
                        $family = $value['font_family'];
                    } elseif (is_string($value) && !empty($value)) {
                        $family = $value;
                    } else {
                        continue;
                    }
                    
                    if (!empty($family) && $family !== 'inherit') {
                        if (!isset($typography[$family])) {
                            $typography[$family] = ['count' => 0, 'weights' => []];
                        }
                        $typography[$family]['count']++;
                        
                        // Capture weights
                        if (is_array($value) && isset($value['font_weight'])) {
                            $weight = intval($value['font_weight']);
                            if ($weight && !in_array($weight, $typography[$family]['weights'])) {
                                $typography[$family]['weights'][] = $weight;
                            }
                        }
                    }
                }
                
                // Extract spacing from sections
                if ($element['elType'] === 'section' || $element['elType'] === 'container') {
                    if ($key === 'padding' && is_array($value)) {
                        $spacing['section_padding'] = $value;
                    }
                    if ($key === 'content_width' && isset($value['size'])) {
                        $spacing['container_width'] = $value['size'] . ($value['unit'] ?? 'px');
                    }
                }
            }
            
            // Recurse into child elements
            if (!empty($element['elements'])) {
                $this->extract_from_elements($element['elements'], $colors, $typography, $spacing);
            }
        }
    }
    
    /**
     * Check if a property name is a color property
     */
    private function is_color_property($key) {
        foreach ($this->color_properties as $prop) {
            if (strpos($key, $prop) !== false || strpos($key, '_color') !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check if a property name is a typography property
     */
    private function is_typography_property($key) {
        foreach ($this->typography_properties as $prop) {
            if (strpos($key, $prop) !== false || strpos($key, 'font_family') !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check if value looks like a valid color
     */
    private function is_valid_color($value) {
        if (!is_string($value)) return false;
        
        // Hex color
        if (preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $value)) {
            return true;
        }
        
        // RGB/RGBA
        if (preg_match('/^rgba?\s*\(/', $value)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Normalize color to 6-digit hex
     */
    private function normalize_hex($value) {
        if (!is_string($value)) return null;
        
        // Already hex
        if (preg_match('/^#([A-Fa-f0-9]{6})$/i', $value)) {
            return strtoupper($value);
        }
        
        // 3-digit hex
        if (preg_match('/^#([A-Fa-f0-9]{3})$/i', $value, $matches)) {
            $hex = $matches[1];
            return '#' . strtoupper($hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]);
        }
        
        // RGB/RGBA
        if (preg_match('/rgba?\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $value, $matches)) {
            return sprintf('#%02X%02X%02X', $matches[1], $matches[2], $matches[3]);
        }
        
        return null;
    }
    
    /**
     * Check if two colors are visually similar (within threshold)
     */
    private function colors_are_similar($hex1, $hex2, $threshold = 30) {
        if (!$hex1 || !$hex2) return false;
        
        $rgb1 = $this->hex_to_rgb($hex1);
        $rgb2 = $this->hex_to_rgb($hex2);
        
        if (!$rgb1 || !$rgb2) return false;
        
        $distance = sqrt(
            pow($rgb1['r'] - $rgb2['r'], 2) +
            pow($rgb1['g'] - $rgb2['g'], 2) +
            pow($rgb1['b'] - $rgb2['b'], 2)
        );
        
        return $distance < $threshold;
    }
    
    /**
     * Convert hex to RGB
     */
    private function hex_to_rgb($hex) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return null;
        
        return [
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2))
        ];
    }
    
    /**
     * Suggest a semantic name for a color based on its hue and context
     */
    private function suggest_color_name($hex, $contexts = []) {
        $rgb = $this->hex_to_rgb($hex);
        if (!$rgb) return 'Color';
        
        // Check common contexts first
        $context_string = implode(' ', $contexts);
        if (strpos($context_string, 'button') !== false) {
            return 'Button Color';
        }
        if (strpos($context_string, 'heading') !== false || strpos($context_string, 'title') !== false) {
            return 'Heading Color';
        }
        if (strpos($context_string, 'background') !== false) {
            return 'Background';
        }
        if (strpos($context_string, 'link') !== false) {
            return 'Link Color';
        }
        
        // Determine by luminance
        $luminance = (0.299 * $rgb['r'] + 0.587 * $rgb['g'] + 0.114 * $rgb['b']) / 255;
        
        if ($luminance > 0.95) return 'White/Light';
        if ($luminance < 0.05) return 'Black/Dark';
        if ($luminance > 0.8) return 'Light Background';
        if ($luminance < 0.2) return 'Dark Text';
        
        // Determine by dominant hue
        $max = max($rgb['r'], $rgb['g'], $rgb['b']);
        $min = min($rgb['r'], $rgb['g'], $rgb['b']);
        
        if ($max - $min < 20) return 'Neutral Gray';
        
        if ($rgb['r'] >= $rgb['g'] && $rgb['r'] >= $rgb['b']) {
            if ($rgb['g'] > $rgb['b']) return 'Orange/Yellow';
            return 'Red/Pink';
        }
        if ($rgb['g'] >= $rgb['r'] && $rgb['g'] >= $rgb['b']) {
            if ($rgb['b'] > $rgb['r']) return 'Teal/Cyan';
            return 'Green';
        }
        if ($rgb['b'] >= $rgb['r'] && $rgb['b'] >= $rgb['g']) {
            if ($rgb['r'] > $rgb['g']) return 'Purple/Violet';
            return 'Blue';
        }
        
        return 'Accent Color';
    }
    
    /**
     * Categorize colors into semantic tokens
     */
    private function categorize_colors($colors) {
        $categorized = [
            'primary' => null,
            'secondary' => null,
            'accent' => null,
            'text' => null,
            'heading' => null,
            'background' => null,
            'buttonBg' => null,
            'buttonText' => null,
            'additional' => []
        ];
        
        // Sort by frequency (global first, then extracted)
        uasort($colors, function($a, $b) {
            return $b['frequency'] - $a['frequency'];
        });
        
        foreach ($colors as $hex => $data) {
            $value = $data['value'] ?? $hex;
            $rgb = $this->hex_to_rgb($value);
            if (!$rgb) continue;
            
            $luminance = (0.299 * $rgb['r'] + 0.587 * $rgb['g'] + 0.114 * $rgb['b']) / 255;
            $name = strtolower($data['name'] ?? '');
            $source = $data['source'] ?? 'extracted';
            
            // From global settings, use their names
            if ($source === 'global') {
                if (strpos($name, 'primary') !== false && !$categorized['primary']) {
                    $categorized['primary'] = $data;
                } elseif (strpos($name, 'secondary') !== false && !$categorized['secondary']) {
                    $categorized['secondary'] = $data;
                } elseif (strpos($name, 'accent') !== false && !$categorized['accent']) {
                    $categorized['accent'] = $data;
                } elseif (strpos($name, 'text') !== false && !$categorized['text']) {
                    $categorized['text'] = $data;
                } else {
                    $categorized['additional'][] = $data;
                }
                continue;
            }
            
            // Extracted colors - categorize by usage
            if (strpos($name, 'button') !== false) {
                if (!$categorized['buttonBg']) {
                    $categorized['buttonBg'] = $data;
                } elseif (!$categorized['buttonText']) {
                    $categorized['buttonText'] = $data;
                }
            } elseif (strpos($name, 'heading') !== false && !$categorized['heading']) {
                $categorized['heading'] = $data;
            } elseif (strpos($name, 'background') !== false && $luminance > 0.7 && !$categorized['background']) {
                $categorized['background'] = $data;
            } elseif ($luminance < 0.3 && !$categorized['text']) {
                $categorized['text'] = $data;
            } elseif (!$categorized['primary'] && $luminance > 0.2 && $luminance < 0.8) {
                $categorized['primary'] = $data;
            } elseif (!$categorized['secondary'] && $luminance > 0.2 && $luminance < 0.8) {
                $categorized['secondary'] = $data;
            } else {
                $categorized['additional'][] = $data;
            }
        }
        
        // Limit additional colors
        $categorized['additional'] = array_slice($categorized['additional'], 0, 5);
        
        return $categorized;
    }
    
    /**
     * Categorize typography into semantic tokens
     */
    private function categorize_typography($typography) {
        $categorized = [
            'primary' => null,
            'secondary' => null,
            'heading' => null,
            'body' => null,
            'additional' => []
        ];
        
        // Sort by frequency
        uasort($typography, function($a, $b) {
            return ($b['frequency'] ?? 0) - ($a['frequency'] ?? 0);
        });
        
        $index = 0;
        foreach ($typography as $family => $data) {
            if ($index === 0) {
                // Most used is likely body font
                $categorized['primary'] = $data;
                $categorized['body'] = $data;
            } elseif ($index === 1) {
                // Second most used is likely heading font
                $categorized['secondary'] = $data;
                $categorized['heading'] = $data;
            } else {
                $categorized['additional'][] = $data;
            }
            $index++;
        }
        
        // Limit additional fonts
        $categorized['additional'] = array_slice($categorized['additional'], 0, 3);
        
        return $categorized;
    }
    
    /**
     * Extract font weights from typography settings
     */
    private function extract_weights($typo) {
        $weights = [];
        
        if (isset($typo['font_weight'])) {
            $weight = intval($typo['font_weight']);
            if ($weight) $weights[] = $weight;
        }
        
        if (isset($typo['typography_font_weight'])) {
            $weight = intval($typo['typography_font_weight']);
            if ($weight && !in_array($weight, $weights)) $weights[] = $weight;
        }
        
        // Default to 400 if no weights found
        if (empty($weights)) {
            $weights[] = 400;
        }
        
        sort($weights);
        return $weights;
    }
}

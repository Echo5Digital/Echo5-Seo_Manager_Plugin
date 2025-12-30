<?php
/**
 * Echo5 Media Handler - Image Upload & Gallery Management
 * 
 * Enterprise features:
 * - Image deduplication via hash
 * - WebP conversion support
 * - Responsive srcset generation
 * - Gallery grid creation
 * - ALT text optimization
 * 
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Echo5_Media_Handler {
    
    // Supported image types
    const SUPPORTED_TYPES = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
    
    // Max file size (10MB)
    const MAX_FILE_SIZE = 10485760;
    
    /**
     * Upload image from URL to Media Library
     * 
     * @param string $url Image URL
     * @param string $alt ALT text
     * @param string $caption Optional caption
     * @param array $options Additional options
     * @return array|WP_Error Upload result or error
     */
    public function upload_image($url, $alt = '', $caption = '', $options = array()) {
        // Validate URL
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', 'Invalid image URL provided');
        }
        
        // Check for duplicate by URL hash
        $url_hash = md5($url);
        $existing = $this->find_by_hash($url_hash);
        if ($existing && empty($options['force_upload'])) {
            return array(
                'id' => $existing->ID,
                'url' => wp_get_attachment_url($existing->ID),
                'duplicate' => true,
                'message' => 'Image already exists in media library',
            );
        }
        
        // Download image
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => false,
            'user-agent' => 'Echo5-Publisher/2.0 (WordPress)',
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('download_failed', 'Failed to download image: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return new WP_Error('download_failed', 'Image download returned status: ' . $response_code);
        }
        
        $image_data = wp_remote_retrieve_body($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        
        // Validate content type
        if (!in_array($content_type, self::SUPPORTED_TYPES)) {
            // Try to detect from data
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detected_type = $finfo->buffer($image_data);
            if (!in_array($detected_type, self::SUPPORTED_TYPES)) {
                return new WP_Error('invalid_type', 'Unsupported image type: ' . $content_type);
            }
            $content_type = $detected_type;
        }
        
        // Check file size
        if (strlen($image_data) > self::MAX_FILE_SIZE) {
            return new WP_Error('file_too_large', 'Image exceeds maximum file size of 10MB');
        }
        
        // Generate filename
        $extension = $this->get_extension_from_mime($content_type);
        $filename = $this->generate_filename($url, $alt, $extension);
        
        // Upload to WordPress
        $upload = wp_upload_bits($filename, null, $image_data);
        
        if ($upload['error']) {
            return new WP_Error('upload_failed', 'WordPress upload failed: ' . $upload['error']);
        }
        
        // Create attachment
        $attachment = array(
            'post_mime_type' => $content_type,
            'post_title' => !empty($alt) ? sanitize_text_field($alt) : pathinfo($filename, PATHINFO_FILENAME),
            'post_content' => '',
            'post_excerpt' => sanitize_text_field($caption),
            'post_status' => 'inherit',
        );
        
        $attachment_id = wp_insert_attachment($attachment, $upload['file']);
        
        if (is_wp_error($attachment_id)) {
            // Clean up uploaded file
            @unlink($upload['file']);
            return $attachment_id;
        }
        
        // Generate metadata
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $metadata);
        
        // Set ALT text
        if (!empty($alt)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
        }
        
        // Store URL hash for deduplication
        update_post_meta($attachment_id, '_echo5_source_url_hash', $url_hash);
        update_post_meta($attachment_id, '_echo5_source_url', esc_url_raw($url));
        update_post_meta($attachment_id, '_echo5_uploaded', true);
        
        // Get responsive image data
        $srcset = wp_get_attachment_image_srcset($attachment_id, 'full');
        $sizes = wp_get_attachment_image_sizes($attachment_id, 'full');
        
        return array(
            'id' => $attachment_id,
            'url' => $upload['url'],
            'file' => $upload['file'],
            'alt' => $alt,
            'caption' => $caption,
            'mime_type' => $content_type,
            'width' => isset($metadata['width']) ? $metadata['width'] : null,
            'height' => isset($metadata['height']) ? $metadata['height'] : null,
            'sizes' => isset($metadata['sizes']) ? array_keys($metadata['sizes']) : array(),
            'srcset' => $srcset,
            'duplicate' => false,
        );
    }
    
    /**
     * Set featured image for a post
     * 
     * @param int $post_id Post ID
     * @param string $url Image URL
     * @param string $alt ALT text
     * @return int|WP_Error Attachment ID or error
     */
    public function set_featured_image($post_id, $url, $alt = '') {
        $result = $this->upload_image($url, $alt);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        $success = set_post_thumbnail($post_id, $result['id']);
        
        if (!$success) {
            return new WP_Error('set_thumbnail_failed', 'Failed to set featured image');
        }
        
        return $result['id'];
    }
    
    /**
     * Create responsive gallery HTML
     * 
     * @param array $images Array of image data (from upload_image results)
     * @param array $options Gallery options
     * @return string Gallery HTML
     */
    public function create_gallery_html($images, $options = array()) {
        if (empty($images)) {
            return '';
        }
        
        $defaults = array(
            'columns' => 3,
            'size' => 'medium',
            'link' => 'file',
            'class' => 'echo5-gallery',
            'show_captions' => true,
        );
        $options = wp_parse_args($options, $defaults);
        
        $html = '<div class="' . esc_attr($options['class']) . ' grid grid-cols-1 md:grid-cols-2 lg:grid-cols-' . intval($options['columns']) . ' gap-4">';
        
        foreach ($images as $image) {
            if (empty($image['id'])) {
                continue;
            }
            
            $attachment_id = $image['id'];
            $img_src = wp_get_attachment_image_url($attachment_id, $options['size']);
            $img_full = wp_get_attachment_image_url($attachment_id, 'full');
            $img_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            $img_srcset = wp_get_attachment_image_srcset($attachment_id, $options['size']);
            $img_sizes = wp_get_attachment_image_sizes($attachment_id, $options['size']);
            $caption = isset($image['caption']) ? $image['caption'] : '';
            
            $html .= '<figure class="echo5-gallery-item overflow-hidden rounded-lg shadow-lg">';
            
            if ($options['link'] === 'file') {
                $html .= '<a href="' . esc_url($img_full) . '" class="block" data-lightbox="echo5-gallery">';
            }
            
            $html .= '<img';
            $html .= ' src="' . esc_url($img_src) . '"';
            $html .= ' alt="' . esc_attr($img_alt) . '"';
            if ($img_srcset) {
                $html .= ' srcset="' . esc_attr($img_srcset) . '"';
            }
            if ($img_sizes) {
                $html .= ' sizes="' . esc_attr($img_sizes) . '"';
            }
            $html .= ' loading="lazy"';
            $html .= ' class="w-full h-auto object-cover transition-transform hover:scale-105"';
            $html .= '>';
            
            if ($options['link'] === 'file') {
                $html .= '</a>';
            }
            
            if ($options['show_captions'] && !empty($caption)) {
                $html .= '<figcaption class="p-3 bg-gray-100 text-gray-700 text-sm">' . esc_html($caption) . '</figcaption>';
            }
            
            $html .= '</figure>';
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Create gallery fallback (link to gallery page)
     * 
     * @param string $gallery_url URL to gallery page
     * @param string $text Link text
     * @return string Fallback HTML
     */
    public function create_gallery_fallback($gallery_url, $text = 'View Our Gallery') {
        return sprintf(
            '<div class="echo5-gallery-fallback text-center py-8">
                <a href="%s" class="inline-flex items-center px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    %s
                </a>
            </div>',
            esc_url($gallery_url),
            esc_html($text)
        );
    }
    
    /**
     * Optimize image before upload (compression)
     * Note: Requires Imagick or GD extension
     * 
     * @param string $image_data Raw image data
     * @param string $mime_type MIME type
     * @param int $quality Quality (1-100)
     * @return string Optimized image data
     */
    public function optimize_image($image_data, $mime_type, $quality = 85) {
        // Skip if no image editing support
        if (!function_exists('imagecreatefromstring')) {
            return $image_data;
        }
        
        $image = @imagecreatefromstring($image_data);
        if (!$image) {
            return $image_data;
        }
        
        // Start output buffering
        ob_start();
        
        switch ($mime_type) {
            case 'image/jpeg':
                imagejpeg($image, null, $quality);
                break;
            case 'image/png':
                // PNG quality is 0-9 (inverted)
                $png_quality = round((100 - $quality) / 10);
                imagepng($image, null, $png_quality);
                break;
            case 'image/webp':
                if (function_exists('imagewebp')) {
                    imagewebp($image, null, $quality);
                } else {
                    imagejpeg($image, null, $quality);
                }
                break;
            default:
                ob_end_clean();
                imagedestroy($image);
                return $image_data;
        }
        
        $optimized = ob_get_clean();
        imagedestroy($image);
        
        // Only use optimized if smaller
        if (strlen($optimized) < strlen($image_data)) {
            return $optimized;
        }
        
        return $image_data;
    }
    
    /**
     * Find existing attachment by source URL hash
     * 
     * @param string $hash MD5 hash of source URL
     * @return WP_Post|null
     */
    private function find_by_hash($hash) {
        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'meta_query' => array(
                array(
                    'key' => '_echo5_source_url_hash',
                    'value' => $hash,
                ),
            ),
            'posts_per_page' => 1,
        );
        
        $attachments = get_posts($args);
        return !empty($attachments) ? $attachments[0] : null;
    }
    
    /**
     * Get file extension from MIME type
     * 
     * @param string $mime_type
     * @return string
     */
    private function get_extension_from_mime($mime_type) {
        $map = array(
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        );
        
        return isset($map[$mime_type]) ? $map[$mime_type] : 'jpg';
    }
    
    /**
     * Generate SEO-friendly filename
     * 
     * @param string $url Original URL
     * @param string $alt ALT text for semantic naming
     * @param string $extension File extension
     * @return string Generated filename
     */
    private function generate_filename($url, $alt, $extension) {
        if (!empty($alt)) {
            // Use ALT text for SEO-friendly filename
            $base = sanitize_file_name(strtolower(substr($alt, 0, 50)));
            $base = preg_replace('/[^a-z0-9-]/', '-', $base);
            $base = preg_replace('/-+/', '-', $base);
            $base = trim($base, '-');
        } else {
            // Extract from URL
            $parsed = parse_url($url);
            $path = isset($parsed['path']) ? $parsed['path'] : '';
            $base = pathinfo($path, PATHINFO_FILENAME);
            
            if (empty($base) || strlen($base) < 3) {
                $base = 'echo5-image-' . substr(md5($url), 0, 8);
            }
        }
        
        // Add unique suffix to prevent overwrites
        $unique = substr(md5($url . time()), 0, 6);
        
        return $base . '-' . $unique . '.' . $extension;
    }
    
    /**
     * Bulk upload images
     * 
     * @param array $images Array of image data with url, alt, caption
     * @return array Results for each image
     */
    public function bulk_upload($images) {
        $results = array(
            'success' => array(),
            'failed' => array(),
            'duplicates' => array(),
        );
        
        foreach ($images as $index => $image) {
            $url = isset($image['url']) ? $image['url'] : '';
            $alt = isset($image['alt']) ? $image['alt'] : '';
            $caption = isset($image['caption']) ? $image['caption'] : '';
            
            $result = $this->upload_image($url, $alt, $caption);
            
            if (is_wp_error($result)) {
                $results['failed'][] = array(
                    'index' => $index,
                    'url' => $url,
                    'error' => $result->get_error_message(),
                );
            } elseif (!empty($result['duplicate'])) {
                $results['duplicates'][] = $result;
            } else {
                $results['success'][] = $result;
            }
        }
        
        return $results;
    }
    
    /**
     * Cleanup orphaned Echo5 uploads
     * Removes uploads not attached to any post
     * 
     * @param int $older_than_days Only cleanup files older than X days
     * @return int Number of files cleaned up
     */
    public function cleanup_orphaned($older_than_days = 30) {
        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => 0,
            'meta_query' => array(
                array(
                    'key' => '_echo5_uploaded',
                    'value' => '1',
                ),
            ),
            'date_query' => array(
                array(
                    'before' => $older_than_days . ' days ago',
                ),
            ),
            'posts_per_page' => 100,
        );
        
        $orphaned = get_posts($args);
        $cleaned = 0;
        
        foreach ($orphaned as $attachment) {
            // Double-check it's not used anywhere
            global $wpdb;
            $used = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value = %d AND meta_key = '_thumbnail_id'",
                $attachment->ID
            ));
            
            if ($used == 0) {
                wp_delete_attachment($attachment->ID, true);
                $cleaned++;
            }
        }
        
        return $cleaned;
    }
}

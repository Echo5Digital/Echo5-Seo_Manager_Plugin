<?php
/**
 * Echo5 Blog Publisher - Internal Blog Post Publishing
 *
 * Registers REST endpoints for publishing blog posts (not pages) from the
 * Echo5 SEO Manager directly to WordPress. Separate from the existing
 * Echo5_Publisher class which handles page/Elementor publishing.
 *
 * Endpoints:
 *   POST /wp-json/echo5/v1/publish-blog
 *   GET  /wp-json/echo5/v1/check-post-slug/{slug}
 *
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Echo5_Blog_Publisher {

    private $security;
    private $media_handler;

    /**
     * @param Echo5_SEO_Security  $security      Shared security instance
     * @param Echo5_Media_Handler $media_handler Shared media handler instance
     */
    public function __construct( $security, $media_handler ) {
        $this->security      = $security;
        $this->media_handler = $media_handler;
    }

    /**
     * Register REST routes under the echo5/v1 namespace.
     */
    public function register_routes() {
        register_rest_route( 'echo5/v1', '/publish-blog', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'publish_blog' ),
            'permission_callback' => array( $this->security, 'verify_api_key' ),
        ) );

        register_rest_route( 'echo5/v1', '/check-post-slug/(?P<slug>[a-z0-9\-]+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'check_post_slug' ),
            'permission_callback' => array( $this->security, 'verify_api_key' ),
            'args'                => array(
                'slug' => array( 'required' => true, 'sanitize_callback' => 'sanitize_title' ),
            ),
        ) );
    }

    /**
     * POST /wp-json/echo5/v1/publish-blog
     *
     * Creates a new WordPress post with images (featured + inline),
     * sets Yoast SEO meta, and assigns a category.
     *
     * Request body params:
     *   title                  (string, required)
     *   content                (string, HTML — may contain <!-- image:N --> markers)
     *   status                 (string, default "publish"; accepts "draft")
     *   category_slug          (string, default "blog")
     *   featured_image_url     (string|null)
     *   featured_image_alt     (string)
     *   inline_images          (array of {url, alt, title})
     *   yoast                  ({title, description, focus_keyword})
     *   featured_is_first_inline (bool) — when true, strips <!-- image:1 --> to prevent duplication
     */
    public function publish_blog( $request ) {
        $title                   = sanitize_text_field( $request->get_param( 'title' ) );
        $content                 = $request->get_param( 'content' );
        $status                  = sanitize_text_field( $request->get_param( 'status' ) ?: 'publish' );
        $category_slug           = sanitize_text_field( $request->get_param( 'category_slug' ) ?: 'blog' );
        $featured_image_url      = esc_url_raw( $request->get_param( 'featured_image_url' ) ?: '' );
        $featured_image_alt      = sanitize_text_field( $request->get_param( 'featured_image_alt' ) ?: '' );
        $featured_image_base64   = $request->get_param( 'featured_image_base64' ) ?: '';
        $inline_images           = $request->get_param( 'inline_images' ) ?: array();
        $yoast                   = $request->get_param( 'yoast' ) ?: array();
        $featured_is_first_inline = (bool) $request->get_param( 'featured_is_first_inline' );
        $slug                     = sanitize_title( $request->get_param( 'slug' ) ?: '' );
        $author_name              = sanitize_text_field( $request->get_param( 'author_name' ) ?: '' );

        // Validate required fields
        if ( empty( $title ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'title is required' ), 400 );
        }
        if ( empty( $content ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'content is required' ), 400 );
        }

        // Sanitise status — only allow publish or draft
        if ( ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
            $status = 'publish';
        }

        // --- Process <!-- image:N --> markers ---------------------------------
        $processed_content = $content;

        if ( is_array( $inline_images ) && count( $inline_images ) > 0 ) {
            foreach ( $inline_images as $index => $img ) {
                $marker    = '<!-- image:' . ( $index + 1 ) . ' -->';
                $img_url   = isset( $img['url'] ) ? esc_url_raw( $img['url'] ) : '';
                $img_alt   = isset( $img['alt'] ) ? sanitize_text_field( $img['alt'] ) : '';

                if ( $featured_is_first_inline && $index === 0 ) {
                    // Same image as featured — remove the marker to avoid showing the image twice
                    $processed_content = str_replace( $marker, '', $processed_content );
                } else {
                    $img_base64 = isset( $img['base64'] ) ? $img['base64'] : '';

                    if ( ! empty( $img_base64 ) ) {
                        // Cropped image from frontend — save base64 directly
                        $result = $this->save_base64_image( $img_base64, $img_alt );
                        $uploaded_url = is_wp_error( $result ) ? '' : $result['url'];
                    } elseif ( ! empty( $img_url ) ) {
                        $result = $this->media_handler->upload_image( $img_url, $img_alt );
                        $uploaded_url = is_wp_error( $result ) ? $img_url : $result['url'];
                    } else {
                        $uploaded_url = '';
                    }

                    if ( ! empty( $uploaded_url ) ) {
                        $figure = '<figure><img src="' . esc_url( $uploaded_url ) . '" alt="' . esc_attr( $img_alt ) . '" /></figure>';
                        $processed_content = str_replace( $marker, $figure, $processed_content );
                    }
                }
            }
        }

        // --- Resolve author ---------------------------------------------------
        $post_author_id = 0; // 0 = use WordPress default (current user / admin)
        if ( ! empty( $author_name ) ) {
            // Try matching a WP user by display_name first, then user_login
            $user_by_display = get_users( array(
                'search'         => $author_name,
                'search_columns' => array( 'display_name', 'user_login', 'user_nicename' ),
                'number'         => 1,
                'fields'         => array( 'ID' ),
            ) );
            if ( ! empty( $user_by_display ) ) {
                $post_author_id = (int) $user_by_display[0]->ID;
            }
        }

        // --- Create the WordPress post ----------------------------------------
        $post_data = array(
            'post_title'   => $title,
            'post_content' => wp_kses_post( $processed_content ),
            'post_status'  => $status,
            'post_type'    => 'post',
        );
        if ( ! empty( $slug ) ) {
            $post_data['post_name'] = $slug;
        }
        if ( $post_author_id > 0 ) {
            $post_data['post_author'] = $post_author_id;
        }
        $post_id = wp_insert_post( $post_data );

        if ( is_wp_error( $post_id ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Failed to create post: ' . $post_id->get_error_message(),
            ), 500 );
        }

        // --- Featured image ---------------------------------------------------
        if ( ! empty( $featured_image_base64 ) ) {
            // Cropped image from frontend — save base64 and set as featured
            $b64_result = $this->save_base64_image( $featured_image_base64, $featured_image_alt );
            if ( ! is_wp_error( $b64_result ) ) {
                $set_ok = set_post_thumbnail( $post_id, $b64_result['id'] );
                if ( ! $set_ok ) {
                    error_log( '[Echo5 Blog Publisher] Failed to set cropped featured image for post ' . $post_id );
                }
            } else {
                error_log( '[Echo5 Blog Publisher] Base64 featured image failed: ' . $b64_result->get_error_message() );
            }
        } elseif ( ! empty( $featured_image_url ) ) {
            $thumb_result = $this->media_handler->set_featured_image( $post_id, $featured_image_url, $featured_image_alt );
            if ( is_wp_error( $thumb_result ) ) {
                error_log( '[Echo5 Blog Publisher] Featured image failed for post ' . $post_id . ': ' . $thumb_result->get_error_message() );
            }
        }

        // --- Category ---------------------------------------------------------
        $term = get_term_by( 'slug', $category_slug, 'category' );
        if ( $term && ! is_wp_error( $term ) ) {
            wp_set_post_categories( $post_id, array( $term->term_id ) );
        }
        // If the category slug doesn't exist WordPress keeps the post in Uncategorized — acceptable.

        // --- Yoast SEO meta ---------------------------------------------------
        if ( ! empty( $yoast['title'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $yoast['title'] ) );
        }
        if ( ! empty( $yoast['description'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_text_field( $yoast['description'] ) );
        }
        if ( ! empty( $yoast['focus_keyword'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_focuskw', sanitize_text_field( $yoast['focus_keyword'] ) );
        }

        // --- Return -----------------------------------------------------------
        return wp_send_json_success( array(
            'post_id' => $post_id,
            'url'     => get_permalink( $post_id ),
            'status'  => $status,
        ) );
    }

    /**
     * GET /wp-json/echo5/v1/check-post-slug/{slug}
     *
     * Returns {"success":true,"data":{"exists":true|false}}
     * Used by the SEO Manager UI to prevent duplicate post URLs before publishing.
     */
    public function check_post_slug( $request ) {
        $slug     = sanitize_title( $request->get_param( 'slug' ) );
        $existing = get_page_by_path( $slug, OBJECT, 'post' );

        return wp_send_json_success( array(
            'exists' => ! is_null( $existing ),
        ) );
    }

    /**
     * Save a base64-encoded image (from the frontend crop canvas) into the
     * WordPress media library with proper ALT text.
     *
     * Accepts a data URI like "data:image/jpeg;base64,/9j/4AAQ..."
     * or a raw base64 string (auto-detects JPEG by default).
     *
     * @param string $base64  Base64-encoded image (with or without data URI prefix)
     * @param string $alt     ALT text for the attachment
     * @return array|WP_Error { id, url } on success
     */
    private function save_base64_image( $base64, $alt = '' ) {
        // Parse data URI prefix if present
        $mime = 'image/jpeg';
        $ext  = 'jpg';

        if ( preg_match( '/^data:(image\/\w+);base64,/', $base64, $matches ) ) {
            $mime   = $matches[1];
            $base64 = substr( $base64, strlen( $matches[0] ) );

            $ext_map = array(
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/gif'  => 'gif',
            );
            $ext = isset( $ext_map[ $mime ] ) ? $ext_map[ $mime ] : 'jpg';
        }

        $decoded = base64_decode( $base64, true );
        if ( false === $decoded || strlen( $decoded ) < 100 ) {
            return new WP_Error( 'invalid_base64', 'Could not decode base64 image data' );
        }

        // Generate a unique file name with publish date prefix (YYYY-MM-DD)
        $filename = sanitize_file_name(
            date( 'Y-m-d' ) . '-'
            . ( ! empty( $alt ) ? substr( sanitize_title( $alt ), 0, 40 ) : 'blog-image' )
            . '-' . substr( md5( $decoded ), 0, 6 )
            . '.' . $ext
        );

        $upload = wp_upload_bits( $filename, null, $decoded );
        if ( ! empty( $upload['error'] ) ) {
            return new WP_Error( 'upload_failed', 'wp_upload_bits error: ' . $upload['error'] );
        }

        // Create attachment post
        $attachment_id = wp_insert_attachment(
            array(
                'post_mime_type' => $mime,
                'post_title'    => ! empty( $alt ) ? sanitize_text_field( $alt ) : 'Cropped Image',
                'post_content'  => '',
                'post_status'   => 'inherit',
            ),
            $upload['file']
        );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $upload['file'] );
            return $attachment_id;
        }

        // Generate all image sizes / metadata
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        // Set ALT text
        if ( ! empty( $alt ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
        }

        return array(
            'id'  => $attachment_id,
            'url' => $upload['url'],
        );
    }
}

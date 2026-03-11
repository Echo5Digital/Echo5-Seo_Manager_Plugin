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
        $inline_images           = $request->get_param( 'inline_images' ) ?: array();
        $yoast                   = $request->get_param( 'yoast' ) ?: array();
        $featured_is_first_inline = (bool) $request->get_param( 'featured_is_first_inline' );
        $slug                     = sanitize_title( $request->get_param( 'slug' ) ?: '' );

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
                    if ( ! empty( $img_url ) ) {
                        $result = $this->media_handler->upload_image( $img_url, $img_alt );
                        // On failure fall back to the original external URL — never abort
                        if ( is_wp_error( $result ) ) {
                            $uploaded_url = $img_url;
                        } else {
                            $uploaded_url = $result['url'];
                        }
                        $figure = '<figure><img src="' . esc_url( $uploaded_url ) . '" alt="' . esc_attr( $img_alt ) . '" /></figure>';
                        $processed_content = str_replace( $marker, $figure, $processed_content );
                    }
                }
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
        $post_id = wp_insert_post( $post_data );

        if ( is_wp_error( $post_id ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Failed to create post: ' . $post_id->get_error_message(),
            ), 500 );
        }

        // --- Featured image ---------------------------------------------------
        if ( ! empty( $featured_image_url ) ) {
            $thumb_result = $this->media_handler->set_featured_image( $post_id, $featured_image_url, $featured_image_alt );
            // Log failure but do not abort the whole publish
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
}

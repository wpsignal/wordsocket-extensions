<?php
/**
 * Living articles: the wrapper around a post's content on its page, and the content route the browser fetches.
 *
 * @package WPSignal\Extensions\PostSocket
 */

namespace WPSignal\Extensions\PostSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a post's content updates live for readers.
 *
 * @since 0.1.0
 *
 * @param int|\WP_Post|null $post Post or id.
 * @return bool
 */
function is_living( int|\WP_Post|null $post ): bool {
	$post = get_post( $post );
	if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! in_array( $post->post_type, living_types(), true ) ) {
		return false;
	}
	if ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) || has_shortcode( $post->post_content, 'et_pb_section' ) ) {
		return false;
	}
	/**
	 * Filters whether a post's content updates live for readers.
	 *
	 * @since 0.1.0
	 *
	 * @param bool     $living True for a published post of an enabled type not built by a page builder.
	 * @param \WP_Post $post   The post.
	 */
	return (bool) apply_filters( 'postsocket_is_living', true, $post );
}

/**
 * The URL of a post's content for the browser, with the version it has.
 *
 * @since 0.1.0
 *
 * @param int $post_id Post id.
 * @return string
 */
function content_url( int $post_id ): string {
	return rest_url( REST_NS . '/content/' . $post_id );
}

// The wrapper the browser swaps, around the page's own content.
add_filter(
	'the_content',
	static function ( string $content ): string {
		$post_id = decorating_post();
		if ( $post_id <= 0 || ! is_living( $post_id ) || str_contains( $content, 'data-postsocket-post=' ) ) {
			return $content;
		}
		return sprintf(
			'<div class="postsocket-living" data-postsocket-post="%d" data-postsocket-modified="%s" data-postsocket-hash="%s" data-postsocket-rendered="%d" data-postsocket-url="%s">%s</div>',
			$post_id,
			esc_attr( mysql2date( 'c', get_post_field( 'post_modified_gmt', $post_id ), false ) ),
			esc_attr( current_hash( get_post( $post_id ) ) ),
			time(),
			esc_url( content_url( $post_id ) ),
			$content
		);
	},
	PHP_INT_MAX - 10
);

// The content a browser fetches when a payload is too large to carry it, or to catch up.
add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			REST_NS,
			'/content/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => array(
					'v' => array( 'type' => 'string' ),
				),
				'callback'            => __NAMESPACE__ . '\handle_content',
			)
		);
	}
);

/**
 * Serve a living post's rendered content, cacheable under the `v` the browser asked with.
 *
 * @since 0.1.0
 *
 * @param \WP_REST_Request $request The request.
 * @return \WP_REST_Response|\WP_Error
 */
function handle_content( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
	$post = get_post( (int) $request['id'] );
	if ( ! $post || ! is_living( $post ) || post_password_required( $post ) ) {
		return new \WP_Error( 'postsocket_not_found', __( 'No such post.', 'postsocket' ), array( 'status' => 404 ) );
	}
	$html     = render_content( $post );
	$response = rest_ensure_response(
		array(
			'id'       => (int) $post->ID,
			'modified' => mysql2date( 'c', $post->post_modified_gmt, false ),
			'hash'     => content_hash( $html ),
			'html'     => $html,
			'assets'   => assets_for( $post ),
		)
	);
	$response->header( 'Cache-Control', 'public, max-age=300, s-maxage=300' );
	return $response;
}

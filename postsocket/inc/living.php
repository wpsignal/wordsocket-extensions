<?php
/**
 * Living articles: the wrapper around a post's content on its page, the live fields outside it, the page refresh for posts without any, and the content route the browser fetches.
 *
 * @package WPSignal\Extensions\PostSocket
 */

namespace WPSignal\Extensions\PostSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether live editing is switched on for a post.
 *
 * @since 0.1.0
 *
 * @param int $post_id Post id.
 * @return bool
 */
function live_editing( int $post_id ): bool {
	return (bool) get_post_meta( $post_id, META_LIVE, true );
}

/**
 * Switch live editing on or off for a post.
 *
 * @since 0.1.0
 *
 * @param int  $post_id Post id.
 * @param bool $on      Whether readers see its saved edits live.
 * @return void
 */
function set_live_editing( int $post_id, bool $on ): void {
	if ( $on ) {
		update_post_meta( $post_id, META_LIVE, 1 );
	} else {
		delete_post_meta( $post_id, META_LIVE );
	}
}

/**
 * Whether a post's content updates live for readers: one of an enabled type with live editing switched on, or a post showing a live blog.
 *
 * @since 0.1.0
 *
 * @param int|\WP_Post|null $post Post or id.
 * @return bool
 */
function is_living( int|\WP_Post|null $post ): bool {
	$post = get_post( $post );
	if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ENTRY_TYPE === $post->post_type ) {
		return false;
	}
	$switched_on = in_array( $post->post_type, living_types(), true ) && live_editing( $post->ID );
	if ( ! $switched_on && ! feed_liveblogs( $post->post_content ) ) {
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
	 * @param bool     $living True for a published post of an enabled type with live editing switched on, or one showing a live blog, not built by a page builder.
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

/**
 * The CSS selector of the headings the browser renames when a living post's title changes.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $post The post.
 * @return string
 */
function title_selector( \WP_Post $post ): string {
	/**
	 * Filters the CSS selector of the headings renamed when a living post's title changes, such as a classic theme's `.entry-title`.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $selector The selector, `[data-postsocket-title="{id}"]` by default, the block theme Post Title PHP marks.
	 * @param \WP_Post $post     The post.
	 */
	return trim( (string) apply_filters( 'postsocket_title_selector', '[data-postsocket-title="' . $post->ID . '"]', $post ) );
}

/**
 * A post's live fields: named pieces of its page outside the content that readers see change, from its meta and from its taxonomies, as plugins and themes declare them.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $post The post.
 * @return array<string, array{selector: string, meta: string[], taxonomy: string, render: callable|null}>
 */
function live_fields( \WP_Post $post ): array {
	/**
	 * Filters the live fields of a living post.
	 *
	 * Each field is `name => array( 'selector' => ..., 'meta' => ..., 'render' => ... )`, every key optional:
	 * `selector` is the CSS selector of its elements (`[data-postsocket-field="{name}"]` by default),
	 * `meta` the meta key or keys whose change renders it again (the name by default),
	 * `render` a `callable( WP_Post ): string` returning its HTML (the first meta key's value as text by default).
	 *
	 * @since 0.1.0
	 *
	 * @param array    $fields The fields, none by default.
	 * @param \WP_Post $post   The post.
	 */
	$declared = (array) apply_filters( 'postsocket_live_fields', array(), $post );
	$fields   = array();
	foreach ( $declared as $name => $args ) {
		if ( ! is_string( $name ) || ! preg_match( '/^[a-z][a-z0-9_-]*$/', $name ) ) {
			continue;
		}
		$args            = (array) $args;
		$fields[ $name ] = array(
			'selector' => trim( (string) ( $args['selector'] ?? '' ) ),
			'meta'     => array_values( array_filter( array_map( 'strval', (array) ( $args['meta'] ?? $name ) ) ) ),
			'taxonomy' => '',
			'render'   => is_callable( $args['render'] ?? null ) ? $args['render'] : null,
		);
	}

	/**
	 * Filters the live taxonomies of a living post: the taxonomies whose terms on the post readers see change.
	 *
	 * Each is `taxonomy => array( 'selector' => ..., 'render' => ... )`, both keys optional:
	 * `selector` is the CSS selector of its elements (`[data-postsocket-taxonomy="{taxonomy}"]` by default),
	 * `render` a `callable( WP_Post ): string` returning its HTML (the post's linked terms, comma separated, by default).
	 *
	 * @since 0.1.0
	 *
	 * @param array    $taxonomies The taxonomies, none by default.
	 * @param \WP_Post $post       The post.
	 */
	$declared = (array) apply_filters( 'postsocket_live_taxonomies', array(), $post );
	foreach ( $declared as $taxonomy => $args ) {
		if ( ! is_string( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
			continue;
		}
		$args                              = (array) $args;
		$selector                          = trim( (string) ( $args['selector'] ?? '' ) );
		$fields[ 'taxonomy-' . $taxonomy ] = array(
			'selector' => '' !== $selector ? $selector : '[data-postsocket-taxonomy="' . $taxonomy . '"]',
			'meta'     => array(),
			'taxonomy' => $taxonomy,
			'render'   => is_callable( $args['render'] ?? null ) ? $args['render'] : null,
		);
	}
	return $fields;
}

/**
 * A post's live fields rendered, by name.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $post The post.
 * @return array<string, string>
 */
function render_fields( \WP_Post $post ): array {
	$rendered = array();
	foreach ( live_fields( $post ) as $name => $field ) {
		if ( $field['render'] ) {
			$rendered[ $name ] = (string) call_user_func( $field['render'], $post );
			continue;
		}
		if ( '' !== $field['taxonomy'] ) {
			$terms             = get_the_term_list( $post->ID, $field['taxonomy'], '', ', ' );
			$rendered[ $name ] = is_string( $terms ) ? $terms : '';
			continue;
		}
		$value             = get_post_meta( $post->ID, $field['meta'][0] ?? $name, true );
		$rendered[ $name ] = is_scalar( $value ) ? esc_html( (string) $value ) : '';
	}
	return $rendered;
}

/**
 * The wrapper attribute naming the selector of each live field that has one, empty when no field does.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $post The post.
 * @return string
 */
function fields_attribute( \WP_Post $post ): string {
	$named = array_filter( array_map( static fn( array $field ): string => $field['selector'], live_fields( $post ) ) );
	return $named ? ' data-postsocket-fields="' . esc_attr( (string) wp_json_encode( $named ) ) . '"' : '';
}

/**
 * The CSS selector of the part of the page a reader's browser fetches again and morphs for a change that its content, title and live fields do not show, empty when none is set.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $post The post.
 * @return string
 */
function refresh_selector( \WP_Post $post ): string {
	/**
	 * Filters the CSS selector of the part of a living post's page that readers fetch again and morph in place, such as `.site-main`.
	 *
	 * The fallback for a change to the post's meta or terms that its content, title, live fields and live taxonomies do not show.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $selector The selector, empty by default: no page refresh.
	 * @param \WP_Post $post     The post.
	 */
	return trim( (string) apply_filters( 'postsocket_refresh_selector', '', $post ) );
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
			'<div class="postsocket-living" data-postsocket-post="%d" data-postsocket-modified="%s" data-postsocket-hash="%s" data-postsocket-rendered="%d" data-postsocket-url="%s" data-postsocket-headline="%s" data-postsocket-title-selector="%s" data-postsocket-refresh="%s"%s>%s</div>',
			$post_id,
			esc_attr( mysql2date( 'c', get_post_field( 'post_modified_gmt', $post_id ), false ) ),
			esc_attr( current_hash( get_post( $post_id ) ) ),
			time(),
			esc_url( content_url( $post_id ) ),
			esc_attr( title_text( get_post( $post_id ) ) ),
			esc_attr( title_selector( get_post( $post_id ) ) ),
			esc_attr( refresh_selector( get_post( $post_id ) ) ),
			fields_attribute( get_post( $post_id ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built.
			$content
		);
	},
	PHP_INT_MAX - 10
);

// A block theme's Post Title for the living post on its own page is marked once, so the browser renames that heading and not a list's linked copy of it.
add_filter(
	'render_block_core/post-title',
	static function ( string $html, array $parsed_block, \WP_Block $block ): string {
		static $marked = false;
		$post_id       = (int) ( $block->context['postId'] ?? 0 );
		if ( $marked || $post_id <= 0 || ! empty( $block->attributes['isLink'] ) || ! is_singular() || get_queried_object_id() !== $post_id || rendering_for_relay() || ! is_living( $post_id ) ) {
			return $html;
		}
		$marked = true;
		$tags   = new \WP_HTML_Tag_Processor( $html );
		if ( $tags->next_tag() ) {
			$tags->set_attribute( 'data-postsocket-title', (string) $post_id );
		}
		return $tags->get_updated_html();
	},
	10,
	3
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
 * Serve a living post's rendered content and live fields, cacheable under the `v` the browser asked with.
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
	$rendered = rendered_post( $post );
	$response = rest_ensure_response(
		array(
			'id'       => (int) $post->ID,
			'modified' => mysql2date( 'c', $post->post_modified_gmt, false ),
			'hash'     => $rendered['hash'],
			'title'    => title_text( $post ),
			'html'     => $rendered['html'],
			'assets'   => $rendered['assets'],
		) + ( $rendered['fields'] ? array( 'fields' => $rendered['fields'] ) : array() )
	);
	$response->header( 'Cache-Control', 'public, max-age=300, s-maxage=300' );
	return $response;
}

// The live editing switch of a post, for the block editor, on every post type a site may enable.
add_action(
	'rest_api_init',
	static function (): void {
		foreach ( living_type_choices() as $type ) {
			register_rest_field(
				$type['name'],
				'postsocket_live',
				array(
					'get_callback'    => static fn( array $post ): bool => live_editing( (int) $post['id'] ),
					'update_callback' => static function ( $value, \WP_Post $post ): bool {
						set_live_editing( $post->ID, (bool) $value );
						return true;
					},
					'schema'          => array(
						'description' => __( 'Whether readers see saved edits to this post without a reload.', 'postsocket' ),
						'type'        => 'boolean',
						'context'     => array( 'view', 'edit' ),
					),
				)
			);
		}
	}
);

// The same switch in the classic editor's Publish box.
add_action(
	'post_submitbox_misc_actions',
	static function ( \WP_Post $post ): void {
		if ( ! in_array( $post->post_type, living_types(), true ) ) {
			return;
		}
		wp_nonce_field( 'postsocket_live_' . $post->ID, 'postsocket_live_nonce', false );
		printf(
			'<div class="misc-pub-section"><label><input type="checkbox" name="postsocket_live" value="1" %s /> %s</label></div>',
			checked( live_editing( $post->ID ), true, false ),
			esc_html__( 'Live editing', 'postsocket' )
		);
	}
);

add_action(
	'save_post',
	static function ( int $post_id ): void {
		$nonce = isset( $_POST['postsocket_live_nonce'] ) ? sanitize_key( wp_unslash( $_POST['postsocket_live_nonce'] ) ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'postsocket_live_' . $post_id ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		set_live_editing( $post_id, ! empty( $_POST['postsocket_live'] ) );
	}
);

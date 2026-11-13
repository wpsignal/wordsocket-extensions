<?php
/**
 * The live feed, wherever a Live Feed block places a live blog.
 *
 * - `render_feed()` is the block's markup; it enqueues the feed module and seeds its store.
 * - `LiveBlogPosting` JSON-LD in the head of the live blog's home.
 * - `GET /postsocket/v1/liveblog/{id}/entries` pages older entries and serves the catch-up.
 *
 * @package WPSignal\Extensions\PostSocket
 */

namespace WPSignal\Extensions\PostSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interactivity API store namespace of the feed.
 *
 * @since 0.1.0
 */
const FEED_STORE = 'postsocket/feed';

/**
 * How many entries the JSON-LD lists.
 *
 * @since 0.1.0
 */
const JSONLD_ENTRIES = 50;

/**
 * The queried post whose main content is being filtered right now, or 0.
 *
 * Covers a classic theme's loop and a block theme's Post Content block.
 *
 * @since 0.1.0
 *
 * @return int
 */
function decorating_post(): int {
	if ( rendering_for_relay() || is_feed() || is_embed() || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return 0;
	}
	if ( doing_filter( 'get_the_excerpt' ) || doing_filter( 'wp_trim_excerpt' ) ) {
		return 0;
	}
	if ( ! is_singular() ) {
		return 0;
	}
	$queried = (int) get_queried_object_id();
	if ( $queried <= 0 ) {
		return 0;
	}
	if ( in_the_loop() && is_main_query() && get_the_ID() === $queried ) {
		return $queried;
	}
	return post_content_block_for() === $queried ? $queried : 0;
}

/**
 * The post id of the Post Content block being rendered, or 0.
 *
 * @since 0.1.0
 *
 * @param int|null $set Set the id; null reads it.
 * @return int
 */
function post_content_block_for( ?int $set = null ): int {
	static $id = 0;
	if ( null !== $set ) {
		$id = $set;
	}
	return $id;
}
add_filter(
	'render_block_context',
	static function ( array $context, array $parsed_block ): array {
		if ( 'core/post-content' === ( $parsed_block['blockName'] ?? '' ) ) {
			post_content_block_for( (int) ( $context['postId'] ?? 0 ) );
		}
		return $context;
	},
	10,
	2
);
add_filter(
	'render_block_core/post-content',
	static function ( string $content ): string {
		post_content_block_for( 0 );
		return $content;
	},
	1
);

/**
 * The feed markup for a live blog, with its module enqueued and its store seeded.
 *
 * @since 0.1.0
 *
 * @param int $liveblog_id Live blog term id.
 * @return string Empty for anything but a live blog.
 */
function render_feed( int $liveblog_id ): string {
	if ( ! is_liveblog( $liveblog_id ) ) {
		return '';
	}
	$per_page = settings()['entries_per_page'];
	$pinned   = entries_query(
		$liveblog_id,
		array(
			'meta_key'       => META_PINNED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'posts_per_page' => 10,
		)
	)->posts;
	$latest   = entries_query(
		$liveblog_id,
		array(
			'posts_per_page' => $per_page + 1,
			'post__not_in'   => $pinned ? array_map( static fn( $p ) => $p->ID, $pinned ) : array( 0 ),
		)
	)->posts;
	$more     = count( $latest ) > $per_page;
	$latest   = array_slice( $latest, 0, $per_page );
	$state    = liveblog_state( $liveblog_id );
	$newest   = '';
	foreach ( array_merge( $pinned, $latest ) as $entry ) {
		$modified = mysql2date( 'c', $entry->post_modified_gmt, false );
		$newest   = $modified > $newest ? $modified : $newest;
	}
	$oldest    = $latest ? mysql2date( 'c', end( $latest )->post_date_gmt, false ) : '';
	$oldest_id = $latest ? (int) end( $latest )->ID : 0;

	enqueue_feed();
	wp_interactivity_state(
		FEED_STORE,
		array(
			'channel'     => liveblog_channel( $liveblog_id ),
			'entriesUrl'  => rest_url( REST_NS . '/liveblog/' . $liveblog_id . '/entries' ),
			'draftsUrl'   => rest_url( REST_NS . '/liveblog/' . $liveblog_id . '/drafts' ),
			'nonceUrl'    => admin_url( 'admin-ajax.php?action=rest-nonce' ),
			'deskChannel' => desk_channel( $liveblog_id ),
			'perPage'     => $per_page,
			'ended'       => 'ended' === $state,
			'more'        => $more,
			'pending'     => 0,
			'loading'     => false,
			'i18n'        => array(
				'live'    => __( 'Live', 'postsocket' ),
				'ended'   => __( 'Ended', 'postsocket' ),
				/* translators: %d: number of new updates waiting above */
				'pending' => __( '%d new updates', 'postsocket' ),
				'one'     => __( '1 new update', 'postsocket' ),
			),
		)
	);
	$html  = sprintf(
		'<section class="postsocket-feed" data-postsocket-feed="%1$d" data-postsocket-state="%2$s" data-postsocket-newest="%3$s" data-postsocket-oldest="%4$s" data-postsocket-oldest-id="%8$d" data-postsocket-rendered="%5$d" data-wp-interactive="%6$s" %7$s>',
		$liveblog_id,
		esc_attr( $state ),
		esc_attr( $newest ),
		esc_attr( $oldest ),
		time(),
		esc_attr( FEED_STORE ),
		wp_interactivity_data_wp_context( array( 'liveblog' => $liveblog_id ) ),
		$oldest_id
	);
	$html .= '<header class="postsocket-feed__header">';
	$html .= sprintf(
		'<span class="postsocket-feed__badge%s" data-wp-class--is-ended="state.ended" data-wp-text="state.badge">%s</span>',
		'ended' === $state ? ' is-ended' : '',
		'ended' === $state ? esc_html__( 'Ended', 'postsocket' ) : esc_html__( 'Live', 'postsocket' )
	);
	$html .= '<button type="button" class="postsocket-feed__pending" hidden data-wp-bind--hidden="!state.pending" data-wp-on--click="actions.showPending" data-wp-text="state.pendingLabel"></button>';
	$html .= '</header>';
	$html .= '<div class="postsocket-feed__pinned" data-postsocket-pinned-list>';
	foreach ( $pinned as $entry ) {
		$html .= render_entry( $entry );
	}
	$html .= '</div>';
	$html .= '<div class="postsocket-feed__entries" data-postsocket-entry-list>';
	foreach ( $latest as $entry ) {
		$html .= render_entry( $entry );
	}
	$html .= '</div>';
	$html .= sprintf(
		'<button type="button" class="postsocket-feed__older"%s data-wp-bind--hidden="!state.more" data-wp-bind--disabled="state.loading" data-wp-on--click="actions.loadOlder">%s</button>',
		$more ? '' : ' hidden',
		esc_html__( 'Load older updates', 'postsocket' )
	);
	$html .= '</section>';
	return $html;
}

/**
 * The `LiveBlogPosting` JSON-LD for a live blog on its home.
 *
 * @since 0.1.0
 *
 * @param \WP_Term $liveblog The live blog.
 * @param \WP_Post $home     The post it lives on.
 * @return array
 */
function jsonld( \WP_Term $liveblog, \WP_Post $home ): array {
	$started = (int) get_term_meta( $liveblog->term_id, META_STARTED, true );
	$ended   = (int) get_term_meta( $liveblog->term_id, META_ENDED, true );
	$updates = array();
	foreach ( entries_query( $liveblog->term_id, array( 'posts_per_page' => JSONLD_ENTRIES ) )->posts as $entry ) {
		$update = array(
			'@type'         => 'BlogPosting',
			'url'           => entry_link( $entry->ID ),
			'datePublished' => mysql2date( 'c', $entry->post_date_gmt, false ),
			'dateModified'  => mysql2date( 'c', $entry->post_modified_gmt, false ),
			'articleBody'   => wp_strip_all_tags( excerpt_remove_blocks( $entry->post_content ), true ),
			'author'        => array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', (int) $entry->post_author ),
			),
		);
		if ( '' !== trim( $entry->post_title ) ) {
			$update['headline'] = get_the_title( $entry );
		}
		$updates[] = $update;
	}
	$data = array(
		'@context'          => 'https://schema.org',
		'@type'             => 'LiveBlogPosting',
		'@id'               => get_permalink( $home ),
		'url'               => get_permalink( $home ),
		'headline'          => get_the_title( $home ),
		'datePublished'     => mysql2date( 'c', $home->post_date_gmt, false ),
		'dateModified'      => mysql2date( 'c', $home->post_modified_gmt, false ),
		'coverageStartTime' => $started > 0 ? gmdate( 'c', $started ) : mysql2date( 'c', $home->post_date_gmt, false ),
		'author'            => array(
			'@type' => 'Person',
			'name'  => get_the_author_meta( 'display_name', (int) $home->post_author ),
		),
		'liveBlogUpdate'    => $updates,
	);
	if ( 'ended' === liveblog_state( $liveblog->term_id ) && $ended > 0 ) {
		$data['coverageEndTime'] = gmdate( 'c', $ended );
	}
	if ( has_post_thumbnail( $home ) ) {
		$data['image'] = get_the_post_thumbnail_url( $home, 'full' );
	}
	return $data;
}

add_action(
	'wp_head',
	static function (): void {
		if ( ! is_singular() ) {
			return;
		}
		$home = get_queried_object();
		if ( ! $home instanceof \WP_Post || 'publish' !== $home->post_status ) {
			return;
		}
		foreach ( feed_liveblogs( $home->post_content ) as $liveblog_id ) {
			$liveblog = get_liveblog( $liveblog_id );
			if ( $liveblog && home_of( $liveblog_id ) === $home->ID ) {
				echo '<script type="application/ld+json" id="postsocket-jsonld">' . wp_json_encode( jsonld( $liveblog, $home ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
				return;
			}
		}
	}
);

// Older entries and the catch-up page.
add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			REST_NS,
			'/liveblog/(?P<id>\d+)/entries',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => array(
					'before'    => array(
						'type'   => 'string',
						'format' => 'date-time',
					),
					'before_id' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'limit'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => ENTRIES_MAX,
					),
				),
				'callback'            => __NAMESPACE__ . '\handle_entries',
			)
		);
		register_rest_route(
			REST_NS,
			'/liveblog/(?P<id>\d+)/drafts',
			array(
				'methods'             => 'GET',
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
				'callback'            => __NAMESPACE__ . '\handle_drafts',
			)
		);
	}
);

/**
 * Serve a page of a live blog's entries, newest first, as rendered items.
 *
 * @since 0.1.0
 *
 * @param \WP_REST_Request $request The request.
 * @return \WP_REST_Response|\WP_Error
 */
function handle_entries( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
	$liveblog = get_liveblog( (int) $request['id'] );
	if ( ! $liveblog ) {
		return new \WP_Error( 'postsocket_not_found', __( 'No such live blog.', 'postsocket' ), array( 'status' => 404 ) );
	}
	$limit     = (int) ( $request['limit'] ?? settings()['entries_per_page'] );
	$before    = (string) ( $request['before'] ?? '' );
	$before_id = (int) ( $request['before_id'] ?? 0 );
	$cursor    = null;
	if ( '' !== $before ) {
		$cursor = static function ( string $where ) use ( $before, $before_id ): string {
			global $wpdb;
			$date = gmdate( 'Y-m-d H:i:s', (int) strtotime( $before ) );
			if ( $before_id > 0 ) {
				return $where . $wpdb->prepare( " AND ( {$wpdb->posts}.post_date_gmt < %s OR ( {$wpdb->posts}.post_date_gmt = %s AND {$wpdb->posts}.ID < %d ) )", $date, $date, $before_id );
			}
			return $where . $wpdb->prepare( " AND {$wpdb->posts}.post_date_gmt < %s", $date );
		};
		add_filter( 'posts_where', $cursor );
	}
	$posts = entries_query( $liveblog->term_id, array( 'posts_per_page' => $limit + 1 ) )->posts;
	if ( $cursor ) {
		remove_filter( 'posts_where', $cursor );
	}
	$more  = count( $posts ) > $limit;
	$items = array();
	foreach ( array_slice( $posts, 0, $limit ) as $entry ) {
		$rendered = rendered_entry( $entry );
		$items[]  = array(
			'id'       => (int) $entry->ID,
			'html'     => $rendered['html'],
			'date'     => mysql2date( 'c', $entry->post_date_gmt, false ),
			'modified' => mysql2date( 'c', $entry->post_modified_gmt, false ),
			'pinned'   => is_pinned( $entry->ID ),
			'assets'   => $rendered['assets'],
		);
	}
	$response = rest_ensure_response(
		array(
			'liveblog' => (int) $liveblog->term_id,
			'state'    => liveblog_state( $liveblog->term_id ),
			'entries'  => $items,
			'more'     => $more,
		)
	);
	$response->header( 'Cache-Control', '' !== $before ? 'public, max-age=300' : 'public, max-age=15' );
	return $response;
}

/**
 * The most unpublished entries the drafts route answers with.
 *
 * @since 0.1.0
 */
const DRAFTS_MAX = 50;

/**
 * Serve a live blog's draft, pending and scheduled entries that the current user may edit, as rendered items, never cached.
 *
 * @since 0.1.0
 *
 * @param \WP_REST_Request $request The request.
 * @return \WP_REST_Response|\WP_Error
 */
function handle_drafts( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
	$liveblog = get_liveblog( (int) $request['id'] );
	if ( ! $liveblog ) {
		return new \WP_Error( 'postsocket_not_found', __( 'No such live blog.', 'postsocket' ), array( 'status' => 404 ) );
	}
	$posts = entries_query(
		$liveblog->term_id,
		array(
			'post_status'    => array( 'draft', 'pending', 'future' ),
			'posts_per_page' => DRAFTS_MAX,
			'orderby'        => array(
				'modified' => 'DESC',
				'ID'       => 'DESC',
			),
		)
	)->posts;
	$items = array();
	foreach ( $posts as $entry ) {
		if ( ! current_user_can( 'edit_post', $entry->ID ) ) {
			continue;
		}
		$rendered = rendered_entry( $entry );
		$items[]  = array(
			'id'       => (int) $entry->ID,
			'html'     => $rendered['html'],
			'status'   => $entry->post_status,
			'modified' => mysql2date( 'c', $entry->post_modified_gmt, false ),
			'pinned'   => is_pinned( $entry->ID ),
			'assets'   => $rendered['assets'],
		);
	}
	$response = rest_ensure_response(
		array(
			'liveblog' => (int) $liveblog->term_id,
			'entries'  => $items,
		)
	);
	$response->header( 'Cache-Control', 'private, no-store' );
	return $response;
}

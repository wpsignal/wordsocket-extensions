<?php
/**
 * The entry post type, the live blog taxonomy, and what ties them to the posts that show them.
 *
 * - A live blog is a `postsocket_liveblog` term; an entry is a non-public `postsocket_entry` post tagged with one.
 * - A post that holds the Live Feed block for a live blog is its host; the first published one is its home.
 * - An entry's link is its live blog's home with the entry anchor.
 * - Deleting a live blog trashes its entries; publishing an entry without one is refused.
 * - The desk reads who has an entry open from its edit lock, lists new entries still open in the editor, and restores trashed ones.
 *
 * @package WPSignal\Extensions\PostSocket
 */

namespace WPSignal\Extensions\PostSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Live Feed block's name.
 *
 * @since 0.1.0
 */
const FEED_BLOCK = 'postsocket/live-feed';

/**
 * Register the entry type, the live blog taxonomy and their meta.
 *
 * @since 0.1.0
 *
 * @return void
 */
function register_content_types(): void {
	register_post_type(
		ENTRY_TYPE,
		array(
			'labels'              => array(
				'name'               => __( 'Entries', 'postsocket' ),
				'singular_name'      => __( 'Live Blog Entry', 'postsocket' ),
				'add_new'            => __( 'Add Entry', 'postsocket' ),
				'add_new_item'       => __( 'Add New Entry', 'postsocket' ),
				'edit_item'          => __( 'Edit Entry', 'postsocket' ),
				'new_item'           => __( 'New Entry', 'postsocket' ),
				'view_item'          => __( 'View Entry', 'postsocket' ),
				'search_items'       => __( 'Search Entries', 'postsocket' ),
				'not_found'          => __( 'No entries found.', 'postsocket' ),
				'not_found_in_trash' => __( 'No entries found in Trash.', 'postsocket' ),
				'all_items'          => __( 'Entries', 'postsocket' ),
				'menu_name'          => __( 'Entries', 'postsocket' ),
			),
			'description'         => __( 'One update on a live blog, written in the editor and shown wherever the live blog is placed.', 'postsocket' ),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => false,
			'show_in_rest'        => true,
			'show_in_nav_menus'   => false,
			'rewrite'             => false,
			'taxonomies'          => array( LIVEBLOG_TAX ),
			'supports'            => array( 'title', 'editor', 'author', 'thumbnail', 'revisions', 'custom-fields' ),
			'template'            => array(
				array( 'core/paragraph', array( 'placeholder' => __( 'What just happened?', 'postsocket' ) ) ),
			),
		)
	);

	register_taxonomy(
		LIVEBLOG_TAX,
		ENTRY_TYPE,
		array(
			'labels'             => array(
				'name'          => __( 'Live Blogs', 'postsocket' ),
				'singular_name' => __( 'Live Blog', 'postsocket' ),
				'add_new_item'  => __( 'Add New Live Blog', 'postsocket' ),
				'edit_item'     => __( 'Edit Live Blog', 'postsocket' ),
				'search_items'  => __( 'Search Live Blogs', 'postsocket' ),
				'not_found'     => __( 'No live blogs found.', 'postsocket' ),
				'menu_name'     => __( 'Live Blogs', 'postsocket' ),
			),
			'description'        => __( 'Live coverage: a stream of entries shown wherever its Live Feed block is placed.', 'postsocket' ),
			'public'             => false,
			'publicly_queryable' => false,
			'hierarchical'       => false,
			'show_ui'            => false,
			'show_in_rest'       => true,
			'rest_base'          => LIVEBLOG_REST_BASE,
			'show_admin_column'  => false,
			'show_tagcloud'      => false,
			'rewrite'            => false,
			'query_var'          => false,
			'capabilities'       => array(
				'manage_terms' => 'edit_others_posts',
				'edit_terms'   => 'edit_others_posts',
				'delete_terms' => 'edit_others_posts',
				'assign_terms' => 'edit_posts',
			),
		)
	);

	$term_meta = array(
		'single'        => true,
		'show_in_rest'  => true,
		'auth_callback' => static fn( bool $allowed, string $key, int $term_id ) => current_user_can( 'edit_term', $term_id ),
	);
	register_term_meta(
		LIVEBLOG_TAX,
		META_STATE,
		$term_meta + array(
			'type'              => 'string',
			'default'           => 'live',
			'sanitize_callback' => static fn( $value ) => 'ended' === $value ? 'ended' : 'live',
		)
	);
	register_term_meta(
		LIVEBLOG_TAX,
		META_STARTED,
		$term_meta + array(
			'type'    => 'integer',
			'default' => 0,
		)
	);
	register_term_meta(
		LIVEBLOG_TAX,
		META_ENDED,
		$term_meta + array(
			'type'    => 'integer',
			'default' => 0,
		)
	);
	register_term_meta(
		LIVEBLOG_TAX,
		META_HOME,
		array(
			'single'        => true,
			'type'          => 'integer',
			'default'       => 0,
			'show_in_rest'  => array( 'schema' => array( 'readonly' => true ) ),
			'auth_callback' => '__return_false',
		)
	);

	register_post_meta(
		ENTRY_TYPE,
		META_PINNED,
		array(
			'single'        => true,
			'show_in_rest'  => true,
			'type'          => 'boolean',
			'default'       => false,
			'auth_callback' => static fn( bool $allowed, string $key, int $post_id ) => current_user_can( 'edit_post', $post_id ),
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\register_content_types' );

/**
 * The live blog term with this id, or null.
 *
 * @since 0.1.0
 *
 * @param int $liveblog_id Term id.
 * @return \WP_Term|null
 */
function get_liveblog( int $liveblog_id ): ?\WP_Term {
	if ( $liveblog_id <= 0 ) {
		return null;
	}
	$term = get_term( $liveblog_id, LIVEBLOG_TAX );
	return $term instanceof \WP_Term ? $term : null;
}

/**
 * Whether an id names a live blog.
 *
 * @since 0.1.0
 *
 * @param int $liveblog_id Term id.
 * @return bool
 */
function is_liveblog( int $liveblog_id ): bool {
	return null !== get_liveblog( $liveblog_id );
}

/**
 * Whether a post is an entry.
 *
 * @since 0.1.0
 *
 * @param int|\WP_Post|null $post Post or id.
 * @return bool
 */
function is_entry( int|\WP_Post|null $post ): bool {
	$post = get_post( $post );
	return $post instanceof \WP_Post && ENTRY_TYPE === $post->post_type;
}

/**
 * The live blog an entry belongs to, or null.
 *
 * @since 0.1.0
 *
 * @param int|\WP_Post|null $entry Entry or id.
 * @return \WP_Term|null
 */
function liveblog_of( int|\WP_Post|null $entry ): ?\WP_Term {
	$entry = get_post( $entry );
	if ( ! $entry instanceof \WP_Post ) {
		return null;
	}
	$terms = get_the_terms( $entry, LIVEBLOG_TAX );
	return is_array( $terms ) && $terms ? $terms[0] : null;
}

/**
 * The id of the live blog an entry belongs to, or 0.
 *
 * @since 0.1.0
 *
 * @param int|\WP_Post|null $entry Entry or id.
 * @return int
 */
function liveblog_id_of( int|\WP_Post|null $entry ): int {
	return (int) ( liveblog_of( $entry )->term_id ?? 0 );
}

/**
 * The live blog's state, `live` or `ended`.
 *
 * @since 0.1.0
 *
 * @param int $liveblog_id Live blog id.
 * @return string
 */
function liveblog_state( int $liveblog_id ): string {
	return 'ended' === get_term_meta( $liveblog_id, META_STATE, true ) ? 'ended' : 'live';
}

/**
 * Whether an entry is pinned.
 *
 * @since 0.1.0
 *
 * @param int $entry_id Entry id.
 * @return bool
 */
function is_pinned( int $entry_id ): bool {
	return (bool) get_post_meta( $entry_id, META_PINNED, true );
}

/**
 * The tax query that limits entries to one live blog.
 *
 * @since 0.1.0
 *
 * @param int $liveblog_id Live blog id.
 * @return array
 */
function liveblog_tax_query( int $liveblog_id ): array {
	return array(
		array(
			'taxonomy'         => LIVEBLOG_TAX,
			'field'            => 'term_id',
			'terms'            => $liveblog_id,
			'include_children' => false,
		),
	);
}

/**
 * A query over a live blog's published entries, newest first, without pagination counts.
 *
 * @since 0.1.0
 *
 * @param int   $liveblog_id Live blog id.
 * @param array $args        Overrides for WP_Query.
 * @return \WP_Query
 */
function entries_query( int $liveblog_id, array $args = array() ): \WP_Query {
	return new \WP_Query(
		array_merge(
			array(
				'post_type'           => ENTRY_TYPE,
				'tax_query'           => liveblog_tax_query( $liveblog_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'post_status'         => 'publish',
				'posts_per_page'      => settings()['entries_per_page'],
				'orderby'             => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			),
			$args
		)
	);
}

/**
 * Every entry of a live blog in the given statuses, as ids.
 *
 * @since 0.1.0
 *
 * @param int      $liveblog_id Live blog id.
 * @param string[] $statuses    Post statuses.
 * @return int[]
 */
function entry_ids( int $liveblog_id, array $statuses ): array {
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'      => ENTRY_TYPE,
				'tax_query'      => liveblog_tax_query( $liveblog_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'post_status'    => $statuses,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		)
	);
}

/**
 * The live blog ids of the Live Feed blocks in some post content.
 *
 * @since 0.1.0
 *
 * @param string $content Post content.
 * @return int[]
 */
function feed_liveblogs( string $content ): array {
	if ( ! str_contains( $content, '<!-- wp:' . FEED_BLOCK ) ) {
		return array();
	}
	$ids  = array();
	$walk = static function ( array $blocks ) use ( &$ids, &$walk ): void {
		foreach ( $blocks as $block ) {
			if ( FEED_BLOCK === ( $block['blockName'] ?? '' ) && (int) ( $block['attrs']['liveblog'] ?? 0 ) > 0 ) {
				$ids[] = (int) $block['attrs']['liveblog'];
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$walk( $block['innerBlocks'] );
			}
		}
	};
	$walk( parse_blocks( $content ) );
	return array_values( array_unique( $ids ) );
}

/**
 * Whether a post is published and holds a Live Feed block for the live blog.
 *
 * @since 0.1.0
 *
 * @param int $post_id     Post id.
 * @param int $liveblog_id Live blog id.
 * @return bool
 */
function hosts_liveblog( int $post_id, int $liveblog_id ): bool {
	$post = get_post( $post_id );
	return $post instanceof \WP_Post
		&& 'publish' === $post->post_status
		&& in_array( $liveblog_id, feed_liveblogs( $post->post_content ), true );
}

/**
 * The published post a live blog lives on, or 0.
 *
 * @since 0.1.0
 *
 * @param int $liveblog_id Live blog id.
 * @return int
 */
function home_of( int $liveblog_id ): int {
	$home = (int) get_term_meta( $liveblog_id, META_HOME, true );
	return $home > 0 && 'publish' === get_post_status( $home ) ? $home : 0;
}

/**
 * Point the live blogs a post shows at it when they have no home, and let go of those it no longer shows.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $post The saved post.
 * @return void
 */
function sync_homes( \WP_Post $post ): void {
	if ( ENTRY_TYPE === $post->post_type || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
		return;
	}
	$shown = 'publish' === $post->post_status ? feed_liveblogs( $post->post_content ) : array();
	foreach ( $shown as $liveblog_id ) {
		if ( ! is_liveblog( $liveblog_id ) ) {
			continue;
		}
		$home = (int) get_term_meta( $liveblog_id, META_HOME, true );
		if ( $home !== $post->ID && ! hosts_liveblog( $home, $liveblog_id ) ) {
			update_term_meta( $liveblog_id, META_HOME, $post->ID );
		}
	}
	foreach ( homed_on( $post->ID ) as $liveblog_id ) {
		if ( ! in_array( $liveblog_id, $shown, true ) ) {
			delete_term_meta( $liveblog_id, META_HOME );
		}
	}
}
add_action( 'wp_after_insert_post', static fn( int $post_id, \WP_Post $post ) => sync_homes( $post ), 10, 2 );
add_action(
	'before_delete_post',
	static function ( int $post_id ): void {
		foreach ( homed_on( $post_id ) as $liveblog_id ) {
			delete_term_meta( $liveblog_id, META_HOME );
		}
	}
);

/**
 * The live blogs whose home is a post.
 *
 * @since 0.1.0
 *
 * @param int $post_id Post id.
 * @return int[]
 */
function homed_on( int $post_id ): array {
	return array_map(
		'intval',
		get_terms(
			array(
				'taxonomy'   => LIVEBLOG_TAX,
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_key'   => META_HOME, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $post_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		)
	);
}

/**
 * An entry's public link: its live blog's home with the entry anchor.
 *
 * @since 0.1.0
 *
 * @param int $entry_id Entry id.
 * @return string Empty when the live blog has no published home.
 */
function entry_link( int $entry_id ): string {
	$home = home_of( liveblog_id_of( $entry_id ) );
	return $home > 0 ? get_permalink( $home ) . '#postsocket-' . $entry_id : '';
}

// Entries have no page of their own: every link to one points at its live blog's home.
add_filter(
	'post_type_link',
	static function ( string $link, \WP_Post $post ): string {
		if ( ENTRY_TYPE !== $post->post_type ) {
			return $link;
		}
		$anchor = entry_link( $post->ID );
		return '' !== $anchor ? $anchor : $link;
	},
	10,
	2
);

/**
 * Refuse an entry with more than one live blog, an unknown one, or none when it is being published.
 *
 * @since 0.1.0
 *
 * @param \stdClass        $prepared Post fields about to be inserted.
 * @param \WP_REST_Request $request  The request.
 * @return \stdClass|\WP_Error
 */
function prepare_entry( \stdClass $prepared, \WP_REST_Request $request ): \stdClass|\WP_Error {
	if ( $request->has_param( LIVEBLOG_REST_BASE ) ) {
		$ids = array_values( array_filter( array_map( 'intval', (array) $request->get_param( LIVEBLOG_REST_BASE ) ) ) );
		if ( count( $ids ) > 1 ) {
			return new \WP_Error( 'postsocket_one_liveblog', __( 'An entry belongs to one live blog.', 'postsocket' ), array( 'status' => 400 ) );
		}
		if ( $ids && ! is_liveblog( $ids[0] ) ) {
			return new \WP_Error( 'postsocket_not_a_liveblog', __( 'That live blog does not exist.', 'postsocket' ), array( 'status' => 400 ) );
		}
		$liveblog = $ids[0] ?? 0;
	} else {
		$liveblog = isset( $prepared->ID ) ? liveblog_id_of( (int) $prepared->ID ) : 0;
	}

	$status = $prepared->post_status ?? ( isset( $prepared->ID ) ? get_post_status( $prepared->ID ) : 'draft' );
	if ( in_array( $status, array( 'publish', 'future' ), true ) && $liveblog <= 0 ) {
		return new \WP_Error( 'postsocket_no_liveblog', __( 'Choose the live blog this entry belongs to before publishing it.', 'postsocket' ), array( 'status' => 400 ) );
	}
	return $prepared;
}
add_filter( 'rest_pre_insert_' . ENTRY_TYPE, __NAMESPACE__ . '\prepare_entry', 10, 2 );

// A new entry opened from the desk starts on that live blog.
add_action(
	'save_post_' . ENTRY_TYPE,
	static function ( int $post_id, \WP_Post $post ): void {
		if ( 'auto-draft' !== $post->post_status || ! is_admin() ) {
			return;
		}
		$liveblog = isset( $_GET['liveblog'] ) ? absint( $_GET['liveblog'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $liveblog > 0 && is_liveblog( $liveblog ) ) {
			wp_set_object_terms( $post_id, $liveblog, LIVEBLOG_TAX );
		}
	},
	10,
	2
);

// A restored entry comes back published when it was, as long as it still has a live blog.
add_filter(
	'wp_untrash_post_status',
	static function ( string $new_status, int $post_id, string $previous_status ): string {
		return is_entry( $post_id ) && 'publish' === $previous_status && liveblog_id_of( $post_id ) > 0 ? 'publish' : $new_status;
	},
	10,
	3
);

// Deleting a live blog trashes its entries first, which tells open pages they are gone.
add_action(
	'pre_delete_term',
	static function ( int $term_id, string $taxonomy ): void {
		if ( LIVEBLOG_TAX !== $taxonomy ) {
			return;
		}
		foreach ( entry_ids( $term_id, array( 'publish', 'draft', 'pending', 'future', 'private' ) ) as $entry_id ) {
			wp_trash_post( $entry_id );
		}
	},
	10,
	2
);

/**
 * The post type "Create page" starts a live blog's home in.
 *
 * @since 0.1.0
 *
 * @return string
 */
function host_post_type(): string {
	/**
	 * Filters the post type a new live blog home is created in.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type `post` by default.
	 */
	$type = (string) apply_filters( 'postsocket_host_post_type', 'post' );
	return post_type_exists( $type ) ? $type : 'post';
}

/**
 * The editor URL for a new post that shows a live blog.
 *
 * @since 0.1.0
 *
 * @param int $liveblog_id Live blog id.
 * @return string
 */
function create_home_url( int $liveblog_id ): string {
	return add_query_arg(
		array(
			'post_type'           => host_post_type(),
			'postsocket_liveblog' => $liveblog_id,
		),
		admin_url( 'post-new.php' )
	);
}

/**
 * The live blog a new post was opened for, or null.
 *
 * @since 0.1.0
 *
 * @return \WP_Term|null
 */
function requested_home_liveblog(): ?\WP_Term {
	$id = isset( $_GET['postsocket_liveblog'] ) ? absint( $_GET['postsocket_liveblog'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return get_liveblog( $id );
}

// A post opened from "Create page" starts with the live blog's name and its Live Feed block.
add_filter(
	'default_content',
	static function ( string $content ): string {
		$liveblog = requested_home_liveblog();
		if ( ! $liveblog ) {
			return $content;
		}
		return "<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->\n\n" . serialize_block(
			array(
				'blockName'    => FEED_BLOCK,
				'attrs'        => array( 'liveblog' => $liveblog->term_id ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}
);
add_filter(
	'default_title',
	static function ( string $title ): string {
		$liveblog = requested_home_liveblog();
		return $liveblog ? $liveblog->name : $title;
	}
);

/**
 * The editor URL for a new entry on a live blog.
 *
 * @since 0.1.0
 *
 * @param int $liveblog_id Live blog id.
 * @return string
 */
function add_entry_url( int $liveblog_id ): string {
	return add_query_arg(
		array(
			'post_type' => ENTRY_TYPE,
			'liveblog'  => $liveblog_id,
		),
		admin_url( 'post-new.php' )
	);
}

/**
 * The Live Desk's URL, on one live blog when given.
 *
 * @since 0.1.0
 *
 * @param int $liveblog_id Live blog id, or 0 for the list of live blogs.
 * @return string
 */
function desk_url( int $liveblog_id = 0 ): string {
	$args = array( 'page' => DESK_PAGE );
	if ( $liveblog_id > 0 ) {
		$args['liveblog'] = $liveblog_id;
	}
	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * A one-line summary of an entry: its title, or the start of its text, and nothing for one not saved yet.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $entry The entry.
 * @return string
 */
function entry_summary( \WP_Post $entry ): string {
	if ( 'auto-draft' === $entry->post_status ) {
		return '';
	}
	$title = trim( $entry->post_title );
	if ( '' !== $title ) {
		return $title;
	}
	return wp_html_excerpt( wp_strip_all_tags( excerpt_remove_blocks( $entry->post_content ), true ), 80, '…' );
}

/**
 * How long an edit lock holds after the editor last renewed it, in seconds.
 *
 * @since 0.1.0
 *
 * @return int
 */
function lock_window(): int {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter.
	return (int) apply_filters( 'wp_check_post_lock_window', 150 );
}

/**
 * The holder of an edit lock value, `time:user`, and how long it still holds, or null once it has lapsed.
 *
 * @since 0.1.0
 *
 * @param string $value The lock as core stores it.
 * @return array{user: int, expires_in: int}|null
 */
function read_lock( string $value ): ?array {
	$lock = explode( ':', $value );
	$user = (int) ( $lock[1] ?? 0 );
	$left = (int) $lock[0] + lock_window() - time();
	return $user > 0 && $left > 0 ? array(
		'user'       => $user,
		'expires_in' => $left,
	) : null;
}

/**
 * Who has an entry open in the editor, from its edit lock, or null when nobody does.
 *
 * @since 0.1.0
 *
 * @param int $entry_id Entry id.
 * @return array{user: int, expires_in: int}|null
 */
function editing_lock( int $entry_id ): ?array {
	return read_lock( (string) get_post_meta( $entry_id, '_edit_lock', true ) );
}

/**
 * The new entries, not saved yet, that an editor has open.
 *
 * @since 0.1.0
 *
 * @return int[]
 */
function open_new_entries(): array {
	$ids = get_posts(
		array(
			'post_type'      => ENTRY_TYPE,
			'post_status'    => 'auto-draft',
			'date_query'     => array( array( 'after' => '1 day ago' ) ),
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	return array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => null !== editing_lock( $id ) ) );
}

// The entries collection takes `postsocket_open` to list new entries that are open in the editor, and `postsocket_orphaned` to list only entries with no live blog.
add_filter(
	'rest_' . ENTRY_TYPE . '_collection_params',
	static function ( array $params ): array {
		$params['postsocket_open']     = array(
			'description' => __( 'Include new entries that are open in the editor and not saved yet.', 'postsocket' ),
			'type'        => 'boolean',
			'default'     => false,
		);
		$params['postsocket_orphaned'] = array(
			'description' => __( 'Limit the entries to those that belong to no live blog.', 'postsocket' ),
			'type'        => 'boolean',
			'default'     => false,
		);
		return $params;
	}
);
add_filter(
	'rest_' . ENTRY_TYPE . '_query',
	static function ( array $args, \WP_REST_Request $request ): array {
		if ( $request['postsocket_open'] ) {
			$args['post_status']     = array_merge( (array) ( $args['post_status'] ?? array() ), array( 'auto-draft' ) );
			$args['postsocket_open'] = open_new_entries();
		}
		if ( $request['postsocket_orphaned'] ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The only way to ask for entries without a live blog.
			$args['tax_query'] = array(
				array(
					'taxonomy' => LIVEBLOG_TAX,
					'operator' => 'NOT EXISTS',
				),
			);
		}
		return $args;
	},
	10,
	2
);
add_filter(
	'posts_where',
	static function ( string $where, \WP_Query $query ): string {
		$open = $query->get( 'postsocket_open' );
		if ( ! is_array( $open ) ) {
			return $where;
		}
		global $wpdb;
		$ids = implode( ',', array_map( 'intval', $open ) );
		return $where . " AND ( {$wpdb->posts}.post_status <> 'auto-draft'" . ( '' !== $ids ? " OR {$wpdb->posts}.ID IN ( {$ids} )" : '' ) . ' )';
	},
	10,
	2
);

/**
 * Restore a trashed entry to the status it was trashed in; one with no live blog comes back as a draft, in the live blog the request names.
 *
 * @since 0.1.0
 *
 * @param \WP_REST_Request $request The request.
 * @return \WP_REST_Response|\WP_Error
 */
function handle_restore( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
	$id       = (int) $request['id'];
	$liveblog = (int) $request['liveblog'];
	if ( $liveblog > 0 && ! is_liveblog( $liveblog ) ) {
		return new \WP_Error( 'postsocket_no_liveblog', __( 'That live blog does not exist.', 'postsocket' ), array( 'status' => 400 ) );
	}
	if ( 'trash' !== get_post_status( $id ) || ! wp_untrash_post( $id ) ) {
		return new \WP_Error( 'postsocket_not_trashed', __( 'That entry is not in the trash.', 'postsocket' ), array( 'status' => 409 ) );
	}
	if ( $liveblog > 0 && 0 === liveblog_id_of( $id ) ) {
		wp_set_object_terms( $id, $liveblog, LIVEBLOG_TAX );
	}
	return rest_ensure_response(
		array(
			'id'     => $id,
			'status' => get_post_status( $id ),
		)
	);
}

// The desk's read-outs: a live blog's home and newest entry, an entry's author, summary and who has it open; and the restore route.
add_action(
	'rest_api_init',
	static function (): void {
		register_rest_field(
			LIVEBLOG_TAX,
			'postsocket',
			array(
				'get_callback' => static function ( array $term ): array {
					$home   = home_of( (int) $term['id'] );
					$latest = entries_query(
						(int) $term['id'],
						array(
							'posts_per_page'         => 1,
							'update_post_meta_cache' => false,
							'update_post_term_cache' => false,
						)
					)->posts;
					return array(
						'home'   => $home > 0 ? array(
							'id'    => $home,
							'title' => get_the_title( $home ),
							'link'  => get_permalink( $home ),
						) : null,
						'latest' => $latest ? mysql2date( 'c', $latest[0]->post_date_gmt, false ) : null,
					);
				},
				'schema'       => array(
					'description' => __( 'The live blog\'s home post and the time of its newest published entry.', 'postsocket' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			)
		);
		register_rest_field(
			ENTRY_TYPE,
			'postsocket',
			array(
				'get_callback' => static function ( array $post ): array {
					$entry = get_post( (int) $post['id'] );
					$lock  = $entry ? editing_lock( $entry->ID ) : null;
					return array(
						'author'  => $entry ? get_the_author_meta( 'display_name', (int) $entry->post_author ) : '',
						'summary' => $entry ? entry_summary( $entry ) : '',
						'editing' => $lock ? array(
							'name'       => get_the_author_meta( 'display_name', $lock['user'] ),
							'expires_in' => $lock['expires_in'],
						) : null,
					);
				},
				'schema'       => array(
					'description' => __( 'The entry\'s author name, a one-line summary, and who has it open in the editor.', 'postsocket' ),
					'type'        => 'object',
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
			)
		);
		register_rest_route(
			REST_NS,
			'/entry/(?P<id>\d+)/restore',
			array(
				'methods'             => 'POST',
				'callback'            => __NAMESPACE__ . '\handle_restore',
				'permission_callback' => static fn( \WP_REST_Request $request ): bool => is_entry( (int) $request['id'] ) && current_user_can( 'delete_post', (int) $request['id'] ),
				'args'                => array(
					'id'       => array(
						'type'     => 'integer',
						'required' => true,
					),
					'liveblog' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
	}
);

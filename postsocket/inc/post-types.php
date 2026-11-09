<?php
/**
 * The two post types and what ties them together.
 *
 * - `postsocket_liveblog` is public; `postsocket_entry` is non-public and parented to a live blog by `post_parent`.
 * - REST exposes the parent as `liveblog`; publishing an entry without one is refused.
 * - An entry's link is its live blog's anchor; trash, untrash and delete cascade from a live blog to its entries.
 * - The list tables get a Live blog column and filter (entries) and an Entries column (live blogs).
 *
 * @package WPSignal\Extensions\PostSocket
 */

namespace WPSignal\Extensions\PostSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register both post types and their meta.
 *
 * @since 0.1.0
 *
 * @return void
 */
function register_post_types(): void {
	register_post_type(
		LIVEBLOG_TYPE,
		array(
			'labels'        => array(
				'name'               => __( 'Live Blogs', 'postsocket' ),
				'singular_name'      => __( 'Live Blog', 'postsocket' ),
				'add_new'            => __( 'Add New', 'postsocket' ),
				'add_new_item'       => __( 'Add New Live Blog', 'postsocket' ),
				'edit_item'          => __( 'Edit Live Blog', 'postsocket' ),
				'new_item'           => __( 'New Live Blog', 'postsocket' ),
				'view_item'          => __( 'View Live Blog', 'postsocket' ),
				'search_items'       => __( 'Search Live Blogs', 'postsocket' ),
				'not_found'          => __( 'No live blogs found.', 'postsocket' ),
				'not_found_in_trash' => __( 'No live blogs found in Trash.', 'postsocket' ),
				'all_items'          => __( 'All Live Blogs', 'postsocket' ),
				'menu_name'          => __( 'Live Blogs', 'postsocket' ),
			),
			'description'   => __( 'Live coverage that updates in every reader\'s browser as entries are published.', 'postsocket' ),
			'public'        => true,
			'show_in_rest'  => true,
			'has_archive'   => false,
			'menu_position' => 5,
			'menu_icon'     => 'dashicons-megaphone',
			'rewrite'       => array(
				'slug'       => rewrite_slug(),
				'with_front' => false,
			),
			'supports'      => array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'revisions', 'custom-fields' ),
			'template'      => array(
				array( 'core/paragraph', array( 'placeholder' => __( 'Introduce the coverage. Entries appear below this as you publish them.', 'postsocket' ) ) ),
			),
		)
	);

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
			'description'         => __( 'One update on a live blog, written in the editor and shown on the live blog\'s page.', 'postsocket' ),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_rest'        => true,
			'show_in_menu'        => 'edit.php?post_type=' . LIVEBLOG_TYPE,
			'show_in_nav_menus'   => false,
			'rewrite'             => false,
			'supports'            => array( 'title', 'editor', 'author', 'thumbnail', 'revisions', 'custom-fields' ),
			'template'            => array(
				array( 'core/paragraph', array( 'placeholder' => __( 'What just happened?', 'postsocket' ) ) ),
			),
		)
	);

	$editable = array(
		'single'        => true,
		'show_in_rest'  => true,
		'auth_callback' => static fn( bool $allowed, string $key, int $post_id ) => current_user_can( 'edit_post', $post_id ),
	);
	register_post_meta(
		LIVEBLOG_TYPE,
		META_STATE,
		$editable + array(
			'type'              => 'string',
			'default'           => 'live',
			'sanitize_callback' => static fn( $value ) => 'ended' === $value ? 'ended' : 'live',
		)
	);
	register_post_meta(
		LIVEBLOG_TYPE,
		META_STARTED,
		$editable + array(
			'type'    => 'integer',
			'default' => 0,
		)
	);
	register_post_meta(
		LIVEBLOG_TYPE,
		META_ENDED,
		$editable + array(
			'type'    => 'integer',
			'default' => 0,
		)
	);
	register_post_meta(
		ENTRY_TYPE,
		META_PINNED,
		$editable + array(
			'type'    => 'boolean',
			'default' => false,
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\register_post_types' );

// A queued rewrite flush after the slug setting changed.
add_action(
	'init',
	static function (): void {
		if ( get_option( OPTION . '_flush' ) ) {
			delete_option( OPTION . '_flush' );
			flush_rewrite_rules();
		}
	},
	20
);

/**
 * Whether a post is a live blog.
 *
 * @since 0.1.0
 *
 * @param int|\WP_Post|null $post Post or id.
 * @return bool
 */
function is_liveblog( int|\WP_Post|null $post ): bool {
	$post = get_post( $post );
	return $post instanceof \WP_Post && LIVEBLOG_TYPE === $post->post_type;
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
 * @return \WP_Post|null
 */
function liveblog_of( int|\WP_Post|null $entry ): ?\WP_Post {
	$entry = get_post( $entry );
	if ( ! $entry instanceof \WP_Post || $entry->post_parent <= 0 ) {
		return null;
	}
	$parent = get_post( $entry->post_parent );
	return is_liveblog( $parent ) ? $parent : null;
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
	return 'ended' === get_post_meta( $liveblog_id, META_STATE, true ) ? 'ended' : 'live';
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
				'post_type'              => ENTRY_TYPE,
				'post_parent'            => $liveblog_id,
				'post_status'            => 'publish',
				'posts_per_page'         => settings()['entries_per_page'],
				'orderby'                => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
			),
			$args
		)
	);
}

/**
 * How many published entries a live blog has.
 *
 * @since 0.1.0
 *
 * @param int $liveblog_id Live blog id.
 * @return int
 */
function entry_count( int $liveblog_id ): int {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d AND post_status = 'publish'",
			ENTRY_TYPE,
			$liveblog_id
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
				'post_parent'    => $liveblog_id,
				'post_status'    => $statuses,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		)
	);
}

/**
 * An entry's public link: its live blog's permalink with the entry anchor.
 *
 * @since 0.1.0
 *
 * @param int $entry_id Entry id.
 * @return string Empty when the entry has no published live blog.
 */
function entry_link( int $entry_id ): string {
	$liveblog = liveblog_of( $entry_id );
	if ( ! $liveblog ) {
		return '';
	}
	return get_permalink( $liveblog ) . '#postsocket-' . $entry_id;
}

// Entries have no page of their own: every link to one points at its live blog's anchor.
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
 * Set an entry's live blog from the REST `liveblog` field and refuse publishing without one.
 *
 * @since 0.1.0
 *
 * @param \stdClass        $prepared Post fields about to be inserted.
 * @param \WP_REST_Request $request  The request.
 * @return \stdClass|\WP_Error
 */
function prepare_entry( \stdClass $prepared, \WP_REST_Request $request ): \stdClass|\WP_Error {
	if ( $request->has_param( 'liveblog' ) ) {
		$liveblog = (int) $request->get_param( 'liveblog' );
		if ( 0 !== $liveblog && ! is_liveblog( $liveblog ) ) {
			return new \WP_Error( 'postsocket_not_a_liveblog', __( 'That post is not a live blog.', 'postsocket' ), array( 'status' => 400 ) );
		}
		$prepared->post_parent = $liveblog;
	}

	$status = $prepared->post_status ?? ( isset( $prepared->ID ) ? get_post_status( $prepared->ID ) : 'draft' );
	$parent = $prepared->post_parent ?? ( isset( $prepared->ID ) ? (int) get_post_field( 'post_parent', $prepared->ID ) : 0 );
	if ( in_array( $status, array( 'publish', 'future' ), true ) && $parent <= 0 ) {
		return new \WP_Error( 'postsocket_no_liveblog', __( 'Choose the live blog this entry belongs to before publishing it.', 'postsocket' ), array( 'status' => 400 ) );
	}
	return $prepared;
}
add_filter( 'rest_pre_insert_' . ENTRY_TYPE, __NAMESPACE__ . '\prepare_entry', 10, 2 );

// The `liveblog` REST field on entries reads `post_parent`; writes go through `prepare_entry()`.
add_action(
	'rest_api_init',
	static function (): void {
		register_rest_field(
			ENTRY_TYPE,
			'liveblog',
			array(
				'get_callback' => static fn( array $post ) => (int) get_post_field( 'post_parent', $post['id'] ),
				'schema'       => array(
					'description' => __( 'The live blog this entry belongs to.', 'postsocket' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit', 'embed' ),
				),
			)
		);
	}
);

// `?liveblog={id}` on the entries collection filters by live blog.
add_filter(
	'rest_' . ENTRY_TYPE . '_collection_params',
	static function ( array $params ): array {
		$params['liveblog'] = array(
			'description' => __( 'Limit results to entries of this live blog.', 'postsocket' ),
			'type'        => 'integer',
		);
		return $params;
	}
);
add_filter(
	'rest_' . ENTRY_TYPE . '_query',
	static function ( array $args, \WP_REST_Request $request ): array {
		if ( $request->has_param( 'liveblog' ) ) {
			$args['post_parent'] = (int) $request->get_param( 'liveblog' );
		}
		return $args;
	},
	10,
	2
);

// Trash, untrash and delete cascade from a live blog to its entries.
add_action(
	'wp_trash_post',
	static function ( int $post_id ): void {
		if ( ! is_liveblog( $post_id ) ) {
			return;
		}
		foreach ( entry_ids( $post_id, array( 'publish', 'draft', 'pending', 'future', 'private' ) ) as $entry_id ) {
			update_post_meta( $entry_id, '_postsocket_trashed_with', $post_id );
			wp_trash_post( $entry_id );
		}
	}
);
add_action(
	'untrashed_post',
	static function ( int $post_id ): void {
		if ( ! is_liveblog( $post_id ) ) {
			return;
		}
		foreach ( entry_ids( $post_id, array( 'trash' ) ) as $entry_id ) {
			if ( (int) get_post_meta( $entry_id, '_postsocket_trashed_with', true ) === $post_id ) {
				delete_post_meta( $entry_id, '_postsocket_trashed_with' );
				wp_untrash_post( $entry_id );
			}
		}
	}
);
add_action(
	'before_delete_post',
	static function ( int $post_id ): void {
		if ( ! is_liveblog( $post_id ) ) {
			return;
		}
		foreach ( entry_ids( $post_id, array( 'any', 'trash' ) ) as $entry_id ) {
			wp_delete_post( $entry_id, true );
		}
	}
);

// A restored entry comes back as a draft unless its live blog is published.
add_filter(
	'wp_untrash_post_status',
	static function ( string $new_status, int $post_id, string $previous_status ): string {
		if ( ! is_entry( $post_id ) || 'publish' !== $previous_status ) {
			return $new_status;
		}
		$liveblog = liveblog_of( $post_id );
		return $liveblog && 'publish' === $liveblog->post_status ? 'publish' : 'draft';
	},
	10,
	3
);

// Entries list table: a Live blog column and filter.
add_filter(
	'manage_' . ENTRY_TYPE . '_posts_columns',
	static function ( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['liveblog'] = __( 'Live blog', 'postsocket' );
			}
		}
		return $out;
	}
);
add_action(
	'manage_' . ENTRY_TYPE . '_posts_custom_column',
	static function ( string $column, int $post_id ): void {
		if ( 'liveblog' !== $column ) {
			return;
		}
		$liveblog = liveblog_of( $post_id );
		if ( ! $liveblog ) {
			echo '<span aria-hidden="true">&#8212;</span>';
			return;
		}
		printf(
			'<a href="%s">%s</a>',
			esc_url(
				add_query_arg(
					array(
						'post_type' => ENTRY_TYPE,
						'liveblog'  => $liveblog->ID,
					),
					admin_url( 'edit.php' )
				)
			),
			esc_html( get_the_title( $liveblog ) )
		);
	},
	10,
	2
);
add_action(
	'restrict_manage_posts',
	static function ( string $post_type ): void {
		if ( ENTRY_TYPE !== $post_type ) {
			return;
		}
		$current = isset( $_GET['liveblog'] ) ? absint( $_GET['liveblog'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$blogs   = get_posts(
			array(
				'post_type'      => LIVEBLOG_TYPE,
				'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		echo '<select name="liveblog"><option value="0">' . esc_html__( 'All live blogs', 'postsocket' ) . '</option>';
		foreach ( $blogs as $blog ) {
			printf( '<option value="%d"%s>%s</option>', (int) $blog->ID, selected( $current, $blog->ID, false ), esc_html( get_the_title( $blog ) ) );
		}
		echo '</select>';
	}
);
add_action(
	'pre_get_posts',
	static function ( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || ENTRY_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		$liveblog = isset( $_GET['liveblog'] ) ? absint( $_GET['liveblog'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $liveblog > 0 ) {
			$query->set( 'post_parent', $liveblog );
		}
	}
);

// Live blogs list table: an Entries column and an "Add entry" row action.
add_filter(
	'manage_' . LIVEBLOG_TYPE . '_posts_columns',
	static function ( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['entries'] = __( 'Entries', 'postsocket' );
			}
		}
		return $out;
	}
);
add_action(
	'manage_' . LIVEBLOG_TYPE . '_posts_custom_column',
	static function ( string $column, int $post_id ): void {
		if ( 'entries' !== $column ) {
			return;
		}
		$count = entry_count( $post_id );
		printf(
			'<a href="%s">%s</a> · <span class="postsocket-state postsocket-state--%s">%s</span>',
			esc_url(
				add_query_arg(
					array(
						'post_type' => ENTRY_TYPE,
						'liveblog'  => $post_id,
					),
					admin_url( 'edit.php' )
				)
			),
			esc_html( (string) $count ),
			esc_attr( liveblog_state( $post_id ) ),
			'ended' === liveblog_state( $post_id ) ? esc_html__( 'Ended', 'postsocket' ) : esc_html__( 'Live', 'postsocket' )
		);
	},
	10,
	2
);
add_filter(
	'post_row_actions',
	static function ( array $actions, \WP_Post $post ): array {
		if ( LIVEBLOG_TYPE === $post->post_type && current_user_can( 'edit_posts' ) ) {
			$actions['postsocket_add_entry'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_entry_url( $post->ID ) ),
				esc_html__( 'Add entry', 'postsocket' )
			);
		}
		return $actions;
	},
	10,
	2
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

// A new entry opened from a live blog starts with that parent.
add_filter(
	'wp_insert_post_data',
	static function ( array $data ): array {
		if ( ENTRY_TYPE !== ( $data['post_type'] ?? '' ) || (int) ( $data['post_parent'] ?? 0 ) > 0 ) {
			return $data;
		}
		$liveblog = isset( $_GET['liveblog'] ) ? absint( $_GET['liveblog'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $liveblog > 0 && 'auto-draft' === ( $data['post_status'] ?? '' ) && is_liveblog( $liveblog ) ) {
			$data['post_parent'] = $liveblog;
		}
		return $data;
	}
);

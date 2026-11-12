<?php
/**
 * The five events, registered as WordSocket triggers.
 *
 * - `postsocket.entry.updated` and `postsocket.entry.removed` on `postsocket:liveblog:{id}`, `{id}` the live blog's term id.
 * - An entry moved to another live blog is removed from the old channel and published on the new one.
 * - `postsocket.liveblog.state` on the same channel when a live blog's state term meta changes.
 * - `postsocket.post.updated` on `postsocket:post:{id}` for living posts: the enabled types and every post showing a live blog, once as the request that saved the post or changed its meta or terms ends.
 * - `postsocket.desk.changed` on `postsocket-desk:liveblog:{id}`, for staff: an entry saved in any status, trashed, restored, deleted, moved, or opened or closed in the editor.
 * - A content event publishes only when the rendered content changed; a removal or a state change at most once per post per request.
 *
 * @package WPSignal\Extensions\PostSocket
 */

namespace WPSignal\Extensions\PostSocket;

use WPSignal\WPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a post is being deleted in this request, so the meta deletes on the way out publish nothing.
 *
 * @since 0.1.0
 *
 * @param int       $post_id Post id.
 * @param bool|null $set     Mark or unmark; null reads.
 * @return bool
 */
function deleting( int $post_id, ?bool $set = null ): bool {
	static $ids = array();
	if ( true === $set ) {
		$ids[ $post_id ] = true;
	} elseif ( false === $set ) {
		unset( $ids[ $post_id ] );
	}
	return isset( $ids[ $post_id ] );
}
add_action( 'before_delete_post', static fn( int $post_id ) => deleting( $post_id, true ), 1 );
add_action( 'after_delete_post', static fn( int $post_id ) => deleting( $post_id, false ), 1 );

/**
 * Whether a REST save of an entry is under way, so its term and meta writes wait for the save to finish.
 *
 * @since 0.1.0
 *
 * @param bool|null $set True as one starts, false as it ends; null reads.
 * @return bool
 */
function saving_entry( ?bool $set = null ): bool {
	static $saving = false;
	if ( null !== $set ) {
		$saving = $set;
	}
	return $saving;
}
add_filter(
	'rest_pre_insert_' . ENTRY_TYPE,
	static function ( $prepared ) {
		saving_entry( true );
		return $prepared;
	},
	1
);
add_action(
	'wp_after_insert_post',
	static function ( int $post_id, \WP_Post $post ): void {
		if ( ENTRY_TYPE === $post->post_type ) {
			saving_entry( false );
		}
	},
	15,
	2
);

/**
 * Whether a save is one readers should hear about: a published post, not an autosave, a revision or a post on its way out.
 *
 * @since 0.1.0
 *
 * @param \WP_Post|null $post The post.
 * @return bool
 */
function is_live_save( ?\WP_Post $post ): bool {
	if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || deleting( $post->ID ) ) {
		return false;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return false;
	}
	return ! wp_is_post_revision( $post->ID ) && ! wp_is_post_autosave( $post->ID );
}

/**
 * Claim one publish of an event for a post in this request; a second claim is refused until released.
 *
 * @since 0.1.0
 *
 * @param string    $event   Event name.
 * @param int       $post_id Post id.
 * @param bool|null $release True to release the claim instead.
 * @return bool True when the claim is taken.
 */
function claim_publish( string $event, int $post_id, ?bool $release = null ): bool {
	static $claimed = array();
	$key            = $event . ':' . $post_id;
	if ( true === $release ) {
		unset( $claimed[ $key ] );
		return false;
	}
	if ( isset( $claimed[ $key ] ) ) {
		return false;
	}
	$claimed[ $key ] = true;
	return true;
}

/**
 * The rendered entry, its hash and its assets, computed once per request.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $entry The entry.
 * @return array{html: string, assets: array, hash: string}
 */
function rendered_entry( \WP_Post $entry ): array {
	static $cache = array();
	$key          = $entry->ID . ':' . md5( $entry->post_title . "\0" . $entry->post_content ) . ':' . ( is_pinned( $entry->ID ) ? 1 : 0 );
	if ( ! isset( $cache[ $key ] ) ) {
		$rendered         = with_render_assets( static fn() => render_entry( $entry ) );
		$rendered['hash'] = content_hash( $rendered['html'] );
		$cache[ $key ]    = $rendered;
	}
	return $cache[ $key ];
}

/**
 * Whether an entry change should be published: a live save, a live blog, changed content.
 *
 * @since 0.1.0
 *
 * @param \WP_Post|null $entry The entry.
 * @return bool
 */
function entry_publishable( ?\WP_Post $entry ): bool {
	if ( saving_entry() || ! is_live_save( $entry ) || ENTRY_TYPE !== $entry->post_type ) {
		return false;
	}
	if ( liveblog_id_of( $entry ) <= 0 ) {
		return false;
	}
	if ( ! content_changed( $entry->ID, rendered_entry( $entry )['hash'] ) ) {
		return false;
	}
	claim_publish( 'entry.removed', $entry->ID, true );
	return true;
}

/**
 * Whether a post's rendered content, title or live fields were found changed in this request.
 *
 * @since 0.1.0
 *
 * @param int       $post_id Post id.
 * @param bool|null $set     Record the finding; null reads it.
 * @return bool
 */
function content_moved( int $post_id, ?bool $set = null ): bool {
	static $moved = array();
	if ( null !== $set ) {
		$moved[ $post_id ] = $set;
	}
	return $moved[ $post_id ] ?? false;
}

/**
 * The `postsocket.entry.updated` payload.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $entry The entry.
 * @return array
 */
function entry_payload( \WP_Post $entry ): array {
	return fit_payload(
		array(
			'id'       => (int) $entry->ID,
			'liveblog' => liveblog_id_of( $entry ),
			'html'     => rendered_entry( $entry )['html'],
			'headline' => get_the_title( $entry ),
			'date'     => mysql2date( 'c', $entry->post_date_gmt, false ),
			'modified' => mysql2date( 'c', $entry->post_modified_gmt, false ),
			'pinned'   => is_pinned( $entry->ID ),
			'author'   => array(
				'name'   => get_the_author_meta( 'display_name', (int) $entry->post_author ),
				'avatar' => get_avatar_url( (int) $entry->post_author, array( 'size' => 64 ) ),
			),
			'assets'   => rendered_entry( $entry )['assets'],
		)
	);
}

/**
 * The `postsocket.post.updated` payload: a notice to reload the page when a reload field or taxonomy changed, else the rendered post when its content, title or live fields changed, else a notice to refresh the page for a post with a refresh selector; in debug mode, with what changed.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $post The post.
 * @return array
 */
function post_payload( \WP_Post $post ): array {
	$rendered = rendered_post( $post );
	$debug    = \WPSignal\Config::is_debug() ? array( 'changed' => change_reasons( $post->ID ) ) : array();
	if ( reloads_page( $post->ID ) ) {
		return array(
			'id'       => (int) $post->ID,
			'modified' => mysql2date( 'c', $post->post_modified_gmt, false ),
			'hash'     => $rendered['hash'],
			'title'    => title_text( $post ),
			'reload'   => true,
		) + $debug;
	}
	if ( ! content_moved( $post->ID ) && '' !== refresh_selector( $post ) ) {
		return array(
			'id'       => (int) $post->ID,
			'modified' => mysql2date( 'c', $post->post_modified_gmt, false ),
			'hash'     => $rendered['hash'],
			'title'    => title_text( $post ),
			'refresh'  => true,
		) + $debug;
	}
	return $debug + fit_payload(
		array(
			'id'       => (int) $post->ID,
			'modified' => mysql2date( 'c', $post->post_modified_gmt, false ),
			'hash'     => $rendered['hash'],
			'title'    => title_text( $post ),
			'html'     => $rendered['html'],
			'assets'   => $rendered['assets'],
		) + ( $rendered['fields'] ? array( 'fields' => $rendered['fields'] ) : array() )
	);
}

/**
 * Whether a post's change should publish `postsocket.post.updated`: its rendered content, title or live fields differ, the change is one that reloads the page, or its page is one readers refresh.
 *
 * @since 0.1.0
 *
 * @param \WP_Post|null $post The post.
 * @return bool
 */
function post_publishable( ?\WP_Post $post ): bool {
	if ( ! is_live_save( $post ) || ! is_living( $post ) ) {
		return false;
	}
	$changed = content_moved( $post->ID, content_changed( $post->ID, rendered_post( $post )['hash'] ) );
	return $changed || reloads_page( $post->ID ) || '' !== refresh_selector( $post );
}


/**
 * The live blog an entry left in a term change, or 0 when it had none, kept it, or is not published.
 *
 * @since 0.1.0
 *
 * @param int   $entry_id   Entry id.
 * @param array $tt_ids     Term taxonomy ids after the change.
 * @param array $old_tt_ids Term taxonomy ids before it.
 * @return int
 */
function left_liveblog( int $entry_id, array $tt_ids, array $old_tt_ids ): int {
	$old = array_map( 'intval', $old_tt_ids );
	if ( ! $old || ! array_diff( $old, array_map( 'intval', $tt_ids ) ) ) {
		return 0;
	}
	$term = get_term_by( 'term_taxonomy_id', $old[0], LIVEBLOG_TAX );
	return $term instanceof \WP_Term && 'publish' === get_post_status( $entry_id ) ? (int) $term->term_id : 0;
}

// A post leaving `publish` forgets its hash, so coming back publishes it again.
add_action(
	'transition_post_status',
	static function ( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' === $old_status && 'publish' !== $new_status ) {
			delete_post_meta( $post->ID, META_HASH );
		}
	},
	10,
	3
);

// An entry moved to another live blog forgets its hash, so the new live blog's readers get it.
add_action(
	'set_object_terms',
	static function ( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		if ( LIVEBLOG_TAX === $taxonomy && is_entry( $object_id ) && array_map( 'intval', $tt_ids ) !== array_map( 'intval', $old_tt_ids ) ) {
			delete_post_meta( $object_id, META_HASH );
		}
	},
	5,
	6
);

/**
 * Queue a post whose readers may have something new to see, with what changed, or with no id take the queue.
 *
 * @since 0.1.0
 *
 * @param int    $post_id Post id, or 0 to take what is queued.
 * @param string $reason  What changed: `post:{fields}`, `meta:{key}` or `terms:{taxonomy}`.
 * @return array<int, string[]> The queued posts with their reasons when taking, else empty.
 */
function living_queue( int $post_id = 0, string $reason = 'post:new' ): array {
	static $queue = array();
	if ( $post_id > 0 ) {
		$queue[ $post_id ][ $reason ] = true;
		render_generation( $post_id, true );
		return array();
	}
	$taken = array_map( 'array_keys', $queue );
	$queue = array();
	return $taken;
}

/**
 * A post meta key's values as they stood before this request first wrote to it.
 *
 * @since 0.1.0
 *
 * @param int    $post_id Post id, or 0 to forget every kept value.
 * @param string $key     Meta key.
 * @param bool   $record  True to keep the values as they stand now, unless already kept.
 * @return array|null Null when the key was not written in this request.
 */
function meta_before( int $post_id, string $key = '', bool $record = false ): ?array {
	static $before = array();
	if ( 0 === $post_id ) {
		$before = array();
		return null;
	}
	if ( $record && ! isset( $before[ $post_id ][ $key ] ) ) {
		$before[ $post_id ][ $key ] = get_post_meta( $post_id, $key, false );
	}
	return $before[ $post_id ][ $key ] ?? null;
}
add_action( 'update_post_meta', static fn( $meta_id, $post_id, $key ) => meta_before( (int) $post_id, (string) $key, true ), 1, 3 );
add_action( 'add_post_meta', static fn( $post_id, $key ) => meta_before( (int) $post_id, (string) $key, true ), 1, 2 );
add_action( 'delete_post_meta', static fn( $meta_ids, $post_id, $key ) => meta_before( (int) $post_id, (string) $key, true ), 1, 3 );

/**
 * The reasons a post was queued that still hold as the request ends: a meta key deleted and added back with the same values is dropped.
 *
 * @since 0.1.0
 *
 * @param int      $post_id Post id.
 * @param string[] $reasons Reasons as queued.
 * @return string[]
 */
function standing_reasons( int $post_id, array $reasons ): array {
	return array_values(
		array_filter(
			$reasons,
			static function ( string $reason ) use ( $post_id ): bool {
				if ( ! str_starts_with( $reason, 'meta:' ) ) {
					return true;
				}
				$key    = substr( $reason, 5 );
				$before = meta_before( $post_id, $key );
				return null === $before || wp_json_encode( $before ) !== wp_json_encode( get_post_meta( $post_id, $key, false ) );
			}
		)
	);
}

/**
 * Offer every queued post to its readers; one whose rendered content, title and live fields are unchanged publishes nothing.
 *
 * @since 0.1.0
 *
 * @return void
 */
function tell_readers(): void {
	foreach ( living_queue() as $post_id => $reasons ) {
		$reasons = standing_reasons( $post_id, $reasons );
		if ( ! $reasons ) {
			continue;
		}
		/**
		 * Fires once a request that changed a post, its meta or its terms is ending.
		 *
		 * @since 0.1.0
		 *
		 * @param int      $post_id Post id.
		 * @param string[] $reasons What changed: `post:{fields}`, `meta:{key}` and `terms:{taxonomy}`.
		 */
		change_reasons( $post_id, $reasons );
		do_action( 'postsocket_post_changed', $post_id, $reasons );
	}
	meta_before( 0 );
}
add_action( 'shutdown', __NAMESPACE__ . '\tell_readers' );

/**
 * The meta keys and post columns whose change makes readers reload the page.
 *
 * @since 0.1.0
 *
 * @param int $post_id Post id.
 * @return string[]
 */
function reload_fields( int $post_id ): array {
	/**
	 * Filters the meta keys and post columns, such as `comment_status`, whose change makes every reader's browser reload the page, for changes that bring stylesheets or scripts the open page does not have.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $names   Meta keys and post columns, none by default.
	 * @param int      $post_id The post id.
	 */
	return array_map( 'strval', (array) apply_filters( 'postsocket_reload_live_fields', array(), $post_id ) );
}

/**
 * The taxonomies whose term changes make readers reload the page.
 *
 * @since 0.1.0
 *
 * @param int $post_id Post id.
 * @return string[]
 */
function reload_taxonomies( int $post_id ): array {
	/**
	 * Filters the taxonomies whose term changes on a post make every reader's browser reload the page.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $taxonomies Taxonomy names, none by default.
	 * @param int      $post_id    The post id.
	 */
	return array_map( 'strval', (array) apply_filters( 'postsocket_reload_live_taxonomies', array(), $post_id ) );
}

/**
 * Whether what changed about a post in the request now ending is something readers reload the page for.
 *
 * @since 0.1.0
 *
 * @param int $post_id Post id.
 * @return bool
 */
function reloads_page( int $post_id ): bool {
	$fields     = reload_fields( $post_id );
	$taxonomies = reload_taxonomies( $post_id );
	foreach ( change_reasons( $post_id ) as $reason ) {
		list( $kind, $what ) = explode( ':', $reason, 2 ) + array( '', '' );
		if ( 'meta' === $kind && in_array( $what, $fields, true ) ) {
			return true;
		}
		if ( 'terms' === $kind && in_array( $what, $taxonomies, true ) ) {
			return true;
		}
		if ( 'post' === $kind && array_intersect( explode( '+', $what ), $fields ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether a change to a post's meta or terms in this request is one to look at: made by someone who may edit the post, by WP-CLI or cron, or to a meta key or taxonomy that a live field or a reload list names.
 *
 * @since 0.1.0
 *
 * @param int    $post_id  Post id.
 * @param string $key      Meta key, empty for a term change.
 * @param string $taxonomy Taxonomy, empty for a meta change.
 * @return bool
 */
function watches_change( int $post_id, string $key = '', string $taxonomy = '' ): bool {
	$watch = current_user_can( 'edit_post', $post_id ) || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron();
	if ( ! $watch ) {
		$watch = '' !== $key ? in_array( $key, reload_fields( $post_id ), true ) : in_array( $taxonomy, reload_taxonomies( $post_id ), true );
	}
	if ( ! $watch ) {
		$post = get_post( $post_id );
		foreach ( $post ? live_fields( $post ) : array() as $field ) {
			if ( '' !== $key ? in_array( $key, $field['meta'], true ) : $taxonomy === $field['taxonomy'] ) {
				$watch = true;
				break;
			}
		}
	}
	/**
	 * Filters whether a change to a post's meta or terms makes PostSocket render the post again and publish it when it differs.
	 *
	 * @since 0.1.0
	 *
	 * @param bool   $watch    True for a change by someone who may edit the post, by WP-CLI or cron, or to a live field's meta key or taxonomy.
	 * @param string $key      The meta key, empty for a term change.
	 * @param int    $post_id  The post id.
	 * @param string $taxonomy The taxonomy, empty for a meta change.
	 */
	return (bool) apply_filters( 'postsocket_watch_meta', $watch, $key, $post_id, $taxonomy );
}

/**
 * The meta keys whose changes are never looked at: the unwatched ones and those a site excludes.
 *
 * @since 0.1.0
 *
 * @param int $post_id Post id.
 * @return string[]
 */
function excluded_meta( int $post_id ): array {
	/**
	 * Filters the meta keys whose changes PostSocket ignores for a post: no render, no update, no page refresh.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $keys    Meta keys, none by default; PostSocket's own and WordPress's housekeeping keys are always ignored.
	 * @param int      $post_id The post id.
	 */
	$excluded = (array) apply_filters( 'postsocket_exclude_live_fields', array(), $post_id );
	return array_merge( UNWATCHED_META, array_map( 'strval', $excluded ) );
}

/**
 * The taxonomies whose term changes are never looked at, as a site excludes them.
 *
 * @since 0.1.0
 *
 * @param int $post_id Post id.
 * @return string[]
 */
function excluded_taxonomies( int $post_id ): array {
	/**
	 * Filters the taxonomies whose term changes PostSocket ignores for a post: no render, no update, no page refresh.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $taxonomies Taxonomy names, none by default.
	 * @param int      $post_id    The post id.
	 */
	return array_map( 'strval', (array) apply_filters( 'postsocket_exclude_live_taxonomies', array(), $post_id ) );
}

/**
 * The fields of the post's own row that a save changed, its modified dates aside; a property the save only attached to the object, such as `page_template`, is not one.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $before The post before the save.
 * @param \WP_Post $after  The post after it.
 * @return string[]
 */
function post_row_changes( \WP_Post $before, \WP_Post $after ): array {
	$was     = get_object_vars( $before );
	$changed = array();
	foreach ( get_object_vars( $after ) as $field => $value ) {
		if ( in_array( $field, array( 'post_modified', 'post_modified_gmt', 'filter' ), true ) ) {
			continue;
		}
		// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- A field is a string before a save and may be an integer after it.
		if ( array_key_exists( $field, $was ) && $was[ $field ] != $value ) {
			$changed[] = $field;
		}
	}
	return $changed;
}

/**
 * What changed about a post in the request now ending, as `tell_readers()` found it.
 *
 * @since 0.1.0
 *
 * @param int           $post_id Post id.
 * @param string[]|null $set     Record the reasons; null reads them.
 * @return string[]
 */
function change_reasons( int $post_id, ?array $set = null ): array {
	static $reasons = array();
	if ( null !== $set ) {
		$reasons[ $post_id ] = $set;
	}
	return $reasons[ $post_id ] ?? array();
}

// A saved post is queued when it is new to readers or its own row changed; its meta and terms queue it themselves.
add_action(
	'wp_after_insert_post',
	static function ( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $before ): void {
		if ( ENTRY_TYPE === $post->post_type || 'publish' !== $post->post_status || wp_is_post_revision( $post ) ) {
			return;
		}
		if ( ! $update || ! $before ) {
			living_queue( $post_id, 'post:new' );
			return;
		}
		$changed = post_row_changes( $before, $post );
		if ( $changed ) {
			living_queue( $post_id, 'post:' . implode( '+', $changed ) );
		}
	},
	10,
	4
);

/**
 * Meta keys whose changes are always ignored: PostSocket's own, and the ones WordPress keeps for its editing, pings, enclosures and old slugs.
 *
 * @since 0.1.0
 */
const UNWATCHED_META = array(
	META_HASH,
	META_PINNED,
	META_LIVE,
	'_edit_lock',
	'_edit_last',
	'_pingme',
	'_encloseme',
	'_trackbackme',
	'enclosure',
	'_wp_old_slug',
	'_wp_old_date',
	'_wp_desired_post_slug',
	'_wp_trash_meta_status',
	'_wp_trash_meta_time',
);

// So is a published post whose meta changes, in a save or outside one, the excluded keys aside.
foreach ( array( 'updated_post_meta', 'added_post_meta', 'deleted_post_meta' ) as $postsocket_hook ) {
	add_action(
		$postsocket_hook,
		static function ( $meta_id, $post_id, $key ): void {
			$post_id = (int) $post_id;
			if ( deleting( $post_id ) || in_array( $key, excluded_meta( $post_id ), true ) ) {
				return;
			}
			if ( 'publish' === get_post_status( $post_id ) && ! is_entry( $post_id ) && watches_change( $post_id, (string) $key ) ) {
				living_queue( $post_id, 'meta:' . $key );
			}
		},
		10,
		3
	);
}
unset( $postsocket_hook );

// And one whose terms change, the excluded taxonomies aside.
add_action(
	'set_object_terms',
	static function ( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		$after  = array_map( 'intval', $tt_ids );
		$before = array_map( 'intval', $old_tt_ids );
		sort( $after );
		sort( $before );
		if ( $after === $before || in_array( $taxonomy, excluded_taxonomies( $object_id ), true ) ) {
			return;
		}
		if ( 'publish' === get_post_status( $object_id ) && ! is_entry( $object_id ) && watches_change( $object_id, '', $taxonomy ) ) {
			living_queue( $object_id, 'terms:' . $taxonomy );
		}
	},
	10,
	6
);

/**
 * Queue a live blog whose desk has something new to show, or with no id take the queue.
 *
 * @since 0.1.0
 *
 * @param int $liveblog_id Live blog id, or 0 to take what is queued.
 * @return int[] The queued live blogs when taking, else empty.
 */
function desk_queue( int $liveblog_id = 0 ): array {
	static $queue = array();
	if ( $liveblog_id > 0 ) {
		$queue[ $liveblog_id ] = true;
		return array();
	}
	$taken = array_keys( $queue );
	$queue = array();
	return $taken;
}

/**
 * Tell the open desks of every queued live blog to look again.
 *
 * @since 0.1.0
 *
 * @return void
 */
function tell_desks(): void {
	foreach ( desk_queue() as $liveblog_id ) {
		/**
		 * Fires once a request that changed what a live blog's desk shows is ending.
		 *
		 * @since 0.1.0
		 *
		 * @param int $liveblog_id Live blog id.
		 */
		do_action( 'postsocket_desk_changed', $liveblog_id );
	}
}
add_action( 'shutdown', __NAMESPACE__ . '\tell_desks' );

// An entry saved, trashed or restored in any status is news for its desk.
add_action(
	'wp_after_insert_post',
	static function ( int $post_id, \WP_Post $post ): void {
		if ( ENTRY_TYPE === $post->post_type && 'auto-draft' !== $post->post_status ) {
			desk_queue( liveblog_id_of( $post ) );
		}
	},
	10,
	2
);
add_action(
	'before_delete_post',
	static function ( int $post_id ): void {
		if ( is_entry( $post_id ) ) {
			desk_queue( liveblog_id_of( $post_id ) );
		}
	}
);

// So is an entry joining or leaving a live blog, for both desks.
add_action(
	'set_object_terms',
	static function ( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		if ( LIVEBLOG_TAX !== $taxonomy || ! is_entry( $object_id ) ) {
			return;
		}
		foreach ( array_unique( array_map( 'intval', array_merge( $tt_ids, $old_tt_ids ) ) ) as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', $tt_id, LIVEBLOG_TAX );
			if ( $term instanceof \WP_Term ) {
				desk_queue( (int) $term->term_id );
			}
		}
	},
	10,
	6
);

// An editor opening an entry, taking it over or closing it is news for its desk; a renewal of the same lock is not.
add_filter(
	'update_post_metadata',
	static function ( $check, $post_id, $key, $value ) {
		if ( '_edit_lock' === $key && is_entry( (int) $post_id ) ) {
			$before = editing_lock( (int) $post_id );
			$after  = read_lock( (string) $value );
			if ( ( $before['user'] ?? 0 ) !== ( $after['user'] ?? 0 ) || ( $after['expires_in'] ?? 0 ) < ( $before['expires_in'] ?? 0 ) ) {
				desk_queue( liveblog_id_of( (int) $post_id ) );
			}
		}
		return $check;
	},
	1,
	4
);

// The triggers, after the namespace is reserved.
add_action(
	'wpsignal_loaded',
	static function (): void {
		if ( ! wordsocket_ready() ) {
			return;
		}

		$label         = NS . ':liveblog:{id}';
		$entry_channel = static fn( int $id ): string => liveblog_channel( liveblog_id_of( $id ) );

		WPS::trigger( 'postsocket.entry.updated' )
			->on( 'wp_after_insert_post', 20, 2 )
			->channel( $entry_channel, $label )
			->when( static fn( int $id, \WP_Post $post ): bool => entry_publishable( $post ) )
			->data( static fn( int $id, \WP_Post $post ): array => entry_payload( $post ) )
			->register();

		foreach ( array( 'updated_post_meta', 'added_post_meta', 'deleted_post_meta' ) as $hook ) {
			WPS::trigger( 'postsocket.entry.updated' )
				->on( $hook, 10, 4 )
				->channel( static fn( $meta_id, int $post_id ): string => $entry_channel( $post_id ), $label )
				->when( static fn( $meta_id, int $post_id, string $key ): bool => META_PINNED === $key && entry_publishable( get_post( $post_id ) ) )
				->data( static fn( $meta_id, int $post_id ): array => entry_payload( get_post( $post_id ) ) )
				->register();
		}

		WPS::trigger( 'postsocket.entry.removed' )
			->on( 'set_object_terms', 10, 6 )
			->channel(
				static fn( int $id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old ): string => liveblog_channel( left_liveblog( $id, $tt_ids, $old ) ),
				$label
			)
			->when(
				static fn( int $id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old ): bool => LIVEBLOG_TAX === $taxonomy
					&& is_entry( $id )
					&& left_liveblog( $id, $tt_ids, $old ) > 0
			)
			->data(
				static fn( int $id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old ): array => array(
					'id'       => $id,
					'liveblog' => left_liveblog( $id, $tt_ids, $old ),
				)
			)
			->register();

		WPS::trigger( 'postsocket.entry.updated' )
			->on( 'set_object_terms', 20, 4 )
			->channel( static fn( int $id ): string => $entry_channel( $id ), $label )
			->when( static fn( int $id, array $terms, array $tt_ids, string $taxonomy ): bool => LIVEBLOG_TAX === $taxonomy && entry_publishable( get_post( $id ) ) )
			->data( static fn( int $id ): array => entry_payload( get_post( $id ) ) )
			->register();

		WPS::trigger( 'postsocket.entry.removed' )
			->on( 'transition_post_status', 10, 3 )
			->channel(
				static fn( string $new_status, string $old_status, \WP_Post $post ): string => $entry_channel( $post->ID ),
				$label
			)
			->when(
				static fn( string $new_status, string $old_status, \WP_Post $post ): bool => ENTRY_TYPE === $post->post_type
					&& 'publish' === $old_status
					&& 'publish' !== $new_status
					&& liveblog_id_of( $post ) > 0
					&& claim_publish( 'entry.removed', $post->ID )
			)
			->data(
				static fn( string $new_status, string $old_status, \WP_Post $post ): array => array(
					'id'       => (int) $post->ID,
					'liveblog' => liveblog_id_of( $post ),
				)
			)
			->register();

		WPS::trigger( 'postsocket.entry.removed' )
			->on( 'before_delete_post', 10, 2 )
			->channel( static fn( int $id ): string => $entry_channel( $id ), $label )
			->when(
				static fn( int $id, ?\WP_Post $post = null ): bool => $post instanceof \WP_Post
					&& ENTRY_TYPE === $post->post_type
					&& 'publish' === $post->post_status
					&& liveblog_id_of( $post ) > 0
					&& claim_publish( 'entry.removed', $post->ID )
			)
			->data(
				static fn( int $id ): array => array(
					'id'       => $id,
					'liveblog' => liveblog_id_of( $id ),
				)
			)
			->register();

		$state_when = static fn( $meta_id, int $term_id, string $key ): bool => META_STATE === $key
			&& is_liveblog( $term_id )
			&& claim_publish( 'liveblog.state', $term_id );
		$state_data = static fn( $meta_id, int $term_id ): array => array(
			'id'    => $term_id,
			'state' => liveblog_state( $term_id ),
			'ended' => (int) get_term_meta( $term_id, META_ENDED, true ),
		);
		foreach ( array( 'updated_term_meta', 'added_term_meta' ) as $hook ) {
			WPS::trigger( 'postsocket.liveblog.state' )
				->on( $hook, 10, 4 )
				->channel( static fn( $meta_id, int $term_id ): string => liveblog_channel( $term_id ), $label )
				->when( $state_when )
				->data( $state_data )
				->register();
		}

		WPS::trigger( 'postsocket.desk.changed' )
			->on( 'postsocket_desk_changed', 10, 1 )
			->channel( static fn( int $id ): string => desk_channel( $id ), DESK_NS . ':liveblog:{id}' )
			->data( static fn( int $id ): array => array( 'liveblog' => $id ) )
			->register();

		WPS::trigger( 'postsocket.post.updated' )
			->on( 'postsocket_post_changed', 10, 1 )
			->channel( static fn( int $id ): string => post_channel( $id ), NS . ':post:{id}' )
			->when( static fn( int $id ): bool => post_publishable( get_post( $id ) ) )
			->data( static fn( int $id ): array => post_payload( get_post( $id ) ) )
			->register();
	},
	20
);

<?php
/**
 * The four events, registered as WordSocket triggers.
 *
 * - `postsocket.entry.updated` and `postsocket.entry.removed` on `postsocket:liveblog:{id}`.
 * - `postsocket.liveblog.state` on the same channel when a live blog's state meta changes.
 * - `postsocket.post.updated` on `postsocket:post:{id}` for live blogs and the enabled post types.
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
 * The rendered entry and its hash, computed once per request.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $entry The entry.
 * @return array{html: string, hash: string}
 */
function rendered_entry( \WP_Post $entry ): array {
	static $cache = array();
	$key          = $entry->ID . ':' . md5( $entry->post_title . "\0" . $entry->post_content ) . ':' . ( is_pinned( $entry->ID ) ? 1 : 0 );
	if ( ! isset( $cache[ $key ] ) ) {
		$html          = render_entry( $entry );
		$cache[ $key ] = array(
			'html' => $html,
			'hash' => content_hash( $html ),
		);
	}
	return $cache[ $key ];
}

/**
 * Whether an entry change should be published: a live save, a published live blog, changed content.
 *
 * @since 0.1.0
 *
 * @param \WP_Post|null $entry The entry.
 * @return bool
 */
function entry_publishable( ?\WP_Post $entry ): bool {
	if ( ! is_live_save( $entry ) || ENTRY_TYPE !== $entry->post_type ) {
		return false;
	}
	$liveblog = liveblog_of( $entry );
	if ( ! $liveblog || 'publish' !== $liveblog->post_status ) {
		return false;
	}
	if ( ! content_changed( $entry->ID, rendered_entry( $entry )['hash'] ) ) {
		return false;
	}
	claim_publish( 'entry.removed', $entry->ID, true );
	return true;
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
			'liveblog' => (int) $entry->post_parent,
			'html'     => rendered_entry( $entry )['html'],
			'headline' => get_the_title( $entry ),
			'date'     => mysql2date( 'c', $entry->post_date_gmt, false ),
			'modified' => mysql2date( 'c', $entry->post_modified_gmt, false ),
			'pinned'   => is_pinned( $entry->ID ),
			'author'   => array(
				'name'   => get_the_author_meta( 'display_name', (int) $entry->post_author ),
				'avatar' => get_avatar_url( (int) $entry->post_author, array( 'size' => 64 ) ),
			),
			'assets'   => assets_for( $entry ),
		)
	);
}

/**
 * The `postsocket.post.updated` payload.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $post The post.
 * @return array
 */
function post_payload( \WP_Post $post ): array {
	$html = render_content( $post );
	return fit_payload(
		array(
			'id'       => (int) $post->ID,
			'modified' => mysql2date( 'c', $post->post_modified_gmt, false ),
			'hash'     => content_hash( $html ),
			'html'     => $html,
			'assets'   => assets_for( $post ),
		)
	);
}

/**
 * Whether a post's save should publish `postsocket.post.updated`.
 *
 * @since 0.1.0
 *
 * @param \WP_Post|null $post The post.
 * @return bool
 */
function post_publishable( ?\WP_Post $post ): bool {
	if ( ! is_live_save( $post ) || ! in_array( $post->post_type, living_types(), true ) ) {
		return false;
	}
	return content_changed( $post->ID, content_hash( render_content( $post ) ) );
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

// The triggers, after the namespace is reserved.
add_action(
	'wpsignal_loaded',
	static function (): void {
		if ( ! wordsocket_ready() ) {
			return;
		}

		$entry_channel = static fn( int $id, ?\WP_Post $post = null ): string => liveblog_channel( (int) ( $post->post_parent ?? get_post_field( 'post_parent', $id ) ) );

		WPS::trigger( 'postsocket.entry.updated' )
			->on( 'save_post_' . ENTRY_TYPE, 20, 2 )
			->channel( $entry_channel, NS . ':liveblog:{id}' )
			->when( static fn( int $id, \WP_Post $post ): bool => entry_publishable( $post ) )
			->data( static fn( int $id, \WP_Post $post ): array => entry_payload( $post ) )
			->register();

		foreach ( array( 'updated_post_meta', 'added_post_meta', 'deleted_post_meta' ) as $hook ) {
			WPS::trigger( 'postsocket.entry.updated' )
				->on( $hook, 10, 4 )
				->channel(
					static fn( $meta_id, int $post_id ): string => liveblog_channel( (int) get_post_field( 'post_parent', $post_id ) ),
					NS . ':liveblog:{id}'
				)
				->when( static fn( $meta_id, int $post_id, string $key ): bool => META_PINNED === $key && entry_publishable( get_post( $post_id ) ) )
				->data( static fn( $meta_id, int $post_id ): array => entry_payload( get_post( $post_id ) ) )
				->register();
		}

		WPS::trigger( 'postsocket.entry.removed' )
			->on( 'transition_post_status', 10, 3 )
			->channel(
				static fn( string $new_status, string $old_status, \WP_Post $post ): string => liveblog_channel( (int) $post->post_parent ),
				NS . ':liveblog:{id}'
			)
			->when(
				static fn( string $new_status, string $old_status, \WP_Post $post ): bool => ENTRY_TYPE === $post->post_type
					&& 'publish' === $old_status
					&& 'publish' !== $new_status
					&& $post->post_parent > 0
					&& claim_publish( 'entry.removed', $post->ID )
			)
			->data(
				static fn( string $new_status, string $old_status, \WP_Post $post ): array => array(
					'id'       => (int) $post->ID,
					'liveblog' => (int) $post->post_parent,
				)
			)
			->register();

		WPS::trigger( 'postsocket.entry.removed' )
			->on( 'before_delete_post', 10, 2 )
			->channel( $entry_channel, NS . ':liveblog:{id}' )
			->when(
				static fn( int $id, ?\WP_Post $post = null ): bool => $post instanceof \WP_Post
					&& ENTRY_TYPE === $post->post_type
					&& 'publish' === $post->post_status
					&& $post->post_parent > 0
					&& claim_publish( 'entry.removed', $post->ID )
			)
			->data(
				static fn( int $id, ?\WP_Post $post = null ): array => array(
					'id'       => (int) $id,
					'liveblog' => (int) $post->post_parent,
				)
			)
			->register();

		$state_channel = static fn( $meta_id, int $post_id ): string => liveblog_channel( $post_id );
		$state_when    = static fn( $meta_id, int $post_id, string $key ): bool => META_STATE === $key
			&& is_liveblog( $post_id )
			&& 'publish' === get_post_status( $post_id )
			&& claim_publish( 'liveblog.state', $post_id );
		$state_data    = static fn( $meta_id, int $post_id ): array => array(
			'id'    => $post_id,
			'state' => liveblog_state( $post_id ),
			'ended' => (int) get_post_meta( $post_id, META_ENDED, true ),
		);
		foreach ( array( 'updated_post_meta', 'added_post_meta' ) as $hook ) {
			WPS::trigger( 'postsocket.liveblog.state' )
				->on( $hook, 10, 4 )
				->channel( $state_channel, NS . ':liveblog:{id}' )
				->when( $state_when )
				->data( $state_data )
				->register();
		}

		WPS::trigger( 'postsocket.post.updated' )
			->on( 'save_post', 20, 2 )
			->channel( static fn( int $id ): string => post_channel( $id ), NS . ':post:{id}' )
			->when( static fn( int $id, ?\WP_Post $post = null ): bool => post_publishable( $post ) )
			->data( static fn( int $id, \WP_Post $post ): array => post_payload( $post ) )
			->register();
	},
	20
);

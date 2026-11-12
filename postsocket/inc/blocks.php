<?php
/**
 * The `postsocket/live-feed` block: the live blog named by its `liveblog` attribute, rendered by `render_feed()`.
 *
 * @package WPSignal\Extensions\PostSocket
 */

namespace WPSignal\Extensions\PostSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	static function (): void {
		if ( ! file_exists( DIR . 'build/blocks/live-feed/block.json' ) ) {
			return;
		}
		register_block_type(
			DIR . 'build/blocks/live-feed',
			array(
				'render_callback' => __NAMESPACE__ . '\render_live_feed_block',
			)
		);
		wp_add_inline_script(
			generate_block_asset_handle( FEED_BLOCK, 'editorScript' ),
			'window.postsocketBlock = ' . wp_json_encode(
				array(
					'taxonomy'  => LIVEBLOG_TAX,
					'deskUrl'   => desk_url(),
					'canManage' => current_user_can( 'edit_others_posts' ),
					'meta'      => array( 'state' => META_STATE ),
				)
			) . ';',
			'before'
		);
	}
);

/**
 * The empty marker a relay render puts where a feed sits, so the browser keeps its own feed there.
 *
 * @since 0.1.0
 *
 * @param int $liveblog_id Live blog id.
 * @return string
 */
function feed_slot( int $liveblog_id ): string {
	return '<div data-postsocket-feed-slot="' . $liveblog_id . '" hidden></div>';
}

/**
 * Render the block: the feed of the live blog it names, a slot for it in a relay render, or nothing.
 *
 * @since 0.1.0
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function render_live_feed_block( array $attributes ): string {
	$liveblog_id = (int) ( $attributes['liveblog'] ?? 0 );
	if ( $liveblog_id <= 0 ) {
		return '';
	}
	if ( rendering_for_relay() ) {
		return feed_slot( $liveblog_id );
	}
	return render_feed( $liveblog_id );
}

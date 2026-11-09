<?php
/**
 * The `postsocket/live-feed` block, rendered by `render_feed()`.
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
	}
);

/**
 * Render the block: the feed of the post it sits in, or nothing outside a live blog.
 *
 * @since 0.1.0
 *
 * @param array     $attributes Block attributes.
 * @param string    $content    Inner content, unused.
 * @param \WP_Block $block      The block, for its post context.
 * @return string
 */
function render_live_feed_block( array $attributes, string $content, \WP_Block $block ): string {
	unset( $attributes, $content );
	$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
	if ( $post_id <= 0 || ! is_liveblog( $post_id ) || rendering_for_relay() ) {
		return '';
	}
	return render_feed( $post_id );
}

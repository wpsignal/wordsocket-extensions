<?php
/**
 * Channels: the catalogue entry, the namespace every token reads, and the channel names.
 *
 * @package WPSignal\Extensions\PostSocket
 */

namespace WPSignal\Extensions\PostSocket;

use WPSignal\WPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The channel a live blog's entries and state travel on.
 *
 * @since 0.1.0
 *
 * @param int $liveblog_id Live blog id.
 * @return string
 */
function liveblog_channel( int $liveblog_id ): string {
	return NS . ':liveblog:' . $liveblog_id;
}

/**
 * The channel a post's content updates travel on.
 *
 * @since 0.1.0
 *
 * @param int $post_id Post id.
 * @return string
 */
function post_channel( int $post_id ): string {
	return NS . ':post:' . $post_id;
}

// The catalogue entry carries translated strings, so it waits for `init`.
add_action(
	'init',
	static function (): void {
		if ( ! class_exists( WPS::class ) ) {
			return;
		}
		WPS::instance()->extensions()->register(
			SLUG,
			array(
				'title'       => __( 'PostSocket', 'postsocket' ),
				'description' => __( 'Live blogs and articles that update in every reader\'s browser as you publish.', 'postsocket' ),
				'version'     => VERSION,
				'docs_url'    => 'https://wpsignal.io/extensions/postsocket',
				'file'        => plugin_basename( DIR . 'postsocket.php' ),
			)
		);
	}
);

// The namespace: readable by every token, written by PHP alone; no generic post events for the two types.
add_action(
	'wpsignal_loaded',
	static function (): void {
		if ( ! wordsocket_ready() ) {
			return;
		}
		$wps = WPS::instance();
		$wps->channels()->reserve( NS, '__return_true', '__return_false' );
		$wps->trigger_registry()->exclude_default_post_type( LIVEBLOG_TYPE );
		$wps->trigger_registry()->exclude_default_post_type( ENTRY_TYPE );
	}
);

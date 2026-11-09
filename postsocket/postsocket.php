<?php
/**
 * Plugin Name:       PostSocket
 * Plugin URI:        https://wpsignal.io/extensions/postsocket
 * Description:       Live blogs and articles that update in every reader's browser as you publish, powered by WordSocket and WPSignal realtime.
 * Version:           0.1.0
 * Author:            WPSignal
 * Author URI:        https://wpsignal.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       postsocket
 * Domain Path:       /languages
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Requires Plugins:  wordsocket
 *
 * @package WPSignal\Extensions\PostSocket
 */

namespace WPSignal\Extensions\PostSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.1.0';
const SLUG    = 'postsocket';
const DIR     = __DIR__ . '/';

// A const takes constant expressions only; define() is namespace aware.
define( __NAMESPACE__ . '\URL', plugin_dir_url( __FILE__ ) );

/**
 * The live blog post type.
 *
 * @since 0.1.0
 */
const LIVEBLOG_TYPE = 'postsocket_liveblog';

/**
 * The entry post type, non-public, parented to a live blog.
 *
 * @since 0.1.0
 */
const ENTRY_TYPE = 'postsocket_entry';

/**
 * Channel namespace every token reads and only PHP writes.
 *
 * @since 0.1.0
 */
const NS = 'postsocket';

/**
 * REST namespace.
 *
 * @since 0.1.0
 */
const REST_NS = 'postsocket/v1';

/**
 * The settings option.
 *
 * @since 0.1.0
 */
const OPTION = 'postsocket_settings';

/**
 * Post meta keys.
 *
 * @since 0.1.0
 */
const META_STATE   = '_postsocket_state';
const META_STARTED = '_postsocket_started';
const META_ENDED   = '_postsocket_ended';
const META_PINNED  = '_postsocket_pinned';
const META_HASH    = '_postsocket_content_hash';

/**
 * Oldest WordSocket this plugin runs with.
 *
 * @since 0.1.0
 */
const MIN_WORDSOCKET = '0.32.0';

/**
 * Whether the active WordSocket has what this plugin uses: a callable trigger channel and `WPS.onReconnect()` (API version 6).
 *
 * @since 0.1.0
 *
 * @return bool
 */
function wordsocket_ready(): bool {
	return class_exists( \WPSignal\WPS::class )
		&& defined( '\WPSignal\Client::API_VERSION' )
		&& \WPSignal\Client::API_VERSION >= 6
		&& method_exists( \WPSignal\Trigger::class, 'get_channel_label' );
}

require_once DIR . 'inc/settings.php';
require_once DIR . 'inc/post-types.php';
require_once DIR . 'inc/render.php';
require_once DIR . 'inc/channels.php';
require_once DIR . 'inc/triggers.php';
require_once DIR . 'inc/feed.php';
require_once DIR . 'inc/blocks.php';
require_once DIR . 'inc/living.php';
require_once DIR . 'inc/reader.php';
if ( is_admin() ) {
	require_once DIR . 'inc/admin.php';
}

register_activation_hook( __FILE__, __NAMESPACE__ . '\activate' );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

/**
 * Register the post types and flush the rewrite rules once.
 *
 * @since 0.1.0
 *
 * @return void
 */
function activate(): void {
	register_post_types();
	flush_rewrite_rules();
}

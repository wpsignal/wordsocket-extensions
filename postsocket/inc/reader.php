<?php
/**
 * The reader side: the script modules and stylesheet on live pages, the feed store's state, and the public client.
 *
 * @package WPSignal\Extensions\PostSocket
 */

namespace WPSignal\Extensions\PostSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Script module id prefix.
 *
 * @since 0.1.0
 */
const READER_MODULE = 'postsocket/reader';

/**
 * A built reader file's manifest: its content-hash version, or the plugin version on an unbuilt checkout.
 *
 * @since 0.1.0
 *
 * @param string $name File name under `build/reader/`.
 * @return array{dependencies: array, version: string}
 */
function reader_asset( string $name ): array {
	$manifest = DIR . 'build/reader/' . preg_replace( '/\.js$/', '', str_replace( '.css', '-css', $name ) ) . '.asset.php';
	return file_exists( $manifest ) ? require $manifest : array(
		'dependencies' => array(),
		'version'      => VERSION,
	);
}

/**
 * Whether this request is a page whose content readers see live: a live blog or an enabled post type's singular.
 *
 * @since 0.1.0
 *
 * @return bool
 */
function is_live_page(): bool {
	if ( is_admin() || ! is_singular() ) {
		return false;
	}
	return is_living( get_queried_object_id() );
}

// The client runs for every reader of a live page, and tokens refresh over REST.
add_filter(
	'wpsignal_allow_client',
	static function ( bool $allow ): bool {
		if ( $allow ) {
			return true;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		return is_live_page();
	}
);

// The modules, the stylesheet and the feed state.
add_action(
	'wp_enqueue_scripts',
	static function (): void {
		if ( ! is_live_page() ) {
			return;
		}
		$post_id = (int) get_queried_object_id();

		wp_register_script_module( READER_MODULE . '/assets', URL . 'build/reader/assets.js', array(), reader_asset( 'assets.js' )['version'] );
		wp_register_script_module( READER_MODULE . '/relay', URL . 'build/reader/relay.js', array(), reader_asset( 'relay.js' )['version'] );
		wp_register_script_module( READER_MODULE . '/morph', URL . 'build/reader/morph.js', array( READER_MODULE . '/assets' ), reader_asset( 'morph.js' )['version'] );
		wp_register_script_module(
			READER_MODULE . '/living',
			URL . 'build/reader/living.js',
			array( READER_MODULE . '/assets', READER_MODULE . '/relay', READER_MODULE . '/morph' ),
			reader_asset( 'living.js' )['version']
		);
		wp_register_script_module(
			READER_MODULE . '/feed',
			URL . 'build/reader/feed.js',
			array( '@wordpress/interactivity', READER_MODULE . '/assets', READER_MODULE . '/relay' ),
			reader_asset( 'feed.js' )['version']
		);
		wp_enqueue_script_module( READER_MODULE . '/living' );
		wp_enqueue_style( SLUG . '-reader', URL . 'build/reader/reader.css', array(), reader_asset( 'reader.css' )['version'] );

		if ( is_liveblog( $post_id ) ) {
			wp_enqueue_script_module( READER_MODULE . '/feed' );
			wp_interactivity_state(
				FEED_STORE,
				array(
					'channel'    => liveblog_channel( $post_id ),
					'entriesUrl' => rest_url( REST_NS . '/liveblog/' . $post_id . '/entries' ),
					'perPage'    => settings()['entries_per_page'],
					'i18n'       => array(
						'live'    => __( 'Live', 'postsocket' ),
						'ended'   => __( 'Ended', 'postsocket' ),
						/* translators: %d: number of new updates waiting above */
						'pending' => __( '%d new updates', 'postsocket' ),
						'one'     => __( '1 new update', 'postsocket' ),
					),
				)
			);
		}
	}
);

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
 * Register the reader's script modules once.
 *
 * @since 0.1.0
 *
 * @return void
 */
function register_reader_modules(): void {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	wp_register_script_module(
		READER_MODULE . '/assets',
		URL . 'build/reader/assets.js',
		array(
			array(
				'id'     => '@wordpress/interactivity',
				'import' => 'dynamic',
			),
		),
		reader_asset( 'assets.js' )['version']
	);
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
	wp_register_style( SLUG . '-reader', URL . 'build/reader/reader.css', array(), reader_asset( 'reader.css' )['version'] );
}

/**
 * Enqueue the feed's module and stylesheet; called by every feed that renders.
 *
 * @since 0.1.0
 *
 * @return void
 */
function enqueue_feed(): void {
	register_reader_modules();
	wp_enqueue_script_module( READER_MODULE . '/feed' );
	wp_enqueue_style( SLUG . '-reader' );
	feed_rendered( true );
}

/**
 * Whether a feed has rendered in this request.
 *
 * @since 0.1.0
 *
 * @param bool|null $set True to mark one; null reads.
 * @return bool
 */
function feed_rendered( ?bool $set = null ): bool {
	static $rendered = false;
	if ( true === $set ) {
		$rendered = true;
	}
	return $rendered;
}

/**
 * Whether this request is a page readers see live: a living post, or one a feed rendered on before the head.
 *
 * @since 0.1.0
 *
 * @return bool
 */
function is_live_page(): bool {
	if ( is_admin() ) {
		return false;
	}
	return feed_rendered() || ( is_singular() && is_living( get_queried_object_id() ) );
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

// The living module and the stylesheet on a living post's page.
add_action(
	'wp_enqueue_scripts',
	static function (): void {
		if ( ! is_singular() || ! is_living( get_queried_object_id() ) ) {
			return;
		}
		register_reader_modules();
		wp_enqueue_script_module( READER_MODULE . '/living' );
		wp_enqueue_style( SLUG . '-reader' );
	}
);

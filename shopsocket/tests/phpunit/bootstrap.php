<?php
/**
 * PHPUnit bootstrap: load a local WordPress with WooCommerce, WordSocket and
 * this plugin active. Refuses anything but a local or development environment.
 */

$wp_root = getenv( 'WP_ROOT' );
if ( ! $wp_root && is_readable( __DIR__ . '/../local.json' ) ) {
	$local   = json_decode( (string) file_get_contents( __DIR__ . '/../local.json' ), true );
	$wp_root = is_array( $local ) ? ( $local['wpRoot'] ?? '' ) : '';
}
if ( ! $wp_root || ! file_exists( $wp_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "No WordPress install: set WP_ROOT or wpRoot in tests/local.json (wp-load.php not found at '{$wp_root}').\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';

require_once $wp_root . '/wp-load.php';

if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	fwrite( STDERR, "Refusing to run: WP_ENVIRONMENT_TYPE is '" . wp_get_environment_type() . "', expected local or development.\n" );
	exit( 1 );
}
foreach ( array( 'WPSignal\\WPS' => 'WordSocket', 'WooCommerce' => 'WooCommerce' ) as $class => $label ) {
	if ( ! class_exists( $class ) ) {
		fwrite( STDERR, "{$label} is not active on the target site.\n" );
		exit( 1 );
	}
}
if ( ! defined( 'WPSignal\\Extensions\\ShopSocket\\VERSION' ) ) {
	fwrite( STDERR, "ShopSocket for WooCommerce is not active on the target site.\n" );
	exit( 1 );
}

require_once __DIR__ . '/ExtensionTestCase.php';

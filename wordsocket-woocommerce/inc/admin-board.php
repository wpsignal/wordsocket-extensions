<?php
/**
 * Live Orders: a WooCommerce submenu screen rendering the React board.
 *
 * The WordSocket client is enqueued by WordSocket itself on every admin page
 * for logged-in users (with the staff token carrying the orders namespace),
 * so the board only needs its own bundle and the initial order list.
 *
 * @package WPSignal\Extensions\WooCommerce
 */

namespace WPSignal\Extensions\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BOARD_SLUG   = 'wordsocket-live-orders';
const BOARD_CAP    = 'manage_woocommerce';
const BOARD_ORDERS = 50;

add_action(
	'admin_menu',
	static function (): void {
		add_submenu_page(
			'woocommerce',
			__( 'Live Orders', 'wordsocket-woocommerce' ),
			__( 'Live Orders', 'wordsocket-woocommerce' ),
			BOARD_CAP,
			BOARD_SLUG,
			__NAMESPACE__ . '\render_board',
			2
		);
	},
	60 // After WooCommerce has added its own submenu items.
);

/**
 * Orders for the board's first paint, newest first.
 *
 * @return array<int, array<string, mixed>>
 */
function initial_orders(): array {
	$orders = wc_get_orders(
		array(
			'limit'   => BOARD_ORDERS,
			'orderby' => 'date',
			'order'   => 'DESC',
			'type'    => 'shop_order',
		)
	);
	return array_values( array_map( __NAMESPACE__ . '\order_payload', array_filter( $orders, static fn( $o ) => $o instanceof \WC_Order ) ) );
}

/**
 * Statuses in WooCommerce's display order, as `{slug, label}` pairs
 * without the `wc-` prefix the board's events use.
 *
 * @return array<int, array{slug: string, label: string}>
 */
function order_statuses(): array {
	$out = array();
	foreach ( wc_get_order_statuses() as $key => $label ) {
		$out[] = array(
			'slug'  => str_starts_with( $key, 'wc-' ) ? substr( $key, 3 ) : $key,
			'label' => $label,
		);
	}
	return $out;
}

/**
 * Render the board screen and enqueue its bundle.
 *
 * @return void
 */
function render_board(): void {
	if ( ! current_user_can( BOARD_CAP ) ) {
		return;
	}

	$asset_file = DIR . 'build/board.asset.php';
	$asset      = file_exists( $asset_file ) ? require $asset_file : array(
		'dependencies' => array(),
		'version'      => VERSION,
	);

	wp_enqueue_script( SLUG . '-board', URL . 'build/board.js', $asset['dependencies'], $asset['version'], true );
	wp_set_script_translations( SLUG . '-board', 'wordsocket-woocommerce', DIR . 'languages' );
	wp_enqueue_style( SLUG . '-board', URL . 'build/board.css', array( 'wp-components' ), $asset['version'] );
	wp_style_add_data( SLUG . '-board', 'rtl', 'replace' );

	wp_add_inline_script(
		SLUG . '-board',
		'window.wordsocketWoo = ' . wp_json_encode(
			array(
				'restUrl'        => rest_url( 'wc/v3/' ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'orders'         => initial_orders(),
				'statuses'       => order_statuses(),
				'currencySymbol' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'soundDefault'   => true,
			)
		) . ';',
		'before'
	);

	echo '<div class="wrap"><div id="wordsocket-woo-board"></div></div>';
}

<?php
/**
 * ShopSocket dashboard under the WooCommerce menu: shoppers online, baskets
 * in progress, and the live orders board staff keep open all day.
 *
 * The WordSocket client is enqueued by WordSocket itself on every admin page
 * for logged-in users (with the staff token carrying the orders namespace),
 * so the screen only needs its own bundle, the initial order list, and a
 * snapshot of the figures (`dashboard.php`) it then keeps fresh through
 * `GET /dashboard`.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BOARD_ORDERS = 50;

add_action(
	'admin_menu',
	static function (): void {
		add_submenu_page(
			'woocommerce',
			__( 'ShopSocket', 'shopsocket' ),
			__( 'ShopSocket', 'shopsocket' ),
			STAFF_CAP,
			SLUG,
			__NAMESPACE__ . '\render_dashboard',
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
 * Render the dashboard screen and enqueue its bundle.
 *
 * @return void
 */
function render_dashboard(): void {
	if ( ! current_user_can( STAFF_CAP ) ) {
		return;
	}

	$asset_file = DIR . 'build/board.asset.php';
	$asset      = file_exists( $asset_file ) ? require $asset_file : array(
		'dependencies' => array(),
		'version'      => VERSION,
	);

	wp_enqueue_script( SLUG . '-board', URL . 'build/board.js', $asset['dependencies'], $asset['version'], true );
	wp_set_script_translations( SLUG . '-board', 'shopsocket', DIR . 'languages' );
	// The bundle carries its own @wordpress/components and DataViews styles
	// (wp-scripts writes package stylesheets to style-board.css).
	wp_enqueue_style( SLUG . '-board-vendor', URL . 'build/style-board.css', array(), $asset['version'] );
	wp_style_add_data( SLUG . '-board-vendor', 'rtl', 'replace' );
	wp_enqueue_style( SLUG . '-board', URL . 'build/board.css', array( SLUG . '-board-vendor' ), $asset['version'] );
	wp_style_add_data( SLUG . '-board', 'rtl', 'replace' );

	wp_add_inline_script(
		SLUG . '-board',
		'window.shopSocket = ' . wp_json_encode(
			array(
				'dashboardUrl'   => rest_url( REST_NS . '/dashboard' ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'orders'         => initial_orders(),
				'statuses'       => order_statuses(),
				'snapshot'       => dashboard_snapshot(),
				'currencySymbol' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			)
		) . ';',
		'before'
	);

	echo '<div class="wrap"><div id="shopsocket-board"></div></div>';
}

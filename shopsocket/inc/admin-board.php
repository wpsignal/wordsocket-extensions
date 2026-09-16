<?php
/**
 * The ShopSocket screen under the WooCommerce menu: online figures, baskets, live orders.
 *
 * WordSocket already enqueues its client on every admin page, so the screen
 * only adds its own bundle, the first page of orders, and a figures snapshot.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BOARD_ORDERS = 50;

// The "WooCommerce > ShopSocket" screen, for staff only.
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
 * Order statuses as `{slug, label}` pairs, without the `wc-` prefix (events carry the bare slug).
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
	// wp-scripts writes the bundled @wordpress/components and DataViews styles to style-board.css.
	wp_enqueue_style( SLUG . '-board-vendor', URL . 'build/style-board.css', array(), $asset['version'] );
	wp_style_add_data( SLUG . '-board-vendor', 'rtl', 'replace' );
	wp_enqueue_style( SLUG . '-board', URL . 'build/board.css', array( SLUG . '-board-vendor' ), $asset['version'] );
	wp_style_add_data( SLUG . '-board', 'rtl', 'replace' );

	/*
	 * Customer names land in this script: encode every HTML-significant
	 * character so nothing in the data can close the tag.
	 */
	wp_add_inline_script(
		SLUG . '-board',
		'window.shopSocket = ' . wp_json_encode(
			array(
				'dashboardUrl'   => rest_url( REST_NS . '/dashboard' ),
				'productsUrl'    => rest_url( REST_NS . '/products' ),
				'adminUrl'       => admin_url(),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'orders'         => initial_orders(),
				'statuses'       => order_statuses(),
				'snapshot'       => dashboard_snapshot(),
				'currencySymbol' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),

				/*
				 * The channels each event is trusted from; the board ignores the same
				 * event name arriving on any other channel.
				 */
				'channels'       => array(
					'orders'      => ORDERS_CHANNEL,
					'stock'       => STOCK_CHANNEL,
					'presence'    => CARTS_PRESENCE_CHANNEL,
					'connections' => CONNECTIONS_CHANNEL,
				),
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		) . ';',
		'before'
	);

	echo '<div class="wrap"><div id="shopsocket-board"></div></div>';
}

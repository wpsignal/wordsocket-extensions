<?php
/**
 * The ShopSocket screen under the WooCommerce menu (online figures, baskets,
 * live orders) and the card on WordSocket's Extensions tab.
 *
 * WordSocket already enqueues its client on every admin page, so the screen
 * only adds its own bundle, the first page of orders, and a figures snapshot.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use WPSignal\WPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BOARD_ORDERS = 50;
const UTM          = '?utm_source=wordpress&utm_medium=plugin&utm_campaign=shopsocket-board';

/**
 * Where the board lives: "Realtime" under Analytics, next to the store's other
 * numbers. Analytics is a WooCommerce feature owners can switch off, and then
 * its menu is gone, so the board falls back to the WooCommerce menu.
 *
 * @return array{parent: string, label: string, position: int}
 */
function board_menu(): array {
	$analytics = class_exists( FeaturesUtil::class ) && FeaturesUtil::feature_is_enabled( 'analytics' );
	return $analytics
		? array(
			'parent'   => 'wc-admin&path=/analytics/overview',
			'label'    => __( 'Realtime', 'shopsocket' ),
			'position' => 1,
		)
		: array(
			'parent'   => 'woocommerce',
			'label'    => __( 'ShopSocket', 'shopsocket' ),
			'position' => 2,
		);
}

// The board's menu entry, for staff only.
add_action(
	'admin_menu',
	static function (): void {
		$menu = board_menu();
		$hook = add_submenu_page(
			$menu['parent'],
			__( 'ShopSocket', 'shopsocket' ),
			$menu['label'],
			STAFF_CAP,
			SLUG,
			__NAMESPACE__ . '\render_dashboard',
			$menu['position']
		);
		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, __NAMESPACE__ . '\enqueue_page_styles' );
		}
	},
	60 // After WooCommerce has added its own submenu items.
);

/**
 * A link to the board on the plugin's row in the Plugins list.
 *
 * @param string[] $links The row's action links.
 * @return string[]
 */
function plugin_action_links( array $links ): array {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . SLUG ) ) . '">' . esc_html__( 'View', 'shopsocket' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . SLUG . '/shopsocket.php', __NAMESPACE__ . '\plugin_action_links' );

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

	// Too old a WordSocket: say so here, on this screen only, and leave the board out.
	if ( ! wordsocket_ready() ) {
		echo '<div class="wrap">';
		render_header();
		echo '<div class="notice notice-warning inline shopsocket-notice"><p>';
		printf(
			/* translators: 1: minimum WordSocket version, 2: link to the Plugins screen */
			esc_html__( 'ShopSocket needs WordSocket %1$s or newer. %2$s, then come back.', 'shopsocket' ),
			esc_html( MIN_WORDSOCKET ),
			'<a href="' . esc_url( admin_url( 'plugins.php?s=wordsocket&plugin_status=all' ) ) . '">' . esc_html__( 'Update WordSocket', 'shopsocket' ) . '</a>'
		);
		echo '</p></div>';
		echo '</div>';
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

	echo '<div class="wrap">';
	render_header();
	echo '<p class="shopsocket-intro">' . esc_html__( 'Your store as it happens: who is on the storefront, what is in their baskets, and every order the moment it is placed. Keep it open on a second screen; nothing here needs a refresh.', 'shopsocket' ) . '</p>';
	echo '<div id="shopsocket-board">';
	render_skeleton();
	echo '</div>';
	echo '</div>';
}

/**
 * The page's own stylesheet (header, intro, notice, skeleton), enqueued on the
 * page's `load-{hook}` action so it prints in `<head>`. Enqueued from the render
 * callback instead, it arrived as a late style in the footer, after the header
 * had painted: the logo showed at the full width of the screen, in the admin's
 * link blue, until the stylesheet caught up.
 *
 * @return void
 */
function enqueue_page_styles(): void {
	$asset_file = DIR . 'build/page.asset.php';
	$asset      = file_exists( $asset_file ) ? require $asset_file : array( 'version' => VERSION );
	wp_enqueue_style( SLUG . '-page', URL . 'build/page.css', array(), $asset['version'] );
	wp_style_add_data( SLUG . '-page', 'rtl', 'replace' );
}

/**
 * What the board looks like before its script runs: the same shapes, shimmering.
 * React replaces it on mount.
 *
 * @return void
 */
function render_skeleton(): void {
	$block = static fn( string $name, bool $invert = false ): string => '<div class="shopsocket-skeleton__' . $name . ' shopsocket-skeleton__shimmer' . ( $invert ? ' shopsocket-skeleton__shimmer-invert' : '' ) . '"></div>';
	echo '<div class="shopsocket-skeleton" aria-hidden="true">';
	echo '<div class="shopsocket-skeleton__controls">' . $block( 'badge', true ) . $block( 'toggle', true ) . $block( 'button', true ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
	echo '<div class="shopsocket-skeleton__summary">';
	echo '<div class="shopsocket-skeleton__tiles">';
	echo str_repeat( '<div class="shopsocket-skeleton__tile">' . $block( 'value' ) . $block( 'label' ) . $block( 'detail' ) . '</div>', 2 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
	echo '</div>';
	echo '<div class="shopsocket-skeleton__panel">' . str_repeat( $block( 'row' ), 4 ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
	echo '</div>';
	echo '<div class="shopsocket-skeleton__panel is-orders">' . $block( 'heading' ) . $block( 'search' ) . str_repeat( $block( 'row' ), 5 ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
	echo '</div>';
}

/**
 * The page header, in WordSocket's shape: the title, then the meta links and
 * the ShopSocket mark. The board's own controls render below it.
 *
 * @return void
 */
function render_header(): void {
	$new_tab   = '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'shopsocket' ) . '</span>';
	$dashboard = trailingslashit( WPS::instance()->config()->base_url() ) . 'dashboard';
	$links     = array(
		array( $dashboard . UTM, __( 'Dashboard', 'shopsocket' ) ),
		array( 'https://wpsignal.io/extensions/shopsocket/' . UTM, __( 'Documentation', 'shopsocket' ) ),
		array( 'https://wordpress.org/support/plugin/shopsocket/', __( 'Support', 'shopsocket' ) ),
	);

	echo '<header class="shopsocket-header">';
	echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';
	echo '<nav class="shopsocket-meta-nav" aria-label="' . esc_attr__( 'External links', 'shopsocket' ) . '">';
	foreach ( $links as list( $url, $label ) ) {
		echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . $new_tab . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $new_tab is escaped above.
		echo '<span aria-hidden="true"> / </span>';
	}
	echo '<a class="shopsocket-logo" href="' . esc_url( 'https://wpsignal.io/extensions/shopsocket/' . UTM ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr__( 'ShopSocket home (opens in a new tab)', 'shopsocket' ) . '">';
	// Sized in markup as well as CSS, so it is never drawn at the full width of the screen.
	echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 536.42 558.21" width="16" height="17" aria-hidden="true" focusable="false">';
	echo '<path fill="currentColor" d="M298.74,517.76c9.92,9.26,31.68,24.34,50.59,30.98,57.29,20.12,107.78,5.9,141.87-26.1,50.95-47.83,65.17-133.96,9.5-195.47,0,0-151.54-161.12-157.92-174.68-6.68-14.2-7.98-38.29,8.77-55.04,38.89-43.3,86.54.34,105.29,22.33l57.43-51.05c-69.47-108.12-247.3-89.47-258.43,61.42-.46,101.32,140.32,183.9,194.61,273.59,9.58,19.93,3.2,50.24-24.72,66.99-27.92,16.75-53.41-.83-63.81-9.57-62.96-52.93-256.17-269-274.38-308.68-16.75-36.51,21.95-85.05,62.22-71.78,20.74,4.78,51.84,39.08,51.84,39.08l57.43-51.05C189.59-39.38,11.7-20.72.59,130.16c-3.99,51.05,30.88,93.9,30.88,93.9,0,0,237.78,266.18,267.27,293.7Z"/>';
	echo '<path fill="currentColor" d="M250.94,511.13c-33.26,53.47-128.56,59.24-179.76,28.18C41.48,522.79,0,467.48,0,467.48l66.85-47.15c33.16,37.91,82.58,97.22,128.2,25.54l55.89,65.26Z"/>';
	echo '</svg>';
	echo '</a>';
	echo '</nav>';
	echo '</header>';
}

/**
 * The card on WordSocket's Extensions tab. Enqueued only on that screen, after
 * WordSocket's settings bundle, so `window.wordsocket` exists when it runs.
 *
 * @return void
 */
function enqueue_settings_panel(): void {
	$asset_file = DIR . 'build/settings.asset.php';
	$asset      = file_exists( $asset_file ) ? require $asset_file : array(
		'dependencies' => array( 'wp-plugins', 'wp-element', 'wp-i18n' ),
		'version'      => VERSION,
	);
	$deps       = array_values( array_unique( array_merge( $asset['dependencies'], array( 'wpsignal-settings' ) ) ) );

	wp_enqueue_script( SLUG . '-settings', URL . 'build/settings.js', $deps, $asset['version'], true );
	wp_set_script_translations( SLUG . '-settings', 'shopsocket', DIR . 'languages' );
	wp_enqueue_style( SLUG . '-settings', URL . 'build/settings.css', array( 'wpsignal-settings' ), $asset['version'] );
	wp_style_add_data( SLUG . '-settings', 'rtl', 'replace' );

	wp_add_inline_script(
		SLUG . '-settings',
		'window.shopSocketSettings = ' . wp_json_encode(
			array(
				'boardUrl' => admin_url( 'admin.php?page=' . SLUG ),
				'docsUrl'  => 'https://wpsignal.io/extensions/shopsocket',
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		) . ';',
		'before'
	);
}
add_action( 'wordsocket_settings_enqueue', __NAMESPACE__ . '\enqueue_settings_panel' );

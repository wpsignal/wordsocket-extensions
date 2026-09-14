<?php
/**
 * Plugin Name:       ShopSocket for WooCommerce
 * Plugin URI:        https://wpsignal.io/extensions/shopsocket
 * Description:       A live orders board for your team and live stock on product pages, powered by WordSocket and WPSignal realtime.
 * Version:           0.1.0
 * Author:            WPSignal
 * Author URI:        https://wpsignal.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shopsocket
 * Domain Path:       /languages
 * Requires at least: 6.7
 * Tested up to:      7.1
 * Requires PHP:      8.2
 * Requires Plugins:  wordsocket, woocommerce
 * WC requires at least: 9.0
 * WC tested up to:   11.1
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.1.0';
const SLUG    = 'shopsocket';
const DIR     = __DIR__ . '/';
const URL     = WP_PLUGIN_URL . '/' . SLUG . '/';

/** Capability that makes a user staff: the orders namespace, the dashboard route, and the board screen. */
const STAFF_CAP = 'manage_woocommerce';

/** Channel namespace reserved for staff (orders and low-stock events). */
const ORDERS_NS = 'woo:orders';
/** Public channel carrying stock changes to product pages. */
const STOCK_CHANNEL = 'woo:stock';
/** Public channel carrying anonymous shopper activity (added to basket). */
const ACTIVITY_CHANNEL = 'woo:activity';
/** The relay's own channel: `wps.connections` with the site's live connection count. */
const CONNECTIONS_CHANNEL = 'wps:connections';

/** Namespace shoppers enter presence on; membership is their live basket. */
const CARTS_NS = 'woo:carts';
/** The presence channel: staff subscribe, shoppers enter (and never subscribe). */
const CARTS_PRESENCE_CHANNEL = 'woo:carts:live';

/**
 * WooCommerce features this plugin is compatible with, declared before
 * WooCommerce checks them (`before_woocommerce_init`).
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

require_once DIR . 'inc/channels.php';
require_once DIR . 'inc/payloads.php';
require_once DIR . 'inc/carts.php';
require_once DIR . 'inc/triggers.php';
require_once DIR . 'inc/storefront.php';
require_once DIR . 'inc/dashboard.php';
if ( is_admin() ) {
	require_once DIR . 'inc/admin-board.php';
}

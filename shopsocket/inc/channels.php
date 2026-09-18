<?php
/**
 * Channels: the staff namespaces and the public channels every token carries.
 *
 * Reserving a namespace puts the site on WordSocket's strict channel list, so
 * every channel this plugin subscribes to must go through `wpsignal_token_channels`.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

use WPSignal\WPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Catalogue entry, the two reserved namespaces, and no generic post events for products.
add_action(
	'wpsignal_loaded',
	static function (): void {
		$wps = WPS::instance();

		$wps->extensions()->register(
			SLUG,
			array(
				'title'       => __( 'ShopSocket', 'shopsocket' ),
				'description' => __( 'A live orders board for your team and live stock on product pages.', 'shopsocket' ),
				'version'     => VERSION,
				'docs_url'    => 'https://wpsignal.io/extensions/shopsocket',
				'file'        => plugin_basename( DIR . 'shopsocket.php' ),
				'requires'    => array( 'woocommerce/woocommerce.php' => 'WooCommerce' ),
			)
		);

		/*
		 * Orders carry totals and customer names: staff only, and PHP is the
		 * only publisher, so no browser writes here.
		 */
		$wps->channels()->reserve( ORDERS_NS, STAFF_CAP, '__return_false' );

		/*
		 * Every shopper enters presence on the carts namespace, only staff may
		 * read it: presence carries a keyed basket id and the relay ties each
		 * membership to its own connection, so a token can only announce itself.
		 */
		$wps->channels()->reserve( CARTS_NS, STAFF_CAP, '__return_true' );

		/*
		 * Products are posts: without this, every product save would also
		 * publish WordSocket's generic post.updated next to woo.stock.changed.
		 */
		$wps->trigger_registry()->exclude_default_post_type( 'product' );
		$wps->trigger_registry()->exclude_default_post_type( 'product_variation' );
	}
);

/**
 * The public channels (stock and activity), for every token, visitors included.
 *
 * @param string[] $channels Channels the client auto-subscribes to.
 * @param int      $user_id  Token owner (0 for visitors).
 * @param string   $site_id  Hashed site identifier.
 * @return string[]
 */
function register_stock_channel( array $channels, int $user_id, string $site_id ): array {
	unset( $user_id );
	$channels[] = 'site:' . $site_id . ':' . STOCK_CHANNEL;
	$channels[] = 'site:' . $site_id . ':' . ACTIVITY_CHANNEL;
	return $channels;
}
// Every token, visitors included.
add_filter( 'wpsignal_token_channels', __NAMESPACE__ . '\register_stock_channel', 10, 3 );

/**
 * Staff channels, auto-subscribed so the board never calls subscribe itself.
 *
 * Skipped for everyone else: their tokens lack the orders prefix, so the
 * subscribe would only be refused.
 *
 * @param string[] $channels Channels the client auto-subscribes to.
 * @param int      $user_id  Token owner.
 * @param string   $site_id  Hashed site identifier.
 * @return string[]
 */
function register_orders_channel( array $channels, int $user_id, string $site_id ): array {
	if ( $user_id > 0 && user_can( $user_id, STAFF_CAP ) ) {
		$channels[] = 'site:' . $site_id . ':' . ORDERS_NS . ':feed';
		$channels[] = 'site:' . $site_id . ':' . CONNECTIONS_CHANNEL;
		$channels[] = 'site:' . $site_id . ':' . CARTS_PRESENCE_CHANNEL;
	}
	return $channels;
}
// Staff tokens only.
add_filter( 'wpsignal_token_channels', __NAMESPACE__ . '\register_orders_channel', 10, 3 );

<?php
/**
 * Channels: the reserved staff namespace and the public stock channel.
 *
 * Registered on `wpsignal_loaded`, which WordSocket fires on `plugins_loaded`
 * after its own boot. Reserving a namespace switches the site to WordSocket's
 * strict channel list, so every channel this plugin subscribes to must also be
 * registered through `wpsignal_token_channels`.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

use WPSignal\WPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wpsignal_loaded',
	static function (): void {
		$wps = WPS::instance();

		$wps->extensions()->register(
			SLUG,
			array(
				'title'       => __( 'ShopSocket for WooCommerce', 'shopsocket' ),
				'description' => __( 'A live orders board for your team and live stock on product pages.', 'shopsocket' ),
				'version'     => VERSION,
				'docs_url'    => 'https://wpsignal.io/extensions/shopsocket',
				'requires'    => array( 'woocommerce/woocommerce.php' => 'WooCommerce' ),
			)
		);

		// Orders carry totals and customer names: staff only.
		$wps->channels()->reserve( ORDERS_NS, STAFF_CAP );

		// Every shopper may enter presence on the carts namespace (write only:
		// they never subscribe, so no shopper sees another's membership). Staff
		// subscribe to read it. The grant is open because presence carries only
		// a non-reversible basket id, and the relay ties each membership to its
		// own connection, so a token can only ever announce itself.
		$wps->channels()->reserve( CARTS_NS, static fn(): bool => true );

		// Products are posts: without this, every product save would also
		// publish WordSocket's generic post.updated next to woo.stock.changed.
		$wps->trigger_registry()->exclude_default_post_type( 'product' );
		$wps->trigger_registry()->exclude_default_post_type( 'product_variation' );
	}
);

/**
 * The public stock channel, for every token (visitors included).
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
add_filter( 'wpsignal_token_channels', __NAMESPACE__ . '\register_stock_channel', 10, 3 );

/**
 * Staff channels, auto-subscribed so the dashboard needs no extra subscribe
 * call: the orders feed, and the relay's own `wps:connections` channel, which
 * carries `wps.connections` (browsers connected right now) whenever the count
 * changes. Non-staff tokens never get the orders prefix, so listing the
 * channel for them would only produce a refused subscribe: skip it.
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
add_filter( 'wpsignal_token_channels', __NAMESPACE__ . '\register_orders_channel', 10, 3 );

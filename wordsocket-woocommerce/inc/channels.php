<?php
/**
 * Channels: the reserved staff namespace and the public stock channel.
 *
 * Registered on `wpsignal_loaded`, which WordSocket fires on `plugins_loaded`
 * after its own boot. Reserving a namespace switches the site to WordSocket's
 * strict channel list, so every channel this plugin subscribes to must also be
 * registered through `wpsignal_token_channels`.
 *
 * @package WPSignal\Extensions\WooCommerce
 */

namespace WPSignal\Extensions\WooCommerce;

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
				'title'       => __( 'WooCommerce', 'wordsocket-woocommerce' ),
				'description' => __( 'A live orders board for your team and live stock on product pages.', 'wordsocket-woocommerce' ),
				'version'     => VERSION,
				'docs_url'    => 'https://wpsignal.io/extensions/woocommerce',
				'requires'    => array( 'woocommerce/woocommerce.php' => 'WooCommerce' ),
			)
		);

		// Orders carry totals and customer names: staff only.
		$wps->channels()->reserve( ORDERS_NS, 'manage_woocommerce' );

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
 * The orders channel, auto-subscribed for staff so the board needs no extra
 * subscribe call. Non-staff tokens never get the prefix, so listing the
 * channel for them would only produce a refused subscribe: skip it.
 *
 * @param string[] $channels Channels the client auto-subscribes to.
 * @param int      $user_id  Token owner.
 * @param string   $site_id  Hashed site identifier.
 * @return string[]
 */
function register_orders_channel( array $channels, int $user_id, string $site_id ): array {
	if ( $user_id > 0 && user_can( $user_id, 'manage_woocommerce' ) ) {
		$channels[] = 'site:' . $site_id . ':' . ORDERS_NS . ':feed';
	}
	return $channels;
}
add_filter( 'wpsignal_token_channels', __NAMESPACE__ . '\register_orders_channel', 10, 3 );

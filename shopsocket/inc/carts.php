<?php
/**
 * The basket store: one row per anonymous shopper, holding what is in their
 * cart. Whether a basket is live or abandoned is not decided here: the dashboard
 * crosses these rows with the relay's presence membership (who has an open tab
 * right now), keyed by the same `basket_id`. So this file only tracks contents.
 *
 * WooCommerce keeps a shopper's cart in their session for the session lifetime
 * (48h by default) whether or not they are on the site, so a row persists until
 * the session would, then ages out. Contents are read server-side from the
 * shopper's own request (`current_cart_state()`), never trusted from the client.
 * Any change republishes the rows to staff so the dashboard updates at once.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

use WC_Cart;
use WPSignal\WPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BASKETS_TRANSIENT = 'shopsocket_baskets';
const BASKETS_PUB_HASH  = 'shopsocket_baskets_pub';

/**
 * Seconds a basket row survives without a cart change: the WooCommerce session
 * lifetime, so an abandoned basket drops when its session would.
 *
 * @return int
 */
function basket_lifetime(): int {
	return max( 60, (int) apply_filters( 'wc_session_expiration', 2 * DAY_IN_SECONDS ) );
}

/**
 * All basket rows, pruned to the session lifetime and to non-empty carts:
 * basket id => row.
 *
 * @return array<string, array<string, mixed>>
 */
function read_baskets(): array {
	$baskets = get_transient( BASKETS_TRANSIENT );
	if ( ! is_array( $baskets ) ) {
		return array();
	}
	$cutoff = time() - basket_lifetime();
	$live   = array();
	foreach ( $baskets as $id => $row ) {
		if ( is_array( $row ) && ! empty( $row['products'] ) && (int) ( $row['last_seen'] ?? 0 ) >= $cutoff ) {
			$live[ $id ] = $row;
		}
	}
	return $live;
}

/**
 * Persist the basket map (or drop it when empty).
 *
 * @param array<string, array<string, mixed>> $baskets Rows by basket id.
 * @return void
 */
function write_baskets( array $baskets ): void {
	if ( empty( $baskets ) ) {
		delete_transient( BASKETS_TRANSIENT );
		return;
	}
	set_transient( BASKETS_TRANSIENT, $baskets, basket_lifetime() );
}

/**
 * The requester's own cart as a row, or null when it is empty or not loaded.
 * Read server-side from the session the request carries, never from the
 * client. Never loads the cart itself: inside the cart hooks it already
 * exists, and the `basket-id` endpoint loads it only for a request that
 * carries a WooCommerce session, so a first-time visitor never gets a session
 * (and a fresh random basket id) just for asking.
 *
 * @return array{products: int[], value: float}|null
 */
function current_cart_state(): ?array {
	if ( ! function_exists( 'WC' ) ) {
		return null;
	}
	$cart = WC()->cart;
	if ( ! $cart instanceof WC_Cart ) {
		return null;
	}
	$items = $cart->get_cart();
	if ( empty( $items ) ) {
		return null;
	}

	$products = array();
	$value    = 0.0;
	foreach ( $items as $item ) {
		$parent = cart_item_parent_id( $item );
		if ( $parent ) {
			$products[ $parent ] = true;
		}
		$product = $item['data'] ?? null;
		$price   = $product instanceof \WC_Product ? (float) $product->get_price() : 0.0;
		$value  += $price * (int) ( $item['quantity'] ?? 1 );
	}

	return array(
		'products' => array_map( 'intval', array_keys( $products ) ),
		'value'    => round( $value, 2 ),
	);
}

/**
 * Upsert the requester's basket row from their real cart (or forget it when the
 * cart is empty). Republishes the rows when they change.
 *
 * @param string $id Basket id (see `basket_id()`).
 * @return void
 */
function record_cart( string $id ): void {
	if ( '' === $id ) {
		return;
	}
	$baskets = read_baskets();
	$state   = current_cart_state();

	if ( null === $state ) {
		if ( isset( $baskets[ $id ] ) ) {
			unset( $baskets[ $id ] );
			write_baskets( $baskets );
			publish_baskets_changed();
		}
		return;
	}

	$baskets[ $id ] = array(
		'products'  => $state['products'],
		'value'     => $state['value'],
		'currency'  => get_woocommerce_currency(),
		'last_seen' => time(),
	);
	write_baskets( $baskets );
	publish_baskets_changed();
}

/**
 * Forget the requester's basket entirely (checkout or an emptied cart).
 *
 * @param string $id Basket id.
 * @return void
 */
function forget_cart( string $id ): void {
	if ( '' === $id ) {
		return;
	}
	$baskets = read_baskets();
	if ( isset( $baskets[ $id ] ) ) {
		unset( $baskets[ $id ] );
		write_baskets( $baskets );
		publish_baskets_changed();
	}
}

/**
 * Every basket row for the dashboard: `{ id, products, value }`. The dashboard
 * splits these into live and abandoned using the relay's presence membership.
 *
 * @return array<int, array{id: string, products: int[], value: float, currency: string}>
 */
function all_baskets(): array {
	$rows = array();
	foreach ( read_baskets() as $id => $row ) {
		$rows[] = array(
			'id'       => (string) $id,
			'products' => array_map( 'intval', (array) ( $row['products'] ?? array() ) ),
			'value'    => round( (float) ( $row['value'] ?? 0 ), 2 ),
			'currency' => (string) ( $row['currency'] ?? get_woocommerce_currency() ),
		);
	}
	return $rows;
}

/**
 * Shoppers with the product (any variation) in their cart. Counts every basket
 * that holds it, present or not: the storefront has no view of presence.
 *
 * @param int $product_id Parent product ID.
 * @return int
 */
function count_in_carts( int $product_id ): int {
	$count = 0;
	foreach ( read_baskets() as $row ) {
		if ( in_array( $product_id, array_map( 'intval', (array) ( $row['products'] ?? array() ) ), true ) ) {
			++$count;
		}
	}
	return $count;
}

/**
 * Publish the basket rows to staff when they have changed since the last
 * publish, so the dashboard updates without waiting for its poll.
 *
 * @return void
 */
function publish_baskets_changed(): void {
	$rows = all_baskets();
	$hash = md5( (string) wp_json_encode( $rows ) );
	if ( get_transient( BASKETS_PUB_HASH ) === $hash ) {
		return;
	}
	set_transient( BASKETS_PUB_HASH, $hash, basket_lifetime() );
	WPS::publish( ORDERS_CHANNEL, 'woo.baskets', array( 'baskets' => $rows ) );
}

/**
 * Parent product ID for a cart line.
 *
 * @param array $item Cart item.
 * @return int
 */
function cart_item_parent_id( array $item ): int {
	return (int) ( $item['product_id'] ?? 0 );
}

// A cart change in the shopper's own request: recapture their basket row.
add_action(
	'woocommerce_add_to_cart',
	static function (): void {
		record_cart( basket_id() );
	},
	5
);

add_action(
	'woocommerce_cart_item_removed',
	static function ( $cart_item_key, WC_Cart $cart ): void {
		$item = $cart->removed_cart_contents[ $cart_item_key ] ?? null;
		record_cart( basket_id() );
		if ( is_array( $item ) ) {
			publish_cart_removed( cart_item_parent_id( $item ), (int) ( $item['variation_id'] ?? 0 ) );
		}
	},
	10,
	2
);

add_action(
	'woocommerce_cart_item_set_quantity',
	static function (): void {
		record_cart( basket_id() );
	},
	20
);

// Checkout or an explicit clear empties the cart: forget the basket, then tell
// each product page the new count (forget first so this shopper is not counted).
add_action(
	'woocommerce_before_cart_emptied',
	static function (): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$parents = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$parent = cart_item_parent_id( $item );
			if ( $parent && ! isset( $parents[ $parent ] ) ) {
				$parents[ $parent ] = (int) ( $item['variation_id'] ?? 0 );
			}
		}
		forget_cart( basket_id() );
		foreach ( $parents as $parent => $variation_id ) {
			publish_cart_removed( $parent, $variation_id );
		}
	}
);

/**
 * Tell product pages the new per-product count after a removal.
 *
 * @param int $product_id   Parent product ID.
 * @param int $variation_id Variation that was in the cart, 0 for simple products.
 * @return void
 */
function publish_cart_removed( int $product_id, int $variation_id ): void {
	if ( ! $product_id ) {
		return;
	}
	WPS::publish(
		ACTIVITY_CHANNEL,
		'woo.cart.removed',
		array(
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'in_carts'     => count_in_carts( $product_id ),
		)
	);
}

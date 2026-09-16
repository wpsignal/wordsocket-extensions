<?php
/**
 * Basket rows: what each shopper has in their cart, keyed by `basket_id()`.
 *
 * Only contents live here. Live or abandoned is decided by the dashboard,
 * which crosses these rows with the relay's presence membership. A row is
 * read from the shopper's own request and lasts as long as their session.
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
 * Seconds a row survives without a cart change: the WooCommerce session lifetime.
 *
 * @return int
 */
function basket_lifetime(): int {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, read for its value.
	return max( 60, (int) apply_filters( 'wc_session_expiration', 2 * DAY_IN_SECONDS ) );
}

/**
 * Basket rows by id, dropping empty carts and rows past the session lifetime.
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
 * The requester's own cart as a row, or null when empty or not loaded.
 *
 * Never loads the cart: a first-time visitor must not get a session (and a
 * random basket id) just for asking. The `basket-id` endpoint loads it only
 * for a request that already carries one.
 *
 * @return array{products: int[], quantities: array<int, int>, value: float}|null
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

	$quantities = array();
	$value      = 0.0;
	foreach ( $items as $item ) {
		$quantity = max( 1, (int) ( $item['quantity'] ?? 1 ) );
		$parent   = cart_item_parent_id( $item );
		if ( $parent ) {
			// Variations of one product count together under the parent.
			$quantities[ $parent ] = ( $quantities[ $parent ] ?? 0 ) + $quantity;
		}
		$product = $item['data'] ?? null;
		$price   = $product instanceof \WC_Product ? (float) $product->get_price() : 0.0;
		$value  += $price * $quantity;
	}

	return array(
		'products'   => array_map( 'intval', array_keys( $quantities ) ),
		'quantities' => $quantities,
		'value'      => round( $value, 2 ),
	);
}

/**
 * Upsert the requester's row from their cart, or drop it when the cart is empty.
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
		'products'   => $state['products'],
		'quantities' => $state['quantities'],
		'value'      => $state['value'],
		'currency'   => get_woocommerce_currency(),
		'last_seen'  => time(),
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
 * Every row for the dashboard: `{ id, products, value, currency }`.
 *
 * @return array<int, array{id: string, products: int[], quantities: array<int, int>, value: float, currency: string}>
 */
function all_baskets(): array {
	$rows = array();
	foreach ( read_baskets() as $id => $row ) {
		$products = array_map( 'intval', (array) ( $row['products'] ?? array() ) );
		$stored   = (array) ( $row['quantities'] ?? array() );
		// A row written before quantities were kept counts one unit per product.
		$quantities = array();
		foreach ( $products as $product_id ) {
			$quantities[ $product_id ] = max( 1, (int) ( $stored[ $product_id ] ?? 1 ) );
		}
		$rows[] = array(
			'id'         => (string) $id,
			'products'   => $products,
			'quantities' => $quantities,
			'value'      => round( (float) ( $row['value'] ?? 0 ), 2 ),
			'currency'   => (string) ( $row['currency'] ?? get_woocommerce_currency() ),
		);
	}
	return $rows;
}

/**
 * Shoppers holding the product (any variation), present or not: WP has no view of presence.
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
 * Publish the rows to staff when they differ from the last publish.
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

// A line removed: recapture the row, then tell product pages the new count.
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

// A quantity change: the row's value moves even when its product set does not.
add_action(
	'woocommerce_cart_item_set_quantity',
	static function (): void {
		record_cart( basket_id() );
	},
	20
);

/*
 * An emptied cart (checkout or clear): forget the basket first, so this shopper
 * is not counted, then tell each product page the new count.
 */
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

<?php
/**
 * Cart rows: what each shopper has in their cart, keyed by `cart_id()`, in
 * the tables `inc/history.php` creates.
 *
 * Only contents and the lifecycle stamps live here. Live or abandoned is
 * decided by the dashboard, which crosses these rows with the relay's
 * presence membership. A row is written from the shopper's own request and,
 * with history off, lasts as long as their session.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

use WC_Cart;
use WPSignal\WPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- The plugin's own tables: names come from the table functions, fragments from prepare(), one placeholder per id.

/** The hash of the last `woo.carts` publish, so staff only hear about a change. */
const CARTS_PUB_HASH = 'shopsocket_carts_pub';

/**
 * Seconds a row survives without a cart change: the WooCommerce session lifetime.
 *
 * @return int
 */
function cart_lifetime(): int {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, read for its value.
	return max( 60, (int) apply_filters( 'wc_session_expiration', 2 * DAY_IN_SECONDS ) );
}

/**
 * The requester's own cart as a row, or null when empty or not loaded.
 *
 * Never loads the cart: a first-time visitor must not get a session (and a
 * random cart id) just for asking. The `cart-id` endpoint loads it only
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
 * @param string $id      Cart id (see `cart_id()`).
 * @param bool   $restart Whether this is an add to cart, which starts a converted row over as a new cart.
 * @return void
 */
function record_cart( string $id, bool $restart = false ): void {
	if ( '' === $id ) {
		return;
	}
	$state = current_cart_state();
	if ( null === $state ) {
		forget_cart( $id );
		return;
	}
	upsert_cart_row( $id, $state, '', $restart );
	publish_carts_changed();
}

/**
 * Forget the requester's cart (checkout or an emptied cart). With history on,
 * a converted row stays as the record of its order.
 *
 * @param string $id Cart id.
 * @return void
 */
function forget_cart( string $id ): void {
	if ( '' === $id ) {
		return;
	}
	$row = cart_row( $id );
	if ( null === $row ) {
		return;
	}
	if ( history_enabled() && null !== $row['converted_at'] ) {
		return;
	}
	if ( delete_cart_row( $id ) ) {
		publish_carts_changed();
	}
}

/**
 * Every open cart for the dashboard: `{ id, products, quantities, value, currency }`.
 *
 * @return array<int, array{id: string, products: int[], quantities: array<int, int>, value: float, currency: string}>
 */
function cart_rows(): array {
	global $wpdb;
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - cart_lifetime() );
	$carts  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT cart_id, value, currency FROM ' . carts_table() . ' WHERE converted_at IS NULL AND last_seen >= %s ORDER BY last_seen DESC', $cutoff ), ARRAY_A );
	$items  = cart_items( array_map( static fn( array $row ): string => (string) $row['cart_id'], $carts ) );
	$rows   = array();
	foreach ( $carts as $cart ) {
		$id         = (string) $cart['cart_id'];
		$quantities = $items[ $id ] ?? array();
		if ( empty( $quantities ) ) {
			continue;
		}
		$rows[] = array(
			'id'         => $id,
			'products'   => array_keys( $quantities ),
			'quantities' => $quantities,
			'value'      => round( (float) $cart['value'], 2 ),
			'currency'   => (string) $cart['currency'],
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
	global $wpdb;

	/*
	 * Cached for a minute under the write generation: a product page may ask for
	 * several products, and every write to the tables starts a new generation,
	 * so a count is never stale after a change and never older than a minute
	 * otherwise (a session expiring is the only change that is not a write).
	 */
	$key    = 'in_carts_' . cache_generation() . '_' . $product_id;
	$cached = wp_cache_get( $key, CACHE_GROUP );
	if ( false !== $cached ) {
		return (int) $cached;
	}
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - cart_lifetime() );
	$count  = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM ' . cart_items_table() . ' i INNER JOIN ' . carts_table() . ' c ON c.cart_id = i.cart_id WHERE i.product_id = %d AND c.converted_at IS NULL AND c.last_seen >= %s',
			$product_id,
			$cutoff
		)
	);
	wp_cache_set( $key, $count, CACHE_GROUP, MINUTE_IN_SECONDS );
	return $count;
}

/**
 * Publish the open and recently abandoned rows to staff when they differ from the last publish.
 *
 * @return void
 */
function publish_carts_changed(): void {
	$payload = array(
		'carts'     => cart_rows(),
		'abandoned' => abandoned_rows(),
	);
	$hash    = md5( (string) wp_json_encode( $payload ) );
	if ( get_transient( CARTS_PUB_HASH ) === $hash ) {
		return;
	}
	set_transient( CARTS_PUB_HASH, $hash, cart_lifetime() );
	WPS::publish( ORDERS_CHANNEL, 'woo.carts', $payload );
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

// An add in the shopper's own request: recapture their cart row, and a converted row starts over.
add_action(
	'woocommerce_add_to_cart',
	static function (): void {
		record_cart( cart_id(), true );
	},
	5
);

// A line removed: recapture the row, then tell product pages the new count.
add_action(
	'woocommerce_cart_item_removed',
	static function ( $cart_item_key, WC_Cart $cart ): void {
		$item = $cart->removed_cart_contents[ $cart_item_key ] ?? null;
		record_cart( cart_id() );
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
		record_cart( cart_id() );
	},
	20
);

/*
 * An emptied cart (checkout or clear): forget the cart first, so this shopper
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
		forget_cart( cart_id() );
		foreach ( $parents as $parent => $variation_id ) {
			publish_cart_removed( $parent, $variation_id );
		}
	}
);

/**
 * Tell product pages the new per-product count after a removal. `actor` is
 * the remover's anonymous cart id, so their own pages know the item left
 * their cart (the count alone cannot say whose cart changed).
 *
 * @param int $product_id   Parent product ID.
 * @param int $variation_id Variation that was in the cart, 0 for simple products.
 * @return void
 */
function publish_cart_removed( int $product_id, int $variation_id ): void {
	if ( ! $product_id || ! in_carts_enabled( $product_id ) ) {
		return;
	}
	WPS::publish(
		ACTIVITY_CHANNEL,
		'woo.cart.removed',
		array(
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'in_carts'     => count_in_carts( $product_id ),
			'actor'        => cart_id(),
		)
	);
}

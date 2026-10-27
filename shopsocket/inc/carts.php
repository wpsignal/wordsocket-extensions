<?php
/**
 * Live carts: what each shopper has in their cart, read from WordSocket's open journeys by `cart_id()`.
 *
 * - Contents only; the dashboard decides live or abandoned from presence.
 * - The journey row is written by WordSocket from the shopper's reports; the cart hooks here only publish.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

use WC_Cart;
use WPSignal\WPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- WordSocket's journeys table: the name from its class, fragments from prepare(), one placeholder per id.

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
 * Every open cart for the dashboard, from each cart's newest journey within the session lifetime, unless that journey converted: `{ id, products, quantities, value, currency }`. The dashboard decides live or abandoned from presence.
 *
 * @since 0.8.0 Read from WordSocket's journeys.
 *
 * @return array<int, array{id: string, products: int[], quantities: array<int, int>, value: float, currency: string}>
 */
function cart_rows(): array {
	global $wpdb;
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - cart_lifetime() );
	$rows   = (array) $wpdb->get_results(
		$wpdb->prepare(
			'SELECT ref, value, data, outcome, last_seen FROM ' . journeys_table() . ' WHERE ref IS NOT NULL AND data IS NOT NULL AND last_seen >= %s ORDER BY last_seen DESC',
			$cutoff
		),
		ARRAY_A
	);
	$by_ref = array();
	foreach ( $rows as $row ) {
		$by_ref[ (string) $row['ref'] ][] = $row;
	}
	$out = array();
	foreach ( $by_ref as $id => $journeys ) {
		$newest = $journeys[0];
		// A cart that became an order is closed, however its leave and its order are ordered within the same second.
		$converted = array_filter( $journeys, static fn( array $row ): bool => 'converted' === $row['outcome'] && $row['last_seen'] >= $newest['last_seen'] );
		$quantities = items_of( $newest['data'] );
		if ( empty( $quantities ) || ! empty( $converted ) || ( 'open' !== $newest['outcome'] && 'left' !== $newest['outcome'] ) ) {
			continue;
		}
		$row = $newest;
		$out[ $id ] = array(
			'id'         => $id,
			'products'   => array_keys( $quantities ),
			'quantities' => $quantities,
			'value'      => round( (float) ( $row['value'] ?? 0 ), 2 ),
			'currency'   => (string) ( data_field( $row['data'], 'c' ) ?? get_woocommerce_currency() ),
		);
	}
	return array_values( $out );
}

/**
 * Shoppers holding the product (any variation) in an open cart, present or not, leaving out the cart named by `$exclude_ref` so a hook can count itself in or out before its own report lands.
 *
 * @since 0.8.0 Read from WordSocket's journeys; takes `$exclude_ref`.
 *
 * @param int    $product_id  Parent product ID.
 * @param string $exclude_ref A cart id not to count, '' for none.
 * @return int
 */
function count_in_carts( int $product_id, string $exclude_ref = '' ): int {
	global $wpdb;

	/*
	 * Cached for a minute under the write generation: a product page may ask for
	 * several products, and every cart change starts a new generation, so a count
	 * is never stale after a change and never older than a minute otherwise.
	 */
	$key    = 'in_carts_' . cache_generation() . '_' . $product_id . '_' . $exclude_ref;
	$cached = wp_cache_get( $key, CACHE_GROUP );
	if ( false !== $cached ) {
		return (int) $cached;
	}
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - cart_lifetime() );
	$count  = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(DISTINCT ref) FROM ' . journeys_table() . " WHERE outcome IN ('open', 'left') AND ref IS NOT NULL AND last_seen >= %s AND data LIKE %s AND ref <> %s",
			$cutoff,
			'%' . $wpdb->esc_like( '"' . $product_id . '":' ) . '%',
			$exclude_ref
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

// A line removed: tell product pages the new count; the shopper's next report updates their row.
add_action(
	'woocommerce_cart_item_removed',
	static function ( $cart_item_key, WC_Cart $cart ): void {
		$item = $cart->removed_cart_contents[ $cart_item_key ] ?? null;
		if ( is_array( $item ) ) {
			publish_cart_removed( cart_item_parent_id( $item ), (int) ( $item['variation_id'] ?? 0 ) );
		}
	},
	10,
	2
);

/*
 * An emptied cart (checkout or clear): tell each product page the new count,
 * this shopper counted out.
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
		foreach ( $parents as $parent => $variation_id ) {
			publish_cart_removed( $parent, $variation_id, false );
		}
	}
);

/**
 * Tell product pages the new per-product count after a removal. `actor` is
 * the remover's anonymous cart id, so their own pages know the item left
 * their cart (the count alone cannot say whose cart changed).
 *
 * @since 0.8.0 Counts this cart from its real contents, since its row updates on the next report.
 *
 * @param int  $product_id   Parent product ID.
 * @param int  $variation_id Variation that was in the cart, 0 for simple products.
 * @param bool $self         Whether this cart may still hold the product (another variation or line); false for an emptied cart.
 * @return void
 */
function publish_cart_removed( int $product_id, int $variation_id, bool $self = true ): void {
	if ( ! $product_id || ! in_carts_enabled( $product_id ) ) {
		return;
	}
	$state = $self ? current_cart_state() : null;
	$held  = null !== $state && isset( $state['quantities'][ $product_id ] ) ? 1 : 0;
	WPS::publish(
		ACTIVITY_CHANNEL,
		'woo.cart.removed',
		array(
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'in_carts'     => count_in_carts( $product_id, cart_id() ) + $held,
			'actor'        => cart_id(),
		)
	);
}

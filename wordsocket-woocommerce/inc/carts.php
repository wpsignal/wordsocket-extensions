<?php
/**
 * How many shoppers currently have a product in their cart.
 *
 * WooCommerce keeps carts in sessions, which cannot be counted per product
 * without scanning every serialised cart. Instead this file keeps, per parent
 * product, the anonymous session hashes that added it and when: added on
 * `woocommerce_add_to_cart`, dropped when the item is removed or the cart is
 * emptied (checkout empties it), and aged out after the WooCommerce session
 * lifetime so abandoned carts stop counting. Approximate by design; it costs
 * one transient read and write per cart change.
 *
 * @package WPSignal\Extensions\WooCommerce
 */

namespace WPSignal\Extensions\WooCommerce;

use WC_Cart;
use WPSignal\WPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CARTS_TRANSIENT = 'wswoo_carts_';

/**
 * Seconds an entry stays counted without activity: the WooCommerce session lifetime.
 *
 * @return int
 */
function cart_entry_lifetime(): int {
	return max( 60, (int) apply_filters( 'wc_session_expiration', 2 * DAY_IN_SECONDS ) );
}

/**
 * Live entries for a product: actor hash => last activity timestamp.
 *
 * @param int $product_id Parent product ID.
 * @return array<string, int>
 */
function cart_entries( int $product_id ): array {
	$entries = get_transient( CARTS_TRANSIENT . $product_id );
	if ( ! is_array( $entries ) ) {
		return array();
	}
	$cutoff = time() - cart_entry_lifetime();
	return array_filter( $entries, static fn( $ts ) => (int) $ts >= $cutoff );
}

/**
 * Persist entries (or drop the transient when empty).
 *
 * @param int                $product_id Parent product ID.
 * @param array<string, int> $entries    Live entries.
 * @return void
 */
function save_cart_entries( int $product_id, array $entries ): void {
	if ( empty( $entries ) ) {
		delete_transient( CARTS_TRANSIENT . $product_id );
		return;
	}
	set_transient( CARTS_TRANSIENT . $product_id, $entries, cart_entry_lifetime() );
}

/**
 * Shoppers with the product (any variation) in their cart right now.
 *
 * @param int $product_id Parent product ID.
 * @return int
 */
function count_in_carts( int $product_id ): int {
	return count( cart_entries( $product_id ) );
}

/**
 * Record that a shopper has the product in their cart.
 *
 * @param int    $product_id Parent product ID.
 * @param string $actor      Anonymous session hash ('' when there is no session).
 * @return int New count.
 */
function track_cart_add( int $product_id, string $actor ): int {
	$entries = cart_entries( $product_id );
	if ( '' !== $actor ) {
		$entries[ $actor ] = time();
		save_cart_entries( $product_id, $entries );
	}
	return count( $entries );
}

/**
 * Record that a shopper no longer has the product in their cart.
 *
 * @param int    $product_id Parent product ID.
 * @param string $actor      Anonymous session hash.
 * @return int New count.
 */
function track_cart_remove( int $product_id, string $actor ): int {
	$entries = cart_entries( $product_id );
	if ( isset( $entries[ $actor ] ) ) {
		unset( $entries[ $actor ] );
		save_cart_entries( $product_id, $entries );
	}
	return count( $entries );
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

// Count before the trigger publishes (triggers run at priority 10).
add_action(
	'woocommerce_add_to_cart',
	static function ( $cart_item_key, $product_id ): void {
		unset( $cart_item_key );
		track_cart_add( (int) $product_id, actor_hash() );
	},
	5,
	2
);

/**
 * A removed line: untrack and publish the new count. Quantity set to zero
 * arrives here too (WooCommerce removes the line).
 */
add_action(
	'woocommerce_cart_item_removed',
	static function ( $cart_item_key, WC_Cart $cart ): void {
		$item = $cart->removed_cart_contents[ $cart_item_key ] ?? null;
		if ( ! is_array( $item ) ) {
			return;
		}
		publish_cart_removed( cart_item_parent_id( $item ), (int) ( $item['variation_id'] ?? 0 ) );
	},
	10,
	2
);

/**
 * The cart is about to be emptied (checkout, or the shopper clearing it):
 * untrack every line while the contents are still readable.
 */
add_action(
	'woocommerce_before_cart_emptied',
	static function (): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$seen = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$parent = cart_item_parent_id( $item );
			if ( $parent && ! isset( $seen[ $parent ] ) ) {
				$seen[ $parent ] = true;
				publish_cart_removed( $parent, (int) ( $item['variation_id'] ?? 0 ) );
			}
		}
	}
);

/**
 * Untrack and tell product pages the new count.
 *
 * @param int $product_id   Parent product ID.
 * @param int $variation_id Variation that was in the cart, 0 for simple products.
 * @return void
 */
function publish_cart_removed( int $product_id, int $variation_id ): void {
	if ( ! $product_id ) {
		return;
	}
	$count = track_cart_remove( $product_id, actor_hash() );
	WPS::publish(
		ACTIVITY_CHANNEL,
		'woo.cart.removed',
		array(
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'in_carts'     => $count,
		)
	);
}

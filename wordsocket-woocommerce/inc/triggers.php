<?php
/**
 * WooCommerce hooks published as WordSocket events.
 *
 * Staff channel (`woo:orders:feed`): order created, paid, status changed,
 * low stock, out of stock. Public channel (`woo:stock`): stock changed.
 *
 * @package WPSignal\Extensions\WooCommerce
 */

namespace WPSignal\Extensions\WooCommerce;

use WC_Product;
use WPSignal\WPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ORDERS_CHANNEL = ORDERS_NS . ':feed';

/** Seconds between publishes for the same product. Filter `wordsocket_woocommerce_activity_throttle`. */
const ACTIVITY_THROTTLE = 10;

/**
 * Whether an add-to-cart should be published: a purchasable product, the
 * feature enabled, and the per-product throttle window elapsed.
 *
 * @param string $cart_item_key Cart item key.
 * @param int    $product_id    Product ID.
 * @param int    $quantity      Quantity.
 * @param int    $variation_id  Variation ID, 0 for simple products.
 * @return bool
 */
function should_publish_cart_add( $cart_item_key, $product_id, $quantity, $variation_id = 0 ): bool {
	unset( $cart_item_key, $quantity );
	/**
	 * Filters whether "added to basket" activity is published at all.
	 *
	 * @param bool $enabled Default true.
	 */
	if ( ! apply_filters( 'wordsocket_woocommerce_activity_enabled', true ) ) {
		return false;
	}
	$id      = $variation_id ? (int) $variation_id : (int) $product_id;
	$product = wc_get_product( $id );
	if ( ! $product instanceof WC_Product || ! $product->is_purchasable() ) {
		return false;
	}
	/**
	 * Filters the minimum seconds between two publishes for the same product.
	 *
	 * @param int $seconds Default 10. Return 0 to publish every add.
	 */
	$throttle = (int) apply_filters( 'wordsocket_woocommerce_activity_throttle', ACTIVITY_THROTTLE );
	if ( $throttle <= 0 ) {
		return true;
	}
	$key = 'wswoo_act_' . $id;
	if ( get_transient( $key ) ) {
		return false;
	}
	set_transient( $key, 1, $throttle );
	return true;
}

/**
 * Anonymous, stable-per-session hash so a shopper's own browser can ignore
 * its own add-to-basket event. Never reversible to the session.
 *
 * @return string
 */
function actor_hash(): string {
	$session = function_exists( 'WC' ) && WC()->session ? (string) WC()->session->get_customer_id() : '';
	if ( '' === $session ) {
		return '';
	}
	return substr( hash_hmac( 'sha256', $session, wp_salt( 'nonce' ) ), 0, 16 );
}

/**
 * Resolve an order from the hook arguments WooCommerce passes.
 *
 * @param int|\WC_Order $order_or_id Order ID or object.
 * @return \WC_Order|null
 */
function resolve_order( $order_or_id ): ?\WC_Order {
	$order = $order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order( (int) $order_or_id );
	return $order instanceof \WC_Order ? $order : null;
}

add_action(
	'wpsignal_loaded',
	static function (): void {
		// New order (any status, including pending checkout). WooCommerce fires
		// this on the order's first save: storefront checkouts add their items
		// first, programmatic `wc_create_order()` calls add them afterwards, so
		// consumers must treat later status and paid events as the row's update.
		WPS::trigger( 'woo.order.created' )
			->on( 'woocommerce_new_order', 10, 2 )
			->channel( ORDERS_CHANNEL )
			->when( static fn( $order_id, $order = null ) => null !== resolve_order( $order ?? $order_id ) )
			->data( static fn( $order_id, $order = null ) => order_payload( resolve_order( $order ?? $order_id ) ) )
			->register();

		// Payment confirmed by the gateway.
		WPS::trigger( 'woo.order.paid' )
			->on( 'woocommerce_payment_complete', 10, 2 )
			->channel( ORDERS_CHANNEL )
			->when( static fn( $order_id ) => null !== resolve_order( $order_id ) )
			->data( static fn( $order_id, $transaction_id = '' ) => order_payload( resolve_order( $order_id ), array( 'transaction_id' => (string) $transaction_id ) ) )
			->register();

		// Status transitions (processing, completed, cancelled, refunded, ...).
		WPS::trigger( 'woo.order.status' )
			->on( 'woocommerce_order_status_changed', 10, 4 )
			->channel( ORDERS_CHANNEL )
			->when( static fn( $order_id, $from, $to, $order = null ) => null !== resolve_order( $order ?? $order_id ) )
			->data(
				static fn( $order_id, $from, $to, $order = null ) => order_payload(
					resolve_order( $order ?? $order_id ),
					array(
						'from' => (string) $from,
						'to'   => (string) $to,
					)
				)
			)
			->register();

		// Stock level changed (checkout, refund, manual edit): public.
		foreach ( array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock' ) as $hook ) {
			WPS::trigger( 'woo.stock.changed' )
				->on( $hook, 10, 1 )
				->channel( STOCK_CHANNEL )
				->when( static fn( $product ) => $product instanceof WC_Product )
				->data( static fn( WC_Product $product ) => stock_payload( $product ) )
				->register();
		}

		// Someone added a product to their basket: public social proof, at most
		// one publish per product every ACTIVITY_THROTTLE seconds.
		WPS::trigger( 'woo.cart.added' )
			->on( 'woocommerce_add_to_cart', 10, 4 )
			->channel( ACTIVITY_CHANNEL )
			->when( __NAMESPACE__ . '\should_publish_cart_add' )
			->data(
				static function ( $cart_item_key, $product_id, $quantity, $variation_id = 0 ) {
					$product = wc_get_product( $variation_id ? (int) $variation_id : (int) $product_id );
					return cart_added_payload( $product, (int) $quantity, actor_hash() );
				}
			)
			->register();

		// Thresholds crossed: staff.
		WPS::trigger( 'woo.stock.low' )
			->on( 'woocommerce_low_stock', 10, 1 )
			->channel( ORDERS_CHANNEL )
			->when( static fn( $product ) => $product instanceof WC_Product )
			->data( static fn( WC_Product $product ) => stock_payload( $product ) )
			->register();

		WPS::trigger( 'woo.stock.out' )
			->on( 'woocommerce_no_stock', 10, 1 )
			->channel( ORDERS_CHANNEL )
			->when( static fn( $product ) => $product instanceof WC_Product )
			->data( static fn( WC_Product $product ) => stock_payload( $product ) )
			->register();
	},
	20 // After channels.php has registered the extension and reserved the namespace.
);

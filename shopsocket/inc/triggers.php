<?php
/**
 * WooCommerce hooks published as WordSocket events.
 *
 * Staff channel (`woo:orders:feed`): order created, paid, status changed,
 * low stock, out of stock. Public channel (`woo:stock`): stock changed.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

use WC_Product;
use WPSignal\WPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ORDERS_CHANNEL = ORDERS_NS . ':feed';

/**
 * Whether stock changes are published right now: not during an import
 * (WordPress importers and the WooCommerce CSV importer set WP_IMPORTING).
 *
 * @return bool
 */
function should_publish_stock(): bool {
	$importing = ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) || product_import_running();
	/**
	 * Filters whether a stock change is published. Return false to stay silent
	 * (for example during a bulk update of your own).
	 *
	 * @param bool $publish Default: true unless an import is running.
	 */
	return (bool) apply_filters( 'shopsocket_publish_stock', ! $importing );
}

/**
 * Whether the WooCommerce CSV importer is running in this request. It does not
 * set WP_IMPORTING, so its per-item hook flips this flag instead.
 *
 * @param bool|null $set Internal: mark the import as running.
 * @return bool
 */
function product_import_running( ?bool $set = null ): bool {
	static $running = false;
	if ( null !== $set ) {
		$running = $set;
	}
	return $running;
}
// The CSV importer's per-item hook: mark the import as running for the rest of the request.
add_action( 'woocommerce_product_import_before_process_item', static fn() => product_import_running( true ) );

/** Seconds between publishes for the same product. Filter `shopsocket_activity_throttle`. */
const ACTIVITY_THROTTLE = 10;

/**
 * Whether the product's stock state is news. One save can fire the status
 * hook and the quantity hook in either order, so both triggers ask here and
 * only the first to see a state publishes it.
 *
 * @param WC_Product $product The product or variation.
 * @return bool
 */
function stock_state_changed( WC_Product $product ): bool {
	static $last = array();
	$state       = $product->get_stock_status() . ':' . (string) $product->get_stock_quantity();
	if ( ( $last[ $product->get_id() ] ?? null ) === $state ) {
		return false;
	}
	$last[ $product->get_id() ] = $state;
	return true;
}

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
	if ( ! apply_filters( 'shopsocket_activity_enabled', true ) ) {
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
	$throttle = (int) apply_filters( 'shopsocket_activity_throttle', ACTIVITY_THROTTLE );
	if ( $throttle <= 0 ) {
		return true;
	}
	$key = 'shopsocket_act_' . $id;
	if ( get_transient( $key ) ) {
		return false;
	}
	set_transient( $key, 1, $throttle );
	return true;
}

/**
 * A stable, anonymous id for the shopper: the first 16 hex of a keyed hash.
 *
 * Other shoppers see this id on public activity events, so it is an HMAC
 * with the site's auth salt: a plain hash of a small user id would be
 * trivial to reverse. Empty while there is no session.
 *
 * @return string
 */
function basket_id(): string {
	/*
	 * For a logged-in shopper only the user id matches across the add request,
	 * the Store API, and REST; guests key on their session id instead.
	 */
	if ( is_user_logged_in() ) {
		return substr( hash_hmac( 'sha256', 'user:' . get_current_user_id(), wp_salt( 'auth' ) ), 0, 16 );
	}
	$customer_id = function_exists( 'WC' ) && WC()->session ? (string) WC()->session->get_customer_id() : '';
	if ( '' === $customer_id ) {
		return '';
	}
	return substr( hash_hmac( 'sha256', 'guest:' . $customer_id, wp_salt( 'auth' ) ), 0, 16 );
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

// The triggers, registered once WordSocket is ready.
add_action(
	'wpsignal_loaded',
	static function (): void {
		/*
		 * New order, any status. A programmatic `wc_create_order()` fires this before
		 * its items are added, so the later status and paid events update the row.
		 */
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

		// Stock level changed (checkout, refund, manual edit): public, silent during imports.
		foreach ( array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock' ) as $hook ) {
			WPS::trigger( 'woo.stock.changed' )
				->on( $hook, 10, 1 )
				->channel( STOCK_CHANNEL )
				->when( static fn( $product ) => $product instanceof WC_Product && should_publish_stock() && stock_state_changed( $product ) )
				->data( static fn( WC_Product $product ) => stock_payload( $product, basket_id() ) )
				->register();
		}

		// Status flipped (a sell-out, or a manual flip with stock unmanaged): the same event, once per state.
		foreach ( array( 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status' ) as $hook ) {
			WPS::trigger( 'woo.stock.changed' )
				->on( $hook, 10, 3 )
				->channel( STOCK_CHANNEL )
				->when( static fn( $id, $status, $product = null ) => $product instanceof WC_Product && should_publish_stock() && stock_state_changed( $product ) )
				->data( static fn( $id, $status, WC_Product $product ) => stock_payload( $product, basket_id() ) )
				->register();
		}

		// Someone added a product to their basket: public, throttled per product.
		WPS::trigger( 'woo.cart.added' )
			->on( 'woocommerce_add_to_cart', 10, 4 )
			->channel( ACTIVITY_CHANNEL )
			->when( __NAMESPACE__ . '\should_publish_cart_add' )
			->data(
				static function ( $cart_item_key, $product_id, $quantity, $variation_id = 0 ) {
					$product   = wc_get_product( $variation_id ? (int) $variation_id : (int) $product_id );
					$image_url = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_gallery_thumbnail' );
					$image_url = is_string( $image_url ) ? $image_url : '';
					return cart_added_payload( $product, (int) $quantity, basket_id(), $image_url );
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

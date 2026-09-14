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
 * Whether the WooCommerce CSV importer is processing items in this request.
 * It does not set WP_IMPORTING; it fires a hook per item, which flips a flag
 * for the rest of the request (each importer batch is its own request).
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
add_action( 'woocommerce_product_import_before_process_item', static fn() => product_import_running( true ) );

/** Seconds between publishes for the same product. Filter `shopsocket_activity_throttle`. */
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
 * A stable, anonymous id for a shopper's WooCommerce session: the first 16 hex
 * of SHA-256 of the session customer id. Two uses need it to match between PHP
 * and the browser, so it is a plain hash (no server secret): the shopper's own
 * browser ignores its own add-to-basket event, and the storefront enters relay
 * presence under this id so the dashboard can tie a live connection to a basket
 * row. The browser derives the same value from its WooCommerce session cookie.
 * Not reversible to the session, and empty when there is no session yet.
 *
 * @return string
 */
function basket_id(): string {
	// Logged-in shoppers: key on the user id, which is identical across the
	// add request, the Store API, and the REST id lookup. Guests have no stable
	// account, so fall back to their WooCommerce session id (they carry the
	// session cookie that ties those requests together).
	if ( is_user_logged_in() ) {
		return substr( hash( 'sha256', 'user:' . get_current_user_id() ), 0, 16 );
	}
	$customer_id = function_exists( 'WC' ) && WC()->session ? (string) WC()->session->get_customer_id() : '';
	if ( '' === $customer_id ) {
		return '';
	}
	return substr( hash( 'sha256', 'guest:' . $customer_id ), 0, 16 );
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

		// Stock level changed (checkout, refund, manual edit): public. Silent
		// during imports, which would otherwise publish once per product.
		foreach ( array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock' ) as $hook ) {
			WPS::trigger( 'woo.stock.changed' )
				->on( $hook, 10, 1 )
				->channel( STOCK_CHANNEL )
				->when( static fn( $product ) => $product instanceof WC_Product && should_publish_stock() )
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

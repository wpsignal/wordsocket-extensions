<?php
/**
 * Event payloads: the minimum the board and the storefront need.
 *
 * Orders go to a staff-only channel, but the payload still leaves out email,
 * addresses and line-item prices. Stock payloads are public data.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

use WC_Order;
use WC_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payload for `woo.order.*` events.
 *
 * @param WC_Order $order The order.
 * @param array    $extra Event-specific keys merged in (for example `from` and `to` on a status change).
 * @return array<string, mixed>
 */
function order_payload( WC_Order $order, array $extra = array() ): array {
	$first = $order->get_billing_first_name();
	$last  = $order->get_billing_last_name();
	$name  = trim( $first . ' ' . ( '' !== $last ? mb_substr( $last, 0, 1 ) . '.' : '' ) );
	if ( '' === $name ) {
		$name = __( 'Guest', 'shopsocket' );
	}

	$date = $order->get_date_created();

	return array_merge(
		array(
			'order_id'       => $order->get_id(),
			'number'         => $order->get_order_number(),
			'status'         => $order->get_status(),
			'total'          => (float) $order->get_total(),
			'currency'       => $order->get_currency(),
			'item_count'     => $order->get_item_count(),
			'customer'       => $name,
			'payment_method' => (string) $order->get_payment_method_title(),
			'created_at'     => $date ? $date->format( DATE_ATOM ) : null,
			'edit_url'       => $order->get_edit_order_url(),
		),
		$extra
	);
}

/**
 * Payload for `woo.cart.added`: what a stranger may see. No identity, no cart.
 *
 * @param WC_Product $product   Product (or variation) added.
 * @param int        $quantity  Quantity added.
 * @param string     $actor     Anonymous hash of the shopper's session, so the
 *                              shopper's own browser can ignore the event.
 * @param string     $image_url Thumbnail URL, empty when the product has none.
 * @return array<string, mixed>
 */
function cart_added_payload( WC_Product $product, int $quantity, string $actor, string $image_url ): array {
	$is_variation = $product->is_type( 'variation' );
	$parent       = $is_variation ? wc_get_product( $product->get_parent_id() ) : $product;

	$parent_id = $is_variation ? $product->get_parent_id() : $product->get_id();

	return array(
		'product_id'   => $parent_id,
		'variation_id' => $is_variation ? $product->get_id() : 0,
		'name'         => $parent instanceof WC_Product ? $parent->get_name() : $product->get_name(),
		'permalink'    => $product->get_permalink(),
		'image'        => $image_url,
		'quantity'     => $quantity,
		'actor'        => $actor,
		'in_carts'     => count_in_carts( $parent_id ),
	);
}

/**
 * Units a shopper could still buy: stock minus what pending checkouts hold,
 * never below zero. Null when there is no limit to know, because stock is
 * not managed or backorders are allowed.
 *
 * @param WC_Product $product The product or variation.
 * @return int|null
 */
function available_quantity( WC_Product $product ): ?int {
	$stock = $product->get_stock_quantity();
	if ( null === $stock || $product->backorders_allowed() ) {
		return null;
	}
	return max( 0, (int) $stock - (int) wc_get_held_stock_quantity( $product ) );
}

/**
 * Payload for `woo.stock.*` events.
 *
 * `actor` is the basket id of the shopper whose request changed the stock, so
 * the storefront can tell a buyer's own sale from someone else's: at checkout
 * the reduction runs inside the buyer's request, and without it the buyer is
 * warned that the item they just bought has sold out. Empty when no shopper is
 * behind the change (an import, a gateway webhook, or a caller that passes none).
 *
 * @param WC_Product $product The product or variation whose stock changed.
 * @param string     $actor   Basket id of the shopper behind the change, or empty.
 * @return array<string, mixed>
 */
function stock_payload( WC_Product $product, string $actor = '' ): array {
	$is_variation = $product->is_type( 'variation' );
	$availability = $product->get_availability();

	return array(
		'product_id'         => $is_variation ? $product->get_parent_id() : $product->get_id(),
		'variation_id'       => $is_variation ? $product->get_id() : 0,
		'name'               => $product->get_name(),
		'permalink'          => $product->get_permalink(),
		'stock_quantity'     => $product->get_stock_quantity(),
		'available'          => available_quantity( $product ),
		'stock_status'       => $product->get_stock_status(),
		'purchasable'        => $product->is_purchasable() && $product->is_in_stock(),
		// WooCommerce's own availability text and class, so the storefront needs no stock rules.
		'availability_text'  => wp_strip_all_tags( (string) $availability['availability'] ),
		'availability_class' => (string) $availability['class'],
		'actor'              => $actor,
	);
}

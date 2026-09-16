<?php
/**
 * The Live Stock block: a product's availability, units left, and the in-cart
 * counter as one block store owners place in the Single Product template (or
 * anywhere, with a product chosen). Markup we own, bound to the storefront store.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

use WC_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LIVE_STOCK_BLOCK = 'shopsocket/live-stock';

// Registered from the built metadata; the markup is rendered here, not saved.
add_action(
	'init',
	static function (): void {
		register_block_type( DIR . 'build/blocks/live-stock', array( 'render_callback' => __NAMESPACE__ . '\render_live_stock_block' ) );
	}
);

/**
 * Whether the block template being rendered contains the Live Stock block, so
 * the counter is not injected a second time after the price.
 *
 * @return bool
 */
function template_has_live_stock_block(): bool {
	global $_wp_current_template_content;
	return is_string( $_wp_current_template_content ) && str_contains( $_wp_current_template_content, 'wp:' . LIVE_STOCK_BLOCK );
}

/**
 * The product a block instance is about: its own attribute, then the template's
 * post context, then the page's queried product.
 *
 * @param array     $attributes Block attributes.
 * @param \WP_Block $block      The block being rendered.
 * @return WC_Product|null
 */
function live_stock_product( array $attributes, \WP_Block $block ): ?WC_Product {
	$id = (int) ( $attributes['productId'] ?? 0 );
	if ( ! $id ) {
		$id = (int) ( $block->context['postId'] ?? 0 );
	}
	if ( ! $id && is_product() ) {
		$id = (int) get_queried_object_id();
	}
	$product = $id ? wc_get_product( $id ) : null;
	return $product instanceof WC_Product ? $product : null;
}

/**
 * Render the block: the availability line (WooCommerce's own wording), the
 * units left, and the in-cart counter, every part bound to the store.
 *
 * @param array     $attributes Block attributes.
 * @param string    $content    Unused: the block has no saved content.
 * @param \WP_Block $block      The block being rendered.
 * @return string
 */
function render_live_stock_block( array $attributes, string $content, \WP_Block $block ): string {
	unset( $content );
	$product = live_stock_product( $attributes, $block );
	if ( ! $product ) {
		return '';
	}
	$context   = stock_context_data( $product );
	$available = $context['available'];
	$wrapper   = get_block_wrapper_attributes( array( 'class' => 'shopsocket-live-stock' ) );

	return sprintf(
		'<div %1$s data-wp-interactive="%2$s" %3$s>' .
			'<p class="stock %4$s shopsocket-stock shopsocket-live-stock__availability" data-wp-bind--class="state.liveStockClassName" data-wp-bind--hidden="state.stockEmpty" data-wp-text="state.stockText"%5$s>%6$s</p>' .
			'<p class="shopsocket-live-stock__left" data-wp-bind--hidden="!state.stockLeftText" data-wp-text="state.stockLeftText"%7$s>%8$s</p>' .
			'%9$s' .
		'</div>',
		$wrapper,
		esc_attr( STORE_NS ),
		wp_interactivity_data_wp_context( $context, STORE_NS ),
		esc_attr( $context['class'] ),
		'' === $context['text'] ? ' hidden' : '',
		esc_html( $context['text'] ),
		null === $available || $available <= 0 ? ' hidden' : '',
		esc_html( null === $available ? '' : sprintf( left_string(), $available ) ),
		in_carts_enabled() ? in_carts_html( $product->get_id() ) : ''
	);
}

/**
 * The "units left" sentence, one source for PHP and the store's `i18n`.
 *
 * @return string
 */
function left_string(): string {
	/* translators: %d: units that can still be bought */
	return __( '%d left', 'shopsocket' );
}

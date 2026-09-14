<?php
/**
 * Storefront: live stock, "someone just added" toasts, and the in-cart count
 * on shop and product pages, for every visitor.
 *
 * Built on the Interactivity API: the markup below carries directives bound
 * to the `shopsocket/storefront` store (`src/storefront/storefront.ts`,
 * compiled to `build/storefront/storefront.js`), the server seeds that store's
 * state, and WordSocket's events update it.
 *
 * Visitors get the WordSocket client only on WooCommerce pages (product,
 * shop and archives, cart, checkout). Their tokens carry the public channels
 * only, so nothing staff-only is reachable from a shopper's browser.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

use WC_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const STOREFRONT_MODULE = 'shopsocket/storefront';
const STORE_NS          = 'shopsocket/storefront';

/**
 * Whether this request is a storefront page that gets the live features.
 *
 * @return bool
 */
function is_live_storefront_page(): bool {
	if ( is_admin() || ! function_exists( 'is_woocommerce' ) ) {
		return false;
	}
	/**
	 * Filters whether the live storefront features load on this request.
	 *
	 * @param bool $load Default: WooCommerce pages, cart and checkout.
	 */
	return (bool) apply_filters( 'shopsocket_storefront', is_woocommerce() || is_cart() || is_checkout() );
}

// Visitors need the realtime client on live storefront pages, and their tokens
// must keep refreshing while they browse. A token refresh is a bare REST request
// with no page context, so `is_live_storefront_page()` is false there; allow the
// token endpoint to mint for anonymous visitors during a REST request too, or
// the connection dies when the 5-minute JWT expires and never comes back.
// This makes the storefront a public-client surface (anyone may mint a
// connection token); the per-plan connection limit is the guard against abuse.
add_filter(
	'wpsignal_allow_client',
	static function ( bool $allow ): bool {
		if ( $allow ) {
			return true;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		return is_live_storefront_page();
	}
);

/**
 * The `data-wp-context` attribute for a product or variation's stock element:
 * ids, plus the server-rendered text and class the store falls back to until
 * an event arrives.
 *
 * @param WC_Product $product Product or variation.
 * @param array      $extra   Additional context keys.
 * @return string
 */
function stock_context( WC_Product $product, array $extra = array() ): string {
	$is_variation = $product->is_type( 'variation' );
	$availability = $product->get_availability();
	return wp_interactivity_data_wp_context(
		array_merge(
			array(
				'productId'   => $is_variation ? $product->get_parent_id() : $product->get_id(),
				'variationId' => $is_variation ? $product->get_id() : 0,
				'text'        => wp_strip_all_tags( (string) $availability['availability'] ),
				'class'       => (string) $availability['class'],
			),
			$extra
		),
		STORE_NS
	);
}

/**
 * Replace WooCommerce's availability paragraph with one bound to the store,
 * so a stock event updates it in place. Rendered (hidden) even when
 * WooCommerce has nothing to say, so a later sell-out can appear.
 *
 * @param string     $html    Availability markup (may be empty).
 * @param WC_Product $product Product or variation.
 * @return string
 */
function wrap_stock_html( string $html, WC_Product $product ): string {
	if ( is_admin() ) {
		return $html;
	}
	$availability = $product->get_availability();
	$text         = wp_strip_all_tags( (string) $availability['availability'] );
	return sprintf(
		'<p class="stock %1$s shopsocket-stock" data-wp-interactive="%2$s" %3$s data-wp-bind--class="state.stockClassName" data-wp-bind--hidden="state.stockEmpty" data-wp-text="state.stockText"%4$s>%5$s</p>',
		esc_attr( (string) $availability['class'] ),
		esc_attr( STORE_NS ),
		stock_context( $product ),
		'' === $text ? ' hidden' : '',
		esc_html( $text )
	);
}
add_filter( 'woocommerce_get_stock_html', __NAMESPACE__ . '\wrap_stock_html', 10, 2 );

/**
 * Block themes: bind the Product Stock Indicator block to the store. On
 * variable products WooCommerce already binds it to its own
 * `woocommerce/products` store, which the script feeds instead.
 *
 * @param string $content Rendered block.
 * @param array  $block   Parsed block, with `context`.
 * @return string
 */
function tag_stock_indicator_block( string $content, array $block ): string {
	$product_id = (int) ( $block['context']['postId'] ?? get_the_ID() );
	if ( ! $product_id || str_contains( $content, 'data-wp-interactive' ) || ! preg_match( '/^<div class="([^"]*wc-block-components-product-stock-indicator[^"]*)"/', $content, $m ) ) {
		return $content;
	}
	$product = wc_get_product( $product_id );
	if ( ! $product instanceof WC_Product ) {
		return $content;
	}
	// Base classes without the availability modifier, which the store re-adds.
	$classes = trim( (string) preg_replace( '/\s*wc-block-components-product-stock-indicator--[\w-]+/', '', $m[1] ) );
	return sprintf(
		'<div class="%1$s shopsocket-stock" data-wp-interactive="%2$s" %3$s data-wp-bind--class="state.indicatorClassName" data-wp-text="state.stockText"%4$s',
		esc_attr( $m[1] ),
		esc_attr( STORE_NS ),
		stock_context( $product, array( 'classes' => $classes ) ),
		substr( $content, strlen( $m[0] ) )
	);
}
add_filter( 'render_block_woocommerce/product-stock-indicator', __NAMESPACE__ . '\tag_stock_indicator_block', 10, 2 );

/**
 * "N shoppers have this in their cart" markup for a product, bound to the
 * store: hidden at zero, shown as soon as the count is positive.
 *
 * @param int $product_id Parent product ID.
 * @return string
 */
function in_carts_html( int $product_id ): string {
	$count   = count_in_carts( $product_id );
	$strings = in_carts_strings();
	wp_interactivity_state( STORE_NS, array( 'carts' => array( $product_id => $count ) ) );
	return sprintf(
		'<p class="shopsocket-in-carts" data-wp-interactive="%1$s" %2$s data-wp-bind--hidden="!state.inCartsCount" data-wp-class--shopsocket-in-carts--updated="state.inCartsPulse"%3$s><span class="shopsocket-in-carts__dot" aria-hidden="true"></span> <span class="shopsocket-in-carts__text" data-wp-text="state.inCartsText">%4$s</span></p>',
		esc_attr( STORE_NS ),
		wp_interactivity_data_wp_context( array( 'productId' => $product_id ), STORE_NS ),
		$count > 0 ? '' : ' hidden',
		esc_html( sprintf( 1 === $count ? $strings['one'] : $strings['many'], $count ) )
	);
}

/**
 * The counter's two sentences, the one source for the server-rendered text and
 * the store's `i18n` (the module picks `one` at exactly 1, `many` otherwise,
 * so both renderings agree).
 *
 * @return array{one: string, many: string}
 */
function in_carts_strings(): array {
	return array(
		/* translators: %d: number of shoppers */
		'one'  => __( '%d shopper has this in their cart right now', 'shopsocket' ),
		/* translators: %d: number of shoppers */
		'many' => __( '%d shoppers have this in their cart right now', 'shopsocket' ),
	);
}

/**
 * The counter is placed by whichever of the two hooks below fires first on a
 * product page (classic summary action, or the Product Price block in block
 * templates); a template may fire both, so it renders once per request.
 *
 * @param int $product_id The page's product.
 * @return string Markup, or '' when already rendered or disabled.
 */
function in_carts_once( int $product_id ): string {
	static $rendered = false;
	if ( $rendered || ! in_carts_enabled() || ! is_product() || get_queried_object_id() !== $product_id ) {
		return '';
	}
	$rendered = true;
	return in_carts_html( $product_id );
}

// Classic single product template: after the price (10) and excerpt (20), before add to cart (30).
add_action(
	'woocommerce_single_product_summary',
	static function (): void {
		global $product;
		if ( $product instanceof WC_Product ) {
			echo in_carts_once( $product->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
	},
	25
);

// Block single product template: right after the Product Price block.
add_filter(
	'render_block_woocommerce/product-price',
	static function ( string $content, array $block ): string {
		$product_id = (int) ( $block['context']['postId'] ?? get_queried_object_id() );
		return $content . in_carts_once( $product_id );
	},
	10,
	2
);

/**
 * Whether the in-cart counter is shown.
 *
 * @return bool
 */
function in_carts_enabled(): bool {
	/**
	 * Filters whether "N shoppers have this in their cart" is shown on product pages.
	 *
	 * @param bool $enabled Default true.
	 */
	return (bool) apply_filters( 'shopsocket_in_carts_enabled', true );
}

/**
 * Whether "someone just added" toasts are shown.
 *
 * @return bool
 */
function toasts_enabled(): bool {
	/**
	 * Filters whether shoppers see "Someone just added ... to their basket" toasts.
	 *
	 * @param bool $enabled Default true.
	 */
	return (bool) apply_filters( 'shopsocket_activity_enabled', true );
}

// The module, its stylesheet, and the store's initial state.
add_action(
	'wp_enqueue_scripts',
	static function (): void {
		if ( ! is_live_storefront_page() ) {
			return;
		}
		wp_register_script_module( STOREFRONT_MODULE, URL . 'build/storefront/storefront.js', array( '@wordpress/interactivity' ), VERSION );
		wp_enqueue_script_module( STOREFRONT_MODULE );
		wp_enqueue_style( SLUG . '-storefront', URL . 'src/storefront/storefront.css', array(), VERSION );

		wp_interactivity_state(
			STORE_NS,
			array(
				'productId'         => is_product() ? (int) get_queried_object_id() : 0,
				'presenceChannel'   => CARTS_PRESENCE_CHANNEL,
				'basketIdUrl'       => rest_url( REST_NS . '/basket-id' ),
				'nonce'             => wp_create_nonce( 'wp_rest' ),
				'toasts'            => toasts_enabled(),
				'inCarts'           => in_carts_enabled(),
				'selectedVariation' => 0,
				'carts'             => new \stdClass(), // An object even when empty, keyed by product ID.
				'i18n'              => in_carts_strings() + array(
					'addedThis'  => __( 'Someone just added this to their basket', 'shopsocket' ),
					/* translators: %s: product name */
					'addedOther' => __( 'Someone just added %s to their basket', 'shopsocket' ),
				),
			)
		);
	}
);

// The toast: one at a time, bound to `state.toast`.
add_action(
	'wp_footer',
	static function (): void {
		if ( ! is_live_storefront_page() || ! toasts_enabled() ) {
			return;
		}
		printf(
			'<div class="shopsocket-toasts" data-wp-interactive="%1$s" aria-live="polite">' .
				'<div class="shopsocket-toast" role="status" data-wp-bind--hidden="!state.toast.visible" data-wp-class--is-visible="state.toast.visible" hidden>' .
					'<img class="shopsocket-toast__image" data-wp-bind--hidden="!state.toast.image" data-wp-bind--src="state.toast.image" alt="" hidden>' .
					'<a data-wp-bind--href="state.toast.href" data-wp-text="state.toast.message"></a>' .
					'<button type="button" class="shopsocket-toast__close" data-wp-on--click="actions.dismissToast" aria-label="%2$s">&times;</button>' .
				'</div>' .
			'</div>',
			esc_attr( STORE_NS ),
			esc_attr__( 'Dismiss', 'shopsocket' )
		);
	}
);


/**
 * Whether the request carries a WooCommerce session cookie already, so we never
 * create a session for a browser that has no cart.
 *
 * @return bool
 */
function has_wc_session_cookie(): bool {
	foreach ( array_keys( $_COOKIE ) as $name ) {
		if ( str_starts_with( (string) $name, 'wp_woocommerce_session_' ) ) {
			return true;
		}
	}
	return false;
}

// The storefront asks here for its own basket id, which it then enters relay
// presence under. The WooCommerce session cookie is HttpOnly, so the browser
// cannot derive the id itself; the server reads the session the request carries.
add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			REST_NS,
			'/basket-id',
			array(
				'methods'             => 'GET',
				'args'                => array(
					'product' => array(
						'type'     => 'integer',
						'required' => false,
					),
				),
				'callback'            => static function ( \WP_REST_Request $request ) {
					if ( ( has_wc_session_cookie() || is_user_logged_in() ) && function_exists( 'wc_load_cart' ) ) {
						wc_load_cart();
					}
					$state    = current_cart_state();
					$response = array(
						'id'       => basket_id(),
						'products' => $state ? $state['products'] : array(),
					);
					$product = (int) $request->get_param( 'product' );
					if ( $product > 0 ) {
						$response['count'] = count_in_carts( $product );
					}
					// Per-visitor identity and a live count: a CDN or full-page cache
					// must never store this, so the storefront's one on-load fetch
					// always busts a stale cached page instead of reading its number.
					$rest = rest_ensure_response( $response );
					$rest->header( 'Cache-Control', 'no-store, max-age=0' );
					return $rest;
				},
				'permission_callback' => '__return_true',
			)
		);
	}
);

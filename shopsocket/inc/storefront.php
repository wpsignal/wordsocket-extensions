<?php
/**
 * Storefront: live stock, "someone just added" toasts, and the in-cart count.
 *
 * Interactivity API markup bound to the `shopsocket/storefront` store
 * (`src/storefront/storefront.ts`): PHP seeds the state, WordSocket events
 * update it. Only WooCommerce pages get the client, on public channels only.
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
 * Whether this request gets the live storefront: every front-end page while
 * WooCommerce is active. A shopper's presence rides on the page they are on,
 * so the board only counts them live while the module is loaded; limiting it
 * to shop pages made a basket look abandoned the moment its shopper opened
 * My Account or a blog post.
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
	 * @param bool $load Default: true on every front-end page.
	 */
	return (bool) apply_filters( 'shopsocket_storefront', true );
}

/*
 * A token refresh is a bare REST request with no page context, so allow minting
 * during any REST request too, or a visitor's connection halts when the
 * five-minute JWT expires. The plan's connection limit guards against abuse.
 */
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
 * The `data-wp-context` for a stock element: ids, plus the rendered text and
 * class the store falls back to until an event arrives.
 *
 * @param WC_Product $product Product or variation.
 * @param array      $extra   Additional context keys.
 * @return string
 */
function stock_context( WC_Product $product, array $extra = array() ): string {
	return wp_interactivity_data_wp_context( stock_context_data( $product, $extra ), STORE_NS );
}

/**
 * The context values behind `stock_context()`.
 *
 * @param WC_Product $product Product or variation.
 * @param array      $extra   Additional context keys.
 * @return array<string, mixed>
 */
function stock_context_data( WC_Product $product, array $extra = array() ): array {
	$is_variation = $product->is_type( 'variation' );
	$availability = $product->get_availability();
	return array_merge(
		array(
			'productId'   => $is_variation ? $product->get_parent_id() : $product->get_id(),
			'variationId' => $is_variation ? $product->get_id() : 0,
			'text'        => wp_strip_all_tags( (string) $availability['availability'] ),
			'class'       => (string) $availability['class'],
			'available'   => available_quantity( $product ),
		),
		$extra
	);
}

/**
 * Replace WooCommerce's availability paragraph with one bound to the store.
 *
 * Rendered hidden when WooCommerce has nothing to say, so a later sell-out can appear.
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
// Classic templates, and the availability markup the variation form swaps in.
add_filter( 'woocommerce_get_stock_html', __NAMESPACE__ . '\wrap_stock_html', 10, 2 );

/**
 * Block themes: bind the Product Stock Indicator block to the store.
 *
 * Variable products keep WooCommerce's own `woocommerce/products` binding,
 * which the script feeds instead.
 *
 * @param string $content Rendered block.
 * @param array  $block   Parsed block, with `context`.
 * @return string
 */
function tag_stock_indicator_block( string $content, array $block ): string {
	$product_id = (int) ( $block['context']['postId'] ?? get_queried_object_id() );
	if ( ! $product_id ) {
		return $content;
	}
	$tags = new \WP_HTML_Tag_Processor( $content );
	if ( ! $tags->next_tag( array( 'class_name' => 'wc-block-components-product-stock-indicator' ) ) ) {
		return $content;
	}
	if ( null !== $tags->get_attribute( 'data-wp-interactive' ) ) {
		return $content; // WooCommerce bound it to its own store already.
	}
	$product = wc_get_product( $product_id );
	if ( ! $product instanceof WC_Product ) {
		return $content;
	}
	// Base classes without the availability modifier, which the store re-adds.
	$classes = trim( (string) preg_replace( '/\s*wc-block-components-product-stock-indicator--[\w-]+/', '', (string) $tags->get_attribute( 'class' ) ) );
	$tags->add_class( 'shopsocket-stock' );
	$tags->set_attribute( 'data-wp-interactive', STORE_NS );
	$tags->set_attribute( 'data-wp-context', STORE_NS . '::' . wp_json_encode( stock_context_data( $product, array( 'classes' => $classes ) ) ) );
	$tags->set_attribute( 'data-wp-bind--class', 'state.indicatorClassName' );
	$tags->set_attribute( 'data-wp-text', 'state.stockText' );
	return $tags->get_updated_html();
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
 * Basket counts already seeded by counters rendered earlier in the request, as
 * an object (so it stays `{}` in JSON when empty). Block themes render the
 * template before scripts are enqueued, so the main state must keep these
 * rather than reset them: a Live Stock block on an ordinary page has no other
 * source for its first count.
 *
 * @return object
 */
function seeded_carts(): object {
	$state = wp_interactivity_state( STORE_NS );
	return (object) (array) ( $state['carts'] ?? array() );
}

/**
 * The counter's two sentences, for the server render and the store's `i18n`
 * alike: `one` at exactly 1, `many` otherwise.
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
 * Render the counter once per request: a template may fire both hooks below.
 *
 * @param int $product_id The page's product.
 * @return string Markup, or '' when already rendered or disabled.
 */
function in_carts_once( int $product_id ): string {
	static $rendered = false;
	if ( $rendered || ! in_carts_enabled() || ! is_product() || get_queried_object_id() !== $product_id || template_has_live_stock_block() ) {
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
 * Which storefront page this is, for the module's page-specific behaviour.
 *
 * @return string `product`, `cart`, `checkout`, or `other`.
 */
function storefront_page(): string {
	if ( is_product() ) {
		return 'product';
	}
	if ( is_cart() ) {
		return 'cart';
	}
	if ( is_checkout() ) {
		return 'checkout';
	}
	return 'other';
}

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

		/*
		 * Three modules, imported by id so the import map gives each a versioned
		 * URL: the viewer (basket id, held products, presence), the WooCommerce
		 * hooks, and the store itself.
		 */
		wp_register_script_module( STOREFRONT_MODULE . '/viewer', URL . 'build/storefront/viewer.js', array(), VERSION );
		wp_register_script_module( STOREFRONT_MODULE . '/woocommerce', URL . 'build/storefront/woocommerce.js', array( '@wordpress/interactivity' ), VERSION );
		wp_register_script_module(
			STOREFRONT_MODULE,
			URL . 'build/storefront/storefront.js',
			array( '@wordpress/interactivity', STOREFRONT_MODULE . '/viewer', STOREFRONT_MODULE . '/woocommerce' ),
			VERSION
		);
		wp_enqueue_script_module( STOREFRONT_MODULE );
		wp_enqueue_style( SLUG . '-storefront', URL . 'src/storefront/storefront.css', array(), VERSION );

		wp_interactivity_state(
			STORE_NS,
			array(
				'productId'         => is_product() ? (int) get_queried_object_id() : 0,
				'page'              => storefront_page(),
				'cartUrl'           => wc_get_cart_url(),
				'presenceChannel'   => CARTS_PRESENCE_CHANNEL,
				'channels'          => array(
					'stock'    => STOCK_CHANNEL,
					'activity' => ACTIVITY_CHANNEL,
				),
				'basketIdUrl'       => rest_url( REST_NS . '/basket-id' ),
				'nonce'             => wp_create_nonce( 'wp_rest' ),
				'toasts'            => toasts_enabled(),
				'inCarts'           => in_carts_enabled(),
				'selectedVariation' => 0,
				'carts'             => seeded_carts(),
				'i18n'              => in_carts_strings() + array(
					'addedThis'  => __( 'Someone just added this to their basket', 'shopsocket' ),
					/* translators: %s: product name */
					'addedOther' => __( 'Someone just added %s to their basket', 'shopsocket' ),
					/* translators: %s: product name */
					'soldOut'    => __( '%s just sold out and can no longer be purchased. Please remove it from your cart.', 'shopsocket' ),
					/* translators: 1: product name, 2: units left */
					'onlyLeft'   => __( 'Only %2$d of %1$s left, fewer than your cart holds.', 'shopsocket' ),
					'left'       => left_string(),
					'addToCart'  => __( 'Add to cart', 'shopsocket' ),
					'readMore'   => __( 'Read more', 'shopsocket' ),
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
			'<div class="shopsocket-toasts" data-wp-interactive="%1$s">' .
				'<div class="shopsocket-toast" role="status" data-wp-bind--role="state.toastRole" data-wp-bind--hidden="!state.toast.visible" data-wp-class--is-visible="state.toast.visible" data-wp-class--is-error="state.toastIsError" hidden>' .
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
 * Whether the request already carries a WooCommerce session cookie.
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

/*
 * The storefront's own basket id, which it enters presence under. The session
 * cookie is HttpOnly, so the browser cannot derive the id itself.
 */
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

					/*
					 * The row store is a cache of live carts, so every load and every
					 * reconnect writes this shopper's row back from the real cart: a
					 * row lost to cache eviction or a cleared transient returns the
					 * moment its shopper is on the site again.
					 */
					record_cart( basket_id() );
					$state    = current_cart_state();
					$response = array(
						'id'         => basket_id(),
						'products'   => $state ? $state['products'] : array(),
						'quantities' => $state ? (object) $state['quantities'] : new \stdClass(),
					);
					$product = (int) $request->get_param( 'product' );
					if ( $product > 0 ) {
						$response['count'] = count_in_carts( $product );
					}
					// Per-visitor identity and a live count: no CDN or page cache may store this.
					$rest = rest_ensure_response( $response );
					$rest->header( 'Cache-Control', 'no-store, max-age=0' );
					return $rest;
				},
				'permission_callback' => '__return_true',
			)
		);
	}
);

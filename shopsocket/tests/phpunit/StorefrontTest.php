<?php
/**
 * Storefront markup (Interactivity API directives), the basket-id endpoint,
 * and the import guard.
 */

use function WPSignal\Extensions\ShopSocket\in_carts_html;
use function WPSignal\Extensions\ShopSocket\product_import_running;
use function WPSignal\Extensions\ShopSocket\should_publish_stock;
use function WPSignal\Extensions\ShopSocket\tag_stock_indicator_block;
use function WPSignal\Extensions\ShopSocket\wrap_stock_html;
use const WPSignal\Extensions\ShopSocket\REST_NS;
use const WPSignal\Extensions\ShopSocket\STOCK_CHANNEL;
use const WPSignal\Extensions\ShopSocket\STORE_NS;

final class StorefrontTest extends ExtensionTestCase {

	/** The `data-wp-context` value on an element (the runtime prefixes it with the namespace). */
	private function context_of( string $html ): array {
		$this->assertMatchesRegularExpression( "/data-wp-context='([^']+)'/", $html );
		preg_match( "/data-wp-context='([^']+)'/", $html, $m );
		$json = str_starts_with( $m[1], STORE_NS . '::' ) ? substr( $m[1], strlen( STORE_NS ) + 2 ) : $m[1];
		return (array) json_decode( $json, true );
	}

	public function test_stock_markup_is_bound_to_the_store_with_the_rendered_text_as_fallback(): void {
		$product = $this->make_product( 5 );
		$html    = wc_get_stock_html( $product );

		$this->assertStringStartsWith( '<p class="stock in-stock shopsocket-stock" data-wp-interactive="' . STORE_NS . '"', $html );
		$this->assertStringContainsString( 'data-wp-text="state.stockText"', $html );
		$this->assertStringContainsString( 'data-wp-bind--class="state.stockClassName"', $html );
		$this->assertStringEndsWith( '>5 in stock</p>', $html );
		$this->assertSame(
			array(
				'productId'   => $product->get_id(),
				'variationId' => 0,
				'text'        => '5 in stock',
				'class'       => 'in-stock',
			),
			$this->context_of( $html )
		);

		// The wrapper rebuilds from the product, so WooCommerce's own markup is not nested.
		$this->assertStringNotContainsString( '<p class="stock">x</p>', wrap_stock_html( '<p class="stock">x</p>', $product ) );
	}

	public function test_stock_markup_is_rendered_hidden_when_woocommerce_has_no_availability_text(): void {
		$product = $this->make_product( 5 );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product->save();

		$html = wc_get_stock_html( $product );
		$this->assertStringContainsString( ' hidden>', $html, 'nothing to say yet, but the element exists for a later sell-out' );
		$this->assertSame( '', $this->context_of( $html )['text'] );
	}

	public function test_the_block_stock_indicator_is_bound_unless_woocommerce_already_made_it_interactive(): void {
		$product = $this->make_product( 5 );
		$block   = '<div class="wc-block-components-product-stock-indicator wp-block-woocommerce-product-stock-indicator  wc-block-components-product-stock-indicator--in-stock" style="color:red">In stock</div>';
		$tagged  = tag_stock_indicator_block( $block, array( 'context' => array( 'postId' => $product->get_id() ) ) );

		$this->assertStringStartsWith( '<div class="wc-block-components-product-stock-indicator wp-block-woocommerce-product-stock-indicator  wc-block-components-product-stock-indicator--in-stock shopsocket-stock" data-wp-interactive="' . STORE_NS . '"', $tagged );
		$this->assertStringContainsString( 'data-wp-bind--class="state.indicatorClassName" data-wp-text="state.stockText" style="color:red">In stock</div>', $tagged );
		$context = $this->context_of( $tagged );
		$this->assertSame( 'wc-block-components-product-stock-indicator wp-block-woocommerce-product-stock-indicator', $context['classes'], 'base classes without the availability modifier' );
		$this->assertSame( '5 in stock', $context['text'] );

		$interactive = '<div class="wc-block-components-product-stock-indicator" data-wp-interactive="woocommerce/products" data-wp-text="state.productInContext.stock_availability.text">In stock</div>';
		$this->assertSame( $interactive, tag_stock_indicator_block( $interactive, array( 'context' => array( 'postId' => $product->get_id() ) ) ), 'variable products stay on WooCommerce\'s store' );
		$this->assertSame( '<p>other</p>', tag_stock_indicator_block( '<p>other</p>', array( 'context' => array( 'postId' => $product->get_id() ) ) ) );
	}

	public function test_in_carts_markup_is_hidden_at_zero_and_seeds_the_store_with_the_count(): void {
		$product = $this->make_product( 5 );
		$id      = $product->get_id();

		$html = in_carts_html( $id );
		$this->assertStringContainsString( 'data-wp-bind--hidden="!state.inCartsCount"', $html );
		$this->assertStringContainsString( ' hidden>', $html );
		$this->assertSame( array( 'productId' => $id ), $this->context_of( $html ) );

		// Two shoppers hold it in their cart.
		$this->seed_basket( 'a', array( $id ), 5.0 );
		$this->seed_basket( 'b', array( $id ), 5.0 );
		$html = in_carts_html( $id );
		$this->assertStringNotContainsString( ' hidden>', $html );
		$this->assertStringContainsString( '2 shoppers have this in their cart right now', $html );
		$this->assertSame( 2, wp_interactivity_state( STORE_NS )['carts'][ $id ], 'the store starts with the rendered count' );
	}

	public function test_basket_id_never_creates_a_session_for_a_visitor_without_one(): void {
		// A first-time visitor: no WooCommerce session cookie, not logged in, and
		// so no session or cart loaded for the request. The endpoint must answer
		// without loading the cart, or every such request would mint a fresh
		// session and a different random basket id.
		$cookies = $_COOKIE;
		$session = WC()->session;
		$cart    = WC()->cart;

		$_COOKIE      = array_filter( $_COOKIE, static fn( $name ) => ! str_starts_with( (string) $name, 'wp_woocommerce_session_' ), ARRAY_FILTER_USE_KEY );
		WC()->session = null;
		WC()->cart    = null;
		try {
			wp_set_current_user( 0 );
			$response = rest_do_request( new WP_REST_Request( 'GET', '/' . REST_NS . '/basket-id' ) );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame(
				array(
					'id'       => '',
					'products' => array(),
				),
				$response->get_data()
			);
			$this->assertNull( WC()->cart, 'the request did not load a cart' );
			$this->assertNull( WC()->session, 'nor start a session' );
			$this->assertSame( 'no-store, max-age=0', $response->get_headers()['Cache-Control'] );
		} finally {
			$_COOKIE      = $cookies;
			WC()->session = $session;
			WC()->cart    = $cart;
		}
	}

	public function test_stock_changes_are_not_published_during_an_import(): void {
		$product = $this->make_product( 5 );

		$this->assertTrue( should_publish_stock() );
		do_action( 'woocommerce_product_import_before_process_item', array() );
		$this->assertFalse( should_publish_stock() );
		wc_update_product_stock( $product, 4 );
		$this->assertCount( 0, $this->events_on( STOCK_CHANNEL, 'woo.stock.changed' ), 'silent while importing' );

		product_import_running( false );
		wc_update_product_stock( $product, 3 );
		$this->assertCount( 1, $this->events_on( STOCK_CHANNEL, 'woo.stock.changed' ) );

		add_filter( 'shopsocket_publish_stock', '__return_false' );
		try {
			wc_update_product_stock( $product, 2 );
		} finally {
			remove_filter( 'shopsocket_publish_stock', '__return_false' );
		}
		$this->assertCount( 1, $this->events_on( STOCK_CHANNEL, 'woo.stock.changed' ), 'the filter silences publishes too' );
	}
}

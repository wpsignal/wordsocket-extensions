<?php
/**
 * Storefront: stock and counter markup, the basket-id endpoint, the import guard.
 */

use function WPSignal\Extensions\ShopSocket\all_baskets;
use function WPSignal\Extensions\ShopSocket\in_carts_html;
use function WPSignal\Extensions\ShopSocket\render_live_stock_block;
use const WPSignal\Extensions\ShopSocket\LIVE_STOCK_BLOCK;
use function WPSignal\Extensions\ShopSocket\product_import_running;
use function WPSignal\Extensions\ShopSocket\should_publish_stock;
use function WPSignal\Extensions\ShopSocket\tag_stock_indicator_block;
use function WPSignal\Extensions\ShopSocket\wrap_stock_html;
use const WPSignal\Extensions\ShopSocket\REST_NS;
use const WPSignal\Extensions\ShopSocket\STOCK_CHANNEL;
use const WPSignal\Extensions\ShopSocket\STORE_NS;

final class StorefrontTest extends ExtensionTestCase {

	/**
	 * The `data-wp-context` value on an element (the runtime prefixes it with the namespace).
	 *
	 * @param string $html Rendered markup.
	 * @return array
	 */
	private function context_of( string $html ): array {
		$this->assertMatchesRegularExpression( '/data-wp-context=([\'"])(.+?)\1/', $html );
		preg_match( '/data-wp-context=([\'"])(.+?)\1/', $html, $m );
		$value = html_entity_decode( $m[2], ENT_QUOTES );
		$json  = str_starts_with( $value, STORE_NS . '::' ) ? substr( $value, strlen( STORE_NS ) + 2 ) : $value;
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
				'available'   => 5,
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
		// The block leads with data attributes on current WooCommerce; the class is not the first attribute.
		$block = '<div data-block-name="woocommerce/product-stock-indicator" class="wc-block-components-product-stock-indicator wp-block-woocommerce-product-stock-indicator  wc-block-components-product-stock-indicator--in-stock" style="color:red">In stock</div>';
		$tagged  = tag_stock_indicator_block( $block, array( 'context' => array( 'postId' => $product->get_id() ) ) );

		$this->assertMatchesRegularExpression( '/class="[^"]*wc-block-components-product-stock-indicator--in-stock[^"]*shopsocket-stock[^"]*"/', $tagged );
		$this->assertStringContainsString( 'data-wp-interactive="' . STORE_NS . '"', $tagged );
		$this->assertStringContainsString( 'data-wp-bind--class="state.indicatorClassName"', $tagged );
		$this->assertStringContainsString( 'data-wp-text="state.stockText"', $tagged );
		$this->assertStringContainsString( 'style="color:red">In stock</div>', $tagged );
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
		/*
		 * A first-time visitor: no session cookie, not logged in. The endpoint must
		 * not load the cart, or every such request would mint a session and a new id.
		 */
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
			$data = $response->get_data();
			$this->assertSame( '', $data['id'] );
			$this->assertSame( array(), $data['products'] );
			$this->assertEquals( new \stdClass(), $data['quantities'] );
			$this->assertNull( WC()->cart, 'the request did not load a cart' );
			$this->assertNull( WC()->session, 'nor start a session' );
			$this->assertSame( 'no-store, max-age=0', $response->get_headers()['Cache-Control'] );
		} finally {
			$_COOKIE      = $cookies;
			WC()->session = $session;
			WC()->cart    = $cart;
		}
	}

	public function test_basket_id_rebuilds_the_shoppers_row_from_their_cart(): void {
		$product = $this->make_product( 5 );
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id(), 2 );
		$this->clear_baskets();
		$this->assertSame( array(), all_baskets(), 'the row store was lost' );

		try {
			$_COOKIE['wp_woocommerce_session_phpunit'] = 'present';
			wp_set_current_user( 0 );
			$response = rest_do_request( new WP_REST_Request( 'GET', '/' . REST_NS . '/basket-id' ) );
			$this->assertSame( 200, $response->get_status() );
			$rows = all_baskets();
			$this->assertCount( 1, $rows, 'asking for the basket id wrote the row back' );
			$this->assertSame( array( $product->get_id() ), $rows[0]['products'] );
			$this->assertSame( array( $product->get_id() => 2 ), $rows[0]['quantities'] );
			$this->assertSame( $rows[0]['id'], $response->get_data()['id'] );
			$this->assertEquals( (object) array( $product->get_id() => 2 ), $response->get_data()['quantities'], 'the shopper learns how many they hold' );
		} finally {
			unset( $_COOKIE['wp_woocommerce_session_phpunit'] );
			WC()->cart->empty_cart();
		}
	}

	public function test_the_live_stock_block_renders_the_availability_units_left_and_counter(): void {
		$product = $this->make_product( 5 );
		$this->seed_basket( 'a', array( $product->get_id() ), 5.0 );

		$block = new WP_Block( array( 'blockName' => LIVE_STOCK_BLOCK, 'attrs' => array( 'productId' => $product->get_id() ) ), array() );
		$html  = render_live_stock_block( array( 'productId' => $product->get_id() ), '', $block );

		$this->assertMatchesRegularExpression( '/<div class="(wp-block-shopsocket-live-stock )?shopsocket-live-stock"/', $html, 'the wp-block class joins in a real block render' );
		$this->assertStringContainsString( 'data-wp-interactive="' . STORE_NS . '"', $html );
		$this->assertStringContainsString( 'data-wp-bind--class="state.liveStockClassName"', $html );
		$this->assertStringContainsString( '>5 in stock</p>', $html );
		$this->assertStringContainsString( 'data-wp-text="state.stockLeftText">5 left</p>', $html );
		$this->assertStringContainsString( '1 shopper has this in their cart right now', $html );
		$context = $this->context_of( $html );
		$this->assertSame( $product->get_id(), $context['productId'] );
		$this->assertSame( 5, $context['available'] );

		// No product to show: nothing rendered.
		$this->assertSame( '', render_live_stock_block( array( 'productId' => 999999999 ), '', $block ) );
	}

	public function test_the_live_storefront_loads_on_every_front_end_page_unless_a_filter_narrows_it(): void {
		// A request that is no WooCommerce page at all (this one) still gets it: presence must follow the shopper.
		$this->assertTrue( \WPSignal\Extensions\ShopSocket\is_live_storefront_page() );
		add_filter( 'shopsocket_storefront', '__return_false' );
		try {
			$this->assertFalse( \WPSignal\Extensions\ShopSocket\is_live_storefront_page() );
		} finally {
			remove_filter( 'shopsocket_storefront', '__return_false' );
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

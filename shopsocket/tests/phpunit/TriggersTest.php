<?php
/**
 * Triggers: the WooCommerce hooks publish the right events, channels, and payloads.
 */

use WPSignal\WPS;
use function WPSignal\Extensions\ShopSocket\count_in_carts;
use const WPSignal\Extensions\ShopSocket\ACTIVITY_CHANNEL;
use const WPSignal\Extensions\ShopSocket\CARTS_PRESENCE_CHANNEL;
use const WPSignal\Extensions\ShopSocket\CONNECTIONS_CHANNEL;
use const WPSignal\Extensions\ShopSocket\ORDERS_CHANNEL;
use const WPSignal\Extensions\ShopSocket\STOCK_CHANNEL;

final class TriggersTest extends ExtensionTestCase {

	public function test_the_extension_is_registered_and_reserves_the_orders_namespace(): void {
		$registered = WPS::instance()->extensions()->registered();
		$this->assertArrayHasKey( 'shopsocket', $registered );
		$this->assertSame( array( 'woocommerce/woocommerce.php' => 'WooCommerce' ), $registered['shopsocket']['requires'] );

		$channels = WPS::instance()->channels();
		$this->assertTrue( $channels->is_strict() );
		$this->assertArrayHasKey( 'woo:orders:', $channels->reservations() );
	}

	public function test_staff_tokens_carry_the_orders_namespace_and_visitors_only_the_stock_channel(): void {
		$site_id  = 'site123';
		$channels = WPS::instance()->channels();

		$visitor_channels = apply_filters( 'wpsignal_token_channels', array( 'site:site123:events' ), 0, $site_id );
		$this->assertContains( 'site:site123:' . STOCK_CHANNEL, $visitor_channels );
		$this->assertContains( 'site:site123:' . ACTIVITY_CHANNEL, $visitor_channels );
		$this->assertNotContains( 'site:site123:' . ORDERS_CHANNEL, $visitor_channels );
		$visitor_prefixes = $channels->allowed_prefixes( 0, $site_id, $visitor_channels );
		$this->assertNotContains( 'site:site123:woo:orders:', $visitor_prefixes );

		/*
		 * Visitors write presence on the carts namespace and nothing else; they
		 * cannot read it, so a shopper never sees another's membership.
		 */
		$this->assertNotContains( 'site:site123:woo:carts:', $visitor_prefixes );
		$this->assertNotContains( 'site:site123:' . CARTS_PRESENCE_CHANNEL, $visitor_channels );
		$this->assertSame( array( 'site:site123:woo:carts:' ), $channels->allowed_publish_prefixes( 0, $site_id ) );

		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		$this->assertNotEmpty( $admins );
		$admin_id       = (int) $admins[0];
		$staff_channels = apply_filters( 'wpsignal_token_channels', array( 'site:site123:events' ), $admin_id, $site_id );
		$this->assertContains( 'site:site123:' . ORDERS_CHANNEL, $staff_channels, 'staff auto-subscribe to the feed' );
		$this->assertContains( 'site:site123:' . CONNECTIONS_CHANNEL, $staff_channels, 'and to the relay\'s connection count' );
		$this->assertContains( 'site:site123:' . CARTS_PRESENCE_CHANNEL, $staff_channels, 'and to shopper presence' );
		$this->assertNotContains( 'site:site123:' . CONNECTIONS_CHANNEL, $visitor_channels );
		$this->assertContains( 'site:site123:woo:orders:', $channels->allowed_prefixes( $admin_id, $site_id, $staff_channels ) );
		// Staff read the orders feed but PHP is its only publisher; presence stays writable for their own storefront visits.
		$this->assertSame( array( 'site:site123:woo:carts:' ), $channels->allowed_publish_prefixes( $admin_id, $site_id ) );
	}

	public function test_a_stock_change_publishes_publicly_and_a_product_save_does_not_publish_post_updated(): void {
		$product = $this->make_product( 5 );

		wc_update_product_stock( $product, 4 );

		$events = $this->events_on( STOCK_CHANNEL, 'woo.stock.changed' );
		$this->assertCount( 1, $events );
		$data = $events[0]['data'];
		$this->assertSame( $product->get_id(), $data['product_id'] );
		$this->assertSame( 0, $data['variation_id'] );
		$this->assertSame( 4, $data['stock_quantity'] );
		$this->assertSame( 'instock', $data['stock_status'] );
		$this->assertTrue( $data['purchasable'] );
		// Availability in WooCommerce's own words, so the storefront needs no stock rules.
		$this->assertSame( '4 in stock', $data['availability_text'] );
		$this->assertSame( 'in-stock', $data['availability_class'] );
		$this->assertSame(
			array( 'product_id', 'variation_id', 'name', 'stock_quantity', 'stock_status', 'purchasable', 'availability_text', 'availability_class' ),
			array_keys( $data )
		);

		$this->assertCount( 0, $this->events_on( 'events', 'post.updated' ), 'products are excluded from the default trigger' );

		wc_update_product_stock( $product, 0 );
		$events = $this->events_on( STOCK_CHANNEL, 'woo.stock.changed' );
		$this->assertSame( 'Out of stock', end( $events )['data']['availability_text'] );
		$this->assertSame( 'out-of-stock', end( $events )['data']['availability_class'] );
	}

	public function test_selling_across_the_low_and_out_of_stock_thresholds_notifies_staff(): void {
		/*
		 * WooCommerce raises low_stock / no_stock only when an order reduces stock,
		 * never on a manual edit (those still reach staff as woo.stock.changed).
		 */
		$product = $this->make_product( 3, 2 );

		$first = $this->make_order( $product, 1 );
		wc_reduce_stock_levels( $first );
		$low = $this->events_on( ORDERS_CHANNEL, 'woo.stock.low' );
		$this->assertCount( 1, $low );
		$this->assertSame( 2, $low[0]['data']['stock_quantity'] );
		$this->assertSame( $product->get_id(), $low[0]['data']['product_id'] );

		$second = $this->make_order( $product, 2 );
		wc_reduce_stock_levels( $second );
		$out = $this->events_on( ORDERS_CHANNEL, 'woo.stock.out' );
		$this->assertCount( 1, $out );
		$this->assertSame( 'outofstock', $out[0]['data']['stock_status'] );
		$this->assertFalse( $out[0]['data']['purchasable'] );

		// Every reduction also went out publicly.
		$public = $this->events_on( STOCK_CHANNEL, 'woo.stock.changed' );
		$this->assertSame( array( 2, 0 ), array_map( static fn( $e ) => $e['data']['stock_quantity'], $public ) );
	}

	public function test_an_order_lifecycle_publishes_created_status_and_paid_with_a_minimal_payload(): void {
		$product = $this->make_product( 5 );
		$order   = $this->make_order( $product, 2 );

		$created = $this->events_on( ORDERS_CHANNEL, 'woo.order.created' );
		$this->assertCount( 1, $created );
		$this->assertSame( $order->get_id(), $created[0]['data']['order_id'] );
		$this->assertSame( 'pending', $created[0]['data']['status'] );

		$order->payment_complete( 'txn_42' );
		$order->update_status( 'completed' );

		$status = $this->events_on( ORDERS_CHANNEL, 'woo.order.status' );
		$this->assertSame( array( 'pending', 'processing' ), array( $status[0]['data']['from'], $status[0]['data']['to'] ) );
		$this->assertSame( array( 'processing', 'completed' ), array( end( $status )['data']['from'], end( $status )['data']['to'] ) );

		$paid = $this->events_on( ORDERS_CHANNEL, 'woo.order.paid' );
		$this->assertCount( 1, $paid );
		$data = $paid[0]['data'];
		$this->assertSame( 'txn_42', $data['transaction_id'] );
		$this->assertSame( 19.98, $data['total'] );
		$this->assertSame( 2, $data['item_count'] );
		$this->assertSame( 'Ada L.', $data['customer'], 'first name and last initial only' );
		$this->assertStringContainsString( 'admin', $data['edit_url'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T/', $data['created_at'] );

		// Never in the payload: PII beyond a display name, and line-item prices.
		foreach ( array( 'email', 'billing_email', 'address', 'billing_address_1', 'phone', 'items', 'line_items' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $data, $key );
		}
		$this->assertStringNotContainsString( 'ada@example.com', wp_json_encode( $this->published ) );

		// Payment reduced stock (payment_complete triggers wc_maybe_reduce_stock_levels).
		$stock = $this->events_on( STOCK_CHANNEL, 'woo.stock.changed' );
		$this->assertNotEmpty( $stock );
		$this->assertSame( 3, end( $stock )['data']['stock_quantity'] );
	}

	public function test_adding_to_the_basket_publishes_anonymous_activity_once_per_throttle_window(): void {
		$product = $this->make_product( 10 );
		delete_transient( 'shopsocket_act_' . $product->get_id() );
		WC()->cart->empty_cart();

		WC()->cart->add_to_cart( $product->get_id(), 2 );
		$events = $this->events_on( ACTIVITY_CHANNEL, 'woo.cart.added' );
		$this->assertCount( 1, $events );
		$data = $events[0]['data'];
		$this->assertSame( $product->get_id(), $data['product_id'] );
		$this->assertSame( 0, $data['variation_id'] );
		$this->assertSame( 'PHPUnit Widget', $data['name'] );
		$this->assertSame( 2, $data['quantity'] );
		$this->assertStringContainsString( 'phpunit-widget', $data['permalink'] );
		$this->assertSame( '', $data['image'], 'no thumbnail on the fixture product' );
		$this->assertSame( array( 'product_id', 'variation_id', 'name', 'permalink', 'image', 'quantity', 'actor', 'in_carts' ), array_keys( $data ), 'nothing about who' );

		// Second add inside the window is throttled.
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		$this->assertCount( 1, $this->events_on( ACTIVITY_CHANNEL, 'woo.cart.added' ) );

		// Window elapsed: publishes again. Disabled by filter: silent.
		delete_transient( 'shopsocket_act_' . $product->get_id() );
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		$this->assertCount( 2, $this->events_on( ACTIVITY_CHANNEL, 'woo.cart.added' ) );

		delete_transient( 'shopsocket_act_' . $product->get_id() );
		add_filter( 'shopsocket_activity_enabled', '__return_false' );
		try {
			WC()->cart->add_to_cart( $product->get_id(), 1 );
		} finally {
			remove_filter( 'shopsocket_activity_enabled', '__return_false' );
		}
		$this->assertCount( 2, $this->events_on( ACTIVITY_CHANNEL, 'woo.cart.added' ) );
		WC()->cart->empty_cart();
	}

	public function test_in_carts_counts_holders_and_removals_publish_the_new_count(): void {
		$product = $this->make_product( 10 );
		$id      = $product->get_id();
		delete_transient( 'shopsocket_act_' . $id );
		WC()->cart->empty_cart();
		$this->clear_baskets();
		$this->published = array();

		// Two other shoppers already hold it.
		$this->seed_basket( 'shopper-a', array( $id ), 10.0 );
		$this->seed_basket( 'shopper-b', array( $id ), 10.0 );
		$this->assertSame( 2, count_in_carts( $id ) );

		// This session adds it: the event carries the count including us.
		$key   = WC()->cart->add_to_cart( $id, 1 );
		$added = $this->events_on( ACTIVITY_CHANNEL, 'woo.cart.added' );
		$this->assertCount( 1, $added );
		$this->assertSame( 3, $added[0]['data']['in_carts'] );
		$this->assertNotSame( '', $added[0]['data']['actor'], 'the adding session is identified anonymously' );

		// Removing our line publishes the new count, without an actor.
		WC()->cart->remove_cart_item( $key );
		$removed = $this->events_on( ACTIVITY_CHANNEL, 'woo.cart.removed' );
		$this->assertCount( 1, $removed );
		$this->assertSame( array( 'product_id' => $id, 'variation_id' => 0, 'in_carts' => 2 ), $removed[0]['data'] );
	}
}

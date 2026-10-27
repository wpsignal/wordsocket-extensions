<?php
/**
 * Cart history on WordSocket's journeys: what a report puts on a journey, the live and abandoned
 * reads, the counts, the cart-left hook, the conversion, the strip, and the page steps.
 */

use function WPSignal\Extensions\ShopSocket\abandoned_rows;
use function WPSignal\Extensions\ShopSocket\cart_id;
use function WPSignal\Extensions\ShopSocket\cart_rows;
use function WPSignal\Extensions\ShopSocket\count_in_carts;
use function WPSignal\Extensions\ShopSocket\in_carts_enabled;
use function WPSignal\Extensions\ShopSocket\journeys_table;
use WPSignal\Extensions\ShopSocket\Privacy;
use WPSignal\WPS;
use function WPSignal\Extensions\ShopSocket\mark_converted;
use function WPSignal\Extensions\ShopSocket\on_order_created;
use function WPSignal\Extensions\ShopSocket\page_step;
use function WPSignal\Extensions\ShopSocket\week_strip;
use const WPSignal\Extensions\ShopSocket\DB_VERSION;
use const WPSignal\Extensions\ShopSocket\DB_VERSION_OPTION;
use const WPSignal\Extensions\ShopSocket\ORDERS_CHANNEL;

final class HistoryTest extends ExtensionTestCase {
	private const VISITOR   = '0b2b6a4e-9f0a-4c7a-8f1c-1d2e3f4a5b6c';
	private const JOURNEY   = '7c1d2e3f-4a5b-4c6d-8e7f-0a1b2c3d4e5f';
	private const JOURNEY_2 = '8d2e3f4a-5b6c-4d7e-9f80-1b2c3d4e5f60';
	private const JOURNEY_3 = '9e3f4a5b-6c7d-4e8f-a091-2c3d4e5f6071';

	protected function setUp(): void {
		parent::setUp();
		WC()->cart->empty_cart();
		$this->published = array();
	}

	protected function tearDown(): void {
		WC()->cart->empty_cart();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** An existing administrator's id, the user every logged-in case runs as. */
	private function admin_id(): int {
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		return (int) $admins[0];
	}

	/** A report as WordSocket's visit and leave routes hand it to the actions. */
	private function report( string $journey, bool $leaving, string $journey_id = self::JOURNEY, string $visitor = self::VISITOR, int $user_id = 0 ): array {
		return array(
			'visitor'    => $visitor,
			'journey_id' => $journey_id,
			'journey'    => $journey,
			'steps'      => '' === $journey ? 0 : substr_count( $journey, '>' ) + 1,
			'user_id'    => $user_id > 0 ? $user_id : get_current_user_id(),
			'data'       => array(),
			'leaving'    => $leaving,
		);
	}

	/** A journey row's cart data, decoded. */
	private function data_of( array $row ): array {
		$data = json_decode( (string) ( $row['data'] ?? '' ), true );
		return is_array( $data ) ? $data : array();
	}

	public function test_the_own_tables_are_gone_and_the_shop_names_its_outcome_to_wordsocket(): void {
		global $wpdb;
		$this->assertSame( DB_VERSION, get_option( DB_VERSION_OPTION ) );
		$this->assertSame( '5', DB_VERSION );
		foreach ( array( 'shopsocket_journeys', 'shopsocket_carts', 'shopsocket_cart_items' ) as $table ) {
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table ) ), "version 5 dropped {$table}" );
		}
		$this->assertSame( journeys_table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', journeys_table() ) ), 'the journeys are WordSocket\'s' );
		$this->assertStringEndsWith( 'wpsignal_journeys', journeys_table() );
		$this->assertSame( 'Order placed', WPS::journeys()->outcomes()['converted']['label'], 'the shop names its outcome to WordSocket' );
		$this->assertSame( get_woocommerce_currency(), WPS::journeys()->currency() );
		$this->assertStringContainsString( 'WordSocket', ( new Privacy() )->get_privacy_message(), 'the policy text points at the journeys' );
	}

	public function test_history_off_keeps_nothing_and_the_counters_are_off(): void {
		$product = $this->make_product( 10 );
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		do_action( 'wpsignal_visitor_visit', $this->report( 'product:' . $product->get_id() . ':0', false ) );
		$this->assertSame( array(), $this->journeys(), 'nothing is written' );
		$this->assertSame( array(), cart_rows() );
		$this->assertNull( week_strip() );
		$this->assertFalse( in_carts_enabled( $product->get_id() ), 'the counter needs the carts, which need History' );
		$this->set_history( true );
		$this->assertTrue( in_carts_enabled( $product->get_id() ) );
	}

	public function test_the_in_cart_count_reads_live_carts_and_is_cached_until_the_next_write(): void {
		global $wpdb;
		$this->seed_cart( 'eeee000000000001', array( 9001 ), 10.0 );
		$this->seed_cart( 'eeee000000000002', array( 9001, 9002 ), 10.0 );
		$this->assertSame( 2, count_in_carts( 9001 ) );
		$this->assertSame( 1, count_in_carts( 9001, 'eeee000000000001' ), 'a cart named is left out' );
		$this->assertSame( 0, count_in_carts( 9009 ), 'counted once, then cached' );
		// A write behind the plugin's back is invisible until a write through it bumps the generation.
		$wpdb->update( journeys_table(), array( 'data' => wp_json_encode( array( 'c' => 'USD', 'i' => array( '9009' => 1 ) ) ) ), array( 'ref' => 'eeee000000000001' ) );
		$this->assertSame( 0, count_in_carts( 9009 ), 'served from the cache' );
		\WPSignal\Extensions\ShopSocket\invalidate_cart_caches();
		$this->assertSame( 1, count_in_carts( 9009 ) );
		$this->assertSame( 0, count_in_carts( 90 ), 'a product id is matched whole' );
		// A cart whose shopper left still counts within the session lifetime; a converted one does not.
		$this->seed_cart( 'eeee000000000003', array( 9002 ), 10.0, array(), 'left', 60 );
		$this->assertSame( 2, count_in_carts( 9002 ) );
		$this->seed_cart( 'eeee000000000004', array( 9002 ), 10.0, array(), 'converted', 60 );
		$this->assertSame( 2, count_in_carts( 9002 ) );
		$this->assertCount( 3, cart_rows(), 'the open and the left carts, not the converted one' );
	}

	public function test_a_report_puts_the_cart_on_the_journey_and_an_emptied_cart_leaves_the_row_with_no_items(): void {
		$this->set_history( true );
		$product = $this->make_product( 10 );
		WC()->cart->add_to_cart( $product->get_id(), 2 );
		$id = cart_id();
		do_action( 'wpsignal_visitor_visit', $this->report( 'home:2:1200>product:' . $product->get_id() . ':0', false ) );
		$row = $this->journeys()[0];
		$this->assertSame( self::JOURNEY, $row['journey_id'] );
		$this->assertSame( $id, $row['ref'], 'the cart rides on WordSocket\'s row as its ref' );
		$this->assertSame( 19.98, (float) $row['value'] );
		$this->assertSame( array( 'c' => get_woocommerce_currency(), 'i' => array( (string) $product->get_id() => 2 ) ), $this->data_of( $row ), 'its items and currency as data' );
		$rows = cart_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( $id, $rows[0]['id'] );
		$this->assertSame( array( $product->get_id() => 2 ), $rows[0]['quantities'] );
		$this->assertSame( 19.98, $rows[0]['value'] );
		$this->assertSame( 1, count_in_carts( $product->get_id() ) );
		$this->assertCount( 1, $this->events_on( ORDERS_CHANNEL, 'woo.carts' ), 'staff hear about the cart' );
		// The same cart again: the board's figures are unchanged, so nothing to tell.
		do_action( 'wpsignal_visitor_visit', $this->report( 'home:2:1200>product:' . $product->get_id() . ':900', false ) );
		$this->assertCount( 1, $this->events_on( ORDERS_CHANNEL, 'woo.carts' ), 'unchanged figures publish nothing' );
		// Emptied: the next report clears the items, the ref stays, and the cart is no longer live.
		WC()->cart->empty_cart();
		do_action( 'wpsignal_visitor_visit', $this->report( 'home:2:1200>product:' . $product->get_id() . ':900>cart:0:0', false ) );
		$row = $this->journeys()[0];
		$this->assertSame( $id, $row['ref'] );
		$this->assertSame( array(), $this->data_of( $row )['i'] );
		$this->assertSame( array(), cart_rows() );
		$this->assertSame( 0, count_in_carts( $product->get_id() ) );
		$this->assertCount( 2, $this->events_on( ORDERS_CHANNEL, 'woo.carts' ) );
		// Another journey from the same browser carries the same, empty, cart.
		do_action( 'wpsignal_visitor_visit', $this->report( 'home:2:100', false, self::JOURNEY_2, '1c3c7b5f-0a1b-4d8b-9a2d-2e3f4a5b6c7d' ) );
		$by_id = array_column( $this->journeys(), null, 'journey_id' );
		$this->assertSame( array(), $this->data_of( $by_id[ self::JOURNEY_2 ] )['i'] );
		$this->assertSame( array(), cart_rows() );
	}

	public function test_a_logged_in_shoppers_journey_carries_their_user_id_and_keeps_it(): void {
		$this->set_history( true );
		$user    = $this->admin_id();
		$product = $this->make_product( 10 );
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		$page = 'product:' . $product->get_id();
		do_action( 'wpsignal_visitor_visit', $this->report( 'home:2:100', false ) );
		$this->assertNull( $this->journeys()[0]['user_id'] );
		wp_set_current_user( $user );
		do_action( 'wpsignal_visitor_visit', $this->report( 'home:2:100>' . $page . ':0', false, self::JOURNEY, self::VISITOR, $user ) );
		$this->assertSame( $user, (int) $this->journeys()[0]['user_id'], 'set by the first report while logged in' );
		wp_set_current_user( 0 );
		do_action( 'wpsignal_visitor_leave', $this->report( 'home:2:100>' . $page . ':500>cart:0:0', true ) );
		$this->assertSame( $user, (int) $this->journeys()[0]['user_id'], 'kept once set' );
	}

	public function test_a_carts_first_leave_fires_the_hook_once_and_the_board_lists_the_cart_with_its_customer(): void {
		$this->set_history( true );
		$user = $this->admin_id();
		wp_set_current_user( $user );
		$product = $this->make_product( 10 );
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		$id    = cart_id();
		$fired = array();
		$heard = static function ( string $cart_id, array $row, array $items ) use ( &$fired ): void {
			$fired[] = array( $cart_id, $row, $items );
		};
		add_action( 'shopsocket_cart_left', $heard, 10, 3 );
		try {
			$page = 'product:' . $product->get_id();
			do_action( 'wpsignal_visitor_visit', $this->report( $page . ':0', false, self::JOURNEY, self::VISITOR, $user ) );
			do_action( 'wpsignal_visitor_leave', $this->report( $page . ':3000', true, self::JOURNEY, self::VISITOR, $user ) );
			// Back for a second visit, and gone again.
			do_action( 'wpsignal_visitor_visit', $this->report( 'cart:0:0', false, self::JOURNEY_2, self::VISITOR, $user ) );
			do_action( 'wpsignal_visitor_leave', $this->report( 'cart:0:2000', true, self::JOURNEY_2, self::VISITOR, $user ) );
		} finally {
			remove_action( 'shopsocket_cart_left', $heard );
		}
		$this->assertCount( 1, $fired, 'once per cart' );
		$this->assertSame( $id, $fired[0][0] );
		$this->assertSame( $user, (int) $fired[0][1]['user_id'] );
		$this->assertSame( self::VISITOR, $fired[0][1]['visitor'] );
		$this->assertSame( 9.99, $fired[0][1]['value'] );
		$this->assertNotEmpty( $fired[0][1]['left_at'] );
		$this->assertSame( $page . ':3000', $fired[0][1]['journey'] );
		$this->assertSame( array( $product->get_id() => 1 ), $fired[0][2] );
		$rows = abandoned_rows();
		$this->assertCount( 1, $rows, 'one row per cart, from its newest leave' );
		$this->assertSame( $id, $rows[0]['id'] );
		$this->assertSame( array( $product->get_id() ), $rows[0]['products'] );
		$this->assertEquals( (object) array( $product->get_id() => 1 ), $rows[0]['quantities'] );
		$this->assertSame( 9.99, $rows[0]['value'] );
		$this->assertContains( $rows[0]['journey'], array( 'cart:0:2000', $page . ':3000' ), 'the newest leave, both within the same second here' );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $rows[0]['left_at'] );
		$this->assertNull( $rows[0]['order_id'] );
		$this->assertSame( $user, $rows[0]['user_id'] );
		$this->assertSame( get_userdata( $user )->display_name, $rows[0]['customer'] );
		$this->assertStringContainsString( 'user-edit.php?user_id=' . $user, $rows[0]['customer_url'] );
	}

	public function test_a_visitor_starts_at_most_thirty_journeys_an_hour_and_a_stranger_cannot_change_one(): void {
		$this->set_history( true );
		$product = $this->make_product( 10 );
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		$id = cart_id();
		// The ids are the browser's word: a loop of fresh ones fills nothing past WordSocket's hourly cap.
		for ( $i = 0; $i < 35; $i++ ) {
			do_action( 'wpsignal_visitor_visit', $this->report( 'home:2:1200', false, sprintf( '%08x-0000-4000-8000-000000000000', $i ) ) );
		}
		$this->assertCount( 30, $this->journeys() );
		// A known journey still updates past the cap, but only for the visitor it was created under.
		$known = sprintf( '%08x-0000-4000-8000-000000000000', 3 );
		do_action( 'wpsignal_visitor_visit', $this->report( 'home:2:1200>product:' . $product->get_id() . ':0', false, $known ) );
		$by_id = array_column( $this->journeys(), null, 'journey_id' );
		$this->assertSame( 2, (int) $by_id[ $known ]['steps'] );
		do_action( 'wpsignal_visitor_leave', $this->report( 'home:2:1200>product:' . $product->get_id() . ':0>cart:0:0', true, $known, self::JOURNEY_2 ) );
		$by_id = array_column( $this->journeys(), null, 'journey_id' );
		$this->assertSame( 2, (int) $by_id[ $known ]['steps'], 'another visitor id changes nothing' );
		$this->assertSame( 'open', $by_id[ $known ]['outcome'] );
		$this->assertSame( self::VISITOR, $by_id[ $known ]['visitor'] );
		$this->assertSame( $id, $by_id[ $known ]['ref'] );
	}

	public function test_a_conversion_ends_the_open_journey_with_the_order_on_it_and_shows_on_the_abandoned_row(): void {
		$this->set_history( true );
		$product = $this->make_product( 10 );
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		$id = cart_id();
		do_action( 'wpsignal_visitor_visit', $this->report( 'home:2:1200', false ) );
		do_action( 'wpsignal_visitor_leave', $this->report( 'home:2:1200>product:' . $product->get_id() . ':900', true ) );
		do_action( 'wpsignal_visitor_visit', $this->report( 'product:' . $product->get_id() . ':0', false, self::JOURNEY_2 ) );
		$by_id = array_column( $this->journeys(), null, 'journey_id' );
		$this->assertSame( 'left', $by_id[ self::JOURNEY ]['outcome'] );
		$this->assertSame( 'open', $by_id[ self::JOURNEY_2 ]['outcome'] );
		$this->assertCount( 1, abandoned_rows() );
		$this->assertNull( abandoned_rows()[0]['order_id'] );
		// Both checkouts hand their order to the same listener.
		$this->assertNotFalse( has_action( 'woocommerce_checkout_order_created', 'WPSignal\Extensions\ShopSocket\on_order_created' ) );
		$this->assertNotFalse( has_action( 'woocommerce_store_api_checkout_order_processed', 'WPSignal\Extensions\ShopSocket\on_order_created' ) );
		$order = $this->make_order( $product );
		$this->published = array();
		on_order_created( $order );
		$by_id = array_column( $this->journeys(), null, 'journey_id' );
		$this->assertSame( 'converted', $by_id[ self::JOURNEY_2 ]['outcome'] );
		$this->assertSame( (float) $order->get_total(), (float) $by_id[ self::JOURNEY_2 ]['value'] );
		$this->assertSame( $order->get_id(), $this->data_of( $by_id[ self::JOURNEY_2 ] )['o'], 'the order rides on the journey\'s data' );
		$this->assertSame( array( (string) $product->get_id() => 1 ), $this->data_of( $by_id[ self::JOURNEY_2 ] )['i'], 'beside the items it held' );
		$this->assertSame( 'left', $by_id[ self::JOURNEY ]['outcome'], 'the journey already left stays left' );
		$this->assertSame( array(), cart_rows(), 'a converted journey is not a live cart' );
		$this->assertNotEmpty( $this->events_on( ORDERS_CHANNEL, 'woo.carts' ), 'staff hear about the conversion' );
		$rows = abandoned_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( $id, $rows[0]['id'] );
		$this->assertSame( $order->get_id(), $rows[0]['order_id'], 'the left cart shows the order it became' );
		$this->assertNotNull( $rows[0]['converted_at'] );
		$this->assertStringContainsString( (string) $order->get_id(), $rows[0]['order_url'] );
		// A later leave cannot relabel the converted journey, though the journey itself still grows.
		do_action( 'wpsignal_visitor_leave', $this->report( 'product:' . $product->get_id() . ':5000>thanks:0:0', true, self::JOURNEY_2 ) );
		$by_id = array_column( $this->journeys(), null, 'journey_id' );
		$this->assertSame( 'converted', $by_id[ self::JOURNEY_2 ]['outcome'] );
		$this->assertSame( 'product:' . $product->get_id() . ':5000>thanks:0:0', $by_id[ self::JOURNEY_2 ]['journey'] );
		// The thank-you page's tab closes with the cart emptied: that leave is nobody's cart left behind, and the row keeps its order.
		WC()->cart->empty_cart();
		do_action( 'wpsignal_visitor_visit', $this->report( 'thanks:0:0', false, self::JOURNEY_3 ) );
		do_action( 'wpsignal_visitor_leave', $this->report( 'thanks:0:4000', true, self::JOURNEY_3 ) );
		$rows = abandoned_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( $order->get_id(), $rows[0]['order_id'] );
		$this->assertSame( array( $product->get_id() ), $rows[0]['products'] );
	}

	public function test_a_conversion_stamps_the_moments_that_fired_for_the_cart_within_the_window_across_journeys(): void {
		global $wpdb;
		$this->set_history( true );
		$product = $this->make_product( 10 );
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		$id = cart_id();
		// A moment fires in the first journey; the shopper returns after the gap, which starts a second one.
		do_action( 'wpsignal_visitor_visit', $this->report( 'home:2:1200', false ) );
		WPS::journeys()->on_fired(
			array(
				'visitor'    => self::VISITOR,
				'journey_id' => self::JOURNEY,
				'rule_id'    => 'r1',
				'action'     => 'show',
				'step'       => 0,
				'ms'         => 900,
				'kind'       => 'fired',
				'user_id'    => 0,
				'data'       => array(),
			)
		);
		// Beside it, one that fired before the window and one an earlier order already claimed.
		$firings   = json_decode( (string) $this->journeys()[0]['firings'], true );
		$firings[] = array(
			'rule'   => 'r1',
			'action' => 'show',
			'step'   => 0,
			'ms'     => 0,
			'at'     => gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ),
		);
		$firings[] = array(
			'rule'     => 'r1',
			'action'   => 'show',
			'step'     => 0,
			'ms'       => 0,
			'at'       => current_time( 'mysql', true ),
			'order'    => 5,
			'order_at' => '2026-01-01 00:00:00',
		);
		$firings[] = array(
			'rule'   => 'r1',
			'action' => 'show',
			'step'   => 0,
			'ms'     => 0,
			'kind'   => 'dismissed',
			'at'     => current_time( 'mysql', true ),
		);
		$wpdb->update( journeys_table(), array( 'firings' => wp_json_encode( $firings ) ), array( 'journey_id' => self::JOURNEY ) );
		do_action( 'wpsignal_visitor_visit', $this->report( 'product:' . $product->get_id() . ':0', false, self::JOURNEY_2 ) );
		$order = $this->make_order( $product );
		mark_converted( $id, $order->get_id(), (float) $order->get_total() );
		$by_id   = array_column( $this->journeys(), null, 'journey_id' );
		$firings = json_decode( (string) $by_id[ self::JOURNEY ]['firings'], true );
		$this->assertSame( 'left', $by_id[ self::JOURNEY ]['outcome'], 'the journey the moment fired in still ended as left' );
		$this->assertSame( $order->get_id(), $firings[0]['order'], 'the firing within the window is credited with the order of another journey' );
		$this->assertSame( 900, $firings[0]['ms'] );
		$this->assertArrayHasKey( 'order_at', $firings[0] );
		$this->assertSame( (float) $order->get_total(), (float) $firings[0]['value'], 'the order total rides on the stamp' );
		$this->assertArrayNotHasKey( 'order', $firings[1], 'a firing older than the window is not' );
		$this->assertSame( 5, $firings[2]['order'], 'an earlier order keeps its claim' );
		$this->assertArrayNotHasKey( 'order', $firings[3], 'a dismissal is what followed a firing, not one' );
		$this->assertSame( 'converted', $by_id[ self::JOURNEY_2 ]['outcome'] );
		$replay = array_column( WPS::journeys()->feed( 30, 10 ), null, 'journey' );
		$this->assertSame( $order->get_id(), $replay['home:2:1200']['firings'][0]['order'], 'the replay feed carries the stamp' );
		$this->assertSame( 1, $replay['home:2:1200']['context']['cart']['count'], 'and the cart context the shop adds from the items' );
		$this->assertTrue( $replay['home:2:1200']['has_ref'] );
	}

	public function test_the_week_strip_reads_left_carts_recovered_orders_conversion_the_leak_and_the_products(): void {
		global $wpdb;
		$this->assertNull( week_strip(), 'history off: no strip' );
		$this->set_history( true );
		$now   = current_time( 'mysql', true );
		$early = gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS );
		// Carts on journeys: three left this week (two hold 9001), one converted this week holding 9001, one left last week; two journeys without a cart reached checkout.
		$this->seed_cart( 'aaaa000000000001', array( 9001, 9002 ), 10.0, array(), 'left', 0, 0, 'home:1:1000>cart:0:48000>away:0:5000' );
		$this->seed_cart( 'aaaa000000000002', array( 9001 ), 10.0, array(), 'left', 0, 0, 'product:9001:9000>cart:0:30000' );
		$this->seed_cart( 'aaaa000000000003', array( 9003 ), 10.0, array(), 'left', 0, 0, 'home:1:1000>product:9003:7000' );
		$this->seed_cart( 'aaaa000000000004', array( 9001 ), 50.0, array(), 'converted', 0, 0, 'cart:0:1000>checkout:0:20000>thanks:0:1' );
		$this->seed_cart( 'aaaa000000000005', array( 9002 ), 10.0, array(), 'left', 10 * DAY_IN_SECONDS );
		foreach ( array( array( 'af405b6c-7d8e-4f90-b1a2-3d4e5f607182', $now ), array( 'c1627d8e-9f00-4b12-d3c4-5f6071829304', $early ) ) as list( $jid, $started ) ) {
			$wpdb->insert( journeys_table(), array( 'journey_id' => $jid, 'visitor' => self::VISITOR, 'journey' => 'cart:0:1000>checkout:0:20000', 'steps' => 2, 'outcome' => 'left', 'started_at' => $started, 'last_seen' => $started, 'ended_at' => $started ) );
		}
		// One moment credited with this week's order, another with an order from last week.
		$wpdb->update(
			journeys_table(),
			array( 'firings' => wp_json_encode( array( array( 'rule' => 'r1', 'action' => 'show', 'step' => 0, 'ms' => 1, 'at' => $now, 'order' => 77, 'order_at' => $now, 'value' => 50.0 ), array( 'rule' => 'r1', 'action' => 'show', 'step' => 0, 'ms' => 1, 'at' => $early, 'order' => 66, 'order_at' => $early, 'value' => 20.0 ) ) ) ),
			array( 'ref' => 'aaaa000000000001' )
		);
		wp_cache_delete( 'week_strip', 'shopsocket' );
		$strip = week_strip();
		$this->assertTrue( $strip['journeys'] );
		$this->assertSame( array( 'count' => 3, 'value' => 30.0, 'currency' => get_woocommerce_currency(), 'previous' => 1 ), $strip['left'] );
		$this->assertSame( array( 'count' => 1, 'value' => 50.0, 'previous' => 1 ), $strip['recovered'] );
		$this->assertSame( array( 'reached' => 2, 'orders' => 1, 'percent' => 50, 'previous_percent' => 0 ), $strip['conversion'] );
		$this->assertSame( array( 'kind' => 'cart', 'count' => 2, 'leavers' => 3, 'median_ms' => 39000 ), $strip['leak'], 'the last step that is not away, median of the two cart leavers' );
		$this->assertSame( array( array( 'id' => 9001, 'left' => 2, 'bought' => 1 ), array( 'id' => 9002, 'left' => 1, 'bought' => 0 ), array( 'id' => 9003, 'left' => 1, 'bought' => 0 ) ), $strip['products'] );
		$this->assertSame( $strip, week_strip(), 'served from the cache' );
	}

	public function test_woocommerce_pages_are_named_as_journey_steps(): void {
		$this->assertSame( array( 'kind' => 'category', 'id' => 26 ), page_step( array( 'kind' => 'product_cat', 'id' => 26 ) ) );
		$this->assertSame( array( 'kind' => 'product', 'id' => 9 ), page_step( array( 'kind' => 'product', 'id' => 9 ) ) );
	}
}

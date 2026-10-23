<?php
/**
 * Cart history: the tables, the lifecycle stamps, the journeys archive, the
 * prune, and the eraser. Everything is written in the shopper's own request.
 */

use function WPSignal\Extensions\ShopSocket\abandoned_rows;
use function WPSignal\Extensions\ShopSocket\cart_id;
use function WPSignal\Extensions\ShopSocket\cart_rows;
use function WPSignal\Extensions\ShopSocket\carts_table;
use function WPSignal\Extensions\ShopSocket\cart_items_table;
use function WPSignal\Extensions\ShopSocket\count_in_carts;
use function WPSignal\Extensions\ShopSocket\erase_user;
use function WPSignal\Extensions\ShopSocket\forget_cart;
use function WPSignal\Extensions\ShopSocket\journeys_table;
use function WPSignal\Extensions\ShopSocket\mark_converted;
use function WPSignal\Extensions\ShopSocket\mark_left;
use function WPSignal\Extensions\ShopSocket\on_order_created;
use function WPSignal\Extensions\ShopSocket\on_visitor_report;
use function WPSignal\Extensions\ShopSocket\page_step;
use function WPSignal\Extensions\ShopSocket\prune;
use function WPSignal\Extensions\ShopSocket\record_cart;
use function WPSignal\Extensions\ShopSocket\settings;
use const WPSignal\Extensions\ShopSocket\DB_VERSION;
use const WPSignal\Extensions\ShopSocket\DB_VERSION_OPTION;
use const WPSignal\Extensions\ShopSocket\ORDERS_CHANNEL;

final class HistoryTest extends ExtensionTestCase {

	private const VISITOR = '0b2b6a4e-9f0a-4c7a-8f1c-1d2e3f4a5b6c';
	private const JOURNEY = '7c1d2e3f-4a5b-4c6d-8e7f-0a1b2c3d4e5f';
	private const JOURNEY_2 = '8d2e3f4a-5b6c-4d7e-9f80-1b2c3d4e5f60';

	protected function setUp(): void {
		parent::setUp();
		WC()->cart->empty_cart();
		$this->published = array();
	}

	protected function tearDown(): void {
		WC()->cart->empty_cart();
		parent::tearDown();
	}

	/** A report as WordSocket's visit and leave routes hand it to the actions. */
	private function report( string $journey, bool $leaving, string $journey_id = self::JOURNEY, string $visitor = self::VISITOR ): array {
		return array(
			'visitor'    => $visitor,
			'journey_id' => $journey_id,
			'journey'    => $journey,
			'steps'      => '' === $journey ? 0 : substr_count( $journey, '>' ) + 1,
			'user_id'    => get_current_user_id(),
			'data'       => array(),
			'leaving'    => $leaving,
		);
	}

	/** Move a cart row's stamps into the past, as time passing would. */
	private function age_cart( string $id, array $columns, int $seconds ): void {
		global $wpdb;
		$past = gmdate( 'Y-m-d H:i:s', time() - $seconds );
		$wpdb->update( carts_table(), array_fill_keys( $columns, $past ), array( 'cart_id' => $id ) );
		\WPSignal\Extensions\ShopSocket\invalidate_cart_caches();
	}

	public function test_the_in_cart_count_is_cached_until_the_next_write(): void {
		$this->seed_cart( 'eeee000000000001', array( 9001 ), 10.0 );
		$this->assertSame( 1, count_in_carts( 9001 ) );
		global $wpdb;
		// A write behind the plugin's back is invisible until a write through it bumps the generation.
		$this->assertSame( 0, count_in_carts( 9009 ), 'counted once, then cached' );
		$wpdb->insert( cart_items_table(), array( 'cart_id' => 'eeee000000000001', 'product_id' => 9009, 'quantity' => 1 ) );
		$this->assertSame( 0, count_in_carts( 9009 ), 'served from the cache' );
		\WPSignal\Extensions\ShopSocket\invalidate_cart_caches();
		$this->assertSame( 1, count_in_carts( 9009 ) );
		// Seeding another cart is a write: the count moves at once.
		$this->seed_cart( 'eeee000000000002', array( 9001 ), 10.0 );
		$this->assertSame( 2, count_in_carts( 9001 ) );
	}

	public function test_the_tables_exist_and_are_versioned(): void {
		global $wpdb;
		$this->assertSame( DB_VERSION, get_option( DB_VERSION_OPTION ) );
		$this->assertSame( '2', DB_VERSION );
		$this->assertContains( 'firings', array_column( $wpdb->get_results( 'SHOW COLUMNS FROM ' . journeys_table(), ARRAY_A ), 'Field' ), 'version 2 adds the firings on a journey' );
		foreach ( array( carts_table(), cart_items_table(), journeys_table() ) as $table ) {
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), "$table exists" );
		}
		$this->assertFalse( settings()['history_enabled'], 'history is off until the merchant opts in' );
	}

	public function test_record_cart_upserts_the_row_and_replaces_its_items(): void {
		$a = $this->make_product( 10 );
		$b = $this->make_product( 10 );
		WC()->cart->add_to_cart( $a->get_id(), 2 );
		$id = cart_id();
		$this->assertNotSame( '', $id );

		$rows = cart_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( $id, $rows[0]['id'] );
		$this->assertSame( array( $a->get_id() => 2 ), $rows[0]['quantities'] );
		$this->assertSame( 19.98, $rows[0]['value'] );
		$this->assertNull( $this->stored_cart( $id )['user_id'], 'with history off nobody is named' );

		// A second product replaces the item set; the first row's stamp moves on.
		WC()->cart->add_to_cart( $b->get_id(), 1 );
		$rows = cart_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( array( $a->get_id() => 2, $b->get_id() => 1 ), $rows[0]['quantities'] );
		$this->assertSame( 1, count_in_carts( $a->get_id() ) );
		$this->assertSame( 1, count_in_carts( $b->get_id() ) );

		// Emptied: the row goes.
		WC()->cart->empty_cart();
		$this->assertSame( array(), cart_rows() );
		$this->assertSame( 0, count_in_carts( $a->get_id() ) );
	}

	public function test_two_carts_recorded_in_sequence_keep_their_own_rows(): void {
		$this->seed_cart( 'aaaa000000000001', array( 9001 ), 10.0 );
		$this->seed_cart( 'aaaa000000000002', array( 9002 ), 20.0 );
		$product = $this->make_product( 10 );
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$ids = array_column( cart_rows(), 'id' );
		sort( $ids );
		$expected = array( 'aaaa000000000001', 'aaaa000000000002', cart_id() );
		sort( $expected );
		$this->assertSame( $expected, $ids, 'a row per cart, none clobbered' );
		$this->assertSame( 1, count_in_carts( 9001 ) );
	}

	public function test_a_leave_sets_left_at_for_the_shoppers_cart_and_nothing_else(): void {
		$product = $this->make_product( 10 );
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		$id = cart_id();
		$this->seed_cart( 'bbbb000000000001', array( 9001 ), 10.0 );
		$this->published = array();

		do_action( 'wpsignal_visitor_leave', $this->report( 'product:' . $product->get_id() . ':3000', true ) );

		$this->assertNotNull( $this->stored_cart( $id )['left_at'] );
		$this->assertNull( $this->stored_cart( 'bbbb000000000001' )['left_at'] );
		$this->assertNull( $this->stored_cart( $id )['journey'], 'with history off the journey is not kept' );
		$this->assertSame( array(), $this->journeys(), 'with history off nothing is archived' );
		$carts = $this->events_on( ORDERS_CHANNEL, 'woo.carts' );
		$this->assertNotEmpty( $carts, 'staff hear about the leave' );
		$this->assertArrayHasKey( 'abandoned', $carts[0]['data'] );

		// Still an open row for the live split: the board decides abandonment from presence and its grace.
		$this->assertCount( 2, cart_rows() );
		$abandoned = abandoned_rows();
		$this->assertCount( 1, $abandoned );
		$this->assertSame( $id, $abandoned[0]['id'] );
		$this->assertSame( array( $product->get_id() ), $abandoned[0]['products'] );
		$this->assertNull( $abandoned[0]['order_id'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $abandoned[0]['left_at'] );

		// Back on the site: the row stays listed with its leave, and presence tells the board the shopper is here.
		record_cart( $id );
		$this->assertNotNull( $this->stored_cart( $id )['left_at'] );
		$this->assertCount( 1, abandoned_rows() );
	}

	public function test_a_conversion_stamps_the_order_and_forget_keeps_the_row_only_with_history_on(): void {
		$product = $this->make_product( 10 );
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		$id    = cart_id();
		$order = $this->make_order( $product );

		// Both checkouts hand their order to the same listener; WooCommerce's own stock reservation on the hook is not under test here.
		$this->assertNotFalse( has_action( 'woocommerce_checkout_order_created', 'WPSignal\Extensions\ShopSocket\on_order_created' ) );
		$this->assertNotFalse( has_action( 'woocommerce_store_api_checkout_order_processed', 'WPSignal\Extensions\ShopSocket\on_order_created' ) );

		// History off: the conversion is stamped, then the emptied cart is forgotten as before.
		on_order_created( $order );
		$this->assertSame( $order->get_id(), (int) $this->stored_cart( $id )['order_id'] );
		$this->assertNotNull( $this->stored_cart( $id )['converted_at'] );
		$this->assertSame( array(), cart_rows(), 'a converted row is not an open cart' );
		forget_cart( $id );
		$this->assertNull( $this->stored_cart( $id ) );

		// History on: the converted row stays as the record.
		$this->set_history( true );
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		on_order_created( $order );
		forget_cart( $id );
		$this->assertNotNull( $this->stored_cart( $id ), 'kept' );
		$this->assertSame( $order->get_id(), (int) $this->stored_cart( $id )['order_id'] );

		// A report that lands after the conversion, the cart not yet emptied, must not erase it (the checkout page's own visit races the order).
		on_visitor_report( $this->report( 'checkout:0:0', false ) );
		$this->assertSame( $order->get_id(), (int) $this->stored_cart( $id )['order_id'] );
		$this->assertNotNull( $this->stored_cart( $id )['converted_at'] );

		// The checkout empties the cart: the record stays. The next cart of the same shopper starts the row over.
		WC()->cart->empty_cart();
		record_cart( $id );
		$this->assertNotNull( $this->stored_cart( $id )['converted_at'], 'an empty cart leaves the record alone' );
		WC()->cart->add_to_cart( $product->get_id(), 2 );
		$row = $this->stored_cart( $id );
		$this->assertNull( $row['converted_at'] );
		$this->assertNull( $row['order_id'] );
		$this->assertNull( $row['left_at'], 'a new cart starts with no leave' );
		$this->assertSame( get_current_user_id() > 0 ? get_current_user_id() : null, null === $row['user_id'] ? null : (int) $row['user_id'] );
	}

	public function test_a_journey_row_is_written_per_report_and_a_shorter_report_never_shortens_it(): void {
		$this->set_history( true );
		$product = $this->make_product( 10 );
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		$id = cart_id();

		on_visitor_report( $this->report( 'home:2:1200>product:' . $product->get_id() . ':0', false ) );
		$journeys = $this->journeys();
		$this->assertCount( 1, $journeys );
		$this->assertSame( self::JOURNEY, $journeys[0]['journey_id'] );
		$this->assertSame( self::VISITOR, $journeys[0]['visitor'] );
		$this->assertSame( $id, $journeys[0]['cart_id'] );
		$this->assertSame( 2, (int) $journeys[0]['steps'] );
		$this->assertSame( 'open', $journeys[0]['outcome'] );
		$this->assertNull( $journeys[0]['ended_at'] );
		$this->assertArrayNotHasKey( 'user_id', $journeys[0], 'the archive never names a user' );
		$this->assertSame( self::VISITOR, $this->stored_cart( $id )['visitor'] );
		$this->assertSame( 'home:2:1200>product:' . $product->get_id() . ':0', $this->stored_cart( $id )['journey'] );

		// The leave of this page (3 steps) lands, then the visit of the previous page (2 steps) arrives late.
		$long = 'home:2:1200>product:' . $product->get_id() . ':4000>cart:0:500';
		on_visitor_report( $this->report( $long, true ) );
		on_visitor_report( $this->report( 'home:2:1200>product:' . $product->get_id() . ':4000', false ) );
		$journeys = $this->journeys();
		$this->assertCount( 1, $journeys );
		$this->assertSame( $long, $journeys[0]['journey'] );
		$this->assertSame( 3, (int) $journeys[0]['steps'] );
		$this->assertSame( 'left', $journeys[0]['outcome'] );
		$this->assertNotNull( $journeys[0]['ended_at'] );
		$this->assertSame( $long, $this->stored_cart( $id )['journey'], 'the live copy keeps the longer journey too' );
	}

	public function test_a_cart_starts_at_most_thirty_journeys_an_hour_and_a_stranger_cannot_change_one(): void {
		$this->set_history( true );
		$product = $this->make_product( 10 );
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		$id = cart_id();

		// The ids are the browser's word: a loop of fresh ones fills nothing past the hourly cap.
		for ( $i = 0; $i < 35; $i++ ) {
			on_visitor_report( $this->report( 'home:2:1200', false, sprintf( '%08x-0000-4000-8000-000000000000', $i ) ) );
		}
		$this->assertCount( 30, $this->journeys() );

		// A known journey still updates past the cap, but only for the visitor it was created under.
		$known = sprintf( '%08x-0000-4000-8000-000000000000', 3 );
		on_visitor_report( $this->report( 'home:2:1200>product:' . $product->get_id() . ':0', false, $known ) );
		$by_id = array_column( $this->journeys(), null, 'journey_id' );
		$this->assertSame( 2, (int) $by_id[ $known ]['steps'] );
		on_visitor_report( $this->report( 'home:2:1200>product:' . $product->get_id() . ':0>cart:0:0', true, $known, self::JOURNEY_2 ) );
		$by_id = array_column( $this->journeys(), null, 'journey_id' );
		$this->assertSame( 2, (int) $by_id[ $known ]['steps'], 'another visitor id changes nothing' );
		$this->assertSame( 'open', $by_id[ $known ]['outcome'] );
		$this->assertSame( self::VISITOR, $by_id[ $known ]['visitor'] );
		$this->assertSame( $id, $by_id[ $known ]['cart_id'] );
	}

	public function test_a_second_journey_from_the_same_visitor_ends_the_first_and_a_conversion_closes_the_open_one(): void {
		$this->set_history( true );
		$product = $this->make_product( 10 );
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		$id = cart_id();

		on_visitor_report( $this->report( 'home:2:1200', false ) );
		on_visitor_report( $this->report( 'product:' . $product->get_id() . ':0', false, self::JOURNEY_2 ) );
		$by_id = array_column( $this->journeys(), null, 'journey_id' );
		$this->assertCount( 2, $by_id );
		$this->assertSame( 'left', $by_id[ self::JOURNEY ]['outcome'] );
		$this->assertSame( $by_id[ self::JOURNEY ]['last_seen'], $by_id[ self::JOURNEY ]['ended_at'], 'ended when it was last heard from' );
		$this->assertSame( 'open', $by_id[ self::JOURNEY_2 ]['outcome'] );
		$this->assertSame( self::JOURNEY_2, $this->stored_cart( $id )['journey_id'] );

		// A late leave for the old journey must not touch the new one.
		on_visitor_report( $this->report( 'home:2:1200>away:0:1800000', true ) );
		$by_id = array_column( $this->journeys(), null, 'journey_id' );
		$this->assertSame( 'open', $by_id[ self::JOURNEY_2 ]['outcome'] );
		$this->assertNull( $by_id[ self::JOURNEY_2 ]['ended_at'] );
		$this->assertSame( 'home:2:1200>away:0:1800000', $by_id[ self::JOURNEY ]['journey'] );

		// Checkout: the open journey converts and a later leave cannot reopen or relabel it.
		$order = $this->make_order( $product );
		mark_converted( $id, $order->get_id(), (float) $order->get_total() );
		on_visitor_report( $this->report( 'product:' . $product->get_id() . ':5000>thanks:0:0', true, self::JOURNEY_2 ) );
		$by_id = array_column( $this->journeys(), null, 'journey_id' );
		$this->assertSame( 'converted', $by_id[ self::JOURNEY_2 ]['outcome'] );
		$this->assertSame( $order->get_id(), (int) $by_id[ self::JOURNEY_2 ]['order_id'] );
		$this->assertSame( 'product:' . $product->get_id() . ':5000>thanks:0:0', $by_id[ self::JOURNEY_2 ]['journey'], 'the journey itself still grows' );
	}

	public function test_prune_respects_the_toggle_and_both_retentions(): void {
		$this->seed_cart( 'cccc000000000001', array( 9001 ), 10.0 );
		$this->seed_cart( 'cccc000000000002', array( 9002 ), 10.0 );
		// Three days old: past the 48 h session, inside a 30 day retention.
		$this->age_cart( 'cccc000000000002', array( 'first_seen', 'last_seen', 'left_at' ), 3 * DAY_IN_SECONDS );

		global $wpdb;
		$now = current_time( 'mysql', true );
		$old = gmdate( 'Y-m-d H:i:s', strtotime( '-13 months' ) );
		foreach ( array( array( self::JOURNEY, $now ), array( self::JOURNEY_2, $old ) ) as list( $jid, $started ) ) {
			$wpdb->insert( journeys_table(), array( 'journey_id' => $jid, 'visitor' => self::VISITOR, 'journey' => 'home:1:1', 'steps' => 1, 'started_at' => $started, 'last_seen' => $started ) );
		}

		// History on: the old cart stays within 30 days, the 13-month journey goes, the fresh one stays.
		$this->set_history( true, 30, 12 );
		prune();
		$this->assertNotNull( $this->stored_cart( 'cccc000000000002' ) );
		$this->assertSame( array( self::JOURNEY ), array_column( $this->journeys(), 'journey_id' ) );

		// A one day retention takes the old cart and its items.
		$this->set_history( true, 1, 12 );
		prune();
		$this->assertNull( $this->stored_cart( 'cccc000000000002' ) );
		$this->assertSame( 0, count_in_carts( 9002 ) );
		$this->assertNotNull( $this->stored_cart( 'cccc000000000001' ) );

		// History off: rows past the session go, and every journey.
		$this->set_history( false );
		$this->age_cart( 'cccc000000000001', array( 'last_seen' ), 3 * DAY_IN_SECONDS );
		prune();
		$this->assertNull( $this->stored_cart( 'cccc000000000001' ) );
		$this->assertSame( array(), $this->journeys() );
	}

	public function test_the_eraser_removes_a_users_carts_and_the_journeys_reachable_through_them(): void {
		global $wpdb;
		$this->set_history( true );
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		$user   = (int) $admins[0];
		$this->seed_cart( 'dddd000000000001', array( 9001 ), 10.0 );
		$this->seed_cart( 'dddd000000000002', array( 9002 ), 10.0 );
		$wpdb->update( carts_table(), array( 'user_id' => $user ), array( 'cart_id' => 'dddd000000000001' ) );
		$now = current_time( 'mysql', true );
		$wpdb->insert( journeys_table(), array( 'journey_id' => self::JOURNEY, 'visitor' => self::VISITOR, 'cart_id' => 'dddd000000000001', 'journey' => 'home:1:1', 'steps' => 1, 'started_at' => $now, 'last_seen' => $now ) );
		$wpdb->insert( journeys_table(), array( 'journey_id' => self::JOURNEY_2, 'visitor' => self::VISITOR, 'cart_id' => 'dddd000000000002', 'journey' => 'home:1:1', 'steps' => 1, 'started_at' => $now, 'last_seen' => $now ) );

		$this->assertSame( 3, erase_user( $user ), 'the cart row, its item, and its journey' );
		$this->assertNull( $this->stored_cart( 'dddd000000000001' ) );
		$this->assertNotNull( $this->stored_cart( 'dddd000000000002' ), 'a guest cart is nobody\'s to erase' );
		$this->assertSame( array( self::JOURNEY_2 ), array_column( $this->journeys(), 'journey_id' ) );
		$this->assertSame( 0, erase_user( 0 ) );
	}

	public function test_woocommerce_pages_are_named_as_journey_steps(): void {
		$this->assertSame( array( 'kind' => 'category', 'id' => 26 ), page_step( array( 'kind' => 'product_cat', 'id' => 26 ) ) );
		$this->assertSame( array( 'kind' => 'product', 'id' => 7 ), page_step( array( 'kind' => 'product', 'id' => 7 ) ), 'a post type stays as WordSocket named it' );
		$this->assertSame( array( 'kind' => 'page', 'id' => 3 ), page_step( array( 'kind' => 'page', 'id' => 3 ) ), 'outside the shop pages nothing changes' );
	}
}

<?php
/**
 * Cart history on WordSocket's journeys: what a report puts on a journey, the live and abandoned
 * reads, the counts, the cart-left hook, the conversion, the strip, and the page steps.
 */

use function WPSignal\Extensions\ShopSocket\cart_id;
use function WPSignal\Extensions\ShopSocket\cart_rows;
use function WPSignal\Extensions\ShopSocket\count_in_carts;
use function WPSignal\Extensions\ShopSocket\in_carts_enabled;
use function WPSignal\Extensions\ShopSocket\journeys_table;
use WPSignal\Extensions\ShopSocket\Privacy;
use WPSignal\Journeys;
use WPSignal\WPS;
use function WPSignal\Extensions\ShopSocket\mark_converted;
use function WPSignal\Extensions\ShopSocket\on_order_created;
use function WPSignal\Extensions\ShopSocket\page_step;
use function WPSignal\Extensions\ShopSocket\abandoned_rows;
use function WPSignal\Extensions\ShopSocket\abandoned_count;
use function WPSignal\Extensions\ShopSocket\abandoned_limit;
use function WPSignal\Extensions\ShopSocket\abandonment;
use function WPSignal\Extensions\ShopSocket\carts_left;
use function WPSignal\Extensions\ShopSocket\day_strip;
use function WPSignal\Extensions\ShopSocket\sales;
use function WPSignal\Extensions\ShopSocket\seed_journeys;
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
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
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
		$this->assertNull( day_strip() );
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
		$wpdb->update(
			journeys_table(),
			array(
				'data' => wp_json_encode(
					array(
						'c' => 'USD',
						'i' => array( '9009' => 1 ),
					)
				),
			),
			array( 'ref' => 'eeee000000000001' )
		);
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
		$this->assertSame(
			array(
				'c' => get_woocommerce_currency(),
				'i' => array( (string) $product->get_id() => 2 ),
			),
			$this->data_of( $row ),
			'its items and currency as data'
		);
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
		$this->assertSame( self::VISITOR, $rows[0]['v'], 'the visitor who left it' );
		$this->assertSame( array( $product->get_id() ), $rows[0]['products'] );
		$this->assertEquals( (object) array( $product->get_id() => 1 ), $rows[0]['quantities'] );
		$this->assertSame( 9.99, $rows[0]['value'] );
		$this->assertContains( $rows[0]['journey'], array( 'cart:0:2000', $page . ':3000' ), 'the newest leave, both within the same second here' );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $rows[0]['left_at'] );
		$this->assertNull( $rows[0]['order_id'] );
		$this->assertNull( $rows[0]['order_value'] );
		$this->assertSame( $user, $rows[0]['user_id'] );
		$this->assertSame( get_userdata( $user )->display_name, $rows[0]['customer'] );
		$this->assertStringContainsString( 'user-edit.php?user_id=' . $user, $rows[0]['customer_url'] );
	}

	public function test_the_board_lists_only_the_carts_left_in_its_last_day(): void {
		$this->set_history( true );
		$this->seed_cart( 'bbbb000000000001', array( 9001 ), 10.0, array(), 'left', 2 * HOUR_IN_SECONDS );
		$this->seed_cart( 'bbbb000000000002', array( 9002 ), 10.0, array(), 'left', 30 * HOUR_IN_SECONDS );
		$this->assertSame( array( 'bbbb000000000001' ), array_column( abandoned_rows(), 'id' ), 'a cart left thirty hours ago is off the board' );
		$this->assertSame( array( 'bbbb000000000001', 'bbbb000000000002' ), array_column( abandoned_rows( 48 ), 'id' ), 'and on it over 48 hours, newest leave first' );
		$this->assertCount( 2, abandoned_rows( 168 ) );
		$total = static fn( int $carts, int $products, float $value ): array => array(
			'carts'     => $carts,
			'products'  => $products,
			'value'     => $value,
			'currency'  => get_woocommerce_currency(),
			'recovered' => 0,
		);
		$this->assertSame(
			array(
				24  => $total( 1, 1, 10.0 ),
				48  => $total( 2, 2, 20.0 ),
				168 => $total( 2, 2, 20.0 ),
			),
			abandoned_count()['totals'],
			'the full count by time frame'
		);
	}

	public function test_the_carts_left_are_counted_in_full_past_the_rows_the_board_lists(): void {
		$this->set_history( true );
		for ( $i = 0; $i < 60; $i++ ) {
			$this->seed_cart( sprintf( 'cccc%012d', $i ), array( 9001 + $i % 3 ), 10.0, array(), 'left', HOUR_IN_SECONDS + $i );
		}
		// One more left and ordered since, and one left with nothing in it: neither is a cart left behind.
		$this->seed_cart( 'dddd000000000001', array( 9009 ), 10.0, array(), 'left', 2 * HOUR_IN_SECONDS );
		$ordered = $this->seed_cart( 'dddd000000000001', array( 9009 ), 10.0, array(), 'converted', HOUR_IN_SECONDS );
		$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'UPDATE ' . journeys_table() . ' SET data = %s WHERE journey_id = UNHEX(%s)', wp_json_encode( array( 'c' => get_woocommerce_currency(), 'i' => array( '9009' => 1 ), 'o' => 77 ) ), Journeys::hex( $ordered ) ) );
		$this->seed_cart( 'dddd000000000002', array(), 0.0, array(), 'left', HOUR_IN_SECONDS );
		$rows = abandoned_rows();
		$this->assertCount( 50, array_filter( $rows, static fn( array $row ): bool => null === $row['order_id'] ), 'the board lists the newest fifty not ordered' );
		$this->assertCount( 51, $rows, 'and the one ordered since beside them' );
		$this->assertSame( 77, array_column( $rows, 'order_id', 'id' )['dddd000000000001'] ?? null );
		$count = abandoned_count();
		$day   = $count['totals'][24];
		$this->assertSame( 60, $day['carts'], 'and counts every one not ordered' );
		$this->assertSame( 1, $day['recovered'], 'the one ordered since is counted apart' );
		$this->assertSame( 3, $day['products'] );
		$this->assertSame( 600.0, $day['value'] );
		$this->assertSame( array(), $count['returning'], 'none of them has a journey that has not ended' );
		// The shopper of the oldest cart, past the rows listed, is on the site again.
		$this->seed_cart( sprintf( 'cccc%012d', 59 ), array( 9003 ), 10.0, array(), 'open', 60 );
		$count = abandoned_count();
		$this->assertSame( 60, $count['totals'][24]['carts'], 'the count holds: presence is the board\'s to read' );
		$this->assertSame( array( sprintf( 'cccc%012d', 59 ) ), array_keys( $count['returning'] ), 'and the cart is named for the board to take out' );
		$this->assertSame( 10.0, $count['returning'][ sprintf( 'cccc%012d', 59 ) ]['value'] );
		$this->assertCount( 51, abandoned_rows(), 'an open journey lists nothing' );
		$this->assertNotContains( sprintf( 'cccc%012d', 59 ), array_column( abandoned_rows(), 'id' ) );
	}

	public function test_every_table_of_the_abandoned_carts_lists_fifty_rows_for_each_day_and_says_how_many_there_are(): void {
		global $wpdb;
		$this->set_history( true );
		// 55 carts left, each holding a product of its own on that product's page, and 52 more left and ordered since.
		for ( $i = 0; $i < 55; $i++ ) {
			$product = $this->make_product( 10 )->get_id();
			$this->seed_cart( sprintf( 'eeee%012d', $i ), array( $product ), 10.0, array(), 'left', HOUR_IN_SECONDS + $i, 0, 'home:1:5>product:' . $product . ':9' );
		}
		for ( $i = 0; $i < 52; $i++ ) {
			$this->seed_cart( sprintf( 'ffff%012d', $i ), array( 9009 ), 10.0, array(), 'left', 3 * HOUR_IN_SECONDS + $i, 0, 'cart:0:9' );
			$this->seed_cart( sprintf( 'ffff%012d', $i ), array( 9009 ), 25.0, array(), 'converted', 2 * HOUR_IN_SECONDS + $i );
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . journeys_table() . ' SET data = %s WHERE outcome = %s', wp_json_encode( array( 'c' => get_woocommerce_currency(), 'i' => array( '9009' => 1 ), 'o' => 77 ) ), 'converted' ) );
		foreach ( array( 24, 48 ) as $hours ) {
			wp_cache_delete( 'abandonment_' . $hours, 'shopsocket' );
		}
		$this->assertSame( 50, abandoned_limit( 24 ) );
		$this->assertSame( 350, abandoned_limit( 168 ) );
		$ordered = static fn( array $rows ): int => count( array_filter( $rows, static fn( array $row ): bool => null !== $row['order_id'] ) );
		$rows    = abandoned_rows();
		$this->assertSame( array( 50, 50 ), array( count( $rows ) - $ordered( $rows ), $ordered( $rows ) ), 'fifty of each a day' );
		$total = abandoned_count()['totals'][24];
		$this->assertSame( array( 55, 52 ), array( $total['carts'], $total['recovered'] ), 'counted in full' );
		$day = abandonment();
		$this->assertSame( array( 50, 55 ), array( count( $day['products'] ), $day['products_total'] ) );
		$this->assertSame( array( 50, 56 ), array( count( $day['pages'] ), $day['pages_total'] ), 'a page for each product and the cart' );
		$this->assertSame( array( 'cart', 52 ), array( $day['pages'][0]['kind'], $day['pages'][0]['left'] ), 'the most left from first' );
		// Over 48 hours each table has room for a hundred.
		$rows = abandoned_rows( 48 );
		$this->assertSame( array( 55, 52 ), array( count( $rows ) - $ordered( $rows ), $ordered( $rows ) ) );
		$two_days = abandonment( 48 );
		$this->assertSame( array( 55, 56 ), array( count( $two_days['products'] ), count( $two_days['pages'] ) ) );
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

	public function test_a_conversion_ends_the_open_journey_with_the_order_on_it_and_shows_on_the_left_cart(): void {
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
		$this->assertSame( 1, carts_left( time() )['count'], 'the cart is left behind until it is ordered' );
		$this->assertCount( 1, abandoned_rows() );
		$this->assertNull( abandoned_rows()[0]['order_id'] );
		// Both checkouts hand their order to the same listener.
		$this->assertNotFalse( has_action( 'woocommerce_checkout_order_created', 'WPSignal\Extensions\ShopSocket\on_order_created' ) );
		$this->assertNotFalse( has_action( 'woocommerce_store_api_checkout_order_processed', 'WPSignal\Extensions\ShopSocket\on_order_created' ) );
		$order           = $this->make_order( $product );
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
		$this->assertSame( $id, $by_id[ self::JOURNEY_2 ]['ref'] );
		$this->assertSame( 0, carts_left( time() )['count'], 'a cart that became an order is not left behind' );
		$rows = abandoned_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( $id, $rows[0]['id'] );
		$this->assertSame( $order->get_id(), $rows[0]['order_id'], 'the left cart shows the order it became' );
		$this->assertNotNull( $rows[0]['converted_at'] );
		$this->assertStringContainsString( (string) $order->get_id(), $rows[0]['order_url'] );
		$this->assertSame( round( (float) $order->get_total(), 2 ), $rows[0]['order_value'], 'with what the order was worth' );
		$day = abandoned_count()['totals'][24];
		$this->assertSame( array( 0, 1 ), array( $day['carts'], $day['recovered'] ), 'counted as recovered, not as left behind' );
		// A later leave cannot relabel the converted journey, though the journey itself still grows.
		do_action( 'wpsignal_visitor_leave', $this->report( 'product:' . $product->get_id() . ':5000>thanks:0:0', true, self::JOURNEY_2 ) );
		$by_id = array_column( $this->journeys(), null, 'journey_id' );
		$this->assertSame( 'converted', $by_id[ self::JOURNEY_2 ]['outcome'] );
		$this->assertSame( 'product:' . $product->get_id() . ':5000>thanks:0:0', $by_id[ self::JOURNEY_2 ]['journey'] );
		// The thank-you page's tab closes with the cart emptied: that leave is nobody's cart left behind.
		WC()->cart->empty_cart();
		do_action( 'wpsignal_visitor_visit', $this->report( 'thanks:0:0', false, self::JOURNEY_3 ) );
		do_action( 'wpsignal_visitor_leave', $this->report( 'thanks:0:4000', true, self::JOURNEY_3 ) );
		$this->assertSame( 0, carts_left( time() )['count'] );
		$rows = abandoned_rows();
		$this->assertCount( 1, $rows, 'and the left cart keeps its order' );
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
				's'          => 1,
				'kind'       => 'fired',
				'user_id'    => 0,
				'data'       => array(),
			)
		);
		// Beside it, one that fired before the window and a dismissal.
		$firings   = Journeys::unpack_firings( (string) $this->journeys()[0]['firings'] );
		$firings[] = array(
			'rule'   => 'r1',
			'action' => 'show',
			'step'   => 0,
			's'      => 0,
			'kind'   => 'fired',
			'at'     => gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ),
		);
		$firings[] = array(
			'rule'   => 'r1',
			'action' => 'show',
			'step'   => 0,
			's'      => 0,
			'kind'   => 'dismissed',
			'at'     => current_time( 'mysql', true ),
		);
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . journeys_table() . ' SET firings = %s WHERE journey_id = UNHEX(%s)', Journeys::pack_firings( $firings ), Journeys::hex( self::JOURNEY ) ) );
		// The first journey went quiet longer ago than the gap, so the next one finds it over.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . journeys_table() . ' SET last_seen = %s WHERE journey_id = UNHEX(%s)', gmdate( 'Y-m-d H:i:s', time() - Journeys::GAP_SECONDS - 60 ), Journeys::hex( self::JOURNEY ) ) );
		do_action( 'wpsignal_visitor_visit', $this->report( 'product:' . $product->get_id() . ':0', false, self::JOURNEY_2 ) );
		$order = $this->make_order( $product );
		mark_converted( $id, $order->get_id(), (float) $order->get_total() );
		$by_id   = array_column( $this->journeys(), null, 'journey_id' );
		$firings = Journeys::unpack_firings( (string) $by_id[ self::JOURNEY ]['firings'] );
		$this->assertSame( 'incomplete', $by_id[ self::JOURNEY ]['outcome'], 'the journey the moment fired in was never seen ending; the order is the second journey\'s' );
		$this->assertSame( $order->get_id(), $firings[0]['order'], 'the firing within the window is credited with the order of another journey' );
		$this->assertSame( 1, $firings[0]['s'] );
		$this->assertArrayHasKey( 'order_at', $firings[0] );
		$this->assertSame( (float) $order->get_total(), (float) $firings[0]['value'], 'the order total rides on the stamp' );
		$this->assertSame( $order->get_id(), $firings[1]['order'], 'the row is credited once, so every firing of it reads the order' );
		$this->assertArrayNotHasKey( 'order', $firings[2], 'a dismissal is what followed a firing, not one' );
		$this->assertSame( 0, WPS::journeys()->credit( $id, 999, 1.0 ), 'a credited row keeps its order' );
		$this->assertSame( 'converted', $by_id[ self::JOURNEY_2 ]['outcome'] );
		$replay = array_column( WPS::journeys()->feed( 30, 10 ), null, 'journey' );
		$this->assertSame( $order->get_id(), $replay['home:2:1200']['firings'][0]['order'], 'the replay feed carries the stamp' );
		$this->assertSame( 1, $replay['home:2:1200']['context']['cart']['count'], 'and the cart context the shop adds from the items' );
		$this->assertTrue( $replay['home:2:1200']['has_ref'] );
	}

	public function test_the_day_strip_reads_left_carts_recovered_orders_and_conversion_and_a_time_frame_its_leak_and_products(): void {
		global $wpdb;
		$this->assertNull( day_strip(), 'history off: no strip' );
		$this->assertNull( abandonment(), 'nor what the left carts tell' );
		$this->set_history( true );
		$now   = current_time( 'mysql', true );
		$early = gmdate( 'Y-m-d H:i:s', time() - 30 * HOUR_IN_SECONDS );
		// Carts on journeys: three left this day (two hold 9001), one converted this day holding 9001, one left the day before; two journeys without a cart reached checkout.
		$this->seed_cart( 'aaaa000000000001', array( 9001, 9002 ), 10.0, array(), 'left', 0, 0, 'home:1:1>cart:0:48>away:0:5' );
		$this->seed_cart( 'aaaa000000000002', array( 9001 ), 10.0, array(), 'left', 0, 0, 'product:9001:9>cart:0:30' );
		$this->seed_cart( 'aaaa000000000003', array( 9003 ), 10.0, array(), 'left', 0, 0, 'home:1:1>product:9003:7' );
		$this->seed_cart( 'aaaa000000000004', array( 9001 ), 50.0, array(), 'converted', 0, 0, 'cart:0:1>checkout:0:20>thanks:0:1' );
		$this->seed_cart( 'aaaa000000000005', array( 9002 ), 10.0, array(), 'left', 30 * HOUR_IN_SECONDS );
		foreach ( array( array( 'af405b6c-7d8e-4f90-b1a2-3d4e5f607182', $now ), array( 'c1627d8e-9f00-4b12-d3c4-5f6071829304', $early ) ) as list( $jid, $started ) ) {
			Journeys::insert_row(
				array(
					'journey_id' => $jid,
					'visitor'    => self::VISITOR,
					'journey'    => 'cart:0:1>checkout:0:20',
					'outcome'    => 'left',
					'started_at' => $started,
				)
			);
		}
		// One cart's moment credited with this day's order, another cart's with an order from the day before.
		foreach ( array(
			array( 'aaaa000000000001', $now, 77, 50.0 ),
			array( 'aaaa000000000005', $early, 66, 20.0 ),
		) as list( $ref, $at, $order_id, $value ) ) {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . journeys_table() . ' SET firings = %s WHERE ref = %s',
					Journeys::pack_firings(
						array(
							array(
								'rule'     => 'r1',
								'action'   => 'show',
								'step'     => 0,
								's'        => 1,
								'kind'     => 'fired',
								'at'       => $at,
								'order'    => $order_id,
								'order_at' => $at,
								'value'    => $value,
							),
						)
					),
					$ref
				)
			);
		}
		wp_cache_delete( 'day_strip', 'shopsocket' );
		wp_cache_delete( 'abandonment_24', 'shopsocket' );
		wp_cache_delete( 'abandonment_48', 'shopsocket' );
		$strip = day_strip();
		$day   = abandonment();
		$this->assertSame( array( 'since', 'journeys', 'trend_days', 'sales', 'left', 'recovered', 'conversion' ), array_keys( $strip ) );
		$this->assertSame( 24, $day['hours'] );
		$this->assertTrue( $strip['journeys'] );
		$this->assertCount( 7, $strip['trend_days'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $strip['trend_days'][6] );
		$this->assertLessThanOrEqual( 5, time() - strtotime( $strip['trend_days'][6] ), 'the last day ends now' );
		$this->assertSame( DAY_IN_SECONDS, strtotime( $strip['trend_days'][6] ) - strtotime( $strip['trend_days'][5] ), 'each day is 24 hours' );
		// Seven days of 24 hours, oldest first: thirty hours ago is in the sixth, now in the last.
		$series = static fn( int $early, int $now ): array => array_replace(
			array_fill( 0, 7, 0 ),
			array(
				5 => $early,
				6 => $now,
			)
		);
		$this->assertSame(
			array(
				'count'    => 3,
				'value'    => 30.0,
				'currency' => get_woocommerce_currency(),
				'average'  => 0.6,
				'days'     => $series( 1, 3 ),
			),
			$strip['left']
		);
		$this->assertSame(
			array(
				'count'   => 1,
				'value'   => 50.0,
				'average' => 0.3,
				'days'    => $series( 1, 1 ),
			),
			$strip['recovered']
		);
		$this->assertSame(
			array(
				'reached'         => 2,
				'orders'          => 1,
				'percent'         => 50,
				'average_percent' => 33,
				'days'            => $series( 0, 50 ),
			),
			$strip['conversion']
		);
		$this->assertSame(
			array(
				'kind'     => 'cart',
				'count'    => 2,
				'leavers'  => 3,
				'median_s' => 39,
			),
			$day['leak'],
			'the last step that is not away, median of the two cart leavers'
		);
		$this->assertSame(
			array(
				array(
					'kind'     => 'cart',
					'id'       => 0,
					'left'     => 2,
					'median_s' => 39,
				),
				array(
					'kind'     => 'deleted',
					'id'       => 0,
					'left'     => 1,
					'median_s' => 7,
				),
			),
			$day['pages'],
			'the pages most left from, a page that is gone as one row'
		);
		$this->assertSame( array( 2, 3 ), array( $day['pages_total'], $day['products_total'] ), 'with how many there are of each' );
		$this->assertSame(
			array(
				array(
					'id'     => 9001,
					'left'   => 2,
					'bought' => 1,
				),
				array(
					'id'     => 9002,
					'left'   => 1,
					'bought' => 0,
				),
				array(
					'id'     => 9003,
					'left'   => 1,
					'bought' => 0,
				),
			),
			$day['products']
		);
		$this->assertSame( $strip, day_strip(), 'served from the cache' );
		$this->assertSame( $day, abandonment(), 'and so is a time frame' );
		// Over 48 hours the cart left thirty hours ago counts too.
		$two_days = abandonment( 48 );
		$this->assertSame( 48, $two_days['hours'] );
		$this->assertSame(
			array(
				9001 => 2,
				9002 => 2,
				9003 => 1,
			),
			array_column( $two_days['products'], 'left', 'id' )
		);
	}

	public function test_the_seed_command_writes_shopper_journeys_with_carts_under_wordsockets_seed_prefix(): void {
		require_once dirname( __DIR__, 2 ) . '/inc/cli.php';
		$this->set_history( true );
		$product   = $this->make_product( 10 );
		$catalogue = array(
			'products' => array( $product->get_id() ),
			'prices'   => array( $product->get_id() => 9.99 ),
			'terms'    => array(),
		);
		$made      = seed_journeys( 60, 7, 7, $catalogue );
		$rows      = $this->journeys();
		$this->assertSame( 60, $made['journeys'] );
		$this->assertCount( 60, $rows );
		$this->assertGreaterThan( 0, $made['carts'] );
		$this->assertCount( $made['carts'], array_filter( $rows, static fn( array $row ): bool => null !== $row['ref'] ) );
		foreach ( $rows as $row ) {
			$this->assertStringStartsWith( \WPSignal\Cli::SEED_PREFIX, $row['visitor'], 'so wp wordsocket journeys unseed removes it' );
			$this->assertContains( $row['outcome'], array( 'left', 'converted' ), 'nothing seeded is live' );
			$this->assertGreaterThanOrEqual( time() - 7 * DAY_IN_SECONDS - MINUTE_IN_SECONDS, strtotime( $row['started_at'] . ' UTC' ) );
			$this->assertLessThanOrEqual( time(), strtotime( $row['last_seen'] . ' UTC' ) );
			foreach ( explode( '>', $row['journey'] ) as $step ) {
				$seconds = (int) explode( ':', $step )[2];
				$this->assertGreaterThanOrEqual( 1, $seconds, $step );
				$this->assertLessThanOrEqual( 900, $seconds, $step );
			}
			if ( null !== $row['ref'] ) {
				$this->assertSame( array( $product->get_id() ), array_keys( $this->data_of( $row )['i'] ) );
				$this->assertGreaterThanOrEqual( 9.99, (float) $row['value'] );
			}
		}
		// The board's day reads them: the seven days' carts left behind are the carts that were not ordered.
		wp_cache_delete( 'day_strip', 'shopsocket' );
		$this->assertSame( $made['carts'] - $made['orders'], array_sum( day_strip()['left']['days'] ) );
	}

	public function test_the_day_strip_counts_paid_orders_placed_in_the_last_day_as_sales(): void {
		$product = $this->make_product( 10 );
		$now     = time();
		$before  = sales( $now );
		$this->assertCount( 7, $before['days'] );
		$this->assertSame( $before['value'], $before['days'][6], 'the last day of the series is the figure' );

		// A paid order now, one two days ago, and one not yet paid: other orders on the site are left alone, so deltas.
		$paid = $this->make_order( $product, 2 );
		$paid->update_status( 'processing' );
		$older = $this->make_order( $product );
		$older->set_date_created( $now - 2 * DAY_IN_SECONDS - HOUR_IN_SECONDS );
		$older->set_status( 'completed' );
		$older->save();
		$this->make_order( $product );

		$after = sales( $now + 1 );
		$this->assertSame( $before['count'] + 1, $after['count'], 'the pending order is not a sale' );
		$this->assertEqualsWithDelta( $before['value'] + (float) $paid->get_total(), $after['value'], 0.01 );
		$this->assertEqualsWithDelta( $before['days'][4] + (float) $older->get_total(), $after['days'][4], 0.01, 'two days ago is the fifth day' );
		$this->assertSame( $after['value'], $after['days'][6] );
		$this->assertSame( round( array_sum( $after['days'] ) / 7, 1 ), $after['average'] );
		$this->assertSame( get_woocommerce_currency(), $after['currency'] );
	}

	public function test_woocommerce_pages_are_named_as_journey_steps(): void {
		$this->assertSame(
			array(
				'kind' => 'category',
				'id'   => 26,
			),
			page_step(
				array(
					'kind' => 'product_cat',
					'id'   => 26,
				)
			)
		);
		$this->assertSame(
			array(
				'kind' => 'product',
				'id'   => 9,
			),
			page_step(
				array(
					'kind' => 'product',
					'id'   => 9,
				)
			)
		);
	}
}

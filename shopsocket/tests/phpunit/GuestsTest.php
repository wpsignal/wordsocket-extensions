<?php
/**
 * The best guess at who a guest is: the offer route, what it keeps, the board's lookup and the eraser.
 */

use WPSignal\Extensions\ShopSocket\Privacy;
use function WPSignal\Extensions\ShopSocket\forget_guests;
use function WPSignal\Extensions\ShopSocket\guest_hint;
use function WPSignal\Extensions\ShopSocket\forget;
use const WPSignal\Extensions\ShopSocket\GUEST_RATE;
use const WPSignal\Extensions\ShopSocket\REST_NS;

final class GuestsTest extends ExtensionTestCase {
	private const V       = '0f6a1c3e-1111-4222-8333-444455556666';
	private const V_OTHER = '0f6a1c3e-7777-4888-8999-aaaabbbbcccc';

	protected function setUp(): void {
		parent::setUp();
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}

	protected function tearDown(): void {
		forget_guests( 'ada@example.com' );
		forget( 'guest_rate_' . md5( '127.0.0.1' ) );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function offer( string $v, string $email ): array {
		$request = new WP_REST_Request( 'POST', '/' . REST_NS . '/guest' );
		$request->set_param( 'v', $v );
		$request->set_param( 'email', $email );
		$response = rest_do_request( $request );
		return array( $response->get_status(), $response->get_data() );
	}

	private function lookup( string $visitors ): array {
		$request = new WP_REST_Request( 'GET', '/' . REST_NS . '/guests' );
		$request->set_param( 'v', $visitors );
		$response = rest_do_request( $request );
		return array( $response->get_status(), $response->get_data() );
	}

	public function test_an_email_with_a_paid_order_is_kept_as_a_guess_for_the_visitor(): void {
		$order = $this->make_order( $this->make_product() );
		$order->set_status( 'processing' );
		$order->save();

		[ $status, $data ] = $this->offer( self::V, 'Ada@Example.com' );
		$this->assertSame( 200, $status );
		$this->assertSame( array( 'ok' => true ), $data );
		$hint = guest_hint( self::V );
		$this->assertNotNull( $hint );
		$this->assertSame( 'ada@example.com', $hint['email'], 'lower-cased' );
		$this->assertSame( 'Ada Lovelace', $hint['name'], 'the billing name on the newest order' );
		$this->assertSame( 1, $hint['orders'] );
	}

	public function test_an_email_without_a_paid_order_answers_ok_and_keeps_nothing(): void {
		$order = $this->make_order( $this->make_product() );
		$order->set_status( 'pending' );
		$order->save();

		[ $status, $data ] = $this->offer( self::V, 'ada@example.com' );
		$this->assertSame( 200, $status, 'the answer never says whether the email has ordered here' );
		$this->assertSame( array( 'ok' => true ), $data );
		$this->assertNull( guest_hint( self::V ), 'an unpaid order is no proof' );
		[ $status ] = $this->offer( self::V, 'nobody@example.com' );
		$this->assertSame( 200, $status );
		$this->assertNull( guest_hint( self::V ) );
	}

	public function test_a_bad_visitor_or_email_is_refused_before_anything_runs(): void {
		[ $status ] = $this->offer( 'not-a-uuid', 'ada@example.com' );
		$this->assertSame( 400, $status );
		[ $status ] = $this->offer( self::V, 'not an email' );
		$this->assertSame( 400, $status );
	}

	public function test_a_logged_in_shopper_offers_nothing(): void {
		$order = $this->make_order( $this->make_product() );
		$order->set_status( 'completed' );
		$order->save();
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		wp_set_current_user( (int) $admins[0] );

		[ $status ] = $this->offer( self::V, 'ada@example.com' );
		$this->assertSame( 200, $status );
		$this->assertNull( guest_hint( self::V ), 'a logged-in shopper is named by their account' );
	}

	public function test_the_board_lookup_is_staff_only_and_answers_the_guesses_it_has(): void {
		$order = $this->make_order( $this->make_product() );
		$order->set_status( 'completed' );
		$order->save();
		$this->offer( self::V, 'ada@example.com' );

		wp_set_current_user( 0 );
		[ $status ] = $this->lookup( self::V );
		$this->assertSame( 401, $status );

		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		wp_set_current_user( (int) $admins[0] );
		[ $status, $data ] = $this->lookup( strtoupper( self::V ) . ',' . self::V_OTHER . ',junk' );
		$this->assertSame( 200, $status );
		$this->assertSame(
			array(
				array(
					'v'      => self::V,
					'email'  => 'ada@example.com',
					'name'   => 'Ada Lovelace',
					'orders' => 1,
				),
			),
			$data['guests'],
			'one row per visitor with a guess, ids lower-cased, junk dropped'
		);
	}

	public function test_the_eraser_forgets_every_guess_made_from_an_email(): void {
		$order = $this->make_order( $this->make_product() );
		$order->set_status( 'completed' );
		$order->save();
		$this->offer( self::V, 'ada@example.com' );
		$this->offer( self::V_OTHER, 'ada@example.com' );
		$this->assertNotNull( guest_hint( self::V_OTHER ) );

		$privacy = new Privacy();
		$export  = $privacy->export_guests( 'ada@example.com' );
		$this->assertCount( 2, $export['data'], 'one item per browser' );
		$this->assertSame( 'ada@example.com', $export['data'][0]['data'][1]['value'] );

		$result = $privacy->erase_guests( 'ADA@example.com' );
		$this->assertTrue( $result['items_removed'] );
		$this->assertNull( guest_hint( self::V ) );
		$this->assertNull( guest_hint( self::V_OTHER ) );
		$this->assertFalse( $privacy->erase_guests( 'ada@example.com' )['items_removed'], 'nothing left' );
	}

	public function test_an_address_may_offer_twenty_guesses_a_minute(): void {
		add_filter( 'shopsocket_persistent_cache', '__return_false' );
		$this->assertSame( ! wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled(), \WPSignal\Extensions\ShopSocket\guest_rate_counted(), 'without a cache only post storage counts' );
		remove_filter( 'shopsocket_persistent_cache', '__return_false' );
		add_filter( 'shopsocket_persistent_cache', '__return_true' );
		for ( $i = 0; $i < GUEST_RATE; $i++ ) {
			[ $status ] = $this->offer( self::V, 'nobody@example.com' );
			$this->assertSame( 200, $status );
		}
		[ $status ] = $this->offer( self::V, 'nobody@example.com' );
		$this->assertSame( 429, $status );
		remove_filter( 'shopsocket_persistent_cache', '__return_true' );
	}

	public function test_an_offer_repeated_from_the_same_browser_costs_no_lookup(): void {
		$order = $this->make_order( $this->make_product() );
		$order->set_status( 'completed' );
		$order->save();
		$this->offer( self::V, 'ada@example.com' );
		$order->delete( true );

		[ $status ] = $this->offer( self::V, 'ada@example.com' );
		$this->assertSame( 200, $status );
		$this->assertSame( 1, guest_hint( self::V )['orders'], 'the guess stands without asking the orders again' );
	}
}

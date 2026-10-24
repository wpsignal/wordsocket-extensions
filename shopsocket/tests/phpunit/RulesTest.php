<?php
/**
 * ShopSocket's vocabulary for WordSocket's rules (0.8): the registrations,
 * the page kinds, the shipped rule, the firing on the journey row, and the
 * two routes the Alert action and the replay use.
 */

use WPSignal\WPS;
use function WPSignal\Extensions\ShopSocket\cart_id;
use function WPSignal\Extensions\ShopSocket\journeys_table;
use function WPSignal\Extensions\ShopSocket\journeys_for_replay;
use function WPSignal\Extensions\ShopSocket\on_rule_fired;
use function WPSignal\Extensions\ShopSocket\on_visitor_report;
use const WPSignal\Extensions\ShopSocket\DEFAULT_RULE_ID;
use const WPSignal\Extensions\ShopSocket\ORDERS_CHANNEL;
use const WPSignal\Extensions\ShopSocket\REST_NS;

final class RulesTest extends ExtensionTestCase {

	private const V = '0b2b6a4e-9f0a-4c7a-8f1c-1d2e3f4a5b6c';
	private const J = '7c1d2e3f-4a5b-4c6d-8e7f-0a1b2c3d4e5f';

	/** The site's saved rules, put back after each case: the shipped rule's state is the site's, not the test's. */
	private $saved_rules = false;

	protected function setUp(): void {
		parent::setUp();
		$this->saved_rules = get_option( 'wpsignal_rules', false );
		delete_option( 'wpsignal_rules' );
		WC()->cart->empty_cart();
		delete_transient( 'shopsocket_alert_' . md5( cart_id() ) );
		$this->published = array();
	}

	/** Save one enabled moment with an Alert action, the way the builder would, so the alert route can find its message. */
	private function save_alert_rule( string $id, string $message ): void {
		update_option(
			'wpsignal_rules',
			array(
				'version' => 1,
				'rules'   => array(
					array(
						'id'         => $id,
						'name'       => 'Alert test',
						'enabled'    => true,
						'frequency'  => 'always',
						'rev'        => 1,
						'conditions' => array(
							'all' => array(
								array(
									'condition' => 'cart_has_items',
									'op'        => 'is',
									'value'     => true,
								),
							),
						),
						'actions'    => array(
							array(
								'action'  => 'alert_staff',
								'message' => $message,
							),
						),
					),
				),
				'removed' => array(),
			),
			false
		);
	}

	protected function tearDown(): void {
		if ( false === $this->saved_rules ) {
			delete_option( 'wpsignal_rules' );
		} else {
			update_option( 'wpsignal_rules', $this->saved_rules, false );
		}
		WC()->cart->empty_cart();
		parent::tearDown();
	}

	private function rest_post( string $route, array $params ): array {
		$request = new WP_REST_Request( 'POST', '/' . REST_NS . '/' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_do_request( $request );
		return array( $response->get_status(), $response->get_data() );
	}

	public function test_the_vocabulary_lists_the_cart_conditions_and_the_shop_actions(): void {
		$vocabulary = WPS::rules()->vocabulary();
		$conditions = array_column( $vocabulary['conditions'], null, 'key' );
		foreach ( array( 'cart_has_items', 'cart_count', 'cart_total', 'cart_has_product' ) as $key ) {
			$this->assertArrayHasKey( $key, $conditions, $key );
			$this->assertSame( 'shopsocket', $conditions[ $key ]['source'] );
		}
		$this->assertSame( array( 'at_least', 'at_most' ), array_column( $conditions['cart_total']['operators'], 'key' ) );
		$this->assertSame( 'page_kind', $vocabulary['conditions'][0]['key'], 'WordSocket\'s own come first' );
		$actions = array_column( $vocabulary['actions'], null, 'key' );
		$this->assertArrayHasKey( 'alert_staff', $actions );
		$this->assertTrue( $actions['alert_staff']['live'] );
		$this->assertArrayHasKey( 'cart', array_column( $vocabulary['contexts'], null, 'key' ) );

		$kinds = WPS::rules()->kinds();
		foreach ( array( 'shop', 'category', 'cart', 'checkout', 'thanks' ) as $kind ) {
			$this->assertArrayHasKey( $kind, $kinds, $kind );
		}
	}

	public function test_the_shipped_rule_is_listed_disabled_and_validates(): void {
		$all     = WPS::rules()->all();
		$shipped = array_column( $all, null, 'id' )[ DEFAULT_RULE_ID ] ?? null;
		$this->assertNotNull( $shipped );
		$this->assertFalse( $shipped['enabled'] );
		$this->assertSame( 'shopsocket', $shipped['source'] );
		$this->assertSame( array(), $shipped['unavailable'], 'every key it names is registered' );
		$this->assertSame( 'show', $shipped['actions'][0]['action'] );
		$this->assertTrue( $shipped['attention']['enabled'], 'it follows the shopper to the hidden tab' );
		$this->assertSame( 'banner', $shipped['actions'][0]['presentation']['type'] );
		$clean = WPS::rules()->validate( array( array_merge( $shipped, array( 'enabled' => true ) ) ) );
		$this->assertIsArray( $clean, is_wp_error( $clean ) ? $clean->get_error_message() : '' );
		$this->assertTrue( $clean[0]['enabled'] );
	}

	public function test_a_firing_lands_on_the_journey_row_only_with_history_on(): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( journeys_table(), array( 'journey_id' => self::J, 'visitor' => self::V, 'journey' => 'home:1:1', 'steps' => 1, 'started_at' => $now, 'last_seen' => $now ) );
		$report = array(
			'visitor'    => self::V,
			'journey_id' => self::J,
			'rule_id'    => DEFAULT_RULE_ID,
			'action'     => 'show',
			'step'       => 2,
			'ms'         => 4500,
			'user_id'    => 0,
			'data'       => array(),
		);
		on_rule_fired( $report );
		$this->assertNull( $this->journeys()[0]['firings'], 'history off: nothing is kept' );

		$this->set_history( true );
		on_rule_fired( $report );
		on_rule_fired( array_merge( $report, array( 'action' => 'show', 'step' => 3 ) ) );
		$firings = json_decode( (string) $this->journeys()[0]['firings'], true );
		$this->assertCount( 2, $firings );
		$this->assertSame( DEFAULT_RULE_ID, $firings[0]['rule'] );
		$this->assertSame( 3, $firings[1]['step'] );
		$this->assertSame( 4500, $firings[0]['ms'], 'how far into the step it fired' );
		$this->assertArrayHasKey( 'at', $firings[1] );
	}

	public function test_a_firing_before_any_visit_report_starts_the_journey_row_and_the_next_report_fills_it(): void {
		$this->set_history( true );
		$report = array(
			'visitor'    => self::V,
			'journey_id' => self::J,
			'rule_id'    => DEFAULT_RULE_ID,
			'action'     => 'show',
			'step'       => 2,
			'user_id'    => 0,
			'data'       => array(),
		);

		on_rule_fired( $report );
		$this->assertSame( array(), $this->journeys(), 'an empty cart has no journey to land on' );

		$product = $this->make_product( 10 );
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		on_rule_fired( $report );
		$rows = $this->journeys();
		$this->assertCount( 1, $rows, 'the firing starts the row the visit never wrote' );
		$this->assertSame( '', $rows[0]['journey'] );
		$this->assertSame( 0, (int) $rows[0]['steps'] );
		$this->assertSame( cart_id(), $rows[0]['cart_id'] );
		$this->assertSame( self::V, $rows[0]['visitor'] );
		$this->assertCount( 1, json_decode( (string) $rows[0]['firings'], true ) );
		$started = $rows[0]['started_at'];

		on_visitor_report(
			array(
				'visitor'    => self::V,
				'journey_id' => self::J,
				'journey'    => 'home:0:1000>product:' . $product->get_id() . ':2000>away:0:12000',
				'steps'      => 3,
				'user_id'    => 0,
				'data'       => array(),
				'leaving'    => false,
			)
		);
		$rows = $this->journeys();
		$this->assertCount( 1, $rows );
		$this->assertSame( 3, (int) $rows[0]['steps'], 'the next report fills the journey in' );
		$this->assertStringStartsWith( 'home:0:1000>', $rows[0]['journey'] );
		$this->assertSame( $started, $rows[0]['started_at'], 'and keeps the start the firing set' );
		$this->assertCount( 1, json_decode( (string) $rows[0]['firings'], true ), 'the firing survives the report' );
	}

	public function test_alert_staff_publishes_the_saved_moments_message_from_php_and_is_rate_limited_per_cart(): void {
		$this->save_alert_rule( 'alert-1', 'Stuck on checkout' );
		$product = $this->make_product( 10 );
		WC()->cart->add_to_cart( $product->get_id(), 2 );
		$this->published = array();

		list( $status, $data ) = $this->rest_post( 'alert', array( 'v' => self::V, 'rule' => 'alert-1', 'message' => 'Refund order #1042 now', 'step' => 'checkout' ) );
		$this->assertSame( 200, $status, wp_json_encode( $data ) );
		$alerts = $this->events_on( ORDERS_CHANNEL, 'woo.alert' );
		$this->assertCount( 1, $alerts, 'the channel only PHP writes, so the board can trust it' );
		$this->assertSame( self::V, $alerts[0]['data']['visitor'] );
		$this->assertSame( cart_id(), $alerts[0]['data']['cart_id'] );
		$this->assertSame( 'Stuck on checkout', $alerts[0]['data']['message'], 'the message is the merchant\'s, never the browser\'s' );
		$this->assertSame( 'checkout', $alerts[0]['data']['step'] );
		$this->assertSame( array( $product->get_id() ), $alerts[0]['data']['products'] );
		$this->assertSame( 19.98, $alerts[0]['data']['value'] );
		$this->assertFalse( $alerts[0]['data']['wants_chat'] );

		list( $status ) = $this->rest_post( 'alert', array( 'v' => self::J, 'rule' => 'alert-1' ) );
		$this->assertSame( 429, $status, 'the limit is the cart\'s, so a new visitor id does not reset it' );
		$this->assertCount( 1, $this->events_on( ORDERS_CHANNEL, 'woo.alert' ) );

		delete_transient( 'shopsocket_alert_' . md5( cart_id() ) );
		list( $status ) = $this->rest_post( 'alert', array( 'v' => self::V, 'rule' => 'nope', 'message' => 'Stuck on checkout' ) );
		$this->assertSame( 404, $status, 'a moment that is not saved and enabled alerts nobody' );
		$this->assertCount( 1, $this->events_on( ORDERS_CHANNEL, 'woo.alert' ) );

		WC()->cart->empty_cart();
		list( $status ) = $this->rest_post( 'alert', array( 'v' => self::V, 'rule' => 'alert-1' ) );
		$this->assertSame( 400, $status, 'no cart, nothing to alert about' );
		$this->assertCount( 1, $this->events_on( ORDERS_CHANNEL, 'woo.alert' ) );
	}

	public function test_the_journeys_feed_is_for_staff_and_carries_outcomes_and_cart_context(): void {
		global $wpdb;
		$this->set_history( true );
		$now = current_time( 'mysql', true );
		$wpdb->insert( journeys_table(), array( 'journey_id' => self::J, 'visitor' => self::V, 'cart_id' => 'abcd000000000001', 'journey' => 'home:1:1000>cart:0:500', 'steps' => 2, 'outcome' => 'converted', 'value' => 42.5, 'order_id' => 7, 'started_at' => $now, 'last_seen' => $now ) );
		$wpdb->insert( journeys_table(), array( 'journey_id' => '8d2e3f4a-5b6c-4d7e-9f80-1b2c3d4e5f60', 'visitor' => self::V, 'journey' => 'home:1:1000', 'steps' => 1, 'outcome' => 'left', 'started_at' => $now, 'last_seen' => $now ) );

		wp_set_current_user( 0 );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/' . REST_NS . '/journeys' ) );
		$this->assertSame( 401, $response->get_status() );

		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		wp_set_current_user( (int) $admins[0] );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/' . REST_NS . '/journeys' ) );
		$this->assertSame( 200, $response->get_status() );
		$journeys = $response->get_data()['journeys'];
		$this->assertCount( 2, $journeys );
		$with_cart = array_values( array_filter( $journeys, static fn( array $j ): bool => $j['has_cart'] ) )[0];
		$this->assertSame( 'converted', $with_cart['outcome'] );
		$this->assertSame( 42.5, $with_cart['value'] );
		$this->assertSame( 1, $with_cart['context']['cart']['count'] );
		$this->assertSame( 42.5, $with_cart['context']['cart']['total'] );
		$this->assertMatchesRegularExpression( '/Z$/', $with_cart['started_at'] );

		$this->set_history( false );
		$this->assertSame( array(), journeys_for_replay( 30, 10 ), 'history off: no journeys to replay' );
		wp_set_current_user( 0 );
	}
}

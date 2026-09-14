<?php
/**
 * The dashboard payload: the basket rows the board splits into live and
 * abandoned (using relay presence, client-side), and "online now" from the
 * signed stats request.
 */

use function WPSignal\Extensions\ShopSocket\all_baskets;
use function WPSignal\Extensions\ShopSocket\dashboard_snapshot;
use const WPSignal\Extensions\ShopSocket\REST_NS;

final class DashboardTest extends ExtensionTestCase {

	public function test_all_baskets_returns_rows_of_contents_by_basket_id(): void {
		$this->assertSame( array(), all_baskets() );

		$this->seed_basket( 'aaa', array( 9001, 9002 ), 40.0 );
		$this->seed_basket( 'bbb', array( 9001 ), 15.0 );

		$rows = all_baskets();
		$this->assertCount( 2, $rows );
		$by_id = array_column( $rows, null, 'id' );
		$this->assertSame( array( 9001, 9002 ), $by_id['aaa']['products'] );
		$this->assertSame( 40.0, $by_id['aaa']['value'] );
		$this->assertSame( 15.0, $by_id['bbb']['value'] );
		$this->assertSame( get_woocommerce_currency(), $by_id['aaa']['currency'] );
	}

	public function test_the_dashboard_route_is_staff_only_and_returns_rows_plus_online(): void {
		$this->seed_basket( 'aaa', array( 9001 ), 20.0 );

		$stats = static function ( $pre, $args, $url ) {
			if ( ! str_ends_with( (string) $url, '/site/stats' ) ) {
				return $pre;
			}
			return array(
				'response' => array( 'code' => 200, 'message' => '' ),
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'active_connections' => 12, 'max_connections' => 500 ) ),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $stats, 5, 3 );
		try {
			wp_set_current_user( 0 );
			$response = rest_do_request( new WP_REST_Request( 'GET', '/' . REST_NS . '/dashboard' ) );
			$this->assertSame( 401, $response->get_status(), 'visitors cannot read the figures' );

			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
			wp_set_current_user( (int) $admins[0] );
			$response = rest_do_request( new WP_REST_Request( 'GET', '/' . REST_NS . '/dashboard' ) );
			$this->assertSame( 200, $response->get_status() );
			$data = $response->get_data();
			$this->assertCount( 1, $data['baskets'] );
			$this->assertSame( 'aaa', $data['baskets'][0]['id'] );
			$this->assertSame( 20.0, $data['baskets'][0]['value'] );
			$this->assertSame( array( 'active_connections' => 12, 'max_connections' => 500 ), $data['online'] );
			$this->assertSame( array( 'baskets', 'online' ), array_keys( $data ) );
		} finally {
			remove_filter( 'pre_http_request', $stats, 5 );
			wp_set_current_user( 0 );
		}
	}

	public function test_online_is_null_when_the_server_cannot_answer(): void {
		$down = static fn( $pre, $args, $url ) => str_ends_with( (string) $url, '/site/stats' ) ? new WP_Error( 'http_request_failed', 'down' ) : $pre;
		add_filter( 'pre_http_request', $down, 5, 3 );
		try {
			$this->assertNull( dashboard_snapshot()['online'] );
		} finally {
			remove_filter( 'pre_http_request', $down, 5 );
		}
	}
}

<?php
/**
 * Dashboard: basket rows for the board and "online now" from the stats request.
 */

use function WPSignal\Extensions\ShopSocket\all_baskets;
use function WPSignal\Extensions\ShopSocket\dashboard_snapshot;
use function WPSignal\Extensions\ShopSocket\parse_product_ids;
use function WPSignal\Extensions\ShopSocket\products_for_board;
use const WPSignal\Extensions\ShopSocket\REST_NS;

final class DashboardTest extends ExtensionTestCase {

	public function test_all_baskets_returns_rows_of_contents_by_basket_id(): void {
		$this->assertSame( array(), all_baskets() );

		$this->seed_basket( 'aaa', array( 9001, 9002 ), 40.0, array( 9001 => 3 ) );
		$this->seed_basket( 'bbb', array( 9001 ), 15.0 );

		$rows = all_baskets();
		$this->assertCount( 2, $rows );
		$by_id = array_column( $rows, null, 'id' );
		$this->assertSame( array( 9001, 9002 ), $by_id['aaa']['products'] );
		$this->assertSame( array( 9001 => 3, 9002 => 1 ), $by_id['aaa']['quantities'], 'a product without a stored quantity counts as one unit' );
		$this->assertSame( array( 9001 => 1 ), $by_id['bbb']['quantities'] );
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

	public function test_products_route_is_staff_only_and_names_real_products(): void {
		$product = $this->make_product( 5 );
		$id      = $product->get_id();
		$request = new WP_REST_Request( 'GET', '/' . REST_NS . '/products' );
		$request->set_param( 'ids', (string) $id );

		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( $request )->get_status(), 'visitors cannot name products' );

		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		wp_set_current_user( (int) $admins[0] );
		try {
			$response = rest_do_request( $request );
			$this->assertSame( 200, $response->get_status() );
			$data = $response->get_data();
			$this->assertSame( array( 'products' ), array_keys( $data ) );
			$this->assertSame(
				array(
					array(
						'id'       => $id,
						'name'     => 'PHPUnit Widget',
						'edit_url' => admin_url( 'post.php?post=' . $id . '&action=edit' ),
					),
				),
				$data['products']
			);
		} finally {
			wp_set_current_user( 0 );
		}
	}

	public function test_product_ids_are_cleaned_and_capped_at_one_hundred(): void {
		$this->assertSame( array( 7, 9 ), parse_product_ids( 'abc,-3,0,,7, 9,7' ) );
		$this->assertCount( 100, parse_product_ids( implode( ',', range( 1, 150 ) ) ) );
		$this->assertSame( array(), parse_product_ids( '' ) );
	}

	public function test_products_that_no_longer_exist_are_left_out(): void {
		$product = $this->make_product( 5 );
		$this->assertSame( array( $product->get_id() ), array_column( products_for_board( array( 999999999, $product->get_id() ) ), 'id' ) );
		$this->assertSame( array(), products_for_board( array() ) );
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

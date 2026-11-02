<?php
/**
 * Dashboard: cart rows for the board and "online now" from the stats request.
 */

use function WPSignal\Extensions\ShopSocket\cart_rows;
use function WPSignal\Extensions\ShopSocket\dashboard_snapshot;
use function WPSignal\Extensions\ShopSocket\enqueue_settings_panel;
use function WPSignal\Extensions\ShopSocket\pages_for_board;
use function WPSignal\Extensions\ShopSocket\parse_product_ids;
use function WPSignal\Extensions\ShopSocket\parse_steps;
use function WPSignal\Extensions\ShopSocket\products_for_board;
use const WPSignal\Extensions\ShopSocket\REST_NS;

final class DashboardTest extends ExtensionTestCase {

	public function test_the_active_wordsocket_is_new_enough_and_the_plugins_row_links_to_the_board(): void {
		$this->assertTrue( \WPSignal\Extensions\ShopSocket\wordsocket_ready() );
		if ( ! function_exists( 'WPSignal\\Extensions\\ShopSocket\\plugin_action_links' ) ) {
			require_once dirname( __DIR__, 2 ) . '/inc/admin-board.php';
		}
		$links = \WPSignal\Extensions\ShopSocket\plugin_action_links( array( 'deactivate' => '<a href="#">Deactivate</a>' ) );
		$this->assertCount( 2, $links );
		$this->assertStringContainsString( 'admin.php?page=shopsocket', $links[0], 'the board link comes first' );
		$this->assertStringContainsString( '>View<', $links[0] );
	}

	public function test_the_board_sits_under_analytics_unless_that_feature_is_off(): void {
		if ( ! function_exists( 'WPSignal\\Extensions\\ShopSocket\\board_menu' ) ) {
			require_once dirname( __DIR__, 2 ) . '/inc/admin-board.php';
		}
		$was = get_option( 'woocommerce_analytics_enabled' );
		try {
			update_option( 'woocommerce_analytics_enabled', 'yes' );
			$menu = \WPSignal\Extensions\ShopSocket\board_menu();
			$this->assertSame( 'wc-admin&path=/analytics/overview', $menu['parent'] );
			$this->assertSame( 'Realtime', $menu['label'] );

			update_option( 'woocommerce_analytics_enabled', 'no' );
			$menu = \WPSignal\Extensions\ShopSocket\board_menu();
			$this->assertSame( 'woocommerce', $menu['parent'], 'no Analytics menu to sit under' );
			$this->assertSame( 'ShopSocket', $menu['label'] );
		} finally {
			if ( false === $was ) {
				delete_option( 'woocommerce_analytics_enabled' );
			} else {
				update_option( 'woocommerce_analytics_enabled', $was );
			}
		}
	}

	public function test_the_extensions_tab_card_is_enqueued_after_wordsocket_settings(): void {
		// The admin side loads only under is_admin(); the hook is registered when the file is.
		if ( ! function_exists( 'WPSignal\\Extensions\\ShopSocket\\enqueue_settings_panel' ) ) {
			require_once dirname( __DIR__, 2 ) . '/inc/admin-board.php';
		}
		$this->assertSame( 10, has_action( 'wordsocket_settings_enqueue', 'WPSignal\\Extensions\\ShopSocket\\enqueue_settings_panel' ) );
		try {
			enqueue_settings_panel();
			$this->assertTrue( wp_script_is( 'shopsocket-settings', 'enqueued' ) );
			$script = wp_scripts()->registered['shopsocket-settings'];
			$this->assertContains( 'wpsignal-settings', $script->deps, 'window.wordsocket must exist first' );
			$this->assertContains( 'wp-plugins', $script->deps );

			$before = wp_scripts()->get_inline_script_data( 'shopsocket-settings', 'before' );
			$this->assertStringContainsString( 'window.shopSocketSettings = ', $before );
			$this->assertMatchesRegularExpression( '/window\\.shopSocketSettings = (\\{.*\\});/s', $before );
			preg_match( '/window\\.shopSocketSettings = (\\{.*\\});/s', $before, $m );
			$config = json_decode( $m[1], true );
			$this->assertIsArray( $config );
			$this->assertStringEndsWith( 'admin.php?page=shopsocket', $config['boardUrl'] );
		} finally {
			wp_dequeue_script( 'shopsocket-settings' );
			wp_deregister_script( 'shopsocket-settings' );
			wp_dequeue_style( 'shopsocket-settings' );
		}
	}

	public function test_cart_rows_returns_rows_of_contents_by_cart_id(): void {
		$this->assertSame( array(), cart_rows() );

		$this->seed_cart( 'aaa', array( 9001, 9002 ), 40.0, array( 9001 => 3 ) );
		$this->seed_cart( 'bbb', array( 9001 ), 15.0 );

		$rows = cart_rows();
		$this->assertCount( 2, $rows );
		$by_id = array_column( $rows, null, 'id' );
		$this->assertSame( array( 9001, 9002 ), $by_id['aaa']['products'] );
		$this->assertSame( array( 9001 => 3, 9002 => 1 ), $by_id['aaa']['quantities'], 'a product without a stored quantity counts as one unit' );
		$this->assertSame( array( 9001 => 1 ), $by_id['bbb']['quantities'] );
		$this->assertSame( 40.0, $by_id['aaa']['value'] );
		$this->assertSame( 15.0, $by_id['bbb']['value'] );
		$this->assertSame( get_woocommerce_currency(), $by_id['aaa']['currency'] );
		$this->assertNull( $by_id['aaa']['customer'], 'a guest has no name' );
		$this->assertSame( '', $by_id['aaa']['customer_url'] );
	}

	public function test_cart_rows_name_a_logged_in_shopper(): void {
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		$user   = (int) $admins[0];
		$this->seed_cart( 'ccc', array( 9001 ), 12.0, array(), 'open', 0, $user );
		$this->seed_cart( 'ddd', array( 9002 ), 8.0 );

		$by_id = array_column( cart_rows(), null, 'id' );
		$this->assertSame( $user, $by_id['ccc']['user_id'] );
		$this->assertSame( get_userdata( $user )->display_name, $by_id['ccc']['customer'] );
		$this->assertStringEndsWith( 'user-edit.php?user_id=' . $user, $by_id['ccc']['customer_url'] );
		$this->assertNull( $by_id['ddd']['user_id'] );
	}

	public function test_the_dashboard_route_is_staff_only_and_returns_rows_plus_online(): void {
		$this->seed_cart( 'aaa', array( 9001 ), 20.0 );

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
			$this->assertCount( 1, $data['carts'] );
			$this->assertSame( 'aaa', $data['carts'][0]['id'] );
			$this->assertSame( 20.0, $data['carts'][0]['value'] );
			$this->assertSame( array( 'active_connections' => 12, 'max_connections' => 500 ), $data['online'] );
			$this->assertSame( array( 'carts', 'abandoned', 'strip', 'online', 'as_of' ), array_keys( $data ), 'no orders unless the board names a moment' );
			$this->assertSame( array(), $data['abandoned'], 'nobody has left yet' );
			$this->assertEqualsWithDelta( time(), $data['as_of'], 5, 'the server clock the board sends back as since' );
		} finally {
			remove_filter( 'pre_http_request', $stats, 5 );
			wp_set_current_user( 0 );
		}
	}

	/*
	 * The relay stores nothing, so a board whose connection was down while an
	 * order was placed never hears the event. Its next refresh names the moment
	 * of its last one and gets back what was created or changed since.
	 */
	public function test_the_dashboard_route_hands_back_orders_changed_since_a_moment(): void {
		$product = $this->make_product();

		$earlier = $this->make_order( $product );
		$earlier->set_date_created( time() - 2 * HOUR_IN_SECONDS );
		$earlier->set_date_modified( time() - 2 * HOUR_IN_SECONDS );
		$earlier->save();

		$untouched = $this->make_order( $product );
		$untouched->set_date_created( time() - 3 * HOUR_IN_SECONDS );
		$untouched->set_date_modified( time() - 3 * HOUR_IN_SECONDS );
		$untouched->save();

		$since  = time() - HOUR_IN_SECONDS;
		$missed = $this->make_order( $product, 2 );

		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		wp_set_current_user( (int) $admins[0] );
		try {
			$ids = function ( int $since ): array {
				$request = new WP_REST_Request( 'GET', '/' . REST_NS . '/dashboard' );
				$request->set_query_params( array( 'since' => $since ) );
				$response = rest_do_request( $request );
				$this->assertSame( 200, $response->get_status() );
				return array_column( $response->get_data()['orders'], 'order_id' );
			};

			$found = $ids( $since );
			$this->assertContains( $missed->get_id(), $found, 'an order placed after the moment comes back' );
			$this->assertNotContains( $earlier->get_id(), $found, 'one from before it does not' );
			$this->assertNotContains( $untouched->get_id(), $found );

			// A status change made while the board was away counts too, on an order of any age.
			$earlier->update_status( 'completed' );
			$found = $ids( $since );
			$this->assertContains( $earlier->get_id(), $found, 'an old order changed after the moment comes back' );
			$this->assertNotContains( $untouched->get_id(), $found );

			$request = new WP_REST_Request( 'GET', '/' . REST_NS . '/dashboard' );
			$request->set_query_params( array( 'since' => $since ) );
			$row = current( array_filter( rest_do_request( $request )->get_data()['orders'], static fn( $o ) => $o['order_id'] === $earlier->get_id() ) );
			$this->assertSame( 'completed', $row['status'], 'in the same shape as an order event' );
			$this->assertSame( 'Ada L.', $row['customer'] );
		} finally {
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
						'url'      => get_permalink( $id ),
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

	public function test_pages_route_is_staff_only_and_resolves_public_content(): void {
		$product = $this->make_product( 5 );
		$term    = wp_insert_term( 'PHPUnit Hoodies ' . wp_generate_password( 6, false ), 'product_cat' );
		$this->assertIsArray( $term );
		$term_id = (int) $term['term_id'];
		$about   = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'PHPUnit About',
			)
		);
		$secret  = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'private',
				'post_title'  => 'PHPUnit Private',
			)
		);
		$steps   = implode(
			',',
			array(
				'product:' . $product->get_id(),
				'category:' . $term_id,
				'page:' . $about,
				'page:' . $secret,
				'post_archive:0',
				'shop:0',
				'search:0',
				'nope:5',
				'page:999999999',
			)
		);
		$request = new WP_REST_Request( 'GET', '/' . REST_NS . '/pages' );
		$request->set_param( 'steps', $steps );

		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( $request )->get_status(), 'visitors cannot name pages' );

		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		wp_set_current_user( (int) $admins[0] );
		try {
			$response = rest_do_request( $request );
			$this->assertSame( 200, $response->get_status() );
			$data = $response->get_data();
			$this->assertSame( array( 'pages' ), array_keys( $data ) );
			$pages = array_column( $data['pages'], null, 'step' );
			$this->assertSame(
				array( 'product:' . $product->get_id(), 'category:' . $term_id, 'page:' . $about, 'post_archive:0' ),
				array_keys( $pages ),
				'a private page, the kinds the board labels itself, an unknown kind and a missing post have no row'
			);
			$this->assertSame( 'PHPUnit Widget', $pages[ 'product:' . $product->get_id() ]['title'] );
			$this->assertSame( get_permalink( $product->get_id() ), $pages[ 'product:' . $product->get_id() ]['url'] );
			$this->assertSame( get_term( $term_id )->name, $pages[ 'category:' . $term_id ]['title'] );
			$this->assertSame( get_term_link( $term_id ), $pages[ 'category:' . $term_id ]['url'] );
			$this->assertSame( 'PHPUnit About', $pages[ 'page:' . $about ]['title'] );
			$this->assertSame( get_permalink( $about ), $pages[ 'page:' . $about ]['url'] );
			$this->assertSame( get_post_type_object( 'post' )->labels->name, $pages['post_archive:0']['title'] );
			$this->assertSame( get_post_type_archive_link( 'post' ), $pages['post_archive:0']['url'] );
		} finally {
			wp_set_current_user( 0 );
			wp_delete_term( $term_id, 'product_cat' );
			wp_delete_post( $about, true );
			wp_delete_post( $secret, true );
		}
	}

	public function test_a_shop_manager_can_read_pages(): void {
		$manager = wp_insert_user(
			array(
				'user_login' => 'phpunit-manager-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'shop_manager',
			)
		);
		$this->assertIsInt( $manager );
		$request = new WP_REST_Request( 'GET', '/' . REST_NS . '/pages' );
		$request->set_param( 'steps', 'post_archive:0' );
		wp_set_current_user( $manager );
		try {
			$this->assertSame( 200, rest_do_request( $request )->get_status() );
		} finally {
			wp_set_current_user( 0 );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $manager );
		}
	}

	public function test_steps_are_cleaned_and_capped_at_one_hundred(): void {
		$this->assertSame(
			array( 'product:1', 'page:3', 'tag:7', 'post_archive:0' ),
			parse_steps( 'product:1,Product:2,bad,page:x,page:3, page:3,:4,tag:07,post_archive:0,page:3:10' )
		);
		$this->assertCount( 100, parse_steps( implode( ',', array_map( static fn( int $i ): string => 'page:' . $i, range( 1, 150 ) ) ) ) );
		$this->assertSame( array(), parse_steps( '' ) );
	}

	public function test_a_term_of_the_core_category_taxonomy_resolves_under_the_shared_kind(): void {
		$term = wp_insert_term( 'PHPUnit News ' . wp_generate_password( 6, false ), 'category' );
		$this->assertIsArray( $term );
		$term_id = (int) $term['term_id'];
		try {
			$pages = pages_for_board( array( 'category:' . $term_id ) );
			$this->assertCount( 1, $pages );
			$this->assertSame( get_term( $term_id )->name, $pages[0]['title'] );
		} finally {
			wp_delete_term( $term_id, 'category' );
		}
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

<?php
/**
 * The dashboard's figures and the REST route that serves them.
 *
 * Loaded on every request, since REST calls are not `is_admin()`. The screen
 * itself is in `admin-board.php`.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

use WPSignal\WPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const REST_NS = 'shopsocket/v1';

/** Products the board may name in one request, and the most it lists. */
const BOARD_LIVE_PRODUCTS = 100;

/** Orders the board renders on first paint, and the most one refresh hands back. */
const BOARD_ORDERS = 50;

/**
 * Browsers connected to the site right now, from the WPSignal server through
 * WordSocket (0.22+). Null when WordSocket is older or the server is unreachable.
 *
 * @return array{active_connections: int, max_connections: int}|null
 */
function online_now(): ?array {
	$publisher = WPS::instance()->publisher();
	if ( ! method_exists( $publisher, 'stats' ) ) {
		return null;
	}
	$stats = $publisher->stats();
	return is_wp_error( $stats ) ? null : $stats;
}

/**
 * The rows and the online count; the board splits live from abandoned itself.
 * `as_of` is this server's clock when the figures were read: the board sends
 * it back as `since` on its next refresh.
 *
 * @return array{carts: array<int, array<string, mixed>>, online: array{active_connections: int, max_connections: int}|null, as_of: int}
 */
function dashboard_snapshot(): array {
	return array(
		'carts'  => cart_rows(),
		'online' => online_now(),
		'as_of'  => time(),
	);
}

/**
 * Orders for the board, newest first: the latest BOARD_ORDERS, or with
 * `$since` only those created or changed at or after that moment.
 *
 * @param int $since Unix timestamp, or 0 for the latest orders regardless.
 * @return array<int, array<string, mixed>>
 */
function board_orders( int $since = 0 ): array {
	$args = array(
		'limit'   => BOARD_ORDERS,
		'orderby' => 'date',
		'order'   => 'DESC',
		'type'    => 'shop_order',
	);
	if ( $since > 0 ) {
		$args['date_modified'] = '>=' . $since;
	}
	$orders = wc_get_orders( $args );
	return array_values( array_map( __NAMESPACE__ . '\\order_payload', array_filter( $orders, static fn( $o ) => $o instanceof \WC_Order ) ) );
}

/**
 * The `/dashboard` response: the snapshot, plus the orders changed since
 * `since` when the board names one.
 *
 * @param \WP_REST_Request $request The request.
 * @return \WP_REST_Response|\WP_Error
 */
function dashboard_response( \WP_REST_Request $request ) {
	$data = dashboard_snapshot();
	if ( $request->has_param( 'since' ) ) {
		$data['orders'] = board_orders( max( 1, (int) $request->get_param( 'since' ) ) );
	}
	return rest_ensure_response( $data );
}

/**
 * Product ids from a comma-separated list: positive integers, no repeats,
 * capped at BOARD_LIVE_PRODUCTS.
 *
 * @param string $ids Raw `ids` parameter.
 * @return int[]
 */
function parse_product_ids( string $ids ): array {
	$clean = array();
	foreach ( explode( ',', $ids ) as $id ) {
		$id = (int) trim( $id );
		if ( $id > 0 ) {
			$clean[ $id ] = $id;
		}
	}
	return array_slice( array_values( $clean ), 0, BOARD_LIVE_PRODUCTS );
}

/**
 * Name and edit link for each product that still exists, for the board's
 * live-products list. The board alone knows which carts are live, so it
 * picks the ids and asks here for the words.
 *
 * @param int[] $ids Parent product ids.
 * @return array<int, array{id: int, name: string, edit_url: string}>
 */
function products_for_board( array $ids ): array {
	if ( empty( $ids ) ) {
		return array();
	}
	$rows = array();
	foreach ( wc_get_products(
		array(
			'include' => $ids,
			'limit'   => BOARD_LIVE_PRODUCTS,
		)
	) as $product ) {
		if ( ! $product instanceof \WC_Product ) {
			continue;
		}
		$rows[] = array(
			'id'       => $product->get_id(),
			'name'     => $product->get_name(),
			'edit_url' => (string) get_edit_post_link( $product->get_id(), 'raw' ),
		);
	}
	return $rows;
}

/*
 * `GET /shopsocket/v1/dashboard`: the tiles' figures, polled by the screen;
 * with `?since=<as_of>` also the orders created or changed since then.
 * `GET /shopsocket/v1/products?ids=1,2,3`: names and edit links for the
 * products the board lists.
 */
add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			REST_NS,
			'/dashboard',
			array(
				'methods'             => 'GET',
				'args'                => array(
					'since' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
				'callback'            => __NAMESPACE__ . '\\dashboard_response',
				'permission_callback' => static fn() => current_user_can( STAFF_CAP ),
			)
		);
		register_rest_route(
			REST_NS,
			'/products',
			array(
				'methods'             => 'GET',
				'args'                => array(
					'ids' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response(
					array( 'products' => products_for_board( parse_product_ids( (string) $request->get_param( 'ids' ) ) ) )
				),
				'permission_callback' => static fn() => current_user_can( STAFF_CAP ),
			)
		);
	}
);

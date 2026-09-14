<?php
/**
 * The dashboard's figures and the REST route that serves them. Loaded on
 * every request (REST calls are not `is_admin()`); the screen itself is in
 * `admin-board.php`.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

use WPSignal\WPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const REST_NS = 'shopsocket/v1';

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
 * The figures on the dashboard tiles.
 *
 * The board splits the basket rows into live and abandoned itself, using the
 * relay's presence membership; here we only supply the rows and the online count.
 *
 * @return array{baskets: array<int, array<string, mixed>>, online: array{active_connections: int, max_connections: int}|null}
 */
function dashboard_snapshot(): array {
	return array(
		'baskets' => all_baskets(),
		'online'  => online_now(),
	);
}

// `GET /shopsocket/v1/dashboard`: the tiles' figures, polled by the screen.
add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			REST_NS,
			'/dashboard',
			array(
				'methods'             => 'GET',
				'callback'            => static fn() => rest_ensure_response( dashboard_snapshot() ),
				'permission_callback' => static fn() => current_user_can( STAFF_CAP ),
			)
		);
	}
);

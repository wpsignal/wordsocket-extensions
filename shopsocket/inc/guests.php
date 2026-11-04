<?php
/**
 * A best guess at who a guest is: the email their browser's checkout form remembers, kept only
 * when it matches a paid order on this store, and shown to staff flagged as a guess.
 *
 * - `POST /shopsocket/v1/guest { v, email }` (public) is the storefront's offer; it answers `ok`
 *   whatever it found, so nobody learns whether an email has ordered here.
 * - `GET /shopsocket/v1/guests?v=a,b` (staff) answers the guesses the board may show.
 * - A guess lives GUEST_TTL in the plugin's memory (`memory.php`) under the visitor id, with an
 *   index under the email for the eraser.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How long a guess is kept: a browser offers again on every new tab, so as long as a tab may stay open.
 *
 * @since 0.8.0
 */
const GUEST_TTL = 2 * DAY_IN_SECONDS;

/**
 * Offers one address may make a minute.
 *
 * @since 0.8.0
 */
const GUEST_RATE = 20;

/**
 * Visitor ids one email's index holds at most.
 *
 * @since 0.8.0
 */
const GUEST_INDEX_MAX = 20;

/**
 * The memory key a visitor's guess lives under.
 *
 * @param string $v The visitor id.
 * @return string
 */
function guest_key( string $v ): string {
	return 'guest_' . md5( strtolower( $v ) );
}

/**
 * The memory key an email's visitor ids live under.
 *
 * @param string $email The email.
 * @return string
 */
function guest_index_key( string $email ): string {
	return 'guests_' . md5( strtolower( $email ) );
}

/**
 * Whether offers are counted: always with a persistent cache, and without one only where the
 * orders lookup is the slow kind (post storage), since the counter itself writes two rows a minute.
 *
 * @since 0.8.0
 *
 * @return bool
 */
function guest_rate_counted(): bool {
	if ( has_persistent_cache() ) {
		return true;
	}
	return ! ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
}

/**
 * Whether this address may offer another guess: GUEST_RATE a minute while offers are counted.
 *
 * @since 0.8.0
 *
 * @param string $address The caller's IP address.
 * @return bool
 */
function guest_rate_ok( string $address ): bool {
	if ( ! guest_rate_counted() ) {
		return true;
	}
	$key   = 'guest_rate_' . md5( $address );
	$count = (int) recall( $key );
	if ( $count >= GUEST_RATE ) {
		return false;
	}
	remember( $key, $count + 1, MINUTE_IN_SECONDS );
	return true;
}

/**
 * The paid orders placed with an email: how many and the newest, null when none.
 *
 * @since 0.8.0
 *
 * @param string $email The billing email.
 * @return array{orders: int, name: string}|null
 */
function orders_by_email( string $email ): ?array {
	$found = wc_get_orders(
		array(
			'type'          => 'shop_order',
			'billing_email' => $email,
			'status'        => wc_get_is_paid_statuses(),
			'limit'         => 1,
			'orderby'       => 'date',
			'order'         => 'DESC',
			'paginate'      => true,
		)
	);
	if ( ! is_object( $found ) || 0 === (int) $found->total || array() === $found->orders ) {
		return null;
	}
	$newest = $found->orders[0];
	return array(
		'orders' => (int) $found->total,
		'name'   => $newest instanceof \WC_Order ? trim( $newest->get_formatted_billing_full_name() ) : '',
	);
}

/**
 * Keep the guess for a visitor when the email has paid orders here; false when it has none.
 *
 * @since 0.8.0
 *
 * @param string $v     The visitor id.
 * @param string $email The email the browser remembers.
 * @return bool
 */
function remember_guest( string $v, string $email ): bool {
	$email = strtolower( sanitize_email( $email ) );
	$v     = strtolower( $v );
	if ( '' === $email ) {
		return false;
	}
	$index = recall( guest_index_key( $email ) );
	$index = is_array( $index ) ? $index : array();
	/* The same browser offering the same email again costs no lookup. */
	if ( in_array( $v, $index, true ) && null !== guest_hint( $v ) ) {
		return true;
	}
	$orders = orders_by_email( $email );
	if ( null === $orders ) {
		return false;
	}
	remember(
		guest_key( $v ),
		array(
			'email'  => $email,
			'name'   => $orders['name'],
			'orders' => $orders['orders'],
			'at'     => time(),
		),
		GUEST_TTL
	);
	$index = array_slice( array_unique( array_merge( array( $v ), $index ) ), 0, GUEST_INDEX_MAX );
	remember( guest_index_key( $email ), $index, GUEST_TTL );
	return true;
}

/**
 * The guess kept for a visitor, null when none.
 *
 * @since 0.8.0
 *
 * @param string $v The visitor id.
 * @return array{email: string, name: string, orders: int, at: int}|null
 */
function guest_hint( string $v ): ?array {
	$hint = recall( guest_key( $v ) );
	return is_array( $hint ) && isset( $hint['email'] ) ? $hint : null;
}

/**
 * The guesses for the board, one row per visitor that has one.
 *
 * @since 0.8.0
 *
 * @param string[] $visitors Visitor ids.
 * @return array<int, array{v: string, email: string, name: string, orders: int}>
 */
function guests_for_board( array $visitors ): array {
	$rows = array();
	foreach ( $visitors as $v ) {
		$hint = guest_hint( $v );
		if ( null !== $hint ) {
			$rows[] = array(
				'v'      => strtolower( $v ),
				'email'  => (string) $hint['email'],
				'name'   => (string) $hint['name'],
				'orders' => (int) $hint['orders'],
			);
		}
	}
	return $rows;
}

/**
 * Forget every guess made from an email; how many went.
 *
 * @since 0.8.0
 *
 * @param string $email The email.
 * @return int
 */
function forget_guests( string $email ): int {
	$email = strtolower( sanitize_email( $email ) );
	if ( '' === $email ) {
		return 0;
	}
	$index = recall( guest_index_key( $email ) );
	$gone  = 0;
	foreach ( is_array( $index ) ? $index : array() as $v ) {
		if ( forget( guest_key( (string) $v ) ) ) {
			++$gone;
		}
	}
	forget( guest_index_key( $email ) );
	return $gone;
}

/**
 * The visitor ids a route lists, lower-cased, deduped, UUIDs only, 100 at most.
 *
 * @since 0.8.0
 *
 * @param string $given Comma-separated ids.
 * @return string[]
 */
function parse_visitors( string $given ): array {
	$ids = array();
	foreach ( explode( ',', $given ) as $raw ) {
		$v = strtolower( trim( $raw ) );
		if ( 1 === preg_match( \WPSignal\Presence::UUID_PATTERN, $v ) ) {
			$ids[ $v ] = true;
		}
	}
	return array_slice( array_keys( $ids ), 0, BOARD_LIVE_PRODUCTS );
}

/**
 * `POST /shopsocket/v1/guest`: take the storefront's offer; `ok` either way.
 *
 * @since 0.8.0
 *
 * @param \WP_REST_Request $request The request.
 * @return \WP_REST_Response|\WP_Error
 */
function handle_guest( \WP_REST_Request $request ) {
	$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( ! guest_rate_ok( $address ) ) {
		return new \WP_Error( 'shopsocket_too_many', __( 'Try again in a minute.', 'shopsocket' ), array( 'status' => 429 ) );
	}
	if ( ! is_user_logged_in() ) {
		remember_guest( (string) $request->get_param( 'v' ), (string) $request->get_param( 'email' ) );
	}
	$response = rest_ensure_response( array( 'ok' => true ) );
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			REST_NS,
			'/guest',
			array(
				'methods'             => 'POST',
				'args'                => array(
					'v'     => array(
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => static fn( $value ): bool => is_string( $value ) && 1 === preg_match( \WPSignal\Presence::UUID_PATTERN, $value ),
					),
					'email' => array(
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => static fn( $value ): bool => is_string( $value ) && strlen( $value ) <= 254 && false !== is_email( $value ),
					),
				),
				'callback'            => __NAMESPACE__ . '\handle_guest',
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			REST_NS,
			'/guests',
			array(
				'methods'             => 'GET',
				'args'                => array(
					'v' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response(
					array( 'guests' => guests_for_board( parse_visitors( (string) $request->get_param( 'v' ) ) ) )
				),
				'permission_callback' => static fn() => current_user_can( STAFF_CAP ),
			)
		);
	}
);

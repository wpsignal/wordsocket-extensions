<?php
/**
 * Cart history on WordSocket's journeys: what the shop adds to a journey row, and what it reads back.
 *
 * - ShopSocket keeps no table of its own. A journey carries the cart id as its `ref`, the cart's
 *   value, and in `data` the cart's items and currency (`{ c, i: { product_id: quantity }, o }`,
 *   `o` the order it became), written through `wpsignal_journey_record` on every visit and leave
 *   report, and kept by WordSocket while its History is on.
 * - A cart is the journeys sharing a ref: open ones are live carts, the newest left one is a cart
 *   left behind, a converted one is an order.
 * - Every write happens in the shopper's request: the reports and the checkout hooks. No job of
 *   ShopSocket's own; WordSocket prunes.
 * - Abandonment is judged on the board: a left cart whose shopper is not present and whose leave
 *   is older than the board's grace.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

use WPSignal\WPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- WordSocket's journeys table: the name from its class, fragments from prepare(), one placeholder per id.

const DB_VERSION        = '5';
const DB_VERSION_OPTION = 'shopsocket_db_version';

/** Rows the board lists under "Recently abandoned". */
const BOARD_ABANDONED = 50;

/** Non-open journeys read to find the latest abandoned carts, newest first. */
const ABANDONED_SCAN = 200;

/** Non-open journeys the strip's cart measures read, newest first. */
const STRIP_CARTS = 5000;

const CACHE_GROUP = 'shopsocket';

/**
 * WordSocket's journeys table.
 *
 * @since 0.8.0
 *
 * @return string
 */
function journeys_table(): string {
	return \WPSignal\Journeys::table();
}

/**
 * Whether the active WordSocket keeps journeys at all (0.31 and later).
 *
 * @since 0.8.0
 *
 * @return bool
 */
function journeys_available(): bool {
	return method_exists( WPS::class, 'journeys' );
}

/**
 * Drop the tables earlier versions kept, once.
 *
 * @since 0.8.0 Drops the carts and items tables too: every cart fact lives on WordSocket's journeys.
 *
 * @return void
 */
function install(): void {
	if ( get_option( DB_VERSION_OPTION ) === DB_VERSION ) {
		return;
	}
	global $wpdb;
	foreach ( array( 'shopsocket_journeys', 'shopsocket_cart_items', 'shopsocket_carts' ) as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
	}
	update_option( DB_VERSION_OPTION, DB_VERSION, false );
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\install' );

/**
 * Whether carts are kept: WordSocket's History switch.
 *
 * @since 0.8.0 Reads WordSocket's switch; ShopSocket has none of its own.
 *
 * @return bool
 */
function history_enabled(): bool {
	return journeys_available() && WPS::journeys()->enabled();
}

/**
 * The current UTC time as MySQL spells it.
 *
 * @return string
 */
function now_sql(): string {
	return current_time( 'mysql', true );
}

/**
 * The cache generation: part of every count key and replaced on every write,
 * so a stale count is never served after a change and no per-key deletion is needed.
 *
 * @return string
 */
function cache_generation(): string {
	$generation = wp_cache_get( 'carts_generation', CACHE_GROUP );
	if ( ! is_string( $generation ) || '' === $generation ) {
		$generation = uniqid( '', true );
		wp_cache_set( 'carts_generation', $generation, CACHE_GROUP );
	}
	return $generation;
}

/**
 * Forget every cached count: called after any write that changes a cart.
 *
 * @return void
 */
function invalidate_cart_caches(): void {
	wp_cache_set( 'carts_generation', uniqid( '', true ), CACHE_GROUP );
}

/**
 * A journey's cart as its `data` holds it: `product_id => quantity`, empty for no items.
 *
 * @since 0.8.0
 *
 * @param string|null $json The row's `data`.
 * @return array<int, int>
 */
function items_of( ?string $json ): array {
	$data  = json_decode( (string) $json, true );
	$items = is_array( $data ) && is_array( $data['i'] ?? null ) ? $data['i'] : array();
	$out   = array();
	foreach ( $items as $product_id => $quantity ) {
		if ( (int) $product_id > 0 && (int) $quantity > 0 ) {
			$out[ (int) $product_id ] = (int) $quantity;
		}
	}
	return $out;
}

/**
 * One field of a journey's `data`, or null.
 *
 * @since 0.8.0
 *
 * @param string|null $json The row's `data`.
 * @param string      $key  `c` or `o`.
 * @return mixed
 */
function data_field( ?string $json, string $key ) {
	$data = json_decode( (string) $json, true );
	return is_array( $data ) ? ( $data[ $key ] ?? null ) : null;
}

/**
 * The non-open journeys carrying a cart since a moment, newest first, one per cart (its newest):
 * `{ ref, outcome, value, ended_at, items, currency, user_id, journey, order }`.
 *
 * @since 0.8.0
 *
 * @param string $since SQL UTC.
 * @return array<int, array<string, mixed>>
 */
function cart_journeys_since( string $since ): array {
	global $wpdb;
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			'SELECT ref, outcome, value, ended_at, data, user_id, journey FROM ' . journeys_table() . " WHERE ref IS NOT NULL AND data IS NOT NULL AND outcome <> 'open' AND ended_at >= %s ORDER BY ended_at DESC LIMIT %d",
			$since,
			STRIP_CARTS
		),
		ARRAY_A
	);
	$out  = array();
	foreach ( $rows as $row ) {
		$ref   = (string) $row['ref'];
		$items = items_of( $row['data'] );
		// A leave with nothing in the cart is not the cart's story.
		if ( isset( $out[ $ref ] ) || ( 'left' === $row['outcome'] && empty( $items ) ) ) {
			continue;
		}
		$out[ $ref ] = array(
			'ref'      => $ref,
			'outcome'  => (string) $row['outcome'],
			'value'    => round( (float) ( $row['value'] ?? 0 ), 2 ),
			'ended_at' => (string) $row['ended_at'],
			'items'    => $items,
			'currency' => (string) ( data_field( $row['data'], 'c' ) ?? '' ),
			'user_id'  => (int) ( $row['user_id'] ?? 0 ),
			'journey'  => (string) ( $row['journey'] ?? '' ),
			'order'    => (int) ( data_field( $row['data'], 'o' ) ?? 0 ),
		);
	}
	return array_values( $out );
}

/**
 * The shopper checked out: end the cart's open journey as converted with the order on it, credit the moments that fired for the cart, and tell the board.
 *
 * @since 0.8.0 Everything goes through WordSocket; there is no cart row to stamp.
 *
 * @param string $id       Cart id.
 * @param int    $order_id The order.
 * @param float  $total    The order total, kept on the journey.
 * @return void
 */
function mark_converted( string $id, int $order_id, float $total ): void {
	if ( '' === $id || $order_id <= 0 || ! journeys_available() ) {
		return;
	}
	WPS::journeys()->end_by_ref( $id, 'converted', $total, array( 'o' => $order_id ) );
	/**
	 * Filters how long after a moment fired an order still counts as its doing, in seconds.
	 *
	 * @since 0.8.0
	 *
	 * @param int $window Default seven days.
	 */
	WPS::journeys()->credit( $id, $order_id, $total, max( 0, (int) apply_filters( 'shopsocket_attribution_window', 7 * DAY_IN_SECONDS ) ) );
	invalidate_cart_caches();
	publish_carts_changed();
}

/**
 * Conversion, in the shopper's own checkout request before the cart is emptied: the classic checkout and the Store API each fire this.
 *
 * @param mixed $order The order WooCommerce passes.
 * @return void
 */
function on_order_created( $order ): void {
	if ( ! $order instanceof \WC_Order ) {
		return;
	}
	mark_converted( cart_id(), $order->get_id(), (float) $order->get_total() );
}
add_action( 'woocommerce_checkout_order_created', __NAMESPACE__ . '\on_order_created' );
add_action( 'woocommerce_store_api_checkout_order_processed', __NAMESPACE__ . '\on_order_created' );

/**
 * The shopper's cart behind a bare REST report (a visit, a leave, a firing), from the session cookie or the login: `[ cart_id, state ]`, `state` null for an empty cart, or null when there is no cart at all.
 *
 * @since 0.8.0 Answers an empty cart as `[ id, null ]`, so a report can clear the row's items.
 *
 * @return array{0: string, 1: array|null}|null
 */
function report_cart(): ?array {
	if ( ( has_wc_session_cookie() || is_user_logged_in() ) && function_exists( 'wc_load_cart' ) && ( ! function_exists( 'WC' ) || ! WC()->cart ) ) {
		wc_load_cart();
	}
	$cart_id = cart_id();
	if ( '' === $cart_id ) {
		return null;
	}
	return array( $cart_id, current_cart_state() );
}

/**
 * What a journey row keeps of the shop: the cart id as its ref, the cart's value, and its items and currency as data.
 *
 * @since 0.8.0
 *
 * @param array $record `{ ref, value, data }` as WordSocket starts it.
 * @param array $report The visit or leave report.
 * @return array
 */
function journey_record( array $record, array $report ): array {
	unset( $report );
	$cart = report_cart();
	if ( null === $cart ) {
		return $record;
	}
	list( $cart_id, $state ) = $cart;
	$items                   = array();
	foreach ( (array) ( $state['quantities'] ?? array() ) as $product_id => $quantity ) {
		$items[ (string) $product_id ] = (int) $quantity;
	}
	$record['ref']   = $cart_id;
	$record['value'] = (float) ( $state['value'] ?? 0 );
	$record['data']  = array(
		'c' => get_woocommerce_currency(),
		'i' => $items,
	);
	return $record;
}
add_filter( 'wpsignal_journey_record', __NAMESPACE__ . '\journey_record', 10, 2 );

/**
 * After WordSocket wrote a report's row for a shopper with a cart: forget the cached counts, tell the board when its figures changed, and on a cart's first leave fire `shopsocket_cart_left`.
 *
 * @since 0.8.0 Writes nothing itself: the row is WordSocket's, written before this runs.
 *
 * @param array $report `{ visitor, journey_id, journey, steps, user_id, data, leaving }` from WordSocket.
 * @return void
 */
function on_visitor_report( array $report ): void {
	if ( ! history_enabled() ) {
		return;
	}
	$cart = report_cart();
	if ( null === $cart ) {
		return;
	}
	list( $cart_id, $state ) = $cart;
	invalidate_cart_caches();
	publish_carts_changed();
	if ( ! empty( $report['leaving'] ) && null !== $state ) {
		cart_left( $cart_id, (string) $report['journey_id'], $state );
	}
}
add_action( 'wpsignal_visitor_visit', __NAMESPACE__ . '\on_visitor_report' );
add_action( 'wpsignal_visitor_leave', __NAMESPACE__ . '\on_visitor_report' );

/**
 * Fire `shopsocket_cart_left` the first time a cart's shopper leaves, with the journey row as the cart's record.
 *
 * @since 0.8.0
 *
 * @param string $cart_id    The cart.
 * @param string $journey_id The journey the leave was reported on.
 * @param array  $state      The cart's `current_cart_state()`.
 * @return void
 */
function cart_left( string $cart_id, string $journey_id, array $state ): void {
	global $wpdb;
	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT user_id, visitor, value, started_at, last_seen, ended_at, journey FROM ' . journeys_table() . " WHERE journey_id = %s AND ref = %s AND outcome = 'left'",
			$journey_id,
			$cart_id
		),
		ARRAY_A
	);
	if ( ! is_array( $row ) ) {
		return;
	}
	$earlier = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM ' . journeys_table() . " WHERE ref = %s AND outcome <> 'open' AND journey_id <> %s",
			$cart_id,
			$journey_id
		)
	);
	if ( $earlier > 0 ) {
		return;
	}
	$record = array(
		'user_id'    => null === $row['user_id'] ? null : (int) $row['user_id'],
		'visitor'    => (string) $row['visitor'],
		'currency'   => get_woocommerce_currency(),
		'value'      => round( (float) ( $state['value'] ?? $row['value'] ), 2 ),
		'first_seen' => (string) $row['started_at'],
		'last_seen'  => (string) $row['last_seen'],
		'left_at'    => (string) $row['ended_at'],
		'journey'    => (string) $row['journey'],
	);
	/**
	 * A cart's shopper left for the first time.
	 *
	 * @since 0.8.0
	 *
	 * @param string              $id    The cart id.
	 * @param array<string,mixed> $row   The cart's record: `user_id` (null for a guest), `visitor`, `currency`, `value`, `first_seen`, `last_seen`, `left_at`, `journey`.
	 * @param array<int,int>      $items The cart's items as `product_id => quantity`.
	 */
	do_action( 'shopsocket_cart_left', $cart_id, $record, array_map( 'intval', (array) ( $state['quantities'] ?? array() ) ) );
}

/**
 * What the cart conditions can know from a stored journey, for the replay: how many units were in the cart and what they came to.
 *
 * @since 0.8.0
 *
 * @param array $context The context so far.
 * @param array $row     WordSocket's row: `journey`, `started_at`, `outcome`, `value`, `ref`, `firings`, `data`.
 * @return array
 */
function journey_context( array $context, array $row ): array {
	$items           = items_of( $row['data'] ?? null );
	$context['cart'] = array(
		'count'    => $items ? array_sum( $items ) : ( '' !== (string) ( $row['ref'] ?? '' ) ? 1 : 0 ),
		'total'    => round( (float) ( $row['value'] ?? 0 ), 2 ),
		'products' => array(),
		'product'  => '',
	);
	return $context;
}
add_filter( 'wpsignal_journey_context', __NAMESPACE__ . '\journey_context', 10, 2 );
add_filter( 'wpsignal_journeys_currency', 'get_woocommerce_currency' );

/**
 * Name the shop's outcome and ref for the Journeys tab: an order placed, and the cart a journey carries.
 *
 * @since 0.8.0
 *
 * @return void
 */
function register_journey_vocabulary(): void {
	if ( ! journeys_available() ) {
		return;
	}
	WPS::journeys()->outcome(
		'converted',
		array(
			'label'  => __( 'Order placed', 'shopsocket' ),
			'color'  => '#008300',
			'series' => __( 'Orders a day', 'shopsocket' ),
		)
	);
	WPS::journeys()->ref(
		array(
			'label'       => __( 'Cart', 'shopsocket' ),
			'left_series' => __( 'Carts left behind a day', 'shopsocket' ),
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\register_journey_vocabulary', 6 );

/**
 * WooCommerce's pages as journey steps: WordSocket names a page by post type
 * and taxonomy; the shop's own pages get the names the board and rules use.
 *
 * @param array{kind: string, id: int} $step The detected step.
 * @return array{kind: string, id: int}
 */
function page_step( array $step ): array {
	if ( ! function_exists( 'is_shop' ) ) {
		return $step;
	}
	if ( is_order_received_page() ) {
		return array(
			'kind' => 'thanks',
			'id'   => 0,
		);
	}
	if ( is_checkout() ) {
		return array(
			'kind' => 'checkout',
			'id'   => 0,
		);
	}
	if ( is_cart() ) {
		return array(
			'kind' => 'cart',
			'id'   => 0,
		);
	}
	if ( is_shop() ) {
		return array(
			'kind' => 'shop',
			'id'   => 0,
		);
	}
	if ( 'product_cat' === $step['kind'] ) {
		$step['kind'] = 'category';
	}
	return $step;
}
add_filter( 'wpsignal_page_step', __NAMESPACE__ . '\page_step' );

/**
 * The carts whose shopper left, newest leave first, for the board's "Recently abandoned" panel: one row per cart, from its newest left journey, with the order a later journey became.
 *
 * Whether the shopper is back right now, and whether the leave is older than the grace, is the board's to judge: it has presence and its own clock.
 *
 * @since 0.8.0 Read from WordSocket's journeys.
 *
 * @return array<int, array<string, mixed>>
 */
function abandoned_rows(): array {
	global $wpdb;
	$rows  = (array) $wpdb->get_results(
		$wpdb->prepare(
			'SELECT ref, user_id, value, journey, outcome, ended_at, data FROM ' . journeys_table() . " WHERE ref IS NOT NULL AND data IS NOT NULL AND outcome <> 'open' ORDER BY ended_at DESC LIMIT %d",
			ABANDONED_SCAN
		),
		ARRAY_A
	);
	$carts = array();
	foreach ( $rows as $row ) {
		$ref  = (string) $row['ref'];
		$cart = $carts[ $ref ] ?? array(
			'left'      => null,
			'converted' => null,
			'user_id'   => 0,
		);
		if ( 0 === $cart['user_id'] && (int) ( $row['user_id'] ?? 0 ) > 0 ) {
			$cart['user_id'] = (int) $row['user_id'];
		}
		// A leave with nothing in the cart (the thank-you page's tab closing) is not a cart left behind.
		if ( 'left' === $row['outcome'] && empty( items_of( $row['data'] ) ) ) {
			$carts[ $ref ] = $cart;
			continue;
		}
		if ( 'left' === $row['outcome'] && null === $cart['left'] ) {
			$cart['left'] = $row;
			// An order placed before this leave is an earlier cart's.
			if ( null !== $cart['converted'] && $cart['converted']['ended_at'] < $row['ended_at'] ) {
				$cart['converted'] = null;
			}
		} elseif ( 'converted' === $row['outcome'] && null === $cart['converted'] && ( null === $cart['left'] || $row['ended_at'] >= $cart['left']['ended_at'] ) ) {
			$cart['converted'] = $row;
		}
		$carts[ $ref ] = $cart;
	}
	$names = customer_names( array_column( $carts, 'user_id' ) );
	$out   = array();
	foreach ( $carts as $ref => $cart ) {
		$left = $cart['left'];
		if ( null === $left ) {
			continue;
		}
		$items = items_of( $left['data'] );
		if ( empty( $items ) ) {
			continue;
		}
		$converted = $cart['converted'];
		$order_id  = null === $converted ? 0 : (int) ( data_field( $converted['data'], 'o' ) ?? 0 );
		$user_id   = (int) $cart['user_id'];
		$out[]     = array(
			'id'           => $ref,
			'products'     => array_keys( $items ),
			'quantities'   => (object) $items,
			'value'        => round( (float) ( $left['value'] ?? 0 ), 2 ),
			'currency'     => (string) ( data_field( $left['data'], 'c' ) ?? get_woocommerce_currency() ),
			'journey'      => (string) ( $left['journey'] ?? '' ),
			'left_at'      => datetime_to_rfc3339( (string) $left['ended_at'] ),
			'converted_at' => null === $converted ? null : datetime_to_rfc3339( (string) $converted['ended_at'] ),
			'order_id'     => $order_id > 0 ? $order_id : null,
			'order_url'    => $order_id > 0 ? order_edit_url( $order_id ) : '',
			'user_id'      => $user_id > 0 ? $user_id : null,
			'customer'     => $names[ $user_id ] ?? null,
			'customer_url' => $user_id > 0 ? customer_edit_url( $user_id ) : '',
		);
		if ( count( $out ) >= BOARD_ABANDONED ) {
			break;
		}
	}
	return $out;
}

/**
 * Display names for a set of users, as `id => name`; unknown ids are left out.
 *
 * @since 0.8.0
 *
 * @param int[] $ids User ids, zeros and repeats allowed.
 * @return array<int, string>
 */
function customer_names( array $ids ): array {
	$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
	if ( array() === $ids ) {
		return array();
	}
	$names = array();
	$users = get_users(
		array(
			'include' => $ids,
			'fields'  => array( 'ID', 'display_name' ),
		)
	);
	foreach ( $users as $user ) {
		$names[ (int) $user->ID ] = (string) $user->display_name;
	}
	return $names;
}

/**
 * The profile screen for a customer.
 *
 * @since 0.8.0
 *
 * @param int $user_id The user.
 * @return string
 */
function customer_edit_url( int $user_id ): string {
	return admin_url( 'user-edit.php?user_id=' . $user_id );
}

/**
 * The edit screen for an order, without loading it: this runs for up to 50
 * rows on every cart change, and the board only needs a link.
 *
 * @param int $order_id The order.
 * @return string
 */
function order_edit_url( int $order_id ): string {
	$util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
	if ( class_exists( $util ) && method_exists( $util, 'get_order_admin_edit_url' ) ) {
		return (string) $util::get_order_admin_edit_url( $order_id );
	}
	return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
}

/**
 * A MySQL UTC datetime as RFC 3339 with the Z suffix, for the board's clock.
 *
 * @param string $datetime `Y-m-d H:i:s` in UTC.
 * @return string
 */
function datetime_to_rfc3339( string $datetime ): string {
	return str_replace( ' ', 'T', $datetime ) . 'Z';
}

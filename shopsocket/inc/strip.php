<?php
/**
 * The board's "This week" strip, read from the history tables.
 *
 * - Carts left behind, recovered orders and checkout conversion over the last seven days, each against the seven before.
 * - The page kind most leavers left from, and their median time there.
 * - The products most often left in a cart, with how many carts bought them.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- The plugin's own tables: names come from the table functions, fragments from prepare(), one placeholder per id.

/**
 * The strip's window, in days.
 *
 * @since 0.8.0
 */
const STRIP_DAYS = 7;

/**
 * Products the strip lists.
 *
 * @since 0.8.0
 */
const STRIP_PRODUCTS = 5;

/**
 * Left journeys the leak sentence reads, newest first.
 *
 * @since 0.8.0
 */
const STRIP_LEAVERS = 2000;

/**
 * How long a computed strip is served from the object cache, in seconds.
 *
 * @since 0.8.0
 */
const STRIP_CACHE_TTL = 60;

/**
 * This week's figures for the board, null with history off.
 *
 * @since 0.8.0
 *
 * @return array<string, mixed>|null
 */
function week_strip(): ?array {
	if ( ! history_enabled() ) {
		return null;
	}
	$cached = wp_cache_get( 'week_strip', 'shopsocket' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$now   = time();
	$since = gmdate( 'Y-m-d H:i:s', $now - STRIP_DAYS * DAY_IN_SECONDS );
	$prev  = gmdate( 'Y-m-d H:i:s', $now - 2 * STRIP_DAYS * DAY_IN_SECONDS );
	$strip = array(
		'since'      => datetime_to_rfc3339( $since ),
		'left'       => carts_left( $since, $prev ),
		'recovered'  => recovered_orders( $since, $prev ),
		'conversion' => checkout_conversion( $since, $prev ),
		'leak'       => leak( $since ),
		'products'   => products_left( $since ),
	);
	wp_cache_set( 'week_strip', $strip, 'shopsocket', STRIP_CACHE_TTL );
	return $strip;
}

/**
 * Carts whose shopper left and that never became an order: this week's count and value, and last week's count.
 *
 * @since 0.8.0
 *
 * @param string $since Start of this week, SQL UTC.
 * @param string $prev  Start of last week, SQL UTC.
 * @return array{count: int, value: float, currency: string, previous: int}
 */
function carts_left( string $since, string $prev ): array {
	global $wpdb;
	$row    = $wpdb->get_row(
		$wpdb->prepare( 'SELECT COUNT(*) AS n, COALESCE(SUM(value), 0) AS v FROM ' . carts_table() . ' WHERE left_at >= %s AND converted_at IS NULL', $since ),
		ARRAY_A
	);
	$before = (int) $wpdb->get_var(
		$wpdb->prepare( 'SELECT COUNT(*) FROM ' . carts_table() . ' WHERE left_at >= %s AND left_at < %s AND converted_at IS NULL', $prev, $since )
	);
	return array(
		'count'    => (int) ( $row['n'] ?? 0 ),
		'value'    => round( (float) ( $row['v'] ?? 0 ), 2 ),
		'currency' => get_woocommerce_currency(),
		'previous' => $before,
	);
}

/**
 * Orders a moment was credited with (`attribute_conversion()`): this week's count and value, and last week's count.
 *
 * @since 0.8.0
 *
 * @param string $since Start of this week, SQL UTC.
 * @param string $prev  Start of last week, SQL UTC.
 * @return array{count: int, value: float, previous: int}
 */
function recovered_orders( string $since, string $prev ): array {
	global $wpdb;
	$rows     = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT firings FROM ' . journeys_table() . ' WHERE firings LIKE %s', '%"order_at"%' ) );
	$orders   = array();
	$previous = array();
	foreach ( $rows as $json ) {
		$firings = json_decode( (string) $json, true );
		if ( ! is_array( $firings ) ) {
			continue;
		}
		foreach ( $firings as $firing ) {
			if ( ! is_array( $firing ) || empty( $firing['order'] ) ) {
				continue;
			}
			$at = (string) ( $firing['order_at'] ?? '' );
			if ( $at >= $since ) {
				$orders[ (int) $firing['order'] ] = (float) ( $firing['value'] ?? 0 );
			} elseif ( $at >= $prev ) {
				$previous[ (int) $firing['order'] ] = true;
			}
		}
	}
	return array(
		'count'    => count( $orders ),
		'value'    => round( array_sum( $orders ), 2 ),
		'previous' => count( $previous ),
	);
}

/**
 * Orders against journeys that reached checkout, this week and last.
 *
 * @since 0.8.0
 *
 * @param string $since Start of this week, SQL UTC.
 * @param string $prev  Start of last week, SQL UTC.
 * @return array{reached: int, orders: int, percent: int, previous_percent: int}
 */
function checkout_conversion( string $since, string $prev ): array {
	$now    = conversion_between( $since, '9999-12-31 23:59:59' );
	$before = conversion_between( $prev, $since );
	return array(
		'reached'          => $now['reached'],
		'orders'           => $now['orders'],
		'percent'          => $now['percent'],
		'previous_percent' => $before['percent'],
	);
}

/**
 * Journeys started in a window that reached checkout, those that converted, and the second as a percentage of the first.
 *
 * @since 0.8.0
 *
 * @param string $from Window start, SQL UTC.
 * @param string $to   Window end, SQL UTC.
 * @return array{reached: int, orders: int, percent: int}
 */
function conversion_between( string $from, string $to ): array {
	global $wpdb;
	$row     = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT SUM(journey LIKE %s) AS reached_n, SUM(outcome = %s) AS converted_n FROM ' . journeys_table() . ' WHERE started_at >= %s AND started_at < %s',
			'%checkout:%',
			'converted',
			$from,
			$to
		),
		ARRAY_A
	);
	$reached = (int) ( $row['reached_n'] ?? 0 );
	$orders  = (int) ( $row['converted_n'] ?? 0 );
	return array(
		'reached' => $reached,
		'orders'  => $orders,
		'percent' => $reached > 0 ? min( 100, (int) round( 100 * $orders / $reached ) ) : 0,
	);
}

/**
 * The page kind most carted leavers left from this week, with their count, the median time there, and every leaver.
 *
 * @since 0.8.0
 *
 * @param string $since Start of this week, SQL UTC.
 * @return array{kind: string, count: int, leavers: int, median_ms: int}|null Null when nobody left.
 */
function leak( string $since ): ?array {
	global $wpdb;
	$journeys = (array) $wpdb->get_col(
		$wpdb->prepare(
			'SELECT journey FROM ' . journeys_table() . ' WHERE started_at >= %s AND outcome = %s AND cart_id IS NOT NULL ORDER BY started_at DESC LIMIT %d',
			$since,
			'left',
			STRIP_LEAVERS
		)
	);
	$last     = array();
	foreach ( $journeys as $journey ) {
		foreach ( array_reverse( explode( '>', (string) $journey ) ) as $step ) {
			list( $kind, , $ms ) = array_pad( explode( ':', $step ), 3, '0' );
			if ( '' === $kind || 'away' === $kind ) {
				continue;
			}
			$last[ $kind ][] = (int) $ms;
			break;
		}
	}
	if ( empty( $last ) ) {
		return null;
	}
	uasort( $last, static fn( array $a, array $b ): int => count( $b ) <=> count( $a ) );
	$kind = (string) array_key_first( $last );
	$ms   = $last[ $kind ];
	sort( $ms );
	$n      = count( $ms );
	$median = $n % 2 ? $ms[ intdiv( $n, 2 ) ] : (int) round( ( $ms[ $n / 2 - 1 ] + $ms[ $n / 2 ] ) / 2 );
	return array(
		'kind'      => $kind,
		'count'     => $n,
		'leavers'   => array_sum( array_map( 'count', $last ) ),
		'median_ms' => $median,
	);
}

/**
 * The products most often in a cart left behind this week, with how many carts converted holding each.
 *
 * @since 0.8.0
 *
 * @param string $since Start of this week, SQL UTC.
 * @return array<int, array{id: int, left: int, bought: int}>
 */
function products_left( string $since ): array {
	global $wpdb;
	$items = cart_items_table();
	$carts = carts_table();
	$rows  = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT i.product_id, COUNT(*) AS n FROM {$items} i JOIN {$carts} c ON c.cart_id = i.cart_id WHERE c.left_at >= %s AND c.converted_at IS NULL GROUP BY i.product_id ORDER BY n DESC, i.product_id ASC LIMIT %d",
			$since,
			STRIP_PRODUCTS
		),
		ARRAY_A
	);
	if ( empty( $rows ) ) {
		return array();
	}
	$ids     = array_map( static fn( array $row ): int => (int) $row['product_id'], $rows );
	$holders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$bought  = array_column(
		(array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.product_id, COUNT(*) AS n FROM {$items} i JOIN {$carts} c ON c.cart_id = i.cart_id WHERE c.converted_at >= %s AND i.product_id IN ({$holders}) GROUP BY i.product_id",
				$since,
				...$ids
			),
			ARRAY_A
		),
		'n',
		'product_id'
	);
	return array_map(
		static fn( array $row ): array => array(
			'id'     => (int) $row['product_id'],
			'left'   => (int) $row['n'],
			'bought' => (int) ( $bought[ $row['product_id'] ] ?? 0 ),
		),
		$rows
	);
}

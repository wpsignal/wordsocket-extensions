<?php
/**
 * The board's "This week" strip, read from WordSocket's journeys and the carts they carry.
 *
 * - Carts left behind, recovered orders and checkout conversion over the last seven days, each against the seven before and as a day-by-day series over both weeks.
 * - The page kind most leavers left from, and their median time there.
 * - The products most often left in a cart, with how many carts bought them.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

use WPSignal\WPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- WordSocket's journeys table: the name from its class, fragments from prepare(), one placeholder per id.

/**
 * The strip's window, in days.
 *
 * @since 0.8.0
 */
const STRIP_DAYS = 7;

/**
 * Days each figure's series covers: this week and the one before.
 *
 * @since 0.8.0
 */
const STRIP_TREND_DAYS = 2 * STRIP_DAYS;

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
 * This week's figures for the board, null with history off; `journeys` says whether WordSocket keeps the journeys the leak, the conversion and the recovered orders read.
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
	$prev  = gmdate( 'Y-m-d H:i:s', $now - STRIP_TREND_DAYS * DAY_IN_SECONDS );
	$dates = strip_dates( $now );
	$strip = array(
		'since'      => datetime_to_rfc3339( $since ),
		'journeys'   => journeys_available() && WPS::journeys()->enabled(),
		'trend_days' => $dates,
		'left'       => carts_left( $since, $prev, $dates ),
		'recovered'  => recovered_orders( $since, $prev, $dates ),
		'conversion' => checkout_conversion( $since, $prev, $dates ),
		'leak'       => leak( $since ),
		'products'   => products_left( $since ),
	);
	wp_cache_set( 'week_strip', $strip, 'shopsocket', STRIP_CACHE_TTL );
	return $strip;
}

/**
 * Carts whose shopper left and that never became an order, by each cart's newest journey: this week's count and value, and last week's count.
 *
 * @since 0.8.0 Read from WordSocket's journeys.
 *
 * @param string   $since Start of this week, SQL UTC.
 * @param string   $prev  Start of last week, SQL UTC.
 * @param string[] $dates The series' UTC days, oldest first.
 * @return array{count: int, value: float, currency: string, previous: int, days: int[]}
 */
function carts_left( string $since, string $prev, array $dates ): array {
	$count    = 0;
	$value    = 0.0;
	$previous = 0;
	$by_day   = array();
	foreach ( cart_journeys_since( $prev ) as $cart ) {
		if ( 'left' !== $cart['outcome'] || empty( $cart['items'] ) ) {
			continue;
		}
		$day            = substr( $cart['ended_at'], 0, 10 );
		$by_day[ $day ] = ( $by_day[ $day ] ?? 0 ) + 1;
		if ( $cart['ended_at'] >= $since ) {
			++$count;
			$value += $cart['value'];
		} else {
			++$previous;
		}
	}
	return array(
		'count'    => $count,
		'value'    => round( $value, 2 ),
		'currency' => get_woocommerce_currency(),
		'previous' => $previous,
		'days'     => day_series( $by_day, $dates ),
	);
}

/**
 * The last STRIP_TREND_DAYS UTC days as `Y-m-d`, oldest first, today last.
 *
 * @since 0.8.0
 *
 * @param int $now Unix time.
 * @return string[]
 */
function strip_dates( int $now ): array {
	$dates = array();
	for ( $d = STRIP_TREND_DAYS - 1; $d >= 0; $d-- ) {
		$dates[] = gmdate( 'Y-m-d', $now - $d * DAY_IN_SECONDS );
	}
	return $dates;
}

/**
 * Counts keyed by `Y-m-d` as one value per day of `$dates`.
 *
 * @since 0.8.0
 *
 * @param array<string, int> $by_day Count per day.
 * @param string[]           $dates  The days, oldest first.
 * @return int[]
 */
function day_series( array $by_day, array $dates ): array {
	return array_map( static fn( string $day ): int => (int) ( $by_day[ $day ] ?? 0 ), $dates );
}

/**
 * Orders a moment was credited with (`WPS::journeys()->credit()`): this week's count and value, and last week's count.
 *
 * @since 0.8.0
 *
 * @param string   $since Start of this week, SQL UTC.
 * @param string   $prev  Start of last week, SQL UTC.
 * @param string[] $dates The series' UTC days, oldest first.
 * @return array{count: int, value: float, previous: int, days: int[]}
 */
function recovered_orders( string $since, string $prev, array $dates ): array {
	global $wpdb;
	// A credited firing is at most the attribution window before its order, and a journey starts before its firings.
	$floor    = gmdate( 'Y-m-d H:i:s', (int) strtotime( $prev . ' UTC' ) - attribution_window() );
	$rows     = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT firings FROM ' . journeys_table() . ' WHERE started_at >= %s AND firings LIKE %s', $floor, '%"order_at"%' ) );
	$orders   = array();
	$previous = array();
	$days     = array();
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
			if ( $at >= $prev ) {
				$days[ (int) $firing['order'] ] = substr( $at, 0, 10 );
			}
		}
	}
	return array(
		'count'    => count( $orders ),
		'value'    => round( array_sum( $orders ), 2 ),
		'previous' => count( $previous ),
		'days'     => day_series( array_count_values( $days ), $dates ),
	);
}

/**
 * Orders against journeys that reached checkout, this week and last, and the percentage per day.
 *
 * @since 0.8.0
 *
 * @param string   $since Start of this week, SQL UTC.
 * @param string   $prev  Start of last week, SQL UTC.
 * @param string[] $dates The series' UTC days, oldest first.
 * @return array{reached: int, orders: int, percent: int, previous_percent: int, days: int[]}
 */
function checkout_conversion( string $since, string $prev, array $dates ): array {
	global $wpdb;
	$weeks  = (array) $wpdb->get_row(
		$wpdb->prepare(
			'SELECT SUM(started_at >= %s AND journey LIKE %s) AS reached_n, SUM(started_at >= %s AND outcome = %s) AS converted_n, SUM(started_at < %s AND journey LIKE %s) AS reached_before, SUM(started_at < %s AND outcome = %s) AS converted_before FROM ' . journeys_table() . ' WHERE started_at >= %s',
			$since,
			'%checkout:%',
			$since,
			'converted',
			$since,
			'%checkout:%',
			$since,
			'converted',
			$prev
		),
		ARRAY_A
	);
	$rows   = (array) $wpdb->get_results(
		$wpdb->prepare(
			'SELECT DATE(started_at) AS day, SUM(journey LIKE %s) AS reached_n, SUM(outcome = %s) AS converted_n FROM ' . journeys_table() . ' WHERE started_at >= %s GROUP BY day',
			'%checkout:%',
			'converted',
			$prev
		),
		ARRAY_A
	);
	$by_day = array();
	foreach ( $rows as $row ) {
		$by_day[ (string) $row['day'] ] = percent_of( (int) ( $row['converted_n'] ?? 0 ), (int) ( $row['reached_n'] ?? 0 ) );
	}
	$reached = (int) ( $weeks['reached_n'] ?? 0 );
	$orders  = (int) ( $weeks['converted_n'] ?? 0 );
	return array(
		'reached'          => $reached,
		'orders'           => $orders,
		'percent'          => percent_of( $orders, $reached ),
		'previous_percent' => percent_of( (int) ( $weeks['converted_before'] ?? 0 ), (int) ( $weeks['reached_before'] ?? 0 ) ),
		'days'             => day_series( $by_day, $dates ),
	);
}

/**
 * Orders as a whole percentage of the journeys that reached checkout, 0 of none, 100 at most.
 *
 * @since 0.8.0
 *
 * @param int $orders  Journeys that converted.
 * @param int $reached Journeys that reached checkout.
 * @return int
 */
function percent_of( int $orders, int $reached ): int {
	return $reached > 0 ? min( 100, (int) round( 100 * $orders / $reached ) ) : 0;
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
			'SELECT journey FROM ' . journeys_table() . ' WHERE started_at >= %s AND outcome = %s AND ref IS NOT NULL ORDER BY started_at DESC LIMIT %d',
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
 * @since 0.8.0 Read from WordSocket's journeys.
 *
 * @param string $since Start of this week, SQL UTC.
 * @return array<int, array{id: int, left: int, bought: int}>
 */
function products_left( string $since ): array {
	$left   = array();
	$bought = array();
	foreach ( cart_journeys_since( $since ) as $cart ) {
		if ( 'left' !== $cart['outcome'] && 'converted' !== $cart['outcome'] ) {
			continue;
		}
		foreach ( array_keys( $cart['items'] ) as $product_id ) {
			if ( 'left' === $cart['outcome'] ) {
				$left[ $product_id ] = ( $left[ $product_id ] ?? 0 ) + 1;
			} else {
				$bought[ $product_id ] = ( $bought[ $product_id ] ?? 0 ) + 1;
			}
		}
	}
	if ( empty( $left ) ) {
		return array();
	}
	uksort( $left, static fn( int $a, int $b ): int => ( $left[ $b ] <=> $left[ $a ] ) ?: ( $a <=> $b ) );
	$out = array();
	foreach ( array_slice( $left, 0, STRIP_PRODUCTS, true ) as $product_id => $n ) {
		$out[] = array(
			'id'     => $product_id,
			'left'   => $n,
			'bought' => (int) ( $bought[ $product_id ] ?? 0 ),
		);
	}
	return $out;
}

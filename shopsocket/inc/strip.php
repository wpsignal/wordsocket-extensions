<?php
/**
 * The board's "This day" strip, read from WordSocket's journeys and the carts they carry.
 *
 * - Sales, carts left behind, recovered orders and checkout conversion over the last 24 hours, each against its daily average over the last seven days and as a day-by-day series over them.
 * - For the board's abandoned carts, over the time frame it asks for: the page kind most leavers left from with their median time there, the pages most left from, and the products most often left in a cart with how many carts bought them.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

use WPSignal\Journeys;
use WPSignal\WPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- WordSocket's journeys table: the name from its class, fragments from prepare(), one placeholder per id.

/**
 * The strip's window, in hours.
 *
 * @since 0.8.0
 */
const STRIP_HOURS = 24;

/**
 * Days each figure's series covers, the last of them the strip's window.
 *
 * @since 0.8.0
 */
const STRIP_TREND_DAYS = 7;

/**
 * Left journeys the leak sentence and the pages left from read, newest first.
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
 * The last day's figures for the board, null with history off; `journeys` says whether WordSocket keeps the journeys the conversion and the recovered orders read.
 *
 * @since 0.8.0
 *
 * @return array<string, mixed>|null
 */
function day_strip(): ?array {
	if ( ! history_enabled() ) {
		return null;
	}
	$cached = wp_cache_get( 'day_strip', 'shopsocket' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$now   = time();
	$since = gmdate( 'Y-m-d H:i:s', $now - STRIP_HOURS * HOUR_IN_SECONDS );
	$strip = array(
		'since'      => datetime_to_rfc3339( $since ),
		'journeys'   => journeys_available() && WPS::journeys()->enabled(),
		'trend_days' => strip_days( $now ),
		'sales'      => sales( $now ),
		'left'       => carts_left( $now ),
		'recovered'  => recovered_orders( $now ),
		'conversion' => checkout_conversion( $now ),
	);
	wp_cache_set( 'day_strip', $strip, 'shopsocket', STRIP_CACHE_TTL );
	return $strip;
}

/**
 * What the carts left in the last `$hours` tell the board, null with history off: the kind of page most leavers left from, then the pages most left from and the products left most often, `abandoned_limit()` of each at most beside how many there are.
 *
 * @since 0.8.0
 *
 * @param int $hours The time frame, in hours.
 * @return array{hours: int, leak: array{kind: string, count: int, leavers: int, median_s: int}|null, pages: array<int, array{kind: string, id: int, left: int, median_s: int}>, pages_total: int, products: array<int, array{id: int, left: int, bought: int}>, products_total: int}|null
 */
function abandonment( int $hours = BOARD_HOURS ): ?array {
	if ( ! history_enabled() ) {
		return null;
	}
	$key    = 'abandonment_' . $hours;
	$cached = wp_cache_get( $key, 'shopsocket' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$since       = gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS );
	$steps       = last_steps( $since );
	$limit       = abandoned_limit( $hours );
	$pages       = pages_left( $steps );
	$products    = products_left( $since );
	$abandonment = array(
		'hours'          => $hours,
		'leak'           => leak( $steps ),
		'pages'          => array_slice( $pages, 0, $limit ),
		'pages_total'    => count( $pages ),
		'products'       => array_slice( $products, 0, $limit ),
		'products_total' => count( $products ),
	);
	wp_cache_set( $key, $abandonment, 'shopsocket', STRIP_CACHE_TTL );
	return $abandonment;
}

/**
 * When each day of the series ends, RFC 3339 UTC, oldest first: a day is the STRIP_HOURS up to that moment, the last one up to now.
 *
 * @since 0.8.0
 *
 * @param int $now Unix time.
 * @return string[]
 */
function strip_days( int $now ): array {
	$days = array();
	for ( $d = STRIP_TREND_DAYS - 1; $d >= 0; $d-- ) {
		$days[] = gmdate( 'Y-m-d\TH:i:s\Z', $now - $d * STRIP_HOURS * HOUR_IN_SECONDS );
	}
	return $days;
}

/**
 * Where a moment falls in the series: 0 the oldest day, STRIP_TREND_DAYS - 1 the last STRIP_HOURS, null outside it.
 *
 * @since 0.8.0
 *
 * @param string $at  SQL UTC.
 * @param int    $now Unix time.
 * @return int|null
 */
function strip_day( string $at, int $now ): ?int {
	$ago = $now - (int) strtotime( $at . ' UTC' );
	if ( $ago < 0 ) {
		return null;
	}
	$day = STRIP_TREND_DAYS - 1 - intdiv( $ago, STRIP_HOURS * HOUR_IN_SECONDS );
	return $day >= 0 ? $day : null;
}

/**
 * The start of the series, SQL UTC.
 *
 * @since 0.8.0
 *
 * @param int $now Unix time.
 * @return string
 */
function strip_floor( int $now ): string {
	return gmdate( 'Y-m-d H:i:s', $now - STRIP_TREND_DAYS * STRIP_HOURS * HOUR_IN_SECONDS );
}

/**
 * A series' daily average, to one decimal.
 *
 * @since 0.8.0
 *
 * @param array<int, int|float> $days One value per day.
 * @return float
 */
function daily_average( array $days ): float {
	return round( array_sum( $days ) / max( 1, count( $days ) ), 1 );
}

/**
 * Paid orders placed in the last day: how many and what they came to, against the daily average, with what each day of the series came to.
 *
 * @since 0.8.0
 *
 * @param int $now Unix time.
 * @return array{count: int, value: float, currency: string, average: float, days: float[]}
 */
function sales( int $now ): array {
	global $wpdb;
	$statuses = array_map( static fn( string $status ): string => 'wc-' . $status, wc_get_is_paid_statuses() );
	$in       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
	$args     = array_merge( array( gmdate( 'Y-m-d H:i:s', $now ) ), $statuses, array( strip_floor( $now ) ) );
	$seconds  = STRIP_HOURS * HOUR_IN_SECONDS;
	if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$sql = "SELECT FLOOR(TIMESTAMPDIFF(SECOND, date_created_gmt, %s) / {$seconds}) AS days_ago, COUNT(*) AS n, SUM(total_amount) AS total FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND status IN ({$in}) AND date_created_gmt >= %s GROUP BY days_ago";
	} else {
		$sql = "SELECT FLOOR(TIMESTAMPDIFF(SECOND, p.post_date_gmt, %s) / {$seconds}) AS days_ago, COUNT(*) AS n, SUM(m.meta_value) AS total FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_order_total' WHERE p.post_type = 'shop_order' AND p.post_status IN ({$in}) AND p.post_date_gmt >= %s GROUP BY days_ago";
	}
	$days  = array_fill( 0, STRIP_TREND_DAYS, 0.0 );
	$count = 0;
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) as $row ) {
		$day = STRIP_TREND_DAYS - 1 - (int) $row['days_ago'];
		if ( ! isset( $days[ $day ] ) ) {
			continue;
		}
		$days[ $day ] = round( (float) $row['total'], 2 );
		if ( STRIP_TREND_DAYS - 1 === $day ) {
			$count = (int) $row['n'];
		}
	}
	return array(
		'count'    => $count,
		'value'    => $days[ STRIP_TREND_DAYS - 1 ],
		'currency' => get_woocommerce_currency(),
		'average'  => daily_average( $days ),
		'days'     => $days,
	);
}

/**
 * Carts whose shopper left and that never became an order, by each cart's newest journey: the last day's count and value, against the daily average, with each day's count.
 *
 * @since 0.8.0
 *
 * @param int $now Unix time.
 * @return array{count: int, value: float, currency: string, average: float, days: int[]}
 */
function carts_left( int $now ): array {
	$days  = array_fill( 0, STRIP_TREND_DAYS, 0 );
	$value = 0.0;
	foreach ( cart_journeys_since( strip_floor( $now ) ) as $cart ) {
		$day = strip_day( (string) $cart['last_seen'], $now );
		if ( null === $day || 'left' !== $cart['outcome'] || empty( $cart['items'] ) ) {
			continue;
		}
		++$days[ $day ];
		if ( STRIP_TREND_DAYS - 1 === $day ) {
			$value += $cart['value'];
		}
	}
	return array(
		'count'    => $days[ STRIP_TREND_DAYS - 1 ],
		'value'    => round( $value, 2 ),
		'currency' => get_woocommerce_currency(),
		'average'  => daily_average( $days ),
		'days'     => $days,
	);
}

/**
 * Orders a moment was credited with (`WPS::journeys()->credit()`): the last day's count and value, against the daily average, with each day's count.
 *
 * @since 0.8.0
 *
 * @param int $now Unix time.
 * @return array{count: int, value: float, average: float, days: int[]}
 */
function recovered_orders( int $now ): array {
	global $wpdb;
	// A credited firing is at most the attribution window before its order, and a journey starts before its firings.
	$floor  = gmdate( 'Y-m-d H:i:s', (int) strtotime( strip_floor( $now ) . ' UTC' ) - attribution_window() );
	$rows   = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT firings FROM ' . journeys_table() . ' WHERE started_at >= %s AND ' . Journeys::credited_where(), $floor ) );
	$orders = array();
	foreach ( $rows as $json ) {
		foreach ( Journeys::unpack_firings( (string) $json ) as $firing ) {
			if ( ! is_array( $firing ) || empty( $firing['order'] ) ) {
				continue;
			}
			$day = strip_day( (string) ( $firing['order_at'] ?? '' ), $now );
			if ( null !== $day ) {
				$orders[ (int) $firing['order'] ] = array( $day, (float) ( $firing['value'] ?? 0 ) );
			}
		}
	}
	$days  = array_fill( 0, STRIP_TREND_DAYS, 0 );
	$value = 0.0;
	foreach ( $orders as list( $day, $worth ) ) {
		++$days[ $day ];
		if ( STRIP_TREND_DAYS - 1 === $day ) {
			$value += $worth;
		}
	}
	return array(
		'count'   => $days[ STRIP_TREND_DAYS - 1 ],
		'value'   => round( $value, 2 ),
		'average' => daily_average( $days ),
		'days'    => $days,
	);
}

/**
 * Orders against journeys that reached checkout over the last day, the same over the whole series, and the percentage per day.
 *
 * @since 0.8.0
 *
 * @param int $now Unix time.
 * @return array{reached: int, orders: int, percent: int, average_percent: int, days: int[]}
 */
function checkout_conversion( int $now ): array {
	global $wpdb;
	$seconds = STRIP_HOURS * HOUR_IN_SECONDS;
	$rows    = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT FLOOR(TIMESTAMPDIFF(SECOND, started_at, %s) / {$seconds}) AS days_ago, SUM(" . Journeys::step_where( 'checkout' ) . ') AS reached_n, SUM(outcome = %s) AS converted_n FROM ' . journeys_table() . ' WHERE started_at >= %s GROUP BY days_ago',
			gmdate( 'Y-m-d H:i:s', $now ),
			'converted',
			strip_floor( $now )
		),
		ARRAY_A
	);
	$days    = array_fill( 0, STRIP_TREND_DAYS, 0 );
	$reached = 0;
	$orders  = 0;
	$all     = array( 0, 0 );
	foreach ( $rows as $row ) {
		$day = STRIP_TREND_DAYS - 1 - (int) $row['days_ago'];
		if ( ! isset( $days[ $day ] ) ) {
			continue;
		}
		$days[ $day ] = percent_of( (int) $row['converted_n'], (int) $row['reached_n'] );
		$all[0]      += (int) $row['converted_n'];
		$all[1]      += (int) $row['reached_n'];
		if ( STRIP_TREND_DAYS - 1 === $day ) {
			$reached = (int) $row['reached_n'];
			$orders  = (int) $row['converted_n'];
		}
	}
	return array(
		'reached'         => $reached,
		'orders'          => $orders,
		'percent'         => percent_of( $orders, $reached ),
		'average_percent' => percent_of( $all[0], $all[1] ),
		'days'            => $days,
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
 * The page each carted leaver left from since a moment: the last step of their journey that is not `away`, as `{ kind, id, s }`.
 *
 * @since 0.8.0
 *
 * @param string $since Start of the time frame, SQL UTC.
 * @return array<int, array{kind: string, id: int, s: int}>
 */
function last_steps( string $since ): array {
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
	foreach ( Journeys::unpack_journeys( $journeys ) as $journey ) {
		foreach ( array_reverse( explode( '>', (string) $journey ) ) as $step ) {
			list( $kind, $id, $s ) = array_pad( explode( ':', $step ), 3, '0' );
			if ( '' === $kind || 'away' === $kind ) {
				continue;
			}
			$last[] = array(
				'kind' => $kind,
				'id'   => (int) $id,
				's'    => (int) $s,
			);
			break;
		}
	}
	return $last;
}

/**
 * The median of whole seconds, rounded; 0 for none.
 *
 * @since 0.8.0
 *
 * @param int[] $s Seconds.
 * @return int
 */
function median_s( array $s ): int {
	$n = count( $s );
	if ( 0 === $n ) {
		return 0;
	}
	sort( $s );
	return $n % 2 ? $s[ intdiv( $n, 2 ) ] : (int) round( ( $s[ $n / 2 - 1 ] + $s[ $n / 2 ] ) / 2 );
}

/**
 * The page kind most carted leavers left from, with their count, the median time there, and every leaver.
 *
 * @since 0.8.0
 *
 * @param array<int, array{kind: string, id: int, s: int}> $steps What `last_steps()` answered.
 * @return array{kind: string, count: int, leavers: int, median_s: int}|null Null when nobody left.
 */
function leak( array $steps ): ?array {
	$last = array();
	foreach ( $steps as $step ) {
		$last[ $step['kind'] ][] = $step['s'];
	}
	if ( empty( $last ) ) {
		return null;
	}
	uasort( $last, static fn( array $a, array $b ): int => count( $b ) <=> count( $a ) );
	$kind = (string) array_key_first( $last );
	return array(
		'kind'     => $kind,
		'count'    => count( $last[ $kind ] ),
		'leavers'  => count( $steps ),
		'median_s' => median_s( $last[ $kind ] ),
	);
}

/**
 * Every page carted leavers left from, the most left from first: each page's kind and id, how many left from it and the median time there. Pages that are gone count as one, `deleted` with id 0.
 *
 * @since 0.8.0
 *
 * @param array<int, array{kind: string, id: int, s: int}> $steps What `last_steps()` answered.
 * @return array<int, array{kind: string, id: int, left: int, median_s: int}>
 */
function pages_left( array $steps ): array {
	$pages = array();
	foreach ( $steps as $step ) {
		$pages[ $step['kind'] . ':' . ( 'deleted' === $step['kind'] ? 0 : $step['id'] ) ][] = $step['s'];
	}
	uksort( $pages, static fn( string $a, string $b ): int => array( count( $pages[ $b ] ), $a ) <=> array( count( $pages[ $a ] ), $b ) );
	$out = array();
	foreach ( $pages as $page => $s ) {
		list( $kind, $id ) = explode( ':', $page );
		$out[]             = array(
			'kind'     => $kind,
			'id'       => (int) $id,
			'left'     => count( $s ),
			'median_s' => median_s( $s ),
		);
	}
	return $out;
}

/**
 * Every product in a cart left behind since a moment, the most often left first, with how many carts converted holding each.
 *
 * @since 0.8.0
 *
 * @param string $since Start of the time frame, SQL UTC.
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
	uksort( $left, static fn( int $a, int $b ): int => array( $left[ $b ], $a ) <=> array( $left[ $a ], $b ) );
	$out = array();
	foreach ( $left as $product_id => $n ) {
		$out[] = array(
			'id'     => $product_id,
			'left'   => $n,
			'bought' => (int) ( $bought[ $product_id ] ?? 0 ),
		);
	}
	return $out;
}

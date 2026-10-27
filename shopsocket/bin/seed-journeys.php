<?php
/**
 * Seed 500 shopper journeys into WordSocket's journeys table, the carts the shoppers held on
 * them as ShopSocket records them (`data`), the way the Phase 61 mock generated its own:
 * a weighted Markov walk over the shop's page kinds, 28 days, weekday and hour
 * weighted. Deterministic (mt_srand). Every seeded row carries a visitor id
 * starting with "5eed0000-", so one DELETE removes them again.
 *
 * Usage: wp --path=<site> eval-file bin/seed-journeys.php [count]
 *
 * @package ShopSocket
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.WP.AlternativeFunctions.rand_mt_rand, WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand

use function WPSignal\Extensions\ShopSocket\journeys_table;

global $wpdb;
$count = isset( $args[0] ) ? max( 1, (int) $args[0] ) : 500;
mt_srand( 500 );

$products = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" ) );
$prices   = array();
$cats     = array();
foreach ( $products as $pid ) {
	$p              = wc_get_product( $pid );
	$prices[ $pid ] = (float) ( $p ? $p->get_price() : 0 );
	$cats[ $pid ]   = $p ? $p->get_category_ids() : array();
}
$products = array_values( array_filter( $products, static fn( $step_id ) => $prices[ $step_id ] > 0 ) );
$terms    = array_map( 'intval', $wpdb->get_col( "SELECT t.term_id FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'product_cat' AND t.slug <> 'uncategorized'" ) );
$currency = get_woocommerce_currency();

$rnd         = static fn(): float => mt_rand() / mt_getrandmax();
$weighted    = static function ( array $table ) use ( $rnd ): string {
	$total = array_sum( $table );
	$r     = $rnd() * $total;
	foreach ( $table as $k => $w ) {
		$r -= $w;
		if ( $r <= 0 ) {
			return (string) $k;
		}
	}
	return (string) array_key_last( $table );
};
$log_uniform = static fn( int $lo, int $hi ): int => (int) ( round( exp( log( $lo ) + $rnd() * ( log( $hi ) - log( $lo ) ) ) / 100 ) * 100 );
$uuid        = static fn( string $prefix = '' ): string => $prefix . substr( wp_generate_uuid4(), strlen( $prefix ) );

// Start, transitions (away is the tab going hidden; back returns to the page it left), dwell ranges in ms.
$start       = array(
	'home'     => 30,
	'shop'     => 15,
	'category' => 15,
	'product'  => 35,
	'search'   => 5,
);
$next        = array(
	'home'     => array(
		'shop'     => 25,
		'category' => 20,
		'product'  => 35,
		'search'   => 5,
		'away'     => 15,
	),
	'shop'     => array(
		'product'  => 55,
		'category' => 20,
		'shop'     => 5,
		'away'     => 15,
		'home'     => 5,
	),
	'category' => array(
		'product'  => 60,
		'category' => 15,
		'shop'     => 5,
		'away'     => 12,
		'home'     => 8,
	),
	'search'   => array(
		'product' => 55,
		'search'  => 15,
		'shop'    => 10,
		'away'    => 15,
		'home'    => 5,
	),
	'product'  => array(
		'product'  => 30,
		'cart'     => 12,
		'category' => 15,
		'shop'     => 8,
		'away'     => 20,
		'home'     => 5,
	),
	'cart'     => array(
		'checkout' => 45,
		'product'  => 20,
		'shop'     => 10,
		'away'     => 25,
	),
	'checkout' => array(
		'thanks'  => 55,
		'cart'    => 10,
		'away'    => 30,
		'product' => 5,
	),
	'thanks'   => array(
		'home' => 50,
		'shop' => 50,
	),
);
$dwell       = array(
	'home'     => array( 1500, 8000 ),
	'shop'     => array( 3000, 20000 ),
	'category' => array( 3000, 15000 ),
	'search'   => array( 3000, 12000 ),
	'product'  => array( 8000, 180000 ),
	'cart'     => array( 5000, 90000 ),
	'checkout' => array( 20000, 300000 ),
	'thanks'   => array( 3000, 15000 ),
	'away'     => array( 8000, 900000 ),
);
$day_weights = array();
for ( $d = 0; $d < 28; $d++ ) {
	$dow               = (int) gmdate( 'w', strtotime( "-{$d} days" ) );
	$day_weights[ $d ] = ( 1 + 0.025 * ( 27 - $d ) ) * ( in_array( $dow, array( 0, 6 ), true ) ? 0.7 : 1 );
}
$hour_weights = array( 1, 0.6, 0.4, 0.3, 0.3, 0.5, 1.2, 2.5, 3.5, 3.5, 3.2, 3, 3.5, 3.2, 3, 2.8, 2.8, 3, 3.5, 4, 4.5, 4.2, 3.2, 2 );

$journeys = 0;
$carts    = 0;
$orders   = 0;
for ( $n = 0; $n < $count; $n++ ) {
	$visitor   = $uuid( '5eed0000-' );
	$steps     = array();
	$kind      = $weighted( $start );
	$held      = array(); // Product id => qty.
	$away_from = null;
	$converted = false;
	$pick_id   = static function ( string $kind ) use ( $products, $terms, $rnd ): int {
		if ( 'product' === $kind ) {
			return $products[ (int) floor( $rnd() * count( $products ) ) ] ?? 0;
		}
		if ( 'category' === $kind ) {
			return $terms[ (int) floor( $rnd() * count( $terms ) ) ] ?? 0;
		}
		return 0;
	};
	$step_id   = $pick_id( $kind );
	$max_steps = 14;
	$taken     = 0;
	while ( $taken < $max_steps ) {
		++$taken;
		$steps[] = "{$kind}:{$step_id}:" . $log_uniform( $dwell[ $kind ][0], $dwell[ $kind ][1] );
		if ( 'product' === $kind && $rnd() < 0.12 ) {
			$held[ $step_id ] = ( $held[ $step_id ] ?? 0 ) + 1;
		}
		if ( 'thanks' === $kind ) {
			$converted = true;
		}
		$i = count( $steps );
		if ( $rnd() < ( $i < 2 ? 0.06 : 0.10 + 0.04 * ( $i - 2 ) ) ) {
			break;
		}
		if ( 'away' === $kind ) {
			[ $kind, $step_id ] = $away_from;
			$away_from          = null;
			continue;
		}
		$to = $weighted( $next[ $kind ] );
		if ( 'cart' === $to && ! $held ) {
			$held[ $step_id ? $step_id : $products[0] ] = 1; // Adding from this product page.
		}
		if ( 'checkout' === $to && ! $held ) {
			$to = 'shop';
		}
		if ( 'away' === $to ) {
			$away_from = array( $kind, $step_id );
			$kind      = 'away';
			$step_id   = 0;
			continue;
		}
		$step_id = ( $to === $kind && $rnd() < 0.3 ) ? $step_id : $pick_id( $to );
		$kind    = $to;
	}
	$ended_hidden = 'away' === $kind;
	$journey      = implode( '>', $steps );
	$total_ms     = array_sum( array_map( static fn( $s ) => (int) explode( ':', $s )[2], $steps ) );

	$day     = (int) $weighted( $day_weights );
	$hour    = (int) $weighted( $hour_weights );
	$started = min( time() - 60, strtotime( gmdate( 'Y-m-d', strtotime( "-{$day} days" ) ) . sprintf( ' %02d:%02d:%02d', $hour, mt_rand( 0, 59 ), mt_rand( 0, 59 ) ) ) );
	$ended   = $started + (int) ( $total_ms / 1000 );
	$value   = 0.0;
	foreach ( $held as $pid => $qty ) {
		$value += $prices[ $pid ] * $qty;
	}
	$cart_id = $held ? substr( md5( $visitor ), 0, 16 ) : null;
	$outcome = $converted ? 'converted' : 'left'; // Nothing seeded is live, so nothing is open.
	$data    = null;
	if ( $held ) {
		$items = array();
		foreach ( $held as $pid => $qty ) {
			$items[ (string) $pid ] = $qty;
		}
		$data = array(
			'c' => $currency,
			'i' => $items,
		);
		if ( $converted ) {
			$data['o'] = 1000 + $n;
		}
	}

	$wpdb->insert(
		journeys_table(),
		array(
			'journey_id' => $uuid(),
			'visitor'    => $visitor,
			'ref'        => $cart_id,
			'journey'    => $journey,
			'steps'      => count( $steps ),
			'outcome'    => $outcome,
			'value'      => $held ? round( $value, 4 ) : null,
			'data'       => null === $data ? null : wp_json_encode( $data ),
			'started_at' => gmdate( 'Y-m-d H:i:s', $started ),
			'last_seen'  => gmdate( 'Y-m-d H:i:s', $ended ),
			'ended_at'   => 'open' === $outcome ? null : gmdate( 'Y-m-d H:i:s', $ended ),
		)
	);
	++$journeys;
	if ( $cart_id ) {
		++$carts;
		if ( $converted ) {
			++$orders;
		}
	}
}
delete_transient( 'shopsocket_carts_pub' );
wp_cache_flush();
WP_CLI::success( "Seeded {$journeys} journeys, {$carts} carts ({$orders} converted), visitors 5eed0000-*." );

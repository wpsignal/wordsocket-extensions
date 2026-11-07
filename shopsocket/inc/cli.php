<?php
/**
 * WP-CLI: made-up shopper journeys, for a look at the board and at WordSocket's Journeys tab.
 *
 * - `wp shopsocket journeys seed [--count=<n>] [--days=<n>] [--seed=<n>]` writes journeys with the carts their shoppers held, some of them ordered.
 * - Seeded visitor ids start with WordSocket's seed prefix, so `wp wordsocket journeys unseed` deletes them.
 * - The same `--seed` draws the same visits again, written as new journeys.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

use WP_CLI;
use WPSignal\Journeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions.rand_mt_rand, WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- Made-up journeys, the same ones for the same seed.

/**
 * Journeys seeded when `--count` is not given.
 *
 * @since 0.8.0
 */
const SEED_COUNT = 500;

/**
 * Most journeys one seed call writes.
 *
 * @since 0.8.0
 */
const SEED_MAX_COUNT = 5000;

/**
 * Days the journeys are spread over when `--days` is not given.
 *
 * @since 0.8.0
 */
const SEED_DAYS = 28;

/**
 * Most days one seed call spreads its journeys over.
 *
 * @since 0.8.0
 */
const SEED_MAX_DAYS = 90;

/**
 * Most pages one seeded journey walks.
 *
 * @since 0.8.0
 */
const SEED_MAX_STEPS = 14;

/**
 * Register the commands. Loaded only while WP-CLI runs.
 *
 * @since 0.8.0
 *
 * @return void
 */
function register_cli(): void {
	WP_CLI::add_command(
		'shopsocket journeys seed',
		__NAMESPACE__ . '\seed_command',
		array(
			'shortdesc' => 'Write made-up shopper journeys with their carts, some of them ordered, for a look at the board.',
			'synopsis'  => array(
				array(
					'type'        => 'assoc',
					'name'        => 'count',
					'description' => 'How many journeys to seed.',
					'optional'    => true,
					'default'     => (string) SEED_COUNT,
				),
				array(
					'type'        => 'assoc',
					'name'        => 'days',
					'description' => 'How many days back to spread them.',
					'optional'    => true,
					'default'     => (string) SEED_DAYS,
				),
				array(
					'type'        => 'assoc',
					'name'        => 'seed',
					'description' => 'The number the made-up journeys are drawn from; another number makes other journeys.',
					'optional'    => true,
					'default'     => '500',
				),
			),
		)
	);
}

/**
 * `wp shopsocket journeys seed`: check the site and the arguments, write the journeys, and say what was written.
 *
 * @since 0.8.0
 *
 * @param array $args       Positional arguments.
 * @param array $assoc_args `count`, `days`, `seed`.
 * @return void
 */
function seed_command( array $args, array $assoc_args ): void {
	unset( $args );
	if ( 'production' === wp_get_environment_type() ) {
		WP_CLI::error( 'Refusing to seed made-up journeys on a production site.' );
	}
	if ( ! journeys_available() ) {
		WP_CLI::error( 'WordSocket 0.31 or later keeps the journeys; it is not active here.' );
	}
	$count = (int) ( $assoc_args['count'] ?? SEED_COUNT );
	if ( $count < 1 || $count > SEED_MAX_COUNT ) {
		WP_CLI::error( sprintf( '--count must be between 1 and %d.', SEED_MAX_COUNT ) );
	}
	$days = (int) ( $assoc_args['days'] ?? SEED_DAYS );
	if ( $days < 1 || $days > SEED_MAX_DAYS ) {
		WP_CLI::error( sprintf( '--days must be between 1 and %d.', SEED_MAX_DAYS ) );
	}
	$catalogue = seed_catalogue();
	if ( empty( $catalogue['products'] ) ) {
		WP_CLI::error( 'The shop has no published product with a price to put in a cart.' );
	}
	if ( ! history_enabled() || ! \WPSignal\WPS::journeys()->enabled() ) {
		WP_CLI::warning( 'History is off, so the board shows nothing of these journeys until it is on, in WordSocket\'s Settings tab.' );
	}
	$made = seed_journeys( $count, $days, (int) ( $assoc_args['seed'] ?? 500 ), $catalogue );
	WP_CLI::success(
		sprintf(
			'Seeded %d journeys over %d day(s): %d with a cart, %d of those ordered. Remove them with `wp wordsocket journeys unseed`.',
			$made['journeys'],
			$days,
			$made['carts'],
			$made['orders']
		)
	);
}

/**
 * What a seeded shopper can look at and buy: the published products with a price, and the product categories.
 *
 * @since 0.8.0
 *
 * @return array{products: int[], prices: array<int, float>, terms: int[]}
 */
function seed_catalogue(): array {
	$prices = array();
	foreach ( wc_get_products(
		array(
			'status' => 'publish',
			'limit'  => 200,
			'return' => 'objects',
		)
	) as $product ) {
		$price = (float) $product->get_price();
		if ( $price > 0 ) {
			$prices[ $product->get_id() ] = $price;
		}
	}
	$terms = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'fields'     => 'ids',
			'exclude'    => array( (int) get_option( 'default_product_cat', 0 ) ),
		)
	);
	return array(
		'products' => array_keys( $prices ),
		'prices'   => $prices,
		'terms'    => is_array( $terms ) ? array_map( 'intval', $terms ) : array(),
	);
}

/**
 * The walk a seeded shopper takes: where a visit starts, where each page kind leads, and how long each is looked at, in seconds.
 *
 * @since 0.8.0
 *
 * @return array{start: array<string, int>, next: array<string, array<string, int>>, dwell: array<string, int[]>}
 */
function seed_walk(): array {
	return array(
		'start' => array(
			'home'     => 30,
			'shop'     => 15,
			'category' => 15,
			'product'  => 35,
			'search'   => 5,
		),
		'next'  => array(
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
		),
		'dwell' => array(
			'home'     => array( 2, 8 ),
			'shop'     => array( 3, 20 ),
			'category' => array( 3, 15 ),
			'search'   => array( 3, 12 ),
			'product'  => array( 8, 180 ),
			'cart'     => array( 5, 90 ),
			'checkout' => array( 20, 300 ),
			'thanks'   => array( 3, 15 ),
			'away'     => array( 8, 900 ),
		),
	);
}

/**
 * A number from 0 up to, not including, 1.
 *
 * @since 0.8.0
 *
 * @return float
 */
function seed_chance(): float {
	return mt_rand() / ( mt_getrandmax() + 1 );
}

/**
 * One key of a table of weights, drawn by weight.
 *
 * @since 0.8.0
 *
 * @param array<int|string, int|float> $weights Weight per key.
 * @return string
 */
function seed_pick( array $weights ): string {
	$left = seed_chance() * array_sum( $weights );
	foreach ( $weights as $key => $weight ) {
		$left -= $weight;
		if ( $left <= 0 ) {
			return (string) $key;
		}
	}
	return (string) array_key_last( $weights );
}

/**
 * A v4 UUID starting with `$prefix`.
 *
 * @since 0.8.0
 *
 * @param string $prefix What the id starts with.
 * @return string
 */
function seed_uuid( string $prefix = '' ): string {
	return $prefix . substr( wp_generate_uuid4(), strlen( $prefix ) );
}

/**
 * One shopper's visit: the pages as `kind:id:s` steps, the products they put in the cart, and whether they ordered.
 *
 * @since 0.8.0
 *
 * @param array $walk      `seed_walk()`.
 * @param array $catalogue `seed_catalogue()`.
 * @return array{steps: string[], held: array<int, int>, converted: bool}
 */
function seed_visit( array $walk, array $catalogue ): array {
	$one_of    = static fn( array $ids ): int => $ids ? (int) $ids[ (int) floor( seed_chance() * count( $ids ) ) ] : 0;
	$id_of     = static fn( string $kind ): int => 'product' === $kind ? $one_of( $catalogue['products'] ) : ( 'category' === $kind ? $one_of( $catalogue['terms'] ) : 0 );
	$steps     = array();
	$held      = array();
	$converted = false;
	$away_from = null;
	$kind      = seed_pick( $walk['start'] );
	$id        = $id_of( $kind );
	for ( $n = 1; $n <= SEED_MAX_STEPS; $n++ ) {
		list( $low, $high ) = $walk['dwell'][ $kind ];
		// Short looks are common and long ones rare, as on a real page.
		$steps[] = "{$kind}:{$id}:" . max( 1, (int) round( exp( log( $low ) + seed_chance() * ( log( $high ) - log( $low ) ) ) ) );
		if ( 'product' === $kind && seed_chance() < 0.12 ) {
			$held[ $id ] = ( $held[ $id ] ?? 0 ) + 1;
		}
		if ( 'thanks' === $kind ) {
			$converted = true;
		}
		// The longer the visit, the likelier it ends here.
		if ( seed_chance() < ( $n < 2 ? 0.06 : 0.10 + 0.04 * ( $n - 2 ) ) ) {
			break;
		}
		if ( 'away' === $kind ) {
			list( $kind, $id ) = $away_from;
			$away_from         = null;
			continue;
		}
		$to = seed_pick( $walk['next'][ $kind ] );
		if ( 'cart' === $to && ! $held ) {
			$held[ $id ? $id : $catalogue['products'][0] ] = 1;
		}
		if ( 'checkout' === $to && ! $held ) {
			$to = 'shop';
		}
		if ( 'away' === $to ) {
			$away_from = array( $kind, $id );
			$kind      = 'away';
			$id        = 0;
			continue;
		}
		$id   = ( $to === $kind && seed_chance() < 0.3 ) ? $id : $id_of( $to );
		$kind = $to;
	}
	return array(
		'steps'     => $steps,
		'held'      => $held,
		'converted' => $converted,
	);
}

/**
 * Write `$count` made-up journeys spread over the last `$days` days, busier on weekdays, in the evening and lately, and say how many were written, held a cart and were ordered.
 *
 * @since 0.8.0
 *
 * @param int   $count     Journeys to write.
 * @param int   $days      Days back to spread them.
 * @param int   $seed      The number the journeys are drawn from.
 * @param array $catalogue `seed_catalogue()`.
 * @return array{journeys: int, carts: int, orders: int}
 */
function seed_journeys( int $count, int $days, int $seed, array $catalogue ): array {
	mt_srand( $seed );
	$walk     = seed_walk();
	$currency = get_woocommerce_currency();
	$prefix   = \WPSignal\Cli::SEED_PREFIX;
	$by_day   = array();
	for ( $d = 0; $d < $days; $d++ ) {
		$weekend      = in_array( (int) gmdate( 'w', time() - $d * DAY_IN_SECONDS ), array( 0, 6 ), true );
		$by_day[ $d ] = ( 1 + 0.675 * ( $days - 1 - $d ) / max( 1, $days - 1 ) ) * ( $weekend ? 0.7 : 1 );
	}
	$by_hour = array( 1, 0.6, 0.4, 0.3, 0.3, 0.5, 1.2, 2.5, 3.5, 3.5, 3.2, 3, 3.5, 3.2, 3, 2.8, 2.8, 3, 3.5, 4, 4.5, 4.2, 3.2, 2 );
	$made    = array(
		'journeys' => 0,
		'carts'    => 0,
		'orders'   => 0,
	);
	for ( $n = 0; $n < $count; $n++ ) {
		$visitor = seed_uuid( $prefix );
		$visit   = seed_visit( $walk, $catalogue );
		$seconds = array_sum( array_map( static fn( string $step ): int => (int) explode( ':', $step )[2], $visit['steps'] ) );
		$day     = gmdate( 'Y-m-d', time() - (int) seed_pick( $by_day ) * DAY_IN_SECONDS );
		$started = (int) strtotime( sprintf( '%s %02d:%02d:%02d UTC', $day, (int) seed_pick( $by_hour ), mt_rand( 0, 59 ), mt_rand( 0, 59 ) ) );
		// An hour of today that has not come yet falls on the day before the oldest instead, so the last `$days` days of 24 hours are evenly filled.
		if ( $started > time() - MINUTE_IN_SECONDS ) {
			$started -= $days * DAY_IN_SECONDS;
		}
		$held  = $visit['held'];
		$value = 0.0;
		$data  = null;
		if ( $held ) {
			$items = array();
			foreach ( $held as $product_id => $quantity ) {
				$value                        += $catalogue['prices'][ $product_id ] * $quantity;
				$items[ (string) $product_id ] = $quantity;
			}
			$data = array(
				'c' => $currency,
				'i' => $items,
			);
			if ( $visit['converted'] ) {
				$data['o'] = 1000 + $n;
			}
		}
		$written = (bool) Journeys::insert_row(
			array(
				'journey_id' => seed_uuid(),
				'visitor'    => $visitor,
				'ref'        => $held ? substr( md5( $visitor ), 0, 16 ) : null,
				'journey'    => implode( '>', $visit['steps'] ),
				'steps'      => count( $visit['steps'] ),
				// Nothing seeded is live, so nothing is open.
				'outcome'    => $visit['converted'] ? 'converted' : 'left',
				'value'      => $held ? round( $value, 4 ) : null,
				'data'       => $data,
				'started_at' => gmdate( 'Y-m-d H:i:s', $started ),
				'last_seen'  => gmdate( 'Y-m-d H:i:s', min( time(), $started + $seconds ) ),
			)
		);
		if ( ! $written ) {
			continue;
		}
		++$made['journeys'];
		if ( $held ) {
			++$made['carts'];
			$made['orders'] += $visit['converted'] ? 1 : 0;
		}
	}
	forget( 'carts_pub' );
	invalidate_cart_caches();
	wp_cache_delete( 'day_strip', 'shopsocket' );
	foreach ( ABANDONED_WINDOWS as $hours ) {
		wp_cache_delete( 'abandonment_' . $hours, 'shopsocket' );
	}
	delete_transient( Journeys::VISUALS_TRANSIENT );
	return $made;
}

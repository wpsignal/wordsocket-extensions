<?php
/**
 * Cart history: the three tables, the lifecycle writes, the journeys
 * archive, and the prune.
 *
 * - `{prefix}shopsocket_carts` and `_cart_items` are the live set: one row per
 *   cart (`cart_id()`), rebuilt from the shopper's own requests. They replace
 *   the `shopsocket_carts` transient, whose read-modify-write let two shoppers
 *   adding at once overwrite each other.
 * - `{prefix}shopsocket_journeys` is append-only and anonymized at write:
 *   visitor and cart ids, ids of posts and terms, durations and money, never
 *   a user id. Written only while `history_enabled` is on.
 * - Every write happens in the shopper's request: the cart hooks, the
 *   `/cart-id` fetch, WordSocket's visit and leave reports
 *   (`wpsignal_visitor_visit` / `wpsignal_visitor_leave`) and the checkout
 *   hooks. No job of ShopSocket's own: the prune rides WooCommerce's
 *   `woocommerce_cleanup_sessions`.
 * - Abandonment is judged on the board: a left cart whose shopper is not
 *   present and whose leave is older than the board's grace. With history off the tables hold exactly what the
 *   transient held, for the session lifetime, with no user or visitor ids.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- The plugin's own tables: names come from the table functions, fragments from prepare(), one placeholder per id.

const DB_VERSION        = '1';
const DB_VERSION_OPTION = 'shopsocket_db_version';

/** Rows the board lists under "Recently abandoned". */
const BOARD_ABANDONED = 50;

/** Rows one prune statement deletes before checking the clock. */
const PRUNE_BATCH = 1000;

/**
 * The carts table name.
 *
 * @return string
 */
function carts_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'shopsocket_carts';
}

/**
 * The cart items table name.
 *
 * @return string
 */
function cart_items_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'shopsocket_cart_items';
}

/**
 * The journeys table name.
 *
 * @return string
 */
function journeys_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'shopsocket_journeys';
}

/**
 * Create or update the tables once per DB_VERSION, WooCommerce's way.
 *
 * @return void
 */
function install(): void {
	if ( get_option( DB_VERSION_OPTION ) === DB_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$collate = $wpdb->get_charset_collate();
	$carts   = carts_table();
	$items   = cart_items_table();
	$journey = journeys_table();
	dbDelta(
		"CREATE TABLE {$carts} (
			cart_id char(16) NOT NULL,
			user_id bigint(20) unsigned NULL,
			visitor char(36) NULL,
			journey_id char(36) NULL,
			journey text NULL,
			currency char(3) NOT NULL DEFAULT '',
			value decimal(13,4) NOT NULL DEFAULT 0,
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			left_at datetime NULL,
			converted_at datetime NULL,
			order_id bigint(20) unsigned NULL,
			PRIMARY KEY  (cart_id),
			KEY last_seen (last_seen),
			KEY left_at (left_at),
			KEY order_id (order_id)
		) {$collate};
		CREATE TABLE {$items} (
			cart_id char(16) NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			quantity int(11) unsigned NOT NULL DEFAULT 1,
			PRIMARY KEY  (cart_id, product_id),
			KEY product_id (product_id)
		) {$collate};
		CREATE TABLE {$journey} (
			journey_id char(36) NOT NULL,
			visitor char(36) NOT NULL,
			cart_id char(16) NULL,
			journey text NULL,
			steps smallint unsigned NOT NULL DEFAULT 0,
			outcome varchar(10) NOT NULL DEFAULT 'open',
			value decimal(13,4) NULL,
			order_id bigint(20) unsigned NULL,
			started_at datetime NOT NULL,
			last_seen datetime NOT NULL,
			ended_at datetime NULL,
			PRIMARY KEY  (journey_id),
			KEY visitor (visitor),
			KEY cart_id (cart_id),
			KEY started_at (started_at),
			KEY outcome (outcome)
		) {$collate};"
	);
	update_option( DB_VERSION_OPTION, DB_VERSION, false );
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\install' );

/**
 * Whether the merchant keeps a history of carts beyond the session.
 *
 * @return bool
 */
function history_enabled(): bool {
	return (bool) settings()['history_enabled'];
}

/**
 * Days a cart row outlives its leave or conversion while history is on.
 *
 * @return int
 */
function retention_days(): int {
	return (int) settings()['history_retention_days'];
}

/**
 * Months a journey row is kept while history is on.
 *
 * @return int
 */
function journeys_retention_months(): int {
	return (int) settings()['journeys_retention_months'];
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
 * Write the shopper's cart row and its items from their real cart.
 *
 * @param string $id      Cart id.
 * @param array  $state   `current_cart_state()`, non-null.
 * @param string $visitor The browser's visitor id when a report carried one, else ''.
 * @param bool   $restart Whether a converted row starts over as a new cart: only a real add to cart says so.
 * @return void
 */
function upsert_cart_row( string $id, array $state, string $visitor = '', bool $restart = false ): void {
	global $wpdb;
	$now      = now_sql();
	$user_sql = history_enabled() && is_user_logged_in() ? (string) get_current_user_id() : 'NULL';

	/*
	 * The visitor id is only known from a tracker report; a cart hook keeps
	 * what is stored. History off writes NULL. `prepare()` turns null into '',
	 * so the NULL and the column name are spelled out; the id itself is prepared.
	 */
	if ( ! history_enabled() ) {
		$visitor_sql = 'NULL';
	} elseif ( '' !== $visitor ) {
		$visitor_sql = $wpdb->prepare( '%s', $visitor );
	} else {
		$visitor_sql = 'visitor';
	}

	/*
	 * A converted row is the record of its order. Only an add to cart starts
	 * it over (first seen now, no leave, no journey, no order): a visit or
	 * leave report never does, because the checkout page's own report can
	 * land in the same second as the order and must not erase the conversion.
	 */
	$fresh = $restart
		? array(
			'visitor'      => 'IF(converted_at IS NULL, ' . $visitor_sql . ', ' . ( 'visitor' === $visitor_sql ? 'NULL' : $visitor_sql ) . ')',
			'first_seen'   => 'IF(converted_at IS NULL, first_seen, VALUES(first_seen))',
			'left_at'      => 'IF(converted_at IS NULL, left_at, NULL)',
			'journey'      => 'IF(converted_at IS NULL, journey, NULL)',
			'journey_id'   => 'IF(converted_at IS NULL, journey_id, NULL)',
			'order_id'     => 'IF(converted_at IS NULL, order_id, NULL)',
			'converted_at' => 'NULL',
		)
		: array(
			'visitor'      => $visitor_sql,
			'first_seen'   => 'first_seen',
			'left_at'      => 'left_at',
			'journey'      => 'journey',
			'journey_id'   => 'journey_id',
			'order_id'     => 'order_id',
			'converted_at' => 'converted_at',
		);
	$wpdb->query(
		$wpdb->prepare(
			'INSERT INTO ' . carts_table() . ' (cart_id, user_id, visitor, currency, value, first_seen, last_seen)
			VALUES (%s, ' . $user_sql . ', NULL, %s, %f, %s, %s)
			ON DUPLICATE KEY UPDATE
				user_id = ' . $user_sql . ',
				visitor = ' . $fresh['visitor'] . ',
				currency = VALUES(currency),
				value = VALUES(value),
				first_seen = ' . $fresh['first_seen'] . ',
				last_seen = VALUES(last_seen),
				left_at = ' . $fresh['left_at'] . ',
				journey = ' . $fresh['journey'] . ',
				journey_id = ' . $fresh['journey_id'] . ',
				order_id = ' . $fresh['order_id'] . ',
				converted_at = ' . $fresh['converted_at'],
			$id,
			get_woocommerce_currency(),
			$state['value'],
			$now,
			$now
		)
	);
	$wpdb->delete( cart_items_table(), array( 'cart_id' => $id ), array( '%s' ) );
	foreach ( $state['quantities'] as $product_id => $quantity ) {
		$wpdb->insert(
			cart_items_table(),
			array(
				'cart_id'    => $id,
				'product_id' => (int) $product_id,
				'quantity'   => max( 1, (int) $quantity ),
			),
			array( '%s', '%d', '%d' )
		);
	}
	invalidate_cart_caches();
}

/** The object-cache group for the counts below. */
const CACHE_GROUP = 'shopsocket';

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
 * Forget every cached count: called after any write to the cart tables. A
 * fresh token rather than an increment, because `wp_cache_incr()` differs
 * between drop-ins (one left the generation at 0 and the counts stale).
 *
 * @return void
 */
function invalidate_cart_caches(): void {
	wp_cache_set( 'carts_generation', uniqid( '', true ), CACHE_GROUP );
}

/**
 * Delete a cart row and its items.
 *
 * @param string $id Cart id.
 * @return bool Whether a row was deleted.
 */
function delete_cart_row( string $id ): bool {
	global $wpdb;
	$deleted = (int) $wpdb->delete( carts_table(), array( 'cart_id' => $id ), array( '%s' ) );
	$wpdb->delete( cart_items_table(), array( 'cart_id' => $id ), array( '%s' ) );
	invalidate_cart_caches();
	return $deleted > 0;
}

/**
 * One cart row as stored, or null.
 *
 * @param string $id Cart id.
 * @return array<string, mixed>|null
 */
function cart_row( string $id ): ?array {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . carts_table() . ' WHERE cart_id = %s', $id ), ARRAY_A );
	return is_array( $row ) ? $row : null;
}

/**
 * Items for a set of carts, as `cart_id => [ product_id => quantity ]`.
 *
 * @param string[] $ids Cart ids.
 * @return array<string, array<int, int>>
 */
function cart_items( array $ids ): array {
	global $wpdb;
	if ( empty( $ids ) ) {
		return array();
	}
	$holders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
	$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT cart_id, product_id, quantity FROM ' . cart_items_table() . " WHERE cart_id IN ({$holders}) ORDER BY product_id", ...$ids ), ARRAY_A );
	$items   = array_fill_keys( $ids, array() );
	foreach ( (array) $rows as $row ) {
		$items[ (string) $row['cart_id'] ][ (int) $row['product_id'] ] = max( 1, (int) $row['quantity'] );
	}
	return $items;
}

/**
 * The shopper left the page: stamp the cart row and, with history on, the journey.
 *
 * @param string $id Cart id.
 * @return void
 */
function mark_left( string $id ): void {
	global $wpdb;
	if ( '' === $id ) {
		return;
	}
	$wpdb->update( carts_table(), array( 'left_at' => now_sql() ), array( 'cart_id' => $id ), array( '%s' ), array( '%s' ) );
	publish_carts_changed();
}

/**
 * The shopper checked out: stamp the order on the cart row and close the journey as converted.
 *
 * @param string $id       Cart id.
 * @param int    $order_id The order.
 * @param float  $total    The order total, kept on the journey.
 * @return void
 */
function mark_converted( string $id, int $order_id, float $total ): void {
	global $wpdb;
	if ( '' === $id || $order_id <= 0 ) {
		return;
	}
	$now = now_sql();
	$wpdb->update(
		carts_table(),
		array(
			'order_id'     => $order_id,
			'converted_at' => $now,
		),
		array( 'cart_id' => $id ),
		array( '%d', '%s' ),
		array( '%s' )
	);
	invalidate_cart_caches();
	if ( ! history_enabled() ) {
		return;
	}
	$wpdb->query(
		$wpdb->prepare(
			'UPDATE ' . journeys_table() . " SET outcome = 'converted', order_id = %d, value = %f, ended_at = %s, last_seen = %s WHERE cart_id = %s AND outcome <> 'converted' AND ended_at IS NULL",
			$order_id,
			$total,
			$now,
			$now,
			$id
		)
	);
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
 * Write a WordSocket visit or leave report against the shopper's cart.
 *
 * @param array $report `{ visitor, journey_id, journey, steps, user_id, data, leaving }` from WordSocket.
 * @return void
 */
function on_visitor_report( array $report ): void {
	global $wpdb;
	if ( ! history_enabled() ) {
		return;
	}
	if ( ( has_wc_session_cookie() || is_user_logged_in() ) && function_exists( 'wc_load_cart' ) && ( ! function_exists( 'WC' ) || ! WC()->cart ) ) {
		wc_load_cart();
	}
	$cart_id = cart_id();
	$state   = current_cart_state();
	// A cartless visitor writes nothing; their journey arrives with the cart when one appears.
	if ( '' === $cart_id || null === $state ) {
		return;
	}
	$visitor    = (string) $report['visitor'];
	$journey_id = (string) $report['journey_id'];
	$journey    = (string) $report['journey'];
	$steps      = (int) $report['steps'];
	$leaving    = (bool) $report['leaving'];
	$now        = now_sql();

	upsert_cart_row( $cart_id, $state, $visitor );
	// The live copy on the cart row: the newest journey, by step count.
	$wpdb->query(
		$wpdb->prepare(
			'UPDATE ' . carts_table() . ' SET journey = %s, journey_id = %s WHERE cart_id = %s AND (journey_id IS NULL OR journey_id <> %s OR journey IS NULL OR CHAR_LENGTH(journey) <= CHAR_LENGTH(%s))',
			$journey,
			$journey_id,
			$cart_id,
			$journey_id,
			$journey
		)
	);

	/*
	 * The archive row: created by the first report, changed only by a report
	 * at least as far along (the leave of page N races the visit of page N+1),
	 * and never reopened once converted.
	 */
	$outcome = $leaving ? 'left' : 'open';
	$ended   = $leaving ? $wpdb->prepare( '%s', $now ) : 'NULL';
	$wpdb->query(
		$wpdb->prepare(
			'INSERT INTO ' . journeys_table() . ' (journey_id, visitor, cart_id, journey, steps, outcome, value, started_at, last_seen, ended_at)
			VALUES (%s, %s, %s, %s, %d, %s, %f, %s, %s, ' . $ended . ')
			ON DUPLICATE KEY UPDATE
				cart_id = VALUES(cart_id),
				value = VALUES(value),
				last_seen = IF(VALUES(steps) >= steps, VALUES(last_seen), last_seen),
				journey = IF(VALUES(steps) >= steps, VALUES(journey), journey),
				outcome = IF(outcome = \'converted\', outcome, IF(VALUES(steps) >= steps, VALUES(outcome), outcome)),
				ended_at = IF(outcome = \'converted\', ended_at, IF(VALUES(steps) >= steps, VALUES(ended_at), ended_at)),
				steps = GREATEST(steps, VALUES(steps))',
			$journey_id,
			$visitor,
			$cart_id,
			$journey,
			$steps,
			$outcome,
			$state['value'],
			$now,
			$now
		)
	);
	if ( $leaving ) {
		return;
	}
	// A new visit ends the visitor's other open journeys; a leave never does (the two reports race).
	$wpdb->query(
		$wpdb->prepare(
			'UPDATE ' . journeys_table() . " SET outcome = 'left', ended_at = last_seen WHERE visitor = %s AND journey_id <> %s AND ended_at IS NULL",
			$visitor,
			$journey_id
		)
	);
}
add_action( 'wpsignal_visitor_visit', __NAMESPACE__ . '\on_visitor_report' );
add_action( 'wpsignal_visitor_leave', __NAMESPACE__ . '\on_visitor_report' );

// The leave stamps the cart row whether or not history is on: it is what the transient never knew.
add_action(
	'wpsignal_visitor_leave',
	static function (): void {
		if ( ( has_wc_session_cookie() || is_user_logged_in() ) && function_exists( 'wc_load_cart' ) && ( ! function_exists( 'WC' ) || ! WC()->cart ) ) {
			wc_load_cart();
		}
		mark_left( cart_id() );
	},
	5
);

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
 * The carts whose shopper left, newest leave first, for the board's "Recently abandoned" panel.
 *
 * The leave stamp survives a return (only a new cart after a conversion clears
 * it), so the board can show the order a left cart later became. Whether the
 * shopper is back right now, and whether the leave is older than the grace,
 * is the board's to judge: it has presence and its own clock.
 *
 * @return array<int, array<string, mixed>>
 */
function abandoned_rows(): array {
	global $wpdb;
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT cart_id, value, currency, journey, left_at, converted_at, order_id FROM ' . carts_table() . ' WHERE left_at IS NOT NULL ORDER BY left_at DESC LIMIT %d',
			BOARD_ABANDONED
		),
		ARRAY_A
	);
	$rows  = (array) $rows;
	$items = cart_items( array_map( static fn( array $row ): string => (string) $row['cart_id'], $rows ) );
	$out   = array();
	foreach ( $rows as $row ) {
		$id       = (string) $row['cart_id'];
		$order_id = (int) ( $row['order_id'] ?? 0 );
		$out[]    = array(
			'id'           => $id,
			'products'     => array_keys( $items[ $id ] ?? array() ),
			'quantities'   => (object) ( $items[ $id ] ?? array() ),
			'value'        => round( (float) $row['value'], 2 ),
			'currency'     => (string) $row['currency'],
			'journey'      => (string) ( $row['journey'] ?? '' ),
			'left_at'      => datetime_to_rfc3339( (string) $row['left_at'] ),
			'converted_at' => null === $row['converted_at'] ? null : datetime_to_rfc3339( (string) $row['converted_at'] ),
			'order_id'     => $order_id > 0 ? $order_id : null,
			'order_url'    => $order_id > 0 ? order_edit_url( $order_id ) : '',
		);
	}
	return $out;
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

/**
 * Rows a user can be identified by, for the privacy exporter and eraser.
 *
 * @param int $user_id The user.
 * @return string[] Cart ids.
 */
function cart_ids_for_user( int $user_id ): array {
	global $wpdb;
	if ( $user_id <= 0 ) {
		return array();
	}
	return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT cart_id FROM ' . carts_table() . ' WHERE user_id = %d', $user_id ) ) );
}

/**
 * Delete everything that names a user: their cart rows and items, and the
 * journeys reachable through those carts.
 *
 * @param int $user_id The user.
 * @return int Rows removed across the three tables.
 */
function erase_user( int $user_id ): int {
	global $wpdb;
	$ids = cart_ids_for_user( $user_id );
	if ( empty( $ids ) ) {
		return 0;
	}
	$holders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
	$removed = 0;
	foreach ( array( journeys_table(), cart_items_table(), carts_table() ) as $table ) {
		$removed += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE cart_id IN ({$holders})", ...$ids ) );
	}
	return $removed;
}

/**
 * Prune on WooCommerce's session cleanup (every 12 hours, already scheduled).
 *
 * - History off: cart rows past the session lifetime, and every journey.
 * - History on: cart rows past the retention after they were left or
 *   converted (or last seen, for a crash with no leave), journeys past their
 *   own retention since they started.
 *
 * @return void
 */
function prune(): void {
	global $wpdb;
	$carts    = carts_table();
	$items    = cart_items_table();
	$journeys = journeys_table();
	if ( history_enabled() ) {
		$cart_cutoff    = gmdate( 'Y-m-d H:i:s', time() - retention_days() * DAY_IN_SECONDS );
		$journey_cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . journeys_retention_months() . ' months', time() ) );
		$cart_where     = $wpdb->prepare( 'COALESCE(converted_at, left_at, last_seen) < %s', $cart_cutoff );
		$journey_where  = $wpdb->prepare( 'started_at < %s', $journey_cutoff );
	} else {
		$cart_where    = $wpdb->prepare( 'last_seen < %s', gmdate( 'Y-m-d H:i:s', time() - cart_lifetime() ) );
		$journey_where = '1 = 1';
	}
	// Items first, through their carts, so no orphan survives a batch boundary.
	$wpdb->query( "DELETE i FROM {$items} i INNER JOIN {$carts} c ON c.cart_id = i.cart_id WHERE {$cart_where}" );
	$wpdb->query( "DELETE FROM {$carts} WHERE {$cart_where}" );
	invalidate_cart_caches();
	$started = microtime( true );
	do {
		$deleted = (int) $wpdb->query( "DELETE FROM {$journeys} WHERE {$journey_where} LIMIT " . PRUNE_BATCH );
	} while ( $deleted >= PRUNE_BATCH && microtime( true ) - $started < 2 );
}
add_action( 'woocommerce_cleanup_sessions', __NAMESPACE__ . '\prune' );

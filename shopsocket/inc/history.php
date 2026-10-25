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
 * - The visitor and journey ids in a report are the browser's word: a cart
 *   starts at most NEW_JOURNEYS_PER_HOUR journey rows an hour, and a journey
 *   row changes only for reports carrying the visitor id it was created under.
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

const DB_VERSION        = '3';
const DB_VERSION_OPTION = 'shopsocket_db_version';

/** Rows the board lists under "Recently abandoned". */
const BOARD_ABANDONED = 50;

/** Rows one prune statement deletes before checking the clock. */
const PRUNE_BATCH = 1000;

/** Journey rows one cart may start in an hour; a tab is a journey, a return after 30 minutes another. */
const NEW_JOURNEYS_PER_HOUR = 30;

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
 * @since 0.8.0 Version 3 adds `user_id` to the journeys.
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
			user_id bigint(20) unsigned NULL,
			cart_id char(16) NULL,
			journey text NULL,
			steps smallint unsigned NOT NULL DEFAULT 0,
			outcome varchar(10) NOT NULL DEFAULT 'open',
			value decimal(13,4) NULL,
			order_id bigint(20) unsigned NULL,
			started_at datetime NOT NULL,
			last_seen datetime NOT NULL,
			ended_at datetime NULL,
			firings text NULL,
			PRIMARY KEY  (journey_id),
			KEY visitor (visitor),
			KEY user_id (user_id),
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
 * The shopper left the page: stamp the cart row, and on a cart's first leave fire `shopsocket_cart_left` with the row and its items.
 *
 * @since 0.8.0 Fires `shopsocket_cart_left`.
 *
 * @param string $id Cart id.
 * @return void
 */
function mark_left( string $id ): void {
	global $wpdb;
	if ( '' === $id ) {
		return;
	}
	$before = cart_row( $id );
	$wpdb->update( carts_table(), array( 'left_at' => now_sql() ), array( 'cart_id' => $id ), array( '%s' ), array( '%s' ) );
	publish_carts_changed();
	if ( null === $before || null !== $before['left_at'] ) {
		return;
	}
	$row = cart_row( $id );
	if ( null === $row ) {
		return;
	}
	/**
	 * A cart's shopper left for the first time.
	 *
	 * @since 0.8.0
	 *
	 * @param string              $id    The cart id.
	 * @param array<string,mixed> $row   The cart row: `user_id` (null for a guest), `visitor`, `currency`, `value`, `first_seen`, `last_seen`, `left_at`, `journey`.
	 * @param array<int,int>      $items The cart's items as `product_id => quantity`.
	 */
	do_action( 'shopsocket_cart_left', $id, $row, cart_items( array( $id ) )[ $id ] ?? array() );
}

/**
 * The shopper checked out: stamp the order on the cart row and close the journey as converted.
 *
 * @since 0.8.0 Credits the cart's firings with the order.
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
	attribute_conversion( $id, $order_id, $now, $total );
}

/**
 * Stamp the order on every moment that fired for this cart within the attribution window, across its journeys.
 *
 * @since 0.8.0
 *
 * @param string $cart_id  The cart that converted.
 * @param int    $order_id The order.
 * @param string $now      The conversion time, SQL UTC.
 * @param float  $value    The order total.
 * @return int Firings stamped.
 */
function attribute_conversion( string $cart_id, int $order_id, string $now, float $value = 0 ): int {
	global $wpdb;
	$window  = max( 0, (int) apply_filters( 'shopsocket_attribution_window', 7 * DAY_IN_SECONDS ) );
	$since   = gmdate( 'Y-m-d H:i:s', time() - $window );
	$rows    = (array) $wpdb->get_results(
		$wpdb->prepare( 'SELECT journey_id, firings FROM ' . journeys_table() . ' WHERE cart_id = %s AND firings IS NOT NULL', $cart_id ),
		ARRAY_A
	);
	$stamped = 0;
	foreach ( $rows as $row ) {
		$firings = json_decode( (string) $row['firings'], true );
		if ( ! is_array( $firings ) ) {
			continue;
		}
		$changed = false;
		foreach ( $firings as &$firing ) {
			// Only a firing earns the order.
			if ( ! is_array( $firing ) || isset( $firing['order'] ) || 'fired' !== (string) ( $firing['kind'] ?? 'fired' ) || (string) ( $firing['at'] ?? '' ) < $since ) {
				continue;
			}
			$firing['order']    = $order_id;
			$firing['order_at'] = $now;
			$firing['value']    = round( $value, 2 );
			$changed            = true;
			++$stamped;
		}
		unset( $firing );
		if ( $changed ) {
			$wpdb->update( journeys_table(), array( 'firings' => wp_json_encode( $firings ) ), array( 'journey_id' => $row['journey_id'] ), array( '%s' ), array( '%s' ) );
		}
	}
	return $stamped;
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
 * The shopper's cart behind a bare REST report (a visit, a leave, a firing), from the session cookie or the login: `[ cart_id, state ]`, or null when there is no cart or it is empty.
 *
 * @return array{0: string, 1: array}|null
 */
function report_cart(): ?array {
	if ( ( has_wc_session_cookie() || is_user_logged_in() ) && function_exists( 'wc_load_cart' ) && ( ! function_exists( 'WC' ) || ! WC()->cart ) ) {
		wc_load_cart();
	}
	$cart_id = cart_id();
	$state   = current_cart_state();
	if ( '' === $cart_id || null === $state ) {
		return null;
	}
	return array( $cart_id, $state );
}

/**
 * Whether a journey id may take a row: one already on file always may, a new one only while the cart has started fewer than NEW_JOURNEYS_PER_HOUR in the last hour.
 *
 * @param string $cart_id    The cart.
 * @param string $journey_id The journey.
 * @return bool
 */
function may_start_journey( string $cart_id, string $journey_id ): bool {
	global $wpdb;

	/*
	 * A journey not yet on file costs a row, and the ids are the browser's word: a cart starts at most
	 * NEW_JOURNEYS_PER_HOUR an hour, so a loop of fresh ids cannot fill the table.
	 */
	$known = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT journey_id FROM ' . journeys_table() . ' WHERE journey_id = %s', $journey_id ) );
	if ( '' !== $known ) {
		return true;
	}
	$recent = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM ' . journeys_table() . ' WHERE cart_id = %s AND started_at >= %s',
			$cart_id,
			gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
		)
	);
	return $recent < NEW_JOURNEYS_PER_HOUR;
}

/**
 * Start a journey row with no steps yet, for a report that arrives before any visit has: the next visit or leave fills the journey in and keeps this start.
 *
 * @since 0.8.0 Takes the shopper's user id.
 *
 * @param string $visitor    The visitor id.
 * @param string $journey_id The journey id.
 * @param int    $user_id    The logged-in shopper, or 0.
 * @return bool Whether a row exists for the journey afterwards.
 */
function start_journey( string $visitor, string $journey_id, int $user_id = 0 ): bool {
	global $wpdb;
	if ( ! history_enabled() || '' === $journey_id ) {
		return false;
	}
	$cart = report_cart();
	if ( null === $cart || ! may_start_journey( $cart[0], $journey_id ) ) {
		return false;
	}
	list( $cart_id, $state ) = $cart;
	upsert_cart_row( $cart_id, $state, $visitor );
	$now = now_sql();
	$wpdb->query(
		$wpdb->prepare(
			'INSERT IGNORE INTO ' . journeys_table() . ' (journey_id, visitor, user_id, cart_id, journey, steps, outcome, value, started_at, last_seen)
			VALUES (%s, %s, ' . user_sql( $user_id ) . ", %s, '', 0, 'open', %f, %s, %s)",
			$journey_id,
			$visitor,
			$cart_id,
			$state['value'],
			$now,
			$now
		)
	);
	return '' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT journey_id FROM ' . journeys_table() . ' WHERE journey_id = %s', $journey_id ) );
}

/**
 * The user id as SQL: the id, or NULL for a guest.
 *
 * @since 0.8.0
 *
 * @param int $user_id The user, or 0.
 * @return string
 */
function user_sql( int $user_id ): string {
	return $user_id > 0 ? (string) $user_id : 'NULL';
}

/**
 * Write a WordSocket visit or leave report against the shopper's cart.
 *
 * @since 0.8.0 Stamps the shopper's user id on the journey while they are logged in.
 *
 * @param array $report `{ visitor, journey_id, journey, steps, user_id, data, leaving }` from WordSocket.
 * @return void
 */
function on_visitor_report( array $report ): void {
	global $wpdb;
	if ( ! history_enabled() ) {
		return;
	}
	$cart = report_cart();
	// A cartless visitor writes nothing; their journey arrives with the cart when one appears.
	if ( null === $cart ) {
		return;
	}
	list( $cart_id, $state ) = $cart;
	$visitor                 = (string) $report['visitor'];
	$journey_id              = (string) $report['journey_id'];
	$journey                 = (string) $report['journey'];
	$steps                   = (int) $report['steps'];
	$leaving                 = (bool) $report['leaving'];
	$user                    = user_sql( (int) ( $report['user_id'] ?? 0 ) );
	$now                     = now_sql();

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

	if ( ! may_start_journey( $cart_id, $journey_id ) ) {
		return;
	}

	/*
	 * The archive row: created by the first report, changed only by a report
	 * from the visitor it was created under (`$owned`) that is at least as far
	 * along (the leave of page N races the visit of page N+1), and never
	 * reopened once converted. The user id is set while the shopper is logged
	 * in and kept afterwards. Assignments run in order, so `steps` is last.
	 */
	$outcome = $leaving ? 'left' : 'open';
	$ended   = $leaving ? $wpdb->prepare( '%s', $now ) : 'NULL';
	$owned   = 'visitor = VALUES(visitor)';
	$ahead   = $owned . ' AND VALUES(steps) >= steps';
	$wpdb->query(
		$wpdb->prepare(
			'INSERT INTO ' . journeys_table() . ' (journey_id, visitor, user_id, cart_id, journey, steps, outcome, value, started_at, last_seen, ended_at)
			VALUES (%s, %s, ' . $user . ', %s, %s, %d, %s, %f, %s, %s, ' . $ended . ')
			ON DUPLICATE KEY UPDATE
				user_id = IF(' . $owned . ' AND VALUES(user_id) IS NOT NULL, VALUES(user_id), user_id),
				cart_id = IF(' . $owned . ', VALUES(cart_id), cart_id),
				value = IF(' . $owned . ', VALUES(value), value),
				last_seen = IF(' . $ahead . ', VALUES(last_seen), last_seen),
				journey = IF(' . $ahead . ', VALUES(journey), journey),
				outcome = IF(outcome = \'converted\' OR NOT (' . $ahead . '), outcome, VALUES(outcome)),
				ended_at = IF(outcome = \'converted\' OR NOT (' . $ahead . '), ended_at, VALUES(ended_at)),
				steps = IF(' . $owned . ', GREATEST(steps, VALUES(steps)), steps)',
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
			'SELECT cart_id, user_id, value, currency, journey, left_at, converted_at, order_id FROM ' . carts_table() . ' WHERE left_at IS NOT NULL ORDER BY left_at DESC LIMIT %d',
			BOARD_ABANDONED
		),
		ARRAY_A
	);
	$rows  = (array) $rows;
	$items = cart_items( array_map( static fn( array $row ): string => (string) $row['cart_id'], $rows ) );
	$names = customer_names( array_map( 'intval', array_column( $rows, 'user_id' ) ) );
	$out   = array();
	foreach ( $rows as $row ) {
		$id       = (string) $row['cart_id'];
		$order_id = (int) ( $row['order_id'] ?? 0 );
		$user_id  = (int) ( $row['user_id'] ?? 0 );
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
			'user_id'      => $user_id > 0 ? $user_id : null,
			'customer'     => $names[ $user_id ] ?? null,
			'customer_url' => $user_id > 0 ? customer_edit_url( $user_id ) : '',
		);
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
	$ids = array_values( array_unique( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) );
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

/**
 * Journeys for the Moments tab's replay: the archive plus the live rows' copies, newest first, with the outcome and whether a cart was there.
 *
 * @since 0.8.0 Returns each journey's `firings`.
 *
 * @param int $days  How far back.
 * @param int $limit At most this many.
 * @return array<int, array{journey: string, started_at: string, outcome: string, value: float, has_cart: bool, context: array, firings: array}>
 */
function journeys_for_replay( int $days, int $limit ): array {
	global $wpdb;
	if ( ! history_enabled() ) {
		return array();
	}
	$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
	$rows  = (array) $wpdb->get_results(
		$wpdb->prepare(
			'SELECT journey, started_at, outcome, value, cart_id, firings FROM ' . journeys_table() . ' WHERE started_at >= %s ORDER BY started_at DESC LIMIT %d',
			$since,
			max( 1, $limit )
		),
		ARRAY_A
	);
	return array_map(
		static function ( array $row ): array {
			$value   = round( (float) ( $row['value'] ?? 0 ), 2 );
			$has     = '' !== (string) ( $row['cart_id'] ?? '' );
			$firings = json_decode( (string) ( $row['firings'] ?? '' ), true );
			return array(
				'journey'    => (string) $row['journey'],
				'started_at' => datetime_to_rfc3339( (string) $row['started_at'] ),
				'outcome'    => (string) $row['outcome'],
				'value'      => $value,
				'has_cart'   => $has,
				// Firings, with any order a later conversion stamped on them.
				'firings'    => is_array( $firings ) ? array_values( $firings ) : array(),
				// What the cart conditions can know from a stored journey: items were there, and what they came to.
				'context'    => array(
					'cart' => array(
						'count'    => $has ? 1 : 0,
						'total'    => $value,
						'products' => array(),
						'product'  => '',
					),
				),
			);
		},
		$rows
	);
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
 * A user's journeys, oldest first, for the privacy exporter.
 *
 * @since 0.8.0
 *
 * @param int $user_id The user.
 * @return array<int, array<string, mixed>>
 */
function journeys_for_user( int $user_id ): array {
	global $wpdb;
	if ( $user_id <= 0 ) {
		return array();
	}
	return (array) $wpdb->get_results(
		$wpdb->prepare( 'SELECT journey_id, journey, outcome, value, order_id, started_at, ended_at FROM ' . journeys_table() . ' WHERE user_id = %d ORDER BY started_at', $user_id ),
		ARRAY_A
	);
}

/**
 * Delete everything that names a user: their cart rows and items, the
 * journeys reachable through those carts, and the journeys stamped with them.
 *
 * @since 0.8.0 Removes the journeys stamped with the user too.
 *
 * @param int $user_id The user.
 * @return int Rows removed across the three tables.
 */
function erase_user( int $user_id ): int {
	global $wpdb;
	if ( $user_id <= 0 ) {
		return 0;
	}
	$removed = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . journeys_table() . ' WHERE user_id = %d', $user_id ) );
	$ids     = cart_ids_for_user( $user_id );
	if ( empty( $ids ) ) {
		return $removed;
	}
	$holders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
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

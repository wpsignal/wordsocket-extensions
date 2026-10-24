<?php
/**
 * ShopSocket's vocabulary for WordSocket's rules engine (WordSocket 0.31).
 *
 * - Conditions about the cart, page kinds for the shop's own pages, and the
 *   action a store wants: a word to the board.
 * - One rule shipped disabled: remind a shopper who hid the tab with a cart.
 * - Firings land on the journey row when history is on; WordSocket keeps none.
 * - One public route the Alert action posts to, naming the moment: PHP reads
 *   the message from the saved moment, needs the shopper's cart, limits one
 *   alert a minute per cart, and publishes on the orders feed, which only PHP
 *   may write, so the board can trust every word on it.
 * - Everything here is guarded on the API, so the plugin keeps working on
 *   WordSocket 0.30 with no rules at all.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

use WPSignal\WPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- The plugin's own tables.

/** The rule shipped disabled. */
const DEFAULT_RULE_ID = 'shopsocket-hidden-cart';

/** Seconds between two alerts from one cart. */
const RULES_RATE_LIMIT = 60;

/** The longest an Alert message gets, as the board shows it. */
const ALERT_MESSAGE_LENGTH = 200;

/**
 * Whether the active WordSocket has the rules engine.
 *
 * @return bool
 */
function rules_available(): bool {
	return method_exists( WPS::class, 'rules' );
}

/**
 * Register the vocabulary once taxonomies and coupons can be read (after WordSocket's own, which registers on `init` 5).
 *
 * @return void
 */
function register_rules_vocabulary(): void {
	if ( ! rules_available() ) {
		return;
	}
	$rules  = WPS::rules();
	$bounds = array(
		array(
			'key'   => 'at_least',
			'label' => __( 'at least', 'shopsocket' ),
		),
		array(
			'key'   => 'at_most',
			'label' => __( 'at most', 'shopsocket' ),
		),
	);
	$inout  = array(
		array(
			'key'   => 'includes',
			'label' => __( 'includes', 'shopsocket' ),
		),
		array(
			'key'   => 'excludes',
			'label' => __( 'excludes', 'shopsocket' ),
		),
	);
	$rules->context(
		'cart',
		array(
			'label'        => __( 'Cart', 'shopsocket' ),
			'placeholders' => array(
				'cart.count'   => __( 'items in the cart', 'shopsocket' ),
				'cart.total'   => __( 'the cart total', 'shopsocket' ),
				'cart.product' => __( 'the first product\'s name', 'shopsocket' ),
			),
			'source'       => SLUG,
		)
	);
	$rules->condition(
		'cart_has_items',
		array(
			'label'     => __( 'Cart has items', 'shopsocket' ),
			'operators' => array(
				array(
					'key'   => 'is',
					'label' => __( 'is', 'shopsocket' ),
				),
			),
			'value'     => 'boolean',
			'source'    => SLUG,
		)
	);
	$rules->condition(
		'cart_count',
		array(
			'label'     => __( 'Items in the cart', 'shopsocket' ),
			'operators' => $bounds,
			'value'     => 'number',
			'source'    => SLUG,
		)
	);
	$rules->condition(
		'cart_total',
		array(
			/* translators: %s: currency code */
			'label'     => sprintf( __( 'Cart total (%s)', 'shopsocket' ), get_woocommerce_currency() ),
			'operators' => $bounds,
			'value'     => 'number',
			'source'    => SLUG,
		)
	);
	$rules->condition(
		'cart_has_product',
		array(
			'label'       => __( 'Cart has product (id)', 'shopsocket' ),
			'operators'   => $inout,
			'value'       => 'id',
			'description' => __( 'The product id, from its edit screen.', 'shopsocket' ),
			'source'      => SLUG,
		)
	);
	$categories = array();
	$terms      = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'number'     => 200,
		)
	);
	foreach ( is_array( $terms ) ? $terms : array() as $term ) {
		$categories[ (string) $term->term_id ] = $term->name;
	}
	if ( ! empty( $categories ) ) {
		$rules->condition(
			'cart_has_category',
			array(
				'label'     => __( 'Cart has a product in', 'shopsocket' ),
				'operators' => $inout,
				'value'     => 'select',
				'options'   => $categories,
				'source'    => SLUG,
			)
		);
	}

	$rules->action(
		'alert_staff',
		array(
			'label'       => __( 'Alert Realtime Board', 'shopsocket' ),
			'description' => __( 'Your team, on the board: the cart, its value and where the shopper is. Nothing is shown to the shopper.', 'shopsocket' ),
			'params'      => array(
				'message' => array(
					'type'    => 'text',
					'label'   => __( 'Message', 'shopsocket' ),
					'default' => __( 'A shopper may need a hand', 'shopsocket' ),
				),
			),
			'live'        => true,
			'source'      => SLUG,
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\register_rules_vocabulary', 6 );

// The shop's own pages as kinds a rule may name.
add_filter(
	'wpsignal_page_kinds',
	static function ( array $kinds ): array {
		return array_merge(
			$kinds,
			array(
				'shop'     => __( 'The shop page', 'shopsocket' ),
				'category' => __( 'A product category', 'shopsocket' ),
				'cart'     => __( 'The cart', 'shopsocket' ),
				'checkout' => __( 'The checkout', 'shopsocket' ),
				'thanks'   => __( 'The order received page', 'shopsocket' ),
			)
		);
	}
);

// Product category pages are the `category` kind, so they count as a taxonomy archive.
add_filter(
	'wpsignal_taxonomy_kinds',
	static function ( array $taxonomies ): array {
		$taxonomies['category'] = __( 'Product category', 'shopsocket' );
		return $taxonomies;
	}
);

// The product archive is the shop page, the `shop` kind, so WordSocket's grouped Page select does not offer a "Products archive" nothing matches.
add_filter(
	'wpsignal_archive_kinds',
	static function ( array $archives ): array {
		unset( $archives['product_archive'] );
		return $archives;
	}
);

// One rule shipped disabled: the hidden-tab reminder.
add_filter(
	'wpsignal_default_rules',
	static function ( array $rules ): array {
		$rules[] = array(
			'id'         => DEFAULT_RULE_ID,
			'name'       => __( "Get customers' attention when away", 'shopsocket' ),
			'enabled'    => false,
			'frequency'  => 'visit',
			'rev'        => 0,
			'source'     => SLUG,
			'conditions' => array(
				'all' => array(
					array(
						'condition' => 'cart_has_items',
						'op'        => 'is',
						'value'     => true,
					),
					array(
						'condition' => 'time_hidden',
						'op'        => 'at_least',
						'value'     => 30,
					),
				),
			),
			'actions'    => array(
				array(
					'action'       => 'show',
					'presentation' => array(
						'type'    => 'banner',
						'pattern' => 0,
						'text'    => __( 'Your cart is waiting: {cart.product}', 'shopsocket' ),
					),
				),
			),
			'attention'  => array(
				'enabled' => true,
				'title'   => __( 'Your cart is waiting', 'shopsocket' ),
			),
		);
		return $rules;
	}
);

/** What a report says happened: the firing, or what the shopper did with the prompt (WordSocket 0.31's kinds). */
const FIRING_KINDS = array( 'fired', 'returned', 'dismissed', 'clicked' );

/**
 * A firing lands on the journey row, when history is on; a journey not yet reported starts its row here, cart permitting.
 *
 * @param array $report `{ visitor, journey_id, rule_id, action, step, user_id, data }`.
 * @return void
 */
function on_rule_fired( array $report ): void {
	global $wpdb;
	if ( ! history_enabled() ) {
		return;
	}
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT firings FROM ' . journeys_table() . ' WHERE journey_id = %s', $report['journey_id'] ), ARRAY_A );
	if ( ! is_array( $row ) ) {
		if ( ! start_journey( (string) $report['visitor'], (string) $report['journey_id'] ) ) {
			return;
		}
		$row = array( 'firings' => null );
	}
	$firings   = json_decode( (string) ( $row['firings'] ?? '' ), true );
	$firings   = is_array( $firings ) ? $firings : array();
	$kind      = (string) ( $report['kind'] ?? 'fired' );
	$firings[] = array(
		'rule'   => (string) $report['rule_id'],
		'action' => (string) $report['action'],
		'step'   => (int) $report['step'],
		'ms'     => max( 0, (int) ( $report['ms'] ?? 0 ) ),
		'kind'   => in_array( $kind, FIRING_KINDS, true ) ? $kind : 'fired',
		'at'     => now_sql(),
	);
	$wpdb->update( journeys_table(), array( 'firings' => wp_json_encode( array_slice( $firings, -50 ) ) ), array( 'journey_id' => $report['journey_id'] ), array( '%s' ), array( '%s' ) );
}
add_action( 'wpsignal_rule_fired', __NAMESPACE__ . '\on_rule_fired' );

/**
 * One request per cart per RULES_RATE_LIMIT for a public route; false when the cart is over the limit.
 *
 * @param string $what    The route's name.
 * @param string $cart_id The shopper's cart id.
 * @return bool
 */
function rules_rate_ok( string $what, string $cart_id ): bool {
	$key = 'shopsocket_' . $what . '_' . md5( $cart_id );
	if ( get_transient( $key ) ) {
		return false;
	}
	set_transient( $key, 1, RULES_RATE_LIMIT );
	return true;
}

/**
 * The shopper's cart for a route: the cart loaded from their cookie, the id, the state.
 *
 * @return array{id: string, state: array|null}
 */
function route_cart(): array {
	if ( ( has_wc_session_cookie() || is_user_logged_in() ) && function_exists( 'wc_load_cart' ) && ( ! function_exists( 'WC' ) || ! WC()->cart ) ) {
		wc_load_cart();
	}
	return array(
		'id'    => cart_id(),
		'state' => current_cart_state(),
	);
}

/**
 * The message of the Alert action on the enabled moment `$rule_id`, as the merchant saved it; null when there is no such moment or action.
 *
 * @param string $rule_id The moment's id, from the browser.
 * @param string $given   The message the browser sent, which only picks among several Alert actions on one moment.
 * @return string|null
 */
function alert_message( string $rule_id, string $given ): ?string {
	$messages = array();
	foreach ( WPS::rules()->enabled() as $rule ) {
		if ( (string) $rule['id'] !== $rule_id ) {
			continue;
		}
		foreach ( (array) ( $rule['actions'] ?? array() ) as $action ) {
			if ( 'alert_staff' === (string) ( $action['action'] ?? '' ) ) {
				$messages[] = mb_substr( (string) ( $action['message'] ?? '' ), 0, ALERT_MESSAGE_LENGTH );
			}
		}
	}
	if ( array() === $messages ) {
		return null;
	}
	$given = mb_substr( sanitize_text_field( $given ), 0, ALERT_MESSAGE_LENGTH );
	return in_array( $given, $messages, true ) ? $given : $messages[0];
}

/**
 * `POST /shopsocket/v1/alert`: the Alert action's word to the board, published from PHP on the orders feed with the saved moment's message.
 *
 * @param \WP_REST_Request $request The request.
 * @return \WP_REST_Response|\WP_Error
 */
function handle_alert( \WP_REST_Request $request ) {
	$message = alert_message( (string) $request->get_param( 'rule' ), (string) $request->get_param( 'message' ) );
	if ( null === $message ) {
		return new \WP_Error( 'shopsocket_unknown_moment', __( 'No such moment.', 'shopsocket' ), array( 'status' => 404 ) );
	}
	$cart  = route_cart();
	$state = $cart['state'];
	// Without a cart there is nothing to show the board, and nothing but the caller's own word to key the limit on.
	if ( '' === $cart['id'] || null === $state ) {
		return new \WP_Error( 'shopsocket_no_cart', __( 'No cart to alert about.', 'shopsocket' ), array( 'status' => 400 ) );
	}
	if ( ! rules_rate_ok( 'alert', $cart['id'] ) ) {
		return new \WP_Error( 'shopsocket_too_many', __( 'Try again in a minute.', 'shopsocket' ), array( 'status' => 429 ) );
	}
	WPS::publish(
		ORDERS_CHANNEL,
		'woo.alert',
		array(
			'visitor'    => strtolower( (string) $request->get_param( 'v' ) ),
			'cart_id'    => $cart['id'],
			'message'    => $message,
			'step'       => sanitize_key( (string) $request->get_param( 'step' ) ),
			'products'   => $state['products'],
			'value'      => $state['value'],
			'currency'   => get_woocommerce_currency(),
			'wants_chat' => false,
			'at'         => time(),
		)
	);
	$response = rest_ensure_response( array( 'ok' => true ) );
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

add_action(
	'rest_api_init',
	static function (): void {
		if ( ! rules_available() ) {
			return;
		}
		$visitor = array(
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => static fn( $value ): bool => is_string( $value ) && 1 === preg_match( \WPSignal\Presence::UUID_PATTERN, $value ),
		);
		$rule    = array(
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => static fn( $value ): bool => is_string( $value ) && 1 === preg_match( \WPSignal\Rules::ID_PATTERN, $value ),
		);
		register_rest_route(
			REST_NS,
			'/alert',
			array(
				'methods'             => 'POST',
				'args'                => array(
					'v'       => $visitor,
					'rule'    => $rule,
					'message' => array(
						'type'    => 'string',
						'default' => '',
					),
					'step'    => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'callback'            => __NAMESPACE__ . '\handle_alert',
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			REST_NS,
			'/journeys',
			array(
				'methods'             => 'GET',
				'args'                => array(
					'days'  => array(
						'type'    => 'integer',
						'default' => 30,
						'minimum' => 1,
						'maximum' => 365,
					),
					'limit' => array(
						'type'    => 'integer',
						'default' => 500,
						'minimum' => 1,
						'maximum' => 2000,
					),
				),
				'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response(
					array( 'journeys' => journeys_for_replay( (int) $request->get_param( 'days' ), (int) $request->get_param( 'limit' ) ) )
				),
				'permission_callback' => static fn() => current_user_can( STAFF_CAP ),
			)
		);
	}
);

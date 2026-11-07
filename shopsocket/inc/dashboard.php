<?php
/**
 * The dashboard's figures and the REST route that serves them.
 *
 * Loaded on every request, since REST calls are not `is_admin()`. The screen
 * itself is in `admin-board.php`.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

use WPSignal\Journeys;
use WPSignal\WPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const REST_NS = 'shopsocket/v1';

/**
 * Products the board may name in one request, and the most it lists.
 *
 * @since 0.1.1
 */
const BOARD_LIVE_PRODUCTS = 100;

/**
 * Orders the board renders on first paint, and the most one refresh hands back.
 *
 * @since 0.1.1
 */
const BOARD_ORDERS = 50;

/**
 * How far back the board's lists go, in hours: its orders, and the carts left behind until the board asks for another time frame.
 *
 * @since 0.8.0
 */
const BOARD_HOURS = 24;

/**
 * Browsers connected to the site right now, from the WPSignal server through
 * WordSocket (0.22+). Null when WordSocket is older or the server is unreachable.
 *
 * @since 0.1.1
 *
 * @return array{active_connections: int, max_connections: int}|null
 */
function online_now(): ?array {
	$publisher = WPS::instance()->publisher();
	if ( ! method_exists( $publisher, 'stats' ) ) {
		return null;
	}
	$stats = $publisher->stats();
	return is_wp_error( $stats ) ? null : $stats;
}

/**
 * The open rows, the carts left behind in the last `$hours` with what they tell, their full count for every time frame, this day's strip and the online count; the board
 * crosses the open rows with presence itself.
 * `as_of` is this server's clock when the figures were read: the board sends
 * it back as `since` on its next refresh.
 *
 * @since 0.1.1
 * @since 0.8.0 Adds `strip`, `abandonment`, `abandoned_totals` and `abandoned_returning`; takes `$hours`.
 *
 * @param int $hours The abandoned carts' time frame, in hours.
 * @return array{carts: array<int, array<string, mixed>>, abandoned: array<int, array<string, mixed>>, abandoned_totals: array<int, array<string, mixed>>, abandoned_returning: object, abandonment: array<string, mixed>|null, strip: array<string, mixed>|null, online: array{active_connections: int, max_connections: int}|null, as_of: int}
 */
function dashboard_snapshot( int $hours = BOARD_HOURS ): array {
	$count = abandoned_count();
	return array(
		'carts'               => cart_rows(),
		'abandoned'           => abandoned_rows( $hours ),
		'abandoned_totals'    => $count['totals'],
		'abandoned_returning' => (object) $count['returning'],
		'abandonment'         => abandonment( $hours ),
		'strip'               => day_strip(),
		'online'              => online_now(),
		'as_of'               => time(),
	);
}

/**
 * Orders placed in the last BOARD_HOURS for the board, newest first, BOARD_ORDERS at most; with
 * `$since` only those of them created or changed at or after that moment.
 *
 * @since 0.5.1
 * @since 0.8.0 Lists only the orders placed in the last BOARD_HOURS.
 *
 * @param int $since Unix timestamp, or 0 for every order in the window.
 * @return array<int, array<string, mixed>>
 */
function board_orders( int $since = 0 ): array {
	$args = array(
		'limit'        => BOARD_ORDERS,
		'orderby'      => 'date',
		'order'        => 'DESC',
		'type'         => 'shop_order',
		'date_created' => '>=' . ( time() - BOARD_HOURS * HOUR_IN_SECONDS ),
	);
	if ( $since > 0 ) {
		$args['date_modified'] = '>=' . $since;
	}
	$orders = wc_get_orders( $args );
	return array_values( array_map( __NAMESPACE__ . '\\order_payload', array_filter( $orders, static fn( $o ) => $o instanceof \WC_Order ) ) );
}

/**
 * The `/dashboard` response: the snapshot with the abandoned carts of the
 * time frame `hours` names, plus the orders changed since `since` when the
 * board names one.
 *
 * @since 0.5.1
 * @since 0.8.0 Reads `hours`.
 *
 * @param \WP_REST_Request $request The request.
 * @return \WP_REST_Response|\WP_Error
 */
function dashboard_response( \WP_REST_Request $request ) {
	$hours = (int) $request->get_param( 'hours' );
	$data  = dashboard_snapshot( in_array( $hours, ABANDONED_WINDOWS, true ) ? $hours : BOARD_HOURS );
	if ( $request->has_param( 'since' ) ) {
		$data['orders'] = board_orders( max( 1, (int) $request->get_param( 'since' ) ) );
	}
	return rest_ensure_response( $data );
}

/**
 * Product ids from a comma-separated list: positive integers, no repeats,
 * capped at BOARD_LIVE_PRODUCTS.
 *
 * @since 0.1.1
 *
 * @param string $ids Raw `ids` parameter.
 * @return int[]
 */
function parse_product_ids( string $ids ): array {
	$clean = array();
	foreach ( explode( ',', $ids ) as $id ) {
		$id = (int) trim( $id );
		if ( $id > 0 ) {
			$clean[ $id ] = $id;
		}
	}
	return array_slice( array_values( $clean ), 0, BOARD_LIVE_PRODUCTS );
}

/**
 * Name, edit link and permalink for each product that still exists, for the board's
 * tables. The board alone knows which products it is showing, so it picks the ids
 * and asks here for the words.
 *
 * @since 0.1.1
 * @since 0.8.0 Adds `url`, the storefront page.
 *
 * @param int[] $ids Parent product ids.
 * @return array<int, array{id: int, name: string, edit_url: string, url: string}>
 */
function products_for_board( array $ids ): array {
	if ( empty( $ids ) ) {
		return array();
	}
	$rows = array();
	foreach ( wc_get_products(
		array(
			'include' => $ids,
			'limit'   => BOARD_LIVE_PRODUCTS,
		)
	) as $product ) {
		if ( ! $product instanceof \WC_Product ) {
			continue;
		}
		$rows[] = array(
			'id'       => $product->get_id(),
			'name'     => $product->get_name(),
			'edit_url' => (string) get_edit_post_link( $product->get_id(), 'raw' ),
			'url'      => (string) $product->get_permalink(),
		);
	}
	return $rows;
}

/**
 * Journey steps from a comma-separated list, each `kind:id`, lower-case and
 * with the id as an integer, no repeats, capped at BOARD_LIVE_PRODUCTS.
 *
 * @since 0.8.0
 *
 * @param string $steps Raw `steps` parameter.
 * @return string[]
 */
function parse_steps( string $steps ): array {
	$clean = array();
	foreach ( explode( ',', $steps ) as $step ) {
		if ( ! preg_match( '/^([a-z_]+):(\d{1,12})$/', trim( $step ), $m ) ) {
			continue;
		}
		$key           = $m[1] . ':' . (int) $m[2];
		$clean[ $key ] = $key;
	}
	return array_slice( array_values( $clean ), 0, BOARD_LIVE_PRODUCTS );
}

/**
 * The step kinds that are a taxonomy's term archives, as WordSocket records them.
 *
 * @since 0.8.0
 *
 * @return string[]
 */
function taxonomy_step_kinds(): array {
	if ( ! class_exists( Journeys::class ) ) {
		return array();
	}
	return array_values( array_unique( array_map( array( Journeys::class, 'taxonomy_kind' ), get_taxonomies( array( 'public' => true ) ) ) ) );
}

/**
 * The title and public URL of the page a journey step names: a published post
 * of the kind's post type, a term of a public taxonomy, or a post type's
 * archive. Null for anything else, which the board labels itself.
 *
 * @since 0.8.0
 *
 * @param string $kind The step kind.
 * @param int    $id   The step id.
 * @return array{step: string, title: string, url: string}|null
 */
function page_for_step( string $kind, int $id ): ?array {
	$step = $kind . ':' . $id;
	$type = get_post_type_object( $kind );
	if ( $type instanceof \WP_Post_Type && $type->public ) {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post || $post->post_type !== $kind || ! is_post_publicly_viewable( $post ) ) {
			return null;
		}
		return array(
			'step'  => $step,
			'title' => (string) get_the_title( $post ),
			'url'   => (string) get_permalink( $post ),
		);
	}
	if ( in_array( $kind, taxonomy_step_kinds(), true ) ) {
		$term = $id > 0 ? get_term( $id ) : null;
		if ( ! $term instanceof \WP_Term || ! is_taxonomy_viewable( $term->taxonomy ) ) {
			return null;
		}
		$link = get_term_link( $term );
		if ( ! is_string( $link ) ) {
			return null;
		}
		return array(
			'step'  => $step,
			'title' => (string) $term->name,
			'url'   => $link,
		);
	}
	if ( str_ends_with( $kind, '_archive' ) ) {
		$archive = get_post_type_object( substr( $kind, 0, -8 ) );
		$link    = $archive instanceof \WP_Post_Type && $archive->public ? get_post_type_archive_link( $archive->name ) : false;
		if ( ! is_string( $link ) ) {
			return null;
		}
		return array(
			'step'  => $step,
			'title' => (string) $archive->labels->name,
			'url'   => $link,
		);
	}
	return null;
}

/**
 * The pages behind the journey steps the board lists, for the shoppers
 * table; a step that names nothing public has no row.
 *
 * @since 0.8.0
 *
 * @param string[] $steps Steps as `kind:id`, from `parse_steps()`.
 * @return array<int, array{step: string, title: string, url: string}>
 */
function pages_for_board( array $steps ): array {
	$posts = array();
	$terms = array();
	$taxes = taxonomy_step_kinds();
	foreach ( $steps as $step ) {
		[ $kind, $id ] = explode( ':', $step, 2 );
		if ( (int) $id <= 0 ) {
			continue;
		}
		if ( in_array( $kind, $taxes, true ) ) {
			$terms[] = (int) $id;
		} elseif ( get_post_type_object( $kind ) instanceof \WP_Post_Type ) {
			$posts[] = (int) $id;
		}
	}
	if ( $posts ) {
		_prime_post_caches( $posts, false, false );
	}
	if ( $terms ) {
		_prime_term_caches( $terms );
	}
	$rows = array();
	foreach ( $steps as $step ) {
		[ $kind, $id ] = explode( ':', $step, 2 );
		$page          = page_for_step( $kind, (int) $id );
		if ( null !== $page ) {
			$rows[] = $page;
		}
	}
	return $rows;
}

/*
 * `GET /shopsocket/v1/dashboard`: the tiles' figures, polled by the screen;
 * with `?since=<as_of>` also the orders created or changed since then.
 * `GET /shopsocket/v1/products?ids=1,2,3`: names and edit links for the
 * products the board lists.
 * `GET /shopsocket/v1/pages?steps=page:3,category:7`: titles and URLs for the
 * pages the shoppers table lists.
 */
add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			REST_NS,
			'/dashboard',
			array(
				'methods'             => 'GET',
				'args'                => array(
					'since' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'hours' => array(
						'type' => 'integer',
						'enum' => ABANDONED_WINDOWS,
					),
				),
				'callback'            => __NAMESPACE__ . '\\dashboard_response',
				'permission_callback' => static fn() => current_user_can( STAFF_CAP ),
			)
		);
		register_rest_route(
			REST_NS,
			'/products',
			array(
				'methods'             => 'GET',
				'args'                => array(
					'ids' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response(
					array( 'products' => products_for_board( parse_product_ids( (string) $request->get_param( 'ids' ) ) ) )
				),
				'permission_callback' => static fn() => current_user_can( STAFF_CAP ),
			)
		);
		register_rest_route(
			REST_NS,
			'/pages',
			array(
				'methods'             => 'GET',
				'args'                => array(
					'steps' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response(
					array( 'pages' => pages_for_board( parse_steps( (string) $request->get_param( 'steps' ) ) ) )
				),
				'permission_callback' => static fn() => current_user_can( STAFF_CAP ),
			)
		);
	}
);

<?php
/**
 * ShopSocket's entry in WooCommerce's personal data tools.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- The plugin's own tables: names come from the table functions, one placeholder per id.

/**
 * Cart history under the user's personal data.
 */
class Privacy extends \WC_Abstract_Privacy {

	/**
	 * One exporter and one eraser, both keyed on the user id behind an email.
	 */
	public function __construct() {
		parent::__construct( __( 'ShopSocket', 'shopsocket' ) );
		$this->add_exporter( 'shopsocket-carts', __( 'ShopSocket cart history', 'shopsocket' ), array( $this, 'export' ) );
		$this->add_eraser( 'shopsocket-carts', __( 'ShopSocket cart history', 'shopsocket' ), array( $this, 'erase' ) );
	}

	/**
	 * The policy text for Settings > Privacy.
	 *
	 * @return string
	 */
	public function get_privacy_message() {
		return wpautop(
			__( 'With cart history on, ShopSocket keeps a record of each shopping cart on this site: the products it held, its value, when it was first and last seen, when the shopper left and whether it became an order, for the number of days set in its settings. For logged-in shoppers the cart is linked to their account; guests are known only by a random id their browser made. Journeys (the pages a shopper opened and how long each was open, as post and term ids) are kept separately for the number of months set in its settings, under that random browser id, and linked to the shopper\'s account for the visits they were logged in.', 'shopsocket' )
		);
	}

	/**
	 * Export a user's cart rows and the journeys linked to their account.
	 *
	 * @since 0.8.0 Exports the journeys too.
	 *
	 * @param string $email Customer email.
	 * @param int    $page  Page (unused: one page).
	 * @return array{data: array, done: bool}
	 */
	public function export( $email, $page = 1 ) {
		unset( $page );
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( $user ) {
			global $wpdb;
			$ids = cart_ids_for_user( (int) $user->ID );
			if ( ! empty( $ids ) ) {
				$holders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
				$rows    = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . carts_table() . " WHERE cart_id IN ({$holders})", ...$ids ), ARRAY_A );
				$items   = cart_items( $ids );
				foreach ( $rows as $row ) {
					$data[] = array(
						'group_id'    => 'shopsocket_carts',
						'group_label' => __( 'ShopSocket cart history', 'shopsocket' ),
						'item_id'     => 'shopsocket-cart-' . $row['cart_id'],
						'data'        => array(
							array(
								'name'  => __( 'Products', 'shopsocket' ),
								'value' => implode( ', ', array_map( 'strval', array_keys( $items[ (string) $row['cart_id'] ] ?? array() ) ) ),
							),
							array(
								'name'  => __( 'Value', 'shopsocket' ),
								'value' => $row['value'] . ' ' . $row['currency'],
							),
							array(
								'name'  => __( 'First seen', 'shopsocket' ),
								'value' => $row['first_seen'],
							),
							array(
								'name'  => __( 'Last seen', 'shopsocket' ),
								'value' => $row['last_seen'],
							),
							array(
								'name'  => __( 'Left', 'shopsocket' ),
								'value' => (string) ( $row['left_at'] ?? '' ),
							),
							array(
								'name'  => __( 'Order', 'shopsocket' ),
								'value' => (string) ( $row['order_id'] ?? '' ),
							),
						),
					);
				}
			}
			foreach ( journeys_for_user( (int) $user->ID ) as $row ) {
				$data[] = array(
					'group_id'    => 'shopsocket_journeys',
					'group_label' => __( 'ShopSocket journeys', 'shopsocket' ),
					'item_id'     => 'shopsocket-journey-' . $row['journey_id'],
					'data'        => array(
						array(
							'name'  => __( 'Pages', 'shopsocket' ),
							'value' => (string) ( $row['journey'] ?? '' ),
						),
						array(
							'name'  => __( 'Started', 'shopsocket' ),
							'value' => (string) $row['started_at'],
						),
						array(
							'name'  => __( 'Ended', 'shopsocket' ),
							'value' => (string) ( $row['ended_at'] ?? '' ),
						),
						array(
							'name'  => __( 'Outcome', 'shopsocket' ),
							'value' => (string) $row['outcome'],
						),
						array(
							'name'  => __( 'Order', 'shopsocket' ),
							'value' => (string) ( $row['order_id'] ?? '' ),
						),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase a user's cart rows, the journeys reachable through them and the journeys linked to their account.
	 *
	 * @since 0.8.0 Erases the journeys linked to the account too.
	 *
	 * @param string $email Customer email.
	 * @param int    $page  Page (unused: one page).
	 * @return array{items_removed: int, items_retained: int, messages: array, done: bool}
	 */
	public function erase( $email, $page = 1 ) {
		unset( $page );
		$user    = get_user_by( 'email', $email );
		$removed = $user ? erase_user( (int) $user->ID ) : 0;
		return array(
			'items_removed'  => $removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}
}

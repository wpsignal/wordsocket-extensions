<?php
/**
 * ShopSocket's entry in WooCommerce's personal data tools: the policy text, and the exporter and
 * eraser for the best guesses at who a guest is; the carts live on WordSocket's journeys, which its
 * own exporter and eraser cover.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the shop adds to a visitor's record.
 *
 * @since 0.8.0 The exporter and eraser cover the guest guesses alone.
 */
class Privacy extends \WC_Abstract_Privacy {

	/**
	 * Named for the privacy policy guide, with the guesses' exporter and eraser.
	 */
	public function __construct() {
		parent::__construct( __( 'ShopSocket', 'shopsocket' ) );
		$this->add_exporter( 'shopsocket-guests', __( 'ShopSocket guest guesses', 'shopsocket' ), array( $this, 'export_guests' ) );
		$this->add_eraser( 'shopsocket-guests', __( 'ShopSocket guest guesses', 'shopsocket' ), array( $this, 'erase_guests' ) );
	}

	/**
	 * The suggested policy text.
	 *
	 * @return string
	 */
	public function get_privacy_message() {
		return wpautop(
			__( 'While WordSocket keeps a history of journeys, ShopSocket adds to each visit the shopping cart it was made with: the products it held, its value, and the order it became. It is kept with the journey, for as long as WordSocket keeps that, and exported and erased with it. Shoppers who were not logged in are known only by a random id their browser made, unless their browser remembers the email of an earlier order here, in which case staff see that email, flagged as a guess, for two days.', 'shopsocket' )
		);
	}

	/**
	 * The guesses made from an email: one item per browser.
	 *
	 * @since 0.8.0
	 *
	 * @param string $email The email.
	 * @return array{data: array, done: bool}
	 */
	public function export_guests( string $email ): array {
		$index = recall( guest_index_key( strtolower( sanitize_email( $email ) ) ) );
		$items = array();
		foreach ( is_array( $index ) ? $index : array() as $v ) {
			$hint = guest_hint( (string) $v );
			if ( null === $hint ) {
				continue;
			}
			$items[] = array(
				'group_id'    => 'shopsocket_guests',
				'group_label' => __( 'ShopSocket guest guesses', 'shopsocket' ),
				'item_id'     => 'shopsocket-guest-' . $v,
				'data'        => array(
					array(
						'name'  => __( 'Browser id', 'shopsocket' ),
						'value' => (string) $v,
					),
					array(
						'name'  => __( 'Email', 'shopsocket' ),
						'value' => (string) $hint['email'],
					),
					array(
						'name'  => __( 'Kept since', 'shopsocket' ),
						'value' => gmdate( 'Y-m-d H:i:s', (int) $hint['at'] ),
					),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Forget the guesses made from an email.
	 *
	 * @since 0.8.0
	 *
	 * @param string $email The email.
	 * @return array{items_removed: bool, items_retained: bool, messages: array, done: bool}
	 */
	public function erase_guests( string $email ): array {
		return array(
			'items_removed'  => forget_guests( $email ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}

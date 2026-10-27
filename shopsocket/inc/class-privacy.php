<?php
/**
 * ShopSocket's entry in WooCommerce's personal data tools: the policy text alone, since the carts live on WordSocket's journeys, which its own exporter and eraser cover.
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
 * @since 0.8.0 No exporter or eraser of its own.
 */
class Privacy extends \WC_Abstract_Privacy {

	/**
	 * Named for the privacy policy guide.
	 */
	public function __construct() {
		parent::__construct( __( 'ShopSocket', 'shopsocket' ) );
	}

	/**
	 * The suggested policy text.
	 *
	 * @return string
	 */
	public function get_privacy_message() {
		return wpautop(
			__( 'While WordSocket keeps a history of journeys, ShopSocket adds to each visit the shopping cart it was made with: the products it held, its value, and the order it became. It is kept with the journey, for as long as WordSocket keeps that, and exported and erased with it. Shoppers who were not logged in are known only by a random id their browser made.', 'shopsocket' )
		);
	}
}

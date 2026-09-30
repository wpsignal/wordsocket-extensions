<?php
/**
 * Storefront settings: the option's shape, how the site switch, category
 * exclusions and the per-product choice combine, and the publish gate.
 */

use function WPSignal\Extensions\ShopSocket\feature_enabled;
use function WPSignal\Extensions\ShopSocket\in_carts_enabled;
use function WPSignal\Extensions\ShopSocket\sanitize_settings;
use function WPSignal\Extensions\ShopSocket\settings;
use function WPSignal\Extensions\ShopSocket\toasts_enabled;
use const WPSignal\Extensions\ShopSocket\ACTIVITY_CHANNEL;
use const WPSignal\Extensions\ShopSocket\FEATURE_ACTIVITY;
use const WPSignal\Extensions\ShopSocket\FEATURE_IN_CARTS;
use const WPSignal\Extensions\ShopSocket\PRODUCT_META;
use const WPSignal\Extensions\ShopSocket\SETTINGS_OPTION;

final class SettingsTest extends ExtensionTestCase {

	private const MISSING = '__missing__';

	/** @var mixed */
	private $saved_option = self::MISSING;

	/** @var int[] */
	private array $terms = array();

	protected function setUp(): void {
		parent::setUp();
		$this->saved_option = get_option( SETTINGS_OPTION, self::MISSING );
		delete_option( SETTINGS_OPTION );
	}

	protected function tearDown(): void {
		if ( self::MISSING === $this->saved_option ) {
			delete_option( SETTINGS_OPTION );
		} else {
			update_option( SETTINGS_OPTION, $this->saved_option );
		}
		foreach ( $this->terms as $term_id ) {
			wp_delete_term( $term_id, 'product_cat' );
		}
		parent::tearDown();
	}

	private function make_category( string $name ): int {
		$term = wp_insert_term( $name . ' ' . wp_generate_password( 6, false ), 'product_cat' );
		$this->assertIsArray( $term );
		$this->terms[] = (int) $term['term_id'];
		return (int) $term['term_id'];
	}

	public function test_defaults_are_everything_on_and_the_option_is_sanitized(): void {
		$this->assertSame(
			array(
				'in_carts_enabled'             => true,
				'activity_enabled'             => true,
				'in_carts_excluded_categories' => array(),
				'activity_excluded_categories' => array(),
			),
			settings(),
			'a site without the option behaves as before the settings existed'
		);

		$this->assertSame(
			array(
				'in_carts_enabled'             => false,
				'activity_enabled'             => true,
				'in_carts_excluded_categories' => array( 3, 7 ),
				'activity_excluded_categories' => array( 9 ),
			),
			sanitize_settings(
				array(
					'in_carts_enabled'             => '0',
					'in_carts_excluded_categories' => array( '3', 7, 3, -1, 'x' ),
					'activity_excluded_categories' => array( 9 ),
				)
			),
			'booleans coerced, term ids deduplicated and cleaned, missing keys defaulted'
		);

		$legacy = sanitize_settings( array( 'excluded_categories' => array( 5 ) ) );
		$this->assertSame( array( 5 ), $legacy['in_carts_excluded_categories'], 'the pre-release single list applies to both features' );
		$this->assertSame( array( 5 ), $legacy['activity_excluded_categories'] );

		$registered = get_registered_settings();
		$this->assertArrayHasKey( SETTINGS_OPTION, $registered, 'registered so /wp/v2/settings can read and write it' );
		$this->assertNotEmpty( $registered[ SETTINGS_OPTION ]['show_in_rest'] );
	}

	public function test_the_site_switch_categories_and_product_choice_combine_in_order(): void {
		$product  = $this->make_product();
		$id       = $product->get_id();
		$excluded = $this->make_category( 'Excluded' );

		// Default: on.
		$this->assertTrue( in_carts_enabled( $id ) );
		$this->assertTrue( toasts_enabled( $id ) );

		// Product Default in a category excluded for the counter only: the counter is off, the toasts stay on.
		wp_set_post_terms( $id, array( $excluded ), 'product_cat' );
		update_option( SETTINGS_OPTION, array( 'in_carts_excluded_categories' => array( $excluded ) ) );
		$this->assertFalse( in_carts_enabled( $id ), 'the category exclusion applies to the counter' );
		$this->assertTrue( toasts_enabled( $id ), 'each feature has its own list' );
		$this->assertTrue( in_carts_enabled(), 'the site-wide switch is still on' );

		update_option( SETTINGS_OPTION, array( 'activity_excluded_categories' => array( $excluded ) ) );
		$this->assertTrue( in_carts_enabled( $id ) );
		$this->assertFalse( toasts_enabled( $id ), 'and the toasts have theirs' );

		update_option(
			SETTINGS_OPTION,
			array(
				'in_carts_excluded_categories' => array( $excluded ),
				'activity_excluded_categories' => array( $excluded ),
			)
		);
		$this->assertFalse( in_carts_enabled( $id ) );
		$this->assertFalse( toasts_enabled( $id ) );

		// Product On beats the excluded category.
		update_post_meta( $id, PRODUCT_META[ FEATURE_IN_CARTS ], 'on' );
		$this->assertTrue( in_carts_enabled( $id ) );
		$this->assertFalse( toasts_enabled( $id ), 'each feature has its own choice' );

		// Product Off is off, whatever the categories say.
		wp_set_post_terms( $id, array(), 'product_cat' );
		update_post_meta( $id, PRODUCT_META[ FEATURE_ACTIVITY ], 'off' );
		$this->assertFalse( toasts_enabled( $id ) );

		// The site switch off is the default: a product on Default is off, a product set to On stays on.
		update_option( SETTINGS_OPTION, array( 'in_carts_enabled' => false ) );
		$this->assertTrue( in_carts_enabled( $id ), 'the product is still set to On' );
		$this->assertFalse( in_carts_enabled(), 'the site-wide question follows the switch' );
		delete_post_meta( $id, PRODUCT_META[ FEATURE_IN_CARTS ] );
		$this->assertFalse( in_carts_enabled( $id ), 'a product on Default follows the switch' );

		// The notification behaves the same way under its own switch.
		update_option( SETTINGS_OPTION, array( 'activity_enabled' => false ) );
		update_post_meta( $id, PRODUCT_META[ FEATURE_ACTIVITY ], 'on' );
		$this->assertTrue( toasts_enabled( $id ), 'a product set to On notifies while the site switch is off' );
		$this->assertFalse( toasts_enabled(), 'the site-wide question follows the switch' );
		delete_post_meta( $id, PRODUCT_META[ FEATURE_ACTIVITY ] );
		$this->assertFalse( toasts_enabled( $id ), 'a product on Default follows the switch' );

		// The legacy filter runs last, with the product id, and still wins.
		delete_option( SETTINGS_OPTION );
		delete_post_meta( $id, PRODUCT_META[ FEATURE_IN_CARTS ] );
		$seen   = array();
		$filter = static function ( bool $enabled, int $product_id ) use ( &$seen ): bool {
			$seen[] = $product_id;
			return false;
		};
		add_filter( 'shopsocket_in_carts_enabled', $filter, 10, 2 );
		try {
			$this->assertFalse( in_carts_enabled( $id ) );
			$this->assertFalse( in_carts_enabled() );
		} finally {
			remove_filter( 'shopsocket_in_carts_enabled', $filter, 10 );
		}
		$this->assertSame( array( $id, 0 ), $seen, 'the filter sees the product id, or 0 for the site-wide question' );
	}

	public function test_a_variation_follows_its_parent(): void {
		$parent = $this->make_product();
		update_post_meta( $parent->get_id(), PRODUCT_META[ FEATURE_ACTIVITY ], 'off' );

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '9.99' );
		$variation->save();
		try {
			$this->assertFalse( feature_enabled( FEATURE_ACTIVITY, $variation->get_id() ) );
			$this->assertTrue( feature_enabled( FEATURE_IN_CARTS, $variation->get_id() ) );
		} finally {
			$variation->delete( true );
		}
	}

	public function test_the_cart_added_event_follows_the_product_settings(): void {
		$product = $this->make_product( 10 );
		$id      = $product->get_id();
		WC()->cart->empty_cart();
		add_filter( 'shopsocket_activity_throttle', '__return_zero' );
		try {
			// Toasts off for the product, counter on: the event still carries the count, flagged.
			update_post_meta( $id, PRODUCT_META[ FEATURE_ACTIVITY ], 'off' );
			WC()->cart->add_to_cart( $id, 1 );
			$events = $this->events_on( ACTIVITY_CHANNEL, 'woo.cart.added' );
			$this->assertCount( 1, $events );
			$this->assertFalse( $events[0]['data']['activity'], 'no toast for this product' );
			$this->assertSame( 1, $events[0]['data']['in_carts'], 'but the counter still learns the new count' );

			// Both off for the product: nothing leaves the site.
			update_post_meta( $id, PRODUCT_META[ FEATURE_IN_CARTS ], 'off' );
			WC()->cart->add_to_cart( $id, 1 );
			$this->assertCount( 1, $this->events_on( ACTIVITY_CHANNEL, 'woo.cart.added' ) );

			// An excluded category does the same for a product on Default.
			delete_post_meta( $id, PRODUCT_META[ FEATURE_IN_CARTS ] );
			delete_post_meta( $id, PRODUCT_META[ FEATURE_ACTIVITY ] );
			$excluded = $this->make_category( 'Quiet' );
			wp_set_post_terms( $id, array( $excluded ), 'product_cat' );
			update_option(
				SETTINGS_OPTION,
				array(
					'in_carts_excluded_categories' => array( $excluded ),
					'activity_excluded_categories' => array( $excluded ),
				)
			);
			WC()->cart->add_to_cart( $id, 1 );
			$this->assertCount( 1, $this->events_on( ACTIVITY_CHANNEL, 'woo.cart.added' ) );
		} finally {
			remove_filter( 'shopsocket_activity_throttle', '__return_zero' );
			WC()->cart->empty_cart();
		}
	}
}

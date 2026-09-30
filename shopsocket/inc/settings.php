<?php
/**
 * Storefront settings: the site-wide switches for the shopper-facing
 * features, the categories they skip, and the per-product override.
 *
 * One option, `shopsocket_storefront`, written by the ShopSocket tab on
 * WordSocket's settings page through `/wp/v2/settings`. Two product meta
 * keys carry the per-product choice. `feature_enabled()` is the one place
 * the three layers are combined, and the `shopsocket_in_carts_enabled` and
 * `shopsocket_activity_enabled` filters still run last, so code keeps the
 * final say and sites that used them behave as before.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

use WC_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SETTINGS_OPTION = 'shopsocket_storefront';

/** The two shopper-facing features the settings switch. */
const FEATURE_IN_CARTS = 'in_carts';
const FEATURE_ACTIVITY = 'activity';

/** Product meta per feature: '' (default), 'on', or 'off'. */
const PRODUCT_META = array(
	FEATURE_IN_CARTS => '_shopsocket_in_carts',
	FEATURE_ACTIVITY => '_shopsocket_activity',
);

/** The legacy filter per feature, applied last to the resolved value. */
const FEATURE_FILTER = array(
	FEATURE_IN_CARTS => 'shopsocket_in_carts_enabled',
	FEATURE_ACTIVITY => 'shopsocket_activity_enabled',
);

/**
 * Defaults: everything on, nothing excluded, which is what every site had
 * before the settings existed.
 *
 * @return array{in_carts_enabled: bool, activity_enabled: bool, in_carts_excluded_categories: int[], activity_excluded_categories: int[]}
 */
function settings_defaults(): array {
	return array(
		'in_carts_enabled'             => true,
		'activity_enabled'             => true,
		'in_carts_excluded_categories' => array(),
		'activity_excluded_categories' => array(),
	);
}

/**
 * A list of `product_cat` term ids: integers above zero, deduplicated.
 *
 * @param mixed $value Whatever was saved.
 * @return int[]
 */
function sanitize_term_ids( $value ): array {
	return array_values(
		array_unique(
			array_filter(
				array_map( 'intval', (array) $value ),
				static fn( int $id ): bool => $id > 0
			)
		)
	);
}

/**
 * Coerce whatever was saved into the option's shape.
 *
 * @param mixed $value The value being saved or read.
 * @return array{in_carts_enabled: bool, activity_enabled: bool, in_carts_excluded_categories: int[], activity_excluded_categories: int[]}
 */
function sanitize_settings( $value ): array {
	$defaults = settings_defaults();
	$value    = is_array( $value ) ? $value : array();
	// A single `excluded_categories` list (the pre-release shape) applies to both features.
	$legacy = $value['excluded_categories'] ?? array();
	return array(
		'in_carts_enabled'             => (bool) ( $value['in_carts_enabled'] ?? $defaults['in_carts_enabled'] ),
		'activity_enabled'             => (bool) ( $value['activity_enabled'] ?? $defaults['activity_enabled'] ),
		'in_carts_excluded_categories' => sanitize_term_ids( $value['in_carts_excluded_categories'] ?? $legacy ),
		'activity_excluded_categories' => sanitize_term_ids( $value['activity_excluded_categories'] ?? $legacy ),
	);
}

/**
 * The saved settings, sanitized, with defaults for anything missing.
 *
 * @return array{in_carts_enabled: bool, activity_enabled: bool, in_carts_excluded_categories: int[], activity_excluded_categories: int[]}
 */
function settings(): array {
	return sanitize_settings( get_option( SETTINGS_OPTION, array() ) );
}

// Registered on init so `/wp/v2/settings` reads and writes it with its schema.
add_action(
	'init',
	static function (): void {
		register_setting(
			'shopsocket',
			SETTINGS_OPTION,
			array(
				'type'              => 'object',
				'description'       => __( 'ShopSocket storefront settings.', 'shopsocket' ),
				'default'           => settings_defaults(),
				'sanitize_callback' => __NAMESPACE__ . '\sanitize_settings',
				'show_in_rest'      => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'in_carts_enabled'             => array( 'type' => 'boolean' ),
							'activity_enabled'             => array( 'type' => 'boolean' ),
							'in_carts_excluded_categories' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'integer' ),
							),
							'activity_excluded_categories' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'integer' ),
							),
						),
					),
				),
			)
		);
	}
);

/**
 * The per-product choice for a feature: 'on', 'off', or '' for default.
 * Variations follow their parent.
 *
 * @param int    $product_id Product or variation ID.
 * @param string $feature    FEATURE_IN_CARTS or FEATURE_ACTIVITY.
 * @return string
 */
function product_setting( int $product_id, string $feature ): string {
	$parent = wp_get_post_parent_id( $product_id );
	$value  = (string) get_post_meta( $parent > 0 ? $parent : $product_id, PRODUCT_META[ $feature ], true );
	return in_array( $value, array( 'on', 'off' ), true ) ? $value : '';
}

/**
 * Whether any of the product's categories is excluded in the settings.
 *
 * @param int   $product_id Parent product ID.
 * @param int[] $excluded   Excluded `product_cat` term IDs.
 * @return bool
 */
function in_excluded_category( int $product_id, array $excluded ): bool {
	if ( empty( $excluded ) ) {
		return false;
	}
	$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
	return is_array( $terms ) && array_intersect( array_map( 'intval', $terms ), $excluded ) !== array();
}

/**
 * Whether a shopper-facing feature applies to a product, or to the site when
 * no product is given. A product's own choice always wins: Off is off and On
 * is on, whatever the site switch and the categories say. A product on
 * Default follows the site switch, then the feature's excluded-category
 * list. So the site switch is the default for every product, not a master
 * switch (decided 2026-09-30: a product set to On must work while the site
 * switch is off). The feature's filter runs last on the result, with the
 * product ID as its second argument (0 for the site-wide question).
 *
 * @param string $feature    FEATURE_IN_CARTS or FEATURE_ACTIVITY.
 * @param int    $product_id Product or variation ID, 0 for the site as a whole.
 * @return bool
 */
function feature_enabled( string $feature, int $product_id = 0 ): bool {
	$settings = settings();
	$prefix   = FEATURE_IN_CARTS === $feature ? 'in_carts' : 'activity';
	$enabled  = (bool) $settings[ $prefix . '_enabled' ];

	if ( $product_id > 0 ) {
		$parent   = wp_get_post_parent_id( $product_id );
		$parent   = $parent > 0 ? $parent : $product_id;
		$override = product_setting( $parent, $feature );
		if ( 'on' === $override ) {
			$enabled = true;
		} elseif ( 'off' === $override ) {
			$enabled = false;
		} elseif ( $enabled && in_excluded_category( $parent, $settings[ $prefix . '_excluded_categories' ] ) ) {
			$enabled = false;
		}
	}

	/**
	 * Filters whether the feature applies. Runs after the settings, so code
	 * still has the final say; `$product_id` is 0 for the site-wide check.
	 *
	 * @param bool $enabled    The resolved value.
	 * @param int  $product_id Parent product ID, or 0.
	 */
	return (bool) apply_filters( FEATURE_FILTER[ $feature ], $enabled, $product_id );
}

/**
 * The per-product controls, on a ShopSocket tab of the product data box.
 * Stored on the parent product; variations follow it.
 */
add_filter(
	'woocommerce_product_data_tabs',
	static function ( array $tabs ): array {
		$tabs['shopsocket'] = array(
			'label'    => __( 'ShopSocket', 'shopsocket' ),
			'target'   => 'shopsocket_product_data',
			'class'    => array(),
			'priority' => 75,
		);
		return $tabs;
	}
);

/**
 * The ShopSocket mark as a CSS mask, so the tab icon is the logo in the tab's
 * own text colour, where WooCommerce's tabs use a dashicon.
 *
 * @return string A `url("data:image/svg+xml,...")` value.
 */
function product_tab_icon(): string {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 536.42 558.21">'
		. '<path d="M298.74,517.76c9.92,9.26,31.68,24.34,50.59,30.98,57.29,20.12,107.78,5.9,141.87-26.1,50.95-47.83,65.17-133.96,9.5-195.47,0,0-151.54-161.12-157.92-174.68-6.68-14.2-7.98-38.29,8.77-55.04,38.89-43.3,86.54.34,105.29,22.33l57.43-51.05c-69.47-108.12-247.3-89.47-258.43,61.42-.46,101.32,140.32,183.9,194.61,273.59,9.58,19.93,3.2,50.24-24.72,66.99-27.92,16.75-53.41-.83-63.81-9.57-62.96-52.93-256.17-269-274.38-308.68-16.75-36.51,21.95-85.05,62.22-71.78,20.74,4.78,51.84,39.08,51.84,39.08l57.43-51.05C189.59-39.38,11.7-20.72.59,130.16c-3.99,51.05,30.88,93.9,30.88,93.9,0,0,237.78,266.18,267.27,293.7Z"/>'
		. '<path d="M250.94,511.13c-33.26,53.47-128.56,59.24-179.76,28.18C41.48,522.79,0,467.48,0,467.48l66.85-47.15c33.16,37.91,82.58,97.22,128.2,25.54l55.89,65.26Z"/>'
		. '</svg>';
	return 'url("data:image/svg+xml,' . rawurlencode( $svg ) . '")';
}

// The tab's icon, replacing the dashicon WooCommerce's tabs draw in `a::before`.
add_action(
	'admin_head',
	static function (): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}
		$icon = product_tab_icon();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a data URI built here from a constant SVG, url-encoded.
		echo '<style>#woocommerce-product-data ul.wc-tabs li.shopsocket_options a::before { content: ""; display: inline-block; width: 13px; height: 13px; vertical-align: -1px; background-color: currentColor; -webkit-mask: ' . $icon . ' center / contain no-repeat; mask: ' . $icon . ' center / contain no-repeat; }</style>';
	}
);

add_action(
	'woocommerce_product_data_panels',
	static function (): void {
		global $post;
		$product = $post ? wc_get_product( $post->ID ) : null;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$options = array(
			''    => __( 'Default', 'shopsocket' ),
			'on'  => __( 'On', 'shopsocket' ),
			'off' => __( 'Off', 'shopsocket' ),
		);
		echo '<div id="shopsocket_product_data" class="panel woocommerce_options_panel hidden"><div class="options_group shopsocket-product-options">';
		echo '<p class="description" style="display:block;margin:0;padding:12px 12px 4px;">' . sprintf(
			/* translators: %s: a link to the ShopSocket tab of the WordSocket settings page, reading "Settings" */
			esc_html__( 'What shoppers see for this product. Default follows the ShopSocket tab on the %s page, including its excluded categories; On and Off apply here whatever that page says.', 'shopsocket' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=wordsocket&tab=shopsocket' ) ) . '">' . esc_html__( 'Settings', 'shopsocket' ) . '</a>'
		) . '</p>';
		woocommerce_wp_select(
			array(
				'id'          => PRODUCT_META[ FEATURE_IN_CARTS ],
				'label'       => __( 'In-cart count', 'shopsocket' ),
				'description' => __( 'The "shoppers have this in their cart" line on this product.', 'shopsocket' ),
				'desc_tip'    => true,
				'options'     => $options,
				'value'       => product_setting( $product->get_id(), FEATURE_IN_CARTS ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'          => PRODUCT_META[ FEATURE_ACTIVITY ],
				'label'       => __( 'Added-to-cart notification', 'shopsocket' ),
				'description' => __( 'The "someone just added this" notice other shoppers see for this product.', 'shopsocket' ),
				'desc_tip'    => true,
				'options'     => $options,
				'value'       => product_setting( $product->get_id(), FEATURE_ACTIVITY ),
			)
		);
		echo '</div></div>';
	}
);

add_action(
	'woocommerce_admin_process_product_object',
	static function ( WC_Product $product ): void {
		foreach ( PRODUCT_META as $meta_key ) {
			// Nonce and capability are WooCommerce's, checked before this action fires.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$value = isset( $_POST[ $meta_key ] ) ? sanitize_key( wp_unslash( $_POST[ $meta_key ] ) ) : '';
			if ( in_array( $value, array( 'on', 'off' ), true ) ) {
				$product->update_meta_data( $meta_key, $value );
			} else {
				$product->delete_meta_data( $meta_key );
			}
		}
	}
);

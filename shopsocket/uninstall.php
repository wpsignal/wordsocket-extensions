<?php
/**
 * ShopSocket uninstall: the history tables, the option, and the transients.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
foreach ( array( 'shopsocket_journeys', 'shopsocket_cart_items', 'shopsocket_carts' ) as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping the plugin's own tables.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
}
delete_option( 'shopsocket_storefront' );
delete_option( 'shopsocket_db_version' );
delete_transient( 'shopsocket_carts' );
delete_transient( 'shopsocket_carts_pub' );
// The per-product activity throttles: `shopsocket_act_<product id>`.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient rows by prefix, which the API cannot do.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_shopsocket_act_' ) . '%', $wpdb->esc_like( '_transient_timeout_shopsocket_act_' ) . '%' ) );

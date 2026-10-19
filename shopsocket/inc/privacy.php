<?php
/**
 * Privacy: what the history tables hold about a user, exported and erased
 * through WooCommerce's personal data tools, and the policy text.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- The plugin's own tables: names come from the table functions, fragments from prepare(), one placeholder per id.

/*
 * The exporter and eraser. WooCommerce loads after this plugin (plugin order
 * is alphabetical), so the class that extends its base is in a file of its
 * own, required once the base exists.
 */
add_action(
	'init',
	static function (): void {
		if ( class_exists( \WC_Abstract_Privacy::class ) ) {
			require_once DIR . 'inc/class-privacy.php';
			new Privacy();
		}
	}
);

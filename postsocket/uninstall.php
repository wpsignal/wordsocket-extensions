<?php
/**
 * Uninstall: the settings go, the live blogs and entries stay.
 *
 * @package WPSignal\Extensions\PostSocket
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'postsocket_settings' );
delete_option( 'postsocket_settings_flush' );

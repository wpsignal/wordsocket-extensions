<?php
/**
 * Admin: the entry editor's panel, the live editing panel of the enabled post types, the settings tab on WordSocket's page, and the WordSocket version notice.
 *
 * @package WPSignal\Extensions\PostSocket
 */

namespace WPSignal\Extensions\PostSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A webpack-built asset's manifest.
 *
 * @since 0.1.0
 *
 * @param string $name Entry name (`editor`, `settings`).
 * @return array{dependencies: string[], version: string}
 */
function built_asset( string $name ): array {
	$manifest = DIR . 'build/' . $name . '.asset.php';
	return file_exists( $manifest ) ? require $manifest : array(
		'dependencies' => array(),
		'version'      => VERSION,
	);
}

// The entry editor's "Live blog" panel, and the "Live editing" panel of a post of an enabled type.
add_action(
	'enqueue_block_editor_assets',
	static function (): void {
		$screen = get_current_screen();
		if ( ! $screen || ( ENTRY_TYPE !== $screen->post_type && ! in_array( $screen->post_type, living_types(), true ) ) || ! file_exists( DIR . 'build/editor.js' ) ) {
			return;
		}
		$asset = built_asset( 'editor' );
		wp_enqueue_script( SLUG . '-editor', URL . 'build/editor.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( SLUG . '-editor', 'postsocket', DIR . 'languages' );
		wp_add_inline_script(
			SLUG . '-editor',
			'window.postsocketEditor = ' . wp_json_encode(
				array(
					'taxonomy'     => LIVEBLOG_TAX,
					'restBase'     => LIVEBLOG_REST_BASE,
					'entryType'    => ENTRY_TYPE,
					'livingTypes'  => living_types(),
					'preselected'  => isset( $_GET['liveblog'] ) ? absint( $_GET['liveblog'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'deskUrl'      => desk_url(),
					'meta'         => array(
						'state'  => META_STATE,
						'pinned' => META_PINNED,
					),
					'wordsocketOk' => wordsocket_ready(),
				)
			) . ';',
			'before'
		);
	}
);

// The settings tab and the Extensions-tab card on WordSocket's settings page.
add_action(
	'wordsocket_settings_enqueue',
	static function (): void {
		if ( ! file_exists( DIR . 'build/settings.js' ) ) {
			return;
		}
		$asset = built_asset( 'settings' );
		wp_enqueue_script( SLUG . '-settings', URL . 'build/settings.js', array_merge( $asset['dependencies'], array( 'wpsignal-settings' ) ), $asset['version'], true );
		wp_set_script_translations( SLUG . '-settings', 'postsocket', DIR . 'languages' );
		wp_add_inline_script(
			SLUG . '-settings',
			'window.postsocketSettings = ' . wp_json_encode(
				array(
					'version'      => VERSION,
					'settingsUrl'  => rest_url( REST_NS . '/settings' ),
					'liveblogsUrl' => desk_url(),
					'wordsocketOk' => wordsocket_ready(),
					'minVersion'   => MIN_WORDSOCKET,
				)
			) . ';',
			'before'
		);
	}
);

// A notice while WordSocket is older than this plugin needs.
add_action(
	'admin_notices',
	static function (): void {
		if ( wordsocket_ready() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: the oldest WordSocket version PostSocket runs with */
					__( 'PostSocket needs WordSocket %s or newer to publish live updates. Live blogs and entries still save; readers see them on their next page load until WordSocket is updated.', 'postsocket' ),
					MIN_WORDSOCKET
				)
			)
		);
	}
);

<?php
/**
 * The Live Desk: the admin screen where live blogs and their entries are run.
 *
 * - A top-level "Live Blogs" page renders the React desk from `build/desk.js`, under a header in WordSocket's shape.
 * - The core entries list redirects to it, and the entry editor's menu highlight points at it.
 *
 * @package WPSignal\Extensions\PostSocket
 */

namespace WPSignal\Extensions\PostSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The menu entry and its assets, enqueued as the page loads so the styles print in the head.
add_action(
	'admin_menu',
	static function (): void {
		$hook = add_menu_page(
			__( 'Live Blogs', 'postsocket' ),
			__( 'Live Blogs', 'postsocket' ),
			'edit_posts',
			DESK_PAGE,
			__NAMESPACE__ . '\render_desk',
			menu_icon(),
			5
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, __NAMESPACE__ . '\enqueue_desk' );
		}
		add_submenu_page( DESK_PAGE, __( 'Live Blogs', 'postsocket' ), __( 'All Live Blogs', 'postsocket' ), 'edit_posts', DESK_PAGE );
		add_submenu_page( DESK_PAGE, __( 'Add Live Blog Entry', 'postsocket' ), __( 'Add Live Blog Entry', 'postsocket' ), 'edit_posts', add_entry_slug() );
	}
);

/**
 * The menu slug of the "Add Live Blog Entry" submenu item, the new entry screen's own address.
 *
 * @since 0.1.0
 *
 * @return string
 */
function add_entry_slug(): string {
	return 'post-new.php?post_type=' . ENTRY_TYPE;
}

/**
 * The PostSocket mark as the data URI the admin menu takes.
 *
 * @since 0.1.0
 *
 * @return string
 */
function menu_icon(): string {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="-69.5 -89.85 650 650">'
		. '<path fill="currentColor" d="M510.99.01v96.39s-39-11.04-77.26,15.45c-38.26,26.49-36.05,66.96-39,94.18-2.94,27.22-10.3,69.9-55.18,116.99-44.88,47.09-108.9,52.24-108.9,52.24v-92.71s54.45-19.13,66.22-70.64c11.77-51.51-2.94-77.75,36.79-130.97C369.23,33.3,409.45-.72,510.99.01Z"/>'
		. '<path fill="currentColor" d="M315.41,50.29s-42.43,48.32-42.43,85.84c0,0-40.47-52.24-104.48-39-64.01,13.24-85.35,86.09-62.54,135.39,22.81,49.3,82.41,50.03,82.41,50.03v92.71s-36.79,5.15-91.97-22.07v116.26S29.43,484.16,0,391.45v-221.47S3.68,69.91,105.22,19.88s199.89,11.28,210.19,30.41Z"/>'
		. '</svg>';
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The encoding the menu icon's data URI needs.
	return 'data:image/svg+xml;base64,' . base64_encode( $svg );
}

/**
 * The query string that marks links from the desk to wpsignal.io.
 *
 * @since 0.1.0
 */
const UTM = '?utm_source=wordpress&utm_medium=plugin&utm_campaign=postsocket-desk';

/**
 * Print the desk's header and root element.
 *
 * @since 0.1.0
 *
 * @return void
 */
function render_desk(): void {
	echo '<div class="wrap">';
	render_header();
	echo '<div id="postsocket-desk"></div>';
	echo '</div>';
}

/**
 * Print the page header in WordSocket's shape: the title, then the meta links and the PostSocket mark.
 *
 * @since 0.1.0
 *
 * @return void
 */
function render_header(): void {
	$new_tab = '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'postsocket' ) . '</span>';
	$home    = 'https://wpsignal.io/extensions/postsocket/' . UTM;
	$links   = array(
		array( $home, __( 'Documentation', 'postsocket' ) ),
		array( 'https://wordpress.org/support/plugin/postsocket/', __( 'Support', 'postsocket' ) ),
	);
	if ( class_exists( \WPSignal\WPS::class ) ) {
		array_unshift( $links, array( trailingslashit( \WPSignal\WPS::instance()->config()->base_url() ) . 'dashboard' . UTM, __( 'Dashboard', 'postsocket' ) ) );
	}

	echo '<header class="postsocket-header">';
	echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';
	echo '<nav class="postsocket-meta-nav" aria-label="' . esc_attr__( 'External links', 'postsocket' ) . '">';
	foreach ( $links as list( $url, $label ) ) {
		echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . $new_tab . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $new_tab is escaped above.
		echo '<span aria-hidden="true"> / </span>';
	}
	echo '<a class="postsocket-logo" href="' . esc_url( $home ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr__( 'PostSocket home (opens in a new tab)', 'postsocket' ) . '">';
	echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 511 485" width="18" height="17" aria-hidden="true" focusable="false">';
	echo '<path fill="currentColor" d="M510.99.01v96.39s-39-11.04-77.26,15.45c-38.26,26.49-36.05,66.96-39,94.18-2.94,27.22-10.3,69.9-55.18,116.99-44.88,47.09-108.9,52.24-108.9,52.24v-92.71s54.45-19.13,66.22-70.64c11.77-51.51-2.94-77.75,36.79-130.97C369.23,33.3,409.45-.72,510.99.01Z"/>';
	echo '<path fill="currentColor" d="M315.41,50.29s-42.43,48.32-42.43,85.84c0,0-40.47-52.24-104.48-39-64.01,13.24-85.35,86.09-62.54,135.39,22.81,49.3,82.41,50.03,82.41,50.03v92.71s-36.79,5.15-91.97-22.07v116.26S29.43,484.16,0,391.45v-221.47S3.68,69.91,105.22,19.88s199.89,11.28,210.19,30.41Z"/>';
	echo '</svg>';
	echo '</a>';
	echo '</nav>';
	echo '</header>';
}

/**
 * Enqueue the desk's script, its bundled styles and its config.
 *
 * @since 0.1.0
 *
 * @return void
 */
function enqueue_desk(): void {
	if ( ! file_exists( DIR . 'build/desk.js' ) ) {
		return;
	}
	$asset = built_asset( 'desk' );
	wp_enqueue_script( SLUG . '-desk', URL . 'build/desk.js', $asset['dependencies'], $asset['version'], true );
	wp_set_script_translations( SLUG . '-desk', 'postsocket', DIR . 'languages' );
	foreach ( array( 'style-desk', 'desk' ) as $name ) {
		if ( file_exists( DIR . 'build/' . $name . '.css' ) ) {
			wp_enqueue_style( SLUG . '-' . $name, URL . 'build/' . $name . '.css', array(), $asset['version'] );
			wp_style_add_data( SLUG . '-' . $name, 'rtl', 'replace' );
		}
	}
	wp_add_inline_script(
		SLUG . '-desk',
		'window.postsocketDesk = ' . wp_json_encode(
			array(
				'taxonomy'      => LIVEBLOG_TAX,
				'restBase'      => LIVEBLOG_REST_BASE,
				'entryType'     => ENTRY_TYPE,
				'deskUrl'       => desk_url(),
				'addEntryUrl'   => add_query_arg( 'post_type', ENTRY_TYPE, admin_url( 'post-new.php' ) ),
				'createPageUrl' => add_query_arg( 'post_type', host_post_type(), admin_url( 'post-new.php' ) ),
				'editUrl'       => admin_url( 'post.php?action=edit&post=' ),
				'meta'          => array(
					'state'   => META_STATE,
					'started' => META_STARTED,
					'ended'   => META_ENDED,
					'home'    => META_HOME,
					'pinned'  => META_PINNED,
				),
				'channel'       => NS . ':liveblog:',
				'deskChannel'   => DESK_NS . ':liveblog:',
				'restNamespace' => REST_NS,
				'canManage'     => current_user_can( 'edit_others_posts' ),
				'wordsocketOk'  => wordsocket_ready(),
				'minVersion'    => MIN_WORDSOCKET,
			)
		) . ';',
		'before'
	);
}

// The core entries list is replaced by the desk.
add_action(
	'load-edit.php',
	static function (): void {
		$type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ENTRY_TYPE !== $type ) {
			return;
		}
		wp_safe_redirect( desk_url() );
		exit;
	}
);

// The entry editor sits under the desk's menu entry.
add_filter(
	'parent_file',
	static function ( ?string $parent_file ): ?string {
		$screen = get_current_screen();
		return $screen && ENTRY_TYPE === $screen->post_type ? DESK_PAGE : $parent_file;
	}
);

// A new entry's editor highlights "Add Live Blog Entry", an existing entry's the list.
add_filter(
	'submenu_file',
	static function ( ?string $submenu_file ): ?string {
		$screen = get_current_screen();
		if ( ! $screen || ENTRY_TYPE !== $screen->post_type ) {
			return $submenu_file;
		}
		return 'add' === $screen->action ? add_entry_slug() : DESK_PAGE;
	}
);

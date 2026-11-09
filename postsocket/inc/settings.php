<?php
/**
 * Settings: one autoloaded option, its defaults and sanitizer, and the REST route the settings tab uses.
 *
 * @package WPSignal\Extensions\PostSocket
 */

namespace WPSignal\Extensions\PostSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default settings.
 *
 * @since 0.1.0
 */
const DEFAULTS = array(
	'living_types'     => array(),
	'entries_per_page' => 20,
	'show_author'      => true,
	'show_times'       => true,
	'rewrite_slug'     => 'live',
);

/**
 * Bounds of the entries-per-page setting.
 *
 * @since 0.1.0
 */
const ENTRIES_MIN = 5;
const ENTRIES_MAX = 100;

/**
 * The saved settings over the defaults.
 *
 * @since 0.1.0
 *
 * @return array{living_types: string[], entries_per_page: int, show_author: bool, show_times: bool, rewrite_slug: string}
 */
function settings(): array {
	$saved = get_option( OPTION, array() );
	return sanitize_settings( is_array( $saved ) ? $saved : array() );
}

/**
 * Coerce a settings array into the stored shape; unknown keys are dropped and missing ones take the default.
 *
 * @since 0.1.0
 *
 * @param array $input Raw settings.
 * @return array
 */
function sanitize_settings( array $input ): array {
	$types = array();
	foreach ( (array) ( $input['living_types'] ?? array() ) as $type ) {
		$type = sanitize_key( (string) $type );
		if ( '' !== $type && ! in_array( $type, array( LIVEBLOG_TYPE, ENTRY_TYPE ), true ) ) {
			$types[] = $type;
		}
	}
	$per_page = (int) ( $input['entries_per_page'] ?? DEFAULTS['entries_per_page'] );
	$slug     = sanitize_title( (string) ( $input['rewrite_slug'] ?? DEFAULTS['rewrite_slug'] ) );

	return array(
		'living_types'     => array_values( array_unique( $types ) ),
		'entries_per_page' => max( ENTRIES_MIN, min( ENTRIES_MAX, $per_page ) ),
		'show_author'      => (bool) ( $input['show_author'] ?? DEFAULTS['show_author'] ),
		'show_times'       => (bool) ( $input['show_times'] ?? DEFAULTS['show_times'] ),
		'rewrite_slug'     => '' !== $slug ? $slug : DEFAULTS['rewrite_slug'],
	);
}

/**
 * Save settings and queue a rewrite flush when the slug changed.
 *
 * @since 0.1.0
 *
 * @param array $input Raw settings.
 * @return array The saved settings.
 */
function save_settings( array $input ): array {
	$before = settings();
	$after  = sanitize_settings( array_merge( $before, $input ) );
	update_option( OPTION, $after, true );
	if ( $before['rewrite_slug'] !== $after['rewrite_slug'] ) {
		update_option( OPTION . '_flush', 1, false );
	}
	return $after;
}

/**
 * The public post types whose saved edits readers see live: the live blog always, plus the enabled ones that exist.
 *
 * @since 0.1.0
 *
 * @return string[]
 */
function living_types(): array {
	$types = array( LIVEBLOG_TYPE );
	foreach ( settings()['living_types'] as $type ) {
		if ( post_type_exists( $type ) && is_post_type_viewable( $type ) ) {
			$types[] = $type;
		}
	}
	return $types;
}

/**
 * The public post types a site may enable, as `{ name, label }`, PostSocket's own left out.
 *
 * @since 0.1.0
 *
 * @return array<int, array{name: string, label: string}>
 */
function living_type_choices(): array {
	$choices = array();
	foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
		if ( in_array( $type->name, array( LIVEBLOG_TYPE, ENTRY_TYPE, 'attachment' ), true ) ) {
			continue;
		}
		$choices[] = array(
			'name'  => $type->name,
			'label' => $type->labels->name,
		);
	}
	return $choices;
}

/**
 * The live blog's URL base.
 *
 * @since 0.1.0
 *
 * @return string
 */
function rewrite_slug(): string {
	/**
	 * Filters the live blog post type's URL base.
	 *
	 * @since 0.1.0
	 *
	 * @param string $slug The saved slug, `live` by default.
	 */
	$slug = (string) apply_filters( 'postsocket_rewrite_slug', settings()['rewrite_slug'] );
	return '' !== $slug ? $slug : DEFAULTS['rewrite_slug'];
}

// Settings route.
add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			REST_NS,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => static fn() => current_user_can( 'manage_options' ),
					'callback'            => static fn() => rest_ensure_response( settings_response() ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => static fn() => current_user_can( 'manage_options' ),
					'callback'            => static function ( \WP_REST_Request $request ) {
						save_settings( (array) $request->get_params() );
						return rest_ensure_response( settings_response() );
					},
				),
			)
		);
	}
);

/**
 * The settings route's answer: the settings, the post types on offer and the bounds.
 *
 * @since 0.1.0
 *
 * @return array
 */
function settings_response(): array {
	return array(
		'settings' => settings(),
		'types'    => living_type_choices(),
		'bounds'   => array(
			'entries_min' => ENTRIES_MIN,
			'entries_max' => ENTRIES_MAX,
		),
	);
}

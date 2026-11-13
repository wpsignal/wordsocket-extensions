<?php
/**
 * Rendering for the page and for the relay: entry markup, post content, the scripts, modules, styles and generated CSS a payload names, the content hash.
 *
 * @package WPSignal\Extensions\PostSocket
 */

namespace WPSignal\Extensions\PostSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether content is being rendered for a relay payload, so the page decorations stay out of it.
 *
 * @since 0.1.0
 *
 * @param bool|null $set Set the flag; null reads it.
 * @return bool
 */
function rendering_for_relay( ?bool $set = null ): bool {
	static $flag = false;
	if ( null !== $set ) {
		$flag = $set;
	}
	return $flag;
}

/**
 * A post's content through `the_content`, with the page decorations held off.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $post The post.
 * @return string
 */
function render_content( \WP_Post $post ): string {
	global $wp_query;
	$was = rendering_for_relay();
	rendering_for_relay( true );
	$previous = $GLOBALS['post'] ?? null;
	setup_postdata( $post ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$html            = (string) apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	if ( $previous instanceof \WP_Post ) {
		setup_postdata( $previous );
	} elseif ( $wp_query instanceof \WP_Query ) {
		$wp_query->reset_postdata();
	}
	rendering_for_relay( $was );
	return $html;
}

/**
 * Run a render as a signed-out visitor, collecting the scripts, script modules and stylesheets it enqueued, with their dependencies, the Interactivity API state it set, the CSS WordPress generated for it, and the SVG filters its duotones use.
 *
 * Scripts come from a registry built for the visitor, so inline data such as core's REST nonce is not the signed-in user's.
 *
 * @since 0.1.0
 *
 * @param callable $render Returns the markup.
 * @return array{html: string, assets: array}
 */
function with_render_assets( callable $render ): array {
	global $wp_scripts;
	register_block_assets();
	$page    = wp_scripts();
	$styles  = wp_styles();
	$queued  = $styles->queue;
	$modules = module_queue();
	$inline  = array();
	foreach ( $styles->registered as $handle => $style ) {
		$inline[ $handle ] = count( (array) ( $style->extra['after'] ?? array() ) );
	}
	$islands       = interactivity_data();
	$user          = get_current_user_id();
	$styles->queue = array();
	wp_set_current_user( 0 );
	$wp_scripts           = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$scripts              = wp_scripts();
	$scripts->registered += $page->registered;
	try {
		$html = (string) $render();
	} finally {
		$wp_scripts = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_set_current_user( $user );
		$enqueued      = array( $scripts->queue, $styles->queue );
		$styles->queue = array_values( array_unique( array_merge( $queued, $enqueued[1] ) ) );
	}
	$svg = duotone_assets();
	$css = array();
	foreach ( $styles->registered as $handle => $style ) {
		$added = array_slice( (array) ( $style->extra['after'] ?? array() ), $inline[ $handle ] ?? 0 );
		$css   = array_merge( $css, array_map( 'strval', $added ) );
	}
	$css[]       = block_support_css( $html );
	$assets      = array(
		'styles'  => style_assets( $enqueued[1] ),
		'scripts' => script_assets( $scripts, $enqueued[0] ),
		'modules' => module_assets( array_values( array_diff( module_queue(), $modules ) ) ),
	);
	$interactive = interactivity_added( $islands, interactivity_data() );
	if ( $interactive ) {
		$assets['interactivity'] = $interactive;
	}
	$css = trim( implode( "\n", array_filter( $css ) ) );
	if ( '' !== $css ) {
		$assets['css'] = $css;
	}
	if ( '' !== $svg ) {
		$assets['svg'] = $svg;
	}
	return array(
		'html'   => $html,
		'assets' => $assets,
	);
}

/**
 * Have WordPress hand over what it holds back for the duotone filters a render used: their rules go to the block supports store, their presets to the global styles' inline CSS, and their SVG filters are returned.
 *
 * @since 0.1.0
 *
 * @return string The SVG filter definitions, empty when the render used none.
 */
function duotone_assets(): string {
	if ( ! class_exists( \WP_Duotone::class ) ) {
		return '';
	}
	if ( ! wp_style_is( 'global-styles', 'registered' ) ) {
		wp_register_style( 'global-styles', false, array(), VERSION );
	}
	\WP_Duotone::output_block_styles();
	\WP_Duotone::output_global_styles();
	ob_start();
	\WP_Duotone::output_footer_assets();
	return trim( (string) ob_get_clean() );
}

/**
 * Fire `enqueue_block_assets` once in a request that has not, such as a REST save, so the handles blocks register there exist; what it enqueues is dropped.
 *
 * @since 0.1.0
 *
 * @return void
 */
function register_block_assets(): void {
	if ( did_action( 'enqueue_block_assets' ) ) {
		return;
	}
	$scripts = wp_scripts();
	$styles  = wp_styles();
	$queued  = array( $scripts->queue, $styles->queue );
	do_action( 'enqueue_block_assets' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	$scripts->queue = $queued[0];
	$styles->queue  = $queued[1];
}

/**
 * The Interactivity API state and config set so far, as a page prints them.
 *
 * @since 0.1.0
 *
 * @return array{state?: array, config?: array}
 */
function interactivity_data(): array {
	if ( ! function_exists( 'wp_interactivity' ) || ! method_exists( wp_interactivity(), 'filter_script_module_interactivity_data' ) ) {
		return array();
	}
	return wp_interactivity()->filter_script_module_interactivity_data( array() );
}

/**
 * The namespaces whose Interactivity API state or config a render added or changed, whole.
 *
 * @since 0.1.0
 *
 * @param array $before The data before the render.
 * @param array $after  The data after it.
 * @return array{state?: array, config?: array}
 */
function interactivity_added( array $before, array $after ): array {
	$added = array();
	foreach ( array( 'state', 'config' ) as $part ) {
		foreach ( (array) ( $after[ $part ] ?? array() ) as $ns => $value ) {
			if ( wp_json_encode( $value ) !== wp_json_encode( $before[ $part ][ $ns ] ?? null ) ) {
				$added[ $part ][ $ns ] = $value;
			}
		}
	}
	return $added;
}

/**
 * The view script handles of the blocks a site names with `postsocket_rerun_blocks`.
 *
 * @since 0.1.0
 *
 * @return array<string, true>
 */
function rerun_handles(): array {
	/**
	 * Filters the blocks whose view scripts run again for each live-inserted copy, with their document queries limited to the new markup while their ready handlers run.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $blocks Block names, none by default.
	 */
	$blocks  = (array) apply_filters( 'postsocket_rerun_blocks', array() );
	$handles = array();
	foreach ( $blocks as $name ) {
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( (string) $name );
		foreach ( $type ? (array) $type->view_script_handles : array() as $handle ) {
			$handles[ (string) $handle ] = true;
		}
	}
	return $handles;
}

/**
 * Scripts in the order a page prints them, dependencies first, each with its URL and inline code.
 *
 * @since 0.1.0
 *
 * @param \WP_Scripts $deps    The registry the render used.
 * @param string[]    $handles Enqueued handles.
 * @return array<int, array{id: string, src?: string, before?: string, after?: string, rerun?: bool}>
 */
function script_assets( \WP_Scripts $deps, array $handles ): array {
	$out   = array();
	$seen  = array();
	$rerun = rerun_handles();
	$visit = static function ( string $handle ) use ( &$visit, &$out, &$seen, $deps, $rerun ): void {
		if ( isset( $seen[ $handle ] ) || ! isset( $deps->registered[ $handle ] ) ) {
			return;
		}
		$seen[ $handle ] = true;
		foreach ( (array) $deps->registered[ $handle ]->deps as $dep ) {
			$visit( (string) $dep );
		}
		$before = array(
			(string) $deps->get_data( $handle, 'data' ),
			(string) $deps->print_translations( $handle, false ),
			$deps->get_inline_script_data( $handle, 'before' ),
		);
		$item   = array_filter(
			array(
				'id'     => $handle,
				'src'    => dependency_url( $deps, $handle ),
				'before' => trim( implode( "\n", array_filter( $before ) ) ),
				'after'  => $deps->get_inline_script_data( $handle, 'after' ),
			)
		);
		if ( isset( $rerun[ $handle ] ) && isset( $item['src'] ) ) {
			$item['rerun'] = true;
		}
		if ( count( $item ) > 1 ) {
			$out[] = $item;
		}
	};
	foreach ( $handles as $handle ) {
		$visit( (string) $handle );
	}
	return $out;
}

/**
 * Stylesheets with their dependencies, dependencies first.
 *
 * @since 0.1.0
 *
 * @param string[] $handles Enqueued handles.
 * @return array<int, array{id: string, href: string}>
 */
function style_assets( array $handles ): array {
	$deps  = wp_styles();
	$out   = array();
	$seen  = array();
	$visit = static function ( string $handle ) use ( &$visit, &$out, &$seen, $deps ): void {
		if ( isset( $seen[ $handle ] ) || ! isset( $deps->registered[ $handle ] ) ) {
			return;
		}
		$seen[ $handle ] = true;
		foreach ( (array) $deps->registered[ $handle ]->deps as $dep ) {
			$visit( (string) $dep );
		}
		$href = dependency_url( $deps, $handle );
		if ( '' !== $href ) {
			$out[] = array(
				'id'   => $handle,
				'href' => $href,
			);
		}
	};
	foreach ( $handles as $handle ) {
		$visit( (string) $handle );
	}
	return $out;
}

/**
 * The script modules enqueued so far, empty before WordPress 6.9.
 *
 * @since 0.1.0
 *
 * @return string[]
 */
function module_queue(): array {
	$modules = wp_script_modules();
	return method_exists( $modules, 'get_queue' ) ? $modules->get_queue() : array();
}

/**
 * Script modules with their dependencies for the import map, the enqueued ones marked `load`; empty before WordPress 7.0.
 *
 * @since 0.1.0
 *
 * @param string[] $ids Enqueued module ids.
 * @return array<int, array{id: string, src: string, load?: bool}>
 */
function module_assets( array $ids ): array {
	$modules = wp_script_modules();
	if ( ! $ids || ! method_exists( $modules, 'get_registered' ) ) {
		return array();
	}
	$out   = array();
	$visit = static function ( string $id, bool $load ) use ( &$visit, &$out, $modules ): void {
		if ( isset( $out[ $id ] ) ) {
			$out[ $id ]['load'] = $load || ! empty( $out[ $id ]['load'] );
			return;
		}
		$module = $modules->get_registered( $id );
		if ( ! $module || '' === (string) $module['src'] ) {
			return;
		}
		$out[ $id ] = array( 'id' => $id );
		foreach ( (array) ( $module['dependencies'] ?? array() ) as $dep ) {
			$visit( (string) ( is_array( $dep ) ? ( $dep['id'] ?? '' ) : $dep ), false );
		}
		$src = (string) $module['src'];
		if ( false === $module['version'] ) {
			$src = add_query_arg( 'ver', get_bloginfo( 'version' ), $src );
		} elseif ( null !== $module['version'] ) {
			$src = add_query_arg( 'ver', $module['version'], $src );
		}
		$out[ $id ]['src'] = (string) apply_filters( 'script_module_loader_src', $src, $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		if ( $load ) {
			$out[ $id ]['load'] = true;
		}
	};
	foreach ( $ids as $id ) {
		$visit( (string) $id, true );
	}
	return array_values( array_filter( $out, static fn( array $module ): bool => isset( $module['src'] ) ) );
}

/**
 * The `block-supports` style engine rules for the classes some markup uses.
 *
 * @since 0.1.0
 *
 * @param string $html Rendered markup.
 * @return string
 */
function block_support_css( string $html ): string {
	if ( ! class_exists( \WP_Style_Engine_CSS_Rules_Store::class ) || ! preg_match_all( '/\bclass="([^"]+)"/', $html, $matches ) ) {
		return '';
	}
	$classes = array_flip( preg_split( '/\s+/', implode( ' ', $matches[1] ), -1, PREG_SPLIT_NO_EMPTY ) );
	$rules   = array();
	foreach ( \WP_Style_Engine_CSS_Rules_Store::get_store( 'block-supports' )->get_all_rules() as $rule ) {
		if ( ! preg_match_all( '/\.([A-Za-z0-9_-]+)/', $rule->get_selector(), $named ) ) {
			continue;
		}
		foreach ( $named[1] as $class ) {
			if ( isset( $classes[ $class ] ) ) {
				$rules[] = $rule;
				break;
			}
		}
	}
	if ( ! $rules ) {
		return '';
	}
	$processor = new \WP_Style_Engine_Processor();
	$processor->add_rules( $rules );
	return $processor->get_css( array( 'prettify' => false ) );
}

/**
 * How many times a post was queued for its readers in this request, which dates its cached render.
 *
 * @since 0.1.0
 *
 * @param int  $post_id Post id.
 * @param bool $bump    True to count one more.
 * @return int
 */
function render_generation( int $post_id, bool $bump = false ): int {
	static $generations = array();
	if ( $bump ) {
		$generations[ $post_id ] = ( $generations[ $post_id ] ?? 0 ) + 1;
	}
	return $generations[ $post_id ] ?? 0;
}

/**
 * A living post as the content route renders it, asked for over HTTP, or null when the route did not answer.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $post The post.
 * @return array{html: string, assets: array, fields: array<string, string>, hash: string}|null
 */
function remote_render( \WP_Post $post ): ?array {
	/**
	 * Filters whether a wp-admin request asks the content route for a living post's render instead of rendering it itself.
	 *
	 * @since 0.1.0
	 *
	 * @param bool     $remote True by default.
	 * @param \WP_Post $post   The post.
	 */
	if ( ! apply_filters( 'postsocket_remote_render', true, $post ) ) {
		return null;
	}
	$response = wp_remote_get(
		add_query_arg( 'v', uniqid(), content_url( $post->ID ) ),
		array(
			'timeout'   => 5,
			'cookies'   => array(),
			/** This filter is documented in wp-includes/class-wp-http-streams.php */
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter.
		)
	);
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) || ! is_string( $data['html'] ?? null ) || ! is_string( $data['hash'] ?? null ) ) {
		return null;
	}
	return array(
		'html'   => $data['html'],
		'assets' => (array) ( $data['assets'] ?? array() ),
		'fields' => array_map( 'strval', (array) ( $data['fields'] ?? array() ) ),
		'hash'   => $data['hash'],
	);
}

/**
 * A living post rendered for the relay, with its live fields, hash and assets, once per request and version; a wp-admin request takes the content route's render, so every context hashes the same markup.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $post The post.
 * @return array{html: string, assets: array, fields: array<string, string>, hash: string}
 */
function rendered_post( \WP_Post $post ): array {
	static $cache = array();
	$key          = $post->ID . ':' . render_generation( $post->ID ) . ':' . md5( $post->post_title . "\0" . $post->post_content );
	if ( ! isset( $cache[ $key ] ) ) {
		$remote = is_admin() ? remote_render( $post ) : null;
		if ( $remote ) {
			$cache[ $key ] = $remote;
			return $remote;
		}
		$fields             = array();
		$rendered           = with_render_assets(
			static function () use ( $post, &$fields ): string {
				$html   = render_content( $post );
				$fields = render_fields( $post );
				return $html;
			}
		);
		$rendered['fields'] = $fields;
		$rendered['hash']   = post_hash( $post, $rendered['html'], $fields );
		$cache[ $key ]      = $rendered;
	}
	return $cache[ $key ];
}

/**
 * The label an entry that readers cannot see carries where its editors preview it, empty for a published one.
 *
 * @since 0.1.0
 *
 * @param string $status The entry's post status.
 * @return string
 */
function unpublished_label( string $status ): string {
	$labels = array(
		'draft'   => __( 'Draft', 'postsocket' ),
		'pending' => __( 'Pending review', 'postsocket' ),
		'future'  => __( 'Scheduled', 'postsocket' ),
	);
	return $labels[ $status ] ?? '';
}

/**
 * An entry as the feed shows it.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $entry The entry.
 * @return string
 */
function render_entry( \WP_Post $entry ): string {
	$settings = settings();
	$pinned   = is_pinned( $entry->ID );
	$status   = unpublished_label( $entry->post_status );
	$date_gmt = '0000-00-00 00:00:00' === $entry->post_date_gmt ? $entry->post_modified_gmt : $entry->post_date_gmt;
	$modified = mysql2date( 'c', $entry->post_modified_gmt, false );
	$date     = mysql2date( 'c', $date_gmt, false );

	$html  = sprintf(
		'<article id="postsocket-%1$d" class="postsocket-entry%2$s" data-postsocket-entry="%1$d" data-postsocket-modified="%3$s" data-postsocket-date="%4$s" data-postsocket-pinned="%5$s"%6$s>',
		(int) $entry->ID,
		( $pinned ? ' is-pinned' : '' ) . ( '' !== $status ? ' is-unpublished' : '' ),
		esc_attr( $modified ),
		esc_attr( $date ),
		$pinned ? '1' : '0',
		'' !== $status ? ' data-postsocket-status="' . esc_attr( $entry->post_status ) . '"' : ''
	);
	$html .= '<header class="postsocket-entry__header">';
	if ( '' !== $status ) {
		$html .= '<span class="postsocket-entry__status">' . esc_html( $status ) . '</span>';
	}
	if ( $pinned ) {
		$html .= '<span class="postsocket-entry__pin">' . esc_html__( 'Pinned', 'postsocket' ) . '</span>';
	}
	if ( $settings['show_times'] ) {
		$html .= sprintf(
			'<a class="postsocket-entry__time" href="%s"><time datetime="%s">%s</time></a>',
			esc_url( entry_link( $entry->ID ) ),
			esc_attr( $date ),
			esc_html( wp_date( get_option( 'time_format' ), strtotime( $date_gmt ) ) )
		);
	}
	if ( $settings['show_author'] && $entry->post_author ) {
		$html .= '<span class="postsocket-entry__author">'
			. get_avatar( (int) $entry->post_author, 32, '', '', array( 'class' => 'postsocket-entry__avatar' ) )
			. '<span class="postsocket-entry__name">' . esc_html( get_the_author_meta( 'display_name', (int) $entry->post_author ) ) . '</span></span>';
	}
	$html .= '</header>';
	if ( '' !== trim( $entry->post_title ) ) {
		$html .= '<h3 class="postsocket-entry__headline">' . esc_html( get_the_title( $entry ) ) . '</h3>';
	}
	$html .= '<div class="postsocket-entry__content">' . render_content( $entry ) . '</div>';
	$html .= '</article>';
	return $html;
}

/**
 * A registered style or script handle's URL, as the page would print it.
 *
 * @since 0.1.0
 *
 * @param \WP_Dependencies $deps   `wp_styles()` or `wp_scripts()`.
 * @param string           $handle The handle.
 * @return string Empty for an alias or an unknown handle.
 */
function dependency_url( \WP_Dependencies $deps, string $handle ): string {
	$item = $deps->registered[ $handle ] ?? null;
	if ( ! $item || empty( $item->src ) ) {
		return '';
	}
	$src = (string) $item->src;
	if ( ! preg_match( '|^(https?:)?//|', $src ) && ! ( $deps->content_url && str_starts_with( $src, $deps->content_url ) ) ) {
		$src = $deps->base_url . $src;
	}
	$ver = $item->ver ?? '';
	if ( null !== $item->ver && false !== $item->ver ) {
		$src = add_query_arg( 'ver', '' !== $ver ? $ver : $deps->default_version, $src );
	}
	return $src;
}

/**
 * A short hash of what a reader sees, used to publish only when it changed; a block style variation's per-request instance number is left out.
 *
 * @since 0.1.0
 *
 * @param string ...$parts Rendered HTML and anything else the payload reflects.
 * @return string
 */
function content_hash( string ...$parts ): string {
	$stable = preg_replace( '/\b(is-style-[a-z0-9_-]+)--\d+\b/', '$1--n', implode( "\0", $parts ) );
	return substr( hash( 'sha256', (string) $stable ), 0, 32 );
}

/**
 * A post's title as plain text, the way readers see it.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $post The post.
 * @return string
 */
function title_text( \WP_Post $post ): string {
	return html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * The hash of a living post: its rendered content, its title, and its live fields when it has any.
 *
 * @since 0.1.0
 *
 * @param \WP_Post              $post   The post.
 * @param string                $html   Its rendered content.
 * @param array<string, string> $fields Its rendered live fields.
 * @return string
 */
function post_hash( \WP_Post $post, string $html, array $fields = array() ): string {
	return content_hash( $html, title_text( $post ), ...( $fields ? array( (string) wp_json_encode( $fields ) ) : array() ) );
}

/**
 * The hash the last publish recorded for a post, computed and recorded once when there is none yet.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $post The post.
 * @return string
 */
function current_hash( \WP_Post $post ): string {
	$stored = (string) get_post_meta( $post->ID, META_HASH, true );
	if ( '' !== $stored ) {
		return $stored;
	}
	$hash = rendered_post( $post )['hash'];
	update_post_meta( $post->ID, META_HASH, $hash );
	return $hash;
}

/**
 * Whether a post's rendered content changed since the last publish, recording the new hash when it did.
 *
 * @since 0.1.0
 *
 * @param int    $post_id Post id.
 * @param string $hash    The current hash.
 * @return bool
 */
function content_changed( int $post_id, string $hash ): bool {
	if ( get_post_meta( $post_id, META_HASH, true ) === $hash ) {
		return false;
	}
	update_post_meta( $post_id, META_HASH, $hash );
	return true;
}

/**
 * Largest `wp_json_encode` size of a payload that carries its HTML inline; above it the browser fetches.
 *
 * @since 0.1.0
 *
 * @return int Bytes.
 */
function inline_limit(): int {
	/**
	 * Filters the largest payload that carries rendered HTML inline, in bytes.
	 *
	 * @since 0.1.0
	 *
	 * @param int $bytes 32768 by default.
	 */
	return max( 1024, (int) apply_filters( 'postsocket_inline_limit', 32768 ) );
}

/**
 * Drop the `html` and the `fields` of a payload that is too large to carry inline and mark it for fetching.
 *
 * @since 0.1.0
 *
 * @param array $payload Payload with an `html` key.
 * @return array
 */
function fit_payload( array $payload ): array {
	if ( strlen( (string) wp_json_encode( $payload ) ) <= inline_limit() ) {
		return $payload;
	}
	unset( $payload['html'], $payload['fields'] );
	$payload['fetch'] = true;
	return $payload;
}

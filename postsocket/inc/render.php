<?php
/**
 * Rendering for the page and for the relay: entry markup, post content, the assets a payload names, the content hash.
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
	$modified = mysql2date( 'c', $entry->post_modified_gmt, false );
	$date     = mysql2date( 'c', $entry->post_date_gmt, false );

	$html  = sprintf(
		'<article id="postsocket-%1$d" class="postsocket-entry%2$s" data-postsocket-entry="%1$d" data-postsocket-modified="%3$s" data-postsocket-date="%4$s" data-postsocket-pinned="%5$s">',
		(int) $entry->ID,
		$pinned ? ' is-pinned' : '',
		esc_attr( $modified ),
		esc_attr( $date ),
		$pinned ? '1' : '0'
	);
	$html .= '<header class="postsocket-entry__header">';
	if ( $pinned ) {
		$html .= '<span class="postsocket-entry__pin">' . esc_html__( 'Pinned', 'postsocket' ) . '</span>';
	}
	if ( $settings['show_times'] ) {
		$html .= sprintf(
			'<a class="postsocket-entry__time" href="%s"><time datetime="%s">%s</time></a>',
			esc_url( entry_link( $entry->ID ) ),
			esc_attr( $date ),
			esc_html( wp_date( get_option( 'time_format' ), strtotime( $entry->post_date_gmt ) ) )
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
 * The block names a post's content uses, nested blocks included.
 *
 * @since 0.1.0
 *
 * @param string $content Serialized block content.
 * @return string[]
 */
function block_names( string $content ): array {
	$names = array();
	$walk  = static function ( array $blocks ) use ( &$names, &$walk ): void {
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$names[ $block['blockName'] ] = true;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$walk( $block['innerBlocks'] );
			}
		}
	};
	$walk( parse_blocks( $content ) );
	return array_keys( $names );
}

/**
 * The stylesheet and script URLs the blocks in a post need, for a browser that inserts its markup without a page load.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $post The post.
 * @return array{styles: string[], scripts: string[]}
 */
function assets_for( \WP_Post $post ): array {
	$registry = \WP_Block_Type_Registry::get_instance();
	$styles   = array();
	$scripts  = array();
	foreach ( block_names( $post->post_content ) as $name ) {
		$type = $registry->get_registered( $name );
		if ( ! $type ) {
			continue;
		}
		foreach ( array_merge( (array) $type->style_handles, (array) $type->view_style_handles ) as $handle ) {
			$url = dependency_url( wp_styles(), (string) $handle );
			if ( '' !== $url ) {
				$styles[ $url ] = true;
			}
		}
		foreach ( (array) $type->view_script_handles as $handle ) {
			$url = dependency_url( wp_scripts(), (string) $handle );
			if ( '' !== $url ) {
				$scripts[ $url ] = true;
			}
		}
	}
	return array(
		'styles'  => array_keys( $styles ),
		'scripts' => array_keys( $scripts ),
	);
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
 * A short hash of what a reader sees, used to publish only when it changed.
 *
 * @since 0.1.0
 *
 * @param string ...$parts Rendered HTML and anything else the payload reflects.
 * @return string
 */
function content_hash( string ...$parts ): string {
	return substr( hash( 'sha256', implode( "\0", $parts ) ), 0, 32 );
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
	$hash = content_hash( render_content( $post ) );
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
 * Drop the `html` of a payload that is too large to carry inline and mark it for fetching.
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
	unset( $payload['html'] );
	$payload['fetch'] = true;
	return $payload;
}

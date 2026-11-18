<?php
/**
 * Template previews for the Live Desk.
 *
 * - One front-end page holds an entry printed in every pattern asked for, so the theme's own head styles it.
 * - Each pattern is printed twice: as an entry and as a pinned entry.
 * - The desk fetches the page once and shows each section in a frame.
 *
 * @package PostSocket
 */

namespace WPSignal\Extensions\PostSocket;

defined( 'ABSPATH' ) || exit;

/**
 * The query argument that asks the front end for template previews; its value is the live blog id.
 *
 * @since 0.1.0
 */
const PREVIEW_ARG = 'postsocket_template_preview';

/**
 * The query argument that lists the pattern ids to preview, comma separated; 0 is the default layout.
 *
 * @since 0.1.0
 */
const PREVIEW_PATTERNS_ARG = 'patterns';

/**
 * The most patterns one preview page prints.
 *
 * @since 0.1.0
 */
const PREVIEW_MAX = 20;

/**
 * The address of the preview page, nonced for the current user, without a live blog or patterns.
 *
 * @since 0.1.0
 *
 * @return string
 */
function template_preview_url(): string {
	return add_query_arg( '_wpnonce', wp_create_nonce( PREVIEW_ARG ), home_url( '/' ) );
}

/**
 * The entry previews are printed with: the live blog's newest published one, else any live blog's, else its newest unpublished one.
 *
 * @since 0.1.0
 *
 * @param int $liveblog_id Live blog id.
 * @return \WP_Post|null
 */
function preview_entry( int $liveblog_id ): ?\WP_Post {
	$own = entries_query( $liveblog_id, array( 'posts_per_page' => 1 ) )->posts;
	if ( $own ) {
		return $own[0];
	}
	$any = get_posts(
		array(
			'post_type'   => ENTRY_TYPE,
			'post_status' => 'publish',
			'numberposts' => 1,
		)
	);
	if ( $any ) {
		return $any[0];
	}
	$unpublished = entries_query(
		$liveblog_id,
		array(
			'post_status'    => array( 'future', 'pending', 'draft' ),
			'posts_per_page' => 1,
		)
	)->posts;
	return $unpublished[0] ?? null;
}

/**
 * An entry printed in each pattern, as an entry and as a pinned one, keyed `{id}` and `{id}:pinned`; 0 is the default layout.
 *
 * @since 0.1.0
 *
 * @param int   $liveblog_id Live blog id.
 * @param int[] $pattern_ids Pattern ids; one that is not an entry template is left out.
 * @return array<string, string>
 */
function template_previews( int $liveblog_id, array $pattern_ids ): array {
	$entry = preview_entry( $liveblog_id );
	if ( ! $entry ) {
		return array();
	}
	$previews = array();
	$as       = false;
	// The entry reads as pinned, or not, whatever it is.
	$pin = static function ( $value, $object_id, $meta_key ) use ( $entry, &$as ) {
		return (int) $object_id === (int) $entry->ID && META_PINNED === $meta_key ? array( $as ? '1' : '' ) : $value;
	};
	add_filter( 'get_post_metadata', $pin, 10, 3 );
	try {
		foreach ( array_slice( array_unique( array_map( 'absint', $pattern_ids ) ), 0, PREVIEW_MAX ) as $pattern_id ) {
			$template = null;
			if ( $pattern_id > 0 ) {
				$valid    = valid_template( $pattern_id );
				$template = $valid > 0 ? find_block( parse_blocks( (string) get_post( $valid )->post_content ), ENTRY_BLOCK ) : null;
				if ( ! $template ) {
					continue;
				}
			}
			foreach ( array( false, true ) as $as ) {
				$previews[ $pattern_id . ( $as ? ':pinned' : '' ) ] = render_entry_in( $entry, $template );
			}
		}
	} finally {
		remove_filter( 'get_post_metadata', $pin, 10 );
	}
	return $previews;
}

/**
 * Print the preview page: the theme's head, then each preview in a feed of its own.
 *
 * @since 0.1.0
 *
 * @param array<string, string> $previews Entry markup by preview key.
 * @return void
 */
function print_template_previews( array $previews ): void {
	register_reader_modules();
	wp_enqueue_style( SLUG . '-reader' );
	wp_add_inline_style( SLUG . '-reader', 'body.postsocket-template-preview{margin:0;padding:24px}body.postsocket-template-preview .postsocket-feed{margin:0}' );
	nocache_headers();
	header( 'X-Robots-Tag: noindex' );
	?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'postsocket-template-preview' ); ?>>
	<?php
	foreach ( $previews as $key => $html ) {
		printf(
			'<section class="postsocket-feed" data-postsocket-preview="%s"><div class="%s">%s</div></section>',
			esc_attr( $key ),
			str_ends_with( (string) $key, ':pinned' ) ? 'postsocket-feed__pinned' : 'postsocket-feed__entries',
			$html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}
	wp_footer();
	?>
</body>
</html>
	<?php
}

// The preview page, for those who may choose a live blog's template.
add_action(
	'template_redirect',
	static function (): void {
		if ( ! isset( $_GET[ PREVIEW_ARG ] ) ) {
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! current_user_can( 'edit_others_posts' ) || ! wp_verify_nonce( $nonce, PREVIEW_ARG ) ) {
			wp_die( esc_html__( 'You are not allowed to preview entry templates.', 'postsocket' ), 403 );
		}
		$liveblog_id = absint( wp_unslash( $_GET[ PREVIEW_ARG ] ) );
		$patterns    = isset( $_GET[ PREVIEW_PATTERNS_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ PREVIEW_PATTERNS_ARG ] ) ) : '0';
		add_filter( 'show_admin_bar', '__return_false' );
		print_template_previews( template_previews( $liveblog_id, wp_parse_id_list( $patterns ) ) );
		exit;
	},
	0
);

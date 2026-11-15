<?php
/**
 * PostSocket's blocks.
 *
 * - `postsocket/live-feed`: the live blog named by its `liveblog` attribute, rendered by `render_feed()`.
 * - `postsocket/entry`: the element an entry is printed in, for a live blog's entry template.
 * - `postsocket/entry-content`: the entry's content, inside that block.
 *
 * @package WPSignal\Extensions\PostSocket
 */

namespace WPSignal\Extensions\PostSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	static function (): void {
		$blocks = array(
			'live-feed'     => 'render_live_feed_block',
			'entry'         => 'render_entry_block',
			'entry-content' => 'render_entry_content_block',
		);
		foreach ( $blocks as $dir => $callback ) {
			if ( ! file_exists( DIR . 'build/blocks/' . $dir . '/block.json' ) ) {
				return;
			}
			register_block_type(
				DIR . 'build/blocks/' . $dir,
				array(
					'render_callback' => __NAMESPACE__ . '\\' . $callback,
				)
			);
		}
		wp_add_inline_script(
			generate_block_asset_handle( ENTRY_BLOCK, 'editorScript' ),
			'window.postsocketEntryBlock = ' . wp_json_encode( array( 'blocks' => entry_template_blocks() ) ) . ';',
			'before'
		);
		wp_add_inline_script(
			generate_block_asset_handle( FEED_BLOCK, 'editorScript' ),
			'window.postsocketBlock = ' . wp_json_encode(
				array(
					'taxonomy'  => LIVEBLOG_TAX,
					'deskUrl'   => desk_url(),
					'canManage' => current_user_can( 'edit_others_posts' ),
					'meta'      => array( 'state' => META_STATE ),
				)
			) . ';',
			'before'
		);
	}
);

/**
 * The empty marker a relay render puts where a feed sits, so the browser keeps its own feed there.
 *
 * @since 0.1.0
 *
 * @param int $liveblog_id Live blog id.
 * @return string
 */
function feed_slot( int $liveblog_id ): string {
	return '<div data-postsocket-feed-slot="' . $liveblog_id . '" hidden></div>';
}

/**
 * Render the block: the feed of the live blog it names, a slot for it in a relay render, or nothing.
 *
 * @since 0.1.0
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function render_live_feed_block( array $attributes ): string {
	$liveblog_id = (int) ( $attributes['liveblog'] ?? 0 );
	if ( $liveblog_id <= 0 ) {
		return '';
	}
	if ( rendering_for_relay() ) {
		return feed_slot( $liveblog_id );
	}
	return render_feed( $liveblog_id );
}

/**
 * The entry a block is rendered for, from its context, or null.
 *
 * @since 0.1.0
 *
 * @param \WP_Block $block Block instance.
 * @return \WP_Post|null
 */
function block_entry( \WP_Block $block ): ?\WP_Post {
	$entry = get_post( (int) ( $block->context['postId'] ?? 0 ) );
	return $entry && is_entry( $entry ) ? $entry : null;
}

/**
 * Render the Live Blog Entry block: the entry's element, its state labels and the inner blocks; nothing outside a feed.
 *
 * @since 0.1.0
 *
 * @param array     $attributes Block attributes.
 * @param string    $content    The rendered inner blocks.
 * @param \WP_Block $block      Block instance.
 * @return string
 */
function render_entry_block( array $attributes, string $content, \WP_Block $block ): string {
	$entry = block_entry( $block );
	if ( ! $entry ) {
		return '';
	}
	$labels = entry_labels( $entry );
	return sprintf(
		'<article %s>%s%s</article>',
		get_block_wrapper_attributes( entry_attributes( $entry ) ),
		'' !== $labels ? '<header class="postsocket-entry__header">' . $labels . '</header>' : '',
		$content
	);
}

/**
 * Render the Entry Content block: the entry's content, never inside itself.
 *
 * @since 0.1.0
 *
 * @param array     $attributes Block attributes.
 * @param string    $content    Unused.
 * @param \WP_Block $block      Block instance.
 * @return string
 */
function render_entry_content_block( array $attributes, string $content, \WP_Block $block ): string {
	static $rendering = array();
	$entry            = block_entry( $block );
	if ( ! $entry || isset( $rendering[ $entry->ID ] ) ) {
		return '';
	}
	$rendering[ $entry->ID ] = true;
	try {
		$html = render_content( $entry );
	} finally {
		unset( $rendering[ $entry->ID ] );
	}
	return '<div ' . get_block_wrapper_attributes( array( 'class' => 'postsocket-entry__content' ) ) . '>' . $html . '</div>';
}

/**
 * The blocks an entry template is built from besides the Live Blog Entry block itself.
 *
 * @since 0.1.0
 *
 * @return string[]
 */
function entry_template_blocks(): array {
	/**
	 * Filters the blocks PostSocket keeps available inside the Live Blog Entry block on a site that limits or removes blocks.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $blocks Block names.
	 */
	return array_values(
		array_unique(
			(array) apply_filters(
				'postsocket_entry_template_blocks',
				array(
					ENTRY_CONTENT_BLOCK,
					'core/post-date',
					'core/post-title',
					'core/post-featured-image',
					'core/post-terms',
					'core/post-author',
					'core/post-author-name',
					'core/avatar',
					'core/post-excerpt',
					'core/post-navigation-link',
					'core/read-more',
				)
			)
		)
	);
}

/**
 * Add the entry template's blocks to a site's list of allowed blocks, and tell the editor which of them the site had left out.
 *
 * @since 0.1.0
 *
 * @param bool|string[] $allowed The allowed block names, or a boolean for all or none.
 * @return bool|string[]
 */
function allow_entry_blocks( bool|array $allowed ): bool|array {
	if ( ! is_array( $allowed ) ) {
		return $allowed;
	}
	$added = array_values( array_diff( entry_template_blocks(), $allowed ) );
	$entry = in_array( ENTRY_BLOCK, $allowed, true ) ? array() : array( ENTRY_BLOCK );
	if ( $added ) {
		restricted_blocks( $added );
	}
	return array_values( array_merge( $allowed, $entry, $added ) );
}
add_filter( 'allowed_block_types_all', __NAMESPACE__ . '\allow_entry_blocks', PHP_INT_MAX );

/**
 * The blocks the editor offers only inside the Live Blog Entry block, printed for its script once some are named.
 *
 * @since 0.1.0
 *
 * @param string[]|null $set Block names to add; null reads them.
 * @return string[]
 */
function restricted_blocks( ?array $set = null ): array {
	static $blocks = array();
	if ( null !== $set ) {
		$blocks = array_values( array_unique( array_merge( $blocks, $set ) ) );
	}
	return $blocks;
}

// Print that list for the entry block's script.
add_action(
	'admin_print_footer_scripts',
	static function (): void {
		if ( restricted_blocks() ) {
			wp_print_inline_script_tag( '( window.postsocketEntryBlock = window.postsocketEntryBlock || {} ).restricted = ' . wp_json_encode( restricted_blocks() ) . ';' );
		}
	},
	1
);

// Last of the editor's scripts: once the editor is ready, put back the template blocks another script unregistered.
add_action(
	'enqueue_block_editor_assets',
	static function (): void {
		$entry = generate_block_asset_handle( ENTRY_BLOCK, 'editorScript' );
		if ( ! wp_script_is( $entry, 'registered' ) ) {
			return;
		}
		wp_register_script( SLUG . '-restore-blocks', false, array( $entry, 'wp-dom-ready' ), VERSION, true );
		wp_enqueue_script( SLUG . '-restore-blocks' );
		wp_add_inline_script( SLUG . '-restore-blocks', 'wp.domReady( function () { var entry = window.postsocketEntryBlock; if ( entry && entry.restore ) { entry.restore(); } } );' );
	},
	PHP_INT_MAX
);

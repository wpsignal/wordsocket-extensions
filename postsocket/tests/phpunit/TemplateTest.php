<?php
/**
 * Entry templates: the Live Blog Entry block, the pattern a live blog names, the default layout without one, and what tells readers a template changed.
 */

use const WPSignal\Extensions\PostSocket\ENTRY_TYPE;
use const WPSignal\Extensions\PostSocket\META_PINNED;
use const WPSignal\Extensions\PostSocket\META_PINNED_TEMPLATE;
use const WPSignal\Extensions\PostSocket\META_TEMPLATE;

use function WPSignal\Extensions\PostSocket\claim_publish;
use function WPSignal\Extensions\PostSocket\entry_template;
use function WPSignal\Extensions\PostSocket\liveblog_channel;
use function WPSignal\Extensions\PostSocket\render_entry;
use function WPSignal\Extensions\PostSocket\render_feed;
use function WPSignal\Extensions\PostSocket\restricted_blocks;
use function WPSignal\Extensions\PostSocket\valid_template;

final class TemplateTest extends ExtensionTestCase {

	private const TEMPLATE = '<!-- wp:group --><div class="wp-block-group"><!-- wp:postsocket/entry {"className":"is-card"} --><!-- wp:post-date /--><!-- wp:post-title {"level":4} /--><!-- wp:post-featured-image /--><!-- wp:post-terms {"term":"post_tag"} /--><!-- wp:postsocket/entry-content /--><!-- /wp:postsocket/entry --></div><!-- /wp:group -->';

	/** A published synced pattern. */
	private function make_pattern( string $content = self::TEMPLATE, string $status = 'publish' ): int {
		$id = wp_insert_post(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => 'Entry card',
				'post_content' => $content,
				'post_status'  => $status,
			),
			true
		);
		$this->assertIsInt( $id );
		$this->posts[] = $id;
		return $id;
	}

	public function test_an_entry_without_a_template_keeps_the_default_layout(): void {
		$liveblog = $this->make_liveblog();
		$entry    = $this->make_entry( $liveblog );

		$this->assertNull( entry_template( $liveblog ) );
		$html = render_entry( get_post( $entry ) );
		$this->assertMatchesRegularExpression(
			'#^<article id="postsocket-' . $entry . '" class="postsocket-entry" data-postsocket-entry="' . $entry . '" data-postsocket-modified="[^"]+" data-postsocket-date="[^"]+" data-postsocket-pinned="0"><header class="postsocket-entry__header">#',
			$html
		);
		$this->assertStringContainsString( '<h3 class="postsocket-entry__headline">Goal</h3><div class="postsocket-entry__content">', $html );
		$this->assertStringEndsWith( '</div></article>', $html );
	}

	public function test_a_template_prints_the_entry_through_its_blocks(): void {
		register_taxonomy_for_object_type( 'post_tag', ENTRY_TYPE );
		$liveblog = $this->make_liveblog();
		$entry    = $this->make_entry( $liveblog );
		wp_set_object_terms( $entry, 'Derby', 'post_tag' );
		update_term_meta( $liveblog, META_TEMPLATE, $this->make_pattern() );

		wp_set_current_user( 0 );
		$html = render_entry( get_post( $entry ) );
		$this->assertFalse( current_user_can( 'read_post', $entry ), 'readable only while it renders' );
		$this->assertStringStartsWith( '<article ', $html, 'only the entry block is printed, not the group around it' );
		$this->assertSame( 1, substr_count( $html, '<article' ) );
		$this->assertStringContainsString( 'id="postsocket-' . $entry . '"', $html );
		$this->assertStringContainsString( 'data-postsocket-entry="' . $entry . '"', $html );
		$this->assertStringContainsString( 'data-postsocket-pinned="0"', $html );
		$this->assertMatchesRegularExpression( '/class="[^"]*\bpostsocket-entry\b[^"]*\bis-card\b|class="[^"]*\bis-card\b[^"]*\bpostsocket-entry\b/', $html );
		$this->assertStringContainsString( '<time datetime=', $html, 'core Post Date reads the entry' );
		$this->assertMatchesRegularExpression( '#<h4[^>]*>Goal</h4>#', $html, 'core Post Title reads the entry' );
		$this->assertStringContainsString( 'Derby', $html, 'core Post Terms reads the entry' );
		$this->assertMatchesRegularExpression( '#<div class="[^"]*postsocket-entry__content[^"]*">\s*<p[^>]*>One nil\.</p>#', $html );
		$this->assertStringNotContainsString( 'postsocket-entry__header', $html, 'no labels for a published, unpinned entry' );
		$this->assertStringNotContainsString( 'postsocket-entry__headline', $html, 'the default headline is the template\'s to print' );

		update_post_meta( $entry, META_PINNED, true );
		$pinned = render_entry( get_post( $entry ) );
		$this->assertStringContainsString( 'is-pinned', $pinned );
		$this->assertStringContainsString( 'data-postsocket-pinned="1"', $pinned );

		$draft = $this->make_entry( $liveblog, array( 'post_status' => 'draft' ) );
		$this->assertStringContainsString( 'data-postsocket-status="draft"', render_entry( get_post( $draft ) ) );
		$this->assertStringContainsString( 'postsocket-entry__status', render_entry( get_post( $draft ) ) );

		$this->assertSame( 1, substr_count( render_feed( $liveblog ), 'data-postsocket-entry="' . $entry . '"' ), 'the feed prints it the same way' );
	}

	public function test_only_a_published_pattern_with_the_entry_block_is_a_template(): void {
		$liveblog = $this->make_liveblog();
		$page     = $this->make_host( $liveblog );
		$plain    = $this->make_pattern( '<!-- wp:paragraph --><p>No entry here.</p><!-- /wp:paragraph -->' );
		$draft    = $this->make_pattern( self::TEMPLATE, 'draft' );
		$good     = $this->make_pattern();

		$this->assertSame( 0, valid_template( $page ), 'a post is not a pattern' );
		$this->assertSame( 0, valid_template( $plain ), 'a pattern without the block' );
		$this->assertSame( 0, valid_template( $draft ), 'an unpublished pattern' );
		$this->assertSame( $good, valid_template( $good ) );

		update_term_meta( $liveblog, META_TEMPLATE, $plain );
		$this->assertSame( 0, (int) get_term_meta( $liveblog, META_TEMPLATE, true ), 'refused on save' );

		update_term_meta( $liveblog, META_TEMPLATE, $good );
		$this->assertNotNull( entry_template( $liveblog ) );
		wp_trash_post( $good );
		$this->assertNull( entry_template( $liveblog ), 'a trashed pattern falls back to the default layout' );
	}

	public function test_a_site_that_limits_its_blocks_still_allows_the_template_blocks(): void {
		$this->assertTrue( apply_filters( 'allowed_block_types_all', true, null ), 'a site without a list is left alone' );
		$this->assertFalse( apply_filters( 'allowed_block_types_all', false, null ) );

		$allowed = apply_filters( 'allowed_block_types_all', array( 'core/paragraph', 'core/post-title' ), null );
		foreach ( array( 'core/paragraph', 'postsocket/entry', 'postsocket/entry-content', 'core/post-date', 'core/post-featured-image', 'core/post-terms' ) as $name ) {
			$this->assertContains( $name, $allowed );
		}
		$this->assertSame( 1, count( array_keys( $allowed, 'core/post-title', true ) ) );
		$this->assertContains( 'core/post-date', restricted_blocks(), 'left out by the site, so kept to the entry block' );
		$this->assertNotContains( 'core/post-title', restricted_blocks(), 'allowed by the site, so left as it was' );
		$this->assertNotContains( 'postsocket/entry', restricted_blocks() );
	}

	public function test_pinned_entries_take_their_own_template_or_the_entries_one(): void {
		$liveblog = $this->make_liveblog();
		$entry    = $this->make_entry( $liveblog );
		$pinned   = $this->make_entry( $liveblog, array( 'post_title' => 'Summary' ) );
		update_post_meta( $pinned, META_PINNED, true );
		$banner = $this->make_pattern( '<!-- wp:postsocket/entry {"className":"is-banner"} --><!-- wp:post-title /--><!-- /wp:postsocket/entry -->' );

		update_term_meta( $liveblog, META_PINNED_TEMPLATE, $banner );
		$this->assertStringContainsString( 'is-banner', render_entry( get_post( $pinned ) ) );
		$this->assertStringContainsString( 'postsocket-entry__headline', render_entry( get_post( $entry ) ), 'the others keep the default layout' );

		update_term_meta( $liveblog, META_TEMPLATE, $this->make_pattern() );
		$this->assertStringContainsString( 'is-card', render_entry( get_post( $entry ) ) );
		$this->assertStringContainsString( 'is-banner', render_entry( get_post( $pinned ) ) );

		delete_term_meta( $liveblog, META_PINNED_TEMPLATE );
		$this->assertStringContainsString( 'is-card', render_entry( get_post( $pinned ) ), 'without one of its own, a pinned entry uses the entries\' template' );

		update_term_meta( $liveblog, META_PINNED_TEMPLATE, $banner );
		$this->published = array();
		update_post_meta( $entry, META_PINNED, true );
		$events = $this->events_on( liveblog_channel( $liveblog ), 'postsocket.entry.updated' );
		$this->assertCount( 1, $events );
		$this->assertStringContainsString( 'is-banner', $events[0]['data']['html'], 'pinning sends the entry in the pinned template' );

		claim_publish( 'liveblog.template', $liveblog, true );
		$this->published = array();
		wp_update_post(
			array(
				'ID'           => $banner,
				'post_content' => '<!-- wp:postsocket/entry {"className":"is-strip"} --><!-- wp:post-title /--><!-- /wp:postsocket/entry -->',
			)
		);
		$this->assertCount( 1, $this->events_on( liveblog_channel( $liveblog ), 'postsocket.liveblog.template' ), 'a save of the pinned template tells readers' );
	}

	public function test_the_entry_block_offers_its_styles(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'postsocket/entry' );
		$this->assertSame( array( 'default', 'card', 'timeline', 'highlight' ), array_column( $block->styles, 'name' ) );
		$this->assertNotEmpty( $block->style_handles, 'the styles ship with the block' );
	}

	public function test_a_filter_supplies_a_template_in_code(): void {
		$liveblog = $this->make_liveblog();
		$entry    = $this->make_entry( $liveblog );
		$filter   = static fn ( string $markup, int $id ): string => $id === $liveblog ? '<!-- wp:postsocket/entry {"className":"from-code"} --><!-- wp:postsocket/entry-content /--><!-- /wp:postsocket/entry -->' : $markup;
		add_filter( 'postsocket_entry_template', $filter, 10, 2 );
		$html = render_entry( get_post( $entry ) );
		remove_filter( 'postsocket_entry_template', $filter, 10 );

		$this->assertStringContainsString( 'from-code', $html );
		$this->assertStringContainsString( 'One nil.</p>', $html );
	}

	public function test_an_entry_block_inside_an_entry_does_not_loop(): void {
		$liveblog = $this->make_liveblog();
		$entry    = $this->make_entry(
			$liveblog,
			array( 'post_content' => '<!-- wp:paragraph --><p>Outer.</p><!-- /wp:paragraph --><!-- wp:postsocket/entry --><!-- wp:postsocket/entry-content /--><!-- /wp:postsocket/entry -->' )
		);
		update_term_meta( $liveblog, META_TEMPLATE, $this->make_pattern() );

		$this->assertSame( 1, substr_count( render_entry( get_post( $entry ) ), 'Outer.</p>' ) );
	}

	public function test_readers_hear_a_template_chosen_and_a_template_saved(): void {
		$liveblog = $this->make_liveblog();
		$pattern  = $this->make_pattern();
		$channel  = liveblog_channel( $liveblog );

		update_term_meta( $liveblog, META_TEMPLATE, $pattern );
		$events = $this->events_on( $channel, 'postsocket.liveblog.template' );
		$this->assertCount( 1, $events );
		$this->assertSame( $liveblog, $events[0]['data']['id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}$/', $events[0]['data']['v'] );

		$save = static fn () => wp_update_post(
			array(
				'ID'           => $pattern,
				'post_content' => str_replace( 'is-card', 'is-banner', self::TEMPLATE ),
			)
		);
		$save();
		$this->assertCount( 1, $this->events_on( $channel, 'postsocket.liveblog.template' ), 'once per live blog in a request' );

		claim_publish( 'liveblog.template', $liveblog, true );
		$save();
		$this->assertCount( 2, $this->events_on( $channel, 'postsocket.liveblog.template' ), 'a save of the pattern in a later request' );
	}

	public function test_a_featured_image_and_a_term_change_reach_readers(): void {
		register_taxonomy_for_object_type( 'post_tag', ENTRY_TYPE );
		$liveblog = $this->make_liveblog();
		$entry    = $this->make_entry( $liveblog );
		update_term_meta( $liveblog, META_TEMPLATE, $this->make_pattern() );
		$this->published = array();

		wp_set_object_terms( $entry, 'Cup tie', 'post_tag' );
		$events = $this->events_on( liveblog_channel( $liveblog ), 'postsocket.entry.updated' );
		$this->assertCount( 1, $events, 'a term the template prints' );
		$this->assertStringContainsString( 'Cup tie', $events[0]['data']['html'] );
	}

	public function test_a_host_in_a_block_theme_keeps_its_living_wrapper_around_a_templated_feed(): void {
		$liveblog = $this->make_liveblog();
		$host     = $this->make_host( $liveblog );
		$entry    = $this->make_entry( $liveblog );
		update_term_meta( $liveblog, META_TEMPLATE, $this->make_pattern() );

		$this->visit( $host );
		$GLOBALS['post'] = get_post( $host ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		$this->assertFalse( in_the_loop(), 'as inside a block template' );
		$page = render_block(
			array(
				'blockName'    => 'core/post-content',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$this->assertStringContainsString( 'data-postsocket-post="' . $host . '"', $page );
		$this->assertStringContainsString( 'data-postsocket-entry="' . $entry . '"', $page );
		$this->assertStringContainsString( 'is-card', $page );
	}
}

<?php
/**
 * The five events: channels from the term, payloads, the hash guard, the removal and move paths, the state change, the desk's staff notices.
 */

use const WPSignal\Extensions\PostSocket\ENTRY_TYPE;
use const WPSignal\Extensions\PostSocket\LIVEBLOG_REST_BASE;
use const WPSignal\Extensions\PostSocket\LIVEBLOG_TAX;
use const WPSignal\Extensions\PostSocket\META_PINNED;
use const WPSignal\Extensions\PostSocket\META_STATE;

use function WPSignal\Extensions\PostSocket\desk_channel;
use function WPSignal\Extensions\PostSocket\liveblog_channel;
use function WPSignal\Extensions\PostSocket\post_channel;
use function WPSignal\Extensions\PostSocket\tell_desks;
use function WPSignal\Extensions\PostSocket\tell_readers;

final class TriggersTest extends ExtensionTestCase {

	public function test_publishing_an_entry_publishes_it_rendered_on_the_live_blog_channel(): void {
		$liveblog        = $this->make_liveblog();
		$this->published = array();
		$entry           = $this->make_entry( $liveblog, array( 'post_content' => '<!-- wp:paragraph --><p>One nil.</p><!-- /wp:paragraph --><!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>a</td></tr></tbody></table></figure><!-- /wp:table -->' ) );

		$events = $this->events_on( liveblog_channel( $liveblog ), 'postsocket.entry.updated' );
		$this->assertCount( 1, $events );
		$data = $events[0]['data'];
		$this->assertSame( $entry, $data['id'] );
		$this->assertSame( $liveblog, $data['liveblog'] );
		$this->assertSame( 'Goal', $data['headline'] );
		$this->assertFalse( $data['pinned'] );
		$this->assertStringContainsString( 'id="postsocket-' . $entry . '"', $data['html'] );
		$this->assertStringContainsString( 'One nil.', $data['html'] );
		$this->assertNotEmpty( array_filter( $data['assets']['styles'], static fn( $s ) => str_contains( $s['href'], 'blocks/table/' ) ), 'the table block\'s stylesheet rides along' );
		$this->assertSame( array(), $this->events_on( liveblog_channel( $liveblog ), 'postsocket.post.updated' ), 'entries are excluded from the generic post event' );
		$this->assertSame( array(), array_filter( $this->published, static fn( $p ) => 'post.updated' === $p['event'] ), 'WordSocket\'s own post.updated is excluded for entries' );
	}

	public function test_an_entry_carries_the_scripts_its_render_enqueues_in_order_rendered_as_a_guest(): void {
		global $wp_scripts;
		wp_set_current_user( $this->admin_id() );
		$wp_scripts = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$nonce      = wp_create_nonce( 'wp_rest' );
		$this->assertStringContainsString( $nonce, wp_scripts()->get_inline_script_data( 'wp-api-fetch', 'after' ), 'the editor\'s registry holds the editor\'s nonce' );
		wp_register_script( 'postsocket-test-dep', 'https://example.com/dep.js', array( 'wp-api-fetch' ), '1', true );
		wp_localize_script( 'postsocket-test-dep', 'postsocketTestDep', array( 'ok' => 1 ) );
		wp_register_script( 'postsocket-test-view', 'https://example.com/view.js', array( 'postsocket-test-dep' ), '1', true );
		wp_add_inline_script( 'postsocket-test-view', 'window.postsocketTestAfter = 1;' );
		register_block_type(
			'postsocket-test/js',
			array(
				'render_callback' => static function (): string {
					wp_enqueue_script( 'postsocket-test-view' );
					return '<div class="postsocket-test-js" data-user="' . get_current_user_id() . '"></div>';
				},
			)
		);
		$liveblog        = $this->make_liveblog();
		$this->published = array();
		$this->make_entry( $liveblog, array( 'post_content' => '<!-- wp:postsocket-test/js /-->' ) );
		unregister_block_type( 'postsocket-test/js' );

		$data    = $this->events_on( liveblog_channel( $liveblog ), 'postsocket.entry.updated' )[0]['data'];
		$scripts = array_column( $data['assets']['scripts'], null, 'id' );
		$ids     = array_keys( $scripts );
		$this->assertStringContainsString( 'data-user="0"', $data['html'], 'rendered as a signed-out reader' );
		$this->assertLessThan( array_search( 'postsocket-test-dep', $ids, true ), array_search( 'wp-api-fetch', $ids, true ), 'dependencies first' );
		$this->assertLessThan( array_search( 'postsocket-test-view', $ids, true ), array_search( 'postsocket-test-dep', $ids, true ), 'dependencies first' );
		$this->assertStringContainsString( 'postsocketTestDep', $scripts['postsocket-test-dep']['before'] );
		$this->assertStringContainsString( 'postsocketTestAfter', $scripts['postsocket-test-view']['after'] );
		$this->assertStringNotContainsString( $nonce, (string) wp_json_encode( $data ), 'no nonce of the editor reaches readers' );
		$this->assertContains( 'wp-api-fetch', array_keys( wp_scripts()->registered ), 'the page registry is back' );
		$this->assertNotContains( 'postsocket-test-view', wp_scripts()->queue, 'the page queue is untouched' );
	}

	public function test_an_entry_carries_the_css_wordpress_generates_for_its_blocks(): void {
		$liveblog        = $this->make_liveblog();
		$group           = '<!-- wp:group {"className":"is-style-section-3","layout":{"type":"flex","flexWrap":"nowrap"}} --><div class="wp-block-group is-style-section-3"><!-- wp:paragraph --><p>Styled.</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		$this->published = array();
		$entry           = $this->make_entry( $liveblog, array( 'post_content' => $group ) );

		$events = $this->events_on( liveblog_channel( $liveblog ), 'postsocket.entry.updated' );
		$this->assertCount( 1, $events );
		$css = $events[0]['data']['assets']['css'] ?? '';
		$this->assertMatchesRegularExpression( '/\.wp-container-core-group-is-layout-[a-z0-9]+\{[^}]*flex-wrap:\s*nowrap/', $css, 'the layout rule rides along' );
		preg_match( '/is-style-section-3--\d+/', $events[0]['data']['html'], $instance );
		if ( $instance ) {
			$this->assertStringContainsString( '.' . $instance[0], $css, 'the variation instance the markup names is styled' );
		}

		$this->published = array();
		wp_update_post( array( 'ID' => $entry, 'post_title' => 'Goal' ) );
		$this->assertCount( 0, $this->published, 'a re-render with another variation instance number is not a change' );
	}

	public function test_an_unchanged_save_publishes_nothing_and_a_change_publishes_once(): void {
		$liveblog        = $this->make_liveblog();
		$entry           = $this->make_entry( $liveblog );
		$this->published = array();

		wp_update_post(
			array(
				'ID'         => $entry,
				'post_title' => 'Goal',
			)
		);
		$this->assertCount( 0, $this->published, 'nothing readers see changed' );

		wp_update_post(
			array(
				'ID'           => $entry,
				'post_content' => '<!-- wp:paragraph --><p>Two nil.</p><!-- /wp:paragraph -->',
			)
		);
		$events = $this->events_on( liveblog_channel( $liveblog ), 'postsocket.entry.updated' );
		$this->assertCount( 1, $events );
		$this->assertStringContainsString( 'Two nil.', $events[0]['data']['html'] );
	}

	public function test_a_draft_entry_or_an_entry_without_a_live_blog_publishes_nothing(): void {
		$liveblog        = $this->make_liveblog();
		$this->published = array();
		$this->make_entry( $liveblog, array( 'post_status' => 'draft' ) );
		$this->assertCount( 0, $this->published );

		$this->make_entry( 0 );
		$this->assertCount( 0, $this->published );
	}

	public function test_a_rest_publish_reaches_readers_once_with_its_live_blog(): void {
		$liveblog        = $this->make_liveblog();
		$this->published = array();

		$created = $this->rest(
			'POST',
			'/wp/v2/' . ENTRY_TYPE,
			array(
				'title'             => 'From the editor',
				'content'           => '<!-- wp:paragraph --><p>Saved.</p><!-- /wp:paragraph -->',
				'status'            => 'publish',
				LIVEBLOG_REST_BASE  => array( $liveblog ),
				'meta'              => array( META_PINNED => true ),
			)
		);
		$this->posts[] = $created->get_data()['id'];
		$events        = $this->events_on( liveblog_channel( $liveblog ), 'postsocket.entry.updated' );
		$this->assertCount( 1, $events, 'the term, the pin and the content are settled before it publishes' );
		$this->assertTrue( end( $events )['data']['pinned'] );
		$this->assertSame( $liveblog, end( $events )['data']['liveblog'] );
	}

	public function test_moving_an_entry_removes_it_from_one_live_blog_and_adds_it_to_the_other(): void {
		$from            = $this->make_liveblog();
		$to              = $this->make_liveblog( 'Other' );
		$entry           = $this->make_entry( $from );
		$this->published = array();

		wp_set_object_terms( $entry, $to, LIVEBLOG_TAX );
		$removed = $this->events_on( liveblog_channel( $from ), 'postsocket.entry.removed' );
		$added   = $this->events_on( liveblog_channel( $to ), 'postsocket.entry.updated' );
		$this->assertCount( 1, $removed );
		$this->assertSame( $from, $removed[0]['data']['liveblog'] );
		$this->assertCount( 1, $added );
		$this->assertSame( $to, $added[0]['data']['liveblog'] );

		$this->published = array();
		wp_set_object_terms( $entry, $to, LIVEBLOG_TAX );
		$this->assertCount( 0, $this->published, 'setting the same live blog again publishes nothing' );
	}

	public function test_pinning_publishes_the_entry_again_as_pinned(): void {
		$liveblog        = $this->make_liveblog();
		$entry           = $this->make_entry( $liveblog );
		$this->published = array();

		update_post_meta( $entry, META_PINNED, true );
		$events = $this->events_on( liveblog_channel( $liveblog ), 'postsocket.entry.updated' );
		$this->assertCount( 1, $events );
		$this->assertTrue( $events[0]['data']['pinned'] );
		$this->assertStringContainsString( 'is-pinned', $events[0]['data']['html'] );

		$this->published = array();
		delete_post_meta( $entry, META_PINNED );
		$events = $this->events_on( liveblog_channel( $liveblog ), 'postsocket.entry.updated' );
		$this->assertCount( 1, $events, 'unpinning publishes too' );
		$this->assertFalse( $events[0]['data']['pinned'] );
	}

	public function test_trashing_and_force_deleting_publish_one_removal_each(): void {
		$liveblog = $this->make_liveblog();
		$entry    = $this->make_entry( $liveblog );
		update_post_meta( $entry, META_PINNED, true );
		$this->published = array();

		wp_trash_post( $entry );
		$removed = $this->events_on( liveblog_channel( $liveblog ), 'postsocket.entry.removed' );
		$this->assertCount( 1, $removed );
		$this->assertSame(
			array(
				'id'       => $entry,
				'liveblog' => $liveblog,
			),
			$removed[0]['data']
		);
		$this->assertSame( array(), $this->events_on( liveblog_channel( $liveblog ), 'postsocket.entry.updated' ), 'a trash publishes no content' );

		$this->published = array();
		wp_untrash_post( $entry );
		$this->assertSame( 'publish', get_post_status( $entry ) );
		$this->assertCount( 1, $this->events_on( liveblog_channel( $liveblog ), 'postsocket.entry.updated' ), 'a restored entry reaches readers again' );
		$this->published = array();
		wp_delete_post( $entry, true );
		$removed = $this->events_on( liveblog_channel( $liveblog ), 'postsocket.entry.removed' );
		$this->assertCount( 1, $removed, 'a force delete of a published entry publishes a removal' );
		$this->assertSame( array(), $this->events_on( liveblog_channel( $liveblog ), 'postsocket.entry.updated' ), 'the pin meta going with the post publishes nothing' );
	}

	public function test_the_state_change_and_the_host_edit_publish_on_their_channels(): void {
		$liveblog        = $this->make_liveblog();
		$host            = $this->make_host( $liveblog );
		$this->published = array();

		update_term_meta( $liveblog, META_STATE, 'ended' );
		$state = $this->events_on( liveblog_channel( $liveblog ), 'postsocket.liveblog.state' );
		$this->assertCount( 1, $state );
		$this->assertSame( 'ended', $state[0]['data']['state'] );

		$this->published = array();
		wp_update_post(
			array(
				'ID'           => $host,
				'post_content' => '<!-- wp:paragraph --><p>Full time.</p><!-- /wp:paragraph -->' . self::feed_block( $liveblog ),
			)
		);
		tell_readers();
		$post = $this->events_on( post_channel( $host ), 'postsocket.post.updated' );
		$this->assertCount( 1, $post, 'a post showing a live blog is living without its type enabled' );
		$this->assertStringContainsString( 'Full time.', $post[0]['data']['html'] );
		$this->assertStringNotContainsString( 'data-postsocket-feed=', $post[0]['data']['html'], 'the relay render carries no feed' );
		$this->assertStringContainsString( 'data-postsocket-feed-slot="' . $liveblog . '"', $post[0]['data']['html'], 'a slot marks where it sits' );
	}

	public function test_a_post_type_that_is_not_enabled_publishes_no_content(): void {
		$post            = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_title'   => 'Plain',
				'post_content' => '<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			),
			true
		);
		$this->posts[]   = $post;
		$this->published = array();

		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => '<!-- wp:paragraph --><p>b</p><!-- /wp:paragraph -->',
			)
		);
		tell_readers();
		$this->assertSame( array(), $this->events_on( post_channel( $post ) ) );
		$this->assertNotEmpty( array_filter( $this->published, static fn( $p ) => 'post.updated' === $p['event'] ), 'WordSocket\'s generic event still fires for posts' );
	}

	public function test_staff_desks_hear_drafts_the_trash_and_the_editor_on_a_channel_readers_cannot_read(): void {
		$liveblog = $this->make_liveblog();
		$admin    = $this->admin_id();
		$channel  = desk_channel( $liveblog );
		$heard    = function () use ( $channel ): array {
			$this->published = array();
			tell_desks();
			return $this->events_on( $channel, 'postsocket.desk.changed' );
		};

		$draft = $this->make_entry( $liveblog, array( 'post_status' => 'draft' ) );
		$this->assertSame( array(), $this->events_on( $channel ), 'nothing is told before the request ends' );
		$first = $heard();
		$this->assertCount( 1, $first, 'a draft is told once however many hooks queued it' );
		$this->assertSame( array( 'liveblog' => $liveblog ), $first[0]['data'] );
		$this->assertSame( array(), $this->events_on( liveblog_channel( $liveblog ) ), 'a draft says nothing on the public channel' );
		$this->assertSame( array(), $heard(), 'the queue is taken once' );

		update_post_meta( $draft, '_edit_lock', time() . ':' . $admin );
		$this->assertCount( 1, $heard(), 'an editor opening the entry' );
		update_post_meta( $draft, '_edit_lock', ( time() + 5 ) . ':' . $admin );
		$this->assertSame( array(), $heard(), 'a renewal of the same lock' );
		update_post_meta( $draft, '_edit_lock', ( time() - 145 ) . ':' . $admin );
		$this->assertCount( 1, $heard(), 'the editor closing, which shortens the lock' );

		wp_trash_post( $draft );
		$this->assertCount( 1, $heard(), 'a trashed draft' );
		wp_delete_post( $draft, true );
		$this->assertCount( 1, $heard(), 'a deleted one' );

		$channels = WPSignal\WPS::instance()->channels();
		$this->assertContains( 'site:x:postsocket-desk:', $channels->allowed_prefixes( $admin, 'x', array() ) );
		$this->assertNotContains( 'site:x:postsocket-desk:', $channels->allowed_prefixes( 0, 'x', array() ) );
		$this->assertNotContains( 'site:x:postsocket-desk:', $channels->allowed_publish_prefixes( $admin, 'x' ), 'only PHP writes it' );
	}

	public function test_an_entry_carries_the_css_and_svg_filters_of_its_duotones(): void {
		$liveblog = $this->make_liveblog();
		$image    = '<!-- wp:image {"style":{"color":{"duotone":["#000000","#7f7f7f"]}}} --><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt=""/></figure><!-- /wp:image -->';
		$this->make_entry( $liveblog, array( 'post_content' => $image ) );

		$events = $this->events_on( liveblog_channel( $liveblog ), 'postsocket.entry.updated' );
		$this->assertCount( 1, $events );
		$this->assertMatchesRegularExpression( '/class="[^"]*wp-duotone-([a-z0-9-]+)/', $events[0]['data']['html'], 'the class is on the block' );
		preg_match( '/wp-duotone-[a-z0-9-]+/', $events[0]['data']['html'], $class );
		$assets = $events[0]['data']['assets'];
		$this->assertStringContainsString( '.' . $class[0], $assets['css'] ?? '', 'with the rule that applies the filter' );
		$this->assertStringContainsString( 'filter:', $assets['css'] );
		$this->assertStringContainsString( '<filter', $assets['svg'] ?? '', 'and the SVG filter it points at' );
		$this->assertStringContainsString( 'id="' . $class[0] . '"', $assets['svg'] );
	}
}

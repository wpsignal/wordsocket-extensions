<?php
/**
 * The four events: channels, payloads, the hash guard, the removal paths, the state change.
 */

use const WPSignal\Extensions\PostSocket\ENTRY_TYPE;
use const WPSignal\Extensions\PostSocket\META_PINNED;
use const WPSignal\Extensions\PostSocket\META_STATE;

use function WPSignal\Extensions\PostSocket\liveblog_channel;
use function WPSignal\Extensions\PostSocket\post_channel;

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
		$this->assertNotEmpty( array_filter( $data['assets']['styles'], static fn( $u ) => str_contains( $u, 'blocks/table/' ) ), 'the table block\'s stylesheet rides along' );
		$this->assertSame( array(), $this->events_on( liveblog_channel( $liveblog ), 'postsocket.post.updated' ), 'entries are excluded from the generic post event' );
		$this->assertSame( array(), array_filter( $this->published, static fn( $p ) => 'post.updated' === $p['event'] ), 'WordSocket\'s own post.updated is excluded for both types' );
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

	public function test_a_draft_entry_or_an_entry_of_a_draft_live_blog_publishes_nothing(): void {
		$liveblog        = $this->make_liveblog();
		$this->published = array();
		$this->make_entry( $liveblog, array( 'post_status' => 'draft' ) );
		$this->assertCount( 0, $this->published );

		$unpublished     = $this->make_liveblog( array( 'post_status' => 'draft' ) );
		$this->published = array();
		$this->make_entry( $unpublished );
		$this->assertCount( 0, $this->published );
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

	public function test_the_state_change_and_the_intro_edit_publish_on_their_channels(): void {
		$liveblog        = $this->make_liveblog();
		$this->published = array();

		update_post_meta( $liveblog, META_STATE, 'ended' );
		$state = $this->events_on( liveblog_channel( $liveblog ), 'postsocket.liveblog.state' );
		$this->assertCount( 1, $state );
		$this->assertSame( 'ended', $state[0]['data']['state'] );

		$this->published = array();
		wp_update_post(
			array(
				'ID'           => $liveblog,
				'post_content' => '<!-- wp:paragraph --><p>Full time.</p><!-- /wp:paragraph -->',
			)
		);
		$post = $this->events_on( post_channel( $liveblog ), 'postsocket.post.updated' );
		$this->assertCount( 1, $post );
		$this->assertStringContainsString( 'Full time.', $post[0]['data']['html'] );
		$this->assertStringNotContainsString( 'postsocket-feed', $post[0]['data']['html'], 'the relay render carries no feed' );
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
		$this->assertSame( array(), $this->events_on( post_channel( $post ) ) );
		$this->assertNotEmpty( array_filter( $this->published, static fn( $p ) => 'post.updated' === $p['event'] ), 'WordSocket\'s generic event still fires for posts' );
	}
}

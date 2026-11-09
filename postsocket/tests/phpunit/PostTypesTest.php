<?php
/**
 * The two post types: visibility, the REST liveblog field and publish guard, links, cascades.
 */

use const WPSignal\Extensions\PostSocket\ENTRY_TYPE;
use const WPSignal\Extensions\PostSocket\LIVEBLOG_TYPE;

use function WPSignal\Extensions\PostSocket\entry_count;
use function WPSignal\Extensions\PostSocket\entry_link;

final class PostTypesTest extends ExtensionTestCase {

	public function test_the_live_blog_is_public_and_the_entry_is_not(): void {
		$liveblog = get_post_type_object( LIVEBLOG_TYPE );
		$entry    = get_post_type_object( ENTRY_TYPE );

		$this->assertTrue( $liveblog->public );
		$this->assertTrue( $liveblog->show_in_rest );
		$this->assertFalse( $liveblog->has_archive );
		$this->assertFalse( $entry->public );
		$this->assertFalse( $entry->publicly_queryable );
		$this->assertTrue( $entry->exclude_from_search );
		$this->assertTrue( $entry->show_ui );
		$this->assertTrue( $entry->show_in_rest );
		$this->assertFalse( is_post_type_viewable( ENTRY_TYPE ), 'an entry has no page of its own' );
		$this->assertSame( 'edit.php?post_type=' . LIVEBLOG_TYPE, $entry->show_in_menu, 'entries sit under the Live Blogs menu' );
	}

	public function test_entries_stay_out_of_the_main_loop_and_search(): void {
		$liveblog = $this->make_liveblog();
		$entry    = $this->make_entry( $liveblog, array( 'post_title' => 'Needle entry zq' ) );

		$this->go_to( home_url( '/' ) );
		$home_ids = wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );
		$this->assertNotContains( $entry, $home_ids );

		$this->go_to( home_url( '/?s=zq' ) );
		$search_ids = wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );
		$this->assertNotContains( $entry, $search_ids, 'excluded from search' );

		$this->assertSame( get_permalink( $liveblog ) . '#postsocket-' . $entry, entry_link( $entry ) );
		$this->assertSame( entry_link( $entry ), get_permalink( $entry ), 'the entry\'s permalink is the anchor' );
	}

	public function test_rest_exposes_the_live_blog_and_refuses_publishing_without_one(): void {
		$liveblog = $this->make_liveblog();

		$refused = $this->rest(
			'POST',
			'/wp/v2/' . ENTRY_TYPE,
			array(
				'title'   => 'Orphan',
				'content' => 'x',
				'status'  => 'publish',
			)
		);
		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( 'postsocket_no_liveblog', $refused->get_data()['code'] );

		$draft = $this->rest(
			'POST',
			'/wp/v2/' . ENTRY_TYPE,
			array(
				'title'   => 'Draft orphan',
				'content' => 'x',
				'status'  => 'draft',
			)
		);
		$this->assertSame( 201, $draft->get_status(), 'a draft needs no live blog yet' );
		$this->posts[] = $draft->get_data()['id'];
		$this->assertSame( 0, $draft->get_data()['liveblog'] );

		$wrong = $this->rest(
			'POST',
			'/wp/v2/' . ENTRY_TYPE,
			array(
				'title'    => 'x',
				'content'  => 'x',
				'status'   => 'publish',
				'liveblog' => $draft->get_data()['id'],
			)
		);
		$this->assertSame( 'postsocket_not_a_liveblog', $wrong->get_data()['code'] );

		$created = $this->rest(
			'POST',
			'/wp/v2/' . ENTRY_TYPE,
			array(
				'title'    => 'Kick-off',
				'content'  => 'x',
				'status'   => 'publish',
				'liveblog' => $liveblog,
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$this->posts[] = $created->get_data()['id'];
		$this->assertSame( $liveblog, $created->get_data()['liveblog'] );
		$this->assertSame( $liveblog, (int) get_post_field( 'post_parent', $created->get_data()['id'] ) );

		$listed = $this->rest( 'GET', '/wp/v2/' . ENTRY_TYPE, array( 'liveblog' => $liveblog ) );
		$this->assertSame( array( $created->get_data()['id'] ), wp_list_pluck( $listed->get_data(), 'id' ), 'the collection filters by live blog' );
		$this->assertSame( 1, entry_count( $liveblog ) );
	}

	public function test_trash_untrash_and_delete_cascade_from_the_live_blog(): void {
		$liveblog = $this->make_liveblog();
		$entry    = $this->make_entry( $liveblog );
		$draft    = $this->make_entry( $liveblog, array( 'post_status' => 'draft' ) );

		wp_trash_post( $liveblog );
		$this->assertSame( 'trash', get_post_status( $entry ) );
		$this->assertSame( 'trash', get_post_status( $draft ) );

		wp_untrash_post( $liveblog );
		$this->assertSame( 'draft', get_post_status( $liveblog ), 'WordPress restores a trashed post as a draft' );
		$this->assertSame( 'draft', get_post_status( $entry ), 'its entries follow it back as drafts' );
		$this->assertSame( 'draft', get_post_status( $draft ) );
		$this->assertSame( '', get_post_meta( $entry, '_postsocket_trashed_with', true ), 'the cascade marker is cleared' );

		wp_delete_post( $liveblog, true );
		$this->assertNull( get_post( $entry ) );
		$this->assertNull( get_post( $draft ) );
	}

	public function test_a_restored_entry_follows_its_live_blog_status(): void {
		$published = $this->make_liveblog();
		$entry     = $this->make_entry( $published );
		wp_trash_post( $entry );
		wp_untrash_post( $entry );
		$this->assertSame( 'publish', get_post_status( $entry ), 'back on a published live blog, the entry is live again' );

		$draft_blog = $this->make_liveblog( array( 'post_status' => 'draft' ) );
		$orphan     = $this->make_entry( $draft_blog );
		wp_trash_post( $orphan );
		wp_untrash_post( $orphan );
		$this->assertSame( 'draft', get_post_status( $orphan ), 'on an unpublished live blog, a draft' );
	}
}

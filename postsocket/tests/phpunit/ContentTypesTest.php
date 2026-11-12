<?php
/**
 * The entry type and the live blog taxonomy: visibility, the REST guard, the home post, links, the delete cascade, the trash of a deleted live blog's entries, and the desk's restore route and edit-lock read-outs.
 */

use const WPSignal\Extensions\PostSocket\ENTRY_TYPE;
use const WPSignal\Extensions\PostSocket\FEED_BLOCK;
use const WPSignal\Extensions\PostSocket\LIVEBLOG_REST_BASE;
use const WPSignal\Extensions\PostSocket\LIVEBLOG_TAX;
use const WPSignal\Extensions\PostSocket\REST_NS;

use function WPSignal\Extensions\PostSocket\entry_link;
use function WPSignal\Extensions\PostSocket\feed_liveblogs;
use function WPSignal\Extensions\PostSocket\home_of;
use function WPSignal\Extensions\PostSocket\liveblog_id_of;

final class ContentTypesTest extends ExtensionTestCase {

	public function test_the_taxonomy_and_the_entry_type_stay_out_of_public_view(): void {
		$tax   = get_taxonomy( LIVEBLOG_TAX );
		$entry = get_post_type_object( ENTRY_TYPE );

		$this->assertFalse( $tax->public );
		$this->assertFalse( $tax->publicly_queryable );
		$this->assertFalse( $tax->hierarchical );
		$this->assertFalse( $tax->show_ui, 'the desk replaces the term screen and the editor panel' );
		$this->assertTrue( $tax->show_in_rest );
		$this->assertSame( LIVEBLOG_REST_BASE, $tax->rest_base );
		$this->assertFalse( $tax->rewrite );
		$this->assertSame( 'edit_posts', $tax->cap->assign_terms );
		$this->assertSame( 'edit_others_posts', $tax->cap->manage_terms );

		$this->assertFalse( $entry->public );
		$this->assertFalse( $entry->publicly_queryable );
		$this->assertTrue( $entry->exclude_from_search );
		$this->assertTrue( $entry->show_ui );
		$this->assertFalse( $entry->show_in_menu, 'the desk is the menu entry' );
		$this->assertFalse( is_post_type_viewable( ENTRY_TYPE ) );
	}

	public function test_entries_stay_out_of_loops_and_link_to_their_home(): void {
		$liveblog = $this->make_liveblog();
		$entry    = $this->make_entry( $liveblog, array( 'post_title' => 'Needle entry zq' ) );
		$this->assertSame( $liveblog, liveblog_id_of( $entry ) );
		$this->assertSame( '', entry_link( $entry ), 'no home yet, no anchor' );

		$host = $this->make_host( $liveblog );
		$this->assertSame( get_permalink( $host ) . '#postsocket-' . $entry, entry_link( $entry ) );
		$this->assertSame( entry_link( $entry ), get_permalink( $entry ), 'the entry\'s permalink is the anchor' );

		$this->go_to( home_url( '/?s=zq' ) );
		$this->assertNotContains( $entry, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), 'excluded from search' );
	}

	public function test_rest_takes_one_live_blog_and_refuses_publishing_without_one(): void {
		$liveblog = $this->make_liveblog();
		$other    = $this->make_liveblog( 'Other' );
		$route    = '/wp/v2/' . ENTRY_TYPE;

		$refused = $this->rest( 'POST', $route, array( 'title' => 'Orphan', 'content' => 'x', 'status' => 'publish' ) );
		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( 'postsocket_no_liveblog', $refused->get_data()['code'] );

		$draft = $this->rest( 'POST', $route, array( 'title' => 'Draft orphan', 'content' => 'x', 'status' => 'draft' ) );
		$this->assertSame( 201, $draft->get_status(), 'a draft needs no live blog yet' );
		$this->posts[] = $draft->get_data()['id'];

		$two = $this->rest( 'POST', $route, array( 'title' => 'x', 'status' => 'draft', LIVEBLOG_REST_BASE => array( $liveblog, $other ) ) );
		$this->assertSame( 'postsocket_one_liveblog', $two->get_data()['code'] );

		$wrong = $this->rest( 'POST', $route, array( 'title' => 'x', 'status' => 'publish', LIVEBLOG_REST_BASE => array( 999999999 ) ) );
		$this->assertSame( 'postsocket_not_a_liveblog', $wrong->get_data()['code'] );

		$created = $this->rest( 'POST', $route, array( 'title' => 'Kick-off', 'content' => 'x', 'status' => 'publish', LIVEBLOG_REST_BASE => array( $liveblog ) ) );
		$this->assertSame( 201, $created->get_status() );
		$this->posts[] = $created->get_data()['id'];
		$this->assertSame( array( $liveblog ), $created->get_data()[ LIVEBLOG_REST_BASE ] );
		$this->assertSame( $liveblog, liveblog_id_of( $created->get_data()['id'] ) );

		$republish = $this->rest( 'POST', $route . '/' . $created->get_data()['id'], array( 'title' => 'Kick-off!' ) );
		$this->assertSame( 200, $republish->get_status(), 'an update that leaves the live blog alone keeps it' );

		$listed = $this->rest( 'GET', $route, array( LIVEBLOG_REST_BASE => $liveblog ) );
		$this->assertSame( array( $created->get_data()['id'] ), wp_list_pluck( $listed->get_data(), 'id' ), 'the collection filters by live blog' );
		$this->assertSame( 1, (int) get_term( $liveblog, LIVEBLOG_TAX )->count, 'the term counts published entries' );
	}

	public function test_the_first_published_post_showing_a_live_blog_is_its_home(): void {
		$liveblog = $this->make_liveblog();
		$this->assertSame( array( $liveblog ), feed_liveblogs( 'x' . self::feed_block( $liveblog ) . self::feed_block( $liveblog ) ) );
		$this->assertSame( array(), feed_liveblogs( '<!-- wp:' . FEED_BLOCK . ' /-->' ), 'a block naming no live blog shows none' );

		$this->make_host( $liveblog, array( 'post_status' => 'draft' ) );
		$this->assertSame( 0, home_of( $liveblog ), 'a draft is not a home' );

		$first  = $this->make_host( $liveblog );
		$second = $this->make_host( $liveblog, array( 'post_title' => 'Sidebar' ) );
		$this->assertSame( $first, home_of( $liveblog ), 'a second placement does not take over' );

		wp_update_post( array( 'ID' => $first, 'post_content' => '<!-- wp:paragraph --><p>Moved.</p><!-- /wp:paragraph -->' ) );
		$this->assertSame( 0, home_of( $liveblog ), 'a home that drops the block lets go' );

		wp_update_post( array( 'ID' => $second, 'post_title' => 'Sidebar, saved' ) );
		$this->assertSame( $second, home_of( $liveblog ), 'the next save of a post showing it becomes the home' );

		wp_trash_post( $second );
		$this->assertSame( 0, home_of( $liveblog ), 'a trashed home is no home' );
	}

	public function test_deleting_a_live_blog_trashes_its_entries(): void {
		$liveblog = $this->make_liveblog();
		$entry    = $this->make_entry( $liveblog );
		$draft    = $this->make_entry( $liveblog, array( 'post_status' => 'draft' ) );

		wp_delete_term( $liveblog, LIVEBLOG_TAX );
		$this->assertSame( 'trash', get_post_status( $entry ) );
		$this->assertSame( 'trash', get_post_status( $draft ) );
	}

	public function test_an_entry_of_a_deleted_live_blog_is_listed_and_restored_into_another_as_a_draft(): void {
		wp_set_current_user( $this->admin_id() );
		$gone  = $this->make_liveblog( 'Gone' );
		$kept  = $this->make_liveblog( 'Kept' );
		$entry = $this->make_entry( $gone );
		$other = $this->make_entry( $kept );
		wp_trash_post( $other );
		wp_delete_term( $gone, LIVEBLOG_TAX );

		$query = array(
			'context'             => 'edit',
			'status'              => 'trash',
			'postsocket_orphaned' => true,
			'per_page'            => 100,
		);
		$ids   = array_column( $this->rest( 'GET', '/wp/v2/' . ENTRY_TYPE, $query )->get_data(), 'id' );
		$this->assertContains( $entry, $ids, 'the entry of the deleted live blog' );
		$this->assertNotContains( $other, $ids, 'not one whose live blog is still there' );

		$route = '/' . REST_NS . '/entry/' . $entry . '/restore';
		$this->assertSame( 400, $this->rest( 'POST', $route, array( 'liveblog' => 999999999 ) )->get_status(), 'a live blog that does not exist is refused' );
		$this->assertSame( 'trash', get_post_status( $entry ) );
		$restored = $this->rest( 'POST', $route, array( 'liveblog' => $kept ) );
		$this->assertSame( 'draft', $restored->get_data()['status'], 'it comes back as a draft, though it was published' );
		$this->assertSame( array( $kept ), wp_get_object_terms( $entry, LIVEBLOG_TAX, array( 'fields' => 'ids' ) ) );
		$this->assertSame( 'draft', get_post_status( $entry ), 'and stays one' );
	}

	public function test_create_page_starts_a_post_with_the_live_blog(): void {
		$liveblog = $this->make_liveblog();
		$draft                       = new WP_Post( (object) array( 'post_type' => 'post' ) );
		$_GET['postsocket_liveblog'] = (string) $liveblog;
		$content                     = apply_filters( 'default_content', '', $draft );
		$title                       = apply_filters( 'default_title', '', $draft );
		unset( $_GET['postsocket_liveblog'] );

		$this->assertSame( array( $liveblog ), feed_liveblogs( $content ) );
		$this->assertSame( get_term( $liveblog, LIVEBLOG_TAX )->name, $title );
		$this->assertSame( '', apply_filters( 'default_content', '', $draft ), 'nothing without the parameter' );
	}

	public function test_a_trashed_entry_is_restored_through_the_desk_route_as_it_was(): void {
		$liveblog = $this->make_liveblog();
		$entry    = $this->make_entry( $liveblog );
		$draft    = $this->make_entry( $liveblog, array( 'post_status' => 'draft' ) );
		$route    = '/' . REST_NS . '/entry/';
		wp_trash_post( $entry );
		wp_trash_post( $draft );

		$restored = $this->rest( 'POST', $route . $entry . '/restore' );
		$this->assertSame( 200, $restored->get_status() );
		$this->assertSame( 'publish', $restored->get_data()['status'], 'a published entry comes back published' );
		$this->assertSame( 'draft', $this->rest( 'POST', $route . $draft . '/restore' )->get_data()['status'] );

		$again = $this->rest( 'POST', $route . $draft . '/restore' );
		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'postsocket_not_trashed', $again->get_data()['code'] );

		wp_trash_post( $draft );
		wp_set_current_user( 0 );
		$this->assertContains( $this->rest( 'POST', $route . $draft . '/restore' )->get_status(), array( 401, 403 ), 'visitors restore nothing' );
		$this->assertSame( 'trash', get_post_status( $draft ) );
	}

	public function test_the_desk_reads_who_has_an_entry_open_and_lists_new_entries_still_in_the_editor(): void {
		$liveblog = $this->make_liveblog();
		$admin    = $this->admin_id();
		$draft    = $this->make_entry( $liveblog, array( 'post_status' => 'draft' ) );
		$open     = $this->make_entry( $liveblog, array( 'post_status' => 'auto-draft', 'post_title' => 'Auto Draft' ) );
		$idle     = $this->make_entry( $liveblog, array( 'post_status' => 'auto-draft', 'post_title' => 'Auto Draft' ) );
		update_post_meta( $draft, '_edit_lock', time() . ':' . $admin );
		update_post_meta( $open, '_edit_lock', time() . ':' . $admin );
		update_post_meta( $idle, '_edit_lock', ( time() - 600 ) . ':' . $admin );

		$query = array(
			'context'          => 'edit',
			LIVEBLOG_REST_BASE => $liveblog,
			'status'           => 'publish,draft',
		);
		$rows  = array_column( $this->rest( 'GET', '/wp/v2/' . ENTRY_TYPE, $query + array( 'postsocket_open' => true ) )->get_data(), null, 'id' );
		$this->assertEqualsCanonicalizing( array( $draft, $open ), array_keys( $rows ), 'an auto-draft is listed only while its editor holds it' );
		$this->assertSame( get_the_author_meta( 'display_name', $admin ), $rows[ $draft ]['postsocket']['editing']['name'] );
		$this->assertGreaterThan( 0, $rows[ $draft ]['postsocket']['editing']['expires_in'] );
		$this->assertSame( 'auto-draft', $rows[ $open ]['status'] );
		$this->assertSame( '', $rows[ $open ]['postsocket']['summary'], 'core\'s placeholder title is not a summary' );

		$plain = array_column( $this->rest( 'GET', '/wp/v2/' . ENTRY_TYPE, $query )->get_data(), 'id' );
		$this->assertSame( array( $draft ), $plain, 'without the parameter the collection is core\'s' );

		update_post_meta( $draft, '_edit_lock', ( time() - 600 ) . ':' . $admin );
		$this->assertNull( $this->rest( 'GET', '/wp/v2/' . ENTRY_TYPE . '/' . $draft, array( 'context' => 'edit' ) )->get_data()['postsocket']['editing'], 'a lapsed lock is nobody editing' );
	}
}

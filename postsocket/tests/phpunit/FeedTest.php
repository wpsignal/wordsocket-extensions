<?php
/**
 * The feed on the page: the markup, the auto-append and its guards, the JSON-LD, the entries route.
 */

use const WPSignal\Extensions\PostSocket\META_PINNED;
use const WPSignal\Extensions\PostSocket\REST_NS;

use function WPSignal\Extensions\PostSocket\jsonld;
use function WPSignal\Extensions\PostSocket\render_feed;

final class FeedTest extends ExtensionTestCase {

	public function test_the_feed_lists_pinned_then_newest_entries_and_pages(): void {
		$liveblog = $this->make_liveblog();
		$older    = $this->make_entry(
			$liveblog,
			array(
				'post_date'     => '2026-01-01 10:00:00',
				'post_date_gmt' => '2026-01-01 10:00:00',
				'post_title'    => 'Older',
			)
		);
		$newer    = $this->make_entry(
			$liveblog,
			array(
				'post_date'     => '2026-01-01 11:00:00',
				'post_date_gmt' => '2026-01-01 11:00:00',
				'post_title'    => 'Newer',
			)
		);
		$pinned   = $this->make_entry(
			$liveblog,
			array(
				'post_date'     => '2026-01-01 09:00:00',
				'post_date_gmt' => '2026-01-01 09:00:00',
				'post_title'    => 'Pinned',
			)
		);
		update_post_meta( $pinned, META_PINNED, true );

		$html = render_feed( $liveblog );
		$this->assertStringContainsString( 'data-postsocket-feed="' . $liveblog . '"', $html );
		$this->assertLessThan( strpos( $html, 'postsocket-' . $newer . '"' ), strpos( $html, 'postsocket-' . $pinned . '"' ), 'pinned first' );
		$this->assertLessThan( strpos( $html, 'postsocket-' . $older . '"' ), strpos( $html, 'postsocket-' . $newer . '"' ), 'then newest first' );
		$this->assertSame( 1, substr_count( $html, 'data-postsocket-entry="' . $pinned . '"' ), 'a pinned entry is listed once' );
		$this->assertStringContainsString( 'data-wp-interactive="postsocket/feed"', $html );

		$this->assertSame( '', render_feed( 0 ) );
	}

	public function test_the_feed_is_appended_to_the_live_blog_page_but_not_to_excerpts_or_the_relay_render(): void {
		$liveblog = $this->make_liveblog();
		$this->make_entry( $liveblog );

		$this->visit( $liveblog );
		$this->assertTrue( is_singular( \WPSignal\Extensions\PostSocket\LIVEBLOG_TYPE ) );
		ob_start();
		while ( have_posts() ) {
			the_post();
			the_content();
		}
		$page = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $page, 'data-postsocket-feed=' ), 'the feed is appended once' );
		$this->assertStringContainsString( 'data-postsocket-post="' . $liveblog . '"', $page, 'the intro is wrapped for living updates' );
		$this->assertLessThan( strpos( $page, 'data-postsocket-feed=' ), strpos( $page, 'data-postsocket-post=' ), 'the feed follows the wrapper' );
		$this->assertStringNotContainsString( 'data-postsocket-feed', substr( $page, 0, strpos( $page, '</div>' ) ), 'the wrapper does not enclose the feed' );

		$excerpt = get_the_excerpt( $liveblog );
		$this->assertStringNotContainsString( 'postsocket', $excerpt );

		$relay = \WPSignal\Extensions\PostSocket\render_content( get_post( $liveblog ) );
		$this->assertStringNotContainsString( 'data-postsocket', $relay );
	}

	public function test_the_json_ld_describes_a_live_blog_posting(): void {
		$liveblog = $this->make_liveblog();
		$entry    = $this->make_entry( $liveblog, array( 'post_content' => '<!-- wp:paragraph --><p>Body text.</p><!-- /wp:paragraph -->' ) );

		$data = jsonld( get_post( $liveblog ) );
		$this->assertSame( 'LiveBlogPosting', $data['@type'] );
		$this->assertSame( get_permalink( $liveblog ), $data['url'] );
		$this->assertArrayHasKey( 'coverageStartTime', $data );
		$this->assertArrayNotHasKey( 'coverageEndTime', $data );
		$this->assertCount( 1, $data['liveBlogUpdate'] );
		$this->assertSame( 'BlogPosting', $data['liveBlogUpdate'][0]['@type'] );
		$this->assertSame( 'Body text.', $data['liveBlogUpdate'][0]['articleBody'] );
		$this->assertSame( get_permalink( $liveblog ) . '#postsocket-' . $entry, $data['liveBlogUpdate'][0]['url'] );

		update_post_meta( $liveblog, \WPSignal\Extensions\PostSocket\META_STATE, 'ended' );
		update_post_meta( $liveblog, \WPSignal\Extensions\PostSocket\META_ENDED, 1767265200 );
		$this->assertSame( gmdate( 'c', 1767265200 ), jsonld( get_post( $liveblog ) )['coverageEndTime'] );
	}

	public function test_the_entries_route_pages_by_date_and_is_public(): void {
		$liveblog = $this->make_liveblog();
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->make_entry(
				$liveblog,
				array(
					'post_date'     => "2026-01-01 1{$i}:00:00",
					'post_date_gmt' => "2026-01-01 1{$i}:00:00",
					'post_title'    => "E{$i}",
				)
			);
		}
		wp_set_current_user( 0 );

		$first = $this->rest( 'GET', '/' . REST_NS . '/liveblog/' . $liveblog . '/entries', array( 'limit' => 2 ) );
		$this->assertSame( 200, $first->get_status() );
		$this->assertCount( 2, $first->get_data()['entries'] );
		$this->assertTrue( $first->get_data()['more'] );
		$this->assertSame( 'live', $first->get_data()['state'] );
		$this->assertStringContainsString( 'public', $first->get_headers()['Cache-Control'] );
		$titles = array_map( static fn( $e ) => strip_tags( $e['html'] ), $first->get_data()['entries'] );
		$this->assertStringContainsString( 'E4', $titles[0] );
		$this->assertStringContainsString( 'E3', $titles[1] );

		$second = $this->rest( 'GET', '/' . REST_NS . '/liveblog/' . $liveblog . '/entries', array( 'limit' => 2, 'before' => $first->get_data()['entries'][1]['date'], 'before_id' => $first->get_data()['entries'][1]['id'] ) );
		$this->assertCount( 2, $second->get_data()['entries'] );
		$this->assertFalse( $second->get_data()['more'] );
		$this->assertStringContainsString( 'E2', strip_tags( $second->get_data()['entries'][0]['html'] ) );

		$same_second = $this->make_liveblog( array( 'post_title' => 'Same second' ) );
		$ids         = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$ids[] = $this->make_entry( $same_second, array( 'post_date' => '2026-02-02 12:00:00', 'post_date_gmt' => '2026-02-02 12:00:00', 'post_title' => "S{$i}" ) );
		}
		$page_one = $this->rest( 'GET', '/' . REST_NS . '/liveblog/' . $same_second . '/entries', array( 'limit' => 2 ) )->get_data();
		$page_two = $this->rest( 'GET', '/' . REST_NS . '/liveblog/' . $same_second . '/entries', array( 'limit' => 2, 'before' => $page_one['entries'][1]['date'], 'before_id' => $page_one['entries'][1]['id'] ) )->get_data();
		$this->assertSame( array( $ids[2], $ids[1] ), wp_list_pluck( $page_one['entries'], 'id' ) );
		$this->assertSame( array( $ids[0] ), wp_list_pluck( $page_two['entries'], 'id' ), 'entries sharing a second are paged by id' );

		$draft = $this->make_liveblog( array( 'post_status' => 'draft' ) );
		$this->assertSame( 404, $this->rest( 'GET', '/' . REST_NS . '/liveblog/' . $draft . '/entries' )->get_status() );
	}
}

<?php
/**
 * The feed on the page: the markup, the block in a host post, the JSON-LD on the home, the entries route.
 */

use const WPSignal\Extensions\PostSocket\LIVEBLOG_TAX;
use const WPSignal\Extensions\PostSocket\META_ENDED;
use const WPSignal\Extensions\PostSocket\META_PINNED;
use const WPSignal\Extensions\PostSocket\META_STATE;
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

	public function test_the_block_shows_its_live_blog_in_the_host_post_but_not_in_excerpts_or_the_relay_render(): void {
		$liveblog = $this->make_liveblog();
		$this->make_entry( $liveblog );
		$host = $this->make_host( $liveblog );

		$this->visit( $host );
		ob_start();
		while ( have_posts() ) {
			the_post();
			the_content();
		}
		$page = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $page, 'data-postsocket-feed="' . $liveblog . '"' ), 'the block renders the feed once' );
		$this->assertStringContainsString( 'data-postsocket-post="' . $host . '"', $page, 'the host is wrapped for living updates' );
		$this->assertTrue( \WPSignal\Extensions\PostSocket\feed_rendered(), 'the feed marks the page live and enqueues its module' );

		$this->assertStringNotContainsString( 'postsocket', get_the_excerpt( $host ) );
		$relay = \WPSignal\Extensions\PostSocket\render_content( get_post( $host ) );
		$this->assertStringNotContainsString( 'data-postsocket-feed=', $relay, 'the relay render carries no feed' );
		$this->assertStringContainsString( 'data-postsocket-feed-slot="' . $liveblog . '"', $relay, 'only a slot where it sits' );
		$this->assertStringContainsString( 'Kick-off at three.', $relay );

		$this->assertSame( '', do_blocks( '<!-- wp:postsocket/live-feed /-->' ), 'a block naming no live blog renders nothing' );
	}

	public function test_the_json_ld_describes_a_live_blog_posting_on_its_home_only(): void {
		$liveblog = $this->make_liveblog();
		$entry    = $this->make_entry( $liveblog, array( 'post_content' => '<!-- wp:paragraph --><p>Body text.</p><!-- /wp:paragraph -->' ) );
		$home     = $this->make_host( $liveblog );
		$elsewhere = $this->make_host( $liveblog, array( 'post_title' => 'Sidebar' ) );

		$data = jsonld( get_term( $liveblog, LIVEBLOG_TAX ), get_post( $home ) );
		$this->assertSame( 'LiveBlogPosting', $data['@type'] );
		$this->assertSame( get_permalink( $home ), $data['url'] );
		$this->assertSame( 'Match day', $data['headline'] );
		$this->assertArrayHasKey( 'coverageStartTime', $data );
		$this->assertArrayNotHasKey( 'coverageEndTime', $data );
		$this->assertCount( 1, $data['liveBlogUpdate'] );
		$this->assertSame( 'Body text.', $data['liveBlogUpdate'][0]['articleBody'] );
		$this->assertSame( get_permalink( $home ) . '#postsocket-' . $entry, $data['liveBlogUpdate'][0]['url'] );

		update_term_meta( $liveblog, META_STATE, 'ended' );
		update_term_meta( $liveblog, META_ENDED, 1767265200 );
		$this->assertSame( gmdate( 'c', 1767265200 ), jsonld( get_term( $liveblog, LIVEBLOG_TAX ), get_post( $home ) )['coverageEndTime'] );

		$this->visit( $home );
		ob_start();
		do_action( 'wp_head' );
		$this->assertStringContainsString( 'id="postsocket-jsonld"', (string) ob_get_clean() );
		$this->visit( $elsewhere );
		ob_start();
		do_action( 'wp_head' );
		$this->assertStringNotContainsString( 'id="postsocket-jsonld"', (string) ob_get_clean(), 'a second placement prints no JSON-LD' );
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

		$same_second = $this->make_liveblog( 'Same second' );
		$ids         = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$ids[] = $this->make_entry( $same_second, array( 'post_date' => '2026-02-02 12:00:00', 'post_date_gmt' => '2026-02-02 12:00:00', 'post_title' => "S{$i}" ) );
		}
		$page_one = $this->rest( 'GET', '/' . REST_NS . '/liveblog/' . $same_second . '/entries', array( 'limit' => 2 ) )->get_data();
		$page_two = $this->rest( 'GET', '/' . REST_NS . '/liveblog/' . $same_second . '/entries', array( 'limit' => 2, 'before' => $page_one['entries'][1]['date'], 'before_id' => $page_one['entries'][1]['id'] ) )->get_data();
		$this->assertSame( array( $ids[2], $ids[1] ), wp_list_pluck( $page_one['entries'], 'id' ) );
		$this->assertSame( array( $ids[0] ), wp_list_pluck( $page_two['entries'], 'id' ), 'entries sharing a second are paged by id' );

		$this->assertSame( 404, $this->rest( 'GET', '/' . REST_NS . '/liveblog/999999999/entries' )->get_status(), 'no such live blog' );
	}
}

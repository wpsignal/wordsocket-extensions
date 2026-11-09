<?php
/**
 * Living articles: the enabled types, the wrapper on the page, the inline limit, the content route.
 */

use const WPSignal\Extensions\PostSocket\OPTION;
use const WPSignal\Extensions\PostSocket\REST_NS;

use function WPSignal\Extensions\PostSocket\is_living;
use function WPSignal\Extensions\PostSocket\post_channel;

final class LivingTest extends ExtensionTestCase {

	private function make_post( string $content = '<!-- wp:paragraph --><p>Hello.</p><!-- /wp:paragraph -->', array $args = array() ): int {
		$id = wp_insert_post(
			array_merge(
				array(
					'post_type'    => 'post',
					'post_title'   => 'Article',
					'post_content' => $content,
					'post_status'  => 'publish',
					'post_author'  => $this->admin_id(),
				),
				$args
			),
			true
		);
		$this->assertIsInt( $id );
		$this->posts[] = $id;
		return $id;
	}

	public function test_a_post_type_is_living_only_once_enabled(): void {
		$post = $this->make_post();
		$this->assertFalse( is_living( $post ), 'nothing is enabled by default' );

		update_option( OPTION, array( 'living_types' => array( 'post' ) ) );
		$this->assertTrue( is_living( $post ) );
		$this->assertFalse( is_living( $this->make_post( 'x', array( 'post_status' => 'draft' ) ) ), 'drafts are not living' );

		$builder = $this->make_post();
		update_post_meta( $builder, '_elementor_edit_mode', 'builder' );
		$this->assertFalse( is_living( $builder ), 'a page-builder post is left alone' );
	}

	public function test_an_enabled_post_publishes_its_content_inline_or_marks_it_for_fetching(): void {
		update_option( OPTION, array( 'living_types' => array( 'post' ) ) );
		$post = $this->make_post();
		$this->published = array();

		wp_update_post( array( 'ID' => $post, 'post_content' => '<!-- wp:paragraph --><p>Changed.</p><!-- /wp:paragraph -->' ) );
		$events = $this->events_on( post_channel( $post ), 'postsocket.post.updated' );
		$this->assertCount( 1, $events );
		$this->assertStringContainsString( 'Changed.', $events[0]['data']['html'] );
		$this->assertArrayNotHasKey( 'fetch', $events[0]['data'] );
		$this->assertSame( array( 'styles', 'scripts' ), array_keys( $events[0]['data']['assets'] ) );

		$this->published = array();
		wp_update_post( array( 'ID' => $post, 'post_content' => '<!-- wp:paragraph --><p>' . str_repeat( 'Long text. ', 4000 ) . '</p><!-- /wp:paragraph -->' ) );
		$events = $this->events_on( post_channel( $post ), 'postsocket.post.updated' );
		$this->assertCount( 1, $events );
		$this->assertTrue( $events[0]['data']['fetch'] );
		$this->assertArrayNotHasKey( 'html', $events[0]['data'], 'a 40 KB post is fetched, not carried' );

		$this->published = array();
		wp_update_post( array( 'ID' => $post, 'post_title' => 'Article' ) );
		$this->assertSame( array(), $this->events_on( post_channel( $post ) ), 'no change, no event' );
	}

	public function test_the_wrapper_is_on_the_page_only_for_living_posts_and_never_in_excerpts(): void {
		$post = $this->make_post();
		$this->visit( $post );
		ob_start();
		while ( have_posts() ) {
			the_post();
			the_content();
		}
		$this->assertStringNotContainsString( 'data-postsocket-post', (string) ob_get_clean(), 'not enabled, not wrapped' );

		update_option( OPTION, array( 'living_types' => array( 'post' ) ) );
		$this->visit( $post );
		ob_start();
		while ( have_posts() ) {
			the_post();
			the_content();
		}
		$page = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-postsocket-post="' . $post . '"', $page );
		$this->assertStringContainsString( 'data-postsocket-url="' . esc_url( rest_url( REST_NS . '/content/' . $post ) ) . '"', $page );
		$this->assertStringNotContainsString( 'data-postsocket', get_the_excerpt( $post ) );

		$rest = $this->rest( 'GET', '/wp/v2/posts/' . $post );
		$this->assertStringNotContainsString( 'data-postsocket', $rest->get_data()['content']['rendered'], 'REST content is untouched' );
	}

	public function test_the_content_route_serves_living_posts_cacheably_and_404s_the_rest(): void {
		$post = $this->make_post();
		wp_set_current_user( 0 );
		$this->assertSame( 404, $this->rest( 'GET', '/' . REST_NS . '/content/' . $post )->get_status(), 'not enabled' );

		update_option( OPTION, array( 'living_types' => array( 'post' ) ) );
		$response = $this->rest( 'GET', '/' . REST_NS . '/content/' . $post, array( 'v' => '2026-01-01T00:00:00+00:00' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $post, $response->get_data()['id'] );
		$this->assertStringContainsString( 'Hello.', $response->get_data()['html'] );
		$this->assertStringNotContainsString( 'data-postsocket', $response->get_data()['html'] );
		$this->assertSame( 'public, max-age=300, s-maxage=300', $response->get_headers()['Cache-Control'] );

		$draft = $this->make_post( 'x', array( 'post_status' => 'draft' ) );
		$this->assertSame( 404, $this->rest( 'GET', '/' . REST_NS . '/content/' . $draft )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', '/' . REST_NS . '/content/999999999' )->get_status() );
	}
}

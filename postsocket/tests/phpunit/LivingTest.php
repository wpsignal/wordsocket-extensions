<?php
/**
 * Living articles: the enabled types, the wrapper on the page, the inline limit, the content route, meta and term changes, live fields, the page refresh, the render a wp-admin request takes from the content route.
 */

use const WPSignal\Extensions\PostSocket\META_LIVE;
use const WPSignal\Extensions\PostSocket\OPTION;
use const WPSignal\Extensions\PostSocket\REST_NS;

use function WPSignal\Extensions\PostSocket\is_living;
use function WPSignal\Extensions\PostSocket\post_channel;
use function WPSignal\Extensions\PostSocket\rendered_post;
use function WPSignal\Extensions\PostSocket\render_generation;
use function WPSignal\Extensions\PostSocket\tell_readers;

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
		update_post_meta( $id, META_LIVE, 1 );
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

	public function test_a_post_is_living_only_once_live_editing_is_switched_on(): void {
		update_option( OPTION, array( 'living_types' => array( 'post' ) ) );
		$post = $this->make_post();
		delete_post_meta( $post, META_LIVE );
		$this->assertFalse( is_living( $post ), 'off until switched on' );
		tell_readers();

		wp_set_current_user( $this->admin_id() );
		$saved = $this->rest( 'POST', '/wp/v2/posts/' . $post, array( 'postsocket_live' => true ) );
		$this->assertTrue( $saved->get_data()['postsocket_live'] );
		$this->assertTrue( is_living( $post ) );
		$this->published = array();
		tell_readers();
		$this->assertSame( array(), $this->events_on( post_channel( $post ), 'postsocket.post.updated' ), 'the switch itself tells readers nothing' );

		$this->rest( 'POST', '/wp/v2/posts/' . $post, array( 'postsocket_live' => false ) );
		$this->assertFalse( is_living( $post ) );
	}

	public function test_a_post_showing_a_live_blog_is_living_without_its_type_enabled(): void {
		$liveblog = $this->make_liveblog();
		$this->assertTrue( is_living( $this->make_host( $liveblog ) ) );
		$this->assertTrue( is_living( $this->make_host( $liveblog, array( 'post_type' => 'page' ) ) ), 'any post type' );
		$this->assertFalse( is_living( $this->make_entry( $liveblog ) ), 'an entry is never a living article' );
	}

	public function test_an_enabled_post_publishes_its_content_inline_or_marks_it_for_fetching(): void {
		update_option( OPTION, array( 'living_types' => array( 'post' ) ) );
		$post = $this->make_post();
		$this->published = array();

		wp_update_post( array( 'ID' => $post, 'post_content' => '<!-- wp:paragraph --><p>Changed.</p><!-- /wp:paragraph -->' ) );
		tell_readers();
		$events = $this->events_on( post_channel( $post ), 'postsocket.post.updated' );
		$this->assertCount( 1, $events );
		$this->assertStringContainsString( 'Changed.', $events[0]['data']['html'] );
		$this->assertArrayNotHasKey( 'fetch', $events[0]['data'] );
		$this->assertSame( array( 'styles', 'scripts', 'modules' ), array_keys( $events[0]['data']['assets'] ) );

		$this->published = array();
		wp_update_post( array( 'ID' => $post, 'post_content' => '<!-- wp:paragraph --><p>' . str_repeat( 'Long text. ', 4000 ) . '</p><!-- /wp:paragraph -->' ) );
		tell_readers();
		$events = $this->events_on( post_channel( $post ), 'postsocket.post.updated' );
		$this->assertCount( 1, $events );
		$this->assertTrue( $events[0]['data']['fetch'] );
		$this->assertArrayNotHasKey( 'html', $events[0]['data'], 'a 40 KB post is fetched, not carried' );

		$this->published = array();
		wp_update_post( array( 'ID' => $post, 'post_title' => 'Article' ) );
		tell_readers();
		$this->assertSame( array(), $this->events_on( post_channel( $post ) ), 'no change, no event' );
	}

	public function test_a_title_only_edit_publishes_the_new_title(): void {
		update_option( OPTION, array( 'living_types' => array( 'post' ) ) );
		$post            = $this->make_post();
		$this->published = array();

		wp_update_post( array( 'ID' => $post, 'post_title' => 'Article & more' ) );
		tell_readers();
		$events = $this->events_on( post_channel( $post ), 'postsocket.post.updated' );
		$this->assertCount( 1, $events, 'the title is part of what readers see' );
		$this->assertSame( 'Article & more', $events[0]['data']['title'], 'plain text, entities decoded' );
	}

	public function test_the_post_title_block_is_marked_only_for_the_living_post_on_its_page(): void {
		update_option( OPTION, array( 'living_types' => array( 'post' ) ) );
		$post  = $this->make_post();
		$other = $this->make_post( 'x', array( 'post_title' => 'Elsewhere' ) );
		$this->visit( $post );

		$own = render_block( array( 'blockName' => 'core/post-title', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
		$this->assertStringContainsString( 'data-postsocket-title="' . $post . '"', $own );

		$block = new WP_Block( array( 'blockName' => 'core/post-title', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ), array( 'postId' => $other, 'postType' => 'post' ) );
		$this->assertStringNotContainsString( 'data-postsocket-title', $block->render(), 'another post\'s title on the page is left alone' );

		$linked = render_block( array( 'blockName' => 'core/post-title', 'attrs' => array( 'isLink' => true ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
		$this->assertStringNotContainsString( 'data-postsocket-title', $linked, 'a linked copy, as a list shows it, is left alone' );

		$again = render_block( array( 'blockName' => 'core/post-title', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
		$this->assertStringNotContainsString( 'data-postsocket-title', $again, 'only the first title is marked' );
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
		$this->assertStringContainsString( 'data-postsocket-title-selector="' . esc_attr( '[data-postsocket-title="' . $post . '"]' ) . '"', $page, 'the default selector' );
		$this->assertStringNotContainsString( 'data-postsocket', get_the_excerpt( $post ) );

		$selector = static fn (): string => 'h1.entry-title';
		add_filter( 'postsocket_title_selector', $selector );
		$this->visit( $post );
		ob_start();
		while ( have_posts() ) {
			the_post();
			the_content();
		}
		remove_filter( 'postsocket_title_selector', $selector );
		$this->assertStringContainsString( 'data-postsocket-title-selector="h1.entry-title"', (string) ob_get_clean(), 'the filtered selector' );

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

	/** The post's events from ending the request now. */
	private function heard( int $post ): array {
		$this->published = array();
		tell_readers();
		return $this->events_on( post_channel( $post ), 'postsocket.post.updated' );
	}

	public function test_a_meta_or_term_change_is_rendered_again_once_the_request_ends(): void {
		update_option( OPTION, array( 'living_types' => array( 'post' ) ) );
		add_shortcode( 'postsocket_test_mood', static fn(): string => '<em>' . esc_html( (string) get_post_meta( get_the_ID(), 'mood', true ) ) . '</em>' );
		$post = $this->make_post( '<!-- wp:shortcode -->[postsocket_test_mood]<!-- /wp:shortcode -->' );
		$this->heard( $post );
		$this->published = array();

		update_post_meta( $post, 'mood', 'calm' );
		$this->assertSame( array(), $this->events_on( post_channel( $post ) ), 'nothing before the request ends' );
		$events = $this->heard( $post );
		$this->assertCount( 1, $events, 'a meta change outside a save reaches readers' );
		$this->assertStringContainsString( '<em>calm</em>', $events[0]['data']['html'] );

		$saved = $this->rest( 'POST', '/wp/v2/posts/' . $post, array( 'title' => 'Article' ) );
		$this->assertSame( 200, $saved->get_status() );
		update_post_meta( $post, 'mood', 'tense' );
		update_post_meta( $post, 'unrelated', 'x' );
		$events = $this->heard( $post );
		$this->assertCount( 1, $events, 'a save and two meta writes in one request publish once' );
		$this->assertStringContainsString( '<em>tense</em>', $events[0]['data']['html'] );

		update_post_meta( $post, 'unrelated', 'y' );
		$this->assertSame( array(), $this->heard( $post ), 'a change readers cannot see publishes nothing' );

		wp_set_current_user( 0 );
		update_post_meta( $post, 'mood', 'visitor' );
		$this->assertSame( array(), $this->heard( $post ), 'a visitor\'s request is not looked at' );
		$watch = static fn( bool $watch, string $key ): bool => 'mood' === $key ? true : $watch;
		add_filter( 'postsocket_watch_meta', $watch, 10, 2 );
		update_post_meta( $post, 'mood', 'open' );
		remove_filter( 'postsocket_watch_meta', $watch, 10 );
		$this->assertCount( 1, $this->heard( $post ), 'unless the filter says so' );
		remove_shortcode( 'postsocket_test_mood' );
	}

	public function test_live_fields_ride_the_payload_the_route_and_the_wrapper(): void {
		update_option( OPTION, array( 'living_types' => array( 'post' ) ) );
		$fields = static function ( array $fields ): array {
			$fields['subtitle']     = array();
			$fields['reading_time'] = array(
				'selector' => '.reading-time',
				'meta'     => 'minutes',
				'render'   => static fn( WP_Post $post ): string => '<b>' . (int) get_post_meta( $post->ID, 'minutes', true ) . ' min</b>',
			);
			$fields['Not a name']   = array();
			return $fields;
		};
		add_filter( 'postsocket_live_fields', $fields );
		$post = $this->make_post();
		update_post_meta( $post, 'subtitle', 'Late <drama>' );
		update_post_meta( $post, 'minutes', 4 );

		$events = $this->heard( $post );
		$this->assertCount( 1, $events );
		$sent = array_intersect_key( $events[0]['data']['fields'], array_flip( array( 'subtitle', 'reading_time' ) ) );
		$this->assertSame(
			array(
				'subtitle'     => 'Late &lt;drama&gt;',
				'reading_time' => '<b>4 min</b>',
			),
			$sent,
			'a field without a render is its meta as text'
		);
		$this->assertArrayNotHasKey( 'Not a name', $events[0]['data']['fields'] );

		update_post_meta( $post, 'minutes', 5 );
		$events = $this->heard( $post );
		$this->assertCount( 1, $events, 'a field-only change publishes' );
		$this->assertSame( '<b>5 min</b>', $events[0]['data']['fields']['reading_time'] );
		update_post_meta( $post, 'minutes', '5 ' );
		$this->assertSame( array(), $this->heard( $post ), 'a field that renders the same publishes nothing' );

		wp_set_current_user( 0 );
		update_post_meta( $post, 'minutes', 6 );
		$this->assertCount( 1, $this->heard( $post ), 'a field\'s own key is looked at whoever writes it' );

		$route = $this->rest( 'GET', '/' . REST_NS . '/content/' . $post )->get_data();
		$this->assertSame( '<b>6 min</b>', $route['fields']['reading_time'] );
		$this->assertSame( $events[0]['data']['hash'] === $route['hash'], false, 'the hash covers the fields' );

		$this->visit( $post );
		ob_start();
		while ( have_posts() ) {
			the_post();
			the_content();
		}
		$page = (string) ob_get_clean();
		remove_filter( 'postsocket_live_fields', $fields );
		preg_match( '/data-postsocket-fields="([^"]*)"/', $page, $found );
		$named = json_decode( html_entity_decode( $found[1] ?? '', ENT_QUOTES ), true );
		$this->assertSame( '.reading-time', $named['reading_time'] ?? null, 'the wrapper names the selectors' );
		$this->assertArrayNotHasKey( 'subtitle', (array) $named, 'a field without one is found by its data attribute' );
	}

	public function test_a_refresh_selector_refreshes_the_page_for_changes_nothing_else_shows(): void {
		update_option( OPTION, array( 'living_types' => array( 'post' ) ) );
		$none     = static fn(): array => array();
		$selector = static fn(): string => '.site-main';
		add_filter( 'postsocket_live_fields', $none, 99 );
		$post = $this->make_post();
		$this->heard( $post );

		update_post_meta( $post, 'outside', 'a' );
		$this->assertSame( array(), $this->heard( $post ), 'without a selector, a change readers cannot see publishes nothing' );

		add_filter( 'postsocket_refresh_selector', $selector );
		update_post_meta( $post, 'outside', 'b' );
		$events = $this->heard( $post );
		$this->assertCount( 1, $events, 'with one, every watched change is told' );
		$this->assertTrue( $events[0]['data']['refresh'] );
		$this->assertSame( array( 'id', 'modified', 'hash', 'title', 'refresh' ), array_values( array_diff( array_keys( $events[0]['data'] ), array( 'changed' ) ) ), 'a change outside the content carries no markup' );
		$this->assertSame( array( 'meta:outside' ), $events[0]['data']['changed'] ?? array( 'meta:outside' ), 'in debug mode it says what changed' );

		wp_update_post( array( 'ID' => $post, 'post_content' => '<!-- wp:paragraph --><p>Edited.</p><!-- /wp:paragraph -->' ) );
		$events = $this->heard( $post );
		$this->assertCount( 1, $events );
		$this->assertArrayNotHasKey( 'refresh', $events[0]['data'], 'a content edit is applied inline, with no page refresh' );
		$this->assertStringContainsString( 'Edited.', $events[0]['data']['html'] );

		$this->visit( $post );
		ob_start();
		while ( have_posts() ) {
			the_post();
			the_content();
		}
		$this->assertStringContainsString( 'data-postsocket-refresh=".site-main"', (string) ob_get_clean() );

		remove_filter( 'postsocket_live_fields', $none, 99 );
		$fields = static fn( array $fields ): array => $fields + array( 'outside' => array() );
		add_filter( 'postsocket_live_fields', $fields );
		update_post_meta( $post, 'outside', 'c' );
		$events = $this->heard( $post );
		update_post_meta( $post, 'elsewhere', 'd' );
		$others = $this->heard( $post );
		remove_filter( 'postsocket_live_fields', $fields );
		remove_filter( 'postsocket_refresh_selector', $selector );
		$this->assertCount( 1, $others, 'meta no field names still refreshes the page' );
		$this->assertTrue( $others[0]['data']['refresh'] );
		$this->assertCount( 1, $events );
		$this->assertArrayNotHasKey( 'refresh', $events[0]['data'], 'a change a live field shows is updated by the field, not refreshed' );
		$this->assertSame( 'c', $events[0]['data']['fields']['outside'] );
	}

	public function test_a_wp_admin_request_takes_its_render_from_the_content_route(): void {
		update_option( OPTION, array( 'living_types' => array( 'post' ) ) );
		$post  = get_post( $this->make_post() );
		$own   = rendered_post( $post );
		$asked = array();
		$route = static function ( $pre, $args, $url ) use ( &$asked, $post ) {
			if ( ! str_contains( (string) $url, '/content/' . $post->ID ) ) {
				return $pre;
			}
			$asked[] = $args;
			return array(
				'response' => array(
					'code'    => 200,
					'message' => '',
				),
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'html'   => '<p>From the route.</p>',
						'hash'   => 'routehash',
						'assets' => array( 'styles' => array() ),
					)
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $route, 10, 3 );
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'post' );
		render_generation( $post->ID, true );
		$admin = rendered_post( $post );
		$off   = static fn(): bool => false;
		add_filter( 'postsocket_remote_render', $off );
		render_generation( $post->ID, true );
		$local = rendered_post( $post );
		remove_filter( 'postsocket_remote_render', $off );
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		remove_filter( 'pre_http_request', $route, 10 );

		$this->assertCount( 1, $asked, 'asked once, and not when the filter turns it off' );
		$this->assertSame( array(), $asked[0]['cookies'], 'as a visitor' );
		$this->assertSame( 'routehash', $admin['hash'] );
		$this->assertSame( '<p>From the route.</p>', $admin['html'] );
		$this->assertSame( $own['hash'], $local['hash'], 'its own render otherwise' );
	}

	public function test_a_live_taxonomy_is_the_posts_terms_and_follows_them(): void {
		update_option( OPTION, array( 'living_types' => array( 'post' ) ) );
		$taxonomies = static function ( array $taxonomies ): array {
			$taxonomies['post_tag'] = array( 'selector' => '.tag-links' );
			$taxonomies['category'] = array(
				'render' => static fn( WP_Post $post ): string => (string) count( wp_get_post_categories( $post->ID ) ),
			);
			$taxonomies['no_such']  = array();
			return $taxonomies;
		};
		add_filter( 'postsocket_live_taxonomies', $taxonomies );
		$post = $this->make_post();
		$this->heard( $post );

		wp_set_post_tags( $post, array( 'Harbour zq', 'Schools zq' ) );
		$events = $this->heard( $post );
		$this->assertCount( 1, $events, 'a term change publishes the taxonomy' );
		$tags = $events[0]['data']['fields']['taxonomy-post_tag'];
		$this->assertStringContainsString( '>Harbour zq</a>, <a', $tags, 'the linked terms, comma separated' );
		$this->assertStringContainsString( 'Schools zq', $tags );
		$this->assertSame( '1', $events[0]['data']['fields']['taxonomy-category'], 'a render of its own' );
		$this->assertArrayNotHasKey( 'taxonomy-no_such', $events[0]['data']['fields'] );

		wp_set_post_tags( $post, array( 'Harbour zq', 'Schools zq' ) );
		$this->assertSame( array(), $this->heard( $post ), 'the same terms publish nothing' );

		wp_set_current_user( 0 );
		wp_set_post_tags( $post, array( 'Harbour zq' ) );
		$events = $this->heard( $post );
		$this->assertCount( 1, $events, 'a live taxonomy is looked at whoever changes it' );
		$this->assertStringNotContainsString( 'Schools zq', $events[0]['data']['fields']['taxonomy-post_tag'] );
		wp_set_object_terms( $post, 'aside', 'post_format' );
		$this->assertSame( array(), $this->heard( $post ), 'another taxonomy is not' );

		$this->visit( $post );
		ob_start();
		while ( have_posts() ) {
			the_post();
			the_content();
		}
		$page = (string) ob_get_clean();
		remove_filter( 'postsocket_live_taxonomies', $taxonomies );
		preg_match( '/data-postsocket-fields="([^"]*)"/', $page, $found );
		$named = json_decode( html_entity_decode( $found[1] ?? '', ENT_QUOTES ), true );
		$this->assertSame( '.tag-links', $named['taxonomy-post_tag'] ?? null );
		$this->assertSame( '[data-postsocket-taxonomy="category"]', $named['taxonomy-category'] ?? null, 'the data attribute without a selector' );
		foreach ( array( 'Harbour zq', 'Schools zq' ) as $name ) {
			$term = get_term_by( 'name', $name, 'post_tag' );
			if ( $term ) {
				wp_delete_term( $term->term_id, 'post_tag' );
			}
		}
	}

	public function test_excluded_meta_and_taxonomies_and_an_unchanged_save_are_never_looked_at(): void {
		update_option( OPTION, array( 'living_types' => array( 'post' ) ) );
		$none     = static fn(): array => array();
		$selector = static fn(): string => '.site-main';
		$meta     = static fn( array $keys ): array => array_merge( $keys, array( 'view_count' ) );
		$taxes    = static fn( array $taxonomies ): array => array_merge( $taxonomies, array( 'post_format' ) );
		add_filter( 'postsocket_live_fields', $none, 99 );
		add_filter( 'postsocket_refresh_selector', $selector );
		add_filter( 'postsocket_exclude_live_fields', $meta );
		add_filter( 'postsocket_exclude_live_taxonomies', $taxes );
		$post = $this->make_post();
		$this->heard( $post );

		update_post_meta( $post, 'view_count', 5 );
		wp_set_object_terms( $post, 'aside', 'post_format' );
		$excluded = $this->heard( $post );
		wp_update_post( array( 'ID' => $post, 'post_title' => 'Article' ) );
		$same = $this->heard( $post );
		wp_update_post( array( 'ID' => $post, 'page_template' => 'default' ) );
		$this->heard( $post );
		wp_update_post( array( 'ID' => $post, 'page_template' => 'default' ) );
		$template = $this->heard( $post );
		update_post_meta( $post, 'other', 5 );
		$other = $this->heard( $post );
		delete_post_meta( $post, 'other' );
		add_post_meta( $post, 'other', 5 );
		$rewritten = $this->heard( $post );
		wp_set_post_tags( $post, array( 'Excluded zq' ) );
		$tagged = $this->heard( $post );
		wp_set_post_tags( $post, array( 'Excluded zq' ) );
		$again = $this->heard( $post );

		remove_filter( 'postsocket_live_fields', $none, 99 );
		remove_filter( 'postsocket_refresh_selector', $selector );
		remove_filter( 'postsocket_exclude_live_fields', $meta );
		remove_filter( 'postsocket_exclude_live_taxonomies', $taxes );
		$term = get_term_by( 'name', 'Excluded zq', 'post_tag' );
		if ( $term ) {
			wp_delete_term( $term->term_id, 'post_tag' );
		}

		$this->assertSame( array(), $excluded, 'an excluded meta key and an excluded taxonomy refresh nothing' );
		$this->assertSame( array(), $same, 'nor does a save that changes nothing' );
		$this->assertSame( array(), $template, 'nor one that only names the page template it already has' );
		$this->assertCount( 1, $other, 'another meta key does' );
		$this->assertTrue( $other[0]['data']['refresh'] );
		$this->assertSame( array(), $rewritten, 'a key deleted and added back as it was is no change' );
		$this->assertCount( 1, $tagged, 'and another taxonomy' );
		$this->assertSame( array(), $again, 'but not the same terms set again' );
	}

	public function test_a_reload_field_or_taxonomy_tells_readers_to_reload_the_page(): void {
		update_option( OPTION, array( 'living_types' => array( 'post' ) ) );
		$none   = static fn(): array => array();
		$fields = static fn( array $names ): array => array_merge( $names, array( 'comment_status', 'layout' ) );
		$taxes  = static fn( array $taxonomies ): array => array_merge( $taxonomies, array( 'post_format' ) );
		add_filter( 'postsocket_live_fields', $none, 99 );
		add_filter( 'postsocket_reload_live_fields', $fields );
		add_filter( 'postsocket_reload_live_taxonomies', $taxes );
		$post = $this->make_post( '<!-- wp:paragraph --><p>Hello.</p><!-- /wp:paragraph -->', array( 'comment_status' => 'closed' ) );
		$this->heard( $post );

		wp_update_post( array( 'ID' => $post, 'comment_status' => 'open' ) );
		$column = $this->heard( $post );
		update_post_meta( $post, 'layout', 'wide' );
		$meta = $this->heard( $post );
		wp_set_object_terms( $post, 'aside', 'post_format' );
		$terms = $this->heard( $post );
		wp_update_post( array( 'ID' => $post, 'post_content' => '<!-- wp:paragraph --><p>Edited.</p><!-- /wp:paragraph -->' ) );
		$content = $this->heard( $post );
		update_post_meta( $post, 'elsewhere', 1 );
		$other = $this->heard( $post );
		wp_set_current_user( 0 );
		update_post_meta( $post, 'layout', 'narrow' );
		$visitor = $this->heard( $post );

		remove_filter( 'postsocket_live_fields', $none, 99 );
		remove_filter( 'postsocket_reload_live_fields', $fields );
		remove_filter( 'postsocket_reload_live_taxonomies', $taxes );

		foreach ( array( 'a post column' => $column, 'a meta key' => $meta, 'a taxonomy' => $terms, 'whoever changes it' => $visitor ) as $what => $events ) {
			$this->assertCount( 1, $events, $what );
			$this->assertTrue( $events[0]['data']['reload'], $what );
			$this->assertSame( array( 'id', 'modified', 'hash', 'title', 'reload' ), array_values( array_diff( array_keys( $events[0]['data'] ), array( 'changed' ) ) ), 'a reload notice carries no content' );
		}
		$this->assertArrayNotHasKey( 'reload', $content[0]['data'], 'a content edit is still applied inline' );
		$this->assertSame( array(), $other, 'and any other meta, with no refresh selector, is nothing' );
	}
}

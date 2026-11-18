<?php
/**
 * Shared fixture: fake credentials, intercepted HTTP, captured publishes, posts and live blogs deleted afterwards.
 */

use PHPUnit\Framework\TestCase;
use WPSignal\WPS;

use const WPSignal\Extensions\PostSocket\ENTRY_TYPE;
use const WPSignal\Extensions\PostSocket\FEED_BLOCK;
use const WPSignal\Extensions\PostSocket\LIVEBLOG_TAX;
use const WPSignal\Extensions\PostSocket\OPTION;

abstract class ExtensionTestCase extends TestCase {

	private const OPTIONS = array(
		'wpsignal_site_key',
		'wpsignal_site_secret',
		'wpsignal_jwt_secret',
		OPTION,
	);

	private const MISSING = '__missing__';

	/** @var array<string, mixed> */
	private array $snapshot = array();

	/** @var array<int, array{channel: string, event: string, data: array}> */
	protected array $published = array();

	/** @var callable|null */
	private $http_filter = null;

	/** @var int[] Posts to delete in tearDown. */
	protected array $posts = array();

	/** @var int[] Live blogs to delete in tearDown. */
	protected array $terms = array();

	/** Fake credentials, default settings, an administrator, and an HTTP interceptor that records every publish. */
	protected function setUp(): void {
		parent::setUp();
		foreach ( self::OPTIONS as $name ) {
			$this->snapshot[ $name ] = get_option( $name, self::MISSING );
		}
		update_option( 'wpsignal_site_key', 'phpunitkey' );
		update_option( 'wpsignal_site_secret', 'phpunitsecret' );
		update_option( 'wpsignal_jwt_secret', 'phpunitjwt' );
		self::drop_option( OPTION );
		wp_set_current_user( $this->admin_id() );
		$this->published   = array();
		$this->http_filter = function ( $pre, $args, $url ) {
			if ( ! str_ends_with( (string) $url, '/publish' ) ) {
				return $pre;
			}
			$body = json_decode( (string) $args['body'], true );
			if ( 'encrypted' === ( $body['event'] ?? '' ) ) {
				$raw   = base64_decode( (string) $body['data']['p'] );
				$inner = json_decode(
					(string) openssl_decrypt(
						substr( $raw, 12, -16 ),
						'aes-256-gcm',
						WPS::instance()->config()->encryption_key(),
						OPENSSL_RAW_DATA,
						substr( $raw, 0, 12 ),
						substr( $raw, -16 )
					),
					true
				);
				$body  = array(
					'channel' => $body['channel'],
					'event'   => $inner['event'],
					'data'    => $inner['data'],
				);
			}
			$this->published[] = array(
				'channel' => (string) $body['channel'],
				'event'   => (string) $body['event'],
				'data'    => (array) $body['data'],
			);
			return array(
				'response' => array(
					'code'    => 200,
					'message' => '',
				),
				'headers'  => array(),
				'body'     => '{"ok":true}',
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	/** Drop this test's posts and live blogs, the desk notices and reader updates they queued and the interceptor, then restore the options. */
	protected function tearDown(): void {
		foreach ( array_reverse( $this->posts ) as $id ) {
			if ( get_post( $id ) ) {
				wp_delete_post( $id, true );
			}
		}
		foreach ( $this->terms as $id ) {
			wp_delete_term( $id, LIVEBLOG_TAX );
		}
		\WPSignal\Extensions\PostSocket\desk_queue();
		\WPSignal\Extensions\PostSocket\living_queue();
		remove_filter( 'pre_http_request', $this->http_filter, 10 );
		foreach ( $this->snapshot as $name => $value ) {
			if ( self::MISSING === $value ) {
				self::drop_option( $name );
			} else {
				update_option( $name, $value );
			}
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** Delete an option and its cached copies: an object-cache drop-in with no backend leaves `delete_option()`'s value in the options cache. */
	protected static function drop_option( string $name ): void {
		delete_option( $name );
		$all = wp_cache_get( 'alloptions', 'options' );
		if ( is_array( $all ) && isset( $all[ $name ] ) ) {
			unset( $all[ $name ] );
			wp_cache_set( 'alloptions', $all, 'options' );
		}
		wp_cache_delete( $name, 'options' );
	}

	/** The first administrator's id. */
	protected function admin_id(): int {
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		return (int) ( $admins[0] ?? 1 );
	}

	/** Events published to a channel, oldest first. */
	protected function events_on( string $channel, ?string $event = null ): array {
		return array_values(
			array_filter(
				$this->published,
				static fn( $p ) => $p['channel'] === $channel && ( null === $event || $p['event'] === $event )
			)
		);
	}

	/** A live blog, named uniquely so reruns never meet a leftover term. */
	protected function make_liveblog( string $name = 'Match day' ): int {
		$term = wp_insert_term( $name . ' ' . wp_generate_password( 6, false ), LIVEBLOG_TAX );
		$this->assertIsArray( $term );
		$this->terms[] = (int) $term['term_id'];
		return (int) $term['term_id'];
	}

	/** The Live Feed block's markup for a live blog. */
	protected static function feed_block( int $liveblog ): string {
		return '<!-- wp:' . FEED_BLOCK . ' {"liveblog":' . $liveblog . '} /-->';
	}

	/** A published post with an intro paragraph and the Live Feed block for a live blog. */
	protected function make_host( int $liveblog, array $args = array() ): int {
		$id = wp_insert_post(
			array_merge(
				array(
					'post_type'    => 'post',
					'post_title'   => 'Match day',
					'post_content' => '<!-- wp:paragraph --><p>Kick-off at three.</p><!-- /wp:paragraph -->' . self::feed_block( $liveblog ),
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

	/** A published entry on a live blog. */
	protected function make_entry( int $liveblog, array $args = array() ): int {
		$id = wp_insert_post(
			array_merge(
				array(
					'post_type'    => ENTRY_TYPE,
					'post_title'   => 'Goal',
					'post_content' => '<!-- wp:paragraph --><p>One nil.</p><!-- /wp:paragraph -->',
					'post_status'  => 'publish',
					'post_author'  => $this->admin_id(),
				),
				$args
			),
			true
		);
		$this->assertIsInt( $id );
		$this->posts[] = $id;
		if ( $liveblog > 0 ) {
			wp_set_object_terms( $id, $liveblog, LIVEBLOG_TAX );
		}
		return $id;
	}

	/** Run a REST request in-process. */
	protected function rest( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	/** Make the next requests look like a front-end visit to this post. */
	protected function visit( int $post_id ): void {
		$this->go_to( get_permalink( $post_id ) );
	}

	/** Point the main query at a URL, as WordPress's own test suite does. */
	protected function go_to( string $url ): void {
		$parts = wp_parse_url( $url );
		$_GET  = array();
		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $_GET );
		}
		$_SERVER['REQUEST_URI'] = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		$_SERVER['SERVER_NAME'] = $parts['host'] ?? 'localhost';
		unset( $_SERVER['PATH_INFO'] );
		$public_vars                       = $GLOBALS['wp']->public_query_vars;
		$private_vars                      = $GLOBALS['wp']->private_query_vars;
		$GLOBALS['wp_the_query']           = new WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_query']               = $GLOBALS['wp_the_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp']                     = new WP(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp']->public_query_vars  = $public_vars;
		$GLOBALS['wp']->private_query_vars = $private_vars;
		$GLOBALS['wp']->main( $parts['query'] ?? '' );
	}
}

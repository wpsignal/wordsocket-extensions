<?php
/**
 * Plugin Name: PostSocket test blocks
 * Description: Fixtures for PostSocket's Playwright suite: a classic view script that starts on DOMContentLoaded with a localized dependency, re-run per insertion, an Interactivity API counter seeded by server state, two live fields of one post meta key printed in the footer, for a post flagged `postsocket_test_refresh`, a refresh selector, the note printed after its title and one live field, `postsocket_test_flag`, whose element is printed only while it has a value, and `comment_status` as a field readers reload the page for.
 * Version: 0.1.0
 *
 * @package WPSignal\Extensions\PostSocket
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	static function (): void {
		wp_register_script( 'postsocket-test-dep', plugins_url( 'dep.js', __FILE__ ), array(), '1', true );
		wp_localize_script( 'postsocket-test-dep', 'postsocketTestDep', array( 'label' => 'ready' ) );
		wp_register_script( 'postsocket-test-ready', plugins_url( 'ready.js', __FILE__ ), array( 'postsocket-test-dep' ), '1', true );
		register_block_type(
			'postsocket-test/ready',
			array(
				'view_script_handles' => array( 'postsocket-test-ready' ),
				'render_callback'     => static fn(): string => '<div class="postsocket-test-ready">waiting</div>',
			)
		);
		wp_register_script_module( 'postsocket-test-counter', plugins_url( 'counter.js', __FILE__ ), array( '@wordpress/interactivity' ), '1' );
		register_block_type(
			'postsocket-test/counter',
			array(
				'supports'               => array( 'interactivity' => true ),
				'view_script_module_ids' => array( 'postsocket-test-counter' ),
				'render_callback'        => static function (): string {
					wp_interactivity_state( 'postsocket-test', array( 'count' => 5 ) );
					return '<div class="postsocket-test-counter" data-wp-interactive="postsocket-test"><button type="button" data-wp-on--click="actions.add" data-wp-text="state.count">0</button></div>';
				},
			)
		);
	}
);

add_filter(
	'postsocket_rerun_blocks',
	static fn( array $blocks ): array => array_merge( $blocks, array( 'postsocket-test/ready' ) )
);

add_action(
	'init',
	static function (): void {
		register_post_meta(
			'post',
			'postsocket_test_refresh',
			array(
				'single'        => true,
				'type'          => 'boolean',
				'show_in_rest'  => true,
				'auth_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			)
		);
		register_post_meta(
			'post',
			'postsocket_test_flag',
			array(
				'single'        => true,
				'type'          => 'string',
				'show_in_rest'  => true,
				'auth_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			)
		);
		register_post_meta(
			'post',
			'postsocket_test_note',
			array(
				'single'        => true,
				'type'          => 'string',
				'show_in_rest'  => true,
				'auth_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			)
		);
	}
);

add_filter(
	'postsocket_live_fields',
	static function ( array $fields, WP_Post $post ): array {
		if ( get_post_meta( $post->ID, 'postsocket_test_refresh', true ) ) {
			$fields['postsocket_test_flag'] = array( 'selector' => '.postsocket-test-flag' );
			return $fields;
		}
		$fields['postsocket_test_note']  = array();
		$fields['postsocket_test_badge'] = array(
			'selector' => '.postsocket-test-badge',
			'meta'     => 'postsocket_test_note',
			'render'   => static fn( WP_Post $post ): string => '' !== (string) get_post_meta( $post->ID, 'postsocket_test_note', true ) ? '<strong>Noted</strong>' : '',
		);
		return $fields;
	},
	10,
	2
);

add_filter(
	'postsocket_reload_live_fields',
	static fn( array $names ): array => array_merge( $names, array( 'comment_status' ) )
);

add_filter(
	'postsocket_refresh_selector',
	static fn( string $selector, WP_Post $post ): string => get_post_meta( $post->ID, 'postsocket_test_refresh', true ) ? 'main' : $selector,
	10,
	2
);

add_filter(
	'render_block_core/post-title',
	static function ( string $html ): string {
		if ( ! is_singular( 'post' ) || ! get_post_meta( get_queried_object_id(), 'postsocket_test_refresh', true ) ) {
			return $html;
		}
		$flag = (string) get_post_meta( get_queried_object_id(), 'postsocket_test_flag', true );
		return $html . '<p class="postsocket-test-outside">' . esc_html( (string) get_post_meta( get_queried_object_id(), 'postsocket_test_note', true ) ) . '</p>' . ( '' !== $flag ? '<p class="postsocket-test-flag">' . esc_html( $flag ) . '</p>' : '' );
	}
);

add_action(
	'wp_footer',
	static function (): void {
		if ( ! is_singular( 'post' ) ) {
			return;
		}
		$note = (string) get_post_meta( get_queried_object_id(), 'postsocket_test_note', true );
		printf(
			'<p data-postsocket-field="postsocket_test_note">%s</p><p class="postsocket-test-badge">%s</p>',
			esc_html( $note ),
			'' !== $note ? '<strong>Noted</strong>' : ''
		);
	}
);

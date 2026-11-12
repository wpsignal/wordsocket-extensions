<?php
/**
 * Settings: defaults, sanitizing, the route and its permission.
 */

use const WPSignal\Extensions\PostSocket\DEFAULTS;
use const WPSignal\Extensions\PostSocket\ENTRY_TYPE;
use const WPSignal\Extensions\PostSocket\OPTION;
use const WPSignal\Extensions\PostSocket\REST_NS;

use function WPSignal\Extensions\PostSocket\living_types;
use function WPSignal\Extensions\PostSocket\sanitize_settings;
use function WPSignal\Extensions\PostSocket\settings;

final class SettingsTest extends ExtensionTestCase {

	public function test_defaults_and_sanitizing(): void {
		$this->assertSame( DEFAULTS, settings() );
		$this->assertSame( array(), living_types(), 'nothing is living until a type is enabled' );

		$clean = sanitize_settings(
			array(
				'living_types'     => array( 'post', 'post', ENTRY_TYPE, 'Weird Type!', '' ),
				'entries_per_page' => 500,
				'show_author'      => '0',
				'rewrite_slug'     => 'Breaking News',
				'unknown'          => true,
			)
		);
		$this->assertSame( array( 'post', 'weirdtype' ), $clean['living_types'], 'deduplicated, the entry type dropped, keys sanitized' );
		$this->assertSame( 100, $clean['entries_per_page'] );
		$this->assertFalse( $clean['show_author'] );
		$this->assertTrue( $clean['show_times'] );
		$this->assertArrayNotHasKey( 'rewrite_slug', $clean, 'there is no URL base any more' );
		$this->assertArrayNotHasKey( 'unknown', $clean );

		update_option( OPTION, array( 'living_types' => array( 'post', 'no_such_type' ) ) );
		$this->assertSame( array( 'post' ), living_types(), 'a type that does not exist is ignored' );
	}

	public function test_the_route_reads_and_saves_for_managers_only(): void {
		$answer = $this->rest( 'GET', '/' . REST_NS . '/settings' );
		$this->assertSame( 200, $answer->get_status() );
		$this->assertSame( DEFAULTS, $answer->get_data()['settings'] );
		$names = wp_list_pluck( $answer->get_data()['types'], 'name' );
		$this->assertContains( 'post', $names );
		$this->assertNotContains( ENTRY_TYPE, $names );
		$this->assertNotContains( 'attachment', $names );

		$saved = $this->rest( 'POST', '/' . REST_NS . '/settings', array( 'living_types' => array( 'post' ), 'entries_per_page' => 10 ) );
		$this->assertSame( array( 'post' ), $saved->get_data()['settings']['living_types'] );
		$this->assertSame( 10, $saved->get_data()['settings']['entries_per_page'] );
		$this->assertTrue( $saved->get_data()['settings']['show_author'], 'fields left out keep their value' );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( 'GET', '/' . REST_NS . '/settings' )->get_status() );
		$this->assertSame( 401, $this->rest( 'POST', '/' . REST_NS . '/settings', array( 'living_types' => array() ) )->get_status() );
	}
}

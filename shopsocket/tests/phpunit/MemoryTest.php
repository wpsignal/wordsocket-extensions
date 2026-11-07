<?php
/**
 * The plugin's memory: the cache flag and the facts kept behind it.
 */

use function WPSignal\Extensions\ShopSocket\forget;
use function WPSignal\Extensions\ShopSocket\has_persistent_cache;
use function WPSignal\Extensions\ShopSocket\plant_cache_probe;
use function WPSignal\Extensions\ShopSocket\recall;
use function WPSignal\Extensions\ShopSocket\remember;
use const WPSignal\Extensions\ShopSocket\MEMORY_GROUP;
use const WPSignal\Extensions\ShopSocket\MEMORY_VERDICT_OPTION;

final class MemoryTest extends ExtensionTestCase {
	protected function tearDown(): void {
		remove_all_filters( 'shopsocket_persistent_cache' );
		delete_option( MEMORY_VERDICT_OPTION );
		forget( 'memory_test' );
		parent::tearDown();
	}

	public function test_the_verdict_is_probed_once_and_then_kept(): void {
		delete_option( MEMORY_VERDICT_OPTION );
		$first = has_persistent_cache();
		$kept  = get_option( MEMORY_VERDICT_OPTION );
		$this->assertIsArray( $kept );
		$this->assertSame( $first, $kept['persists'] );
		$this->assertSame( $first, has_persistent_cache(), 'the same answer within its freshness' );
	}

	public function test_planting_the_marker_at_activation_or_when_history_turns_on_forgets_the_verdict_and_the_next_look_finds_it(): void {
		wp_cache_delete( 'cache_probe', 'wpsignal' );
		update_option( MEMORY_VERDICT_OPTION, array( 'persists' => false, 'at' => time() ), false );
		plant_cache_probe();
		$this->assertFalse( get_option( MEMORY_VERDICT_OPTION ), 'the verdict is forgotten' );
		$this->assertTrue( has_persistent_cache(), 'the marker planted ahead is there to find' );

		wp_cache_delete( 'cache_probe', 'wpsignal' );
		update_option( 'wpsignal_history_enabled', false );
		update_option( MEMORY_VERDICT_OPTION, array( 'persists' => false, 'at' => time() ), false );
		update_option( 'wpsignal_history_enabled', true );
		$this->assertFalse( get_option( MEMORY_VERDICT_OPTION ), 'History turning on plants and forgets' );
		$this->assertTrue( has_persistent_cache() );

		update_option( MEMORY_VERDICT_OPTION, array( 'persists' => false, 'at' => time() ), false );
		update_option( 'wpsignal_history_enabled', false );
		$this->assertIsArray( get_option( MEMORY_VERDICT_OPTION ), 'turning it off plants nothing' );
	}

	public function test_the_filter_forces_the_verdict_and_the_backend(): void {
		add_filter( 'shopsocket_persistent_cache', '__return_false' );
		$this->assertFalse( has_persistent_cache() );
		remember( 'memory_test', array( 'a' => 1 ), MINUTE_IN_SECONDS );
		$this->assertSame( array( 'a' => 1 ), recall( 'memory_test' ) );
		$this->assertSame( array( 'a' => 1 ), get_transient( 'shopsocket_memory_test' ), 'a transient without a cache' );
		$this->assertTrue( forget( 'memory_test' ) );
		$this->assertNull( recall( 'memory_test' ) );
		remove_all_filters( 'shopsocket_persistent_cache' );

		add_filter( 'shopsocket_persistent_cache', '__return_true' );
		remember( 'memory_test', 'cached', MINUTE_IN_SECONDS );
		$this->assertSame( 'cached', recall( 'memory_test' ) );
		$this->assertSame( 'cached', wp_cache_get( 'memory_test', MEMORY_GROUP ), 'the object cache with one' );
		$this->assertFalse( get_transient( 'shopsocket_memory_test' ), 'no row written' );
		remember( 'memory_test', null, MINUTE_IN_SECONDS );
		$this->assertNull( recall( 'memory_test' ), 'null forgets' );
	}
}

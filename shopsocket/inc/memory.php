<?php
/**
 * Short-lived facts, kept in the object cache where one outlives a request and in transients
 * where none does.
 *
 * - `has_persistent_cache()` is the flag: the probe's verdict, kept in an option for a day once
 *   true and ten minutes while false, since the probe needs two requests to see its own marker.
 * - `remember()`, `recall()` and `forget()` pick the backend by that flag.
 *
 * @package WPSignal\Extensions\ShopSocket
 */

namespace WPSignal\Extensions\ShopSocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The option the cache verdict lives in.
 *
 * @since 0.8.0
 */
const MEMORY_VERDICT_OPTION = 'shopsocket_cache_persists';

/**
 * The object cache group the facts live in.
 *
 * @since 0.8.0
 */
const MEMORY_GROUP = 'shopsocket_memory';

/**
 * Whether the object cache outlives a request, as last probed; the `shopsocket_persistent_cache` filter overrides it.
 *
 * @since 0.8.0
 *
 * @return bool
 */
function has_persistent_cache(): bool {
	$forced = apply_filters( 'shopsocket_persistent_cache', null );
	if ( is_bool( $forced ) ) {
		return $forced;
	}
	$verdict = get_option( MEMORY_VERDICT_OPTION );
	if ( is_array( $verdict ) && isset( $verdict['persists'], $verdict['at'] ) ) {
		$fresh = $verdict['persists'] ? DAY_IN_SECONDS : 10 * MINUTE_IN_SECONDS;
		if ( time() - (int) $verdict['at'] < $fresh ) {
			return (bool) $verdict['persists'];
		}
	}
	$persists = object_cache_persists();
	update_option(
		MEMORY_VERDICT_OPTION,
		array(
			'persists' => $persists,
			'at'       => time(),
		),
		false
	);
	return $persists;
}

/**
 * Keep a fact for `$ttl` seconds.
 *
 * @since 0.8.0
 *
 * @param string $key   The fact's key, `[a-z0-9_]` and at most 150 characters.
 * @param mixed  $value The fact; null forgets it.
 * @param int    $ttl   Seconds to keep it.
 * @return void
 */
function remember( string $key, $value, int $ttl ): void {
	if ( null === $value ) {
		forget( $key );
		return;
	}
	if ( has_persistent_cache() ) {
		wp_cache_set( $key, $value, MEMORY_GROUP, $ttl );
		return;
	}
	set_transient( 'shopsocket_' . $key, $value, $ttl );
}

/**
 * The fact kept under `$key`, null when none.
 *
 * @since 0.8.0
 *
 * @param string $key The fact's key.
 * @return mixed
 */
function recall( string $key ) {
	if ( has_persistent_cache() ) {
		$found = wp_cache_get( $key, MEMORY_GROUP );
		return false === $found ? null : $found;
	}
	$found = get_transient( 'shopsocket_' . $key );
	return false === $found ? null : $found;
}

/**
 * Forget the fact under `$key`; whether there was one.
 *
 * @since 0.8.0
 *
 * @param string $key The fact's key.
 * @return bool
 */
function forget( string $key ): bool {
	if ( has_persistent_cache() ) {
		return wp_cache_delete( $key, MEMORY_GROUP );
	}
	return delete_transient( 'shopsocket_' . $key );
}

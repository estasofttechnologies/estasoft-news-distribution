<?php
/**
 * Versioned output cache.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Uses the object cache when a persistent one exists (Redis, Memcached) and
 * transients otherwise. Flushing bumps a version number in the keys, so stale
 * entries are never read and simply expire on their own.
 */
final class Cache {

	public const GROUP = 'estasoft_nd';

	private const VERSION_OPTION = 'estasoft_nd_cache_version';

	/**
	 * Returns the cached value, or builds it and caches it.
	 *
	 * @param string   $key      Cache key, unique per output (for example "google_news:ar").
	 * @param int      $ttl      Lifetime in seconds.
	 * @param callable $producer Builds the value on a miss.
	 * @return mixed
	 */
	public function remember( string $key, int $ttl, callable $producer ) {
		$full_key = $this->full_key( $key );
		$hit      = $this->read( $full_key );

		if ( is_array( $hit ) && array_key_exists( 'value', $hit ) ) {
			return $hit['value'];
		}

		$value = $producer();
		$this->write( $full_key, array( 'value' => $value ), $ttl );

		return $value;
	}

	/**
	 * Makes every cached output stale.
	 *
	 * @return void
	 */
	public function flush(): void {
		update_option( self::VERSION_OPTION, $this->version() + 1, true );
	}

	/**
	 * Current cache version.
	 *
	 * @return int
	 */
	public function version(): int {
		return max( 1, (int) get_option( self::VERSION_OPTION, 1 ) );
	}

	private function full_key( string $key ): string {
		return 'v' . $this->version() . '_' . md5( $key );
	}

	/**
	 * @param string $full_key Versioned key.
	 * @return mixed False on a miss.
	 */
	private function read( string $full_key ) {
		if ( wp_using_ext_object_cache() ) {
			return wp_cache_get( $full_key, self::GROUP );
		}

		return get_transient( 'estasoft_nd_' . $full_key );
	}

	/**
	 * @param string $full_key Versioned key.
	 * @param mixed  $value    Value to store.
	 * @param int    $ttl      Lifetime in seconds.
	 * @return void
	 */
	private function write( string $full_key, $value, int $ttl ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $full_key, $value, self::GROUP, $ttl );
			return;
		}

		set_transient( 'estasoft_nd_' . $full_key, $value, $ttl );
	}
}

<?php
/**
 * WP Rocket.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations\CachePlugins;

defined( 'ABSPATH' ) || exit;

/**
 * Asks WP Rocket to drop pages by address with `rocket_clean_files( $urls )`.
 *
 * Unverified against the real plugin: the function name and its argument come
 * from WP Rocket's public documentation and were not run against WP Rocket.
 */
final class WpRocket extends AbstractCachePlugin {

	private const FUNCTION = 'rocket_clean_files';

	public function id(): string {
		return 'wp-rocket';
	}

	public function label(): string {
		return 'WP Rocket';
	}

	public function is_installed(): bool {
		return $this->has_function( self::FUNCTION );
	}

	public function asks(): string {
		return __( 'Single pages by address', 'estasoft-news-distribution' );
	}

	public function purge( array $urls, array $post_ids ): array {
		if ( ! $this->is_installed() ) {
			return $this->absent();
		}

		try {
			$this->call_function( self::FUNCTION, array_values( $urls ) );
		} catch ( \Throwable $error ) {
			return $this->threw( 0, $error );
		}

		return $this->done( count( $urls ) );
	}
}

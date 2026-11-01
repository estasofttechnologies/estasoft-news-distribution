<?php
/**
 * WP Super Cache.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations\CachePlugins;

defined( 'ABSPATH' ) || exit;

/**
 * Asks WP Super Cache to drop pages by address with `wpsc_delete_url_cache( $url )`.
 *
 * Unverified against the real plugin: the function name comes from WP Super
 * Cache's source as read in its public repository and was not run against WP
 * Super Cache.
 */
final class WpSuperCache extends AbstractCachePlugin {

	private const FUNCTION = 'wpsc_delete_url_cache';

	public function id(): string {
		return 'wp-super-cache';
	}

	public function label(): string {
		return 'WP Super Cache';
	}

	public function is_installed(): bool {
		return $this->has_function( self::FUNCTION );
	}

	public function asks(): string {
		return __( 'Single pages by address', 'estasoft-news-distribution' );
	}

	public function purge( array $urls, array $post_ids ): array {
		return $this->purge_each( self::FUNCTION, $urls );
	}
}

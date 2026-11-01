<?php
/**
 * W3 Total Cache.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations\CachePlugins;

defined( 'ABSPATH' ) || exit;

/**
 * Asks W3 Total Cache to drop pages by address with `w3tc_flush_url( $url )`.
 *
 * Unverified against the real plugin: the function name comes from W3 Total
 * Cache's public documentation and was not run against W3 Total Cache.
 */
final class W3TotalCache extends AbstractCachePlugin {

	private const FUNCTION = 'w3tc_flush_url';

	public function id(): string {
		return 'w3-total-cache';
	}

	public function label(): string {
		return 'W3 Total Cache';
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

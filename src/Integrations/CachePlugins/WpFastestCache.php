<?php
/**
 * WP Fastest Cache.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations\CachePlugins;

defined( 'ABSPATH' ) || exit;

/**
 * Asks WP Fastest Cache to drop one article's page with
 * `wpfc_clear_post_cache_by_id( $post_id )`. It can only drop a post, not an
 * address, so feeds and sitemaps are not part of what it is asked.
 *
 * Unverified against the real plugin: the function name comes from WP Fastest
 * Cache's public documentation and was not run against WP Fastest Cache.
 */
final class WpFastestCache extends AbstractCachePlugin {

	private const FUNCTION = 'wpfc_clear_post_cache_by_id';

	public function id(): string {
		return 'wp-fastest-cache';
	}

	public function label(): string {
		return 'WP Fastest Cache';
	}

	public function is_installed(): bool {
		return $this->has_function( self::FUNCTION );
	}

	public function asks(): string {
		return __( 'Single pages by article', 'estasoft-news-distribution' );
	}

	public function purge( array $urls, array $post_ids ): array {
		if ( ! $this->is_installed() ) {
			return $this->absent();
		}

		$ids = array_filter( array_unique( array_map( 'intval', $post_ids ) ) );

		if ( array() === $ids ) {
			return array(
				'ok'      => true,
				'asked'   => 0,
				'message' => __( 'It was not asked: it can only drop an article by its ID, and there was none.', 'estasoft-news-distribution' ),
			);
		}

		$asked = 0;

		try {
			foreach ( $ids as $post_id ) {
				$this->call_function( self::FUNCTION, $post_id );
				++$asked;
			}
		} catch ( \Throwable $error ) {
			return $this->threw( $asked, $error );
		}

		return $this->done( $asked );
	}
}

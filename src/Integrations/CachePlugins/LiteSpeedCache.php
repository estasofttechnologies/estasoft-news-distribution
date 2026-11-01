<?php
/**
 * LiteSpeed Cache.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations\CachePlugins;

defined( 'ABSPATH' ) || exit;

/**
 * Asks LiteSpeed Cache to drop pages by address with its action
 * `litespeed_purge_url`, which takes one address.
 *
 * Unverified against the real plugin: the action name comes from LiteSpeed's
 * public API documentation and was not run against LiteSpeed Cache. The plugin
 * counts as installed when something listens to that action.
 */
final class LiteSpeedCache extends AbstractCachePlugin {

	private const ACTION = 'litespeed_purge_url';

	public function id(): string {
		return 'litespeed-cache';
	}

	public function label(): string {
		return 'LiteSpeed Cache';
	}

	public function is_installed(): bool {
		return false !== has_action( self::ACTION );
	}

	public function asks(): string {
		return __( 'Single pages by address', 'estasoft-news-distribution' );
	}

	public function purge( array $urls, array $post_ids ): array {
		if ( ! $this->is_installed() ) {
			return $this->absent();
		}

		$asked = 0;

		try {
			foreach ( $urls as $url ) {
				do_action( self::ACTION, $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- LiteSpeed Cache's own action.
				++$asked;
			}
		} catch ( \Throwable $error ) {
			return $this->threw( $asked, $error );
		}

		return $this->done( $asked );
	}
}

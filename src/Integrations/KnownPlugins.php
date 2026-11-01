<?php
/**
 * The other plugins News Distribution knows about, and how to see that they are active.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * A plugin is found when it is active and its folder name matches, or when it
 * defines the constant it is known for. Only the plugin's presence is read: how it
 * is set up cannot be seen, so nothing here claims it.
 *
 * The folder names and constants are the ones the plugins use in their own
 * releases as the author knows them. They were not checked against each current
 * release in this build, so a plugin renamed by its authors would not be found.
 *
 * Kinds: `news` prints a news sitemap or feed, `seo` prints page tags and a sitemap,
 * `sitemap` prints sitemaps, `cache` caches pages, `language` supplies languages.
 */
final class KnownPlugins {

	/**
	 * Every known plugin.
	 *
	 * @return array<string, array{name: string, kind: string, folders: string[], constants: string[]}>
	 */
	public static function all(): array {
		return array(
			'gn-publisher'        => array(
				'name'      => 'GN Publisher',
				'kind'      => 'news',
				'folders'   => array( 'gn-publisher' ),
				'constants' => array(),
			),
			'yoast-news'          => array(
				'name'      => 'Yoast News SEO',
				'kind'      => 'news',
				'folders'   => array( 'wpseo-news' ),
				'constants' => array( 'WPSEO_NEWS_VERSION' ),
			),
			'yoast'               => array(
				'name'      => 'Yoast SEO',
				'kind'      => 'seo',
				'folders'   => array( 'wordpress-seo', 'wordpress-seo-premium' ),
				'constants' => array( 'WPSEO_VERSION' ),
			),
			'rank-math'           => array(
				'name'      => 'Rank Math SEO',
				'kind'      => 'seo',
				'folders'   => array( 'seo-by-rank-math', 'seo-by-rank-math-pro' ),
				'constants' => array( 'RANK_MATH_VERSION' ),
			),
			'aioseo'              => array(
				'name'      => 'All in One SEO',
				'kind'      => 'seo',
				'folders'   => array( 'all-in-one-seo-pack', 'all-in-one-seo-pack-pro' ),
				'constants' => array( 'AIOSEO_VERSION' ),
			),
			'google-xml-sitemaps' => array(
				'name'      => 'Google XML Sitemaps',
				'kind'      => 'sitemap',
				'folders'   => array( 'google-sitemap-generator' ),
				'constants' => array(),
			),
			'jetpack'             => array(
				'name'      => 'Jetpack',
				'kind'      => 'sitemap',
				'folders'   => array( 'jetpack' ),
				'constants' => array( 'JETPACK__VERSION' ),
			),
			'wp-rocket'           => array(
				'name'      => 'WP Rocket',
				'kind'      => 'cache',
				'folders'   => array( 'wp-rocket' ),
				'constants' => array( 'WP_ROCKET_VERSION' ),
			),
			'litespeed-cache'     => array(
				'name'      => 'LiteSpeed Cache',
				'kind'      => 'cache',
				'folders'   => array( 'litespeed-cache' ),
				'constants' => array( 'LSCWP_V' ),
			),
			'w3-total-cache'      => array(
				'name'      => 'W3 Total Cache',
				'kind'      => 'cache',
				'folders'   => array( 'w3-total-cache' ),
				'constants' => array( 'W3TC_VERSION' ),
			),
			'wp-super-cache'      => array(
				'name'      => 'WP Super Cache',
				'kind'      => 'cache',
				'folders'   => array( 'wp-super-cache' ),
				'constants' => array( 'WPCACHEHOME' ),
			),
			'wp-fastest-cache'    => array(
				'name'      => 'WP Fastest Cache',
				'kind'      => 'cache',
				'folders'   => array( 'wp-fastest-cache' ),
				'constants' => array(),
			),
			'cache-enabler'       => array(
				'name'      => 'Cache Enabler',
				'kind'      => 'cache',
				'folders'   => array( 'cache-enabler' ),
				'constants' => array(),
			),
			'breeze'              => array(
				'name'      => 'Breeze',
				'kind'      => 'cache',
				'folders'   => array( 'breeze' ),
				'constants' => array( 'BREEZE_VERSION' ),
			),
			'nitropack'           => array(
				'name'      => 'NitroPack',
				'kind'      => 'cache',
				'folders'   => array( 'nitropack' ),
				'constants' => array( 'NITROPACK_VERSION' ),
			),
			'polylang'            => array(
				'name'      => 'Polylang',
				'kind'      => 'language',
				'folders'   => array( 'polylang', 'polylang-pro' ),
				'constants' => array( 'POLYLANG_VERSION' ),
			),
			'wpml'                => array(
				'name'      => 'WPML',
				'kind'      => 'language',
				'folders'   => array( 'sitepress-multilingual-cms' ),
				'constants' => array( 'ICL_SITEPRESS_VERSION' ),
			),
		);
	}

	/**
	 * The known plugins found on this site.
	 *
	 * @return array<string, array{id: string, name: string, kind: string, version: string, file: string, found_by: string}>
	 */
	public static function found(): array {
		$active = self::active_files();
		$found  = array();

		foreach ( self::all() as $id => $known ) {
			$match = self::match( $known, $active );

			if ( null === $match ) {
				continue;
			}

			$found[ $id ] = array(
				'id'       => $id,
				'name'     => $known['name'],
				'kind'     => $known['kind'],
				'version'  => self::version_of( $match['file'], $known['constants'] ),
				'file'     => $match['file'],
				'found_by' => $match['by'],
			);
		}

		return $found;
	}

	/**
	 * One known plugin, when it is found.
	 *
	 * @param string $id Plugin id such as gn-publisher.
	 * @return array<string, string>|null
	 */
	public static function find( string $id ): ?array {
		$all = self::found();

		return $all[ $id ] ?? null;
	}

	/**
	 * The active plugin files, such as "wordpress-seo/wp-seo.php".
	 *
	 * @return string[]
	 */
	private static function active_files(): array {
		$files = array_map( 'strval', (array) get_option( 'active_plugins', array() ) );

		if ( is_multisite() ) {
			$files = array_merge( $files, array_map( 'strval', array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) );
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Checks one known plugin against the active plugins and the defined constants.
	 *
	 * @param array<string, mixed> $known  The known plugin.
	 * @param string[]             $active Active plugin files.
	 * @return array{file: string, by: string}|null
	 */
	private static function match( array $known, array $active ): ?array {
		foreach ( $active as $file ) {
			$folder = false === strpos( $file, '/' ) ? '' : (string) strtok( $file, '/' );

			if ( '' !== $folder && in_array( $folder, $known['folders'], true ) ) {
				return array(
					'file' => $file,
					'by'   => 'folder',
				);
			}
		}

		foreach ( $known['constants'] as $constant ) {
			if ( defined( $constant ) ) {
				return array(
					'file' => '',
					'by'   => 'constant',
				);
			}
		}

		return null;
	}

	/**
	 * The version of a plugin, or an empty string.
	 *
	 * @param string   $file      The plugin file, or an empty string.
	 * @param string[] $constants Constants that hold its version.
	 * @return string
	 */
	private static function version_of( string $file, array $constants ): string {
		foreach ( $constants as $constant ) {
			if ( defined( $constant ) && is_scalar( constant( $constant ) ) && preg_match( '/^\d+(\.\d+)*/', (string) constant( $constant ) ) ) {
				return (string) constant( $constant );
			}
		}

		if ( '' === $file ) {
			return '';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();

		return isset( $plugins[ $file ]['Version'] ) ? (string) $plugins[ $file ]['Version'] : '';
	}
}

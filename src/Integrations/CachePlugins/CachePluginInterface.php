<?php
/**
 * A caching plugin News Distribution can ask to drop stored pages.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations\CachePlugins;

defined( 'ABSPATH' ) || exit;

/**
 * One class per caching plugin, in this folder. It is found by folder and used
 * by `Core\CachePurge`. An adapter only calls the plugin's own purge function
 * when that function exists, so a site without the plugin is never touched.
 *
 * The function names are written down from the plugins' public documentation
 * and are not confirmed against the real plugins (see
 * docs/external-services/cache-plugins.md). Every adapter says so in its own
 * class comment.
 */
interface CachePluginInterface {

	/**
	 * Short id, such as "wp-rocket".
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * The plugin's name as its authors write it.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Whether the plugin's purge function is available on this site.
	 *
	 * @return bool
	 */
	public function is_installed(): bool;

	/**
	 * What the plugin is asked, in words: "Single pages by address".
	 *
	 * @return string
	 */
	public function asks(): string;

	/**
	 * Asks the plugin to drop its stored copies.
	 *
	 * @param string[] $urls     Addresses to drop.
	 * @param int[]    $post_ids Articles the addresses belong to, for a plugin that purges by post.
	 * @return array{ok: bool, asked: int, message: string} How many it was asked about, and what it answered.
	 */
	public function purge( array $urls, array $post_ids ): array;
}

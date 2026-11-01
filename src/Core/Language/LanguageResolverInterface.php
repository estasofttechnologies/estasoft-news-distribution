<?php
/**
 * Language lookups, independent of the translation plugin in use.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Every output is per language, so every part of the plugin asks this interface.
 */
interface LanguageResolverInterface {

	/**
	 * Short name of the integration, such as "polylang".
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * Whether the underlying translation plugin is active.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Language codes the site publishes in.
	 *
	 * @return string[]
	 */
	public function languages(): array;

	/**
	 * The site's default language code.
	 *
	 * @return string
	 */
	public function default_language(): string;

	/**
	 * Language code of one post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public function language_of_post( int $post_id ): string;

	/**
	 * Extra WP_Query arguments that limit a query to one language.
	 *
	 * @param string $language Language code.
	 * @return array<string, mixed>
	 */
	public function query_args( string $language ): array;

	/**
	 * Runs a callback with the given language active, then restores the previous one.
	 *
	 * @param string   $language Language code.
	 * @param callable $callback Work to run.
	 * @return mixed Whatever the callback returns.
	 */
	public function with_language( string $language, callable $callback );
}

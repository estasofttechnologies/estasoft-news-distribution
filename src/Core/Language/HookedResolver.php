<?php
/**
 * Lets other code replace how an article's language is found.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Language;

use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the detected resolver and runs the `estasoft_nd_language_resolver`
 * filter for each article. Everything else passes straight through.
 */
final class HookedResolver implements LanguageResolverInterface {

	private LanguageResolverInterface $inner;

	/**
	 * Creates the wrapper.
	 *
	 * @param LanguageResolverInterface $inner The detected resolver.
	 */
	public function __construct( LanguageResolverInterface $inner ) {
		$this->inner = $inner;
	}

	public function name(): string {
		return $this->inner->name();
	}

	public function is_available(): bool {
		return $this->inner->is_available();
	}

	public function languages(): array {
		$languages = $this->inner->languages();

		/**
		 * Lets demo mode add the languages of its sample articles to a site that has no translation plugin (internal).
		 *
		 * @param string[] $languages The language codes the site has.
		 */
		$filtered = apply_filters( 'estasoft_nd_internal_site_languages', $languages );

		return is_array( $filtered ) ? array_values( array_map( 'strval', $filtered ) ) : $languages;
	}

	public function default_language(): string {
		// The owner can choose the default in Settings, Languages. A language the site does not have is ignored.
		$chosen = (string) Plugin::instance()->settings()->get( 'languages.default', '' );

		if ( '' !== $chosen && in_array( $chosen, array_map( 'strval', $this->inner->languages() ), true ) ) {
			return $chosen;
		}

		return $this->inner->default_language();
	}

	public function language_of_post( int $post_id ): string {
		$language = $this->inner->language_of_post( $post_id );

		/**
		 * Filters the language code of one article.
		 *
		 * Return a language code to replace how the article's language is found,
		 * or null to keep the detected one.
		 *
		 * @param string $language The detected language code.
		 * @param int    $post_id  Article ID.
		 */
		$filtered = apply_filters( 'estasoft_nd_language_resolver', $language, $post_id );

		return is_string( $filtered ) && '' !== $filtered ? $filtered : $language;
	}

	public function query_args( string $language ): array {
		$args = $this->inner->query_args( $language );

		/**
		 * Lets demo mode tell its sample articles apart by language when the site has no translation plugin (internal).
		 *
		 * @param array<string, mixed> $args     Query arguments that pick one language.
		 * @param string               $language The language code.
		 */
		$filtered = apply_filters( 'estasoft_nd_internal_language_query_args', $args, $language );

		return is_array( $filtered ) ? $filtered : $args;
	}

	public function with_language( string $language, callable $callback ) {
		return $this->inner->with_language( $language, $callback );
	}
}

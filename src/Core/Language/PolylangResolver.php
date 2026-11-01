<?php
/**
 * Polylang integration.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Reads languages from Polylang's public API.
 */
final class PolylangResolver implements LanguageResolverInterface {

	public function name(): string {
		return 'polylang';
	}

	public function is_available(): bool {
		return function_exists( 'pll_languages_list' ) && function_exists( 'pll_get_post_language' );
	}

	public function languages(): array {
		$list = pll_languages_list();
		return is_array( $list ) ? array_values( array_map( 'strval', $list ) ) : array();
	}

	public function default_language(): string {
		$default = function_exists( 'pll_default_language' ) ? pll_default_language() : '';
		return $default ? (string) $default : SingleLanguageResolver::code_from_locale( (string) get_locale() );
	}

	public function language_of_post( int $post_id ): string {
		$language = pll_get_post_language( $post_id );
		return $language ? (string) $language : $this->default_language();
	}

	public function query_args( string $language ): array {
		return array( 'lang' => $language );
	}

	public function with_language( string $language, callable $callback ) {
		return $callback();
	}
}

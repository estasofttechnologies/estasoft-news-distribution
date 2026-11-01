<?php
/**
 * Fallback for sites without a translation plugin.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Treats the whole site as one language, taken from the site locale.
 */
final class SingleLanguageResolver implements LanguageResolverInterface {

	public function name(): string {
		return 'none';
	}

	public function is_available(): bool {
		return true;
	}

	public function languages(): array {
		return array( $this->default_language() );
	}

	public function default_language(): string {
		return self::code_from_locale( (string) get_locale() );
	}

	public function language_of_post( int $post_id ): string {
		return $this->default_language();
	}

	public function query_args( string $language ): array {
		return array();
	}

	public function with_language( string $language, callable $callback ) {
		return $callback();
	}

	/**
	 * Turns a WordPress locale such as "ar" or "en_US" into a language code.
	 *
	 * Chinese keeps its region because Google News tells zh-cn and zh-tw apart.
	 *
	 * @param string $locale WordPress locale.
	 * @return string
	 */
	public static function code_from_locale( string $locale ): string {
		$code = strtolower( str_replace( '_', '-', trim( $locale ) ) );

		if ( 0 === strpos( $code, 'zh' ) ) {
			return $code;
		}

		$parts = explode( '-', $code );
		return '' !== $parts[0] ? $parts[0] : 'en';
	}
}

<?php
/**
 * WPML integration.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Language;

defined( 'ABSPATH' ) || exit;

// These are WPML's own hooks, so they cannot carry this plugin's prefix.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Reads languages through WPML's documented hooks.
 */
final class WpmlResolver implements LanguageResolverInterface {

	public function name(): string {
		return 'wpml';
	}

	public function is_available(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	public function languages(): array {
		$active = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		return is_array( $active ) ? array_map( 'strval', array_keys( $active ) ) : array();
	}

	public function default_language(): string {
		$default = apply_filters( 'wpml_default_language', null );
		return $default ? (string) $default : SingleLanguageResolver::code_from_locale( (string) get_locale() );
	}

	public function language_of_post( int $post_id ): string {
		$details = apply_filters( 'wpml_post_language_details', null, $post_id );

		if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
			return (string) $details['language_code'];
		}

		return $this->default_language();
	}

	public function query_args( string $language ): array {
		// WPML filters queries by the current language, so its filters must stay on.
		return array( 'suppress_filters' => false );
	}

	public function with_language( string $language, callable $callback ) {
		$original = apply_filters( 'wpml_current_language', null );
		do_action( 'wpml_switch_language', $language );

		try {
			return $callback();
		} finally {
			if ( null !== $original ) {
				do_action( 'wpml_switch_language', $original );
			}
		}
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

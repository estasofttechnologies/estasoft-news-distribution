<?php
/**
 * Picks the language resolver that matches the site.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Detects Polylang, then WPML, then falls back to a single language.
 */
final class LanguageResolverFactory {

	/**
	 * @return LanguageResolverInterface
	 */
	public static function detect(): LanguageResolverInterface {
		$resolver = new PolylangResolver();

		if ( ! $resolver->is_available() ) {
			$resolver = new WpmlResolver();
		}

		if ( ! $resolver->is_available() ) {
			$resolver = new SingleLanguageResolver();
		}

		return new HookedResolver( $resolver );
	}
}

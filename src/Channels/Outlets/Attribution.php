<?php
/**
 * The line that credits the original article.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Outlets;

use Estasoft\NewsDistribution\Core\Language\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * "Originally published at {site}." in the article's own language. `{site}`
 * becomes the publication's name and links to the original article. A line
 * with no `{site}` is used as written and carries no link.
 *
 * The default lines are content in each article's language, not interface
 * text, so they are not run through gettext: a Kurmanji article gets a
 * Kurmanji line whatever language the administrator reads. The Kurmanji,
 * Arabic and Hebrew lines are drafts for a native reviewer.
 */
final class Attribution {

	public const TOKEN = '{site}';

	/**
	 * The default line of each language, by primary language subtag.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return array(
			'en' => 'Originally published at {site}.',
			'ku' => 'Di eslê xwe de li {site} hatiye weşandin.',
			'ar' => 'نُشر في الأصل على {site}.',
			'es' => 'Publicado originalmente en {site}.',
			'fr' => 'Publié à l’origine sur {site}.',
			'nl' => 'Oorspronkelijk gepubliceerd op {site}.',
			'he' => 'פורסם במקור ב-{site}.',
		);
	}

	/**
	 * The default line for a language. A language with no draft line gets the English one.
	 *
	 * @param string $language Language code.
	 * @return string
	 */
	public static function default_line( string $language ): string {
		$defaults = self::defaults();

		return $defaults[ Catalog::primary( $language ) ] ?? $defaults['en'];
	}

	/**
	 * The line a language uses now: the one the owner saved (an empty one means no line), else the default.
	 *
	 * @param string               $language Language code.
	 * @param array<string,string> $saved    Saved lines by language code.
	 * @return string
	 */
	public static function line( string $language, array $saved ): string {
		if ( array_key_exists( $language, $saved ) ) {
			return trim( (string) $saved[ $language ] );
		}

		return self::default_line( $language );
	}

	/**
	 * Whether a line has the `{site}` placeholder, so it can link to the original.
	 *
	 * @param string $line The line.
	 * @return bool
	 */
	public static function has_token( string $line ): bool {
		return false !== strpos( $line, self::TOKEN );
	}

	/**
	 * The line as plain text: `{site}` becomes the publication's name.
	 *
	 * @param string $line The line.
	 * @param string $site Publication name.
	 * @return string
	 */
	public static function text( string $line, string $site ): string {
		return str_replace( self::TOKEN, $site, $line );
	}

	/**
	 * The line as escaped HTML: `{site}` becomes the publication's name as a link to the original.
	 *
	 * @param string $line The line.
	 * @param string $site Publication name.
	 * @param string $url  Address of the original article.
	 * @return string
	 */
	public static function html( string $line, string $site, string $url ): string {
		$parts  = explode( self::TOKEN, $line );
		$link   = '<a href="' . esc_url( $url ) . '">' . esc_html( $site ) . '</a>';
		$output = array();

		foreach ( $parts as $part ) {
			$output[] = esc_html( $part );
		}

		return implode( '' === $url ? esc_html( $site ) : $link, $output );
	}

	/**
	 * How many of the languages have a line that links to the original.
	 *
	 * @param string[]             $languages Language codes in use.
	 * @param array<string,string> $saved     Saved lines by language code.
	 * @return array{linked: int, total: int}
	 */
	public static function counts( array $languages, array $saved ): array {
		$linked = 0;

		foreach ( $languages as $language ) {
			if ( self::has_token( self::line( (string) $language, $saved ) ) ) {
				++$linked;
			}
		}

		return array(
			'linked' => $linked,
			'total'  => count( $languages ),
		);
	}

	/**
	 * A sentence that says how many languages credit the original with a link.
	 *
	 * @param int $linked Languages whose line links to the original.
	 * @param int $total  Languages in use.
	 * @return string
	 */
	public static function summary( int $linked, int $total ): string {
		return sprintf(
			/* translators: 1: how many languages have an attribution line with a link to the original article, 2: how many languages the site publishes in. */
			_n(
				'%1$d of %2$d language credits the original article with a link.',
				'%1$d of %2$d languages credit the original article with a link.',
				$total,
				'estasoft-news-distribution'
			),
			$linked,
			$total
		);
	}
}

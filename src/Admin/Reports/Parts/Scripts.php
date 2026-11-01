<?php
/**
 * Which letters a text is written in.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports\Parts;

defined( 'ABSPATH' ) || exit;

/**
 * Kurmanji, the Kurdish this plugin supports, is written in Latin letters. Sorani is
 * written in Arabic letters and is not supported (spec, languages). The Translations
 * and Hreflang reports use this to tell a Kurdish article whose text is in Arabic
 * letters, which is most likely Sorani or a copy of the Arabic version. It reads
 * letters only and never guesses the language.
 */
final class Scripts {

	/**
	 * The share of letters in a text that are Arabic letters, from 0 to 1.
	 *
	 * @param string $text Plain text.
	 * @return float Zero when the text has no letters.
	 */
	public static function arabic_share( string $text ): float {
		$letters = (int) preg_match_all( '/\p{L}/u', $text );

		if ( $letters < 1 ) {
			return 0.0;
		}

		return (int) preg_match_all( '/\p{Arabic}/u', $text ) / $letters;
	}

	/**
	 * Whether a text is written mostly in Arabic letters.
	 *
	 * @param string $text Plain text.
	 * @return bool
	 */
	public static function is_arabic( string $text ): bool {
		return self::arabic_share( $text ) > 0.5;
	}

	/**
	 * Whether an article whose language is Kurdish (Kurmanji) is written in Arabic letters.
	 *
	 * It reads the title and the first part of the body, each on its own: either one in Arabic letters counts.
	 *
	 * @param string $language Language code of the article.
	 * @param string $title    Title.
	 * @param string $content  Post content.
	 * @return bool False for every other language.
	 */
	public static function kurdish_in_arabic_letters( string $language, string $title, string $content ): bool {
		if ( 'ku' !== strtolower( (string) preg_replace( '/[-_].*$/', '', $language ) ) ) {
			return false;
		}

		return self::is_arabic( $title ) || self::is_arabic( mb_substr( wp_strip_all_tags( strip_shortcodes( $content ) ), 0, 600 ) );
	}
}

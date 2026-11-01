<?php
/**
 * Small clean-ups for post HTML before it goes into a feed.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers shared by the channels that print article HTML.
 */
final class HtmlClean {

	/**
	 * Removes paragraphs that hold nothing but spaces, non-breaking spaces or line breaks.
	 *
	 * An empty paragraph block (`<p></p>`) is common in articles. Left in, `wpautop()` turns
	 * it into a stray closing tag, which makes the feed's HTML unbalanced.
	 *
	 * @param string $html Article HTML.
	 * @return string
	 */
	public static function drop_empty_paragraphs( string $html ): string {
		$clean = preg_replace( '#<p(?:\s[^>]*)?>(?:\s|&nbsp;|&\#160;|\x{00A0}|<br\s*/?>)*</p>#iu', '', $html );

		return null === $clean ? $html : $clean;
	}
}

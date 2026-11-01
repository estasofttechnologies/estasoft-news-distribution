<?php
/**
 * The search description a person chose for an article, as a tag in the head of its page.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Markup;

use Estasoft\NewsDistribution\Ai\Features\Summary;

defined( 'ABSPATH' ) || exit;

/**
 * The AI assistant can suggest a description of about 155 characters. A person chooses one in the Summary tab of the
 * Article check, and it is stored in the post meta `_estasoft_nd_meta_description`. This class turns it into the one tag
 * `<meta name="description">`.
 *
 * It only adds. `MarkupModule` reads the head as the theme and the other plugins printed it, and asks `present()`
 * whether a description is already there. When one is, nothing is printed: a description that an SEO plugin, the theme
 * or a person wrote always wins, and this class never edits another plugin's settings or output. An article for which
 * nobody chose a description gets no tag.
 *
 * The tag carries the attribute `data-estasoft-nd`, like the other tags News Distribution prints.
 */
final class Description {

	/**
	 * Whether a person chose a description for the article.
	 *
	 * @param int $post_id The article.
	 * @return bool
	 */
	public static function wanted( int $post_id ): bool {
		return $post_id > 0 && '' !== Summary::meta_description( $post_id );
	}

	/**
	 * Whether the head already has a description with something in it.
	 *
	 * @param string $head The head as printed, or the whole page.
	 * @return bool
	 */
	public static function present( string $head ): bool {
		$end = stripos( $head, '</head>' );

		if ( false !== $end ) {
			$head = substr( $head, 0, $end );
		}

		$processor = new \WP_HTML_Tag_Processor( $head );

		while ( $processor->next_tag( 'meta' ) ) {
			$name    = $processor->get_attribute( 'name' );
			$content = $processor->get_attribute( 'content' );

			if ( is_string( $name ) && 'description' === strtolower( trim( $name ) ) && is_string( $content ) && '' !== trim( $content ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The tag for an article.
	 *
	 * @param int $post_id The article.
	 * @return string HTML that is safe to print; empty when no description was chosen.
	 */
	public static function html( int $post_id ): string {
		$text = Summary::meta_description( $post_id );

		if ( '' === $text ) {
			return '';
		}

		return '<meta name="description" content="' . esc_attr( $text ) . '" ' . PageTags::MARKER . " />\n";
	}
}

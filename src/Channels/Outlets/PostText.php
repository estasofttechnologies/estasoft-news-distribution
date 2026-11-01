<?php
/**
 * The pieces of a post, and the counting each service uses.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Outlets;

use Estasoft\NewsDistribution\Core\Article;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the headline, the summary and the hashtags out of an article, and
 * measures and shortens text the way the services count it. Nothing here
 * contacts a service.
 */
final class PostText {

	/**
	 * The headline as plain text.
	 *
	 * @param Article $article The article.
	 * @return string
	 */
	public static function headline( Article $article ): string {
		return self::plain( $article->title() );
	}

	/**
	 * The short summary: the one a person chose for platforms, else the article's excerpt, or its first two sentences when it has none.
	 *
	 * @param Article $article The article.
	 * @return string
	 */
	public static function summary( Article $article ): string {
		if ( '' !== $article->summary() ) {
			return self::plain( $article->summary() );
		}

		$post    = get_post( $article->id() );
		$excerpt = $post instanceof \WP_Post ? self::plain( (string) $post->post_excerpt ) : '';

		if ( '' !== $excerpt ) {
			return $excerpt;
		}

		return self::sentences( self::plain( strip_shortcodes( $article->content() ) ), 2 );
	}

	/**
	 * Plain text from markup: no tags, entities decoded, runs of white space made one space.
	 *
	 * @param string $text Text that may hold markup.
	 * @return string
	 */
	public static function plain( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( null === $text ? '' : $text );
	}

	/**
	 * The first sentences of a text. A sentence ends at a full stop, an exclamation mark or a
	 * question mark, in Latin, Arabic or CJK form, followed by white space.
	 *
	 * @param string $text  Plain text.
	 * @param int    $count How many sentences.
	 * @return string
	 */
	public static function sentences( string $text, int $count ): string {
		$parts = preg_split( '/(?<=[.!?\x{061F}\x{06D4}\x{3002}])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $parts ) ) {
			return $text;
		}

		return trim( implode( ' ', array_slice( $parts, 0, max( 1, $count ) ) ) );
	}

	/**
	 * Hashtags from the article's tags, in order, as many as fit.
	 *
	 * A tag becomes one word with each word capitalised ("local news" becomes #LocalNews). A tag
	 * with no letters, or only digits, is left out, because a service would not treat it as a hashtag.
	 *
	 * @param Article  $article The article.
	 * @param int      $room    How many more units of text the post may hold.
	 * @param callable $measure Takes a string and returns its length in the service's units.
	 * @return string[] Hashtags with the leading #.
	 */
	public static function hashtags( Article $article, int $room, callable $measure ): array {
		$found = array();

		foreach ( $article->tags() as $tag ) {
			$words = preg_split( '/\s+/u', self::plain( $tag ), -1, PREG_SPLIT_NO_EMPTY );
			$word  = '';

			foreach ( is_array( $words ) ? $words : array() as $part ) {
				$part  = (string) preg_replace( '/[^\p{L}\p{N}\p{M}_]/u', '', $part );
				$word .= mb_strtoupper( mb_substr( $part, 0, 1 ) ) . mb_substr( $part, 1 );
			}

			if ( '' === $word || 1 === preg_match( '/^\p{N}+$/u', $word ) ) {
				continue;
			}

			$tag_text = '#' . $word;
			$cost     = (int) call_user_func( $measure, ' ' . $tag_text );

			if ( $cost > $room ) {
				continue;
			}

			$room                               -= $cost;
			$found[ mb_strtolower( $tag_text ) ] = $tag_text;
		}

		return array_values( $found );
	}

	/**
	 * What a reader sees as one character: a letter with its marks, an emoji with its joiners.
	 *
	 * Where the intl extension is loaded this is exact. Without it the count is a safe one: it never
	 * counts fewer than a reader sees, so a post is never longer than the service allows. A joined
	 * emoji then counts as the emoji it is made of.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function graphemes( string $text ): int {
		if ( function_exists( 'grapheme_strlen' ) ) {
			$count = grapheme_strlen( $text );

			if ( is_int( $count ) ) {
				return $count;
			}
		}

		$count = preg_match_all( '/[^\p{M}\x{200D}\x{FE0E}\x{FE0F}]/u', $text );

		return false === $count ? mb_strlen( $text ) : $count;
	}

	/**
	 * The text split into what a reader sees as one character each, so a cut never lands inside one.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function clusters( string $text ): array {
		$pieces = array();

		if ( function_exists( 'grapheme_extract' ) ) {
			$length = strlen( $text );
			$at     = 0;

			while ( $at < $length ) {
				$next  = $at;
				$piece = grapheme_extract( $text, 1, GRAPHEME_EXTR_COUNT, $at, $next );

				if ( ! is_string( $piece ) || '' === $piece || $next <= $at ) {
					$pieces = array();
					break;
				}

				$pieces[] = $piece;
				$at       = $next;
			}

			if ( array() !== $pieces || '' === $text ) {
				return $pieces;
			}
		}

		// Without intl: a character with the marks and joiners that follow it.
		preg_match_all( '/\X/u', $text, $matches );

		return is_array( $matches[0] ?? null ) ? $matches[0] : array();
	}

	/**
	 * Length in UTF-16 code units, the unit Telegram's own apps count in. It is never smaller than
	 * the number of characters, so using it keeps a message on the safe side of the limit.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function utf16( string $text ): int {
		return (int) ( strlen( mb_convert_encoding( $text, 'UTF-16LE', 'UTF-8' ) ) / 2 );
	}

	/**
	 * Shortens a text to fit a budget, ending with an ellipsis. It cuts between words when it can
	 * and never inside a character a reader sees as one.
	 *
	 * @param string   $text    Text.
	 * @param int      $budget  The most units it may take.
	 * @param callable $measure Takes a string and returns its length in the service's units.
	 * @return string The text unchanged when it fits, an empty string when not even the ellipsis fits.
	 */
	public static function shorten( string $text, int $budget, callable $measure ): string {
		if ( (int) call_user_func( $measure, $text ) <= $budget ) {
			return $text;
		}

		$ellipsis = '…';

		if ( (int) call_user_func( $measure, $ellipsis ) > $budget ) {
			return '';
		}

		$clusters = self::clusters( $text );
		$low      = 0;
		$high     = count( $clusters );

		while ( $low < $high ) {
			$mid = intdiv( $low + $high + 1, 2 );
			$cut = implode( '', array_slice( $clusters, 0, $mid ) ) . $ellipsis;

			if ( (int) call_user_func( $measure, $cut ) <= $budget ) {
				$low = $mid;
			} else {
				$high = $mid - 1;
			}
		}

		$kept  = implode( '', array_slice( $clusters, 0, $low ) );
		$space = strrpos( $kept, ' ' );

		if ( false !== $space && mb_strlen( substr( $kept, 0, $space ) ) >= (int) ( mb_strlen( $kept ) * 0.6 ) ) {
			$kept = substr( $kept, 0, $space );
		}

		$kept = rtrim( $kept, " \t\n\r,;:-–—" );

		return '' === $kept ? '' : $kept . $ellipsis;
	}
}

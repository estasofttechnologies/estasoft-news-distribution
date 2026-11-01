<?php
/**
 * The body: present, long enough, and free of leftovers.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Checks;

use Estasoft\NewsDistribution\Core\Article;

defined( 'ABSPATH' ) || exit;

/**
 * Codes: `body.empty`, `body.short`, `body.shortcode_left_as_text`, `body.broken_markup`.
 *
 * It reads the saved text only. It never renders the article, so it cannot see what
 * a shortcode would print: a shortcode is reported only when no active plugin handles
 * it, which is when it shows as text in a feed. Work is bounded: a body over
 * 300,000 characters is not scanned for markup.
 */
final class BodyCheck extends AbstractCheck {

	private const MAX_SCAN = 300000;

	/**
	 * Tags that must be closed for the text to be well formed. `p` and `li` are left out:
	 * browsers and feed readers accept them unclosed.
	 *
	 * @var string[]
	 */
	private const PAIRED = array( 'a', 'b', 'blockquote', 'div', 'em', 'figcaption', 'figure', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'i', 'ol', 'span', 'strong', 'table', 'tbody', 'td', 'th', 'tr', 'ul' );

	/**
	 * @return string
	 */
	public function id(): string {
		return 'body';
	}

	/**
	 * @return int
	 */
	public function order(): int {
		return 60;
	}

	/**
	 * @param Article $article The article.
	 * @return array<int, array{code: string, severity: string, message: string, channels: string[], fix: string}>
	 */
	public function run( Article $article ): array {
		$content = $article->content();
		$words   = $article->word_count();

		if ( 0 === $words && ! $this->has_media( $content ) ) {
			return $this->emit(
				$article,
				'body.empty',
				static function (): string {
					return __( 'The article has no body text.', 'estasoft-news-distribution' );
				}
			);
		}

		$findings = $this->emit(
			$article,
			'body.short',
			function ( string $platform ) use ( $words ) {
				$min = $this->limit( $platform, 'body_min_words' );

				if ( $min < 1 || $words >= $min ) {
					return null;
				}

				return array(
					sprintf(
						/* translators: 1: the number of words in the body, 2: the number of words the rules ask for. */
						_n(
							'The body has %1$s word, under the %2$s that the rules ask for.',
							'The body has %1$s words, under the %2$s that the rules ask for.',
							$words,
							'estasoft-news-distribution'
						),
						number_format_i18n( $words ),
						number_format_i18n( (int) $min )
					),
					-$min,
				);
			},
			'',
			true
		);

		$tag = $this->unhandled_shortcode( $content );

		if ( '' !== $tag ) {
			$findings = array_merge(
				$findings,
				$this->emit(
					$article,
					'body.shortcode_left_as_text',
					static function () use ( $tag ): string {
						return sprintf(
							/* translators: %s: the shortcode name, such as gallery. */
							__( 'The shortcode [%s] shows as text in the body because no active plugin handles it.', 'estasoft-news-distribution' ),
							$tag
						);
					}
				)
			);
		}

		$broken = $this->broken_tag( $content );

		if ( '' !== $broken ) {
			$findings = array_merge(
				$findings,
				$this->emit(
					$article,
					'body.broken_markup',
					static function () use ( $broken ): string {
						return sprintf(
							/* translators: %s: a tag or block name such as div or wp:paragraph. */
							__( 'The body has markup that is opened and not closed (%s).', 'estasoft-news-distribution' ),
							$broken
						);
					}
				)
			);
		}

		return $findings;
	}

	/**
	 * Whether the body carries media, so it is not empty even without words.
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	private function has_media( string $content ): bool {
		return 1 === preg_match( '/<(?:img|video|audio|iframe|embed|object)\b|<!--\s+wp:(?:image|gallery|video|audio|embed|core-embed)/i', $content );
	}

	/**
	 * The name of the first shortcode in the body that no active plugin handles, or an empty string.
	 *
	 * A bare `[name]` with no attributes, no closing tag and no `_` or `-` in its name is
	 * ignored, because it is as likely to be an editor's note such as [sic].
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	private function unhandled_shortcode( string $content ): string {
		if ( false === strpos( $content, '[' ) || strlen( $content ) > self::MAX_SCAN ) {
			return '';
		}

		$text = (string) preg_replace( '/<!--.*?-->|<(code|pre|script|style)\b.*?<\/\1>|\[\[.*?\]\]/is', ' ', $content );

		if ( 1 > (int) preg_match_all( '/\[([a-z][a-z0-9_-]*)((?:\s[^\[\]]*)?)\]/i', $text, $found, PREG_SET_ORDER ) ) {
			return '';
		}

		foreach ( $found as $match ) {
			$name = (string) $match[1];

			if ( shortcode_exists( $name ) ) {
				continue;
			}

			$attributes = false !== strpos( (string) $match[2], '=' );
			$closed     = false !== stripos( $text, '[/' . $name . ']' );
			$joined     = false !== strpos( $name, '_' ) || false !== strpos( $name, '-' );

			if ( $attributes || $closed || $joined ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * The first tag or block that is opened and not closed, or an empty string.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	private function broken_tag( string $content ): string {
		if ( '' === $content || strlen( $content ) > self::MAX_SCAN ) {
			return '';
		}

		// Block comments first: a block opened and not closed.
		$blocks = array();

		if ( preg_match_all( '/<!--\s+(\/?)wp:([a-z0-9_\/-]+)(?:\s+\{.*?\})?\s+(\/?)-->/s', $content, $found, PREG_SET_ORDER ) > 0 ) {
			foreach ( $found as $match ) {
				if ( '/' === $match[3] ) {
					continue;
				}

				$name            = (string) $match[2];
				$blocks[ $name ] = ( $blocks[ $name ] ?? 0 ) + ( '/' === $match[1] ? -1 : 1 );
			}
		}

		foreach ( $blocks as $name => $balance ) {
			if ( 0 !== $balance ) {
				return 'wp:' . $name;
			}
		}

		$text  = (string) preg_replace( '/<!--.*?-->|<(script|style|pre|code)\b.*?<\/\1>/is', ' ', $content );
		$count = array();

		if ( preg_match_all( '/<(\/?)([a-z][a-z0-9]*)\b[^<>]*?(\/?)>/i', $text, $tags, PREG_SET_ORDER ) > 0 ) {
			foreach ( $tags as $tag ) {
				$name = strtolower( (string) $tag[2] );

				if ( '/' === $tag[3] || ! in_array( $name, self::PAIRED, true ) ) {
					continue;
				}

				$count[ $name ] = ( $count[ $name ] ?? 0 ) + ( '/' === $tag[1] ? -1 : 1 );
			}
		}

		foreach ( $count as $name => $balance ) {
			if ( 0 !== $balance ) {
				return $name;
			}
		}

		return '';
	}
}

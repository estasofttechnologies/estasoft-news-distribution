<?php
/**
 * The title: present, a sensible length, and free of things platforms ask to leave out.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Checks;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Bylines;

defined( 'ABSPATH' ) || exit;

/**
 * Codes: `title.missing`, `title.long`, `title.short`, `title.publication_name`,
 * `title.author`, `title.date`.
 *
 * The limits `title_max` and `title_min` come from the platform's pack, then the
 * article-checks pack. A platform pack may name the three "leave it out" rules
 * `title.has_publication_name`, `title.has_author` and `title.has_date`.
 */
final class TitleCheck extends AbstractCheck {

	/**
	 * @return string
	 */
	public function id(): string {
		return 'title';
	}

	/**
	 * @return int
	 */
	public function order(): int {
		return 40;
	}

	/**
	 * @param Article $article The article.
	 * @return array<int, array{code: string, severity: string, message: string, channels: string[], fix: string}>
	 */
	public function run( Article $article ): array {
		$title = trim( $article->title() );

		if ( '' === $title ) {
			return $this->emit(
				$article,
				'title.missing',
				static function (): string {
					return __( 'The article has no title.', 'estasoft-news-distribution' );
				},
				'edit-title'
			);
		}

		$length = mb_strlen( $title );

		return array_merge(
			$this->long( $article, $length ),
			$this->short( $article, $length ),
			$this->publication_name( $article, $title ),
			$this->author( $article, $title ),
			$this->date( $article, $title )
		);
	}

	/**
	 * A title over the limit.
	 *
	 * @param Article $article The article.
	 * @param int     $length  Title length in characters.
	 * @return array<int, array<string, mixed>>
	 */
	private function long( Article $article, int $length ): array {
		return $this->emit(
			$article,
			'title.long',
			function ( string $platform ) use ( $length ) {
				$max = $this->limit( $platform, 'title_max' );

				if ( $max < 1 || $length <= $max ) {
					return null;
				}

				return array(
					sprintf(
						/* translators: 1: the length of the title in characters, 2: the longest title that reads well. */
						_n(
							'The title is %1$s character, over the %2$s that reads well in a feed.',
							'The title is %1$s characters, over the %2$s that reads well in a feed.',
							$length,
							'estasoft-news-distribution'
						),
						number_format_i18n( $length ),
						number_format_i18n( (int) $max )
					),
					-$max,
				);
			},
			'edit-title',
			true
		);
	}

	/**
	 * A title under the limit.
	 *
	 * @param Article $article The article.
	 * @param int     $length  Title length in characters.
	 * @return array<int, array<string, mixed>>
	 */
	private function short( Article $article, int $length ): array {
		return $this->emit(
			$article,
			'title.short',
			function ( string $platform ) use ( $length ) {
				$min = $this->limit( $platform, 'title_min' );

				if ( $min < 1 || $length >= $min ) {
					return null;
				}

				return sprintf(
					/* translators: %s: the length of the title in characters. */
					_n(
						'The title is only %s character, so it says little in a feed.',
						'The title is only %s characters, so it says little in a feed.',
						$length,
						'estasoft-news-distribution'
					),
					number_format_i18n( $length )
				);
			},
			'edit-title',
			true
		);
	}

	/**
	 * The publication name in the title, or in the page title an SEO plugin stores for the article.
	 *
	 * @param Article $article The article.
	 * @param string  $title   The title.
	 * @return array<int, array<string, mixed>>
	 */
	private function publication_name( Article $article, string $title ): array {
		$names = array_unique(
			array_filter(
				array(
					$this->plugin->settings()->publication_name( $article->language() ),
					$this->plugin->settings()->publication_name(),
				)
			)
		);

		$message = '';
		$page    = $this->page_title( $article );

		foreach ( $names as $name ) {
			if ( Bylines::contains_word( $title, $name ) ) {
				$message = __( 'The title contains the publication name.', 'estasoft-news-distribution' );
				break;
			}

			if ( '' !== $page && ( Bylines::contains_word( $page, $name ) || 1 === preg_match( '/%%?(?:sitename|site_title|sitetitle|site_name)%%?/i', $page ) ) ) {
				$message = __( 'The page title contains the publication name.', 'estasoft-news-distribution' );
			}
		}

		if ( '' === $message ) {
			return array();
		}

		return $this->emit(
			$article,
			'title.publication_name',
			static function () use ( $message ): string {
				return $message;
			},
			'edit-title',
			true
		);
	}

	/**
	 * The page title written for one article, when an SEO plugin or a developer supplies one.
	 *
	 * Only a title written for this article counts: the pattern an SEO plugin applies to every
	 * page is not read, because adding the site name there is normal and is not what a
	 * platform reads. The meta keys are those of Yoast SEO, Rank Math, SEOPress and The SEO
	 * Framework; they were not checked against the plugins here (see docs/check-codes.md).
	 *
	 * @param Article $article The article.
	 * @return string
	 */
	private function page_title( Article $article ): string {
		/**
		 * Supplies the page title written for an article.
		 *
		 * Internal. Return a string (empty for none), or null to read the SEO plugins' post meta.
		 *
		 * @param string|null $title   The page title, or null.
		 * @param int         $post_id Post ID.
		 */
		$title = apply_filters( 'estasoft_nd_internal_page_title', null, $article->id() );

		if ( is_string( $title ) ) {
			return trim( $title );
		}

		foreach ( array( '_yoast_wpseo_title', 'rank_math_title', '_seopress_titles_title', '_genesis_title' ) as $key ) {
			$value = get_post_meta( $article->id(), $key, true );

			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return '';
	}

	/**
	 * The author's name in the title.
	 *
	 * @param Article $article The article.
	 * @param string  $title   The title.
	 * @return array<int, array<string, mixed>>
	 */
	private function author( Article $article, string $title ): array {
		$author = trim( $article->author_name() );

		if ( '' === $author || $this->plugin->make( Bylines::class )->is_not_a_person( $author ) || ! Bylines::contains_word( $title, $author ) ) {
			return array();
		}

		return $this->emit(
			$article,
			'title.author',
			static function (): string {
				return __( 'The title contains the author name.', 'estasoft-news-distribution' );
			},
			'edit-title',
			true
		);
	}

	/**
	 * A written date in the title, such as 21/10/2026 or 2026-10-21.
	 *
	 * @param Article $article The article.
	 * @param string  $title   The title.
	 * @return array<int, array<string, mixed>>
	 */
	private function date( Article $article, string $title ): array {
		if ( 1 !== preg_match( '/(?<![\p{L}\p{N}])(?:\d{4}[\/.-]\d{1,2}[\/.-]\d{1,2}|\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4})(?![\p{L}\p{N}])/u', $title ) ) {
			return array();
		}

		return $this->emit(
			$article,
			'title.date',
			static function (): string {
				return __( 'The title contains a date.', 'estasoft-news-distribution' );
			},
			'edit-title',
			true
		);
	}
}

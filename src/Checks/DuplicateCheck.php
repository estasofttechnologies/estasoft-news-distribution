<?php
/**
 * Duplicates and clashes: the same title or the same web address on two articles.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Checks;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Language\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Codes: `duplicate.title` (same title, same language), `clash.title` (same title,
 * another language) and `clash.address` (same web address, any language).
 *
 * Work is bounded: one query for the title (at most 5 articles) and the most recent
 * 100 articles for the address. No network.
 */
final class DuplicateCheck extends AbstractCheck {

	private const TITLE_LIMIT   = 5;
	private const ADDRESS_LIMIT = 100;

	/**
	 * @return string
	 */
	public function id(): string {
		return 'duplicate';
	}

	/**
	 * @return int
	 */
	public function order(): int {
		return 80;
	}

	/**
	 * @param Article $article The article.
	 * @return array<int, array{code: string, severity: string, message: string, channels: string[], fix: string}>
	 */
	public function run( Article $article ): array {
		$findings = array();
		$title    = trim( $article->title() );

		if ( '' !== $title ) {
			$same  = '';
			$other = '';

			foreach ( $this->ids( array( 'title' => $title ), self::TITLE_LIMIT, $article->id() ) as $id ) {
				$language = $this->plugin->languages()->language_of_post( $id );

				if ( $language === $article->language() ) {
					$same = '' === $same ? $this->describe( $id ) : $same;
				} else {
					$other = '' === $other ? $this->describe( $id, $language ) : $other;
				}
			}

			if ( '' !== $same ) {
				$findings = array_merge(
					$findings,
					$this->emit(
						$article,
						'duplicate.title',
						static function () use ( $same ): string {
							return sprintf(
								/* translators: %s: the title of another article with the same title, in quotes. */
								__( 'Another article in this language has the same title: %s.', 'estasoft-news-distribution' ),
								$same
							);
						}
					)
				);
			}

			if ( '' !== $other ) {
				$findings = array_merge(
					$findings,
					$this->emit(
						$article,
						'clash.title',
						static function () use ( $other ): string {
							return sprintf(
								/* translators: %s: the title and language of another article, such as "Harbor festival" (Arabic). */
								__( 'An article in another language has the same title, %s. A translation may have been copied without being translated.', 'estasoft-news-distribution' ),
								$other
							);
						}
					)
				);
			}
		}

		$clash = $this->address_clash( $article );

		if ( '' !== $clash ) {
			$findings = array_merge(
				$findings,
				$this->emit(
					$article,
					'clash.address',
					static function () use ( $clash ): string {
						return sprintf(
							/* translators: %s: the title and language of the other article, such as "Harbor festival" (Arabic). */
							__( 'This article has the same web address as %s, so a platform sees one page for both.', 'estasoft-news-distribution' ),
							$clash
						);
					}
				)
			);
		}

		return $findings;
	}

	/**
	 * The first recent article with the same web address, described, or an empty string.
	 *
	 * @param Article $article The article.
	 * @return string
	 */
	private function address_clash( Article $article ): string {
		if ( ! $article->is_public() || '' === $article->permalink() ) {
			return '';
		}

		foreach ( $this->ids( array(), self::ADDRESS_LIMIT, $article->id() ) as $id ) {
			if ( get_permalink( $id ) === $article->permalink() ) {
				return $this->describe( $id, $this->plugin->languages()->language_of_post( $id ) );
			}
		}

		return '';
	}

	/**
	 * IDs of other articles on the site's post types, across every language.
	 *
	 * @param array<string, mixed> $query   Extra query arguments.
	 * @param int                  $limit   Most articles to read.
	 * @param int                  $exclude Article to leave out.
	 * @return int[]
	 */
	private function ids( array $query, int $limit, int $exclude ): array {
		$ids = get_posts(
			array_merge(
				array(
					'post_type'           => $this->plugin->settings()->post_types(),
					'post_status'         => array( 'publish', 'future' ),
					'post__not_in'        => array( $exclude ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Leaves out the one article that is being checked.
					'posts_per_page'      => $limit,
					'orderby'             => 'date',
					'order'               => 'DESC',
					'fields'              => 'ids',
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
					'lang'                => '',
					'suppress_filters'    => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- A duplicate can be in another language; another plugin's filters must not hide it.
				),
				$query
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * An article as `"Title" (Language)`.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $language Language code, or an empty string to leave the language out.
	 * @return string
	 */
	private function describe( int $post_id, string $language = '' ): string {
		$title = wp_strip_all_tags( get_the_title( $post_id ) );
		$text  = '"' . $title . '"';

		return '' === $language ? $text : $text . ' (' . Catalog::name( $language ) . ')';
	}
}

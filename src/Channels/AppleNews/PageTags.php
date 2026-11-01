<?php
/**
 * Reads the tags Apple takes from an article page.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\AppleNews;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Apple reads the thumbnail (and, News Distribution assumes, the author and
 * language) from each article's page, not from the feed, so a feed cannot show
 * whether those tags are there. This class reads them from the page.
 *
 * It only runs when a person presses the button on the Apple News screen. It
 * asks the owner's own site for a few pages (never another host), stops after
 * a time budget, and keeps the answer so the screen can show it later.
 */
final class PageTags {

	/**
	 * Where the last check is kept (not loaded on every request).
	 */
	public const OPTION = 'estasoft_nd_apple_page_tags';

	/**
	 * Most pages one check reads.
	 */
	public const LIMIT = 18;

	/**
	 * Seconds a check may spend asking for pages.
	 */
	public const BUDGET = 20;

	/**
	 * The tags on one page.
	 *
	 * @param string $html The page's HTML.
	 * @return array{author: bool, image: bool, language: bool}
	 */
	public static function read( string $html ): array {
		$found     = array(
			'author'   => false,
			'image'    => false,
			'language' => false,
		);
		$processor = new \WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag( 'meta' ) ) {
			$key     = '';
			$content = $processor->get_attribute( 'content' );

			foreach ( array( 'property', 'name' ) as $attribute ) {
				$value = $processor->get_attribute( $attribute );

				if ( is_string( $value ) && '' !== $value ) {
					$key = strtolower( trim( $value ) );
					break;
				}
			}

			if ( '' === $key || ! is_string( $content ) || '' === trim( $content ) ) {
				continue;
			}

			if ( in_array( $key, array( 'author', 'article:author' ), true ) ) {
				$found['author'] = true;
			} elseif ( in_array( $key, array( 'og:image', 'og:image:secure_url' ), true ) ) {
				$found['image'] = true;
			} elseif ( 'og:locale' === $key ) {
				$found['language'] = true;
			}
		}

		return $found;
	}

	/**
	 * Reads the pages of some articles.
	 *
	 * @param Article[] $articles Articles to read, newest first.
	 * @return array<string, mixed> The result, as stored.
	 */
	public static function check( array $articles ): array {
		$started = microtime( true );
		$host    = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$rows    = array();
		$reached = 0;

		foreach ( array_slice( $articles, 0, self::LIMIT ) as $article ) {
			if ( ! $article instanceof Article ) {
				continue;
			}

			$row = array(
				'id'       => $article->id(),
				'title'    => $article->title(),
				'language' => $article->language(),
				'featured' => '' !== $article->thumbnail_url(),
				'fetched'  => false,
				'author'   => false,
				'image'    => false,
				'tag_lang' => false,
			);

			$page_host = strtolower( (string) wp_parse_url( $article->permalink(), PHP_URL_HOST ) );

			if ( $page_host === $host && microtime( true ) - $started < self::BUDGET ) {
				$response = wp_remote_get(
					$article->permalink(),
					array(
						'timeout'     => 5,
						'redirection' => 3,
						'user-agent'  => 'EstasoftNewsDistribution/' . ESTASOFT_ND_VERSION . ' (page tag check)',
					)
				);

				if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
					$tags            = self::read( (string) wp_remote_retrieve_body( $response ) );
					$row['fetched']  = true;
					$row['author']   = $tags['author'];
					$row['image']    = $tags['image'];
					$row['tag_lang'] = $tags['language'];
					++$reached;
				}
			}

			$rows[] = $row;
		}

		return array(
			'checked_at' => Clock::now(),
			'total'      => count( $rows ),
			'reached'    => $reached,
			'rows'       => $rows,
		);
	}

	/**
	 * The totals the screen prints for a stored check.
	 *
	 * @param array<string, mixed> $result A result from check().
	 * @return array{total: int, reached: int, author: int, image: int, language: int, no_featured: int, missing_language: int}
	 */
	public static function totals( array $result ): array {
		$totals = array(
			'total'            => (int) ( $result['total'] ?? 0 ),
			'reached'          => (int) ( $result['reached'] ?? 0 ),
			'author'           => 0,
			'image'            => 0,
			'language'         => 0,
			'no_featured'      => 0,
			'missing_language' => 0,
		);

		foreach ( (array) ( $result['rows'] ?? array() ) as $row ) {
			if ( empty( $row['fetched'] ) ) {
				continue;
			}

			$totals['author']           += ! empty( $row['author'] ) ? 1 : 0;
			$totals['image']            += ! empty( $row['image'] ) ? 1 : 0;
			$totals['language']         += ! empty( $row['tag_lang'] ) ? 1 : 0;
			$totals['no_featured']      += empty( $row['image'] ) && empty( $row['featured'] ) ? 1 : 0;
			$totals['missing_language'] += empty( $row['tag_lang'] ) ? 1 : 0;
		}

		return $totals;
	}

	/**
	 * The last stored check.
	 *
	 * @return array<string, mixed> Empty when no check has run.
	 */
	public static function stored(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Keeps a check's result.
	 *
	 * @param array<string, mixed> $result A result from check().
	 * @return void
	 */
	public static function store( array $result ): void {
		update_option( self::OPTION, $result, false );
	}
}

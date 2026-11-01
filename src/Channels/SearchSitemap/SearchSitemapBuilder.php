<?php
/**
 * Builds and checks the plain search sitemap that Bing and Yandex read.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\SearchSitemap;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Language\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * One standard XML sitemap per language: the latest 1,000 articles, each with
 * an accurate last-modified time. It is not the Google News sitemap. Nothing
 * here reads the database; it works on the articles it is given, so it is easy
 * to test.
 */
final class SearchSitemapBuilder {

	/**
	 * Most URLs in one file. News Distribution's own limit.
	 */
	public const MAX_URLS = 1000;

	/**
	 * Longest address in a sitemap, in characters. The sitemap protocol sets it
	 * and Yandex states the same limit.
	 */
	public const URL_MAX_CHARS = 2048;

	/**
	 * The sitemap protocol's namespace.
	 */
	public const NAMESPACE_URI = 'http://www.sitemaps.org/schemas/sitemap/0.9';

	/**
	 * Builds the sitemap for one language.
	 *
	 * @param Article[] $articles     Candidate articles, in any order.
	 * @param string    $language     Language code of this sitemap.
	 * @param int       $now          Unix time to treat as now.
	 * @param int       $url_max_chars Longest address to include, or 0 for no limit.
	 * @return string
	 */
	public static function build( array $articles, string $language, int $now, int $url_max_chars = 0 ): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="' . self::NAMESPACE_URI . '">' . "\n";

		foreach ( self::select( $articles, $language, $now, $url_max_chars ) as $article ) {
			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . Xml::text( $article->permalink() ) . "</loc>\n";
			$xml .= "\t\t<lastmod>" . gmdate( 'c', self::last_modified( $article, $now ) ) . "</lastmod>\n";
			$xml .= "\t</url>\n";
		}

		return $xml . "</urlset>\n";
	}

	/**
	 * The articles that belong in the sitemap, newest first: public, in this
	 * language, with an address, not dated in the future, one entry per address,
	 * at most 1,000.
	 *
	 * @param Article[] $articles      Candidate articles.
	 * @param string    $language      Language code.
	 * @param int       $now           Unix time to treat as now.
	 * @param int       $url_max_chars Longest address to include, or 0 for no limit.
	 * @return Article[]
	 */
	public static function select( array $articles, string $language, int $now, int $url_max_chars = 0 ): array {
		$chosen = array_filter(
			$articles,
			static function ( $article ) use ( $language, $now, $url_max_chars ): bool {
				return $article instanceof Article
					&& $article->is_public()
					&& '' !== $article->permalink()
					&& self::same_language( $article->language(), $language )
					&& $article->published_ts() <= $now
					&& ( $url_max_chars < 1 || strlen( $article->permalink() ) <= $url_max_chars );
			}
		);

		usort(
			$chosen,
			static function ( Article $a, Article $b ): int {
				$by_date = $b->published_ts() <=> $a->published_ts();

				return 0 !== $by_date ? $by_date : $b->id() <=> $a->id();
			}
		);

		$seen = array();
		$out  = array();

		foreach ( $chosen as $article ) {
			if ( isset( $seen[ $article->permalink() ] ) ) {
				continue;
			}

			$seen[ $article->permalink() ] = true;
			$out[]                         = $article;

			if ( count( $out ) >= self::MAX_URLS ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * The time shown as the last-modified time: when the article last changed,
	 * never earlier than it was published and never later than now.
	 *
	 * @param Article $article The article.
	 * @param int     $now     Unix time to treat as now.
	 * @return int
	 */
	public static function last_modified( Article $article, int $now ): int {
		return min( $now, max( $article->modified_ts(), $article->published_ts() ) );
	}

	/**
	 * Whether an article's language is the language of the sitemap. An article
	 * with no language recorded is accepted, because nothing says otherwise.
	 *
	 * @param string $article_language Language the article is in.
	 * @param string $wanted           Language of the sitemap.
	 * @return bool
	 */
	public static function same_language( string $article_language, string $wanted ): bool {
		if ( '' === $article_language ) {
			return true;
		}

		return strtolower( str_replace( '_', '-', $article_language ) ) === strtolower( str_replace( '_', '-', $wanted ) );
	}

	/**
	 * The plugin's own check of a built search sitemap, in plain words.
	 *
	 * @param string               $body     The document.
	 * @param array<string, mixed> $limits   Optional `max_urls` and `url_max_chars` (0 or missing means no check).
	 * @param string               $site_url The site's address, to compare hosts with.
	 * @return array{valid: bool, entries: int, problems: string[]}
	 */
	public static function check( string $body, array $limits, string $site_url ): array {
		$parsed = Xml::parse( $body );

		if ( null === $parsed['xml'] ) {
			$problems = array( __( 'The sitemap is not valid XML, so a search engine could not read it.', 'estasoft-news-distribution' ) );

			foreach ( $parsed['errors'] as $message ) {
				$problems[] = $message;
			}

			return array(
				'valid'    => false,
				'entries'  => 0,
				'problems' => $problems,
			);
		}

		$xml      = $parsed['xml'];
		$problems = array();

		if ( in_array( strtolower( $xml->getName() ), array( 'rss', 'feed', 'rdf' ), true ) ) {
			$problems[] = __( 'This is a feed, not a sitemap. Search engines expect a sitemap file here.', 'estasoft-news-distribution' );
		} elseif ( 'urlset' !== $xml->getName() || self::NAMESPACE_URI !== (string) ( $xml->getNamespaces()[''] ?? '' ) ) {
			$problems[] = __( 'The sitemap does not start with a standard urlset element.', 'estasoft-news-distribution' );
		}

		$urls      = $xml->children( self::NAMESPACE_URI );
		$entries   = 0;
		$max_urls  = (int) ( $limits['max_urls'] ?? 0 );
		$max_chars = (int) ( $limits['url_max_chars'] ?? 0 );
		$site_host = (string) wp_parse_url( $site_url, PHP_URL_HOST );
		$counts    = array(
			'no_loc'      => 0,
			'bad_loc'     => 0,
			'long_loc'    => 0,
			'other_host'  => 0,
			'no_lastmod'  => 0,
			'bad_lastmod' => 0,
			'extra'       => 0,
		);

		foreach ( $urls->url as $url ) {
			++$entries;

			$loc = trim( (string) $url->loc );

			if ( '' === $loc ) {
				++$counts['no_loc'];
			} else {
				$parts = wp_parse_url( $loc );
				$host  = is_array( $parts ) ? (string) ( $parts['host'] ?? '' ) : '';
				$ok    = is_array( $parts ) && '' !== $host && in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true );

				if ( ! $ok ) {
					++$counts['bad_loc'];
				} elseif ( '' !== $site_host && strtolower( $host ) !== strtolower( $site_host ) ) {
					++$counts['other_host'];
				}

				if ( $max_chars > 0 && strlen( $loc ) > $max_chars ) {
					++$counts['long_loc'];
				}
			}

			$lastmod = trim( (string) $url->lastmod );

			if ( '' === $lastmod ) {
				++$counts['no_lastmod'];
			} elseif ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2}))?$/', $lastmod ) ) {
				++$counts['bad_lastmod'];
			}

			foreach ( $url->getNamespaces( true ) as $namespace ) {
				if ( self::NAMESPACE_URI !== (string) $namespace && count( $url->children( (string) $namespace ) ) > 0 ) {
					++$counts['extra'];
					break;
				}
			}
		}

		if ( $max_urls > 0 && $entries > $max_urls ) {
			$problems[] = sprintf(
				/* translators: 1: number of URLs in the sitemap, 2: largest number allowed in one file. */
				_n( 'The sitemap has %1$s URL. One file may hold at most %2$s.', 'The sitemap has %1$s URLs. One file may hold at most %2$s.', $entries, 'estasoft-news-distribution' ),
				number_format_i18n( $entries ),
				number_format_i18n( $max_urls )
			);
		}

		$messages = array(
			'no_loc'      => array(
				/* translators: %s: number of entries. */
				_n( '%s entry has no address.', '%s entries have no address.', $counts['no_loc'], 'estasoft-news-distribution' ),
				$counts['no_loc'],
			),
			'bad_loc'     => array(
				/* translators: %s: number of entries. */
				_n( '%s entry has an address that is not a full http or https address.', '%s entries have an address that is not a full http or https address.', $counts['bad_loc'], 'estasoft-news-distribution' ),
				$counts['bad_loc'],
			),
			'other_host'  => array(
				/* translators: %s: number of entries. */
				_n( '%s entry points to another domain than your site.', '%s entries point to another domain than your site.', $counts['other_host'], 'estasoft-news-distribution' ),
				$counts['other_host'],
			),
			'long_loc'    => array(
				/* translators: %s: number of entries. */
				_n( '%s address is longer than the allowed length.', '%s addresses are longer than the allowed length.', $counts['long_loc'], 'estasoft-news-distribution' ),
				$counts['long_loc'],
			),
			'no_lastmod'  => array(
				/* translators: %s: number of entries. */
				_n( '%s entry has no last-modified time.', '%s entries have no last-modified time.', $counts['no_lastmod'], 'estasoft-news-distribution' ),
				$counts['no_lastmod'],
			),
			'bad_lastmod' => array(
				/* translators: %s: number of entries. */
				_n( '%s entry has a last-modified time in a format search engines may not read.', '%s entries have a last-modified time in a format search engines may not read.', $counts['bad_lastmod'], 'estasoft-news-distribution' ),
				$counts['bad_lastmod'],
			),
			'extra'       => array(
				/* translators: %s: number of entries. */
				_n( '%s entry has elements that are not part of the standard sitemap.', '%s entries have elements that are not part of the standard sitemap.', $counts['extra'], 'estasoft-news-distribution' ),
				$counts['extra'],
			),
		);

		foreach ( $messages as $key => $message ) {
			if ( $counts[ $key ] > 0 ) {
				$problems[] = sprintf( $message[0], number_format_i18n( (int) $message[1] ) );
			}
		}

		return array(
			'valid'    => array() === $problems,
			'entries'  => $entries,
			'problems' => $problems,
		);
	}

	/**
	 * The address of the search sitemap for one language.
	 *
	 * @param string $language Language code.
	 * @return string
	 */
	public static function url( string $language ): string {
		return add_query_arg( 'lang', rawurlencode( $language ), home_url( '/feed/search-sitemap/' ) );
	}

	/**
	 * The short language tag printed beside an address, such as "KU".
	 *
	 * @param string $language Language code.
	 * @return string
	 */
	public static function tag( string $language ): string {
		return Catalog::tag( $language );
	}
}

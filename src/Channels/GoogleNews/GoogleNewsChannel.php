<?php
/**
 * Google News sitemap channel.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\GoogleNews;

use Estasoft\NewsDistribution\Channels\AbstractChannel;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Channels\SearchSitemap\FetchSummary;
use Estasoft\NewsDistribution\Channels\SearchSitemap\SearchSitemapBuilder;
use Estasoft\NewsDistribution\Channels\SearchSitemap\SitemapProbe;
use Estasoft\NewsDistribution\Channels\SearchSitemap\Xml;
use Estasoft\NewsDistribution\Channels\ValidationResult;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a news sitemap that follows Google's documented rules: only articles
 * from the last two days, at most 1,000 entries, the required tags (publication
 * name, language, publication date, title) and titles without the author, the
 * publication name or the date.
 *
 * One file per language, served at `/feed/google-news/?lang=xx`. The limits and
 * severities come from `rules/google-news.json`; the wording lives here.
 *
 * The sitemap declares the sitemap and `news` namespaces only. It writes no
 * `xhtml:link` alternates: each translation has its own sitemap, so nothing here
 * adds, moves or rewrites them.
 */
final class GoogleNewsChannel extends AbstractChannel {

	/**
	 * Largest number of articles in one news sitemap file, when the rule pack has no limit.
	 */
	public const MAX_URLS = 1000;

	/**
	 * Articles older than this drop out of the sitemap: two days, when the rule pack has no limit.
	 */
	public const WINDOW_SECONDS = 172800;

	/**
	 * The namespace of the Google News sitemap elements.
	 */
	public const NEWS_NAMESPACE = 'http://www.google.com/schemas/sitemap-news/0.9';

	public function id(): string {
		return 'google-news';
	}

	public function label(): string {
		return __( 'Google News', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return Group::SITEMAP;
	}

	public function order(): int {
		return 10;
	}

	public function mode(): string {
		return self::MODE_PULLED;
	}

	public function gate_note(): string {
		return __( 'Title rules, language and sitemap eligibility.', 'estasoft-news-distribution' );
	}

	public function feed_window(): array {
		return array(
			'max_age' => $this->window_seconds(),
			'limit'   => $this->max_urls(),
		);
	}

	/**
	 * How long an article stays in the sitemap, in seconds.
	 *
	 * @return int
	 */
	public function window_seconds(): int {
		return max( 1, (int) $this->context->rules->limit( $this->id(), 'window_hours', self::WINDOW_SECONDS / HOUR_IN_SECONDS ) ) * HOUR_IN_SECONDS;
	}

	/**
	 * Most entries in one sitemap file.
	 *
	 * @return int
	 */
	public function max_urls(): int {
		return max( 1, (int) $this->context->rules->limit( $this->id(), 'max_urls', self::MAX_URLS ) );
	}

	/**
	 * Longest publication name News Distribution accepts, in characters.
	 *
	 * @return int
	 */
	public function name_max(): int {
		return max( 1, (int) $this->context->rules->limit( $this->id(), 'publication_name_max', 80 ) );
	}

	/**
	 * Checks one article against the rules of this channel.
	 *
	 * @param Article $article The article.
	 * @return ValidationResult
	 */
	public function validate( Article $article ): ValidationResult {
		$result = new ValidationResult();
		$name   = $this->context->settings->publication_name( $article->language() );
		$title  = trim( $article->title() );

		if ( '' === $name ) {
			$this->flag(
				$result,
				'publication_name.missing',
				__( 'Set the publication name in the News Distribution settings. It must match the name Google News shows for your articles.', 'estasoft-news-distribution' )
			);
		} elseif ( self::length( $name ) > $this->name_max() ) {
			$this->flag(
				$result,
				'publication_name.long',
				sprintf(
					/* translators: %s: largest number of characters allowed in the publication name. */
					__( 'The publication name is longer than %s characters. Shorten it in the News Distribution settings.', 'estasoft-news-distribution' ),
					number_format_i18n( $this->name_max() )
				)
			);
		}

		if ( '' === $title ) {
			$this->flag( $result, 'title.missing', __( 'The article has no title, so it cannot go in the news sitemap.', 'estasoft-news-distribution' ) );
		}

		if ( '' === $article->permalink() ) {
			$this->flag( $result, 'url.missing', __( 'The article has no public address, so it cannot go in the news sitemap.', 'estasoft-news-distribution' ) );
		}

		if ( '' !== $title && self::title_has( $title, $name ) ) {
			$this->flag(
				$result,
				'title.has_publication_name',
				__( 'The title includes the publication name. Google asks for titles without it.', 'estasoft-news-distribution' )
			);
		}

		if ( '' !== $title && self::title_has( $title, $article->author_name() ) ) {
			$this->flag(
				$result,
				'title.has_author',
				__( 'The title includes the author name. Google asks for titles without it.', 'estasoft-news-distribution' )
			);
		}

		if ( '' !== $title && self::has_date( $title ) ) {
			$this->flag(
				$result,
				'title.has_date',
				__( 'The title includes a date. Google asks for titles without it.', 'estasoft-news-distribution' )
			);
		}

		if ( $article->published_ts() < Clock::now() - $this->window_seconds() ) {
			$this->flag(
				$result,
				'news.window',
				__( 'The article is older than two days, so it is not in the news sitemap.', 'estasoft-news-distribution' )
			);
		}

		return $result;
	}

	/**
	 * Builds the news sitemap for one language.
	 *
	 * @param Article[] $articles Candidate articles.
	 * @param string    $language Language code of this sitemap.
	 * @param int|null  $now      Unix time to treat as now.
	 * @return string
	 */
	public function build( array $articles, string $language, ?int $now = null ): string {
		$now  = null === $now ? Clock::now() : $now;
		$name = Xml::text( $this->context->settings->publication_name( $language ) );
		$lang = Xml::text( self::news_language( $language ) );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="' . SearchSitemapBuilder::NAMESPACE_URI . '" xmlns:news="' . self::NEWS_NAMESPACE . '">' . "\n";

		foreach ( $this->recent( $articles, $now, $language ) as $article ) {
			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . Xml::text( $article->permalink() ) . "</loc>\n";
			$xml .= "\t\t<news:news>\n";
			$xml .= "\t\t\t<news:publication>\n";
			$xml .= "\t\t\t\t<news:name>{$name}</news:name>\n";
			$xml .= "\t\t\t\t<news:language>{$lang}</news:language>\n";
			$xml .= "\t\t\t</news:publication>\n";
			$xml .= "\t\t\t<news:publication_date>" . gmdate( 'c', $article->published_ts() ) . "</news:publication_date>\n";
			$xml .= "\t\t\t<news:title>" . Xml::text( $article->title() ) . "</news:title>\n";
			$xml .= "\t\t</news:news>\n";
			$xml .= "\t</url>\n";
		}

		return $xml . "</urlset>\n";
	}

	/**
	 * Articles that belong in the sitemap: public, in this language, with a title
	 * and an address, published within the window and not in the future, one entry
	 * per address, newest first, capped at the limit.
	 *
	 * @param Article[] $articles Candidate articles.
	 * @param int       $now      Unix time to treat as now.
	 * @param string    $language Language code, or an empty string for any language.
	 * @return Article[]
	 */
	public function recent( array $articles, int $now, string $language = '' ): array {
		$cutoff = $now - $this->window_seconds();

		$recent = array_filter(
			$articles,
			static function ( $article ) use ( $cutoff, $now, $language ): bool {
				return $article instanceof Article
					&& $article->is_public()
					&& '' !== $article->permalink()
					&& '' !== trim( $article->title() )
					&& ( '' === $language || SearchSitemapBuilder::same_language( $article->language(), $language ) )
					&& $article->published_ts() >= $cutoff
					&& $article->published_ts() <= $now;
			}
		);

		usort(
			$recent,
			static function ( Article $a, Article $b ): int {
				$by_date = $b->published_ts() <=> $a->published_ts();

				return 0 !== $by_date ? $by_date : $b->id() <=> $a->id();
			}
		);

		$seen = array();
		$out  = array();

		foreach ( $recent as $article ) {
			if ( isset( $seen[ $article->permalink() ] ) ) {
				continue;
			}

			$seen[ $article->permalink() ] = true;
			$out[]                         = $article;

			if ( count( $out ) >= $this->max_urls() ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * The plugin's own check of a built news sitemap, in plain words.
	 *
	 * @param string $body     The document.
	 * @param string $language Language code.
	 * @return array{valid: bool, entries: int, problems: string[]}
	 */
	public function check_output( string $body, string $language ): array {
		$parsed = Xml::parse( $body );

		if ( null === $parsed['xml'] ) {
			return array(
				'valid'    => false,
				'entries'  => 0,
				'problems' => array_merge(
					array( __( 'The sitemap is not valid XML, so Google could not read it.', 'estasoft-news-distribution' ) ),
					$parsed['errors']
				),
			);
		}

		$xml      = $parsed['xml'];
		$problems = array();
		$counts   = array(
			'no_loc'          => 0,
			'no_name'         => 0,
			'no_language'     => 0,
			'wrong_language'  => 0,
			'no_date'         => 0,
			'bad_date'        => 0,
			'no_title'        => 0,
			'old'             => 0,
			'name_in_title'   => 0,
			'date_in_title'   => 0,
			'name_too_long'   => 0,
			'no_news_element' => 0,
		);

		if ( 'urlset' !== $xml->getName() || ! in_array( self::NEWS_NAMESPACE, $xml->getNamespaces( true ), true ) ) {
			$problems[] = __( 'The sitemap does not start with a urlset element that declares the Google News namespace.', 'estasoft-news-distribution' );
		}

		$expected = self::news_language( $language );
		$cutoff   = Clock::now() - $this->window_seconds();
		$entries  = 0;

		foreach ( $xml->children( SearchSitemapBuilder::NAMESPACE_URI )->url as $url ) {
			++$entries;

			if ( '' === trim( (string) $url->loc ) ) {
				++$counts['no_loc'];
			}

			$news = $url->children( self::NEWS_NAMESPACE )->news;

			if ( 0 === count( $news ) ) {
				++$counts['no_news_element'];
				continue;
			}

			$news        = $news->children( self::NEWS_NAMESPACE );
			$publication = $news->publication->children( self::NEWS_NAMESPACE );
			$name        = trim( (string) $publication->name );
			$entry_lang  = trim( (string) $publication->language );
			$date        = trim( (string) $news->publication_date );
			$title       = trim( (string) $news->title );

			if ( '' === $name ) {
				++$counts['no_name'];
			} elseif ( self::length( $name ) > $this->name_max() ) {
				++$counts['name_too_long'];
			}

			if ( '' === $entry_lang ) {
				++$counts['no_language'];
			} elseif ( strtolower( $entry_lang ) !== $expected ) {
				++$counts['wrong_language'];
			}

			if ( '' === $date ) {
				++$counts['no_date'];
			} else {
				$time = strtotime( $date );

				if ( false === $time || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}/', $date ) ) {
					++$counts['bad_date'];
				} elseif ( $time < $cutoff ) {
					++$counts['old'];
				}
			}

			if ( '' === $title ) {
				++$counts['no_title'];
				continue;
			}

			if ( self::title_has( $title, $name ) ) {
				++$counts['name_in_title'];
			}

			if ( self::has_date( $title ) ) {
				++$counts['date_in_title'];
			}
		}

		if ( $entries > $this->max_urls() ) {
			$problems[] = sprintf(
				/* translators: 1: number of articles in the sitemap, 2: largest number of articles allowed in one sitemap. */
				_n( 'The sitemap has %1$s article. Google allows at most %2$s in one news sitemap.', 'The sitemap has %1$s articles. Google allows at most %2$s in one news sitemap.', $entries, 'estasoft-news-distribution' ),
				number_format_i18n( $entries ),
				number_format_i18n( $this->max_urls() )
			);
		}

		$messages = array(
			'no_loc'          => array(
				/* translators: %s: number of entries. */
				_n( '%s entry has no article address.', '%s entries have no article address.', $counts['no_loc'], 'estasoft-news-distribution' ),
				$counts['no_loc'],
			),
			'no_news_element' => array(
				/* translators: %s: number of entries. */
				_n( '%s entry has no news details.', '%s entries have no news details.', $counts['no_news_element'], 'estasoft-news-distribution' ),
				$counts['no_news_element'],
			),
			'no_name'         => array(
				/* translators: %s: number of entries. */
				_n( '%s entry has no publication name.', '%s entries have no publication name.', $counts['no_name'], 'estasoft-news-distribution' ),
				$counts['no_name'],
			),
			'name_too_long'   => array(
				/* translators: %s: number of entries. */
				_n( '%s entry has a publication name that is too long.', '%s entries have a publication name that is too long.', $counts['name_too_long'], 'estasoft-news-distribution' ),
				$counts['name_too_long'],
			),
			'no_language'     => array(
				/* translators: %s: number of entries. */
				_n( '%s entry has no language.', '%s entries have no language.', $counts['no_language'], 'estasoft-news-distribution' ),
				$counts['no_language'],
			),
			'wrong_language'  => array(
				/* translators: %s: number of entries. */
				_n( '%s entry is in another language than this sitemap.', '%s entries are in another language than this sitemap.', $counts['wrong_language'], 'estasoft-news-distribution' ),
				$counts['wrong_language'],
			),
			'no_date'         => array(
				/* translators: %s: number of entries. */
				_n( '%s entry has no publication date.', '%s entries have no publication date.', $counts['no_date'], 'estasoft-news-distribution' ),
				$counts['no_date'],
			),
			'bad_date'        => array(
				/* translators: %s: number of entries. */
				_n( '%s entry has a publication date Google may not read.', '%s entries have a publication date Google may not read.', $counts['bad_date'], 'estasoft-news-distribution' ),
				$counts['bad_date'],
			),
			'old'             => array(
				/* translators: %s: number of entries. */
				_n( '%s article is older than two days. A news sitemap holds only the last two days.', '%s articles are older than two days. A news sitemap holds only the last two days.', $counts['old'], 'estasoft-news-distribution' ),
				$counts['old'],
			),
			'no_title'        => array(
				/* translators: %s: number of entries. */
				_n( '%s entry has no title.', '%s entries have no title.', $counts['no_title'], 'estasoft-news-distribution' ),
				$counts['no_title'],
			),
			'name_in_title'   => array(
				/* translators: %s: number of entries. */
				_n( '%s title includes the publication name.', '%s titles include the publication name.', $counts['name_in_title'], 'estasoft-news-distribution' ),
				$counts['name_in_title'],
			),
			'date_in_title'   => array(
				/* translators: %s: number of entries. */
				_n( '%s title includes a date.', '%s titles include a date.', $counts['date_in_title'], 'estasoft-news-distribution' ),
				$counts['date_in_title'],
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
	 * A one-off test a person starts: asks the site's own sitemap addresses, as a
	 * visitor would, and checks what comes back. It contacts no other server.
	 *
	 * @return array{ok: bool, code: int, message: string}
	 */
	public function test(): array {
		return SitemapProbe::run(
			$this,
			array_map( 'strval', $this->context->languages->languages() ),
			function ( string $body, string $language ): array {
				return $this->check_output( $body, $language );
			}
		);
	}

	/**
	 * How Google's fetches look per language, read by crawler name.
	 *
	 * @return array<string, array{status: string, last_ts: int, fetches_24h: int, agent: string}>
	 */
	public function fetch_report(): array {
		$languages = array();

		foreach ( $this->context->languages->languages() as $language ) {
			if ( $this->accepts_language( (string) $language ) ) {
				$languages[] = (string) $language;
			}
		}

		return FetchSummary::states(
			array( $this->id() ),
			$this->fetch_agents(),
			$languages,
			$this->context->rules->fetch_interval_hours( $this->id() )
		);
	}

	/**
	 * Crawler names that count as Google fetching the sitemap, from the rule pack.
	 *
	 * @return string[]
	 */
	public function fetch_agents(): array {
		$agents = (array) ( $this->context->rules->pack( $this->id() )['fetch']['agents'] ?? array( 'googlebot-news', 'googlebot' ) );

		return array_values( array_filter( array_map( 'strval', $agents ) ) );
	}

	/**
	 * Converts a language code to the form Google News expects: an ISO 639
	 * code of two or three letters, with zh-cn and zh-tw for Chinese.
	 *
	 * @param string $language Language code or locale, such as "ar", "en_US" or "zh-hant".
	 * @return string
	 */
	public static function news_language( string $language ): string {
		$code = strtolower( str_replace( '_', '-', trim( $language ) ) );

		if ( in_array( $code, array( 'zh-cn', 'zh-tw' ), true ) ) {
			return $code;
		}

		if ( 0 === strpos( $code, 'zh-hant' ) ) {
			return 'zh-tw';
		}

		if ( 'zh' === $code || 0 === strpos( $code, 'zh-hans' ) ) {
			return 'zh-cn';
		}

		$parts = explode( '-', $code );

		return 1 === preg_match( '/^[a-z]{2,3}$/', $parts[0] ) ? $parts[0] : 'en';
	}

	protected function fetch_states(): array {
		return $this->fetch_report();
	}

	protected function hub_summary(): string {
		return __( 'One news sitemap per language, with articles from the last two days.', 'estasoft-news-distribution' );
	}

	/**
	 * Adds a finding at the severity the rule pack gives it.
	 *
	 * @param ValidationResult $result  The result to add to.
	 * @param string           $code    Rule code, such as "title.has_date".
	 * @param string           $message One line a person can act on.
	 * @return void
	 */
	private function flag( ValidationResult $result, string $code, string $message ): void {
		$result->add( $this->context->rules->severity( $this->id(), $code ), $code, $message );
	}

	/**
	 * Whether a title contains a name. Names shorter than three characters are
	 * ignored, because they would match inside ordinary words.
	 *
	 * @param string $title Article title.
	 * @param string $name  Publication or author name.
	 * @return bool
	 */
	private static function title_has( string $title, string $name ): bool {
		$name = trim( $name );

		if ( self::length( $name ) < 3 ) {
			return false;
		}

		return function_exists( 'mb_stripos' ) ? false !== mb_stripos( $title, $name, 0, 'UTF-8' ) : false !== stripos( $title, $name );
	}

	/**
	 * Whether a title carries a written date such as 21/10/2026, 21.10.26 or 2026-10-21.
	 *
	 * @param string $title Article title.
	 * @return bool
	 */
	private static function has_date( string $title ): bool {
		return 1 === preg_match( '/(?<![\p{L}\p{N}])(\p{Nd}{1,2}[\/.\-]\p{Nd}{1,2}[\/.\-]\p{Nd}{2,4}|\p{Nd}{4}[\/.\-]\p{Nd}{1,2}[\/.\-]\p{Nd}{1,2})(?![\p{L}\p{N}])/u', $title );
	}

	/**
	 * Length of a text in characters.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}
}

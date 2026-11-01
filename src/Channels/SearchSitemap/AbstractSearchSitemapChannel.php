<?php
/**
 * What the Bing, Yandex and Baidu channels share: one search sitemap.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\SearchSitemap;

use Estasoft\NewsDistribution\Channels\AbstractChannel;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Bing, Yandex and Baidu read the same plain XML sitemap, at `/feed/search-sitemap/?lang=xx`:
 * the latest 1,000 articles of one language, each with an accurate last-modified
 * time. The channels build it with `SearchSitemapBuilder`.
 *
 * Which channel answers when several are on: Bing does, whenever it is on. Yandex
 * answers only when Bing is off, and Baidu only when both are off. So exactly one
 * switched-on channel answers an address and the fetch log names that channel. The log also names the crawler,
 * so each screen reads its own platform's fetches by crawler name
 * (`FetchSummary`), whichever channel answered.
 *
 * Steps in the platform's own tools are ticked by the person. Nothing here
 * reads Bing Webmaster Tools, Yandex Webmaster or Baidu Webmaster Tools.
 */
abstract class AbstractSearchSitemapChannel extends AbstractChannel {

	/**
	 * The feed address both channels share.
	 */
	public const SLUG = 'search-sitemap';

	/**
	 * Channel ids that can answer the shared address.
	 *
	 * @var string[]
	 */
	public const ANSWERING = array( 'bing', 'yandex', 'baidu' );

	public function group(): string {
		return Group::SITEMAP;
	}

	public function mode(): string {
		return self::MODE_PULLED;
	}

	public function feed_slug(): string {
		return self::SLUG;
	}

	public function feed_window(): array {
		return array(
			'max_age' => 30 * YEAR_IN_SECONDS,
			'limit'   => SearchSitemapBuilder::MAX_URLS,
		);
	}

	/**
	 * The settings a person ticks and picks on the channel page. The dates are
	 * the day a step was ticked; the page fills them in.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'verified'     => array(
				'type'    => 'bool',
				'label'   => __( 'I verified my site', 'estasoft-news-distribution' ),
				'default' => false,
			),
			'verified_on'  => array(
				'type'    => 'string',
				'format'  => 'key',
				'label'   => __( 'Date the site was verified', 'estasoft-news-distribution' ),
				'default' => '',
				'max'     => 10,
			),
			'submitted'    => array(
				'type'    => 'bool',
				'label'   => __( 'I submitted the sitemaps', 'estasoft-news-distribution' ),
				'default' => false,
			),
			'submitted_on' => array(
				'type'    => 'string',
				'format'  => 'key',
				'label'   => __( 'Date the sitemaps were submitted', 'estasoft-news-distribution' ),
				'default' => '',
				'max'     => 10,
			),
			'robots'       => array(
				'type'    => 'bool',
				'label'   => __( 'Also list the sitemaps in robots.txt', 'estasoft-news-distribution' ),
				'default' => false,
			),
			'source'       => array(
				'type'    => 'enum',
				'label'   => __( 'Sitemap to submit', 'estasoft-news-distribution' ),
				'default' => 'search',
				'options' => array(
					'search'   => __( 'News Distribution search sitemap (latest 1,000 articles)', 'estasoft-news-distribution' ),
					'existing' => __( 'A sitemap I already have (WordPress or an SEO plugin)', 'estasoft-news-distribution' ),
				),
				'help'    => __( 'Keep your full sitemap from WordPress or an SEO plugin for older articles. Both can be submitted.', 'estasoft-news-distribution' ),
			),
			'existing_url' => array(
				'type'    => 'string',
				'format'  => 'url',
				'label'   => __( 'Address of your own sitemap', 'estasoft-news-distribution' ),
				'default' => '',
				'max'     => 500,
				'width'   => 420,
				'show_if' => array( 'source' => 'existing' ),
			),
		);
	}

	/**
	 * Builds the search sitemap for one language.
	 *
	 * @param \Estasoft\NewsDistribution\Core\Article[] $articles Candidate articles.
	 * @param string                                    $language Language code of this sitemap.
	 * @param int|null                                  $now      Unix time to treat as now.
	 * @return string
	 */
	public function build( array $articles, string $language, ?int $now = null ): string {
		return SearchSitemapBuilder::build( $articles, $language, null === $now ? Clock::now() : $now, SearchSitemapBuilder::URL_MAX_CHARS );
	}

	/**
	 * The plugin's own check of a built search sitemap, in plain words.
	 *
	 * @param string $body     The document.
	 * @param string $language Language code.
	 * @return array{valid: bool, entries: int, problems: string[]}
	 */
	public function check_output( string $body, string $language ): array {
		unset( $language );

		return SearchSitemapBuilder::check(
			$body,
			array(
				'max_urls'      => $this->max_urls(),
				'url_max_chars' => SearchSitemapBuilder::URL_MAX_CHARS,
			),
			home_url( '/' )
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
	 * Most URLs in one file, from the rule pack.
	 *
	 * @return int
	 */
	public function max_urls(): int {
		return max( 1, (int) $this->context->rules->limit( $this->id(), 'max_urls', SearchSitemapBuilder::MAX_URLS ) );
	}

	/**
	 * Crawler names that count as this platform fetching the sitemap, from the rule pack.
	 *
	 * @return string[]
	 */
	public function fetch_agents(): array {
		$agents = (array) ( $this->context->rules->pack( $this->id() )['fetch']['agents'] ?? array() );

		return array_values( array_filter( array_map( 'strval', $agents ) ) );
	}

	/**
	 * How this platform's fetches look per language, read by crawler name across
	 * both channels that can answer the address.
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
			self::ANSWERING,
			$this->fetch_agents(),
			$languages,
			$this->context->rules->fetch_interval_hours( $this->id() )
		);
	}

	/**
	 * Processing until the first fetch, then Needs attention if a language was
	 * never fetched or is later than usual, else Healthy.
	 *
	 * @return string A Status code.
	 */
	public function health(): string {
		if ( ! $this->is_enabled() ) {
			return Status::NOT_SET_UP;
		}

		$codes = array_column( $this->fetch_report(), 'status' );

		if ( array() === $codes || array( Status::NEVER ) === array_values( array_unique( $codes ) ) ) {
			$since = $this->waiting_since();

			return ( 0 === $since || Clock::now() - $since < $this->first_fetch_days() * DAY_IN_SECONDS ) ? Status::PROCESSING : Status::ATTENTION;
		}

		foreach ( $codes as $code ) {
			if ( in_array( $code, array( Status::LATE, Status::NEVER ), true ) ) {
				return Status::ATTENTION;
			}
		}

		return Status::HEALTHY;
	}

	/**
	 * When the platform started to wait for its first fetch: the day the person
	 * ticked "I submitted the sitemaps", else the day the channel was turned on.
	 *
	 * @return int Unix time, or 0 when unknown.
	 */
	public function waiting_since(): int {
		$settings = $this->settings();
		$since    = (int) ( $settings['enabled_since'] ?? 0 );

		if ( ! empty( $settings['submitted'] ) ) {
			$on    = self::date_to_ts( (string) ( $settings['submitted_on'] ?? '' ) );
			$since = $on > 0 ? $on : $since;
		}

		return $since;
	}

	/**
	 * Days the platform may take for its first fetch before the channel needs attention.
	 *
	 * @return int
	 */
	public function first_fetch_days(): int {
		return 2;
	}

	/**
	 * The addresses of this site's search sitemaps, one per language the channel carries.
	 *
	 * @return array<string, string> Language code => sitemap address.
	 */
	public function sitemap_urls(): array {
		$urls = array();

		foreach ( $this->context->languages->languages() as $language ) {
			if ( $this->accepts_language( (string) $language ) ) {
				$urls[ (string) $language ] = SearchSitemapBuilder::url( (string) $language );
			}
		}

		return $urls;
	}

	/**
	 * Whether the person chose a sitemap they already have, one this plugin does not serve.
	 *
	 * @return bool
	 */
	public function uses_existing_sitemap(): bool {
		return 'existing' === ( $this->settings()['source'] ?? 'search' );
	}

	/**
	 * The addresses to list in robots.txt for this channel, or none when the box is not ticked.
	 *
	 * @return string[]
	 */
	public function robots_urls(): array {
		$settings = $this->settings();

		if ( empty( $settings['robots'] ) ) {
			return array();
		}

		if ( $this->uses_existing_sitemap() ) {
			$url = trim( (string) ( $settings['existing_url'] ?? '' ) );

			return '' === $url ? array() : array( $url );
		}

		return array_values( $this->sitemap_urls() );
	}

	/**
	 * Turns a stored day (YYYY-MM-DD) into a Unix time, or 0.
	 *
	 * @param string $date The stored day.
	 * @return int
	 */
	public static function date_to_ts( string $date ): int {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return 0;
		}

		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, Clock::zone() );

		return false === $parsed ? 0 : $parsed->getTimestamp();
	}

	protected function fetch_states(): array {
		return $this->fetch_report();
	}

	protected function hub_summary(): string {
		return __( 'One plain sitemap per language, with your latest 1,000 articles.', 'estasoft-news-distribution' );
	}
}

<?php
/**
 * Bing Webmaster Tools.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations\Connections;

use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Submits the site's search sitemap to Bing and reads the list of sitemaps Bing
 * has for the site, through the Bing Webmaster API with an API key the person
 * makes in Bing Webmaster Tools (Settings, API access). The plugin has no
 * registered Microsoft app, so it offers no "sign in" button.
 *
 * Read in Microsoft's reference on 22 Oct 2026: `POST
 * https://ssl.bing.com/webmaster/api.svc/json/SubmitFeed?apikey=...` with
 * `{"siteUrl", "feedUrl"}` (answers 200, `{"d": null}`), and `GetFeeds` with
 * the fields `Url`, `Type`, `Status`, `LastCrawled`, `Submitted`, `Compressed`,
 * `FileSize` and `UrlCount` of each feed. Not confirmed: the values the
 * `Status` field takes, and the exact query form of `GetFeeds`. Bing's own
 * `Status` text is shown as it comes; this maps it only as far as a word for an
 * error, a feed Bing has crawled, and a feed it has not crawled yet.
 */
final class BingWebmaster extends AbstractConnection {

	private const API = 'https://ssl.bing.com/webmaster/api.svc/json/';

	public function id(): string {
		return 'bing-webmaster-tools';
	}

	public function label(): string {
		return 'Bing Webmaster Tools';
	}

	public function secret_field(): string {
		return 'bing_key';
	}

	public function plain_fields(): array {
		return array( 'bing_site_url' );
	}

	public function channel_ids(): array {
		return array( 'bing' );
	}

	public function verify( string $credential, array $settings ): array {
		$answer = $this->feeds( $credential, $this->site_url( $settings ) );

		if ( 200 !== $answer['code'] || ! isset( $answer['json']['d'] ) || ! is_array( $answer['json']['d'] ) ) {
			return array(
				'ok'      => false,
				'message' => $this->failure( $answer, __( 'Bing did not accept the API key. Make a new key in Bing Webmaster Tools, under Settings, API access, and paste it in again.', 'estasoft-news-distribution' ) ),
				'account' => '',
				'context' => array(),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( 'Bing accepted the API key.', 'estasoft-news-distribution' ),
			'account' => '',
			'context' => array(),
		);
	}

	public function sync( string $credential, array $settings, array $context, array $sitemaps ): array {
		$site   = $this->site_url( $settings );
		$denied = __( 'Bing did not accept the API key. Make a new key in Bing Webmaster Tools, under Settings, API access, and paste it in again.', 'estasoft-news-distribution' );
		$list   = $this->feeds( $credential, $site );

		if ( 200 !== $list['code'] ) {
			return $this->stopped( $this->failure( $list, $denied ) );
		}

		$known = $this->index( $list );

		foreach ( $sitemaps as $sitemap ) {
			if ( isset( $known[ $sitemap['url'] ] ) ) {
				continue;
			}

			$post = $this->request(
				'POST',
				self::API . 'SubmitFeed?apikey=' . rawurlencode( $credential ),
				array( 'Content-Type' => 'application/json; charset=utf-8' ),
				wp_json_encode(
					array(
						'siteUrl' => $site,
						'feedUrl' => $sitemap['url'],
					)
				)
			);

			if ( 200 !== $post['code'] ) {
				return $this->stopped( $this->failure( $post, $denied ) );
			}
		}

		$list = $this->feeds( $credential, $site );

		if ( 200 !== $list['code'] ) {
			return $this->stopped( $this->failure( $list, $denied ) );
		}

		$known = $this->index( $list );
		$rows  = array();

		foreach ( $sitemaps as $sitemap ) {
			$rows[] = $this->describe( $sitemap, $known[ $sitemap['url'] ] ?? null );
		}

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: %d: number of sitemaps. */
				_n( '%d sitemap read', '%d sitemaps read', count( $rows ), 'estasoft-news-distribution' ),
				count( $rows )
			),
			'rows'    => $rows,
		);
	}

	/**
	 * Asks Bing for the list of feeds of a site.
	 *
	 * @param string $key  The API key.
	 * @param string $site The site's address.
	 * @return array{code: int, json: array<mixed>|null, raw: string, error: string}
	 */
	private function feeds( string $key, string $site ): array {
		return $this->request( 'GET', self::API . 'GetFeeds?apikey=' . rawurlencode( $key ) . '&siteUrl=' . rawurlencode( $site ) );
	}

	/**
	 * The site address Bing knows: the saved one, or this site's home address.
	 *
	 * @param array<string, mixed> $settings The plain fields.
	 * @return string
	 */
	private function site_url( array $settings ): string {
		$url = trim( (string) ( $settings['bing_site_url'] ?? '' ) );

		return '' !== $url ? $url : trailingslashit( home_url() );
	}

	/**
	 * The feeds Bing lists, by address.
	 *
	 * @param array{code: int, json: array<mixed>|null, raw: string, error: string} $answer The list answer.
	 * @return array<string, array<string, mixed>>
	 */
	private function index( array $answer ): array {
		$by_url = array();

		foreach ( (array) ( $answer['json']['d'] ?? array() ) as $item ) {
			if ( is_array( $item ) && isset( $item['Url'] ) ) {
				$by_url[ (string) $item['Url'] ] = $item;
			}
		}

		return $by_url;
	}

	/**
	 * A check that stopped.
	 *
	 * @param string $message What went wrong.
	 * @return array{ok: bool, message: string, rows: array<int, array<string, string>>}
	 */
	private function stopped( string $message ): array {
		return array(
			'ok'      => false,
			'message' => $message,
			'rows'    => array(),
		);
	}

	/**
	 * Reads a date the way Bing's service writes it: "/Date(1729600000000)/".
	 *
	 * @param mixed $value The field's value.
	 * @return int Unix time, or 0 when there is none (Bing writes the year 1 for "never").
	 */
	private function date( $value ): int {
		if ( is_string( $value ) && preg_match( '#/Date\((-?\d+)#', $value, $match ) ) {
			return max( 0, (int) floor( (int) $match[1] / 1000 ) );
		}

		$time = is_string( $value ) ? strtotime( $value ) : false;

		return false === $time ? 0 : max( 0, (int) $time );
	}

	/**
	 * One row, from what Bing lists for the sitemap.
	 *
	 * @param array{label: string, url: string} $sitemap The sitemap.
	 * @param array<string, mixed>|null         $item    Bing's record of it.
	 * @return array{label: string, url: string, says: string, status: string, link: string}
	 */
	private function describe( array $sitemap, ?array $item ): array {
		if ( null === $item ) {
			return $this->row( $sitemap, __( 'Bing does not list it yet', 'estasoft-news-distribution' ), Status::PROCESSING );
		}

		$word    = trim( wp_strip_all_tags( (string) ( $item['Status'] ?? '' ) ) );
		$crawled = $this->date( $item['LastCrawled'] ?? '' );
		$says    = '' !== $word ? $word : __( 'Listed', 'estasoft-news-distribution' );

		if ( $crawled > 0 ) {
			$says = sprintf(
				/* translators: 1: Bing's own status text, 2: a date such as "14 Oct". */
				__( '%1$s, crawled %2$s', 'estasoft-news-distribution' ),
				$says,
				$this->day( $crawled )
			);
		}

		if ( 1 === preg_match( '/error|fail|invalid|denied|blocked/i', $word ) ) {
			return $this->row( $sitemap, $says, Status::ATTENTION, 'https://www.bing.com/webmasters/sitemaps' );
		}

		return $this->row( $sitemap, $says, $crawled > 0 ? Status::HEALTHY : Status::PROCESSING );
	}
}

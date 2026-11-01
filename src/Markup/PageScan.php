<?php
/**
 * Looks at a few recent article pages to see which tags they carry.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Markup;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Integrations\SiteFetch;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Runs only when a person presses "Check the pages" on the Page markup screen.
 * It reads the site's own article pages, one request each, and nothing else: no
 * other server is contacted, nothing is sent, and nothing runs when an article is
 * published or when the screen is opened. The answer is kept for 30 minutes.
 *
 * Shape of a result:
 *
 *     array(
 *       'ran_at' => int,    // Unix time
 *       'pages'  => int,    // pages that answered
 *       'failed' => int,    // pages that did not answer with a 200
 *       'tags'   => array<string, array{other: int, ours: int, nothing: int, missing: int}>,
 *                           // by tag id: pages where another plugin or the theme prints it,
 *                           // where News Distribution printed it, where the article has no value
 *                           // for it, and where it is missing although there was a value
 *       'jsonld' => array{other: int, ours: int, none: int},
 *                           // pages with article data from another source, with ours, and with neither
 *     )
 */
final class PageScan {

	/**
	 * The cache key. It follows the same naming as the other checks on the Environment screen.
	 */
	public const KEY = 'estasoft_nd_probe_page_tags';

	/**
	 * How long a result is kept, in seconds.
	 */
	public const TTL = 1800;

	/**
	 * The most pages looked at.
	 */
	public const MAX_PAGES = 10;

	/**
	 * The most seconds spent in total.
	 */
	public const BUDGET = 40;

	private Plugin $plugin;

	/**
	 * Creates the scan.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The last result, or null when nobody has run it in the last 30 minutes.
	 *
	 * @return array<string, mixed>|null
	 */
	public function last(): ?array {
		$stored = get_transient( self::KEY );

		return is_array( $stored ) && isset( $stored['pages'], $stored['tags'] ) ? $stored : null;
	}

	/**
	 * Forgets the last result.
	 *
	 * @return void
	 */
	public function forget(): void {
		delete_transient( self::KEY );
	}

	/**
	 * Reads the recent article pages and keeps the answer.
	 *
	 * @return array<string, mixed> See the class description. `pages` is 0 when no article could be read.
	 */
	public function run(): array {
		$tags = new PageTags( $this->plugin );
		$ids  = array_keys( PageTags::definitions() );
		$out  = array(
			'ran_at' => Clock::now(),
			'pages'  => 0,
			'failed' => 0,
			'tags'   => array(),
			'jsonld' => array(
				'other' => 0,
				'ours'  => 0,
				'none'  => 0,
			),
		);

		foreach ( $ids as $id ) {
			$out['tags'][ $id ] = array(
				'other'   => 0,
				'ours'    => 0,
				'nothing' => 0,
				'missing' => 0,
			);
		}

		$started = microtime( true );

		foreach ( $this->articles() as $article ) {
			if ( microtime( true ) - $started > self::BUDGET ) {
				break;
			}

			$page = SiteFetch::get( $article->permalink(), 6 );

			if ( ! $page['ok'] || 200 !== $page['status'] ) {
				++$out['failed'];
				continue;
			}

			++$out['pages'];

			$parsed = $tags->parse( $page['body'] );

			foreach ( $ids as $id ) {
				++$out['tags'][ $id ][ $this->state( $id, $parsed, $tags, $article ) ];
			}

			if ( $parsed['ours_jsonld'] ) {
				++$out['jsonld']['ours'];
			} elseif ( $parsed['article_data'] ) {
				++$out['jsonld']['other'];
			} else {
				++$out['jsonld']['none'];
			}
		}

		set_transient( self::KEY, $out, self::TTL );

		return $out;
	}

	/**
	 * How one tag stands on one page.
	 *
	 * @param string               $id      The tag id.
	 * @param array<string, mixed> $parsed  The page, from PageTags::parse().
	 * @param PageTags             $tags    The tags helper.
	 * @param Article              $article The article.
	 * @return string other, ours, nothing or missing.
	 */
	private function state( string $id, array $parsed, PageTags $tags, Article $article ): string {
		if ( ! empty( $parsed['present'][ $id ] ) ) {
			return ! empty( $parsed['ours'][ $id ] ) ? 'ours' : 'other';
		}

		return $tags->nothing_to_add( $id, $article ) ? 'nothing' : 'missing';
	}

	/**
	 * The newest published articles of the configured post types, across languages.
	 *
	 * @return Article[]
	 */
	private function articles(): array {
		$found = array();
		$seen  = array();

		foreach ( $this->plugin->languages()->languages() as $language ) {
			foreach ( $this->plugin->reader()->recent( (string) $language, 30 * DAY_IN_SECONDS, self::MAX_PAGES ) as $article ) {
				if ( $article instanceof Article && ! isset( $seen[ $article->id() ] ) && '' !== $article->permalink() ) {
					$seen[ $article->id() ] = true;
					$found[]                = $article;
				}
			}
		}

		usort(
			$found,
			static function ( Article $a, Article $b ): int {
				return $b->published_ts() <=> $a->published_ts();
			}
		);

		return array_slice( $found, 0, self::MAX_PAGES );
	}
}

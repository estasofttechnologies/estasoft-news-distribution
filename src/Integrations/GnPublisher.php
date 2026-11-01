<?php
/**
 * The switch from GN Publisher: look, compare, answer at the old address, check.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Frontend\FeedController;
use Estasoft\NewsDistribution\Frontend\Response;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Every step that reads the other plugin reads what it prints on this site: its
 * sitemaps and feeds, as any visitor sees them. Nothing here reads or changes the
 * other plugin's settings or data.
 *
 * Steps that make requests (`scan`, `compare`, `check`) run only when a person
 * presses a button. Their results are kept in transients named
 * `estasoft_nd_probe_*` so the screen can show when they were last run.
 *
 * Shape of `scan()`:
 *
 *     array(
 *       'ran_at'    => int,
 *       'reachable' => bool,   // false when the site's own address could not be read
 *       'items'     => array( array(
 *         'type'   => string,  // 'news_sitemap' or 'news_feed'
 *         'url'    => string,
 *         'path'   => string,  // the address without the site's host
 *         'status' => int,
 *         'ours'   => bool,    // true when News Distribution answered
 *         'count'  => int,     // articles listed
 *       ) ),
 *       'errors'    => string[],
 *     )
 *
 * Shape of `check()`:
 *
 *     array(
 *       'result'  => string,   // 'ok', 'other_still', 'not_answered' or 'unreachable'
 *       'ran_at'  => int,
 *       'message' => string,
 *       'old'     => array( 'url' => string, 'status' => int, 'answered_by' => string ), // us, other or nobody
 *       'new'     => array( 'url' => string, 'status' => int, 'ok' => bool ),
 *     )
 */
final class GnPublisher {

	public const PLUGIN_ID   = 'gn-publisher';
	public const SCAN_KEY    = 'estasoft_nd_probe_news_scan';
	public const CHECK_KEY   = 'estasoft_nd_probe_gn_check';
	public const COMPARE_KEY = 'estasoft_nd_probe_gn_compare';
	public const TTL         = 1800;
	public const HEADER      = 'X-Estasoft-ND-Answer';
	public const WINDOW      = 172800;

	/**
	 * The usual addresses of a news sitemap, looked at besides what robots.txt lists.
	 */
	private const USUAL_ADDRESSES = array( '/news-sitemap.xml', '/sitemap-news.xml' );

	/**
	 * The feeds WordPress itself provides. Any other registered feed came from a plugin.
	 */
	private const CORE_FEEDS = array( 'feed', 'rdf', 'rss', 'rss2', 'atom' );

	private Plugin $plugin;

	/**
	 * Creates the helper.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The GN Publisher plugin, when it is active.
	 *
	 * @return array<string, string>|null
	 */
	public function plugin(): ?array {
		return KnownPlugins::find( self::PLUGIN_ID );
	}

	/**
	 * Where the other plugin's own settings are, as its plugin links say.
	 *
	 * Reads the links the plugin shows in the Plugins list. It changes nothing.
	 *
	 * @return string An admin address, or the Plugins list when the plugin shows no link.
	 */
	public function settings_url(): string {
		$found = $this->plugin();

		if ( null !== $found && '' !== $found['file'] ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own per-plugin filter, read to find the plugin's settings link.
			$links = apply_filters( 'plugin_action_links_' . $found['file'], array() );

			foreach ( is_array( $links ) ? $links : array() as $link ) {
				if ( preg_match( '/href=(["\'])(.*?)\1/i', (string) $link, $match ) ) {
					$url = html_entity_decode( $match[2], ENT_QUOTES );

					if ( 0 === strpos( $url, admin_url() ) ) {
						return $url;
					}

					if ( 0 !== strpos( $url, 'http' ) && '#' !== substr( $url, 0, 1 ) ) {
						return admin_url( ltrim( $url, '/' ) );
					}
				}
			}
		}

		return admin_url( 'plugins.php' );
	}

	/**
	 * The path the owner chose to answer at, or an empty string.
	 *
	 * @return string A path such as /news-sitemap.xml.
	 */
	public function old_path(): string {
		return (string) $this->plugin->settings()->get( 'compatibility.old_sitemap_path', '' );
	}

	/**
	 * Whether the old address is answered by News Distribution.
	 *
	 * @return bool
	 */
	public function answers_old_address(): bool {
		return (bool) $this->plugin->settings()->get( 'compatibility.answer_old_address', false ) && '' !== $this->old_path();
	}

	/**
	 * The address of the news sitemap News Distribution builds.
	 *
	 * @return string Empty when the Google News channel does not exist.
	 */
	public function new_sitemap_url(): string {
		$channel = $this->plugin->registry()->get( 'google-news' );

		return null === $channel ? '' : home_url( '/feed/' . $channel->feed_slug() . '/' );
	}

	/**
	 * Looks at the news sitemaps and feeds the site prints. Makes requests to this site only.
	 *
	 * @return array<string, mixed> See the class description. Stored for half an hour.
	 */
	public function scan(): array {
		global $wp_rewrite;

		$items     = array();
		$errors    = array();
		$reachable = true;
		$budget    = 10;
		$seen      = array();

		$robots = SiteFetch::get( home_url( '/robots.txt' ) );

		if ( ! $robots['ok'] ) {
			$reachable = false;
			$errors[]  = $robots['error'];
		}

		$candidates = array();

		if ( $robots['ok'] && $robots['status'] >= 200 && $robots['status'] < 300 ) {
			foreach ( SiteFetch::parse_robots( $robots['body'] )['sitemaps'] as $address ) {
				if ( SiteFetch::is_own( $address ) ) {
					$candidates[] = $address;
				}
			}
		}

		foreach ( self::USUAL_ADDRESSES as $path ) {
			$candidates[] = home_url( $path );
		}

		$candidates = array_values( array_unique( $candidates ) );

		while ( $reachable && array() !== $candidates && $budget > 0 ) {
			$url = (string) array_shift( $candidates );

			if ( isset( $seen[ $url ] ) ) {
				continue;
			}

			$seen[ $url ] = true;
			--$budget;

			$reply = SiteFetch::get( $url );

			if ( ! $reply['ok'] ) {
				$errors[] = $reply['error'];
				continue;
			}

			if ( 200 !== $reply['status'] ) {
				continue;
			}

			// A sitemap index lists other sitemaps. Follow the ones that look like news sitemaps.
			if ( false !== strpos( $reply['body'], '<sitemapindex' ) ) {
				foreach ( $this->locations( $reply['body'] ) as $child ) {
					if ( false !== stripos( $child, 'news' ) && SiteFetch::is_own( $child ) ) {
						$candidates[] = $child;
					}
				}
				continue;
			}

			if ( $this->is_news_sitemap( $reply['body'] ) ) {
				$items[] = array(
					'type'   => 'news_sitemap',
					'url'    => $url,
					'path'   => $this->path_of( $url ),
					'status' => $reply['status'],
					'ours'   => isset( $reply['headers'][ strtolower( self::HEADER ) ] ),
					'count'  => substr_count( $reply['body'], '<url>' ) + substr_count( $reply['body'], '<url ' ),
				);
			}
		}

		// Feeds that a plugin added to WordPress. The ones WordPress has by itself are left out.
		$feed_names = is_object( $wp_rewrite ) && is_array( $wp_rewrite->feeds ) ? array_diff( array_map( 'strval', $wp_rewrite->feeds ), self::CORE_FEEDS ) : array();

		foreach ( array_slice( array_values( $feed_names ), 0, 4 ) as $name ) {
			if ( ! $reachable ) {
				break;
			}

			$url   = (string) get_feed_link( $name );
			$reply = SiteFetch::get( $url );

			if ( ! $reply['ok'] ) {
				$errors[] = $reply['error'];
				continue;
			}

			if ( 200 === $reply['status'] && ( false !== strpos( $reply['body'], '<rss' ) || false !== strpos( $reply['body'], '<feed' ) ) ) {
				$items[] = array(
					'type'   => 'news_feed',
					'url'    => $url,
					'path'   => $this->path_of( $url ),
					'status' => $reply['status'],
					'ours'   => isset( $reply['headers'][ strtolower( self::HEADER ) ] ),
					'count'  => substr_count( $reply['body'], '<item' ) + substr_count( $reply['body'], '<entry' ),
				);
			}
		}

		$result = array(
			'ran_at'    => Clock::now(),
			'reachable' => $reachable,
			'items'     => $items,
			'errors'    => array_values( array_unique( array_filter( $errors ) ) ),
		);

		set_transient( self::SCAN_KEY, $result, self::TTL );

		return $result;
	}

	/**
	 * The last scan, while it is still kept.
	 *
	 * @return array<string, mixed>|null
	 */
	public function last_scan(): ?array {
		$stored = get_transient( self::SCAN_KEY );

		return is_array( $stored ) && isset( $stored['items'] ) ? $stored : null;
	}

	/**
	 * The news sitemaps and feeds that News Distribution did not print.
	 *
	 * @param array<string, mixed>|null $scan A scan, or null for the last one.
	 * @return array<int, array<string, mixed>>
	 */
	public function others( ?array $scan = null ): array {
		$scan = null === $scan ? $this->last_scan() : $scan;

		if ( null === $scan ) {
			return array();
		}

		return array_values(
			array_filter(
				(array) $scan['items'],
				static function ( array $item ): bool {
					return empty( $item['ours'] );
				}
			)
		);
	}

	/**
	 * Compares what the other plugin lists with what News Distribution would list.
	 *
	 * Reads the other plugin's address once and keeps the answer for half an hour (see `last_compare()`). Shape: `available`, `reason` ('', 'no_scan',
	 * 'no_other', 'channel_off' or 'fetch_failed'), `other_url`, `window_hours`, `total`,
	 * `same`, `only_ours`, `only_other`, and `rows` (title, url, other, ours, difference),
	 * differences first.
	 *
	 * @param array<string, mixed>|null $scan A scan, or null for the last one.
	 * @return array<string, mixed>
	 */
	public function compare( ?array $scan = null ): array {
		$base = array(
			'available'    => false,
			'reason'       => '',
			'other_url'    => '',
			'window_hours' => (int) ( self::WINDOW / HOUR_IN_SECONDS ),
			'total'        => 0,
			'same'         => 0,
			'only_ours'    => 0,
			'only_other'   => 0,
			'rows'         => array(),
		);

		$scan = null === $scan ? $this->last_scan() : $scan;

		if ( null === $scan ) {
			return array_merge( $base, array( 'reason' => 'no_scan' ) );
		}

		$others = $this->others( $scan );

		if ( array() === $others ) {
			return array_merge( $base, array( 'reason' => 'no_other' ) );
		}

		$other   = $others[0];
		$channel = $this->plugin->registry()->get( 'google-news' );

		if ( null === $channel || ! $channel->is_enabled() ) {
			return array_merge(
				$base,
				array(
					'reason'    => 'channel_off',
					'other_url' => (string) $other['url'],
				)
			);
		}

		$reply = SiteFetch::get( (string) $other['url'] );

		if ( ! $reply['ok'] || 200 !== $reply['status'] ) {
			return $this->keep_compare(
				array_merge(
					$base,
					array(
						'reason'    => 'fetch_failed',
						'other_url' => (string) $other['url'],
					)
				)
			);
		}

		$theirs = array();

		foreach ( $this->listed_addresses( $reply['body'] ) as $address ) {
			$theirs[ $this->normalise( $address ) ] = $address;
		}

		$ours = array();

		foreach ( array_map( 'strval', $this->plugin->languages()->languages() ) as $language ) {
			if ( ! $channel->accepts_language( $language ) ) {
				continue;
			}

			foreach ( $this->plugin->reader()->recent( $language, self::WINDOW, 1000 ) as $article ) {
				$ours[ $this->normalise( $article->permalink() ) ] = array(
					'title' => $article->title(),
					'url'   => $article->permalink(),
				);
			}
		}

		$rows = array();

		foreach ( $ours as $key => $article ) {
			$rows[] = array(
				'title'      => $article['title'],
				'url'        => $article['url'],
				'other'      => isset( $theirs[ $key ] ),
				'ours'       => true,
				'difference' => isset( $theirs[ $key ] ) ? 'same' : 'only_ours',
			);
		}

		foreach ( $theirs as $key => $address ) {
			if ( isset( $ours[ $key ] ) ) {
				continue;
			}

			$post_id = url_to_postid( $address );
			$post    = $post_id > 0 ? get_post( $post_id ) : null;

			// Something the other plugin lists that is older than the news window is not a difference.
			if ( $post instanceof \WP_Post && Clock::from_gmt( (string) $post->post_date_gmt ) < Clock::now() - self::WINDOW ) {
				continue;
			}

			$rows[] = array(
				'title'      => $post instanceof \WP_Post ? wp_strip_all_tags( (string) $post->post_title ) : $address,
				'url'        => $address,
				'other'      => true,
				'ours'       => false,
				'difference' => 'only_other',
			);
		}

		$order = array(
			'only_other' => 0,
			'only_ours'  => 1,
			'same'       => 2,
		);

		usort(
			$rows,
			static function ( array $a, array $b ) use ( $order ): int {
				return $order[ $a['difference'] ] <=> $order[ $b['difference'] ];
			}
		);

		$count = static function ( string $difference ) use ( $rows ): int {
			return count(
				array_filter(
					$rows,
					static function ( array $row ) use ( $difference ): bool {
						return $difference === $row['difference'];
					}
				)
			);
		};

		return $this->keep_compare(
			array_merge(
				$base,
				array(
					'available'  => true,
					'other_url'  => (string) $other['url'],
					'total'      => count( $rows ),
					'same'       => $count( 'same' ),
					'only_ours'  => $count( 'only_ours' ),
					'only_other' => $count( 'only_other' ),
					'rows'       => $rows,
				)
			)
		);
	}

	/**
	 * The last comparison that read the other plugin's address, while it is still kept.
	 *
	 * @return array<string, mixed>|null Shape of compare(), plus `ran_at`.
	 */
	public function last_compare(): ?array {
		$stored = get_transient( self::COMPARE_KEY );

		return is_array( $stored ) && isset( $stored['rows'], $stored['ran_at'] ) ? $stored : null;
	}

	/**
	 * Keeps a comparison that made a request, so the screen can show it and when it ran.
	 *
	 * @param array<string, mixed> $result The comparison.
	 * @return array<string, mixed>
	 */
	private function keep_compare( array $result ): array {
		$result['ran_at'] = Clock::now();

		set_transient( self::COMPARE_KEY, $result, self::TTL );

		return $result;
	}

	/**
	 * Fetches the old address and the new sitemap, and says who answers.
	 *
	 * @return array<string, mixed> See the class description. Stored for half an hour.
	 */
	public function check(): array {
		$old_path = $this->old_path();

		if ( '' === $old_path ) {
			$others   = $this->others();
			$old_path = array() !== $others ? (string) $others[0]['path'] : self::USUAL_ADDRESSES[0];
		}

		$old_url = home_url( $old_path );
		$new_url = $this->new_sitemap_url();
		$old     = SiteFetch::get( $old_url );
		$fresh   = '' === $new_url ? array(
			'ok'      => true,
			'status'  => 0,
			'body'    => '',
			'headers' => array(),
			'error'   => '',
		) : SiteFetch::get( $new_url );

		if ( ! $old['ok'] || ! $fresh['ok'] ) {
			return $this->keep_check(
				'unreachable',
				__( 'Nothing was changed. Try again in a few minutes.', 'estasoft-news-distribution' ),
				$old_url,
				$old,
				'nobody',
				$new_url,
				$fresh
			);
		}

		$ours_old   = isset( $old['headers'][ strtolower( self::HEADER ) ] );
		$is_sitemap = 200 === $old['status'] && $this->is_news_sitemap( $old['body'] );
		$answered   = $ours_old ? 'us' : ( $is_sitemap ? 'other' : 'nobody' );
		$new_ok     = 200 === $fresh['status'] && false !== strpos( $fresh['body'], '<urlset' );

		if ( 'other' === $answered ) {
			return $this->keep_check(
				'other_still',
				__( 'Turn it off in step 3 and run the check again.', 'estasoft-news-distribution' ),
				$old_url,
				$old,
				$answered,
				$new_url,
				$fresh
			);
		}

		if ( 'us' === $answered && $new_ok ) {
			return $this->keep_check(
				'ok',
				__( 'Only News Distribution answers at the old address and the new one.', 'estasoft-news-distribution' ),
				$old_url,
				$old,
				$answered,
				$new_url,
				$fresh
			);
		}

		$message = $new_ok
			? __( 'Nobody answers at the old address. Turn on “Also answer at the old address” in step 4, save it and run the check again.', 'estasoft-news-distribution' )
			: __( 'The new sitemap does not answer. Turn on the Google News channel and run the check again.', 'estasoft-news-distribution' );

		return $this->keep_check( 'not_answered', $message, $old_url, $old, $answered, $new_url, $fresh );
	}

	/**
	 * The last check, while it is still kept.
	 *
	 * @return array<string, mixed>|null
	 */
	public function last_check(): ?array {
		$stored = get_transient( self::CHECK_KEY );

		return is_array( $stored ) && isset( $stored['result'] ) ? $stored : null;
	}

	/**
	 * The sitemap News Distribution answers with at the old address, as a response.
	 *
	 * Used by the module that answers at the old address. It is the Google News
	 * channel's own sitemap for the default language: the same body, the same
	 * `noindex` header and the same short replies as at the new address, with one
	 * more header that says who answered. It is not a redirect.
	 *
	 * @param array<string, string> $headers The request's `if_none_match` and `if_modified_since`.
	 * @param string                $agent   The visitor's user agent, used by the fetch log.
	 * @return Response|null Null when the Google News channel does not exist.
	 */
	public function old_address_response( array $headers, string $agent ): ?Response {
		$channel = $this->plugin->registry()->get( 'google-news' );

		if ( null === $channel ) {
			return null;
		}

		$response                          = ( new FeedController( $this->plugin ) )->respond( $channel->feed_slug(), '', $headers, $agent );
		$response->headers[ self::HEADER ] = 'old-address';

		return $response;
	}

	/**
	 * Stores and returns a check result.
	 *
	 * @param string               $result  The result code.
	 * @param string               $message What it means.
	 * @param string               $old_url The old address.
	 * @param array<string, mixed> $old     The reply from the old address.
	 * @param string               $by      Who answered at the old address.
	 * @param string               $new_url The new sitemap's address.
	 * @param array<string, mixed> $fresh   The reply from the new address.
	 * @return array<string, mixed>
	 */
	private function keep_check( string $result, string $message, string $old_url, array $old, string $by, string $new_url, array $fresh ): array {
		$check = array(
			'result'  => $result,
			'ran_at'  => Clock::now(),
			'message' => $message,
			'old'     => array(
				'url'         => $old_url,
				'status'      => (int) $old['status'],
				'answered_by' => $by,
			),
			'new'     => array(
				'url'    => $new_url,
				'status' => (int) $fresh['status'],
				'ok'     => 200 === (int) $fresh['status'] && false !== strpos( (string) $fresh['body'], '<urlset' ),
			),
		);

		set_transient( self::CHECK_KEY, $check, self::TTL );

		return $check;
	}

	/**
	 * Whether a page is a Google News sitemap.
	 *
	 * @param string $body The page.
	 * @return bool
	 */
	private function is_news_sitemap( string $body ): bool {
		return false !== strpos( $body, '<urlset' ) && ( false !== strpos( $body, 'sitemap-news' ) || false !== strpos( $body, '<news:news' ) );
	}

	/**
	 * The addresses an XML sitemap or a feed lists.
	 *
	 * @param string $body The page.
	 * @return string[]
	 */
	private function listed_addresses( string $body ): array {
		if ( false !== strpos( $body, '<urlset' ) ) {
			return $this->locations( $body );
		}

		$found = array();

		if ( preg_match_all( '#<item\b.*?</item>#s', $body, $items ) ) {
			foreach ( $items[0] as $item ) {
				if ( preg_match( '#<link>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)#', $item, $match ) ) {
					$found[] = html_entity_decode( $match[1], ENT_QUOTES );
				}
			}
		}

		if ( preg_match_all( '#<entry\b.*?</entry>#s', $body, $entries ) ) {
			foreach ( $entries[0] as $entry ) {
				if ( preg_match( '#<link[^>]+href=["\']([^"\']+)["\']#', $entry, $match ) ) {
					$found[] = html_entity_decode( $match[1], ENT_QUOTES );
				}
			}
		}

		return $found;
	}

	/**
	 * The `<loc>` addresses of a sitemap or a sitemap index.
	 *
	 * @param string $body The XML.
	 * @return string[]
	 */
	private function locations( string $body ): array {
		if ( ! preg_match_all( '#<loc>\s*([^<]+?)\s*</loc>#', $body, $match ) ) {
			return array();
		}

		return array_map(
			static function ( string $address ): string {
				return html_entity_decode( $address, ENT_QUOTES );
			},
			$match[1]
		);
	}

	/**
	 * An address as the site's host would see it: lower-case host, no fragment, no trailing slash.
	 *
	 * A query string is dropped, except the arguments WordPress itself uses to find a post
	 * (`p`, `page_id`, `name`, `pagename`, `post_type`), because on a site without pretty
	 * permalinks those are the address.
	 *
	 * @param string $url An address.
	 * @return string
	 */
	private function normalise( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return $url;
		}

		$key = strtolower( (string) ( $parts['host'] ?? '' ) ) . rtrim( (string) ( $parts['path'] ?? '' ), '/' );

		if ( ! empty( $parts['query'] ) ) {
			$query = array();
			parse_str( (string) $parts['query'], $query );
			$query = array_intersect_key( $query, array_flip( array( 'p', 'page_id', 'name', 'pagename', 'post_type' ) ) );
			ksort( $query );

			if ( array() !== $query ) {
				$key .= '?' . http_build_query( $query );
			}
		}

		return $key;
	}

	/**
	 * The path of an address on this site.
	 *
	 * @param string $url An address.
	 * @return string
	 */
	private function path_of( string $url ): string {
		$parts = wp_parse_url( $url );
		$path  = is_array( $parts ) ? (string) ( $parts['path'] ?? '/' ) : '/';
		$query = is_array( $parts ) && isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$home  = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = '/' . ltrim( substr( $path, strlen( $home ) ), '/' );
		}

		return $path . $query;
	}
}

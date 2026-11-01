<?php
/**
 * The fixed list of sources a visit is counted under.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Referrals;

defined( 'ABSPATH' ) || exit;

/**
 * Turns "the site the reader came from" into one of a few source names. Only the
 * name is ever stored: never the address, never the full referrer.
 *
 * The reader's browser sends two short values, the host of `document.referrer` and the
 * `utm_source` of the page address. A known `utm_source` wins, because a link that carries
 * one was made on purpose; otherwise the host is matched against the lists below; no host
 * means "direct"; any other host means "other".
 *
 * The host lists are the plugin's own and were not checked against live traffic: a platform
 * can send readers through an address that is not listed (an app, a redirect service), and
 * those visits are then counted as "other" or "direct". The screen says every number is a floor.
 * A site that wants another host counted under a source adds it with the internal filter
 * `estasoft_nd_internal_referral_hosts` (source id => list of hosts).
 */
final class Sources {

	public const DIRECT       = 'direct';
	public const OTHER        = 'other';
	public const FEED_READERS = 'feed_readers';

	/**
	 * The longest host the route accepts, in characters.
	 */
	public const MAX_HOST = 100;

	/**
	 * The longest `utm_source` the route accepts, in characters.
	 */
	public const MAX_UTM = 40;

	/**
	 * Every source id, in the order a tie is shown. Direct and other come last.
	 *
	 * @var string[]
	 */
	private const IDS = array(
		'google_news',
		'google_search',
		'bing',
		'yandex',
		'baidu',
		'microsoft_start',
		'yahoo_news',
		'apple_news',
		'smartnews',
		'flipboard',
		'feed_readers',
		'facebook',
		'x',
		'reddit',
		'telegram',
		'mastodon',
		'bluesky',
		'other',
		'direct',
	);

	/**
	 * Hosts per source. A host matches when it is the listed one or a subdomain of it.
	 *
	 * @var array<string, string[]>
	 */
	private const DOMAINS = array(
		'google_news'     => array( 'news.google.com' ),
		'bing'            => array( 'bing.com' ),
		'yandex'          => array( 'ya.ru' ),
		'baidu'           => array( 'baidu.com' ),
		'microsoft_start' => array( 'start.microsoft.com', 'msn.com' ),
		'yahoo_news'      => array( 'news.yahoo.com', 'www.yahoo.com', 'yahoo.com' ),
		'apple_news'      => array( 'apple.news', 'news.apple.com' ),
		'smartnews'       => array( 'smartnews.com' ),
		'flipboard'       => array( 'flipboard.com', 'flip.it' ),
		'feed_readers'    => array( 'feedly.com', 'inoreader.com', 'newsblur.com' ),
		'facebook'        => array( 'facebook.com', 'fb.com', 'fb.me' ),
		'x'               => array( 'x.com', 'twitter.com', 't.co' ),
		'reddit'          => array( 'reddit.com', 'redd.it' ),
		'telegram'        => array( 't.me', 'telegram.me', 'telegram.org' ),
		'mastodon'        => array( 'mastodon.social', 'mastodon.online', 'mstdn.social', 'fosstodon.org', 'mas.to', 'infosec.exchange', 'hachyderm.io' ),
		'bluesky'         => array( 'bsky.app' ),
	);

	/**
	 * What a `utm_source` is called, with every dash, dot and underscore taken out, and the source it means.
	 *
	 * @var array<string, string>
	 */
	private const ALIASES = array(
		'googlenews'     => 'google_news',
		'google'         => 'google_search',
		'googlesearch'   => 'google_search',
		'bing'           => 'bing',
		'yandex'         => 'yandex',
		'baidu'          => 'baidu',
		'microsoftstart' => 'microsoft_start',
		'msn'            => 'microsoft_start',
		'yahoonews'      => 'yahoo_news',
		'yahoo'          => 'yahoo_news',
		'applenews'      => 'apple_news',
		'smartnews'      => 'smartnews',
		'flipboard'      => 'flipboard',
		'feedreaders'    => 'feed_readers',
		'feedly'         => 'feed_readers',
		'inoreader'      => 'feed_readers',
		'newsblur'       => 'feed_readers',
		'facebook'       => 'facebook',
		'fb'             => 'facebook',
		'x'              => 'x',
		'twitter'        => 'x',
		'reddit'         => 'reddit',
		'telegram'       => 'telegram',
		'mastodon'       => 'mastodon',
		'bluesky'        => 'bluesky',
		'bsky'           => 'bluesky',
	);

	/**
	 * Every source id.
	 *
	 * @return string[]
	 */
	public static function ids(): array {
		return self::IDS;
	}

	/**
	 * Whether a string is one of the source ids.
	 *
	 * @param string $id The id.
	 * @return bool
	 */
	public static function is_source( string $id ): bool {
		return in_array( $id, self::IDS, true );
	}

	/**
	 * The name a source is shown under.
	 *
	 * @param string $id Source id.
	 * @return string
	 */
	public static function label( string $id ): string {
		switch ( $id ) {
			case 'google_news':
				return 'Google News';
			case 'google_search':
				return 'Google Search';
			case 'bing':
				return 'Bing';
			case 'yandex':
				return 'Yandex';
			case 'baidu':
				return 'Baidu';
			case 'microsoft_start':
				return 'Microsoft Start';
			case 'yahoo_news':
				return 'Yahoo News';
			case 'apple_news':
				return 'Apple News';
			case 'smartnews':
				return 'SmartNews';
			case 'flipboard':
				return 'Flipboard';
			case self::FEED_READERS:
				return __( 'Feed readers', 'estasoft-news-distribution' );
			case 'facebook':
				return 'Facebook';
			case 'x':
				return 'X';
			case 'reddit':
				return 'Reddit';
			case 'telegram':
				return 'Telegram';
			case 'mastodon':
				return 'Mastodon';
			case 'bluesky':
				return 'Bluesky';
			case self::DIRECT:
				return __( 'Direct, or the platform hides where it came from', 'estasoft-news-distribution' );
			case self::OTHER:
				return __( 'Other sites', 'estasoft-news-distribution' );
		}

		return $id;
	}

	/**
	 * A short line under a source's name, or an empty string.
	 *
	 * @param string $id Source id.
	 * @return string
	 */
	public static function detail( string $id ): string {
		return self::FEED_READERS === $id ? __( 'Feedly, Inoreader, NewsBlur', 'estasoft-news-distribution' ) : '';
	}

	/**
	 * Whether a source is a platform that sends readers, as opposed to "direct" or "other".
	 *
	 * @param string $id Source id.
	 * @return bool
	 */
	public static function is_platform( string $id ): bool {
		return self::is_source( $id ) && self::DIRECT !== $id && self::OTHER !== $id;
	}

	/**
	 * A host as the route accepts it: lower case, no trailing dot, only letters, digits, dots and dashes.
	 *
	 * @param string $host What the browser sent.
	 * @return string The host, or an empty string when it is not a usable host name.
	 */
	public static function clean_host( string $host ): string {
		$host = rtrim( strtolower( trim( $host ) ), '.' );

		if ( '' === $host || strlen( $host ) > self::MAX_HOST || 1 !== preg_match( '/^[a-z0-9][a-z0-9.-]*$/', $host ) ) {
			return '';
		}

		return $host;
	}

	/**
	 * The source a `utm_source` names, or an empty string when it names none of them.
	 *
	 * @param string $utm The `utm_source` value.
	 * @return string
	 */
	public static function from_utm( string $utm ): string {
		$utm = strtolower( trim( $utm ) );

		if ( '' === $utm || strlen( $utm ) > self::MAX_UTM ) {
			return '';
		}

		$squashed = preg_replace( '/[^a-z0-9]/', '', $utm );

		return is_string( $squashed ) ? ( self::ALIASES[ $squashed ] ?? '' ) : '';
	}

	/**
	 * The source a referrer host belongs to.
	 *
	 * @param string $host A clean host (see clean_host()).
	 * @return string A source id; "other" when the host is in none of the lists.
	 */
	public static function from_host( string $host ): string {
		foreach ( self::domains() as $id => $domains ) {
			foreach ( $domains as $domain ) {
				if ( $host === $domain || self::ends_with( $host, '.' . $domain ) ) {
					return $id;
				}
			}
		}

		// Search engines use a country domain of their own: google.com, google.co.uk, www.google.de.
		if ( 1 === preg_match( '/^(?:www\.)?google\.(?:com?\.[a-z]{2}|[a-z]{2,3})$/', $host ) ) {
			return 'google_search';
		}

		if ( 1 === preg_match( '/^(?:[a-z0-9-]+\.)*yandex\.(?:com?\.[a-z]{2}|[a-z]{2,3})$/', $host ) ) {
			return 'yandex';
		}

		return self::OTHER;
	}

	/**
	 * The source of one visit.
	 *
	 * @param string $host     The host of the page the reader came from, or an empty string.
	 * @param string $utm      The `utm_source` of the page address, or an empty string.
	 * @param string $own_host This site's own host, so a reader moving around the site is not counted.
	 * @return string A source id, or an empty string when the visit should not be counted at all.
	 */
	public static function classify( string $host, string $utm = '', string $own_host = '' ): string {
		$host = self::clean_host( $host );

		if ( '' !== $host && '' !== $own_host && self::bare( $host ) === self::bare( strtolower( $own_host ) ) ) {
			return '';
		}

		$named = self::from_utm( $utm );

		if ( '' !== $named ) {
			return $named;
		}

		return '' === $host ? self::DIRECT : self::from_host( $host );
	}

	/**
	 * The host lists, with what other code added through the filter.
	 *
	 * @return array<string, string[]>
	 */
	private static function domains(): array {
		$domains = self::DOMAINS;

		/**
		 * Adds hosts to the sources a visit is counted under (internal).
		 *
		 * @param array<string, string[]> $extra Source id => hosts. Empty by default.
		 */
		$extra = apply_filters( 'estasoft_nd_internal_referral_hosts', array() );

		if ( is_array( $extra ) ) {
			foreach ( $extra as $id => $hosts ) {
				if ( ! self::is_platform( (string) $id ) ) {
					continue;
				}

				foreach ( (array) $hosts as $host ) {
					$clean = self::clean_host( (string) $host );

					if ( '' !== $clean ) {
						$domains[ (string) $id ][] = $clean;
					}
				}
			}
		}

		return $domains;
	}

	/**
	 * A host without a leading "www.".
	 *
	 * @param string $host Host.
	 * @return string
	 */
	private static function bare( string $host ): string {
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Whether a string ends with another.
	 *
	 * @param string $haystack The string.
	 * @param string $needle   The ending.
	 * @return bool
	 */
	private static function ends_with( string $haystack, string $needle ): bool {
		$length = strlen( $needle );

		return strlen( $haystack ) >= $length && substr( $haystack, -$length ) === $needle;
	}
}

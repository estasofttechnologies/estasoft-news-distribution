<?php
/**
 * The addresses the Follow block links to: what each service accepts, and how a typed address is cleaned.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Follow;

use Estasoft\NewsDistribution\Channels\Outlets\BlueskyOutlet;

defined( 'ABSPATH' ) || exit;

/**
 * Four services have a follow address: Mastodon, Telegram, Bluesky and Apple News. Each has one shape
 * of address, so a link that does not belong to the service is refused instead of printed.
 *
 * - Mastodon: an https profile address with an `@name` path on any server (`https://mastodon.social/@name`).
 *   The handle `@name@server` is turned into that address.
 * - Telegram: a public channel on `t.me` (`https://t.me/name`). `@name` is turned into that address. A
 *   private channel's number has no public address.
 * - Bluesky: a profile on `bsky.app` (`https://bsky.app/profile/name.bsky.social`). A bare handle or a
 *   `did:` identifier is turned into that address.
 * - Apple News: an address on `apple.news` or `news.apple.com`. Nothing about its path is guessed.
 *
 * Everything here is pure: no option is read and nothing is sent. `canonical()` is the one test of
 * whether an address is acceptable, and the address it returns is the one that is stored and printed.
 */
final class Address {

	public const MASTODON   = 'mastodon';
	public const TELEGRAM   = 'telegram';
	public const BLUESKY    = 'bluesky';
	public const APPLE_NEWS = 'apple_news';

	/**
	 * The hosts an Apple News address may have.
	 */
	private const APPLE_HOSTS = array( 'apple.news', 'news.apple.com' );

	/**
	 * The services, in the order their links are printed.
	 *
	 * @return string[]
	 */
	public static function services(): array {
		return array( self::TELEGRAM, self::APPLE_NEWS, self::MASTODON, self::BLUESKY );
	}

	/**
	 * The name of a service as the service writes it.
	 *
	 * @param string $service One of the class constants.
	 * @return string
	 */
	public static function label( string $service ): string {
		switch ( $service ) {
			case self::MASTODON:
				return __( 'Mastodon', 'estasoft-news-distribution' );
			case self::TELEGRAM:
				return __( 'Telegram', 'estasoft-news-distribution' );
			case self::BLUESKY:
				return __( 'Bluesky', 'estasoft-news-distribution' );
			case self::APPLE_NEWS:
				return __( 'Apple News', 'estasoft-news-distribution' );
		}

		return '';
	}

	/**
	 * Cleans what a person typed into the address the service's link will have.
	 *
	 * An address that cannot be made acceptable comes back as it was typed (trimmed), so the form can show
	 * it next to the error.
	 *
	 * @param string $service One of the class constants.
	 * @param string $typed   What was typed.
	 * @return string
	 */
	public static function normalise( string $service, string $typed ): string {
		$typed = trim( $typed );
		$clean = self::canonical( $service, $typed );

		return null === $clean ? $typed : $clean;
	}

	/**
	 * The address for a service, or null when the text is not an address that service uses.
	 *
	 * An empty text is not an address: callers treat it as "not set" before they ask.
	 *
	 * @param string $service One of the class constants.
	 * @param string $typed   What was typed or stored.
	 * @return string|null
	 */
	public static function canonical( string $service, string $typed ): ?string {
		$typed = trim( $typed );

		if ( '' === $typed || preg_match( '/[\x00-\x20\x7f]/', $typed ) ) {
			return null;
		}

		switch ( $service ) {
			case self::MASTODON:
				return self::mastodon( $typed );
			case self::TELEGRAM:
				return self::telegram( $typed );
			case self::BLUESKY:
				return self::bluesky( $typed );
			case self::APPLE_NEWS:
				return self::apple_news( $typed );
		}

		return null;
	}

	/**
	 * The sentence that says what a service's address looks like, for the error that names the field.
	 *
	 * @param string $service One of the class constants.
	 * @return string
	 */
	public static function expects( string $service ): string {
		switch ( $service ) {
			case self::MASTODON:
				return __( 'Mastodon address must be a profile address on https with an @name path, such as https://mastodon.social/@name. A handle such as @name@mastodon.social also works.', 'estasoft-news-distribution' );
			case self::TELEGRAM:
				return __( 'Telegram address must be a public channel on t.me, such as https://t.me/name. A name such as @name also works.', 'estasoft-news-distribution' );
			case self::BLUESKY:
				return __( 'Bluesky address must be a profile on bsky.app, such as https://bsky.app/profile/name.bsky.social. A handle such as name.bsky.social also works.', 'estasoft-news-distribution' );
			case self::APPLE_NEWS:
				return __( 'Apple News address must be an address on apple.news or news.apple.com, copied from your publication on Apple News.', 'estasoft-news-distribution' );
		}

		return '';
	}

	/**
	 * Whether a host name has the shape of a domain name.
	 *
	 * @param string $host A lower-case host name.
	 * @return bool
	 */
	private static function is_host( string $host ): bool {
		return 1 === preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host );
	}

	/**
	 * Splits an address that may have been typed without its scheme.
	 *
	 * A text that starts with a host name and a slash (`t.me/name`) is read as an https address. A text that
	 * has some other scheme (`javascript:`, `ftp://`) is read as typed, so its scheme is seen.
	 *
	 * @param string $typed The text.
	 * @return array{scheme: string, host: string, port: int, path: string, query: string}|null Null when it is not an address.
	 */
	private static function split( string $typed ): ?array {
		if ( 1 === preg_match( '#^[A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z]{2,}(?::\d{1,5})?/#', $typed ) ) {
			$typed = 'https://' . $typed;
		}

		if ( 1 !== preg_match( '#^(https?)://#i', $typed ) ) {
			return null;
		}

		$parts = wp_parse_url( $typed );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		$host = strtolower( (string) $parts['host'] );

		if ( ! self::is_host( $host ) ) {
			return null;
		}

		return array(
			'scheme' => strtolower( (string) $parts['scheme'] ),
			'host'   => $host,
			'port'   => isset( $parts['port'] ) ? (int) $parts['port'] : 0,
			'path'   => (string) ( $parts['path'] ?? '' ),
			'query'  => (string) ( $parts['query'] ?? '' ),
		);
	}

	/**
	 * A Mastodon profile.
	 *
	 * @param string $typed A trimmed text.
	 * @return string|null
	 */
	private static function mastodon( string $typed ): ?string {
		if ( 1 === preg_match( '/^@([A-Za-z0-9_][A-Za-z0-9_.-]{0,63})@([A-Za-z0-9.-]+)$/', $typed, $found ) ) {
			$host = strtolower( $found[2] );

			return self::is_host( $host ) ? 'https://' . $host . '/@' . $found[1] : null;
		}

		$parts = self::split( $typed );

		if ( null === $parts || 'https' !== $parts['scheme'] || '' !== $parts['query'] || 1 !== preg_match( '#^/@([A-Za-z0-9_][A-Za-z0-9_.-]{0,63})/?$#', $parts['path'], $found ) ) {
			return null;
		}

		return 'https://' . $parts['host'] . ( $parts['port'] > 0 ? ':' . $parts['port'] : '' ) . '/@' . $found[1];
	}

	/**
	 * A public Telegram channel.
	 *
	 * @param string $typed A trimmed text.
	 * @return string|null
	 */
	private static function telegram( string $typed ): ?string {
		if ( 1 === preg_match( '/^@([A-Za-z][A-Za-z0-9_]{3,31})$/', $typed, $found ) ) {
			return 'https://t.me/' . $found[1];
		}

		$parts = self::split( $typed );

		if ( null === $parts || 't.me' !== $parts['host'] || 0 !== $parts['port'] || '' !== $parts['query'] || 1 !== preg_match( '#^/([A-Za-z][A-Za-z0-9_]{3,31})/?$#', $parts['path'], $found ) ) {
			return null;
		}

		return 'https://t.me/' . $found[1];
	}

	/**
	 * A Bluesky profile.
	 *
	 * @param string $typed A trimmed text.
	 * @return string|null
	 */
	private static function bluesky( string $typed ): ?string {
		$who = null;

		if ( false === strpos( $typed, '/' ) ) {
			$who = strtolower( ltrim( $typed, '@' ) );
		} else {
			$parts = self::split( $typed );

			if ( null !== $parts && 'bsky.app' === $parts['host'] && 0 === $parts['port'] && '' === $parts['query'] && 1 === preg_match( '#^/profile/([^/]+)/?$#', $parts['path'], $found ) ) {
				$who = strtolower( $found[1] );
			}
		}

		if ( null === $who || ( ! BlueskyOutlet::is_valid_handle( $who ) && 1 !== preg_match( '/^did:(?:plc|web):[a-z0-9._:%-]{1,250}$/', $who ) ) ) {
			return null;
		}

		return 'https://bsky.app/profile/' . $who;
	}

	/**
	 * An Apple News address. Only the host is checked: no identifier is guessed.
	 *
	 * @param string $typed A trimmed text.
	 * @return string|null
	 */
	private static function apple_news( string $typed ): ?string {
		$parts = self::split( $typed );

		if ( null === $parts || ! in_array( $parts['host'], self::APPLE_HOSTS, true ) || 0 !== $parts['port'] || strlen( $parts['path'] ) < 2 ) {
			return null;
		}

		return 'https://' . $parts['host'] . $parts['path'] . ( '' !== $parts['query'] ? '?' . $parts['query'] : '' );
	}
}

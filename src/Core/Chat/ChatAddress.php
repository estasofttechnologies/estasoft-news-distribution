<?php
/**
 * Checks a Slack or Teams webhook address when it is saved.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * The checks that need no network: the address is `https://`, has a host name (not an
 * IP address, not a name that only exists inside a network), carries no sign-in and no odd
 * port, and, for Slack, is an incoming webhook address of `hooks.slack.com`.
 *
 * A host name that points inside a private network is refused too, using
 * `wp_http_validate_url()`. That function looks the name up, and a name that cannot be looked up
 * from this server is not refused here, because a server with no outbound DNS would otherwise
 * refuse every valid address. A send is checked again, every time, by `wp_safe_remote_post()`.
 */
final class ChatAddress {

	public const MAX = 2000;

	/**
	 * The domains Microsoft hands out for Teams: the Workflows app (Power Automate) and the older incoming webhooks.
	 * Unverified against Microsoft's current documentation; a site that is told a different domain can add it with the
	 * `estasoft_nd_internal_teams_hosts` filter.
	 */
	private const TEAMS_HOSTS = array( 'webhook.office.com', 'logic.azure.com', 'powerplatform.com' );

	private const LOCAL_ENDINGS = array( '.local', '.localhost', '.internal', '.lan', '.intranet', '.home.arpa', '.corp', '.test', '.invalid' );

	/**
	 * Checks an address.
	 *
	 * @param string $service `slack` or `teams`.
	 * @param string $address The address a person pasted.
	 * @return string An empty string when it is fine, else a sentence that says what is wrong.
	 */
	public static function problem( string $service, string $address ): string {
		$address = trim( $address );
		$wrong   = 'slack' === $service
			? __( 'The Slack address must start with https://hooks.slack.com/services/. Copy it from the incoming webhook you added to a Slack channel.', 'estasoft-news-distribution' )
			: __( 'Enter the https:// address that Teams gives you. It comes from the Workflows app in Teams or from an incoming webhook.', 'estasoft-news-distribution' );

		if ( strlen( $address ) > self::MAX || preg_match( '/[\s<>"\']/', $address ) ) {
			return $wrong;
		}

		$parts = wp_parse_url( $address );

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
			return $wrong;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
			return $wrong;
		}

		$host = strtolower( trim( (string) $parts['host'], '.' ) );

		if ( 'slack' === $service ) {
			$path = (string) ( $parts['path'] ?? '' );

			if ( 'hooks.slack.com' !== $host || isset( $parts['query'] ) || 1 !== preg_match( '#^/services/[A-Za-z0-9_/-]{3,}$#', $path ) ) {
				return $wrong;
			}

			return '';
		}

		if ( self::is_inside_network( $host ) ) {
			return __( 'That address points inside a private network, so it cannot be a Teams address. Use the https:// address that Teams gives you.', 'estasoft-news-distribution' );
		}

		if ( false === wp_http_validate_url( $address ) && gethostbyname( $host ) !== $host ) {
			return __( 'That address points inside a private network, so it cannot be a Teams address. Use the https:// address that Teams gives you.', 'estasoft-news-distribution' );
		}

		// Only Microsoft's own domains: a pasted address is a place this site will send a message to, so it must not
		// be any other host (a name that resolves inside a network, or an address spelled in a form the checks above miss).
		$allowed = array_filter( array_map( 'strtolower', array_map( 'strval', (array) apply_filters( 'estasoft_nd_internal_teams_hosts', self::TEAMS_HOSTS ) ) ) );

		foreach ( $allowed as $domain ) {
			if ( $host === $domain || substr( $host, -strlen( $domain ) - 1 ) === '.' . $domain ) {
				return '';
			}
		}

		return $wrong;
	}

	/**
	 * Whether a host name can only mean something inside a network: an IP address, a name with no dot, a name
	 * that ends in a local suffix, or this site's own host.
	 *
	 * @param string $host Lower-case host name.
	 * @return bool
	 */
	private static function is_inside_network( string $host ): bool {
		if ( '' === $host || false === strpos( $host, '.' ) || false !== strpbrk( $host, ':[]' ) ) {
			return true;
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) || 1 === preg_match( '/^[0-9.]+$/', $host ) ) {
			return true;
		}

		foreach ( self::LOCAL_ENDINGS as $ending ) {
			if ( substr( $host, -strlen( $ending ) ) === $ending ) {
				return true;
			}
		}

		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === $host;
	}

	/**
	 * Takes a webhook address, and anything that could be used to rebuild it, out of text that may be shown or stored.
	 * A transport error can repeat the whole address, and a service can repeat part of it.
	 *
	 * @param string $text    Message.
	 * @param string $address The webhook address.
	 * @return string
	 */
	public static function scrub( string $text, string $address ): string {
		$address = trim( $address );

		if ( '' === $address ) {
			return $text;
		}

		$parts   = (array) wp_parse_url( $address );
		$needles = array( $address, (string) preg_replace( '#^https?://#i', '', $address ) );

		foreach ( array( 'path', 'query' ) as $key ) {
			if ( isset( $parts[ $key ] ) && strlen( (string) $parts[ $key ] ) > 3 ) {
				$needles[] = (string) $parts[ $key ];
			}
		}

		foreach ( explode( '/', (string) ( $parts['path'] ?? '' ) ) as $segment ) {
			if ( strlen( $segment ) >= 6 ) {
				$needles[] = $segment;
			}
		}

		usort(
			$needles,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		foreach ( array_unique( array_filter( $needles ) ) as $needle ) {
			$text = str_ireplace( $needle, '[removed]', $text );
		}

		return (string) preg_replace( '/([?&](?:sig|sp|sv|code|key|token|access_token)=)[^&\s"\']+/i', '$1[removed]', $text );
	}
}

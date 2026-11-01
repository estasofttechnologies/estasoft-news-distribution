<?php
/**
 * Reads a page of the site's own address.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * The checks on the Environment, Compatibility and Page markup screens read the
 * site's own pages: robots.txt, the REST address, the sitemaps and the articles.
 * They never contact another server, and they run only when a person presses a
 * button (or a script asks the REST address for a fresh check), never on a page
 * load and never when an article is published.
 */
final class SiteFetch {

	/**
	 * The most bytes read from one page.
	 */
	public const MAX_BYTES = 1048576;

	/**
	 * Whether an address belongs to this site.
	 *
	 * @param string $url An absolute address.
	 * @return bool
	 */
	public static function is_own( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$own  = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return is_string( $host ) && is_string( $own ) && strtolower( $host ) === strtolower( $own );
	}

	/**
	 * Reads one page of this site.
	 *
	 * @param string $url     An address on this site. Any other address is refused.
	 * @param int    $timeout Seconds to wait.
	 * @return array{ok: bool, status: int, body: string, headers: array<string, string>, error: string}
	 *         `ok` is true when the site answered at all, whatever the status.
	 */
	public static function get( string $url, int $timeout = 6 ): array {
		if ( ! self::is_own( $url ) ) {
			return array(
				'ok'      => false,
				'status'  => 0,
				'body'    => '',
				'headers' => array(),
				'error'   => __( 'Only addresses on this site can be read.', 'estasoft-news-distribution' ),
			);
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => max( 1, $timeout ),
				'redirection'         => 3,
				'limit_response_size' => self::MAX_BYTES,
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter for requests to the site itself.
				'user-agent'          => 'EstasoftNewsDistribution/' . ( defined( 'ESTASOFT_ND_VERSION' ) ? ESTASOFT_ND_VERSION : '1' ) . '; ' . wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'status'  => 0,
				'body'    => '',
				'headers' => array(),
				'error'   => $response->get_error_message(),
			);
		}

		$headers = array();

		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		}

		return array(
			'ok'      => true,
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'headers' => $headers,
			'error'   => '',
		);
	}

	/**
	 * What robots.txt says that matters here.
	 *
	 * Reads the group for every crawler (`User-agent: *`). A path that blocks `/feed/`
	 * is one that is `/` or starts with `/feed`, unless the same group allows `/feed`.
	 * This is a simple reading: it does not understand wildcards inside a path.
	 *
	 * @param string $body The robots.txt text.
	 * @return array{blocks_feeds: bool, blocked_paths: string[], sitemaps: string[]}
	 */
	public static function parse_robots( string $body ): array {
		$in_group = false;
		$disallow = array();
		$allow    = array();
		$sitemaps = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $body ) as $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', $line ) );

			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}

			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field                 = strtolower( $field );

			if ( 'sitemap' === $field && '' !== $value ) {
				$sitemaps[] = $value;
				continue;
			}

			if ( 'user-agent' === $field ) {
				$in_group = '*' === $value;
				continue;
			}

			if ( ! $in_group ) {
				continue;
			}

			if ( 'disallow' === $field && '' !== $value ) {
				$disallow[] = $value;
			} elseif ( 'allow' === $field && '' !== $value ) {
				$allow[] = $value;
			}
		}

		$allowed = false;

		foreach ( $allow as $path ) {
			$plain = rtrim( $path, '*' );

			if ( '' !== $plain && ( 0 === strpos( '/feed/', $plain ) || 0 === strpos( $plain, '/feed' ) ) ) {
				$allowed = true;
			}
		}

		$blocked = array();

		foreach ( $disallow as $path ) {
			$plain = rtrim( $path, '*' );

			if ( '/' === $plain || 0 === strpos( $plain, '/feed' ) ) {
				$blocked[] = $path;
			}
		}

		return array(
			'blocks_feeds'  => array() !== $blocked && ! $allowed,
			'blocked_paths' => $blocked,
			'sitemaps'      => array_values( array_unique( $sitemaps ) ),
		);
	}
}

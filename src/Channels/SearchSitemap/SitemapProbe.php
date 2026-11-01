<?php
/**
 * Fetches a channel's own sitemap addresses the way a visitor would, and checks them.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\SearchSitemap;

use Estasoft\NewsDistribution\Channels\ChannelInterface;

defined( 'ABSPATH' ) || exit;

/**
 * The "test" a person starts for a sitemap channel. It asks the site's own
 * address, so it proves the rewrite rule, the caching plugin and the firewall
 * let a crawler through. It never contacts any other server and never runs on
 * publish. Tests replace the request with the `pre_http_request` filter.
 */
final class SitemapProbe {

	/**
	 * Runs the probe for every language the channel carries.
	 *
	 * @param ChannelInterface $channel   The channel.
	 * @param string[]         $languages Language codes the site publishes in.
	 * @param callable         $check     Checks a body: `function ( string $body, string $language ): array{valid, entries, problems}`.
	 * @return array{ok: bool, code: int, message: string}
	 */
	public static function run( ChannelInterface $channel, array $languages, callable $check ): array {
		if ( ! $channel->is_enabled() ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'message' => __( 'The channel is off, so its sitemap addresses answer 404. Turn it on, then test again.', 'estasoft-news-distribution' ),
			);
		}

		if ( self::plain_permalinks() ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'message' => __( 'Your site uses plain permalinks, so the sitemap addresses cannot answer. Choose another structure in Settings, Permalinks, then test again.', 'estasoft-news-distribution' ),
			);
		}

		$carried = array_values(
			array_filter(
				array_map( 'strval', $languages ),
				static function ( string $language ) use ( $channel ): bool {
					return $channel->accepts_language( $language );
				}
			)
		);

		if ( array() === $carried ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'message' => __( 'Every language is turned off for this channel, so there is no sitemap to test.', 'estasoft-news-distribution' ),
			);
		}

		$entries = 0;

		foreach ( $carried as $language ) {
			$url      = add_query_arg( 'lang', rawurlencode( $language ), home_url( '/feed/' . $channel->feed_slug() . '/' ) );
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 15,
					'redirection' => 3,
					'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter for requests to the site itself.
					'headers'     => array( 'Accept' => 'application/xml, text/xml' ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'ok'      => false,
					'code'    => 0,
					'message' => sprintf(
						/* translators: 1: the sitemap address, 2: the error text from WordPress. */
						__( 'Your site did not answer %1$s: %2$s', 'estasoft-news-distribution' ),
						$url,
						$response->get_error_message()
					),
				);
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 200 !== $code ) {
				return array(
					'ok'      => false,
					'code'    => $code,
					'message' => self::explain( $code, $url ),
				);
			}

			$checked = $check( (string) wp_remote_retrieve_body( $response ), $language );

			if ( empty( $checked['valid'] ) ) {
				$problems = array_values( (array) ( $checked['problems'] ?? array() ) );

				return array(
					'ok'      => false,
					'code'    => 200,
					'message' => sprintf(
						/* translators: 1: the sitemap address, 2: the first problem found. */
						__( '%1$s answered 200, but %2$s', 'estasoft-news-distribution' ),
						$url,
						(string) ( $problems[0] ?? __( 'the document did not pass the check.', 'estasoft-news-distribution' ) )
					),
				);
			}

			$entries += (int) ( $checked['entries'] ?? 0 );
		}

		return array(
			'ok'      => true,
			'code'    => 200,
			'message' => sprintf(
				/* translators: 1: number of sitemaps, 2: number of entries in them. */
				_n( 'Your site answered 200 for %1$s sitemap, and it is valid (%2$s entries).', 'Your site answered 200 for %1$s sitemaps, and all of them are valid (%2$s entries).', count( $carried ), 'estasoft-news-distribution' ),
				number_format_i18n( count( $carried ) ),
				number_format_i18n( $entries )
			),
		);
	}

	/**
	 * Whether the site uses plain permalinks (`?p=123`). Feed and file addresses of
	 * this plugin are pretty addresses, so a site with plain permalinks cannot serve them.
	 *
	 * @return bool
	 */
	public static function plain_permalinks(): bool {
		return '' === (string) get_option( 'permalink_structure', '' );
	}

	/**
	 * What an unexpected answer means, in plain words.
	 *
	 * @param int    $code HTTP status.
	 * @param string $url  The address asked.
	 * @return string
	 */
	private static function explain( int $code, string $url ): string {
		if ( 404 === $code ) {
			return sprintf(
				/* translators: %s: the sitemap address. */
				__( '%s answered 404. Save your permalinks (Settings, Permalinks) so the sitemap address works, and check that no plugin blocks it.', 'estasoft-news-distribution' ),
				$url
			);
		}

		if ( 410 === $code ) {
			return sprintf(
				/* translators: %s: the sitemap address. */
				__( '%s answered 410, which means this language is turned off for the channel.', 'estasoft-news-distribution' ),
				$url
			);
		}

		if ( in_array( $code, array( 401, 403 ), true ) ) {
			return sprintf(
				/* translators: 1: the sitemap address, 2: the HTTP status such as 403. */
				__( '%1$s answered %2$d. A firewall, a security plugin or password protection may be blocking the sitemap.', 'estasoft-news-distribution' ),
				$url,
				$code
			);
		}

		return sprintf(
			/* translators: 1: the sitemap address, 2: the HTTP status such as 500. */
			__( '%1$s answered %2$d instead of 200.', 'estasoft-news-distribution' ),
			$url,
			$code
		);
	}
}

<?php
/**
 * The one place outlets make web requests.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Outlets;

defined( 'ABSPATH' ) || exit;

/**
 * Every call goes through wp_safe_remote_request(), so WordPress refuses
 * addresses on private networks. Redirects are not followed: a request that
 * carries a token or a signature must not be handed to another host.
 *
 * Whatever comes back is cleaned before anything reads it: a server may echo a
 * token in an error body, and a transport error may carry the address, which for
 * Telegram holds the bot token.
 */
final class OutletHttp {

	/**
	 * Seconds to wait for an answer.
	 */
	public const TIMEOUT = 15;

	/**
	 * Largest body kept, in bytes.
	 */
	private const MAX_BODY = 1048576;

	/**
	 * Sends a request and returns the answer.
	 *
	 * @param string               $method  GET, POST or DELETE.
	 * @param string               $url     Address.
	 * @param array<string, mixed> $args    wp_remote_request() arguments. `headers` and `body` are the usual ones.
	 * @param string[]             $secrets Values to remove from anything read back, such as tokens.
	 * @return Reply
	 */
	public static function send( string $method, string $url, array $args = array(), array $secrets = array() ): Reply {
		// A binary body (an image) is handed back as it is: cleaning could change its bytes.
		$raw = ! empty( $args['raw'] );
		unset( $args['raw'] );

		$args = array_merge(
			array(
				'method'              => strtoupper( $method ),
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'user-agent'          => self::agent(),
				'headers'             => array(),
				// An answer from a service the owner typed in is never read past 1 MB.
				'limit_response_size' => 1048576,
			),
			$args
		);

		$response = wp_safe_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new Reply( 0, '', array(), self::scrub( $response->get_error_message(), $secrets ) );
		}

		$headers = array();
		$sent    = wp_remote_retrieve_headers( $response );

		if ( $sent instanceof \WpOrg\Requests\Utility\CaseInsensitiveDictionary ) {
			$sent = $sent->getAll();
		}

		foreach ( is_array( $sent ) ? $sent : array() as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}

		$body = (string) wp_remote_retrieve_body( $response );

		return new Reply(
			(int) wp_remote_retrieve_response_code( $response ),
			substr( $raw ? $body : self::scrub( $body, $secrets ), 0, self::MAX_BODY ),
			$headers
		);
	}

	/**
	 * Removes secrets from a text.
	 *
	 * Known values are replaced as they are, and the shapes secrets take in
	 * requests (a Telegram bot address, an authorization header) are removed even
	 * when the value is not known here.
	 *
	 * @param string   $text    Text that may hold a secret.
	 * @param string[] $secrets Known secret values.
	 * @return string
	 */
	public static function scrub( string $text, array $secrets = array() ): string {
		foreach ( $secrets as $secret ) {
			$secret = (string) $secret;

			if ( strlen( $secret ) >= 4 ) {
				$text = str_replace( array( $secret, rawurlencode( $secret ), urlencode( $secret ) ), '[hidden]', $text ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- Matches the form a form body or a query string may carry.
			}
		}

		$text = (string) preg_replace( '#/bot\d{3,}:[A-Za-z0-9_-]{8,}#', '/bot[hidden]', $text );
		$text = (string) preg_replace( '#(Bearer|Basic)\s+[A-Za-z0-9._~+/=-]{8,}#i', '$1 [hidden]', $text );

		return $text;
	}

	/**
	 * A sentence for a failed request that a person can read.
	 *
	 * @param Reply  $reply   The answer.
	 * @param string $service The service's name, such as "Telegram".
	 * @param string $detail  What the service said about the problem, or an empty string.
	 * @return string
	 */
	public static function describe_failure( Reply $reply, string $service, string $detail = '' ): string {
		if ( $reply->timed_out() ) {
			return sprintf(
				/* translators: %d: seconds. */
				_n( 'No answer after %d second.', 'No answer after %d seconds.', self::TIMEOUT, 'estasoft-news-distribution' ),
				self::TIMEOUT
			);
		}

		if ( 0 === $reply->code() ) {
			return sprintf(
				/* translators: %s: the name of a service, such as Telegram. */
				__( '%s could not be reached.', 'estasoft-news-distribution' ),
				$service
			);
		}

		$text = sprintf(
			/* translators: 1: the name of a service, such as Telegram, 2: an HTTP status number. */
			__( '%1$s answered %2$d.', 'estasoft-news-distribution' ),
			$service,
			$reply->code()
		);

		$detail = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $detail ) ) ?? '' );

		if ( '' !== $detail ) {
			$text .= ' ' . mb_substr( $detail, 0, 160 );
		}

		return $text;
	}

	/**
	 * The User-Agent every request carries.
	 *
	 * @return string
	 */
	private static function agent(): string {
		return 'EstasoftNewsDistribution/' . ESTASOFT_ND_VERSION . '; ' . home_url();
	}
}

<?php
/**
 * Shared parts of the search engine connections.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations\Connections;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Integrations\OAuth\CredentialSource;
use Estasoft\NewsDistribution\Integrations\OAuth\PastedToken;
use Estasoft\NewsDistribution\Integrations\OAuth\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one request, reads the answer, and words a failure so a person can act
 * on it. A credential is never put in a message, a log or a returned value.
 */
abstract class AbstractConnection implements ConnectionInterface {

	/**
	 * The most characters of an engine's error text that are shown.
	 */
	private const MAX_ERROR = 200;

	/**
	 * Where the bearer token comes from, when something other than the pasted credential gives it (a sign-in).
	 *
	 * @var CredentialSource|null
	 */
	private ?CredentialSource $source = null;

	/**
	 * The same connection, taking its bearer token from another source: a sign-in with the owner's own app.
	 * The pasted credential given to `verify()` and `sync()` is then not used.
	 *
	 * @param CredentialSource $source The source.
	 * @return static A copy; this connection is not changed.
	 */
	public function using( CredentialSource $source ) {
		$copy         = clone $this;
		$copy->source = $source;

		return $copy;
	}

	/**
	 * The source of the bearer token: the one given to `using()`, else the one the pasted credential makes.
	 *
	 * @param string $credential The pasted credential.
	 * @return CredentialSource
	 */
	protected function source( string $credential ): CredentialSource {
		return $this->source ?? $this->default_source( $credential );
	}

	/**
	 * The source a pasted credential makes. A token, unless the engine says otherwise.
	 *
	 * @param string $credential The pasted credential.
	 * @return CredentialSource
	 */
	protected function default_source( string $credential ): CredentialSource {
		return new PastedToken( $credential );
	}

	/**
	 * What a source uses to send a request and to word a failure: this connection's own.
	 *
	 * @return Transport
	 */
	protected function transport(): Transport {
		return new Transport(
			\Closure::fromCallable( array( $this, 'request' ) ),
			\Closure::fromCallable( array( $this, 'failure' ) )
		);
	}

	/**
	 * Takes an address's query string, and any key or token in a message, out of text that may be shown or stored.
	 * A transport error can repeat the whole address, and some services take the key in the query string.
	 *
	 * @param string $text Message.
	 * @param string $url  Address the message is about.
	 * @return string
	 */
	protected static function scrub( string $text, string $url ): string {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		if ( '' !== $query ) {
			$text = str_replace( $query, '[removed]', $text );
		}

		return (string) preg_replace( '/([?&](?:api_?key|key|token|access_token|refresh_token|client_secret|code_verifier|assertion)=)[^&\s"\']+/i', '$1[removed]', $text );
	}

	/**
	 * Sends a request.
	 *
	 * @param string                           $method  HTTP method.
	 * @param string                           $url     Address.
	 * @param array<string, string>            $headers Headers.
	 * @param string|array<string, mixed>|null $body Body: a string, or an array sent as a form.
	 * @return array{code: int, json: array<mixed>|null, raw: string, error: string} `error` is set only when no answer came back.
	 */
	protected function request( string $method, string $url, array $headers = array(), $body = null ): array {
		$response = wp_remote_request(
			$url,
			array(
				'method'              => $method,
				'timeout'             => 15,
				'redirection'         => 0,
				'headers'             => $headers,
				'body'                => $body,
				'user-agent'          => 'Estasoft News Distribution (' . home_url() . ')',
				// A service's answer is never read past 1 MB.
				'limit_response_size' => 1048576,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'code'  => 0,
				'json'  => null,
				'raw'   => '',
				'error' => self::scrub( $response->get_error_message(), $url ),
			);
		}

		$raw  = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		return array(
			'code'  => (int) wp_remote_retrieve_response_code( $response ),
			'json'  => is_array( $json ) ? $json : null,
			'raw'   => $raw,
			'error' => '',
		);
	}

	/**
	 * A sentence for an answer that was not a success.
	 *
	 * @param array{code: int, json: array<mixed>|null, raw: string, error: string} $answer What `request()` returned.
	 * @param string                                                                $denied What to say for a 401 or 403.
	 * @return string
	 */
	protected function failure( array $answer, string $denied ): string {
		if ( '' !== $answer['error'] ) {
			return sprintf(
				/* translators: 1: search engine name, 2: the error text from WordPress. */
				__( 'Your site could not reach %1$s: %2$s', 'estasoft-news-distribution' ),
				$this->label(),
				$answer['error']
			);
		}

		if ( in_array( $answer['code'], array( 401, 403 ), true ) ) {
			return $denied;
		}

		$detail = $this->engine_message( $answer );

		return sprintf(
			/* translators: 1: search engine name, 2: HTTP status number, 3: the engine's own error text, may be empty. */
			__( '%1$s answered with error %2$d. %3$s', 'estasoft-news-distribution' ),
			$this->label(),
			$answer['code'],
			$detail
		);
	}

	/**
	 * The engine's own error text, shortened.
	 *
	 * @param array{code: int, json: array<mixed>|null, raw: string, error: string} $answer What `request()` returned.
	 * @return string
	 */
	protected function engine_message( array $answer ): string {
		$json = (array) $answer['json'];
		$text = '';

		foreach ( array( 'error_message', 'Message', 'message' ) as $key ) {
			if ( isset( $json[ $key ] ) && is_string( $json[ $key ] ) ) {
				$text = $json[ $key ];
				break;
			}
		}

		if ( '' === $text && isset( $json['error'] ) ) {
			$error = $json['error'];
			$text  = is_array( $error ) ? (string) ( $error['message'] ?? '' ) : ( is_string( $error ) ? $error : '' );
		}

		$text = trim( wp_strip_all_tags( $text ) );

		return mb_strlen( $text ) > self::MAX_ERROR ? mb_substr( $text, 0, self::MAX_ERROR ) . '…' : $text;
	}

	/**
	 * A date for a row: "14 Oct" in the site's time zone.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	protected function day( int $timestamp ): string {
		return wp_date( 'j M', $timestamp, Clock::zone() );
	}

	/**
	 * The host of the site the person runs, lower case.
	 *
	 * @return string
	 */
	protected function site_host(): string {
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * One row.
	 *
	 * @param array{label: string, url: string} $sitemap The sitemap.
	 * @param string                            $says    The engine's facts, in words.
	 * @param string                            $status  A Core\Status code.
	 * @param string                            $link    Where the details are, or an empty string.
	 * @return array{label: string, url: string, says: string, status: string, link: string}
	 */
	protected function row( array $sitemap, string $says, string $status, string $link = '' ): array {
		return array(
			'label'  => $sitemap['label'],
			'url'    => $sitemap['url'],
			'says'   => $says,
			'status' => $status,
			'link'   => $link,
		);
	}
}

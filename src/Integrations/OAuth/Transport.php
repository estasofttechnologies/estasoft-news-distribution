<?php
/**
 * How a credential source sends a request on behalf of a connection.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Hands a source the connection's own way of sending a request and of wording a failure,
 * so no source repeats the request code.
 */
final class Transport {

	private \Closure $sender;

	private \Closure $failer;

	/**
	 * Creates the transport.
	 *
	 * @param \Closure $sender The connection's `request()`.
	 * @param \Closure $failer The connection's `failure()`.
	 */
	public function __construct( \Closure $sender, \Closure $failer ) {
		$this->sender = $sender;
		$this->failer = $failer;
	}

	/**
	 * Sends a request.
	 *
	 * @param string                           $method  HTTP method.
	 * @param string                           $url     Address.
	 * @param array<string, string>            $headers Headers.
	 * @param string|array<string, mixed>|null $body    Body: a string, or an array sent as a form.
	 * @return array{code: int, json: array<mixed>|null, raw: string, error: string}
	 */
	public function send( string $method, string $url, array $headers = array(), $body = null ): array {
		return (array) call_user_func( $this->sender, $method, $url, $headers, $body );
	}

	/**
	 * A sentence for an answer that was not a success.
	 *
	 * @param array{code: int, json: array<mixed>|null, raw: string, error: string} $answer What `send()` returned.
	 * @param string                                                                $denied What to say for a 401 or 403.
	 * @return string
	 */
	public function failure( array $answer, string $denied ): string {
		return (string) call_user_func( $this->failer, $answer, $denied );
	}
}

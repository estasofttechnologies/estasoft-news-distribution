<?php
/**
 * A public response, built first and sent second so it can be tested.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Status, headers and body.
 */
final class Response {

	public int $status;

	/**
	 * Header name => value.
	 *
	 * @var array<string, string>
	 */
	public array $headers;

	public string $body;

	/**
	 * Creates the response.
	 *
	 * @param int                  $status  HTTP status.
	 * @param array<string,string> $headers Header name => value.
	 * @param string               $body    Body. Empty for 304 and HEAD.
	 */
	public function __construct( int $status, array $headers = array(), string $body = '' ) {
		$this->status  = $status;
		$this->headers = $headers;
		$this->body    = $body;
	}

	/**
	 * The headers that are sent: the ones the response was given, and `nosniff` unless it set its own.
	 *
	 * Every document here has the type it says it has; a browser is not to guess another one.
	 *
	 * @return array<string, string>
	 */
	public function sent_headers(): array {
		$headers = $this->headers;

		if ( ! array_key_exists( 'X-Content-Type-Options', $headers ) ) {
			$headers['X-Content-Type-Options'] = 'nosniff';
		}

		return $headers;
	}

	/**
	 * Sends the response and stops. A HEAD request gets the headers only.
	 *
	 * @param bool $head_only Whether to leave the body out.
	 * @return void
	 */
	public function send( bool $head_only = false ): void {
		status_header( $this->status );

		foreach ( $this->sent_headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}

		if ( ! $head_only && '' !== $this->body ) {
			echo $this->body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Documents are built and escaped by the channel; a plain-text error body is fixed.
		}

		exit;
	}
}

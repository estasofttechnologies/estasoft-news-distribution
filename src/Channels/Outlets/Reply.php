<?php
/**
 * What an outlet's server answered.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Outlets;

defined( 'ABSPATH' ) || exit;

/**
 * A small, read-only copy of an HTTP answer. A request that got no answer has
 * the code 0 and an error text (already cleaned of secrets).
 */
final class Reply {

	private int $code;
	private string $body;

	/**
	 * Header values by lower-case name.
	 *
	 * @var array<string, string>
	 */
	private array $headers;
	private string $error;

	/**
	 * The decoded body, read once.
	 *
	 * @var array<mixed>|null
	 */
	private ?array $json = null;

	/**
	 * Creates the reply.
	 *
	 * @param int                  $code    HTTP status, or 0 when nothing answered.
	 * @param string               $body    Raw body.
	 * @param array<string,string> $headers Header values by lower-case name.
	 * @param string               $error   Transport error text with secrets removed, or an empty string.
	 */
	public function __construct( int $code, string $body, array $headers = array(), string $error = '' ) {
		$this->code    = $code;
		$this->body    = $body;
		$this->headers = $headers;
		$this->error   = $error;
	}

	public function code(): int {
		return $this->code;
	}

	public function body(): string {
		return $this->body;
	}

	public function error(): string {
		return $this->error;
	}

	/**
	 * Whether the answer is a success (200 to 299).
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return $this->code >= 200 && $this->code < 300;
	}

	/**
	 * Whether the request ended because nothing answered in time.
	 *
	 * @return bool
	 */
	public function timed_out(): bool {
		return 0 === $this->code && 1 === preg_match( '/time(d)?[ -]?out/i', $this->error );
	}

	/**
	 * The body decoded as JSON.
	 *
	 * @return array<mixed> Empty when the body is not a JSON object or list.
	 */
	public function json(): array {
		if ( null === $this->json ) {
			$decoded    = json_decode( $this->body, true );
			$this->json = is_array( $decoded ) ? $decoded : array();
		}

		return $this->json;
	}

	/**
	 * A header value.
	 *
	 * @param string $name Header name.
	 * @return string Empty when the header is missing.
	 */
	public function header( string $name ): string {
		return $this->headers[ strtolower( $name ) ] ?? '';
	}

	/**
	 * Seconds the server asked to wait, from the body (Telegram) or a Retry-After header. Zero when it did not say.
	 *
	 * @return int
	 */
	public function retry_after(): int {
		$json = $this->json();
		$wait = 0;

		if ( isset( $json['parameters']['retry_after'] ) && is_numeric( $json['parameters']['retry_after'] ) ) {
			$wait = (int) $json['parameters']['retry_after'];
		} elseif ( is_numeric( $this->header( 'retry-after' ) ) ) {
			$wait = (int) $this->header( 'retry-after' );
		}

		return max( 0, min( $wait, DAY_IN_SECONDS ) );
	}
}

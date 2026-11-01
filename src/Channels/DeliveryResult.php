<?php
/**
 * Outcome of one push.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels;

use Estasoft\NewsDistribution\Core\Retry;

defined( 'ABSPATH' ) || exit;

/**
 * What a channel's deliver() returns.
 */
final class DeliveryResult {

	public const SUCCESS = 'success';
	public const FAILED  = 'failed';
	public const SKIPPED = 'skipped';

	private string $status;
	private int $code;
	private string $message;
	private bool $retryable;

	private function __construct( string $status, int $code, string $message, bool $retryable ) {
		$this->status    = $status;
		$this->code      = $code;
		$this->message   = $message;
		$this->retryable = $retryable;
	}

	/**
	 * The destination accepted the request.
	 *
	 * @param int    $code    HTTP status of the answer.
	 * @param string $message Short detail for the log, such as "IndexNow answered 200". Never a secret.
	 * @return self
	 */
	public static function success( int $code = 200, string $message = '' ): self {
		return new self( self::SUCCESS, $code, $message, false );
	}

	/**
	 * The request failed. Whether it is tried again follows the HTTP code unless
	 * you say otherwise.
	 *
	 * @param string    $message   What went wrong, in a sentence a person can read. Never a secret.
	 * @param int       $code      HTTP status, or 0 when nothing answered.
	 * @param bool|null $retryable True to retry, false to give up now, null to decide from the code.
	 * @return self
	 */
	public static function failure( string $message, int $code = 0, ?bool $retryable = null ): self {
		return new self( self::FAILED, $code, $message, null === $retryable ? Retry::is_retryable_code( $code ) : $retryable );
	}

	/**
	 * The channel chose not to send, for example because the article breaks one of its rules.
	 *
	 * @param string $message Why, in a sentence a person can read.
	 * @return self
	 */
	public static function skipped( string $message ): self {
		return new self( self::SKIPPED, 0, $message, false );
	}

	/**
	 * For pulled channels, which have nothing to push.
	 *
	 * @return self
	 */
	public static function not_applicable(): self {
		return new self( self::SKIPPED, 0, 'Nothing to deliver for a pulled channel.', false );
	}

	public function status(): string {
		return $this->status;
	}

	public function code(): int {
		return $this->code;
	}

	public function message(): string {
		return $this->message;
	}

	public function is_failure(): bool {
		return self::FAILED === $this->status;
	}

	/**
	 * Whether a failed delivery should be tried again.
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		return $this->retryable;
	}
}

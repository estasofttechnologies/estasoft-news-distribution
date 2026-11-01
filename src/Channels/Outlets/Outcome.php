<?php
/**
 * What happened when one target was tried.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Outlets;

use Estasoft\NewsDistribution\Core\Retry;

defined( 'ABSPATH' ) || exit;

/**
 * One line of a delivery: sent, failed, skipped, or listed (Medium: the story
 * is on the import list, which is not the same as posted).
 */
final class Outcome {

	public const SENT    = 'sent';
	public const FAILED  = 'failed';
	public const SKIPPED = 'skipped';
	public const LISTED  = 'listed';

	private Target $target;
	private string $status;
	private int $code;
	private string $message;
	private bool $retryable;
	private string $remote;

	private function __construct( Target $target, string $status, int $code, string $message, bool $retryable, string $remote ) {
		$this->target    = $target;
		$this->status    = $status;
		$this->code      = $code;
		$this->message   = $message;
		$this->retryable = $retryable;
		$this->remote    = $remote;
	}

	/**
	 * The other side accepted the post.
	 *
	 * @param Target $target  The target.
	 * @param int    $code    HTTP status.
	 * @param string $message A short detail. Never a secret.
	 * @param string $remote  The other side's id or address for the post, or an empty string.
	 * @return self
	 */
	public static function sent( Target $target, int $code, string $message = '', string $remote = '' ): self {
		return new self( $target, self::SENT, $code, $message, false, $remote );
	}

	/**
	 * The attempt failed. Whether it is tried again follows the HTTP code unless you say.
	 *
	 * @param Target    $target    The target.
	 * @param string    $message   What went wrong, in a sentence a person can read. Never a secret.
	 * @param int       $code      HTTP status, or 0 when nothing answered.
	 * @param bool|null $retryable True to retry, false to give up, null to decide from the code.
	 * @return self
	 */
	public static function failed( Target $target, string $message, int $code = 0, ?bool $retryable = null ): self {
		return new self( $target, self::FAILED, $code, $message, null === $retryable ? Retry::is_retryable_code( $code ) : $retryable, '' );
	}

	/**
	 * Nothing was sent, on purpose.
	 *
	 * @param Target $target  The target.
	 * @param string $message Why.
	 * @return self
	 */
	public static function skipped( Target $target, string $message ): self {
		return new self( $target, self::SKIPPED, 0, $message, false, '' );
	}

	/**
	 * The story is waiting on a list for a person to act on. Nothing left the site.
	 *
	 * @param Target $target  The target.
	 * @param string $message What is waiting.
	 * @return self
	 */
	public static function listed( Target $target, string $message ): self {
		return new self( $target, self::LISTED, 0, $message, false, '' );
	}

	public function target(): Target {
		return $this->target;
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

	public function remote(): string {
		return $this->remote;
	}

	public function is_sent(): bool {
		return self::SENT === $this->status;
	}

	public function is_failure(): bool {
		return self::FAILED === $this->status;
	}

	public function is_retryable(): bool {
		return $this->retryable;
	}
}

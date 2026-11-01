<?php
/**
 * When a failed delivery is tried again.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Four attempts in all: the first, then after 1, 5 and 15 minutes. After the
 * fourth the delivery is Failed.
 */
final class Retry {

	public const MAX_ATTEMPTS = 4;

	private const BACKOFF = array( 60, 300, 900 );

	/**
	 * Seconds to wait before the next attempt.
	 *
	 * @param int $attempt The attempt that just failed, starting at 1.
	 * @return int|null Null when no attempt is left.
	 */
	public static function delay_after( int $attempt ): ?int {
		if ( $attempt < 1 || $attempt >= self::MAX_ATTEMPTS ) {
			return null;
		}

		return self::BACKOFF[ $attempt - 1 ];
	}

	/**
	 * Whether another attempt remains.
	 *
	 * @param int $attempt The attempt that just failed.
	 * @return bool
	 */
	public static function has_next( int $attempt ): bool {
		return null !== self::delay_after( $attempt );
	}

	/**
	 * Whether an HTTP answer is worth trying again.
	 *
	 * A refusal that will not change (bad request, unauthorized, forbidden, not
	 * found, gone) is not retried. Timeouts, rate limits, server errors and a
	 * request that never got an answer (code 0) are.
	 *
	 * @param int $code HTTP status, or 0 when there was no answer.
	 * @return bool
	 */
	public static function is_retryable_code( int $code ): bool {
		if ( 0 === $code || 408 === $code || 425 === $code || 429 === $code ) {
			return true;
		}

		return $code >= 500;
	}
}

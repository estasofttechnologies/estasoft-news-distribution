<?php
/**
 * Keeps the visit route from being flooded, without remembering anyone.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Referrals;

use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * One counter per article and source for the current minute, kept in a transient that lives two
 * minutes under one of two names, used by turns, so the rows do not pile up. It is not per visitor: nothing about who called is kept, only how many calls came.
 *
 * With a persistent object cache the counter is increased in one step; without one, two requests
 * that arrive in the same instant can read the same number, so the count can be a little low.
 * That is fine for a limit of hundreds a minute.
 */
final class Limiter {

	/**
	 * Calls allowed per article and source in one minute, unless the internal filter changes it.
	 */
	public const DEFAULT_CAP = 600;

	private const GROUP = 'estasoft_nd_referrals';

	/**
	 * The calls allowed per article and source in a minute.
	 *
	 * @return int
	 */
	public static function cap(): int {
		/**
		 * Changes how many visits one article can count under one source in a minute (internal).
		 *
		 * @param int $cap Calls allowed in a minute. Default 600.
		 */
		$cap = apply_filters( 'estasoft_nd_internal_referral_cap', self::DEFAULT_CAP );

		return max( 1, (int) $cap );
	}

	/**
	 * Counts one call and says whether it is still inside the limit.
	 *
	 * @param int    $post_id Article ID.
	 * @param string $source  Source id.
	 * @return array{allowed: bool, count: int, retry_after: int} `retry_after` is the seconds until the next minute.
	 */
	public static function hit( int $post_id, string $source ): array {
		$now    = Clock::now();
		$window = (int) floor( $now / MINUTE_IN_SECONDS );
		// Two names per article and source, used by turns (even and odd minutes) and each remembering its minute,
		// so a flood of calls cannot leave a new row behind for every minute.
		$key   = 'estasoft_nd_rf_' . $post_id . '_' . $source . '_' . ( $window % 2 );
		$count = self::increase( $key, $window );
		$left  = ( ( $window + 1 ) * MINUTE_IN_SECONDS ) - $now;

		return array(
			'allowed'     => $count <= self::cap(),
			'count'       => $count,
			'retry_after' => max( 1, $left ),
		);
	}

	/**
	 * Adds one to a counter.
	 *
	 * @param string $key    Counter name.
	 * @param int    $window The minute the call is in.
	 * @return int The count including this call.
	 */
	private static function increase( string $key, int $window ): int {
		if ( wp_using_ext_object_cache() ) {
			// The memory forgets the name after 90 seconds, before the same name is used again two minutes on.
			wp_cache_add( $key, 0, self::GROUP, 90 );
			$count = wp_cache_incr( $key, 1, self::GROUP );

			if ( false !== $count ) {
				return (int) $count;
			}
		}

		$stored = get_transient( $key );
		$count  = ( is_array( $stored ) && (int) ( $stored['w'] ?? -1 ) === $window ? (int) ( $stored['c'] ?? 0 ) : 0 ) + 1;

		set_transient(
			$key,
			array(
				'w' => $window,
				'c' => $count,
			),
			2 * MINUTE_IN_SECONDS
		);

		return $count;
	}
}

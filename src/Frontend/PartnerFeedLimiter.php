<?php
/**
 * Counts a partner's requests in the current minute.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Frontend;

use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * One counter per partner per minute, kept in a transient that lives two minutes. With a
 * persistent object cache the counter is increased in one step; without one, two requests
 * that arrive in the same instant can both read the same number, so the count can be a
 * little low. That is acceptable for a limit of 10 to 600 requests a minute.
 *
 * A request over the limit is answered with 429 and `Retry-After`, the seconds left until
 * the next minute starts.
 */
final class PartnerFeedLimiter {

	private const GROUP = 'estasoft_nd_partner_feed';

	/**
	 * Counts one request and says whether the partner is still inside its limit.
	 *
	 * @param string $partner_id Partner id.
	 * @param int    $limit      Requests allowed in a minute.
	 * @return array{allowed: bool, count: int, retry_after: int} `retry_after` is the seconds until the next minute.
	 */
	public static function hit( string $partner_id, int $limit ): array {
		$now    = Clock::now();
		$window = (int) floor( $now / MINUTE_IN_SECONDS );
		$key    = 'estasoft_nd_pf_' . $partner_id . '_' . $window;
		$count  = self::increase( $key );
		$left   = ( ( $window + 1 ) * MINUTE_IN_SECONDS ) - $now;

		return array(
			'allowed'     => $count <= $limit,
			'count'       => $count,
			'retry_after' => max( 1, $left ),
		);
	}

	/**
	 * Adds one to a counter.
	 *
	 * @param string $key Counter name.
	 * @return int The count including this request.
	 */
	private static function increase( string $key ): int {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, self::GROUP, 2 * MINUTE_IN_SECONDS );
			$count = wp_cache_incr( $key, 1, self::GROUP );

			if ( false !== $count ) {
				return (int) $count;
			}
		}

		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, 2 * MINUTE_IN_SECONDS );

		return $count;
	}
}

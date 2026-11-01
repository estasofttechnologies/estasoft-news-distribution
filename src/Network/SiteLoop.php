<?php
/**
 * The one way the Network screens visit other sites.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Network;

defined( 'ABSPATH' ) || exit;

/**
 * Reads across sites with `switch_to_blog()`, in a bounded loop that always goes back.
 *
 * - At most `MAX_SITES` sites are ever visited, and the loop stops early when it has used
 *   `BUDGET_SECONDS`; the sites it did not reach are returned so a screen can say so.
 * - `restore_current_blog()` runs in a `finally`, so a callback that fails (even with an
 *   exception) leaves WordPress on the site it started on.
 * - One site failing never stops the others: its result is a `WP_Error`.
 *
 * Archived, spam and deleted sites are left out.
 */
final class SiteLoop {

	/**
	 * Most sites one pass visits.
	 */
	public const MAX_SITES = 200;

	/**
	 * Seconds one pass may take before it stops and reports the sites it left.
	 */
	public const BUDGET_SECONDS = 20;

	/**
	 * The IDs of the sites a pass visits, oldest first, never more than MAX_SITES.
	 *
	 * @return int[]
	 */
	public static function site_ids(): array {
		$ids = get_sites(
			array(
				'number'   => self::MAX_SITES,
				'fields'   => 'ids',
				'orderby'  => 'id',
				'order'    => 'ASC',
				'archived' => 0,
				'spam'     => 0,
				'deleted'  => 0,
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * How many sites the network has that a pass would visit if it had no limit.
	 *
	 * @return int
	 */
	public static function total(): int {
		return (int) get_sites(
			array(
				'count'    => true,
				'archived' => 0,
				'spam'     => 0,
				'deleted'  => 0,
			)
		);
	}

	/**
	 * Runs a callback inside one site and goes back, whatever happens.
	 *
	 * @param int      $blog_id  Site ID.
	 * @param callable $callback Called with the site ID while that site is the current one.
	 * @return mixed What the callback returned.
	 * @throws \Throwable Whatever the callback threw, after the site was restored.
	 */
	public static function in_site( int $blog_id, callable $callback ) {
		$switched = switch_to_blog( $blog_id );

		try {
			return $callback( $blog_id );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Seconds one pass may take. A test lowers it to see the loop stop.
	 *
	 * @return float
	 */
	public static function budget(): float {
		/**
		 * The time one pass over the sites may take, in seconds.
		 *
		 * @param float $seconds Default 20.
		 */
		return max( 0.0, (float) apply_filters( 'estasoft_nd_internal_network_loop_budget', (float) self::BUDGET_SECONDS ) );
	}

	/**
	 * Visits sites one by one.
	 *
	 * @param int[]    $blog_ids Sites to visit; anything past MAX_SITES is not visited.
	 * @param callable $callback Called with the site ID while that site is current. It returns the site's result.
	 * @return array{done: array<int, mixed>, left: int[]} Results by site ID (a WP_Error for a site that failed), and the IDs that were not visited.
	 */
	public static function visit( array $blog_ids, callable $callback ): array {
		$ids     = array_values( array_unique( array_map( 'intval', $blog_ids ) ) );
		$ids     = array_slice( $ids, 0, self::MAX_SITES );
		$started = microtime( true );
		$budget  = self::budget();
		$done    = array();
		$left    = array();

		foreach ( $ids as $index => $blog_id ) {
			if ( microtime( true ) - $started > $budget ) {
				$left = array_slice( $ids, $index );
				break;
			}

			try {
				$done[ $blog_id ] = self::in_site( $blog_id, $callback );
			} catch ( \Throwable $failure ) {
				$done[ $blog_id ] = new \WP_Error( 'estasoft_nd_site_failed', $failure->getMessage() );
			}
		}

		return array(
			'done' => $done,
			'left' => $left,
		);
	}
}

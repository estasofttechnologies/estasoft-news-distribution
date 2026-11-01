<?php
/**
 * The three delivery questions the Network screens ask of one site's log.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Network;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Db\Schema;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Every method reads the delivery log of the site that is current, so a caller runs it inside
 * `SiteLoop`. A site that never ran the plugin has no table; every method then answers "nothing".
 */
final class DeliveryQueries {

	/**
	 * A delivery that took longer than this many seconds is slow.
	 */
	public const SLOW_SECONDS = 15;

	/**
	 * Most `sent` rows looked at when working out the slowest delivery of a period.
	 */
	private const SLOW_WINDOW = 2000;

	/**
	 * Whether this site has a delivery log table.
	 *
	 * @return bool
	 */
	public static function has_table(): bool {
		return Tables::exists( Schema::table( 'delivery_log' ) );
	}

	/**
	 * Sent and failed deliveries since a time, by channel.
	 *
	 * @param int $since Unix time.
	 * @return array<string, array{sent: int, failed: int}> Channel id => counts.
	 */
	public static function by_channel( int $since ): array {
		global $wpdb;

		if ( ! self::has_table() ) {
			return array();
		}

		$table = Schema::table( 'delivery_log' );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT channel, status, COUNT(*) AS total FROM %i WHERE created_gmt >= %s AND status IN ( %s, %s, %s ) GROUP BY channel, status', $table, Clock::to_gmt( $since ), Status::SENT, 'success', Status::FAILED ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id = (string) $row['channel'];

			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = array(
					'sent'   => 0,
					'failed' => 0,
				);
			}

			$key = Status::FAILED === $row['status'] ? 'failed' : 'sent';

			$out[ $id ][ $key ] = $out[ $id ][ $key ] + (int) $row['total'];
		}

		return $out;
	}

	/**
	 * Deliveries that wait now: the newest row of a delivery says queued or retrying.
	 *
	 * @return int
	 */
	public static function queued(): int {
		global $wpdb;

		if ( ! self::has_table() ) {
			return 0;
		}

		$table = Schema::table( 'delivery_log' );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i l WHERE l.status IN ( %s, %s ) AND NOT EXISTS ( SELECT 1 FROM %i n WHERE n.post_id = l.post_id AND n.channel = l.channel AND n.language = l.language AND n.event = l.event AND n.id > l.id )',
				$table,
				Status::QUEUED,
				Status::RETRY,
				$table
			)
		);
	}

	/**
	 * The longest a delivery took since a time, in seconds.
	 *
	 * The log has no call duration, so this is how long after its planned time a delivery was
	 * sent: from the `run_at` of its last queued or retrying row to the row that says sent. It
	 * looks at the newest 2,000 sent deliveries of the period.
	 *
	 * @param int $since Unix time.
	 * @return int Seconds, or 0 when nothing was sent.
	 */
	public static function slowest( int $since ): int {
		global $wpdb;

		if ( ! self::has_table() ) {
			return 0;
		}

		$table = Schema::table( 'delivery_log' );

		return max(
			0,
			(int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT MAX( TIMESTAMPDIFF( SECOND, q.run_at_gmt, s.created_gmt ) ) FROM ( SELECT id, post_id, channel, language, event, created_gmt FROM %i WHERE status IN ( %s, %s ) AND created_gmt >= %s ORDER BY id DESC LIMIT %d ) s INNER JOIN %i q ON q.id = ( SELECT MAX( q2.id ) FROM %i q2 WHERE q2.post_id = s.post_id AND q2.channel = s.channel AND q2.language = s.language AND q2.event = s.event AND q2.id < s.id AND q2.status IN ( %s, %s ) AND q2.run_at_gmt IS NOT NULL )',
					$table,
					Status::SENT,
					'success',
					Clock::to_gmt( $since ),
					self::SLOW_WINDOW,
					$table,
					$table,
					Status::QUEUED,
					Status::RETRY
				)
			)
		);
	}

	/**
	 * When the site last had a platform fetch a feed.
	 *
	 * @return int Unix time, or 0 when never.
	 */
	public static function last_fetch(): int {
		global $wpdb;

		$table = Schema::table( 'fetch_log' );

		if ( ! Tables::exists( $table ) ) {
			return 0;
		}

		return Clock::from_gmt( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(created_gmt) FROM %i', $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}

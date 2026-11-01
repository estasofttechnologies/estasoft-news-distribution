<?php
/**
 * What the fetch log says about one platform: last fetch, the last 24 hours and the last 7 days.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports\Parts;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads `{prefix}estasoft_nd_fetch_log` by crawler name across the channels that can
 * answer one address, so "fetched by Bingbot" is true whichever channel replied.
 * Only answers that carried a document count as a fetch (`ok` and `not_modified`):
 * a 404 or a 410 is a request, not a fetch. The log holds only requests that reach
 * WordPress, so a feed served from a cache is not counted.
 */
final class FetchStats {

	/**
	 * Days of per-day bars.
	 */
	public const DAYS = 7;

	/**
	 * The empty figures of one language or one platform.
	 *
	 * @return array{last_ts: int, fetches_24h: int, days: int[], average_ms: int, short_24h: int, agent: string}
	 */
	public static function blank(): array {
		return array(
			'last_ts'     => 0,
			'fetches_24h' => 0,
			'days'        => array_fill( 0, self::DAYS, 0 ),
			'average_ms'  => 0,
			'short_24h'   => 0,
			'agent'       => '',
		);
	}

	/**
	 * The figures of a platform, per language.
	 *
	 * Days run from the oldest to today: the last bar is the last 24 hours, the one before
	 * it the 24 hours before that, and so on. They are rolling days, not calendar days.
	 *
	 * @param string[] $channel_ids Channels that may have answered.
	 * @param string[] $agents      Crawler names from the fetch log, such as "googlebot".
	 * @param int      $now         Unix time to count from.
	 * @return array<string, array{last_ts: int, fetches_24h: int, days: int[], average_ms: int, short_24h: int, agent: string}> Language code => figures.
	 */
	public static function by_language( array $channel_ids, array $agents, int $now ): array {
		global $wpdb;

		$channel_ids = array_values( array_filter( array_map( 'strval', $channel_ids ) ) );
		$agents      = array_values( array_filter( array_map( 'strval', $agents ) ) );

		if ( array() === $channel_ids || array() === $agents ) {
			return array();
		}

		$table = Schema::table( 'fetch_log' );
		$in_ch = implode( ',', array_fill( 0, count( $channel_ids ), '%s' ) );
		$in_ag = implode( ',', array_fill( 0, count( $agents ), '%s' ) );
		$out   = array();

		// Last fetch, ever.
		$last = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT language, agent, MAX(created_gmt) AS last_gmt FROM %i WHERE channel IN ( {$in_ch} ) AND agent IN ( {$in_ag} ) AND status IN ( 'ok', 'not_modified' ) GROUP BY language, agent", array_merge( array( $table ), $channel_ids, $agents ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only %s placeholders are added to the lists.
			ARRAY_A
		);

		foreach ( is_array( $last ) ? $last : array() as $row ) {
			$language = (string) $row['language'];
			$stamp    = Clock::from_gmt( (string) $row['last_gmt'] );

			if ( ! isset( $out[ $language ] ) ) {
				$out[ $language ] = self::blank();
			}

			if ( $stamp > $out[ $language ]['last_ts'] ) {
				$out[ $language ]['last_ts'] = $stamp;
				$out[ $language ]['agent']   = (string) $row['agent'];
			}
		}

		// The last seven rolling days. Bucket 0 is the last 24 hours.
		$args = array_merge( array( Clock::to_gmt( $now ), $table ), $channel_ids, $agents, array( Clock::to_gmt( $now - ( self::DAYS * DAY_IN_SECONDS ) ) ) );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT language, GREATEST( 0, FLOOR( TIMESTAMPDIFF( SECOND, created_gmt, %s ) / 86400 ) ) AS bucket, COUNT(*) AS fetches, SUM(duration_ms) AS milliseconds, SUM( CASE WHEN response_code = 304 THEN 1 ELSE 0 END ) AS short_replies FROM %i WHERE channel IN ( {$in_ch} ) AND agent IN ( {$in_ag} ) AND status IN ( 'ok', 'not_modified' ) AND created_gmt >= %s GROUP BY language, bucket", $args ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only %s placeholders are added to the lists.
			ARRAY_A
		);

		$milliseconds = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$language = (string) $row['language'];
			$bucket   = (int) $row['bucket'];

			if ( $bucket < 0 || $bucket >= self::DAYS ) {
				continue;
			}

			if ( ! isset( $out[ $language ] ) ) {
				$out[ $language ] = self::blank();
			}

			// Oldest first: bucket 0 (the last 24 hours) is the last bar.
			$out[ $language ]['days'][ self::DAYS - 1 - $bucket ] += (int) $row['fetches'];

			if ( 0 === $bucket ) {
				$out[ $language ]['fetches_24h'] += (int) $row['fetches'];
				$out[ $language ]['short_24h']   += (int) $row['short_replies'];
				$milliseconds[ $language ]        = ( $milliseconds[ $language ] ?? 0 ) + (int) $row['milliseconds'];
			}
		}

		foreach ( $out as $language => $figures ) {
			$out[ $language ]['average_ms'] = $figures['fetches_24h'] > 0 ? (int) round( ( $milliseconds[ $language ] ?? 0 ) / $figures['fetches_24h'] ) : 0;
		}

		return $out;
	}

	/**
	 * Adds the figures of several languages into one.
	 *
	 * @param array<int, array{last_ts: int, fetches_24h: int, days: int[], average_ms: int, short_24h: int, agent: string}> $parts Figures to add.
	 * @return array{last_ts: int, fetches_24h: int, days: int[], average_ms: int, short_24h: int, agent: string}
	 */
	public static function sum( array $parts ): array {
		$total  = self::blank();
		$weight = 0;
		$ms     = 0;

		foreach ( $parts as $part ) {
			if ( (int) $part['last_ts'] > $total['last_ts'] ) {
				$total['last_ts'] = (int) $part['last_ts'];
				$total['agent']   = (string) $part['agent'];
			}

			$total['fetches_24h'] += (int) $part['fetches_24h'];
			$total['short_24h']   += (int) $part['short_24h'];
			$weight               += (int) $part['fetches_24h'];
			$ms                   += (int) $part['average_ms'] * (int) $part['fetches_24h'];

			foreach ( array_values( $part['days'] ) as $index => $count ) {
				if ( isset( $total['days'][ $index ] ) ) {
					$total['days'][ $index ] += (int) $count;
				}
			}
		}

		$total['average_ms'] = $weight > 0 ? (int) round( $ms / $weight ) : 0;

		return $total;
	}
}

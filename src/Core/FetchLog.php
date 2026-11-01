<?php
/**
 * Fetch log: who asked for a sitemap or feed, and what they got.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Core\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Written by the feed controller for named platforms only. It stores no IP
 * address and no full user agent; `agent` is a platform name or "other".
 */
final class FetchLog {

	/**
	 * Full table name, with the site's prefix.
	 *
	 * @return string
	 */
	public function table(): string {
		return Schema::table( 'fetch_log' );
	}

	/**
	 * Adds one row.
	 *
	 * @param string $channel     Channel ID.
	 * @param string $language    Language code.
	 * @param string $agent       Platform name, such as "googlebot", or "other".
	 * @param string $status      ok, not_modified, gone, not_found or error.
	 * @param int    $code        HTTP status sent.
	 * @param int    $duration_ms Time to answer.
	 * @param bool   $conditional Whether the request asked "has it changed?".
	 * @param int    $bytes       Bytes in the body that was sent.
	 * @return void
	 */
	public function record( string $channel, string $language, string $agent, string $status, int $code, int $duration_ms = 0, bool $conditional = false, int $bytes = 0 ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			array(
				'channel'       => $channel,
				'language'      => $language,
				'agent'         => $agent,
				'status'        => $status,
				'response_code' => $code,
				'duration_ms'   => max( 0, $duration_ms ),
				'conditional'   => $conditional ? 1 : 0,
				'bytes'         => max( 0, $bytes ),
				'created_gmt'   => Clock::to_gmt(),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' )
		);
	}

	/**
	 * The latest fetch of each channel and language.
	 *
	 * @return array<string, array<string, mixed>> Key "channel|language" => row with created_ts.
	 */
	public function latest(): array {
		global $wpdb;

		$table = $this->table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT f.* FROM %i f INNER JOIN ( SELECT channel, language, MAX(id) AS max_id FROM %i GROUP BY channel, language ) m ON m.max_id = f.id', $table, $table ),
			ARRAY_A
		);

		$latest = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row['created_ts']                                  = Clock::from_gmt( (string) $row['created_gmt'] );
			$latest[ $row['channel'] . '|' . $row['language'] ] = $row;
		}

		return $latest;
	}

	/**
	 * Rows for one channel, newest first.
	 *
	 * @param string $channel  Channel ID.
	 * @param int    $since    Unix time to start from.
	 * @param int    $limit    Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_channel( string $channel, int $since, int $limit = 200 ): array {
		global $wpdb;

		$table = $this->table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i WHERE channel = %s AND created_gmt >= %s ORDER BY id DESC LIMIT %d', $table, $channel, Clock::to_gmt( $since ), $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row['created_ts'] = Clock::from_gmt( (string) $row['created_gmt'] );
			$out[]             = $row;
		}

		return $out;
	}

	/**
	 * Rows for one named crawler, whichever of the given channels answered, newest first.
	 *
	 * Bing, Yandex and Baidu share one sitemap address. The log names the channel that answered, which is not always the
	 * platform that asked, so what a platform fetched is read by crawler name (the `agent` column).
	 *
	 * @param string[] $channels Channel ids that may have answered.
	 * @param string[] $agents   Crawler names from the log, such as "baidu".
	 * @param int      $since    Unix time to start from.
	 * @param int      $limit    Maximum rows.
	 * @param bool     $served   Only requests that were answered with the document (`ok` and `not_modified`).
	 * @return array<int, array<string, mixed>>
	 */
	public function for_agents( array $channels, array $agents, int $since, int $limit = 200, bool $served = false ): array {
		global $wpdb;

		$channels = array_values( array_filter( array_map( 'strval', $channels ) ) );
		$agents   = array_values( array_filter( array_map( 'strval', $agents ) ) );

		if ( array() === $channels || array() === $agents ) {
			return array();
		}

		$table = $this->table();
		$in_ch = implode( ',', array_fill( 0, count( $channels ), '%s' ) );
		$in_ag = implode( ',', array_fill( 0, count( $agents ), '%s' ) );
		$args  = array_merge( array( $table ), $channels, $agents, array( $served ? 1 : 0, Clock::to_gmt( $since ), $limit ) );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM %i WHERE channel IN ( {$in_ch} ) AND agent IN ( {$in_ag} ) AND ( %d = 0 OR status IN ( 'ok', 'not_modified' ) ) AND created_gmt >= %s ORDER BY id DESC LIMIT %d", $args ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only %s placeholders and fixed text are added.
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row['created_ts'] = Clock::from_gmt( (string) $row['created_gmt'] );
			$out[]             = $row;
		}

		return $out;
	}

	/**
	 * The latest fetch by a named crawler for each language, whichever of the given channels answered.
	 *
	 * @param string[] $channels Channel ids that may have answered.
	 * @param string[] $agents   Crawler names from the log.
	 * @param bool     $served   Only requests that were answered with the document.
	 * @return array<string, array<string, mixed>> Language => row with created_ts. The newest row of all is also under "".
	 */
	public function latest_by_agent( array $channels, array $agents, bool $served = false ): array {
		$rows = $this->for_agents( $channels, $agents, 0, 500, $served );
		$out  = array();

		// Newest first, so the first row seen for a language is its latest.
		foreach ( $rows as $row ) {
			$language = (string) $row['language'];

			if ( ! isset( $out[ $language ] ) ) {
				$out[ $language ] = $row;
			}

			if ( ! isset( $out[''] ) ) {
				$out[''] = $row;
			}
		}

		return $out;
	}

	/**
	 * How often requests asked "has it changed?" and how often the short reply was sent.
	 *
	 * Bandwidth saved is an estimate: each short reply is counted as the average
	 * size of a full reply from the same channel in the same period.
	 *
	 * @param int $days Look-back period in days.
	 * @return array{requests: int, asked_percent: int, short_percent: int, saved_percent: int}
	 */
	public function performance( int $days ): array {
		global $wpdb;

		$table = $this->table();
		$since = Clock::to_gmt( Clock::now() - ( $days * DAY_IN_SECONDS ) );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT channel,
					COUNT(*) AS requests,
					SUM(conditional) AS asked,
					SUM(CASE WHEN response_code = 304 THEN 1 ELSE 0 END) AS short_replies,
					SUM(CASE WHEN response_code = 200 THEN bytes ELSE 0 END) AS full_bytes,
					SUM(CASE WHEN response_code = 200 THEN 1 ELSE 0 END) AS full_replies
				FROM %i WHERE created_gmt >= %s AND status IN ('ok','not_modified') GROUP BY channel",
				$table,
				$since
			),
			ARRAY_A
		);

		$requests = 0;
		$asked    = 0;
		$short    = 0;
		$saved    = 0.0;
		$would    = 0.0;

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$requests += (int) $row['requests'];
			$asked    += (int) $row['asked'];
			$short    += (int) $row['short_replies'];
			$average   = (int) $row['full_replies'] > 0 ? ( (float) $row['full_bytes'] / (int) $row['full_replies'] ) : 0.0;
			$saved    += $average * (int) $row['short_replies'];
			$would    += (float) $row['full_bytes'] + ( $average * (int) $row['short_replies'] );
		}

		return array(
			'requests'      => $requests,
			'asked_percent' => $requests > 0 ? (int) round( 100 * $asked / $requests ) : 0,
			'short_percent' => $requests > 0 ? (int) round( 100 * $short / $requests ) : 0,
			'saved_percent' => $would > 0 ? (int) round( 100 * $saved / $would ) : 0,
		);
	}

	/**
	 * Deletes rows older than a number of days.
	 *
	 * @param int $days Age limit in days.
	 * @return int Rows deleted.
	 */
	public function purge_older_than( int $days ): int {
		global $wpdb;

		$table  = $this->table();
		$cutoff = Clock::to_gmt( Clock::now() - ( $days * DAY_IN_SECONDS ) );

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'DELETE FROM %i WHERE created_gmt < %s', $table, $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}

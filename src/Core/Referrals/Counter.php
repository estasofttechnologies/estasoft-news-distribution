<?php
/**
 * Counts visits by source: one number per day, article and source.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Referrals;

use Estasoft\NewsDistribution\Core\Audit;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Db\Schema;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The only writer of the `estasoft_nd_referrals` table.
 *
 * A visit adds 1 to the row for its day (the site's own calendar day), article and source name.
 * The visit itself is not kept. The table has four columns (day, post_id, source, visits) and
 * nothing else: no address of any kind, no user agent, no time finer than the day, no user, no
 * cookie, no session, no referrer address (only the name of the source it maps to).
 *
 * Counting is off until an administrator turns on "Count visits by source" in Settings, Privacy
 * and data. Turning it off stops counting and keeps the counts until `delete_all()`.
 * Counts older than `RETENTION_DAYS` are deleted by the daily clean-up.
 *
 * A settings file can carry the counts (`export()`) and a file read later can add to them (`plan_restore()`, `restore()`).
 * That is the other way into the table: it adds figures and raises them, and never lowers one.
 *
 * Get it with `Plugin::instance()->make( Counter::class )`.
 */
final class Counter {

	/**
	 * Days a count is kept, today included.
	 */
	public const RETENTION_DAYS = 90;

	/**
	 * The periods the report offers, in days.
	 *
	 * @var int[]
	 */
	public const PERIODS = array( 7, 30, 90 );

	/**
	 * The most figures a settings file holds, newest days first. A figure takes about 45 bytes in the downloaded file, so this
	 * keeps it well under the size a settings file may have (`SettingsBackup::MAX_BYTES`).
	 */
	public const EXPORT_ROWS = 15000;

	/**
	 * The most visits one figure may carry when it is read from a file. The column holds far more; this keeps a damaged file
	 * from writing a number nobody counted.
	 */
	public const MAX_VISITS = 100000000;

	/**
	 * How many rows go into one INSERT when figures from a file are written.
	 */
	private const BATCH = 500;

	private Plugin $plugin;

	/**
	 * Creates the counter.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Full table name, with the site's prefix.
	 *
	 * @return string
	 */
	public static function table(): string {
		return Schema::table( 'referrals' );
	}

	/**
	 * Whether this site counts visits by source.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return (bool) $this->plugin->settings()->get( 'privacy.count_visits', false );
	}

	/**
	 * The calendar day of a moment, in the site's time zone.
	 *
	 * @param int|null $timestamp Unix time, or null for now.
	 * @return string Y-m-d.
	 */
	public static function day( ?int $timestamp = null ): string {
		return Clock::format( null === $timestamp ? Clock::now() : $timestamp, 'Y-m-d' );
	}

	/**
	 * The first day a count of $days days covers: today and the days before it.
	 *
	 * @param int $days Length of the period.
	 * @return string Y-m-d.
	 */
	public static function first_day_of( int $days ): string {
		return self::shift( self::day(), 1 - max( 1, $days ) );
	}

	/**
	 * A calendar day some days before or after another.
	 *
	 * @param string $day  Y-m-d.
	 * @param int    $days Days to add; negative for earlier.
	 * @return string Y-m-d.
	 */
	public static function shift( string $day, int $days ): string {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $day, new \DateTimeZone( 'UTC' ) );

		if ( false === $date ) {
			return $day;
		}

		return $date->modify( sprintf( '%+d days', $days ) )->format( 'Y-m-d' );
	}

	/**
	 * Whether a post is a published, public article of a post type chosen in Settings, General.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function counts_for( int $post_id ): bool {
		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return 'publish' === $post->post_status
			&& '' === (string) $post->post_password
			&& in_array( $post->post_type, $this->plugin->settings()->post_types(), true )
			&& is_post_type_viewable( $post->post_type );
	}

	/**
	 * Adds one visit.
	 *
	 * @param int      $post_id   Article ID.
	 * @param string   $source    A source id from Sources.
	 * @param int|null $timestamp Unix time of the visit, or null for now. Only its day is used.
	 * @return bool Whether a visit was added.
	 */
	public function record( int $post_id, string $source, ?int $timestamp = null ): bool {
		global $wpdb;

		if ( $post_id < 1 || ! Sources::is_source( $source ) ) {
			return false;
		}

		$table = self::table();
		$done  = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'INSERT INTO %i (day, post_id, source, visits) VALUES (%s, %d, %s, 1) ON DUPLICATE KEY UPDATE visits = visits + 1',
				$table,
				self::day( $timestamp ),
				$post_id,
				$source
			)
		);

		return false !== $done;
	}

	/**
	 * Deletes counts older than the retention period. Runs after the daily clean-up.
	 *
	 * @return int Rows deleted.
	 */
	public function purge(): int {
		global $wpdb;

		$table   = self::table();
		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'DELETE FROM %i WHERE day < %s', $table, self::first_day_of( self::RETENTION_DAYS ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is built from the prefix.
		);

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Deletes every count. Turning counting off never does this; the "Delete the counts" button does.
	 *
	 * The person who pressed it is written to the audit trail.
	 *
	 * @return int Rows deleted.
	 */
	public function delete_all(): int {
		global $wpdb;

		$table   = self::table();
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is built from the prefix.
		$deleted = is_int( $deleted ) ? $deleted : 0;

		if ( get_current_user_id() > 0 ) {
			$this->plugin->audit()->record( Audit::SETTINGS, 0, __( 'Counts of visits by source were deleted.', 'estasoft-news-distribution' ) );
		}

		return $deleted;
	}

	/**
	 * Deletes the counts of one article, for when the article is deleted.
	 *
	 * @param int $post_id Article ID.
	 * @return int Rows deleted.
	 */
	public function delete_for_post( int $post_id ): int {
		global $wpdb;

		// Quiet on purpose: while the plugin is being uninstalled the table can be gone before the last article is deleted.
		$quiet   = $wpdb->suppress_errors( true );
		$deleted = $wpdb->delete( self::table(), array( 'post_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( $quiet );

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * The figures a settings file holds: the counts of the retention period, newest day first.
	 *
	 * Each figure is `[ day, article ID, source, visits ]`. Nothing else about a visit exists to export.
	 *
	 * @param int $limit The most figures to return; `EXPORT_ROWS` when zero. A filter can lower it (internal).
	 * @return array{rows: array<int, array{0: string, 1: int, 2: string, 3: int}>, truncated: bool}
	 */
	public function export( int $limit = 0 ): array {
		global $wpdb;

		/**
		 * Changes how many figures a settings file holds (internal).
		 *
		 * @param int $limit The most figures.
		 */
		$limit = max( 1, (int) apply_filters( 'estasoft_nd_internal_referral_export_rows', $limit > 0 ? $limit : self::EXPORT_ROWS ) );
		$table = self::table();
		$found = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT day, post_id, source, visits FROM %i WHERE day >= %s ORDER BY day DESC, visits DESC, post_id ASC, source ASC LIMIT %d',
				$table,
				self::first_day_of( self::RETENTION_DAYS ),
				$limit + 1
			),
			ARRAY_A
		);
		$found = is_array( $found ) ? $found : array();
		$more  = count( $found ) > $limit;
		$rows  = array();

		foreach ( array_slice( $found, 0, $limit ) as $row ) {
			$rows[] = array( (string) $row['day'], (int) $row['post_id'], (string) $row['source'], (int) $row['visits'] );
		}

		return array(
			'rows'      => $rows,
			'truncated' => $more,
		);
	}

	/**
	 * Sorts the figures of a settings file into what would be written and what would not, without writing anything.
	 *
	 * A figure is taken when its day is inside the retention period and not in the future, its article exists on this site,
	 * its source is one this plugin names, and its visits are a sensible number. It is written only if it is new here or
	 * higher than what is here: a count is never lowered, so the same file read twice changes nothing the second time.
	 *
	 * @param array<int|string, mixed> $figures The `rows` of the file.
	 * @return array{add: array<int, array{0: string, 1: int, 2: string, 3: int}>, raise: array<int, array{0: string, 1: int, 2: string, 3: int}>, same: int, skipped: array{shape: int, old: int, article: int, source: int, visits: int}}
	 */
	public function plan_restore( array $figures ): array {
		global $wpdb;

		$first   = self::first_day_of( self::RETENTION_DAYS );
		$today   = self::day();
		$skipped = array(
			'shape'   => 0,
			'old'     => 0,
			'article' => 0,
			'source'  => 0,
			'visits'  => 0,
		);
		$clean   = array();

		foreach ( $figures as $figure ) {
			if ( ! is_array( $figure ) || 4 !== count( $figure ) || ! isset( $figure[0], $figure[1], $figure[2], $figure[3] ) || ! is_string( $figure[0] ) || ! is_scalar( $figure[1] ) || ! is_string( $figure[2] ) || ! is_scalar( $figure[3] ) ) {
				++$skipped['shape'];
				continue;
			}

			$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $figure[0], new \DateTimeZone( 'UTC' ) );

			if ( false === $day || $day->format( 'Y-m-d' ) !== $figure[0] || ! ctype_digit( (string) $figure[1] ) || ! ctype_digit( (string) $figure[3] ) ) {
				++$skipped['shape'];
				continue;
			}

			if ( $figure[0] < $first || $figure[0] > $today ) {
				++$skipped['old'];
				continue;
			}

			if ( ! Sources::is_source( $figure[2] ) ) {
				++$skipped['source'];
				continue;
			}

			$visits = (int) $figure[3];

			if ( $visits < 1 || $visits > self::MAX_VISITS ) {
				++$skipped['visits'];
				continue;
			}

			$clean[ $figure[0] . '|' . (int) $figure[1] . '|' . $figure[2] ] = array( $figure[0], (int) $figure[1], $figure[2], $visits );
		}

		// Only the articles this site has: the figures name them by number.
		$ids = array_values( array_unique( array_map( 'intval', array_column( $clean, 1 ) ) ) );
		$has = array();

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in    = implode( ',', array_map( 'absint', $chunk ) );
			$found = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$in})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Whole numbers only.

			foreach ( (array) $found as $id ) {
				$has[ (int) $id ] = true;
			}
		}

		// What is here now, for the days the file covers.
		$days    = array_values( array_unique( array_column( $clean, 0 ) ) );
		$current = array();
		$table   = self::table();

		foreach ( array_chunk( $days, 100 ) as $chunk ) {
			$marks = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT day, post_id, source, visits FROM %i WHERE day IN ({$marks})", array_merge( array( $table ), $chunk ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only %s placeholders are added to the list; they are counted above.
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$current[ $row['day'] . '|' . (int) $row['post_id'] . '|' . $row['source'] ] = (int) $row['visits'];
			}
		}

		$plan = array(
			'add'     => array(),
			'raise'   => array(),
			'same'    => 0,
			'skipped' => $skipped,
		);

		foreach ( $clean as $key => $figure ) {
			if ( ! isset( $has[ $figure[1] ] ) ) {
				++$plan['skipped']['article'];
				continue;
			}

			if ( ! isset( $current[ $key ] ) ) {
				$plan['add'][] = $figure;
			} elseif ( $current[ $key ] < $figure[3] ) {
				$plan['raise'][] = $figure;
			} else {
				++$plan['same'];
			}
		}

		return $plan;
	}

	/**
	 * Writes the figures `plan_restore()` said are new or higher.
	 *
	 * A new figure is inserted, and a figure already here is raised only while it is still lower, so a count that grew
	 * between the preview and the write is never lowered.
	 *
	 * @param array<int, array{0: string, 1: int, 2: string, 3: int}> $add   Figures this site does not have.
	 * @param array<int, array{0: string, 1: int, 2: string, 3: int}> $raise Figures this site has with fewer visits.
	 * @return int Figures written.
	 */
	public function restore( array $add, array $raise ): int {
		global $wpdb;

		$table   = self::table();
		$written = 0;

		foreach ( array_chunk( $add, self::BATCH ) as $chunk ) {
			$args = array( $table );

			foreach ( $chunk as $figure ) {
				$args[] = (string) $figure[0];
				$args[] = (int) $figure[1];
				$args[] = (string) $figure[2];
				$args[] = (int) $figure[3];
			}

			$done = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'INSERT IGNORE INTO %i (day, post_id, source, visits) VALUES ' . implode( ', ', array_fill( 0, count( $chunk ), '(%s, %d, %s, %d)' ) ), $args ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One group of placeholders for each figure.
			);

			$written += is_int( $done ) ? $done : 0;
		}

		foreach ( $raise as $figure ) {
			$done = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'UPDATE %i SET visits = %d WHERE day = %s AND post_id = %d AND source = %s AND visits < %d',
					$table,
					(int) $figure[3],
					(string) $figure[0],
					(int) $figure[1],
					(string) $figure[2],
					(int) $figure[3]
				)
			);

			$written += is_int( $done ) ? $done : 0;
		}

		return $written;
	}

	/**
	 * How many rows are stored.
	 *
	 * @return int
	 */
	public function rows(): int {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is built from the prefix.
	}

	/**
	 * The oldest day that has a count.
	 *
	 * @return string Y-m-d, or an empty string when nothing is counted.
	 */
	public function since(): string {
		global $wpdb;

		$table = self::table();
		$day   = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(day) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is built from the prefix.

		return is_string( $day ) ? $day : '';
	}
}

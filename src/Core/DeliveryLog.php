<?php
/**
 * Delivery log.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Core\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Records every queued, delivered, retried and failed push. Statuses are stored
 * as the Status codes; the old "success" is read as "sent".
 */
final class DeliveryLog {

	/**
	 * Full table name, with the site's prefix.
	 *
	 * @return string
	 */
	public function table(): string {
		return Schema::table( 'delivery_log' );
	}

	/**
	 * Adds one log row.
	 *
	 * @param int      $post_id       Post ID.
	 * @param string   $channel       Channel ID.
	 * @param string   $language      Language code.
	 * @param string   $event         Event type.
	 * @param string   $status        A result code: queued, sent, retry, failed or skipped.
	 * @param int      $attempt       Attempt number.
	 * @param int      $response_code HTTP status of the remote call, or 0.
	 * @param string   $message       Human-readable detail. Never a secret.
	 * @param int|null $run_at        Unix time a queued or retrying delivery will run.
	 * @return int Row ID, or 0 when the insert failed.
	 */
	public function record( int $post_id, string $channel, string $language, string $event, string $status, int $attempt = 1, int $response_code = 0, string $message = '', ?int $run_at = null ): int {
		global $wpdb;

		$row    = array(
			'post_id'       => $post_id,
			'channel'       => $channel,
			'language'      => $language,
			'event'         => $event,
			'status'        => 'success' === $status ? Status::SENT : $status,
			'attempt'       => $attempt,
			'response_code' => $response_code,
			'message'       => $message,
			'created_gmt'   => Clock::to_gmt(),
		);
		$format = array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' );

		if ( null !== $run_at ) {
			$row['run_at_gmt'] = Clock::to_gmt( $run_at );
			$format[]          = '%s';
		}

		$inserted = $wpdb->insert( $this->table(), $row, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Newest rows first.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( int $limit = 50 ): array {
		return $this->query( array( 'per_page' => $limit ) )['rows'];
	}

	/**
	 * One row.
	 *
	 * @param int $id Row ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = $this->table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/**
	 * A page of rows with filters.
	 *
	 * Filters: channel, status, language, post (post ID), posts (a list of post IDs;
	 * an empty list matches nothing), since (Unix time: rows made at or after it),
	 * only_author (restrict to articles written by this user), latest_only (leave out
	 * a waiting or retrying row once a later row exists for the same push, so a push
	 * shows once), page, per_page (at most 100).
	 *
	 * @param array<string, mixed> $args Filters and paging.
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$table    = $this->table();
		$posts    = $wpdb->posts;
		$where    = array( '1=1' );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		// Every condition is prepared as it is added, so the finished query is only put together from prepared parts.
		$columns = array(
			'channel'  => 'l.channel = %s',
			'status'   => 'l.status = %s',
			'language' => 'l.language = %s',
		);

		foreach ( $columns as $key => $condition ) {
			if ( isset( $args[ $key ] ) && '' !== (string) $args[ $key ] ) {
				$where[] = $wpdb->prepare( $condition, 'status' === $key && 'success' === $args[ $key ] ? Status::SENT : (string) $args[ $key ] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- A fixed condition from the list above.
			}
		}

		if ( ! empty( $args['post'] ) ) {
			$where[] = $wpdb->prepare( 'l.post_id = %d', (int) $args['post'] );
		}

		if ( isset( $args['posts'] ) && is_array( $args['posts'] ) ) {
			$ids = array_values( array_filter( array_map( 'intval', $args['posts'] ) ) );

			if ( array() === $ids ) {
				$where[] = '1=0';
			} else {
				$where[] = $wpdb->prepare( 'l.post_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only %d placeholders are added.
			}
		}

		if ( ! empty( $args['since'] ) ) {
			$where[] = $wpdb->prepare( 'l.created_gmt >= %s', Clock::to_gmt( (int) $args['since'] ) );
		}

		if ( ! empty( $args['latest_only'] ) ) {
			// A push that was queued or is being retried has a later row once it moved on. Rows with no article (catch-up batches, tests) are separate pushes.
			$where[] = $wpdb->prepare( "( l.post_id = 0 OR l.status NOT IN ( 'queued', 'retry' ) OR NOT EXISTS ( SELECT 1 FROM %i n WHERE n.post_id = l.post_id AND n.channel = l.channel AND n.language = l.language AND n.event = l.event AND n.id > l.id ) )", $table );
		}

		$from = $wpdb->prepare( '%i l', $table );
		if ( ! empty( $args['only_author'] ) ) {
			$from   .= $wpdb->prepare( ' INNER JOIN %i p ON p.ID = l.post_id', $posts );
			$where[] = $wpdb->prepare( 'p.post_author = %d', (int) $args['only_author'] );
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = ( $page - 1 ) * $per_page;
		$count_sql = 'SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $where_sql;
		$rows_sql  = 'SELECT l.* FROM ' . $from . ' WHERE ' . $where_sql . $wpdb->prepare( ' ORDER BY l.id DESC LIMIT %d OFFSET %d', $per_page, $offset );

		$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Put together from prepared parts above.
		$rows  = $wpdb->get_results( $rows_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Put together from prepared parts above.

		return array(
			'rows'  => array_map( array( $this, 'normalize' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Whether a later row exists for the same push: the same article, channel,
	 * language and event. A failed push that has been started again has one.
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public function has_later( int $id ): bool {
		global $wpdb;

		$table = $this->table();
		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT n.id FROM %i l INNER JOIN %i n ON n.post_id = l.post_id AND n.channel = l.channel AND n.language = l.language AND n.event = l.event AND n.id > l.id WHERE l.id = %d AND l.post_id > 0 LIMIT 1',
				$table,
				$table,
				$id
			)
		);

		return null !== $found;
	}

	/**
	 * The latest row for each channel of one article.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, array<string, mixed>> Channel => row.
	 */
	public function latest_for_post( int $post_id ): array {
		global $wpdb;

		$table = $this->table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d ORDER BY id DESC LIMIT 200', $table, $post_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$latest = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row = $this->normalize( $row );
			if ( ! isset( $latest[ $row['channel'] ] ) ) {
				$latest[ $row['channel'] ] = $row;
			}
		}

		return $latest;
	}

	/**
	 * Counts of rows by status since a time.
	 *
	 * @param int $since Unix time.
	 * @return array<string, int> Status code => count.
	 */
	public function counts_since( int $since ): array {
		global $wpdb;

		$table = $this->table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i WHERE created_gmt >= %s GROUP BY status', $table, Clock::to_gmt( $since ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$code            = Status::from_log( (string) $row['status'] );
			$counts[ $code ] = ( $counts[ $code ] ?? 0 ) + (int) $row['total'];
		}

		return $counts;
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

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'DELETE FROM %i WHERE created_gmt < %s', $table, $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return (int) $deleted;
	}

	/**
	 * Adds the display fields screens need: a result code, a Unix time.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function normalize( array $row ): array {
		$row['id']            = (int) $row['id'];
		$row['post_id']       = (int) $row['post_id'];
		$row['attempt']       = (int) $row['attempt'];
		$row['response_code'] = (int) $row['response_code'];
		$row['status']        = Status::from_log( (string) $row['status'] );
		$row['created_ts']    = Clock::from_gmt( (string) $row['created_gmt'] );
		$row['run_at_ts']     = Clock::from_gmt( (string) ( $row['run_at_gmt'] ?? '' ) );

		return $row;
	}
}

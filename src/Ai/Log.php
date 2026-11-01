<?php
/**
 * The AI log: every suggestion, answer and draft, and what a person decided.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * One row of `{prefix}estasoft_nd_ai_log` per request. The row is written when the suggestion is made,
 * with the decision `pending`, and completed by `POST /ai/decision` with the person who decided.
 *
 * What a row holds: the feature, the article, the connection that answered (a provider id and a model name,
 * never a key), the decision, the person, and `summary`, which is JSON of what the AI suggested and what was
 * chosen. It is the suggestion, such as a new title or a translation draft excerpt, and not a copy of the
 * article. Questions to the assistant are kept without the article text. Nothing is ever an API key: no
 * code here reads one.
 */
final class Log {

	public const PENDING    = 'pending';
	public const USED       = 'used';
	public const USED_FEEDS = 'used_feeds';
	public const EDITED     = 'edited';
	public const DISMISSED  = 'dismissed';
	public const ANSWERED   = 'answered';
	public const REVIEW     = 'review';
	public const APPROVED   = 'approved';
	public const COPIED     = 'copied';
	public const NONE       = 'none';
	public const AUTO       = 'auto';

	/**
	 * The longest text kept for one suggestion.
	 */
	public const MAX_TEXT = 4000;

	/**
	 * Every decision with its words.
	 *
	 * @return array<string, string>
	 */
	public static function decisions(): array {
		return array(
			self::PENDING    => __( 'Not decided yet', 'estasoft-news-distribution' ),
			self::USED       => __( 'Used', 'estasoft-news-distribution' ),
			self::USED_FEEDS => __( 'Used in feeds only', 'estasoft-news-distribution' ),
			self::EDITED     => __( 'Edited, then used', 'estasoft-news-distribution' ),
			self::DISMISSED  => __( 'Dismissed', 'estasoft-news-distribution' ),
			self::ANSWERED   => __( 'Answered, source shown', 'estasoft-news-distribution' ),
			self::REVIEW     => __( 'Not reviewed yet', 'estasoft-news-distribution' ),
			self::APPROVED   => __( 'Approved', 'estasoft-news-distribution' ),
			self::COPIED     => __( 'Copied', 'estasoft-news-distribution' ),
			self::NONE       => __( 'Nothing suggested', 'estasoft-news-distribution' ),
			self::AUTO       => __( 'Used by your rule', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public function table(): string {
		return Schema::table( 'ai_log' );
	}

	/**
	 * Whether this site has the table (WordPress 7.0 and newer).
	 *
	 * @return bool
	 */
	public function available(): bool {
		return in_array( 'ai_log', Schema::names(), true );
	}

	/**
	 * Writes a row.
	 *
	 * @param array{post_id?: int, feature: string, connection?: string, decision?: string, reviewer_id?: int, data?: array<string, mixed>} $row The row.
	 * @return int The new id, or 0 when nothing was written.
	 */
	public function add( array $row ): int {
		global $wpdb;

		if ( ! $this->available() ) {
			return 0;
		}

		$done = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			array(
				'post_id'     => (int) ( $row['post_id'] ?? 0 ),
				'feature'     => substr( sanitize_key( (string) $row['feature'] ), 0, 40 ),
				'connection'  => substr( sanitize_text_field( (string) ( $row['connection'] ?? '' ) ), 0, 60 ),
				'decision'    => substr( sanitize_key( (string) ( $row['decision'] ?? self::PENDING ) ), 0, 20 ),
				'reviewer_id' => (int) ( $row['reviewer_id'] ?? 0 ),
				'summary'     => $this->encode( (array) ( $row['data'] ?? array() ) ),
				'created_gmt' => Clock::to_gmt(),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return false === $done ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * One row.
	 *
	 * @param int $id Row id.
	 * @return array{id: int, post_id: int, feature: string, connection: string, decision: string, reviewer_id: int, data: array<string, mixed>, created: int}|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		if ( $id < 1 || ! $this->available() ) {
			return null;
		}

		$table = $this->table();
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $this->shape( $row ) : null;
	}

	/**
	 * Records the decision on a row, with the person who made it.
	 *
	 * @param int                  $id          Row id.
	 * @param string               $decision    One of the decisions.
	 * @param int                  $reviewer_id The person.
	 * @param array<string, mixed> $merge       Facts to add to the row's data, such as what was chosen.
	 * @return bool
	 */
	public function decide( int $id, string $decision, int $reviewer_id, array $merge = array() ): bool {
		global $wpdb;

		$row = $this->get( $id );

		if ( null === $row ) {
			return false;
		}

		$data = array_merge( $row['data'], $merge );
		$done = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A write to the plugin's own table.
			$this->table(),
			array(
				'decision'    => substr( sanitize_key( $decision ), 0, 20 ),
				'reviewer_id' => $reviewer_id,
				'summary'     => $this->encode( $data ),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		return false !== $done;
	}

	/**
	 * Adds facts to a row's data without touching the decision.
	 *
	 * @param int                  $id    Row id.
	 * @param array<string, mixed> $merge Facts to add.
	 * @return bool
	 */
	public function annotate( int $id, array $merge ): bool {
		global $wpdb;

		$row = $this->get( $id );

		if ( null === $row ) {
			return false;
		}

		return false !== $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A write to the plugin's own table.
			$this->table(),
			array( 'summary' => $this->encode( array_merge( $row['data'], $merge ) ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Rows, newest first.
	 *
	 * Arguments: `feature` (a REST feature name, a list of names, or empty), `decision` (or empty), `from` and `to` (Unix times),
	 * `post_id`, `limit` and `offset`.
	 *
	 * @param array<string, mixed> $args Filters.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		if ( ! $this->available() ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		// Every condition is prepared as it is added, so the finished query is only put together from prepared parts.
		$where = array( '1=1' );

		if ( ! empty( $args['feature'] ) ) {
			$names = array_values( array_filter( array_map( 'strval', (array) $args['feature'] ) ) );

			if ( array() !== $names ) {
				$where[] = $wpdb->prepare( 'feature IN (' . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ')', $names ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only %s placeholders are added.
			}
		}

		if ( ! empty( $args['decision'] ) ) {
			$where[] = $wpdb->prepare( 'decision = %s', (string) $args['decision'] );
		}

		if ( ! empty( $args['post_id'] ) ) {
			$where[] = $wpdb->prepare( 'post_id = %d', (int) $args['post_id'] );
		}

		if ( ! empty( $args['from'] ) ) {
			$where[] = $wpdb->prepare( 'created_gmt >= %s', Clock::to_gmt( (int) $args['from'] ) );
		}

		if ( ! empty( $args['to'] ) ) {
			$where[] = $wpdb->prepare( 'created_gmt < %s', Clock::to_gmt( (int) $args['to'] ) );
		}

		$table     = $this->table();
		$where_sql = implode( ' AND ', $where );
		$limit     = max( 1, min( 200, (int) ( $args['limit'] ?? 20 ) ) );
		$skip      = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$count_sql = $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ', $table ) . $where_sql;
		$rows_sql  = $wpdb->prepare( 'SELECT * FROM %i WHERE ', $table ) . $where_sql . $wpdb->prepare( ' ORDER BY created_gmt DESC, id DESC LIMIT %d OFFSET %d', $limit, $skip );

		$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Put together from prepared parts above.
		$rows  = $wpdb->get_results( $rows_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Put together from prepared parts above.

		return array(
			'items' => array_map( array( $this, 'shape' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * How many rows fall in each feature and decision between two times.
	 *
	 * @param int $from Start, Unix time.
	 * @param int $to   End, Unix time.
	 * @return array<int, array{feature: string, decision: string, total: int}>
	 */
	public function tally( int $from, int $to ): array {
		global $wpdb;

		if ( ! $this->available() ) {
			return array();
		}

		$table = $this->table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT feature, decision, COUNT(*) AS total FROM %i WHERE created_gmt >= %s AND created_gmt < %s GROUP BY feature, decision', $table, Clock::to_gmt( $from ), Clock::to_gmt( $to ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'feature'  => (string) $row['feature'],
				'decision' => (string) $row['decision'],
				'total'    => (int) $row['total'],
			);
		}

		return $out;
	}

	/**
	 * The newest row of a feature for one article that has no decision yet.
	 *
	 * @param string $feature REST feature name.
	 * @param int    $post_id Article.
	 * @return array<string, mixed>|null
	 */
	public function latest_for( string $feature, int $post_id ): ?array {
		$found = $this->query(
			array(
				'feature' => $feature,
				'post_id' => $post_id,
				'limit'   => 1,
			)
		);

		return $found['items'][0] ?? null;
	}

	/**
	 * Turns a stored row into the shape the rest of the code reads.
	 *
	 * @param array<string, mixed> $row A database row.
	 * @return array{id: int, post_id: int, feature: string, connection: string, decision: string, reviewer_id: int, data: array<string, mixed>, created: int}
	 */
	private function shape( array $row ): array {
		$data = json_decode( (string) $row['summary'], true );

		return array(
			'id'          => (int) $row['id'],
			'post_id'     => (int) $row['post_id'],
			'feature'     => (string) $row['feature'],
			'connection'  => (string) $row['connection'],
			'decision'    => (string) $row['decision'],
			'reviewer_id' => (int) $row['reviewer_id'],
			'data'        => is_array( $data ) ? $data : array(),
			'created'     => Clock::from_gmt( (string) $row['created_gmt'] ),
		);
	}

	/**
	 * Encodes a row's data, trimming long texts so the log never becomes a copy of the article.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return string JSON.
	 */
	private function encode( array $data ): string {
		$data = $this->trim( $data );
		$json = wp_json_encode( $data );

		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * Cuts every long string in a structure.
	 *
	 * @param mixed $value A value.
	 * @return mixed
	 */
	private function trim( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->trim( $item );
			}

			return $value;
		}

		return is_string( $value ) && mb_strlen( $value ) > self::MAX_TEXT ? mb_substr( $value, 0, self::MAX_TEXT ) : $value;
	}
}

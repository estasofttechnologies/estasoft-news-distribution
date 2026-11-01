<?php
/**
 * Audit trail for actions that change who can publish, what leaves the site or what is stored.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Core\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Gate overrides (with the typed reason), takedowns, imports, restores and key
 * revocations: who, what, which article, when. Kept as long as the delivery log.
 */
final class Audit {

	public const GATE_OVERRIDE = 'gate_override';
	public const GATE_SKIPPED  = 'gate_skipped';
	public const TAKEDOWN      = 'takedown';
	public const IMPORT        = 'import';
	public const RESTORE       = 'restore';
	public const KEY_REVOKED   = 'key_revoked';
	public const SETTINGS      = 'settings';
	public const HOLD          = 'hold';
	public const RELEASE       = 'release';
	public const SEND_NOW      = 'send_now';
	public const PLAN          = 'plan';
	public const ADDRESS       = 'address_changed';

	/**
	 * Full table name, with the site's prefix.
	 *
	 * @return string
	 */
	public function table(): string {
		return Schema::table( 'audit' );
	}

	/**
	 * Adds one entry for the signed-in user.
	 *
	 * @param string   $action  One of the class constants.
	 * @param int      $post_id Article, or 0.
	 * @param string   $detail  What happened, in a short sentence. Never a secret.
	 * @param int|null $user_id Person, or the signed-in user.
	 * @return void
	 */
	public function record( string $action, int $post_id = 0, string $detail = '', ?int $user_id = null ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			array(
				'user_id'     => null === $user_id ? get_current_user_id() : $user_id,
				'action'      => $action,
				'post_id'     => $post_id,
				'detail'      => $detail,
				'created_gmt' => Clock::to_gmt(),
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Newest entries first.
	 *
	 * @param int $limit Maximum entries.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( int $limit = 50 ): array {
		global $wpdb;

		$table = $this->table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * The entries of one article, newest first.
	 *
	 * @param int    $post_id Article ID.
	 * @param string $action  One of the class constants, or an empty string for every action.
	 * @param int    $limit   Maximum entries.
	 * @return array<int, array<string, mixed>> Rows with `created_ts` added.
	 */
	public function for_post( int $post_id, string $action = '', int $limit = 50 ): array {
		global $wpdb;

		$table = $this->table();
		$rows  = '' === $action
			? $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d ORDER BY id DESC LIMIT %d', $table, $post_id, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			)
			: $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d AND action = %s ORDER BY id DESC LIMIT %d', $table, $post_id, $action, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * Deletes entries older than a number of days.
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

<?php
/**
 * Whether a table of the current site exists.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Network;

defined( 'ABSPATH' ) || exit;

/**
 * The Network screens read sites that may have been added after the plugin was installed, so a
 * site's tables may not exist yet (they are made on that site's first request). Asking by reading
 * zero rows answers the same as `SHOW TABLES` for a normal table, and it also works for a table
 * the database keeps only for the connection, which a test uses.
 */
final class Tables {

	/**
	 * Whether the table exists.
	 *
	 * @param string $table Full table name, from `Schema::table()`.
	 * @return bool
	 */
	public static function exists( string $table ): bool {
		global $wpdb;

		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return false;
		}

		$suppressed = $wpdb->suppress_errors( true );
		$wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 0', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The name was checked above and comes from Schema.
		$found = '' === (string) $wpdb->last_error;
		$wpdb->suppress_errors( $suppressed );

		return $found;
	}
}

<?php
/**
 * The plugin's database tables.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and drops the tables. dbDelta makes a repeat run safe, so activation,
 * upgrades and a copied-in install all call install().
 *
 * Each site of a network has its own tables.
 */
final class Schema {

	public const VERSION = '4';

	public const OPTION = 'estasoft_nd_db_version';

	/**
	 * Which tables were made on this site. The set of tables depends on the WordPress version, so a site that is updated
	 * from WordPress 6 to 7 needs the table that only 7 has, although this plugin's own version has not changed.
	 */
	public const TABLES_OPTION = 'estasoft_nd_db_tables';

	/**
	 * Full table name, with the site's prefix.
	 *
	 * @param string $name delivery_log, fetch_log, fixes, audit, referrals or ai_log.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'estasoft_nd_' . $name;
	}

	/**
	 * Short names of the tables that exist on this site.
	 *
	 * The AI log exists only on WordPress 7.0 and newer.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		$names = array( 'delivery_log', 'fetch_log', 'fixes', 'audit', 'referrals' );

		if ( version_compare( (string) get_bloginfo( 'version' ), '7.0', '>=' ) ) {
			$names[] = 'ai_log';
		}

		return $names;
	}

	/**
	 * Creates or updates the tables.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		foreach ( self::statements( $charset ) as $name => $sql ) {
			if ( in_array( $name, self::names(), true ) ) {
				dbDelta( $sql );
			}
		}

		update_option( self::OPTION, self::VERSION, true );
		update_option( self::TABLES_OPTION, self::tables_marker(), true );
	}

	/**
	 * Whether the stored version is older than the code's, or the site now needs a table it was not given.
	 *
	 * @return bool
	 */
	public static function needs_upgrade(): bool {
		return self::VERSION !== get_option( self::OPTION ) || self::tables_marker() !== get_option( self::TABLES_OPTION );
	}

	/**
	 * The tables this site should have, written as one short text to compare with what was made.
	 *
	 * @return string
	 */
	private static function tables_marker(): string {
		return implode( ',', self::names() );
	}

	/**
	 * Drops every table. Used by uninstall when the owner asked for it.
	 *
	 * @return void
	 */
	public static function drop_all(): void {
		global $wpdb;

		foreach ( array( 'delivery_log', 'fetch_log', 'fixes', 'audit', 'ai_log', 'referrals' ) as $name ) {
			$table = self::table( $name );
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name is built from the prefix and a fixed list.
		}

		delete_option( self::OPTION );
	}

	/**
	 * The CREATE TABLE statements, in dbDelta's format.
	 *
	 * @param string $charset Charset and collation clause.
	 * @return array<string, string>
	 */
	private static function statements( string $charset ): array {
		$delivery = self::table( 'delivery_log' );
		$fetch    = self::table( 'fetch_log' );
		$fixes    = self::table( 'fixes' );
		$audit    = self::table( 'audit' );
		$ai       = self::table( 'ai_log' );
		$referral = self::table( 'referrals' );

		return array(
			// Aggregate counts only: one row per day, article and source name. No visitor data of any kind.
			'referrals'    => "CREATE TABLE {$referral} (
				day date NOT NULL,
				post_id bigint(20) unsigned NOT NULL,
				source varchar(40) NOT NULL,
				visits int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (day,post_id,source),
				KEY post_id (post_id)
			) {$charset};",
			'delivery_log' => "CREATE TABLE {$delivery} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL,
				channel varchar(40) NOT NULL,
				language varchar(20) NOT NULL DEFAULT '',
				event varchar(20) NOT NULL,
				status varchar(20) NOT NULL,
				attempt smallint(5) unsigned NOT NULL DEFAULT 1,
				response_code smallint(5) unsigned NOT NULL DEFAULT 0,
				message text NOT NULL,
				created_gmt datetime NOT NULL,
				run_at_gmt datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY post_channel (post_id,channel),
				KEY status_created (status,created_gmt),
				KEY created_gmt (created_gmt)
			) {$charset};",
			'fetch_log'    => "CREATE TABLE {$fetch} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				channel varchar(40) NOT NULL,
				language varchar(20) NOT NULL DEFAULT '',
				agent varchar(40) NOT NULL DEFAULT 'other',
				status varchar(20) NOT NULL,
				response_code smallint(5) unsigned NOT NULL DEFAULT 200,
				duration_ms int(10) unsigned NOT NULL DEFAULT 0,
				conditional tinyint(1) unsigned NOT NULL DEFAULT 0,
				bytes int(10) unsigned NOT NULL DEFAULT 0,
				created_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY channel_created (channel,created_gmt),
				KEY created_gmt (created_gmt)
			) {$charset};",
			'fixes'        => "CREATE TABLE {$fixes} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL,
				code varchar(60) NOT NULL,
				channel varchar(40) NOT NULL DEFAULT '',
				assignee_id bigint(20) unsigned NOT NULL,
				assigned_by bigint(20) unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'open',
				due_gmt datetime DEFAULT NULL,
				created_gmt datetime NOT NULL,
				resolved_gmt datetime DEFAULT NULL,
				note text NOT NULL,
				PRIMARY KEY  (id),
				KEY post_id (post_id),
				KEY assignee_status (assignee_id,status)
			) {$charset};",
			'audit'        => "CREATE TABLE {$audit} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				action varchar(40) NOT NULL,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				detail text NOT NULL,
				created_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY post_id (post_id),
				KEY created_gmt (created_gmt)
			) {$charset};",
			'ai_log'       => "CREATE TABLE {$ai} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				feature varchar(40) NOT NULL,
				connection varchar(60) NOT NULL DEFAULT '',
				decision varchar(20) NOT NULL DEFAULT '',
				reviewer_id bigint(20) unsigned NOT NULL DEFAULT 0,
				summary text NOT NULL,
				created_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY post_id (post_id),
				KEY created_gmt (created_gmt)
			) {$charset};",
		);
	}
}

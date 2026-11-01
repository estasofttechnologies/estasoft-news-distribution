<?php
/**
 * A restore point before the plugin is updated.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Operations;

defined( 'ABSPATH' ) || exit;

/**
 * Saves a copy of the settings just before the plugin's files are replaced, so an
 * update that goes wrong can be undone from Settings, Backup and uninstall.
 *
 * There are two moments, because a plugin can be updated in two ways:
 *
 * 1. WordPress updates it (a click on Update now, a bulk update, or an automatic
 *    update). WordPress fires `upgrader_pre_install` before it replaces the files,
 *    while the old code is still running, so `before_install()` can save the point
 *    with the version the update transient names ("Before updating to 1.1.0"), or
 *    without it ("Before an update"). This runs only for code that already holds
 *    this feature: the update that first brings it cannot save a point this way.
 * 2. The files are replaced some other way (FTP, a deploy, a manager, an upload that
 *    replaces the plugin). WordPress fires nothing the plugin hears. The plugin sees it
 *    at its next load, when the stored version (`estasoft_nd_version`) differs from the
 *    running one, and `before_migration()` saves the point before any migration runs
 *    ("Before the update to 1.1.0"). If moment 1 already saved one for that update, no
 *    second copy is saved.
 *
 * On a network, each site keeps its own points (they are made from that site's options).
 * `upgrader_pre_install` runs once, in the site WordPress runs the update from, so it
 * saves a point for that site only (the main site, for an update from the network admin).
 * Every other site gets its point the first time it loads after the update (moment 2).
 *
 * Neither moment may stop an update, so every failure here is swallowed and reported
 * through the internal action `estasoft_nd_internal_update_point_failed`.
 *
 * A point holds the settings the way `SettingsBackup` copies them: no secret.
 */
final class UpdatePoints {

	/**
	 * Saves a point just before WordPress replaces the plugin's files. This is a filter, and returns what it was given.
	 *
	 * @param mixed $response   What the filter chain holds so far: true, or a `WP_Error` that stops the update.
	 * @param mixed $hook_extra What WordPress says about the update: `plugin` holds the plugin's file for a plugin update.
	 * @return mixed The same `$response`.
	 */
	public static function before_install( $response, $hook_extra = array() ) {
		if ( is_wp_error( $response ) || ! self::is_this_plugin( $hook_extra ) ) {
			return $response;
		}

		try {
			SettingsBackup::save_update_point(
				self::announced_version(),
				self::running_version(),
				SettingsBackup::HOW_INSTALL,
				get_current_user_id()
			);
		} catch ( \Throwable $error ) {
			self::failed( $error );
		}

		return $response;
	}

	/**
	 * Saves a point when the plugin finds, at load time, that its files are not the version it last ran.
	 *
	 * Call it before anything is migrated. A first install (nothing stored) and a load with nothing new
	 * save nothing.
	 *
	 * @param string $stored The version saved in `estasoft_nd_version`, or an empty string.
	 * @return void
	 */
	public static function before_migration( string $stored ): void {
		$running = self::running_version();

		if ( '' === $stored || $running === $stored ) {
			return;
		}

		try {
			if ( SettingsBackup::has_update_point( $running, $stored ) || SettingsBackup::name_update_point( $stored, $running ) ) {
				return;
			}

			SettingsBackup::save_update_point( $running, $stored, SettingsBackup::HOW_LOAD, 0 );
		} catch ( \Throwable $error ) {
			self::failed( $error );
		}
	}

	/**
	 * The version of the code that is running now.
	 *
	 * @return string
	 */
	public static function running_version(): string {
		return defined( 'ESTASOFT_ND_VERSION' ) ? (string) ESTASOFT_ND_VERSION : '';
	}

	/**
	 * The version WordPress has announced for this plugin, from its list of plugin updates. Empty when the list does not name one.
	 *
	 * @return string
	 */
	public static function announced_version(): string {
		$list  = get_site_transient( 'update_plugins' );
		$entry = is_object( $list ) && isset( $list->response ) && is_array( $list->response ) ? ( $list->response[ self::basename() ] ?? null ) : null;

		if ( is_array( $entry ) ) {
			$entry = (object) $entry;
		}

		$version = is_object( $entry ) && isset( $entry->new_version ) && is_scalar( $entry->new_version ) ? trim( (string) $entry->new_version ) : '';

		// A version is a short run of letters, digits and dots. Anything else is not trusted as a label.
		return 1 === preg_match( '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,39}$/', $version ) ? $version : '';
	}

	/**
	 * The plugin's own file, as WordPress names it: `estasoft-news-distribution/estasoft-news-distribution.php`.
	 *
	 * @return string
	 */
	private static function basename(): string {
		return plugin_basename( ESTASOFT_ND_FILE );
	}

	/**
	 * Whether an update is about this plugin.
	 *
	 * @param mixed $hook_extra What WordPress says about the update.
	 * @return bool
	 */
	private static function is_this_plugin( $hook_extra ): bool {
		return is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) && self::basename() === $hook_extra['plugin'];
	}

	/**
	 * Reports a failure to save a point, without stopping anything.
	 *
	 * @param \Throwable $error What went wrong.
	 * @return void
	 */
	private static function failed( \Throwable $error ): void {
		/**
		 * Fires when a restore point could not be saved before an update. The update goes on.
		 *
		 * @param \Throwable $error What went wrong.
		 */
		do_action( 'estasoft_nd_internal_update_point_failed', $error );
	}
}

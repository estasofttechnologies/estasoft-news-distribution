<?php
/**
 * Runs on plugin activation.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution;

use Estasoft\NewsDistribution\Core\Db\Schema;
use Estasoft\NewsDistribution\Core\Operations\UpdatePoints;
use Estasoft\NewsDistribution\Frontend\Rewrite;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the database tables, records the version, asks WordPress to learn the
 * feed addresses and flags the first-run screen for an administrator.
 */
final class Activator {

	/**
	 * Activation callback.
	 *
	 * @param bool $network_wide Whether the plugin was activated for the whole network.
	 * @return void
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}

		self::activate_site();
	}

	/**
	 * A site was added to the network: sets it up when the plugin is active for the whole network.
	 *
	 * @param \WP_Site $site The new site.
	 * @return void
	 */
	public static function new_site( $site ): void {
		if ( ! $site instanceof \WP_Site || ! is_multisite() ) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( ESTASOFT_ND_FILE ) ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );

		try {
			self::activate_site();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Sets up one site.
	 *
	 * @return void
	 */
	public static function activate_site(): void {
		// Files replaced while the plugin was off (FTP, a deploy) show here as a different stored version: keep the settings first.
		UpdatePoints::before_migration( (string) get_option( 'estasoft_nd_version', '' ) );

		Schema::install();

		Rewrite::add_rules();
		flush_rewrite_rules( false );

		update_option( 'estasoft_nd_version', ESTASOFT_ND_VERSION, true );
	}
}

<?php
/**
 * Brings stored data up to date after a plugin update.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution;

use Estasoft\NewsDistribution\Core\Db\Schema;
use Estasoft\NewsDistribution\Core\Operations\UpdatePoints;

defined( 'ABSPATH' ) || exit;

/**
 * Runs when the stored version differs from the code's, including after a copy
 * of the files (an update by FTP never fires the activation hook).
 */
final class Upgrader {

	/**
	 * Upgrades the database and stored options if needed.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$stored = (string) get_option( 'estasoft_nd_version', '' );

		if ( ESTASOFT_ND_VERSION === $stored && ! Schema::needs_upgrade() ) {
			return;
		}

		// The files are not the version that last ran: keep a copy of the settings before anything is migrated.
		UpdatePoints::before_migration( $stored );

		Schema::install();

		if ( '' === $stored || version_compare( $stored, ESTASOFT_ND_VERSION, '<' ) ) {
			// Feed addresses may have changed between versions.
			update_option( 'estasoft_nd_rewrite_dirty', 1, true );
		}

		update_option( 'estasoft_nd_version', ESTASOFT_ND_VERSION, true );
	}
}

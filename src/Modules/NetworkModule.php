<?php
/**
 * The News Distribution pages of Network Admin, and the network REST routes' home.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Network\NetworkAdmin;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Does nothing on a single site. On a multisite network it adds the Network Admin page
 * (`network/admin.php?page=estasoft-nd-network`, tabs Sites, Delivery health and AI policy) and the
 * handlers of its forms. The REST routes under `/network/` are registered by their controllers,
 * which also stay quiet on a single site.
 *
 * Network Admin never runs on the front end, so the class is only built there.
 */
final class NetworkModule implements ModuleInterface {

	/**
	 * Hooks the Network Admin pages.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		if ( ! is_multisite() ) {
			return;
		}

		( new NetworkAdmin( $plugin ) )->register();
	}
}

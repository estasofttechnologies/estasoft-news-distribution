<?php
/**
 * Keeps a published article's change of address in its history.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Core\AddressChange;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to the internal action `Core\EventListener` fires when a published article gets a new address, and
 * writes the audit entry the History tab shows as "Address changed".
 */
final class AddressChangeModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * Adds the hook.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'estasoft_nd_internal_address_changed', array( $this, 'changed' ), 10, 4 );
	}

	/**
	 * Records the change.
	 *
	 * @param mixed $post_id  Post ID.
	 * @param mixed $was      The address before.
	 * @param mixed $now      The address now.
	 * @param mixed $redirect Whether WordPress sends the old address to the new one by itself.
	 * @return void
	 */
	public function changed( $post_id = 0, $was = '', $now = '', $redirect = false ): void {
		if ( (int) $post_id < 1 || ! is_string( $was ) || ! is_string( $now ) || '' === $was || '' === $now ) {
			return;
		}

		$this->plugin->make( AddressChange::class )->record( (int) $post_id, $was, $now, (bool) $redirect );
	}
}

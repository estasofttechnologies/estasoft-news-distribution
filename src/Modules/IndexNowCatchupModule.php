<?php
/**
 * Runs one batch of the IndexNow catch-up when the queue asks.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Channels\IndexNow\Catchup;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The catch-up schedules its own batches in the plugin's queue group. Nothing
 * runs unless a person started a catch-up on the IndexNow page.
 */
final class IndexNowCatchupModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * Adds the hook.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( Catchup::HOOK, array( $this, 'process' ) );
	}

	/**
	 * Sends the next batch.
	 *
	 * @return void
	 */
	public function process(): void {
		$catchup = Catchup::of( $this->plugin );

		if ( null !== $catchup ) {
			$catchup->process();
		}
	}
}

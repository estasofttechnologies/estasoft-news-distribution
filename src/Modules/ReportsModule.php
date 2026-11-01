<?php
/**
 * Runs a report's scan when the queue asks.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Reports\Parts\Scans;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The self-test, the hreflang audit and the Discover check read the site's own
 * pages. A person starts one with a button; it runs here, in the queue, so a page
 * load never waits for it. The one scheduled run is the self-test, once an hour,
 * which asks only this site's own addresses.
 */
final class ReportsModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * Adds the hook.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( Scans::HOOK, array( $this, 'run' ), 10, 2 );
		add_action( Scans::SELF_TEST_HOURLY, array( $this, 'self_test' ) );

		// Booking the hourly job touches the database, so it happens in the admin, not on every page view.
		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'schedule' ) );
		}
	}

	/**
	 * The hourly self-test.
	 *
	 * @return void
	 */
	public function self_test(): void {
		$this->plugin->make( Scans::class )->run_scheduled( 'self-test' );
	}

	/**
	 * Makes sure the hourly self-test is booked, once any feed or sitemap is on.
	 *
	 * @return void
	 */
	public function schedule(): void {
		if ( array() !== $this->plugin->registry()->pulled() ) {
			$this->plugin->queue()->ensure_recurring( Scans::SELF_TEST_HOURLY, HOUR_IN_SECONDS );
		}
	}

	/**
	 * Runs one scan.
	 *
	 * @param mixed $name Report name.
	 * @param mixed $job  Job id.
	 * @return void
	 */
	public function run( $name = '', $job = '' ): void {
		$name = is_string( $name ) ? sanitize_key( $name ) : '';
		$job  = is_string( $job ) ? sanitize_key( $job ) : '';

		if ( '' === $name || '' === $job ) {
			return;
		}

		$this->plugin->make( Scans::class )->run( $name, $job );
	}
}

<?php
/**
 * Daily clean-up.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Network\AiBudget;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Removes log rows past the retention period. Runs through the background
 * queue (not WP-Cron) once a day. On a multisite network it also gives the
 * network's AI budget its daily look (`Network\AiBudget::sweep()`: a new
 * month's first recount, and an alert that is due) and runs the queued alert
 * email of that budget.
 */
final class Maintenance {

	public const HOOK = 'estasoft_nd_daily';

	private Plugin $plugin;

	/**
	 * Creates the job.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks the job and makes sure it is scheduled.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );

		if ( is_multisite() ) {
			add_action( AiBudget::ALERT_HOOK, array( AiBudget::class, 'run_alert' ), 10, 2 );
		}

		// Scheduling touches the database, so it happens in the admin, not on every page view.
		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'schedule' ) );
		}
	}

	/**
	 * Schedules the daily job once.
	 *
	 * @return void
	 */
	public function schedule(): void {
		$this->plugin->queue()->ensure_recurring( self::HOOK, DAY_IN_SECONDS );
	}

	/**
	 * Deletes old log rows.
	 *
	 * @return void
	 */
	public function run(): void {
		$days = $this->plugin->settings()->log_days();

		$this->plugin->log()->purge_older_than( $days );
		$this->plugin->fetch_log()->purge_older_than( $days );
		$this->plugin->audit()->purge_older_than( $days );

		if ( is_multisite() ) {
			AiBudget::sweep();
		}

		/**
		 * Fires after the daily clean-up, so other parts of the plugin can add their own.
		 *
		 * @param int $days Days the logs are kept.
		 */
		do_action( 'estasoft_nd_maintenance', $days );
	}
}

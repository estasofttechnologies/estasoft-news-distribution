<?php
/**
 * Email alerts and the Monday summary.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Core\Alerts;
use Estasoft\NewsDistribution\Core\Digest;
use Estasoft\NewsDistribution\Core\Environment;
use Estasoft\NewsDistribution\Core\Queue;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Wires `Core\Alerts` and `Core\Digest` into WordPress:
 *
 * - `estasoft_nd_delivery_failed` becomes the push-failed alert;
 * - the queue runs a reminder (`estasoft_nd_alert_reminder`), an hourly check
 *   (held failures, a platform that stopped fetching, the daily skipped summary)
 *   and the Monday summary (`estasoft_nd_weekly_digest`);
 * - the first two are booked when someone opens the admin, the third when the
 *   Weekly summary is turned on.
 *
 * Nothing here contacts another server. Email goes through the site's own mail setup. While demo mode is on, none of
 * these automatic jobs sends anything: the samples would only make alerts about nothing. The test buttons on the
 * Alerts screen still work, because a person pressed them.
 */
final class AlertsModule implements ModuleInterface {

	public const HOURLY = 'estasoft_nd_alerts_hourly';

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'estasoft_nd_delivery_failed', array( $this, 'delivery_failed' ), 10, 7 );
		add_action( Alerts::REMINDER_HOOK, array( $this, 'reminder' ), 10, 2 );
		add_action( self::HOURLY, array( $this, 'hourly' ) );
		add_action( Digest::HOOK, array( $this, 'weekly' ) );

		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'schedule' ) );
		}
	}

	/**
	 * Hooked to `estasoft_nd_delivery_failed`.
	 *
	 * @param mixed $article_id Article ID.
	 * @param mixed $channel_id Channel ID.
	 * @param mixed $language   Language code.
	 * @param mixed $event_type Event type.
	 * @param mixed $attempt    The attempt that failed.
	 * @param mixed $code       The platform's HTTP status, or 0.
	 * @param mixed $message    What the platform answered.
	 * @return void
	 */
	public function delivery_failed( $article_id = 0, $channel_id = '', $language = '', $event_type = '', $attempt = 1, $code = 0, $message = '' ): void {
		if ( $this->demo() ) {
			return;
		}

		$this->plugin->make( Alerts::class )->on_delivery_failed( $article_id, $channel_id, $language, $event_type, $attempt, $code, $message );
	}

	/**
	 * A reminder the queue was asked to run.
	 *
	 * @param mixed $type    Alert type.
	 * @param mixed $context Context.
	 * @return void
	 */
	public function reminder( $type = '', $context = array() ): void {
		if ( $this->demo() ) {
			return;
		}

		$this->plugin->make( Alerts::class )->run_reminder( $type, $context );
	}

	/**
	 * The hourly job.
	 *
	 * @return void
	 */
	public function hourly(): void {
		if ( $this->demo() ) {
			return;
		}

		$alerts = $this->plugin->make( Alerts::class );

		$alerts->flush_pending();
		$alerts->check_silent_platforms();
		$alerts->send_daily_skipped();
	}

	/**
	 * The Monday job: sends the summary, then books the next one.
	 *
	 * @return void
	 */
	public function weekly(): void {
		if ( $this->demo() ) {
			return;
		}

		$this->plugin->make( Digest::class )->run();
	}

	/**
	 * Makes sure the hourly job is booked, and the Monday one when the summary is on.
	 *
	 * @return void
	 */
	public function schedule(): void {
		$this->plugin->queue()->ensure_recurring( self::HOURLY, HOUR_IN_SECONDS );

		if ( $this->plugin->make( Alerts::class )->is_on( Alerts::WEEKLY ) ) {
			$this->plugin->make( Digest::class )->schedule();
		}
	}

	/**
	 * Whether demo mode is on, which keeps every automatic alert and summary from going out.
	 *
	 * @return bool
	 */
	private function demo(): bool {
		return (bool) ( new Environment( $this->plugin ) )->staging()['demo'];
	}
}

<?php
/**
 * Starts a report's scan as a job in the queue, runs it there, and keeps what it found.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports\Parts;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Queue;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Get it with `Plugin::instance()->make( Scans::class )`.
 *
 * A scan is a `Core\Jobs` job: `start()` answers at once with the job id, the queue
 * runs the work (`run()`, on the action `estasoft_nd_report_scan`), and the screen
 * or the caller polls `GET /jobs/{id}`. The last result of each report is kept in an
 * option that is not loaded on every page (`estasoft_nd_report_<name>`).
 */
final class Scans {

	public const HOOK = 'estasoft_nd_report_scan';

	/**
	 * The hourly job that runs the self-test. The queue fires it; it is already running in the queue, so it does the work at once.
	 */
	public const SELF_TEST_HOURLY = 'estasoft_nd_self_test_hourly';

	private const OPTION_PREFIX = 'estasoft_nd_report_';
	private const LOCK_PREFIX   = 'estasoft_nd_scan_';
	private const LOCK_SECONDS  = 600;

	private Plugin $plugin;

	/**
	 * Creates the service.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Starts a scan.
	 *
	 * @param string $name Report name, such as "self-test".
	 * @return array{job: string, report: string}|\WP_Error The job to poll, or why it could not start.
	 */
	public function start( string $name ) {
		$report = Reports::find( $this->plugin, $name );

		if ( ! $report instanceof Scannable ) {
			return new \WP_Error( 'estasoft_nd_unknown_report', __( 'That report does not read pages, so there is nothing to start.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		if ( null !== $this->running( $name ) ) {
			return new \WP_Error( 'estasoft_nd_scan_running', __( 'This check is already running. Wait for it to finish.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		if ( ! $this->plugin->queue()->is_available() ) {
			return new \WP_Error( 'estasoft_nd_queue_unavailable', __( 'The background queue is not available on this site, so the check cannot start. Make sure Action Scheduler is not blocked, then try again.', 'estasoft-news-distribution' ), array( 'status' => 503 ) );
		}

		$jobs = $this->plugin->jobs();
		$job  = $jobs->start( $name, max( 1, $report->scan_units() ), array( 'report' => $name ) );

		set_transient( self::LOCK_PREFIX . $name, $job, self::LOCK_SECONDS );

		$action = (int) as_enqueue_async_action( self::HOOK, array( $name, $job ), Queue::GROUP );

		if ( $action < 1 ) {
			delete_transient( self::LOCK_PREFIX . $name );
			$jobs->finish( $job, false, array(), __( 'The check could not be put in the queue.', 'estasoft-news-distribution' ) );

			return new \WP_Error( 'estasoft_nd_queue_failed', __( 'The check could not be put in the queue. Try again in a minute.', 'estasoft-news-distribution' ), array( 'status' => 503 ) );
		}

		return array(
			'job'    => $job,
			'report' => $name,
		);
	}

	/**
	 * Runs a scan now, from a job of the queue that is already running (the hourly self-test).
	 *
	 * @param string $name Report name.
	 * @return void
	 */
	public function run_scheduled( string $name ): void {
		$report = Reports::find( $this->plugin, $name );

		if ( ! $report instanceof Scannable || null !== $this->running( $name ) ) {
			return;
		}

		$jobs = $this->plugin->jobs();
		$job  = $jobs->start( $name, max( 1, $report->scan_units() ), array( 'report' => $name ) );

		set_transient( self::LOCK_PREFIX . $name, $job, self::LOCK_SECONDS );

		$this->run( $name, $job );
	}

	/**
	 * The job of a scan that is waiting or running, if there is one.
	 *
	 * @param string $name Report name.
	 * @return string|null Job id.
	 */
	public function running( string $name ): ?string {
		$job = get_transient( self::LOCK_PREFIX . $name );

		if ( ! is_string( $job ) || '' === $job ) {
			return null;
		}

		$state = $this->plugin->jobs()->get( $job );

		if ( null === $state || ! in_array( $state['state'], array( 'queued', 'running' ), true ) ) {
			delete_transient( self::LOCK_PREFIX . $name );

			return null;
		}

		return $job;
	}

	/**
	 * Does the work of a started scan. The queue calls this.
	 *
	 * @param string $name Report name.
	 * @param string $job  Job id.
	 * @return void
	 */
	public function run( string $name, string $job ): void {
		$jobs   = $this->plugin->jobs();
		$report = Reports::find( $this->plugin, $name );

		if ( ! $report instanceof Scannable ) {
			$jobs->finish( $job, false, array(), __( 'That report is not available on this site.', 'estasoft-news-distribution' ) );
			delete_transient( self::LOCK_PREFIX . $name );

			return;
		}

		$jobs->progress( $job, 0 );

		try {
			$result = $report->scan(
				static function ( int $done ) use ( $jobs, $job ): void {
					$jobs->progress( $job, $done );
				}
			);
		} catch ( \Throwable $error ) {
			$jobs->finish( $job, false, array(), __( 'The check stopped before it finished. Try again.', 'estasoft-news-distribution' ) );
			delete_transient( self::LOCK_PREFIX . $name );

			return;
		}

		$this->save( $name, $job, $result );

		$jobs->finish( $job, true, array( 'report' => $name ), $report->scan_message( $result ) );
		delete_transient( self::LOCK_PREFIX . $name );
	}

	/**
	 * Keeps the result of a scan.
	 *
	 * @param string               $name   Report name.
	 * @param string               $job    Job id.
	 * @param array<string, mixed> $result What the scan found.
	 * @return void
	 */
	public function save( string $name, string $job, array $result ): void {
		update_option(
			self::OPTION_PREFIX . $name,
			array(
				'at'   => Clock::now(),
				'job'  => $job,
				'data' => $result,
			),
			false
		);
	}

	/**
	 * The last result of a scan.
	 *
	 * @param string $name Report name.
	 * @return array{at: int, job: string, data: array<string, mixed>}|null Null when it never ran.
	 */
	public function last( string $name ): ?array {
		$stored = get_option( self::OPTION_PREFIX . $name, null );

		if ( ! is_array( $stored ) || ! isset( $stored['at'], $stored['data'] ) || ! is_array( $stored['data'] ) ) {
			return null;
		}

		return array(
			'at'   => (int) $stored['at'],
			'job'  => (string) ( $stored['job'] ?? '' ),
			'data' => $stored['data'],
		);
	}

	/**
	 * Forgets the last result of a scan.
	 *
	 * @param string $name Report name.
	 * @return void
	 */
	public function forget( string $name ): void {
		delete_option( self::OPTION_PREFIX . $name );
		delete_transient( self::LOCK_PREFIX . $name );
	}
}

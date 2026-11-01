<?php
/**
 * The button, the progress and the messages of a report that runs a scan.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports\Parts;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Reports\AbstractReport;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Used by the self-test, the hreflang audit and the Discover check. A button posts a form (it works
 * without a script), `handle()` starts the scan in the queue and goes back to the report with the job
 * id in the address, and the report shows how far the job is. A small script follows the job and
 * reloads the report when it ends.
 */
final class ScanControls {

	/**
	 * The form field that carries the report name.
	 */
	private const FIELD = 'estasoft_nd_scan';

	private Plugin $plugin;

	private AbstractReport $report;

	/**
	 * Creates the controls.
	 *
	 * @param Plugin         $plugin The running plugin.
	 * @param AbstractReport $report The report that runs the scan.
	 */
	public function __construct( Plugin $plugin, AbstractReport $report ) {
		$this->plugin = $plugin;
		$this->report = $report;
	}

	/**
	 * The nonce action of this report's form.
	 *
	 * @return string
	 */
	private function nonce(): string {
		return 'estasoft_nd_scan_' . $this->report->name();
	}

	/**
	 * The form with the button that starts the scan, or a link to the running one.
	 *
	 * @param string $label   The button text.
	 * @param string $running The text of the link while a scan runs.
	 * @param string $variant Button variant: "primary" or empty.
	 * @return string
	 */
	public function form( string $label, string $running = '', string $variant = '' ): string {
		$job = (string) $this->plugin->make( Scans::class )->running( $this->report->name() );

		if ( '' !== $job ) {
			return Button::link( '' === $running ? __( 'Running…', 'estasoft-news-distribution' ) : $running, $this->report->url( array( 'job' => $job ) ) );
		}

		return Html::tag(
			'form',
			array(
				'method' => 'post',
				'action' => $this->report->url(),
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => self::FIELD,
					'value' => $this->report->name(),
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => '_wpnonce',
					'value' => wp_create_nonce( $this->nonce() ),
				)
			)
			. Button::submit( $label, '' === $variant ? array() : array( 'variant' => $variant ) )
		);
	}

	/**
	 * Starts the scan when the form was posted, and goes back to the report.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only asks whether a scan form was posted; the nonce is checked next, before its value is read.
			return;
		}

		check_admin_referer( $this->nonce() );

		if ( sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) ) !== $this->report->name() ) {
			return;
		}

		$this->plugin->access()->require_or_die( $this->report->permission() );

		$started = $this->plugin->make( Scans::class )->start( $this->report->name() );

		wp_safe_redirect(
			is_wp_error( $started )
				? $this->report->url( array( 'problem' => $started->get_error_code() ) )
				: $this->report->url( array( 'job' => (string) $started['job'] ) )
		);
		exit;
	}

	/**
	 * The job being watched: the one in the address while it waits or runs, or the one another person started.
	 *
	 * @return string The job id, or an empty string.
	 */
	public function job_in_view(): string {
		$job = Query::key( 'job' );

		if ( '' === $job ) {
			return (string) $this->plugin->make( Scans::class )->running( $this->report->name() );
		}

		$state = $this->plugin->jobs()->get( $job );

		return null !== $state && in_array( $state['state'], array( 'queued', 'running' ), true ) ? $job : '';
	}

	/**
	 * Loads the script that follows the job, when there is one to follow.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$job = $this->job_in_view();

		if ( '' === $job ) {
			return;
		}

		Assets::screen_script(
			'report-scan',
			array(
				'job'      => $job,
				'finished' => $this->report->url(),
				'interval' => 2000,
				'words'    => __( 'Checking: {done} of {total} done.', 'estasoft-news-distribution' ),
			)
		);
	}

	/**
	 * The card that shows a scan that is waiting or running, or an empty string.
	 *
	 * @return string
	 */
	public function progress(): string {
		$job = $this->job_in_view();

		if ( '' === $job ) {
			return '';
		}

		$state = $this->plugin->jobs()->get( $job );
		$total = null === $state ? 1 : max( 1, (int) $state['total'] );
		$done  = null === $state ? 0 : min( $total, (int) $state['done'] );
		$words = null !== $state && 'running' === $state['state']
			? sprintf(
				/* translators: 1: units done, 2: units in all. */
				__( 'Checking: %1$s of %2$s done.', 'estasoft-news-distribution' ),
				number_format_i18n( $done ),
				number_format_i18n( $total )
			)
			: __( 'Waiting for the queue to pick the check up.', 'estasoft-news-distribution' );

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-notes' ),
			Card::note(
				'scan-progress',
				sprintf(
					/* translators: %s: a report name such as Self-test. */
					__( '%s is running', 'estasoft-news-distribution' ),
					$this->report->title()
				),
				Html::tag(
					'progress',
					array(
						'class'      => 'estasoft-nd-meter',
						'value'      => (string) $done,
						'max'        => (string) $total,
						'aria-label' => __( 'Check progress', 'estasoft-news-distribution' ),
					),
					''
				)
				. Html::tag(
					'p',
					array(
						'class'     => 'estasoft-nd-muted',
						'aria-live' => 'polite',
						'id'        => 'estasoft-nd-scan-words',
					),
					esc_html( $words )
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'This page updates by itself. If it does not, reload it to see the result.', 'estasoft-news-distribution' ) )
			)
		);
	}

	/**
	 * The words for a problem that stopped a start, from the address, or an empty string.
	 *
	 * @return string
	 */
	public function problem(): string {
		$code = Query::key( 'problem' );

		switch ( $code ) {
			case 'estasoft_nd_scan_running':
				return __( 'This check is already running. Wait for it to finish.', 'estasoft-news-distribution' );
			case 'estasoft_nd_queue_unavailable':
			case 'estasoft_nd_queue_failed':
				return __( 'The background queue is not available, so the check could not start. Make sure Action Scheduler is not blocked, then try again.', 'estasoft-news-distribution' );
			default:
				return '';
		}
	}
}

<?php
/**
 * Progress of long work: a catch-up, a self-test, a bulk action.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A request that starts long work answers `202` with a job id, then the work
 * runs in the queue and updates the job. The screen polls `GET /jobs/{id}`.
 * A job lives in a transient for a day; it is progress, not a record.
 *
 * States: queued, running, done, failed.
 */
final class Jobs {

	private const PREFIX = 'estasoft_nd_job_';

	/**
	 * Starts a job.
	 *
	 * @param string               $type  What the job is, such as "indexnow-catchup" or "self-test".
	 * @param int                  $total Units of work in all, or 0 when not known.
	 * @param array<string, mixed> $meta  Small values the screen needs back, such as the date range.
	 * @return string The job id.
	 */
	public function start( string $type, int $total = 0, array $meta = array() ): string {
		$id = strtolower( wp_generate_password( 12, false, false ) );

		$this->save(
			$id,
			array(
				'id'      => $id,
				'type'    => $type,
				'state'   => 'queued',
				'total'   => max( 0, $total ),
				'done'    => 0,
				'user'    => get_current_user_id(),
				'meta'    => $meta,
				'result'  => array(),
				'message' => '',
				'started' => Clock::iso( Clock::now() ),
				'ended'   => '',
			)
		);

		return $id;
	}

	/**
	 * Records progress.
	 *
	 * @param string $id    Job id.
	 * @param int    $done  Units done so far.
	 * @param int    $total New total, or -1 to keep the old one.
	 * @return void
	 */
	public function progress( string $id, int $done, int $total = -1 ): void {
		$job = $this->get( $id );

		if ( null === $job ) {
			return;
		}

		$job['state'] = 'running';
		$job['done']  = max( 0, $done );

		if ( $total >= 0 ) {
			$job['total'] = $total;
		}

		$this->save( $id, $job );
	}

	/**
	 * Ends a job.
	 *
	 * @param string               $id      Job id.
	 * @param bool                 $success Whether the work finished.
	 * @param array<string, mixed> $result  Result the screen shows, such as counts.
	 * @param string               $message A plain sentence for the screen, or empty.
	 * @return void
	 */
	public function finish( string $id, bool $success = true, array $result = array(), string $message = '' ): void {
		$job = $this->get( $id );

		if ( null === $job ) {
			return;
		}

		$job['state']   = $success ? 'done' : 'failed';
		$job['result']  = $result;
		$job['message'] = $message;
		$job['ended']   = Clock::iso( Clock::now() );

		if ( $success && $job['total'] > 0 ) {
			$job['done'] = $job['total'];
		}

		$this->save( $id, $job );
	}

	/**
	 * A job, with its percentage.
	 *
	 * @param string $id Job id.
	 * @return array<string, mixed>|null Null when the job is unknown or expired.
	 */
	public function get( string $id ): ?array {
		$job = get_transient( self::PREFIX . sanitize_key( $id ) );

		if ( ! is_array( $job ) ) {
			return null;
		}

		$job['percent'] = $job['total'] > 0 ? (int) min( 100, floor( $job['done'] / $job['total'] * 100 ) ) : ( 'done' === $job['state'] ? 100 : 0 );

		return $job;
	}

	/**
	 * Stores a job.
	 *
	 * @param string               $id  Job id.
	 * @param array<string, mixed> $job Job data.
	 * @return void
	 */
	private function save( string $id, array $job ): void {
		unset( $job['percent'] );
		set_transient( self::PREFIX . sanitize_key( $id ), $job, DAY_IN_SECONDS );
	}
}

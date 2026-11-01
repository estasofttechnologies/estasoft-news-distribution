<?php
/**
 * Deliveries that are waiting in the queue for one article.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Control;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Core\Queue;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * A release plan, a hold and Send now all change when a delivery that is
 * already in the queue should run. This class finds those deliveries, moves
 * them and puts back what a hold kept from being queued. It never calls a
 * channel: it only changes when the queue runs one.
 */
final class Waiting {

	/**
	 * The queue action that drops the cached feeds when an exclusive or a wait ends.
	 */
	public const RELEASE_DUE = 'estasoft_nd_release_due';

	private Plugin $plugin;

	/**
	 * Creates the helper.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Publish and update deliveries of one article that wait in the queue.
	 *
	 * @param int $post_id Article ID.
	 * @return array<int, array{id: int, post: int, channel: string, event: string, attempt: int, at: int}>
	 */
	public function pending( int $post_id ): array {
		$out = array();

		foreach ( $this->plugin->queue()->waiting_for_article( $post_id ) as $item ) {
			$event = (string) ( $item['args'][2] ?? '' );

			if ( ! in_array( $event, array( Event::PUBLISH, Event::UPDATE ), true ) ) {
				continue;
			}

			$out[] = array(
				'id'      => $item['id'],
				'post'    => $post_id,
				'channel' => (string) ( $item['args'][0] ?? '' ),
				'event'   => $event,
				'attempt' => max( 1, (int) ( $item['args'][3] ?? 1 ) ),
				'at'      => $item['at'],
			);
		}

		return $out;
	}

	/**
	 * Runs a waiting delivery at another time.
	 *
	 * @param array{id: int, post: int, channel: string, event: string, attempt: int, at: int} $item A waiting delivery from pending().
	 * @param int                                                                              $to   Unix time. A time that has passed means as soon as possible.
	 * @return bool Whether it was queued again.
	 */
	public function move( array $item, int $to ): bool {
		$post_id = $item['post'];

		\ActionScheduler::store()->cancel_action( $item['id'] );

		$delay = max( 0, $to - Clock::now() );

		if ( $this->plugin->queue()->enqueue( $item['channel'], $post_id, $item['event'], $delay, $item['attempt'] ) < 1 ) {
			return false;
		}

		$this->plugin->log()->record( $post_id, $item['channel'], $this->language( $post_id ), $item['event'], Status::QUEUED, $item['attempt'], 0, '', Clock::now() + $delay );

		return true;
	}

	/**
	 * Takes a waiting delivery out of the queue until a person lets it go.
	 *
	 * The delivery log gets a Waiting row with no time, which is what requeue_held() looks for.
	 *
	 * @param array{id: int, post: int, channel: string, event: string, attempt: int, at: int} $item A waiting delivery from pending().
	 * @return void
	 */
	public function park( array $item ): void {
		$post_id = $item['post'];

		\ActionScheduler::store()->cancel_action( $item['id'] );
		$this->plugin->log()->record( $post_id, $item['channel'], $this->language( $post_id ), $item['event'], Status::QUEUED, $item['attempt'], 0, __( 'Waiting until you release it.', 'estasoft-news-distribution' ) );
	}

	/**
	 * Cancels the waiting deliveries of an article, so Send now can queue them to run at once.
	 *
	 * @param int      $post_id Article ID.
	 * @param string[] $only    Channel ids, or an empty array for all.
	 * @return string[] Channel ids that were cancelled.
	 */
	public function cancel( int $post_id, array $only = array() ): array {
		$cancelled = array();

		foreach ( $this->pending( $post_id ) as $item ) {
			if ( array() !== $only && ! in_array( $item['channel'], $only, true ) ) {
				continue;
			}

			// A delivery that is already due has no wait to take out of the way; cancelling it would only queue it twice.
			if ( $item['at'] <= Clock::now() ) {
				continue;
			}

			\ActionScheduler::store()->cancel_action( $item['id'] );
			$cancelled[] = $item['channel'];
		}

		return array_values( array_unique( $cancelled ) );
	}

	/**
	 * Queues what a hold, or an exclusive without an end time, kept from being queued.
	 *
	 * A delivery that was kept back is in the delivery log as Waiting with no time. The
	 * latest row of a pushed channel being exactly that is the sign.
	 *
	 * @param int      $post_id Article ID.
	 * @param string[] $only    Channel ids that may go now, or an empty array for all.
	 * @return string[] Channel ids that were queued.
	 */
	public function requeue_held( int $post_id, array $only = array() ): array {
		$queued = array();
		$queue  = $this->plugin->queue();
		$log    = $this->plugin->log();
		$latest = $log->latest_for_post( $post_id );

		foreach ( $this->plugin->registry()->pushed() as $channel ) {
			$id  = $channel->id();
			$row = $latest[ $id ] ?? null;

			if ( null === $row || Status::QUEUED !== $row['status'] || (int) $row['run_at_ts'] > 0 || ! $channel->is_enabled() ) {
				continue;
			}

			if ( array() !== $only && ! in_array( $id, $only, true ) ) {
				continue;
			}

			$event   = (string) $row['event'];
			$attempt = max( 1, (int) $row['attempt'] );

			if ( $queue->enqueue( $id, $post_id, $event, 0, $attempt ) > 0 ) {
				$log->record( $post_id, $id, (string) $row['language'], $event, Status::QUEUED, $attempt, 0, '', Clock::now() );
				$queued[] = $id;
			}
		}

		return $queued;
	}

	/**
	 * Asks the queue to drop the cached feeds at some times, so an article joins a feed when its wait ends.
	 *
	 * Earlier requests for the same article are replaced, so changing a plan never leaves a stale one.
	 *
	 * @param int   $post_id Article ID.
	 * @param int[] $times   Unix times.
	 * @return void
	 */
	public function schedule_flush( int $post_id, array $times ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( self::RELEASE_DUE, array( $post_id ), Queue::GROUP );

		foreach ( array_unique( array_map( 'intval', $times ) ) as $at ) {
			if ( $at > Clock::now() ) {
				as_schedule_single_action( $at + 1, self::RELEASE_DUE, array( $post_id ), Queue::GROUP );
			}
		}
	}

	/**
	 * Runs the scheduled drop of the cached feeds.
	 *
	 * @param mixed $post_id Article ID.
	 * @return void
	 */
	public function run_flush( $post_id = 0 ): void {
		unset( $post_id );

		$this->plugin->cache()->flush();
	}

	/**
	 * The language of an article.
	 *
	 * @param int $post_id Article ID.
	 * @return string
	 */
	private function language( int $post_id ): string {
		return $this->plugin->languages()->language_of_post( $post_id );
	}
}

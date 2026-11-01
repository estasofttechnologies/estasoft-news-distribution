<?php
/**
 * Sends one push again when a person asks.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * "Retry now" on the Delivery log. It never calls a platform itself: it puts the
 * delivery back in the background queue, and the queue runs it the usual way, so
 * the result is logged like any other attempt.
 *
 * - A row that is Retrying (an attempt failed, the next one is waiting) sends the
 *   next attempt at once. The attempt counter continues.
 * - A row that is Failed (all four attempts used) starts a new set of up to four.
 * - Any other row, a row without an article and a push that has already been
 *   started again are refused, with a plain sentence.
 *
 * Get an instance with `Plugin::instance()->make( DeliveryRetry::class )`.
 */
final class DeliveryRetry {

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
	 * Whether a row may be sent again right now. The screen uses this to decide
	 * whether to show the button; `retry()` checks it again.
	 *
	 * @param array<string, mixed> $row A row from DeliveryLog.
	 * @return bool
	 */
	public function can_retry( array $row ): bool {
		return null === $this->refusal( $row );
	}

	/**
	 * Queues the push again.
	 *
	 * @param int $id Delivery log row ID.
	 * @return array{id: int, status: string, attempt: int, channel: string, post_id: int}|\WP_Error The row that now shows the push as waiting, or why not.
	 */
	public function retry( int $id ) {
		$log = $this->plugin->log();
		$row = $log->find( $id );

		if ( null === $row ) {
			return $this->error( 'estasoft_nd_unknown_entry', __( 'That entry is not in the delivery log. It may be older than the time entries are kept.', 'estasoft-news-distribution' ), 404 );
		}

		$refused = $this->refusal( $row );

		if ( null !== $refused ) {
			return $refused;
		}

		$channel = (string) $row['channel'];
		$post_id = (int) $row['post_id'];
		$event   = (string) $row['event'];
		$queue   = $this->plugin->queue();

		if ( ! $queue->is_available() ) {
			return $this->error( 'estasoft_nd_queue_unavailable', __( 'The background queue is not available, so nothing can be sent now.', 'estasoft-news-distribution' ), 503 );
		}

		if ( Status::RETRY === $row['status'] ) {
			$attempt = min( Retry::MAX_ATTEMPTS, (int) $row['attempt'] + 1 );

			// The next try is already waiting for its turn. It goes now instead.
			$queue->cancel_delivery( $channel, $post_id, $event, $attempt );
		} else {
			$attempt = 1;
		}

		if ( 0 === $queue->enqueue( $channel, $post_id, $event, 0, $attempt ) ) {
			return $this->error( 'estasoft_nd_already_waiting', __( 'This push is already waiting in the queue.', 'estasoft-news-distribution' ), 409 );
		}

		$new = $log->record( $post_id, $channel, (string) $row['language'], $event, Status::QUEUED, $attempt, 0, '', Clock::now() );

		return array(
			'id'      => $new,
			'status'  => Status::QUEUED,
			'attempt' => $attempt,
			'channel' => $channel,
			'post_id' => $post_id,
		);
	}

	/**
	 * Why a row cannot be sent again, or null when it can.
	 *
	 * @param array<string, mixed> $row A row from DeliveryLog.
	 * @return \WP_Error|null
	 */
	private function refusal( array $row ): ?\WP_Error {
		$status = (string) $row['status'];

		if ( ! in_array( $status, array( Status::FAILED, Status::RETRY ), true ) ) {
			return $this->error(
				'estasoft_nd_not_failed',
				sprintf(
					/* translators: %s: a status word such as Sent or Waiting. */
					__( 'Only a push that failed can be sent again. This one is marked “%s”.', 'estasoft-news-distribution' ),
					Status::word( $status )
				),
				409
			);
		}

		if ( (int) $row['post_id'] < 1 || ! in_array( (string) $row['event'], array( Event::PUBLISH, Event::UPDATE, Event::UNPUBLISH, Event::DELETE ), true ) ) {
			return $this->error( 'estasoft_nd_no_article', __( 'This entry is not about one article, so it cannot be sent again from here. Start it again from the channel page.', 'estasoft-news-distribution' ), 409 );
		}

		if ( $this->plugin->log()->has_later( (int) $row['id'] ) ) {
			return $this->error( 'estasoft_nd_moved_on', __( 'This push has already moved on. Use the newest entry for it.', 'estasoft-news-distribution' ), 409 );
		}

		$channel = $this->plugin->registry()->get( (string) $row['channel'] );

		if ( null === $channel ) {
			return $this->error( 'estasoft_nd_unknown_channel', __( 'That channel is no longer installed.', 'estasoft-news-distribution' ), 404 );
		}

		if ( ! $channel->is_enabled() ) {
			return $this->error(
				'estasoft_nd_channel_off',
				sprintf(
					/* translators: %s: a channel name such as WebSub. */
					__( '%s is switched off. Turn it on, then try again.', 'estasoft-news-distribution' ),
					$channel->label()
				),
				409
			);
		}

		return null;
	}

	/**
	 * A plain-language error.
	 *
	 * @param string $code    Stable code.
	 * @param string $message A sentence.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}

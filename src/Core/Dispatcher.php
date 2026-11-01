<?php
/**
 * Queues pushed deliveries and runs them in the background.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\ChannelRegistry;
use Estasoft\NewsDistribution\Channels\DeliveryResult;

defined( 'ABSPATH' ) || exit;

/**
 * Reacts to events by queueing one delivery per enabled pushed channel, then
 * runs each delivery, logs the result and retries failures with backoff.
 *
 * Pulled channels need nothing here: the cache listener drops their cached
 * output when the same event fires.
 */
final class Dispatcher {

	private ChannelRegistry $registry;
	private ContentReader $reader;
	private Queue $queue;
	private DeliveryLog $log;

	/**
	 * Creates the dispatcher.
	 *
	 * @param ChannelRegistry $registry Registered channels.
	 * @param ContentReader   $reader   Reads articles.
	 * @param Queue           $queue    Background queue.
	 * @param DeliveryLog     $log      Delivery log.
	 */
	public function __construct( ChannelRegistry $registry, ContentReader $reader, Queue $queue, DeliveryLog $log ) {
		$this->registry = $registry;
		$this->reader   = $reader;
		$this->queue    = $queue;
		$this->log      = $log;
	}

	/**
	 * Hooks into the event and the queue.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'estasoft_nd_post_event', array( $this, 'on_event' ) );
		add_action( Queue::HOOK, array( $this, 'handle' ), 10, 4 );
	}

	/**
	 * Queues a delivery for every enabled pushed channel that carries the article's language.
	 *
	 * @param Event $event The event.
	 * @return void
	 */
	public function on_event( Event $event ): void {
		if ( $event->removes_content() ) {
			// Nothing waiting to announce the article should go out after it is gone.
			$this->queue->cancel_for_article( $event->post_id(), array( Event::PUBLISH, Event::UPDATE ) );
		}

		foreach ( $this->registry->pushed() as $channel ) {
			if ( ! $channel->is_enabled() || ! $channel->accepts_language( $event->language() ) ) {
				continue;
			}

			$hold = $this->held( $channel, $event, null );

			if ( null !== $hold && null === $hold['until'] ) {
				// Something holds every push back, such as staging mode. Queue nothing and say so in the log.
				$this->log->record( $event->post_id(), $channel->id(), $event->language(), $event->type(), $hold['status'], 1, 0, $hold['message'] );
				continue;
			}

			/**
			 * Lets the article's release plan change how long a push waits (internal).
			 *
			 * @param int              $delay   Seconds the channel asks for.
			 * @param ChannelInterface $channel The channel.
			 * @param Event            $event   The event.
			 */
			$delay = max( 0, (int) apply_filters( 'estasoft_nd_internal_delivery_delay', $channel->delay_seconds( $event ), $channel, $event ) );

			if ( ! $this->queue->is_available() ) {
				$this->log->record( $event->post_id(), $channel->id(), $event->language(), $event->type(), Status::SKIPPED, 1, 0, __( 'The background queue is not available.', 'estasoft-news-distribution' ) );
				continue;
			}

			$action_id = $this->queue->enqueue( $channel->id(), $event->post_id(), $event->type(), $delay );

			if ( 0 === $action_id ) {
				// The same delivery is already waiting. One is enough.
				continue;
			}

			$this->log->record( $event->post_id(), $channel->id(), $event->language(), $event->type(), Status::QUEUED, 1, 0, '', Clock::now() + $delay );
		}
	}

	/**
	 * Runs one queued delivery. Called by Action Scheduler with the four values the queue stored.
	 *
	 * @param mixed $channel_id Channel id.
	 * @param mixed $article_id Article ID.
	 * @param mixed $event_type Event type.
	 * @param mixed $attempt    Attempt number, 1 to 4.
	 * @return void
	 */
	public function handle( $channel_id = '', $article_id = 0, $event_type = '', $attempt = 1 ): void {
		$channel_id = (string) $channel_id;
		$article_id = (int) $article_id;
		$event_type = (string) $event_type;
		$attempt    = max( 1, (int) $attempt );

		if ( '' === $channel_id || $article_id < 1 || '' === $event_type ) {
			return;
		}

		$article  = $this->reader->find( $article_id );
		$snapshot = Removed::recall( $article_id );
		$language = null !== $article ? $article->language() : $snapshot['language'];
		$event    = new Event(
			$event_type,
			$article_id,
			$language,
			null,
			array(
				'url'   => $snapshot['url'],
				'title' => $snapshot['title'],
			)
		);
		$channel  = $this->registry->get( $channel_id );

		if ( null === $channel || ! $channel->is_enabled() ) {
			$this->log->record( $article_id, $channel_id, $language, $event_type, Status::SKIPPED, $attempt, 0, __( 'The channel was switched off.', 'estasoft-news-distribution' ) );
			return;
		}

		if ( ! $event->removes_content() && ( null === $article || ! $article->is_public() ) ) {
			$this->log->record( $article_id, $channel_id, $language, $event_type, Status::SKIPPED, $attempt, 0, __( 'The article is no longer published.', 'estasoft-news-distribution' ) );
			return;
		}

		$hold = $this->held( $channel, $event, $article );

		if ( null !== $hold ) {
			$wait = null === $hold['until'] ? 0 : max( 1, $hold['until'] - Clock::now() );

			if ( $wait > 0 && $this->queue->enqueue( $channel_id, $article_id, $event_type, $wait, $attempt ) > 0 ) {
				$this->log->record( $article_id, $channel_id, $language, $event_type, Status::QUEUED, $attempt, 0, $hold['message'], $hold['until'] );
				return;
			}

			$this->log->record( $article_id, $channel_id, $language, $event_type, $hold['status'], $attempt, 0, $hold['message'] );
			return;
		}

		try {
			$result = $channel->deliver( $event, $article );
		} catch ( \Throwable $error ) {
			$result = DeliveryResult::failure( $error->getMessage() );
		}

		if ( $result->is_failure() ) {
			$delay = $result->is_retryable() ? Retry::delay_after( $attempt ) : null;

			if ( null !== $delay && $this->queue->enqueue( $channel_id, $article_id, $event_type, $delay, $attempt + 1 ) > 0 ) {
				$this->log->record( $article_id, $channel_id, $language, $event_type, Status::RETRY, $attempt, $result->code(), $result->message(), Clock::now() + $delay );
				return;
			}

			$this->log->record( $article_id, $channel_id, $language, $event_type, Status::FAILED, $attempt, $result->code(), $result->message() );

			/**
			 * Fires when a delivery failed for good: it will not be tried again.
			 *
			 * The failure alert email listens to this. A failure that is being retried does not fire it.
			 *
			 * @param int    $article_id Article ID.
			 * @param string $channel_id Channel ID.
			 * @param string $language   Language code of the article.
			 * @param string $event_type Event type: publish, update, unpublish or delete.
			 * @param int    $attempt    The attempt that failed, 1 to 4.
			 * @param int    $code       The platform's HTTP status, or 0.
			 * @param string $message    What the platform answered.
			 */
			do_action( 'estasoft_nd_delivery_failed', $article_id, $channel_id, $language, $event_type, $attempt, $result->code(), $result->message() );
			return;
		}

		$status = DeliveryResult::SUCCESS === $result->status() ? Status::SENT : Status::SKIPPED;
		$this->log->record( $article_id, $channel_id, $language, $event_type, $status, $attempt, $result->code(), $result->message() );
	}

	/**
	 * Asks whether something holds this delivery back.
	 *
	 * Staging mode, a language that was turned off and an article dated in the
	 * future each answer here. The filter is internal: a module that holds
	 * deliveries returns an array with a Status code, a message for the delivery
	 * log and, when the hold ends by itself, the time it ends. Anything else means
	 * nothing holds the delivery.
	 *
	 * @param ChannelInterface $channel The channel about to be used.
	 * @param Event            $event   The event.
	 * @param Article|null     $article The article, when it has been read.
	 * @return array{status: string, message: string, until: int|null}|null
	 */
	private function held( ChannelInterface $channel, Event $event, ?Article $article ): ?array {
		$hold = apply_filters( 'estasoft_nd_internal_delivery_hold', null, $channel, $event, $article );

		if ( ! is_array( $hold ) || ! isset( $hold['status'], $hold['message'] ) ) {
			return null;
		}

		return array(
			'status'  => (string) $hold['status'],
			'message' => (string) $hold['message'],
			'until'   => isset( $hold['until'] ) && (int) $hold['until'] > Clock::now() ? (int) $hold['until'] : null,
		);
	}
}

<?php
/**
 * Background queue for pushed channels.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over Action Scheduler. WP-Cron is not used for deliveries, and
 * no remote request ever runs inside save_post.
 *
 * The action carries four small values (channel, article, event, attempt) and
 * nothing else, so the stored action stays small and the language is read from
 * the article when the delivery runs.
 */
final class Queue {

	public const GROUP = 'estasoft_nd';
	public const HOOK  = 'estasoft_nd_deliver';

	/**
	 * How many waiting deliveries are read at a time, and how many at most when one article's are looked for.
	 */
	private const SCAN_PAGE  = 200;
	private const SCAN_LIMIT = 5000;

	/**
	 * Whether Action Scheduler is loaded.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Queues one delivery.
	 *
	 * @param string $channel_id    Channel that delivers.
	 * @param int    $article_id    Article ID.
	 * @param string $event_type    Event type: publish, update, unpublish or delete.
	 * @param int    $delay_seconds Wait before running. Zero runs as soon as possible.
	 * @param int    $attempt       Attempt number, starting at 1.
	 * @return int Action ID, or 0 when the queue is unavailable or the same delivery is already waiting.
	 */
	public function enqueue( string $channel_id, int $article_id, string $event_type, int $delay_seconds = 0, int $attempt = 1 ): int {
		if ( ! $this->is_available() ) {
			return 0;
		}

		return self::once( $delay_seconds > 0 ? Clock::now() + $delay_seconds : 0, self::HOOK, array( $channel_id, $article_id, $event_type, $attempt ), self::GROUP );
	}

	/**
	 * Whether an action with exactly these arguments is waiting to run.
	 *
	 * Only a waiting (pending) action counts. An action that is running now does not, so a job can book its own next run
	 * from inside itself.
	 *
	 * @param string       $hook  The action's hook.
	 * @param array<mixed> $args  Its arguments, as they were given when it was scheduled.
	 * @param string       $group Its group.
	 * @return bool
	 */
	public static function is_waiting( string $hook, array $args, string $group = self::GROUP ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return false;
		}

		$found = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => $group,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);

		return is_array( $found ) && array() !== $found;
	}

	/**
	 * Books an action unless the same one (the same hook and the same arguments) is already waiting.
	 *
	 * Action Scheduler's own `unique` flag is not used: it looks at the hook and the group only, so with it one delivery
	 * in the queue would turn every other delivery away, whatever its channel or article.
	 *
	 * @param int          $when  When to run, as a Unix time, or 0 to run as soon as possible.
	 * @param string       $hook  The action's hook.
	 * @param array<mixed> $args  Its arguments.
	 * @param string       $group Its group.
	 * @return int The action's ID, or 0 when the queue is unavailable or the same action is already waiting.
	 */
	public static function once( int $when, string $hook, array $args = array(), string $group = self::GROUP ): int {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_enqueue_async_action' ) || self::is_waiting( $hook, $args, $group ) ) {
			return 0;
		}

		if ( $when > Clock::now() ) {
			return (int) as_schedule_single_action( $when, $hook, $args, $group );
		}

		return (int) as_enqueue_async_action( $hook, $args, $group );
	}

	/**
	 * Cancels one waiting delivery, such as the next try of a push that is being retried.
	 *
	 * @param string $channel_id Channel that delivers.
	 * @param int    $article_id Article ID.
	 * @param string $event_type Event type.
	 * @param int    $attempt    The attempt number the waiting action carries.
	 * @return void
	 */
	public function cancel_delivery( string $channel_id, int $article_id, string $event_type, int $attempt ): void {
		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( self::HOOK, array( $channel_id, $article_id, $event_type, $attempt ), self::GROUP );
		}
	}

	/**
	 * The deliveries of one article that wait in the queue.
	 *
	 * The queue is read a page at a time up to a limit, so an article is found when a busy site has far more than one page
	 * of deliveries waiting.
	 *
	 * @param int $article_id Article ID.
	 * @return array<int, array{id: int, args: array<mixed>, at: int}> Action ID, arguments and run time (0 when unknown).
	 */
	public function waiting_for_article( int $article_id ): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler' ) ) {
			return array();
		}

		$found  = array();
		$offset = 0;

		while ( $offset < self::SCAN_LIMIT ) {
			$ids = as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK,
					'group'    => self::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => self::SCAN_PAGE,
					'offset'   => $offset,
				),
				'ids'
			);

			if ( ! is_array( $ids ) || array() === $ids ) {
				break;
			}

			foreach ( $ids as $action_id ) {
				$action = \ActionScheduler::store()->fetch_action( $action_id );
				$args   = $action->get_args();

				if ( (int) ( $args[1] ?? 0 ) !== $article_id ) {
					continue;
				}

				$date    = $action->get_schedule()->get_date();
				$found[] = array(
					'id'   => (int) $action_id,
					'args' => $args,
					'at'   => null === $date ? 0 : (int) $date->getTimestamp(),
				);
			}

			if ( count( $ids ) < self::SCAN_PAGE ) {
				break;
			}

			$offset += self::SCAN_PAGE;
		}

		return $found;
	}

	/**
	 * Cancels every waiting delivery of one article, for example when it is unpublished.
	 *
	 * @param int           $article_id Article ID.
	 * @param string[]|null $only_events Event types to cancel, or null for all.
	 * @return void
	 */
	public function cancel_for_article( int $article_id, ?array $only_events = null ): void {
		foreach ( $this->waiting_for_article( $article_id ) as $item ) {
			if ( null !== $only_events && ! in_array( (string) ( $item['args'][2] ?? '' ), $only_events, true ) ) {
				continue;
			}

			\ActionScheduler::store()->cancel_action( $item['id'] );
		}
	}

	/**
	 * Schedules a repeating job that runs through the queue, if it is not already scheduled.
	 *
	 * @param string $hook     Action hook.
	 * @param int    $interval Seconds between runs.
	 * @return void
	 */
	public function ensure_recurring( string $hook, int $interval ): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
			as_schedule_recurring_action( Clock::now() + $interval, $interval, $hook, array(), self::GROUP );
		}
	}

	/**
	 * Removes every action in the plugin's group. Used by uninstall.
	 *
	 * @return void
	 */
	public function clear(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}
}

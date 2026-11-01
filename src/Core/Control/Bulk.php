<?php
/**
 * Bulk actions on a selection of articles: check again, send now, hold, release.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Control;

use Estasoft\NewsDistribution\Checks\ArticleChecker;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\ArticleService;
use Estasoft\NewsDistribution\Core\Hold;
use Estasoft\NewsDistribution\Core\Queue;
use Estasoft\NewsDistribution\Core\Takedown;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The Posts list, `POST /articles/bulk` and the row action all use this class, so a
 * bulk action does exactly what the same action on one article does.
 *
 * Every run is a job (`Core\Jobs`). A selection of up to INLINE posts is done before
 * the call returns and the job is already `done`. A longer one is done in the queue,
 * BATCH posts at a time, and the caller polls `GET /jobs/{id}`. A post that cannot be
 * changed (a draft that is on hold, a post the person may not edit) is skipped, named in
 * the result with the reason, and never stops the run.
 *
 * The person who started the run is the signed-in user while a batch runs, so the audit
 * rows carry their name.
 */
final class Bulk {

	public const CHECK   = 'check';
	public const SEND    = 'send';
	public const HOLD    = 'hold';
	public const RELEASE = 'release';

	/**
	 * The queue action that does one batch.
	 */
	public const HOOK = 'estasoft_nd_bulk_batch';

	/**
	 * Most posts in one run.
	 */
	public const MAX = 50;

	/**
	 * Posts done in one background batch.
	 */
	public const BATCH = 20;

	/**
	 * Up to this many posts are done before the request ends.
	 */
	public const INLINE = 5;

	private const WORK = 'estasoft_nd_bulk_';

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
	 * The actions, with the label the Bulk actions menu shows.
	 *
	 * @return array<string, string>
	 */
	public static function actions(): array {
		return array(
			self::CHECK   => __( 'Check again', 'estasoft-news-distribution' ),
			self::SEND    => __( 'Send now, skip the delay', 'estasoft-news-distribution' ),
			self::HOLD    => __( 'Hold partner feeds', 'estasoft-news-distribution' ),
			self::RELEASE => __( 'Release hold', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * The actions a person may run.
	 *
	 * @param int|null $user_id Person, or the signed-in user.
	 * @return array<string, string>
	 */
	public function allowed( ?int $user_id = null ): array {
		$out = array();

		foreach ( self::actions() as $action => $label ) {
			if ( $this->plugin->access()->can( self::CHECK === $action ? Access::VIEW_OWN : Access::VIEW, null, $user_id ) ) {
				$out[ $action ] = $label;
			}
		}

		return $out;
	}

	/**
	 * Starts a run.
	 *
	 * @param string               $action  One of the class constants.
	 * @param int[]                $ids     Article IDs.
	 * @param array<string, mixed> $args    `reason` for a hold.
	 * @param int|null             $user_id Person, or the signed-in user.
	 * @return array{job: string, state: string, total: int, inline: bool}|\WP_Error
	 */
	public function start( string $action, array $ids, array $args = array(), ?int $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : $user_id;

		if ( ! array_key_exists( $action, $this->allowed( $user_id ) ) ) {
			return $this->fail( 'estasoft_nd_bulk_not_allowed', __( 'You cannot run that action on these posts.', 'estasoft-news-distribution' ), rest_authorization_required_code() );
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( array() === $ids ) {
			return $this->fail( 'estasoft_nd_bulk_nothing', __( 'Select at least one post.', 'estasoft-news-distribution' ), 422, array( 'ids' ) );
		}

		if ( count( $ids ) > self::MAX ) {
			return $this->fail(
				'estasoft_nd_bulk_too_many',
				sprintf(
					/* translators: %d: the most posts one run can take. */
					__( 'Select %d posts or fewer for one run.', 'estasoft-news-distribution' ),
					self::MAX
				),
				422,
				array( 'ids' )
			);
		}

		$reason = isset( $args['reason'] ) ? trim( mb_substr( sanitize_textarea_field( (string) $args['reason'] ), 0, Hold::REASON_MAX ) ) : '';

		if ( self::HOLD === $action && '' === $reason ) {
			return $this->fail( 'estasoft_nd_hold_reason_needed', __( 'Write why you are holding the articles.', 'estasoft-news-distribution' ), 422, array( 'reason' ) );
		}

		$job = $this->plugin->jobs()->start(
			'bulk-' . $action,
			count( $ids ),
			array(
				'action' => $action,
				'label'  => self::actions()[ $action ],
			)
		);

		set_transient(
			self::WORK . $job,
			array(
				'action'  => $action,
				'ids'     => $ids,
				'reason'  => $reason,
				'user'    => $user_id,
				'cursor'  => 0,
				'ok'      => 0,
				'checked' => 0,
				'clean'   => 0,
				'queued'  => 0,
				'skipped' => array(),
			),
			DAY_IN_SECONDS
		);

		$inline = count( $ids ) <= self::INLINE || ! $this->plugin->queue()->is_available();

		if ( $inline ) {
			while ( $this->step( $job ) ) {
				continue;
			}
		} else {
			$this->schedule( $job, 1 );
		}

		$state = $this->plugin->jobs()->get( $job );

		return array(
			'job'    => $job,
			'state'  => null === $state ? 'queued' : (string) $state['state'],
			'total'  => count( $ids ),
			'inline' => $inline,
		);
	}

	/**
	 * Does one background batch. The queue calls this.
	 *
	 * @param mixed $job   Job id.
	 * @param mixed $batch Batch number, so each batch is its own queue action.
	 * @return void
	 */
	public function process( $job = '', $batch = 0 ): void {
		unset( $batch );

		$job = sanitize_key( (string) $job );

		if ( '' === $job ) {
			return;
		}

		if ( $this->step( $job ) ) {
			$work = get_transient( self::WORK . $job );

			$this->schedule( $job, is_array( $work ) ? (int) $work['cursor'] : 0 );
		}
	}

	// ---------------------------------------------------------------------
	// Internals.
	// ---------------------------------------------------------------------

	/**
	 * Does up to BATCH posts of a run.
	 *
	 * @param string $job Job id.
	 * @return bool Whether there is more to do.
	 */
	private function step( string $job ): bool {
		$jobs = $this->plugin->jobs();
		$work = get_transient( self::WORK . $job );

		if ( ! is_array( $work ) ) {
			$jobs->finish( $job, false, array(), __( 'The run was lost before it finished. Start it again.', 'estasoft-news-distribution' ) );

			return false;
		}

		$previous = get_current_user_id();

		if ( $previous !== (int) $work['user'] ) {
			wp_set_current_user( (int) $work['user'] );
		}

		$slice = array_slice( $work['ids'], (int) $work['cursor'], self::BATCH );

		foreach ( $slice as $post_id ) {
			$one = $this->apply( (string) $work['action'], (int) $post_id, (string) $work['reason'], (int) $work['user'] );

			if ( $one['ok'] ) {
				++$work['ok'];
				$work['checked'] += $one['checked'];
				$work['clean']   += $one['clean'];
				$work['queued']  += $one['queued'];
			} else {
				$work['skipped'][] = array(
					'id'     => (int) $post_id,
					'title'  => wp_strip_all_tags( get_the_title( (int) $post_id ) ),
					'reason' => $one['reason'],
				);
			}
		}

		if ( $previous !== (int) $work['user'] ) {
			wp_set_current_user( $previous );
		}

		$work['cursor'] += count( $slice );
		$total           = count( $work['ids'] );

		if ( $work['cursor'] < $total ) {
			set_transient( self::WORK . $job, $work, DAY_IN_SECONDS );
			$jobs->progress( $job, (int) $work['cursor'], $total );

			return true;
		}

		delete_transient( self::WORK . $job );

		$result = array(
			'action'  => $work['action'],
			'total'   => $total,
			'ok'      => (int) $work['ok'],
			'checked' => (int) $work['checked'],
			'clean'   => (int) $work['clean'],
			'queued'  => (int) $work['queued'],
			'skipped' => $work['skipped'],
		);

		$jobs->finish( $job, true, $result, $this->summary( $result ) );

		return false;
	}

	/**
	 * Does one action on one post.
	 *
	 * @param string $action  The action.
	 * @param int    $post_id Article ID.
	 * @param string $reason  Reason for a hold.
	 * @param int    $user_id The person who started the run.
	 * @return array{ok: bool, reason: string, checked: int, clean: int, queued: int}
	 */
	private function apply( string $action, int $post_id, string $reason, int $user_id ): array {
		$done = array(
			'ok'      => true,
			'reason'  => '',
			'checked' => 0,
			'clean'   => 0,
			'queued'  => 0,
		);
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return $this->skip( __( 'It is not a post News Distribution sends.', 'estasoft-news-distribution' ) );
		}

		if ( ! $this->plugin->access()->can_edit_article( $post_id, $user_id ) ) {
			return $this->skip( __( 'You are not allowed to edit it.', 'estasoft-news-distribution' ) );
		}

		$hold = $this->plugin->make( Hold::class );

		switch ( $action ) {
			case self::CHECK:
				$report = $this->plugin->make( ArticleChecker::class )->check( $post_id );

				if ( null === $report ) {
					return $this->skip( __( 'The post could not be read.', 'estasoft-news-distribution' ) );
				}

				$done['checked'] = 1;
				$done['clean']   = $report->is_clean() ? 1 : 0;

				return $done;

			case self::SEND:
				if ( 'publish' !== $post->post_status ) {
					return $this->skip( __( 'It is not published, so there is nothing to send.', 'estasoft-news-distribution' ) );
				}

				if ( $hold->is_held( $post_id ) ) {
					return $this->skip( __( 'It is on hold. Release the hold first.', 'estasoft-news-distribution' ) );
				}

				if ( $this->plugin->make( Takedown::class )->is_down( $post_id ) ) {
					return $this->skip( __( 'It was taken down.', 'estasoft-news-distribution' ) );
				}

				$sent = $this->plugin->make( ArticleService::class )->send( $post_id );

				if ( is_wp_error( $sent ) ) {
					return $this->skip( $sent->get_error_message() );
				}

				if ( array() === $sent['queued'] ) {
					$why = isset( $sent['skipped'][0]['reason'] ) ? (string) $sent['skipped'][0]['reason'] : __( 'No push channel takes it.', 'estasoft-news-distribution' );

					return $this->skip( $why );
				}

				$done['queued'] = count( $sent['queued'] );

				return $done;

			case self::HOLD:
				if ( $hold->is_held( $post_id ) ) {
					return $this->skip( __( 'It is already on hold.', 'estasoft-news-distribution' ) );
				}

				$held = $hold->hold( $post_id, $reason, $user_id );

				return is_wp_error( $held ) ? $this->skip( $held->get_error_message() ) : $done;

			case self::RELEASE:
				if ( ! $hold->is_held( $post_id ) ) {
					return $this->skip( __( 'It is not on hold.', 'estasoft-news-distribution' ) );
				}

				$lifted = $hold->lift( $post_id, $user_id );

				return is_wp_error( $lifted ) ? $this->skip( $lifted->get_error_message() ) : $done;
		}

		return $this->skip( __( 'That action does not exist.', 'estasoft-news-distribution' ) );
	}

	/**
	 * A post that was not changed.
	 *
	 * @param string $reason Why.
	 * @return array{ok: bool, reason: string, checked: int, clean: int, queued: int}
	 */
	private function skip( string $reason ): array {
		return array(
			'ok'      => false,
			'reason'  => $reason,
			'checked' => 0,
			'clean'   => 0,
			'queued'  => 0,
		);
	}

	/**
	 * One sentence about a finished run.
	 *
	 * @param array<string, mixed> $result The result stored on the job.
	 * @return string
	 */
	private function summary( array $result ): string {
		$ok   = (int) $result['ok'];
		$text = '';

		switch ( $result['action'] ) {
			case self::CHECK:
				$text = sprintf(
					/* translators: %d: how many articles were checked. */
					_n( '%d article checked.', '%d articles checked.', $ok, 'estasoft-news-distribution' ),
					$ok
				);

				if ( $ok > 0 ) {
					$clean = (int) $result['clean'];
					$need  = $ok - $clean;

					if ( 0 === $need ) {
						$text .= ' ' . ( 1 === $ok ? __( 'It is clean.', 'estasoft-news-distribution' ) : __( 'All are clean.', 'estasoft-news-distribution' ) );
					} elseif ( 0 === $clean ) {
						$text .= ' ' . ( 1 === $ok ? __( 'It needs attention.', 'estasoft-news-distribution' ) : __( 'All need attention.', 'estasoft-news-distribution' ) );
					} else {
						$text .= ' ' . sprintf(
							/* translators: 1: how many articles are clean, with the verb, 2: how many need attention, with the verb. */
							__( '%1$s and %2$s.', 'estasoft-news-distribution' ),
							sprintf(
								/* translators: %d: number of clean articles. */
								_n( '%d is clean', '%d are clean', $clean, 'estasoft-news-distribution' ),
								$clean
							),
							sprintf(
								/* translators: %d: number of articles that need attention. */
								_n( '%d needs attention', '%d need attention', $need, 'estasoft-news-distribution' ),
								$need
							)
						);
					}
				}
				break;

			case self::SEND:
				$text = sprintf(
					/* translators: %d: how many articles were sent. */
					_n( 'Pushes for %d article went in the queue.', 'Pushes for %d articles went in the queue.', $ok, 'estasoft-news-distribution' ),
					$ok
				);
				break;

			case self::HOLD:
				$text = sprintf(
					/* translators: %d: how many articles were held. */
					_n( '%d article is on hold.', '%d articles are on hold.', $ok, 'estasoft-news-distribution' ),
					$ok
				);
				break;

			case self::RELEASE:
				$text = sprintf(
					/* translators: %d: how many articles were released. */
					_n( '%d article was released.', '%d articles were released.', $ok, 'estasoft-news-distribution' ),
					$ok
				);
				break;
		}

		$skipped = count( (array) $result['skipped'] );

		if ( $skipped > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: %d: how many posts were skipped. */
				_n( '%d post was skipped.', '%d posts were skipped.', $skipped, 'estasoft-news-distribution' ),
				$skipped
			);
		}

		return $text;
	}

	/**
	 * Asks the queue for the next batch.
	 *
	 * @param string $job   Job id.
	 * @param int    $batch Number that keeps each queue action different.
	 * @return void
	 */
	private function schedule( string $job, int $batch ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $job, $batch ), Queue::GROUP );
		}
	}

	/**
	 * A plain-language error.
	 *
	 * @param string   $code    Stable code.
	 * @param string   $message Sentence the screen can show as written.
	 * @param int      $status  HTTP status.
	 * @param string[] $fields  Fields the message is about.
	 * @return \WP_Error
	 */
	private function fail( string $code, string $message, int $status, array $fields = array() ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( array( 'status' => $status ), array() === $fields ? array() : array( 'fields' => $fields ) ) );
	}
}

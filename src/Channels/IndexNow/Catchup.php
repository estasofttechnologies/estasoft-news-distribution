<?php
/**
 * Catch-up submission: sends the articles a site already has to IndexNow, in batches.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\IndexNow;

use Estasoft\NewsDistribution\Channels\DeliveryResult;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Queue;
use Estasoft\NewsDistribution\Core\Retry;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * A person starts a catch-up on the IndexNow page. It reads the published articles
 * of the chosen range that were never sent, and sends them in batches of 1,000
 * with a pause between batches, so one run never floods IndexNow.
 *
 * - The run is kept in the option `estasoft_nd_indexnow_run`. Each batch is one
 *   queue action (`estasoft_nd_indexnow_catchup`); the next is scheduled by the
 *   previous. Nothing is sent outside a batch.
 * - Progress goes to Core\Jobs, so the screen polls `GET /jobs/{id}`.
 * - Each address is sent once: an accepted batch marks its articles with the post
 *   meta `_estasoft_nd_indexnow_sent`. An article sent by a normal publish or
 *   update is marked too, so a second run only sends what is new.
 * - Pause keeps the run and lets it resume. Cancel ends it. Either way what was
 *   already sent stays sent.
 * - A batch that fails with a code that can pass is tried again after 1, 5 and 15
 *   minutes, then the run stops as failed. A refusal that will not change stops it
 *   at once.
 * - Every batch is one row in the delivery log: event `catchup`, article 0, and a
 *   message that starts with the number of addresses.
 */
final class Catchup {

	public const HOOK   = 'estasoft_nd_indexnow_catchup';
	public const OPTION = 'estasoft_nd_indexnow_run';
	public const META   = '_estasoft_nd_indexnow_sent';

	/**
	 * How far back a person can choose, in days.
	 *
	 * @var int[]
	 */
	public const RANGES = array( 7, 30, 90, 365 );

	/**
	 * Batches kept in the run for the screen.
	 */
	private const KEEP_BATCHES = 20;

	private Plugin $plugin;
	private IndexNowChannel $channel;

	/**
	 * @param Plugin          $plugin  The running plugin.
	 * @param IndexNowChannel $channel The IndexNow channel.
	 */
	public function __construct( Plugin $plugin, IndexNowChannel $channel ) {
		$this->plugin  = $plugin;
		$this->channel = $channel;
	}

	/**
	 * The catch-up of a running plugin, or null when IndexNow is not registered.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return self|null
	 */
	public static function of( Plugin $plugin ): ?self {
		$channel = $plugin->registry()->get( 'indexnow' );

		return $channel instanceof IndexNowChannel ? new self( $plugin, $channel ) : null;
	}

	/**
	 * Addresses in each batch.
	 *
	 * @return int
	 */
	public function batch_size(): int {
		return max( 1, min( $this->channel->max_urls(), (int) $this->plugin->rules()->limit( IndexNowChannel::PLATFORM, 'catchup_batch', 1000 ) ) );
	}

	/**
	 * Seconds between batches.
	 *
	 * @return int
	 */
	public function pause_seconds(): int {
		return max( 1, (int) $this->plugin->rules()->limit( IndexNowChannel::PLATFORM, 'catchup_pause_seconds', 60 ) );
	}

	/**
	 * The run as it is kept, with defaults.
	 *
	 * @return array{job: string, state: string, days: int, languages: string[], total: int, sent: int, rounds: int, next_at: int, attempt: int, started: int, ended: int, message: string, batches: array<int, array<string, mixed>>}
	 */
	public function run(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'job'       => (string) ( $stored['job'] ?? '' ),
			'state'     => (string) ( $stored['state'] ?? 'idle' ),
			'days'      => (int) ( $stored['days'] ?? 0 ),
			'languages' => array_values( array_map( 'strval', (array) ( $stored['languages'] ?? array() ) ) ),
			'total'     => (int) ( $stored['total'] ?? 0 ),
			'sent'      => (int) ( $stored['sent'] ?? 0 ),
			'rounds'    => (int) ( $stored['rounds'] ?? 0 ),
			'next_at'   => (int) ( $stored['next_at'] ?? 0 ),
			'attempt'   => max( 1, (int) ( $stored['attempt'] ?? 1 ) ),
			'started'   => (int) ( $stored['started'] ?? 0 ),
			'ended'     => (int) ( $stored['ended'] ?? 0 ),
			'message'   => (string) ( $stored['message'] ?? '' ),
			'batches'   => array_values( (array) ( $stored['batches'] ?? array() ) ),
		);
	}

	/**
	 * Whether a run is going or paused, so another cannot start.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return in_array( $this->run()['state'], array( 'running', 'paused' ), true );
	}

	/**
	 * How many addresses a catch-up for this many days would send now.
	 *
	 * @param int $days How far back, in days.
	 * @return int
	 */
	public function count( int $days ): int {
		$total = 0;

		foreach ( $this->channel->carried_languages() as $language ) {
			$total += $this->query( $language, $days, 1, true )['found'];
		}

		return $total;
	}

	/**
	 * Starts a catch-up. Nothing is sent here: the first batch runs from the queue.
	 *
	 * @param int $days How far back, in days. One of RANGES.
	 * @return array<string, mixed>|\WP_Error The run, or an error with a plain message.
	 */
	public function start( int $days ) {
		if ( ! in_array( $days, self::RANGES, true ) ) {
			return $this->error( 'estasoft_nd_invalid_value', __( 'Choose how far back to go: 7, 30, 90 or 365 days.', 'estasoft-news-distribution' ), 400 );
		}

		if ( ! $this->channel->is_enabled() ) {
			return $this->error( 'estasoft_nd_channel_off', __( 'IndexNow is off. Turn it on before sending a catch-up.', 'estasoft-news-distribution' ), 409 );
		}

		if ( '' === $this->channel->key() ) {
			return $this->error( 'estasoft_nd_no_key', __( 'There is no key yet. Create one on the IndexNow page first.', 'estasoft-news-distribution' ), 409 );
		}

		if ( $this->is_active() ) {
			return $this->error( 'estasoft_nd_catchup_active', __( 'A catch-up is already running. Another can start when it ends.', 'estasoft-news-distribution' ), 409 );
		}

		if ( ! $this->plugin->queue()->is_available() ) {
			return $this->error( 'estasoft_nd_queue_unavailable', __( 'The background queue is not available, so a catch-up cannot run.', 'estasoft-news-distribution' ), 503 );
		}

		$languages = $this->channel->carried_languages();
		$total     = $this->count( $days );

		if ( $total < 1 ) {
			return $this->error( 'estasoft_nd_nothing_to_send', __( 'There are no articles to send in that range. Every address was already sent, or none was published.', 'estasoft-news-distribution' ), 409 );
		}

		$job = $this->plugin->jobs()->start(
			'indexnow-catchup',
			$total,
			array(
				'days'      => $days,
				'languages' => $languages,
			)
		);

		$this->save(
			array(
				'job'       => $job,
				'state'     => 'running',
				'days'      => $days,
				'languages' => $languages,
				'total'     => $total,
				'sent'      => 0,
				'rounds'    => 0,
				'next_at'   => Clock::now(),
				'attempt'   => 1,
				'started'   => Clock::now(),
				'ended'     => 0,
				'message'   => '',
				'batches'   => array(),
			)
		);

		$this->schedule( 0 );

		return $this->run();
	}

	/**
	 * Pauses a running catch-up. What was sent stays sent.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function pause() {
		$run = $this->run();

		if ( 'running' !== $run['state'] ) {
			return $this->error( 'estasoft_nd_catchup_not_running', __( 'There is no running catch-up to pause.', 'estasoft-news-distribution' ), 409 );
		}

		$run['state'] = 'paused';
		$this->save( $run );
		$this->unschedule();

		return $this->run();
	}

	/**
	 * Resumes a paused catch-up.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function resume() {
		$run = $this->run();

		if ( 'paused' !== $run['state'] ) {
			return $this->error( 'estasoft_nd_catchup_not_paused', __( 'There is no paused catch-up to resume.', 'estasoft-news-distribution' ), 409 );
		}

		$run['state']   = 'running';
		$run['next_at'] = Clock::now();
		$this->save( $run );
		$this->schedule( 0 );

		return $this->run();
	}

	/**
	 * Ends a running or paused catch-up. What was sent stays sent.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function cancel() {
		$run = $this->run();

		if ( ! $this->is_active() ) {
			return $this->error( 'estasoft_nd_catchup_not_active', __( 'There is no catch-up to cancel.', 'estasoft-news-distribution' ), 409 );
		}

		$this->unschedule();
		$this->end( $run, 'cancelled', __( 'Cancelled. The addresses already sent stay sent.', 'estasoft-news-distribution' ) );

		return $this->run();
	}

	/**
	 * Sends one batch. Called by the queue.
	 *
	 * @return void
	 */
	public function process(): void {
		$run = $this->run();

		if ( 'running' !== $run['state'] || $run['next_at'] > Clock::now() ) {
			return;
		}

		if ( ! $this->channel->is_enabled() || '' === $this->channel->key() ) {
			$this->end( $run, 'failed', __( 'The catch-up stopped because IndexNow was turned off or has no key.', 'estasoft-news-distribution' ) );
			return;
		}

		$batch = $this->batch( $run );

		if ( array() === $batch ) {
			$this->end( $run, 'done', '' );
			return;
		}

		$result = $this->channel->submit( array_values( $batch ) );
		$count  = count( $batch );
		$ok     = DeliveryResult::SUCCESS === $result->status();
		$number = $run['rounds'] + 1;

		if ( $ok || DeliveryResult::SKIPPED === $result->status() ) {
			$this->mark( array_keys( $batch ), $ok ? Clock::now() : 0 );
			$this->log( $count, $ok ? Status::SENT : Status::SKIPPED, $run['attempt'], $result->code(), $result->message(), null );

			$run            = $this->put_row( $run, $this->batch_row( $number, $count, $result->code(), $ok ? 'sent' : 'skipped', $result->message() ) );
			$run['sent']   += $ok ? $count : 0;
			$run['rounds']  = $number;
			$run['attempt'] = 1;

			if ( $ok && $run['sent'] >= $run['total'] ) {
				$this->save( $run );
				$this->end( $this->run(), 'done', '' );
				return;
			}

			$run['next_at'] = Clock::now() + $this->pause_seconds();
			$this->save( $run );
			$this->plugin->jobs()->progress( $run['job'], $run['sent'] );
			$this->schedule( $this->pause_seconds() );
			return;
		}

		$delay = $result->is_retryable() ? Retry::delay_after( $run['attempt'] ) : null;

		if ( null !== $delay ) {
			$this->log( $count, Status::RETRY, $run['attempt'], $result->code(), $result->message(), Clock::now() + $delay );

			$run            = $this->put_row( $run, $this->batch_row( $number, $count, $result->code(), 'retry', $result->message() ) );
			$run['next_at'] = Clock::now() + $delay;
			++$run['attempt'];
			$this->save( $run );
			$this->schedule( $delay );
			return;
		}

		$this->log( $count, Status::FAILED, $run['attempt'], $result->code(), $result->message(), null );

		$run = $this->put_row( $run, $this->batch_row( $number, $count, $result->code(), 'failed', $result->message() ) );
		$this->save( $run );
		$this->end( $this->run(), 'failed', $result->message() );
	}

	/**
	 * Seconds until the next batch starts, or 0.
	 *
	 * @return int
	 */
	public function seconds_to_next(): int {
		$run = $this->run();

		return 'running' === $run['state'] ? max( 0, $run['next_at'] - Clock::now() ) : 0;
	}

	/**
	 * The run for the screen and the REST answer: ISO times, percent and the counts for each range.
	 *
	 * @param bool $with_counts Add how many addresses each range would send.
	 * @return array<string, mixed>
	 */
	public function summary( bool $with_counts = false ): array {
		$run     = $this->run();
		$percent = $run['total'] > 0 ? (int) min( 100, floor( $run['sent'] / $run['total'] * 100 ) ) : 0;
		$out     = array(
			'state'     => $run['state'],
			'job'       => $run['job'],
			'days'      => $run['days'],
			'languages' => $run['languages'],
			'total'     => $run['total'],
			'sent'      => $run['sent'],
			'percent'   => 'done' === $run['state'] ? 100 : $percent,
			'next_in'   => $this->seconds_to_next(),
			'started'   => $run['started'] > 0 ? Clock::iso( $run['started'] ) : '',
			'ended'     => $run['ended'] > 0 ? Clock::iso( $run['ended'] ) : '',
			'message'   => $run['message'],
			'batches'   => $run['batches'],
		);

		if ( $with_counts ) {
			$counts = array();

			foreach ( self::RANGES as $days ) {
				$counts[ (string) $days ] = $this->count( $days );
			}

			$out['counts'] = $counts;
		}

		return $out;
	}

	/**
	 * The next batch: article id => address, newest first, never sent before.
	 *
	 * @param array<string, mixed> $run The run.
	 * @return array<int, string>
	 */
	private function batch( array $run ): array {
		$batch = array();
		$room  = $this->batch_size();

		foreach ( $run['languages'] as $language ) {
			if ( $room < 1 ) {
				break;
			}

			$found = $this->query( (string) $language, (int) $run['days'], $room, false );

			foreach ( $found['urls'] as $id => $url ) {
				$batch[ $id ] = $url;
			}

			$room = $this->batch_size() - count( $batch );
		}

		return $batch;
	}

	/**
	 * Reads the articles of one language that were not sent, newest first.
	 *
	 * @param string $language Language code.
	 * @param int    $days     How far back.
	 * @param int    $limit    Most articles.
	 * @param bool   $count    Only count them.
	 * @return array{found: int, urls: array<int, string>}
	 */
	private function query( string $language, int $days, int $limit, bool $count ): array {
		$args = array(
			'post_type'              => $this->plugin->settings()->post_types(),
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => max( 1, $limit ),
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'fields'                 => 'ids',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => ! $count,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
			'date_query'             => array(
				array(
					'column'    => 'post_date_gmt',
					'after'     => gmdate( 'Y-m-d H:i:s', Clock::now() - $days * DAY_IN_SECONDS ),
					'before'    => gmdate( 'Y-m-d H:i:s', Clock::now() ),
					'inclusive' => true,
				),
			),
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Finds articles that were never sent to IndexNow; it runs in small batches in the background.
				array(
					'key'     => self::META,
					'compare' => 'NOT EXISTS',
				),
			),
		);

		$args = array_merge( $args, $this->plugin->languages()->query_args( $language ) );

		$read = $this->plugin->languages()->with_language(
			$language,
			static function () use ( $args, $count ): array {
				$query = new \WP_Query( $args );
				$urls  = array();

				if ( ! $count ) {
					foreach ( $query->posts as $id ) {
						$link = get_permalink( (int) $id );

						if ( is_string( $link ) && '' !== $link ) {
							$urls[ (int) $id ] = $link;
						}
					}
				}

				return array(
					'found' => (int) $query->found_posts,
					'urls'  => $urls,
				);
			}
		);

		return is_array( $read ) ? $read : array(
			'found' => 0,
			'urls'  => array(),
		);
	}

	/**
	 * Marks articles as handled so a later batch and a later run skip them.
	 *
	 * @param int[] $ids   Article ids.
	 * @param int   $stamp Time they were sent, or 0 when they were left out.
	 * @return void
	 */
	private function mark( array $ids, int $stamp ): void {
		foreach ( $ids as $id ) {
			update_post_meta( (int) $id, self::META, $stamp );
		}
	}

	/**
	 * Adds a batch to the run. A batch that is tried again replaces its own earlier row.
	 *
	 * @param array<string, mixed> $run The run.
	 * @param array<string, mixed> $row The batch row.
	 * @return array<string, mixed>
	 */
	private function put_row( array $run, array $row ): array {
		$last = count( $run['batches'] ) - 1;

		if ( $last >= 0 && 'retry' === ( $run['batches'][ $last ]['state'] ?? '' ) && (int) $run['batches'][ $last ]['n'] === (int) $row['n'] ) {
			$run['batches'][ $last ] = $row;
		} else {
			$run['batches'][] = $row;
		}

		return $run;
	}

	/**
	 * One batch as the screen lists it.
	 *
	 * @param int    $number Batch number, from 1.
	 * @param int    $count  Addresses in the batch.
	 * @param int    $code   HTTP status, or 0.
	 * @param string $state  sent, skipped, retry or failed.
	 * @param string $text   What the answer said.
	 * @return array<string, mixed>
	 */
	private function batch_row( int $number, int $count, int $code, string $state, string $text ): array {
		return array(
			'n'     => $number,
			'count' => $count,
			'code'  => $code,
			'state' => $state,
			'text'  => $text,
			'at'    => Clock::now(),
		);
	}

	/**
	 * Writes a batch to the delivery log. The message starts with the number of
	 * addresses, which the screen reads back.
	 *
	 * @param int      $count   Addresses in the batch.
	 * @param string   $status  Status code.
	 * @param int      $attempt Attempt number.
	 * @param int      $code    HTTP status, or 0.
	 * @param string   $text    What the answer said.
	 * @param int|null $run_at  When the next attempt runs, or null.
	 * @return void
	 */
	private function log( int $count, string $status, int $attempt, int $code, string $text, ?int $run_at ): void {
		$message = sprintf(
			/* translators: 1: number of addresses in one catch-up batch (keep it first: the screen reads it from the start), 2: what IndexNow answered. */
			_n( '%1$d address. %2$s', '%1$d addresses. %2$s', $count, 'estasoft-news-distribution' ),
			$count,
			$text
		);

		$this->plugin->log()->record( 0, $this->channel->id(), '', 'catchup', $status, $attempt, $code, $message, $run_at );
	}

	/**
	 * Ends the run.
	 *
	 * @param array<string, mixed> $run     The run.
	 * @param string               $state   done, cancelled or failed.
	 * @param string               $message A sentence for the screen, or empty.
	 * @return void
	 */
	private function end( array $run, string $state, string $message ): void {
		$run['state']   = $state;
		$run['message'] = $message;
		$run['ended']   = Clock::now();
		$run['next_at'] = 0;
		$this->save( $run );

		$this->plugin->jobs()->finish(
			(string) $run['job'],
			'done' === $state,
			array(
				'sent'  => (int) $run['sent'],
				'total' => (int) $run['total'],
			),
			$message
		);
	}

	/**
	 * Keeps the run.
	 *
	 * @param array<string, mixed> $run The run.
	 * @return void
	 */
	private function save( array $run ): void {
		$run['batches'] = array_slice( array_values( (array) $run['batches'] ), -self::KEEP_BATCHES );

		update_option( self::OPTION, $run, false );
	}

	/**
	 * Schedules the next batch in the queue.
	 *
	 * @param int $delay Seconds to wait. Zero runs as soon as possible.
	 * @return void
	 */
	private function schedule( int $delay ): void {
		Queue::once( $delay > 0 ? Clock::now() + $delay : 0, self::HOOK, array(), 'estasoft_nd' );
	}

	/**
	 * Removes the batch that is waiting in the queue.
	 *
	 * @return void
	 */
	private function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), 'estasoft_nd' );
		}
	}

	/**
	 * A plain-language error.
	 *
	 * @param string $code    Stable code.
	 * @param string $message A sentence the screen can show as written.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}

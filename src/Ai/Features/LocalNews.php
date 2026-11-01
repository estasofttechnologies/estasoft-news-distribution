<?php
/**
 * Local news: which articles are local to the places the owner lists, for the News Break rule.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai\Features;

use Estasoft\NewsDistribution\Ai\Config;
use Estasoft\NewsDistribution\Ai\Log;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * The owner switches "Send only local news" on, lists the places their readers use and chooses how strict to be.
 * Each article is read once, by the AI, from its title and its first paragraphs: when it is published (in the
 * background, never during the save) and once for the last 30 articles when the owner asks. The result is a verdict
 * and a confidence, kept on the article as `_estasoft_nd_local_ai`, and always shown with a reason.
 *
 * What the rule does with it (through the filter `estasoft_nd_news_break_local`, which News Break's rules already read):
 *
 * - `local`: the article may go in the News Break feed.
 * - `skip` and `decide`: it is left out. An article the AI is not sure about is never sent; a person decides first.
 *
 * A person can override any article: `local` or `skip`. The override is kept on the article as
 * `_estasoft_nd_local_override`, beats the AI from then on, and can be undone. Nothing here changes an article.
 *
 * A result is only as good as the title and the opening paragraphs. It is not a fact check and it cannot read a place
 * the owner did not list. The log row of each article records the AI's answer, and who overrode it.
 */
final class LocalNews extends AbstractFeature {

	public const OPTION   = 'estasoft_nd_ai_local';
	public const RESULT   = '_estasoft_nd_local_ai';
	public const OVERRIDE = '_estasoft_nd_local_override';
	public const QUEUE    = 'estasoft_nd_ai_local_queue';
	public const HOOK     = 'estasoft_nd_ai_local_batch';

	/**
	 * Articles in the list and in the first batch.
	 */
	public const RECENT = 30;

	/**
	 * Articles read in one background run.
	 */
	public const PER_RUN = 3;

	/**
	 * Characters of the opening that are sent.
	 */
	public const LEAD = 800;

	/**
	 * Confidence needed for `local`, by how strict the owner chose to be.
	 */
	private const LOCAL_AT = array(
		'strict'   => 85,
		'balanced' => 70,
		'broad'    => 55,
	);

	/**
	 * Confidence needed to leave an article out as not local.
	 */
	private const SKIP_AT = 55;

	public function name(): string {
		return 'local-news';
	}

	public function label(): string {
		return __( 'Local news', 'estasoft-news-distribution' );
	}

	public function can( int $post_id ): bool {
		$access = $this->plugin->access();

		return $access->can( Access::MANAGE ) && ( $post_id < 1 || $access->can( Access::AI, $post_id ) );
	}

	/**
	 * What the owner chose.
	 *
	 * @return array{enabled: bool, places: string[], strict: string}
	 */
	public function settings(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$strict = (string) ( $stored['strict'] ?? 'balanced' );

		return array(
			'enabled' => ! empty( $stored['enabled'] ),
			'places'  => $this->places( (array) ( $stored['places'] ?? array() ) ),
			'strict'  => isset( self::LOCAL_AT[ $strict ] ) ? $strict : 'balanced',
		);
	}

	/**
	 * Saves what the owner chose.
	 *
	 * @param array<string, mixed> $input `enabled`, `places` (a list, or text with one place on each line) and `strict`.
	 * @return array{enabled: bool, places: string[], strict: string}|\WP_Error
	 */
	public function save( array $input ) {
		$places = $input['places'] ?? array();
		$places = is_array( $places ) ? $places : preg_split( '/[\r\n,]+/', (string) $places );
		$places = $this->places( is_array( $places ) ? $places : array() );
		$strict = (string) ( $input['strict'] ?? 'balanced' );
		$on     = ! empty( $input['enabled'] );

		if ( ! isset( self::LOCAL_AT[ $strict ] ) ) {
			return new \WP_Error( 'estasoft_nd_ai_bad_strict', __( 'Choose Strict, Balanced or Broad.', 'estasoft-news-distribution' ), array( 'status' => 400 ) );
		}

		if ( $on && array() === $places ) {
			return new \WP_Error( 'estasoft_nd_ai_no_places', __( 'Add at least one place first. The AI uses the places as a guide.', 'estasoft-news-distribution' ), array( 'status' => 400 ) );
		}

		$before = $this->settings();

		update_option(
			self::OPTION,
			array(
				'enabled' => $on,
				'places'  => $places,
				'strict'  => $strict,
				'hash'    => md5( implode( '|', $places ) ),
			),
			false
		);

		if ( $before['places'] !== $places ) {
			$this->plugin->cache()->flush();
		}

		return $this->settings();
	}

	/**
	 * Whether the rule is on and the feature switch is on, so the rule has an effect.
	 *
	 * @return bool
	 */
	public function active(): bool {
		return $this->settings()['enabled'] && $this->plugin->make( Config::class )->feature_on( $this->name() );
	}

	/**
	 * The filter News Break's rules read: false leaves the article out.
	 *
	 * @param bool|null $local   What is known so far.
	 * @param mixed     $article The article.
	 * @return bool|null
	 */
	public function filter( $local, $article ) {
		if ( ! $article instanceof Article || ! $this->active() ) {
			return $local;
		}

		$state = $this->state( $article->id() );

		return 'local' === $state['state'];
	}

	/**
	 * What the rule does with one article now.
	 *
	 * @param int $post_id The article.
	 * @return array{state: string, source: string, confidence: int, word: string, why: string, log: int}
	 *         `state` is `local`, `skip`, `decide` (the AI is not sure) or `none` (not read yet, or read with other places). `source` is `override`, `ai` or an empty string.
	 */
	public function state( int $post_id ): array {
		$override = get_post_meta( $post_id, self::OVERRIDE, true );

		if ( is_array( $override ) && in_array( $override['value'] ?? '', array( 'local', 'skip' ), true ) ) {
			$result = $this->result( $post_id );

			return array(
				'state'      => (string) $override['value'],
				'source'     => 'override',
				'confidence' => $result['confidence'],
				'word'       => $result['word'],
				'why'        => $result['why'],
				'log'        => (int) ( $override['log'] ?? 0 ),
			);
		}

		$result = $this->result( $post_id );

		return array(
			'state'      => $result['state'],
			'source'     => 'none' === $result['state'] ? '' : 'ai',
			'confidence' => $result['confidence'],
			'word'       => $result['word'],
			'why'        => $result['why'],
			'log'        => $result['log'],
		);
	}

	/**
	 * Reads the AI's stored verdict and applies the owner's strictness.
	 *
	 * @param int $post_id The article.
	 * @return array{state: string, confidence: int, word: string, why: string, log: int}
	 */
	private function result( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::RESULT, true );
		$none   = array(
			'state'      => 'none',
			'confidence' => 0,
			'word'       => '',
			'why'        => '',
			'log'        => 0,
		);

		if ( ! is_array( $stored ) || ! isset( $stored['verdict'], $stored['confidence'] ) ) {
			return $none;
		}

		$settings = $this->settings();

		// A verdict made with other places is out of date: the article is read again before it counts.
		if ( (string) ( $stored['hash'] ?? '' ) !== md5( implode( '|', $settings['places'] ) ) ) {
			return $none;
		}

		$confidence = (int) $stored['confidence'];
		$verdict    = (string) $stored['verdict'];

		if ( 'local' === $verdict && $confidence >= self::LOCAL_AT[ $settings['strict'] ] ) {
			$state = 'local';
		} elseif ( 'not_local' === $verdict && $confidence >= self::SKIP_AT ) {
			$state = 'skip';
		} else {
			$state = 'decide';
		}

		return array(
			'state'      => $state,
			'confidence' => $confidence,
			'word'       => self::word( $confidence ),
			'why'        => (string) ( $stored['why'] ?? '' ),
			'log'        => (int) ( $stored['log'] ?? 0 ),
		);
	}

	/**
	 * "High", "Medium" or "Low".
	 *
	 * @param int $confidence A percentage.
	 * @return string
	 */
	public static function word( int $confidence ): string {
		if ( $confidence >= 80 ) {
			return __( 'High', 'estasoft-news-distribution' );
		}

		return $confidence >= 55 ? __( 'Medium', 'estasoft-news-distribution' ) : __( 'Low', 'estasoft-news-distribution' );
	}

	public function suggest( int $post_id, array $args ) {
		$settings = $this->settings();

		if ( array() === $settings['places'] ) {
			return new \WP_Error( 'estasoft_nd_ai_no_places', __( 'Add at least one place first. The AI uses the places as a guide.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		$article = $this->sendable( $post_id );

		if ( is_wp_error( $article ) ) {
			return $article;
		}

		$answer = $this->ask(
			array(
				'system'     => 'You decide whether a news article is local to a list of places, from its title and its first paragraphs only. Judge only what the text says. Reply with a JSON object and nothing else.',
				'prompt'     => "Places the publisher's readers use: " . implode( '; ', $settings['places'] ) . "\nTitle: " . trim( wp_strip_all_tags( (string) get_the_title( $post_id ) ) ) . "\nFirst paragraphs: " . $this->lead( $article, self::LEAD ) . "\n\nReply with JSON: {\"verdict\": \"local\" or \"not_local\" or \"unsure\", \"confidence\": a whole number from 0 to 100, \"why\": \"one short sentence saying which place or what makes it local or not\"}.",
				'max_tokens' => 200,
			)
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$read       = $this->json( $answer['text'] );
		$read       = is_array( $read ) ? $read : array();
		$verdict    = isset( $read['verdict'] ) && is_string( $read['verdict'] ) ? strtolower( trim( $read['verdict'] ) ) : '';
		$confidence = isset( $read['confidence'] ) && is_numeric( $read['confidence'] ) ? (float) $read['confidence'] : -1.0;
		$why        = isset( $read['why'] ) && is_string( $read['why'] ) ? trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $read['why'] ) ) ) : '';

		if ( $confidence > 0 && $confidence <= 1 ) {
			$confidence *= 100;
		}

		if ( ! in_array( $verdict, array( 'local', 'not_local', 'unsure' ), true ) || $confidence < 0 || $confidence > 100 ) {
			$verdict    = 'unsure';
			$confidence = 0.0;
			$why        = '';
		}

		if ( mb_strlen( $why ) > 140 ) {
			$why = rtrim( mb_substr( $why, 0, 137 ) ) . '…';
		}

		$confidence = (int) round( $confidence );
		$data       = array(
			'finding'    => 'local',
			'verdict'    => $verdict,
			'confidence' => $confidence,
			'why'        => $why,
			'strict'     => $settings['strict'],
		);

		$log = $this->record( $post_id, $answer, $data, Log::AUTO );

		update_post_meta(
			$post_id,
			self::RESULT,
			array(
				'verdict'    => $verdict,
				'confidence' => $confidence,
				'why'        => $why,
				'log'        => $log,
				'at'         => Clock::now(),
				'hash'       => md5( implode( '|', $settings['places'] ) ),
			)
		);

		return array_merge(
			array(
				'log'     => $log,
				'feature' => $this->name(),
				'state'   => $this->state( $post_id ),
			),
			$data,
			$this->meta( $answer ),
			array( 'message' => '' )
		);
	}

	public function decide( array $row, array $args, int $user_id ) {
		$post_id  = (int) $row['post_id'];
		$decision = sanitize_key( (string) ( $args['decision'] ?? '' ) );
		$log      = $this->plugin->make( Log::class );

		if ( 'clear' === $decision ) {
			delete_post_meta( $post_id, self::OVERRIDE );
			$log->annotate(
				(int) $row['id'],
				array(
					'override_cleared' => array(
						'by' => $user_id,
						'at' => Clock::now(),
					),
				)
			);

			return array(
				'log'      => (int) $row['id'],
				'decision' => 'cleared',
				'applied'  => 'override',
				'state'    => $this->state( $post_id ),
				'message'  => __( 'Put back. The result is used again.', 'estasoft-news-distribution' ),
			);
		}

		if ( 'local' !== $decision && 'skip' !== $decision ) {
			return new \WP_Error( 'estasoft_nd_ai_bad_decision', __( 'Choose Local or Skip.', 'estasoft-news-distribution' ), array( 'status' => 400 ) );
		}

		$before = $this->result( $post_id );
		$agrees = ( 'local' === $decision && 'local' === $before['state'] ) || ( 'skip' === $decision && 'skip' === $before['state'] );

		update_post_meta(
			$post_id,
			self::OVERRIDE,
			array(
				'value' => $decision,
				'by'    => $user_id,
				'at'    => Clock::now(),
				'log'   => (int) $row['id'],
			)
		);

		$log->decide(
			(int) $row['id'],
			$agrees ? Log::USED : Log::EDITED,
			$user_id,
			array(
				'override' => $decision,
				'before'   => $before['state'],
			)
		);

		return array(
			'log'      => (int) $row['id'],
			'decision' => $agrees ? Log::USED : Log::EDITED,
			'applied'  => 'override',
			'state'    => $this->state( $post_id ),
			'message'  => 'local' === $decision ? __( 'Marked Local. It stays on this article until you change it.', 'estasoft-news-distribution' ) : __( 'Marked Skip. It stays on this article until you change it.', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * Finds, or makes, the log row an override is recorded on.
	 *
	 * @param int $post_id The article.
	 * @return int The log row id, or 0.
	 */
	public function row_for( int $post_id ): int {
		$stored   = get_post_meta( $post_id, self::RESULT, true );
		$override = get_post_meta( $post_id, self::OVERRIDE, true );

		if ( is_array( $stored ) && ! empty( $stored['log'] ) ) {
			return (int) $stored['log'];
		}

		if ( is_array( $override ) && ! empty( $override['log'] ) && null !== $this->plugin->make( Log::class )->get( (int) $override['log'] ) ) {
			return (int) $override['log'];
		}

		return $this->plugin->make( Log::class )->add(
			array(
				'post_id'    => $post_id,
				'feature'    => $this->name(),
				'connection' => '',
				'decision'   => Log::PENDING,
				'data'       => array(
					'finding'      => 'local',
					'requested_by' => get_current_user_id(),
					'note'         => 'override without a result',
				),
			)
		);
	}

	/**
	 * The newest published articles with what the rule does with each.
	 *
	 * @param int $limit How many.
	 * @return array<int, array{id: int, title: string, state: array<string, mixed>}>
	 */
	public function recent( int $limit = self::RECENT ): array {
		$ids = get_posts(
			array(
				'post_type'        => $this->plugin->settings()->post_types(),
				'post_status'      => 'publish',
				'numberposts'      => max( 1, $limit ),
				'orderby'          => 'date',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- The list covers every language; another plugin's filters must not narrow it.
			)
		);
		$out = array();

		foreach ( $ids as $id ) {
			$out[] = array(
				'id'    => (int) $id,
				'title' => trim( wp_strip_all_tags( (string) get_the_title( (int) $id ) ) ),
				'state' => $this->state( (int) $id ),
			);
		}

		return $out;
	}

	/**
	 * Adds the newest articles that have no current result to the background queue.
	 *
	 * @param int $limit How many of the newest articles to look at.
	 * @return int How many were queued.
	 */
	public function queue_recent( int $limit = self::RECENT ): int {
		$ids = array();

		foreach ( $this->recent( $limit ) as $row ) {
			if ( 'none' === $row['state']['state'] ) {
				$ids[] = $row['id'];
			}
		}

		return $this->enqueue( $ids );
	}

	/**
	 * Adds articles to the queue and makes sure a background run is planned. Nothing is sent here.
	 *
	 * @param int[] $ids Article ids.
	 * @return int How many were added.
	 */
	public function enqueue( array $ids ): int {
		$queue = get_option( self::QUEUE, array() );
		$queue = is_array( $queue ) ? array_map( 'intval', (array) ( $queue['ids'] ?? array() ) ) : array();
		$added = array_values( array_diff( array_map( 'intval', $ids ), $queue ) );

		if ( array() === $added ) {
			return 0;
		}

		update_option(
			self::QUEUE,
			array(
				'ids'   => array_merge( $queue, $added ),
				'error' => '',
			),
			false
		);

		$this->schedule( 10 );

		return count( $added );
	}

	/**
	 * What waits in the queue, and why it stopped when it did.
	 *
	 * @return array{waiting: int, error: string}
	 */
	public function queue_state(): array {
		$queue = get_option( self::QUEUE, array() );
		$queue = is_array( $queue ) ? $queue : array();

		return array(
			'waiting' => count( (array) ( $queue['ids'] ?? array() ) ),
			'error'   => (string) ( $queue['error'] ?? '' ),
		);
	}

	/**
	 * Background run: reads a few articles, then plans the next run.
	 *
	 * @return void
	 */
	public function run_batch(): void {
		$queue = get_option( self::QUEUE, array() );
		$ids   = is_array( $queue ) ? array_map( 'intval', (array) ( $queue['ids'] ?? array() ) ) : array();

		if ( array() === $ids || ! $this->active() ) {
			delete_option( self::QUEUE );

			return;
		}

		$error = '';

		for ( $i = 0; $i < self::PER_RUN && array() !== $ids; $i++ ) {
			$id = (int) array_shift( $ids );

			if ( 'none' !== $this->result( $id )['state'] ) {
				continue;
			}

			$done = $this->suggest( $id, array() );

			if ( is_wp_error( $done ) ) {
				// A limit, a missing consent or a provider that does not answer stops the run; the rest waits for a person to ask again.
				$error = $done->get_error_message();
				break;
			}
		}

		if ( '' !== $error || array() === $ids ) {
			if ( '' === $error ) {
				delete_option( self::QUEUE );
			} else {
				update_option(
					self::QUEUE,
					array(
						'ids'   => array(),
						'error' => $error,
					),
					false
				);
			}

			return;
		}

		update_option(
			self::QUEUE,
			array(
				'ids'   => $ids,
				'error' => '',
			),
			false
		);

		$this->schedule( 20 );
	}

	/**
	 * Plans the next background run, in the plugin's queue when it has one.
	 *
	 * @param int $delay Seconds to wait.
	 * @return void
	 */
	private function schedule( int $delay ): void {
		if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_get_scheduled_actions' ) ) {
			// Only a waiting run counts: the run that is doing this now must be able to book the next one.
			Queue::once( Clock::now() + max( 1, $delay ), self::HOOK, array(), Queue::GROUP );

			return;
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK );
		}
	}

	/**
	 * Cleans the places the owner typed.
	 *
	 * @param array<mixed> $places Raw places.
	 * @return string[]
	 */
	private function places( array $places ): array {
		$out = array();

		foreach ( $places as $place ) {
			$place = is_scalar( $place ) ? trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $place ) ) ) : '';

			if ( '' !== $place && mb_strlen( $place ) <= 60 && ! in_array( $place, $out, true ) ) {
				$out[] = $place;
			}
		}

		return array_slice( $out, 0, 20 );
	}
}

<?php
/**
 * WebSub ping.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\WebSub;

use Estasoft\NewsDistribution\Channels\DeliveryResult;
use Estasoft\NewsDistribution\Channels\Ping\AbstractPingChannel;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Core\Retry;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the owner's WebSub hub that the open-reader feed changed, so readers
 * that follow it through WebSub can fetch it at once. It does not make any other
 * platform fetch sooner.
 *
 * - There is no default hub. Nothing is sent until the owner enters one and
 *   turns the channel on.
 * - A ping is sent from the queue, never inside the save, one POST for each hub
 *   and each feed address of the article's language.
 * - A failed ping follows the plugin's retry rule: after 1, 5 and 15 minutes,
 *   four attempts in all (Core\Retry). A refusal that will not change (400, 403,
 *   404, a redirect) is not retried.
 * - The feeds that are pinged are `/feed/main/?lang=xx`. Other feeds can be added
 *   through the `estasoft_nd_websub_topics` filter.
 */
final class WebSubChannel extends AbstractPingChannel {

	/**
	 * Slug of the open-reader feed the hub is told about.
	 */
	public const TOPIC_SLUG = 'main';

	public function id(): string {
		return 'websub';
	}

	public function label(): string {
		return __( 'WebSub', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 10;
	}

	public function gate_note(): string {
		return '';
	}

	public function fields(): array {
		$hub = array(
			'type'    => 'string',
			'format'  => 'url',
			'schemes' => array( 'https' ),
			'max'     => 500,
			'default' => '',
			'width'   => 480,
		);

		return array_merge(
			array(
				'hub'   => array_merge(
					$hub,
					array(
						'label'       => __( 'Hub address', 'estasoft-news-distribution' ),
						'placeholder' => 'https://',
					)
				),
				'hub_2' => array_merge( $hub, array( 'label' => __( 'Second hub address', 'estasoft-news-distribution' ) ) ),
				'hub_3' => array_merge( $hub, array( 'label' => __( 'Third hub address', 'estasoft-news-distribution' ) ) ),
			),
			$this->toggle_fields(
				array(
					'publish'   => __( 'Sent when the post goes live, or when its release plan lets the other feeds go out.', 'estasoft-news-distribution' ),
					'update'    => __( 'Sent when the content or title changes. An edit marked minor sends nothing.', 'estasoft-news-distribution' ),
					'remove'    => __( 'Sent so subscribers fetch the updated feed.', 'estasoft-news-distribution' ),
					'languages' => __( 'Other languages are never pinged.', 'estasoft-news-distribution' ),
				)
			)
		);
	}

	/**
	 * The hubs the owner entered, in order, without blanks or repeats.
	 *
	 * @return string[]
	 */
	public function hubs(): array {
		$settings = $this->settings();
		$hubs     = array();

		foreach ( array( 'hub', 'hub_2', 'hub_3' ) as $key ) {
			$hub = trim( (string) ( $settings[ $key ] ?? '' ) );

			if ( '' !== $hub && ! in_array( $hub, $hubs, true ) ) {
				$hubs[] = $hub;
			}
		}

		return $hubs;
	}

	protected function is_ready(): bool {
		return array() !== $this->hubs();
	}

	/**
	 * The feed addresses the hub is told about for one language: each is the
	 * feed's own exact address, which is also its self link.
	 *
	 * @param string $language Language code.
	 * @return string[]
	 */
	public function topics( string $language ): array {
		$topics = array( add_query_arg( 'lang', rawurlencode( $language ), home_url( '/feed/' . self::TOPIC_SLUG . '/' ) ) );

		/**
		 * Filters the feed addresses a WebSub ping names for one language.
		 *
		 * @param string[] $topics   Feed addresses.
		 * @param string   $language Language code.
		 */
		$filtered = apply_filters( 'estasoft_nd_websub_topics', $topics, $language );

		return array_values( array_filter( array_map( 'strval', is_array( $filtered ) ? $filtered : $topics ) ) );
	}

	/**
	 * The request body of a ping for one language: the form a hub reads.
	 *
	 * @param Article[] $articles Not used: a ping names the feed, not an article.
	 * @param string    $language Language code.
	 * @param int|null  $now      Not used.
	 * @return string
	 */
	public function build( array $articles, string $language, ?int $now = null ): string {
		unset( $articles, $now );

		$topics = $this->topics( $language );

		return http_build_query(
			array(
				'hub.mode' => 'publish',
				'hub.url'  => (string) reset( $topics ),
			),
			'',
			'&'
		);
	}

	public function deliver( Event $event, ?Article $article ): DeliveryResult {
		unset( $article );

		if ( ! $this->event_enabled( $event ) ) {
			return DeliveryResult::skipped( __( 'This kind of change is not sent. You can change that in the WebSub settings.', 'estasoft-news-distribution' ) );
		}

		if ( $this->is_minor_edit( $event ) ) {
			return DeliveryResult::skipped( __( 'A minor edit sends no ping.', 'estasoft-news-distribution' ) );
		}

		$hubs = $this->hubs();

		if ( array() === $hubs ) {
			return DeliveryResult::skipped( __( 'No hub is set, so nothing was sent.', 'estasoft-news-distribution' ) );
		}

		return $this->ping( $hubs, $this->topics( $event->language() ) );
	}

	/**
	 * Pings every hub for every feed address. The ping is accepted when every
	 * hub answers with a 2xx code. It is tried again when any failure can pass.
	 *
	 * @param string[] $hubs   Hub addresses.
	 * @param string[] $topics Feed addresses.
	 * @return DeliveryResult
	 */
	public function ping( array $hubs, array $topics ): DeliveryResult {
		$failures = array();
		$retry    = false;
		$code     = 0;
		$text     = '';

		foreach ( $hubs as $hub ) {
			foreach ( $topics as $topic ) {
				$answer = $this->ping_one( $hub, $topic );

				if ( $answer['code'] >= 200 && $answer['code'] < 300 ) {
					$code = $answer['code'];
					$text = $answer['text'];
					continue;
				}

				$failures[] = $answer;
				$retry      = $retry || Retry::is_retryable_code( $answer['code'] );
			}
		}

		if ( array() === $failures ) {
			return DeliveryResult::success( $code, count( $hubs ) > 1 ? __( 'Every hub accepted the ping', 'estasoft-news-distribution' ) : $text );
		}

		$first = $failures[0];

		return DeliveryResult::failure( $this->failure_text( $failures ), (int) $first['code'], $retry );
	}

	/**
	 * Sends one ping.
	 *
	 * @param string $hub   Hub address.
	 * @param string $topic Feed address.
	 * @return array{code: int, text: string, error: string, hub: string}
	 */
	private function ping_one( string $hub, string $topic ): array {
		$answer = $this->post(
			$hub,
			array(
				'body'    => array(
					'hub.mode' => 'publish',
					'hub.url'  => $topic,
				),
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			),
			true
		);

		$answer['hub'] = $hub;

		return $answer;
	}

	/**
	 * One sentence about the pings that did not go through.
	 *
	 * @param array<int, array{code: int, text: string, error: string, hub: string}> $failures Failed answers.
	 * @return string
	 */
	private function failure_text( array $failures ): string {
		$lines = array();

		foreach ( $failures as $failure ) {
			$host = (string) wp_parse_url( $failure['hub'], PHP_URL_HOST );

			if ( 0 === $failure['code'] ) {
				$line = sprintf(
					/* translators: %s: the reason the hub could not be reached. */
					__( 'The hub did not answer: %s', 'estasoft-news-distribution' ),
					'' !== $failure['error'] ? $failure['error'] : __( 'no reason given', 'estasoft-news-distribution' )
				);
			} elseif ( $failure['code'] >= 300 && $failure['code'] < 400 ) {
				$line = __( 'The hub moved the ping to another address. Enter the address it moved to.', 'estasoft-news-distribution' );
			} else {
				$line = $failure['text'];
			}

			$lines[] = count( $failures ) > 1 && '' !== $host ? $host . ': ' . $line : $line;
		}

		return implode( ' ', array_unique( $lines ) );
	}

	/**
	 * Pings the hubs once for the site's main language. Started by a person
	 * pressing "Send a test ping", never by a timer.
	 *
	 * @return array{ok: bool, code: int, message: string}
	 */
	public function test(): array {
		$hubs = $this->hubs();

		if ( array() === $hubs ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'message' => __( 'Add a hub address and save before sending a test ping.', 'estasoft-news-distribution' ),
			);
		}

		$language = $this->context->languages->default_language();
		$result   = $this->ping( $hubs, $this->topics( $language ) );
		$ok       = ! $result->is_failure();
		$message  = $ok
			? sprintf(
				/* translators: 1: the HTTP code the hub answered, 2: the words for the code, such as "No Content". */
				__( 'The hub answered %1$d %2$s.', 'estasoft-news-distribution' ),
				$result->code(),
				$result->message()
			)
			: $result->message();

		$this->log_test( $language, $ok, $result->code(), $result->message() );

		return array(
			'ok'      => $ok,
			'code'    => $result->code(),
			'message' => trim( $message ),
		);
	}

	/**
	 * The words about pings for the hub: what is waiting to be tried again, or
	 * the last answer.
	 *
	 * @return string
	 */
	protected function hub_summary(): string {
		if ( ! $this->is_ready() ) {
			return __( 'Add a hub address. Nothing is sent until you do.', 'estasoft-news-distribution' );
		}

		$seen = array();
		$rows = $this->recent_rows( 50 );

		foreach ( $rows as $row ) {
			$key = $row['post_id'] . '|' . $row['event'];

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;

			if ( Status::RETRY === $row['status'] ) {
				return sprintf(
					/* translators: 1: the attempt that failed, 2: attempts in all, 3: the time of the next try, such as 14:37. */
					__( '1 ping retrying: attempt %1$d of %2$d, next try %3$s', 'estasoft-news-distribution' ),
					(int) $row['attempt'],
					Retry::MAX_ATTEMPTS,
					Clock::format( (int) $row['run_at_ts'] )
				);
			}
		}

		foreach ( $rows as $row ) {
			if ( Status::SENT === $row['status'] ) {
				return sprintf(
					/* translators: 1: the time of the last ping, such as 14:21, 2: the HTTP code the hub answered. */
					__( 'Last ping %1$s, the hub answered %2$d', 'estasoft-news-distribution' ),
					Clock::format( (int) $row['created_ts'] ),
					(int) $row['response_code']
				);
			}
		}

		return __( 'Waiting for the next change.', 'estasoft-news-distribution' );
	}
}

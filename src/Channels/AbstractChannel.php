<?php
/**
 * Shared channel behaviour.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Gives channels sensible defaults, so each one only overrides what it needs.
 *
 * A new channel needs: id(), label(), group(), order(), mode(), build() and,
 * if it has rules or settings, validate() and fields().
 */
abstract class AbstractChannel implements ChannelInterface {

	protected ChannelContext $context;

	/**
	 * Creates the channel.
	 *
	 * @param ChannelContext $context Services the channel may use.
	 */
	public function __construct( ChannelContext $context ) {
		$this->context = $context;
	}

	public function mode(): string {
		return self::MODE_PULLED;
	}

	public function feed_slug(): string {
		return self::MODE_PULLED === $this->mode() ? $this->id() : '';
	}

	public function serves( string $slug ): bool {
		return '' !== $slug && $slug === $this->feed_slug();
	}

	public function content_type(): string {
		return 'application/xml; charset=UTF-8';
	}

	public function feed_window(): array {
		return array(
			'max_age' => 2 * DAY_IN_SECONDS,
			'limit'   => 1000,
		);
	}

	public function fields(): array {
		return array();
	}

	public function gate_note(): string {
		return '';
	}

	public function is_enabled(): bool {
		return $this->context->channels->is_enabled( $this->id() );
	}

	/**
	 * This channel's stored settings with defaults, `enabled` included.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		return $this->context->channels->get( $this->id(), $this->fields() );
	}

	/**
	 * A channel is off for a language when the site's Languages settings turn
	 * that language off, or the channel's own `languages_off` setting lists it.
	 *
	 * @param string $language Language code.
	 * @return bool
	 */
	public function accepts_language( string $language ): bool {
		$off = (array) $this->context->settings->get( 'languages.off', array() );

		if ( in_array( $language, $off, true ) ) {
			return false;
		}

		$own = $this->settings()['languages_off'] ?? array();

		return ! ( is_array( $own ) && in_array( $language, $own, true ) );
	}

	public function validate( Article $article ): ValidationResult {
		return new ValidationResult();
	}

	/**
	 * A secret this channel stores (a token, a webhook address), or an empty
	 * string. Never print it, log it or send it to a screen.
	 *
	 * @param string $field The secret field's name.
	 * @return string
	 */
	protected function secret( string $field ): string {
		return $this->context->secrets->get( ChannelSettings::secret_key( $this->id(), $field ) );
	}

	/**
	 * A one-off test that reaches the other side. Only ever started by a person
	 * (`POST /channels/{id}/test`), never on a timer and never on publish.
	 * A channel with something to test overrides this; the result is stored as
	 * the channel's `last_test` and shown as "Test passed: ..." or the failure.
	 *
	 * @return array{ok: bool, code: int, message: string}
	 */
	public function test(): array {
		return array(
			'ok'      => false,
			'code'    => 0,
			'message' => __( 'This channel has no test to run.', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * The plugin's own check of the channel's built document (`POST
	 * /channels/{id}/validate`). The default parses it as XML and counts the
	 * entries (children of the root); a channel overrides it with the rules of
	 * its own format and returns the problems in plain language.
	 *
	 * @param string $body     The document `build()` returned.
	 * @param string $language Language code.
	 * @return array{valid: bool, entries: int, problems: string[]}
	 */
	public function check_output( string $body, string $language ): array {
		unset( $language );

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body );
		$problems = array();

		if ( false === $xml ) {
			foreach ( libxml_get_errors() as $error ) {
				$problems[] = trim( $error->message );
			}
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return array(
			'valid'    => false !== $xml,
			'entries'  => false === $xml ? 0 : count( $xml->children() ),
			'problems' => array_slice( array_unique( $problems ), 0, 10 ),
		);
	}

	public function deliver( Event $event, ?Article $article ): DeliveryResult {
		return DeliveryResult::not_applicable();
	}

	/**
	 * Pushed channels send at once by default. A channel that should wait for
	 * the site to be crawled first returns the index-first delay for new articles.
	 *
	 * @param Event $event The event.
	 * @return int
	 */
	public function delay_seconds( Event $event ): int {
		return 0;
	}

	/**
	 * Index-first delay in seconds when the event publishes a new article, else zero.
	 *
	 * @param Event $event The event.
	 * @return int
	 */
	protected function index_first_delay( Event $event ): int {
		return Event::PUBLISH === $event->type() ? $this->context->settings->index_first_delay_seconds() : 0;
	}

	/**
	 * Standard health.
	 *
	 * - Off: Not set up.
	 * - Pushed: Needs attention when a delivery failed in the last 24 hours, else Healthy.
	 * - Pulled: Processing until the first fetch (or 48 hours after turning on), then Needs
	 *   attention if a language was never fetched or is later than usual, else Healthy.
	 *
	 * @return string A Status code.
	 */
	public function health(): string {
		if ( ! $this->is_enabled() ) {
			return Status::NOT_SET_UP;
		}

		if ( self::MODE_PUSHED === $this->mode() ) {
			$counts = $this->context->log->counts_since( Clock::now() - DAY_IN_SECONDS );

			return ( ( $counts[ Status::FAILED ] ?? 0 ) + ( $counts[ Status::RETRY ] ?? 0 ) ) > 0 ? Status::ATTENTION : Status::HEALTHY;
		}

		if ( self::MODE_MANUAL === $this->mode() ) {
			return Status::HEALTHY;
		}

		$states = $this->fetch_states();
		$codes  = array_column( $states, 'status' );

		if ( array() === $codes || array( Status::NEVER ) === array_values( array_unique( $codes ) ) ) {
			$since = (int) ( $this->settings()['enabled_since'] ?? 0 );

			return ( 0 === $since || Clock::now() - $since < 2 * DAY_IN_SECONDS ) ? Status::PROCESSING : Status::ATTENTION;
		}

		foreach ( $codes as $code ) {
			if ( in_array( $code, array( Status::LATE, Status::NEVER ), true ) ) {
				return Status::ATTENTION;
			}
		}

		return Status::HEALTHY;
	}

	/**
	 * How the platform's fetches look, per language.
	 *
	 * @return array<string, array{status: string, last_ts: int}> Language => fetch code and the last fetch time.
	 */
	protected function fetch_states(): array {
		$latest   = $this->context->fetches->latest();
		$interval = $this->context->rules->fetch_interval_hours( $this->id() ) * HOUR_IN_SECONDS;
		$states   = array();

		foreach ( $this->context->languages->languages() as $language ) {
			if ( ! $this->accepts_language( $language ) ) {
				continue;
			}

			$row  = $latest[ $this->id() . '|' . $language ] ?? null;
			$last = null === $row ? 0 : (int) $row['created_ts'];

			if ( 0 === $last ) {
				$code = Status::NEVER;
			} elseif ( Clock::now() - $last > 2 * $interval ) {
				$code = Status::LATE;
			} else {
				$code = Status::ON_TIME;
			}

			$states[ $language ] = array(
				'status'  => $code,
				'last_ts' => $last,
			);
		}

		return $states;
	}

	/**
	 * One hub row built from the channel's own name and health. Channels with a
	 * richer summary override hub_rows().
	 *
	 * @return array<int, array{key: string, name: string, summary: string, status: string, enabled: bool|null, action: string}>
	 */
	public function hub_rows(): array {
		$enabled = $this->is_enabled();
		$status  = $this->health();

		return array(
			array(
				'key'     => $this->id(),
				'name'    => $this->label(),
				'summary' => $this->hub_summary(),
				'status'  => $status,
				'enabled' => self::MODE_MANUAL === $this->mode() ? null : $enabled,
				'action'  => $enabled ? 'open' : 'set_up',
			),
		);
	}

	/**
	 * The plain-text line under the channel's name on the hub.
	 *
	 * @return string
	 */
	protected function hub_summary(): string {
		return '';
	}
}

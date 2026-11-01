<?php
/**
 * Outlets: Telegram, Mastodon, Bluesky, Medium and signed webhooks.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Outlets;

use Estasoft\NewsDistribution\Channels\AbstractChannel;
use Estasoft\NewsDistribution\Channels\ChannelContext;
use Estasoft\NewsDistribution\Channels\DeliveryResult;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Channels\ValidationResult;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\ContentReader;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Core\Removed;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * One pushed channel that delegates to one class per outlet. Posts leave only
 * from the queue after an article is published, only to the outlets the owner
 * turned on, and only when the article passes that outlet's checks. A test is
 * a button a person presses.
 *
 * The queue retries a whole delivery, so every target leaves a mark (see Marks):
 * a retry skips a target that already took the post. The outcomes of the targets
 * are folded into one DeliveryResult. It is retryable when any target failed in
 * a way worth trying again.
 *
 * Medium is a list, not a post: its outcome is "listed", never "sent".
 */
final class OutletsChannel extends AbstractChannel {

	public const ID = 'outlets';

	/**
	 * @var array<string, OutletInterface>|null
	 */
	private ?array $outlets = null;

	private OutletsState $state;
	private Marks $marks;

	/**
	 * Creates the channel.
	 *
	 * @param ChannelContext $context Services the channel may use.
	 */
	public function __construct( ChannelContext $context ) {
		parent::__construct( $context );

		$this->state = new OutletsState();
		$this->marks = new Marks();
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Outlets', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return Group::PUSHES;
	}

	public function order(): int {
		return 30;
	}

	public function mode(): string {
		return self::MODE_PUSHED;
	}

	/**
	 * The channel is on while at least one outlet is on and has something to post to.
	 *
	 * Each outlet has its own switch, so there is no switch for the channel as a whole. The queue and
	 * the screens ask this question, and the answer follows the outlets.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return array() !== $this->active( $this->settings() );
	}

	public function gate_note(): string {
		return __( 'A headline that fits beside the link on Mastodon and Bluesky, and an account for the article’s language.', 'estasoft-news-distribution' );
	}

	/**
	 * What the outlets learned while they ran.
	 *
	 * @return OutletsState
	 */
	public function state(): OutletsState {
		return $this->state;
	}

	/**
	 * Which targets already got a post.
	 *
	 * @return Marks
	 */
	public function marks(): Marks {
		return $this->marks;
	}

	/**
	 * The outlets, by id, in the order the screens show them.
	 *
	 * @return array<string, OutletInterface>
	 */
	public function outlets(): array {
		if ( null === $this->outlets ) {
			$this->outlets = array();

			foreach ( array(
				new TelegramOutlet( $this->context, $this->state, $this->marks ),
				new MastodonOutlet( $this->context, $this->state, $this->marks ),
				new BlueskyOutlet( $this->context, $this->state, $this->marks ),
				new MediumOutlet( $this->context, $this->state, $this->marks ),
				new WebhookOutlet( $this->context, $this->state, $this->marks ),
			) as $outlet ) {
				$this->outlets[ $outlet->id() ] = $outlet;
			}
		}

		return $this->outlets;
	}

	/**
	 * One outlet.
	 *
	 * @param string $id Outlet id.
	 * @return OutletInterface|null
	 */
	public function outlet( string $id ): ?OutletInterface {
		return $this->outlets()[ $id ] ?? null;
	}

	/**
	 * Forgets what was read earlier in this request, after a setting or a secret changed.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->state->flush();

		foreach ( $this->outlets() as $outlet ) {
			if ( $outlet instanceof AbstractOutlet ) {
				$outlet->forget_secrets();
			}

			if ( $outlet instanceof MediumOutlet ) {
				$outlet->forget_stories();
			}
		}
	}

	/**
	 * The languages the site publishes in.
	 *
	 * @return string[]
	 */
	public function site_languages(): array {
		return array_values( array_unique( array_map( 'strval', $this->context->languages->languages() ) ) );
	}

	/**
	 * Every setting: the attribution lines, what a social post holds, and each outlet's own fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		$fields = array(
			'attribution'     => array(
				'type'        => 'map',
				'label'       => __( 'Attribution line', 'estasoft-news-distribution' ),
				'default'     => array(),
				'key_pattern' => '/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/',
				'item'        => array(
					'type' => 'string',
					'max'  => 300,
				),
			),
			'social_headline' => array(
				'type'    => 'bool',
				'label'   => __( 'The headline', 'estasoft-news-distribution' ),
				'default' => true,
			),
			'social_summary'  => array(
				'type'    => 'bool',
				'label'   => __( 'A short summary', 'estasoft-news-distribution' ),
				'default' => true,
			),
			'social_card'     => array(
				'type'    => 'bool',
				'label'   => __( 'A link card on Bluesky', 'estasoft-news-distribution' ),
				'default' => true,
			),
			'social_hashtags' => array(
				'type'    => 'bool',
				'label'   => __( 'Hashtags from the article’s tags', 'estasoft-news-distribution' ),
				'default' => false,
			),
		);

		foreach ( $this->outlets() as $outlet ) {
			$fields = array_merge( $fields, $outlet->fields( $this->site_languages() ) );
		}

		return $fields;
	}

	/**
	 * Outlets wait for the index-first delay after a new article, so the site's own page is crawled
	 * first. Breaking news goes at once.
	 *
	 * @param Event $event The event.
	 * @return int
	 */
	public function delay_seconds( Event $event ): int {
		if ( $this->is_breaking( $event->post_id() ) ) {
			return 0;
		}

		return $this->index_first_delay( $event );
	}

	/**
	 * Whether the article was marked as breaking news, which skips the delay.
	 *
	 * @param int $post_id Article ID.
	 * @return bool
	 */
	private function is_breaking( int $post_id ): bool {
		$options = get_post_meta( $post_id, '_estasoft_nd_options', true );

		return is_array( $options ) && ! empty( $options['breaking'] );
	}

	/**
	 * What the outlets that are on would do with an article.
	 *
	 * A finding that blocks means one outlet leaves the article out. It blocks the channel only when
	 * every outlet that is on would leave it out; otherwise it is shown as a risk.
	 *
	 * @param Article $article The article.
	 * @return ValidationResult
	 */
	public function validate( Article $article ): ValidationResult {
		$settings = $this->settings();
		$result   = new ValidationResult();
		$active   = $this->active( $settings );
		$blocking = 0;
		$findings = array();

		foreach ( $active as $outlet ) {
			$check = $outlet->check( $article, $settings );

			if ( ! $check->is_valid() ) {
				++$blocking;
			}

			foreach ( $check->findings() as $finding ) {
				$findings[ $finding['code'] . '|' . $outlet->id() ] = $finding;
			}
		}

		$total       = count( $active );
		$all_blocked = $total > 0 && $total === $blocking;
		$seen        = array();

		foreach ( $findings as $finding ) {
			if ( isset( $seen[ $finding['code'] . $finding['message'] ] ) ) {
				continue;
			}

			$seen[ $finding['code'] . $finding['message'] ] = true;
			$severity                                       = $finding['severity'];

			if ( Status::BLOCK === $severity && ! $all_blocked ) {
				$severity = Status::WARN;
			}

			$result->add( $severity, $finding['code'], $finding['message'] );
		}

		return $result;
	}

	/**
	 * What a webhook would receive for these articles, as JSON. Nothing is sent.
	 *
	 * @param Article[] $articles Articles in one language.
	 * @param string    $language Language code.
	 * @param int|null  $now      Unix time to treat as now.
	 * @return string
	 */
	public function build( array $articles, string $language, ?int $now = null ): string {
		$webhook  = $this->outlet( 'webhook' );
		$settings = $this->settings();
		$items    = array();

		if ( ! $webhook instanceof WebhookOutlet ) {
			return '[]';
		}

		foreach ( $articles as $article ) {
			if ( $article instanceof Article && $article->language() === $language ) {
				$items[] = $webhook->payload( Event::PUBLISH, new Event( Event::PUBLISH, $article->id(), $language, $now ), $article, $settings );
			}
		}

		return (string) wp_json_encode( $items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Sends one event to every target that should get it. Only the queue calls this.
	 *
	 * @param Event        $event   The event.
	 * @param Article|null $article The article, or null when it was removed.
	 * @return DeliveryResult
	 */
	public function deliver( Event $event, ?Article $article ): DeliveryResult {
		$settings = $this->settings();
		$language = null === $article ? $event->language() : $article->language();
		$active   = $this->active( $settings );
		$outcomes = array();
		$carried  = false;

		if ( array() === $active ) {
			return DeliveryResult::skipped( __( 'No outlet is turned on.', 'estasoft-news-distribution' ) );
		}

		foreach ( $active as $outlet ) {
			if ( ! $outlet->carries( $event ) ) {
				continue;
			}

			$carried = true;
			$targets = array();

			foreach ( $outlet->targets( $settings, $this->site_languages() ) as $target ) {
				if ( $target->is_on() && $target->is_complete() && $target->carries( $language ) ) {
					$targets[] = $target;
				}
			}

			if ( array() === $targets ) {
				continue;
			}

			$blocked = null;

			if ( null !== $article ) {
				$check = $outlet->check( $article, $settings );

				if ( ! $check->is_valid() ) {
					$blocked = (string) $check->errors()[0]['message'];
				}
			}

			foreach ( $targets as $target ) {
				$outcomes[] = null !== $blocked
					? Outcome::skipped( $target, $blocked )
					: $this->deliver_to( $outlet, $target, $event, $article, $settings );
			}
		}

		return $this->summarise( $outcomes, $carried );
	}

	/**
	 * Sends to one target, with the mark that keeps a retry from posting twice.
	 *
	 * @param OutletInterface      $outlet   The outlet.
	 * @param Target               $target   The target.
	 * @param Event                $event    The event.
	 * @param Article|null         $article  The article.
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return Outcome
	 */
	private function deliver_to( OutletInterface $outlet, Target $target, Event $event, ?Article $article, array $settings ): Outcome {
		$post  = $event->post_id();
		$type  = $event->type();
		$track = 'medium' !== $outlet->id();

		if ( $track && $this->marks->is_settled( $post, $type, $target->id() ) ) {
			$mark = $this->marks->get( $post, $type, $target->id() );

			return Marks::SENT === $mark['status']
				? Outcome::sent( $target, 0, __( 'Already sent.', 'estasoft-news-distribution' ), $mark['remote'] )
				: Outcome::failed( $target, '' !== $mark['remote'] ? $mark['remote'] : __( 'It was refused earlier and is not sent again.', 'estasoft-news-distribution' ), 0, false );
		}

		if ( $track ) {
			$this->marks->begin( $post, $type, $target->id() );
		}

		try {
			$outcome = $outlet->send( $target, $event, $article, $settings );
		} catch ( \Throwable $error ) {
			$outcome = Outcome::failed( $target, $this->scrub( $error->getMessage() ), 0, true );
		}

		if ( ! $track ) {
			return $outcome;
		}

		$this->settle( $post, $type, $outcome, null === $article ? $event->title() : PostText::headline( $article ) );

		return $outcome;
	}

	/**
	 * Writes the mark and the latest-delivery record for an outcome.
	 *
	 * @param int     $post    Article ID.
	 * @param string  $type    Event type.
	 * @param Outcome $outcome What happened.
	 * @param string  $title   The article's headline, for the recent posts list.
	 * @return void
	 */
	private function settle( int $post, string $type, Outcome $outcome, string $title ): void {
		$id = $outcome->target()->id();

		if ( $outcome->is_sent() ) {
			$this->marks->record( $post, $type, $id, Marks::SENT, $outcome->remote() );
			$this->state->record_delivery( $id, 'sent', $outcome->code(), $outcome->message() );
			$this->state->record_recent( $post, $title, $id, 'sent', $outcome->message() );
			return;
		}

		if ( ! $outcome->is_failure() ) {
			$this->marks->forget( $post, $type, $id );
			return;
		}

		$this->state->record_delivery( $id, 'failed', $outcome->code(), $outcome->message() );
		$this->state->record_recent( $post, $title, $id, 'failed', $outcome->message() );

		if ( ! $outcome->is_retryable() ) {
			$this->marks->record( $post, $type, $id, Marks::FAILED, $outcome->message() );
			return;
		}

		// No answer, or a server error: the post may have arrived, so the next attempt checks first where it can.
		if ( 0 === $outcome->code() || $outcome->code() >= 500 ) {
			$this->marks->record( $post, $type, $id, Marks::PENDING );
			return;
		}

		$this->marks->forget( $post, $type, $id );
	}

	/**
	 * Folds the outcomes of the targets into one result for the queue.
	 *
	 * @param Outcome[] $outcomes Outcome of each target tried.
	 * @param bool      $carried  Whether any outlet that is on acts on this kind of event.
	 * @return DeliveryResult
	 */
	private function summarise( array $outcomes, bool $carried ): DeliveryResult {
		if ( ! $carried ) {
			return DeliveryResult::skipped( __( 'Outlets post new articles only. Nothing was sent for this change.', 'estasoft-news-distribution' ) );
		}

		$sent   = array();
		$listed = array();
		$failed = array();
		$notes  = array();
		$retry  = false;
		$code   = 0;

		foreach ( $outcomes as $outcome ) {
			$name = $this->describe( $outcome->target() );

			if ( $outcome->is_sent() ) {
				$sent[] = $name;
				$code   = 0 === $code ? $outcome->code() : $code;
			} elseif ( $outcome->is_failure() ) {
				$failed[] = $name . ': ' . $outcome->message();
				$retry    = $retry || $outcome->is_retryable();
				$code     = 0 === $outcome->code() ? $code : $outcome->code();
			} elseif ( Outcome::LISTED === $outcome->status() ) {
				$listed[] = $outcome->message();
			} else {
				$notes[] = $name . ': ' . $outcome->message();
			}
		}

		$parts = array();

		if ( array() !== $sent ) {
			$parts[] = sprintf(
				/* translators: 1: how many destinations got the post, 2: their names. */
				_n( 'Sent to %1$d destination: %2$s.', 'Sent to %1$d destinations: %2$s.', count( $sent ), 'estasoft-news-distribution' ),
				count( $sent ),
				implode( ', ', $sent )
			);
		}

		if ( array() !== $failed ) {
			$parts[] = sprintf(
				/* translators: 1: how many destinations failed, 2: each one with what went wrong. */
				_n( '%1$d destination failed. %2$s', '%1$d destinations failed. %2$s', count( $failed ), 'estasoft-news-distribution' ),
				count( $failed ),
				implode( ' ', $failed )
			);
		}

		$parts = array_merge( $parts, array_slice( $listed, 0, 1 ), $notes );

		if ( array() !== $failed ) {
			return DeliveryResult::failure( trim( implode( ' ', $parts ) ), $code, $retry );
		}

		if ( array() !== $sent ) {
			return DeliveryResult::success( $code > 0 ? $code : 200, trim( implode( ' ', $parts ) ) );
		}

		if ( array() === $parts ) {
			return DeliveryResult::skipped( __( 'No account or endpoint is set for this article’s language.', 'estasoft-news-distribution' ) );
		}

		return DeliveryResult::skipped( trim( implode( ' ', $parts ) ) );
	}

	/**
	 * A name for a target in a log line: the outlet and where it posts. Never a secret.
	 *
	 * @param Target $target The target.
	 * @return string
	 */
	private function describe( Target $target ): string {
		$outlet = $this->outlet( $target->outlet() );
		$name   = null === $outlet ? $target->outlet() : $outlet->label();

		return 'webhook' === $target->outlet() ? $target->label() : trim( $name . ' ' . $target->label() );
	}

	/**
	 * Removes secrets from a text.
	 *
	 * @param string $text A text that will be logged.
	 * @return string
	 */
	private function scrub( string $text ): string {
		$known = array();

		foreach ( $this->context->secrets->keys() as $key ) {
			if ( 0 === strpos( (string) $key, 'channel_' . self::ID . '_' ) ) {
				$known[] = $this->context->secrets->get( (string) $key );
			}
		}

		return OutletHttp::scrub( $text, array_filter( $known ) );
	}

	/**
	 * The outlets the owner turned on, with something ready to post to.
	 *
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return OutletInterface[]
	 */
	private function active( array $settings ): array {
		$active = array();

		foreach ( $this->outlets() as $outlet ) {
			if ( $outlet->is_on( $settings ) ) {
				$active[] = $outlet;
			}
		}

		return $active;
	}

	/**
	 * The outlets that are on, for the screens.
	 *
	 * @return string[] Outlet ids.
	 */
	public function active_ids(): array {
		return array_map(
			static function ( OutletInterface $outlet ): string {
				return $outlet->id();
			},
			$this->active( $this->settings() )
		);
	}

	/**
	 * The setting that switches each outlet, or each webhook endpoint, on and off.
	 *
	 * @return array<string, string> Setting name, such as telegram_on or webhook_2_on, and the outlet it belongs to.
	 */
	public function switch_keys(): array {
		$keys = array();

		foreach ( $this->outlets() as $outlet ) {
			foreach ( $outlet->fields( $this->site_languages() ) as $name => $spec ) {
				if ( 'bool' === ( $spec['type'] ?? '' ) && '_on' === substr( (string) $name, -3 ) ) {
					$keys[ (string) $name ] = $outlet->id();
				}
			}
		}

		return $keys;
	}

	/**
	 * Whether the channel would be on if these switches were on, with every other setting as saved.
	 *
	 * An outlet that is switched on but has no key or no account yet does not count, so the answer is no
	 * when the only switches given belong to outlets that are not set up.
	 *
	 * @param string[] $keys Setting names such as telegram_on.
	 * @return bool
	 */
	public function would_be_on( array $keys ): bool {
		$settings = $this->settings();

		foreach ( $keys as $key ) {
			$settings[ (string) $key ] = true;
		}

		return array() !== $this->active( $settings );
	}

	/**
	 * Health: Not set up while no outlet is on, Needs attention while something needs a person, else Healthy.
	 *
	 * The base class counts failures across every channel, so this channel works out its own.
	 *
	 * @return string A Status code.
	 */
	public function health(): string {
		if ( array() === $this->active( $this->settings() ) ) {
			return Status::NOT_SET_UP;
		}

		return array() === $this->attention() ? Status::HEALTHY : Status::ATTENTION;
	}

	/**
	 * What needs a person, one sentence each: a webhook that failed for good, a failed post to a
	 * social account, and stories waiting to be imported into Medium.
	 *
	 * @return array<int, array{key: string, text: string}>
	 */
	public function attention(): array {
		$settings = $this->settings();
		$items    = array();
		$webhook  = $this->outlet( 'webhook' );

		if ( $webhook instanceof WebhookOutlet && $webhook->is_on( $settings ) ) {
			$names = array();

			foreach ( $webhook->targets( $settings, array() ) as $target ) {
				$row = $this->state->webhook( $target->key() );

				if ( $target->is_on() && $target->is_complete() && 'failed' === ( $row['status'] ?? '' ) && ! empty( $row['final'] ) ) {
					$names[] = $target->label();
				}
			}

			if ( array() !== $names ) {
				$items[] = array(
					'key'  => 'webhook',
					'text' => sprintf(
						/* translators: 1: how many webhooks failed, 2: their names. */
						_n( '%1$d webhook failed (%2$s).', '%1$d webhooks failed (%2$s).', count( $names ), 'estasoft-news-distribution' ),
						count( $names ),
						implode( ', ', $names )
					),
				);
			}
		}

		foreach ( array( 'telegram', 'mastodon', 'bluesky' ) as $id ) {
			$outlet = $this->outlet( $id );

			if ( null !== $outlet && $outlet->is_on( $settings ) && $this->has_failed( $outlet, $settings ) ) {
				$items[] = array(
					'key'  => $id,
					'text' => sprintf(
						/* translators: %s: the name of a service, such as Telegram. */
						__( 'The latest post to %s failed.', 'estasoft-news-distribution' ),
						$outlet->label()
					),
				);
			}
		}

		$medium = $this->outlet( 'medium' );

		if ( $medium instanceof MediumOutlet && $medium->is_on( $settings ) ) {
			$waiting = $medium->waiting_count( $this->carried_languages() );

			if ( $waiting > 0 ) {
				$items[] = array(
					'key'  => 'medium',
					'text' => sprintf(
						/* translators: %d: how many stories wait to be imported into Medium. */
						_n( '%d story is waiting for you to import it into Medium.', '%d stories are waiting for you to import them into Medium.', $waiting, 'estasoft-news-distribution' ),
						$waiting
					),
				);
			}
		}

		return $items;
	}

	/**
	 * Whether the latest delivery or test to any target of an outlet failed.
	 *
	 * @param OutletInterface      $outlet   The outlet.
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return bool
	 */
	private function has_failed( OutletInterface $outlet, array $settings ): bool {
		foreach ( $outlet->targets( $settings, $this->site_languages() ) as $target ) {
			if ( ! $target->is_complete() ) {
				continue;
			}

			$delivery = $this->state->delivery( $target->id() );
			$test     = $this->state->test( $target->id() );

			// A later test that passed settles an earlier failed post, and the other way round.
			$last_ok = (int) ( $delivery['time'] ?? 0 ) >= (int) ( $test['time'] ?? 0 )
				? array() === $delivery || 'sent' === $delivery['status']
				: ! empty( $test['ok'] );

			if ( ! $last_ok ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The languages this channel carries, for the Medium list.
	 *
	 * @return string[]
	 */
	public function carried_languages(): array {
		return array_values(
			array_filter(
				$this->site_languages(),
				function ( string $language ): bool {
					return $this->accepts_language( $language );
				}
			)
		);
	}

	/**
	 * The state of one service for its own screen: Not set up, Healthy or Needs attention, with the reason.
	 *
	 * Languages with no account count here (the service skips them), though they do not move the
	 * channel's own health: leaving a language empty is a choice the owner may have made.
	 *
	 * @param string $outlet_id telegram, mastodon or bluesky.
	 * @return array{code: string, detail: string}
	 */
	public function service_status( string $outlet_id ): array {
		$outlet   = $this->outlet( $outlet_id );
		$settings = $this->settings();

		if ( null === $outlet || ! $outlet->is_on( $settings ) ) {
			return array(
				'code'   => Status::NOT_SET_UP,
				'detail' => '',
			);
		}

		if ( $this->has_failed( $outlet, $settings ) ) {
			return array(
				'code'   => Status::ATTENTION,
				'detail' => $this->latest_problem( $outlet, $settings ),
			);
		}

		$missing = array();
		$present = array();

		foreach ( $outlet->targets( $settings, $this->site_languages() ) as $target ) {
			if ( $target->is_complete() ) {
				$present[ $target->language() ] = true;
			}
		}

		foreach ( $this->site_languages() as $language ) {
			if ( ! isset( $present[ $language ] ) ) {
				$missing[] = \Estasoft\NewsDistribution\Core\Language\Catalog::name( $language );
			}
		}

		if ( array() !== $missing ) {
			return array(
				'code'   => Status::ATTENTION,
				'detail' => sprintf(
					/* translators: 1: how many languages have no account, 2: their names, such as Kurmanji. */
					_n( 'The account for %2$s is missing, so those posts are skipped.', 'The accounts for %2$s are missing, so those posts are skipped.', count( $missing ), 'estasoft-news-distribution' ),
					count( $missing ),
					implode( ', ', $missing )
				),
			);
		}

		return array(
			'code'   => Status::HEALTHY,
			'detail' => '',
		);
	}

	/**
	 * The message of the newest failed delivery or test among an outlet's targets.
	 *
	 * @param OutletInterface      $outlet   The outlet.
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return string
	 */
	private function latest_problem( OutletInterface $outlet, array $settings ): string {
		$best = array(
			'time'    => 0,
			'message' => '',
		);

		foreach ( $outlet->targets( $settings, $this->site_languages() ) as $target ) {
			$delivery = $this->state->delivery( $target->id() );
			$test     = $this->state->test( $target->id() );

			if ( array() !== $delivery && 'failed' === $delivery['status'] && $delivery['time'] >= $best['time'] ) {
				$best = $delivery;
			}

			if ( array() !== $test && empty( $test['ok'] ) && $test['time'] >= $best['time'] ) {
				$best = $test;
			}
		}

		return (string) $best['message'];
	}

	/**
	 * A test a person pressed, for one target. It posts a short test message when `$send` is true.
	 *
	 * @param string $outlet_id Outlet id.
	 * @param string $key       Target key: a language code, or an endpoint number.
	 * @param bool   $send      Whether to post a test message.
	 * @return Outcome
	 */
	public function run_test( string $outlet_id, string $key, bool $send ): Outcome {
		$outlet   = $this->outlet( $outlet_id );
		$settings = $this->settings();

		if ( null === $outlet ) {
			return Outcome::failed( new Target( $outlet_id, $key, $outlet_id ), __( 'That outlet does not exist.', 'estasoft-news-distribution' ), 0, false );
		}

		foreach ( $outlet->targets( $settings, $this->site_languages() ) as $target ) {
			if ( $target->key() !== $key ) {
				continue;
			}

			if ( ! $target->is_complete() ) {
				$outcome = Outcome::failed( $target, __( 'Finish setting this one up first: something it needs is missing.', 'estasoft-news-distribution' ), 0, false );
			} else {
				try {
					$outcome = $outlet->test( $target, $settings, $send );
				} catch ( \Throwable $error ) {
					$outcome = Outcome::failed( $target, $this->scrub( $error->getMessage() ), 0, true );
				}
			}

			$this->state->record_test( $target->id(), $outcome->is_sent() || Outcome::LISTED === $outcome->status(), $outcome->code(), $outcome->message() );

			return $outcome;
		}

		return Outcome::failed( new Target( $outlet_id, $key, $key ), __( 'That one is not set up.', 'estasoft-news-distribution' ), 0, false );
	}

	/**
	 * `POST /channels/outlets/test`: checks the connection of every outlet that has something set up.
	 * Telegram, Mastodon and Bluesky are asked whether they accept the credentials, and nothing is posted.
	 * An endpoint gets a signed ping that carries no article. The buttons on the screens post a short
	 * test message, one target at a time.
	 *
	 * @return array{ok: bool, code: int, message: string}
	 */
	public function test(): array {
		$settings = $this->settings();
		$messages = array();
		$ok       = true;
		$code     = 0;
		$tested   = 0;

		foreach ( $this->outlets() as $outlet ) {
			if ( 'medium' === $outlet->id() ) {
				continue;
			}

			$done = array();

			foreach ( $outlet->targets( $settings, $this->site_languages() ) as $target ) {
				if ( ! $target->is_complete() || ( isset( $done[ $outlet->id() ] ) && 'webhook' !== $outlet->id() ) ) {
					continue;
				}

				$done[ $outlet->id() ] = true;
				$outcome               = $this->run_test( $outlet->id(), $target->key(), false );
				$messages[]            = $outcome->message();
				$ok                    = $ok && $outcome->is_sent();
				$code                  = 0 === $code ? $outcome->code() : $code;
				++$tested;
			}
		}

		if ( 0 === $tested ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'message' => __( 'Nothing is set up to test yet.', 'estasoft-news-distribution' ),
			);
		}

		return array(
			'ok'      => $ok,
			'code'    => $code,
			'message' => trim( implode( ' ', $messages ) ),
		);
	}

	/**
	 * Sends a webhook's latest failed article again. A person pressed "Retry now".
	 *
	 * @param string $key Endpoint number.
	 * @return Outcome
	 */
	public function retry_webhook( string $key ): Outcome {
		$webhook  = $this->outlet( 'webhook' );
		$settings = $this->settings();
		$row      = $this->state->webhook( $key );
		$post_id  = (int) ( $row['post'] ?? 0 );
		$type     = (string) ( $row['event'] ?? Event::PUBLISH );
		$none     = new Target( 'webhook', $key, $key );

		if ( ! $webhook instanceof WebhookOutlet || $post_id < 1 || ! in_array( $type, array( Event::PUBLISH, Event::UNPUBLISH, Event::DELETE ), true ) ) {
			return Outcome::failed( $none, __( 'There is no failed delivery to send again.', 'estasoft-news-distribution' ), 0, false );
		}

		$reader   = new ContentReader( $this->context->languages, $this->context->settings );
		$article  = $reader->find( $post_id );
		$snapshot = Removed::recall( $post_id );
		$event    = new Event(
			$type,
			$post_id,
			null === $article ? $snapshot['language'] : $article->language(),
			null,
			array(
				'url'   => $snapshot['url'],
				'title' => $snapshot['title'],
			)
		);

		if ( ! $event->removes_content() && ( null === $article || ! $article->is_public() ) ) {
			return Outcome::failed( $none, __( 'The article is no longer published, so it is not sent.', 'estasoft-news-distribution' ), 0, false );
		}

		foreach ( $webhook->targets( $settings, array() ) as $target ) {
			if ( $target->key() !== $key ) {
				continue;
			}

			if ( ! $target->is_complete() ) {
				return Outcome::failed( $target, __( 'Finish setting this endpoint up first: something it needs is missing.', 'estasoft-news-distribution' ), 0, false );
			}

			$this->marks->forget( $post_id, $type, $target->id() );

			$outcome = $webhook->send( $target, $event, $article, $settings );

			$this->settle( $post_id, $type, $outcome, null === $article ? $event->title() : PostText::headline( $article ) );
			$this->context->log->record(
				$post_id,
				self::ID,
				$event->language(),
				$type,
				$outcome->is_sent() ? Status::SENT : Status::FAILED,
				1,
				$outcome->code(),
				$this->describe( $target ) . ': ' . $outcome->message()
			);

			return $outcome;
		}

		return Outcome::failed( $none, __( 'That endpoint is not set up.', 'estasoft-news-distribution' ), 0, false );
	}

	/**
	 * The rows this channel gives the Channels hub: one, with what needs a person.
	 *
	 * @return array<int, array{key: string, name: string, summary: string, status: string, enabled: bool|null, action: string}>
	 */
	public function hub_rows(): array {
		$active = array() !== $this->active( $this->settings() );

		return array(
			array(
				'key'     => $this->id(),
				'name'    => $this->label(),
				'summary' => $this->hub_summary(),
				'status'  => $this->health(),
				'enabled' => $active,
				'action'  => $active ? 'open' : 'set_up',
			),
		);
	}

	/**
	 * The line under the name on the hub: what needs a person, else which outlets are on.
	 *
	 * @return string
	 */
	protected function hub_summary(): string {
		$items = $this->attention();

		if ( array() !== $items ) {
			return implode(
				' ',
				array_map(
					static function ( array $item ): string {
						return $item['text'];
					},
					$items
				)
			);
		}

		$names = array();

		foreach ( $this->active( $this->settings() ) as $outlet ) {
			$names[] = $outlet->label();
		}

		return implode( ', ', $names );
	}
}

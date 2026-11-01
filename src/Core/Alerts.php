<?php
/**
 * Alert emails.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Channels\SearchSitemap\FetchSummary;
use Estasoft\NewsDistribution\Core\Chat\AbstractChatSender;
use Estasoft\NewsDistribution\Core\Chat\ChatMessage;
use Estasoft\NewsDistribution\Core\Chat\SlackSender;
use Estasoft\NewsDistribution\Core\Chat\TeamsSender;
use Estasoft\NewsDistribution\Core\Operations\AlertState;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Tells people about a problem by email, through `wp_mail()`, so the site's own
 * mail setup (or an SMTP plugin) decides how it is delivered, and, when an administrator
 * saved a webhook address, by a short message to Slack or Microsoft Teams. The email part
 * contacts no other server; the plugin hands the message to WordPress and cannot see
 * whether it reached an inbox. The chat part posts to the saved webhook address (see
 * `Core\Chat`), only from the paths that already send alerts (the queue, or a person's
 * button), never when an article is published, and never while nobody ticked a Slack or Teams box.
 *
 * Settings, Alerts has one column of boxes for each place: `email_alerts`, `slack_alerts` and
 * `teams_alerts`, each a list of the keys of `types()`. `places()` says where one alert goes. A chat
 * message is the alert's title, one sentence and a link back (`chat_message()`). It never holds article
 * text or an article's title, and is built from `model()`, which the email filter cannot change.
 *
 * Get it with `Plugin::instance()->make( Alerts::class )`, then:
 *
 *     $alerts->send( Alerts::TAKEDOWN, array( 'article_id' => 12, ... ) );
 *
 * `send()` returns array{ status: string, sent: int, to: string[], error: string, places: array }, where
 * `status` is one of `sent` (at least one place accepted the alert), `off` (the type is not ticked anywhere in
 * Settings, Alerts), `nobody` (nobody to tell), `held` (a push failure kept for the next hourly alert) or
 * `failed` (every place that was tried refused it; `error` says why, with no webhook address in it). `sent`
 * counts the email addresses and the chat services that took it. `places` has an entry
 * for each chat service that was tried: array{ status: 'sent'|'failed', error: string }.
 *
 * Types and the context each one reads (every key is optional unless it says so):
 *
 * - `push_failed`: `failures`, a list of array{ channel: string label, article_id: int, title: string,
 *   tries: int, first_at: int, last_at: int, answer: string }. At most one email an hour; failures
 *   that arrive within the hour are added to the next one. Goes to the alert addresses.
 * - `platform_silent`: `platform` (label), `hours` (int). Alert addresses.
 * - `article_skipped`: `articles` (int), `reasons`, a list of array{ reason: string, channels: string[]
 *   labels, count: int }. A daily summary. Alert addresses.
 * - `takedown`: `article_id` (int), `title`, `by` (user ID of the person who started it), `done`
 *   (text of what was done), `todo` (list of text, what is left), `reminder` (bool). Goes to
 *   `by` and to the alert addresses.
 * - `fix_assigned`: `to_user` (user ID, required), `by` (user ID), `article_id`, `title`, `fix`
 *   (text), `due` (Unix time), `reminder` (bool). Goes to `to_user` only.
 * - `rule_change`: `platform` (label), `articles` (int), `version`. Alert addresses.
 * - `ai_limit`: `message`. Alert addresses.
 * - `weekly`: sent by Digest.
 *
 * A fix or takedown owner that wants a reminder calls `schedule_reminder()`, and answers
 * the filter `estasoft_nd_internal_alert_still_open` with false once nothing is open.
 */
final class Alerts {

	public const PUSH_FAILED     = 'push_failed';
	public const PLATFORM_SILENT = 'platform_silent';
	public const ARTICLE_SKIPPED = 'article_skipped';
	public const TAKEDOWN        = 'takedown';
	public const FIX_ASSIGNED    = 'fix_assigned';
	public const RULE_CHANGE     = 'rule_change';
	public const AI_LIMIT        = 'ai_limit';
	public const WEEKLY          = 'weekly';
	public const TEST            = 'test';

	public const REMINDER_HOOK = 'estasoft_nd_alert_reminder';

	public const SLACK = 'slack';
	public const TEAMS = 'teams';

	private const MAX_PENDING = 50;

	private Plugin $plugin;

	private AlertState $state;

	/**
	 * Creates the alert sender.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->state  = new AlertState();
	}

	/**
	 * The alert types, in the order the screen lists them.
	 *
	 * @return array<string, array{title: string, help: string, default: bool}>
	 */
	public static function types(): array {
		return array(
			self::PUSH_FAILED     => array(
				'title'   => __( 'A push fails its last retry', 'estasoft-news-distribution' ),
				'help'    => __( 'WebSub pings and outlet webhooks that never got through.', 'estasoft-news-distribution' ),
				'default' => true,
			),
			self::PLATFORM_SILENT => array(
				'title'   => __( 'A platform stops fetching your feed', 'estasoft-news-distribution' ),
				'help'    => __( 'No request for 24 hours. Checked only for feeds that platforms pull.', 'estasoft-news-distribution' ),
				'default' => true,
			),
			self::ARTICLE_SKIPPED => array(
				'title'   => __( 'An article is skipped by a channel', 'estasoft-news-distribution' ),
				'help'    => __( 'Summarized once a day, not once per article.', 'estasoft-news-distribution' ),
				'default' => false,
			),
			self::TAKEDOWN        => array(
				'title'   => __( 'A takedown needs your action', 'estasoft-news-distribution' ),
				'help'    => __( 'A platform needs you to finish a step by hand.', 'estasoft-news-distribution' ),
				'default' => true,
			),
			self::FIX_ASSIGNED    => array(
				'title'   => __( 'A fix is assigned to you', 'estasoft-news-distribution' ),
				'help'    => __( 'Assigned in Article check.', 'estasoft-news-distribution' ),
				'default' => true,
			),
			self::RULE_CHANGE     => array(
				'title'   => __( 'A rule change affects your articles', 'estasoft-news-distribution' ),
				'help'    => __( 'A platform’s rule pack changed and some of your recent articles are now affected. Needs rule updates turned on in Platform rules.', 'estasoft-news-distribution' ),
				'default' => true,
			),
			self::AI_LIMIT        => array(
				'title'   => __( 'The AI assistant reaches a limit', 'estasoft-news-distribution' ),
				'help'    => __( 'At the alert level of the monthly token limit (80% by default) and when suggestions pause. Only when the AI assistant is on.', 'estasoft-news-distribution' ),
				'default' => true,
			),
			self::WEEKLY          => array(
				'title'   => __( 'Weekly summary', 'estasoft-news-distribution' ),
				'help'    => __( 'Fetches, pushes and skipped articles for the week.', 'estasoft-news-distribution' ),
				'default' => true,
			),
		);
	}

	/**
	 * Splits what a person typed into addresses.
	 *
	 * @param string $raw Addresses separated by commas, semicolons, spaces or new lines.
	 * @return array{valid: string[], invalid: string[]}
	 */
	public static function parse_addresses( string $raw ): array {
		$valid   = array();
		$invalid = array();

		foreach ( (array) preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $entry ) {
			$entry = trim( (string) $entry );
			$clean = sanitize_email( $entry );

			if ( '' !== $clean && is_email( $clean ) ) {
				$valid[ strtolower( $clean ) ] = $clean;
			} else {
				$invalid[] = $entry;
			}
		}

		return array(
			'valid'   => array_values( $valid ),
			'invalid' => $invalid,
		);
	}

	/**
	 * The saved alert addresses.
	 *
	 * @return string[]
	 */
	public function addresses(): array {
		return self::parse_addresses( (string) $this->plugin->settings()->get( 'alerts.send_to', '' ) )['valid'];
	}

	/**
	 * The alert types that are ticked.
	 *
	 * @return string[]
	 */
	public function enabled_types(): array {
		$chosen = $this->plugin->settings()->get( 'alerts.email_alerts', array() );

		return is_array( $chosen ) ? array_values( array_map( 'strval', $chosen ) ) : array();
	}

	/**
	 * Whether an alert type is ticked.
	 *
	 * @param string $type One of the class constants.
	 * @return bool
	 */
	public function is_on( string $type ): bool {
		return in_array( $type, $this->enabled_types(), true );
	}

	/**
	 * The chat services, by id.
	 *
	 * @return array<string, AbstractChatSender>
	 */
	public function chat_senders(): array {
		return array(
			self::SLACK => new SlackSender(),
			self::TEAMS => new TeamsSender(),
		);
	}

	/**
	 * One chat service.
	 *
	 * @param string $service `slack` or `teams`.
	 * @return AbstractChatSender|null
	 */
	public function chat_sender( string $service ): ?AbstractChatSender {
		return $this->chat_senders()[ $service ] ?? null;
	}

	/**
	 * Whether an administrator saved a webhook address for a chat service.
	 *
	 * @param string $service `slack` or `teams`.
	 * @return bool
	 */
	public function has_chat_address( string $service ): bool {
		$sender = $this->chat_sender( $service );

		return null !== $sender && $this->plugin->secrets()->has( $sender->secret_key() );
	}

	/**
	 * The alert types that are ticked in a chat service's column.
	 *
	 * @param string $service `slack` or `teams`.
	 * @return string[]
	 */
	public function chat_types( string $service ): array {
		$chosen = $this->plugin->settings()->get( 'alerts.' . $service . '_alerts', array() );

		return is_array( $chosen ) ? array_values( array_map( 'strval', $chosen ) ) : array();
	}

	/**
	 * The chat services an alert goes to: ticked in the service's column, and the service has a saved address.
	 *
	 * @param string $type One of the class constants.
	 * @return string[] `slack` and `teams`, in that order, or fewer.
	 */
	public function chat_places( string $type ): array {
		$places = array();

		foreach ( array_keys( $this->chat_senders() ) as $service ) {
			if ( in_array( $type, $this->chat_types( $service ), true ) && $this->has_chat_address( $service ) ) {
				$places[] = $service;
			}
		}

		return $places;
	}

	/**
	 * Where an alert goes: `email` when it is ticked in the Email column, then each chat service that is
	 * ticked and set up. Email is listed whether or not an address is saved; with none, nothing is sent there.
	 *
	 * @param string $type One of the class constants.
	 * @return string[]
	 */
	public function places( string $type ): array {
		return array_merge( $this->is_on( $type ) ? array( 'email' ) : array(), $this->chat_places( $type ) );
	}

	/**
	 * Whether anybody asked to hear about an alert type, by email or in chat. Work that only builds an alert
	 * checks this first, so nothing is prepared for an alert that goes nowhere.
	 *
	 * @param string $type One of the class constants.
	 * @return bool
	 */
	public function is_wanted( string $type ): bool {
		return array() !== $this->places( $type );
	}

	/**
	 * Tells people about something.
	 *
	 * @param string               $type    One of the class constants. See the class description for each context.
	 * @param array<string, mixed> $context Facts the email needs.
	 * @return array{status: string, sent: int, to: string[], error: string}
	 */
	public function send( string $type, array $context = array() ): array {
		if ( ! isset( self::types()[ $type ] ) || self::WEEKLY === $type ) {
			return $this->result( 'off' );
		}

		$chat = $this->chat_places( $type );

		if ( ! $this->is_on( $type ) && array() === $chat ) {
			return $this->result( 'off' );
		}

		$recipients = $this->is_on( $type ) ? $this->recipients( $type, $context ) : array();

		if ( array() === $recipients && array() === $chat ) {
			return $this->result( 'nobody' );
		}

		if ( self::PUSH_FAILED === $type ) {
			$state   = (array) $this->state->get( 'push_failed', array() );
			$pending = array_merge( (array) ( $state['pending'] ?? array() ), (array) ( $context['failures'] ?? array() ) );
			$last    = (int) ( $state['last_sent'] ?? 0 );

			if ( $last > 0 && Clock::now() - $last < HOUR_IN_SECONDS ) {
				$state['pending'] = array_slice( $pending, -self::MAX_PENDING );
				$this->state->put( 'push_failed', $state );

				return $this->result( 'held' );
			}

			$context['failures'] = $pending;
		}

		$result = array() === $recipients
			? $this->result( 'nobody' )
			: $this->dispatch(
				$recipients,
				function () use ( $type, $context ): array {
					return $this->mail( $this->compose( $type, $context ) );
				}
			);

		if ( array() !== $chat ) {
			$result = $this->add_chat( $result, $chat, $this->chat_message( $type, $context ), array() !== $recipients );
		}

		if ( self::PUSH_FAILED === $type && 'sent' === $result['status'] ) {
			$this->state->put(
				'push_failed',
				array(
					'last_sent' => Clock::now(),
					'pending'   => array(),
				)
			);
		}

		return $result;
	}

	/**
	 * Sends the push failures kept back during the last hour, once the hour is over.
	 *
	 * @return array{status: string, sent: int, to: string[], error: string}
	 */
	public function flush_pending(): array {
		$state   = (array) $this->state->get( 'push_failed', array() );
		$pending = (array) ( $state['pending'] ?? array() );

		if ( array() === $pending || Clock::now() - (int) ( $state['last_sent'] ?? 0 ) < HOUR_IN_SECONDS ) {
			return $this->result( 'held' );
		}

		return $this->send( self::PUSH_FAILED, array( 'failures' => array() ) );
	}

	/**
	 * Hooked to `estasoft_nd_delivery_failed`: the failure goes into the push-failed alert.
	 *
	 * An hour passes between two such emails, so a burst of failures becomes one email.
	 *
	 * @param mixed $article_id Article ID.
	 * @param mixed $channel_id Channel ID.
	 * @param mixed $language   Language code.
	 * @param mixed $event_type Event type.
	 * @param mixed $attempt    The attempt that failed.
	 * @param mixed $code       The platform's HTTP status, or 0.
	 * @param mixed $message    What the platform answered.
	 * @return void
	 */
	public function on_delivery_failed( $article_id = 0, $channel_id = '', $language = '', $event_type = '', $attempt = 1, $code = 0, $message = '' ): void {
		unset( $language, $event_type );

		if ( ! $this->is_wanted( self::PUSH_FAILED ) ) {
			return;
		}

		$channel = $this->plugin->registry()->get( (string) $channel_id );
		$tries   = max( 1, (int) $attempt );
		$span    = 0;

		for ( $i = 1; $i < $tries; $i++ ) {
			$span += (int) Retry::delay_after( $i );
		}

		$answer = trim( (string) $message );

		if ( (int) $code > 0 ) {
			$answer = trim( $code . ' ' . $answer );
		}

		$this->send(
			self::PUSH_FAILED,
			array(
				'failures' => array(
					array(
						'channel'  => null === $channel ? (string) $channel_id : $channel->label(),
						'title'    => wp_strip_all_tags( get_the_title( (int) $article_id ) ),
						'answer'   => $answer,
						'tries'    => $tries,
						'first_at' => Clock::now() - $span,
						'last_at'  => Clock::now(),
					),
				),
			)
		);
	}

	/**
	 * Sends one short email with no article text, to the alert addresses, or to
	 * the signed-in person when there are none.
	 *
	 * "Sent" means WordPress accepted the message. Nothing can see the inbox.
	 *
	 * @return array{status: string, sent: int, to: string[], error: string}
	 */
	public function send_test(): array {
		$recipients = array();

		foreach ( $this->addresses() as $address ) {
			$recipients[] = $this->recipient( $address );
		}

		if ( array() === $recipients ) {
			$user = wp_get_current_user();

			if ( $user->exists() && is_email( $user->user_email ) ) {
				$recipients[] = array(
					'email'   => (string) $user->user_email,
					'user_id' => (int) $user->ID,
				);
			}
		}

		if ( array() === $recipients ) {
			return $this->result( 'nobody', array(), __( 'There is no address to send to. Add one above and save, then try again.', 'estasoft-news-distribution' ) );
		}

		$result = $this->dispatch(
			$recipients,
			function (): array {
				return $this->mail( $this->compose( self::TEST, array() ) );
			}
		);

		$this->state->put(
			'email_test',
			array(
				'time'    => Clock::now(),
				'ok'      => 'sent' === $result['status'],
				'to'      => $result['to'],
				'message' => $result['error'],
			)
		);

		return $result;
	}

	/**
	 * The result of the latest test email.
	 *
	 * @return array{time: int, ok: bool, to: string[], message: string}|null Null when no test was sent.
	 */
	public function last_test(): ?array {
		$test = $this->state->get( 'email_test', array() );

		if ( ! is_array( $test ) || empty( $test['time'] ) ) {
			return null;
		}

		return array(
			'time'    => (int) $test['time'],
			'ok'      => ! empty( $test['ok'] ),
			'to'      => array_values( array_map( 'strval', (array) ( $test['to'] ?? array() ) ) ),
			'message' => (string) ( $test['message'] ?? '' ),
		);
	}

	/**
	 * Schedules a reminder through the queue.
	 *
	 * When it runs, the filter `estasoft_nd_internal_alert_still_open` decides
	 * whether it is still needed.
	 *
	 * @param string               $type          Alert type.
	 * @param array<string, mixed> $context       The same context `send()` took. Keep it small and made of text and numbers.
	 * @param int                  $delay_seconds Wait before the reminder.
	 * @return int Action ID, or 0 when the queue is not available.
	 */
	public function schedule_reminder( string $type, array $context, int $delay_seconds ): int {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return 0;
		}

		return (int) as_schedule_single_action( Clock::now() + max( 60, $delay_seconds ), self::REMINDER_HOOK, array( $type, $context ), Queue::GROUP );
	}

	/**
	 * Runs a reminder the queue was asked for.
	 *
	 * @param mixed $type    Alert type.
	 * @param mixed $context Context.
	 * @return void
	 */
	public function run_reminder( $type = '', $context = array() ): void {
		$type    = (string) $type;
		$context = is_array( $context ) ? $context : array();

		/**
		 * Whether a reminder is still needed.
		 *
		 * @param bool                 $open    True to send the reminder.
		 * @param string               $type    Alert type.
		 * @param array<string, mixed> $context What was scheduled with it.
		 */
		if ( ! (bool) apply_filters( 'estasoft_nd_internal_alert_still_open', true, $type, $context ) ) {
			return;
		}

		$context['reminder'] = true;
		$this->send( $type, $context );
	}

	/**
	 * Tells the alert addresses about a platform that stopped fetching, once per quiet spell.
	 *
	 * Looks at the fetch log for every feed a platform pulls and tells the alert
	 * addresses about one that fetched before and has been silent for 24 hours.
	 *
	 * @return int Alerts sent.
	 */
	public function check_silent_platforms(): int {
		if ( ! $this->is_wanted( self::PLATFORM_SILENT ) ) {
			return 0;
		}

		$latest   = $this->plugin->fetch_log()->latest();
		$told     = (array) $this->state->get( 'silent', array() );
		$sent     = 0;
		$last_for = array();

		foreach ( $this->plugin->registry()->pulled() as $channel ) {
			$id = $channel->id();

			// Bing, Yandex and Baidu share one sitemap: each is read by its crawler name, not by the channel that answered.
			foreach ( FetchSummary::latest_rows( $this->plugin, $channel, $latest ) as $row ) {
				$last_for[ $id ] = max( (int) ( $last_for[ $id ] ?? 0 ), (int) $row['created_ts'] );
			}

			$last = (int) ( $last_for[ $id ] ?? 0 );

			if ( ! $channel->is_enabled() || '' === $channel->feed_slug() || 0 === $last ) {
				continue;
			}

			$quiet = Clock::now() - $last;

			if ( $quiet < DAY_IN_SECONDS ) {
				unset( $told[ $id ] );
				continue;
			}

			if ( (int) ( $told[ $id ] ?? 0 ) === $last ) {
				continue;
			}

			$result = $this->send(
				self::PLATFORM_SILENT,
				array(
					'platform' => $channel->label(),
					'hours'    => (int) floor( $quiet / HOUR_IN_SECONDS ),
				)
			);

			if ( 'sent' === $result['status'] ) {
				$told[ $id ] = $last;
				++$sent;
			}
		}

		$this->state->put( 'silent', $told );

		return $sent;
	}

	/**
	 * Sends the once-a-day summary of articles a channel skipped.
	 *
	 * @return array{status: string, sent: int, to: string[], error: string}
	 */
	public function send_daily_skipped(): array {
		$state = (array) $this->state->get( 'skipped_daily', array() );
		$last  = (int) ( $state['last_sent'] ?? 0 );

		if ( ! $this->is_wanted( self::ARTICLE_SKIPPED ) || ( $last > 0 && Clock::now() - $last < DAY_IN_SECONDS ) ) {
			return $this->result( 'off' );
		}

		$since   = $last > 0 ? $last : Clock::now() - DAY_IN_SECONDS;
		$skipped = $this->plugin->make( Digest::class )->skipped( $since, Clock::now() );

		if ( 0 === $skipped['articles'] ) {
			return $this->result( 'nobody' );
		}

		$result = $this->send(
			self::ARTICLE_SKIPPED,
			array(
				'articles' => $skipped['articles'],
				'reasons'  => $skipped['reasons'],
			)
		);

		if ( 'sent' === $result['status'] ) {
			$this->state->put( 'skipped_daily', array( 'last_sent' => Clock::now() ) );
		}

		return $result;
	}

	/**
	 * The short message a chat service gets for an alert: the alert's title, one sentence, and a link back.
	 *
	 * It is written in the site's language, because a chat channel is shared. It never names an article, never
	 * holds article text and never holds a secret. It is built from `model()`, so the email filter cannot add to it.
	 *
	 * @param string               $type    Alert type, or `test`.
	 * @param array<string, mixed> $context The context given to `send()`.
	 * @return ChatMessage
	 */
	public function chat_message( string $type, array $context = array() ): ChatMessage {
		$locale = get_locale();
		$switch = '' !== $locale && switch_to_locale( $locale );

		try {
			$message = $this->build_chat_message( $type, $context );
		} finally {
			if ( $switch ) {
				restore_previous_locale();
			}
		}

		return $message;
	}

	/**
	 * Builds the chat message in the language that is active now.
	 *
	 * @param string               $type    Alert type, or `test`.
	 * @param array<string, mixed> $context The context given to `send()`.
	 * @return ChatMessage
	 */
	private function build_chat_message( string $type, array $context ): ChatMessage {
		$model = $this->model( $type, $context );
		$types = self::types();

		if ( self::TEST === $type ) {
			return new ChatMessage(
				__( 'Test message from News Distribution', 'estasoft-news-distribution' ),
				$model['lead'] . ' ' . __( 'If you can read this, the webhook works. Nothing needs doing.', 'estasoft-news-distribution' )
			);
		}

		$title = isset( $types[ $type ] ) ? $types[ $type ]['title'] : (string) $model['subject'];

		switch ( $type ) {
			case self::FIX_ASSIGNED:
				$text = sprintf(
					/* translators: 1: the name of the person who assigned the fix, 2: the name of the person it is assigned to, 3: the site name. */
					__( '%1$s assigned a fix to %2$s on %3$s.', 'estasoft-news-distribution' ),
					$this->person_name( (int) ( $context['by'] ?? 0 ) ),
					$this->person_name( (int) ( $context['to_user'] ?? 0 ) ),
					$this->site_name()
				);
				break;

			case self::ARTICLE_SKIPPED:
				// The subject counts the articles and names none.
				$text = (string) $model['subject'];
				break;

			case self::PUSH_FAILED:
				$count = count( array_filter( (array) ( $context['failures'] ?? array() ), 'is_array' ) );
				$text  = $count > 1
					? sprintf(
						/* translators: %d: number of failed pushes. */
						_n( '%d push failed after every try.', '%d pushes failed after every try.', $count, 'estasoft-news-distribution' ),
						$count
					)
					: (string) $model['lead'];
				break;

			default:
				$text = (string) $model['lead'];
		}

		$button = is_array( $model['button'] ) ? $model['button'] : array(
			'label' => '',
			'url'   => '',
		);

		return new ChatMessage( $title, $text, (string) $button['label'], (string) $button['url'] );
	}

	/**
	 * Posts a message to one chat service, with the saved address.
	 *
	 * @param string      $service `slack` or `teams`.
	 * @param ChatMessage $message The message.
	 * @return array{ok: bool, code: int, message: string} `message` is empty on success, else a sentence with no address in it.
	 */
	private function send_chat( string $service, ChatMessage $message ): array {
		$sender  = $this->chat_sender( $service );
		$address = null === $sender ? '' : $this->plugin->secrets()->get( $sender->secret_key() );

		if ( null === $sender || '' === $address ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'message' => __( 'No webhook address is saved for that service.', 'estasoft-news-distribution' ),
			);
		}

		return $sender->post( $address, $message );
	}

	/**
	 * Adds the chat services' answers to the result of the email part.
	 *
	 * @param array{status: string, sent: int, to: string[], error: string} $result   The email part.
	 * @param string[]                                                      $services The chat services to post to.
	 * @param ChatMessage                                                   $message  The message.
	 * @param bool                                                          $emailed  Whether an email was tried.
	 * @return array{status: string, sent: int, to: string[], error: string, places: array<string, array{status: string, error: string}>}
	 */
	private function add_chat( array $result, array $services, ChatMessage $message, bool $emailed ): array {
		$places = array();
		$sent   = $emailed ? $result['sent'] : 0;
		$error  = $emailed ? $result['error'] : '';

		foreach ( $services as $service ) {
			$answer = $this->send_chat( $service, $message );

			$places[ $service ] = array(
				'status' => $answer['ok'] ? 'sent' : 'failed',
				'error'  => $answer['message'],
			);

			if ( $answer['ok'] ) {
				++$sent;
			} elseif ( '' === $error ) {
				$error = $answer['message'];
			}
		}

		return array(
			'status' => $sent > 0 ? 'sent' : 'failed',
			'sent'   => $sent,
			'to'     => $emailed ? $result['to'] : array(),
			'error'  => $error,
			'places' => $places,
		);
	}

	/**
	 * Posts one short test message to a chat service's saved address. A person starts it; nothing else does.
	 *
	 * "Sent" means the service accepted the message. Nothing can see whether a person read it.
	 *
	 * @param string $service `slack` or `teams`.
	 * @return array{status: string, ok: bool, error: string} `status` is `sent`, `failed`, `nobody` (no address is saved, so nothing was sent) or `unknown`.
	 */
	public function send_test_chat( string $service ): array {
		$sender = $this->chat_sender( $service );

		if ( null === $sender ) {
			return array(
				'status' => 'unknown',
				'ok'     => false,
				'error'  => __( 'That chat service does not exist.', 'estasoft-news-distribution' ),
			);
		}

		$address = $this->plugin->secrets()->get( $sender->secret_key() );

		if ( '' === $address ) {
			$answer = array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %s: chat service name, such as Slack. */
					__( 'There is no %s address to send to. Paste one above and save, then try again.', 'estasoft-news-distribution' ),
					$sender->label()
				),
			);
			$this->remember_chat_test( $service, $answer, '' );

			return array(
				'status' => 'nobody',
				'ok'     => false,
				'error'  => $answer['message'],
			);
		}

		$answer = $this->send_chat( $service, $this->chat_message( self::TEST ) );
		$this->remember_chat_test( $service, $answer, $address );

		return array(
			'status' => $answer['ok'] ? 'sent' : 'failed',
			'ok'     => $answer['ok'],
			'error'  => $answer['message'],
		);
	}

	/**
	 * Keeps how a test message went, with a fingerprint of the address it was run against.
	 *
	 * @param string                           $service `slack` or `teams`.
	 * @param array{ok: bool, message: string} $answer  What the test returned.
	 * @param string                           $address The address tested, or an empty string when none was saved.
	 * @return void
	 */
	private function remember_chat_test( string $service, array $answer, string $address ): void {
		$all = (array) $this->state->get( 'chat_test', array() );

		$all[ $service ] = array(
			'time'    => Clock::now(),
			'ok'      => $answer['ok'],
			'message' => $answer['message'],
			'address' => $this->fingerprint( $address ),
		);

		$this->state->put( 'chat_test', $all );
	}

	/**
	 * The result of the latest test message to a chat service, while the saved address is still the one tested.
	 *
	 * @param string $service `slack` or `teams`.
	 * @return array{time: int, ok: bool, message: string}|null Null when no test was sent to the address that is saved now. With no address saved, it is the answer to the last test that found none.
	 */
	public function last_chat_test( string $service ): ?array {
		$sender = $this->chat_sender( $service );
		$all    = (array) $this->state->get( 'chat_test', array() );
		$test   = is_array( $all[ $service ] ?? null ) ? $all[ $service ] : array();

		if ( null === $sender || empty( $test['time'] ) ) {
			return null;
		}

		if ( ( $test['address'] ?? '' ) !== $this->fingerprint( $this->plugin->secrets()->get( $sender->secret_key() ) ) ) {
			return null;
		}

		return array(
			'time'    => (int) $test['time'],
			'ok'      => ! empty( $test['ok'] ),
			'message' => (string) ( $test['message'] ?? '' ),
		);
	}

	/**
	 * A short fingerprint of an address, so a test result is kept only for the address it was run against. It is
	 * not the address and cannot be turned back into it.
	 *
	 * @param string $address The webhook address.
	 * @return string
	 */
	private function fingerprint( string $address ): string {
		return '' === $address ? '' : substr( hash_hmac( 'sha256', $address, wp_salt( 'auth' ) ), 0, 16 );
	}

	/**
	 * Posts the weekly summary to the chat services that are ticked for it. The email is `Core\Digest`'s own.
	 *
	 * @param string $sentence One sentence that says what the week was, with no article text.
	 * @return array<string, array{status: string, error: string}> One entry for each chat service that was tried.
	 */
	public function send_weekly_chat( string $sentence ): array {
		$places  = array();
		$message = null;

		foreach ( $this->chat_places( self::WEEKLY ) as $service ) {
			if ( null === $message ) {
				$locale = get_locale();
				$switch = '' !== $locale && switch_to_locale( $locale );

				try {
					$message = new ChatMessage(
						self::types()[ self::WEEKLY ]['title'],
						$sentence,
						__( 'Open the weekly summary', 'estasoft-news-distribution' ),
						Links::settings( 'alerts', array( 'tab' => 'weekly' ) )
					);
				} finally {
					if ( $switch ) {
						restore_previous_locale();
					}
				}
			}

			$answer = $this->send_chat( $service, $message );

			$places[ $service ] = array(
				'status' => $answer['ok'] ? 'sent' : 'failed',
				'error'  => $answer['message'],
			);
		}

		return $places;
	}

	/**
	 * Sends one email to each recipient, in that person's language.
	 *
	 * @param array<int, array{email: string, user_id: int}> $recipients People to tell.
	 * @param callable                                       $build      Called once per recipient, while that person's language is
	 *                                                                   active. Returns array{ subject: string, html: string, text: string }.
	 * @return array{status: string, sent: int, to: string[], error: string}
	 */
	public function dispatch( array $recipients, callable $build ): array {
		$sent  = array();
		$error = '';

		foreach ( $recipients as $recipient ) {
			$user_id = (int) $recipient['user_id'];
			$locale  = $user_id > 0 ? get_user_locale( $user_id ) : get_locale();
			$switch  = '' !== $locale && switch_to_locale( $locale );

			try {
				$mail = $build();
			} finally {
				if ( $switch ) {
					restore_previous_locale();
				}
			}

			$problem = '';
			$capture = static function ( $failure ) use ( &$problem ): void {
				$problem = $failure instanceof \WP_Error ? $failure->get_error_message() : '';
			};
			$alt     = static function ( $mailer ) use ( $mail ): void {
				if ( is_object( $mailer ) && property_exists( $mailer, 'AltBody' ) ) {
					$mailer->AltBody = (string) $mail['text']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property.
				}
			};

			add_action( 'wp_mail_failed', $capture );
			add_action( 'phpmailer_init', $alt );

			$accepted = wp_mail(
				$recipient['email'],
				$mail['subject'],
				$mail['html'],
				array( 'Content-Type: text/html; charset=UTF-8' )
			);

			remove_action( 'wp_mail_failed', $capture );
			remove_action( 'phpmailer_init', $alt );

			if ( $accepted ) {
				$sent[] = $recipient['email'];
			} elseif ( '' === $error ) {
				$error = '' !== $problem ? $problem : __( 'WordPress could not send the email.', 'estasoft-news-distribution' );
			}
		}

		return array(
			'status' => array() === $sent ? 'failed' : 'sent',
			'sent'   => count( $sent ),
			'to'     => $sent,
			'error'  => $error,
		);
	}

	/**
	 * Who gets an alert.
	 *
	 * @param string               $type    Alert type.
	 * @param array<string, mixed> $context The context given to `send()`.
	 * @return array<int, array{email: string, user_id: int}>
	 */
	public function recipients( string $type, array $context ): array {
		$people = array();

		if ( self::FIX_ASSIGNED === $type ) {
			$user = get_userdata( (int) ( $context['to_user'] ?? 0 ) );

			if ( $user instanceof \WP_User && is_email( $user->user_email ) ) {
				$people[ strtolower( $user->user_email ) ] = array(
					'email'   => (string) $user->user_email,
					'user_id' => (int) $user->ID,
				);
			}

			return array_values( $people );
		}

		if ( self::TAKEDOWN === $type ) {
			$user = get_userdata( (int) ( $context['by'] ?? 0 ) );

			if ( $user instanceof \WP_User && is_email( $user->user_email ) ) {
				$people[ strtolower( $user->user_email ) ] = array(
					'email'   => (string) $user->user_email,
					'user_id' => (int) $user->ID,
				);
			}
		}

		foreach ( $this->addresses() as $address ) {
			if ( ! isset( $people[ strtolower( $address ) ] ) ) {
				$people[ strtolower( $address ) ] = $this->recipient( $address );
			}
		}

		return array_values( $people );
	}

	/**
	 * The person behind an address, when the address belongs to a WordPress user.
	 *
	 * @param string $address Email address.
	 * @return array{email: string, user_id: int}
	 */
	public function recipient( string $address ): array {
		$user = get_user_by( 'email', $address );

		return array(
			'email'   => $address,
			'user_id' => $user instanceof \WP_User ? (int) $user->ID : 0,
		);
	}

	/**
	 * Builds the email for a type, in the language that is active now. Other code may change it with the filter
	 * `estasoft_nd_alert_email`; a chat message is built from `model()` instead, so that filter cannot add text to it.
	 *
	 * @param string               $type    Alert type, or `test`.
	 * @param array<string, mixed> $context The context given to `send()`.
	 * @return array{subject: string, lead: string, facts: array<int, array{label: string, value: string}>, items: string[], paragraphs: string[], button: array{label: string, url: string}|null, note: string}
	 */
	public function compose( string $type, array $context ): array {
		$model = $this->model( $type, $context );

		/**
		 * Filters the email before it is sent.
		 *
		 * @param array<string, mixed> $model   Subject, lead, facts, items, paragraphs, button and note.
		 * @param string               $type    Alert type.
		 * @param array<string, mixed> $context The context given to `send()`.
		 */
		$filtered = apply_filters( 'estasoft_nd_alert_email', $model, $type, $context );

		return is_array( $filtered ) ? array_merge( $model, $filtered ) : $model;
	}

	/**
	 * The parts of an alert, in the language that is active now, before any filter.
	 *
	 * @param string               $type    Alert type, or `test`.
	 * @param array<string, mixed> $context The context given to `send()`.
	 * @return array{subject: string, lead: string, facts: array<int, array{label: string, value: string}>, items: string[], paragraphs: string[], button: array{label: string, url: string}|null, note: string}
	 */
	public function model( string $type, array $context ): array {
		$site     = $this->site_name();
		$title    = $this->article_title( $context );
		$reminder = ! empty( $context['reminder'] );

		$model = array(
			'subject'    => '',
			'lead'       => '',
			'facts'      => array(),
			'items'      => array(),
			'paragraphs' => array(),
			'button'     => null,
			'note'       => '',
		);

		switch ( $type ) {
			case self::FIX_ASSIGNED:
				$by_name = $this->person_name( (int) ( $context['by'] ?? 0 ) );
				$post_id = (int) ( $context['article_id'] ?? 0 );

				if ( $reminder ) {
					/* translators: %s: the name of the person who assigned the fix. */
					$model['subject'] = sprintf( __( 'Reminder: %s asked you to fix an article', 'estasoft-news-distribution' ), $by_name );
				} else {
					/* translators: %s: the name of the person who assigned the fix. */
					$model['subject'] = sprintf( __( '%s asked you to fix an article', 'estasoft-news-distribution' ), $by_name );
				}

				/* translators: 1: the name of the person who assigned the fix, 2: the site name. */
				$model['lead']    = sprintf( __( '%1$s gave you a fix to do on %2$s.', 'estasoft-news-distribution' ), $by_name, $site );
				$model['facts'][] = array(
					'label' => __( 'Article:', 'estasoft-news-distribution' ),
					'value' => $title,
				);
				$model['facts'][] = array(
					'label' => __( 'Fix:', 'estasoft-news-distribution' ),
					'value' => (string) ( $context['fix'] ?? '' ),
				);

				if ( ! empty( $context['due'] ) ) {
					$model['facts'][] = array(
						/* translators: %s: the name of the person who assigned the fix. */
						'label' => sprintf( __( 'Tell %s by:', 'estasoft-news-distribution' ), $by_name ),
						'value' => Clock::datetime( (int) $context['due'] ),
					);
				}

				$model['paragraphs'][] = $reminder
					? __( 'This is the reminder. Nobody else is told.', 'estasoft-news-distribution' )
					: __( 'If it is not done by then, you get one reminder. Nobody else is told.', 'estasoft-news-distribution' );
				$model['button']       = array(
					'label' => __( 'Open the fix', 'estasoft-news-distribution' ),
					'url'   => isset( $context['url'] ) ? (string) $context['url'] : Links::check( $post_id, 'assign' ),
				);
				$model['note']         = __( 'The button opens Article check in WordPress and asks you to sign in. Replies to this email are not read.', 'estasoft-news-distribution' );
				break;

			case self::PUSH_FAILED:
				$failures = array_values( array_filter( (array) ( $context['failures'] ?? array() ), 'is_array' ) );
				$count    = count( $failures );

				if ( $count > 1 ) {
					/* translators: 1: number of failed pushes, 2: the site name. */
					$model['subject'] = sprintf( _n( '%1$d push failed on %2$s', '%1$d pushes failed on %2$s', $count, 'estasoft-news-distribution' ), $count, $site );
				} else {
					/* translators: %s: the site name. */
					$model['subject'] = sprintf( __( 'A push failed on %s', 'estasoft-news-distribution' ), $site );
				}

				if ( 1 === $count ) {
					$one    = $failures[0];
					$answer = trim( (string) ( $one['answer'] ?? '' ) );

					$model['lead'] = sprintf(
						/* translators: 1: the channel name, 2: number of attempts, 3: time of the first attempt, 4: time of the last attempt. */
						__( 'A push to %1$s failed after %2$d tries (%3$s to %4$s).', 'estasoft-news-distribution' ),
						(string) ( $one['channel'] ?? '' ),
						(int) ( $one['tries'] ?? 1 ),
						Clock::format( (int) ( $one['first_at'] ?? Clock::now() ) ),
						Clock::format( (int) ( $one['last_at'] ?? Clock::now() ) )
					);

					$model['facts'][] = array(
						'label' => __( 'Article:', 'estasoft-news-distribution' ),
						'value' => (string) ( $one['title'] ?? '' ),
					);
					$model['facts'][] = array(
						'label' => __( 'Last answer:', 'estasoft-news-distribution' ),
						'value' => '' !== $answer ? $answer : __( 'none, the endpoint did not respond', 'estasoft-news-distribution' ),
					);
				} else {
					$model['lead'] = __( 'These pushes failed after every try.', 'estasoft-news-distribution' );

					foreach ( array_slice( $failures, 0, 20 ) as $failure ) {
						$model['items'][] = sprintf(
							/* translators: 1: article title, 2: channel name, 3: the answer the platform gave. */
							__( '%1$s, to %2$s: %3$s', 'estasoft-news-distribution' ),
							(string) ( $failure['title'] ?? '' ),
							(string) ( $failure['channel'] ?? '' ),
							'' !== trim( (string) ( $failure['answer'] ?? '' ) ) ? trim( (string) $failure['answer'] ) : __( 'no answer', 'estasoft-news-distribution' )
						);
					}
				}

				$model['paragraphs'][] = __( 'News Distribution has stopped trying. Sitemaps and feeds are not affected. You can send it again from the Delivery log.', 'estasoft-news-distribution' );
				$model['button']       = array(
					'label' => __( 'Open the Delivery log', 'estasoft-news-distribution' ),
					'url'   => Links::delivery_log(),
				);
				$model['note']         = __( 'You get at most one failure email an hour. If more pushes fail in that hour, they are added to the next email.', 'estasoft-news-distribution' );
				break;

			case self::TAKEDOWN:
				$by_name = $this->person_name( (int) ( $context['by'] ?? 0 ) );
				$todo    = array_values( array_filter( array_map( 'strval', (array) ( $context['todo'] ?? array() ) ) ) );
				$post_id = (int) ( $context['article_id'] ?? 0 );

				/* translators: %s: article title. */
				$model['subject'] = sprintf( __( 'A takedown needs you: %s', 'estasoft-news-distribution' ), $title );
				$model['lead']    = sprintf(
					/* translators: 1: the name of the person who took the article down, 2: number of steps left. */
					_n( '%1$s took down an article. News Distribution did its part. %2$d step still needs you.', '%1$s took down an article. News Distribution did its part. %2$d steps still need you.', max( 1, count( $todo ) ), 'estasoft-news-distribution' ),
					$by_name,
					count( $todo )
				);

				if ( '' !== trim( (string) ( $context['done'] ?? '' ) ) ) {
					$model['facts'][] = array(
						'label' => __( 'Done:', 'estasoft-news-distribution' ),
						'value' => trim( (string) $context['done'] ),
					);
				}

				$model['facts'][] = array(
					'label' => __( 'Yours to do:', 'estasoft-news-distribution' ),
					'value' => implode( ' ', $todo ),
				);
				$model['button']  = array(
					'label' => __( 'Open the takedown', 'estasoft-news-distribution' ),
					'url'   => Links::check( $post_id, 'takedown' ),
				);
				$model['note']    = __( 'You get this once, and one reminder 24 hours later if steps are still open.', 'estasoft-news-distribution' );
				break;

			case self::PLATFORM_SILENT:
				$platform = (string) ( $context['platform'] ?? '' );

				/* translators: %s: platform name. */
				$model['subject'] = sprintf( __( '%s has stopped fetching your feed', 'estasoft-news-distribution' ), $platform );
				$model['lead']    = sprintf(
					/* translators: 1: platform name, 2: number of hours. */
					_n( '%1$s has not asked for your feed for %2$d hour.', '%1$s has not asked for your feed for %2$d hours.', max( 1, (int) ( $context['hours'] ?? 24 ) ), 'estasoft-news-distribution' ),
					$platform,
					(int) ( $context['hours'] ?? 24 )
				);
				$model['paragraphs'][] = __( 'A fetch is counted only when the request reaches WordPress, so a cached feed can look quiet.', 'estasoft-news-distribution' );
				$model['button']       = array(
					'label' => __( 'Open Fetch monitor', 'estasoft-news-distribution' ),
					'url'   => Links::fetch_monitor(),
				);
				$model['note']         = __( 'You get this once for each quiet spell.', 'estasoft-news-distribution' );
				break;

			case self::ARTICLE_SKIPPED:
				$articles = (int) ( $context['articles'] ?? 0 );

				$model['subject'] = sprintf(
					/* translators: %d: number of articles. */
					_n( '%d article is skipped by a channel', '%d articles are skipped by a channel', max( 1, $articles ), 'estasoft-news-distribution' ),
					$articles
				);
				$model['lead'] = __( 'A channel will not take these articles as they are.', 'estasoft-news-distribution' );

				foreach ( (array) ( $context['reasons'] ?? array() ) as $reason ) {
					if ( ! is_array( $reason ) ) {
						continue;
					}

					$model['items'][] = sprintf(
						/* translators: 1: why the articles were skipped, 2: channel names, 3: number of articles. */
						__( '%1$s (%2$s): %3$d', 'estasoft-news-distribution' ),
						(string) ( $reason['reason'] ?? '' ),
						implode( ', ', array_map( 'strval', (array) ( $reason['channels'] ?? array() ) ) ),
						(int) ( $reason['count'] ?? 0 )
					);
				}

				$model['button'] = array(
					'label' => __( 'Open Article check', 'estasoft-news-distribution' ),
					'url'   => Links::check(),
				);
				$model['note']   = __( 'You get one summary a day, not one email per article.', 'estasoft-news-distribution' );
				break;

			case self::RULE_CHANGE:
				$platform = (string) ( $context['platform'] ?? '' );
				$articles = (int) ( $context['articles'] ?? 0 );

				$model['subject'] = __( 'A rule change affects your articles', 'estasoft-news-distribution' );
				$model['lead']    = sprintf(
					/* translators: 1: platform name, 2: number of articles. */
					_n( 'The %1$s rules changed, and %2$d of your recent articles is now affected.', 'The %1$s rules changed, and %2$d of your recent articles are now affected.', max( 1, $articles ), 'estasoft-news-distribution' ),
					$platform,
					$articles
				);

				if ( '' !== (string) ( $context['version'] ?? '' ) ) {
					$model['facts'][] = array(
						'label' => __( 'Rule pack:', 'estasoft-news-distribution' ),
						'value' => (string) $context['version'],
					);
				}

				$model['button'] = array(
					'label' => __( 'Open Platform rules', 'estasoft-news-distribution' ),
					'url'   => Links::settings( 'rules' ),
				);
				$model['note']   = __( 'You get this when a rule update changes what your recent articles need.', 'estasoft-news-distribution' );
				break;

			case self::AI_LIMIT:
				$model['subject']      = __( 'The AI assistant reached a limit', 'estasoft-news-distribution' );
				$model['lead']         = '' !== trim( (string) ( $context['message'] ?? '' ) ) ? trim( (string) $context['message'] ) : __( 'The AI assistant reached its usage limit.', 'estasoft-news-distribution' );
				$model['paragraphs'][] = __( 'Suggestions pause until the limit resets or you raise it.', 'estasoft-news-distribution' );
				$model['button']       = array(
					'label' => __( 'Open AI assistant settings', 'estasoft-news-distribution' ),
					'url'   => Links::settings( 'ai' ),
				);
				$model['note']         = __( 'This email holds no article text.', 'estasoft-news-distribution' );
				break;

			case self::TEST:
			default:
				/* translators: %s: the site name. */
				$model['subject'] = sprintf( __( 'Test email from News Distribution on %s', 'estasoft-news-distribution' ), $site );
				/* translators: %s: the site name. */
				$model['lead']         = sprintf( __( 'This is a test from News Distribution on %s.', 'estasoft-news-distribution' ), $site );
				$model['paragraphs'][] = __( 'If you can read this, your site’s mail setup delivered it. Nothing needs doing.', 'estasoft-news-distribution' );
				$model['note']         = __( 'This email holds no article text. Replies to this email are not read.', 'estasoft-news-distribution' );
		}

		return $model;
	}

	/**
	 * Makes the sendable parts of an email: the subject, an HTML body and a plain-text body.
	 *
	 * @param array<string, mixed> $model What `compose()` returned.
	 * @return array{subject: string, html: string, text: string}
	 */
	public function mail( array $model ): array {
		return array(
			'subject' => wp_strip_all_tags( (string) $model['subject'] ),
			'html'    => $this->html( $model ),
			'text'    => $this->text( $model ),
		);
	}

	/**
	 * The email as HTML: plain wording, no images, one button, inline styles because mail programs need them.
	 *
	 * @param array<string, mixed> $model What `compose()` returned.
	 * @return string
	 */
	public function html( array $model ): string {
		$dir   = is_rtl() ? 'rtl' : 'ltr';
		$inner = '<p style="margin:0 0 16px;font-size:16px;line-height:24px;">' . $this->isolate( esc_html( (string) $model['lead'] ) ) . '</p>';

		if ( array() !== $model['facts'] ) {
			$inner .= '<p style="margin:0 0 16px;line-height:22px;">';

			foreach ( $model['facts'] as $fact ) {
				$inner .= '<strong>' . esc_html( (string) $fact['label'] ) . '</strong> <bdi>' . esc_html( (string) $fact['value'] ) . '</bdi><br>';
			}

			$inner .= '</p>';
		}

		if ( array() !== $model['items'] ) {
			$inner .= '<ul style="margin:0 0 16px;padding-inline-start:20px;line-height:22px;">';

			foreach ( $model['items'] as $item ) {
				$inner .= '<li><bdi>' . esc_html( (string) $item ) . '</bdi></li>';
			}

			$inner .= '</ul>';
		}

		foreach ( $model['paragraphs'] as $paragraph ) {
			$inner .= '<p style="margin:0 0 16px;line-height:22px;">' . esc_html( (string) $paragraph ) . '</p>';
		}

		if ( is_array( $model['button'] ) ) {
			$inner .= '<p style="margin:0 0 20px;"><a href="' . esc_url( (string) $model['button']['url'] ) . '" style="display:inline-block;padding:10px 16px;border-radius:4px;background:#005ece;color:#ffffff;font-weight:600;text-decoration:none;">' . esc_html( (string) $model['button']['label'] ) . '</a></p>';
		}

		if ( '' !== (string) $model['note'] ) {
			$inner .= '<p style="margin:0;font-size:12px;line-height:18px;color:#505966;">' . esc_html( (string) $model['note'] ) . '</p>';
		}

		return $this->frame( $inner, $dir );
	}

	/**
	 * Wraps email content in the page every email shares.
	 *
	 * @param string $inner Escaped HTML.
	 * @param string $dir   ltr or rtl.
	 * @return string
	 */
	public function frame( string $inner, string $dir = 'ltr' ): string {
		return '<!doctype html><html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '" dir="' . esc_attr( $dir ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>'
			. '<body style="margin:0;padding:24px;background:#f4f6f8;font-family:-apple-system,\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:14px;color:#19212c;">'
			. '<div style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #dce0e4;border-radius:8px;padding:24px;">'
			. '<p style="margin:0 0 20px;font-size:14px;font-weight:600;color:#505966;">' . esc_html__( 'News Distribution', 'estasoft-news-distribution' ) . ' · <bdi>' . esc_html( $this->site_name() ) . '</bdi></p>'
			. $inner
			. '</div></body></html>';
	}

	/**
	 * The email as plain text.
	 *
	 * @param array<string, mixed> $model What `compose()` returned.
	 * @return string
	 */
	public function text( array $model ): string {
		$lines = array( (string) $model['lead'], '' );

		foreach ( $model['facts'] as $fact ) {
			$lines[] = $fact['label'] . ' ' . $fact['value'];
		}

		if ( array() !== $model['facts'] ) {
			$lines[] = '';
		}

		foreach ( $model['items'] as $item ) {
			$lines[] = '- ' . $item;
		}

		if ( array() !== $model['items'] ) {
			$lines[] = '';
		}

		foreach ( $model['paragraphs'] as $paragraph ) {
			$lines[] = $paragraph;
			$lines[] = '';
		}

		if ( is_array( $model['button'] ) ) {
			$lines[] = $model['button']['label'] . ': ' . $model['button']['url'];
			$lines[] = '';
		}

		if ( '' !== (string) $model['note'] ) {
			$lines[] = $model['note'];
		}

		return wp_specialchars_decode( trim( implode( "\n", array_map( 'strval', $lines ) ) ), ENT_QUOTES );
	}

	/**
	 * Sample contexts for the Emails screen, so the screen shows what the real email holds.
	 *
	 * @param string $type fix_assigned, push_failed or takedown.
	 * @return array<string, mixed>
	 */
	public function sample_context( string $type ): array {
		$me   = get_current_user_id();
		$time = Clock::now();

		switch ( $type ) {
			case self::FIX_ASSIGNED:
				return array(
					'to_user'    => $me,
					'by'         => $me,
					'article_id' => 0,
					'title'      => __( 'Ferry service to the island starts next week', 'estasoft-news-distribution' ),
					'fix'        => __( 'Add alt text to the lead image', 'estasoft-news-distribution' ),
					'due'        => $time + ( 3 * HOUR_IN_SECONDS ),
				);

			case self::PUSH_FAILED:
				return array(
					'failures' => array(
						array(
							'channel'    => __( 'Newsletter tool webhook', 'estasoft-news-distribution' ),
							'article_id' => 0,
							'title'      => __( 'Ferry service to the island starts next week', 'estasoft-news-distribution' ),
							'tries'      => Retry::MAX_ATTEMPTS,
							'first_at'   => $time - ( 21 * MINUTE_IN_SECONDS ),
							'last_at'    => $time,
							'answer'     => '',
						),
					),
				);

			default:
				return array(
					'by'         => $me,
					'article_id' => 0,
					'title'      => __( 'Weather warning for the coast', 'estasoft-news-distribution' ),
					'done'       => __( 'removed from your sitemaps and feeds, and the platforms that listen were told.', 'estasoft-news-distribution' ),
					'todo'       => array( __( 'delete the story on Medium, if you imported it there. News Distribution cannot delete it for you.', 'estasoft-news-distribution' ) ),
				);
		}
	}

	/**
	 * The publication name used in emails.
	 *
	 * @return string
	 */
	public function site_name(): string {
		return wp_specialchars_decode( $this->plugin->settings()->publication_name(), ENT_QUOTES );
	}

	/**
	 * A result array.
	 *
	 * @param string   $status Status word.
	 * @param string[] $to     Addresses that were sent to.
	 * @param string   $error  Why it failed.
	 * @return array{status: string, sent: int, to: string[], error: string}
	 */
	private function result( string $status, array $to = array(), string $error = '' ): array {
		return array(
			'status' => $status,
			'sent'   => count( $to ),
			'to'     => $to,
			'error'  => $error,
		);
	}

	/**
	 * The article title for an email.
	 *
	 * @param array<string, mixed> $context The context.
	 * @return string
	 */
	private function article_title( array $context ): string {
		$title = trim( (string) ( $context['title'] ?? '' ) );

		if ( '' === $title && ! empty( $context['article_id'] ) ) {
			$title = wp_strip_all_tags( get_the_title( (int) $context['article_id'] ) );
		}

		return $title;
	}

	/**
	 * A person's name for an email.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function person_name( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;

		return $user instanceof \WP_User ? (string) $user->display_name : __( 'A colleague', 'estasoft-news-distribution' );
	}

	/**
	 * Marks text that may run in another direction, so an Arabic or Hebrew title keeps its order in a left-to-right email.
	 *
	 * @param string $html Escaped HTML.
	 * @return string
	 */
	private function isolate( string $html ): string {
		return '<bdi>' . $html . '</bdi>';
	}
}

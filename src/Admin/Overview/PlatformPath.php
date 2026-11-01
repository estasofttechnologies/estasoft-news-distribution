<?php
/**
 * The path to each platform: what is done, what a platform decides, what to do next.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Overview;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Core\Applications;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Counts steps from what is stored: which channels are on, what the person ticked
 * on a channel page, what the fetch log saw and what the person recorded in the
 * application tracker. A platform's own answer cannot be seen, so a step that the
 * platform decides is only done when the person says so ("Listed").
 *
 * A platform counts once at least one step is done or its application is no longer
 * "Not requested". It is live when an application is Listed, or, for a platform with
 * no application, when its channel is Healthy. The card stays until every platform
 * that was started is live; platforms that were not started do not count.
 */
final class PlatformPath {

	public const WORKS    = 'works';
	public const DECISION = 'decision';
	public const LISTED   = 'listed';

	/**
	 * Platform id => the channel that carries it, the section and the steps.
	 *
	 * Steps: ready (the channel is on), account and verified (the person ticked them on the
	 * Baidu page), submitted (the person ticked it, or the platform already fetched), fetched (every language fetched at least once), applied (an
	 * application is recorded), listed (the application is Listed).
	 *
	 * @var array<string, array{channel: string, section: string, steps: string[]}>
	 */
	private const PLATFORMS = array(
		'google-news'     => array(
			'channel' => 'google-news',
			'section' => self::WORKS,
			'steps'   => array( 'ready', 'submitted', 'fetched' ),
		),
		'bing'            => array(
			'channel' => 'bing',
			'section' => self::WORKS,
			'steps'   => array( 'ready', 'submitted', 'fetched' ),
		),
		'yandex'          => array(
			'channel' => 'yandex',
			'section' => self::WORKS,
			'steps'   => array( 'ready', 'submitted', 'fetched' ),
		),
		'baidu'           => array(
			'channel' => 'baidu',
			'section' => self::WORKS,
			'steps'   => array( 'ready', 'account', 'verified', 'submitted' ),
		),
		'flipboard'       => array(
			'channel' => 'flipboard',
			'section' => self::WORKS,
			'steps'   => array( 'ready' ),
		),
		'feed-readers'    => array(
			'channel' => 'feed-readers',
			'section' => self::WORKS,
			'steps'   => array( 'ready' ),
		),
		'microsoft-start' => array(
			'channel' => 'microsoft-start',
			'section' => self::DECISION,
			'steps'   => array( 'ready', 'applied', 'listed', 'fetched' ),
		),
		'smartnews'       => array(
			'channel' => 'smartnews',
			'section' => self::DECISION,
			'steps'   => array( 'ready', 'applied', 'listed' ),
		),
		'apple-news'      => array(
			'channel' => 'apple-news',
			'section' => self::DECISION,
			'steps'   => array( 'ready', 'applied', 'listed' ),
		),
		'news-break'      => array(
			'channel' => 'news-break',
			'section' => self::DECISION,
			'steps'   => array( 'ready', 'applied', 'listed' ),
		),
		'yahoo-news'      => array(
			'channel' => 'yahoo-news',
			'section' => self::DECISION,
			'steps'   => array( 'ready', 'applied', 'listed' ),
		),
		'ground-news'     => array(
			'channel' => 'listings',
			'section' => self::LISTED,
			'steps'   => array( 'applied', 'listed' ),
		),
		'particle'        => array(
			'channel' => 'listings',
			'section' => self::LISTED,
			'steps'   => array( 'applied', 'listed' ),
		),
	);

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The section titles and one line each, in the order the card prints them.
	 *
	 * @return array<string, string>
	 */
	public static function sections(): array {
		return array(
			self::WORKS    => __( 'Works without approval', 'estasoft-news-distribution' ),
			self::DECISION => __( 'Needs a decision from the platform', 'estasoft-news-distribution' ),
			self::LISTED   => __( 'Ask to be listed', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * Every platform with its steps, grouped in sections.
	 *
	 * @return array{sections: array<int, array{id: string, title: string, rows: array<int, array<string, mixed>>}>, started: int, live: int, all_live: bool}
	 */
	public function build(): array {
		$rows = array();

		foreach ( self::PLATFORMS as $platform => $definition ) {
			$row = $this->row( (string) $platform, $definition );

			if ( null !== $row ) {
				$rows[ $definition['section'] ][] = $row;
			}
		}

		$started  = 0;
		$live     = 0;
		$sections = array();
		$primary  = false;

		foreach ( self::sections() as $id => $title ) {
			$list = $rows[ $id ] ?? array();

			if ( array() === $list ) {
				continue;
			}

			if ( self::WORKS !== $id ) {
				// Platforms that were started first, in the order they are listed.
				usort(
					$list,
					static function ( array $a, array $b ): int {
						return (int) $b['started'] <=> (int) $a['started'];
					}
				);
			}

			foreach ( $list as $index => $row ) {
				$started += $row['started'] ? 1 : 0;
				$live    += $row['started'] && $row['live'] ? 1 : 0;

				if ( ! $primary && $row['started'] && Status::ATTENTION === $row['health'] ) {
					$list[ $index ]['action']['primary'] = true;
					$list[ $index ]['action']['label']   = __( 'Continue', 'estasoft-news-distribution' );
					$primary                             = true;
				}
			}

			$sections[] = array(
				'id'    => $id,
				'title' => $title,
				'rows'  => $list,
			);
		}

		return array(
			'sections' => $sections,
			'started'  => $started,
			'live'     => $live,
			'all_live' => $started > 0 && $live === $started,
		);
	}

	/**
	 * Reminders that fall in the next two weeks, or that have already passed, on open applications.
	 *
	 * @return array<int, array{platform: string, name: string, date: string, overdue: bool}>
	 */
	public function reminders(): array {
		$out          = array();
		$applications = $this->plugin->applications();
		$until        = Clock::now() + 14 * DAY_IN_SECONDS;

		foreach ( Applications::platforms() as $platform => $name ) {
			$row = $applications->get( (string) $platform );

			if ( ! in_array( $row['status'], array( Status::REQUESTED, Status::IN_REVIEW ), true ) || '' === $row['reminder_on'] ) {
				continue;
			}

			$due = date_create_immutable( $row['reminder_on'] . ' 23:59:59', Clock::zone() );

			if ( false === $due || $due->getTimestamp() > $until ) {
				continue;
			}

			$out[] = array(
				'platform' => (string) $platform,
				'name'     => (string) $name,
				'date'     => $row['reminder_on'],
				'overdue'  => $applications->is_overdue( (string) $platform ),
			);
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				return strcmp( $a['date'], $b['date'] );
			}
		);

		return $out;
	}

	/**
	 * One platform's row, or null when this site has no channel for it.
	 *
	 * @param string               $platform   Platform id.
	 * @param array<string, mixed> $definition Its channel, section and steps.
	 * @return array<string, mixed>|null
	 */
	private function row( string $platform, array $definition ): ?array {
		$channel = $this->plugin->registry()->get( (string) $definition['channel'] );

		if ( null === $channel ) {
			return null;
		}

		$applications = Applications::platforms();
		$has_app      = isset( $applications[ $platform ] );
		$name         = $has_app ? (string) $applications[ $platform ] : $channel->label();
		$app          = $has_app ? $this->plugin->applications()->get( $platform ) : null;
		$states       = ChannelInterface::MODE_PULLED === $channel->mode() && $channel->is_enabled() ? Facts::fetch_states( $this->plugin, $channel ) : array();
		$done         = 0;

		foreach ( (array) $definition['steps'] as $step ) {
			$done += $this->is_done( (string) $step, $channel, $app, $states ) ? 1 : 0;
		}

		$total   = count( (array) $definition['steps'] );
		$health  = 'listings' === $channel->id() ? Status::NOT_SET_UP : $channel->health();
		$applied = null !== $app && Status::NOT_REQUESTED !== $app['status'];
		$started = $done > 0 || $applied;
		$live    = $has_app ? ( null !== $app && Status::LISTED === $app['status'] ) : Status::HEALTHY === $health;

		return array(
			'id'      => $platform,
			'name'    => $name,
			'channel' => $channel->id(),
			'section' => (string) $definition['section'],
			'done'    => $done,
			'total'   => $total,
			'started' => $started,
			'live'    => $live,
			'health'  => $health,
			'status'  => null === $app ? $health : $this->plugin->applications()->display_status( $platform ),
			'text'    => $has_app ? $this->application_text( $platform, $name, (array) $app, $channel, $states ) : $this->channel_text( $channel, $states ),
			'action'  => array(
				'label'   => $started ? __( 'Open', 'estasoft-news-distribution' ) : __( 'Start', 'estasoft-news-distribution' ),
				'url'     => Facts::channel_url( $this->plugin, $channel->id() ),
				'primary' => false,
			),
		);
	}

	/**
	 * Whether one step is done.
	 *
	 * @param string                                             $step    Step name.
	 * @param ChannelInterface                                   $channel The channel.
	 * @param array<string, string>|null                         $app     The application record, or null for a platform with none.
	 * @param array<string, array{status: string, last_ts: int}> $states  Fetch state per language.
	 * @return bool
	 */
	private function is_done( string $step, ChannelInterface $channel, ?array $app, array $states ): bool {
		switch ( $step ) {
			case 'ready':
				return $channel->is_enabled();

			case 'account':
			case 'verified':
				// The channel's own fields are passed: without them a tick the channel defines is not read back.
				$settings = $this->plugin->channel_settings()->get( $channel->id(), $channel->fields() );

				return ! empty( $settings[ $step ] );

			case 'submitted':
				$settings = $this->plugin->channel_settings()->get( $channel->id(), $channel->fields() );

				return ! empty( $settings['submitted'] ) || $this->has_fetched( $states, false );

			case 'fetched':
				return $this->has_fetched( $states, true );

			case 'applied':
				return null !== $app && Status::NOT_REQUESTED !== $app['status'];

			case 'listed':
				return null !== $app && Status::LISTED === $app['status'];
		}

		return false;
	}

	/**
	 * Whether the fetch log saw the platform fetch.
	 *
	 * @param array<string, array{status: string, last_ts: int}> $states Fetch state per language.
	 * @param bool                                               $every  True when every language needs a fetch, false when one is enough.
	 * @return bool
	 */
	private function has_fetched( array $states, bool $every ): bool {
		if ( array() === $states ) {
			return false;
		}

		$seen = 0;

		foreach ( $states as $state ) {
			if ( Status::NEVER !== $state['status'] ) {
				++$seen;
			}
		}

		return $every ? count( $states ) === $seen : $seen > 0;
	}

	/**
	 * The line under a platform with no application: what the fetches look like, or what the channel says.
	 *
	 * @param ChannelInterface                                   $channel The channel.
	 * @param array<string, array{status: string, last_ts: int}> $states  Fetch state per language.
	 * @return string
	 */
	private function channel_text( ChannelInterface $channel, array $states ): string {
		if ( ! $channel->is_enabled() ) {
			$rows = $channel->hub_rows();
			$note = method_exists( $channel, 'path_note' ) ? (string) $channel->path_note() : '';

			return trim(
				sprintf(
					/* translators: 1: a status such as "Not set up", 2: what the channel says about itself. */
					__( '%1$s. %2$s', 'estasoft-news-distribution' ),
					Status::word( Status::NOT_SET_UP ),
					'' !== $note ? $note : ( array() === $rows ? '' : (string) $rows[0]['summary'] )
				)
			);
		}

		if ( array() !== $states ) {
			return $this->fetch_sentence( $states );
		}

		$rows = $channel->hub_rows();

		return array() === $rows ? '' : (string) $rows[0]['summary'];
	}

	/**
	 * A sentence about the fetches: one clause per language.
	 *
	 * @param array<string, array{status: string, last_ts: int}> $states Fetch state per language.
	 * @return string
	 */
	private function fetch_sentence( array $states ): string {
		$words = array();

		foreach ( $states as $state ) {
			$words[] = Status::NEVER === $state['status'] ? '' : Facts::ago( $state['last_ts'] );
		}

		// Every language looks the same: say it once.
		if ( 1 === count( array_unique( $words ) ) ) {
			if ( '' === $words[0] ) {
				return __( 'Not fetched yet.', 'estasoft-news-distribution' );
			}

			/* translators: %s: how long ago, such as "31 min ago". */
			return sprintf( __( 'Fetched %s.', 'estasoft-news-distribution' ), $words[0] );
		}

		$parts = array();

		foreach ( $states as $code => $state ) {
			$name = Catalog::name( (string) $code );

			if ( Status::NEVER === $state['status'] ) {
				/* translators: %s: a language name such as Kurmanji. */
				$parts[] = sprintf( __( '%s not fetched yet.', 'estasoft-news-distribution' ), $name );
			} else {
				/* translators: 1: a language name such as English, 2: how long ago, such as "6 min ago". */
				$parts[] = sprintf( __( '%1$s fetched %2$s.', 'estasoft-news-distribution' ), $name, Facts::ago( $state['last_ts'] ) );
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * The line under a platform with an application: the status you set, and what you can do.
	 *
	 * @param string                                             $platform Platform id.
	 * @param string                                             $name     Platform name.
	 * @param array<string, string>                              $app      The application record.
	 * @param ChannelInterface                                   $channel  The channel.
	 * @param array<string, array{status: string, last_ts: int}> $states   Fetch state per language.
	 * @return string
	 */
	private function application_text( string $platform, string $name, array $app, ChannelInterface $channel, array $states ): string {
		$parts = array( Status::word( $app['status'] ) . '.' );
		$day   = '' === $app['applied_on'] ? '' : wp_date( 'j M', (int) strtotime( $app['applied_on'] . ' 12:00:00' ), Clock::zone() );

		switch ( $app['status'] ) {
			case Status::REQUESTED:
			case Status::IN_REVIEW:
				if ( '' !== $day ) {
					/* translators: %s: a date such as "5 Oct". */
					$parts[] = sprintf( __( 'Request sent %s.', 'estasoft-news-distribution' ), $day );
				}

				/* translators: %s: a platform name such as SmartNews. */
				$parts[] = sprintf( __( '%s decides, and News Distribution cannot see the answer.', 'estasoft-news-distribution' ), $name );
				break;

			case Status::LISTED:
				if ( '' !== $day ) {
					/* translators: %s: a date such as "12 Sep". */
					$parts[] = sprintf( __( 'Applied %s.', 'estasoft-news-distribution' ), $day );
				}

				if ( array() !== $states ) {
					$parts[] = $this->fetch_sentence( $states );
				}
				break;

			case Status::DECLINED:
				$parts[] = __( 'You set this status by hand.', 'estasoft-news-distribution' );
				break;

			default:
				$hint = $this->hint( $platform, $channel );

				if ( '' !== $hint ) {
					$parts[] = $hint;
				}
		}

		if ( $this->plugin->applications()->is_overdue( $platform ) ) {
			$parts[] = __( 'The reminder date has passed.', 'estasoft-news-distribution' );
		} elseif ( '' !== $app['reminder_on'] && in_array( $app['status'], array( Status::REQUESTED, Status::IN_REVIEW ), true ) ) {
			$parts[] = sprintf(
				/* translators: %s: a date such as "28 Oct". */
				__( 'Reminder on %s.', 'estasoft-news-distribution' ),
				wp_date( 'j M', (int) strtotime( $app['reminder_on'] . ' 12:00:00' ), Clock::zone() )
			);
		}

		return implode( ' ', $parts );
	}

	/**
	 * What to do before applying.
	 *
	 * @param string           $platform Platform id.
	 * @param ChannelInterface $channel  The channel.
	 * @return string
	 */
	private function hint( string $platform, ChannelInterface $channel ): string {
		if ( 'listings' === $channel->id() ) {
			return __( 'Find a contact before you ask.', 'estasoft-news-distribution' );
		}

		if ( 'news-break' === $platform ) {
			return __( 'Read the publisher terms first.', 'estasoft-news-distribution' );
		}

		return __( 'Apply when you want to.', 'estasoft-news-distribution' );
	}
}

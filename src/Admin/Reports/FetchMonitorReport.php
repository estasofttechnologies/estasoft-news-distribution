<?php
/**
 * Fetch monitor: when each platform last read your feeds, and whether it stopped.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports;

use Estasoft\NewsDistribution\Admin\Reports\Parts\FetchStats;
use Estasoft\NewsDistribution\Admin\Reports\Parts\View;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Core\Applications;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the fetch log, which holds only requests that reach WordPress. A feed
 * that a CDN or page cache serves is not counted, and the screen says so.
 *
 * A platform is "on time" when its last fetch is no older than twice the interval
 * in its rule pack. That is the plugin's own yardstick, the same one the channel
 * pages use for health; it is not a rule of the platform.
 */
final class FetchMonitorReport extends AbstractReport {

	/**
	 * Crawler names the fetch log keeps, as people know them.
	 *
	 * @var array<string, string>
	 */
	private const CRAWLERS = array(
		'googlebot'       => 'Googlebot',
		'googlebot-news'  => 'Googlebot-News',
		'bingbot'         => 'Bingbot',
		'yandex'          => 'YandexBot',
		'baidu'           => 'Baiduspider',
		'applebot'        => 'Applebot',
		'smartnews'       => 'SmartNews',
		'flipboard'       => 'Flipboard',
		'yahoo'           => 'Yahoo',
		'newsbreak'       => 'NewsBreak',
		'microsoft-start' => 'Microsoft Start',
		'feedly'          => 'Feedly',
		'inoreader'       => 'Inoreader',
		'newsblur'        => 'NewsBlur',
	);

	/**
	 * Crawlers of channels that have no rule pack.
	 *
	 * @var array<string, string[]>
	 */
	private const FALLBACK_AGENTS = array(
		'feed-readers' => array( 'feedly', 'inoreader', 'newsblur' ),
	);

	public function name(): string {
		return 'fetch-monitor';
	}

	public function title(): string {
		return __( 'Fetch monitor', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'When each platform last read your feeds, and whether it stopped.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'platforms';
	}

	public function order(): int {
		return 10;
	}

	public function enqueue(): void {
	}

	/**
	 * The figures, for the screen and for `GET /reports/fetch-monitor`.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$now      = Clock::now();
		$platform = array();
		$summary  = array();

		foreach ( $this->rows( $now ) as $row ) {
			$summary[ $row['status'] ] = ( $summary[ $row['status'] ] ?? 0 ) + 1;
			$platform[]                = $this->export( $row );
		}

		return array(
			'generated_at' => Clock::iso( $now ),
			'window_hours' => 24,
			'days'         => FetchStats::DAYS,
			'counts_only'  => 'requests_reaching_wordpress',
			'platforms'    => $platform,
			'summary'      => $summary,
		);
	}

	/**
	 * One row as the REST route returns it: times as ISO strings.
	 *
	 * @param array<string, mixed> $row A row from rows().
	 * @return array<string, mixed>
	 */
	private function export( array $row ): array {
		$out = array(
			'id'               => $row['id'],
			'label'            => $row['label'],
			'group'            => $row['group'],
			'enabled'          => $row['enabled'],
			'status'           => $row['status'],
			'crawlers'         => $row['crawlers'],
			'last_fetch'       => $row['last_ts'] > 0 ? Clock::iso( $row['last_ts'] ) : null,
			'fetches_24h'      => $row['fetches_24h'],
			'per_day'          => $row['days'],
			'average_ms'       => $row['average_ms'],
			'short_replies'    => $row['short_24h'],
			'items'            => $row['items'],
			'skipped'          => $row['skipped'],
			'application'      => $row['application'],
			'waiting_since'    => $row['waiting_since'] > 0 ? Clock::iso( $row['waiting_since'] ) : null,
			'first_fetch_days' => $row['first_fetch_days'],
			'languages'        => array(),
		);

		foreach ( $row['languages'] as $language ) {
			$out['languages'][] = array(
				'language'      => $language['language'],
				'status'        => $language['status'],
				'last_fetch'    => $language['last_ts'] > 0 ? Clock::iso( $language['last_ts'] ) : null,
				'fetches_24h'   => $language['fetches_24h'],
				'per_day'       => $language['days'],
				'average_ms'    => $language['average_ms'],
				'short_replies' => $language['short_24h'],
			);
		}

		return $out;
	}

	/**
	 * Every platform that is fetched from an address on this site, with its figures.
	 *
	 * @param int $now Unix time to count from.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( int $now ): array {
		$groups = array_flip( array( Group::SITEMAP, Group::PARTNER, Group::READERS ) );
		$list   = array();

		foreach ( $this->plugin->registry()->all() as $channel ) {
			if ( ChannelInterface::MODE_PULLED !== $channel->mode() || '' === $channel->feed_slug() ) {
				continue;
			}

			$list[] = $channel;
		}

		usort(
			$list,
			static function ( ChannelInterface $a, ChannelInterface $b ) use ( $groups ): int {
				$by_group = ( $groups[ $a->group() ] ?? 9 ) <=> ( $groups[ $b->group() ] ?? 9 );

				return 0 !== $by_group ? $by_group : $a->order() <=> $b->order();
			}
		);

		$rows = array();

		foreach ( $list as $channel ) {
			$rows[] = $this->row( $channel, $now );
		}

		return $rows;
	}

	/**
	 * The crawler names that count as this platform fetching.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return string[]
	 */
	private function agents( ChannelInterface $channel ): array {
		$agents = (array) ( $this->plugin->rules()->pack( $channel->id() )['fetch']['agents'] ?? array() );
		$agents = array_values( array_filter( array_map( 'strval', $agents ) ) );

		return array() === $agents ? ( self::FALLBACK_AGENTS[ $channel->id() ] ?? array() ) : $agents;
	}

	/**
	 * The languages the channel carries, in the order the site lists them.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return string[]
	 */
	private function languages( ChannelInterface $channel ): array {
		$out = array();

		foreach ( $this->plugin->languages()->languages() as $language ) {
			if ( $channel->accepts_language( (string) $language ) ) {
				$out[] = (string) $language;
			}
		}

		return $out;
	}

	/**
	 * What a platform's fetches look like: on time, later than usual, not fetched yet, never.
	 *
	 * @param ChannelInterface $channel  The channel.
	 * @param int              $last     Unix time of the last fetch, or 0.
	 * @param int              $interval Hours between fetches that the rule pack gives.
	 * @param string           $applied  The application status code, or an empty string.
	 * @param int              $now      Unix time to count from.
	 * @return string A Core\Status code.
	 */
	private function state( ChannelInterface $channel, int $last, int $interval, string $applied, int $now ): string {
		if ( ! $channel->is_enabled() ) {
			return Status::NOT_SET_UP;
		}

		if ( $last > 0 ) {
			return $now - $last > 2 * max( 1, $interval ) * HOUR_IN_SECONDS ? Status::LATE : Status::ON_TIME;
		}

		// A search engine reads a new sitemap on its own time: the channel says it is still processing.
		if ( Status::PROCESSING === $channel->health() ) {
			return Status::PROCESSING;
		}

		// A partner has not been told yet, or has not approved: nothing is expected, so it reads "Never".
		if ( Group::PARTNER === $channel->group() && '' !== $applied && Status::LISTED !== $applied ) {
			return Status::NEVER;
		}

		return Status::NOT_FETCHED;
	}

	/**
	 * One platform's row.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @param int              $now     Unix time to count from.
	 * @return array<string, mixed>
	 */
	private function row( ChannelInterface $channel, int $now ): array {
		$id       = $channel->id();
		$agents   = $this->agents( $channel );
		$interval = $this->plugin->rules()->fetch_interval_hours( $id );
		$carried  = $this->languages( $channel );
		$ids      = array_map(
			static function ( ChannelInterface $other ): string {
				return $other->id();
			},
			$this->plugin->registry()->serving( $channel->feed_slug() )
		);
		$stats    = FetchStats::by_language( array_unique( array_merge( array( $id ), $ids ) ), $agents, $now );
		$total    = FetchStats::sum( array_values( $stats ) );
		$applied  = array_key_exists( $id, Applications::platforms() ) ? $this->plugin->applications()->get( $id ) : array();
		$status   = '' === (string) ( $applied['status'] ?? '' ) ? '' : (string) $applied['status'];
		$overall  = $this->state( $channel, $total['last_ts'], $interval, $status, $now );

		$languages = array();
		$states    = array();

		foreach ( $carried as $language ) {
			$figures = $stats[ $language ] ?? FetchStats::blank();
			$state   = $this->state( $channel, $figures['last_ts'], $interval, $status, $now );

			$states[]    = $state;
			$languages[] = array_merge(
				$figures,
				array(
					'language' => $language,
					'status'   => $state,
				)
			);
		}

		// A platform's languages are listed under it only when they differ, so a problem can be found.
		if ( count( array_unique( $states ) ) > 1 ) {
			$overall = $this->worst( $states );
		} else {
			$languages = array();
		}

		$items   = null;
		$skipped = null;

		if ( $channel->is_enabled() && Group::SITEMAP !== $channel->group() ) {
			$count   = $this->feed_items( $channel, $carried, $now );
			$items   = $count['items'];
			$skipped = $count['skipped'];
		}

		return array_merge(
			$total,
			array(
				'id'               => $id,
				'label'            => $channel->label(),
				'group'            => $channel->group(),
				'enabled'          => $channel->is_enabled(),
				'status'           => $overall,
				'crawlers'         => $agents,
				'languages'        => $languages,
				'carried'          => $carried,
				'items'            => $items,
				'skipped'          => $skipped,
				'application'      => '' === $status ? null : $status,
				'applied_on'       => (string) ( $applied['applied_on'] ?? '' ),
				'waiting_since'    => method_exists( $channel, 'waiting_since' ) ? (int) $channel->waiting_since() : 0,
				'first_fetch_days' => method_exists( $channel, 'first_fetch_days' ) ? (int) $channel->first_fetch_days() : null,
			)
		);
	}

	/**
	 * The worst of several fetch states: later than usual, then not fetched, then on time.
	 *
	 * @param string[] $states Core\Status codes.
	 * @return string
	 */
	private function worst( array $states ): string {
		foreach ( array( Status::LATE, Status::NOT_FETCHED, Status::NEVER, Status::PROCESSING, Status::ON_TIME ) as $code ) {
			if ( in_array( $code, $states, true ) ) {
				return $code;
			}
		}

		return Status::NOT_FETCHED;
	}

	/**
	 * How many items the feed holds now and how many articles it leaves out.
	 *
	 * It reads the articles of the feed window in each language, as the feed does, and
	 * keeps the count for five minutes.
	 *
	 * @param ChannelInterface $channel   The channel.
	 * @param string[]         $languages Languages the feed carries.
	 * @param int              $now       Unix time to count from.
	 * @return array{items: int, skipped: int}
	 */
	private function feed_items( ChannelInterface $channel, array $languages, int $now ): array {
		return $this->plugin->cache()->remember(
			'report_feed_items_' . $channel->id() . '_' . implode( '-', $languages ),
			5 * MINUTE_IN_SECONDS,
			function () use ( $channel, $languages, $now ): array {
				$window  = $channel->feed_window();
				$items   = 0;
				$skipped = 0;

				foreach ( $languages as $language ) {
					$articles = $this->plugin->reader()->recent( $language, (int) $window['max_age'], (int) $window['limit'] );

					/** This filter is documented in Frontend/FeedController.php. */
					$kept     = apply_filters( 'estasoft_nd_internal_feed_articles', $articles, $channel, $language );
					$articles = is_array( $kept ) ? array_values( $kept ) : $articles;
					$entries  = method_exists( $channel, 'entries' ) ? (array) $channel->entries( $articles, $now ) : $articles;

					$items   += count( $entries );
					$skipped += max( 0, count( $articles ) - count( $entries ) );
				}

				return array(
					'items'   => $items,
					'skipped' => $skipped,
				);
			}
		);
	}

	/**
	 * The line under a platform's name.
	 *
	 * @param array<string, mixed> $row A row from rows().
	 * @param int                  $now Unix time to count from.
	 * @return string
	 */
	private function detail( array $row, int $now ): string {
		$noun    = Group::SITEMAP === $row['group'] ? __( 'Sitemap', 'estasoft-news-distribution' ) : __( 'Feed', 'estasoft-news-distribution' );
		$names   = $this->crawler_names( $row['crawlers'] );
		$parts   = array();
		$count   = count( $row['carried'] );
		$applied = (string) ( $row['application'] ?? '' );

		if ( ! $row['enabled'] ) {
			return sprintf(
				/* translators: %s: "Sitemap" or "Feed". */
				__( '%s is not turned on.', 'estasoft-news-distribution' ),
				$noun
			);
		}

		if ( Group::SITEMAP === $row['group'] ) {
			if ( array() !== $row['languages'] ) {
				$parts[] = sprintf(
					/* translators: 1: crawler name, 2: number of sitemaps. */
					__( '%1$s, per language. Shows the worst of the %2$s sitemaps below.', 'estasoft-news-distribution' ),
					$names,
					number_format_i18n( $count )
				);
			} else {
				$parts[] = sprintf(
					/* translators: 1: crawler name, 2: number of sitemaps. */
					_n( '%1$s, %2$s sitemap.', '%1$s, %2$s sitemaps.', max( 1, $count ), 'estasoft-news-distribution' ),
					$names,
					number_format_i18n( max( 1, $count ) )
				);
			}

			if ( Status::PROCESSING === $row['status'] && (int) $row['waiting_since'] > 0 ) {
				$parts[] = sprintf(
					/* translators: %s: a date such as "14 Oct". */
					__( 'Sitemap added %s.', 'estasoft-news-distribution' ),
					Clock::format( (int) $row['waiting_since'], 'j M' )
				);

				if ( (int) $row['first_fetch_days'] > 0 ) {
					$parts[] = sprintf(
						/* translators: %s: number of days. */
						_n( 'Allow up to %s day for the first fetch.', 'Allow up to %s days for the first fetch.', (int) $row['first_fetch_days'], 'estasoft-news-distribution' ),
						number_format_i18n( (int) $row['first_fetch_days'] )
					);
				}
			}

			return implode( ' ', $parts );
		}

		if ( null !== $row['items'] ) {
			$parts[] = (int) $row['skipped'] > 0
				? sprintf(
					/* translators: 1: items in the feed, 2: articles left out. */
					_n( 'Feed: %1$s item, %2$s skipped.', 'Feed: %1$s items, %2$s skipped.', (int) $row['items'], 'estasoft-news-distribution' ),
					number_format_i18n( (int) $row['items'] ),
					number_format_i18n( (int) $row['skipped'] )
				)
				: sprintf(
					/* translators: %s: items in the feed. */
					_n( 'Feed: %s item.', 'Feed: %s items.', (int) $row['items'], 'estasoft-news-distribution' ),
					number_format_i18n( (int) $row['items'] )
				);
		}

		if ( '' !== $applied && Status::LISTED !== $applied ) {
			$since = Applications::show_date( (string) $row['applied_on'] );

			if ( Status::NOT_REQUESTED === $applied ) {
				$parts[] = __( 'You have not applied yet.', 'estasoft-news-distribution' );
			} elseif ( '' !== $since ) {
				$parts[] = sprintf(
					/* translators: 1: application status such as "In review", 2: a date such as "5 Oct 2026". */
					__( '%1$s since %2$s.', 'estasoft-news-distribution' ),
					Status::word( $applied ),
					$since
				);
			} else {
				$parts[] = Status::word( $applied ) . '.';
			}

			if ( (int) $row['last_ts'] < 1 && '' !== $names ) {
				$parts[] = sprintf(
					/* translators: %s: crawler name. */
					__( 'No fetch from %s recorded.', 'estasoft-news-distribution' ),
					$names
				);
			}
		}

		if ( Group::READERS === $row['group'] && 'feed-readers' === $row['id'] ) {
			$parts[] = sprintf(
				/* translators: %s: crawler names such as "Feedly, Inoreader, NewsBlur". */
				__( '%s fetch on behalf of their users.', 'estasoft-news-distribution' ),
				$names
			);
		}

		unset( $now );

		return implode( ' ', $parts );
	}

	/**
	 * Crawler names for a line of text.
	 *
	 * @param string[] $agents Names from the fetch log.
	 * @return string
	 */
	private function crawler_names( array $agents ): string {
		$names = array();

		foreach ( $agents as $agent ) {
			$names[ $agent ] = self::CRAWLERS[ $agent ] ?? ucfirst( $agent );
		}

		// Googlebot-News is read as Googlebot.
		if ( isset( $names['googlebot'], $names['googlebot-news'] ) ) {
			unset( $names['googlebot-news'] );
		}

		return implode( ', ', array_values( $names ) );
	}

	/**
	 * The cells of one row.
	 *
	 * @param array<string, mixed> $row     The figures.
	 * @param string               $name    Name to print.
	 * @param string               $detail  Line under the name.
	 * @param bool                 $nested  Whether this is a language under its platform.
	 * @param int                  $now     Unix time to count from.
	 * @return array<int, string>
	 */
	private function cells( array $row, string $name, string $detail, bool $nested, int $now ): array {
		$label = Html::tag(
			'div',
			array( 'class' => $nested ? 'estasoft-nd-stack estasoft-nd-gap-4 estasoft-nd-indent' : 'estasoft-nd-stack estasoft-nd-gap-4' ),
			Html::tag( 'span', array( 'class' => 'estasoft-nd-strong' ), esc_html( $name ) )
			. ( '' !== $detail ? Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $detail ) ) : '' )
		);

		$when = (int) $row['last_ts'] > 0
			? esc_html( View::ago( (int) $row['last_ts'], $now ) )
			: esc_html( Status::PROCESSING === $row['status'] ? __( 'Not yet', 'estasoft-news-distribution' ) : __( 'Never', 'estasoft-news-distribution' ) );

		$count = Html::tag( 'span', array(), esc_html( number_format_i18n( (int) $row['fetches_24h'] ) ) );

		if ( (int) $row['fetches_24h'] > 0 ) {
			$short  = (int) round( 100 * (int) $row['short_24h'] / max( 1, (int) $row['fetches_24h'] ) );
			$count .= Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: 1: average time to answer such as "120 ms", 2: share of short replies such as "40". */
						__( '%1$s on average, %2$s%% short replies', 'estasoft-news-distribution' ),
						View::duration( (int) $row['average_ms'] ),
						number_format_i18n( $short )
					)
				)
			);
		}

		$bars = array_sum( array_map( 'intval', $row['days'] ) ) > 0
			? View::bars( $row['days'], __( 'Fetches per day, oldest first:', 'estasoft-news-distribution' ) )
			: '';

		return array(
			$label,
			$when,
			Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ), $count ),
			$bars,
			StatusView::inline( (string) $row['status'] ),
		);
	}

	/**
	 * The messages above the table: what needs a look.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 * @param int                              $now  Unix time to count from.
	 * @return string
	 */
	private function lead( array $rows, int $now ): string {
		$html     = '';
		$on_time  = 0;
		$set_up   = 0;
		$attended = 0;

		foreach ( $rows as $row ) {
			if ( ! $row['enabled'] ) {
				continue;
			}

			++$set_up;

			if ( Status::ON_TIME === $row['status'] ) {
				++$on_time;
			}

			$states  = array_column( $row['languages'], 'status' );
			$problem = Status::LATE === $row['status'] || ( array() !== $states && in_array( Status::ON_TIME, $states, true ) && ( in_array( Status::LATE, $states, true ) || in_array( Status::NOT_FETCHED, $states, true ) ) );
			$problem = $problem || Status::LATE === $row['status'];

			if ( ! $problem || $attended >= 3 ) {
				continue;
			}

			++$attended;

			$html .= View::lead(
				'warning',
				sprintf(
					/* translators: %s: platform name such as "Google News". */
					__( '%s needs attention.', 'estasoft-news-distribution' ),
					$row['label']
				),
				$this->attention_text( $row, $now ),
				Button::link(
					sprintf(
						/* translators: %s: platform name such as "Google News". */
						__( 'Open the %s channel', 'estasoft-news-distribution' ),
						$row['label']
					),
					Links::channel( (string) $row['id'] ),
					array( 'size' => 'sm' )
				)
			);
		}

		if ( 0 === $attended && $set_up > 0 && $on_time === $set_up ) {
			$html .= View::lead( 'success', __( 'Every platform that is set up fetched on time.', 'estasoft-news-distribution' ) );
		} elseif ( 0 === $attended && ( 0 === $set_up || 0 === $on_time ) ) {
			$html .= View::lead( 'info', __( 'No platform has fetched your feeds yet.', 'estasoft-news-distribution' ), __( 'Fetches appear here as soon as one reads an address on this site.', 'estasoft-news-distribution' ) );
		}

		return $html;
	}

	/**
	 * The sentence that says what is late or missing for one platform.
	 *
	 * @param array<string, mixed> $row A row from rows().
	 * @param int                  $now Unix time to count from.
	 * @return string
	 */
	private function attention_text( array $row, int $now ): string {
		$names = $this->crawler_names( $row['crawlers'] );
		$names = '' === $names ? (string) $row['label'] : $names;
		$noun  = Group::SITEMAP === $row['group'] ? __( 'sitemap', 'estasoft-news-distribution' ) : __( 'feed', 'estasoft-news-distribution' );
		$parts = array();

		if ( array() === $row['languages'] ) {
			return sprintf(
				/* translators: 1: crawler name, 2: how long ago, such as "26 hours ago". */
				__( '%1$s last fetched your %3$s %2$s.', 'estasoft-news-distribution' ),
				$names,
				View::ago( (int) $row['last_ts'], $now ),
				$noun
			);
		}

		foreach ( $row['languages'] as $language ) {
			if ( Status::NOT_FETCHED === $language['status'] || Status::NEVER === $language['status'] ) {
				$parts[] = sprintf(
					/* translators: 1: crawler name, 2: language name, 3: "sitemap" or "feed". */
					__( '%1$s has never fetched your %2$s %3$s', 'estasoft-news-distribution' ),
					$names,
					View::language( (string) $language['language'] ),
					$noun
				);
			} elseif ( Status::LATE === $language['status'] ) {
				$parts[] = sprintf(
					/* translators: 1: crawler name, 2: language name, 3: how long ago, such as "26 hours ago". */
					__( '%1$s last fetched the %2$s one %3$s', 'estasoft-news-distribution' ),
					$names,
					View::language( (string) $language['language'] ),
					View::ago( (int) $language['last_ts'], $now )
				);
			}
		}

		return implode( '. ', $parts ) . '.';
	}

	public function render(): string {
		$now   = Clock::now();
		$rows  = $this->rows( $now );
		$cells = array();

		foreach ( $rows as $row ) {
			$cells[] = $this->cells( $row, (string) $row['label'], $this->detail( $row, $now ), false, $now );

			foreach ( $row['languages'] as $language ) {
				$noun    = Group::SITEMAP === $row['group'] ? __( 'sitemap', 'estasoft-news-distribution' ) : __( 'feed', 'estasoft-news-distribution' );
				$cells[] = $this->cells( $language, View::language( (string) $language['language'] ), $noun, true, $now );
			}
		}

		$table = Grid::render(
			'estasoft-nd-cols-fetch',
			array(
				__( 'Platform', 'estasoft-news-distribution' ),
				__( 'Last fetched', 'estasoft-news-distribution' ),
				__( 'Last 24 h', 'estasoft-news-distribution' ),
				__( '7 days', 'estasoft-news-distribution' ),
				__( 'Status', 'estasoft-news-distribution' ),
			),
			$cells,
			__( 'Fetches by platform', 'estasoft-news-distribution' )
		);

		$legend = Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-card estasoft-nd-legend estasoft-nd-small' ),
			Html::tag( 'strong', array(), esc_html__( 'What the words mean.', 'estasoft-news-distribution' ) )
			. ' ' . esc_html__( 'On time: fetched as often as usual. Later than usual: the gap is longer than that platform’s own pattern. Not fetched yet: set up, but no fetch recorded so far. Never: nothing recorded, and none expected until the platform approves the feed. Processing: a search engine is reading a new sitemap.', 'estasoft-news-distribution' )
		);

		return $this->lead( $rows, $now )
			. Card::flush(
				'fetches',
				__( 'Fetches by platform', 'estasoft-news-distribution' ),
				__( 'Counted over the last 24 hours. The 7-day bars show fetches per day, today on the right.', 'estasoft-news-distribution' ),
				'' === $table ? '' : $table
			)
			. $legend
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html__( 'News Distribution sees only requests that reach WordPress. If a CDN or page cache serves your feeds, fetches are missed. Exclude /feed/ from the cache so every fetch is counted.', 'estasoft-news-distribution' )
			);
	}
}

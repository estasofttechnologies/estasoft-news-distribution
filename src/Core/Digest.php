<?php
/**
 * The weekly summary email.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\SearchSitemap\FetchSummary;
use Estasoft\NewsDistribution\Checks\ArticleChecker;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Operations\AlertState;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the Monday email from the delivery log, the fetch log and the stored
 * article checks, and sends it. It reads what is stored; it never runs a check
 * and never calls out.
 *
 * Get it with `Plugin::instance()->make( Digest::class )`.
 *
 * `build()` returns this array (the Reports workstream serves it as `GET /reports/digest`):
 *
 *     array(
 *       'site'      => string,                    // publication name
 *       'period'    => array( 'from' => int, 'to' => int, 'partial' => bool ), // Unix times; partial is true for the week so far
 *       'counts'    => array( 'published' => int, 'clean' => int, 'skipped' => int, 'unchecked' => int ),
 *       'skipped'   => array( array( 'code' => string, 'reason' => string, 'channels' => array( id => label ), 'count' => int ) ),
 *       'needs_you' => array( array( 'kind' => 'skipped'|'fetch'|'application', 'tone' => 'warning'|'info',
 *                                    'title' => string, 'detail' => string,
 *                                    'action' => array( 'label' => string, 'url' => string )|null ) ),
 *       'fetches'   => array( array( 'channel' => string, 'label' => string, 'status' => string, 'detail' => string,
 *                                    'languages' => array( code => array( 'status' => string, 'last' => int ) ) ) ),
 *       'pushes'    => array( status code => int ),  // delivery log rows in the period, by Core\Status code
 *     )
 *
 * Every status is a Core\Status code. `clean` counts checked articles that no channel skips;
 * `unchecked` counts published articles that have no stored check yet.
 */
final class Digest {

	public const HOOK = 'estasoft_nd_weekly_digest';

	private const MAX_ARTICLES = 5000;

	private Plugin $plugin;

	private AlertState $state;

	/**
	 * Creates the digest.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->state  = new AlertState();
	}

	/**
	 * Midnight on the Monday of the week that holds a time, in the site's time zone.
	 *
	 * @param int $time Unix time.
	 * @return int
	 */
	public function week_start( int $time ): int {
		$date = ( new \DateTimeImmutable( '@' . $time ) )->setTimezone( Clock::zone() );

		return $date->modify( 'monday this week' )->setTime( 0, 0, 0 )->getTimestamp();
	}

	/**
	 * The time of the next Monday email: 08:00 on the next Monday, in the site's time zone.
	 *
	 * @param int|null $now Unix time, or null for now.
	 * @return int
	 */
	public function next_run( ?int $now = null ): int {
		$now  = null === $now ? Clock::now() : $now;
		$date = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( Clock::zone() )->modify( 'monday this week' )->setTime( 8, 0, 0 );

		if ( $date->getTimestamp() <= $now ) {
			$date = $date->modify( '+1 week' );
		}

		return $date->getTimestamp();
	}

	/**
	 * The figures for a week.
	 *
	 * @param int|null $now    Unix time, or null for now.
	 * @param string   $period `so_far` for the week up to now (the preview), `last_week` for the whole week before this one (what is emailed).
	 * @return array<string, mixed> See the class description.
	 */
	public function build( ?int $now = null, string $period = 'so_far' ): array {
		$now   = null === $now ? Clock::now() : $now;
		$start = $this->week_start( $now );

		if ( 'last_week' === $period ) {
			$from    = ( new \DateTimeImmutable( '@' . $start ) )->setTimezone( Clock::zone() )->modify( '-1 week' )->getTimestamp();
			$to      = $start;
			$partial = false;
		} else {
			$from    = $start;
			$to      = $now;
			$partial = true;
		}

		$scan    = $this->scan( $from, $to );
		$fetches = $this->fetches( $now );

		return array(
			'site'      => $this->plugin->settings()->publication_name(),
			'period'    => array(
				'from'    => $from,
				'to'      => $to,
				'partial' => $partial,
			),
			'counts'    => array(
				'published' => $scan['published'],
				'clean'     => $scan['clean'],
				'skipped'   => $scan['skipped'],
				'unchecked' => $scan['unchecked'],
			),
			'skipped'   => $scan['reasons'],
			'needs_you' => $this->needs_you( $scan['reasons'], $fetches, $now ),
			'fetches'   => $fetches,
			'pushes'    => $this->pushes( $from, $to ),
		);
	}

	/**
	 * Articles published in a time span that a channel skips, by reason.
	 *
	 * @param int $from Start, Unix time.
	 * @param int $to   End, Unix time.
	 * @return array{articles: int, reasons: array<int, array{code: string, reason: string, channels: array<string, string>, count: int}>}
	 */
	public function skipped( int $from, int $to ): array {
		$scan = $this->scan( $from, $to );

		return array(
			'articles' => $scan['skipped'],
			'reasons'  => array_map(
				static function ( array $reason ): array {
					return array(
						'code'     => $reason['code'],
						'reason'   => $reason['reason'],
						'channels' => array_values( $reason['channels'] ),
						'count'    => $reason['count'],
					);
				},
				$scan['reasons']
			),
		);
	}

	/**
	 * What the email says, in the order it says it, as data both the email and the preview print.
	 *
	 * @param array<string, mixed> $digest What `build()` returned.
	 * @return array<string, mixed>
	 */
	public function view_model( array $digest ): array {
		$counts  = (array) $digest['counts'];
		$period  = (array) $digest['period'];
		$site    = (string) $digest['site'];
		$skipped = (int) $counts['skipped'];

		if ( 0 === (int) $counts['published'] ) {
			/* translators: %s: the site name. */
			$title = sprintf( __( '%s: nothing was published this week', 'estasoft-news-distribution' ), $site );
		} elseif ( $skipped > 0 ) {
			$title = sprintf(
				/* translators: 1: the site name, 2: number of articles. */
				_n( '%1$s: %2$d article was skipped this week', '%1$s: %2$d articles were skipped this week', $skipped, 'estasoft-news-distribution' ),
				$site,
				$skipped
			);
		} else {
			/* translators: %s: the site name. */
			$title = sprintf( __( '%s: no articles were skipped this week', 'estasoft-news-distribution' ), $site );
		}

		$subtitle = '';

		if ( (int) $counts['clean'] > 0 && $skipped > 0 ) {
			$subtitle = sprintf(
				/* translators: %d: number of articles. */
				_n( 'No problems were found for the other %d article.', 'No problems were found for the other %d articles.', (int) $counts['clean'], 'estasoft-news-distribution' ),
				(int) $counts['clean']
			);
		} elseif ( (int) $counts['clean'] > 0 ) {
			$subtitle = sprintf(
				/* translators: %d: number of articles. */
				_n( 'No problems were found for %d article.', 'No problems were found for any of the %d articles.', (int) $counts['clean'], 'estasoft-news-distribution' ),
				(int) $counts['clean']
			);
		}

		if ( (int) $counts['unchecked'] > 0 ) {
			$subtitle = trim(
				$subtitle . ' ' . sprintf(
					/* translators: %d: number of articles. */
					_n( '%d article has not been checked yet.', '%d articles have not been checked yet.', (int) $counts['unchecked'], 'estasoft-news-distribution' ),
					(int) $counts['unchecked']
				)
			);
		}

		$basis = sprintf(
			/* translators: 1: start of the period, 2: end of the period. */
			__( 'Based on the Delivery log and the Fetch monitor, from %1$s to %2$s. A fetch is counted only when the request reaches WordPress, so a cached feed can look quiet.', 'estasoft-news-distribution' ),
			wp_date( 'l j M', (int) $period['from'], Clock::zone() ),
			wp_date( 'l j M, H:i', (int) $period['to'] - ( $period['partial'] ? 0 : 1 ), Clock::zone() )
		);

		$sections = array();

		$sections[] = array(
			'key'   => 'week',
			'title' => __( 'This week', 'estasoft-news-distribution' ),
			'rows'  => array(
				array(
					'label' => __( 'Articles published', 'estasoft-news-distribution' ),
					'hint'  => '',
					'value' => number_format_i18n( (int) $counts['published'] ),
				),
				array(
					'label' => __( 'No problems found', 'estasoft-news-distribution' ),
					'hint'  => '',
					'value' => number_format_i18n( (int) $counts['clean'] ),
				),
				array(
					'label' => __( 'Skipped by at least one channel', 'estasoft-news-distribution' ),
					'hint'  => $skipped > 0 ? __( 'Details below', 'estasoft-news-distribution' ) : '',
					'value' => number_format_i18n( $skipped ),
				),
			),
		);

		$pushes = (array) $digest['pushes'];

		if ( array() !== $pushes ) {
			$sections[0]['rows'][] = array(
				'label' => __( 'Pushes sent', 'estasoft-news-distribution' ),
				'hint'  => (int) ( $pushes[ Status::FAILED ] ?? 0 ) > 0
					? sprintf(
						/* translators: %d: number of pushes. */
						_n( '%d push failed after every retry', '%d pushes failed after every retry', (int) $pushes[ Status::FAILED ], 'estasoft-news-distribution' ),
						(int) $pushes[ Status::FAILED ]
					)
					: '',
				'value' => number_format_i18n( (int) ( $pushes[ Status::SENT ] ?? 0 ) ),
			);
		}

		if ( array() !== $digest['needs_you'] ) {
			$sections[] = array(
				'key'   => 'needs',
				'title' => __( 'Needs you', 'estasoft-news-distribution' ),
				'items' => $digest['needs_you'],
			);
		}

		if ( array() !== $digest['skipped'] ) {
			$rows = array();

			foreach ( $digest['skipped'] as $reason ) {
				$rows[] = array(
					'label' => (string) $reason['reason'],
					'hint'  => implode( ', ', array_map( 'strval', (array) $reason['channels'] ) ),
					'value' => number_format_i18n( (int) $reason['count'] ),
				);
			}

			$sections[] = array(
				'key'   => 'skipped',
				'title' => __( 'Skipped articles, by reason', 'estasoft-news-distribution' ),
				'rows'  => $rows,
			);
		}

		if ( array() !== $digest['fetches'] ) {
			$lines = array();

			foreach ( $digest['fetches'] as $fetch ) {
				$lines[] = array(
					'status' => (string) $fetch['status'],
					'text'   => sprintf(
						/* translators: 1: platform name, 2: its status, such as "On time", 3: what is known about its fetches. */
						__( '%1$s: %2$s. %3$s', 'estasoft-news-distribution' ),
						(string) $fetch['label'],
						Status::word( (string) $fetch['status'] ),
						(string) $fetch['detail']
					),
				);
			}

			$sections[] = array(
				'key'   => 'fetch',
				'title' => __( 'Did the platforms fetch?', 'estasoft-news-distribution' ),
				'lines' => $lines,
			);
		}

		$model = array(
			'title'    => $title,
			'subtitle' => $subtitle,
			'basis'    => $basis,
			'range'    => sprintf(
				/* translators: 1: first day, 2: last day. */
				__( 'Weekly summary · %1$s to %2$s', 'estasoft-news-distribution' ),
				wp_date( 'j', (int) $period['from'], Clock::zone() ),
				wp_date( 'j M', max( (int) $period['from'], (int) $period['to'] - ( $period['partial'] ? 0 : 1 ) ), Clock::zone() )
			),
			'sections' => $sections,
			'footer'   => __( 'You get this because Weekly summary is on in Settings, Alerts.', 'estasoft-news-distribution' ),
			'link'     => array(
				'label' => __( 'Change what you receive', 'estasoft-news-distribution' ),
				'url'   => Links::settings( 'alerts' ),
			),
		);

		/**
		 * Lets another part of the plugin add a section to the email (internal; the AI assistant adds its opening paragraph).
		 *
		 * @param array<string, mixed> $model  What the email prints.
		 * @param array<string, mixed> $digest The figures behind it.
		 */
		$filtered = apply_filters( 'estasoft_nd_internal_digest_model', $model, $digest );

		return is_array( $filtered ) ? $filtered : $model;
	}

	/**
	 * The email as HTML, with inline styles because mail programs need them.
	 *
	 * @param array<string, mixed> $model What `view_model()` returned.
	 * @return string
	 */
	public function html( array $model ): string {
		$alerts = $this->plugin->make( Alerts::class );
		$inner  = '<p style="margin:0 0 4px;font-size:12px;color:#505966;">' . esc_html( (string) $model['range'] ) . '</p>'
			. '<h1 style="margin:0 0 8px;font-size:22px;line-height:30px;"><bdi>' . esc_html( (string) $model['title'] ) . '</bdi></h1>';

		if ( '' !== (string) $model['subtitle'] ) {
			$inner .= '<p style="margin:0 0 8px;font-size:16px;line-height:24px;">' . esc_html( (string) $model['subtitle'] ) . '</p>';
		}

		$inner .= '<p style="margin:0 0 20px;font-size:12px;line-height:18px;color:#505966;">' . esc_html( (string) $model['basis'] ) . '</p>';

		foreach ( $model['sections'] as $section ) {
			$inner .= '<h2 style="margin:24px 0 8px;font-size:16px;line-height:22px;">' . esc_html( (string) $section['title'] ) . '</h2>';

			foreach ( (array) ( $section['rows'] ?? array() ) as $row ) {
				$inner .= '<p style="margin:0 0 8px;line-height:20px;"><span>' . esc_html( (string) $row['label'] ) . '</span> <strong style="float:right;">' . esc_html( (string) $row['value'] ) . '</strong>'
					. ( '' !== (string) $row['hint'] ? '<br><span style="font-size:12px;color:#505966;"><bdi>' . esc_html( (string) $row['hint'] ) . '</bdi></span>' : '' ) . '</p>';
			}

			foreach ( (array) ( $section['items'] ?? array() ) as $item ) {
				$inner .= '<p style="margin:0 0 12px;line-height:20px;"><strong>' . esc_html( (string) $item['title'] ) . '</strong><br>' . esc_html( (string) $item['detail'] );

				if ( is_array( $item['action'] ) ) {
					$inner .= '<br><a href="' . esc_url( (string) $item['action']['url'] ) . '" style="color:#005ece;font-weight:600;">' . esc_html( (string) $item['action']['label'] ) . '</a>';
				}

				$inner .= '</p>';
			}

			foreach ( (array) ( $section['lines'] ?? array() ) as $line ) {
				$inner .= '<p style="margin:0 0 6px;line-height:20px;"><bdi>' . esc_html( (string) $line['text'] ) . '</bdi></p>';
			}
		}

		$inner .= '<p style="margin:24px 0 0;padding-top:12px;border-top:1px solid #dce0e4;font-size:12px;line-height:18px;color:#505966;">' . esc_html( (string) $model['footer'] ) . ' <a href="' . esc_url( (string) $model['link']['url'] ) . '" style="color:#005ece;">' . esc_html( (string) $model['link']['label'] ) . '</a></p>';

		return $alerts->frame( $inner, is_rtl() ? 'rtl' : 'ltr' );
	}

	/**
	 * The email as plain text.
	 *
	 * @param array<string, mixed> $model What `view_model()` returned.
	 * @return string
	 */
	public function text( array $model ): string {
		$lines = array( (string) $model['title'] );

		if ( '' !== (string) $model['subtitle'] ) {
			$lines[] = (string) $model['subtitle'];
		}

		$lines[] = (string) $model['basis'];

		foreach ( $model['sections'] as $section ) {
			$lines[] = '';
			$lines[] = (string) $section['title'];

			foreach ( (array) ( $section['rows'] ?? array() ) as $row ) {
				$lines[] = $row['label'] . ': ' . $row['value'] . ( '' !== (string) $row['hint'] ? ' (' . $row['hint'] . ')' : '' );
			}

			foreach ( (array) ( $section['items'] ?? array() ) as $item ) {
				$lines[] = '- ' . $item['title'] . ' ' . $item['detail'] . ( is_array( $item['action'] ) ? ' ' . $item['action']['url'] : '' );
			}

			foreach ( (array) ( $section['lines'] ?? array() ) as $line ) {
				$lines[] = (string) $line['text'];
			}
		}

		$lines[] = '';
		$lines[] = $model['footer'] . ' ' . $model['link']['url'];

		return wp_specialchars_decode( implode( "\n", array_map( 'strval', $lines ) ), ENT_QUOTES );
	}

	/**
	 * Sends last week's summary to the alert addresses, each in their own language.
	 *
	 * @param int|null $now Unix time, or null for now.
	 * @return array{status: string, sent: int, to: string[], error: string}
	 */
	public function send( ?int $now = null ): array {
		$alerts = $this->plugin->make( Alerts::class );

		if ( ! $alerts->is_on( Alerts::WEEKLY ) ) {
			return array(
				'status' => 'off',
				'sent'   => 0,
				'to'     => array(),
				'error'  => '',
			);
		}

		$recipients = array_map( array( $alerts, 'recipient' ), $alerts->addresses() );

		if ( array() === $recipients ) {
			return array(
				'status' => 'nobody',
				'sent'   => 0,
				'to'     => array(),
				'error'  => '',
			);
		}

		$result = $alerts->dispatch(
			$recipients,
			function () use ( $now ): array {
				// Built inside the call so every sentence is in the recipient's language.
				$model = $this->view_model( $this->build( $now, 'last_week' ) );

				return array(
					'subject' => wp_strip_all_tags( (string) $model['title'] ),
					'html'    => $this->html( $model ),
					'text'    => $this->text( $model ),
				);
			}
		);

		if ( 'sent' === $result['status'] ) {
			$this->state->put(
				'weekly',
				array(
					'last_sent' => null === $now ? Clock::now() : $now,
					'sent'      => $result['sent'],
				)
			);
		}

		return $result;
	}

	/**
	 * Makes sure the next Monday email is in the queue.
	 *
	 * @return bool True when one is waiting.
	 */
	public function schedule(): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}

		if ( $this->has_pending() ) {
			return true;
		}

		return Queue::once( $this->next_run(), self::HOOK, array(), Queue::GROUP ) > 0;
	}

	/**
	 * The queue's handler: sends the email, then books the next one.
	 *
	 * @return void
	 */
	public function run(): void {
		try {
			$this->send();
		} finally {
			$this->schedule();
		}
	}

	/**
	 * Whether a Monday email is already waiting in the queue.
	 *
	 * @return bool
	 */
	private function has_pending(): bool {
		return Queue::is_waiting( self::HOOK, array(), Queue::GROUP );
	}

	/**
	 * Reads the stored checks of the articles published in a span.
	 *
	 * @param int $from Start, Unix time.
	 * @param int $to   End, Unix time.
	 * @return array{published: int, clean: int, skipped: int, unchecked: int, reasons: array<int, array{code: string, reason: string, channels: array<string, string>, count: int}>}
	 */
	private function scan( int $from, int $to ): array {
		$ids = get_posts(
			array(
				'post_type'              => $this->plugin->settings()->post_types(),
				'post_status'            => 'publish',
				'posts_per_page'         => self::MAX_ARTICLES,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- The digest covers every article of the period; another plugin's filters must not narrow it.
				'date_query'             => array(
					array(
						'column'    => 'post_date_gmt',
						'after'     => Clock::to_gmt( $from ),
						'before'    => Clock::to_gmt( $to ),
						'inclusive' => true,
					),
				),
			)
		);

		$checker   = $this->plugin->make( ArticleChecker::class );
		$groups    = array();
		$skipped   = 0;
		$unchecked = 0;

		foreach ( (array) $ids as $post_id ) {
			$report = $checker->stored( (int) $post_id );

			if ( null === $report ) {
				++$unchecked;
				continue;
			}

			$skipping = $report->skipping_channels();

			if ( array() === $skipping ) {
				continue;
			}

			++$skipped;

			foreach ( $skipping as $channel_id ) {
				$channel = $this->plugin->registry()->get( $channel_id );
				$label   = null === $channel ? $channel_id : $channel->label();
				$entry   = $report->channels()[ $channel_id ] ?? array();

				foreach ( (array) ( $entry['findings'] ?? array() ) as $finding ) {
					if ( Status::BLOCK !== ( $finding['severity'] ?? '' ) ) {
						continue;
					}

					$code = (string) ( $finding['code'] ?? '' );

					if ( ! isset( $groups[ $code ] ) ) {
						$groups[ $code ] = array(
							'code'     => $code,
							'reason'   => (string) ( $finding['message'] ?? $code ),
							'channels' => array(),
							'posts'    => array(),
						);
					}

					$groups[ $code ]['channels'][ $channel_id ] = $label;
					$groups[ $code ]['posts'][ (int) $post_id ] = true;
				}
			}
		}

		$reasons = array();

		foreach ( $groups as $group ) {
			$reasons[] = array(
				'code'     => $group['code'],
				'reason'   => $group['reason'],
				'channels' => $group['channels'],
				'count'    => count( $group['posts'] ),
			);
		}

		usort(
			$reasons,
			static function ( array $a, array $b ): int {
				$by_count = $b['count'] <=> $a['count'];

				return 0 !== $by_count ? $by_count : strcmp( $a['code'], $b['code'] );
			}
		);

		$published = count( (array) $ids );

		return array(
			'published' => $published,
			'clean'     => max( 0, $published - $skipped - $unchecked ),
			'skipped'   => $skipped,
			'unchecked' => $unchecked,
			'reasons'   => $reasons,
		);
	}

	/**
	 * How each feed a platform pulls is being fetched.
	 *
	 * @param int $now Unix time.
	 * @return array<int, array{channel: string, label: string, status: string, detail: string, languages: array<string, array{status: string, last: int}>}>
	 */
	private function fetches( int $now ): array {
		$latest = $this->plugin->fetch_log()->latest();
		$out    = array();

		foreach ( $this->plugin->registry()->pulled() as $channel ) {
			if ( ! $channel->is_enabled() || '' === $channel->feed_slug() ) {
				continue;
			}

			$interval  = $this->plugin->rules()->fetch_interval_hours( $channel->id() ) * HOUR_IN_SECONDS;
			$rows      = FetchSummary::latest_rows( $this->plugin, $channel, $latest );
			$languages = array();

			foreach ( $this->plugin->languages()->languages() as $language ) {
				if ( ! $channel->accepts_language( (string) $language ) ) {
					continue;
				}

				$row  = $rows[ (string) $language ] ?? null;
				$last = null === $row ? 0 : (int) $row['created_ts'];

				if ( 0 === $last ) {
					$code = Status::NEVER;
				} elseif ( $now - $last > 2 * $interval ) {
					$code = Status::LATE;
				} else {
					$code = Status::ON_TIME;
				}

				$languages[ (string) $language ] = array(
					'status' => $code,
					'last'   => $last,
				);
			}

			if ( array() === $languages ) {
				continue;
			}

			$statuses = array_column( $languages, 'status' );
			$all_ever = array( Status::NEVER ) === array_values( array_unique( $statuses ) );

			if ( $all_ever ) {
				$since  = (int) $this->plugin->channel_settings()->get( $channel->id() )['enabled_since'];
				$status = ( 0 === $since || $now - $since < 2 * DAY_IN_SECONDS ) ? Status::PROCESSING : Status::NEVER;
			} elseif ( in_array( Status::LATE, $statuses, true ) || in_array( Status::NEVER, $statuses, true ) ) {
				$status = Status::LATE;
			} else {
				$status = Status::ON_TIME;
			}

			$out[] = array(
				'channel'   => $channel->id(),
				'label'     => $channel->label(),
				'status'    => $status,
				'detail'    => $this->fetch_detail( $channel, $status, $languages, $now ),
				'languages' => $languages,
			);
		}

		return $out;
	}

	/**
	 * A sentence about a platform's fetches.
	 *
	 * @param ChannelInterface                                $channel   The channel.
	 * @param string                                          $status    Its status code.
	 * @param array<string, array{status: string, last: int}> $languages Fetch state per language.
	 * @param int                                             $now       Unix time.
	 * @return string
	 */
	private function fetch_detail( ChannelInterface $channel, string $status, array $languages, int $now ): string {
		$parts = array();

		foreach ( $languages as $code => $state ) {
			$name = Catalog::name( (string) $code );

			if ( Status::NEVER === $state['status'] ) {
				/* translators: %s: a language name. */
				$parts[] = sprintf( __( '%s: not fetched yet.', 'estasoft-news-distribution' ), $name );
			} else {
				$hours = max( 1, (int) floor( ( $now - $state['last'] ) / HOUR_IN_SECONDS ) );
				/* translators: 1: a language name, 2: a number of hours. */
				$parts[] = sprintf( _n( '%1$s: last fetched %2$d hour ago.', '%1$s: last fetched %2$d hours ago.', $hours, 'estasoft-news-distribution' ), $name, $hours );
			}
		}

		if ( Status::PROCESSING === $status || Status::NEVER === $status ) {
			$application = $this->plugin->applications()->get( $channel->id() )['status'];

			if ( Status::IN_REVIEW === $application ) {
				$parts[] = __( 'It is still in review.', 'estasoft-news-distribution' );
			} elseif ( Status::REQUESTED === $application ) {
				$parts[] = __( 'The application is still open.', 'estasoft-news-distribution' );
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * The things the person has to do something about.
	 *
	 * @param array<int, array<string, mixed>> $reasons Skipped reasons from `scan()`.
	 * @param array<int, array<string, mixed>> $fetches Fetch states from `fetches()`.
	 * @param int                              $now     Unix time.
	 * @return array<int, array<string, mixed>>
	 */
	private function needs_you( array $reasons, array $fetches, int $now ): array {
		$items = array();

		foreach ( array_slice( $reasons, 0, 3 ) as $reason ) {
			$items[] = array(
				'kind'   => 'skipped',
				'tone'   => 'warning',
				'title'  => sprintf(
					/* translators: %d: number of articles. */
					_n( '%d article was skipped', '%d articles were skipped', (int) $reason['count'], 'estasoft-news-distribution' ),
					(int) $reason['count']
				),
				'detail' => sprintf(
					/* translators: 1: why articles were skipped, 2: channel names. */
					__( '%1$s Skipped by: %2$s.', 'estasoft-news-distribution' ),
					(string) $reason['reason'],
					implode( ', ', array_map( 'strval', (array) $reason['channels'] ) )
				),
				'action' => array(
					'label' => __( 'Open Article check', 'estasoft-news-distribution' ),
					'url'   => Links::check(),
				),
			);
		}

		foreach ( $fetches as $fetch ) {
			if ( Status::LATE !== $fetch['status'] && Status::NEVER !== $fetch['status'] ) {
				continue;
			}

			$items[] = array(
				'kind'   => 'fetch',
				'tone'   => 'warning',
				'title'  => sprintf(
					/* translators: %s: platform name. */
					__( '%s needs attention', 'estasoft-news-distribution' ),
					(string) $fetch['label']
				),
				'detail' => (string) $fetch['detail'],
				'action' => array(
					'label' => __( 'Open Fetch monitor', 'estasoft-news-distribution' ),
					'url'   => Links::fetch_monitor(),
				),
			);
		}

		foreach ( Applications::platforms() as $platform => $name ) {
			$row = $this->plugin->applications()->get( (string) $platform );

			if ( ! in_array( $row['status'], array( Status::REQUESTED, Status::IN_REVIEW ), true ) ) {
				continue;
			}

			$overdue = $this->plugin->applications()->is_overdue( (string) $platform );
			$detail  = '' !== $row['applied_on']
				? sprintf(
					/* translators: %s: a date. */
					__( 'You applied on %s.', 'estasoft-news-distribution' ),
					wp_date( 'j M', (int) strtotime( $row['applied_on'] . ' 12:00:00' ), Clock::zone() )
				)
				: __( 'You marked it by hand.', 'estasoft-news-distribution' );

			if ( $overdue ) {
				$detail .= ' ' . __( 'The reminder date has passed.', 'estasoft-news-distribution' );
			}

			$channel = $this->plugin->registry()->get( (string) $platform );

			$items[] = array(
				'kind'   => 'application',
				'tone'   => $overdue ? 'warning' : 'info',
				'title'  => sprintf(
					/* translators: 1: platform name, 2: the status, such as "In review". */
					__( '%1$s is marked %2$s', 'estasoft-news-distribution' ),
					(string) $name,
					Status::word( $row['status'] )
				),
				'detail' => $detail,
				'action' => null === $channel ? null : array(
					'label' => sprintf(
						/* translators: %s: platform name. */
						__( 'Open %s', 'estasoft-news-distribution' ),
						(string) $name
					),
					'url'   => Links::channel( (string) $platform ),
				),
			);
		}

		return $items;
	}

	/**
	 * Delivery log rows in a span, by status code.
	 *
	 * @param int $from Start, Unix time.
	 * @param int $to   End, Unix time.
	 * @return array<string, int>
	 */
	private function pushes( int $from, int $to ): array {
		global $wpdb;

		$table = $this->plugin->log()->table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i WHERE created_gmt >= %s AND created_gmt <= %s GROUP BY status', $table, Clock::to_gmt( $from ), Clock::to_gmt( $to ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$counts = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$code            = Status::from_log( (string) $row['status'] );
			$counts[ $code ] = ( $counts[ $code ] ?? 0 ) + (int) $row['total'];
		}

		return $counts;
	}
}

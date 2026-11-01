<?php
/**
 * Delivery log: what News Distribution pushed, and what came back.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Pages;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Page;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\CommandBar;
use Estasoft\NewsDistribution\Admin\Ui\EmptyState;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\PageHeader;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Table;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\DeliveryEntries;
use Estasoft\NewsDistribution\Core\DeliveryRetry;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Retry;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-log`. One row per push to WebSub, IndexNow or an
 * outlet, newest first, with the answer it got. A push that is waiting or being
 * tried again shows once, as its latest row.
 *
 * Nothing on this screen needs a script. The filters are a plain GET form with a
 * Filter button, the status filter is a set of links, "Show more entries" is a
 * link, and "Retry now" is a form posted to this page. A retry only puts the
 * push back in the background queue (`Core\DeliveryRetry`); the queue sends it
 * and the answer comes back as a new row.
 */
final class LogPage extends Page {

	public const FIELD = 'estasoft_nd_log';
	public const NONCE = 'estasoft_nd_log_retry';

	/**
	 * Rows shown before "Show more entries".
	 */
	private const START = 10;

	/**
	 * The status buttons, in the order of the design.
	 */
	private const STATUSES = array( Status::SENT, Status::QUEUED, Status::RETRY, Status::FAILED );

	/**
	 * The periods in the Period list, in days. Zero is every entry still kept.
	 */
	private const PERIODS = array( 1, 7, 30, 0 );

	/**
	 * Why a retry was refused, to show after the form was posted.
	 *
	 * @var string
	 */
	private string $refused = '';

	public function slug(): string {
		return 'estasoft-nd-log';
	}

	public function title(): string {
		return __( 'Delivery log', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 50;
	}

	public function permission(): string {
		return Access::VIEW;
	}

	public function in_simple_view(): bool {
		return true;
	}

	public function enqueue(): void {
		Assets::screen_style( 'log' );
	}

	/**
	 * Handles "Retry now". A push that cannot be sent again is shown again with the reason.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! isset( $_POST[ self::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is checked on the next line.
			return;
		}

		check_admin_referer( self::NONCE );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		$id     = isset( $_POST['entry'] ) ? absint( wp_unslash( $_POST['entry'] ) ) : 0;
		$queued = $this->plugin->make( DeliveryRetry::class )->retry( $id );

		if ( is_wp_error( $queued ) ) {
			$this->refused = $queued->get_error_message();

			return;
		}

		wp_safe_redirect( $this->address( array( 'retried' => (int) $queued['id'] ) ) );
		exit;
	}

	public function render(): string {
		$filters = $this->filters();
		$entries = $this->plugin->make( DeliveryEntries::class );
		$found   = $entries->search(
			array(
				'channel'  => $filters['channel'],
				'status'   => $filters['status'],
				'language' => $filters['language'],
				'post'     => $filters['post'],
				'search'   => $filters['search'],
				'days'     => $filters['days'],
				'page'     => $filters['paged'],
				'per_page' => $filters['per_page'],
			)
		);

		$html = PageHeader::render(
			__( 'Delivery log', 'estasoft-news-distribution' ),
			__( 'Every notice News Distribution sent to WebSub, IndexNow and your outlets, one row per push with its attempts, and the answer it got. A failed push is tried again after 1 minute, then 5 minutes, then 15 minutes, and then News Distribution stops. “Retry now” sends it again at once.', 'estasoft-news-distribution' )
		)
			. $this->notice( $filters )
			. $this->command_bar( $filters, $entries );

		if ( array() === $found['rows'] ) {
			return $html . $this->empty_state( $filters );
		}

		$rows = array();
		$now  = Clock::now();

		foreach ( $found['rows'] as $row ) {
			$rows[] = $this->cells( $row, $now, $filters );
		}

		return $html . Table::render(
			'estasoft-nd-cols-log',
			array(
				__( 'Time', 'estasoft-news-distribution' ),
				__( 'Article', 'estasoft-news-distribution' ),
				__( 'Channel', 'estasoft-news-distribution' ),
				__( 'Event', 'estasoft-news-distribution' ),
				__( 'Status', 'estasoft-news-distribution' ),
				__( 'Response', 'estasoft-news-distribution' ),
				'',
			),
			$rows,
			__( 'Delivery log', 'estasoft-news-distribution' ),
			$this->footer( $filters, count( $found['rows'] ), $found['total'] )
		);
	}

	/**
	 * What the address asks to see, each value checked.
	 *
	 * @return array{status: string, channel: string, language: string, days: int, search: string, post: int, per_page: int, paged: int}
	 */
	private function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filtering a list changes nothing.
		$status   = Query::key( 'status' );
		$post     = Query::int( 'post' );
		$language = Query::text( 'language' );
		$days     = $post > 0 ? 0 : 7;

		$asked = Query::int( 'days', -1 );

		if ( in_array( $asked, self::PERIODS, true ) ) {
			$days = $asked;
		}

		$per_page = Query::int( 'per_page', self::START );
		$paged    = Query::int( 'paged', 1 );
		$channel  = Query::key( 'channel' );
		$search   = trim( Query::text( 'search' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array(
			'status'   => in_array( $status, DeliveryEntries::statuses(), true ) ? $status : '',
			'channel'  => $channel,
			'language' => 1 === preg_match( '/^[A-Za-z0-9_-]{1,12}$/', $language ) ? $language : '',
			'days'     => $days,
			'search'   => $search,
			'post'     => $post,
			'per_page' => max( self::START, min( 100, $per_page ) ),
			'paged'    => max( 1, $paged ),
		);
	}

	/**
	 * This screen's address with the filters in force, and changes on top.
	 *
	 * @param array<string, scalar> $change Values to set; an empty string or null drops one.
	 * @return string
	 */
	private function address( array $change = array() ): string {
		$filters = $this->filters();
		$args    = array();

		if ( '' !== $filters['status'] ) {
			$args['status'] = $filters['status'];
		}

		foreach ( array( 'channel', 'language', 'search' ) as $key ) {
			if ( '' !== $filters[ $key ] ) {
				$args[ $key ] = $filters[ $key ];
			}
		}

		if ( $filters['post'] > 0 ) {
			$args['post'] = $filters['post'];
		}

		$args['days'] = $filters['days'];

		if ( self::START !== $filters['per_page'] ) {
			$args['per_page'] = $filters['per_page'];
		}

		if ( $filters['paged'] > 1 ) {
			$args['paged'] = $filters['paged'];
		}

		foreach ( $change as $key => $value ) {
			if ( '' === $value || null === $value ) {
				unset( $args[ $key ] );
			} else {
				$args[ $key ] = $value;
			}
		}

		return $this->url( $args );
	}

	/**
	 * The message after a retry was queued or refused, and the line that says the list is for one article.
	 *
	 * @param array<string, mixed> $filters The filters in force.
	 * @return string
	 */
	private function notice( array $filters ): string {
		$html = '';

		if ( '' !== $this->refused ) {
			$html .= Message::text( 'danger', $this->refused );
		} elseif ( Query::has( 'retried' ) ) {
			$html .= Message::text( 'success', __( 'Retry queued. News Distribution sends it in the background, and the answer appears here as a new entry.', 'estasoft-news-distribution' ) );
		}

		if ( $filters['post'] > 0 ) {
			$title = get_the_title( (int) $filters['post'] );

			$html .= Message::bar(
				'info',
				esc_html(
					'' === $title
						? sprintf(
							/* translators: %d: an article number. */
							__( 'Showing the entries for article %d only.', 'estasoft-news-distribution' ),
							(int) $filters['post']
						)
						: sprintf(
							/* translators: %s: an article title. */
							__( 'Showing the entries for “%s” only.', 'estasoft-news-distribution' ),
							$title
						)
				),
				Html::tag(
					'a',
					array( 'href' => $this->address( array( 'post' => '' ) ) ),
					esc_html__( 'Show every article', 'estasoft-news-distribution' )
				)
			);
		}

		return $html;
	}

	/**
	 * The status buttons, the lists and the search box, in one GET form.
	 *
	 * @param array<string, mixed> $filters The filters in force.
	 * @param DeliveryEntries      $entries The log reader, for the channel names.
	 * @return string
	 */
	private function command_bar( array $filters, DeliveryEntries $entries ): string {
		$segments = array(
			'' => array(
				'label' => __( 'All', 'estasoft-news-distribution' ),
				'url'   => $this->address(
					array(
						'status' => '',
						'paged'  => '',
					)
				),
			),
		);

		foreach ( self::STATUSES as $code ) {
			$segments[ $code ] = array(
				'label' => Status::word( $code ),
				'url'   => $this->address(
					array(
						'status' => $code,
						'paged'  => '',
					)
				),
			);
		}

		$channels  = array( '' => __( 'All channels', 'estasoft-news-distribution' ) ) + $entries->channels();
		$languages = array( '' => __( 'All languages', 'estasoft-news-distribution' ) );

		foreach ( $this->plugin->languages()->languages() as $code ) {
			$languages[ (string) $code ] = Catalog::name( (string) $code );
		}

		if ( '' !== $filters['channel'] && ! isset( $channels[ $filters['channel'] ] ) ) {
			$channels[ $filters['channel'] ] = $filters['channel'];
		}

		if ( '' !== $filters['language'] && ! isset( $languages[ $filters['language'] ] ) ) {
			$languages[ $filters['language'] ] = $filters['language'];
		}

		$periods = array(
			'1'  => __( 'Last 24 hours', 'estasoft-news-distribution' ),
			'7'  => __( 'Last 7 days', 'estasoft-news-distribution' ),
			'30' => __( 'Last 30 days', 'estasoft-news-distribution' ),
			'0'  => __( 'Every kept entry', 'estasoft-news-distribution' ),
		);

		$hidden = array( 'per_page' => $filters['per_page'] );

		if ( '' !== $filters['status'] ) {
			$hidden['status'] = $filters['status'];
		}

		if ( $filters['post'] > 0 ) {
			$hidden['post'] = $filters['post'];
		}

		$active  = $this->is_narrowed( $filters ) || ( 0 === $filters['post'] && 7 !== $filters['days'] );
		$actions = Button::submit( __( 'Filter', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) );

		if ( $active ) {
			$actions .= Button::link( __( 'Clear filters', 'estasoft-news-distribution' ), $this->url() );
		}

		return CommandBar::render(
			$this->slug(),
			CommandBar::segments( $segments, $filters['status'], __( 'Status', 'estasoft-news-distribution' ) )
			. CommandBar::select( 'channel', __( 'Channel', 'estasoft-news-distribution' ), $channels, $filters['channel'] )
			. CommandBar::select( 'language', __( 'Language', 'estasoft-news-distribution' ), $languages, $filters['language'] )
			. CommandBar::select( 'days', __( 'Period', 'estasoft-news-distribution' ), $periods, (string) $filters['days'] )
			. CommandBar::search( 'search', __( 'Article', 'estasoft-news-distribution' ), $filters['search'] ),
			$actions,
			$hidden
		);
	}

	/**
	 * Whether anything other than the period narrows the list.
	 *
	 * @param array<string, mixed> $filters The filters in force.
	 * @return bool
	 */
	private function is_narrowed( array $filters ): bool {
		return '' !== $filters['status'] || '' !== $filters['channel'] || '' !== $filters['language'] || '' !== $filters['search'] || $filters['post'] > 0;
	}

	/**
	 * What shows when no entry matches.
	 *
	 * @param array<string, mixed> $filters The filters in force.
	 * @return string
	 */
	private function empty_state( array $filters ): string {
		if ( $this->is_narrowed( $filters ) || 7 !== $filters['days'] ) {
			return EmptyState::render(
				__( 'No entries match', 'estasoft-news-distribution' ),
				__( 'Nothing was sent, waiting or retried with these filters. Try a longer period or clear the filters.', 'estasoft-news-distribution' ),
				Button::link( __( 'Clear filters', 'estasoft-news-distribution' ), $this->url(), array( 'variant' => 'primary' ) )
			);
		}

		return EmptyState::render(
			__( 'No pushes yet', 'estasoft-news-distribution' ),
			__( 'When News Distribution sends a notice to WebSub, IndexNow or an outlet, it is listed here with the answer it got.', 'estasoft-news-distribution' )
		);
	}

	/**
	 * One row of the table.
	 *
	 * @param array<string, mixed> $row     A decorated entry.
	 * @param int                  $now     Unix time now.
	 * @param array<string, mixed> $filters The filters in force.
	 * @return array<int, string>
	 */
	private function cells( array $row, int $now, array $filters ): array {
		$status = (string) $row['status'];
		$queued = Status::QUEUED === $status;
		$stamp  = $queued && (int) $row['run_at_ts'] > 0 ? (int) $row['run_at_ts'] : (int) $row['created_ts'];

		return array(
			Html::tag(
				'time',
				array( 'datetime' => Clock::iso( $stamp ) ),
				esc_html( $this->when( $stamp, $now ) )
			),
			$this->article( $row ),
			esc_html( (string) $row['channel_label'] ),
			esc_html( $this->event_word( (string) $row['event'] ) ),
			$this->status_cell( $row, $now ),
			Html::tag( 'span', array( 'class' => 'estasoft-nd-small' ), esc_html( $this->response( $row, $now ) ) ),
			$this->retry_form( $row ),
		);
	}

	/**
	 * The article's title, linked to its editor when the person can open it, and the language.
	 *
	 * @param array<string, mixed> $row A decorated entry.
	 * @return string
	 */
	private function article( array $row ): string {
		$post_id = (int) $row['post_id'];
		$title   = (string) $row['title'];

		if ( 0 === $post_id ) {
			$name = esc_html__( 'Several articles', 'estasoft-news-distribution' );
		} elseif ( '' === $title ) {
			$name = esc_html(
				sprintf(
					/* translators: %d: an article number. */
					__( 'Article %d, no longer on this site', 'estasoft-news-distribution' ),
					$post_id
				)
			);
		} else {
			$name = esc_html( $title );
			$edit = $row['post_exists'] && $this->plugin->access()->can_edit_article( $post_id ) ? get_edit_post_link( $post_id, 'raw' ) : '';

			if ( is_string( $edit ) && '' !== $edit ) {
				$name = Html::tag( 'a', array( 'href' => $edit ), $name );
			}
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-log-article' ),
			Html::tag(
				'span',
				array(
					'class' => 'estasoft-nd-strong',
					'dir'   => 'auto',
				),
				$name
			)
			. ( '' !== (string) $row['language'] ? Tag::lang( (string) $row['language'] ) : '' )
		);
	}

	/**
	 * The status word, with the time it waits for or the attempt it is on.
	 *
	 * @param array<string, mixed> $row A decorated entry.
	 * @param int                  $now Unix time now.
	 * @return string
	 */
	private function status_cell( array $row, int $now ): string {
		$status  = (string) $row['status'];
		$attempt = sprintf(
			/* translators: 1: the attempt this entry is, 2: the most attempts there are. */
			__( 'Attempt %1$d of %2$d', 'estasoft-news-distribution' ),
			max( 1, (int) $row['attempt'] ),
			Retry::MAX_ATTEMPTS
		);
		$line = '';
		$word = null;

		if ( Status::QUEUED === $status ) {
			if ( (int) $row['run_at_ts'] > 0 ) {
				$word = sprintf(
					/* translators: %s: a time such as 14:38. */
					__( 'Waiting %s', 'estasoft-news-distribution' ),
					$this->when( (int) $row['run_at_ts'], $now )
				);
			}

			$line = sprintf(
				/* translators: %s: a time such as 14:23. */
				__( 'Queued %s', 'estasoft-news-distribution' ),
				$this->when( (int) $row['created_ts'], $now )
			);
		} elseif ( in_array( $status, array( Status::RETRY, Status::SENT, Status::FAILED ), true ) ) {
			$line = $attempt;
		}

		return StatusView::inline( $status, $word )
			. ( '' === $line ? '' : Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-log-line' ), esc_html( $line ) ) );
	}

	/**
	 * What came back, or why nothing has been sent yet, in plain words.
	 *
	 * @param array<string, mixed> $row A decorated entry.
	 * @param int                  $now Unix time now.
	 * @return string
	 */
	private function response( array $row, int $now ): string {
		$status  = (string) $row['status'];
		$message = trim( (string) $row['message'] );
		$run     = (int) $row['run_at_ts'];

		if ( Status::QUEUED === $status && '' === $message ) {
			if ( $run <= 0 ) {
				return __( 'Waiting in the background queue. Nothing has been sent yet.', 'estasoft-news-distribution' );
			}

			if ( $run > $now ) {
				$minutes = (int) round( max( 0, $run - (int) $row['created_ts'] ) / MINUTE_IN_SECONDS );

				if ( $minutes >= 1 ) {
					return sprintf(
						/* translators: %s: a number of minutes. */
						_n( 'Waits for the %s-minute delay. Nothing has been sent yet.', 'Waits for the %s-minute delay. Nothing has been sent yet.', $minutes, 'estasoft-news-distribution' ),
						number_format_i18n( $minutes )
					);
				}

				return __( 'Waiting to be sent. Nothing has been sent yet.', 'estasoft-news-distribution' );
			}

			return sprintf(
				/* translators: %s: a time such as 14:38. */
				__( 'It was due at %s. The background queue has not run it yet, so nothing has been sent.', 'estasoft-news-distribution' ),
				$this->when( $run, $now )
			);
		}

		if ( '' !== $message ) {
			return $message;
		}

		if ( Status::FAILED === $status ) {
			return __( 'News Distribution stopped trying. Check the channel, then retry.', 'estasoft-news-distribution' );
		}

		if ( (int) $row['response_code'] > 0 ) {
			return sprintf(
				/* translators: %d: an HTTP answer code such as 200. */
				__( 'Answer %d.', 'estasoft-news-distribution' ),
				(int) $row['response_code']
			);
		}

		return '';
	}

	/**
	 * The "Retry now" button, for a push that can be sent again.
	 *
	 * @param array<string, mixed> $row A decorated entry.
	 * @return string Empty when there is nothing to retry.
	 */
	private function retry_form( array $row ): string {
		if ( empty( $row['can_retry'] ) ) {
			return '';
		}

		$name = '' === (string) $row['title']
			? (string) $row['channel_label']
			: (string) $row['title'] . ', ' . (string) $row['channel_label'];

		return Html::tag(
			'form',
			array(
				'method' => 'post',
				'action' => $this->address(),
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => self::FIELD,
					'value' => 'retry',
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'entry',
					'value' => (string) (int) $row['id'],
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => '_wpnonce',
					'value' => wp_create_nonce( self::NONCE ),
				)
			)
			. Button::submit(
				__( 'Retry now', 'estasoft-news-distribution' ),
				array(
					'size'  => 'sm',
					'attrs' => array(
						'aria-label' => sprintf(
							/* translators: %s: an article title and a channel name. */
							__( 'Retry now: %s', 'estasoft-news-distribution' ),
							$name
						),
					),
				)
			)
		);
	}

	/**
	 * The line under the table: how many are shown, how long they are kept, and the way to see more.
	 *
	 * @param array<string, mixed> $filters The filters in force.
	 * @param int                  $shown   Rows on this page.
	 * @param int                  $total   Rows that match.
	 * @return string
	 */
	private function footer( array $filters, int $shown, int $total ): string {
		$from = ( ( $filters['paged'] - 1 ) * $filters['per_page'] ) + 1;

		if ( $total <= $shown && 1 === $filters['paged'] ) {
			$count = sprintf(
				/* translators: %s: the number of entries. */
				_n( 'Showing %s entry.', 'Showing all %s entries.', $total, 'estasoft-news-distribution' ),
				number_format_i18n( $total )
			);
		} elseif ( 1 === $filters['paged'] ) {
			$count = sprintf(
				/* translators: 1: entries shown, 2: entries in all. */
				__( 'Showing the newest %1$s of %2$s entries.', 'estasoft-news-distribution' ),
				number_format_i18n( $shown ),
				number_format_i18n( $total )
			);
		} else {
			$count = sprintf(
				/* translators: 1: the first entry shown, 2: the last entry shown, 3: entries in all. */
				__( 'Showing entries %1$s to %2$s of %3$s.', 'estasoft-news-distribution' ),
				number_format_i18n( $from ),
				number_format_i18n( $from + $shown - 1 ),
				number_format_i18n( $total )
			);
		}

		$days = $this->plugin->settings()->log_days();
		$text = $count . ' ' . sprintf(
			/* translators: %s: a number of days. */
			_n( 'Entries are kept for %s day.', 'Entries are kept for %s days.', $days, 'estasoft-news-distribution' ),
			number_format_i18n( $days )
		);

		$link = '';

		if ( $this->plugin->access()->can( Access::MANAGE ) ) {
			$link = ' ' . Html::tag(
				'a',
				array(
					'href' => add_query_arg(
						array(
							'page'    => 'estasoft-nd-settings',
							'section' => 'general',
						),
						admin_url( 'admin.php' )
					),
				),
				esc_html__( 'Change this in Settings', 'estasoft-news-distribution' )
			);
		}

		$html = Html::tag( 'span', array( 'class' => 'estasoft-nd-grow' ), esc_html( $text ) . $link );

		$more  = $total > ( $filters['paged'] * $filters['per_page'] );
		$links = '';

		if ( $filters['paged'] > 1 ) {
			$links .= Button::link(
				__( 'Newer entries', 'estasoft-news-distribution' ),
				$this->address( array( 'paged' => $filters['paged'] > 2 ? $filters['paged'] - 1 : '' ) ),
				array( 'size' => 'sm' )
			);
		}

		if ( $more && $filters['per_page'] < 100 ) {
			$links .= Button::link(
				__( 'Show more entries', 'estasoft-news-distribution' ),
				$this->address(
					array(
						'per_page' => min( 100, $filters['per_page'] + self::START ),
						'paged'    => '',
					)
				),
				array( 'size' => 'sm' )
			);
		} elseif ( $more ) {
			$links .= Button::link( __( 'Older entries', 'estasoft-news-distribution' ), $this->address( array( 'paged' => $filters['paged'] + 1 ) ), array( 'size' => 'sm' ) );
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-log-footer' ), $html . ( '' === $links ? '' : Html::tag( 'span', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $links ) ) );
	}

	/**
	 * A time as "14:38", or "20 Oct, 14:38" when it is not today.
	 *
	 * @param int $stamp Unix time.
	 * @param int $now   Unix time now.
	 * @return string
	 */
	private function when( int $stamp, int $now ): string {
		$today = Clock::format( $stamp, 'Y-m-d' ) === Clock::format( $now, 'Y-m-d' );

		return Clock::format( $stamp, $today ? 'H:i' : 'j M, H:i' );
	}

	/**
	 * The word for what was pushed.
	 *
	 * @param string $event An Event value.
	 * @return string
	 */
	private function event_word( string $event ): string {
		switch ( $event ) {
			case Event::PUBLISH:
				return __( 'Published', 'estasoft-news-distribution' );
			case Event::UPDATE:
				return __( 'Updated', 'estasoft-news-distribution' );
			case Event::UNPUBLISH:
			case Event::DELETE:
				return __( 'Removed', 'estasoft-news-distribution' );
		}

		return $event;
	}
}

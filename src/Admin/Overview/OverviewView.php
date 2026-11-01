<?php
/**
 * The pieces of the Overview screen, as escaped HTML.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Overview;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Table;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Retry;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Prints what `OverviewData` built. Words for a status come from `Core\Status`; the
 * sentences here only add numbers, times and names to them.
 */
final class OverviewView {

	/**
	 * The order status counts are listed in under a collapsed group.
	 *
	 * @var string[]
	 */
	private const COUNT_ORDER = array(
		Status::ATTENTION,
		Status::HEALTHY,
		Status::PROCESSING,
		Status::IN_REVIEW,
		Status::REQUESTED,
		Status::LISTED,
		Status::DECLINED,
		Status::NOT_REQUESTED,
		Status::NOT_SET_UP,
	);

	/**
	 * The section heading with an optional link at its end.
	 *
	 * @param string $id    Unique id for the heading.
	 * @param string $title Plain title.
	 * @param string $count Trusted HTML after the title (a count), or empty.
	 * @param string $end   Trusted HTML at the end of the line, or empty.
	 * @return string
	 */
	public function heading( string $id, string $title, string $count = '', string $end = '' ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ov-head' ),
			Html::tag(
				'h2',
				array(
					'class' => 'estasoft-nd-title',
					'id'    => $id,
				),
				esc_html( $title ) . ( '' !== $count ? ' ' . $count : '' )
			) . $end
		);
	}

	/**
	 * The "Needs attention" list.
	 *
	 * @param array<int, array<string, mixed>> $items What `NeedsYou` built.
	 * @return string
	 */
	public function needs( array $items ): string {
		$rows = '';

		foreach ( $items as $item ) {
			$info   = Status::describe( (string) $item['status'] );
			$action = null === $item['action'] ? '' : Button::link( (string) $item['action']['label'], (string) $item['action']['url'] );

			$rows .= Html::tag(
				'li',
				array( 'class' => 'estasoft-nd-att estasoft-nd-ov-att' ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-ov-icon estasoft-nd-ov-icon--' . $info['tone'] ), Icon::svg( $info['icon'] ) )
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-ov-att__text' ),
					Html::tag( 'p', array( 'class' => 'estasoft-nd-strong' ), esc_html( (string) $item['title'] ) )
					. ( '' === $item['detail'] ? '' : Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( (string) $item['detail'] ) ) )
				)
				. $action
			);
		}

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-ov-section',
				'aria-labelledby' => 'estasoft-nd-ov-needs',
			),
			$this->heading( 'estasoft-nd-ov-needs', __( 'Needs attention', 'estasoft-news-distribution' ), Tag::count( count( $items ) ) )
			. Html::tag( 'ul', array( 'class' => 'estasoft-nd-card estasoft-nd-card--flush' ), $rows )
		);
	}

	/**
	 * The message that says nothing needs attention.
	 *
	 * @return string
	 */
	public function all_clear(): string {
		return Message::bar(
			'success',
			Html::tag( 'strong', array(), esc_html__( 'No problems found.', 'estasoft-news-distribution' ) )
			. ' ' . esc_html__( 'Nothing needs attention: no article is skipped and no push is retrying. News Distribution cannot see what platforms do with your feeds after they fetch them.', 'estasoft-news-distribution' ),
			Html::tag( 'a', array( 'href' => Links::check() ), esc_html__( 'Open article check', 'estasoft-news-distribution' ) )
		);
	}

	/**
	 * The channel groups. Groups with something to look at are open cards; the rest fold into one list.
	 *
	 * @param array<int, array<string, mixed>> $groups What `OverviewData::groups()` built.
	 * @return string
	 */
	public function channels( array $groups ): string {
		$by_id = array();
		$open  = false;

		foreach ( $groups as $group ) {
			$by_id[ (string) $group['id'] ] = $group;
			$open                           = $open || $group['open'];
		}

		if ( $open ) {
			$columns = array(
				array( Group::SITEMAP, Group::PUSHES ),
				array( Group::PARTNER, Group::READERS, Group::LISTINGS ),
			);
		} else {
			$columns = array( array_keys( Group::all() ) );
		}

		$html = '';

		foreach ( $columns as $ids ) {
			$list = array();

			foreach ( $ids as $id ) {
				if ( isset( $by_id[ $id ] ) ) {
					$list[] = $by_id[ $id ];
				}
			}

			if ( array() !== $list ) {
				$html .= Html::tag( 'div', array( 'class' => 'estasoft-nd-ov-column' ), $this->column( $list ) );
			}
		}

		return Html::tag( 'div', array( 'class' => Html::classes( 'estasoft-nd-ov-columns', $open ? '' : 'estasoft-nd-ov-columns--one' ) ), $html );
	}

	/**
	 * One column of groups. Neighbouring folded groups share one card.
	 *
	 * @param array<int, array<string, mixed>> $groups Groups in the column.
	 * @return string
	 */
	private function column( array $groups ): string {
		$html   = '';
		$folded = '';
		$flush  = static function ( string $folded ): string {
			return '' === $folded ? '' : Html::tag( 'div', array( 'class' => 'estasoft-nd-card estasoft-nd-card--flush estasoft-nd-ov-folded' ), $folded );
		};

		foreach ( $groups as $group ) {
			if ( $group['open'] ) {
				$html  .= $flush( $folded ) . $this->open_group( $group );
				$folded = '';
			} else {
				$folded .= $this->folded_group( $group );
			}
		}

		return $html . $flush( $folded );
	}

	/**
	 * A group card with its channels listed.
	 *
	 * @param array<string, mixed> $group The group.
	 * @return string
	 */
	private function open_group( array $group ): string {
		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--flush',
				'aria-labelledby' => 'estasoft-nd-ov-group-' . $group['id'],
			),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-group__head' ),
				Html::tag(
					'h3',
					array(
						'class' => 'estasoft-nd-title',
						'id'    => 'estasoft-nd-ov-group-' . $group['id'],
					),
					esc_html( (string) $group['title'] )
				) . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( (string) $group['help'] ) )
			) . $this->group_rows( $group )
		);
	}

	/**
	 * A group that is closed until the person opens it.
	 *
	 * @param array<string, mixed> $group The group.
	 * @return string
	 */
	private function folded_group( array $group ): string {
		return Html::tag(
			'details',
			array( 'class' => 'estasoft-nd-collapse' ),
			Html::tag(
				'summary',
				array(),
				Html::tag(
					'div',
					array(),
					Html::tag( 'h3', array( 'class' => 'estasoft-nd-title' ), esc_html( (string) $group['title'] ) )
					. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $this->counts_line( $group ) ) )
				)
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-strong' ), esc_html__( 'Show', 'estasoft-news-distribution' ) )
			) . $this->group_rows( $group )
		);
	}

	/**
	 * The channel rows of a group.
	 *
	 * @param array<string, mixed> $group The group.
	 * @return string
	 */
	private function group_rows( array $group ): string {
		$rows = '';

		foreach ( $group['rows'] as $row ) {
			$rows .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-group-row' ),
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-group-row__name' ),
					Html::tag(
						'a',
						array(
							'class' => 'estasoft-nd-strong',
							'href'  => (string) $row['url'],
						),
						esc_html( (string) $row['name'] )
					)
					. ( '' === $row['summary'] ? '' : Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( (string) $row['summary'] ) ) )
				) . StatusView::pill( (string) $row['status'] )
			);
		}

		return $rows;
	}

	/**
	 * "4 channels · 2 healthy · 1 processing · 1 not set up".
	 *
	 * @param array<string, mixed> $group The group.
	 * @return string
	 */
	private function counts_line( array $group ): string {
		$parts  = array(
			sprintf(
				/* translators: %s: a number of channels. */
				_n( '%s channel', '%s channels', (int) $group['total'], 'estasoft-news-distribution' ),
				number_format_i18n( (int) $group['total'] )
			),
		);
		$counts = (array) $group['counts'];
		$order  = array_merge( self::COUNT_ORDER, array_diff( array_keys( $counts ), self::COUNT_ORDER ) );

		foreach ( $order as $code ) {
			if ( ! empty( $counts[ $code ] ) ) {
				$parts[] = number_format_i18n( (int) $counts[ $code ] ) . ' ' . Facts::lower( Status::word( (string) $code ) );
			}
		}

		return implode( ' · ', $parts );
	}

	/**
	 * The latest articles, as a matrix by channel group or as one result each.
	 *
	 * @param array<int, array<string, mixed>> $rows   What `ArticleRows` built.
	 * @param bool                             $matrix True for the matrix, false for one result per article.
	 * @return string
	 */
	public function articles( array $rows, bool $matrix ): string {
		if ( array() === $rows ) {
			return Html::tag( 'div', array( 'class' => 'estasoft-nd-card estasoft-nd-card--padded' ), Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No articles are published yet. They appear here as soon as one is.', 'estasoft-news-distribution' ) ) );
		}

		$body = array();

		foreach ( $rows as $row ) {
			$title = Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack' ),
				Html::tag(
					'a',
					array(
						'class' => 'estasoft-nd-strong',
						'href'  => Links::check( (int) $row['id'] ),
					),
					esc_html( (string) $row['title'] )
				) . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $this->published( $row ) ) )
			);

			$cells = array( $title, Tag::lang( (string) $row['language'] ) );

			if ( $matrix ) {
				foreach ( ArticleRows::groups() as $group ) {
					$cells[] = $this->cell( $group, (array) $row['groups'][ $group ] );
				}
			} else {
				$cells[] = $this->result( $row );
			}

			$body[] = $cells;
		}

		if ( $matrix ) {
			$head = array(
				__( 'Article', 'estasoft-news-distribution' ),
				__( 'Language', 'estasoft-news-distribution' ),
				__( 'Sitemaps', 'estasoft-news-distribution' ),
				Group::all()[ Group::PARTNER ]['title'],
				Group::all()[ Group::READERS ]['title'],
				Group::all()[ Group::PUSHES ]['title'],
			);
			$cols = 'estasoft-nd-ov-cols-matrix';
		} else {
			$head = array(
				__( 'Article', 'estasoft-news-distribution' ),
				__( 'Language', 'estasoft-news-distribution' ),
				__( 'Result', 'estasoft-news-distribution' ),
			);
			$cols = 'estasoft-nd-ov-cols-result';
		}

		$table = Table::render( $cols, $head, $body, __( 'Latest articles', 'estasoft-news-distribution' ), $this->legend() );

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-ov-articles' ), $table . $this->article_list( $rows ) );
	}

	/**
	 * "12 min ago", from the article's publish time.
	 *
	 * @param array<string, mixed> $row The article row.
	 * @return string
	 */
	private function published( array $row ): string {
		$time = null === $row['published'] ? 0 : (int) strtotime( (string) $row['published'] );

		return $time > 0 ? Facts::ago( $time ) : '';
	}

	/**
	 * The key under the table: what each icon means.
	 *
	 * @return string
	 */
	private function legend(): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ov-legend' ),
			StatusView::inline( Status::IN_FEED, __( 'In feed or sent', 'estasoft-news-distribution' ) )
			. StatusView::inline( Status::QUEUED, __( 'Waiting, with the time it goes out', 'estasoft-news-distribution' ) )
			. StatusView::inline( Status::RETRY )
			. StatusView::inline( Status::FAILED, __( 'Skipped (breaks a channel’s rule) or Failed (retries stopped)', 'estasoft-news-distribution' ) )
			. StatusView::inline( Status::OFF, __( 'Channel is off or not set up', 'estasoft-news-distribution' ) )
		);
	}

	/**
	 * One matrix cell: what a group of channels did with the article.
	 *
	 * @param string               $group The group id.
	 * @param array<string, mixed> $cell  The group's result.
	 * @return string
	 */
	private function cell( string $group, array $cell ): string {
		$status   = (string) $cell['status'];
		$channels = (array) $cell['channels'];
		$time     = null === $cell['at'] ? '' : Facts::clock( (int) strtotime( (string) $cell['at'] ) );
		$labels   = array();

		foreach ( $channels as $channel ) {
			if ( $channel['status'] === $status ) {
				$labels[] = (string) $channel['label'];
			}
		}

		switch ( $status ) {
			case Status::OFF:
				return StatusView::inline( Status::OFF );

			case Status::QUEUED:
				return StatusView::inline( Status::QUEUED, trim( Status::word( Status::QUEUED ) . ' ' . $time ) );

			case Status::RETRY:
				return $this->with_detail(
					StatusView::inline( Status::RETRY ),
					sprintf(
						/* translators: 1: channel names, 2: a clock time such as 14:37. */
						__( '%1$s, next try %2$s', 'estasoft-news-distribution' ),
						implode( ', ', $labels ),
						$time
					)
				);

			case Status::FAILED:
				return $this->with_detail( StatusView::inline( Status::FAILED ), $this->failure_names( $channels ) );

			case Status::SKIPPED:
				if ( Group::PUSHES === $group ) {
					return $this->with_detail( StatusView::inline( Status::SKIPPED ), implode( ', ', $labels ) );
				}

				return $this->with_detail(
					StatusView::inline( Status::SKIPPED, $this->count_text( $group, $cell, Status::IN_FEED ) ),
					sprintf(
						/* translators: %s: channel names. */
						__( '%s skipped', 'estasoft-news-distribution' ),
						implode( ', ', $labels )
					)
				);
		}

		return StatusView::inline( $status, $this->count_text( $group, $cell, $status ) );
	}

	/**
	 * "3 of 3 in feed", or the status word when the group has one channel.
	 *
	 * @param string               $group  The group id.
	 * @param array<string, mixed> $cell   The group's result.
	 * @param string               $status The status whose word to use for one channel.
	 * @return string
	 */
	private function count_text( string $group, array $cell, string $status ): string {
		$total = (int) $cell['total'];
		$done  = (int) $cell['done'];

		if ( $total < 2 ) {
			return Status::word( Status::SKIPPED === $status ? Status::IN_FEED : $status );
		}

		if ( Group::PUSHES === $group ) {
			return sprintf(
				/* translators: 1: channels that got the article, 2: channels in the group. */
				__( '%1$d of %2$d sent', 'estasoft-news-distribution' ),
				$done,
				$total
			);
		}

		return sprintf(
			/* translators: 1: channels that carry the article, 2: channels in the group. */
			__( '%1$d of %2$d in feed', 'estasoft-news-distribution' ),
			$done,
			$total
		);
	}

	/**
	 * A status with a small line under it.
	 *
	 * @param string $status Trusted HTML of the status.
	 * @param string $detail Plain detail.
	 * @return string
	 */
	private function with_detail( string $status, string $detail ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-2' ),
			$status . ( '' === $detail ? '' : Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $detail ) ) )
		);
	}

	/**
	 * Names of the channels whose delivery failed: "Outlets: Newsletter tool" when the message says which target.
	 *
	 * @param array<string, array<string, mixed>> $channels The group's channels.
	 * @return string
	 */
	private function failure_names( array $channels ): string {
		$names = array();

		foreach ( $channels as $channel ) {
			if ( Status::FAILED !== $channel['status'] ) {
				continue;
			}

			$target = array();

			if ( 1 === preg_match( '/^([^:.]{2,40}):\s/', (string) $channel['message'], $target ) ) {
				$names[] = (string) $channel['label'] . ': ' . $target[1];
			} else {
				$names[] = (string) $channel['label'];
			}
		}

		return implode( ', ', $names );
	}

	/**
	 * The result of one article in a single cell: the worst channel and what to do about it.
	 *
	 * @param array<string, mixed> $row The article row.
	 * @return string
	 */
	private function result( array $row ): string {
		$overall = (array) $row['overall'];
		$status  = (string) $overall['status'];
		$time    = null === $overall['at'] ? '' : Facts::clock( (int) strtotime( (string) $overall['at'] ) );
		$label   = (string) $overall['label'];
		$detail  = '';
		$show    = Status::word( $status );

		switch ( $status ) {
			case Status::FAILED:
				$show = sprintf(
					/* translators: %s: a channel name such as Outlets. */
					__( 'Failed in %s', 'estasoft-news-distribution' ),
					$label
				);

				if ( $this->pulled_ok( $row ) ) {
					$detail = __( 'In feed on every pulled channel.', 'estasoft-news-distribution' ) . ' ';
				}

				$detail .= (string) $overall['message'];
				break;

			case Status::RETRY:
				$detail = sprintf(
					/* translators: 1: a channel name, 2: the attempt, 3: the most attempts, 4: a clock time. */
					__( '%1$s, attempt %2$d of %3$d, next try %4$s', 'estasoft-news-distribution' ),
					$label,
					(int) $overall['attempt'],
					Retry::MAX_ATTEMPTS,
					$time
				);
				break;

			case Status::SKIPPED:
				$show = sprintf(
					/* translators: %s: a channel name such as Microsoft Start. */
					__( 'Skipped by %s', 'estasoft-news-distribution' ),
					$label
				);
				$detail = $this->first_sentence( (string) $overall['message'] );
				break;

			case Status::QUEUED:
				$show   = trim( $show . ' ' . $time );
				$detail = $this->waiting_text( $row, $time );
				break;

			default:
				if ( Status::OFF === $status && '' !== (string) $overall['message'] ) {
					// Every channel that has the article says Off and why (demo mode, a language turned off): not "In feed".
					$detail = $this->first_sentence( (string) $overall['message'] );
					break;
				}

				$status = 'both' === $overall['ok'] ? Status::IN_FEED : (string) $overall['ok'];
				$show   = 'both' === $overall['ok'] ? __( 'In feed and sent', 'estasoft-news-distribution' ) : Status::word( $status );
		}

		return $this->with_detail( StatusView::inline( $status, $show ), trim( $detail ) );
	}

	/**
	 * Whether every pulled channel that has the article carries it.
	 *
	 * @param array<string, mixed> $row The article row.
	 * @return bool
	 */
	private function pulled_ok( array $row ): bool {
		$seen = false;

		foreach ( array( Group::SITEMAP, Group::PARTNER, Group::READERS ) as $group ) {
			$status = (string) $row['groups'][ $group ]['status'];

			if ( ! in_array( $status, array( Status::IN_FEED, Status::SENT, Status::OFF ), true ) ) {
				return false;
			}

			$seen = $seen || Status::OFF !== $status;
		}

		return $seen;
	}

	/**
	 * "In the sitemaps now. Partner feeds, open readers and pushes go out at 14:38."
	 *
	 * @param array<string, mixed> $row  The article row.
	 * @param string               $time The clock time.
	 * @return string
	 */
	private function waiting_text( array $row, string $time ): string {
		$text  = '';
		$names = array();

		if ( in_array( (string) $row['groups'][ Group::SITEMAP ]['status'], array( Status::IN_FEED, Status::SENT ), true ) ) {
			$text = __( 'In the sitemaps now.', 'estasoft-news-distribution' ) . ' ';
		}

		foreach ( (array) $row['overall']['waiting_groups'] as $group ) {
			$title   = (string) Group::all()[ $group ]['title'];
			$names[] = array() === $names ? $title : Facts::lower( $title );
		}

		if ( array() !== $names ) {
			$text .= sprintf(
				/* translators: 1: a list of channel group names such as "partner feeds and pushes", 2: a clock time. */
				__( '%1$s go out at %2$s.', 'estasoft-news-distribution' ),
				wp_sprintf( '%l', $names ),
				$time
			);
		}

		return trim( $text );
	}

	/**
	 * The text up to the first full stop.
	 *
	 * @param string $text A sentence or two.
	 * @return string
	 */
	private function first_sentence( string $text ): string {
		$end = strpos( $text, '. ' );

		return false === $end ? $text : substr( $text, 0, $end + 1 );
	}

	/**
	 * The narrow-screen list of articles: title, language and time, then one line of result.
	 *
	 * @param array<int, array<string, mixed>> $rows The article rows.
	 * @return string
	 */
	private function article_list( array $rows ): string {
		$items = '';

		foreach ( $rows as $row ) {
			$items .= Html::tag(
				'li',
				array( 'class' => 'estasoft-nd-ov-item' ),
				Html::tag(
					'a',
					array(
						'class' => 'estasoft-nd-strong',
						'href'  => Links::check( (int) $row['id'] ),
					),
					esc_html( (string) $row['title'] )
				)
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
					Tag::lang( (string) $row['language'] ) . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $this->published( $row ) ) )
				)
				. $this->short_result( $row )
			);
		}

		return Html::tag(
			'ul',
			array(
				'class'      => 'estasoft-nd-card estasoft-nd-card--flush estasoft-nd-ov-list',
				'aria-label' => __( 'Latest articles', 'estasoft-news-distribution' ),
			),
			$items
		);
	}

	/**
	 * One short line for a narrow screen: "WebSub retrying, next try 14:37".
	 *
	 * @param array<string, mixed> $row The article row.
	 * @return string
	 */
	private function short_result( array $row ): string {
		$overall = (array) $row['overall'];
		$status  = (string) $overall['status'];
		$label   = (string) $overall['label'];
		$time    = null === $overall['at'] ? '' : Facts::clock( (int) strtotime( (string) $overall['at'] ) );
		$show    = Status::word( $status );

		switch ( $status ) {
			case Status::QUEUED:
				$show = trim( $show . ' ' . $time );
				break;

			case Status::RETRY:
				$show = sprintf(
					/* translators: 1: a channel name such as WebSub, 2: a clock time such as 14:37. */
					__( '%1$s retrying, next try %2$s', 'estasoft-news-distribution' ),
					$label,
					$time
				);
				break;

			case Status::FAILED:
				$show = sprintf(
					/* translators: %s: a channel name such as Outlets. */
					__( '%s failed', 'estasoft-news-distribution' ),
					$label
				);
				break;

			case Status::SKIPPED:
				$show = sprintf(
					/* translators: %s: a channel name such as Microsoft Start. */
					__( '%s skipped', 'estasoft-news-distribution' ),
					$label
				);
				break;

			default:
				if ( Status::OFF === $status && '' !== (string) $overall['message'] ) {
					break;
				}

				$status = 'both' === $overall['ok'] ? Status::IN_FEED : (string) $overall['ok'];
				$show   = 'both' === $overall['ok'] ? __( 'In feed and sent', 'estasoft-news-distribution' ) : Status::word( $status );
		}

		return StatusView::inline( $status, $show );
	}

	/**
	 * The path-to-each-platform card.
	 *
	 * @param array<string, mixed> $path  What `PlatformPath::build()` returned.
	 * @param string               $mode  The person's choice: when_live, always.
	 * @param string               $nonce Trusted HTML of the nonce fields for the form at the bottom.
	 * @param string               $post  The address the form posts to.
	 * @return string
	 */
	public function path( array $path, string $mode, string $nonce, string $post ): string {
		$sections = '';

		foreach ( $path['sections'] as $section ) {
			$rows = '';

			foreach ( $section['rows'] as $row ) {
				$rows .= Html::tag(
					'li',
					array( 'class' => 'estasoft-nd-ov-prow' ),
					Html::tag( 'span', array( 'class' => 'estasoft-nd-strong' ), esc_html( (string) $row['name'] ) )
					. $this->steps_bar( (string) $row['name'], (int) $row['done'], (int) $row['total'] )
					. Html::tag( 'span', array( 'class' => 'estasoft-nd-ov-prow__text' ), esc_html( (string) $row['text'] ) )
					. Button::link(
						(string) $row['action']['label'],
						(string) $row['action']['url'],
						array(
							'size'    => 'sm',
							'variant' => $row['action']['primary'] ? 'primary' : '',
							'attrs'   => array(
								'aria-label' => sprintf(
									/* translators: 1: a button label such as Open, 2: a platform name such as Bing. */
									__( '%1$s: %2$s', 'estasoft-news-distribution' ),
									(string) $row['action']['label'],
									(string) $row['name']
								),
							),
						)
					)
				);
			}

			$sections .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ov-psection' ),
				Html::tag(
					'h3',
					array(
						'class' => 'estasoft-nd-label',
						'id'    => 'estasoft-nd-ov-path-' . $section['id'],
					),
					esc_html( (string) $section['title'] )
				) . Html::tag(
					'ul',
					array( 'aria-labelledby' => 'estasoft-nd-ov-path-' . $section['id'] ),
					$rows
				)
			);
		}

		$form = Html::tag(
			'form',
			array(
				'class'  => 'estasoft-nd-ov-pathfoot',
				'method' => 'post',
				'action' => $post,
			),
			$nonce
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'action',
					'value' => 'estasoft_nd_overview_path',
				)
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4 estasoft-nd-grow' ),
				Html::tag(
					'label',
					array( 'class' => 'estasoft-nd-check' ),
					Html::void(
						'input',
						array(
							'type'  => 'hidden',
							'name'  => 'hide_when_live',
							'value' => '0',
						)
					)
					. Html::void(
						'input',
						array(
							'type'             => 'checkbox',
							'name'             => 'hide_when_live',
							'value'            => '1',
							'checked'          => 'always' !== $mode,
							'data-auto-submit' => '1',
						)
					)
					. Html::tag( 'span', array(), esc_html__( 'Hide this card once every platform I started is live', 'estasoft-news-distribution' ) )
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Platforms you have not started do not count. Start one when you are ready, or hide this card now.', 'estasoft-news-distribution' ) )
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
				Button::submit( __( 'Save', 'estasoft-news-distribution' ), array( 'class' => 'estasoft-nd-ov-nojs' ) )
				. Button::submit(
					__( 'Hide this card', 'estasoft-news-distribution' ),
					array(
						'variant' => 'subtle',
						'attrs'   => array(
							'name'  => 'hide_now',
							'value' => '1',
						),
					)
				)
				. Html::tag( 'a', array( 'href' => Links::admin( 'estasoft-nd-reports', array( 'report' => 'readiness' ) ) ), esc_html__( 'Make a readiness report', 'estasoft-news-distribution' ) )
			)
		);

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--flush estasoft-nd-ov-path',
				'aria-labelledby' => 'estasoft-nd-ov-path',
			),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-card-head' ),
				Html::tag(
					'h2',
					array(
						'class' => 'estasoft-nd-title',
						'id'    => 'estasoft-nd-ov-path',
					),
					esc_html__( 'Your path to each platform', 'estasoft-news-distribution' )
				) . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'What is done, what a platform decides, and the next thing you can do.', 'estasoft-news-distribution' ) )
			) . $sections . $form
		);
	}

	/**
	 * A progress bar with its words: "2 of 3 steps".
	 *
	 * @param string $name  What is measured.
	 * @param int    $done  Steps done.
	 * @param int    $total Steps in all.
	 * @return string
	 */
	private function steps_bar( string $name, int $done, int $total ): string {
		$total   = max( 1, $total );
		$done    = max( 0, min( $total, $done ) );
		$percent = (int) round( $done / $total * 100 );
		$words   = sprintf(
			/* translators: 1: steps done, 2: steps in all. */
			_n( '%1$d of %2$d step', '%1$d of %2$d steps', $total, 'estasoft-news-distribution' ),
			$done,
			$total
		);

		return Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
			Html::tag(
				'span',
				array(
					'class'         => Html::classes( 'estasoft-nd-progress', $done === $total ? 'estasoft-nd-progress--done' : '' ),
					'role'          => 'progressbar',
					'aria-valuemin' => '0',
					'aria-valuemax' => (string) $total,
					'aria-valuenow' => (string) $done,
					'aria-label'    => $name . ': ' . $words,
				),
				Html::tag( 'span', array( 'style' => 'width:' . $percent . '%' ), '' )
			) . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $words ) )
		);
	}
}

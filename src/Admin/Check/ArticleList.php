<?php
/**
 * The list of recent articles on the Article check screen, and the banner above it.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Check;

use Estasoft\NewsDistribution\Admin\Ui\CommandBar;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Checks\ArticleChecker;
use Estasoft\NewsDistribution\Core\ArticleService;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Reads through `Core\ArticleService::list()`, so a person who may only see their own articles
 * only gets those. Each row has the title, the language, how long ago, one icon for each of
 * four groups (sitemaps, partner feeds, open readers, pushes) and one short note.
 */
final class ArticleList {

	public const PAGE = 'estasoft-nd-check';

	public const STEP = 10;

	/**
	 * The four groups the icons stand for, in order.
	 *
	 * @var string[]
	 */
	private const ICON_GROUPS = array( Group::SITEMAP, Group::PARTNER, Group::READERS, Group::PUSHES );

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * What the address asks for: the filter, the search words and how many rows to show.
	 *
	 * @return array{filter: string, search: string, show: int}
	 */
	public function state(): array {
		// These only choose what is listed. Nothing is changed by them.
		$filter = Query::key( 'filter' );
		$search = Query::text( 's' );
		$show   = Query::int( 'show' );

		return array(
			'filter' => in_array( $filter, array( 'problems', 'waiting' ), true ) ? $filter : 'all',
			'search' => $search,
			'show'   => $show < 1 ? self::STEP : min( 100, $show ),
		);
	}

	/**
	 * One page of articles for a state.
	 *
	 * @param array{filter: string, search: string, show: int} $state What the address asks for.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function query( array $state ): array {
		return $this->plugin->make( ArticleService::class )->list(
			array(
				'status'   => 'all' === $state['filter'] ? '' : $state['filter'],
				'search'   => $state['search'],
				'page'     => 1,
				'per_page' => $state['show'],
			)
		);
	}

	/**
	 * The address of the screen for an article and a state.
	 *
	 * @param int                                              $post_id The article, or 0.
	 * @param array{filter: string, search: string, show: int} $state   The list state to keep.
	 * @param array<string, scalar>                            $extra   Arguments that replace the kept ones.
	 * @return string
	 */
	public function url( int $post_id, array $state, array $extra = array() ): string {
		$args = array( 'page' => self::PAGE );

		if ( $post_id > 0 ) {
			$args['post'] = $post_id;
		}

		if ( 'all' !== $state['filter'] ) {
			$args['filter'] = $state['filter'];
		}

		if ( '' !== $state['search'] ) {
			$args['s'] = $state['search'];
		}

		if ( self::STEP !== $state['show'] ) {
			$args['show'] = $state['show'];
		}

		return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
	}

	/**
	 * The banner about the channel that skips the most of the listed articles.
	 *
	 * @param array<int, array<string, mixed>>                 $items The listed articles (summaries).
	 * @param int                                              $total How many articles the filter finds in all.
	 * @param array{filter: string, search: string, show: int} $state The list state.
	 * @return string Escaped HTML, empty when no listed article is skipped.
	 */
	public function banner( array $items, int $total, array $state ): string {
		$skips = array();

		foreach ( $items as $item ) {
			foreach ( (array) $item['check']['skipped_on'] as $channel_id ) {
				$skips[ (string) $channel_id ][] = (int) $item['id'];
			}
		}

		if ( array() === $skips ) {
			return '';
		}

		uasort(
			$skips,
			static function ( array $a, array $b ): int {
				return count( $b ) <=> count( $a );
			}
		);

		$channel_id = (string) array_key_first( $skips );
		$ids        = $skips[ $channel_id ];
		$channel    = $this->plugin->registry()->get( $channel_id );
		$label      = null === $channel ? $channel_id : $channel->label();
		$count      = count( $ids );
		$reason     = $this->shared_reason( $channel_id, $ids );

		if ( count( $items ) < $total ) {
			$lead = sprintf(
				/* translators: 1: a channel name, 2: a number of articles skipped, 3: the number of latest articles looked at. */
				_n( '%1$s skips %2$s of the %3$s latest articles.', '%1$s skips %2$s of the %3$s latest articles.', $count, 'estasoft-news-distribution' ),
				$label,
				number_format_i18n( $count ),
				number_format_i18n( count( $items ) )
			);
		} else {
			$lead = sprintf(
				/* translators: 1: a channel name, 2: a number of articles. */
				_n( '%1$s skips %2$s article.', '%1$s skips %2$s articles.', $count, 'estasoft-news-distribution' ),
				$label,
				number_format_i18n( $count )
			);
		}

		return Message::bar(
			'warning',
			Html::tag( 'strong', array(), esc_html( $lead ) ) . ' ' . esc_html( $reason ),
			Html::tag(
				'a',
				array(
					'class' => 'estasoft-nd-btn estasoft-nd-btn--sm',
					'href'  => $this->url( (int) $ids[0], $state ),
				),
				esc_html( _n( 'Review it', 'Review them one by one', $count, 'estasoft-news-distribution' ) )
			)
		);
	}

	/**
	 * Why a channel skips articles: the message of the finding they share, or a pointer.
	 *
	 * @param string $channel_id Channel id.
	 * @param int[]  $ids        The skipped articles.
	 * @return string Plain text.
	 */
	private function shared_reason( string $channel_id, array $ids ): string {
		$checker = $this->plugin->make( ArticleChecker::class );
		$codes   = array();
		$message = '';

		foreach ( $ids as $id ) {
			$report = $checker->stored( $id );
			$found  = '';

			foreach ( null === $report ? array() : (array) ( $report->channels()[ $channel_id ]['findings'] ?? array() ) as $finding ) {
				if ( Status::BLOCK === ( $finding['severity'] ?? '' ) ) {
					$found = (string) $finding['code'];

					if ( '' === $message ) {
						$message = (string) $finding['message'];
					}

					break;
				}
			}

			$codes[ $found ] = true;
		}

		if ( 1 === count( $codes ) && '' !== $message ) {
			return $message;
		}

		return __( 'They are skipped for different reasons. Open the first one to see why.', 'estasoft-news-distribution' );
	}

	/**
	 * The card with the search, the filter and the rows.
	 *
	 * @param array<int, array<string, mixed>>                 $items   The listed articles (summaries).
	 * @param int                                              $total   How many articles the filter finds in all.
	 * @param array{filter: string, search: string, show: int} $state   The list state.
	 * @param int                                              $current The open article, or 0.
	 * @return string Escaped HTML.
	 */
	public function render( array $items, int $total, array $state, int $current ): string {
		$segments = array(
			'all'      => __( 'All', 'estasoft-news-distribution' ),
			'problems' => __( 'Has problems', 'estasoft-news-distribution' ),
			'waiting'  => __( 'Waiting', 'estasoft-news-distribution' ),
		);
		$links    = array();

		foreach ( $segments as $value => $label ) {
			$kept            = $state;
			$kept['filter']  = $value;
			$kept['show']    = self::STEP;
			$links[ $value ] = array(
				'label' => $label,
				'url'   => $this->url( 0, $kept ),
			);
		}

		$search = Html::tag(
			'form',
			array(
				'class'  => 'estasoft-nd-list-search',
				'method' => 'get',
				'action' => admin_url( 'admin.php' ),
				'role'   => 'search',
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'page',
					'value' => self::PAGE,
				)
			)
			. ( 'all' === $state['filter'] ? '' : Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'filter',
					'value' => $state['filter'],
				)
			) )
			. Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-sr',
					'for'   => 'estasoft-nd-list-s',
				),
				esc_html__( 'Search articles', 'estasoft-news-distribution' )
			)
			. Html::void(
				'input',
				array(
					'class'       => 'estasoft-nd-field estasoft-nd-w-full',
					'type'        => 'search',
					'id'          => 'estasoft-nd-list-s',
					'name'        => 's',
					'value'       => $state['search'],
					'placeholder' => __( 'Search articles', 'estasoft-news-distribution' ),
				)
			)
			. CommandBar::segments( $links, $state['filter'], __( 'Show', 'estasoft-news-distribution' ) )
		);

		$rows = '';

		foreach ( $items as $item ) {
			$rows .= $this->row( $item, (int) $item['id'] === $current, $state );
		}

		if ( '' === $rows ) {
			$rows = Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-pad' ), esc_html( $this->empty_text( $state ) ) );
		} else {
			$rows = Html::tag( 'ul', array( 'class' => 'estasoft-nd-artlist' ), $rows );
		}

		return Html::tag(
			'section',
			array(
				'class'      => 'estasoft-nd-card estasoft-nd-card--flush estasoft-nd-listcard',
				'aria-label' => __( 'Articles', 'estasoft-news-distribution' ),
			),
			$search . $rows . $this->footer( count( $items ), $total, $state )
		);
	}

	/**
	 * The words for an empty list.
	 *
	 * @param array{filter: string, search: string, show: int} $state The list state.
	 * @return string
	 */
	private function empty_text( array $state ): string {
		if ( '' !== $state['search'] ) {
			return __( 'No article matches those words.', 'estasoft-news-distribution' );
		}

		if ( 'problems' === $state['filter'] ) {
			return __( 'No checked article has a problem.', 'estasoft-news-distribution' );
		}

		if ( 'waiting' === $state['filter'] ) {
			return __( 'No article is waiting to be sent.', 'estasoft-news-distribution' );
		}

		return __( 'There are no articles yet.', 'estasoft-news-distribution' );
	}

	/**
	 * The foot of the card: how many are shown, Load more, and what the icons mean.
	 *
	 * @param int                                              $shown How many rows are listed.
	 * @param int                                              $total How many articles in all.
	 * @param array{filter: string, search: string, show: int} $state The list state.
	 * @return string Escaped HTML.
	 */
	private function footer( int $shown, int $total, array $state ): string {
		$more  = '';
		$count = Html::tag(
			'span',
			array(),
			esc_html(
				sprintf(
					/* translators: 1: how many articles are listed, 2: how many there are. */
					__( 'Showing %1$s of %2$s articles', 'estasoft-news-distribution' ),
					number_format_i18n( $shown ),
					number_format_i18n( $total )
				)
			)
		);

		if ( $shown < $total && $state['show'] < 100 ) {
			$more = Html::tag(
				'a',
				array( 'href' => $this->url( 0, $state, array( 'show' => min( 100, $state['show'] + self::STEP ) ) ) ),
				esc_html__( 'Load more', 'estasoft-news-distribution' )
			);
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-listfoot estasoft-nd-small estasoft-nd-muted' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $count . ( '' === $more ? '' : Html::tag( 'span', array( 'class' => 'estasoft-nd-end estasoft-nd-strong' ), $more ) ) )
			. Html::tag( 'p', array(), esc_html__( 'Icons, left to right: Sitemaps, Partner feeds, Open readers, Pushes. Green is fine, amber has a note or is retrying, red is skipped or failed, gray is waiting.', 'estasoft-news-distribution' ) )
		);
	}

	/**
	 * One article in the list.
	 *
	 * @param array<string, mixed>                             $item    The summary.
	 * @param bool                                             $current Whether it is the open article.
	 * @param array{filter: string, search: string, show: int} $state   The list state to keep in the link.
	 * @return string Escaped HTML.
	 */
	private function row( array $item, bool $current, array $state ): string {
		$id      = (int) $item['id'];
		$title   = '' === trim( (string) $item['title'] ) ? __( '(no title)', 'estasoft-news-distribution' ) : (string) $item['title'];
		$stamp   = Times::from_iso( 'publish' === $item['status'] ? $item['published'] : ( $item['modified'] ?? null ) );
		$dots    = $this->dots( $item );
		$caption = '';
		$icons   = '';

		foreach ( $dots as $dot ) {
			$caption .= $dot['group'] . ': ' . $dot['word'] . '. ';
			$icons   .= Html::tag(
				'span',
				array(
					'class' => 'estasoft-nd-status estasoft-nd-status--' . $dot['tone'],
					'title' => $dot['group'] . ': ' . $dot['word'],
				),
				Icon::svg( $dot['icon'] )
			);
		}

		$attrs = array(
			'class' => 'estasoft-nd-artrow' . ( $current ? ' is-current' : '' ),
			'href'  => $this->url( $id, $state ),
		);

		if ( $current ) {
			$attrs['aria-current'] = 'page';
		}

		$status = 'publish' === $item['status'] ? '' : Tag::plain( $this->status_word( (string) $item['status'] ) );

		return Html::tag(
			'li',
			array(),
			Html::tag(
				'a',
				$attrs,
				Html::tag(
					'span',
					array(
						'class' => 'estasoft-nd-artrow__title',
						'lang'  => (string) $item['language'],
						'dir'   => 'auto',
					),
					esc_html( $title )
				)
				. Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-artrow__meta' ),
					Tag::lang( (string) $item['language'] )
					. $status
					. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $stamp > 0 ? Times::ago( $stamp ) : '' ) )
				)
				. Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-artrow__foot' ),
					Html::tag( 'span', array( 'class' => 'estasoft-nd-artrow__icons' ), $icons . Html::tag( 'span', array( 'class' => 'estasoft-nd-sr' ), esc_html( trim( $caption ) ) ) )
					. Html::tag( 'span', array( 'class' => 'estasoft-nd-artrow__note estasoft-nd-small estasoft-nd-muted' ), esc_html( $this->note( $item ) ) )
				)
			)
		);
	}

	/**
	 * The word for a post status that is not published.
	 *
	 * @param string $status Post status.
	 * @return string
	 */
	private function status_word( string $status ): string {
		switch ( $status ) {
			case 'future':
				return __( 'Scheduled', 'estasoft-news-distribution' );
			case 'pending':
				return __( 'Pending', 'estasoft-news-distribution' );
		}

		return __( 'Draft', 'estasoft-news-distribution' );
	}

	/**
	 * One icon for each of the four groups.
	 *
	 * @param array<string, mixed> $item The summary.
	 * @return array<int, array{group: string, tone: string, icon: string, word: string}>
	 */
	private function dots( array $item ): array {
		$report  = $this->plugin->make( ArticleChecker::class )->stored( (int) $item['id'] );
		$results = (array) $item['results'];
		$titles  = Group::all();
		$out     = array();
		$rank    = array(
			'neutral' => 0,
			'success' => 1,
			'wait'    => 2,
			'warning' => 3,
			'retry'   => 4,
			'danger'  => 5,
		);

		foreach ( self::ICON_GROUPS as $group ) {
			$state = 'none';

			foreach ( $this->plugin->registry()->enabled() as $channel ) {
				if ( $group !== $channel->group() || ChannelInterface::MODE_MANUAL === $channel->mode() || ! $channel->accepts_language( (string) $item['language'] ) ) {
					continue;
				}

				$tone = $this->tone( $results[ $channel->id() ]['status'] ?? null, null === $report ? array() : (array) ( $report->channels()[ $channel->id() ]['findings'] ?? array() ) );

				if ( 'none' === $state || $rank[ $tone ] > $rank[ $state ] ) {
					$state = $tone;
				}
			}

			$out[] = $this->dot( $titles[ $group ]['title'], $state );
		}

		return $out;
	}

	/**
	 * How one channel stands for one article.
	 *
	 * @param string|null                      $status   Result code, or null when the article is not published.
	 * @param array<int, array<string, mixed>> $findings Findings for the channel.
	 * @return string neutral, success, wait, warning, retry or danger.
	 */
	private function tone( ?string $status, array $findings ): string {
		$worst = Findings::worst( $findings );

		if ( Status::FAILED === $status || Status::SKIPPED === $status || Status::BLOCK === $worst ) {
			return 'danger';
		}

		if ( Status::RETRY === $status ) {
			return 'retry';
		}

		if ( Status::WARN === $worst ) {
			return 'warning';
		}

		if ( Status::QUEUED === $status ) {
			return 'wait';
		}

		return null === $status ? 'neutral' : 'success';
	}

	/**
	 * The icon, tone and words of one group.
	 *
	 * @param string $group Group name.
	 * @param string $state `none` when no channel of the group is on, else a tone.
	 * @return array{group: string, tone: string, icon: string, word: string}
	 */
	private function dot( string $group, string $state ): array {
		$map = array(
			'none'    => array( 'neutral', 'minus-circle', __( 'not set up', 'estasoft-news-distribution' ) ),
			'neutral' => array( 'neutral', 'minus-circle', __( 'not live yet', 'estasoft-news-distribution' ) ),
			'success' => array( 'success', 'check-circle', __( 'fine', 'estasoft-news-distribution' ) ),
			'wait'    => array( 'wait', 'clock', __( 'waiting', 'estasoft-news-distribution' ) ),
			'warning' => array( 'warning', 'warning', __( 'has a note', 'estasoft-news-distribution' ) ),
			'retry'   => array( 'warning', 'retry', __( 'retrying', 'estasoft-news-distribution' ) ),
			'danger'  => array( 'danger', 'x-circle', __( 'skipped or failed', 'estasoft-news-distribution' ) ),
		);

		return array(
			'group' => $group,
			'tone'  => $map[ $state ][0],
			'icon'  => $map[ $state ][1],
			'word'  => $map[ $state ][2],
		);
	}

	/**
	 * The short note on the right of a row.
	 *
	 * @param array<string, mixed> $item The summary.
	 * @return string Plain text.
	 */
	private function note( array $item ): string {
		$checked = null !== $item['check']['checked'];
		$counts  = (array) $item['check']['counts'];
		$to_fix  = (int) ( $counts[ Status::BLOCK ] ?? 0 ) + (int) ( $counts[ Status::WARN ] ?? 0 );
		$tips    = (int) ( $counts[ Status::INFO ] ?? 0 );

		if ( 'publish' !== $item['status'] ) {
			if ( ! $checked ) {
				return __( 'Not checked yet', 'estasoft-news-distribution' );
			}

			return $to_fix > 0
				? sprintf(
					/* translators: %s: a number of things to fix. */
					_n( '%s to fix', '%s to fix', $to_fix, 'estasoft-news-distribution' ),
					number_format_i18n( $to_fix )
				)
				: __( 'No problems', 'estasoft-news-distribution' );
		}

		$latest = $this->plugin->log()->latest_for_post( (int) $item['id'] );
		$parts  = array();
		$labels = array();

		foreach ( $this->plugin->registry()->all() as $channel ) {
			$labels[ $channel->id() ] = $channel->label();
		}

		$waiting = 0;

		foreach ( $latest as $row ) {
			if ( Status::QUEUED === $row['status'] && ( 0 === $waiting || ( (int) $row['run_at_ts'] > 0 && (int) $row['run_at_ts'] < $waiting ) ) ) {
				$waiting = (int) $row['run_at_ts'];
			}
		}

		if ( $waiting > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: a time such as "14:38". */
				__( 'Waiting %s', 'estasoft-news-distribution' ),
				Times::at( $waiting )
			);
		}

		foreach ( (array) $item['results'] as $channel_id => $result ) {
			$name = $labels[ $channel_id ] ?? (string) $channel_id;

			if ( Status::RETRY === $result['status'] ) {
				/* translators: %s: a channel name. */
				$parts[] = sprintf( __( '%s retrying', 'estasoft-news-distribution' ), $name );
			} elseif ( Status::FAILED === $result['status'] ) {
				/* translators: %s: a channel name. */
				$parts[] = sprintf( __( '%s failed', 'estasoft-news-distribution' ), $name );
			} elseif ( Status::SKIPPED === $result['status'] ) {
				/* translators: %s: a channel name. */
				$parts[] = sprintf( __( '%s skipped', 'estasoft-news-distribution' ), $name );
			}
		}

		$parts = array_slice( $parts, 0, 2 );

		if ( count( $parts ) < 2 && $to_fix + $tips > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: a number of notes. */
				_n( '%s note', '%s notes', $to_fix + $tips, 'estasoft-news-distribution' ),
				number_format_i18n( $to_fix + $tips )
			);
		}

		if ( array() === $parts ) {
			return $checked ? __( 'No problems', 'estasoft-news-distribution' ) : __( 'Not checked yet', 'estasoft-news-distribution' );
		}

		return implode( ', ', $parts );
	}
}

<?php
/**
 * Body rendering: leftovers in the article body that a feed would show or drop.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports;

use Estasoft\NewsDistribution\Admin\Reports\Parts\Rendering;
use Estasoft\NewsDistribution\Admin\Reports\Parts\View;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Code;
use Estasoft\NewsDistribution\Admin\Ui\CommandBar;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the body each channel would send for the recent articles, the same way the feed does,
 * and reads it for what a reader's app would show as odd. The reading is in `Parts\Rendering`.
 *
 * The result is kept for 15 minutes and is dropped whenever the feed cache is. Nothing is fetched
 * from the site or from anywhere else.
 */
final class RenderingReport extends AbstractReport {

	/**
	 * Rows on one page of the list.
	 */
	private const PER_PAGE = 20;

	public function name(): string {
		return 'rendering';
	}

	public function title(): string {
		return __( 'Body rendering', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Leftovers in the article body that platforms would show or drop.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'content';
	}

	public function order(): int {
		return 50;
	}

	/**
	 * The figures, for the screen and for `GET /reports/rendering`.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$model = $this->model();
		$rows  = array();

		foreach ( $model['rows'] as $row ) {
			$rows[] = array(
				'article'  => $row['article'],
				'title'    => $row['title'],
				'language' => $row['language'],
				'problem'  => $row['kind'],
				'group'    => $row['group'],
				'found'    => $row['found'],
				'matches'  => $row['count'],
				'channels' => $row['channels'],
			);
		}

		return array(
			'generated' => Clock::iso( (int) $model['at'] ),
			'channels'  => $model['channels'],
			'articles'  => $model['articles'],
			'affected'  => $model['affected'],
			'counts'    => $model['counts'],
			'rows'      => $rows,
		);
	}

	/**
	 * The rows, from the 15 minute cache.
	 *
	 * @return array<string, mixed>
	 */
	private function model(): array {
		$plugin = $this->plugin;

		$model = $plugin->cache()->remember(
			'report:rendering',
			15 * MINUTE_IN_SECONDS,
			static function () use ( $plugin ): array {
				return ( new Rendering( $plugin ) )->scan();
			}
		);

		return is_array( $model ) ? $model : ( new Rendering( $plugin ) )->scan();
	}

	/**
	 * The filter in the address: all, shortcodes, builder or other.
	 *
	 * @return string
	 */
	private function filter(): string {
		$filter = Query::key( 'filter', 'all' );

		return in_array( $filter, array( 'shortcodes', 'builder', 'other' ), true ) ? $filter : 'all';
	}

	/**
	 * The name of a kind of finding, as the Problem column prints it.
	 *
	 * @param string $kind shortcode, builder, empty or embed.
	 * @return string
	 */
	private function problem( string $kind ): string {
		switch ( $kind ) {
			case 'shortcode':
				return __( 'Shortcode left as text', 'estasoft-news-distribution' );
			case 'builder':
				return __( 'Page-builder wrapper', 'estasoft-news-distribution' );
			case 'empty':
				return __( 'Empty block', 'estasoft-news-distribution' );
			default:
				return __( 'An embed some channels drop', 'estasoft-news-distribution' );
		}
	}

	/**
	 * The text of the Found column.
	 *
	 * @param array<string, mixed> $row A row of the model.
	 * @return string Plain text.
	 */
	private function found( array $row ): string {
		if ( 'empty' !== $row['kind'] ) {
			return (string) $row['found'];
		}

		$has = array_filter( explode( ',', (string) $row['found'] ) );

		if ( in_array( 'p', $has, true ) && in_array( 'list', $has, true ) ) {
			return __( 'An empty paragraph and an empty list', 'estasoft-news-distribution' );
		}

		if ( in_array( 'list', $has, true ) ) {
			return __( 'An empty list', 'estasoft-news-distribution' );
		}

		if ( in_array( 'p', $has, true ) ) {
			return __( 'An empty paragraph', 'estasoft-news-distribution' );
		}

		return __( 'An empty block', 'estasoft-news-distribution' );
	}

	/**
	 * The text of the Channels affected column.
	 *
	 * @param array<string, mixed> $row   A row of the model.
	 * @param array<string, mixed> $model The model.
	 * @return string Plain text.
	 */
	private function channels( array $row, array $model ): string {
		$names = array();

		foreach ( (array) $row['channels'] as $id ) {
			foreach ( $model['channels'] as $channel ) {
				if ( $channel['id'] === $id ) {
					$names[] = (string) $channel['label'];
				}
			}
		}

		$more  = count( $names ) - 2;
		$shown = array_slice( $names, 0, 2 );
		$text  = $more > 0
			? sprintf(
				/* translators: 1: two channel names, such as "Microsoft Start, Yahoo News", 2: how many more. */
				_n( '%1$s and %2$s more', '%1$s and %2$s more', $more, 'estasoft-news-distribution' ),
				implode( ', ', $shown ),
				number_format_i18n( $more )
			)
			: wp_sprintf( '%l', $shown );

		if ( 'embed' === $row['kind'] ) {
			return sprintf(
				/* translators: %s: channel names. */
				__( 'Left out of %s', 'estasoft-news-distribution' ),
				$text
			);
		}

		return $text;
	}

	/**
	 * The lead message.
	 *
	 * @param array<string, mixed> $model The model.
	 * @return string
	 */
	private function lead( array $model ): string {
		if ( array() === $model['channels'] ) {
			return View::lead( 'info', __( 'No channel that carries article text is turned on.', 'estasoft-news-distribution' ), __( 'Turn on a partner feed or a feed for readers, and this report will read what it sends.', 'estasoft-news-distribution' ) );
		}

		if ( 0 === $model['affected'] ) {
			return View::lead(
				'success',
				__( 'No leftovers found.', 'estasoft-news-distribution' ),
				sprintf(
					/* translators: %s: number of articles. */
					_n( 'The body of %s recent article reads cleanly in every feed.', 'The body of %s recent articles reads cleanly in every feed.', $model['articles'], 'estasoft-news-distribution' ),
					number_format_i18n( $model['articles'] )
				)
			);
		}

		$first = $model['rows'][0];

		return View::lead(
			'warning',
			sprintf(
				/* translators: %s: number of articles. */
				_n( '%s article would show something odd in a feed.', '%s articles would show something odd in a feed.', $model['affected'], 'estasoft-news-distribution' ),
				number_format_i18n( $model['affected'] )
			),
			__( 'A shortcode that stays in the text prints as text, and a page builder’s wrapper adds markup a reader’s app may not use.', 'estasoft-news-distribution' ),
			Button::link( __( 'Review them one by one', 'estasoft-news-distribution' ), Links::check( (int) $first['article'] ), array( 'size' => 'sm' ) )
		);
	}

	/**
	 * The filter bar and the count.
	 *
	 * @param array<string, mixed> $model  The model.
	 * @param string               $filter The filter in use.
	 * @return string
	 */
	private function segments( array $model, string $filter ): string {
		$counts = $model['counts'];

		return CommandBar::segments(
			array(
				'all'        => array(
					/* translators: %s: number of findings. */
					'label' => sprintf( __( 'All (%s)', 'estasoft-news-distribution' ), number_format_i18n( $counts['all'] ) ),
					'url'   => $this->url(),
				),
				'shortcodes' => array(
					/* translators: %s: number of findings. */
					'label' => sprintf( __( 'Shortcodes (%s)', 'estasoft-news-distribution' ), number_format_i18n( $counts['shortcodes'] ) ),
					'url'   => $this->url( array( 'filter' => 'shortcodes' ) ),
				),
				'builder'    => array(
					/* translators: %s: number of findings. */
					'label' => sprintf( __( 'Page builder (%s)', 'estasoft-news-distribution' ), number_format_i18n( $counts['builder'] ) ),
					'url'   => $this->url( array( 'filter' => 'builder' ) ),
				),
				'other'      => array(
					/* translators: %s: number of findings. */
					'label' => sprintf( __( 'Other (%s)', 'estasoft-news-distribution' ), number_format_i18n( $counts['other'] ) ),
					'url'   => $this->url( array( 'filter' => 'other' ) ),
				),
			),
			$filter,
			__( 'Show findings', 'estasoft-news-distribution' )
		);
	}

	/**
	 * The footer of the list, with the paging links when there is more than a page.
	 *
	 * @param int    $total  Rows in the filter.
	 * @param int    $paged  The page shown, from 1.
	 * @param string $filter The filter in use.
	 * @return string
	 */
	private function footer( int $total, int $paged, string $filter ): string {
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );

		if ( $pages < 2 ) {
			return View::footer(
				sprintf(
					/* translators: 1: number of rows. */
					__( 'Showing %1$s of %1$s', 'estasoft-news-distribution' ),
					number_format_i18n( $total )
				)
			);
		}

		$from  = ( $paged - 1 ) * self::PER_PAGE + 1;
		$to    = min( $total, $paged * self::PER_PAGE );
		$links = '';

		if ( $paged > 1 ) {
			$links .= Button::link(
				__( 'Previous', 'estasoft-news-distribution' ),
				$this->url(
					array_filter(
						array(
							'filter' => 'all' === $filter ? '' : $filter,
							'paged'  => $paged > 2 ? (string) ( $paged - 1 ) : '',
						)
					)
				),
				array( 'size' => 'sm' )
			);
		}

		if ( $paged < $pages ) {
			$links .= Button::link(
				__( 'Next', 'estasoft-news-distribution' ),
				$this->url(
					array_filter(
						array(
							'filter' => 'all' === $filter ? '' : $filter,
							'paged'  => (string) ( $paged + 1 ),
						)
					)
				),
				array( 'size' => 'sm' )
			);
		}

		return View::footer(
			sprintf(
				/* translators: 1: first row, 2: last row, 3: rows in all. */
				__( 'Showing %1$s to %2$s of %3$s', 'estasoft-news-distribution' ),
				number_format_i18n( $from ),
				number_format_i18n( $to ),
				number_format_i18n( $total )
			),
			Html::tag( 'span', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $links )
		);
	}

	/**
	 * The card for one article: what its feed bodies hold.
	 *
	 * @param array<string, mixed>             $model The model.
	 * @param array<int, array<string, mixed>> $rows  The findings of that article.
	 * @return string
	 */
	private function detail( array $model, array $rows ): string {
		if ( array() === $rows ) {
			return '';
		}

		$first = $rows[0];
		$body  = '';

		foreach ( $rows as $row ) {
			$count  = count( (array) $row['channels'] );
			$flags  = array();
			$lines  = array_values( array_map( 'strval', (array) $row['context']['lines'] ) );
			$marked = (int) $row['context']['marked'];

			if ( $marked > 0 ) {
				$flags[] = array(
					'line' => $marked,
					'tone' => 'danger',
				);
			}

			$summary = $this->summary( $row, $count );
			$from    = (string) $row['context']['channel'];
			$body   .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-detail-row' ),
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
					Html::tag( 'strong', array(), esc_html( $this->problem( (string) $row['kind'] ) ) )
					. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $summary ) )
				)
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
					Code::block(
						implode( "\n", $lines ),
						'' === $from
							? __( 'The article body as it is saved', 'estasoft-news-distribution' )
							: sprintf(
								/* translators: %s: a channel name. */
								__( 'The article body in the %s feed', 'estasoft-news-distribution' ),
								$from
							),
						$flags
					)
					. Html::tag(
						'span',
						array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
						esc_html(
							'' === $from
								? __( 'As the article is saved. The feeds listed above leave this part out.', 'estasoft-news-distribution' )
								: sprintf(
									/* translators: %s: a channel name. */
									__( 'As the %s feed sends it.', 'estasoft-news-distribution' ),
									$from
								)
						)
					)
				)
			);
		}

		$edit = (string) get_edit_post_link( (int) $first['article'], 'raw' );
		$foot = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8 estasoft-nd-card-pad' ),
			Button::link( __( 'Open the article check', 'estasoft-news-distribution' ), Links::check( (int) $first['article'] ), array( 'size' => 'sm' ) )
			. ( '' === $edit ? '' : Button::link( __( 'Edit post', 'estasoft-news-distribution' ), $edit, array( 'size' => 'sm' ) ) )
			. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'This report changes nothing. Fix the text in the article, or in the plugin that adds it, then check again.', 'estasoft-news-distribution' ) )
		);

		return Card::flush(
			'rendering-article',
			(string) $first['title'],
			sprintf(
				/* translators: %s: number of findings. */
				_n( '%s thing to look at in the feed body.', '%s things to look at in the feed body.', count( $rows ), 'estasoft-news-distribution' ),
				number_format_i18n( count( $rows ) )
			),
			$body . $foot
		);
	}

	/**
	 * One sentence about a finding: how many, and how many channels.
	 *
	 * @param array<string, mixed> $row   A row of the model.
	 * @param int                  $count Channels in the row.
	 * @return string
	 */
	private function summary( array $row, int $count ): string {
		$matches = (int) $row['count'];

		switch ( $row['kind'] ) {
			case 'shortcode':
				return sprintf(
					/* translators: 1: sentence about shortcodes, 2: sentence about channels. */
					__( '%1$s %2$s', 'estasoft-news-distribution' ),
					sprintf(
						/* translators: %s: number of shortcodes. */
						_n( '%s shortcode in the body.', '%s shortcodes in the body.', $matches, 'estasoft-news-distribution' ),
						number_format_i18n( $matches )
					),
					sprintf(
						/* translators: %s: number of channels. */
						_n( '%s channel would show it as text.', '%s channels would show it as text.', $count, 'estasoft-news-distribution' ),
						number_format_i18n( $count )
					)
				);

			case 'builder':
				return sprintf(
					/* translators: 1: sentence about wrappers, 2: sentence about channels. */
					__( '%1$s %2$s', 'estasoft-news-distribution' ),
					sprintf(
						/* translators: %s: number of tags. */
						_n( '%s tag from a page builder in the body.', '%s tags from a page builder in the body.', $matches, 'estasoft-news-distribution' ),
						number_format_i18n( $matches )
					),
					sprintf(
						/* translators: %s: number of channels. */
						_n( '%s channel still carries it.', '%s channels still carry it.', $count, 'estasoft-news-distribution' ),
						number_format_i18n( $count )
					)
				);

			case 'empty':
				return sprintf(
					/* translators: 1: sentence about empty blocks, 2: sentence about channels. */
					__( '%1$s %2$s', 'estasoft-news-distribution' ),
					sprintf(
						/* translators: %s: number of empty blocks. */
						_n( '%s empty block in the body.', '%s empty blocks in the body.', $matches, 'estasoft-news-distribution' ),
						number_format_i18n( $matches )
					),
					sprintf(
						/* translators: %s: number of channels. */
						_n( '%s channel carries it.', '%s channels carry it.', $count, 'estasoft-news-distribution' ),
						number_format_i18n( $count )
					)
				);

			default:
				return sprintf(
					/* translators: %s: number of channels. */
					_n( '%s channel leaves it out.', '%s channels leave it out.', $count, 'estasoft-news-distribution' ),
					number_format_i18n( $count )
				);
		}
	}

	public function render(): string {
		$model  = $this->model();
		$filter = $this->filter();

		if ( array() === $model['channels'] ) {
			return $this->lead( $model ) . $this->cards();
		}

		$rows = array_values(
			array_filter(
				$model['rows'],
				static function ( array $row ) use ( $filter ): bool {
					return 'all' === $filter || $row['group'] === $filter;
				}
			)
		);

		$paged = max( 1, Query::int( 'paged', 1 ) );
		$paged = min( $paged, max( 1, (int) ceil( count( $rows ) / self::PER_PAGE ) ) );
		$shown = array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$cells = array();

		foreach ( $shown as $row ) {
			$title   = '' === (string) $row['title'] ? __( '(no title)', 'estasoft-news-distribution' ) : (string) $row['title'];
			$cells[] = array(
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8 estasoft-nd-start' ),
					Html::tag(
						'a',
						array_merge(
							array(
								'class' => 'estasoft-nd-strong',
								'href'  => $this->url( array( 'article' => (int) $row['article'] ) ),
							),
							View::text_attributes( $title )
						),
						esc_html( $title )
					)
					. Button::link(
						__( 'Review', 'estasoft-news-distribution' ),
						Links::check( (int) $row['article'] ),
						array(
							'size'  => 'sm',
							'attrs' => array(
								'aria-label' => sprintf(
									/* translators: %s: article title. */
									__( 'Review %s', 'estasoft-news-distribution' ),
									$title
								),
							),
						)
					)
				),
				View::mark( 'warning', $this->problem( (string) $row['kind'] ) ),
				Html::tag( 'code', array( 'class' => 'estasoft-nd-found' ), esc_html( $this->found( $row ) ) ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $this->channels( $row, $model ) ) ),
			);
		}

		$table = array() === $cells
			? Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-card-pad' ), esc_html__( 'No findings in this list.', 'estasoft-news-distribution' ) )
			: Grid::render(
				'estasoft-nd-cols-rendering',
				array(
					__( 'Article', 'estasoft-news-distribution' ),
					__( 'Problem', 'estasoft-news-distribution' ),
					__( 'Found in the feed body', 'estasoft-news-distribution' ),
					__( 'Channels affected', 'estasoft-news-distribution' ),
				),
				$cells,
				__( 'Leftovers in the article body', 'estasoft-news-distribution' )
			) . Html::tag( 'div', array( 'class' => 'estasoft-nd-table-footer' ), $this->footer( count( $rows ), $paged, $filter ) );

		$list = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-card estasoft-nd-card--flush' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-card-pad' ), $this->segments( $model, $filter ) ) . $table
		);

		return $this->lead( $model ) . ( 0 === $model['affected'] ? '' : $list . $this->detail_for( $model, $rows ) ) . $this->cards();
	}

	/**
	 * The detail card of the article named in the address, or of the first row.
	 *
	 * @param array<string, mixed>             $model The model.
	 * @param array<int, array<string, mixed>> $rows  The rows of the filter in use.
	 * @return string
	 */
	private function detail_for( array $model, array $rows ): string {
		$wanted = Query::int( 'article' );

		if ( $wanted < 1 && array() !== $rows ) {
			$wanted = (int) $rows[0]['article'];
		}

		$mine = array_values(
			array_filter(
				$model['rows'],
				static function ( array $row ) use ( $wanted ): bool {
					return (int) $row['article'] === $wanted;
				}
			)
		);

		return $this->detail( $model, $mine );
	}

	/**
	 * The two cards under the list.
	 *
	 * @return string
	 */
	private function cards(): string {
		$looks = array(
			__( 'Shortcodes printed as text, such as [gallery]', 'estasoft-news-distribution' ),
			__( 'A page builder’s wrapper tags and classes', 'estasoft-news-distribution' ),
			__( 'Empty paragraphs, lists and blocks', 'estasoft-news-distribution' ),
			__( 'Iframes and scripts in a channel that does not carry them', 'estasoft-news-distribution' ),
		);
		$list  = '';

		foreach ( $looks as $item ) {
			$list .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule estasoft-nd-rule--ok' ), Icon::svg( 'check-circle' ) . Html::tag( 'span', array(), esc_html( $item ) ) );
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-notes' ),
			Card::note( 'rendering-looks', __( 'What it looks for', 'estasoft-news-distribution' ), Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $list ) )
			. Card::note(
				'rendering-cannot',
				__( 'What it cannot do', 'estasoft-news-distribution' ),
				Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'It checks the feed body that News Distribution builds, in the same way. It cannot see content a page builder fills in with JavaScript in the browser, and it does not judge how a reader’s app lays the article out. The page-builder names are the common ones, not a complete list.', 'estasoft-news-distribution' ) )
			)
		);
	}
}

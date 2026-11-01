<?php
/**
 * Discover: what would stop a large image from showing.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports;

use Estasoft\NewsDistribution\Admin\Reports\Parts\Discover;
use Estasoft\NewsDistribution\Admin\Reports\Parts\ScanControls;
use Estasoft\NewsDistribution\Admin\Reports\Parts\Scannable;
use Estasoft\NewsDistribution\Admin\Reports\Parts\Scans;
use Estasoft\NewsDistribution\Admin\Reports\Parts\View;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Markup\PageTags;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Discover is not a channel and nobody applies to it, so this report is a list of blockers, not a
 * verdict. The width of the image, the site logo and the title come from the media library and the
 * post, so they are always current. The page's `max-image-preview` rule needs a request to the site's own
 * page, so it is read only when a person presses "Check the pages" and kept until the next time.
 * The rules come from Google's Discover documentation (verify-log, "Report-discover"); nothing here
 * says an article will or will not be shown.
 */
final class DiscoverReport extends AbstractReport implements Scannable {

	/**
	 * Rows shown before "Show all".
	 */
	private const SHOWN = 6;

	public function name(): string {
		return 'discover';
	}

	public function title(): string {
		return __( 'Discover', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Whether your articles can appear with a large image in Google Discover.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'content';
	}

	public function order(): int {
		return 30;
	}

	public function scan_units(): int {
		return ( new Discover( $this->plugin ) )->units();
	}

	public function scan( callable $progress ): array {
		return ( new Discover( $this->plugin ) )->scan( $progress );
	}

	public function scan_message( array $result ): string {
		$limited = 0;

		foreach ( (array) ( $result['pages'] ?? array() ) as $page ) {
			$limited += in_array( $page['state'] ?? '', array( 'blocked', 'not_set' ), true ) ? 1 : 0;
		}

		return 0 === $limited
			? __( 'The Discover check finished. No page limits the preview.', 'estasoft-news-distribution' )
			: sprintf(
				/* translators: %s: number of pages. */
				_n( 'The Discover check finished. %s page limits the preview.', 'The Discover check finished. %s pages limit the preview.', $limited, 'estasoft-news-distribution' ),
				number_format_i18n( $limited )
			);
	}

	/**
	 * What each result is called, the tone it carries and how much it matters (lowest first).
	 *
	 * @return array<string, array{tone: string, text: string}>
	 */
	private function results(): array {
		return array(
			'no_image'    => array(
				'tone' => 'danger',
				'text' => __( 'Needs a featured image', 'estasoft-news-distribution' ),
			),
			'logo'        => array(
				'tone' => 'danger',
				'text' => __( 'Use a photo, not the logo', 'estasoft-news-distribution' ),
			),
			'small'       => array(
				'tone' => 'danger',
				'text' => __( 'Image too small', 'estasoft-news-distribution' ),
			),
			'preview_off' => array(
				'tone' => 'danger',
				'text' => __( 'Large preview off', 'estasoft-news-distribution' ),
			),
			'title'       => array(
				'tone' => 'warning',
				'text' => __( 'Check the title', 'estasoft-news-distribution' ),
			),
			'unreachable' => array(
				'tone' => 'warning',
				'text' => __( 'Page did not answer', 'estasoft-news-distribution' ),
			),
			'unchecked'   => array(
				'tone' => 'neutral',
				'text' => __( 'Preview not checked yet', 'estasoft-news-distribution' ),
			),
			'ok'          => array(
				'tone' => 'success',
				'text' => __( 'No blockers found', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * The first blocker of an article, in the order a person should deal with them.
	 *
	 * @param string   $image   none, logo, small or fine.
	 * @param string   $preview allowed, blocked, not_set, error or unchecked.
	 * @param string[] $hints   Title hints.
	 * @return string A key of results().
	 */
	private function result_of( string $image, string $preview, array $hints ): string {
		if ( 'none' === $image ) {
			return 'no_image';
		}

		if ( 'logo' === $image ) {
			return 'logo';
		}

		if ( 'small' === $image ) {
			return 'small';
		}

		if ( in_array( $preview, array( 'blocked', 'not_set' ), true ) ) {
			return 'preview_off';
		}

		if ( array() !== $hints ) {
			return 'title';
		}

		if ( 'error' === $preview ) {
			return 'unreachable';
		}

		return 'unchecked' === $preview ? 'unchecked' : 'ok';
	}

	/**
	 * Every article page with what is known about it.
	 *
	 * @return array<string, mixed>
	 */
	private function model(): array {
		$scans   = $this->plugin->make( Scans::class );
		$last    = $scans->last( $this->name() );
		$pages   = null === $last ? array() : (array) ( $last['data']['pages'] ?? array() );
		$engine  = new Discover( $this->plugin );
		$many    = count( $this->plugin->languages()->languages() ) > 1;
		$rows    = array();
		$preview = array(
			'allowed'    => 0,
			'blocked'    => 0,
			'not_set'    => 0,
			'unreadable' => 0,
		);

		foreach ( $engine->articles() as $article ) {
			$page  = $pages[ $article->id() ] ?? null;
			$state = null === $last || ! is_array( $page ) ? 'unchecked' : (string) $page['state'];
			$image = $engine->image_of( $article );
			$hints = Discover::hints( $article->title() );
			$code  = $this->result_of( $image['state'], $state, $hints );

			if ( isset( $preview[ $state ] ) ) {
				++$preview[ $state ];
			} elseif ( null !== $last ) {
				++$preview['unreadable'];
			}

			$rows[] = array(
				'article'   => $article->id(),
				'title'     => $article->title(),
				'url'       => $article->permalink(),
				'language'  => $many ? $article->language() : '',
				'published' => $article->published_ts(),
				'image'     => $image,
				'preview'   => array(
					'state'     => $state,
					'directive' => is_array( $page ) ? (string) ( $page['directive'] ?? '' ) : '',
				),
				'hints'     => $hints,
				'result'    => $code,
			);
		}

		return array(
			'ran_at'  => null === $last ? 0 : $last['at'],
			'running' => (string) $scans->running( $this->name() ),
			'rows'    => $rows,
			'preview' => $preview,
			'allowed' => (bool) ( new PageTags( $this->plugin ) )->enabled( 'preview' ),
		);
	}

	/**
	 * The figures, for the screen and for `GET /reports/discover`.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$model   = $this->model();
		$results = $this->results();
		$counts  = array_fill_keys( array_keys( $results ), 0 );
		$rows    = array();

		foreach ( $model['rows'] as $row ) {
			++$counts[ $row['result'] ];

			$rows[] = array(
				'article'   => $row['article'],
				'title'     => $row['title'],
				'language'  => $row['language'],
				'published' => Clock::iso( $row['published'] ),
				'image'     => array(
					'state' => $row['image']['state'],
					'width' => $row['image']['width'],
				),
				'preview'   => $row['preview'],
				'hints'     => $row['hints'],
				'result'    => $row['result'],
				'text'      => $results[ $row['result'] ]['text'],
			);
		}

		return array(
			'ran'            => $model['ran_at'] > 0,
			'ran_at'         => $model['ran_at'] > 0 ? Clock::iso( $model['ran_at'] ) : null,
			'running'        => '' === $model['running'] ? null : $model['running'],
			'minimum_width'  => Discover::MIN_WIDTH,
			'adds_allowance' => $model['allowed'],
			'articles'       => count( $rows ),
			'counts'         => $counts,
			'preview'        => $model['preview'],
			'rows'           => $rows,
		);
	}

	/**
	 * The controls: the button, the progress and the messages.
	 *
	 * @return ScanControls
	 */
	private function controls(): ScanControls {
		return new ScanControls( $this->plugin, $this );
	}

	public function handle(): void {
		$this->controls()->handle();
	}

	public function enqueue(): void {
		$this->controls()->enqueue();
	}

	/**
	 * The Large previews card.
	 *
	 * @param array<string, mixed> $model The model.
	 * @return string
	 */
	private function preview_card( array $model ): string {
		$preview  = $model['preview'];
		$checked  = $preview['allowed'] + $preview['blocked'] + $preview['not_set'];
		$limited  = array_values(
			array_filter(
				$model['rows'],
				static function ( array $row ): bool {
					return in_array( $row['preview']['state'], array( 'blocked', 'not_set' ), true );
				}
			)
		);
		$settings = Html::tag( 'a', array( 'href' => Links::settings( 'markup' ) ), esc_html__( 'Settings, Page markup', 'estasoft-news-distribution' ) );
		$intro    = Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Discover shows a large image only when the page allows it. Technical name: max-image-preview.', 'estasoft-news-distribution' ) );
		$ctl      = $this->controls();

		if ( $model['ran_at'] < 1 ) {
			return Card::note(
				'discover-preview',
				__( 'Large previews', 'estasoft-news-distribution' ),
				$intro
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-strong' ), esc_html__( 'Not checked yet', 'estasoft-news-distribution' ) )
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Press Check the pages to read the preview rule on your recent article pages. The check reads pages of your own site and sends nothing anywhere.', 'estasoft-news-distribution' ) )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $ctl->form( __( 'Check the pages', 'estasoft-news-distribution' ), '', 'primary' ) )
			);
		}

		$heading = sprintf(
			/* translators: 1: pages that allow a large preview, 2: pages read. */
			_n( 'Allowed on %1$s of %2$s article page', 'Allowed on %1$s of %2$s article pages', $checked, 'estasoft-news-distribution' ),
			number_format_i18n( $preview['allowed'] ),
			number_format_i18n( $checked )
		);
		$body    = '';
		$blocked = $preview['blocked'];

		if ( $blocked > 0 ) {
			$first = '';

			foreach ( $limited as $row ) {
				if ( 'blocked' === $row['preview']['state'] ) {
					$first = $row['title'];
					break;
				}
			}

			$body .= Html::tag(
				'p',
				array(),
				esc_html(
					1 === $blocked
						? sprintf(
							/* translators: %s: an article title. */
							__( 'One page, %s, carries a limit that keeps the preview small. It may come from another plugin, your theme or a server header. News Distribution adds its own allowance only where a page sets none, so it cannot override that one. Change the setting where the limit comes from, then check the page again.', 'estasoft-news-distribution' ),
							$first
						)
						: sprintf(
							/* translators: 1: number of pages, 2: an article title. */
							__( '%1$s pages carry a limit that keeps the preview small, for example %2$s. They may come from another plugin, your theme or a server header. News Distribution adds its own allowance only where a page sets none, so it cannot override those. Change the setting where each limit comes from, then check the pages again.', 'estasoft-news-distribution' ),
							number_format_i18n( $blocked ),
							$first
						)
				)
			);
		}

		if ( $preview['not_set'] > 0 ) {
			$body .= Html::tag(
				'p',
				array(),
				esc_html(
					$model['allowed']
						? sprintf(
							/* translators: %s: number of pages. */
							_n( '%s page shows no preview rule, although News Distribution adds one where a page sets none. A page cache may be serving an older copy. Clear it, then check again.', '%s pages show no preview rule, although News Distribution adds one where a page sets none. A page cache may be serving an older copy. Clear it, then check again.', $preview['not_set'], 'estasoft-news-distribution' ),
							number_format_i18n( $preview['not_set'] )
						)
						: sprintf(
							/* translators: %s: number of pages. */
							_n( '%s page sets no preview rule, and the allowance in Settings, Page markup is off. Turn it on there, then check again.', '%s pages set no preview rule, and the allowance in Settings, Page markup is off. Turn it on there, then check again.', $preview['not_set'], 'estasoft-news-distribution' ),
							number_format_i18n( $preview['not_set'] )
						)
				)
			);
		}

		if ( $preview['unreadable'] > 0 ) {
			$body .= Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted' ),
				esc_html(
					sprintf(
						/* translators: %s: number of pages. */
						_n( '%s page could not be read. Check again to read it.', '%s pages could not be read. Check again to read them.', $preview['unreadable'], 'estasoft-news-distribution' ),
						number_format_i18n( $preview['unreadable'] )
					)
				)
			);
		}

		$actions = '';

		if ( array() !== $limited ) {
			$actions .= Button::link( __( 'See the page', 'estasoft-news-distribution' ), (string) $limited[0]['url'] );
		}

		$actions .= $ctl->form( __( 'Check again', 'estasoft-news-distribution' ), '', '' );

		$foot = $model['allowed']
			? sprintf(
				/* translators: %s: a link with the words "Settings, Page markup". */
				esc_html__( 'News Distribution adds the allowance on pages that set none. You can turn that off in %s.', 'estasoft-news-distribution' ),
				$settings
			)
			: sprintf(
				/* translators: %s: a link with the words "Settings, Page markup". */
				esc_html__( 'News Distribution does not add the allowance while it is off in %s.', 'estasoft-news-distribution' ),
				$settings
			);

		return Card::note(
			'discover-preview',
			__( 'Large previews', 'estasoft-news-distribution' ),
			$intro
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-strong' ), esc_html( $heading ) )
			. $body
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $actions )
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				sprintf(
					/* translators: %s: a day and time such as "today, 14:10". */
					esc_html__( 'Last checked %s.', 'estasoft-news-distribution' ),
					esc_html( View::day( $model['ran_at'], Clock::now() ) )
				) . ' ' . $foot
			)
		);
	}

	/**
	 * The Large preview cell.
	 *
	 * @param array<string, mixed> $row A row.
	 * @return string
	 */
	private function preview_cell( array $row ): string {
		switch ( $row['preview']['state'] ) {
			case 'allowed':
				return View::mark( 'success', __( 'Allowed', 'estasoft-news-distribution' ) );
			case 'blocked':
				return View::mark( 'danger', __( 'Blocked by another plugin or theme', 'estasoft-news-distribution' ) );
			case 'not_set':
				return View::mark( 'warning', __( 'Not set on the page', 'estasoft-news-distribution' ) );
			case 'error':
				return View::mark( 'warning', __( 'Page did not answer', 'estasoft-news-distribution' ) );
			default:
				return View::mark( 'neutral', __( 'Not checked yet', 'estasoft-news-distribution' ) );
		}
	}

	/**
	 * The Image width cell.
	 *
	 * @param array<string, mixed> $row A row.
	 * @return string
	 */
	private function width_cell( array $row ): string {
		$image = $row['image'];

		if ( 'none' === $image['state'] ) {
			return View::mark( 'danger', __( 'No featured image', 'estasoft-news-distribution' ) );
		}

		if ( 'small' === $image['state'] ) {
			return View::mark(
				'danger',
				sprintf(
					/* translators: 1: the width such as "800 px", 2: the width Google recommends such as "1,200". */
					__( '%1$s, needs %2$s', 'estasoft-news-distribution' ),
					View::pixels( $image['width'] ),
					number_format_i18n( Discover::MIN_WIDTH )
				)
			);
		}

		return Html::tag( 'span', array(), esc_html( View::pixels( $image['width'] ) ) );
	}

	/**
	 * The Not a logo cell.
	 *
	 * @param array<string, mixed> $row A row.
	 * @return string
	 */
	private function logo_cell( array $row ): string {
		switch ( $row['image']['state'] ) {
			case 'none':
				return Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Nothing to check', 'estasoft-news-distribution' ) );
			case 'logo':
				return View::mark( 'danger', __( 'The site logo', 'estasoft-news-distribution' ) );
			default:
				return View::mark( 'success', __( 'Not the logo', 'estasoft-news-distribution' ) );
		}
	}

	/**
	 * The Title and preview cell.
	 *
	 * @param array<string, mixed> $row A row.
	 * @return string
	 */
	private function title_hint_cell( array $row ): string {
		if ( array() === $row['hints'] ) {
			return Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No hints', 'estasoft-news-distribution' ) );
		}

		return View::mark( 'warning', (string) $row['hints'][0] );
	}

	/**
	 * The Article cell.
	 *
	 * @param array<string, mixed> $row A row.
	 * @return string
	 */
	private function article_cell( array $row ): string {
		$title = '' === (string) $row['title'] ? __( '(no title)', 'estasoft-news-distribution' ) : (string) $row['title'];
		$sub   = '' === (string) $row['language']
			? ''
			: Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( View::language_short( (string) $row['language'] ) ) );

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
			Html::tag(
				'a',
				array_merge(
					array(
						'class' => 'estasoft-nd-strong',
						'href'  => Links::check( (int) $row['article'] ),
					),
					View::text_attributes( $title )
				),
				esc_html( $title )
			) . $sub
		);
	}

	/**
	 * The table of article pages.
	 *
	 * @param array<string, mixed> $model The model.
	 * @param bool                 $all   Whether every row is shown.
	 * @return string
	 */
	private function table( array $model, bool $all ): string {
		$results = $this->results();
		$rows    = $model['rows'];
		$shown   = $all ? $rows : array_slice( $rows, 0, self::SHOWN );
		$cells   = array();

		foreach ( $shown as $row ) {
			$cells[] = array(
				$this->article_cell( $row ),
				$this->width_cell( $row ),
				$this->preview_cell( $row ),
				$this->logo_cell( $row ),
				$this->title_hint_cell( $row ),
				View::mark( $results[ $row['result'] ]['tone'], $results[ $row['result'] ]['text'] ),
			);
		}

		if ( array() === $cells ) {
			return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-card-pad' ), esc_html__( 'No published articles in the last 30 days.', 'estasoft-news-distribution' ) );
		}

		$footer = sprintf(
			/* translators: 1: rows shown, 2: rows in all. */
			_n( 'Showing %1$s of %2$s article page, newest first.', 'Showing %1$s of %2$s article pages, newest first.', count( $rows ), 'estasoft-news-distribution' ),
			number_format_i18n( count( $shown ) ),
			number_format_i18n( count( $rows ) )
		);
		$fixes = count(
			array_filter(
				$rows,
				static function ( array $row ): bool {
					return ! in_array( $row['result'], array( 'ok', 'unchecked' ), true );
				}
			)
		);

		if ( $fixes > 0 && count( $rows ) > count( $shown ) ) {
			$footer .= ' ' . sprintf(
				/* translators: 1: pages that need a fix, 2: pages in all. */
				_n( '%1$s of the %2$s article pages needs a fix.', '%1$s of the %2$s article pages need a fix.', $fixes, 'estasoft-news-distribution' ),
				number_format_i18n( $fixes ),
				number_format_i18n( count( $rows ) )
			);
		}

		$more = count( $rows ) > count( $shown )
			? Button::link(
				sprintf(
					/* translators: %s: number of article pages. */
					__( 'Show all %s', 'estasoft-news-distribution' ),
					number_format_i18n( count( $rows ) )
				),
				$this->url( array( 'all' => '1' ) ),
				array( 'size' => 'sm' )
			)
			: '';

		return Grid::render(
			'estasoft-nd-cols-discover',
			array(
				__( 'Article', 'estasoft-news-distribution' ),
				__( 'Image width', 'estasoft-news-distribution' ),
				__( 'Large preview', 'estasoft-news-distribution' ),
				__( 'Not a logo', 'estasoft-news-distribution' ),
				__( 'Title and preview', 'estasoft-news-distribution' ),
				__( 'Result', 'estasoft-news-distribution' ),
			),
			$cells,
			__( 'Discover checks by article page', 'estasoft-news-distribution' )
		) . Html::tag( 'div', array( 'class' => 'estasoft-nd-table-footer' ), View::footer( $footer, $more ) );
	}

	/**
	 * The three note cards under the table.
	 *
	 * @return string
	 */
	private function notes(): string {
		$recommends = array(
			__( 'Titles that capture the essence of the article, without clickbait', 'estasoft-news-distribution' ),
			sprintf(
				/* translators: %s: width in pixels such as "1,200". */
				__( 'Large images at least %s px wide, with large previews allowed', 'estasoft-news-distribution' ),
				number_format_i18n( Discover::MIN_WIDTH )
			),
			__( 'An article photo, not a site logo, as the image', 'estasoft-news-distribution' ),
			__( 'No misleading or exaggerated details in the title, snippet or image', 'estasoft-news-distribution' ),
		);
		$list       = '';

		foreach ( $recommends as $item ) {
			$list .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule estasoft-nd-rule--ok' ), Icon::svg( 'check-circle' ) . Html::tag( 'span', array(), esc_html( $item ) ) );
		}

		$can   = array(
			sprintf(
				/* translators: %s: width in pixels such as "1,200". */
				__( 'Make a %s px copy of an image', 'estasoft-news-distribution' ),
				number_format_i18n( Discover::MIN_WIDTH )
			),
			__( 'Allow large previews, only where a page sets none', 'estasoft-news-distribution' ),
		);
		$items = '';

		foreach ( $can as $item ) {
			$items .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule estasoft-nd-rule--ok' ), Icon::svg( 'check-circle' ) . Html::tag( 'span', array(), esc_html( $item ) ) );
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-notes' ),
			Card::note(
				'discover-recommends',
				__( 'What Google recommends', 'estasoft-news-distribution' ),
				Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $list )
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Google says an indexed article that meets its content policies is automatically eligible, but Discover decides what to show. These checks cover what makes a large image possible. Title hints are only hints, never a verdict.', 'estasoft-news-distribution' ) )
			)
			. Card::note(
				'discover-can',
				__( 'What News Distribution can do', 'estasoft-news-distribution' ),
				Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $items )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), Button::link( __( 'Open image presets', 'estasoft-news-distribution' ), Links::check( 0, 'images' ), array( 'size' => 'sm' ) ) )
			)
			. Card::cannot(
				array(
					__( 'Make an article appear in Discover', 'estasoft-news-distribution' ),
					__( 'Rewrite your title', 'estasoft-news-distribution' ),
					__( 'Remove a limit that another plugin sets', 'estasoft-news-distribution' ),
					__( 'Tell whether a picture is a logo, unless it is the one set as your site logo or site icon', 'estasoft-news-distribution' ),
				)
			)
		);
	}

	public function render(): string {
		$model = $this->model();
		$ctl   = $this->controls();
		$all   = Query::is( 'all', '1' );
		$out   = '';
		$text  = $ctl->problem();

		if ( '' !== $text ) {
			$out .= View::lead( 'danger', $text );
		}

		return $out
			. $ctl->progress()
			. View::lead( 'info', __( 'Discover is not a channel you apply to.', 'estasoft-news-distribution' ), __( 'These checks only remove what would stop a large image from showing.', 'estasoft-news-distribution' ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-notes' ), $this->preview_card( $model ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-card estasoft-nd-card--flush' ), $this->table( $model, $all ) )
			. $this->notes();
	}
}

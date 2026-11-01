<?php
/**
 * Article check: the list of recent articles, and one article with its tabs.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Pages;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Check\ArticleList;
use Estasoft\NewsDistribution\Admin\Check\ArticleOptions;
use Estasoft\NewsDistribution\Admin\Check\AuthorView;
use Estasoft\NewsDistribution\Admin\Check\Context;
use Estasoft\NewsDistribution\Admin\Check\Links;
use Estasoft\NewsDistribution\Admin\Check\Times;
use Estasoft\NewsDistribution\Admin\CheckTabs\AbstractCheckTab;
use Estasoft\NewsDistribution\Admin\Page;
use Estasoft\NewsDistribution\Admin\Ui\EmptyState;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\PageHeader;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-check` is the list of recent articles with the article at the top
 * open beside it. Adding `&post=<id>` opens one article. An article has tabs, found by folder in
 * `src/Admin/CheckTabs/`: Checks (the default), Feed entry, History, and whatever else another
 * feature adds (release plan, takedown, assign a fix). A tab can also be about the whole site
 * (`&tab=images`, with no post).
 *
 * A person who may only see their own articles gets a shorter screen: their articles and the
 * checks of each, and nothing about anyone else's.
 */
final class CheckPage extends Page {

	private ?Links $links = null;

	public function slug(): string {
		return ArticleList::PAGE;
	}

	public function title(): string {
		return __( 'Article check', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 30;
	}

	public function permission(): string {
		return Access::VIEW_OWN;
	}

	public function in_simple_view(): bool {
		return true;
	}

	/**
	 * Addresses of other screens, read once.
	 *
	 * @return Links
	 */
	private function links(): Links {
		if ( null === $this->links ) {
			$this->links = new Links( $this->plugin );
		}

		return $this->links;
	}

	/**
	 * The article in the address, or 0 for the list.
	 *
	 * @return int
	 */
	private function post_id(): int {
		return Query::int( 'post' );
	}

	/**
	 * The tab the address asks for. `entry=<channel>` alone means the Feed entry tab.
	 *
	 * @return string
	 */
	private function wanted_tab(): string {
		$tab = Query::key( 'tab' );

		if ( '' === $tab && Query::has( 'entry' ) ) {
			return 'entry';
		}

		return $tab;
	}

	/**
	 * The tab that is open: the one asked for if the person may use it, else Checks.
	 *
	 * @param int $post_id The article, or 0 for a tab about the whole site.
	 * @return AbstractCheckTab|null Null for the list, or when the site-wide tab asked for does not exist.
	 */
	private function active_tab( int $post_id ): ?AbstractCheckTab {
		$tabs   = $this->links()->tabs( $post_id );
		$wanted = $this->wanted_tab();

		if ( $post_id < 1 ) {
			return '' !== $wanted && isset( $tabs[ $wanted ] ) ? $tabs[ $wanted ] : null;
		}

		if ( isset( $tabs[ $wanted ] ) ) {
			return $tabs[ $wanted ];
		}

		return $tabs['checks'] ?? ( array() === $tabs ? null : reset( $tabs ) );
	}

	/**
	 * Stops a person who asked for a tab they may not use. A tab that does not exist falls back to Checks.
	 *
	 * @param int $post_id The article, or 0.
	 * @return void
	 */
	private function require_tab_permission( int $post_id ): void {
		$all    = $this->links()->all_tabs();
		$wanted = $this->wanted_tab();

		if ( isset( $all[ $wanted ] ) && $all[ $wanted ]->needs_article() === ( $post_id > 0 ) && $all[ $wanted ]->available( $post_id ) ) {
			$this->plugin->access()->require_or_die( $all[ $wanted ]->permission(), $post_id > 0 ? $post_id : null );
		}
	}

	public function handle(): void {
		$post_id = $this->post_id();

		if ( $post_id > 0 && ! $this->plugin->access()->can( Access::VIEW_OWN, $post_id ) ) {
			return;
		}

		$tab = $this->active_tab( $post_id );

		if ( null !== $tab ) {
			$tab->handle( $post_id );
		}
	}

	public function enqueue(): void {
		Assets::screen_style( 'check' );
		Assets::screen_script(
			'check',
			array(
				'text' => array(
					'running'    => __( 'Checking…', 'estasoft-news-distribution' ),
					'done'       => __( 'Checked just now.', 'estasoft-news-distribution' ),
					'failed'     => __( 'Could not run the check. Try again, or reload the page.', 'estasoft-news-distribution' ),
					'copied'     => __( 'Entry copied.', 'estasoft-news-distribution' ),
					'copy_error' => __( 'Could not copy. Select the text and copy it by hand.', 'estasoft-news-distribution' ),
				),
			)
		);

		$post_id = $this->post_id();

		if ( $post_id > 0 && ! $this->plugin->access()->can( Access::VIEW_OWN, $post_id ) ) {
			return;
		}

		$tab = $this->active_tab( $post_id );

		if ( null !== $tab ) {
			$tab->enqueue();
		}
	}

	public function render(): string {
		$access  = $this->plugin->access();
		$post_id = $this->post_id();

		if ( $post_id > 0 ) {
			$access->require_or_die( Access::VIEW_OWN, $post_id );
			$this->require_tab_permission( $post_id );

			return $this->article( $post_id );
		}

		$this->require_tab_permission( 0 );

		$site_tab = $this->active_tab( 0 );

		if ( null !== $site_tab ) {
			return $this->crumbs( $site_tab->crumb( 0 ) ) . PageHeader::render( $site_tab->title(), $site_tab->description() ) . $site_tab->render( 0 );
		}

		if ( $access->sees_only_own() ) {
			return ( new AuthorView( $this->plugin ) )->render( $this->links() );
		}

		return $this->overview();
	}

	/**
	 * The list, with the newest article open beside it.
	 *
	 * @return string Escaped HTML.
	 */
	private function overview(): string {
		$list   = new ArticleList( $this->plugin );
		$state  = $list->state();
		$result = $list->query( $state );
		$items  = $result['items'];
		$first  = array() === $items ? 0 : (int) $items[0]['id'];
		$head   = PageHeader::render( __( 'Article check', 'estasoft-news-distribution' ), __( 'Why each article is, or is not, on each channel, and how to fix it.', 'estasoft-news-distribution' ) );
		$side   = $list->render( $items, $result['total'], $state, $first );
		$main   = '';

		if ( $first > 0 ) {
			$ctx  = new Context( $this->plugin, $first );
			$tabs = $this->links()->tabs( $first );
			$main = isset( $tabs['checks'] ) ? $this->detail( $ctx, $tabs, $tabs['checks'] ) : '';
		}

		return $head . $list->banner( $items, $result['total'], $state ) . $this->split( $side, $main );
	}

	/**
	 * One article: with the list beside it on the Checks tab, alone on the others.
	 *
	 * @param int $post_id The article.
	 * @return string Escaped HTML.
	 */
	private function article( int $post_id ): string {
		$ctx  = new Context( $this->plugin, $post_id );
		$tabs = $this->links()->tabs( $post_id );
		$tab  = $this->active_tab( $post_id );

		if ( ! $ctx->exists() || null === $tab ) {
			return $this->crumbs( '' ) . PageHeader::render( __( 'Article check', 'estasoft-news-distribution' ) ) . EmptyState::render(
				__( 'This is not an article News Distribution sends', 'estasoft-news-distribution' ),
				__( 'Its post type is not one of the types chosen in Settings, General.', 'estasoft-news-distribution' )
			);
		}

		if ( 'checks' !== $tab->slug() ) {
			return $this->crumbs( $ctx->title() )
				. PageHeader::render( $tab->title(), $tab->description(), $this->switcher( $tabs, $tab, $post_id ) )
				. $tab->render( $post_id );
		}

		if ( $this->plugin->access()->sees_only_own() ) {
			return $this->crumbs( $ctx->title() ) . $this->detail( $ctx, $tabs, $tab );
		}

		$list   = new ArticleList( $this->plugin );
		$state  = $list->state();
		$result = $list->query( $state );

		return PageHeader::render( __( 'Article check', 'estasoft-news-distribution' ), $tab->description() )
			. $list->banner( $result['items'], $result['total'], $state )
			. $this->split( $list->render( $result['items'], $result['total'], $state, $post_id ), $this->detail( $ctx, $tabs, $tab ) );
	}

	/**
	 * The list on one side and the open article on the other.
	 *
	 * @param string $side Escaped HTML of the list.
	 * @param string $main Escaped HTML of the article.
	 * @return string
	 */
	private function split( string $side, string $main ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-checksplit' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-checksplit__list' ), $side )
			. ( '' === $main ? '' : Html::tag( 'div', array( 'class' => 'estasoft-nd-checksplit__main' ), $main ) )
		);
	}

	/**
	 * "Article check / Title" above a heading.
	 *
	 * @param string $current The name of the page below the list. Empty to leave it out.
	 * @return string Escaped HTML.
	 */
	private function crumbs( string $current ): string {
		return Html::tag(
			'nav',
			array(
				'class'      => 'estasoft-nd-breadcrumb estasoft-nd-small',
				'aria-label' => __( 'Breadcrumb', 'estasoft-news-distribution' ),
			),
			Html::tag( 'a', array( 'href' => $this->url() ), esc_html__( 'Article check', 'estasoft-news-distribution' ) )
			. ( '' === $current ? '' : Html::tag( 'span', array( 'aria-hidden' => 'true' ), ' / ' ) . Html::tag( 'span', array( 'aria-current' => 'page' ), esc_html( $current ) ) )
		);
	}

	/**
	 * The tabs of an article as one segmented control.
	 *
	 * @param array<string, AbstractCheckTab> $tabs    The tabs this person can open.
	 * @param AbstractCheckTab                $current The open tab.
	 * @param int                             $post_id The article.
	 * @return string Escaped HTML, empty when there is only one tab.
	 */
	private function switcher( array $tabs, AbstractCheckTab $current, int $post_id ): string {
		if ( count( $tabs ) < 2 ) {
			return '';
		}

		$links = '';

		foreach ( $tabs as $slug => $tab ) {
			$on     = $slug === $current->slug();
			$links .= Html::tag(
				'a',
				array(
					'class'        => $on ? 'is-on' : null,
					'href'         => $tab->url( $post_id ),
					'aria-current' => $on ? 'page' : null,
				),
				esc_html( $tab->title() )
			);
		}

		return Html::tag(
			'nav',
			array(
				'class'      => 'estasoft-nd-seg',
				'aria-label' => __( 'Article sections', 'estasoft-news-distribution' ),
			),
			$links
		);
	}

	/**
	 * The card of one article: title, links, what is known about it, the tabs and the open tab.
	 *
	 * @param Context                         $ctx  The article.
	 * @param array<string, AbstractCheckTab> $tabs The tabs this person can open.
	 * @param AbstractCheckTab                $tab  The open tab.
	 * @return string Escaped HTML.
	 */
	private function detail( Context $ctx, array $tabs, AbstractCheckTab $tab ): string {
		$id    = $ctx->id();
		$post  = $ctx->post();
		$title = 'estasoft-nd-detail-title';

		if ( ! $ctx->exists() || null === $post ) {
			return '';
		}

		$actions = '';

		if ( $ctx->is_published() ) {
			$actions .= Html::tag(
				'a',
				array(
					'class' => 'estasoft-nd-btn estasoft-nd-btn--subtle',
					'href'  => (string) get_permalink( $post ),
				),
				esc_html__( 'View on site', 'estasoft-news-distribution' )
			);
		}

		if ( current_user_can( 'edit_post', $id ) ) {
			$actions .= Html::tag(
				'a',
				array(
					'class' => 'estasoft-nd-btn',
					'href'  => (string) get_edit_post_link( $id, 'raw' ),
				),
				esc_html__( 'Edit post', 'estasoft-news-distribution' )
			);
		}

		$takedown = $ctx->is_published() ? $this->links()->tab( 'takedown', $id ) : '';

		if ( '' !== $takedown ) {
			$actions .= Html::tag(
				'a',
				array(
					'class' => 'estasoft-nd-btn',
					'href'  => $takedown,
				),
				esc_html__( 'Take down', 'estasoft-news-distribution' )
			);
		}

		$head = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-detail__head' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-detail__text' ),
				Html::tag(
					'h2',
					array(
						'class' => 'estasoft-nd-page-title estasoft-nd-detail__title',
						'id'    => $title,
						'lang'  => $ctx->language(),
						'dir'   => 'auto',
					),
					esc_html( $ctx->title() )
				)
				. $this->meta( $ctx )
			)
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8 estasoft-nd-detail__actions' ), $actions )
		);

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--flush estasoft-nd-detail',
				'aria-labelledby' => $title,
			),
			$head
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-detail__tabs' ), $this->switcher( $tabs, $tab, $id ) )
			. $tab->render( $id )
		);
	}

	/**
	 * The line under the title: language, when it was published, its content label, who skips it.
	 *
	 * @param Context $ctx The article.
	 * @return string Escaped HTML.
	 */
	private function meta( Context $ctx ): string {
		$post   = $ctx->post();
		$parts  = array();
		$status = null === $post ? '' : $post->post_status;

		if ( $ctx->is_published() && null !== $ctx->article() ) {
			$parts[] = sprintf(
				/* translators: %s: a date and time such as "today, 13:41". */
				__( 'Published %s', 'estasoft-news-distribution' ),
				lcfirst( Times::day( $ctx->article()->published_ts() ) )
			);
		} elseif ( 'future' === $status && null !== $ctx->article() ) {
			$parts[] = sprintf(
				/* translators: %s: a date and time such as "21 Oct, 15:00". */
				__( 'Scheduled for %s', 'estasoft-news-distribution' ),
				Times::short( $ctx->article()->published_ts() )
			);
		} elseif ( 'pending' === $status ) {
			$parts[] = __( 'Pending review', 'estasoft-news-distribution' );
		} else {
			$parts[] = __( 'Draft', 'estasoft-news-distribution' );
		}

		$options = ArticleOptions::get( $ctx->id() );
		$labels  = ArticleOptions::content_labels();
		$label   = (string) $options['content_label'];

		$parts[] = sprintf(
			/* translators: %s: a content label such as "Original". */
			__( 'Content label: %s', 'estasoft-news-distribution' ),
			$labels[ $label ] ?? $label
		);

		if ( $ctx->was_checked() ) {
			$skipping = array_values( $ctx->skipping() );

			if ( array() === $skipping ) {
				$parts[] = __( 'No channel skips this article', 'estasoft-news-distribution' );
			} elseif ( 1 === count( $skipping ) ) {
				$parts[] = sprintf(
					/* translators: %s: a channel name. */
					__( '%s skips this article', 'estasoft-news-distribution' ),
					$skipping[0]
				);
			} else {
				$parts[] = sprintf(
					/* translators: %s: a number of channels. */
					__( '%s channels skip this article', 'estasoft-news-distribution' ),
					number_format_i18n( count( $skipping ) )
				);
			}
		}

		return Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-small estasoft-nd-muted estasoft-nd-detail__meta' ),
			Tag::lang( $ctx->language() ) . ' ' . esc_html( implode( ' · ', $parts ) )
		);
	}
}

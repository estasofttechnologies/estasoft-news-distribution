<?php
/**
 * Network Admin, Sites: every site with its health, and templates to apply to chosen sites.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Network\Screens;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\CommandBar;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\PageHeader;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Channels\SearchSitemap\FetchSummary;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Network\SiteLoop;
use Estasoft\NewsDistribution\Network\SitesList;
use Estasoft\NewsDistribution\Network\Templates;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * The list (with a search and three filters that work as links), the "Apply a template" card, the
 * review page and the small forms for saving and deleting a template. Everything works without script;
 * the script only adds "select all" and a live "N sites selected" line.
 *
 * Choosing sites and a template and pressing "Review the changes" writes nothing: it opens the
 * review, a list of every change on every chosen site with a tick box each. A setting the site's owner
 * changed after setup is listed unticked. "Apply" writes the ticked changes, and "Undo" puts them back.
 */
final class SitesScreen extends AbstractScreen {

	public const REVIEW   = 'estasoft_nd_net_review';
	public const APPLY    = 'estasoft_nd_net_apply';
	public const UNDO     = 'estasoft_nd_net_undo';
	public const SAVE     = 'estasoft_nd_net_save_template';
	public const DELETE   = 'estasoft_nd_net_delete_template';
	public const FORM_ID  = 'estasoft-nd-net-apply';
	public const NO_SITES = 'sites';

	public function tab(): string {
		return 'sites';
	}

	public function title(): string {
		return __( 'Sites', 'estasoft-news-distribution' );
	}

	public function enqueue(): void {
		Assets::screen_script(
			'network',
			array(
				'text' => array(
					'none' => __( 'No site selected. Tick sites in the list above.', 'estasoft-news-distribution' ),
					/* translators: 1: number of sites, 2: their names. The script fills both. */
					'one'  => __( '%1$d site selected: %2$s.', 'estasoft-news-distribution' ),
					/* translators: 1: number of sites, 2: their names. The script fills both. */
					'many' => __( '%1$d sites selected: %2$s.', 'estasoft-news-distribution' ),
				),
			)
		);
	}

	public function actions(): array {
		return array(
			self::REVIEW => 'post_review',
			self::APPLY  => 'post_apply',
			self::UNDO   => 'post_undo',
			self::SAVE   => 'post_save',
			self::DELETE => 'post_delete',
		);
	}

	/**
	 * The tab: the list and the cards, or the review when one is asked for.
	 *
	 * @return string
	 */
	public function render(): string {
		$flash = $this->take_flash();

		if ( $this->reviewing() ) {
			return $flash . $this->render_review();
		}

		$search = $this->search();
		$filter = $this->filter();
		$data   = SitesList::build( $search, $filter, $this->wants_fresh() );

		return PageHeader::render(
			__( 'Sites', 'estasoft-news-distribution' ),
			__( 'Every site in your network, and which of them needs you.', 'estasoft-news-distribution' ),
			Button::link( __( 'Add a site in WordPress', 'estasoft-news-distribution' ), network_admin_url( 'site-new.php' ) )
		)
			. $flash
			. Message::text( 'info', __( 'This is the Network Admin screen of a WordPress multisite network. Separate WordPress installs are not connected: each one runs on its own.', 'estasoft-news-distribution' ) )
			. $this->undo_bar( $data )
			. $this->command_bar( $data, $search, $filter )
			. $this->table( $data, $search, $filter )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-notes' ),
				$this->template_card( $data )
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-net-side' ),
					Card::cannot(
						array(
							__( 'Connect separate WordPress installs. It covers the sites of one multisite network.', 'estasoft-news-distribution' ),
							__( 'Fix a site’s problem from here. Open the site to do it.', 'estasoft-news-distribution' ),
						)
					)
					. $this->saved_templates_card( $data )
				)
			);
	}

	/**
	 * The search and the three filters.
	 *
	 * @param array<string, mixed> $data   The list.
	 * @param string               $search Search text.
	 * @param string               $filter Filter.
	 * @return string
	 */
	private function command_bar( array $data, string $search, string $filter ): string {
		$counts = $data['counts'];
		$items  = array(
			'all'        => array(
				/* translators: %d: number of sites. */
				'label' => sprintf( __( 'All (%d)', 'estasoft-news-distribution' ), $counts['all'] ),
				'url'   => $this->url( $this->keep( array( 's' => $search ), 'all' ) ),
			),
			'attention'  => array(
				/* translators: %d: number of sites. */
				'label' => sprintf( __( 'Needs attention (%d)', 'estasoft-news-distribution' ), $counts['attention'] ),
				'url'   => $this->url( $this->keep( array( 's' => $search ), 'attention' ) ),
			),
			'not_set_up' => array(
				/* translators: %d: number of sites. */
				'label' => sprintf( __( 'Not set up (%d)', 'estasoft-news-distribution' ), $counts['not_set_up'] ),
				'url'   => $this->url( $this->keep( array( 's' => $search ), 'not_set_up' ) ),
			),
		);

		return Html::tag(
			'form',
			array(
				'class'  => 'estasoft-nd-commandbar',
				'method' => 'get',
				'action' => network_admin_url( 'admin.php' ),
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'page',
					'value' => \Estasoft\NewsDistribution\Network\NetworkAdmin::PAGE,
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'tab',
					'value' => 'sites',
				)
			)
			. ( 'all' !== $filter ? Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'filter',
					'value' => $filter,
				)
			) : '' )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-net-search' ),
				Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-sr',
						'for'   => 'estasoft-nd-net-search',
					),
					esc_html__( 'Search sites', 'estasoft-news-distribution' )
				)
				. Html::void(
					'input',
					array(
						'class'       => 'estasoft-nd-field',
						'type'        => 'search',
						'id'          => 'estasoft-nd-net-search',
						'name'        => 's',
						'value'       => $search,
						'placeholder' => __( 'Search sites', 'estasoft-news-distribution' ),
					)
				)
			)
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), CommandBar::segments( $items, $filter, __( 'Show', 'estasoft-news-distribution' ) ) )
		);
	}

	/**
	 * The table of sites, with a tick box on each.
	 *
	 * @param array<string, mixed> $data   The list.
	 * @param string               $search Search text.
	 * @param string               $filter Filter.
	 * @return string
	 */
	private function table( array $data, string $search, string $filter ): string {
		$sites = $data['sites'];
		$cols  = 'estasoft-nd-cols-net-sites';

		if ( array() === $sites ) {
			return Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-card estasoft-nd-card--flush' ),
				Html::tag(
					'p',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-net-empty' ),
					esc_html( '' !== $search || 'all' !== $filter ? __( 'No site matches. Clear the search or choose All.', 'estasoft-news-distribution' ) : __( 'This network has no sites yet.', 'estasoft-news-distribution' ) )
				)
			);
		}

		$head = Html::tag(
			'div',
			array(
				'class' => Html::classes( 'estasoft-nd-grid-row', 'estasoft-nd-grid-row--head', $cols ),
				'role'  => 'row',
			),
			Html::tag(
				'div',
				array( 'role' => 'columnheader' ),
				Html::tag(
					'label',
					array(
						'class'  => 'estasoft-nd-check estasoft-nd-net-all',
						'hidden' => true,
					),
					Html::void(
						'input',
						array(
							'type'            => 'checkbox',
							'data-select-all' => true,
							'aria-label'      => sprintf(
								/* translators: %d: number of sites. */
								_n( 'Select all %d site', 'Select all %d sites', count( $sites ), 'estasoft-news-distribution' ),
								count( $sites )
							),
						)
					)
				)
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-sr' ), esc_html__( 'Select', 'estasoft-news-distribution' ) )
			)
			. $this->head_cell( __( 'Site', 'estasoft-news-distribution' ) )
			. $this->head_cell( __( 'Needs attention', 'estasoft-news-distribution' ) )
			. $this->head_cell( __( 'Languages', 'estasoft-news-distribution' ) )
			. $this->head_cell( __( 'Channels set up', 'estasoft-news-distribution' ) )
			. $this->head_cell( __( 'Last fetch', 'estasoft-news-distribution' ) )
		);

		$rows = '';

		foreach ( $sites as $site ) {
			$rows .= Html::tag(
				'div',
				array(
					'class' => Html::classes( 'estasoft-nd-grid-row', $cols ),
					'role'  => 'row',
				),
				$this->cell( $this->checkbox( $site ) )
				. $this->cell(
					Html::tag( 'a', array( 'href' => (string) $site['admin_url'] ), esc_html( (string) $site['name'] ) )
					. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-net-block' ), esc_html( (string) $site['domain'] ) )
				)
				. $this->cell( $this->needs( $site ) )
				. $this->cell( $this->languages( $site ) )
				. $this->cell( $this->channels_set_up( $site ) )
				. $this->cell( $this->last_fetch( $site ) )
			);
		}

		$shown  = count( $sites );
		$total  = (int) $data['counts']['all'];
		$footer = sprintf(
			/* translators: 1: sites shown, 2: sites in the network. */
			_n( 'Showing %1$d of %2$d site', 'Showing %1$d of %2$d sites', $total, 'estasoft-news-distribution' ),
			$shown,
			$total
		);

		if ( (int) $data['left'] > 0 || (int) $data['total'] > $total ) {
			$footer .= '. ' . sprintf(
				/* translators: %d: the most sites one reading covers. */
				__( 'A reading covers the first %d sites', 'estasoft-news-distribution' ),
				SiteLoop::MAX_SITES
			);
		}

		$footer .= ' · ' . sprintf(
			/* translators: %s: how long ago the sites were read, such as "3 min ago". */
			__( 'Updated %s', 'estasoft-news-distribution' ),
			FetchSummary::ago( (int) strtotime( (string) $data['generated'] ) )
		);

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-card estasoft-nd-card--flush estasoft-nd-scroll' ),
			Html::tag(
				'div',
				array(
					'class'      => 'estasoft-nd-table',
					'role'       => 'table',
					'aria-label' => __( 'Sites in this network', 'estasoft-news-distribution' ),
				),
				$head . $rows . Html::tag( 'div', array( 'class' => 'estasoft-nd-table-footer' ), esc_html( $footer ) )
			)
		);
	}

	/**
	 * @param string $text Plain heading.
	 * @return string
	 */
	private function head_cell( string $text ): string {
		return Html::tag( 'div', array( 'role' => 'columnheader' ), esc_html( $text ) );
	}

	/**
	 * @param string $html Trusted HTML.
	 * @return string
	 */
	private function cell( string $html ): string {
		return Html::tag( 'div', array( 'role' => 'cell' ), $html );
	}

	/**
	 * The tick box of a site. It belongs to the "Apply a template" form below the table.
	 *
	 * @param array<string, mixed> $site The site.
	 * @return string
	 */
	private function checkbox( array $site ): string {
		return Html::tag(
			'label',
			array( 'class' => 'estasoft-nd-check' ),
			Html::void(
				'input',
				array(
					'type'           => 'checkbox',
					'name'           => 'sites[]',
					'value'          => (string) (int) $site['id'],
					'form'           => self::FORM_ID,
					'data-site-name' => (string) $site['name'],
					'checked'        => $this->is_picked( (int) $site['id'] ),
					'aria-label'     => sprintf(
						/* translators: %s: a site's name. */
						__( 'Select %s', 'estasoft-news-distribution' ),
						(string) $site['name']
					),
				)
			)
		);
	}

	/**
	 * What needs the person on a site, in words and with the status icon.
	 *
	 * @param array<string, mixed> $site The site.
	 * @return string
	 */
	private function needs( array $site ): string {
		$needs = array_values( array_map( 'strval', (array) $site['needs'] ) );

		if ( Status::HEALTHY === $site['status'] ) {
			return StatusView::inline( Status::HEALTHY, __( 'Nothing needs you', 'estasoft-news-distribution' ) );
		}

		if ( Status::NOT_SET_UP === $site['status'] && array() === $needs ) {
			return StatusView::inline( Status::NOT_SET_UP );
		}

		$first = array_shift( $needs );
		$html  = StatusView::inline( Status::NOT_SET_UP === $site['status'] ? Status::NOT_SET_UP : Status::ATTENTION, $first );

		foreach ( $needs as $more ) {
			$html .= Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $more ) );
		}

		return $html;
	}

	/**
	 * "12 of 16", or a dash for a site where the plugin is not active.
	 *
	 * @param array<string, mixed> $site The site.
	 * @return string
	 */
	private function channels_set_up( array $site ): string {
		if ( (int) $site['channels_total'] < 1 ) {
			return Html::tag(
				'span',
				array(
					'class'      => 'estasoft-nd-muted',
					'aria-label' => __( 'None', 'estasoft-news-distribution' ),
				),
				'—'
			);
		}

		return esc_html(
			sprintf(
				/* translators: 1: channels set up, 2: channels the plugin has. */
				__( '%1$d of %2$d', 'estasoft-news-distribution' ),
				(int) $site['channels_on'],
				(int) $site['channels_total']
			)
		);
	}

	/**
	 * @param array<string, mixed> $site The site.
	 * @return string
	 */
	private function languages( array $site ): string {
		$codes = (array) $site['languages'];

		if ( array() === $codes ) {
			return Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'None', 'estasoft-news-distribution' ) );
		}

		$chips = '';

		foreach ( $codes as $code ) {
			$chips .= Tag::lang( (string) $code );
		}

		return Html::tag( 'span', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-4' ), $chips );
	}

	/**
	 * @param array<string, mixed> $site The site.
	 * @return string
	 */
	private function last_fetch( array $site ): string {
		$when = null === $site['last_fetch'] ? 0 : (int) strtotime( (string) $site['last_fetch'] );

		return $when > 0
			? esc_html( FetchSummary::ago( $when ) )
			: Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( Status::word( Status::NEVER ) ) );
	}

	/**
	 * The bar that offers to undo the last template run.
	 *
	 * @param array<string, mixed> $data The list.
	 * @return string
	 */
	private function undo_bar( array $data ): string {
		$last = $data['last_applied'];

		if ( ! is_array( $last ) ) {
			return '';
		}

		$form = Html::tag(
			'form',
			array(
				'method' => 'post',
				'action' => $this->post_url( self::UNDO ),
			),
			$this->nonce( self::UNDO )
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'id',
					'value' => (string) $last['id'],
				)
			)
			. Button::submit( __( 'Undo', 'estasoft-news-distribution' ) )
		);

		return Message::bar(
			'info',
			esc_html(
				sprintf(
					/* translators: 1: a template name, 2: number of sites, 3: when. */
					_n( 'Template “%1$s” was applied to %2$d site, %3$s. Undo puts back what it changed, except what a site’s owner changed since.', 'Template “%1$s” was applied to %2$d sites, %3$s. Undo puts back what it changed, except what a site’s owner changed since.', (int) $last['sites'], 'estasoft-news-distribution' ),
					(string) $last['name'],
					(int) $last['sites'],
					Clock::datetime( (int) strtotime( (string) $last['at'] ) )
				)
			),
			$form
		);
	}

	/* ------------------------------------------------------------- Templates. */

	/**
	 * The "Apply a template" card.
	 *
	 * @param array<string, mixed> $data The list.
	 * @return string
	 */
	private function template_card( array $data ): string {
		$templates = (array) $data['templates'];
		$picked    = $this->picked_names( $data );
		$chosen    = $this->requested_template( $templates );
		$options   = '';

		foreach ( $templates as $template ) {
			$options .= Html::tag(
				'option',
				array(
					'value'    => (string) $template['id'],
					'selected' => (string) $template['id'] === $chosen,
				),
				esc_html( (string) $template['name'] )
			);
		}

		$sets  = array(
			__( 'Languages and the default language', 'estasoft-news-distribution' ),
			__( 'The index-first delay and cache time', 'estasoft-news-distribution' ),
			__( 'Alert rules and recipients', 'estasoft-news-distribution' ),
			__( 'Page markup and image rules', 'estasoft-news-distribution' ),
		);
		$never = array(
			__( 'Platform accounts, applications and statuses', 'estasoft-news-distribution' ),
			__( 'Verification tags and IndexNow keys', 'estasoft-news-distribution' ),
			__( 'A setting a site owner changed after setup. The review lists it, unticked.', 'estasoft-news-distribution' ),
		);

		$body = Html::tag(
			'p',
			array(
				'class'                 => 'estasoft-nd-muted',
				'data-selected-summary' => true,
				'aria-live'             => 'polite',
			),
			esc_html( $this->selected_sentence( $picked ) )
		);

		if ( array() === $templates ) {
			$body .= Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No template is saved yet. Save one from a site in the next card.', 'estasoft-news-distribution' ) );
		} else {
			$body .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-label',
						'for'   => 'estasoft-nd-net-template',
					),
					esc_html__( 'Template', 'estasoft-news-distribution' )
				)
				. Html::tag(
					'select',
					array(
						'class' => 'estasoft-nd-field',
						'id'    => 'estasoft-nd-net-template',
						'name'  => 'template',
					),
					$options
				)
			)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Templates are saved from a site’s settings. This list shows every saved template.', 'estasoft-news-distribution' ) );
		}

		$body .= $this->rule_list( __( 'It sets', 'estasoft-news-distribution' ), $sets, 'check-circle', 'estasoft-nd-rule--on' )
			. $this->rule_list( __( 'It never sets', 'estasoft-news-distribution' ), $never, 'minus-circle', '' );

		if ( array() !== $templates ) {
			$body .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster' ),
				Button::submit( __( 'Review the changes', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
			)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Nothing is written until you confirm in the review. Each site keeps its previous values, so you can undo the change.', 'estasoft-news-distribution' ) );
		}

		// The ticked sites sit in the table above and join this form through their `form` attribute.
		$form = Html::tag(
			'form',
			array(
				'id'     => self::FORM_ID,
				'class'  => 'estasoft-nd-stack estasoft-nd-gap-16',
				'method' => 'post',
				'action' => $this->post_url( self::REVIEW ),
			),
			$this->nonce( self::REVIEW ) . $body
		);

		return Card::note( 'apply-template', __( 'Apply a template', 'estasoft-news-distribution' ), $form );
	}

	/**
	 * A titled list of what a template does or does not set.
	 *
	 * @param string   $title Heading.
	 * @param string[] $items Plain sentences.
	 * @param string   $icon  Icon name.
	 * @param string   $tone  Extra class.
	 * @return string
	 */
	private function rule_list( string $title, array $items, string $icon, string $tone ): string {
		$list = '';

		foreach ( $items as $item ) {
			$list .= Html::tag( 'li', array( 'class' => Html::classes( 'estasoft-nd-rule', $tone ) ), Icon::svg( $icon ) . Html::tag( 'span', array(), esc_html( $item ) ) );
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ),
			Html::tag( 'h3', array( 'class' => 'estasoft-nd-net-subtitle' ), esc_html( $title ) ) . Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $list )
		);
	}

	/**
	 * The card that lists the saved templates and saves a new one.
	 *
	 * @param array<string, mixed> $data The list.
	 * @return string
	 */
	private function saved_templates_card( array $data ): string {
		$rows = '';

		foreach ( (array) $data['templates'] as $template ) {
			$rows .= Html::tag(
				'li',
				array( 'class' => 'estasoft-nd-net-template' ),
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-grow' ),
					Html::tag( 'strong', array(), esc_html( (string) $template['name'] ) )
					. Html::tag(
						'span',
						array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-net-block' ),
						esc_html(
							sprintf(
								/* translators: 1: number of settings, 2: the site it was saved from, 3: date. */
								_n( '%1$d setting, saved from %2$s on %3$s', '%1$d settings, saved from %2$s on %3$s', (int) $template['settings'], 'estasoft-news-distribution' ),
								(int) $template['settings'],
								$this->site_label( (int) $template['source'] ),
								'' === $template['created'] ? '' : Clock::format( (int) strtotime( (string) $template['created'] ), 'j M Y' )
							)
						)
					)
				)
				. Html::tag(
					'form',
					array(
						'method' => 'post',
						'action' => $this->post_url( self::DELETE ),
					),
					$this->nonce( self::DELETE )
					. Html::void(
						'input',
						array(
							'type'  => 'hidden',
							'name'  => 'template',
							'value' => (string) $template['id'],
						)
					)
					. Button::submit(
						__( 'Delete', 'estasoft-news-distribution' ),
						array(
							'variant' => 'subtle',
							'attrs'   => array(
								'aria-label' => sprintf(
									/* translators: %s: a template name. */
									__( 'Delete the template %s', 'estasoft-news-distribution' ),
									(string) $template['name']
								),
							),
						)
					)
				)
			);
		}

		$sites = '';

		foreach ( (array) $data['sites'] as $site ) {
			$sites .= Html::tag( 'option', array( 'value' => (string) (int) $site['id'] ), esc_html( (string) $site['name'] ) );
		}

		$form = Html::tag(
			'form',
			array(
				'method' => 'post',
				'action' => $this->post_url( self::SAVE ),
				'class'  => 'estasoft-nd-stack estasoft-nd-gap-12',
			),
			$this->nonce( self::SAVE )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-label',
						'for'   => 'estasoft-nd-net-from-site',
					),
					esc_html__( 'Save the settings of', 'estasoft-news-distribution' )
				)
				. Html::tag(
					'select',
					array(
						'class' => 'estasoft-nd-field',
						'id'    => 'estasoft-nd-net-from-site',
						'name'  => 'site',
					),
					$sites
				)
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-label',
						'for'   => 'estasoft-nd-net-template-name',
					),
					esc_html__( 'Template name', 'estasoft-news-distribution' )
				)
				. Html::void(
					'input',
					array(
						'class'        => 'estasoft-nd-field',
						'type'         => 'text',
						'id'           => 'estasoft-nd-net-template-name',
						'name'         => 'name',
						'maxlength'    => Templates::NAME_MAX,
						'required'     => true,
						'autocomplete' => 'off',
					)
				)
			)
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster' ), Button::submit( __( 'Save as template', 'estasoft-news-distribution' ) ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'A template holds only the settings listed under “It sets”. It never holds a secret, a connected account or a key.', 'estasoft-news-distribution' ) )
		);

		return Card::note(
			'templates',
			__( 'Templates', 'estasoft-news-distribution' ),
			( '' === $rows ? '' : Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ), $rows ) )
			. $form
		);
	}

	/* ---------------------------------------------------------------- Review. */

	/**
	 * Whether the review page is being asked for.
	 *
	 * @return bool
	 */
	private function reviewing(): bool {
		return Query::has( 'review' );
	}

	/**
	 * The review: every change on every chosen site, with a tick box each.
	 *
	 * @return string
	 */
	private function render_review(): string {
		$template = Query::text( 'template' );
		$sites    = $this->requested_sites();
		$review   = ( new Templates() )->review( $template, $sites );
		$head     = PageHeader::render( __( 'Review the changes', 'estasoft-news-distribution' ), __( 'Nothing is written until you apply. Untick any change you do not want.', 'estasoft-news-distribution' ) );

		if ( is_wp_error( $review ) ) {
			return $head
				. Message::text( 'danger', $review->get_error_message() )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster' ), Button::link( __( 'Back to Sites', 'estasoft-news-distribution' ), $this->url() ) );
		}

		$body = '';

		foreach ( $review['sites'] as $site ) {
			$rows = '';

			if ( '' !== $site['error'] ) {
				$rows = Html::tag( 'p', array( 'class' => 'estasoft-nd-net-pad' ), StatusView::inline( Status::ATTENTION, (string) $site['error'] ) );
			} elseif ( array() === $site['changes'] ) {
				$rows = Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-net-pad' ), esc_html__( 'Already matches the template. Nothing to change.', 'estasoft-news-distribution' ) );
			}

			foreach ( $site['changes'] as $change ) {
				$rows .= $this->change_row( $change );
			}

			$body .= Card::flush( 'review-' . (int) $site['id'], (string) $site['name'], '', $rows );
		}

		$count = (int) $review['changes'];
		$form  = Html::tag(
			'form',
			array(
				'method'      => 'post',
				'action'      => $this->post_url( self::APPLY ),
				'class'       => 'estasoft-nd-stack estasoft-nd-gap-16',
				'data-review' => true,
			),
			$this->nonce( self::APPLY )
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'template',
					'value' => (string) $review['template']['id'],
				)
			)
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted' ),
				esc_html(
					sprintf(
						/* translators: %s: a template name. */
						__( 'Template: %s', 'estasoft-news-distribution' ),
						(string) $review['template']['name']
					)
				)
			)
			. $body
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
				( $count > 0 ? Button::submit( __( 'Apply the ticked changes', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) ) : '' )
				. Button::link( __( 'Cancel', 'estasoft-news-distribution' ), $this->url() )
			)
		);

		return $head . $form;
	}

	/**
	 * One change in the review.
	 *
	 * @param array<string, mixed> $change The change.
	 * @return string
	 */
	private function change_row( array $change ): string {
		$owner = ! empty( $change['owner_changed'] );
		$id    = 'estasoft-nd-change-' . md5( (string) $change['key'] );
		$words = Html::tag( 'strong', array(), esc_html( (string) $change['label'] ) )
			. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-net-block' ), esc_html( (string) $change['group'] ) )
			. Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-net-change' ),
				esc_html(
					sprintf(
						/* translators: 1: the value the site has now, 2: the value the template sets. */
						__( 'From %1$s to %2$s', 'estasoft-news-distribution' ),
						(string) $change['from_text'],
						(string) $change['to_text']
					)
				)
			);

		if ( $owner ) {
			$words .= Html::tag( 'span', array( 'class' => 'estasoft-nd-net-owner' ), StatusView::inline( Status::ATTENTION, __( 'The site’s owner changed this after setup, so it is unticked.', 'estasoft-news-distribution' ) ) );
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-check-row' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-check estasoft-nd-check--top',
					'for'   => $id,
				),
				Html::void(
					'input',
					array(
						'type'    => 'checkbox',
						'id'      => $id,
						'name'    => 'accept[]',
						'value'   => (string) $change['key'],
						'checked' => ! $owner,
					)
				)
				. Html::tag( 'span', array(), $words )
			)
		);
	}

	/* ------------------------------------------------------------ Form posts. */

	/**
	 * "Review the changes": checks the choice and opens the review.
	 *
	 * @return void
	 */
	public function post_review(): void {
		$this->plugin->access()->require_or_die( Access::NETWORK );
		check_admin_referer( self::REVIEW );

		$template = isset( $_POST['template'] ) ? sanitize_text_field( wp_unslash( $_POST['template'] ) ) : '';
		$sites    = isset( $_POST['sites'] ) && is_array( $_POST['sites'] ) ? array_values( array_filter( array_map( 'intval', wp_unslash( $_POST['sites'] ) ) ) ) : array();

		if ( '' === $template || array() === $sites ) {
			$this->flash( 'danger', __( 'Choose at least one site and a template, then review the changes.', 'estasoft-news-distribution' ) );
			$this->back();
		}

		$this->back(
			array(
				'review'   => 1,
				'template' => $template,
				'sites'    => implode( ',', array_slice( $sites, 0, SiteLoop::MAX_SITES ) ),
			)
		);
	}

	/**
	 * "Apply the ticked changes".
	 *
	 * @return void
	 */
	public function post_apply(): void {
		$this->plugin->access()->require_or_die( Access::NETWORK );
		check_admin_referer( self::APPLY );

		$template = isset( $_POST['template'] ) ? sanitize_text_field( wp_unslash( $_POST['template'] ) ) : '';
		$accept   = isset( $_POST['accept'] ) && is_array( $_POST['accept'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['accept'] ) ) : array();
		$result   = ( new Templates() )->apply( $template, $accept );

		if ( is_wp_error( $result ) ) {
			$this->flash( 'danger', $result->get_error_message() );
			$this->back();
		}

		$lines = array();

		foreach ( $result['skipped'] as $skipped ) {
			$lines[] = sprintf(
				/* translators: 1: a site's name, 2: the reason a change was not made. */
				__( '%1$s: %2$s', 'estasoft-news-distribution' ),
				$this->site_label( (int) $skipped['site'] ),
				(string) $skipped['message']
			);
		}

		$this->flash(
			array() === $lines ? 'success' : 'warning',
			sprintf(
				/* translators: 1: number of settings, 2: number of sites. */
				_n( '%1$d setting changed on %2$d site. Undo is at the top of Sites.', '%1$d settings changed on %2$d sites. Undo is at the top of Sites.', (int) $result['fields'], 'estasoft-news-distribution' ),
				(int) $result['fields'],
				(int) $result['sites']
			),
			$lines
		);
		$this->back();
	}

	/**
	 * "Undo".
	 *
	 * @return void
	 */
	public function post_undo(): void {
		$this->plugin->access()->require_or_die( Access::NETWORK );
		check_admin_referer( self::UNDO );

		$id     = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$result = ( new Templates() )->undo( $id );

		if ( is_wp_error( $result ) ) {
			$this->flash( 'danger', $result->get_error_message() );
			$this->back();
		}

		$lines = array();

		foreach ( $result['skipped'] as $skipped ) {
			$lines[] = sprintf(
				/* translators: 1: a site's name, 2: the reason a change was left. */
				__( '%1$s: %2$s', 'estasoft-news-distribution' ),
				$this->site_label( (int) $skipped['site'] ),
				(string) $skipped['message']
			);
		}

		$this->flash(
			array() === $lines ? 'success' : 'warning',
			sprintf(
				/* translators: %d: number of settings. */
				_n( '%d setting was put back.', '%d settings were put back.', (int) $result['restored'], 'estasoft-news-distribution' ),
				(int) $result['restored']
			),
			$lines
		);
		$this->back();
	}

	/**
	 * "Save as template".
	 *
	 * @return void
	 */
	public function post_save(): void {
		$this->plugin->access()->require_or_die( Access::NETWORK );
		check_admin_referer( self::SAVE );

		$site   = isset( $_POST['site'] ) ? absint( wp_unslash( $_POST['site'] ) ) : 0;
		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$result = ( new Templates() )->save_from_site( $site, $name );

		if ( is_wp_error( $result ) ) {
			$this->flash( 'danger', $result->get_error_message() );
			$this->back();
		}

		$this->flash(
			'success',
			sprintf(
				/* translators: %s: a template name. */
				__( 'Template “%s” saved.', 'estasoft-news-distribution' ),
				(string) $result['name']
			)
		);
		$this->back();
	}

	/**
	 * "Delete" on a template.
	 *
	 * @return void
	 */
	public function post_delete(): void {
		$this->plugin->access()->require_or_die( Access::NETWORK );
		check_admin_referer( self::DELETE );

		$id     = isset( $_POST['template'] ) ? sanitize_text_field( wp_unslash( $_POST['template'] ) ) : '';
		$result = ( new Templates() )->delete( $id );

		$this->flash( is_wp_error( $result ) ? 'danger' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Template deleted. Sites it was applied to keep their values.', 'estasoft-news-distribution' ) );
		$this->back();
	}

	/* ---------------------------------------------------------------- Helpers. */

	/**
	 * The search text from the address.
	 *
	 * @return string
	 */
	private function search(): string {
		return Query::text( 's' );
	}

	/**
	 * The filter from the address.
	 *
	 * @return string all, attention or not_set_up.
	 */
	private function filter(): string {
		$filter = Query::key( 'filter', 'all' );

		return in_array( $filter, array( 'attention', 'not_set_up' ), true ) ? $filter : 'all';
	}

	/**
	 * Whether the page was opened with "read again".
	 *
	 * @return bool
	 */
	private function wants_fresh(): bool {
		return Query::has( 'refresh' );
	}

	/**
	 * Query arguments that keep the search, with a filter.
	 *
	 * @param array<string, string> $args   Arguments, such as the search.
	 * @param string                $filter Filter.
	 * @return array<string, string>
	 */
	private function keep( array $args, string $filter ): array {
		$args = array_filter( $args, 'strlen' );

		if ( 'all' !== $filter ) {
			$args['filter'] = $filter;
		}

		return $args;
	}

	/**
	 * The sites asked for in the review address.
	 *
	 * @return int[]
	 */
	private function requested_sites(): array {
		$raw = Query::text( 'sites' );

		return array_values( array_filter( array_map( 'intval', explode( ',', $raw ) ) ) );
	}

	/**
	 * Whether a site's tick box is ticked when the page opens: after a "back" from the review.
	 *
	 * @param int $blog_id Site ID.
	 * @return bool
	 */
	private function is_picked( int $blog_id ): bool {
		return in_array( $blog_id, $this->requested_sites(), true );
	}

	/**
	 * The names of the sites picked so far.
	 *
	 * @param array<string, mixed> $data The list.
	 * @return string[]
	 */
	private function picked_names( array $data ): array {
		$names = array();

		foreach ( (array) $data['sites'] as $site ) {
			if ( $this->is_picked( (int) $site['id'] ) ) {
				$names[] = (string) $site['name'];
			}
		}

		return $names;
	}

	/**
	 * "2 sites selected: Example Times and Example Kurdistan."
	 *
	 * @param string[] $names The names.
	 * @return string
	 */
	private function selected_sentence( array $names ): string {
		if ( array() === $names ) {
			return __( 'No site selected. Tick sites in the list above.', 'estasoft-news-distribution' );
		}

		return sprintf(
			/* translators: 1: number of sites, 2: their names, such as "Example Times and Example Kurdistan". */
			_n( '%1$d site selected: %2$s.', '%1$d sites selected: %2$s.', count( $names ), 'estasoft-news-distribution' ),
			count( $names ),
			wp_sprintf_l( '%l', $names )
		);
	}

	/**
	 * The template the form shows first.
	 *
	 * @param array<int, array<string, mixed>> $templates Saved templates.
	 * @return string
	 */
	private function requested_template( array $templates ): string {
		$want = Query::text( 'template' );

		foreach ( $templates as $template ) {
			if ( (string) $template['id'] === $want ) {
				return $want;
			}
		}

		return array() === $templates ? '' : (string) $templates[0]['id'];
	}

	/**
	 * A site's name without switching to it.
	 *
	 * @param int $blog_id Site ID.
	 * @return string
	 */
	private function site_label( int $blog_id ): string {
		$name = trim( (string) get_blog_option( $blog_id, 'blogname', '' ) );

		return '' !== $name ? $name : (string) $blog_id;
	}
}

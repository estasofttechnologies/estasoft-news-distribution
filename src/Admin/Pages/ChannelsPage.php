<?php
/**
 * Channels: the hub, and the page of each channel.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Pages;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Channels\AbstractChannelScreen;
use Estasoft\NewsDistribution\Admin\Channels\ApplicationsTab;
use Estasoft\NewsDistribution\Admin\Channels\GenericScreen;
use Estasoft\NewsDistribution\Admin\Channels\Hub;
use Estasoft\NewsDistribution\Admin\Page;
use Estasoft\NewsDistribution\Admin\Partners\PartnersTab;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Support\Discovery;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels` is the hub (`Admin\Channels\Hub`).
 * `&tab=applications` is the application tracker (`ApplicationsTab`); another
 * hub tab, such as Partners, is added to `hub_tab()` and the three places that
 * switch on it. Adding `&channel=<id>` opens one channel inside a frame
 * (breadcrumb, heading, mode tag, health, tabs) and prints that channel's own
 * screen, found by folder in `src/Admin/Channels/`. A channel without a screen
 * gets a generic page built from its fields.
 */
final class ChannelsPage extends Page {

	private ?Hub $hub                     = null;
	private ?ApplicationsTab $application = null;
	private ?PartnersTab $partners        = null;

	public function slug(): string {
		return 'estasoft-nd-channels';
	}

	public function title(): string {
		return __( 'Channels', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 20;
	}

	public function in_simple_view(): bool {
		return true;
	}

	/**
	 * The channel being opened, or null for the hub.
	 *
	 * @return ChannelInterface|null
	 */
	private function channel(): ?ChannelInterface {
		$id = Query::key( 'channel' );

		return '' === $id ? null : $this->plugin->registry()->get( $id );
	}

	/**
	 * The screen for a channel: its own, or the generic one.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return AbstractChannelScreen
	 */
	private function screen( ChannelInterface $channel ): AbstractChannelScreen {
		foreach ( Discovery::classes( 'Admin/Channels' ) as $class ) {
			if ( class_exists( $class ) && is_subclass_of( $class, AbstractChannelScreen::class ) && $class::channel_id() === $channel->id() ) {
				return new $class( $this->plugin, $channel );
			}
		}

		return new GenericScreen( $this->plugin, $channel );
	}

	/**
	 * The open tab of a channel page: the `tab` argument if the screen has it, else its first tab.
	 *
	 * @param AbstractChannelScreen $screen The screen.
	 * @return string Empty when the screen has no tabs.
	 */
	private function tab( AbstractChannelScreen $screen ): string {
		$tabs = array_keys( $screen->tabs() );

		if ( array() === $tabs ) {
			return '';
		}

		$wanted = Query::key( 'tab' );

		return in_array( $wanted, $tabs, true ) ? $wanted : (string) $tabs[0];
	}

	/**
	 * The open tab of the hub: "applications" or "partners", or an empty string for the channel list.
	 *
	 * @return string
	 */
	private function hub_tab(): string {
		$wanted = Query::key( 'tab' );

		return in_array( $wanted, array( 'applications', PartnersTab::SLUG ), true ) ? $wanted : '';
	}

	/**
	 * The channel list.
	 *
	 * @return Hub
	 */
	private function hub(): Hub {
		if ( null === $this->hub ) {
			$this->hub = new Hub( $this->plugin, $this );
		}

		return $this->hub;
	}

	/**
	 * The application tracker.
	 *
	 * @return ApplicationsTab
	 */
	private function application(): ApplicationsTab {
		if ( null === $this->application ) {
			$this->application = new ApplicationsTab( $this->plugin, $this );
		}

		return $this->application;
	}

	/**
	 * The Partners tab: partners that read an API-key feed (admin permission, Full view).
	 *
	 * @return PartnersTab
	 */
	private function partners(): PartnersTab {
		if ( null === $this->partners ) {
			$this->partners = new PartnersTab( $this->plugin, $this );
		}

		return $this->partners;
	}

	/**
	 * Tabs another part of the plugin adds to a channel page (internal; the AI assistant adds `helper` and `local`).
	 *
	 * Each is an object with `slug()`, `label()`, `handle()`, `enqueue()` and `render()`. `render()` prints the whole page
	 * of the tab, heading included, as a tab of the Alerts settings does.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return array<int, object>
	 */
	private function added_tabs( ChannelInterface $channel ): array {
		$found = apply_filters( 'estasoft_nd_internal_channel_tabs', array(), $channel->id() );

		return array_values(
			array_filter(
				is_array( $found ) ? $found : array(),
				static function ( $tab ): bool {
					return is_object( $tab ) && method_exists( $tab, 'slug' ) && method_exists( $tab, 'label' ) && method_exists( $tab, 'render' );
				}
			)
		);
	}

	/**
	 * The added tab the address names, if any.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return object|null
	 */
	private function added_tab( ChannelInterface $channel ): ?object {
		$wanted = Query::key( 'tab' );

		foreach ( '' === $wanted ? array() : $this->added_tabs( $channel ) as $tab ) {
			if ( $wanted === $tab->slug() ) {
				return $tab;
			}
		}

		return null;
	}

	public function handle(): void {
		$channel = $this->channel();

		if ( null === $channel ) {
			$this->handle_hub();

			return;
		}

		if ( ! $this->plugin->access()->can( Access::MANAGE ) ) {
			return;
		}

		$added = $this->added_tab( $channel );

		if ( null !== $added && method_exists( $added, 'handle' ) ) {
			$added->handle();

			return;
		}

		$screen = $this->screen( $channel );
		$screen->handle( $this->tab( $screen ) );
	}

	/**
	 * Forms posted to the hub or one of its tabs. Each checks its own nonce and permission.
	 * The design board calls the verification page a tab; it is a channel page, so the old address goes there.
	 *
	 * @return void
	 */
	private function handle_hub(): void {
		$tab = Query::key( 'tab' );

		if ( 'verification' === $tab && null !== $this->plugin->registry()->get( 'verification' ) && $this->plugin->access()->can( Access::MANAGE ) ) {
			wp_safe_redirect( $this->url( array( 'channel' => 'verification' ) ) );
			exit;
		}

		if ( 'applications' === $this->hub_tab() ) {
			$this->application()->handle();

			return;
		}

		if ( PartnersTab::SLUG === $this->hub_tab() ) {
			$this->partners()->handle();

			return;
		}

		$this->hub()->handle();
	}

	public function enqueue(): void {
		Assets::screen_style( 'channels' );

		$channel = $this->channel();

		if ( null === $channel ) {
			if ( 'applications' === $this->hub_tab() ) {
				$this->application()->enqueue();
			} elseif ( PartnersTab::SLUG === $this->hub_tab() ) {
				$this->partners()->enqueue();
			} else {
				$this->hub()->enqueue();
			}

			return;
		}

		if ( ! $this->plugin->access()->can( Access::MANAGE ) ) {
			return;
		}

		$added = $this->added_tab( $channel );

		if ( null !== $added ) {
			if ( method_exists( $added, 'enqueue' ) ) {
				$added->enqueue();
			}

			return;
		}

		$screen = $this->screen( $channel );
		$screen->enqueue( $this->tab( $screen ) );
	}

	public function render(): string {
		$channel = $this->channel();

		if ( null === $channel ) {
			if ( PartnersTab::SLUG === $this->hub_tab() ) {
				return $this->partners()->render();
			}

			return 'applications' === $this->hub_tab() ? $this->application()->render() : $this->hub()->render();
		}

		$this->plugin->access()->require_or_die( Access::MANAGE );

		$added = $this->added_tab( $channel );

		if ( null !== $added ) {
			return (string) $added->render();
		}

		$screen = $this->screen( $channel );
		$tab    = $this->tab( $screen );

		return $this->frame( $channel, $screen, $tab ) . $screen->render( $tab );
	}

	/**
	 * Breadcrumb, heading with mode tag and health, tabs.
	 *
	 * @param ChannelInterface      $channel The channel.
	 * @param AbstractChannelScreen $screen  Its screen.
	 * @param string                $tab     The open tab.
	 * @return string
	 */
	private function frame( ChannelInterface $channel, AbstractChannelScreen $screen, string $tab ): string {
		$crumbs = Html::tag(
			'nav',
			array(
				'class'      => 'estasoft-nd-breadcrumb estasoft-nd-small',
				'aria-label' => __( 'Breadcrumb', 'estasoft-news-distribution' ),
			),
			Html::tag( 'a', array( 'href' => $this->url() ), esc_html__( 'Channels', 'estasoft-news-distribution' ) )
			. Html::tag( 'span', array( 'aria-hidden' => 'true' ), ' / ' )
			. Html::tag( 'span', array( 'aria-current' => 'page' ), esc_html( $screen->heading() ) )
		);

		$title = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
			Html::tag( 'h1', array( 'class' => 'estasoft-nd-page-title' ), esc_html( $screen->heading() ) )
			. ( $screen->shows_status() ? Tag::mode( $channel->mode() ) . StatusView::pill( $channel->health() ) : '' )
		);

		$text = $title;

		if ( '' !== $screen->description() ) {
			$text .= Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $screen->description() ) );
		}

		$header = Html::tag( 'div', array( 'class' => 'estasoft-nd-page-header__text' ), $text );
		$acts   = $screen->actions( $tab );

		foreach ( $this->added_tabs( $channel ) as $added ) {
			$acts .= Html::tag(
				'a',
				array(
					'class' => 'estasoft-nd-btn',
					'href'  => $screen->url( (string) $added->slug() ),
				),
				esc_html( (string) $added->label() )
			);
		}

		if ( '' !== $acts ) {
			$header .= Html::tag( 'div', array( 'class' => 'estasoft-nd-page-header__actions' ), $acts );
		}

		$html = $crumbs . Html::tag( 'header', array( 'class' => 'estasoft-nd-page-header' ), $header );
		$tabs = $screen->tabs();

		if ( array() !== $tabs ) {
			$links = '';

			foreach ( $tabs as $slug => $label ) {
				$attrs = array(
					'class' => 'estasoft-nd-subtab' . ( (string) $slug === $tab ? ' is-current' : '' ),
					'href'  => $screen->url( (string) $slug ),
				);

				if ( (string) $slug === $tab ) {
					$attrs['aria-current'] = 'page';
				}

				$links .= Html::tag( 'a', $attrs, esc_html( (string) $label ) );
			}

			$html .= Html::tag(
				'nav',
				array(
					'class'      => 'estasoft-nd-subtabs',
					'aria-label' => __( 'Channel sections', 'estasoft-news-distribution' ),
				),
				$links
			);
		}

		return $html;
	}
}

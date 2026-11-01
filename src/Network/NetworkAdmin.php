<?php
/**
 * The News Distribution pages of Network Admin.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Network;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Output;
use Estasoft\NewsDistribution\Admin\Ui\AppBar;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Logo;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Network\Screens\AbstractScreen;
use Estasoft\NewsDistribution\Network\Screens\AiPolicyScreen;
use Estasoft\NewsDistribution\Network\Screens\DeliveryScreen;
use Estasoft\NewsDistribution\Network\Screens\SitesScreen;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * One menu entry in Network Admin, `network/admin.php?page=estasoft-nd-network`, with three
 * tabs: `sites` (the default), `delivery` and `ai-policy`. Everything needs the `network`
 * permission, which is `manage_network_options`. This class is only created on a multisite
 * network (see `Modules\NetworkModule`).
 *
 * The screens print in the same frame as the site screens: the app bar, then the body.
 */
final class NetworkAdmin {

	public const PAGE  = 'estasoft-nd-network';
	public const FLASH = 'estasoft_nd_net_flash_';

	private Plugin $plugin;

	/**
	 * The screens by tab.
	 *
	 * @var array<string, AbstractScreen>|null
	 */
	private ?array $screens = null;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks the pages into Network Admin.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'network_admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'init' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'submenu_file', array( $this, 'submenu_file' ) );

		foreach ( $this->screens() as $screen ) {
			foreach ( $screen->actions() as $action => $method ) {
				add_action(
					'network_admin_edit_' . $action,
					static function () use ( $screen, $method ): void {
						$screen->$method();
					}
				);
			}
		}
	}

	/**
	 * The address of a tab.
	 *
	 * @param string                $tab  sites, delivery or ai-policy.
	 * @param array<string, scalar> $args Extra query arguments.
	 * @return string
	 */
	public static function url( string $tab = 'sites', array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE,
					'tab'  => $tab,
				),
				$args
			),
			network_admin_url( 'admin.php' )
		);
	}

	/**
	 * The screens, in tab order.
	 *
	 * @return array<string, AbstractScreen>
	 */
	public function screens(): array {
		if ( null === $this->screens ) {
			$this->screens = array();

			foreach ( array( new SitesScreen( $this->plugin ), new DeliveryScreen( $this->plugin ), new AiPolicyScreen( $this->plugin ) ) as $screen ) {
				$this->screens[ $screen->tab() ] = $screen;
			}
		}

		return $this->screens;
	}

	/**
	 * The tab being opened. An unknown tab is the first one.
	 *
	 * @return string
	 */
	public function current_tab(): string {
		$tab = Query::key( 'tab', 'sites' );

		return isset( $this->screens()[ $tab ] ) ? $tab : 'sites';
	}

	/**
	 * Whether the page being opened is this one.
	 *
	 * @return bool
	 */
	public function is_this_page(): bool {
		return Query::is( 'page', self::PAGE );
	}

	/**
	 * Adds the menu entry and its three sub-entries.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$capability = $this->plugin->access()->capability_for( Access::NETWORK );

		add_menu_page(
			__( 'News Distribution', 'estasoft-news-distribution' ),
			__( 'News Distribution', 'estasoft-news-distribution' ),
			$capability,
			self::PAGE,
			array( $this, 'render_page' ),
			Logo::menu_icon(),
			'26.5'
		);

		foreach ( $this->screens() as $tab => $screen ) {
			add_submenu_page(
				self::PAGE,
				$screen->title(),
				$screen->title(),
				$capability,
				'sites' === $tab ? self::PAGE : self::PAGE . '&tab=' . $tab,
				array( $this, 'render_page' )
			);
		}
	}

	/**
	 * Highlights the sub-entry of the open tab.
	 *
	 * @param string|null $file The entry WordPress would highlight.
	 * @return string|null
	 */
	public function submenu_file( $file ) {
		if ( ! $this->is_this_page() ) {
			return $file;
		}

		$tab = $this->current_tab();

		return 'sites' === $tab ? self::PAGE : self::PAGE . '&tab=' . $tab;
	}

	/**
	 * Runs before output on this page: puts the defaults of the AI policy in place the first time.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! $this->is_this_page() || ! $this->plugin->access()->can( Access::NETWORK ) ) {
			return;
		}

		// Until a Super Admin has saved the policy, sites follow the defaults. Writing them down means
		// every reader of the option, the AI settings of a site included, sees the same rules.
		if ( ! AiPolicy::is_saved() ) {
			Store::set( 'ai', AiPolicy::defaults() );
		}
	}

	/**
	 * Loads the app style and script, and the open tab's own, only on this page.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! $this->is_this_page() ) {
			return;
		}

		( new Assets() )->enqueue();
		Assets::screen_style( 'network' );

		$this->screens()[ $this->current_tab() ]->enqueue();
	}

	/**
	 * The menu callback: checks the permission, then prints the frame and the tab.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$this->plugin->access()->require_or_die( Access::NETWORK );

		$tab    = $this->current_tab();
		$screen = $this->screens()[ $tab ];
		$tabs   = array();

		foreach ( $this->screens() as $slug => $each ) {
			$tabs[] = array(
				'slug'  => $slug,
				'label' => $each->title(),
				'url'   => self::url( $slug ),
			);
		}

		Output::show(
			Html::tag(
				'div',
				array(
					'class'     => 'wrap estasoft-nd',
					'id'        => 'estasoft-nd-app',
					'data-page' => self::PAGE,
					'data-view' => 'full',
				),
				Html::void( 'hr', array( 'class' => 'wp-header-end' ) )
				. Html::tag(
					'main',
					array( 'class' => 'estasoft-nd-main' ),
					AppBar::render( $tabs, array(), $tab ) . Html::tag( 'div', array( 'class' => 'estasoft-nd-page' ), $screen->render() )
				)
			)
		);
	}
}

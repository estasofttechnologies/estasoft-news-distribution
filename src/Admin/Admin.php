<?php
/**
 * The admin side: menu, screens, assets and form posts.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin;

use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Logo;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Settings\SecretInput;
use Estasoft\NewsDistribution\Modules\SetupModule;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Discovery;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the screens in `src/Admin/Pages/`, puts them in the menu, wraps them in
 * the app frame and loads assets only on them.
 */
final class Admin {

	public const VIEW_META = 'estasoft_nd_view';
	public const FORM_KEY  = 'estasoft_nd_form_';

	/**
	 * The nonce actions of the two form posts below. They name the form, not what it holds: the section or channel
	 * is read after the nonce has passed.
	 */
	public const NONCE_SECTION = 'estasoft_nd_save_section';
	public const NONCE_CHANNEL = 'estasoft_nd_save_channel';

	private Plugin $plugin;
	private Shell $shell;

	/**
	 * Screens by slug.
	 *
	 * @var array<string, Page>|null
	 */
	private ?array $pages = null;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->shell  = new Shell( $this );
	}

	/**
	 * Hooks the admin into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'init' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_estasoft_nd_save_section', array( $this, 'save_section' ) );
		add_action( 'admin_post_estasoft_nd_save_channel', array( $this, 'save_channel' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ESTASOFT_ND_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Every screen that exists on this site, in tab order.
	 *
	 * @return array<string, Page>
	 */
	public function pages(): array {
		if ( null === $this->pages ) {
			$pages = array();

			foreach ( Discovery::classes( 'Admin/Pages' ) as $class ) {
				if ( ! class_exists( $class ) || ! is_subclass_of( $class, Page::class ) ) {
					continue;
				}

				$page = new $class( $this->plugin );

				if ( $page->available() ) {
					$pages[ $page->slug() ] = $page;
				}
			}

			uasort(
				$pages,
				static function ( Page $a, Page $b ): int {
					return $a->order() <=> $b->order();
				}
			);

			$this->pages = $pages;
		}

		return $this->pages;
	}

	/**
	 * One screen.
	 *
	 * @param string $slug The `page` argument.
	 * @return Page|null
	 */
	public function page( string $slug ): ?Page {
		$pages = $this->pages();

		return $pages[ $slug ] ?? null;
	}

	/**
	 * The slug of the screen being opened, or an empty string on any other admin page.
	 *
	 * @return string
	 */
	public function current_slug(): string {
		$slug = Query::key( 'page' );

		return $this->page( $slug ) instanceof Page ? $slug : '';
	}

	/**
	 * Simple or full, as the person chose in Settings, General.
	 *
	 * @return string
	 */
	public function view(): string {
		$view = (string) get_user_meta( get_current_user_id(), self::VIEW_META, true );

		return 'full' === $view ? 'full' : 'simple';
	}

	/**
	 * The screens the person may open, split into the tabs of the app bar and the More menu.
	 *
	 * @return array{0: array<int, array{slug: string, label: string, url: string}>, 1: array<int, array{slug: string, label: string, url: string}>}
	 */
	public function tabs(): array {
		$tabs = array();
		$more = array();
		$full = 'full' === $this->view();

		foreach ( $this->pages() as $page ) {
			if ( $page->is_hidden() || ! $this->plugin->access()->can( $page->permission() ) ) {
				continue;
			}

			$entry = array(
				'slug'  => $page->slug(),
				'label' => $page->title(),
				'url'   => $page->url(),
			);

			if ( $full || $page->in_simple_view() ) {
				$tabs[] = $entry;
			} else {
				$more[] = $entry;
			}
		}

		return array( $tabs, $more );
	}

	/**
	 * Adds the menu and its entries.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$pages = array_filter(
			$this->pages(),
			static function ( Page $page ): bool {
				return ! $page->is_hidden();
			}
		);

		if ( array() === $pages ) {
			return;
		}

		$access = $this->plugin->access();
		$first  = reset( $pages );

		add_menu_page(
			__( 'News Distribution', 'estasoft-news-distribution' ),
			__( 'News Distribution', 'estasoft-news-distribution' ),
			$access->capability_for( $first->permission() ),
			$first->slug(),
			array( $this, 'render_page' ),
			Logo::menu_icon(),
			'26.5'
		);

		foreach ( $pages as $page ) {
			add_submenu_page(
				$first->slug(),
				$page->title(),
				$page->title(),
				$access->capability_for( $page->permission() ),
				$page->slug(),
				array( $this, 'render_page' )
			);
		}

		// Screens that are not in the menu are still reachable by address.
		foreach ( $this->pages() as $page ) {
			if ( $page->is_hidden() ) {
				add_submenu_page(
					'options.php',
					$page->title(),
					$page->title(),
					$access->capability_for( $page->permission() ),
					$page->slug(),
					array( $this, 'render_page' )
				);
			}
		}
	}

	/**
	 * Runs on admin_init: lets the open screen handle a request before any output.
	 *
	 * @return void
	 */
	public function init(): void {
		$slug = $this->current_slug();

		if ( '' === $slug ) {
			return;
		}

		$page = $this->page( $slug );

		if ( $page instanceof Page && $this->plugin->access()->can( $page->permission() ) ) {
			$page->handle();
		}
	}

	/**
	 * Loads the style and script on this plugin's own screens.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$slug = $this->current_slug();

		if ( '' === $slug ) {
			return;
		}

		( new Assets() )->enqueue();

		$page = $this->page( $slug );

		if ( $page instanceof Page ) {
			$page->enqueue();
		}
	}

	/**
	 * The menu callback: checks the permission, then prints the frame and the screen.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$page = $this->page( $this->current_slug() );

		if ( ! $page instanceof Page ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to access this page.', 'estasoft-news-distribution' ),
				'',
				array( 'response' => 403 )
			);
		}

		$this->plugin->access()->require_or_die( $page->permission() );

		Output::show( $this->shell->frame( $page, $page->render() ) );
	}

	/**
	 * The form post for a settings section, for browsers without the script.
	 *
	 * @return void
	 */
	public function save_section(): void {
		check_admin_referer( self::NONCE_SECTION );

		$name     = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';
		$settings = $this->plugin->settings();
		$section  = $settings->section_object( $name );

		if ( null === $section || ! $section->available() ) {
			wp_die( esc_html__( 'That settings section does not exist.', 'estasoft-news-distribution' ), '', array( 'response' => 404 ) );
		}

		$this->plugin->access()->require_or_die( $section->permission() );

		$input = Field::posted( $section->fields(), self::NONCE_SECTION );

		$result = $settings->save_section( $name, $input );
		$target = array( 'section' => $name );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();

			set_transient(
				self::FORM_KEY . get_current_user_id(),
				array(
					'section' => $name,
					// A rejected form is shown again, but a typed secret is never kept in the stash.
					'values'  => array_diff_key( $input, SecretInput::fields( $section->fields() ) ),
					'errors'  => is_array( $data ) && isset( $data['fields'] ) ? (array) $data['fields'] : array( $name => $result->get_error_message() ),
				),
				2 * MINUTE_IN_SECONDS
			);
			$target['error'] = 1;
		} else {
			$target['saved'] = 1;
		}

		$page = $this->page( 'estasoft-nd-settings' );
		$url  = $page instanceof Page ? $page->url( $target ) : admin_url();

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * The form post for a channel's settings, for browsers without the script.
	 *
	 * @return void
	 */
	public function save_channel(): void {
		check_admin_referer( self::NONCE_CHANNEL );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		$id = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';

		$channel = $this->plugin->registry()->get( $id );

		if ( null === $channel ) {
			wp_die( esc_html__( 'That channel does not exist.', 'estasoft-news-distribution' ), '', array( 'response' => 404 ) );
		}

		$input = Field::posted( $channel->fields(), self::NONCE_CHANNEL );

		$current = $this->plugin->channel_settings()->get( $id, $channel->fields() );
		$result  = ( SecretInput::touches( $channel->fields(), $input ) || array() !== SecretInput::moved( $channel->fields(), $input, $current ) ) && ! $this->plugin->access()->can( Access::ADMIN )
			? new \WP_Error( 'estasoft_nd_secrets_need_admin', __( 'Only an administrator can change a saved key, token or address. Ask one to enter it.', 'estasoft-news-distribution' ), array( 'status' => 403 ) )
			: $this->plugin->channel_settings()->save( $id, $channel->fields(), $input );
		$target  = array( 'channel' => $id );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();

			// A rejected form is shown again, but a typed secret is never kept in the stash.
			$kept = array_diff_key( $input, SecretInput::fields( $channel->fields() ) );

			set_transient(
				self::FORM_KEY . get_current_user_id(),
				array(
					'section' => 'channel-' . $id,
					'values'  => $kept,
					'errors'  => is_array( $data ) && isset( $data['fields'] ) ? (array) $data['fields'] : array( $id => $result->get_error_message() ),
				),
				2 * MINUTE_IN_SECONDS
			);
			$target['error'] = 1;
		} else {
			$target['saved'] = 1;
		}

		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';

		if ( '' !== $tab ) {
			$target['tab'] = $tab;
		}

		$page = $this->page( 'estasoft-nd-channels' );
		$url  = $page instanceof Page ? $page->url( $target ) : admin_url();

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param array<int|string, string> $links Existing links.
	 * @return array<int|string, string>
	 */
	public function action_links( array $links ): array {
		$page = $this->page( 'estasoft-nd-settings' );

		if ( $page instanceof Page && $this->plugin->access()->can( $page->permission() ) ) {
			$mine = array( '<a href="' . esc_url( $page->url() ) . '">' . esc_html__( 'Settings', 'estasoft-news-distribution' ) . '</a>' );

			// The way in for a new site is a link in the plugin's own row; nothing is printed above the Plugins list.
			if ( '' === SetupModule::state()['status'] && $this->plugin->access()->can( Access::MANAGE ) ) {
				$mine[] = '<a href="' . esc_url( SetupModule::url( 'start' ) ) . '">' . esc_html__( 'Start setup', 'estasoft-news-distribution' ) . '</a>';
			}

			$links = array_merge( $mine, $links );
		}

		return $links;
	}
}

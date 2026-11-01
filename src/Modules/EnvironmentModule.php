<?php
/**
 * Staging mode banner, "Check again" and the support information download.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Setup\DemoPanel;
use Estasoft\NewsDistribution\Admin\Ui\GlobalBanner;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Demo\Demo;
use Estasoft\NewsDistribution\Core\Environment;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * - While staging mode or demo mode holds pushes back, a banner shows on every
 *   News Distribution screen (the Shell asks through the filter
 *   `estasoft_nd_internal_global_banner`).
 * - "Check again" on the Environment screen forgets the cached probes and runs
 *   them, including the two that read the site's own address. It is a button, so
 *   it never runs on its own.
 * - "Download as a file" sends the support information as a plain text file.
 * - "Exit demo" (`estasoft_nd_demo_exit`) removes the demo's sample articles, turns demo mode off and opens the
 *   first-run screen (`Core\Demo\Demo::leave()`). The banner and Settings, Environment link to a confirmation
 *   that says how many samples will go; the confirmation's button is a link with a nonce that comes here.
 *
 * Every action needs the Manage permission and a nonce.
 */
final class EnvironmentModule implements ModuleInterface {

	public const ACTION_CHECK     = 'estasoft_nd_environment_check';
	public const ACTION_DOWNLOAD  = 'estasoft_nd_environment_download';
	public const ACTION_EXIT_DEMO = 'estasoft_nd_demo_exit';

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_filter( 'estasoft_nd_internal_global_banner', array( $this, 'banner' ), 10, 1 );
		add_action( 'admin_post_' . self::ACTION_CHECK, array( $this, 'check_again' ) );
		add_action( 'admin_post_' . self::ACTION_DOWNLOAD, array( $this, 'download' ) );
		add_action( 'admin_post_' . self::ACTION_EXIT_DEMO, array( $this, 'exit_demo' ) );
	}

	/**
	 * The address of the Environment screen.
	 *
	 * @param array<string, string> $extra More query arguments.
	 * @return string
	 */
	public static function screen_url( array $extra = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page'    => 'estasoft-nd-settings',
					'section' => 'environment',
				),
				$extra
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The banner for a site that pushes nothing.
	 *
	 * @param mixed $banner The banner so far.
	 * @return mixed
	 */
	public function banner( $banner ) {
		if ( ! is_string( $banner ) || '' !== $banner ) {
			return $banner;
		}

		$state = ( new Environment( $this->plugin ) )->staging();

		if ( ! $state['paused'] ) {
			return $banner;
		}

		$demo    = $state['demo'];
		$samples = $demo && ( new Demo( $this->plugin ) )->has_samples();
		$link    = '';

		if ( $this->plugin->access()->can( Access::MANAGE ) ) {
			$link = $samples
				? '<a href="' . esc_url( DemoPanel::confirm_url() ) . '">' . esc_html__( 'Exit demo', 'estasoft-news-distribution' ) . '</a>'
				: '<a href="' . esc_url( self::screen_url() ) . '">' . esc_html__( 'Change it in Environment', 'estasoft-news-distribution' ) . '</a>';
		}

		return GlobalBanner::render(
			$demo ? __( 'Demo mode.', 'estasoft-news-distribution' ) : __( 'Staging mode.', 'estasoft-news-distribution' ),
			$demo ? DemoPanel::effect( $this->plugin ) : __( 'Nothing is pushed to platforms from this site.', 'estasoft-news-distribution' ),
			$link
		);
	}

	/**
	 * Ends the demo: removes the samples, puts back what the demo changed and opens the first-run screen.
	 *
	 * @return void
	 */
	public function exit_demo(): void {
		check_admin_referer( self::ACTION_EXIT_DEMO );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		$removed = ( new Demo( $this->plugin ) )->leave();

		wp_safe_redirect( add_query_arg( 'demo_left', (string) (int) $removed['articles'], SetupModule::url() ) );
		exit;
	}

	/**
	 * Runs the probes again and returns to the screen.
	 *
	 * @return void
	 */
	public function check_again(): void {
		check_admin_referer( self::ACTION_CHECK );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		$environment = new Environment( $this->plugin );
		$environment->forget_probes();
		$environment->probes( true, true );

		wp_safe_redirect( self::screen_url( array( 'checked' => '1' ) ) . '#estasoft-nd-card-environment-site' );
		exit;
	}

	/**
	 * Sends the support information as a text file.
	 *
	 * @return void
	 */
	public function download(): void {
		check_admin_referer( self::ACTION_DOWNLOAD );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		$text = ( new Environment( $this->plugin ) )->support_text();

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="estasoft-news-distribution-support.txt"' );
		header( 'X-Content-Type-Options: nosniff' );

		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A plain text file; nothing in it is run as markup.
		exit;
	}
}

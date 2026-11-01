<?php
/**
 * Styles, scripts and buttons for the site setup screens.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Markup\PageScan;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Languages, Clock and time zone, Environment, Compatibility, Page markup and
 * Developers each have a small stylesheet, and some a small script, under
 * assets/css/screens and assets/js/screens. The Settings page prints one section
 * at a time, so this loads only the files of the section being shown.
 *
 * It also holds the one button of the Page markup screen that has no home of its
 * own: "Check the pages", which reads a few of the site's own article pages.
 */
final class SiteScreensModule implements ModuleInterface {

	public const ACTION_MARKUP_SCAN = 'estasoft_nd_markup_scan';

	/**
	 * The sections that have screen files, with what a script on that screen needs.
	 */
	private const SECTIONS = array( 'languages', 'clock', 'environment', 'compatibility', 'markup', 'developers' );

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_action( 'admin_post_' . self::ACTION_MARKUP_SCAN, array( $this, 'scan_pages' ) );
	}

	/**
	 * Loads the files of the section on screen.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$page    = Query::key( 'page' );
		$section = Query::key( 'section' );

		if ( 'estasoft-nd-settings' !== $page || ! in_array( $section, self::SECTIONS, true ) ) {
			return;
		}

		Assets::screen_style( 'site-common' );

		if ( is_readable( ESTASOFT_ND_PATH . 'assets/css/screens/' . $section . '.css' ) ) {
			Assets::screen_style( $section );
		}

		if ( is_readable( ESTASOFT_ND_PATH . 'assets/js/screens/' . $section . '.js' ) ) {
			Assets::screen_script( $section, $this->script_data( $section ) );
		}
	}

	/**
	 * What a section's script reads. Small, and free of secrets.
	 *
	 * @param string $section The section.
	 * @return array<string, mixed>
	 */
	private function script_data( string $section ): array {
		if ( 'clock' === $section ) {
			return array(
				'route' => 'clock-check',
				'text'  => array(
					'reading' => __( 'Reading your browser’s clock…', 'estasoft-news-distribution' ),
					'failed'  => __( 'The browser’s clock could not be compared. The other clocks are shown.', 'estasoft-news-distribution' ),
				),
			);
		}

		if ( 'environment' === $section ) {
			return array(
				'text' => array(
					'copied' => __( 'Copied to the clipboard', 'estasoft-news-distribution' ),
					'failed' => __( 'Could not copy. Select the text and copy it yourself.', 'estasoft-news-distribution' ),
				),
			);
		}

		return array();
	}

	/**
	 * Reads a few article pages and keeps what the tags look like.
	 *
	 * @return void
	 */
	public function scan_pages(): void {
		check_admin_referer( self::ACTION_MARKUP_SCAN );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		( new PageScan( $this->plugin ) )->run();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'estasoft-nd-settings',
					'section' => 'markup',
				),
				admin_url( 'admin.php' )
			) . '#estasoft-nd-card-markup-tags'
		);
		exit;
	}
}

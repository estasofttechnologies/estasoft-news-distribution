<?php
/**
 * The scripts, styles and one action of the Dates, Authors, Publisher trust and Platform rules screens.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\RulePacks;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * The settings page loads no files of its own per section, so this module asks for
 * the stylesheet (and, for Authors, the script) of the four content sections when one of
 * them is open. It also handles the roll back button of the Platform rules screen,
 * which is a POST to `admin-post.php` with its own nonce, so no form sits inside another.
 */
final class ContentSettingsModule implements ModuleInterface {

	/**
	 * The nonce action of the roll back button. It names the button, not the platform: the platform is read after it passes.
	 */
	public const NONCE_USE_PACK = 'estasoft_nd_rules_use';

	private const SECTIONS = array( 'dates', 'authors', 'trust', 'rules' );

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		// After the plugin's own styles are queued, so the screen's file can depend on them.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_action( 'admin_post_estasoft_nd_rules_use', array( $this, 'use_pack' ) );
	}

	/**
	 * Queues the files of the open section.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$page    = Query::key( 'page' );
		$section = Query::key( 'section' );

		if ( 'estasoft-nd-settings' !== $page || ! in_array( $section, self::SECTIONS, true ) ) {
			return;
		}

		Assets::screen_style( 'content-settings' );

		if ( 'authors' === $section ) {
			Assets::screen_style( 'authors' );
			Assets::screen_script( 'authors' );
		}
	}

	/**
	 * Chooses an older bundled rule pack for a platform, or the newest again.
	 *
	 * @return void
	 */
	public function use_pack(): void {
		check_admin_referer( self::NONCE_USE_PACK );

		$this->plugin->access()->require_or_die( Access::ADMIN );

		$platform = isset( $_POST['platform'] ) ? sanitize_key( wp_unslash( $_POST['platform'] ) ) : '';
		$version  = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
		$result   = $this->plugin->make( RulePacks::class )->use_version( $platform, $version );
		$target   = array(
			'page'    => 'estasoft-nd-settings',
			'section' => 'rules',
		);

		if ( is_wp_error( $result ) ) {
			$target['rule_pack_error'] = 1;
		} else {
			$target['rule_pack'] = $platform;
		}

		wp_safe_redirect( add_query_arg( $target, admin_url( 'admin.php' ) ) );
		exit;
	}
}

<?php
/**
 * The form posts of the Overview, and keeping its dashboard copy fresh.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Admin;
use Estasoft\NewsDistribution\Admin\Overview\OverviewData;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Two small preferences that belong to the person, not the site, so they need only
 * the View permission: whether the "path to each platform" card shows, and whether
 * the app bar shows every screen. Both are forms that post to `admin-post.php`, so
 * they work without a script.
 *
 * A change to a channel, an application or a setting also drops the copy of the
 * overview that the dashboard widget keeps for five minutes.
 */
final class OverviewModule implements ModuleInterface {

	public const ACTION_PATH = 'estasoft_nd_overview_path';
	public const ACTION_VIEW = 'estasoft_nd_set_view';
	public const PATH_META   = 'estasoft_nd_overview_path';

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'admin_post_' . self::ACTION_PATH, array( $this, 'save_path' ) );
		add_action( 'admin_post_' . self::ACTION_VIEW, array( $this, 'save_view' ) );

		foreach ( array( 'estasoft_nd_channels', 'estasoft_nd_applications', 'estasoft_nd_settings' ) as $option ) {
			add_action( 'update_option_' . $option, array( OverviewData::class, 'forget' ) );
		}

		add_action( 'estasoft_nd_post_event', array( OverviewData::class, 'forget' ) );
	}

	/**
	 * What the person chose for the path card: `when_live` (the default), `always` or `hidden`.
	 *
	 * @param int $user_id The person.
	 * @return string
	 */
	public static function path_mode( int $user_id ): string {
		$mode = (string) get_user_meta( $user_id, self::PATH_META, true );

		return in_array( $mode, array( 'always', 'hidden' ), true ) ? $mode : 'when_live';
	}

	/**
	 * Saves the choice about the path card.
	 *
	 * @return void
	 */
	public function save_path(): void {
		check_admin_referer( self::ACTION_PATH );
		$this->plugin->access()->require_or_die( Access::VIEW );

		$user = get_current_user_id();

		if ( ! empty( $_POST['hide_now'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
			$mode = 'hidden';
		} elseif ( isset( $_POST['show'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
			$mode = 'when_live';
		} else {
			$mode = ! empty( $_POST['hide_when_live'] ) ? 'when_live' : 'always'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
		}

		update_user_meta( $user, self::PATH_META, $mode );

		wp_safe_redirect( Links::admin( 'estasoft-nd' ) );
		exit;
	}

	/**
	 * Shows every screen in the app bar.
	 *
	 * @return void
	 */
	public function save_view(): void {
		check_admin_referer( self::ACTION_VIEW );
		$this->plugin->access()->require_or_die( Access::VIEW );

		update_user_meta( get_current_user_id(), Admin::VIEW_META, 'full' );

		wp_safe_redirect( Links::admin( 'estasoft-nd' ) );
		exit;
	}
}

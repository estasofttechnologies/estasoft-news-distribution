<?php
/**
 * Saves the Listings form.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Channels\ListingsScreen;
use Estasoft\NewsDistribution\Channels\Listings\ListingsChannel;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The Listings page posts to `admin-post.php`. The handler is registered here
 * because it must exist on that request, where the Channels page does not run.
 */
final class ListingsModule implements ModuleInterface {

	public const ACTION = 'estasoft_nd_save_listings';

	private Plugin $plugin;

	/**
	 * Adds the hook.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
	}

	/**
	 * Saves the form, then returns to the page.
	 *
	 * @return void
	 */
	public function save(): void {
		$channel = $this->plugin->registry()->get( 'listings' );
		$saved   = $channel instanceof ListingsChannel && ( new ListingsScreen( $this->plugin, $channel ) )->save();

		wp_safe_redirect( add_query_arg( $saved ? 'saved' : 'error', '1', admin_url( 'admin.php?page=estasoft-nd-channels&channel=listings' ) ) );
		exit;
	}
}

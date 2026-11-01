<?php
/**
 * Cache purge after a real change.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Core\CachePurge;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to the publishing event and, at shutdown, fires the public action
 * `estasoft_nd_purge_urls` and asks the caching plugins that are installed to drop
 * the addresses that changed. It also runs the queued job that purges the image
 * copies a bulk change made. See `Core\CachePurge`.
 */
final class CachePurgeModule implements ModuleInterface {

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$service = $plugin->make( CachePurge::class );

		add_action( 'estasoft_nd_post_event', array( $service, 'on_event' ), 20 );
		add_action( 'shutdown', array( $service, 'flush' ), 20 );
		add_action( CachePurge::IMAGES_HOOK, array( $service, 'run_images' ) );
	}
}

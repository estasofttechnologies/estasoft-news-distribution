<?php
/**
 * Answers `/partner-feed/{slug}/`, the feed that needs a partner's API key.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Frontend\PartnerFeedController;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The address and its query variable are registered by `Frontend\Rewrite` with the other
 * public addresses (`^partner-feed/([a-z0-9-]+)/?$`), so the rule is learned and flushed
 * with them, on activation and after an update. When a request matches, the router fires
 * `estasoft_nd_internal_serve_partner_feed` and this module answers it. Nothing else here
 * runs on a request that is not a partner feed.
 */
final class PartnerFeedModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * Adds the hook.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'estasoft_nd_internal_serve_partner_feed', array( $this, 'serve' ) );
	}

	/**
	 * Answers the request and stops.
	 *
	 * @param mixed $slug Partner slug from the address.
	 * @return void
	 */
	public function serve( $slug = '' ): void {
		( new PartnerFeedController( $this->plugin ) )->serve( sanitize_key( (string) $slug ) );
	}
}

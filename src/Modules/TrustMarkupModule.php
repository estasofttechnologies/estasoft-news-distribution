<?php
/**
 * Prints the publisher's policy pages in the home page's structured data.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Core\Trust;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * For each policy page chosen in Settings, Publisher trust, the home page carries a
 * `NewsMediaOrganization` with the schema.org property for it (`ownershipFundingInfo`,
 * `correctionsPolicy`, `ethicsPolicy`). With no page chosen, nothing is printed. The
 * addresses are this site's own; nothing is read from, or sent to, another server.
 */
final class TrustMarkupModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'wp_head', array( $this, 'print_markup' ), 30 );
	}

	/**
	 * Prints the script element on the home page.
	 *
	 * @return void
	 */
	public function print_markup(): void {
		if ( ! is_front_page() && ! is_home() ) {
			return;
		}

		$data = $this->plugin->make( Trust::class )->markup();

		if ( array() === $data ) {
			return;
		}

		$json = wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $json ) || '' === $json ) {
			return;
		}

		// The text is JSON with every angle bracket, ampersand and quote written as an escape, so it cannot close the script element.
		echo '<script type="application/ld+json" class="estasoft-nd-jsonld estasoft-nd-trust">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

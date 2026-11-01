<?php
/**
 * Lists the search sitemaps in robots.txt when the person asks for it.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Channels\SearchSitemap\AbstractSearchSitemapChannel;
use Estasoft\NewsDistribution\Core\Environment;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress writes a virtual robots.txt. When "Also list the sitemaps in
 * robots.txt" is ticked on the Bing or the Yandex page, this adds a `Sitemap:`
 * line for each sitemap to it. A real robots.txt file in the site root is not
 * touched, because WordPress does not read it; News Distribution never writes
 * to the site root.
 *
 * While demo mode is on the plugin's own sitemaps answer 503, so none of them is listed. A sitemap the person
 * already had and chose (not one the plugin serves) is still listed.
 */
final class RobotsModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * Adds the hook.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_filter( 'robots_txt', array( $this, 'append' ), 20, 2 );
	}

	/**
	 * Adds the Sitemap lines.
	 *
	 * @param mixed $output The robots.txt text so far.
	 * @param mixed $is_public Whether the site asks to be indexed ("1") or not ("0").
	 * @return mixed
	 */
	public function append( $output, $is_public ) {
		if ( ! is_string( $output ) || ! (bool) (int) $is_public ) {
			return $output;
		}

		$lines = $this->lines( $output );

		if ( array() === $lines ) {
			return $output;
		}

		return rtrim( $output ) . "\n\n" . implode( "\n", $lines ) . "\n";
	}

	/**
	 * The `Sitemap:` lines to add, without any that robots.txt already has.
	 *
	 * @param string $existing The robots.txt text so far.
	 * @return string[]
	 */
	public function lines( string $existing = '' ): array {
		$lines = array();
		$demo  = (bool) ( new Environment( $this->plugin ) )->staging()['demo'];

		foreach ( AbstractSearchSitemapChannel::ANSWERING as $id ) {
			$channel = $this->plugin->registry()->get( $id );

			if ( ! $channel instanceof AbstractSearchSitemapChannel || ! $channel->is_enabled() ) {
				continue;
			}

			if ( $demo && ! $channel->uses_existing_sitemap() ) {
				continue;
			}

			foreach ( $channel->robots_urls() as $url ) {
				$line = 'Sitemap: ' . esc_url_raw( $url );

				if ( false === strpos( $existing, $line ) && ! in_array( $line, $lines, true ) ) {
					$lines[] = $line;
				}
			}
		}

		return $lines;
	}
}

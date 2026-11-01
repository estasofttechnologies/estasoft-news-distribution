<?php
/**
 * Advertises the open feed in the page head so readers can find it.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Channels\FeedReaders\FeedReadersChannel;
use Estasoft\NewsDistribution\Core\Environment;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Feedly, Inoreader, NewsBlur and any other reader look for
 * `<link rel="alternate" type="application/rss+xml">` in the head of a page.
 * This prints one for each language the site carries, the language of the
 * page first. It can be turned off on the feed readers page. While demo mode is on the feed answers 503, so no
 * link to it is printed.
 */
final class FeedDiscoveryModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * Adds the hook.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'wp_head', array( $this, 'print_links' ), 3 );
	}

	/**
	 * Prints the link tags.
	 *
	 * @return void
	 */
	public function print_links(): void {
		if ( is_feed() || is_embed() ) {
			return;
		}

		foreach ( self::links( $this->plugin, self::current_language() ) as $link ) {
			printf(
				'<link rel="alternate" type="application/rss+xml" title="%1$s" href="%2$s" />' . "\n",
				esc_attr( $link['title'] ),
				esc_url( $link['href'] )
			);
		}
	}

	/**
	 * The feed links the page head carries, or none when the feed is not advertised.
	 *
	 * @param Plugin $plugin  The running plugin.
	 * @param string $current Language of the page, listed first, or an empty string.
	 * @return array<int, array{language: string, title: string, href: string}>
	 */
	public static function links( Plugin $plugin, string $current = '' ): array {
		$channel = $plugin->registry()->get( 'feed-readers' );

		if ( ! $channel instanceof FeedReadersChannel || ! $channel->is_enabled() || ! $channel->advertised() ) {
			return array();
		}

		if ( (bool) ( new Environment( $plugin ) )->staging()['demo'] ) {
			return array();
		}

		$languages = array_values(
			array_filter(
				array_map( 'strval', $plugin->languages()->languages() ),
				static function ( string $language ) use ( $channel ): bool {
					return $channel->accepts_language( $language );
				}
			)
		);

		if ( '' !== $current && in_array( $current, $languages, true ) ) {
			$languages = array_merge( array( $current ), array_values( array_diff( $languages, array( $current ) ) ) );
		}

		$links = array();

		foreach ( $languages as $language ) {
			$name = $plugin->settings()->publication_name( $language );

			$links[] = array(
				'language' => $language,
				'title'    => count( $languages ) > 1 ? sprintf( '%1$s (%2$s)', $name, strtoupper( $language ) ) : $name,
				'href'     => $channel->feed_url( $language ),
			);
		}

		return $links;
	}

	/**
	 * The language of the page being shown, from the translation plugin in use.
	 *
	 * @return string An empty string when it is not known.
	 */
	private static function current_language(): string {
		if ( function_exists( 'pll_current_language' ) ) {
			return (string) pll_current_language( 'slug' );
		}

		return defined( 'ICL_LANGUAGE_CODE' ) ? (string) ICL_LANGUAGE_CODE : '';
	}
}

<?php
/**
 * Prints the article page's structured data and the tags it lacks.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Integrations\KnownPlugins;
use Estasoft\NewsDistribution\Markup\Description;
use Estasoft\NewsDistribution\Markup\NewsArticleJsonLd;
use Estasoft\NewsDistribution\Markup\OtherPlugins;
use Estasoft\NewsDistribution\Markup\PageTags;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * On the page of one article, of a post type chosen in Settings, General:
 *
 * 1. It reads what the head prints (output buffering from the start of `wp_head`
 *    to its end, and gives it back unchanged).
 * 2. After it, it prints the tags the page lacks (`og:image`, `author`, `og:locale`,
 *    `article:published_time`, `article:modified_time`, `canonical`) that are turned on.
 * 3. After those, it prints the NewsArticle structured data, unless the head
 *    already carries article data from another source.
 *
 * It never removes or rewrites what another plugin printed. With "Replace it,
 * where the other plugin allows" it asks a plugin that is known to offer a hook to
 * leave a tag out, before that plugin prints; if the tag is still there, it stays and
 * News Distribution prints no second copy.
 *
 * Nothing is read from another server and nothing is sent. When the head's output
 * cannot be read back (another plugin left a buffer open in a way that cannot be
 * closed), the page is left exactly as printed and nothing is added.
 */
final class MarkupModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * The output buffer level of the buffer opened for the head, or -1 when none is open.
	 *
	 * @var int
	 */
	private int $level = -1;

	private ?Article $article = null;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'wp_head', array( $this, 'start' ), 0 );
		add_action( 'wp_head', array( $this, 'finish' ), PHP_INT_MAX );
		add_filter( 'wp_robots', array( $this, 'robots' ), 20 );
	}

	/**
	 * Starts reading the head.
	 *
	 * @return void
	 */
	public function start(): void {
		$this->level   = -1;
		$this->article = null;

		if ( ! $this->applies() || ( ! $this->anything_on() && ! Description::wanted( (int) get_queried_object_id() ) ) ) {
			return;
		}

		$article = $this->plugin->reader()->find( (int) get_queried_object_id() );

		if ( null === $article ) {
			return;
		}

		if ( 'replace' === $this->mode() ) {
			$tags = array();
			$page = new PageTags( $this->plugin );

			foreach ( array_keys( PageTags::definitions() ) as $id ) {
				if ( $page->enabled( $id ) ) {
					$tags[] = $id;
				}
			}

			OtherPlugins::ask( array_keys( KnownPlugins::found() ), $tags, $this->jsonld_on() );
		}

		$this->article = $article;

		ob_start();
		$this->level = ob_get_level();
	}

	/**
	 * Gives the head back and prints what it lacks.
	 *
	 * @return void
	 */
	public function finish(): void {
		$level   = $this->level;
		$article = $this->article;

		$this->level   = -1;
		$this->article = null;

		if ( $level < 1 || null === $article ) {
			return;
		}

		// A buffer that another plugin opened and left open is flushed into ours.
		while ( ob_get_level() > $level ) {
			ob_end_flush();
		}

		if ( ob_get_level() < $level ) {
			// Our buffer was closed by someone else; there is nothing to read back.
			return;
		}

		$head = (string) ob_get_clean();

		echo $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Printed back unchanged: the head as the theme and the other plugins wrote it.

		$tags   = new PageTags( $this->plugin );
		$parsed = $tags->parse( $head );

		// Each tag is escaped where it is made (PageTags::html_for).
		echo implode( '', $tags->missing( $article, $parsed['present'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// The search description a person chose in the Summary tab, only when the page has none (escaped in Description::html).
		if ( ! Description::present( $head ) ) {
			echo Description::html( $article->id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( $this->jsonld_on() && ! $parsed['article_data'] ) {
			// Encoded by wp_json_encode with JSON_HEX_TAG, so nothing in it can close the script element.
			echo ( new NewsArticleJsonLd( $this->plugin ) )->script( $article ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Adds max-image-preview:large to the robots tag when the page has none.
	 *
	 * @param mixed $robots The directives WordPress is about to print.
	 * @return mixed
	 */
	public function robots( $robots ) {
		if ( ! is_array( $robots ) || isset( $robots['max-image-preview'] ) || ! empty( $robots['noindex'] ) ) {
			return $robots;
		}

		if ( ! $this->applies() || ! ( new PageTags( $this->plugin ) )->enabled( 'preview' ) ) {
			return $robots;
		}

		$robots['max-image-preview'] = 'large';

		return $robots;
	}

	/**
	 * Whether the page being printed is a public article of a post type chosen in Settings.
	 *
	 * @return bool
	 */
	private function applies(): bool {
		if ( is_admin() || is_feed() || is_embed() || is_preview() || ! is_singular() ) {
			return false;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return false;
		}

		return in_array( $post->post_type, $this->plugin->settings()->post_types(), true );
	}

	/**
	 * Whether any tag or the structured data is turned on.
	 *
	 * @return bool
	 */
	private function anything_on(): bool {
		if ( $this->jsonld_on() ) {
			return true;
		}

		$tags = new PageTags( $this->plugin );

		foreach ( array_keys( PageTags::definitions() ) as $id ) {
			if ( 'preview' !== $id && $tags->enabled( $id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the structured data is on.
	 *
	 * @return bool
	 */
	private function jsonld_on(): bool {
		return (bool) $this->plugin->settings()->get( 'markup.jsonld', true );
	}

	/**
	 * What to do about another plugin's tags: leave or replace.
	 *
	 * @return string
	 */
	private function mode(): string {
		return 'replace' === (string) $this->plugin->settings()->get( 'markup.other_plugin', 'leave' ) ? 'replace' : 'leave';
	}
}

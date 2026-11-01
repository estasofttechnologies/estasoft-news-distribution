<?php
/**
 * The Follow block: the ways to follow the publication, as plain links.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Channels\FeedReaders\AbstractFeedChannel;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Language\UiLanguages;
use Estasoft\NewsDistribution\Follow\Accounts;
use Estasoft\NewsDistribution\Follow\Address;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * A static block with a PHP `render_callback`: there is no build step and nothing is stored in the post
 * except the block's few attributes, so a change to the feed address, an account or the publication name
 * shows on every page at once.
 *
 * What it prints, in this order:
 *
 * 1. A heading ("Follow Example Times" unless the owner wrote one).
 * 2. A button to the follow address the owner typed in the block. News Distribution does not know a
 *    platform's follow address, so it asks for one. It makes no promise about where the address leads.
 * 3. A link to the open feed in the language of the page, when the feed is on and the owner did not hide
 *    it. The feed is the one the feed readers channel serves.
 * 4. A link for each of Telegram, Apple News, Mastodon and Bluesky that has an address for the page's
 *    language and that the owner did not hide (`Follow\Accounts` says where each address comes from: the
 *    one typed in Settings, Follow links, or the public account saved in Outlets).
 *
 * It prints plain links and no script, and nothing at all inside a feed item. An external link carries `rel="noopener"`. The styles are
 * `blocks/follow/style.css`, which a theme adjusts through `--estasoft-nd-follow-*` variables. Nothing is
 * loaded from another site, there are no icons or logos, and no color is written into the markup. A block
 * with nothing to show prints nothing on the site, and a note in the editor.
 *
 * The same markup is printed three ways: by the block, by the shortcode `[estasoft_nd_follow]`, and, when
 * Settings, Follow links, "Add to the end of every article" is on, after the text of every article of the
 * site's post types. That last one runs on the single page of an article only (never in a feed, an excerpt
 * or another loop), after the page labels (`the_content` at 13, the labels are at 12), and not when the
 * article already holds the block or the shortcode. Nothing here calls another server and nothing reads a
 * secret.
 */
final class FollowBlockModule implements ModuleInterface {

	public const BLOCK     = 'estasoft-nd/follow';
	public const HANDLE    = 'estasoft-nd-editor-follow';
	public const SHORTCODE = 'estasoft_nd_follow';
	public const STYLE     = 'estasoft-nd-follow-style';

	/**
	 * The block attribute that switches each service's link on or off.
	 */
	private const SWITCHES = array(
		Address::TELEGRAM   => 'showTelegram',
		Address::APPLE_NEWS => 'showAppleNews',
		Address::MASTODON   => 'showMastodon',
		Address::BLUESKY    => 'showBluesky',
	);

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'add_editor_data' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'front_style' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'settings_assets' ), 20 );
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		// After the page labels (12), so the order is: labels, then the follow links.
		add_filter( 'the_content', array( $this, 'append' ), 13 );
	}

	/**
	 * Registers the editor script, then the block from its block.json.
	 *
	 * @return void
	 */
	public function register_block(): void {
		$this->register_script();

		register_block_type(
			ESTASOFT_ND_PATH . 'blocks/follow',
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Registers the editor script.
	 *
	 * @return void
	 */
	public function register_script(): void {
		wp_register_script(
			self::HANDLE,
			ESTASOFT_ND_URL . 'assets/js/editor/follow-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-api-fetch', 'wp-i18n' ),
			Assets::file_version( 'assets/js/editor/follow-block.js' ),
			array( 'in_footer' => true )
		);

		wp_set_script_translations( self::HANDLE, 'estasoft-news-distribution', ESTASOFT_ND_PATH . 'languages' );
	}

	/**
	 * Gives the editor script what it needs to know about the site. It runs only where the block editor
	 * loads, so no page of the site pays for it.
	 *
	 * @return void
	 */
	public function add_editor_data(): void {
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			$this->register_script();
		}

		wp_add_inline_script( self::HANDLE, 'window.estasoftNDFollow = ' . wp_json_encode( $this->editor_data() ) . ';', 'before' );
	}

	/**
	 * What the editor's controls need to know about the site. Nothing secret: names, counts and notes.
	 *
	 * @return array<string, mixed>
	 */
	private function editor_data(): array {
		$languages = array();
		$services  = array();
		$accounts  = new Accounts( $this->plugin );

		foreach ( $this->site_languages() as $code ) {
			$languages[] = array(
				'value' => $code,
				'label' => UiLanguages::native_name( $code ),
			);
		}

		foreach ( Address::services() as $service ) {
			$services[] = array(
				'id'        => $service,
				'attribute' => self::SWITCHES[ $service ],
				'label'     => Address::label( $service ),
				'note'      => $accounts->note( $service ),
				'available' => $accounts->is_available( $service ),
			);
		}

		return array(
			'publication' => $this->plugin->settings()->publication_name( $this->plugin->languages()->default_language() ),
			'languages'   => $languages,
			'services'    => $services,
			'append'      => $this->append_is_on(),
			'canManage'   => $this->plugin->access()->can( Access::MANAGE ),
			'settingsUrl' => admin_url( 'admin.php?page=estasoft-nd-settings&section=follow' ),
		);
	}

	/**
	 * The languages the site publishes in.
	 *
	 * @return string[]
	 */
	private function site_languages(): array {
		return array_values( array_unique( array_map( 'strval', $this->plugin->languages()->languages() ) ) );
	}

	/**
	 * The language of the page being shown.
	 *
	 * Asked of the translation plugin in use, then of the article, then the site's default.
	 *
	 * @return string A language the site publishes in.
	 */
	private function page_language(): string {
		$found = '';

		if ( function_exists( 'pll_current_language' ) ) {
			$found = (string) pll_current_language( 'slug' );
		} elseif ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			$found = (string) ICL_LANGUAGE_CODE;
		}

		if ( '' === $found && is_singular() ) {
			$found = $this->plugin->languages()->language_of_post( (int) get_queried_object_id() );
		}

		return in_array( $found, $this->site_languages(), true ) ? $found : $this->plugin->languages()->default_language();
	}

	/**
	 * Whether the block is being drawn for the editor's preview.
	 *
	 * @return bool
	 */
	private function in_editor(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST && Query::is( 'context', 'edit' );
	}

	/**
	 * The block's markup.
	 *
	 * @param mixed $attributes The block's attributes.
	 * @return string
	 */
	public function render( $attributes ): string {
		return $this->build( is_array( $attributes ) ? $attributes : array(), 'block' );
	}

	/**
	 * The shortcode `[estasoft_nd_follow]`.
	 *
	 * Attributes: `heading`, `layout` (`buttons` or `list`), `language`, `url` and `label` (the follow
	 * button), and `hide`, a comma-separated list of `feed`, `telegram`, `apple_news`, `mastodon` and
	 * `bluesky`.
	 *
	 * @param mixed $atts The shortcode's attributes.
	 * @return string Escaped HTML.
	 */
	public function shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'heading'  => '',
				'layout'   => 'buttons',
				'language' => '',
				'url'      => '',
				'label'    => '',
				'hide'     => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);
		$hide = array_map( 'sanitize_key', explode( ',', (string) $atts['hide'] ) );
		$args = array(
			'heading'     => (string) $atts['heading'],
			'layout'      => (string) $atts['layout'],
			'language'    => (string) $atts['language'],
			'followUrl'   => (string) $atts['url'],
			'followLabel' => (string) $atts['label'],
			'showFeed'    => ! in_array( 'feed', $hide, true ),
		);

		foreach ( self::SWITCHES as $service => $attribute ) {
			$args[ $attribute ] = ! in_array( $service, $hide, true );
		}

		return $this->build( $args, 'shortcode' );
	}

	/**
	 * Whether the block is added after the text of every article.
	 *
	 * @return bool
	 */
	private function append_is_on(): bool {
		return true === (bool) $this->plugin->settings()->get( 'follow.append', false );
	}

	/**
	 * Loads the stylesheet in the head of an article page that gets the block at its end. A block or a
	 * shortcode placed by hand loads it where it prints.
	 *
	 * @return void
	 */
	public function front_style(): void {
		if ( $this->append_is_on() && is_singular( $this->plugin->settings()->post_types() ) ) {
			wp_enqueue_style( self::STYLE );
		}
	}

	/**
	 * Loads the Follow links settings screen's own stylesheet.
	 *
	 * @return void
	 */
	public function settings_assets(): void {
		$page    = Query::key( 'page' );
		$section = Query::key( 'section' );

		if ( 'estasoft-nd-settings' === $page && 'follow' === $section ) {
			Assets::screen_style( 'follow' );
		}
	}

	/**
	 * Adds the block after the text of an article, when the site asked for it.
	 *
	 * Only the single page of an article of the site's post types, in the main loop. Never a feed, an
	 * excerpt, a list of articles, a page of the REST API or a password form. Not twice: an article that holds
	 * the block or the shortcode, or whose text already carries the links, is left alone. Another feature
	 * can leave one article out through the internal filter `estasoft_nd_internal_follow_append`.
	 *
	 * @param mixed $content The text WordPress is about to print.
	 * @return mixed
	 */
	public function append( $content ) {
		if ( ! is_string( $content ) || ! $this->append_is_on() ) {
			return $content;
		}

		if ( ! in_the_loop() || ! is_main_query() || doing_filter( 'get_the_excerpt' ) || doing_filter( 'the_excerpt' ) || is_admin() || is_feed() || is_embed() ) {
			return $content;
		}

		$post = get_post();

		if ( ! $post instanceof \WP_Post || ! is_singular( $this->plugin->settings()->post_types() ) || (int) get_queried_object_id() !== (int) $post->ID || post_password_required( $post ) ) {
			return $content;
		}

		if ( has_block( self::BLOCK, $post ) || has_shortcode( (string) $post->post_content, self::SHORTCODE ) || false !== strpos( $content, 'estasoft-nd-follow__links' ) ) {
			return $content;
		}

		/**
		 * Whether the Follow block is added to the end of this article.
		 *
		 * @param bool $append  Default true: the site switched "Add to the end of every article" on.
		 * @param int  $post_id The article.
		 */
		if ( ! (bool) apply_filters( 'estasoft_nd_internal_follow_append', true, (int) $post->ID ) ) {
			return $content;
		}

		return $content . $this->build(
			array(
				'followUrl'   => (string) $this->plugin->settings()->get( 'follow.follow_url', '' ),
				'followLabel' => (string) $this->plugin->settings()->get( 'follow.follow_label', '' ),
			),
			'end'
		);
	}

	/**
	 * Builds the markup for the block, the shortcode or the end of an article.
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 * @param string               $context    `block`, `shortcode` or `end`.
	 * @return string Escaped HTML, or an empty string when there is nothing to show.
	 */
	private function build( array $attributes, string $context ): string {
		// Buttons have no place inside a feed item: readers of the feed already follow it.
		if ( is_feed() ) {
			return '';
		}

		$language = $this->language( $attributes );
		$name     = $this->plugin->settings()->publication_name( $language );
		$links    = $this->links( $attributes, $language );
		$heading  = trim( wp_strip_all_tags( (string) ( $attributes['heading'] ?? '' ) ) );
		$layout   = 'list' === ( $attributes['layout'] ?? '' ) ? 'list' : 'buttons';

		if ( '' === $heading ) {
			$heading = sprintf(
				/* translators: %s: the name of the publication. */
				__( 'Follow %s', 'estasoft-news-distribution' ),
				$name
			);
		}

		if ( array() === $links ) {
			return 'block' === $context && $this->in_editor() ? $this->empty_note( $heading ) : '';
		}

		if ( 'block' !== $context ) {
			wp_enqueue_style( self::STYLE );
		}

		$id    = wp_unique_id( 'estasoft-nd-follow-' );
		$items = '';

		foreach ( $links as $link ) {
			$items .= '<li class="estasoft-nd-follow__item estasoft-nd-follow__item--' . esc_attr( $link['service'] ) . '"><a class="estasoft-nd-follow__link' . ( $link['primary'] ? ' estasoft-nd-follow__link--primary' : '' ) . '" href="' . esc_url( $link['href'] ) . '"' . ( $this->is_external( $link['href'] ) ? ' rel="noopener"' : '' ) . '>' . esc_html( $link['label'] ) . '</a></li>';
		}

		$classes = 'estasoft-nd-follow estasoft-nd-follow--' . $layout . ( 'end' === $context ? ' estasoft-nd-follow--end' : '' );

		if ( 'block' === $context ) {
			$wrapper = get_block_wrapper_attributes(
				array(
					'class'           => $classes,
					'role'            => 'group',
					'aria-labelledby' => $id,
				)
			);
		} else {
			$wrapper = 'class="' . esc_attr( $classes ) . '" role="group" aria-labelledby="' . esc_attr( $id ) . '"';
		}

		return '<div ' . $wrapper . '><p class="estasoft-nd-follow__heading" id="' . esc_attr( $id ) . '">' . esc_html( $heading ) . '</p><ul class="estasoft-nd-follow__links">' . $items . '</ul></div>';
	}

	/**
	 * The language the block follows: the one the owner chose, or the page's.
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 * @return string
	 */
	private function language( array $attributes ): string {
		$chosen = sanitize_text_field( (string) ( $attributes['language'] ?? '' ) );

		return '' !== $chosen && in_array( $chosen, $this->site_languages(), true ) ? $chosen : $this->page_language();
	}

	/**
	 * Whether an attribute that switches a link is on. A block saved before the switch existed has none, and shows the link.
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 * @param string               $name       The attribute.
	 * @return bool
	 */
	private function is_on( array $attributes, string $name ): bool {
		return ! array_key_exists( $name, $attributes ) || ! empty( $attributes[ $name ] );
	}

	/**
	 * The links the block shows, in the order of the block's controls.
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 * @param string               $language   The language the block follows.
	 * @return array<int, array{href: string, label: string, primary: bool, service: string}>
	 */
	private function links( array $attributes, string $language ): array {
		$links = array();
		$url   = $this->address( (string) ( $attributes['followUrl'] ?? '' ) );

		if ( '' !== $url ) {
			$label = trim( wp_strip_all_tags( (string) ( $attributes['followLabel'] ?? '' ) ) );

			$links[] = array(
				'href'    => $url,
				'label'   => '' === $label ? __( 'Follow us', 'estasoft-news-distribution' ) : $label,
				'primary' => true,
				'service' => 'follow',
			);
		}

		$feed = $this->plugin->registry()->get( 'feed-readers' );

		if ( $this->is_on( $attributes, 'showFeed' ) && $feed instanceof AbstractFeedChannel && $feed->is_enabled() && $feed->accepts_language( $language ) ) {
			$links[] = array(
				'href'    => $feed->feed_url( $language ),
				'label'   => __( 'RSS feed', 'estasoft-news-distribution' ),
				'primary' => false,
				'service' => 'feed',
			);
		}

		$accounts = new Accounts( $this->plugin );

		foreach ( self::SWITCHES as $service => $attribute ) {
			$href = $this->is_on( $attributes, $attribute ) ? $accounts->address( $service, $language ) : '';

			if ( '' !== $href ) {
				$links[] = array(
					'href'    => $href,
					'label'   => Address::label( $service ),
					'primary' => false,
					'service' => str_replace( '_', '-', $service ),
				);
			}
		}

		return $links;
	}

	/**
	 * Whether an address leads away from this site, so its link carries `rel="noopener"`.
	 *
	 * @param string $href An absolute address.
	 * @return bool
	 */
	private function is_external( string $href ): bool {
		$host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		return '' !== $host && $host !== $home;
	}

	/**
	 * The follow address the owner typed, made safe.
	 *
	 * Only an address that starts with http:// or https://, or a path on this site that starts with one slash,
	 * is kept. Anything else (a script, a file, a protocol-relative address) leaves the button out.
	 *
	 * @param string $typed What was typed.
	 * @return string An absolute address, or an empty string.
	 */
	private function address( string $typed ): string {
		$typed = trim( $typed );

		if ( 1 === preg_match( '#^/(?!/)#', $typed ) ) {
			return esc_url_raw( home_url( $typed ), array( 'http', 'https' ) );
		}

		return 1 === preg_match( '#^https?://#i', $typed ) ? esc_url_raw( $typed, array( 'http', 'https' ) ) : '';
	}

	/**
	 * What the editor shows for a block with nothing to print.
	 *
	 * @param string $heading The heading.
	 * @return string
	 */
	private function empty_note( string $heading ): string {
		return '<div class="estasoft-nd-follow estasoft-nd-follow--empty"><p class="estasoft-nd-follow__heading">' . esc_html( $heading ) . '</p><p>' . esc_html__( 'Nothing to show yet. Add a follow address, turn on the RSS feed link, or set up an account in Outlets or in Settings, Follow links. A block with no links prints nothing on your site.', 'estasoft-news-distribution' ) . '</p></div>';
	}
}

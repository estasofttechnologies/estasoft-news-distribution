<?php
/**
 * Page labels and corrections on the article page.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Content\CorrectionCard;
use Estasoft\NewsDistribution\Content\Corrections;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Settings\Sections\Labels;
use Estasoft\NewsDistribution\Markup\LabelMarkup;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the labels (`Markup\LabelMarkup`) on the page of an article of a post type chosen in
 * Settings, General, in three ways that give the same markup:
 *
 * - The automatic placement, a filter on `the_content`, by Settings, Page labels, "Where they go".
 *   It runs after blocks and shortcodes, so a block or a shortcode that was placed by hand is
 *   never printed a second time.
 * - The block `estasoft-nd/labels` ("News Distribution labels").
 * - The shortcode `[estasoft_nd_labels]`, with an optional `part` of `top` or `end`.
 *
 * It also answers the date guard when a correction note is saved (`Content\Corrections`), and
 * puts a saved correction note at the end of the article text that channels read, so feeds that carry
 * the full text include it. Nothing here calls another server.
 */
final class LabelsModule implements ModuleInterface {

	public const SHORTCODE = 'estasoft_nd_labels';
	public const BLOCK     = 'estasoft-nd/labels';
	public const STYLE     = 'estasoft-nd-labels';

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		// After do_blocks (9) and do_shortcode (11), so hand-placed labels are already in the text.
		add_filter( 'the_content', array( $this, 'content' ), 12 );
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_assets', array( $this, 'styles' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'block_script' ) );
		add_filter( 'estasoft_nd_internal_date_guard_always_moves', array( Corrections::class, 'moves_date' ), 10, 4 );
		add_filter( 'estasoft_nd_internal_article_content', array( $this, 'feed_content' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'settings_assets' ), 20 );
		add_action( 'admin_post_' . CorrectionCard::ACTION, array( $this, 'save_correction' ) );
	}

	/**
	 * Whether the page being built is the page of an article that carries labels.
	 *
	 * @return bool
	 */
	private function on_article_page(): bool {
		return ! is_admin()
			&& ! is_feed()
			&& is_singular( $this->plugin->settings()->post_types() )
			&& get_the_ID() === (int) get_queried_object_id();
	}

	/**
	 * Adds the labels around the text of the article.
	 *
	 * @param mixed $content The text WordPress is about to print.
	 * @return mixed
	 */
	public function content( $content ) {
		if ( ! is_string( $content ) || ! in_the_loop() || ! is_main_query() || doing_filter( 'get_the_excerpt' ) || doing_filter( 'the_excerpt' ) ) {
			return $content;
		}

		if ( ! $this->on_article_page() ) {
			return $content;
		}

		$placement = (string) $this->plugin->settings()->get( 'labels.placement', Labels::BYLINE );

		if ( Labels::MANUAL === $placement || false !== strpos( $content, LabelMarkup::MARKER ) ) {
			return $content;
		}

		$parts = ( new LabelMarkup( $this->plugin ) )->parts( (int) get_the_ID() );

		if ( array() === $parts ) {
			return $content;
		}

		if ( Labels::BEFORE === $placement ) {
			return LabelMarkup::all( $parts ) . $content;
		}

		return LabelMarkup::top( $parts ) . $content . LabelMarkup::end( $parts );
	}

	/**
	 * The shortcode.
	 *
	 * @param mixed $atts Attributes: `part` is `top`, `end` or `all` (the default).
	 * @return string Escaped HTML.
	 */
	public function shortcode( $atts = array() ): string {
		$atts = shortcode_atts( array( 'part' => 'all' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );

		return $this->render( (int) get_the_ID(), (string) $atts['part'] );
	}

	/**
	 * The block's output.
	 *
	 * @param mixed     $attributes Block attributes.
	 * @param mixed     $content    Inner content, which is empty.
	 * @param \WP_Block $block      The block, with the post it sits in as context.
	 * @return string Escaped HTML.
	 */
	public function render_block( $attributes = array(), $content = '', $block = null ): string {
		$post_id = $block instanceof \WP_Block && isset( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_the_ID();

		return $this->render( $post_id, 'all' );
	}

	/**
	 * Builds the labels of one article for the block and the shortcode.
	 *
	 * Only the page of a published article of the chosen post types has them. A block placed in the
	 * editor asks while the article is a draft, and gets nothing then: the labels are for readers.
	 *
	 * @param int    $post_id Article ID.
	 * @param string $part    `top`, `end` or `all`.
	 * @return string Escaped HTML.
	 */
	private function render( int $post_id, string $part ): string {
		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return '';
		}

		$parts = ( new LabelMarkup( $this->plugin ) )->parts( $post_id );

		if ( 'top' === $part ) {
			return LabelMarkup::top( $parts );
		}

		if ( 'end' === $part ) {
			return LabelMarkup::end( $parts );
		}

		return LabelMarkup::all( $parts );
	}

	/**
	 * Registers the block. It has no editor controls: it is drawn by the server, like the page.
	 *
	 * @return void
	 */
	public function register_block(): void {
		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 3,
				'title'           => __( 'News Distribution labels', 'estasoft-news-distribution' ),
				'description'     => __( 'Small lines that tell readers what changed or where text came from: Updated, AI-assisted, correction and source.', 'estasoft-news-distribution' ),
				'category'        => 'widgets',
				'icon'            => 'tag',
				'uses_context'    => array( 'postId' ),
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * Loads the stylesheet on the page of an article, and in the block editor for the block.
	 *
	 * @return void
	 */
	public function styles(): void {
		if ( is_admin() ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

			if ( null === $screen || ! method_exists( $screen, 'is_block_editor' ) || ! $screen->is_block_editor() ) {
				return;
			}
		} elseif ( ! is_singular( $this->plugin->settings()->post_types() ) || ! $this->page_has_labels() ) {
			// The stylesheet is a request on every article page, so it loads only where a label will print.
			return;
		}

		wp_enqueue_style( self::STYLE, ESTASOFT_ND_URL . 'assets/css/labels.css', array(), Assets::file_version( 'assets/css/labels.css' ) );
	}

	/**
	 * Whether the article being shown has a label to print, or holds the block or the shortcode.
	 *
	 * @return bool
	 */
	private function page_has_labels(): bool {
		$post_id = (int) get_queried_object_id();
		$post    = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || '' !== (string) $post->post_password ) {
			return false;
		}

		if ( has_shortcode( (string) $post->post_content, self::SHORTCODE ) || has_block( self::BLOCK, $post ) ) {
			return true;
		}

		return array() !== ( new LabelMarkup( $this->plugin ) )->parts( $post_id );
	}

	/**
	 * Loads the script that shows the block in the editor.
	 *
	 * @return void
	 */
	public function block_script(): void {
		$handle = 'estasoft-nd-editor-labels';

		wp_enqueue_script(
			$handle,
			ESTASOFT_ND_URL . 'assets/js/editor/labels-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render', 'wp-data', 'wp-i18n' ),
			Assets::file_version( 'assets/js/editor/labels-block.js' ),
			array( 'in_footer' => true )
		);

		wp_set_script_translations( $handle, 'estasoft-news-distribution', ESTASOFT_ND_PATH . 'languages' );
	}

	/**
	 * Puts a saved, shown correction note at the end of the text channels read.
	 *
	 * The page's own switch for the note does not matter here: it hides the note on the page and
	 * does not change what the feeds carry.
	 *
	 * @param mixed $content The article's stored text.
	 * @param mixed $post    The post.
	 * @return mixed
	 */
	public function feed_content( $content, $post = null ) {
		if ( ! is_string( $content ) || ! $post instanceof \WP_Post ) {
			return $content;
		}

		$note = $this->plugin->make( Corrections::class )->visible( (int) $post->ID );

		if ( null === $note ) {
			return $content;
		}

		$heading = sprintf(
			/* translators: %s: the date and time of a correction, such as "21 October, 14:21". */
			__( 'Correction, %s.', 'estasoft-news-distribution' ),
			Clock::format( $note['at'], (string) _x( 'j F, H:i', 'date and time of a correction note', 'estasoft-news-distribution' ) )
		);

		return $content . "\n\n<p><strong>" . esc_html( $heading ) . '</strong> ' . wp_kses( nl2br( esc_html( $note['note'] ) ), array( 'br' => array() ) ) . '</p>';
	}

	/**
	 * Saves the note from the History tab's card.
	 *
	 * @return void
	 */
	public function save_correction(): void {
		CorrectionCard::handle( $this->plugin );
	}

	/**
	 * Loads the Page labels screen's files.
	 *
	 * @return void
	 */
	public function settings_assets(): void {
		$page    = Query::key( 'page' );
		$section = Query::key( 'section' );

		if ( 'estasoft-nd-settings' === $page && 'labels' === $section ) {
			wp_enqueue_style( self::STYLE, ESTASOFT_ND_URL . 'assets/css/labels.css', array(), Assets::file_version( 'assets/css/labels.css' ) );
			Assets::screen_style( 'content-features' );
			Assets::screen_script( 'content-features' );
		}
	}
}

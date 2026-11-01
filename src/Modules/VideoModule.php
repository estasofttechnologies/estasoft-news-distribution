<?php
/**
 * Video markup on the article page, the editor section for it and the queued lookup.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Content\VideoLookup;
use Estasoft\NewsDistribution\Content\Videos;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Markup\VideoJsonLd;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * - On the page of a published article of a post type chosen in Settings, General, it prints one VideoObject
 *   (`Markup\VideoJsonLd`) for each video that has a name, a thumbnail and an upload date. A video that lacks one is left out.
 * - In the block editor it loads `assets/js/editor/video.js`, which adds the "News Distribution: video" section to the panel
 *   through the filter `estasoft_nd.panel_sections`, where an editor types the details of each video.
 * - When "Look up details" is on and an article with a video is saved, it puts one job in the queue (`estasoft_nd_video_lookup`).
 *   The job asks the video site once for each video and saves the answer with the article. Nothing is sent while the
 *   article is saved, when a page is shown or in the editor.
 *
 * What an editor typed is the post meta `_estasoft_nd_video`, registered for the REST API with a schema and a permission check.
 */
final class VideoModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'init', array( $this, 'register_meta' ), 20 );
		add_action( 'wp_head', array( $this, 'print_markup' ), 15 );
		add_action( 'wp_after_insert_post', array( $this, 'saved' ), 60, 2 );
		add_action( Videos::HOOK, array( $this, 'run_lookup' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'settings_assets' ), 20 );
	}

	/**
	 * Registers what an editor typed about the videos, for the block editor.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$access = $this->plugin->access();

		foreach ( $this->plugin->settings()->post_types() as $type ) {
			register_post_meta(
				$type,
				Videos::OVERRIDES,
				array(
					'type'              => 'array',
					'single'            => true,
					'default'           => array(),
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'key'         => array( 'type' => 'string' ),
									'name'        => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
									'upload_date' => array( 'type' => 'string' ),
									'duration'    => array( 'type' => 'integer' ),
									'thumbnail'   => array( 'type' => 'string' ),
									'skip'        => array( 'type' => 'boolean' ),
								),
								'additionalProperties' => false,
							),
						),
					),
					'sanitize_callback' => array( Videos::class, 'clean_overrides' ),
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id, $user_id = 0 ) use ( $access ): bool {
						return $access->can_edit_article( (int) $post_id, (int) $user_id );
					},
				)
			);
		}
	}

	/**
	 * The service.
	 *
	 * @return Videos
	 */
	private function videos(): Videos {
		return $this->plugin->make( Videos::class );
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
	 * `wp_head`: prints the markup of the videos that are ready.
	 *
	 * @return void
	 */
	public function print_markup(): void {
		if ( ! $this->videos()->enabled() || ! $this->applies() ) {
			return;
		}

		// Encoded by wp_json_encode with JSON_HEX_TAG, so nothing in it can close the script element.
		echo VideoJsonLd::scripts( $this->videos()->for_post( (int) get_queried_object_id() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * `wp_after_insert_post`: asks for the lookup in the background when an article with a video was saved.
	 *
	 * @param mixed $post_id The saved post's ID.
	 * @param mixed $post    The saved post.
	 * @return void
	 */
	public function saved( $post_id, $post = null ): void {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		if ( in_array( $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return;
		}

		if ( $this->videos()->enabled() && $this->videos()->lookup_on() ) {
			$this->plugin->make( VideoLookup::class )->queue( (int) $post->ID );
		}
	}

	/**
	 * The queued job: asks the video sites about the videos of one article.
	 *
	 * @param mixed $post_id Article ID.
	 * @return void
	 */
	public function run_lookup( $post_id = 0 ): void {
		$this->plugin->make( VideoLookup::class )->run( (int) $post_id );
	}

	/**
	 * Loads the script that adds the video section to the editor panel.
	 *
	 * @return void
	 */
	public function editor_assets(): void {
		$post = get_post();

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return;
		}

		if ( ! $this->plugin->access()->can( Access::VIEW_OWN, (int) $post->ID ) || ! $this->plugin->access()->can_edit_article( (int) $post->ID ) ) {
			return;
		}

		$handle = 'estasoft-nd-editor-video';

		wp_enqueue_script(
			$handle,
			ESTASOFT_ND_URL . 'assets/js/editor/video.js',
			array( 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-api-fetch', 'wp-hooks' ),
			Assets::file_version( 'assets/js/editor/video.js' ),
			array( 'in_footer' => true )
		);

		wp_set_script_translations( $handle, 'estasoft-news-distribution', ESTASOFT_ND_PATH . 'languages' );
		wp_enqueue_style( 'estasoft-nd-editor-content', ESTASOFT_ND_URL . 'assets/css/editor-content.css', array(), Assets::file_version( 'assets/css/editor-content.css' ) );

		wp_add_inline_script(
			$handle,
			'window.estasoftNDVideo = ' . wp_json_encode(
				array(
					'enabled'     => $this->videos()->enabled(),
					'lookup'      => $this->videos()->lookup_on(),
					'postTypes'   => array_values( $this->plugin->settings()->post_types() ),
					'path'        => 'articles/%d/videos',
					'lookupPath'  => 'articles/%d/videos/lookup',
					'metaKey'     => Videos::OVERRIDES,
					'settingsUrl' => admin_url( 'admin.php?page=estasoft-nd-settings&section=video' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Loads the settings screen's files.
	 *
	 * @return void
	 */
	public function settings_assets(): void {
		$page    = Query::key( 'page' );
		$section = Query::key( 'section' );

		if ( 'estasoft-nd-settings' === $page && 'video' === $section ) {
			Assets::screen_style( 'content-features' );
			Assets::screen_script( 'content-features' );
		}
	}
}

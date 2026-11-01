<?php
/**
 * The News Distribution panel in the block editor, and what its choices do.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Check\ArticleOptions;
use Estasoft\NewsDistribution\Admin\Check\PanelState;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Loads `assets/js/editor/panel.js` in the block editor, for the post types the site sends, and only
 * for a person who may edit the article. The script reads the check (`GET /articles/{id}/check`), runs it
 * (`POST /articles/{id}/check`) and saves the per-article choices in the post meta `_estasoft_nd_options`,
 * which this module registers for the REST API with a schema and a permission check.
 *
 * Two of the choices change what is sent:
 *
 * - Minor edit: the save that carries it sends no update to the pushed channels. The flag is used once
 *   (`wp_after_insert_post`, after the meta is written) and then switched off.
 * - A pushed channel switched off for the article: its publish and update pushes are held when the queue
 *   runs them. A takedown is never held, so removing an article still reaches every channel.
 *
 * Another feature adds a section to the panel through the script's extension point: push a descriptor
 * `{ id, title, order, render }` onto `window.estasoftND.panelSections`, or add it with the filter
 * `estasoft_nd.panel_sections` (`wp.hooks.addFilter`). The descriptor is described at the top of `assets/js/editor/panel.js`.
 */
final class EditorPanelModule implements ModuleInterface {

	public const HANDLE = 'estasoft-nd-editor-panel';

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
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
		add_action( 'wp_after_insert_post', array( $this, 'consume_minor_edit' ), 100, 4 );
		add_filter( 'estasoft_nd_internal_delivery_hold', array( $this, 'hold_switched_off' ), 20, 4 );
	}

	/**
	 * `init`: the per-article choices as post meta, in the REST API.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		ArticleOptions::register( $this->plugin );
	}

	/**
	 * `enqueue_block_editor_assets`: the panel's script and style, with the article's starting state.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post   = get_post();

		if ( ! $screen || ! $screen->is_block_editor() || ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) || ! $this->plugin->access()->can_edit_article( (int) $post->ID ) ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			ESTASOFT_ND_URL . 'assets/css/editor-panel.css',
			array( 'wp-components' ),
			Assets::file_version( 'assets/css/editor-panel.css' )
		);

		wp_enqueue_script(
			self::HANDLE,
			ESTASOFT_ND_URL . 'assets/js/editor/panel.js',
			array( 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-hooks', 'wp-components', 'wp-a11y', 'wp-date' ),
			Assets::file_version( 'assets/js/editor/panel.js' ),
			array( 'in_footer' => true )
		);

		wp_set_script_translations( self::HANDLE, 'estasoft-news-distribution', ESTASOFT_ND_PATH . 'languages' );

		wp_add_inline_script(
			self::HANDLE,
			'window.estasoftND = window.estasoftND || {};'
			. 'window.estasoftND.panel = ' . wp_json_encode( PanelState::build( $this->plugin, (int) $post->ID ) ) . ';'
			. 'window.estasoftND.panelSections = window.estasoftND.panelSections || [];'
			. 'window.estasoftND.panelHostsSections = true;',
			'before'
		);
	}

	/**
	 * `wp_after_insert_post`: a save marked as a minor edit sends no update.
	 *
	 * Runs after the meta is written, in the block editor's save and in the classic editor's. The updates
	 * this save put in the queue are cancelled and each is logged as skipped, with the reason. The flag
	 * is then switched off, so it covers one save only.
	 *
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post      $post        The saved post.
	 * @param bool          $update      Whether this updated an existing post.
	 * @param \WP_Post|null $post_before The post as it was, on an update.
	 * @return void
	 */
	public function consume_minor_edit( $post_id, $post = null, $update = false, $post_before = null ): void {
		$post_id = (int) $post_id;

		if ( ! $post instanceof \WP_Post || ! $update || wp_is_post_autosave( $post ) || wp_is_post_revision( $post ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) || ! ArticleOptions::get( $post_id )['minor_edit'] ) {
			return;
		}

		// An article that was not published before has nothing to update; the flag has no work to do, so it is cleared.
		$was_live = $post_before instanceof \WP_Post && 'publish' === $post_before->post_status;

		if ( $was_live && 'publish' === $post->post_status ) {
			$this->skip_waiting_updates( $post_id );
		}

		ArticleOptions::update( $post_id, array( 'minor_edit' => false ) );
	}

	/**
	 * Cancels the updates waiting for an article and logs each as skipped.
	 *
	 * @param int $post_id Article ID.
	 * @return void
	 */
	private function skip_waiting_updates( int $post_id ): void {
		$log     = $this->plugin->log();
		$waiting = array();

		foreach ( $log->latest_for_post( $post_id ) as $channel => $row ) {
			if ( Status::QUEUED === $row['status'] && Event::UPDATE === $row['event'] ) {
				$waiting[ (string) $channel ] = (string) $row['language'];
			}
		}

		if ( array() === $waiting ) {
			return;
		}

		$this->plugin->queue()->cancel_for_article( $post_id, array( Event::UPDATE ) );

		foreach ( $waiting as $channel => $language ) {
			$log->record( $post_id, $channel, $language, Event::UPDATE, Status::SKIPPED, 1, 0, ArticleOptions::minor_edit_message() );
		}
	}

	/**
	 * `estasoft_nd_internal_delivery_hold`: a pushed channel switched off for the article sends nothing for it.
	 *
	 * Only answers when the queue runs the delivery (the article is given). When the push is queued the
	 * block editor has not written the meta yet, so the choice could not be read.
	 *
	 * @param mixed $hold    An answer given earlier, or null.
	 * @param mixed $channel The channel.
	 * @param mixed $event   The event.
	 * @param mixed $article The article, or null while the push is being queued.
	 * @return mixed
	 */
	public function hold_switched_off( $hold, $channel = null, $event = null, $article = null ) {
		if ( null !== $hold || ! $article instanceof Article || ! is_object( $channel ) || ! method_exists( $channel, 'id' ) || ! $event instanceof Event ) {
			return $hold;
		}

		if ( ! in_array( $event->type(), array( Event::PUBLISH, Event::UPDATE ), true ) ) {
			return $hold;
		}

		if ( ! ArticleOptions::channel_is_off( $event->post_id(), (string) $channel->id() ) ) {
			return $hold;
		}

		return array(
			'status'  => Status::SKIPPED,
			'message' => __( 'Turned off for this article in the editor.', 'estasoft-news-distribution' ),
		);
	}
}

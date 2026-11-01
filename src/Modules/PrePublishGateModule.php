<?php
/**
 * The pre-publish gate in the block editor.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Core\Gate;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Loads `assets/js/editor/gate.js` in the block editor when an administrator set the gate to warn or block
 * (Settings, Access and gate), for a post type the site sends and a person who may edit the article.
 *
 * The script adds a section to the "Are you ready to publish?" sidebar. It reads the gate from
 * `GET /articles/{id}/gate` and in block mode holds the sidebar's Publish button back while a required channel
 * has a blocking problem. "Publish anyway" sends the typed reason with the save, in the `X-Estasoft-ND-Override`
 * header; `GateServerModule` is what checks it and writes the audit row when the article goes live.
 *
 * The panel is the friendly half. In block mode the server refuses a publish that fails the gate whichever way
 * it arrives (see `GateServerModule`), and the panel says what the server does not stop (`Core\Gate::limits()`).
 * `Core\Gate::config()` carries `enforced: true` in block mode.
 */
final class PrePublishGateModule implements ModuleInterface {

	public const HANDLE = 'estasoft-nd-editor-gate';

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * `enqueue_block_editor_assets`: the gate's script and style, with the starting state.
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

		$gate = $this->plugin->make( Gate::class );

		if ( Gate::OFF === $gate->mode() ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			ESTASOFT_ND_URL . 'assets/css/editor-gate.css',
			array( 'wp-components' ),
			Assets::file_version( 'assets/css/editor-gate.css' )
		);

		wp_enqueue_script(
			self::HANDLE,
			ESTASOFT_ND_URL . 'assets/js/editor/gate.js',
			array( 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-components', 'wp-a11y' ),
			Assets::file_version( 'assets/js/editor/gate.js' ),
			array( 'in_footer' => true )
		);

		wp_set_script_translations( self::HANDLE, 'estasoft-news-distribution', ESTASOFT_ND_PATH . 'languages' );

		wp_add_inline_script(
			self::HANDLE,
			'window.estasoftND = window.estasoftND || {};'
			. 'window.estasoftND.gate = ' . wp_json_encode( array( 'postId' => (int) $post->ID ) + $gate->config( (int) $post->ID ) ) . ';',
			'before'
		);
	}
}

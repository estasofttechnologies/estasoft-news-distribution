<?php
/**
 * Keeps the modified date when nothing important changed.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\DateGuard;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Wires `Core\DateGuard` into WordPress: it decides while the post row is written
 * (`wp_insert_post_data`), writes the decision down when the save is done
 * (`wp_after_insert_post`), looks again after the block editor has set the featured
 * image and the categories (`rest_after_insert_<type>`), and tells the editor in the
 * classic Publish box what happened to the date.
 *
 * Autosaves, revisions, imports and posts that are not published are never touched.
 */
final class DateGuardModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;
		$guard        = $plugin->make( DateGuard::class );

		add_filter( 'wp_insert_post_data', array( $guard, 'filter_post_data' ), 20, 4 );
		add_action( 'wp_after_insert_post', array( $guard, 'after_insert' ), 20, 2 );
		add_action( 'rest_api_init', array( $this, 'watch_rest' ) );
		add_action( 'post_submitbox_misc_actions', array( $this, 'publish_box' ) );
	}

	/**
	 * Listens for the end of a REST save of each post type the site sends.
	 *
	 * @return void
	 */
	public function watch_rest(): void {
		$guard = $this->plugin->make( DateGuard::class );

		foreach ( $this->plugin->settings()->post_types() as $type ) {
			add_action( 'rest_after_insert_' . $type, array( $guard, 'after_rest' ), 20, 1 );
		}
	}

	/**
	 * A line in the classic editor's Publish box: what the guard did with the modified date.
	 *
	 * @param \WP_Post|mixed $post The post being edited.
	 * @return void
	 */
	public function publish_box( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$guard = $this->plugin->make( DateGuard::class );

		if ( ! $guard->supports( (string) $post->post_type ) ) {
			return;
		}

		$last = $guard->last( (int) $post->ID );

		if ( null === $last || '' === (string) $last['modified_gmt'] ) {
			return;
		}

		$time = Clock::format( Clock::from_gmt( (string) $last['modified_gmt'] ), 'H:i' );
		$text = DateGuard::KEPT === $last['decision']
			/* translators: %s: a time such as 13:41. */
			? sprintf( __( 'kept at %s', 'estasoft-news-distribution' ), $time )
			/* translators: %s: a time such as 13:05. */
			: sprintf( __( 'moved to %s', 'estasoft-news-distribution' ), $time );

		echo '<div class="misc-pub-section estasoft-nd-date-guard"><strong>' . esc_html__( 'Modified date:', 'estasoft-news-distribution' ) . '</strong> ' . esc_html( $text ) . '</div>';
	}
}

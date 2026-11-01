<?php
/**
 * Scripts and styles for News Distribution screens.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin;

use Estasoft\NewsDistribution\Rest\Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Loaded only on this plugin's own screens, never on other admin pages.
 */
final class Assets {

	public const STYLE  = 'estasoft-nd-admin';
	public const SCRIPT = 'estasoft-nd-admin';

	/**
	 * Enqueues the admin style and script and the settings the script reads.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$version = $this->version();

		wp_enqueue_style( self::STYLE, ESTASOFT_ND_URL . 'assets/css/admin.css', array(), $version );

		wp_enqueue_script(
			self::SCRIPT,
			ESTASOFT_ND_URL . 'assets/js/admin.js',
			array(),
			$version,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script(
			self::SCRIPT,
			'window.estasoftND = ' . wp_json_encode( $this->config() ) . ';',
			'before'
		);
	}

	/**
	 * Enqueues a screen's own stylesheet from assets/css/screens/<name>.css.
	 * Call it from a screen's enqueue() method. Never add screen styles to admin.css.
	 *
	 * @param string $name File name without the extension, such as "overview".
	 * @return void
	 */
	public static function screen_style( string $name ): void {
		$name = sanitize_file_name( $name );

		wp_enqueue_style(
			self::STYLE . '-' . $name,
			ESTASOFT_ND_URL . 'assets/css/screens/' . $name . '.css',
			array( self::STYLE ),
			self::file_version( 'assets/css/screens/' . $name . '.css' )
		);
	}

	/**
	 * Enqueues a screen's own script from assets/js/screens/<name>.js, and gives
	 * it its data as `window.estasoftNDScreens["<name>"]`.
	 *
	 * @param string               $name File name without the extension, such as "overview".
	 * @param array<string, mixed> $data Values the script reads. Keep it small and free of secrets.
	 * @return void
	 */
	public static function screen_script( string $name, array $data = array() ): void {
		$name   = sanitize_file_name( $name );
		$handle = self::SCRIPT . '-' . $name;

		wp_enqueue_script(
			$handle,
			ESTASOFT_ND_URL . 'assets/js/screens/' . $name . '.js',
			array( self::SCRIPT ),
			self::file_version( 'assets/js/screens/' . $name . '.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		if ( array() !== $data ) {
			wp_add_inline_script(
				$handle,
				'window.estasoftNDScreens = window.estasoftNDScreens || {}; window.estasoftNDScreens[' . wp_json_encode( $name ) . '] = ' . wp_json_encode( $data ) . ';',
				'before'
			);
		}
	}

	/**
	 * The version used to refresh browser caches for a file under the plugin folder.
	 *
	 * @param string $relative Path inside the plugin, such as "assets/js/admin.js".
	 * @return string
	 */
	public static function file_version( string $relative ): string {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$time = @filemtime( ESTASOFT_ND_PATH . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing file only changes the cache key.

			return ESTASOFT_ND_VERSION . '.' . ( false === $time ? '0' : (string) $time );
		}

		return ESTASOFT_ND_VERSION;
	}

	/**
	 * What the script needs to talk to the REST API and to say things in the page language.
	 *
	 * @return array<string, mixed>
	 */
	private function config(): array {
		return array(
			'rest'  => esc_url_raw( rest_url( Controller::REST_NAMESPACE . '/' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'text'  => array(
				'saving'  => __( 'Saving…', 'estasoft-news-distribution' ),
				'saved'   => __( 'Saved.', 'estasoft-news-distribution' ),
				'save'    => __( 'Save changes', 'estasoft-news-distribution' ),
				'failed'  => __( 'Could not save. Check the highlighted settings and try again.', 'estasoft-news-distribution' ),
				'offline' => __( 'Could not reach your site. Check your connection and try again.', 'estasoft-news-distribution' ),
				'leave'   => __( 'You have unsaved changes.', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * The version of the main files.
	 *
	 * @return string
	 */
	private function version(): string {
		return self::file_version( 'assets/js/admin.js' );
	}
}

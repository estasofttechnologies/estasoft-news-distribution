<?php
/**
 * Answers at the old news sitemap address, and runs the buttons of the GN Publisher switch.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Settings\Sections\Compatibility;
use Estasoft\NewsDistribution\Integrations\ConflictDetector;
use Estasoft\NewsDistribution\Integrations\GnPublisher;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * "Answer at the old address" does not redirect. Google already knows the old
 * address, and Yandex flags a redirected sitemap as something to fix (the verify
 * log has both), so News Distribution answers there itself, with HTTP 200 and its
 * own Google News sitemap, and marks the answer with a header so the check can tell
 * who answered.
 *
 * It runs late (`template_redirect`, priority 99). While another plugin still
 * serves the address, that plugin answers first and this module never runs, which
 * is exactly what step 5 of the switch reports ("still answers with the other
 * plugin's sitemap"). It answers only GET and HEAD requests, only at the one path
 * the owner saved, and only while "Also answer at the old address" is on.
 *
 * WordPress adds a trailing slash to an address that has none and redirects to it
 * (`redirect_canonical`, which runs before this module). For the one saved path that
 * redirect is switched off, so the old address answers where it always did and no
 * redirect is left in front of it.
 *
 * The three buttons of the switch post here, so they work without JavaScript. Each
 * needs the Manage permission and a nonce. The look, compare and check buttons make
 * requests to this site's own addresses and nothing else.
 */
final class GnPublisherRedirectModule implements ModuleInterface {

	public const ACTION_LOOK  = 'estasoft_nd_gn_look';
	public const ACTION_CHECK = 'estasoft_nd_gn_check';
	public const ACTION_SAVE  = 'estasoft_nd_gn_save';
	public const ACTION_SCAN  = 'estasoft_nd_gn_scan';

	/**
	 * Where a message for the person who pressed a button waits for the next page.
	 */
	public const NOTICE_KEY = 'estasoft_nd_gn_notice_';

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_filter( 'redirect_canonical', array( $this, 'keep_address' ) );
		add_action( 'template_redirect', array( $this, 'answer' ), 99 );
		add_action( 'admin_post_' . self::ACTION_LOOK, array( $this, 'look' ) );
		add_action( 'admin_post_' . self::ACTION_CHECK, array( $this, 'check' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'save' ) );
		add_action( 'admin_post_' . self::ACTION_SCAN, array( $this, 'scan' ) );
	}

	/**
	 * Stops WordPress from redirecting the old address to the same address with a slash.
	 *
	 * @param mixed $redirect_url Where WordPress is about to redirect, or false.
	 * @return mixed
	 */
	public function keep_address( $redirect_url ) {
		return $this->is_asked_for() ? false : $redirect_url;
	}

	/**
	 * Answers a request for the old address.
	 *
	 * @return void
	 */
	public function answer(): void {
		if ( ! $this->is_asked_for() ) {
			return;
		}

		$gn       = new GnPublisher( $this->plugin );
		$method   = $this->method();
		$response = $gn->old_address_response(
			array(
				'if_none_match'     => isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '',
				'if_modified_since' => isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) : '',
			),
			isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''
		);

		if ( null !== $response ) {
			// WordPress started this request as a missing page and asked caches to keep away. The answer is a sitemap.
			header_remove( 'Pragma' );
			header_remove( 'Expires' );

			$response->send( 'HEAD' === $method );
		}
	}

	/**
	 * Whether this request is for the old address, and News Distribution is to answer it.
	 *
	 * @return bool
	 */
	private function is_asked_for(): bool {
		if ( is_admin() || ! in_array( $this->method(), array( 'GET', 'HEAD' ), true ) ) {
			return false;
		}

		$gn = new GnPublisher( $this->plugin );

		return $gn->answers_old_address() && $this->is_old_address( $gn->old_path() );
	}

	/**
	 * The request method.
	 *
	 * @return string
	 */
	private function method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}

	/**
	 * Whether the request is for a path, in the site's own folder.
	 *
	 * @param string $path A path such as /news-sitemap.xml.
	 * @return bool
	 */
	public function is_old_address( string $path ): bool {
		if ( '' === $path || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$requested = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$folder    = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		if ( '' !== $folder && 0 === strpos( $requested, $folder . '/' ) ) {
			$requested = substr( $requested, strlen( $folder ) );
		}

		return rtrim( $requested, '/' ) === rtrim( $path, '/' );
	}

	/**
	 * Step 1 and 2: looks at what the other plugin prints and compares the lists.
	 *
	 * @return void
	 */
	public function look(): void {
		$this->guard( self::ACTION_LOOK );

		$gn = new GnPublisher( $this->plugin );
		$gn->scan();
		$gn->compare();

		$this->back( 'step-2' );
	}

	/**
	 * "Check again" on the Compatibility screen: looks at what the site prints, and nothing else.
	 *
	 * @return void
	 */
	public function scan(): void {
		$this->guard( self::ACTION_SCAN );

		( new GnPublisher( $this->plugin ) )->scan();

		wp_safe_redirect( Compatibility::url_of( Compatibility::NAME ) );
		exit;
	}

	/**
	 * Step 5: runs the check.
	 *
	 * @return void
	 */
	public function check(): void {
		$this->guard( self::ACTION_CHECK );

		( new GnPublisher( $this->plugin ) )->check();

		$this->back( 'step-5' );
	}

	/**
	 * Step 4: saves "Also answer at the old address" and the address.
	 *
	 * @return void
	 */
	public function save(): void {
		check_admin_referer( self::ACTION_SAVE );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		$answer = isset( $_POST['answer_old_address'] ) && '1' === sanitize_key( wp_unslash( $_POST['answer_old_address'] ) );
		$path   = isset( $_POST['old_sitemap_path'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['old_sitemap_path'] ) ) ) : '';
		$error  = $this->store( $answer, $path );

		if ( null !== $error ) {
			set_transient( self::NOTICE_KEY . get_current_user_id(), $error, 2 * MINUTE_IN_SECONDS );
			$this->back( 'step-4', 'error' );
		}

		$this->back( 'step-4', 'saved' );
	}

	/**
	 * Saves the two settings of step 4 through the Compatibility section, so its checks apply.
	 *
	 * @param bool   $answer Whether to answer at the old address.
	 * @param string $path   The old address, as typed.
	 * @return string|null The message to show when the section refused the values, or null when they were saved.
	 */
	public function store( bool $answer, string $path ): ?string {
		$result = $this->plugin->settings()->save_section(
			'compatibility',
			array(
				'answer_old_address' => $answer,
				'old_sitemap_path'   => $path,
			)
		);

		return is_wp_error( $result ) ? $result->get_error_message() : null;
	}

	/**
	 * Checks the nonce and the permission.
	 *
	 * @param string $action The action, which is also the nonce name.
	 * @return void
	 */
	private function guard( string $action ): void {
		check_admin_referer( $action );
		$this->plugin->access()->require_or_die( Access::MANAGE );
	}

	/**
	 * Returns to the switch screen.
	 *
	 * @param string $anchor The step to scroll to.
	 * @param string $flag   `saved` or `error`, or an empty string.
	 * @return void
	 */
	private function back( string $anchor, string $flag = '' ): void {
		$url = ConflictDetector::migration_url();

		if ( '' !== $flag ) {
			$url = add_query_arg( 'gn', $flag, $url );
		}

		wp_safe_redirect( $url . '#' . $anchor );
		exit;
	}
}

<?php
/**
 * The buttons of the sign-in with the person's own app, and the address the provider sends them back to.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Operations\Forms;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Connections;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Integrations\OAuth\Flow;
use Estasoft\NewsDistribution\Integrations\OAuth\Providers;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Three handlers, all in `admin-post.php`, all for administrators only:
 *
 * - `estasoft_nd_oauth_start`: the "Sign in with Google" and "Sign in with Yandex" button. A form post with a
 *   nonce. It saves the client ID and client secret the person typed, makes the one-time state, and sends the
 *   browser to the provider.
 * - `estasoft_nd_oauth_callback`: the address the person registers with the provider (`Providers::redirect_uri()`).
 *   A GET, sent by the provider, so it has no nonce: the state the sign-in made is what ties it to the person who
 *   pressed the button. A visitor who is not signed in to the site is sent to the login page and nothing else.
 * - `estasoft_nd_oauth_forget`: removes the client ID and secret of an app that is not connected.
 *
 * Nothing runs unless a person presses the button. Nothing is sent on publish.
 */
final class OAuthModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 21 );
		add_action( 'admin_post_estasoft_nd_oauth_start', array( $this, 'start' ) );
		add_action( 'admin_post_' . Providers::CALLBACK_ACTION, array( $this, 'callback' ) );
		add_action( 'admin_post_nopriv_' . Providers::CALLBACK_ACTION, array( $this, 'callback_signed_out' ) );
		add_action( 'admin_post_estasoft_nd_oauth_forget', array( $this, 'forget' ) );
	}

	/**
	 * Loads the copy button of the redirect address, on the Connections screen only.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$page    = Query::key( 'page' );
		$section = Query::key( 'section' );

		if ( 'estasoft-nd-settings' !== $page || 'connections' !== $section ) {
			return;
		}

		Assets::screen_style( 'address-row' );
		Assets::screen_script( 'address-row' );
	}

	/**
	 * Saves the app, then sends the browser to the provider's sign-in page.
	 *
	 * @return void
	 */
	public function start(): void {
		check_admin_referer( Forms::connection_nonce() );
		$this->plugin->access()->require_or_die( Access::ADMIN );

		$engine_id = $this->posted( 'engine' );
		$service   = $this->plugin->make( Connections::class );
		$engine    = $service->get( $engine_id );
		$provider  = Providers::for_engine( $engine_id );

		if ( null === $engine || null === $provider ) {
			wp_die( esc_html__( 'That connection does not exist.', 'estasoft-news-distribution' ), '', array( 'response' => 404 ) );
		}

		// "Sign in again" posts no app: the saved one is used. A first sign-in posts the client ID and secret.
		if ( Connections::MODE_NONE === $service->mode( $engine_id ) ) {
			$saved = $service->save_app(
				$engine_id,
				array(
					(string) $provider['client_id_field'] => $this->posted( (string) $provider['client_id_field'] ),
					(string) $provider['client_secret_field'] => $this->posted_secret( (string) $provider['client_secret_field'] ),
				)
			);

			if ( is_wp_error( $saved ) ) {
				Flash::set( 'danger', $saved->get_error_message() );
				$this->back( $engine_id );
			}
		}

		$address = ( new Flow( $this->plugin ) )->start( $engine_id );

		if ( is_wp_error( $address ) ) {
			Flash::set( 'danger', $address->get_error_message() );
			$this->back( $engine_id );
		}

		// The provider's own address, so it is not a "safe" redirect to this site's hosts.
		wp_redirect( $address ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The address was made here from the provider's fixed sign-in address.
		exit;
	}

	/**
	 * The provider sent the browser back: finish the sign-in and say how it went.
	 *
	 * @return void
	 */
	public function callback(): void {
		$this->plugin->access()->require_or_die( Access::ADMIN );

		$query = array();

		// The state and the code are compared and sent on exactly as they came, so only control characters are removed.
		foreach ( array( 'state', 'code' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The one-time state is the check; see Flow::finish().
				$query[ $key ] = trim( (string) filter_var( wp_unslash( $_GET[ $key ] ), FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}

		foreach ( array( 'error', 'error_description' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The one-time state is the check; see Flow::finish().
				$query[ $key ] = trim( sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}

		$done   = ( new Flow( $this->plugin ) )->finish( $query );
		$engine = $this->plugin->make( Connections::class )->get( $done['engine'] );

		if ( null === $engine ) {
			Flash::set( 'danger', $done['message'] );
		} elseif ( $done['ok'] ) {
			Flash::set(
				'success',
				sprintf(
					/* translators: 1: the service's name, 2: what the service said. */
					__( 'Connected to %1$s. %2$s', 'estasoft-news-distribution' ),
					$engine->label(),
					$done['message']
				)
			);
		} else {
			Flash::set(
				'danger',
				sprintf(
					/* translators: 1: the service's name, 2: why it did not work. */
					__( 'Not connected to %1$s. %2$s', 'estasoft-news-distribution' ),
					$engine->label(),
					$done['message']
				)
			);
		}

		$this->back( $done['engine'] );
	}

	/**
	 * Someone who is not signed in to the site reached the callback address: the login page, and nothing from the address.
	 *
	 * @return void
	 */
	public function callback_signed_out(): void {
		wp_safe_redirect( wp_login_url( Links::settings( 'connections' ) ) );
		exit;
	}

	/**
	 * Removes the client ID and client secret of an app that is not connected.
	 *
	 * @return void
	 */
	public function forget(): void {
		check_admin_referer( Forms::connection_nonce() );
		$this->plugin->access()->require_or_die( Access::ADMIN );

		$engine_id = $this->posted( 'engine' );
		$service   = $this->plugin->make( Connections::class );
		$engine    = $service->get( $engine_id );

		if ( null === $engine || null === Providers::for_engine( $engine_id ) ) {
			wp_die( esc_html__( 'That connection does not exist.', 'estasoft-news-distribution' ), '', array( 'response' => 404 ) );
		}

		if ( $service->forget_app( $engine_id ) ) {
			Flash::set( 'success', __( 'The client ID and client secret were removed from your site.', 'estasoft-news-distribution' ) );
		} else {
			Flash::set( 'danger', __( 'This account is connected. Press Disconnect first.', 'estasoft-news-distribution' ) );
		}

		$this->back( $engine_id );
	}

	/**
	 * Sends the person back to the Connections screen, at the card of the connection.
	 *
	 * @param string $engine_id The connection's id, or empty when it is not known.
	 * @return void
	 */
	private function back( string $engine_id ): void {
		$url = Links::settings( 'connections' ) . ( '' === $engine_id ? '' : '#estasoft-nd-card-' . sanitize_key( 'connections-' . $engine_id ) );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * One posted value as text, or an empty string.
	 *
	 * @param string $key The field name.
	 * @return string
	 */
	private function posted( string $key ): string {
		check_admin_referer( Forms::connection_nonce() );

		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) ) : '';
	}

	/**
	 * A posted secret as typed. It is never printed.
	 *
	 * @param string $key The field name.
	 * @return string
	 */
	private function posted_secret( string $key ): string {
		check_admin_referer( Forms::connection_nonce() );

		// A secret is saved as typed (control characters removed) and never printed.
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? trim( (string) filter_var( wp_unslash( $_POST[ $key ] ), FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW ) ) : '';
	}
}

<?php
/**
 * Handles the forms of the AI screens, so they work without a script.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai\View;

use Estasoft\NewsDistribution\Core\Operations\Flash;

defined( 'ABSPATH' ) || exit;

/**
 * A suggestion form posts to the screen that printed it. `handle()` checks the nonce and then makes the same
 * internal request the script would (`POST /ai/suggest/<feature>` or `POST /ai/decision`), so consent, the switches,
 * the limits, the rule check and the permission checks are the routes' own and are not written a second time.
 * The person is sent back to the screen, with one message saying what happened.
 */
final class Runner {

	public const NONCE = '_estasoft_nd_ai';

	/**
	 * The posted values a request may carry, and how each is read.
	 *
	 * @var array<string, string>
	 */
	private const FIELDS = array(
		'post'        => 'int',
		'log'         => 'int',
		'index'       => 'int',
		'responsible' => 'int',
		'reviewer'    => 'int',
		'confirm'     => 'int',
		'decision'    => 'key',
		'kind'        => 'key',
		'mode'        => 'key',
		'target'      => 'key',
		'language'    => 'key',
		'text'        => 'text',
		'question'    => 'text',
		'keep'        => 'list',
		'facts'       => 'list',
	);

	/**
	 * The nonce action of a screen.
	 *
	 * @param string $tab The screen's own name.
	 * @return string
	 */
	public static function action( string $tab ): string {
		return 'estasoft_nd_ai_' . sanitize_key( $tab );
	}

	/**
	 * Whether an AI form was posted to this screen. A posted form must carry the screen's nonce: the request
	 * ends with the WordPress message when it does not, before anything else is read from it.
	 *
	 * @param string $tab The screen's own name.
	 * @return bool
	 */
	public static function posted( string $tab ): bool {
		if ( ! isset( $_POST['estasoft_nd_ai_do'] ) ) {
			return false;
		}

		check_admin_referer( self::action( $tab ), self::NONCE );

		return true;
	}

	/**
	 * Does what the posted form asked, then sends the person back.
	 *
	 * @param string                    $tab   The screen's own name.
	 * @param string                    $back  Where to go afterwards.
	 * @param array<string, int|string> $extra Values the screen itself works out, set after the posted ones.
	 * @return void
	 */
	public function handle( string $tab, string $back, array $extra = array() ): void {
		if ( ! self::posted( $tab ) ) {
			return;
		}

		check_admin_referer( self::action( $tab ), self::NONCE );

		$do      = isset( $_POST['estasoft_nd_ai_do'] ) ? sanitize_key( wp_unslash( $_POST['estasoft_nd_ai_do'] ) ) : '';
		$feature = isset( $_POST['estasoft_nd_ai_feature'] ) ? sanitize_key( wp_unslash( $_POST['estasoft_nd_ai_feature'] ) ) : '';
		$route   = 'suggest' === $do && '' !== $feature ? '/estasoft-nd/v1/ai/suggest/' . $feature : ( 'decide' === $do ? '/estasoft-nd/v1/ai/decision' : '' );

		if ( '' === $route ) {
			wp_safe_redirect( $back );
			exit;
		}

		$request = new \WP_REST_Request( 'POST', $route );

		foreach ( self::FIELDS as $name => $kind ) {
			$value = $this->read( $name, $kind, $tab );

			if ( null !== $value ) {
				$request->set_param( $name, $value );
			}
		}

		foreach ( $extra as $name => $value ) {
			$request->set_param( (string) $name, $value );
		}

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$error = $response->as_error();

			Flash::set( 'danger', null === $error ? __( 'Nothing was changed. Try again.', 'estasoft-news-distribution' ) : $this->say( $error ) );
		} else {
			$data    = $response->get_data();
			$message = is_array( $data ) ? (string) ( $data['message'] ?? '' ) : '';

			if ( '' !== $message ) {
				Flash::set( 'decide' === $do ? 'success' : 'warning', $message );
			}
		}

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * The error's message and, when the rule check refused a draft, the checks it failed.
	 *
	 * @param \WP_Error $error The refusal.
	 * @return string Plain text.
	 */
	private function say( \WP_Error $error ): string {
		$text = $error->get_error_message();
		$data = $error->get_error_data();
		$why  = array();

		foreach ( is_array( $data ) ? (array) ( $data['checks'] ?? array() ) : array() as $check ) {
			if ( ! is_array( $check ) || 'fail' !== ( $check['level'] ?? '' ) ) {
				continue;
			}

			$label = trim( (string) ( $check['label'] ?? '' ) );
			$why[] = trim( ( '' === $label ? '' : $label . ': ' ) . (string) ( $check['text'] ?? '' ) );
		}

		return array() === $why ? $text : $text . ' ' . implode( ' ', array_filter( $why ) );
	}

	/**
	 * One posted value.
	 *
	 * @param string $name The field.
	 * @param string $kind How to read it: `int`, `key`, `text` or `list`.
	 * @param string $tab  The screen's own name, for the nonce.
	 * @return int|string|string[]|null Null when the field was not posted.
	 */
	private function read( string $name, string $kind, string $tab ) {
		check_admin_referer( self::action( $tab ), self::NONCE );

		if ( ! isset( $_POST[ $name ] ) ) {
			return null;
		}

		if ( 'list' === $kind ) {
			return is_array( $_POST[ $name ] ) ? array_values( array_map( 'sanitize_text_field', wp_unslash( $_POST[ $name ] ) ) ) : array();
		}

		if ( is_array( $_POST[ $name ] ) ) {
			return null;
		}

		switch ( $kind ) {
			case 'int':
				return absint( wp_unslash( $_POST[ $name ] ) );
			case 'key':
				return sanitize_key( wp_unslash( $_POST[ $name ] ) );
		}

		return sanitize_textarea_field( wp_unslash( $_POST[ $name ] ) );
	}
}

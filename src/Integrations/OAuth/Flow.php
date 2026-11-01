<?php
/**
 * The sign-in with the owner's own app: the authorization code flow.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations\OAuth;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Connections;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * "Bring your own app". See `Providers` for why, and for what has not been run against a
 * provider (all of it: outbound network is blocked where this was built, and the tests answer
 * from recorded shapes).
 *
 * 1. `start()` runs when an administrator presses "Sign in with Google" or "Sign in with Yandex".
 *    It makes a random `state`, and for Google a PKCE verifier, keeps them for ten minutes in a
 *    transient tied to the signed-in person (only a hash of the state is kept), and returns the
 *    provider's sign-in address. Nothing is sent to anyone yet: the person's browser goes there.
 * 2. The provider sends the browser back to `admin-post.php?action=estasoft_nd_oauth_callback`.
 *    `finish()` checks the state (the same person, not expired, not used before, compared with
 *    `hash_equals`), then exchanges the code for tokens, once, with `wp_safe_remote_post`
 *    (10 seconds, an answer is never read past 1 MB). A missing, wrong, expired or reused state is
 *    refused before anything is sent.
 * 3. The connection tests what it was given (`Core\Connections::accept_oauth()`) and keeps the
 *    token, as a write-only secret, only when the engine accepts it.
 *
 * Nothing here runs on publish, on a schedule or when a screen opens. The refresh token, the
 * client secret, the code and the verifier are never printed, logged, put in a message, a
 * restore point or an export: `scrub()` takes them out of any text that is shown.
 */
final class Flow {

	/**
	 * How long a sign-in may take, in seconds.
	 */
	public const STATE_TTL = 600;

	private const PENDING = 'estasoft_nd_oauth_';

	/**
	 * The most characters of a provider's own error text that are shown.
	 */
	private const MAX_ERROR = 200;

	private Plugin $plugin;

	/**
	 * Creates the service.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The PKCE challenge for a verifier: `BASE64URL( SHA-256( verifier ) )`, method `S256`.
	 *
	 * @param string $verifier The verifier.
	 * @return string
	 */
	public static function challenge( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A PKCE challenge, not hidden data.
	}

	/**
	 * Random text for a state or a PKCE verifier: the hexadecimal digits of random bytes. Digits and letters are all "unreserved" characters in RFC 7636.
	 *
	 * @param int $bytes How many random bytes. The text is twice as long.
	 * @return string
	 */
	private static function random( int $bytes ): string {
		try {
			return bin2hex( random_bytes( $bytes ) );
		} catch ( \Exception $unavailable ) {
			// No source of random bytes: WordPress's own password generator is the next best.
			return wp_generate_password( $bytes * 2, false, false );
		}
	}

	/**
	 * Begins a sign-in for a connection.
	 *
	 * @param string $engine_id The connection's id, such as `google-search-console`.
	 * @return string|\WP_Error The provider's sign-in address, to send the browser to, or an error that says what is missing.
	 */
	public function start( string $engine_id ) {
		$provider = Providers::for_engine( $engine_id );

		if ( null === $provider ) {
			return new \WP_Error( 'estasoft_nd_no_sign_in', __( 'That connection has no sign-in. Connect it with its key.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		if ( get_current_user_id() < 1 ) {
			return new \WP_Error( 'estasoft_nd_sign_in_needs_person', __( 'Sign in to your site first.', 'estasoft-news-distribution' ), array( 'status' => 401 ) );
		}

		$connections = $this->connections();

		if ( Connections::MODE_KEY === $connections->mode( $engine_id ) ) {
			return new \WP_Error(
				'estasoft_nd_connected_other_way',
				__( 'This account is connected with a key. Press Disconnect first, then sign in.', 'estasoft-news-distribution' ),
				array( 'status' => 409 )
			);
		}

		$client = $connections->client( $provider );

		if ( '' === $client['id'] || '' === $client['secret'] ) {
			$missing = __( 'Paste the client ID and the client secret first.', 'estasoft-news-distribution' );

			return new \WP_Error(
				'estasoft_nd_missing_app',
				$missing,
				array(
					'status' => 400,
					'fields' => array(
						$provider['client_id_field']     => $missing,
						$provider['client_secret_field'] => $missing,
					),
				)
			);
		}

		$state    = self::random( 20 );
		$verifier = $provider['pkce'] ? self::random( 32 ) : '';
		$redirect = Providers::redirect_uri();

		set_transient(
			$this->pending_key(),
			array(
				'state'    => hash( 'sha256', $state ),
				'provider' => $provider['id'],
				'verifier' => $verifier,
				'redirect' => $redirect,
				'expires'  => Clock::now() + self::STATE_TTL,
			),
			self::STATE_TTL
		);

		$query = array_merge(
			array(
				'client_id'     => $client['id'],
				'redirect_uri'  => $redirect,
				'response_type' => 'code',
				'scope'         => $provider['scope'],
				'state'         => $state,
			),
			(array) $provider['authorize_extra']
		);

		if ( '' !== $verifier ) {
			$query['code_challenge']        = self::challenge( $verifier );
			$query['code_challenge_method'] = 'S256';
		}

		return $provider['authorize_url'] . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Ends a sign-in: the provider sent the browser back.
	 *
	 * @param array<string, mixed> $query The address's query arguments, unslashed: `state` and `code`, or `error`.
	 * @return array{ok: bool, engine: string, message: string, code: string} `code` is `connected`, `state`, `expired`, `denied`, `exchange` or `rejected`.
	 */
	public function finish( array $query ): array {
		$key     = $this->pending_key();
		$pending = get_transient( $key );
		$given   = isset( $query['state'] ) && is_string( $query['state'] ) ? $query['state'] : '';

		if ( ! is_array( $pending ) || '' === $given || ! hash_equals( (string) ( $pending['state'] ?? '' ), hash( 'sha256', $given ) ) ) {
			// A wrong state does not use up the right one: whoever sent it learns nothing, and the real sign-in can still finish.
			return $this->result(
				false,
				'',
				'state',
				__( 'That sign-in link is not valid, or was already used. Start the sign-in again.', 'estasoft-news-distribution' )
			);
		}

		// One time only: from here on this state is gone, whatever happens next.
		delete_transient( $key );

		$provider = Providers::get( (string) ( $pending['provider'] ?? '' ) );

		if ( null === $provider ) {
			return $this->result( false, '', 'state', __( 'That sign-in link is not valid, or was already used. Start the sign-in again.', 'estasoft-news-distribution' ) );
		}

		$engine = (string) $provider['engine'];

		if ( Clock::now() > (int) ( $pending['expires'] ?? 0 ) ) {
			return $this->result(
				false,
				$engine,
				'expired',
				sprintf(
					/* translators: %s: the name of a sign-in service, such as "Google". */
					__( 'That sign-in took too long, so it was stopped. Press Sign in with %s to start again.', 'estasoft-news-distribution' ),
					$provider['label']
				)
			);
		}

		if ( isset( $query['error'] ) ) {
			return $this->result( false, $engine, 'denied', $this->denied_text( $provider, $query ) );
		}

		$code = isset( $query['code'] ) && is_string( $query['code'] ) ? trim( $query['code'] ) : '';

		if ( '' === $code ) {
			return $this->result(
				false,
				$engine,
				'rejected',
				sprintf(
					/* translators: %s: the name of a sign-in service, such as "Google". */
					__( '%s sent no sign-in code. Press Sign in again.', 'estasoft-news-distribution' ),
					$provider['label']
				)
			);
		}

		$connections = $this->connections();
		$client      = $connections->client( $provider );
		$tokens      = $this->exchange( $provider, $code, $pending, $client );

		if ( '' !== $tokens['error'] ) {
			return $this->result( false, $engine, 'exchange', $tokens['error'] );
		}

		$done = $connections->accept_oauth( $engine, $tokens );

		if ( is_wp_error( $done ) ) {
			return $this->result( false, $engine, 'rejected', $done->get_error_message() );
		}

		return $this->result( (bool) $done['ok'], $engine, $done['ok'] ? 'connected' : 'rejected', (string) $done['message'] );
	}

	/**
	 * Asks the provider to cancel a token. The caller removes its own copy whatever this says.
	 *
	 * @param array<string, mixed> $provider The provider (see `Providers`).
	 * @param string               $token    The token to cancel.
	 * @return string `revoked`, `failed`, or `none` when the provider has no such address or there is no token to cancel.
	 */
	public function revoke( array $provider, string $token ): string {
		if ( '' === (string) $provider['revoke_url'] || '' === $token ) {
			return 'none';
		}

		$body = array( 'token' => $token );

		if ( Providers::YANDEX === $provider['id'] ) {
			$client = $this->connections()->client( $provider );
			$body   = array(
				'access_token'  => $token,
				'client_id'     => $client['id'],
				'client_secret' => $client['secret'],
			);
		}

		$response = wp_safe_remote_post(
			(string) $provider['revoke_url'],
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'limit_response_size' => 1048576,
				'user-agent'          => $this->agent(),
				'body'                => $body,
			)
		);

		return ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ? 'revoked' : 'failed';
	}

	/**
	 * Takes the secrets out of text that may be shown or stored: any value given, and anything that looks like one in a query string.
	 *
	 * @param string   $text   The text.
	 * @param string[] $values Values to remove wherever they appear (the code, the client secret, a token).
	 * @return string
	 */
	public static function scrub( string $text, array $values = array() ): string {
		foreach ( $values as $value ) {
			if ( is_string( $value ) && strlen( $value ) >= 4 ) {
				$text = str_replace( $value, '[removed]', $text );
			}
		}

		$text = (string) preg_replace( '/((?:access_token|refresh_token|id_token|client_secret|code_verifier|assertion|code|token|key)(?:=|":\s*"|\'\s*:\s*\'))[^&\s"\']+/i', '$1[removed]', $text );

		return (string) preg_replace( '/(Bearer|OAuth)\s+[A-Za-z0-9._~+\/=-]{8,}/', '$1 [removed]', $text );
	}

	/**
	 * Trades the code for tokens.
	 *
	 * @param array<string, mixed>              $provider The provider.
	 * @param string                            $code     The code the provider sent back.
	 * @param array<string, mixed>              $pending  What `start()` kept.
	 * @param array{id: string, secret: string} $client   The owner's client ID and secret.
	 * @return array{access: string, refresh: string, expires_in: int, scope: string, error: string} `error` is a sentence for a person, empty when there are tokens.
	 */
	private function exchange( array $provider, string $code, array $pending, array $client ): array {
		$fields = array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => (string) ( $pending['redirect'] ?? Providers::redirect_uri() ),
			'client_id'     => $client['id'],
			'client_secret' => $client['secret'],
		);

		if ( '' !== (string) ( $pending['verifier'] ?? '' ) ) {
			$fields['code_verifier'] = (string) $pending['verifier'];
		}

		$response = wp_safe_remote_post(
			(string) $provider['token_url'],
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'limit_response_size' => 1048576,
				'user-agent'          => $this->agent(),
				'headers'             => array( 'Accept' => 'application/json' ),
				'body'                => $fields,
			)
		);

		$known = array( $code, $client['secret'], (string) ( $pending['verifier'] ?? '' ) );
		$label = (string) $provider['label'];
		$none  = array(
			'access'     => '',
			'refresh'    => '',
			'expires_in' => 0,
			'scope'      => '',
			'error'      => '',
		);

		if ( is_wp_error( $response ) ) {
			return array_merge(
				$none,
				array(
					'error' => sprintf(
						/* translators: 1: the name of a sign-in service, such as "Google", 2: the error text from WordPress. */
						__( 'Your site could not reach %1$s to finish the sign-in: %2$s', 'estasoft-news-distribution' ),
						$label,
						self::scrub( $response->get_error_message(), $known )
					),
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$json   = is_array( $json ) ? $json : array();
		$known  = array_merge( $known, array( (string) ( $json['access_token'] ?? '' ), (string) ( $json['refresh_token'] ?? '' ) ) );

		if ( 200 !== $status || empty( $json['access_token'] ) || ! is_string( $json['access_token'] ) ) {
			return array_merge(
				$none,
				array(
					'error' => sprintf(
						/* translators: 1: the name of a sign-in service, such as "Google", 2: why, in the service's own words or ours. */
						__( '%1$s did not accept the sign-in. %2$s', 'estasoft-news-distribution' ),
						$label,
						$this->exchange_reason( $json, $known )
					),
				)
			);
		}

		$refresh = isset( $json['refresh_token'] ) && is_string( $json['refresh_token'] ) ? $json['refresh_token'] : '';

		if ( Providers::GOOGLE === $provider['id'] && '' === $refresh ) {
			return array_merge(
				$none,
				array(
					'error' => __( 'Google gave no refresh token, so News Distribution could not stay signed in. In your Google Account, remove the access you gave this app, then press Sign in with Google again.', 'estasoft-news-distribution' ),
				)
			);
		}

		return array(
			'access'     => $json['access_token'],
			'refresh'    => $refresh,
			'expires_in' => (int) ( $json['expires_in'] ?? 0 ),
			'scope'      => isset( $json['scope'] ) && is_string( $json['scope'] ) ? sanitize_text_field( $json['scope'] ) : '',
			'error'      => '',
		);
	}

	/**
	 * Why a code exchange failed, in words: ours for the standard OAuth errors, else the provider's own, shortened.
	 *
	 * @param array<string, mixed> $json  The provider's answer.
	 * @param string[]             $known Values to take out of the text.
	 * @return string
	 */
	private function exchange_reason( array $json, array $known ): string {
		$error = isset( $json['error'] ) && is_string( $json['error'] ) ? $json['error'] : '';

		if ( 'invalid_client' === $error ) {
			return __( 'Check the client ID and the client secret.', 'estasoft-news-distribution' );
		}

		if ( 'invalid_grant' === $error ) {
			return __( 'The sign-in code was refused, or had been used before. Press Sign in again.', 'estasoft-news-distribution' );
		}

		$text = isset( $json['error_description'] ) && is_string( $json['error_description'] ) ? $json['error_description'] : $error;
		$text = trim( wp_strip_all_tags( self::scrub( $text, $known ) ) );

		return mb_strlen( $text ) > self::MAX_ERROR ? mb_substr( $text, 0, self::MAX_ERROR ) . '…' : $text;
	}

	/**
	 * The sentence for a provider that sent the person back with an error instead of a code.
	 *
	 * @param array<string, mixed> $provider The provider.
	 * @param array<string, mixed> $query    The query arguments.
	 * @return string
	 */
	private function denied_text( array $provider, array $query ): string {
		$error = isset( $query['error'] ) && is_string( $query['error'] ) ? sanitize_key( $query['error'] ) : '';

		if ( 'access_denied' === $error ) {
			return sprintf(
				/* translators: %s: the name of a sign-in service, such as "Google". */
				__( 'Access was not given, so nothing was connected. Press Sign in with %s to try again.', 'estasoft-news-distribution' ),
				$provider['label']
			);
		}

		$text = isset( $query['error_description'] ) && is_string( $query['error_description'] ) ? sanitize_text_field( $query['error_description'] ) : $error;
		$text = trim( self::scrub( $text ) );
		$text = mb_strlen( $text ) > self::MAX_ERROR ? mb_substr( $text, 0, self::MAX_ERROR ) . '…' : $text;

		return sprintf(
			/* translators: 1: the name of a sign-in service, such as "Google", 2: why, in the service's own words, may be empty. */
			__( '%1$s did not finish the sign-in. %2$s', 'estasoft-news-distribution' ),
			$provider['label'],
			$text
		);
	}

	/**
	 * One finished step.
	 *
	 * @param bool   $ok      Whether the person is now connected.
	 * @param string $engine  The connection's id, or empty when it is not known.
	 * @param string $code    What happened.
	 * @param string $message A sentence for a person.
	 * @return array{ok: bool, engine: string, message: string, code: string}
	 */
	private function result( bool $ok, string $engine, string $code, string $message ): array {
		return array(
			'ok'      => $ok,
			'engine'  => $engine,
			'message' => $message,
			'code'    => $code,
		);
	}

	/**
	 * The transient that holds the sign-in in progress for the signed-in person. A person has one at a time.
	 *
	 * @return string
	 */
	private function pending_key(): string {
		return self::PENDING . get_current_user_id();
	}

	/**
	 * The connections service.
	 *
	 * @return Connections
	 */
	private function connections(): Connections {
		return $this->plugin->make( Connections::class );
	}

	/**
	 * The name this plugin gives itself in a request.
	 *
	 * @return string
	 */
	private function agent(): string {
		return 'Estasoft News Distribution (' . home_url() . ')';
	}
}

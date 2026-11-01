<?php
/**
 * The sign-in providers of the connections: addresses, permission, and where each secret is kept.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * "Bring your own app" sign-in. News Distribution has no registered app with Google or
 * Yandex, and a client secret cannot ship in a public plugin, so the owner makes an OAuth
 * client in their own Google Cloud or Yandex OAuth account, and gives the plugin its client
 * ID and client secret. `Flow` then signs in with the authorization code flow.
 *
 * NOT RUN AGAINST THE PROVIDERS. Outbound network is blocked where this was built, so none
 * of the addresses, permission names or answer fields below has been used against a real
 * provider. The tests answer from recorded shapes with `pre_http_request`. What is known
 * and what is not:
 *
 * - Google: the authorization and token addresses and the `revoke` address are Google's
 *   documented OAuth 2.0 web server flow addresses, written from the documentation as known,
 *   not read again here. The scope is the one the service account path already asks for.
 *   PKCE (`S256`), `access_type=offline` and `prompt=consent` are the documented way to get
 *   a refresh token. Not run.
 * - Yandex: the authorization and token addresses are Yandex OAuth's documented addresses
 *   (`https://oauth.yandex.com/authorize` and `/token`). The permission names
 *   `webmaster:hostinfo webmaster:verify` and the revoke address are UNVERIFIED: they are
 *   written from memory of Yandex's guide, no file of this project confirms them, and the
 *   plugin cannot reach Yandex to check. The permission can be corrected without a new
 *   version through the filter `estasoft_nd_internal_oauth_scope`. A revoke that fails is
 *   not an error: the saved sign-in is removed here either way.
 *
 * Provider array shape:
 *
 *     array(
 *       'id'                  => 'google',
 *       'label'               => 'Google',
 *       'engine'              => 'google-search-console',    // the connection it signs in for
 *       'authorize_url'       => string,
 *       'token_url'           => string,
 *       'revoke_url'          => string,                     // '' when there is none
 *       'scope'               => string,                     // space separated
 *       'scope_verified'      => bool,
 *       'pkce'                => bool,                       // send a PKCE S256 challenge
 *       'authorize_extra'     => array<string, string>,      // more query arguments of the sign-in address
 *       'client_id_field'     => 'google_client_id',         // plain field of the Connections section
 *       'client_secret_field' => 'google_client_secret',     // secret field of the Connections section
 *       'token_key'           => 'connections_google_oauth_token', // Core\Secrets key of the kept token
 *     )
 */
final class Providers {

	public const GOOGLE = 'google';
	public const YANDEX = 'yandex';

	public const GOOGLE_AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
	public const GOOGLE_TOKEN_URL     = 'https://oauth2.googleapis.com/token';
	public const GOOGLE_REVOKE_URL    = 'https://oauth2.googleapis.com/revoke';
	public const GOOGLE_SCOPE         = 'https://www.googleapis.com/auth/webmasters';

	public const YANDEX_AUTHORIZE_URL = 'https://oauth.yandex.com/authorize';
	public const YANDEX_TOKEN_URL     = 'https://oauth.yandex.com/token';
	public const YANDEX_REVOKE_URL    = 'https://oauth.yandex.com/revoke_token'; // Unverified.
	public const YANDEX_SCOPE         = 'webmaster:hostinfo webmaster:verify'; // Unverified.

	/**
	 * The action name of the address the provider sends the person back to.
	 */
	public const CALLBACK_ACTION = 'estasoft_nd_oauth_callback';

	/**
	 * Every provider, by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		/**
		 * Filters the permission (scope) a sign-in asks for. For the day a provider's name for it differs from the one this
		 * version knows; the Yandex names are unverified.
		 *
		 * @param string $scope    Space separated permission names.
		 * @param string $provider `google` or `yandex`.
		 */
		$google_scope = (string) apply_filters( 'estasoft_nd_internal_oauth_scope', self::GOOGLE_SCOPE, self::GOOGLE );
		$yandex_scope = (string) apply_filters( 'estasoft_nd_internal_oauth_scope', self::YANDEX_SCOPE, self::YANDEX );

		return array(
			self::GOOGLE => array(
				'id'                  => self::GOOGLE,
				'label'               => 'Google',
				'engine'              => 'google-search-console',
				'authorize_url'       => self::GOOGLE_AUTHORIZE_URL,
				'token_url'           => self::GOOGLE_TOKEN_URL,
				'revoke_url'          => self::GOOGLE_REVOKE_URL,
				'scope'               => $google_scope,
				'scope_verified'      => self::GOOGLE_SCOPE === $google_scope,
				'pkce'                => true,
				'authorize_extra'     => array(
					'access_type' => 'offline',
					'prompt'      => 'consent',
				),
				'client_id_field'     => 'google_client_id',
				'client_secret_field' => 'google_client_secret',
				'token_key'           => 'connections_google_oauth_token',
			),
			self::YANDEX => array(
				'id'                  => self::YANDEX,
				'label'               => 'Yandex',
				'engine'              => 'yandex-webmaster',
				'authorize_url'       => self::YANDEX_AUTHORIZE_URL,
				'token_url'           => self::YANDEX_TOKEN_URL,
				'revoke_url'          => self::YANDEX_REVOKE_URL,
				'scope'               => $yandex_scope,
				'scope_verified'      => false,
				'pkce'                => false,
				'authorize_extra'     => array(),
				'client_id_field'     => 'yandex_client_id',
				'client_secret_field' => 'yandex_client_secret',
				'token_key'           => 'connections_yandex_oauth_token',
			),
		);
	}

	/**
	 * One provider.
	 *
	 * @param string $id `google` or `yandex`.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * The provider that signs in for a connection.
	 *
	 * @param string $engine_id The connection's id, such as `google-search-console`.
	 * @return array<string, mixed>|null Null for a connection that has no sign-in (Bing).
	 */
	public static function for_engine( string $engine_id ): ?array {
		foreach ( self::all() as $provider ) {
			if ( $provider['engine'] === $engine_id ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * The address the person registers with the provider, and the provider sends them back to.
	 *
	 * @return string
	 */
	public static function redirect_uri(): string {
		return add_query_arg( 'action', self::CALLBACK_ACTION, admin_url( 'admin-post.php' ) );
	}
}

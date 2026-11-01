<?php
/**
 * Connections to search engine accounts.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Integrations\Connections\AbstractConnection;
use Estasoft\NewsDistribution\Integrations\Connections\ConnectionInterface;
use Estasoft\NewsDistribution\Integrations\OAuth\Flow;
use Estasoft\NewsDistribution\Integrations\OAuth\OAuthRefresh;
use Estasoft\NewsDistribution\Integrations\OAuth\Providers;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * Connect, check and disconnect for Yandex Webmaster, Google Search Console and
 * Bing Webmaster Tools (the engines in `src/Integrations/Connections/`).
 *
 * Nothing here runs on its own. `connect()`, `accept_oauth()` and `check()` contact an engine and
 * are called only from a person's click (the Connections screen or its REST
 * routes). A credential is saved in `Core\Secrets` only after the engine
 * accepted it, is never returned, and is left out of every export. What the
 * last check found is kept in the option `estasoft_nd_connection_state` (not
 * autoloaded, not exported).
 *
 * Google Search Console and Yandex Webmaster can be connected in two ways, and only one at a time
 * (`mode()`): with the key or token the person pastes (`connect()`), or by signing in with the
 * person's own app (`Integrations\OAuth\Flow`, then `accept_oauth()`). A sign-in leaves one
 * secret, the token (`Providers` says where: `connections_<provider>_oauth_token`), kept as
 * `{"kind":"refresh|access","token":"..."}`. The client ID is a plain setting and the client
 * secret a secret of the Connections section. `disconnect()` asks the provider to cancel the token
 * when it can, and then always removes the token, the client secret, the pasted key and the
 * cached access token.
 *
 * State of one engine (`state()`):
 *
 *     array(
 *       'saved_on'   => int,      // Unix time the credential was saved, 0 when none
 *       'expires_on' => int,      // Unix time a sign-in's long-lived access token stops working, 0 when not known or not that kind
 *       'account'    => string,   // who the credential belongs to, when the engine says (Google)
 *       'context'    => array,    // what the engine's test found, handed back to the engine
 *       'checked_on' => int,      // Unix time of the last check, 0 when never
 *       'ok'         => bool,     // whether the last check worked
 *       'message'    => string,   // one line about the last check
 *       'rows'       => array,    // what the last check read, see ConnectionInterface
 *     )
 */
final class Connections {

	public const STATE_OPTION = 'estasoft_nd_connection_state';

	public const MODE_NONE  = 'none';
	public const MODE_KEY   = 'key';
	public const MODE_OAUTH = 'oauth';

	private Plugin $plugin;

	/**
	 * Engines given by a test, or null to find them by folder.
	 *
	 * @var array<int, ConnectionInterface>|null
	 */
	private ?array $given;

	/**
	 * Creates the service.
	 *
	 * @param Plugin                               $plugin  The running plugin.
	 * @param array<int, ConnectionInterface>|null $engines Engines to use instead of the ones found by folder. For tests.
	 */
	public function __construct( Plugin $plugin, ?array $engines = null ) {
		$this->plugin = $plugin;
		$this->given  = $engines;
	}

	/**
	 * The engines, in the order the screen lists them.
	 *
	 * @return array<string, ConnectionInterface> By id.
	 */
	public function all(): array {
		$list = array();

		if ( null !== $this->given ) {
			foreach ( $this->given as $engine ) {
				$list[ $engine->id() ] = $engine;
			}
		} else {
			foreach ( Discovery::classes( 'Integrations/Connections' ) as $class ) {
				$engine = new $class();

				if ( $engine instanceof ConnectionInterface ) {
					$list[ $engine->id() ] = $engine;
				}
			}
		}

		$order = array( 'yandex-webmaster', 'google-search-console', 'bing-webmaster-tools' );

		uksort(
			$list,
			static function ( string $a, string $b ) use ( $order ): int {
				$left  = array_search( $a, $order, true );
				$right = array_search( $b, $order, true );

				return ( false === $left ? 99 : $left ) <=> ( false === $right ? 99 : $right );
			}
		);

		return $list;
	}

	/**
	 * One engine.
	 *
	 * @param string $id Engine id.
	 * @return ConnectionInterface|null
	 */
	public function get( string $id ): ?ConnectionInterface {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * The key of an engine's credential in the secret store.
	 *
	 * @param ConnectionInterface $engine The engine.
	 * @return string
	 */
	public function secret_key( ConnectionInterface $engine ): string {
		return 'connections_' . $engine->secret_field();
	}

	/**
	 * Whether a credential is saved for an engine.
	 *
	 * @param string $id Engine id.
	 * @return bool
	 */
	public function is_connected( string $id ): bool {
		return self::MODE_NONE !== $this->mode( $id );
	}

	/**
	 * How an engine is connected, from which secret is saved.
	 *
	 * @param string $id Engine id.
	 * @return string `key` (the pasted key or token), `oauth` (a sign-in with the person's own app) or `none`.
	 */
	public function mode( string $id ): string {
		$engine = $this->get( $id );

		if ( null === $engine ) {
			return self::MODE_NONE;
		}

		if ( $this->plugin->secrets()->has( $this->secret_key( $engine ) ) ) {
			return self::MODE_KEY;
		}

		$provider = Providers::for_engine( $id );

		if ( null !== $provider && $this->plugin->secrets()->has( (string) $provider['token_key'] ) ) {
			return self::MODE_OAUTH;
		}

		return self::MODE_NONE;
	}

	/**
	 * The client ID and client secret of the person's own app, for a request. Never print the secret.
	 *
	 * @param array<string, mixed> $provider The provider (see `Providers`).
	 * @return array{id: string, secret: string}
	 */
	public function client( array $provider ): array {
		$section = $this->plugin->settings()->section( 'connections' );

		return array(
			'id'     => trim( (string) ( $section[ (string) $provider['client_id_field'] ] ?? '' ) ),
			'secret' => $this->plugin->secrets()->get( 'connections_' . $provider['client_secret_field'] ),
		);
	}

	/**
	 * What a screen may know about the person's own app: the client ID, and whether a client secret is saved. Not the secret.
	 *
	 * @param string $id Engine id.
	 * @return array{provider: array<string, mixed>|null, id: string, has_secret: bool}
	 */
	public function app( string $id ): array {
		$provider = Providers::for_engine( $id );

		if ( null === $provider ) {
			return array(
				'provider'   => null,
				'id'         => '',
				'has_secret' => false,
			);
		}

		$section = $this->plugin->settings()->section( 'connections' );

		return array(
			'provider'   => $provider,
			'id'         => trim( (string) ( $section[ (string) $provider['client_id_field'] ] ?? '' ) ),
			'has_secret' => $this->plugin->secrets()->has( 'connections_' . $provider['client_secret_field'] ),
		);
	}

	/**
	 * What is known about an engine's connection.
	 *
	 * @param string $id Engine id.
	 * @return array{saved_on: int, expires_on: int, account: string, context: array<string, mixed>, checked_on: int, ok: bool, message: string, rows: array<int, array<string, string>>}
	 */
	public function state( string $id ): array {
		$all = get_option( self::STATE_OPTION, array() );
		$row = is_array( $all ) && isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array();

		return array(
			'saved_on'   => (int) ( $row['saved_on'] ?? 0 ),
			'expires_on' => (int) ( $row['expires_on'] ?? 0 ),
			'account'    => (string) ( $row['account'] ?? '' ),
			'context'    => (array) ( $row['context'] ?? array() ),
			'checked_on' => (int) ( $row['checked_on'] ?? 0 ),
			'ok'         => ! empty( $row['ok'] ),
			'message'    => (string) ( $row['message'] ?? '' ),
			'rows'       => (array) ( $row['rows'] ?? array() ),
		);
	}

	/**
	 * Connects: tests the credential at the engine, and saves it only when the engine accepts it.
	 *
	 * @param string               $id    Engine id.
	 * @param array<string, mixed> $input The credential under the engine's secret field name, and its plain fields.
	 * @return array{ok: bool, message: string}|\WP_Error An error for a missing credential or an engine that is not known.
	 */
	public function connect( string $id, array $input ) {
		$engine = $this->get( $id );

		if ( null === $engine ) {
			return new \WP_Error( 'estasoft_nd_unknown_connection', __( 'That connection does not exist.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		if ( self::MODE_OAUTH === $this->mode( $id ) ) {
			return new \WP_Error(
				'estasoft_nd_connected_other_way',
				__( 'This account is signed in with your own app. Press Disconnect first, then connect with a key.', 'estasoft-news-distribution' ),
				array( 'status' => 409 )
			);
		}

		$field      = $engine->secret_field();
		$credential = isset( $input[ $field ] ) && is_scalar( $input[ $field ] ) ? trim( (string) $input[ $field ] ) : '';

		if ( '' === $credential ) {
			return new \WP_Error(
				'estasoft_nd_missing_credential',
				__( 'Paste the access credential first.', 'estasoft-news-distribution' ),
				array(
					'status' => 400,
					'fields' => array( $field => __( 'Paste the access credential first.', 'estasoft-news-distribution' ) ),
				)
			);
		}

		$settings = $this->plain_settings( $engine );

		foreach ( $engine->plain_fields() as $plain ) {
			if ( isset( $input[ $plain ] ) && is_scalar( $input[ $plain ] ) && '' !== trim( (string) $input[ $plain ] ) ) {
				$settings[ $plain ] = trim( (string) $input[ $plain ] );
			}
		}

		$result = $engine->verify( $credential, $settings );

		if ( ! $result['ok'] ) {
			return array(
				'ok'      => false,
				'message' => $result['message'],
			);
		}

		$save = array( $field => $credential );

		foreach ( $engine->plain_fields() as $plain ) {
			if ( isset( $settings[ $plain ] ) ) {
				$save[ $plain ] = $settings[ $plain ];
			}
		}

		$saved = $this->plugin->settings()->save_section( 'connections', $save );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$this->put_state(
			$id,
			array(
				'saved_on'   => Clock::now(),
				'expires_on' => 0,
				'account'    => $result['account'],
				'context'    => $result['context'],
				'checked_on' => Clock::now(),
				'ok'         => true,
				'message'    => $result['message'],
				'rows'       => array(),
			)
		);

		$this->plugin->audit()->record(
			Audit::SETTINGS,
			0,
			sprintf(
				/* translators: %s: the name of a search engine service, such as "Yandex Webmaster". */
				__( 'Connected %s.', 'estasoft-news-distribution' ),
				$engine->label()
			)
		);

		return array(
			'ok'      => true,
			'message' => $result['message'],
		);
	}

	/**
	 * Saves the client ID and client secret of the person's own app.
	 *
	 * Called by the sign-in button before it sends the person to the provider. The client secret is a
	 * write-only secret: an empty value keeps the saved one. Nothing is sent to anyone.
	 *
	 * @param string               $id    Engine id.
	 * @param array<string, mixed> $input The provider's `client_id_field` and `client_secret_field`.
	 * @return array{ok: bool}|\WP_Error An error for a connection that has no sign-in, one that is connected already, or a value that does not fit.
	 */
	public function save_app( string $id, array $input ) {
		$provider = Providers::for_engine( $id );

		if ( null === $provider || null === $this->get( $id ) ) {
			return new \WP_Error( 'estasoft_nd_no_sign_in', __( 'That connection has no sign-in. Connect it with its key.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		if ( self::MODE_NONE !== $this->mode( $id ) ) {
			return new \WP_Error( 'estasoft_nd_already_connected', __( 'This account is connected. Press Disconnect first to use another app.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		$save = array();

		foreach ( array( 'client_id_field', 'client_secret_field' ) as $key ) {
			$field = (string) $provider[ $key ];

			if ( isset( $input[ $field ] ) && is_scalar( $input[ $field ] ) ) {
				$save[ $field ] = trim( (string) $input[ $field ] );
			}
		}

		if ( array() === $save ) {
			return array( 'ok' => true );
		}

		$saved = $this->plugin->settings()->save_section( 'connections', $save );

		return is_wp_error( $saved ) ? $saved : array( 'ok' => true );
	}

	/**
	 * Removes the client ID and client secret of the person's own app. Only while the account is not connected.
	 *
	 * @param string $id Engine id.
	 * @return bool False when the connection has no sign-in or is connected.
	 */
	public function forget_app( string $id ): bool {
		$provider = Providers::for_engine( $id );

		if ( null === $provider || self::MODE_NONE !== $this->mode( $id ) ) {
			return false;
		}

		$this->plugin->secrets()->delete( 'connections_' . $provider['client_secret_field'] );
		$this->plugin->settings()->save_section( 'connections', array( (string) $provider['client_id_field'] => '' ) );

		return true;
	}

	/**
	 * Takes the tokens a finished sign-in gave: tests them at the engine, and keeps them only when the engine accepts them.
	 *
	 * The long-lived token is kept as a secret and never returned. A short-lived access token that came with it
	 * is used for the test and for the next hour (less 60 seconds) without asking again.
	 *
	 * @param string                                                                                  $id     Engine id.
	 * @param array{access: string, refresh: string, expires_in: int, scope?: string, error?: string} $tokens What the provider gave.
	 * @return array{ok: bool, message: string}|\WP_Error An error for an engine that has no sign-in or is connected with a key.
	 */
	public function accept_oauth( string $id, array $tokens ) {
		$engine   = $this->get( $id );
		$provider = Providers::for_engine( $id );

		if ( null === $engine || null === $provider || ! $engine instanceof AbstractConnection ) {
			return new \WP_Error( 'estasoft_nd_no_sign_in', __( 'That connection has no sign-in. Connect it with its key.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		if ( self::MODE_KEY === $this->mode( $id ) ) {
			return new \WP_Error( 'estasoft_nd_connected_other_way', __( 'This account is connected with a key. Press Disconnect first, then sign in.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		$now     = Clock::now();
		$access  = (string) $tokens['access'];
		$refresh = (string) $tokens['refresh'];
		$life    = max( 0, (int) $tokens['expires_in'] );
		$kind    = '' !== $refresh ? OAuthRefresh::KIND_REFRESH : OAuthRefresh::KIND_ACCESS;
		$keep    = OAuthRefresh::KIND_REFRESH === $kind ? $refresh : $access;
		$expires = OAuthRefresh::KIND_ACCESS === $kind && $life > 0 ? $now + $life : 0;
		$client  = $this->client( $provider );
		$source  = new OAuthRefresh(
			$provider,
			$client['id'],
			$client['secret'],
			$keep,
			$kind,
			$expires,
			array(
				'token' => $access,
				'until' => $now + max( 0, $life - 60 ),
			)
		);

		$result = $engine->using( $source )->verify( '', $this->plain_settings( $engine ) );

		if ( ! $result['ok'] ) {
			return array(
				'ok'      => false,
				'message' => $result['message'],
			);
		}

		$this->plugin->secrets()->set(
			(string) $provider['token_key'],
			(string) wp_json_encode(
				array(
					'kind'  => $kind,
					'token' => $keep,
				)
			)
		);

		OAuthRefresh::forget( (string) $provider['id'] );

		if ( OAuthRefresh::KIND_REFRESH === $kind ) {
			OAuthRefresh::remember( (string) $provider['id'], $access, $life );
		}

		$this->put_state(
			$id,
			array(
				'saved_on'   => $now,
				'expires_on' => $expires,
				'account'    => $result['account'],
				'context'    => $result['context'],
				'checked_on' => $now,
				'ok'         => true,
				'message'    => $result['message'],
				'rows'       => array(),
			)
		);

		$this->plugin->audit()->record(
			Audit::SETTINGS,
			0,
			sprintf(
				/* translators: %s: the name of a search engine service, such as "Yandex Webmaster". */
				__( 'Connected %s.', 'estasoft-news-distribution' ),
				$engine->label()
			)
		);

		return array(
			'ok'      => true,
			'message' => $result['message'],
		);
	}

	/**
	 * Checks now: submits the sitemaps the engine lacks, then reads what it shows for each.
	 *
	 * @param string $id Engine id.
	 * @return array{ok: bool, message: string, rows: array<int, array<string, string>>}|\WP_Error An error when the engine is not known or not connected.
	 */
	public function check( string $id ) {
		$engine = $this->get( $id );

		if ( null === $engine ) {
			return new \WP_Error( 'estasoft_nd_unknown_connection', __( 'That connection does not exist.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		if ( ! $this->is_connected( $id ) ) {
			return new \WP_Error( 'estasoft_nd_not_connected', __( 'This account is not connected.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		$state    = $this->state( $id );
		$sitemaps = $this->sitemaps_for( $engine );
		$oauth    = self::MODE_OAUTH === $this->mode( $id ) ? $this->oauth_source( $id ) : null;

		if ( array() === $sitemaps ) {
			$result = array(
				'ok'      => false,
				'message' => __( 'No channel that uses this engine is on, so there is no sitemap to submit. Turn on its channel first.', 'estasoft-news-distribution' ),
				'rows'    => array(),
			);
		} else {
			// A sign-in has no pasted credential: the engine asks its source for a token.
			$result = ( null !== $oauth && $engine instanceof AbstractConnection ? $engine->using( $oauth ) : $engine )->sync(
				null !== $oauth ? '' : $this->plugin->secrets()->get( $this->secret_key( $engine ) ),
				$this->plain_settings( $engine ),
				$state['context'],
				$sitemaps
			);
		}

		$state['checked_on'] = Clock::now();
		$state['ok']         = $result['ok'];
		$state['message']    = $result['message'];
		$state['rows']       = $result['rows'];

		$this->put_state( $id, $state );

		return $result;
	}

	/**
	 * Disconnects: removes the saved credential and what was read. No question is asked.
	 *
	 * @param string $id Engine id.
	 * @return bool False when the engine is not known.
	 */
	public function disconnect( string $id ): bool {
		return $this->disconnect_report( $id )['found'];
	}

	/**
	 * Disconnects, and says what happened at the provider.
	 *
	 * A sign-in is cancelled at the provider first, when the provider has an address for that, and
	 * whatever it answers every secret of the connection is removed here: the token, the client secret,
	 * a pasted key, and the access token kept for the next hour. The client ID is plain text and stays,
	 * so signing in again only asks for the secret.
	 *
	 * @param string $id Engine id.
	 * @return array{found: bool, mode: string, revoked: string} `revoked` is `revoked`, `failed`, or `none` (no sign-in, or the provider has no such address).
	 */
	public function disconnect_report( string $id ): array {
		$engine = $this->get( $id );

		if ( null === $engine ) {
			return array(
				'found'   => false,
				'mode'    => self::MODE_NONE,
				'revoked' => 'none',
			);
		}

		$mode     = $this->mode( $id );
		$provider = Providers::for_engine( $id );
		$revoked  = 'none';

		if ( self::MODE_OAUTH === $mode && null !== $provider ) {
			$revoked = ( new Flow( $this->plugin ) )->revoke( $provider, $this->kept_token( $provider )['token'] );
		}

		$this->plugin->secrets()->delete( $this->secret_key( $engine ) );

		if ( null !== $provider ) {
			$this->plugin->secrets()->delete( (string) $provider['token_key'] );
			$this->plugin->secrets()->delete( 'connections_' . $provider['client_secret_field'] );
			OAuthRefresh::forget( (string) $provider['id'] );
		}

		$this->drop_state( $id );

		$this->plugin->audit()->record(
			Audit::SETTINGS,
			0,
			sprintf(
				/* translators: %s: the name of a search engine service, such as "Yandex Webmaster". */
				__( 'Disconnected %s.', 'estasoft-news-distribution' ),
				$engine->label()
			)
		);

		return array(
			'found'   => true,
			'mode'    => $mode,
			'revoked' => $revoked,
		);
	}

	/**
	 * The sentence that tells a person what Disconnect did.
	 *
	 * @param string                                               $label  The connection's name.
	 * @param array{found?: bool, mode?: string, revoked?: string} $report What `disconnect_report()` gave.
	 * @return string
	 */
	public static function disconnect_text( string $label, array $report ): string {
		if ( self::MODE_OAUTH !== ( $report['mode'] ?? '' ) ) {
			return sprintf(
				/* translators: %s: the service's name. */
				__( 'Disconnected from %s. The saved credential was removed.', 'estasoft-news-distribution' ),
				$label
			);
		}

		if ( 'revoked' === ( $report['revoked'] ?? '' ) ) {
			return sprintf(
				/* translators: %s: the service's name. */
				__( 'Disconnected from %s. The sign-in was cancelled there, and the saved sign-in and client secret were removed from your site.', 'estasoft-news-distribution' ),
				$label
			);
		}

		if ( 'failed' === ( $report['revoked'] ?? '' ) ) {
			return sprintf(
				/* translators: %s: the service's name. */
				__( 'Disconnected from %s. The saved sign-in and client secret were removed from your site, but the sign-in could not be cancelled there. You can remove the access in your account there.', 'estasoft-news-distribution' ),
				$label
			);
		}

		return sprintf(
			/* translators: %s: the service's name. */
			__( 'Disconnected from %s. The saved sign-in and client secret were removed from your site. This service has no address for cancelling a sign-in, so you can also remove the access in your account there.', 'estasoft-news-distribution' ),
			$label
		);
	}

	/**
	 * The sitemaps an engine is given: those of the channels that use it and are on.
	 *
	 * @param ConnectionInterface $engine The engine.
	 * @return array<int, array{label: string, url: string}>
	 */
	public function sitemaps_for( ConnectionInterface $engine ): array {
		$list = array();

		foreach ( $engine->channel_ids() as $channel_id ) {
			$channel = $this->plugin->registry()->get( $channel_id );

			if ( ! $channel instanceof ChannelInterface || ! $channel->is_enabled() || '' === $channel->feed_slug() ) {
				continue;
			}

			$kind = 'google-news' === $channel_id
				? __( 'News sitemap', 'estasoft-news-distribution' )
				: __( 'Search sitemap', 'estasoft-news-distribution' );

			foreach ( $this->plugin->languages()->languages() as $language ) {
				if ( ! $channel->accepts_language( (string) $language ) ) {
					continue;
				}

				$url = add_query_arg( 'lang', rawurlencode( (string) $language ), home_url( '/feed/' . $channel->feed_slug() . '/' ) );

				$list[ $url ] = array(
					'label' => sprintf(
						/* translators: 1: kind of sitemap, such as "Search sitemap", 2: a language name. */
						__( '%1$s, %2$s', 'estasoft-news-distribution' ),
						$kind,
						Catalog::name( (string) $language )
					),
					'url'   => $url,
				);
			}
		}

		return array_values( $list );
	}

	/**
	 * The token a sign-in left, and which kind it is.
	 *
	 * @param array<string, mixed> $provider The provider (see `Providers`).
	 * @return array{kind: string, token: string} The token is empty when there is none.
	 */
	private function kept_token( array $provider ): array {
		$stored = json_decode( $this->plugin->secrets()->get( (string) $provider['token_key'] ), true );
		$token  = is_array( $stored ) && isset( $stored['token'] ) && is_string( $stored['token'] ) ? $stored['token'] : '';
		$kind   = is_array( $stored ) && OAuthRefresh::KIND_ACCESS === ( $stored['kind'] ?? '' ) ? OAuthRefresh::KIND_ACCESS : OAuthRefresh::KIND_REFRESH;

		return array(
			'kind'  => $kind,
			'token' => $token,
		);
	}

	/**
	 * Where a signed-in connection gets its token.
	 *
	 * @param string $id Engine id.
	 * @return OAuthRefresh|null Null when the engine has no sign-in or none is saved.
	 */
	private function oauth_source( string $id ): ?OAuthRefresh {
		$provider = Providers::for_engine( $id );

		if ( null === $provider ) {
			return null;
		}

		$kept = $this->kept_token( $provider );

		if ( '' === $kept['token'] ) {
			return null;
		}

		$client  = $this->client( $provider );
		$secrets = $this->plugin->secrets();
		$key     = (string) $provider['token_key'];

		return new OAuthRefresh(
			$provider,
			$client['id'],
			$client['secret'],
			$kept['token'],
			$kept['kind'],
			$this->state( $id )['expires_on'],
			null,
			static function ( string $replacement ) use ( $secrets, $key ): void {
				// The provider replaced the refresh token with a new one: keep the new one.
				$secrets->set(
					$key,
					(string) wp_json_encode(
						array(
							'kind'  => OAuthRefresh::KIND_REFRESH,
							'token' => $replacement,
						)
					)
				);
			}
		);
	}

	/**
	 * The plain fields of an engine, with defaults filled in.
	 *
	 * @param ConnectionInterface $engine The engine.
	 * @return array<string, mixed>
	 */
	private function plain_settings( ConnectionInterface $engine ): array {
		$section = $this->plugin->settings()->section( 'connections' );
		$out     = array();

		foreach ( $engine->plain_fields() as $field ) {
			$out[ $field ] = $section[ $field ] ?? '';
		}

		return $out;
	}

	/**
	 * Keeps one engine's state.
	 *
	 * @param string               $id    Engine id.
	 * @param array<string, mixed> $state The state.
	 * @return void
	 */
	private function put_state( string $id, array $state ): void {
		$all = get_option( self::STATE_OPTION, array() );
		$all = is_array( $all ) ? $all : array();

		$all[ $id ] = $state;
		update_option( self::STATE_OPTION, $all, false );
	}

	/**
	 * Forgets one engine's state.
	 *
	 * @param string $id Engine id.
	 * @return void
	 */
	private function drop_state( string $id ): void {
		$all = get_option( self::STATE_OPTION, array() );

		if ( is_array( $all ) && isset( $all[ $id ] ) ) {
			unset( $all[ $id ] );
			update_option( self::STATE_OPTION, $all, false );
		}
	}
}

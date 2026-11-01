<?php
/**
 * The token a sign-in with the person's own app gives, trading a kept token for a short-lived one.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations\OAuth;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * What a sign-in (`Flow`) leaves is one long-lived token, kept as a write-only secret:
 *
 * - `refresh`: a refresh token (Google always; Yandex when it gives one). Each time a connection needs
 *   a bearer token, this trades it for an access token, and keeps that access token in a
 *   transient for no longer than its life minus 60 seconds.
 * - `access`: an access token that lives long (Yandex without a refresh token). It is the bearer
 *   token, used until the day it expires, which is kept with the connection's state.
 *
 * The refresh request follows the OAuth 2.0 refresh token grant (`grant_type=refresh_token`).
 * Not run against Google or Yandex.
 */
final class OAuthRefresh implements CredentialSource {

	public const KIND_REFRESH = 'refresh';
	public const KIND_ACCESS  = 'access';

	/** The name the short-lived access token is kept under, in the sealed store (Core\Secrets) like the refresh token. */
	private const CACHE = 'oauth_at_';

	/**
	 * The provider, as `Providers::get()` gives it.
	 *
	 * @var array<string, mixed>
	 */
	private array $provider;

	private string $client_id;

	private string $client_secret;

	private string $token;

	private string $token_kind;

	private int $expires_on;

	/**
	 * An access token just received, so the first use needs no request.
	 *
	 * @var array{token: string, until: int}|null
	 */
	private ?array $fresh;

	/**
	 * Called with a new refresh token when the provider gives one in place of the old.
	 *
	 * @var callable|null
	 */
	private $rotated;

	/**
	 * Creates the source.
	 *
	 * @param array<string, mixed>                  $provider      The provider (see `Providers`).
	 * @param string                                $client_id     The owner's client ID.
	 * @param string                                $client_secret The owner's client secret.
	 * @param string                                $token         The kept token.
	 * @param string                                $token_kind    `refresh` or `access`.
	 * @param int                                   $expires_on    Unix time an `access` token stops working, or 0 when not known.
	 * @param array{token: string, until: int}|null $fresh         An access token just received.
	 * @param callable|null                         $rotated       Called with a new refresh token.
	 */
	public function __construct( array $provider, string $client_id, string $client_secret, string $token, string $token_kind, int $expires_on = 0, ?array $fresh = null, ?callable $rotated = null ) {
		$this->provider      = $provider;
		$this->client_id     = $client_id;
		$this->client_secret = $client_secret;
		$this->token         = $token;
		$this->token_kind    = self::KIND_ACCESS === $token_kind ? self::KIND_ACCESS : self::KIND_REFRESH;
		$this->expires_on    = $expires_on;
		$this->fresh         = $fresh;
		$this->rotated       = $rotated;
	}

	public function kind(): string {
		return 'oauth';
	}

	public function account(): string {
		return '';
	}

	public function bearer( Transport $transport ): array {
		$now   = Clock::now();
		$label = (string) $this->provider['label'];

		if ( null !== $this->fresh && $this->fresh['until'] > $now ) {
			return $this->got( $this->fresh['token'] );
		}

		if ( self::KIND_ACCESS === $this->token_kind ) {
			if ( $this->expires_on > 0 && $now >= $this->expires_on ) {
				return $this->lacking(
					sprintf(
						/* translators: %s: the name of a sign-in service, such as "Yandex". */
						__( 'Your sign-in with %s has expired. Press Sign in again.', 'estasoft-news-distribution' ),
						$label
					)
				);
			}

			return $this->got( $this->token );
		}

		$cached = self::cached( (string) $this->provider['id'] );

		if ( '' !== $cached ) {
			return $this->got( $cached );
		}

		$answer = $transport->send(
			'POST',
			(string) $this->provider['token_url'],
			array(),
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $this->token,
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
			)
		);

		if ( 200 === $answer['code'] && ! empty( $answer['json']['access_token'] ) && is_string( $answer['json']['access_token'] ) ) {
			self::remember( (string) $this->provider['id'], $answer['json']['access_token'], (int) ( $answer['json']['expires_in'] ?? 0 ) );

			$new = isset( $answer['json']['refresh_token'] ) && is_string( $answer['json']['refresh_token'] ) ? $answer['json']['refresh_token'] : '';

			if ( '' !== $new && $new !== $this->token && null !== $this->rotated ) {
				call_user_func( $this->rotated, $new );
				$this->token = $new;
			}

			return $this->got( $answer['json']['access_token'] );
		}

		$code = isset( $answer['json']['error'] ) && is_string( $answer['json']['error'] ) ? $answer['json']['error'] : '';

		if ( in_array( $answer['code'], array( 400, 401 ), true ) && 'invalid_grant' === $code ) {
			return $this->lacking(
				sprintf(
					/* translators: %s: the name of a sign-in service, such as "Google". */
					__( '%s no longer accepts the saved sign-in. It may have expired, or been removed in your account there. Press Sign in again.', 'estasoft-news-distribution' ),
					$label
				)
			);
		}

		if ( in_array( $answer['code'], array( 400, 401 ), true ) && in_array( $code, array( 'invalid_client', 'unauthorized_client' ), true ) ) {
			return $this->lacking(
				sprintf(
					/* translators: %s: the name of a sign-in service, such as "Google". */
					__( '%s did not accept the client ID and client secret. Check them, then press Sign in again.', 'estasoft-news-distribution' ),
					$label
				)
			);
		}

		return $this->lacking(
			$transport->failure(
				$answer,
				sprintf(
					/* translators: %s: the name of a sign-in service, such as "Google". */
					__( '%s did not accept the saved sign-in. Press Sign in again.', 'estasoft-news-distribution' ),
					$label
				)
			)
		);
	}

	/**
	 * Keeps an access token for less than its life: its life minus 60 seconds. A life of a minute or less is not kept.
	 *
	 * @param string $provider_id `google` or `yandex`.
	 * @param string $token      The access token.
	 * @param int    $expires_in Seconds it lives, as the provider said.
	 * @return void
	 */
	public static function remember( string $provider_id, string $token, int $expires_in ): void {
		$life = $expires_in - 60;

		if ( $life <= 0 || '' === $token ) {
			return;
		}

		// Sealed like the sign-in token itself, with the time it stops being good: it is never kept as plain text.
		Plugin::instance()->secrets()->set(
			self::CACHE . $provider_id,
			(string) wp_json_encode(
				array(
					'token' => $token,
					'until' => Clock::now() + $life,
				)
			)
		);
	}

	/**
	 * The access token kept for a provider, if it is still good.
	 *
	 * @param string $provider_id `google` or `yandex`.
	 * @return string Empty when there is none.
	 */
	public static function cached( string $provider_id ): string {
		$kept = json_decode( Plugin::instance()->secrets()->get( self::CACHE . $provider_id ), true );

		if ( is_array( $kept ) && isset( $kept['token'], $kept['until'] ) && is_string( $kept['token'] ) && (int) $kept['until'] > Clock::now() ) {
			return $kept['token'];
		}

		return '';
	}

	/**
	 * Forgets the kept access token, so the next use asks for a new one.
	 *
	 * @param string $provider_id `google` or `yandex`.
	 * @return void
	 */
	public static function forget( string $provider_id ): void {
		Plugin::instance()->secrets()->delete( self::CACHE . $provider_id );
	}

	/**
	 * A token.
	 *
	 * @param string $token The bearer token.
	 * @return array{token: string, error: string}
	 */
	private function got( string $token ): array {
		return array(
			'token' => $token,
			'error' => '',
		);
	}

	/**
	 * No token, and why.
	 *
	 * @param string $reason A sentence for a person.
	 * @return array{token: string, error: string}
	 */
	private function lacking( string $reason ): array {
		return array(
			'token' => '',
			'error' => $reason,
		);
	}
}

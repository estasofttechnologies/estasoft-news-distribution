<?php
/**
 * A Google service account key, traded for a short-lived access token.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations\OAuth;

use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * The first way to connect Google Search Console: the person creates a service account in their
 * own Google Cloud project, makes a JSON key, and adds the service account as a user of the
 * property in Search Console. The key signs a request for an access token
 * (`urn:ietf:params:oauth:grant-type:jwt-bearer`, RS256, scope
 * `https://www.googleapis.com/auth/webmasters`); the token is used for this one click and not kept.
 *
 * The token request and the key file's fields (`client_email`, `private_key`) follow Google's
 * service account guide and were not run against Google.
 */
final class ServiceAccountKey implements CredentialSource {

	private string $email = '';

	private string $private_key = '';

	private string $problem = '';

	/**
	 * Creates the source from the key file's text.
	 *
	 * @param string $json The key file's text.
	 */
	public function __construct( string $json ) {
		$key = json_decode( $json, true );

		if ( ! is_array( $key ) || empty( $key['client_email'] ) || empty( $key['private_key'] ) || 'service_account' !== ( $key['type'] ?? 'service_account' ) ) {
			$this->problem = __( 'That is not a service account key. Create a key of type JSON for a service account in Google Cloud and paste all of the file here.', 'estasoft-news-distribution' );

			return;
		}

		if ( ! function_exists( 'openssl_sign' ) ) {
			$this->problem = __( 'This server has no OpenSSL support in PHP, which signing the request needs. Ask your host to turn it on.', 'estasoft-news-distribution' );

			return;
		}

		$this->email       = (string) $key['client_email'];
		$this->private_key = (string) $key['private_key'];
	}

	public function kind(): string {
		return 'key';
	}

	public function account(): string {
		return $this->email;
	}

	public function bearer( Transport $transport ): array {
		if ( '' !== $this->problem ) {
			return array(
				'token' => '',
				'error' => $this->problem,
			);
		}

		$now    = Clock::now();
		$header = $this->base64url(
			(string) wp_json_encode(
				array(
					'alg' => 'RS256',
					'typ' => 'JWT',
				)
			)
		);
		$claims = $this->base64url(
			(string) wp_json_encode(
				array(
					'iss'   => $this->email,
					'scope' => Providers::GOOGLE_SCOPE,
					'aud'   => Providers::GOOGLE_TOKEN_URL,
					'iat'   => $now,
					'exp'   => $now + HOUR_IN_SECONDS,
				)
			)
		);

		$signature = '';
		$private   = openssl_pkey_get_private( $this->private_key );

		if ( false === $private || ! openssl_sign( $header . '.' . $claims, $signature, $private, OPENSSL_ALGO_SHA256 ) ) {
			return array(
				'token' => '',
				'error' => __( 'The private key in that file could not be read. Create a new JSON key and paste all of the file here.', 'estasoft-news-distribution' ),
			);
		}

		$answer = $transport->send(
			'POST',
			Providers::GOOGLE_TOKEN_URL,
			array(),
			array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $header . '.' . $claims . '.' . $this->base64url( $signature ),
			)
		);

		if ( 200 !== $answer['code'] || empty( $answer['json']['access_token'] ) ) {
			return array(
				'token' => '',
				'error' => $transport->failure( $answer, __( 'Google did not accept the key. Check that the service account still exists and that the key was not deleted.', 'estasoft-news-distribution' ) ),
			);
		}

		return array(
			'token' => (string) $answer['json']['access_token'],
			'error' => '',
		);
	}

	/**
	 * Base64 with the URL-safe alphabet and no padding, as a signed token needs.
	 *
	 * @param string $data Bytes.
	 * @return string
	 */
	private function base64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Signing a token, not hiding data.
	}
}

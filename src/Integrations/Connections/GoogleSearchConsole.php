<?php
/**
 * Google Search Console.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations\Connections;

use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Integrations\OAuth\CredentialSource;
use Estasoft\NewsDistribution\Integrations\OAuth\ServiceAccountKey;

defined( 'ABSPATH' ) || exit;

/**
 * Submits the site's sitemaps in Search Console and reads what Google shows
 * for each, through the Search Console API (`sitemaps` resource).
 *
 * There are two ways to connect, and the person picks one:
 *
 * - A service account key (a JSON file) the person creates in Google Cloud and adds
 *   as a user of the property in Search Console. This is `OAuth\ServiceAccountKey`: the
 *   key signs a short-lived request for an access token
 *   (`urn:ietf:params:oauth:grant-type:jwt-bearer`, RS256, scope
 *   `https://www.googleapis.com/auth/webmasters`); the token is used for this one
 *   click and not kept.
 * - A sign-in with the owner's own Google app ("bring your own app", see
 *   `OAuth\Flow`). The plugin has no registered Google app, so there is no
 *   "sign in" button that works out of the box. This is `OAuth\OAuthRefresh`.
 *
 * The connection asks its `CredentialSource` for the bearer token and does not care which. The
 * `sitemaps` resource fields `path`, `lastSubmitted`, `lastDownloaded`,
 * `isPending`, `errors` and `warnings` were read in Google's API reference on
 * 22 Oct 2026. The token request and the key file's fields (`client_email`,
 * `private_key`) follow Google's service account guide and were not run
 * against Google.
 */
final class GoogleSearchConsole extends AbstractConnection {

	private const API = 'https://www.googleapis.com/webmasters/v3/sites/';

	public function id(): string {
		return 'google-search-console';
	}

	public function label(): string {
		return 'Google Search Console';
	}

	public function secret_field(): string {
		return 'google_key';
	}

	public function plain_fields(): array {
		return array( 'google_property' );
	}

	public function channel_ids(): array {
		return array( 'google-news', 'bing', 'yandex' );
	}

	protected function default_source( string $credential ): CredentialSource {
		return new ServiceAccountKey( $credential );
	}

	public function verify( string $credential, array $settings ): array {
		$source = $this->source( $credential );
		$token  = $source->bearer( $this->transport() );

		if ( '' !== $token['error'] ) {
			return $this->refused( $token['error'] );
		}

		$property = $this->property( $settings );
		$answer   = $this->request( 'GET', $this->sitemaps_url( $property ), $this->headers( $token['token'] ) );

		if ( 200 !== $answer['code'] ) {
			return $this->refused(
				in_array( $answer['code'], array( 403, 404 ), true )
					? $this->no_access( $source, $property )
					: $this->failure( $answer, $this->denied( $source ) )
			);
		}

		return array(
			'ok'      => true,
			'message' => 'oauth' === $source->kind()
				? __( 'Google accepted the sign-in.', 'estasoft-news-distribution' )
				: __( 'Google accepted the key.', 'estasoft-news-distribution' ),
			'account' => $source->account(),
			'context' => array(),
		);
	}

	public function sync( string $credential, array $settings, array $context, array $sitemaps ): array {
		$source = $this->source( $credential );
		$token  = $source->bearer( $this->transport() );

		if ( '' !== $token['error'] ) {
			return $this->stopped( $token['error'] );
		}

		$property = $this->property( $settings );
		$headers  = $this->headers( $token['token'] );
		$list     = $this->request( 'GET', $this->sitemaps_url( $property ), $headers );
		$read     = 'oauth' === $source->kind()
			? __( 'Google did not let this account read sitemaps for this property.', 'estasoft-news-distribution' )
			: __( 'Google did not let the service account read sitemaps for this property.', 'estasoft-news-distribution' );

		if ( 200 !== $list['code'] ) {
			return $this->stopped( $this->failure( $list, $read ) );
		}

		$known = $this->index( $list );

		foreach ( $sitemaps as $sitemap ) {
			if ( isset( $known[ $sitemap['url'] ] ) ) {
				continue;
			}

			$put = $this->request( 'PUT', $this->sitemaps_url( $property ) . '/' . rawurlencode( $sitemap['url'] ), $headers );

			if ( $put['code'] < 200 || $put['code'] > 299 ) {
				return $this->stopped(
					$this->failure(
						$put,
						'oauth' === $source->kind()
							? __( 'Google did not let this account submit sitemaps. It needs the Owner or Full permission on the property in Search Console.', 'estasoft-news-distribution' )
							: __( 'Google did not let the service account submit sitemaps. Give it the Owner or Full permission on the property.', 'estasoft-news-distribution' )
					)
				);
			}
		}

		$list = $this->request( 'GET', $this->sitemaps_url( $property ), $headers );

		if ( 200 !== $list['code'] ) {
			return $this->stopped( $this->failure( $list, $read ) );
		}

		$known = $this->index( $list );
		$rows  = array();

		foreach ( $sitemaps as $sitemap ) {
			$rows[] = $this->describe( $sitemap, $known[ $sitemap['url'] ] ?? null );
		}

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: %d: number of sitemaps. */
				_n( '%d sitemap read', '%d sitemaps read', count( $rows ), 'estasoft-news-distribution' ),
				count( $rows )
			),
			'rows'    => $rows,
		);
	}

	/**
	 * What to say when Google gives no access to the property.
	 *
	 * @param CredentialSource $source   How the person connected.
	 * @param string           $property The property.
	 * @return string
	 */
	private function no_access( CredentialSource $source, string $property ): string {
		if ( 'oauth' === $source->kind() ) {
			return sprintf(
				/* translators: %s: a Search Console property such as https://example.com/. */
				__( 'Search Console does not give access to %s. Sign in with a Google account that is an owner or a user of that property in Search Console.', 'estasoft-news-distribution' ),
				$property
			);
		}

		return sprintf(
			/* translators: 1: a Search Console property such as https://example.com/, 2: the service account's email address. */
			__( 'Search Console does not give access to %1$s. In Search Console, open Settings, Users and permissions, and add %2$s as a user of that property.', 'estasoft-news-distribution' ),
			$property,
			$source->account()
		);
	}

	/**
	 * What to say when Google refuses the token.
	 *
	 * @param CredentialSource $source How the person connected.
	 * @return string
	 */
	private function denied( CredentialSource $source ): string {
		return 'oauth' === $source->kind()
			? __( 'Google did not accept the sign-in. Press Sign in again.', 'estasoft-news-distribution' )
			: __( 'Google did not accept the key.', 'estasoft-news-distribution' );
	}

	/**
	 * The headers every request carries.
	 *
	 * @param string $token The access token.
	 * @return array<string, string>
	 */
	private function headers( string $token ): array {
		return array( 'Authorization' => 'Bearer ' . $token );
	}

	/**
	 * The Search Console property: the saved one, or this site's home address.
	 *
	 * @param array<string, mixed> $settings The plain fields.
	 * @return string
	 */
	private function property( array $settings ): string {
		$property = trim( (string) ( $settings['google_property'] ?? '' ) );

		return '' !== $property ? $property : trailingslashit( home_url() );
	}

	/**
	 * The address of the sitemaps resource of a property.
	 *
	 * @param string $property The property.
	 * @return string
	 */
	private function sitemaps_url( string $property ): string {
		return self::API . rawurlencode( $property ) . '/sitemaps';
	}

	/**
	 * The sitemaps Google lists, by address.
	 *
	 * @param array{code: int, json: array<mixed>|null, raw: string, error: string} $answer The list answer.
	 * @return array<string, array<string, mixed>>
	 */
	private function index( array $answer ): array {
		$by_path = array();

		foreach ( (array) ( $answer['json']['sitemap'] ?? array() ) as $item ) {
			if ( is_array( $item ) && isset( $item['path'] ) ) {
				$by_path[ (string) $item['path'] ] = $item;
			}
		}

		return $by_path;
	}

	/**
	 * A failed test.
	 *
	 * @param string $message What went wrong.
	 * @return array{ok: bool, message: string, account: string, context: array<string, mixed>}
	 */
	private function refused( string $message ): array {
		return array(
			'ok'      => false,
			'message' => $message,
			'account' => '',
			'context' => array(),
		);
	}

	/**
	 * A check that stopped.
	 *
	 * @param string $message What went wrong.
	 * @return array{ok: bool, message: string, rows: array<int, array<string, string>>}
	 */
	private function stopped( string $message ): array {
		return array(
			'ok'      => false,
			'message' => $message,
			'rows'    => array(),
		);
	}

	/**
	 * One row, from what Google lists for the sitemap.
	 *
	 * @param array{label: string, url: string} $sitemap The sitemap.
	 * @param array<string, mixed>|null         $item    Google's record of it.
	 * @return array{label: string, url: string, says: string, status: string, link: string}
	 */
	private function describe( array $sitemap, ?array $item ): array {
		$link = 'https://search.google.com/search-console/sitemaps';

		if ( null === $item ) {
			return $this->row( $sitemap, __( 'Google does not list it yet', 'estasoft-news-distribution' ), Status::PROCESSING );
		}

		$errors     = (int) ( $item['errors'] ?? 0 );
		$warnings   = (int) ( $item['warnings'] ?? 0 );
		$downloaded = empty( $item['lastDownloaded'] ) ? 0 : (int) strtotime( (string) $item['lastDownloaded'] );

		if ( $errors > 0 ) {
			return $this->row(
				$sitemap,
				sprintf(
					/* translators: %d: number of errors. */
					_n( 'Google found %d error', 'Google found %d errors', $errors, 'estasoft-news-distribution' ),
					$errors
				),
				Status::ATTENTION,
				$link
			);
		}

		if ( ! empty( $item['isPending'] ) || 0 === $downloaded ) {
			return $this->row( $sitemap, __( 'Submitted, not read yet', 'estasoft-news-distribution' ), Status::PROCESSING );
		}

		$says = sprintf(
			/* translators: %s: a date such as "14 Oct". */
			__( 'Read on %s', 'estasoft-news-distribution' ),
			$this->day( $downloaded )
		);

		if ( $warnings > 0 ) {
			$says .= ', ' . sprintf(
				/* translators: %d: number of warnings. */
				_n( '%d warning', '%d warnings', $warnings, 'estasoft-news-distribution' ),
				$warnings
			);
		}

		return $this->row( $sitemap, $says, Status::HEALTHY, $warnings > 0 ? $link : '' );
	}
}

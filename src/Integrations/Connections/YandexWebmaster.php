<?php
/**
 * Yandex Webmaster.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations\Connections;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Integrations\OAuth\CredentialSource;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the site's search sitemaps in Yandex Webmaster and reads what Yandex
 * knows about each one, through the Yandex Webmaster API (v4).
 *
 * There are two ways to connect, and the person picks one: an OAuth access token
 * the person makes in their own Yandex account and pastes in (`OAuth\PastedToken`),
 * or a sign-in with the owner's own Yandex app (`OAuth\Flow` and
 * `OAuth\OAuthRefresh`; the plugin has no registered Yandex app, so there is no
 * "sign in" button that works out of the box). The connection asks its
 * `CredentialSource` for the token and does not care which; both are sent as
 * `Authorization: OAuth <token>`. Endpoints and response fields, as read in
 * Yandex's reference on 22 Oct 2026:
 *
 * - `GET /v4/user` gives the user ID (the field name `user_id` is from Yandex's
 *   guide and was not read on the reference page).
 * - `GET /v4/user/{user-id}/hosts` gives `hosts[]` with `host_id`, `ascii_host_url`, `verified`.
 * - `GET /v4/user/{user-id}/hosts/{host-id}/user-added-sitemaps` gives `sitemaps[]` with `sitemap_id`, `sitemap_url`, `added_date`.
 * - `POST` to the same address with `{"url": ...}` adds one (201, `sitemap_id`).
 * - `GET /v4/user/{user-id}/hosts/{host-id}/sitemaps` gives the files the robot found, with `sitemap_url`, `last_access_date`, `errors_count`, `urls_count`.
 *
 * Yandex gives no status word for a sitemap. This maps what it does give: a
 * file the robot has not read yet is Processing, a file read with errors is
 * Needs attention, a file read with none is Healthy.
 */
final class YandexWebmaster extends AbstractConnection {

	private const API = 'https://api.webmaster.yandex.net/v4';

	public function id(): string {
		return 'yandex-webmaster';
	}

	public function label(): string {
		return 'Yandex Webmaster';
	}

	public function secret_field(): string {
		return 'yandex_token';
	}

	public function plain_fields(): array {
		return array();
	}

	public function channel_ids(): array {
		return array( 'yandex' );
	}

	public function verify( string $credential, array $settings ): array {
		$source = $this->source( $credential );
		$token  = $source->bearer( $this->transport() );

		if ( '' !== $token['error'] ) {
			return $this->refused( $token['error'] );
		}

		$credential = $token['token'];
		$user       = $this->request( 'GET', self::API . '/user', $this->headers( $credential ) );

		if ( 200 !== $user['code'] || ! isset( $user['json']['user_id'] ) ) {
			return $this->refused(
				$this->failure(
					$user,
					'oauth' === $source->kind()
						? __( 'Yandex did not accept the sign-in. Press Sign in again.', 'estasoft-news-distribution' )
						: __( 'Yandex did not accept the token. Make a new access token with the Yandex Webmaster permissions and paste it in again.', 'estasoft-news-distribution' )
				)
			);
		}

		$user_id = (string) $user['json']['user_id'];
		$hosts   = $this->request( 'GET', self::API . '/user/' . rawurlencode( $user_id ) . '/hosts', $this->headers( $credential ) );

		if ( 200 !== $hosts['code'] || ! isset( $hosts['json']['hosts'] ) || ! is_array( $hosts['json']['hosts'] ) ) {
			return $this->refused(
				$this->failure(
					$hosts,
					'oauth' === $source->kind()
						? __( 'Yandex did not let this sign-in read your sites. Check that the Yandex app has the Yandex Webmaster permissions, then press Sign in again.', 'estasoft-news-distribution' )
						: __( 'Yandex did not let the token read your sites. Make a new access token with the Yandex Webmaster permissions.', 'estasoft-news-distribution' )
				)
			);
		}

		$host = $this->find_host( $hosts['json']['hosts'] );

		if ( null === $host ) {
			return $this->refused(
				sprintf(
					/* translators: %s: the site's address, such as example.com. */
					__( 'Yandex Webmaster does not list %s among the sites of this account. Add the site there and verify it first.', 'estasoft-news-distribution' ),
					$this->site_host()
				)
			);
		}

		if ( empty( $host['verified'] ) ) {
			return $this->refused(
				sprintf(
					/* translators: %s: the site's address, such as example.com. */
					__( 'Yandex Webmaster lists %s but has not verified it yet. Verify the site there first.', 'estasoft-news-distribution' ),
					$this->site_host()
				)
			);
		}

		return array(
			'ok'      => true,
			'message' => 'oauth' === $source->kind()
				? __( 'Yandex accepted the sign-in.', 'estasoft-news-distribution' )
				: __( 'Yandex accepted the token.', 'estasoft-news-distribution' ),
			'account' => '',
			'context' => array(
				'user_id' => $user_id,
				'host_id' => (string) $host['host_id'],
			),
		);
	}

	public function sync( string $credential, array $settings, array $context, array $sitemaps ): array {
		$source  = $this->source( $credential );
		$user_id = (string) ( $context['user_id'] ?? '' );
		$host_id = (string) ( $context['host_id'] ?? '' );

		if ( '' === $user_id || '' === $host_id ) {
			return array(
				'ok'      => false,
				'message' => __( 'Connect Yandex again so News Distribution can find your site there.', 'estasoft-news-distribution' ),
				'rows'    => array(),
			);
		}

		$token = $source->bearer( $this->transport() );

		if ( '' !== $token['error'] ) {
			return array(
				'ok'      => false,
				'message' => $token['error'],
				'rows'    => array(),
			);
		}

		$credential = $token['token'];
		$base       = self::API . '/user/' . rawurlencode( $user_id ) . '/hosts/' . rawurlencode( $host_id );
		$added      = $this->request( 'GET', $base . '/user-added-sitemaps?limit=100', $this->headers( $credential ) );
		$known      = array();
		$denied     = 'oauth' === $source->kind()
			? __( 'Yandex did not let this sign-in read or add sitemaps. Check that the Yandex app has the Yandex Webmaster permissions, then press Sign in again.', 'estasoft-news-distribution' )
			: __( 'Yandex did not let the token read or add sitemaps. Make a new access token with the Yandex Webmaster permissions.', 'estasoft-news-distribution' );

		if ( 200 !== $added['code'] ) {
			return array(
				'ok'      => false,
				'message' => $this->failure( $added, $denied ),
				'rows'    => array(),
			);
		}

		foreach ( (array) ( $added['json']['sitemaps'] ?? array() ) as $item ) {
			if ( is_array( $item ) && isset( $item['sitemap_url'] ) ) {
				$known[ (string) $item['sitemap_url'] ] = (string) ( $item['added_date'] ?? '' );
			}
		}

		$problems = array();

		foreach ( $sitemaps as $sitemap ) {
			if ( isset( $known[ $sitemap['url'] ] ) ) {
				continue;
			}

			$answer = $this->request(
				'POST',
				$base . '/user-added-sitemaps',
				$this->headers( $credential ) + array( 'Content-Type' => 'application/json' ),
				wp_json_encode( array( 'url' => $sitemap['url'] ) )
			);

			if ( 201 === $answer['code'] || 409 === $answer['code'] ) {
				$known[ $sitemap['url'] ] = gmdate( 'c', Clock::now() );
				continue;
			}

			$problems[] = $this->failure( $answer, $denied );
		}

		if ( array() !== $problems ) {
			return array(
				'ok'      => false,
				'message' => $problems[0],
				'rows'    => array(),
			);
		}

		$seen = $this->request( 'GET', $base . '/sitemaps?limit=100', $this->headers( $credential ) );

		if ( 200 !== $seen['code'] ) {
			return array(
				'ok'      => false,
				'message' => $this->failure( $seen, $denied ),
				'rows'    => array(),
			);
		}

		$files = array();

		foreach ( (array) ( $seen['json']['sitemaps'] ?? array() ) as $item ) {
			if ( is_array( $item ) && isset( $item['sitemap_url'] ) ) {
				$files[ (string) $item['sitemap_url'] ] = $item;
			}
		}

		$rows = array();

		foreach ( $sitemaps as $sitemap ) {
			$rows[] = $this->describe( $sitemap, $files[ $sitemap['url'] ] ?? null, (string) ( $known[ $sitemap['url'] ] ?? '' ) );
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
	 * The headers every request carries.
	 *
	 * @param string $token The access token.
	 * @return array<string, string>
	 */
	private function headers( string $token ): array {
		return array( 'Authorization' => 'OAuth ' . $token );
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
	 * Finds this site in the list of sites of the account.
	 *
	 * @param array<int, mixed> $hosts The `hosts` array of the answer.
	 * @return array<string, mixed>|null
	 */
	private function find_host( array $hosts ): ?array {
		$wanted = $this->host_id();
		$bare   = preg_replace( '/^www\./', '', $this->site_host() );
		$found  = null;

		foreach ( $hosts as $host ) {
			if ( ! is_array( $host ) || ! isset( $host['host_id'] ) ) {
				continue;
			}

			if ( (string) $host['host_id'] === $wanted ) {
				return $host;
			}

			$candidate = strtolower( (string) wp_parse_url( (string) ( $host['ascii_host_url'] ?? '' ), PHP_URL_HOST ) );

			if ( null === $found && preg_replace( '/^www\./', '', $candidate ) === $bare ) {
				$found = $host;
			}
		}

		return $found;
	}

	/**
	 * The ID Yandex gives this site: scheme, host and port joined by colons.
	 *
	 * @return string
	 */
	private function host_id(): string {
		$parts  = (array) wp_parse_url( home_url() );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		return $scheme . ':' . $this->site_host() . ':' . $port;
	}

	/**
	 * One row, from what the robot found, or from the date the sitemap was added.
	 *
	 * @param array{label: string, url: string} $sitemap The sitemap.
	 * @param array<string, mixed>|null         $file    The robot's record of the file, if it has one.
	 * @param string                            $added   The date it was added, when known.
	 * @return array{label: string, url: string, says: string, status: string, link: string}
	 */
	private function describe( array $sitemap, ?array $file, string $added ): array {
		$added_ts = '' === $added ? 0 : (int) strtotime( $added );
		$read     = null === $file || empty( $file['last_access_date'] ) ? 0 : (int) strtotime( (string) $file['last_access_date'] );
		$errors   = null === $file ? 0 : (int) ( $file['errors_count'] ?? 0 );

		if ( $errors > 0 ) {
			return $this->row(
				$sitemap,
				sprintf(
					/* translators: 1: number of errors, 2: a date such as "14 Oct". */
					_n( 'Read on %2$s, %1$d error', 'Read on %2$s, %1$d errors', $errors, 'estasoft-news-distribution' ),
					$errors,
					$this->day( $read > 0 ? $read : Clock::now() )
				),
				Status::ATTENTION,
				'https://webmaster.yandex.com/'
			);
		}

		if ( $read > 0 ) {
			return $this->row(
				$sitemap,
				sprintf(
					/* translators: 1: a date such as "14 Oct", 2: number of links in the file. */
					_n( 'Read on %1$s, %2$d link', 'Read on %1$s, %2$d links', (int) ( $file['urls_count'] ?? 0 ), 'estasoft-news-distribution' ),
					$this->day( $read ),
					(int) ( $file['urls_count'] ?? 0 )
				),
				Status::HEALTHY
			);
		}

		return $this->row(
			$sitemap,
			$added_ts > 0
				? sprintf(
					/* translators: %s: a date such as "14 Oct". */
					__( 'In the queue, added %s', 'estasoft-news-distribution' ),
					$this->day( $added_ts )
				)
				: __( 'In the queue', 'estasoft-news-distribution' ),
			Status::PROCESSING
		);
	}
}

<?php
/**
 * IndexNow.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\IndexNow;

use Estasoft\NewsDistribution\Channels\DeliveryResult;
use Estasoft\NewsDistribution\Channels\Ping\AbstractPingChannel;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Core\Retry;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the search engines that take part in IndexNow that an address was
 * published, changed or removed. One request goes to one shared endpoint and
 * the engines share it with each other.
 *
 * - The request is a JSON POST with the host, the key, the address of the key
 *   file and the list of addresses. The key file is answered from a rewrite rule
 *   (Modules\IndexNowKeyModule), so nothing is written to the site root.
 * - It is sent from the queue after a publish, update, unpublish or delete, never
 *   inside the save, and only while the channel is on. Only addresses on the
 *   site's own host are sent.
 * - A test, a catch-up batch and every event use the same submit() method.
 * - 200 and 202 are accepted. 429 and server errors are tried again after 1, 5
 *   and 15 minutes. 400, 403 and 422 are not: the answer will not change until
 *   the person fixes the key or the addresses.
 * - A notice is a hint to crawl. It does not promise indexing.
 */
final class IndexNowChannel extends AbstractPingChannel {

	public const PLATFORM = 'indexnow';

	/**
	 * The shared endpoint every engine on the list reads. Filterable for tests and for a site that
	 * wants to send straight to one engine.
	 */
	public const ENDPOINT = 'https://api.indexnow.org/indexnow';

	/**
	 * Option that keeps the result of the last key file check.
	 */
	public const KEY_CHECK_OPTION = 'estasoft_nd_indexnow_keycheck';

	public function id(): string {
		return 'indexnow';
	}

	public function label(): string {
		return __( 'IndexNow', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 20;
	}

	public function gate_note(): string {
		return '';
	}

	public function fields(): array {
		return array_merge(
			array(
				'key' => array(
					'type'    => 'string',
					'format'  => 'key',
					'label'   => __( 'Key', 'estasoft-news-distribution' ),
					'default' => '',
					'max'     => 128,
				),
			),
			$this->toggle_fields(
				array(
					'publish'   => __( 'Sent when the post goes live.', 'estasoft-news-distribution' ),
					'update'    => __( 'Sent when the content or title changes.', 'estasoft-news-distribution' ),
					'remove'    => __( 'Sent so search engines can drop them.', 'estasoft-news-distribution' ),
					'languages' => __( 'Other languages are never sent.', 'estasoft-news-distribution' ),
				)
			)
		);
	}

	/**
	 * A new random key: 32 letters and digits.
	 *
	 * @return string
	 */
	public static function new_key(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Whether text can be an IndexNow key: letters, digits and dashes, 8 to 128 characters.
	 *
	 * @param string $key Text.
	 * @return bool
	 */
	public static function is_key( string $key ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9-]{8,128}$/', $key );
	}

	/**
	 * The stored key, or an empty string when there is none or it is not usable.
	 *
	 * @return string
	 */
	public function key(): string {
		$key = trim( (string) ( $this->settings()['key'] ?? '' ) );

		return self::is_key( $key ) ? $key : '';
	}

	/**
	 * Replaces the key with a new random one. The old key file answers 404 at once,
	 * and the earlier key file check no longer applies.
	 *
	 * @return string|\WP_Error The new key, or the error the settings gave.
	 */
	public function regenerate_key() {
		$saved = $this->context->channels->save( $this->id(), $this->fields(), array( 'key' => self::new_key() ) );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		delete_option( self::KEY_CHECK_OPTION );

		return $this->key();
	}

	/**
	 * The host IndexNow is told the addresses belong to.
	 *
	 * @return string Lowercase host name, without a port.
	 */
	public function host(): string {
		return strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}

	/**
	 * The address of the key file, or an empty string without a key.
	 *
	 * @return string
	 */
	public function key_url(): string {
		$key = $this->key();

		return '' === $key ? '' : home_url( '/' . $key . '.txt' );
	}

	protected function is_ready(): bool {
		return '' !== $this->key();
	}

	/**
	 * The address a submission is sent to.
	 *
	 * @return string
	 */
	public function endpoint(): string {
		/**
		 * Filters the IndexNow endpoint.
		 *
		 * @param string $endpoint Address that takes the JSON request.
		 */
		$endpoint = apply_filters( 'estasoft_nd_indexnow_endpoint', self::ENDPOINT );

		return is_string( $endpoint ) && '' !== $endpoint ? $endpoint : self::ENDPOINT;
	}

	/**
	 * Most addresses one request carries.
	 *
	 * @return int
	 */
	public function max_urls(): int {
		return max( 1, (int) $this->context->rules->limit( self::PLATFORM, 'urls_per_request', 10000 ) );
	}

	/**
	 * What the key file answers, or null when it would not be answered: the key, only the key.
	 *
	 * @return array{body: string, type: string}|null
	 */
	public function key_file(): ?array {
		$key = $this->key();

		if ( '' === $key || ! $this->is_enabled() ) {
			return null;
		}

		return array(
			'body' => $key,
			'type' => 'text/plain; charset=UTF-8',
		);
	}

	/**
	 * The JSON request body for some addresses.
	 *
	 * @param Article[] $articles Articles whose addresses are sent.
	 * @param string    $language Not used: IndexNow takes addresses, not languages.
	 * @param int|null  $now      Not used.
	 * @return string
	 */
	public function build( array $articles, string $language, ?int $now = null ): string {
		unset( $language, $now );

		$urls = array();

		foreach ( $articles as $article ) {
			$urls[] = $article->permalink();
		}

		return $this->body( $urls );
	}

	/**
	 * The JSON body of a request.
	 *
	 * @param string[] $urls Addresses.
	 * @return string
	 */
	private function body( array $urls ): string {
		return (string) wp_json_encode(
			array(
				'host'        => $this->host(),
				'key'         => $this->key(),
				'keyLocation' => $this->key_url(),
				'urlList'     => array_values( $urls ),
			)
		);
	}

	public function deliver( Event $event, ?Article $article ): DeliveryResult {
		if ( ! $this->event_enabled( $event ) ) {
			return DeliveryResult::skipped( __( 'This kind of change is not sent. You can change that in the IndexNow settings.', 'estasoft-news-distribution' ) );
		}

		if ( $this->is_minor_edit( $event ) ) {
			return DeliveryResult::skipped( __( 'A minor edit sends no notice.', 'estasoft-news-distribution' ) );
		}

		$url = $event->removes_content() ? $event->url() : ( null === $article ? '' : $article->permalink() );

		if ( '' === $url ) {
			return DeliveryResult::skipped( __( 'There is no address to send.', 'estasoft-news-distribution' ) );
		}

		$result = $this->submit( array( $url ) );

		if ( ! $result->is_failure() && DeliveryResult::SUCCESS === $result->status() && ! $event->removes_content() ) {
			update_post_meta( $event->post_id(), Catchup::META, Clock::now() );
		}

		return $result;
	}

	/**
	 * Sends addresses to IndexNow. Used by every event, the test and the catch-up.
	 *
	 * Addresses on another host are left out. A list longer than one request
	 * carries is split, and the first refusal stops the rest.
	 *
	 * @param string[] $urls Addresses.
	 * @return DeliveryResult
	 */
	public function submit( array $urls ): DeliveryResult {
		if ( '' === $this->key() ) {
			return DeliveryResult::failure( __( 'There is no key yet. Create one on the IndexNow page.', 'estasoft-news-distribution' ), 0, false );
		}

		$host  = $this->host();
		$keep  = array();
		$other = 0;

		foreach ( $urls as $url ) {
			$url = trim( (string) $url );

			if ( '' === $url ) {
				continue;
			}

			if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $host ) {
				++$other;
				continue;
			}

			$keep[ $url ] = $url;
		}

		if ( array() === $keep ) {
			return DeliveryResult::skipped(
				sprintf(
					/* translators: %s: the site's host name, such as example.com. */
					__( 'None of the addresses are on %s, so nothing was sent.', 'estasoft-news-distribution' ),
					$host
				)
			);
		}

		$result = DeliveryResult::skipped( '' );

		foreach ( array_chunk( array_values( $keep ), $this->max_urls() ) as $chunk ) {
			$result = $this->interpret(
				$this->post(
					$this->endpoint(),
					array(
						'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
						'body'    => $this->body( $chunk ),
					),
					false
				)
			);

			if ( $result->is_failure() ) {
				return $result;
			}
		}

		if ( $other > 0 ) {
			return DeliveryResult::success(
				$result->code(),
				$result->message() . ' ' . sprintf(
					/* translators: %s: number of addresses. */
					_n( '%s address on another host was left out.', '%s addresses on another host were left out.', $other, 'estasoft-news-distribution' ),
					number_format_i18n( $other )
				)
			);
		}

		return $result;
	}

	/**
	 * Turns IndexNow's answer into a delivery result.
	 *
	 * @param array{code: int, text: string, error: string} $answer The answer.
	 * @return DeliveryResult
	 */
	private function interpret( array $answer ): DeliveryResult {
		$code   = (int) $answer['code'];
		$reason = (string) $answer['text'];

		if ( 0 === $code ) {
			return DeliveryResult::failure(
				sprintf(
					/* translators: %s: the reason IndexNow could not be reached. */
					__( 'IndexNow did not answer: %s', 'estasoft-news-distribution' ),
					'' !== $answer['error'] ? $answer['error'] : __( 'no reason given', 'estasoft-news-distribution' )
				),
				0,
				true
			);
		}

		if ( 200 === $code ) {
			return DeliveryResult::success( $code, $reason );
		}

		if ( $code >= 200 && $code < 300 ) {
			return DeliveryResult::success(
				$code,
				202 === $code
					? $reason . '. ' . __( 'The key check is still pending.', 'estasoft-news-distribution' )
					: $reason
			);
		}

		return DeliveryResult::failure( $this->explain( $code, $reason ), $code, Retry::is_retryable_code( $code ) );
	}

	/**
	 * A refusal in plain words.
	 *
	 * @param int    $code   HTTP status.
	 * @param string $reason The standard words for the status.
	 * @return string
	 */
	private function explain( int $code, string $reason ): string {
		switch ( $code ) {
			case 400:
				return $reason . '. ' . __( 'IndexNow could not read the request.', 'estasoft-news-distribution' );
			case 403:
				return $reason . '. ' . __( 'IndexNow did not accept the key. The key file must be reachable and contain only the key.', 'estasoft-news-distribution' );
			case 422:
				return $reason . '. ' . __( 'The addresses must be on your site and the key must match the key file.', 'estasoft-news-distribution' );
			case 429:
				return $reason . '. ' . __( 'IndexNow asks you to send less often. News Distribution tries again later.', 'estasoft-news-distribution' );
		}

		if ( $code >= 500 ) {
			return $reason . '. ' . __( 'IndexNow had a problem. News Distribution tries again later.', 'estasoft-news-distribution' );
		}

		return $reason . '.';
	}

	/**
	 * Sends the home page address once and keeps the answer in the delivery log.
	 * Started by a person pressing "Send a test".
	 *
	 * @return array{ok: bool, code: int, message: string}
	 */
	public function test(): array {
		if ( '' === $this->key() ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'message' => __( 'There is no key yet. Create one before sending a test.', 'estasoft-news-distribution' ),
			);
		}

		$result   = $this->submit( array( home_url( '/' ) ) );
		$ok       = ! $result->is_failure() && DeliveryResult::SUCCESS === $result->status();
		$language = $this->context->languages->default_language();
		$message  = $ok
			? sprintf(
				/* translators: 1: the HTTP code IndexNow answered, 2: the words for the code, such as "OK". */
				__( 'IndexNow answered %1$d %2$s.', 'estasoft-news-distribution' ),
				$result->code(),
				rtrim( $result->message(), '.' )
			)
			: $result->message();

		$this->log_test( $language, $ok, $result->code(), $result->message() );

		return array(
			'ok'      => $ok,
			'code'    => $result->code(),
			'message' => $message,
		);
	}

	/**
	 * Fetches the key file the way an engine does and says what came back.
	 * Started by a person pressing "Check again".
	 *
	 * @return array{ok: bool, code: int, message: string, time: int}
	 */
	public function check_key_file(): array {
		$url = $this->key_url();

		if ( '' === $url ) {
			return $this->keep_check( false, 0, __( 'There is no key yet.', 'estasoft-news-distribution' ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'user-agent'  => $this->user_agent(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->keep_check(
				false,
				0,
				sprintf(
					/* translators: %s: the reason the address could not be fetched. */
					__( 'The key file could not be fetched: %s', 'estasoft-news-distribution' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return $this->keep_check(
				false,
				$code,
				sprintf(
					/* translators: 1: the HTTP code, 2: the words for the code, such as "Not Found". */
					__( 'The key file answers %1$d %2$s. Check that the channel is on, then save your permalinks in Settings so the address is served.', 'estasoft-news-distribution' ),
					$code,
					self::reason( $code )
				)
			);
		}

		if ( $body !== $this->key() ) {
			return $this->keep_check( false, $code, __( 'The address answers, but the file holds more than the key. An engine refuses a key file with other text.', 'estasoft-news-distribution' ) );
		}

		return $this->keep_check( true, $code, __( 'Served from the site root, containing only the key', 'estasoft-news-distribution' ) );
	}

	/**
	 * The result of the last key file check.
	 *
	 * @return array{ok: bool, code: int, message: string, time: int}|null Null when the check was never run.
	 */
	public function last_key_check(): ?array {
		$stored = get_option( self::KEY_CHECK_OPTION, array() );

		if ( ! is_array( $stored ) || ! isset( $stored['time'] ) ) {
			return null;
		}

		return array(
			'ok'      => ! empty( $stored['ok'] ),
			'code'    => (int) ( $stored['code'] ?? 0 ),
			'message' => (string) ( $stored['message'] ?? '' ),
			'time'    => (int) $stored['time'],
		);
	}

	/**
	 * Keeps a key file check.
	 *
	 * @param bool   $ok      Whether the file is served as it should be.
	 * @param int    $code    HTTP status, or 0.
	 * @param string $message A sentence.
	 * @return array{ok: bool, code: int, message: string, time: int}
	 */
	private function keep_check( bool $ok, int $code, string $message ): array {
		$result = array(
			'ok'      => $ok,
			'code'    => $code,
			'message' => $message,
			'time'    => Clock::now(),
		);

		update_option( self::KEY_CHECK_OPTION, $result, false );

		return $result;
	}

	/**
	 * What the delivery log says about IndexNow in one line for the Channels list.
	 *
	 * @return string
	 */
	protected function hub_summary(): string {
		if ( ! $this->is_ready() ) {
			return __( 'No key yet. Turn the channel on to create one.', 'estasoft-news-distribution' );
		}

		$seen = array();
		$rows = $this->recent_rows( 50 );

		foreach ( $rows as $row ) {
			$key = $row['post_id'] . '|' . $row['event'];

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;

			if ( Status::RETRY === $row['status'] ) {
				return sprintf(
					/* translators: 1: the attempt that failed, 2: attempts in all, 3: the time of the next try, such as 14:37. */
					__( '1 notice retrying: attempt %1$d of %2$d, next try %3$s', 'estasoft-news-distribution' ),
					(int) $row['attempt'],
					Retry::MAX_ATTEMPTS,
					Clock::format( (int) $row['run_at_ts'] )
				);
			}
		}

		foreach ( $rows as $row ) {
			if ( Status::SENT === $row['status'] ) {
				return sprintf(
					/* translators: 1: the time of the last notice, such as 14:21, 2: the HTTP code IndexNow answered. */
					__( 'Last sent %1$s, answered %2$d', 'estasoft-news-distribution' ),
					Clock::format( (int) $row['created_ts'] ),
					(int) $row['response_code']
				);
			}
		}

		return __( 'Waiting for the next change.', 'estasoft-news-distribution' );
	}
}

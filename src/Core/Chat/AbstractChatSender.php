<?php
/**
 * Shared part of the chat senders.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one message to a chat service's incoming webhook and words a failure so a person can act
 * on it. The webhook address is a secret: it is never put in a message, a log or a returned value.
 *
 * A request is made with `wp_safe_remote_post()`, so WordPress itself refuses an address that points
 * inside a private network. It waits at most 10 seconds, follows no redirect and reads at most 1 MB
 * of the answer. "Accepted" means the service answered with a 2xx status. It does not mean a person
 * saw the message.
 */
abstract class AbstractChatSender {

	/**
	 * The most characters of a service's own answer that are shown.
	 */
	private const MAX_ANSWER = 120;

	/**
	 * The service id, as used in the settings: `slack` or `teams`.
	 *
	 * @return string
	 */
	abstract public function service(): string;

	/**
	 * The service's name as its owner writes it.
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * The JSON body the service reads.
	 *
	 * @param ChatMessage $message The message.
	 * @return array<string, mixed>
	 */
	abstract public function payload( ChatMessage $message ): array;

	/**
	 * The key of the webhook address in the secret store.
	 *
	 * @return string
	 */
	public function secret_key(): string {
		return 'alerts_' . $this->service() . '_webhook';
	}

	/**
	 * Posts a message.
	 *
	 * @param string      $address The webhook address. Never printed.
	 * @param ChatMessage $message The message.
	 * @return array{ok: bool, code: int, message: string} `message` is empty when the service accepted the message, else a plain sentence with the address taken out.
	 */
	public function post( string $address, ChatMessage $message ): array {
		$response = wp_safe_remote_post(
			$address,
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'headers'             => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'                => (string) wp_json_encode( $this->payload( $message ) ),
				'user-agent'          => 'EstasoftNewsDistribution/' . ( defined( 'ESTASOFT_ND_VERSION' ) ? ESTASOFT_ND_VERSION : '' ) . '; ' . home_url(),
				// A service's answer is never read past 1 MB.
				'limit_response_size' => 1048576,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'message' => $this->unreachable( $response, $address ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			return array(
				'ok'      => true,
				'code'    => $code,
				'message' => '',
			);
		}

		$answer = trim( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ) );
		$answer = ChatAddress::scrub( $answer, $address );
		$answer = mb_strlen( $answer ) > self::MAX_ANSWER ? mb_substr( $answer, 0, self::MAX_ANSWER ) . '…' : $answer;

		return array(
			'ok'      => false,
			'code'    => $code,
			'message' => sprintf(
				/* translators: 1: chat service name, such as Slack, 2: HTTP status number, 3: what the service said, may be empty. */
				__( '%1$s did not accept the message. It answered with error %2$d. %3$s', 'estasoft-news-distribution' ),
				$this->label(),
				$code,
				$answer
			),
		);
	}

	/**
	 * A sentence for a request that got no answer.
	 *
	 * @param \WP_Error $error   What WordPress returned.
	 * @param string    $address The webhook address.
	 * @return string
	 */
	private function unreachable( \WP_Error $error, string $address ): string {
		// WordPress answers an address its safety check refuses with an error of its own; the same check, run here, says that is what happened.
		$host = (string) wp_parse_url( $address, PHP_URL_HOST );

		$known = '' !== $host && ( false !== filter_var( $host, FILTER_VALIDATE_IP ) || gethostbyname( $host ) !== $host );

		if ( 'http_request_failed' === $error->get_error_code() && $known && false === wp_http_validate_url( $address ) ) {
			return sprintf(
				/* translators: %s: chat service name, such as Slack. */
				__( 'WordPress refused the saved %s address as unsafe. It may point inside a private network, or its name could not be looked up from your server.', 'estasoft-news-distribution' ),
				$this->label()
			);
		}

		return sprintf(
			/* translators: 1: chat service name, such as Slack, 2: the error text from WordPress. */
			__( 'Your site could not reach %1$s: %2$s', 'estasoft-news-distribution' ),
			$this->label(),
			ChatAddress::scrub( $error->get_error_message(), $address )
		);
	}
}

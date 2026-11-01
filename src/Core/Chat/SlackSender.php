<?php
/**
 * Posts an alert to a Slack incoming webhook.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * The body is `{ "text": "..." }`: the title in bold, the sentence, and the link as `<address|label>`.
 *
 * Not verified against the real service: the escaping of `&`, `<` and `>` and the `<address|label>` link are
 * Slack's message formatting as this plugin's authors know it, and no request was made to Slack while
 * this was written. The tests answer from a recorded shape with `pre_http_request`.
 */
final class SlackSender extends AbstractChatSender {

	public function service(): string {
		return 'slack';
	}

	public function label(): string {
		return 'Slack';
	}

	/**
	 * The JSON body.
	 *
	 * @param ChatMessage $message The message.
	 * @return array{text: string}
	 */
	public function payload( ChatMessage $message ): array {
		$lines = array();

		if ( '' !== $message->title() ) {
			$lines[] = '*' . $this->escape( $message->title() ) . '*';
		}

		if ( '' !== $message->text() ) {
			$lines[] = $this->escape( $message->text() );
		}

		if ( $message->has_link() ) {
			$lines[] = '<' . str_replace( array( '|', '<', '>' ), array( '%7C', '%3C', '%3E' ), $message->link_url() ) . '|' . $this->escape( $message->link_label() ) . '>';
		}

		return array( 'text' => implode( "\n", $lines ) );
	}

	/**
	 * Escapes the three characters Slack reads as markup.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	private function escape( string $text ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
	}
}

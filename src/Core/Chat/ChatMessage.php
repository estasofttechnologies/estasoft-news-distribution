<?php
/**
 * One short chat message.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * What a chat service is told: the alert's title, one sentence, and a link back to the
 * problem. It holds no article text and no article title, and nothing here is secret.
 * `Core\Alerts::chat_message()` builds it, and a sender turns it into the body its
 * service reads.
 */
final class ChatMessage {

	private string $title;

	private string $text;

	private string $link_label;

	private string $link_url;

	/**
	 * Creates the message.
	 *
	 * @param string $title      The alert's title, such as "A push fails its last retry".
	 * @param string $text       One sentence.
	 * @param string $link_label What the link is called, or an empty string for no link.
	 * @param string $link_url   Where the link goes, or an empty string for no link.
	 */
	public function __construct( string $title, string $text, string $link_label = '', string $link_url = '' ) {
		$this->title      = trim( wp_strip_all_tags( $title ) );
		$this->text       = trim( wp_strip_all_tags( $text ) );
		$this->link_label = trim( wp_strip_all_tags( $link_label ) );
		$this->link_url   = '' === $link_url ? '' : esc_url_raw( $link_url );
	}

	/**
	 * The alert's title.
	 *
	 * @return string
	 */
	public function title(): string {
		return $this->title;
	}

	/**
	 * The sentence.
	 *
	 * @return string
	 */
	public function text(): string {
		return $this->text;
	}

	/**
	 * What the link is called.
	 *
	 * @return string
	 */
	public function link_label(): string {
		return $this->link_label;
	}

	/**
	 * Where the link goes.
	 *
	 * @return string
	 */
	public function link_url(): string {
		return $this->link_url;
	}

	/**
	 * Whether the message carries a link.
	 *
	 * @return bool
	 */
	public function has_link(): bool {
		return '' !== $this->link_url && '' !== $this->link_label;
	}
}

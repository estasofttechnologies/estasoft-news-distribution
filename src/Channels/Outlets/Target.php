<?php
/**
 * One place an outlet can post to.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Outlets;

defined( 'ABSPATH' ) || exit;

/**
 * A Telegram channel for one language, a Mastodon or Bluesky account for one
 * language, or one webhook endpoint. It never holds a secret: the label is
 * something a person may read on a screen or in the delivery log (a channel
 * name, an account handle, the name given to an endpoint).
 */
final class Target {

	private string $outlet;
	private string $key;
	private string $label;
	private string $language;
	private bool $complete;
	private bool $on;

	/**
	 * Creates the target.
	 *
	 * @param string $outlet   Outlet id: telegram, mastodon, bluesky, medium or webhook.
	 * @param string $key      Language code, or the endpoint number for a webhook.
	 * @param string $label    A name a person can read. Never a secret.
	 * @param string $language Language of the articles it carries, or an empty string for every language.
	 * @param bool   $complete Whether everything it needs to post is saved.
	 * @param bool   $on       Whether the owner turned this target on.
	 */
	public function __construct( string $outlet, string $key, string $label, string $language = '', bool $complete = true, bool $on = true ) {
		$this->outlet   = $outlet;
		$this->key      = $key;
		$this->label    = $label;
		$this->language = $language;
		$this->complete = $complete;
		$this->on       = $on;
	}

	/**
	 * The target's id, such as "telegram:en" or "webhook:2".
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->outlet . ':' . $this->key;
	}

	public function outlet(): string {
		return $this->outlet;
	}

	public function key(): string {
		return $this->key;
	}

	public function label(): string {
		return $this->label;
	}

	public function language(): string {
		return $this->language;
	}

	public function is_complete(): bool {
		return $this->complete;
	}

	public function is_on(): bool {
		return $this->on;
	}

	/**
	 * Whether the target carries articles in a language.
	 *
	 * @param string $language Language code of the article.
	 * @return bool
	 */
	public function carries( string $language ): bool {
		return '' === $this->language || $this->language === $language;
	}
}

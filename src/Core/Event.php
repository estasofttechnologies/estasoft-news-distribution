<?php
/**
 * A normalized publishing event.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

defined( 'ABSPATH' ) || exit;

/**
 * One thing that happened to one post, in one language.
 */
final class Event {

	public const PUBLISH   = 'publish';
	public const UPDATE    = 'update';
	public const UNPUBLISH = 'unpublish';
	public const DELETE    = 'delete';

	private string $type;
	private int $post_id;
	private string $language;
	private int $timestamp;

	/**
	 * What was known about the article before it left the site: url, title.
	 *
	 * @var array<string, string>
	 */
	private array $snapshot;

	/**
	 * @param string               $type      One of the class constants.
	 * @param int                  $post_id   Post ID.
	 * @param string               $language  Language code of the post.
	 * @param int|null             $timestamp Unix time of the event. Defaults to now.
	 * @param array<string,string> $snapshot  Address and title of an article that was unpublished or deleted.
	 */
	public function __construct( string $type, int $post_id, string $language, ?int $timestamp = null, array $snapshot = array() ) {
		$this->type      = $type;
		$this->post_id   = $post_id;
		$this->language  = $language;
		$this->timestamp = null === $timestamp ? Clock::now() : $timestamp;
		$this->snapshot  = $snapshot;
	}

	/**
	 * The address the article had while it was public. Set for removal events.
	 *
	 * @return string
	 */
	public function url(): string {
		return (string) ( $this->snapshot['url'] ?? '' );
	}

	/**
	 * The title the article had while it was public. Set for removal events.
	 *
	 * @return string
	 */
	public function title(): string {
		return (string) ( $this->snapshot['title'] ?? '' );
	}

	public function type(): string {
		return $this->type;
	}

	public function post_id(): int {
		return $this->post_id;
	}

	public function language(): string {
		return $this->language;
	}

	public function timestamp(): int {
		return $this->timestamp;
	}

	/**
	 * True when the content is no longer public after this event.
	 *
	 * @return bool
	 */
	public function removes_content(): bool {
		return in_array( $this->type, array( self::UNPUBLISH, self::DELETE ), true );
	}

	/**
	 * Plain array, safe to store in the queue.
	 *
	 * @return array<string, int|string>
	 */
	public function to_array(): array {
		return array(
			'type'      => $this->type,
			'post_id'   => $this->post_id,
			'language'  => $this->language,
			'timestamp' => $this->timestamp,
			'url'       => $this->url(),
			'title'     => $this->title(),
		);
	}

	/**
	 * Rebuilds an event from {@see to_array()} output.
	 *
	 * @param array<string, mixed> $data Stored event data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['type'] ?? self::UPDATE ),
			(int) ( $data['post_id'] ?? 0 ),
			(string) ( $data['language'] ?? '' ),
			isset( $data['timestamp'] ) ? (int) $data['timestamp'] : null,
			array(
				'url'   => (string) ( $data['url'] ?? '' ),
				'title' => (string) ( $data['title'] ?? '' ),
			)
		);
	}
}

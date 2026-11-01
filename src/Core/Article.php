<?php
/**
 * A lean, channel-neutral view of one post.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Holds only the fields channels need, so the full WP_Post never leaves the reader.
 */
final class Article {

	private const DEFAULTS = array(
		'id'               => 0,
		'title'            => '',
		'content'          => '',
		'permalink'        => '',
		'language'         => '',
		'author_name'      => '',
		'author_url'       => '',
		'thumbnail_id'     => 0,
		'thumbnail_url'    => '',
		'thumbnail_width'  => 0,
		'thumbnail_height' => 0,
		'categories'       => array(),
		'tags'             => array(),
		'published_ts'     => 0,
		'modified_ts'      => 0,
		'post_type'        => 'post',
		'status'           => 'publish',
		'author_id'        => 0,
		'protected'        => false,
		'summary'          => '',
	);

	private array $data;

	/**
	 * @param array<string, mixed> $data Values keyed like {@see Article::DEFAULTS}.
	 */
	public function __construct( array $data ) {
		$this->data = array_merge( self::DEFAULTS, $data );
	}

	public function id(): int {
		return (int) $this->data['id'];
	}

	public function title(): string {
		return (string) $this->data['title'];
	}

	/**
	 * Raw post content. Channels that need rendered HTML render it themselves.
	 *
	 * @return string
	 */
	public function content(): string {
		return (string) $this->data['content'];
	}

	public function permalink(): string {
		return (string) $this->data['permalink'];
	}

	public function language(): string {
		return (string) $this->data['language'];
	}

	public function author_name(): string {
		return (string) $this->data['author_name'];
	}

	public function author_url(): string {
		return (string) $this->data['author_url'];
	}

	/**
	 * The media library ID of the featured image, or 0 when the article has none.
	 *
	 * Channels use it to find the copy made for their platform and the photo's credit.
	 *
	 * @return int
	 */
	public function thumbnail_id(): int {
		return (int) $this->data['thumbnail_id'];
	}

	public function thumbnail_url(): string {
		return (string) $this->data['thumbnail_url'];
	}

	public function thumbnail_width(): int {
		return (int) $this->data['thumbnail_width'];
	}

	public function thumbnail_height(): int {
		return (int) $this->data['thumbnail_height'];
	}

	/**
	 * @return string[]
	 */
	public function categories(): array {
		return array_map( 'strval', (array) $this->data['categories'] );
	}

	/**
	 * @return string[]
	 */
	public function tags(): array {
		return array_map( 'strval', (array) $this->data['tags'] );
	}

	/**
	 * Original publication time as a Unix timestamp (UTC).
	 *
	 * @return int
	 */
	public function published_ts(): int {
		return (int) $this->data['published_ts'];
	}

	public function modified_ts(): int {
		return (int) $this->data['modified_ts'];
	}

	/**
	 * The short text a person chose for platforms to print under the headline, when they chose one.
	 *
	 * Empty when none was chosen. Channels then print what they always did (the first words of the text, or the
	 * post's excerpt). The post's own excerpt is never changed by this.
	 *
	 * @return string
	 */
	public function summary(): string {
		return (string) $this->data['summary'];
	}

	public function post_type(): string {
		return (string) $this->data['post_type'];
	}

	/**
	 * The WordPress post status, such as "publish" or "draft".
	 *
	 * @return string
	 */
	public function status(): string {
		return (string) $this->data['status'];
	}

	/**
	 * Whether readers can see the article.
	 *
	 * @return bool
	 */
	public function is_public(): bool {
		return 'publish' === $this->status() && ! $this->is_protected();
	}

	/**
	 * Whether the post is behind a post password. Such a post is never in a feed, a sitemap or a push.
	 *
	 * @return bool
	 */
	public function is_protected(): bool {
		return (bool) $this->data['protected'];
	}

	/**
	 * The user ID of the author.
	 *
	 * @return int
	 */
	public function author_id(): int {
		return (int) $this->data['author_id'];
	}

	/**
	 * Words in the body, counting letters and digits in any script, Arabic included.
	 *
	 * @return int
	 */
	public function word_count(): int {
		$text = wp_strip_all_tags( $this->content() );
		return (int) preg_match_all( '/[\p{L}\p{N}]+/u', $text );
	}
}

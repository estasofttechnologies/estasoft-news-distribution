<?php
/**
 * What the Article check screens know about one article, read once.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Check;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\ArticleService;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Built for one article by the page and by each tab. It reads through `Core\ArticleService`, the
 * same service the REST routes use, so the screen and the API agree. Nothing here changes data:
 * the stored check is read, never run (a person runs it with the Run again button).
 */
final class Context {

	private Plugin $plugin;

	private int $post_id;

	private ?\WP_Post $post;

	private ?Article $article = null;

	private bool $article_read = false;

	/**
	 * The service's answer for the check, once read.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $check = null;

	/**
	 * The latest delivery row of each channel, once read.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $latest = null;

	/**
	 * @param Plugin $plugin  The running plugin.
	 * @param int    $post_id The article.
	 */
	public function __construct( Plugin $plugin, int $post_id ) {
		$this->plugin  = $plugin;
		$this->post_id = $post_id;
		$post          = $post_id > 0 ? get_post( $post_id ) : null;
		$this->post    = $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Whether the post exists and is of a type the site sends.
	 *
	 * @return bool
	 */
	public function exists(): bool {
		return null !== $this->post && in_array( $this->post->post_type, $this->plugin->settings()->post_types(), true );
	}

	public function id(): int {
		return $this->post_id;
	}

	public function post(): ?\WP_Post {
		return $this->post;
	}

	/**
	 * The article as the channels see it.
	 *
	 * @return Article|null
	 */
	public function article(): ?Article {
		if ( ! $this->article_read ) {
			$this->article_read = true;
			$this->article      = $this->plugin->reader()->find( $this->post_id );
		}

		return $this->article;
	}

	/**
	 * The title as plain text.
	 *
	 * @return string
	 */
	public function title(): string {
		if ( null === $this->post ) {
			return '';
		}

		$title = trim( wp_strip_all_tags( get_the_title( $this->post ) ) );

		return '' === $title ? __( '(no title)', 'estasoft-news-distribution' ) : $title;
	}

	public function language(): string {
		return $this->plugin->languages()->language_of_post( $this->post_id );
	}

	/**
	 * Whether the article is live: published and not scheduled.
	 *
	 * @return bool
	 */
	public function is_published(): bool {
		return null !== $this->post && 'publish' === $this->post->post_status;
	}

	/**
	 * The stored check, in the shape `GET /articles/{id}/check` returns.
	 *
	 * @return array<string, mixed>
	 */
	public function check(): array {
		if ( null === $this->check ) {
			$check       = $this->plugin->make( ArticleService::class )->check( $this->post_id, false );
			$this->check = null === $check ? array() : $check;
		}

		return $this->check;
	}

	/**
	 * Whether the article was ever checked.
	 *
	 * @return bool
	 */
	public function was_checked(): bool {
		return ! empty( $this->check()['stored'] );
	}

	/**
	 * Unix time of the stored check, or 0.
	 *
	 * @return int
	 */
	public function checked_at(): int {
		return Times::from_iso( $this->check()['checked'] ?? null );
	}

	/**
	 * Every finding once, most severe first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findings(): array {
		return array_values( (array) ( $this->check()['findings'] ?? array() ) );
	}

	/**
	 * Findings that apply to one channel.
	 *
	 * @param string $channel_id Channel id.
	 * @return array<int, array<string, mixed>>
	 */
	public function channel_findings( string $channel_id ): array {
		return array_values( (array) ( $this->check()['channels'][ $channel_id ]['findings'] ?? array() ) );
	}

	/**
	 * Whether the check lets the channel take the article.
	 *
	 * @param string $channel_id Channel id.
	 * @return bool
	 */
	public function eligible( string $channel_id ): bool {
		$channels = (array) ( $this->check()['channels'] ?? array() );

		return ! isset( $channels[ $channel_id ] ) || (bool) $channels[ $channel_id ]['eligible'];
	}

	/**
	 * Labels of the channels that skip the article because of a finding.
	 *
	 * @return array<string, string> Channel id => label.
	 */
	public function skipping(): array {
		$out = array();

		foreach ( (array) ( $this->check()['channels'] ?? array() ) as $id => $channel ) {
			if ( empty( $channel['eligible'] ) ) {
				$out[ (string) $id ] = (string) ( $channel['label'] ?? $id );
			}
		}

		return $out;
	}

	/**
	 * The result of the article on each channel that is on (published articles only).
	 *
	 * @return array<string, array{status: string, at: string|null, message: string}>
	 */
	public function results(): array {
		return (array) ( $this->check()['results'] ?? array() );
	}

	/**
	 * The latest delivery row of each channel for the article.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function latest(): array {
		if ( null === $this->latest ) {
			$this->latest = $this->plugin->log()->latest_for_post( $this->post_id );
		}

		return $this->latest;
	}

	/**
	 * Channels that are on and take the article's language, in hub order.
	 *
	 * @return array<string, ChannelInterface>
	 */
	public function active(): array {
		$language = $this->language();
		$out      = array();

		foreach ( $this->plugin->registry()->enabled() as $channel ) {
			if ( $channel->accepts_language( $language ) ) {
				$out[ $channel->id() ] = $channel;
			}
		}

		return $out;
	}

	/**
	 * Channels that are on but do not take the article's language.
	 *
	 * @return array<string, ChannelInterface>
	 */
	public function other_language(): array {
		$language = $this->language();
		$out      = array();

		foreach ( $this->plugin->registry()->enabled() as $channel ) {
			if ( ! $channel->accepts_language( $language ) ) {
				$out[ $channel->id() ] = $channel;
			}
		}

		return $out;
	}

	/**
	 * Channels that are switched off.
	 *
	 * @return array<string, ChannelInterface>
	 */
	public function off(): array {
		$out = array();

		foreach ( $this->plugin->registry()->all() as $channel ) {
			if ( ! $channel->is_enabled() ) {
				$out[ $channel->id() ] = $channel;
			}
		}

		return $out;
	}

	/**
	 * How many findings are worth a person's time: blocks and risks.
	 *
	 * @return int
	 */
	public function to_fix(): int {
		$counts = (array) ( $this->check()['counts'] ?? array() );

		return (int) ( $counts[ Status::BLOCK ] ?? 0 ) + (int) ( $counts[ Status::WARN ] ?? 0 );
	}
}

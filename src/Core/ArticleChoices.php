<?php
/**
 * What a person chose, for one article, about which channels carry it.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Admin\Check\ArticleOptions;
use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The editor panel lets a person switch a channel off for one article. The choice is stored in the
 * article's `_estasoft_nd_options` as `channels[<channel id>] = false`, and anything not listed is on,
 * exactly as the panel shows it (`ArticleOptions::channel_is_off()`).
 *
 * This service is the one place that answers the question for the channels that are read, not sent to
 * (sitemaps and feeds): `Modules\ArticleChoicesModule` puts it on the internal filter
 * `estasoft_nd_internal_feed_articles`, so every document and every report that lists a feed's articles
 * leaves the article out. A pushed channel reads the same choice when the queue runs the delivery.
 *
 * A switched-off channel is a choice, not a failure: the screens say Off, with the sentence from
 * `message()`. Nothing here contacts another server, and nothing here touches the delivery log, the fetch
 * log or a takedown.
 *
 * Get it with `Plugin::instance()->make( ArticleChoices::class )`.
 */
final class ArticleChoices {

	/**
	 * The option that holds the time of the last change of a choice, so a feed's `Last-Modified` moves too.
	 */
	public const CHANGED_OPTION = 'estasoft_nd_choices_changed';

	private Plugin $plugin;

	/**
	 * Creates the service.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The sentence every screen uses for a channel left out by a choice.
	 *
	 * @return string
	 */
	public static function message(): string {
		return __( 'Left out by a choice for this article.', 'estasoft-news-distribution' );
	}

	/**
	 * Whether a channel may carry an article, as far as the person's choice goes.
	 *
	 * @param Article $article    The article.
	 * @param string  $channel_id Channel id.
	 * @return bool False when the channel is switched off for this article.
	 */
	public function channel_allowed( Article $article, string $channel_id ): bool {
		return ! $this->is_left_out( $article->id(), $channel_id );
	}

	/**
	 * Whether a channel is switched off for one article.
	 *
	 * @param int    $post_id    Article ID.
	 * @param string $channel_id Channel id.
	 * @return bool
	 */
	public function is_left_out( int $post_id, string $channel_id ): bool {
		return $post_id > 0 && '' !== $channel_id && ArticleOptions::channel_is_off( $post_id, $channel_id );
	}

	/**
	 * Whether the document of a pulled channel leaves the article out.
	 *
	 * Bing, Yandex and Baidu read one sitemap, and a sitemap cannot tell them apart, so it leaves the article out
	 * only when every one of them that is on has it switched off. A channel with a document of its own (or a
	 * pushed channel) leaves it out when its own switch is off.
	 *
	 * @param int              $post_id Article ID.
	 * @param ChannelInterface $channel The channel.
	 * @return bool
	 */
	public function leaves_out( int $post_id, ChannelInterface $channel ): bool {
		foreach ( $this->sharing( $channel ) as $other ) {
			if ( ! $this->is_left_out( $post_id, $other->id() ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The names of the other channels that keep a shared document carrying an article this channel was switched off for.
	 *
	 * @param int              $post_id Article ID.
	 * @param ChannelInterface $channel The channel that was switched off.
	 * @return string[] Empty when the document leaves the article out, or is not shared.
	 */
	public function kept_by( int $post_id, ChannelInterface $channel ): array {
		$names = array();

		foreach ( $this->sharing( $channel ) as $other ) {
			if ( $other->id() !== $channel->id() && ! $this->is_left_out( $post_id, $other->id() ) ) {
				$names[] = $other->label();
			}
		}

		return $names;
	}

	/**
	 * The channels that read the same document as one channel, itself first: the ones that are on and use its address.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return ChannelInterface[]
	 */
	private function sharing( ChannelInterface $channel ): array {
		$found = array( $channel );
		$slug  = $channel->feed_slug();

		if ( '' === $slug ) {
			return $found;
		}

		// Not `serving()`: only one of the search channels answers the address, but every one that is on reads it.
		foreach ( $this->plugin->registry()->all() as $other ) {
			if ( $other->id() !== $channel->id() && $slug === $other->feed_slug() && $other->is_enabled() ) {
				$found[] = $other;
			}
		}

		return $found;
	}

	/**
	 * The ids of the channels switched off for one article.
	 *
	 * @param int $post_id Article ID.
	 * @return string[] Sorted.
	 */
	public function left_out( int $post_id ): array {
		return self::off_ids( get_post_meta( $post_id, ArticleOptions::META, true ) );
	}

	/**
	 * The channel ids a stored value of the options switches off.
	 *
	 * @param mixed $value The stored options, in any state.
	 * @return string[] Sorted.
	 */
	public static function off_ids( $value ): array {
		$channels = is_array( $value ) ? ArticleOptions::sanitize( $value )['channels'] ?? array() : array();
		$off      = array();

		foreach ( is_array( $channels ) ? $channels : array() as $id => $on ) {
			if ( false === $on ) {
				$off[] = (string) $id;
			}
		}

		sort( $off );

		return $off;
	}

	/**
	 * Takes out of a channel's document the articles switched off for it.
	 *
	 * @param array<int, Article> $articles The articles.
	 * @param ChannelInterface    $channel  The channel the document is for.
	 * @return array<int, Article>
	 */
	public function filter( array $articles, ChannelInterface $channel ): array {
		if ( array() === $articles ) {
			return $articles;
		}

		$ids = array();

		foreach ( $articles as $article ) {
			$ids[] = $article->id();
		}

		// One query for the choices of every article, whether or not the reader already loaded them.
		update_meta_cache( 'post', $ids );

		$kept = array();

		foreach ( $articles as $article ) {
			if ( ! $this->leaves_out( $article->id(), $channel ) ) {
				$kept[] = $article;
			}
		}

		return $kept;
	}

	/**
	 * A choice changed: the feeds built before it still carry the article, so they are built again.
	 *
	 * The feed cache is versioned, so this makes every stored document stale at once. The time is kept so
	 * that a platform which only asks "has it changed since?" is not told "no" after an article left the document.
	 *
	 * @return void
	 */
	public function changed(): void {
		$this->plugin->cache()->flush();

		update_option( self::CHANGED_OPTION, Clock::now(), false );
	}

	/**
	 * When a choice last changed, or zero.
	 *
	 * @return int Unix time.
	 */
	public function changed_at(): int {
		return max( 0, (int) get_option( self::CHANGED_OPTION, 0 ) );
	}
}

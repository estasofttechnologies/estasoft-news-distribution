<?php
/**
 * Contract every channel adapter implements.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Event;

defined( 'ABSPATH' ) || exit;

/**
 * A channel is one destination: a sitemap, a feed profile, a push target.
 *
 * Extend AbstractChannel rather than implementing this directly; it supplies
 * the settings, enabled switch, language filter and default health.
 *
 * Channel ids are the ids the REST API uses (`google-news`, `microsoft-start`).
 * Their settings are stored under the id with hyphens turned to underscores
 * (`estasoft_nd_channels.google_news`).
 */
interface ChannelInterface {

	/**
	 * Pulled channels are fetched by a crawler or aggregator.
	 */
	public const MODE_PULLED = 'pulled';

	/**
	 * Pushed channels are sent through the background queue.
	 */
	public const MODE_PUSHED = 'pushed';

	/**
	 * Manual channels send nothing and serve nothing of their own. They hold a
	 * checklist the person follows (listings, verification).
	 */
	public const MODE_MANUAL = 'manual';

	/**
	 * Stable id used in settings, cache keys, logs and REST routes.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Name as the platform writes it, such as "Google News".
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * A Group constant: where the channel sits on the Channels hub.
	 *
	 * @return string
	 */
	public function group(): string;

	/**
	 * Position inside the group. Lower comes first.
	 *
	 * @return int
	 */
	public function order(): int;

	/**
	 * MODE_PULLED, MODE_PUSHED or MODE_MANUAL.
	 *
	 * @return string
	 */
	public function mode(): string;

	/**
	 * Whether the site owner switched the channel on.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool;

	/**
	 * Whether the channel carries articles in a language.
	 *
	 * @param string $language Language code.
	 * @return bool
	 */
	public function accepts_language( string $language ): bool;

	/**
	 * The public address segment this channel's feed is served under:
	 * `/feed/{slug}/`. Most channels use their own id. Channels that share one
	 * document (Bing, Yandex and Baidu read the same search sitemap) return the
	 * same slug. Manual and pushed channels may return an empty string.
	 *
	 * @return string
	 */
	public function feed_slug(): string;

	/**
	 * Whether this channel serves the feed at a slug.
	 *
	 * @param string $slug Feed slug from the address.
	 * @return bool
	 */
	public function serves( string $slug ): bool;

	/**
	 * Media type of the feed document, such as `application/xml; charset=UTF-8`.
	 *
	 * @return string
	 */
	public function content_type(): string;

	/**
	 * Which articles the feed draws from: how far back, and how many at most.
	 *
	 * @return array{max_age: int, limit: int} Seconds and a count.
	 */
	public function feed_window(): array;

	/**
	 * Settings fields, in the settings framework's field-spec format
	 * (see Core\Settings\Validator). `enabled` is added automatically.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array;

	/**
	 * One line about this channel's rules for the pre-publish gate list, or an
	 * empty string when an article cannot fail any rule here.
	 *
	 * @return string
	 */
	public function gate_note(): string;

	/**
	 * Checks one article against the channel's rules.
	 *
	 * @param Article $article The article.
	 * @return ValidationResult
	 */
	public function validate( Article $article ): ValidationResult;

	/**
	 * Builds the channel's output: a sitemap or feed document for pulled
	 * channels, or a request body for pushed ones.
	 *
	 * @param Article[] $articles Articles in one language.
	 * @param string    $language Language code.
	 * @param int|null  $now      Unix time to treat as now. Defaults to the current time.
	 * @return string
	 */
	public function build( array $articles, string $language, ?int $now = null ): string;

	/**
	 * Sends an event to the destination. Pulled and manual channels return DeliveryResult::not_applicable().
	 *
	 * @param Event        $event   The event.
	 * @param Article|null $article The article, or null when it was removed.
	 * @return DeliveryResult
	 */
	public function deliver( Event $event, ?Article $article ): DeliveryResult;

	/**
	 * Seconds to wait before a pushed delivery of this event runs. Zero sends at once.
	 *
	 * @param Event $event The event.
	 * @return int
	 */
	public function delay_seconds( Event $event ): int;

	/**
	 * Health of the channel as a Core\Status code: healthy, attention,
	 * not_set_up or processing.
	 *
	 * @return string
	 */
	public function health(): string;

	/**
	 * The rows this channel contributes to the Channels hub.
	 *
	 * Most channels give one row. A channel that stands for several platforms
	 * (open readers, listings) gives several.
	 *
	 * @return array<int, array{key: string, name: string, summary: string, status: string, enabled: bool|null, action: string}>
	 */
	public function hub_rows(): array;
}

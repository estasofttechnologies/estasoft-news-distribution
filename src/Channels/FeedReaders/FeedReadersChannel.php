<?php
/**
 * Open feed readers: Feedly, Inoreader, NewsBlur.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\FeedReaders;

use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * A plain RSS feed with the full text of every article, one per language, at
 * `/feed/main/?lang=xx`. Any reader can follow it: there is no application. It is
 * always on, and the page head advertises it (`FeedDiscoveryModule`) so a reader
 * can find it from any article.
 *
 * The suggested Flipboard topic for each category is stored here, with this
 * channel's settings, because it is chosen on this channel's page. The Flipboard
 * feed itself is `FlipboardChannel`.
 */
final class FeedReadersChannel extends AbstractFeedChannel {

	public function id(): string {
		return 'feed-readers';
	}

	public function label(): string {
		return __( 'Feed readers', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 60;
	}

	public function feed_slug(): string {
		return 'main';
	}

	public function gate_note(): string {
		return __( 'Full text and valid RSS.', 'estasoft-news-distribution' );
	}

	/**
	 * The open feed is always on: any reader may follow it, and nothing is sent anywhere.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return true;
	}

	protected function is_flipboard(): bool {
		return false;
	}

	/**
	 * The settings of the channel page: whether the page head advertises the
	 * feed, and the suggested Flipboard topic for each category.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'advertise' => array(
				'type'      => 'bool',
				'label'     => __( 'Advertise the feed on every page', 'estasoft-news-distribution' ),
				'row_label' => __( 'Discovery', 'estasoft-news-distribution' ),
				'default'   => true,
				'help'      => __( 'Adds a link in the page head so readers find your feed from any article.', 'estasoft-news-distribution' ),
			),
			'topics'    => array(
				'type'        => 'map',
				'label'       => __( 'Flipboard topic per category', 'estasoft-news-distribution' ),
				'default'     => array(),
				'item'        => array(
					'type'    => 'enum',
					'options' => self::topic_options(),
				),
				'key_pattern' => '/^\d{1,10}$/',
				'max_items'   => 500,
			),
		);
	}

	/**
	 * Whether the page head advertises the feed.
	 *
	 * @return bool
	 */
	public function advertised(): bool {
		return (bool) ( $this->settings()['advertise'] ?? true );
	}

	/**
	 * The topics a category can suggest to Flipboard, as key => label for the page.
	 *
	 * @return array<string, string>
	 */
	public static function topic_options(): array {
		return array(
			'none'       => __( 'Let Flipboard decide', 'estasoft-news-distribution' ),
			'news'       => __( 'News', 'estasoft-news-distribution' ),
			'sports'     => __( 'Sports', 'estasoft-news-distribution' ),
			'opinion'    => __( 'Opinion', 'estasoft-news-distribution' ),
			'business'   => __( 'Business', 'estasoft-news-distribution' ),
			'technology' => __( 'Technology', 'estasoft-news-distribution' ),
			'travel'     => __( 'Travel', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * The topic text written into the Flipboard feed for each key. It is read by
	 * a machine, so it is not translated. An empty text means no suggestion.
	 *
	 * @return array<string, string>
	 */
	public static function topic_feed_labels(): array {
		return array(
			'none'       => '',
			'news'       => 'News',
			'sports'     => 'Sports',
			'opinion'    => 'Opinion',
			'business'   => 'Business',
			'technology' => 'Technology',
			'travel'     => 'Travel',
		);
	}

	/**
	 * Always on, so Healthy.
	 *
	 * @return string
	 */
	public function health(): string {
		return Status::HEALTHY;
	}

	/**
	 * The hub row for the readers that need no setup. It has no switch: it is always on.
	 *
	 * @return array<int, array{key: string, name: string, summary: string, status: string, enabled: bool|null, action: string}>
	 */
	public function hub_rows(): array {
		return array(
			array(
				'key'     => $this->id(),
				'name'    => __( 'Feedly, Inoreader and NewsBlur', 'estasoft-news-distribution' ),
				'summary' => $this->hub_summary(),
				'status'  => $this->health(),
				'enabled' => null,
				'action'  => 'open',
			),
		);
	}

	protected function hub_summary(): string {
		return $this->advertised()
			? __( 'Always on. Your feed is advertised on every page.', 'estasoft-news-distribution' )
			: __( 'Always on. Your feed is not advertised on your pages.', 'estasoft-news-distribution' );
	}
}

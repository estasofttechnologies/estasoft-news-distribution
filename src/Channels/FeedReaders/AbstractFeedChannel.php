<?php
/**
 * What the open-reader channels share: one plain RSS feed per language.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\FeedReaders;

use Estasoft\NewsDistribution\Channels\AbstractChannel;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * The feed for open readers (`/feed/main/`) and the Flipboard feed
 * (`/feed/flipboard/`) are the same RSS document, built by `FeedBuilder`. They
 * are two channels because a channel builds one document and does not know which
 * address asked for it.
 */
abstract class AbstractFeedChannel extends AbstractChannel {

	/**
	 * Items in one feed. News Distribution's own size, not a limit of any reader.
	 */
	public const ITEMS = 25;

	/**
	 * Whether this feed carries the Flipboard additions: image sizes and a suggested topic.
	 *
	 * @return bool
	 */
	abstract protected function is_flipboard(): bool;

	public function group(): string {
		return Group::READERS;
	}

	public function mode(): string {
		return self::MODE_PULLED;
	}

	public function content_type(): string {
		return 'application/rss+xml; charset=UTF-8';
	}

	public function feed_window(): array {
		return array(
			'max_age' => YEAR_IN_SECONDS,
			'limit'   => $this->items(),
		);
	}

	/**
	 * Items in the feed.
	 *
	 * @return int
	 */
	public function items(): int {
		return self::ITEMS;
	}

	/**
	 * Builds the feed for one language.
	 *
	 * @param \Estasoft\NewsDistribution\Core\Article[] $articles Candidate articles.
	 * @param string                                    $language Language code of this feed.
	 * @param int|null                                  $now      Unix time to treat as now.
	 * @return string
	 */
	public function build( array $articles, string $language, ?int $now = null ): string {
		$name        = $this->context->settings->publication_name( $language );
		$description = function_exists( 'get_bloginfo' ) ? trim( (string) get_bloginfo( 'description' ) ) : '';

		return FeedBuilder::build(
			$articles,
			$language,
			null === $now ? Clock::now() : $now,
			array(
				'title'       => $name,
				'link'        => home_url( '/' ),
				'description' => '' !== $description ? $description : $name,
				'self'        => $this->feed_url( $language ),
				'hub'         => $this->websub_hub(),
				'channel'     => $this->id(),
				'flipboard'   => $this->is_flipboard(),
				'topics'      => $this->is_flipboard() ? $this->topic_names() : array(),
				'limit'       => $this->items(),
			)
		);
	}

	/**
	 * The plugin's own check of a built feed, in plain words.
	 *
	 * @param string $body     The document.
	 * @param string $language Language code.
	 * @return array{valid: bool, entries: int, problems: string[]}
	 */
	public function check_output( string $body, string $language ): array {
		unset( $language );

		$checked = FeedBuilder::check( $body, $this->items() );

		return array(
			'valid'    => $checked['valid'],
			'entries'  => $checked['entries'],
			'problems' => $checked['problems'],
		);
	}

	/**
	 * The address of this feed for one language.
	 *
	 * @param string $language Language code.
	 * @return string
	 */
	public function feed_url( string $language ): string {
		return add_query_arg( 'lang', rawurlencode( $language ), home_url( '/feed/' . $this->feed_slug() . '/' ) );
	}

	/**
	 * The WebSub hub to name in the feed, or an empty string. Read from the WebSub
	 * channel's settings (`channels.websub.hub`) when that channel is on and has a
	 * hub; an empty string means the feed names no hub.
	 *
	 * @return string
	 */
	public function websub_hub(): string {
		$row = $this->context->channels->all()['websub'] ?? array();

		if ( ! is_array( $row ) || empty( $row['enabled'] ) ) {
			return '';
		}

		$hub = $row['hub'] ?? '';

		if ( '' === (string) $hub && isset( $row['hubs'] ) && is_array( $row['hubs'] ) ) {
			$hub = (string) reset( $row['hubs'] );
		}

		$hub    = trim( (string) $hub );
		$scheme = (string) wp_parse_url( $hub, PHP_URL_SCHEME );

		return in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ? $hub : '';
	}

	/**
	 * The suggested Flipboard topic for each category, by category name. Only the
	 * Flipboard feed uses it. The choices are stored as category id => topic key
	 * with the open-reader channel's settings.
	 *
	 * @return array<string, string> Category name => topic as Flipboard shows it.
	 */
	protected function topic_names(): array {
		$stored = $this->context->channels->all()['feed_readers']['topics'] ?? array();
		$labels = FeedReadersChannel::topic_feed_labels();
		$names  = array();

		foreach ( is_array( $stored ) ? $stored : array() as $term_id => $key ) {
			if ( ! isset( $labels[ (string) $key ] ) || '' === $labels[ (string) $key ] ) {
				continue;
			}

			$term = get_term( (int) $term_id, 'category' );

			if ( $term instanceof \WP_Term ) {
				$names[ $term->name ] = $labels[ (string) $key ];
			}
		}

		return $names;
	}
}

<?php
/**
 * Flipboard.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\FeedReaders;

use Estasoft\NewsDistribution\Channels\SearchSitemap\FetchSummary;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * The same RSS feed as the open readers get, with the featured image's width and
 * height and a suggested topic added to each item, at `/feed/flipboard/?lang=xx`.
 * Flipboard also has a publisher program on its own site; this channel prepares
 * the feed and never claims to know what Flipboard decided.
 *
 * Its page is the open-readers page (`channel=feed-readers`).
 */
final class FlipboardChannel extends AbstractFeedChannel {

	public function id(): string {
		return 'flipboard';
	}

	public function label(): string {
		return __( 'Flipboard', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 55;
	}

	public function gate_note(): string {
		return __( 'Full text and the size of the featured image.', 'estasoft-news-distribution' );
	}

	protected function is_flipboard(): bool {
		return true;
	}

	/**
	 * Always on, like the open feed: Flipboard needs no application for the feed
	 * itself, and nothing is sent anywhere.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return true;
	}

	/**
	 * Always on, so Healthy. Flipboard's publisher program is its own business.
	 *
	 * @return string
	 */
	public function health(): string {
		return Status::HEALTHY;
	}

	/**
	 * One hub row, with no switch: the feed is always on.
	 *
	 * @return array<int, array{key: string, name: string, summary: string, status: string, enabled: bool|null, action: string}>
	 */
	public function hub_rows(): array {
		return array(
			array(
				'key'     => $this->id(),
				'name'    => $this->label(),
				'summary' => $this->hub_summary(),
				'status'  => $this->health(),
				'enabled' => null,
				'action'  => 'open',
			),
		);
	}

	/**
	 * Items in the feed, from the rule pack.
	 *
	 * @return int
	 */
	public function items(): int {
		return max( 1, (int) $this->context->rules->limit( $this->id(), 'items', self::ITEMS ) );
	}

	/**
	 * When Flipboard last fetched the feed, in any language, or zero.
	 *
	 * @return int Unix time.
	 */
	public function last_fetch(): int {
		$last = 0;

		foreach ( $this->fetch_report() as $state ) {
			$last = max( $last, (int) $state['last_ts'] );
		}

		return $last;
	}

	/**
	 * How Flipboard's fetches look per language, read by crawler name.
	 *
	 * @return array<string, array{status: string, last_ts: int, fetches_24h: int, agent: string}>
	 */
	public function fetch_report(): array {
		$languages = array();

		foreach ( $this->context->languages->languages() as $language ) {
			if ( $this->accepts_language( (string) $language ) ) {
				$languages[] = (string) $language;
			}
		}

		$agents = (array) ( $this->context->rules->pack( $this->id() )['fetch']['agents'] ?? array( 'flipboard' ) );

		return FetchSummary::states( array( $this->id() ), array_map( 'strval', $agents ), $languages, $this->context->rules->fetch_interval_hours( $this->id() ) );
	}

	protected function fetch_states(): array {
		return $this->fetch_report();
	}

	protected function hub_summary(): string {
		$last = $this->last_fetch();

		if ( $last <= 0 ) {
			return __( 'Not fetched yet.', 'estasoft-news-distribution' );
		}

		return sprintf(
			/* translators: 1: number of items in the feed, 2: how long ago Flipboard fetched it, such as "31 min ago". */
			_n( '%1$s item. Fetched %2$s.', '%1$s items. Fetched %2$s.', $this->items(), 'estasoft-news-distribution' ),
			number_format_i18n( $this->items() ),
			FetchSummary::ago( $last )
		);
	}
}

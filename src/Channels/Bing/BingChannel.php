<?php
/**
 * Bing Webmaster Tools channel.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Bing;

use Estasoft\NewsDistribution\Channels\SearchSitemap\AbstractSearchSitemapChannel;

defined( 'ABSPATH' ) || exit;

/**
 * Bing reads the plain search sitemap. This channel builds it (the shared work
 * is in `AbstractSearchSitemapChannel`), checks it and tells the person what to
 * do in Bing Webmaster Tools. It cannot see inside Bing Webmaster Tools, so the
 * steps there are ticked by hand.
 *
 * When both Bing and Yandex are on, Bing answers the shared sitemap address.
 */
final class BingChannel extends AbstractSearchSitemapChannel {

	public function id(): string {
		return 'bing';
	}

	public function label(): string {
		return __( 'Bing Webmaster Tools', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 20;
	}

	public function gate_note(): string {
		return __( 'Search sitemap: public articles of each language.', 'estasoft-news-distribution' );
	}

	/**
	 * Bing serves the shared sitemap address. The feed controller only answers
	 * through a channel that is on, so an off Bing leaves the address to Yandex.
	 *
	 * @param string $slug Feed slug.
	 * @return bool
	 */
	public function serves( string $slug ): bool {
		return self::SLUG === $slug;
	}
}

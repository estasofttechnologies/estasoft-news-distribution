<?php
/**
 * The Bing Webmaster Tools channel page.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels&channel=bing`. The sitemap, the three
 * steps in Bing Webmaster Tools (ticked by hand), the rules and the limits.
 */
final class BingScreen extends AbstractSearchScreen {

	public static function channel_id(): string {
		return 'bing';
	}

	public function description(): string {
		return __( 'Pulled channel: News Distribution builds the sitemap and Bing fetches it on its own schedule. Bing decides what it indexes.', 'estasoft-news-distribution' );
	}

	protected function platform(): string {
		return __( 'Bing', 'estasoft-news-distribution' );
	}

	protected function crawler_name(): string {
		return 'Bingbot';
	}

	protected function rule_sentences(): array {
		return array(
			'sitemap.format'   => __( 'Standard XML sitemap in UTF-8', 'estasoft-news-distribution' ),
			'sitemap.max_urls' => sprintf(
				/* translators: %s: the most URLs the sitemap protocol allows in one file, such as 50,000. */
				__( 'Up to %s URLs per file', 'estasoft-news-distribution' ),
				number_format_i18n( (int) $this->plugin->rules()->limit( 'bing', 'protocol_max_urls', 50000 ) )
			),
			'sitemap.lastmod'  => __( 'An accurate last-modified time on every URL', 'estasoft-news-distribution' ),
			'sitemap.robots'   => __( 'Listed in robots.txt when you turn that on', 'estasoft-news-distribution' ),
			'sitemap.language' => __( 'Only articles of the language in that sitemap', 'estasoft-news-distribution' ),
		);
	}

	protected function cannot_card(): string {
		return $this->cannot_paragraph(
			__( 'It cannot make Bing index an article or fetch a sitemap sooner, and it cannot see inside Bing Webmaster Tools. Bing decides what it indexes. The fetch times above are the requests Bingbot makes to your sitemap.', 'estasoft-news-distribution' )
		);
	}

	protected function steps_copy(): array {
		return array(
			'ready'              => __( 'News Distribution builds one search sitemap per language and keeps the last-modified time accurate.', 'estasoft-news-distribution' ),
			'verify_title'       => __( 'Verify your site in Bing Webmaster Tools', 'estasoft-news-distribution' ),
			'verify_text'        => __( 'Add your site and prove you own it with a method Bing offers: an XML file, a meta tag or a DNS record.', 'estasoft-news-distribution' ),
			/* translators: %s: a day such as "24 Sep". */
			'verify_label'       => __( 'I verified my site on %s', 'estasoft-news-distribution' ),
			'verify_label_plain' => __( 'I verified my site', 'estasoft-news-distribution' ),
			'verify_help'        => __( 'News Distribution cannot see inside Bing Webmaster Tools, so you tick this yourself.', 'estasoft-news-distribution' ),
			'verify_link'        => __( 'Bing Webmaster Tools', 'estasoft-news-distribution' ),
			'verify_url'         => 'https://www.bing.com/webmasters',
			'submit_title'       => __( 'Submit the sitemaps', 'estasoft-news-distribution' ),
			'submit_text'        => __( 'In Bing Webmaster Tools open Sitemaps, choose Submit a sitemap and paste each URL from the card above. Bing reads a new sitemap on its own schedule, usually soon after you submit it.', 'estasoft-news-distribution' ),
			'submit_label'       => '',
			'submit_label_plain' => __( 'I submitted the sitemaps', 'estasoft-news-distribution' ),
			'submit_note'        => '',
			'robots_help'        => __( 'Bing can find them on its own and read them again later.', 'estasoft-news-distribution' ),
		);
	}
}

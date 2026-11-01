<?php
/**
 * The Yandex Webmaster channel page.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Ui\Card;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels&channel=yandex`. Like the Bing page,
 * with Yandex's own numbers (two weeks to a first read, how often a file can be
 * read again, the longest address) taken from the rule pack.
 */
final class YandexScreen extends AbstractSearchScreen {

	public static function channel_id(): string {
		return 'yandex';
	}

	public function description(): string {
		return sprintf(
			/* translators: %s: how long Yandex takes to read a new sitemap, such as "two weeks". */
			__( 'Pulled channel: News Distribution builds the sitemap and Yandex reads it on its own schedule, usually within %s of you adding it. Yandex decides what it indexes.', 'estasoft-news-distribution' ),
			$this->first_read()
		);
	}

	protected function platform(): string {
		return __( 'Yandex', 'estasoft-news-distribution' );
	}

	protected function crawler_name(): string {
		return 'YandexBot';
	}

	protected function rule_sentences(): array {
		return array(
			'sitemap.standard_elements' => __( 'Plain sitemap with only standard elements', 'estasoft-news-distribution' ),
			'sitemap.same_domain'       => __( 'URLs on the same domain as the site', 'estasoft-news-distribution' ),
			'url.length'                => sprintf(
				/* translators: %s: the longest address allowed, in characters, such as 2,048. */
				__( 'Each URL at most %s characters', 'estasoft-news-distribution' ),
				number_format_i18n( (int) $this->plugin->rules()->limit( 'yandex', 'url_max_chars', 2048 ) )
			),
			'sitemap.reachable'         => __( 'Answers 200 and is not blocked in robots.txt', 'estasoft-news-distribution' ),
			'sitemap.not_feed'          => __( 'A sitemap file, never an RSS or Atom feed', 'estasoft-news-distribution' ),
		);
	}

	protected function cannot_card(): string {
		return Card::cannot(
			array(
				__( 'Make Yandex read a sitemap sooner, or index an article. It cannot see whether an article is listed.', 'estasoft-news-distribution' ),
				__( 'Read Yandex Webmaster without the optional Yandex connection in Settings, Connections. With it, News Distribution can add your sitemaps and read the status Yandex shows for each file: OK reads Healthy, In the queue reads Processing, an error reads Needs attention.', 'estasoft-news-distribution' ),
				__( 'Show an error\'s cause. Open the file\'s details in Yandex Webmaster. Without the connection, the channel shows Processing until YandexBot has fetched your sitemap, then Healthy.', 'estasoft-news-distribution' ),
			)
		);
	}

	protected function steps_copy(): array {
		$rules = $this->plugin->rules();

		return array(
			'ready'              => __( 'News Distribution builds a plain sitemap with only the standard elements.', 'estasoft-news-distribution' ),
			'verify_title'       => __( 'Verify your site in Yandex Webmaster', 'estasoft-news-distribution' ),
			'verify_text'        => __( 'Add your site, with the exact address including http or https and the www prefix.', 'estasoft-news-distribution' ),
			/* translators: %s: a short date such as "24 Sep". */
			'verify_label'       => __( 'I verified my site on %s', 'estasoft-news-distribution' ),
			'verify_label_plain' => __( 'I verified my site', 'estasoft-news-distribution' ),
			'verify_help'        => __( 'News Distribution cannot see inside Yandex Webmaster, so you tick this yourself.', 'estasoft-news-distribution' ),
			'verify_link'        => __( 'Yandex Webmaster', 'estasoft-news-distribution' ),
			'verify_url'         => 'https://webmaster.yandex.com/',
			'submit_title'       => __( 'Add the sitemaps', 'estasoft-news-distribution' ),
			'submit_text'        => sprintf(
				/* translators: %s: how long Yandex takes to read a new sitemap, such as "two weeks". */
				__( 'In Yandex Webmaster open Indexing, then Sitemap files, choose your site, paste each URL and add it. Yandex downloads a newly added file within %s.', 'estasoft-news-distribution' ),
				$this->first_read()
			),
			/* translators: %s: a short date such as "24 Sep". */
			'submit_label'       => __( 'I added the sitemaps on %s', 'estasoft-news-distribution' ),
			'submit_label_plain' => __( 'I added the sitemaps', 'estasoft-news-distribution' ),
			'submit_note'        => sprintf(
				/* translators: 1: how many times a file can be read again, such as 10; 2: days to wait after that, such as 30. */
				__( 'After a change you can ask Yandex to read a file again, up to %1$s times for each site address. After that, try again %2$s days after the first try.', 'estasoft-news-distribution' ),
				number_format_i18n( (int) $rules->limit( 'yandex', 'reread_requests', 10 ) ),
				number_format_i18n( (int) $rules->limit( 'yandex', 'reread_wait_days', 30 ) )
			),
			'robots_help'        => __( 'Yandex reads the Sitemap line there too.', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * How long Yandex takes to read a new sitemap, in words: "two weeks" or "20 days".
	 *
	 * @return string
	 */
	private function first_read(): string {
		$days = (int) $this->plugin->rules()->limit( 'yandex', 'first_read_days', 14 );

		if ( 14 === $days ) {
			return __( 'two weeks', 'estasoft-news-distribution' );
		}

		return sprintf(
			/* translators: %s: a number of days. */
			_n( '%s day', '%s days', $days, 'estasoft-news-distribution' ),
			number_format_i18n( $days )
		);
	}
}

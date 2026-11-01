<?php
/**
 * Baidu Webmaster Tools channel.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Baidu;

use Estasoft\NewsDistribution\Channels\SearchSitemap\AbstractSearchSitemapChannel;
use Estasoft\NewsDistribution\Channels\SearchSitemap\FetchSummary;
use Estasoft\NewsDistribution\Channels\ValidationResult;
use Estasoft\NewsDistribution\Core\Article;

defined( 'ABSPATH' ) || exit;

/**
 * Baidu reads the same plain search sitemap as Bing and Yandex. This channel
 * builds it with the shared builder (the work is in `AbstractSearchSitemapChannel`),
 * checks it and tells the person what to do in Baidu Webmaster Tools. News
 * Distribution does not connect to Baidu and cannot see inside it, so the account,
 * the verification and the submission are ticked by hand.
 *
 * Baidu answers the shared sitemap address only when neither Bing nor Yandex is
 * on, so exactly one switched-on channel answers an address. Baiduspider is still
 * recognised by its name in the fetch log whichever channel answered.
 *
 * Every rule in the Baidu pack comes from third-party guides or from News
 * Distribution itself, so none is stated as Baidu's own.
 */
final class BaiduChannel extends AbstractSearchSitemapChannel {

	public function id(): string {
		return 'baidu';
	}

	public function label(): string {
		return __( 'Baidu Webmaster Tools', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 40;
	}

	public function gate_note(): string {
		return __( 'Search sitemap: public articles of each language.', 'estasoft-news-distribution' );
	}

	/**
	 * Serves the shared sitemap address unless Bing or Yandex is on and serves it.
	 *
	 * @param string $slug Feed slug.
	 * @return bool
	 */
	public function serves( string $slug ): bool {
		return self::SLUG === $slug
			&& ! $this->context->channels->is_enabled( 'bing' )
			&& ! $this->context->channels->is_enabled( 'yandex' );
	}

	/**
	 * Days to wait for the first fetch before the channel needs attention. No Baidu figure
	 * was confirmed, so this is News Distribution's own yardstick and it is a long one:
	 * a short wait would raise a false alarm.
	 *
	 * @return int
	 */
	public function first_fetch_days(): int {
		return max( 1, (int) $this->context->rules->limit( $this->id(), 'first_read_days', 14 ) );
	}

	/**
	 * The steps the person ticks by hand: the account, the verified site and the
	 * submitted sitemaps (with the day each was ticked), and which sitemap to submit.
	 * There is no robots.txt box: the Baidu page does not offer one.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		$fields = parent::fields();

		unset( $fields['robots'] );

		return array_merge(
			array(
				'account' => array(
					'type'    => 'bool',
					'label'   => __( 'I have a Baidu account', 'estasoft-news-distribution' ),
					'default' => false,
				),
			),
			$fields
		);
	}

	/**
	 * Checks one article against the sitemap News Distribution builds: an address of
	 * at most 2,048 characters. That is News Distribution's own limit, not a rule
	 * Baidu publishes.
	 *
	 * @param Article $article The article.
	 * @return ValidationResult
	 */
	public function validate( Article $article ): ValidationResult {
		$result = new ValidationResult();
		$limit  = max( 1, (int) $this->context->rules->limit( $this->id(), 'url_max_chars', 2048 ) );

		if ( strlen( $article->permalink() ) > $limit ) {
			$result->add(
				$this->context->rules->severity( $this->id(), 'url.length' ),
				'url.length',
				sprintf(
					/* translators: %s: largest number of characters allowed in an address. */
					__( 'The article address is longer than %s characters, so it is left out of the sitemap. Shorten the permalink.', 'estasoft-news-distribution' ),
					number_format_i18n( $limit )
				)
			);
		}

		return $result;
	}

	/**
	 * The line under "Not set up" on the Overview path, until the person ticks that they
	 * have an account. It says what they meet, in words that stay careful.
	 *
	 * @return string Plain text, or an empty string once the account is ticked.
	 */
	public function path_note(): string {
		if ( ! empty( $this->settings()['account'] ) ) {
			return '';
		}

		return __( 'The console is in Chinese, and registering may ask for a phone number.', 'estasoft-news-distribution' );
	}

	/**
	 * What the hub says under the name: what is still to do, from the ticks, and once
	 * the sitemaps are submitted, what Baiduspider has done.
	 *
	 * @return string
	 */
	protected function hub_summary(): string {
		$settings = $this->settings();

		if ( empty( $settings['account'] ) || empty( $settings['verified'] ) ) {
			return __( 'Needs a Baidu account and a verified site', 'estasoft-news-distribution' );
		}

		if ( empty( $settings['submitted'] ) ) {
			return __( 'Submit the sitemaps in Baidu Webmaster Tools', 'estasoft-news-distribution' );
		}

		if ( ! $this->is_enabled() ) {
			return __( 'Submitted. Turn the channel on so Baiduspider can read the sitemaps.', 'estasoft-news-distribution' );
		}

		$last = 0;

		foreach ( $this->fetch_report() as $state ) {
			$last = max( $last, (int) $state['last_ts'] );
		}

		if ( $last <= 0 ) {
			return __( 'Submitted. Baiduspider has not fetched yet.', 'estasoft-news-distribution' );
		}

		return sprintf(
			/* translators: 1: the name of a crawler such as Baiduspider, 2: how long ago, such as "3 h ago". */
			__( 'Submitted. Fetched by %1$s %2$s', 'estasoft-news-distribution' ),
			FetchSummary::agent_name( 'baidu' ),
			FetchSummary::ago( $last )
		);
	}
}

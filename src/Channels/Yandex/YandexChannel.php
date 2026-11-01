<?php
/**
 * Yandex Webmaster channel.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Yandex;

use Estasoft\NewsDistribution\Channels\SearchSitemap\AbstractSearchSitemapChannel;
use Estasoft\NewsDistribution\Channels\ValidationResult;
use Estasoft\NewsDistribution\Core\Article;

defined( 'ABSPATH' ) || exit;

/**
 * Yandex reads the plain search sitemap and downloads a newly added file within
 * two weeks. This channel builds the sitemap with the shared builder, checks it
 * and tells the person what to do in Yandex Webmaster. It cannot see inside
 * Yandex Webmaster, so the steps there are ticked by hand.
 *
 * Yandex answers the shared sitemap address only when Bing is off, so exactly
 * one switched-on channel answers an address.
 */
final class YandexChannel extends AbstractSearchSitemapChannel {

	public function id(): string {
		return 'yandex';
	}

	public function label(): string {
		return __( 'Yandex Webmaster', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 30;
	}

	public function gate_note(): string {
		return __( 'Search sitemap: public articles of each language, addresses up to 2,048 characters.', 'estasoft-news-distribution' );
	}

	/**
	 * Serves the shared sitemap address unless Bing is on and serves it.
	 *
	 * @param string $slug Feed slug.
	 * @return bool
	 */
	public function serves( string $slug ): bool {
		return self::SLUG === $slug && ! $this->context->channels->is_enabled( 'bing' );
	}

	/**
	 * Yandex states that a newly added sitemap is downloaded within two weeks.
	 *
	 * @return int
	 */
	public function first_fetch_days(): int {
		return max( 1, (int) $this->context->rules->limit( $this->id(), 'first_read_days', 14 ) );
	}

	/**
	 * Checks one article against Yandex's rules: an address of at most 2,048 characters.
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
}

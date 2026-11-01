<?php
/**
 * Yahoo News partner feed.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Yahoo;

use Estasoft\NewsDistribution\Channels\ChannelImage;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Channels\MicrosoftStart\MicrosoftStartChannel;
use Estasoft\NewsDistribution\Channels\RssPartner\AbstractRssPartnerChannel;
use Estasoft\NewsDistribution\Channels\ValidationResult;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Support\PartnerRss;

defined( 'ABSPATH' ) || exit;

/**
 * One RSS 2.0 feed per language at `/feed/yahoo-news/?lang=xx`. Yahoo has to
 * onboard the feed before it fetches it, and the plugin cannot see the answer,
 * so the application is recorded by hand.
 *
 * Yahoo's own feed specification is given to partners it onboards and was not
 * read for this build, so every rule here is News Distribution's own default
 * (see rules/yahoo-news.json): a minimum body length the person can change,
 * common HTML only, the featured image first, and a stable guid and original
 * date on an updated article.
 *
 * The featured image at the top of the body is the copy the image presets made
 * for this channel when the photo has one, and the original otherwise. It has a
 * `figcaption` (the photo's caption, with its credit after it when "Put the
 * credit in the caption" is on), because the body's HTML already allows that
 * element. Yahoo's public partner page does not ask for a caption, so it is
 * optional and nothing here claims Yahoo requires one.
 *
 * Kurdish is off by default (the person turns it on if the Yahoo contact asks).
 */
final class YahooChannel extends AbstractRssPartnerChannel {

	/**
	 * HTML kept in the item body. Anything else, scripts and frames included, is removed.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		return array(
			'a'          => array(
				'href'  => true,
				'title' => true,
			),
			'p'          => array(),
			'br'         => array(),
			'strong'     => array(),
			'b'          => array(),
			'em'         => array(),
			'i'          => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'blockquote' => array(),
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'figure'     => array(),
			'figcaption' => array(),
			'img'        => array(
				'src'    => true,
				'alt'    => true,
				'width'  => true,
				'height' => true,
			),
		);
	}

	public function id(): string {
		return 'yahoo-news';
	}

	public function label(): string {
		return __( 'Yahoo News', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return Group::PARTNER;
	}

	public function order(): int {
		return 20;
	}

	public function gate_note(): string {
		$settings = $this->settings();

		if ( empty( $settings['min_length'] ) ) {
			return '';
		}

		return sprintf(
			/* translators: %s: the minimum number of words. */
			__( 'A body of at least %s words. Shorter articles are left out of this feed.', 'estasoft-news-distribution' ),
			number_format_i18n( (int) $settings['min_words'] )
		);
	}

	public function fields(): array {
		return array(
			'kurdish'    => array(
				'type'    => 'bool',
				'label'   => __( 'Also build a Kurdish (Kurmanji) feed', 'estasoft-news-distribution' ),
				'help'    => __( 'Off by default. Turn it on only if your Yahoo contact asks for Kurdish.', 'estasoft-news-distribution' ),
				'default' => false,
				'control' => 'checkbox',
			),
			'urls_sent'  => array(
				'type'    => 'bool',
				'label'   => __( 'I have sent my feed URLs to Yahoo', 'estasoft-news-distribution' ),
				'default' => false,
				'control' => 'checkbox',
			),
			'min_length' => array(
				'type'    => 'bool',
				'label'   => __( 'Skip articles under a minimum length', 'estasoft-news-distribution' ),
				'help'    => __( 'Yahoo partners usually have a minimum body length, so News Distribution skips shorter articles in this feed. Ask your Yahoo contact for the real minimum.', 'estasoft-news-distribution' ),
				'default' => true,
				'control' => 'checkbox',
			),
			'min_words'  => array(
				'type'    => 'int',
				'label'   => __( 'Minimum words', 'estasoft-news-distribution' ),
				'default' => 150,
				'min'     => 1,
				'max'     => 5000,
				'width'   => 90,
			),
			'wait_delay' => array(
				'type'    => 'bool',
				'label'   => __( 'Wait for the index-first delay', 'estasoft-news-distribution' ),
				'default' => true,
				'control' => 'checkbox',
			),
		);
	}

	/**
	 * Kurdish is carried only when the person asked for it; every other language
	 * follows the site's own language settings.
	 *
	 * @param string $language Language code.
	 * @return bool
	 */
	public function accepts_language( string $language ): bool {
		$primary = strtolower( (string) strtok( str_replace( '_', '-', $language ), '-' ) );

		if ( 'ku' === $primary && empty( $this->settings()['kurdish'] ) ) {
			return false;
		}

		return parent::accepts_language( $language );
	}

	/**
	 * Articles wait for the index-first delay when this feed's own option is on,
	 * or when Microsoft Start has new articles first.
	 *
	 * @return int
	 */
	protected function hold_seconds(): int {
		$wait = ! empty( $this->settings()['wait_delay'] ) || MicrosoftStartChannel::is_exclusive( $this->context );

		return $wait ? $this->context->settings->index_first_delay_seconds() : 0;
	}

	public function validate( Article $article ): ValidationResult {
		$result   = parent::validate( $article );
		$settings = $this->settings();

		if ( empty( $settings['min_length'] ) ) {
			return $result;
		}

		$minimum = (int) $settings['min_words'];
		$words   = $article->word_count();

		if ( $words < $minimum ) {
			$this->finding(
				$result,
				'body.min_words',
				Status::BLOCK,
				sprintf(
					/* translators: 1: the number of words in the article, 2: the minimum number of words set for the Yahoo feed. */
					__( 'The body has %1$s words and the Yahoo feed skips articles under %2$s. Add text or lower the minimum in the Yahoo News settings.', 'estasoft-news-distribution' ),
					number_format_i18n( $words ),
					number_format_i18n( $minimum )
				)
			);
		}

		return $result;
	}

	protected function item_extra( Article $article, string $language ): string {
		unset( $language );

		$body = PartnerRss::html( $article );

		if ( '' !== $article->thumbnail_url() ) {
			$thumb_id = (int) get_post_thumbnail_id( $article->id() );
			$alt      = $thumb_id > 0 ? (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) : '';
			$picture  = ChannelImage::of( $article, $this->id() );
			$body     = ChannelImage::figure( $picture['url'], $alt, ChannelImage::credit( $article )['line'] ) . $body;
		}

		$body = wp_kses( $body, self::allowed_html() );

		$xml  = "\t\t\t<description>" . PartnerRss::xml( PartnerRss::summary( $article ) ) . "</description>\n";
		$xml .= "\t\t\t<content:encoded>" . PartnerRss::cdata( $body ) . "</content:encoded>\n";

		return $xml;
	}

	protected function hub_summary(): string {
		$parts = $this->summary_parts();

		if ( $this->is_enabled() && Status::NOT_REQUESTED === $this->context->applications->display_status( $this->id() ) ) {
			$parts[] = __( 'Feed is ready. Apply when ready.', 'estasoft-news-distribution' );
		}

		return implode( ' ', $parts );
	}
}

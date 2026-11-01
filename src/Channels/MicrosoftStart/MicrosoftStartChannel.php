<?php
/**
 * Microsoft Start partner feed.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\MicrosoftStart;

use Estasoft\NewsDistribution\Channels\ChannelContext;
use Estasoft\NewsDistribution\Channels\ChannelImage;
use Estasoft\NewsDistribution\Channels\ChannelSettings;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Channels\RssPartner\AbstractRssPartnerChannel;
use Estasoft\NewsDistribution\Channels\ValidationResult;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Support\PartnerRss;

defined( 'ABSPATH' ) || exit;

/**
 * One RSS 2.0 feed per language at `/feed/microsoft-start/?lang=xx`. Microsoft
 * Partner Hub is invitation-only and the plugin cannot see Microsoft's answer,
 * so the application is recorded by hand (Core\Applications).
 *
 * The thumbnail is the 16:9 copy the image presets made for this channel when the photo has
 * one (with the copy's own width and height), and the original otherwise. The item has no
 * place for the photo's credit: the feed writes `media:thumbnail` only, and no credit element
 * is known for Microsoft Start, so none is written.
 *
 * An article without a featured image is left out: Microsoft Start shows a
 * thumbnail with every headline. A thumbnail under the minimum size is kept and
 * flagged. Items with the same ID are read as one article, so the ID never
 * changes and the Updated date moves only for a real change (the date guard
 * decides that; this channel writes the modified date it is given).
 *
 * "Give Microsoft Start new articles first" (`exclusive`) makes the other pushed
 * channels that ask {@see MicrosoftStartChannel::exclusive_seconds()} wait for the
 * index-first delay. Microsoft Start itself never waits.
 */
final class MicrosoftStartChannel extends AbstractRssPartnerChannel {

	public function id(): string {
		return 'microsoft-start';
	}

	public function label(): string {
		return __( 'Microsoft Start', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return Group::PARTNER;
	}

	public function order(): int {
		return 10;
	}

	public function gate_note(): string {
		return __( 'A featured image, at least 640 × 360 px. Articles without one are left out.', 'estasoft-news-distribution' );
	}

	public function fields(): array {
		return array(
			'exclusive' => array(
				'type'    => 'bool',
				'label'   => __( 'Give Microsoft Start new articles first', 'estasoft-news-distribution' ),
				'default' => false,
				'control' => 'checkbox',
			),
		);
	}

	/**
	 * Whether Microsoft Start has new articles first: the channel is on and the
	 * "Give Microsoft Start new articles first" option is ticked.
	 *
	 * @param ChannelContext $context The channel services.
	 * @return bool
	 */
	public static function is_exclusive( ChannelContext $context ): bool {
		$row = $context->channels->all()[ ChannelSettings::key( 'microsoft-start' ) ] ?? array();

		return is_array( $row ) && ! empty( $row['enabled'] ) && ! empty( $row['exclusive'] );
	}

	/**
	 * Seconds the other pushed channels wait for a new article because Microsoft
	 * Start has it first. Zero when the option is off or the event is not a publish.
	 *
	 * Other channels call this from their own `delay_seconds()`.
	 *
	 * @param ChannelContext $context The channel services.
	 * @param Event          $event   The event.
	 * @return int
	 */
	public static function exclusive_seconds( ChannelContext $context, Event $event ): int {
		if ( Event::PUBLISH !== $event->type() || ! self::is_exclusive( $context ) ) {
			return 0;
		}

		return $context->settings->index_first_delay_seconds();
	}

	public function validate( Article $article ): ValidationResult {
		$result = parent::validate( $article );

		if ( '' === $article->thumbnail_url() ) {
			$this->finding(
				$result,
				'image.missing',
				Status::BLOCK,
				__( 'Microsoft Start shows a thumbnail with every headline, so this article is left out of the feeds. Add a featured image and it joins them the next time Microsoft reads them.', 'estasoft-news-distribution' )
			);

			return $result;
		}

		$width  = $article->thumbnail_width();
		$height = $article->thumbnail_height();

		if ( $width < 1 || $height < 1 ) {
			return $result;
		}

		$limits = array(
			'min_width'  => (int) $this->context->rules->limit( $this->id(), 'image_min_width', 640 ),
			'min_height' => (int) $this->context->rules->limit( $this->id(), 'image_min_height', 360 ),
			'rec_width'  => (int) $this->context->rules->limit( $this->id(), 'image_recommended_width', 1280 ),
			'rec_height' => (int) $this->context->rules->limit( $this->id(), 'image_recommended_height', 720 ),
		);

		if ( $width < $limits['min_width'] || $height < $limits['min_height'] ) {
			$this->finding(
				$result,
				'image.below_minimum',
				Status::WARN,
				sprintf(
					/* translators: 1: the image width, 2: the image height, 3: the minimum width, 4: the minimum height. All in pixels. */
					__( 'The featured image is %1$d × %2$d px. Microsoft Start asks for at least %3$d × %4$d px.', 'estasoft-news-distribution' ),
					$width,
					$height,
					$limits['min_width'],
					$limits['min_height']
				)
			);
		} elseif ( $width < $limits['rec_width'] || $height < $limits['rec_height'] ) {
			$this->finding(
				$result,
				'image.below_recommended',
				Status::INFO,
				sprintf(
					/* translators: 1: the image width, 2: the image height, 3: the recommended width, 4: the recommended height. All in pixels. */
					__( 'The featured image is %1$d × %2$d px. Microsoft Start recommends %3$d × %4$d px.', 'estasoft-news-distribution' ),
					$width,
					$height,
					$limits['rec_width'],
					$limits['rec_height']
				)
			);
		}

		return $result;
	}

	protected function required_fields(): array {
		return array( 'title', 'link', 'guid', 'pubDate', 'updated', 'thumbnail' );
	}

	protected function item_extra( Article $article, string $language ): string {
		unset( $language );

		$updated = max( $article->modified_ts(), $article->published_ts() );
		$picture = ChannelImage::of( $article, $this->id() );
		$image   = array(
			'url'    => $picture['url'],
			'width'  => $picture['width'],
			'height' => $picture['height'],
		);

		/**
		 * Filters the thumbnail written into a Microsoft Start item.
		 *
		 * It already holds the 16:9 copy the image presets made for this channel, when there is one, and the
		 * featured image otherwise. Return the same shape.
		 *
		 * @param array{url: string, width: int, height: int} $image   The featured image.
		 * @param Article                                     $article The article.
		 */
		$filtered = apply_filters( 'estasoft_nd_microsoft_start_thumbnail', $image, $article );
		$image    = is_array( $filtered ) && ! empty( $filtered['url'] ) ? array_merge( $image, $filtered ) : $image;

		$xml  = "\t\t\t" . '<atom:updated>' . gmdate( 'c', $updated ) . "</atom:updated>\n";
		$xml .= "\t\t\t<description>" . PartnerRss::xml( PartnerRss::summary( $article ) ) . "</description>\n";
		$xml .= "\t\t\t<content:encoded>" . PartnerRss::cdata( PartnerRss::html( $article ) ) . "</content:encoded>\n";
		$xml .= "\t\t\t" . '<media:thumbnail url="' . PartnerRss::xml( (string) $image['url'] ) . '"';

		if ( (int) $image['width'] > 0 && (int) $image['height'] > 0 ) {
			$xml .= ' width="' . (int) $image['width'] . '" height="' . (int) $image['height'] . '"';
		}

		return $xml . "/>\n";
	}

	protected function hub_summary(): string {
		return implode( ' ', $this->summary_parts() );
	}
}

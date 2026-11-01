<?php
/**
 * The featured image a channel writes: the copy made for its platform, and the photo's credit.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels;

use Estasoft\NewsDistribution\Content\ImageCredits;
use Estasoft\NewsDistribution\Content\ImagePresets;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * One place where a channel asks "which picture do I write, and what do I say about it?", so every channel
 * answers the same way.
 *
 * - `of()` is the address and size of the featured image for one channel. When the photo has a copy made
 *   for that platform (`Content\ImagePresets`, the preset id is the channel's id), it is the copy's address
 *   and the copy's own width and height. Otherwise it is the original, exactly as `Article` carries it. The
 *   original is never changed and never replaced in the media library; only the address a feed prints differs.
 * - `credit()` is the credit, the rights note and the caption of the photo (`Content\ImageCredits`). A channel
 *   prints them only where its format already has a place for one, and says in its own doc comment where it
 *   does not.
 * - `figure()` is a lead image as the body of a partner feed writes it, with a `figcaption` when there is a
 *   caption to write.
 *
 * Nothing here calls another server and nothing writes to the database.
 */
final class ChannelImage {

	/**
	 * The featured image as one channel writes it.
	 *
	 * @param Article $article    The article.
	 * @param string  $channel_id The channel's id, which is also its preset id (such as "microsoft-start").
	 * @return array{url: string, width: int, height: int, copy: bool} `copy` is true when the address is a copy made for the channel.
	 *         `url` is empty when the article has no featured image.
	 */
	public static function of( Article $article, string $channel_id ): array {
		$image      = array(
			'url'    => $article->thumbnail_url(),
			'width'  => $article->thumbnail_width(),
			'height' => $article->thumbnail_height(),
			'copy'   => false,
		);
		$attachment = $article->thumbnail_id();

		if ( '' === $image['url'] || $attachment < 1 || '' === $channel_id ) {
			return $image;
		}

		// Most channels have no copy of most photos. Reading one meta value, which a feed build has already loaded, says so
		// without touching the file system.
		$copies = get_post_meta( $attachment, ImagePresets::COPIES, true );

		if ( ! is_array( $copies ) || ! isset( $copies[ $channel_id ] ) ) {
			return $image;
		}

		$presets = Plugin::instance()->make( ImagePresets::class );
		$url     = $presets->url_for( $attachment, $channel_id );

		if ( '' === $url ) {
			return $image;
		}

		$copy = $presets->copy_of( $attachment, $channel_id );

		return array(
			'url'    => $url,
			'width'  => null === $copy ? 0 : $copy['width'],
			'height' => null === $copy ? 0 : $copy['height'],
			'copy'   => true,
		);
	}

	/**
	 * What the photo says about itself, for a channel that has a place for it.
	 *
	 * `line` is the caption with the credit after it when "Put the credit in the caption" is on, the form for a
	 * format that only has captions. `credit` and `note` are for a format with elements of their own.
	 *
	 * @param Article $article The article.
	 * @return array{credit: string, note: string, caption: string, line: string} All empty when the article has no featured image.
	 */
	public static function credit( Article $article ): array {
		$none       = array(
			'credit'  => '',
			'note'    => '',
			'caption' => '',
			'line'    => '',
		);
		$attachment = $article->thumbnail_id();

		if ( $attachment < 1 || '' === $article->thumbnail_url() ) {
			return $none;
		}

		$details = Plugin::instance()->make( ImageCredits::class )->details( $attachment );

		return array(
			'credit'  => $details['credit'],
			'note'    => $details['note'],
			'caption' => $details['caption'],
			'line'    => $details['line'],
		);
	}

	/**
	 * A lead image for the body of a feed item: the picture, and its caption when there is one.
	 *
	 * @param string $url     The picture's address.
	 * @param string $alt     Alternative text. May be empty.
	 * @param string $caption Plain text for the caption. Leave empty for none.
	 * @return string Escaped HTML.
	 */
	public static function figure( string $url, string $alt, string $caption = '' ): string {
		$caption = trim( $caption );

		return '<figure><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" />'
			. ( '' === $caption ? '' : '<figcaption>' . esc_html( $caption ) . '</figcaption>' )
			. '</figure>';
	}
}

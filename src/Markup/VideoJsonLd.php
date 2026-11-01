<?php
/**
 * The VideoObject structured data of the videos in an article.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Markup;

use Estasoft\NewsDistribution\Content\Videos;

defined( 'ABSPATH' ) || exit;

/**
 * Builds one VideoObject for each video that is ready, and encodes it for a page.
 *
 * Google asks for three things: a name, a thumbnail and an upload date. It recommends a description, a
 * duration and the address of the player (`embedUrl`) or of the file (`contentUrl`). A video that lacks one of the
 * three is not printed at all, so the page never carries markup that would be reported as incomplete. Nothing is
 * made up: the article's date is never used as an upload date, and a field with no value is left out.
 *
 * Each video is its own `<script type="application/ld+json" class="estasoft-nd-video-jsonld">` block, with the
 * same escaping as the article data (`<`, `>` and `&` become <, > and &), so a title can never
 * close the script element. The class is not the article data's class, so a page that has only video markup is
 * never taken for one that has News Distribution's article data.
 *
 * Other code can change a video's data before it is printed with the internal filter
 * `estasoft_nd_internal_video_object` (the data, the video as `Content\Videos::resolve()` returns it).
 */
final class VideoJsonLd {

	/**
	 * Builds the data of one video.
	 *
	 * @param array<string, mixed> $video A video as `Content\Videos::resolve()` returns it.
	 * @return array<string, mixed> Empty when a name, a thumbnail or an upload date is missing.
	 */
	public static function build( array $video ): array {
		$name      = trim( (string) ( $video['name'] ?? '' ) );
		$thumbnail = trim( (string) ( $video['thumbnail'] ?? '' ) );
		$date      = trim( (string) ( $video['upload_date'] ?? '' ) );

		if ( '' === $name || '' === $thumbnail || '' === $date ) {
			return array();
		}

		$data = array(
			'@context'     => 'https://schema.org',
			'@type'        => 'VideoObject',
			'name'         => $name,
			'thumbnailUrl' => $thumbnail,
			'uploadDate'   => $date,
		);

		$description = trim( (string) ( $video['description'] ?? '' ) );

		if ( '' !== $description ) {
			$data['description'] = $description;
		}

		$duration = Videos::iso_duration( (int) ( $video['duration'] ?? 0 ) );

		if ( '' !== $duration ) {
			$data['duration'] = $duration;
		}

		if ( '' !== (string) ( $video['embed_url'] ?? '' ) ) {
			$data['embedUrl'] = (string) $video['embed_url'];
		}

		if ( '' !== (string) ( $video['content_url'] ?? '' ) ) {
			$data['contentUrl'] = (string) $video['content_url'];
		}

		/**
		 * Changes the data of one video before it is printed (internal).
		 *
		 * Return an empty array to print nothing for this video.
		 *
		 * @param array<string, mixed> $data  The VideoObject.
		 * @param array<string, mixed> $video The video and what is known about it.
		 */
		$data = apply_filters( 'estasoft_nd_internal_video_object', $data, $video );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Builds the data of every video that is ready.
	 *
	 * @param array<int, array<string, mixed>> $videos The videos, as `Content\Videos::resolve()` returns them.
	 * @return array<int, array<string, mixed>>
	 */
	public static function build_all( array $videos ): array {
		$out = array();

		foreach ( $videos as $video ) {
			if ( empty( $video['ready'] ) ) {
				continue;
			}

			$data = self::build( $video );

			if ( array() !== $data ) {
				$out[] = $data;
			}
		}

		return $out;
	}

	/**
	 * The script elements of every video that is ready.
	 *
	 * @param array<int, array<string, mixed>> $videos The videos, as `Content\Videos::resolve()` returns them.
	 * @return string HTML that is already safe to print. Empty when no video is ready.
	 */
	public static function scripts( array $videos ): string {
		$html = '';

		foreach ( self::build_all( $videos ) as $data ) {
			$json = wp_json_encode( $data, NewsArticleJsonLd::FLAGS );

			if ( is_string( $json ) ) {
				$html .= '<script type="application/ld+json" class="estasoft-nd-video-jsonld">' . $json . "</script>\n";
			}
		}

		return $html;
	}
}

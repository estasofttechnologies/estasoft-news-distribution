<?php
/**
 * Details of the videos in an article that Google asks for.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Checks;

use Estasoft\NewsDistribution\Content\Videos;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Codes: `video.upload_date_missing`, `video.name_missing`, `video.thumbnail_missing` and `video.unavailable`.
 *
 * Only when "Add markup for embedded videos" is on. Each code is raised once for the article and names the videos
 * (numbered in the order they appear, as the editor panel numbers them). A video the settings leave out (a site that is
 * off, or a later video when only the first gets markup) and a video an editor left out of the markup are not checked.
 *
 * The upload date is a risk by default, and a tip when "When a video has no upload date" is "Ask me in the editor".
 * The article's own date is never offered in its place.
 */
final class VideoCheck extends AbstractCheck {

	/**
	 * @return string
	 */
	public function id(): string {
		return 'video';
	}

	/**
	 * @return int
	 */
	public function order(): int {
		return 95;
	}

	/**
	 * @param Article $article The article.
	 * @return array<int, array{code: string, severity: string, message: string, channels: string[], fix: string}>
	 */
	public function run( Article $article ): array {
		$service = $this->plugin->make( Videos::class );

		if ( ! $service->enabled() ) {
			return array();
		}

		$numbers = array(
			'upload_date' => array(),
			'name'        => array(),
			'thumbnail'   => array(),
			'unavailable' => array(),
		);

		foreach ( $service->for_post( $article->id() ) as $index => $video ) {
			if ( empty( $video['included'] ) || ! empty( $video['skip'] ) ) {
				continue;
			}

			$number = $index + 1;

			if ( 'unavailable' === $video['status'] ) {
				$numbers['unavailable'][] = $number;
				continue;
			}

			foreach ( (array) $video['missing'] as $field ) {
				$numbers[ $field ][] = $number;
			}
		}

		$asking   = 'ask' === ( $service->settings()['no_date'] ?? 'flag' );
		$findings = array();

		if ( array() !== $numbers['upload_date'] ) {
			$findings[] = $this->finding(
				'video.upload_date_missing',
				$asking ? Status::INFO : $this->default_severity( 'video.upload_date_missing', Status::WARN ),
				$this->sentence(
					$numbers['upload_date'],
					/* translators: %s: the number of a video, such as 2. */
					__( 'Video %s has no upload date, so it gets no video markup. The article’s date is not used in its place.', 'estasoft-news-distribution' ),
					/* translators: %s: the numbers of videos, such as "1 and 3". */
					__( 'Videos %s have no upload date, so they get no video markup. The article’s date is not used in its place.', 'estasoft-news-distribution' )
				)
			);
		}

		if ( array() !== $numbers['name'] ) {
			$findings[] = $this->finding(
				'video.name_missing',
				$this->default_severity( 'video.name_missing', Status::INFO ),
				$this->sentence(
					$numbers['name'],
					/* translators: %s: the number of a video, such as 2. */
					__( 'Video %s has no name, so it gets no video markup. Type one in the editor.', 'estasoft-news-distribution' ),
					/* translators: %s: the numbers of videos, such as "1 and 3". */
					__( 'Videos %s have no name, so they get no video markup. Type one for each in the editor.', 'estasoft-news-distribution' )
				)
			);
		}

		if ( array() !== $numbers['thumbnail'] ) {
			$findings[] = $this->finding(
				'video.thumbnail_missing',
				$this->default_severity( 'video.thumbnail_missing', Status::INFO ),
				$this->sentence(
					$numbers['thumbnail'],
					/* translators: %s: the number of a video, such as 2. */
					__( 'Video %s has no thumbnail, so it gets no video markup. Add the address of a picture in the editor.', 'estasoft-news-distribution' ),
					/* translators: %s: the numbers of videos, such as "1 and 3". */
					__( 'Videos %s have no thumbnail, so they get no video markup. Add the address of a picture for each in the editor.', 'estasoft-news-distribution' )
				)
			);
		}

		if ( array() !== $numbers['unavailable'] ) {
			$findings[] = $this->finding(
				'video.unavailable',
				$this->default_severity( 'video.unavailable', Status::INFO ),
				$this->sentence(
					$numbers['unavailable'],
					/* translators: %s: the number of a video, such as 2. */
					__( 'Video %s is private or was removed, as its site says, so it gets no video markup.', 'estasoft-news-distribution' ),
					/* translators: %s: the numbers of videos, such as "1 and 3". */
					__( 'Videos %s are private or were removed, as their sites say, so they get no video markup.', 'estasoft-news-distribution' )
				)
			);
		}

		return $findings;
	}

	/**
	 * A sentence that names one video or several.
	 *
	 * @param int[]  $numbers  The videos' numbers.
	 * @param string $one      The sentence for one video, with %s for its number.
	 * @param string $several  The sentence for several, with %s for their numbers.
	 * @return string
	 */
	private function sentence( array $numbers, string $one, string $several ): string {
		if ( 1 === count( $numbers ) ) {
			return sprintf( $one, (string) $numbers[0] );
		}

		return sprintf( $several, wp_sprintf( '%l', array_map( 'strval', $numbers ) ) );
	}
}

<?php
/**
 * Featured image: is there one, is it big enough, is it a sensible shape.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Checks;

use Estasoft\NewsDistribution\Core\Article;

defined( 'ABSPATH' ) || exit;

/**
 * Codes: `image.missing`, `image.too_small`, `image.bad_ratio`.
 *
 * What each platform needs is in its own rule pack: a rule with the code opts the
 * platform in, and the pack's limits `image_min_width`, `image_min_height`,
 * `image_ratio_min` and `image_ratio_max` replace the article-checks defaults.
 * See docs/check-codes.md.
 */
final class ImageCheck extends AbstractCheck {

	/**
	 * @return string
	 */
	public function id(): string {
		return 'image';
	}

	/**
	 * @return int
	 */
	public function order(): int {
		return 10;
	}

	/**
	 * @param Article $article The article.
	 * @return array<int, array{code: string, severity: string, message: string, channels: string[], fix: string}>
	 */
	public function run( Article $article ): array {
		if ( '' === $article->thumbnail_url() ) {
			return $this->emit(
				$article,
				'image.missing',
				static function (): string {
					return __( 'The article has no featured image.', 'estasoft-news-distribution' );
				},
				'add-image',
				true
			);
		}

		$width  = $article->thumbnail_width();
		$height = $article->thumbnail_height();

		if ( $width < 1 || $height < 1 ) {
			return array();
		}

		$findings = $this->emit(
			$article,
			'image.too_small',
			function ( string $platform ) use ( $width, $height ) {
				$min_width  = $this->limit( $platform, 'image_min_width' );
				$min_height = $this->limit( $platform, 'image_min_height' );

				if ( $width >= $min_width && $height >= $min_height ) {
					return null;
				}

				return array(
					sprintf(
						/* translators: 1: the image size such as "500 × 300", 2: the smallest size wanted such as "640 × 360". */
						__( 'The featured image is %1$s px. Use one that is at least %2$s px.', 'estasoft-news-distribution' ),
						$this->size( $width, $height ),
						$this->size( (int) $min_width, (int) $min_height )
					),
					$min_width,
				);
			},
			'add-image',
			true
		);

		$ratio = $width / $height;

		return array_merge(
			$findings,
			$this->emit(
				$article,
				'image.bad_ratio',
				function ( string $platform ) use ( $ratio, $width, $height ) {
					$min = $this->limit( $platform, 'image_ratio_min' );
					$max = $this->limit( $platform, 'image_ratio_max' );

					if ( ( $min <= 0 || $ratio >= $min ) && ( $max <= 0 || $ratio <= $max ) ) {
						return null;
					}

					return sprintf(
						/* translators: %s: the image size such as "400 × 1,600". */
						__( 'The featured image is %s px, a shape that thumbnails may crop badly.', 'estasoft-news-distribution' ),
						$this->size( $width, $height )
					);
				},
				'add-image',
				true
			)
		);
	}

	/**
	 * A size as text.
	 *
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels.
	 * @return string
	 */
	private function size( int $width, int $height ): string {
		return number_format_i18n( $width ) . ' × ' . number_format_i18n( $height );
	}
}

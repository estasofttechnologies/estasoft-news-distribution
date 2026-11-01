<?php
/**
 * Alt text of the featured image.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Checks;

use Estasoft\NewsDistribution\Core\Article;

defined( 'ABSPATH' ) || exit;

/**
 * Code: `image.alt_missing`. Reads the attachment's `_wp_attachment_image_alt`.
 */
final class AltTextCheck extends AbstractCheck {

	/**
	 * @return string
	 */
	public function id(): string {
		return 'alt';
	}

	/**
	 * @return int
	 */
	public function order(): int {
		return 20;
	}

	/**
	 * @param Article $article The article.
	 * @return array<int, array{code: string, severity: string, message: string, channels: string[], fix: string}>
	 */
	public function run( Article $article ): array {
		if ( '' === $article->thumbnail_url() ) {
			return array();
		}

		$attachment = (int) get_post_thumbnail_id( $article->id() );

		if ( $attachment < 1 || '' !== trim( (string) get_post_meta( $attachment, '_wp_attachment_image_alt', true ) ) ) {
			return array();
		}

		return $this->emit(
			$article,
			'image.alt_missing',
			static function (): string {
				return __( 'The featured image has no alt text.', 'estasoft-news-distribution' );
			},
			'add-alt'
		);
	}
}

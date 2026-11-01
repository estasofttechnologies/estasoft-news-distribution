<?php
/**
 * Credit of the featured image.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Checks;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Code: `credit.missing`.
 *
 * The image credits feature owns where a credit is stored. This check reads the
 * attachment meta `_estasoft_nd_credit`, and the filter
 * `estasoft_nd_internal_image_credit` lets that feature answer another way.
 * It asks for a credit only when the setting `credits.require` is on (it is, until
 * the owner turns it off), and uses `credits.on_fail` (`block`, `warn` or `info`)
 * as the severity when the setting holds one of those words.
 */
final class CreditCheck extends AbstractCheck {

	public const META = '_estasoft_nd_credit';

	/**
	 * @return string
	 */
	public function id(): string {
		return 'credit';
	}

	/**
	 * @return int
	 */
	public function order(): int {
		return 30;
	}

	/**
	 * @param Article $article The article.
	 * @return array<int, array{code: string, severity: string, message: string, channels: string[], fix: string}>
	 */
	public function run( Article $article ): array {
		if ( '' === $article->thumbnail_url() || ! (bool) $this->plugin->settings()->get( 'credits.require', true ) ) {
			return array();
		}

		$attachment = (int) get_post_thumbnail_id( $article->id() );

		if ( $attachment < 1 || '' !== $this->credit_of( $attachment ) ) {
			return array();
		}

		$chosen   = (string) $this->plugin->settings()->get( 'credits.on_fail', '' );
		$fallback = in_array( $chosen, array( Status::BLOCK, Status::WARN, Status::INFO ), true ) ? $chosen : '';

		return $this->emit(
			$article,
			'credit.missing',
			static function (): string {
				return __( 'The featured image has no credit.', 'estasoft-news-distribution' );
			},
			'add-credit',
			false,
			$fallback
		);
	}

	/**
	 * The credit written for an image.
	 *
	 * @param int $attachment Attachment ID.
	 * @return string
	 */
	private function credit_of( int $attachment ): string {
		/**
		 * Answers the credit of an image, when the image credits feature keeps it somewhere else.
		 *
		 * Internal. Return a string (empty means no credit), or null to read the attachment meta.
		 *
		 * @param string|null $credit     The credit, or null.
		 * @param int         $attachment Attachment ID.
		 */
		$credit = apply_filters( 'estasoft_nd_internal_image_credit', null, $attachment );

		if ( null === $credit ) {
			$credit = get_post_meta( $attachment, self::META, true );
		}

		return is_scalar( $credit ) ? trim( (string) $credit ) : '';
	}
}

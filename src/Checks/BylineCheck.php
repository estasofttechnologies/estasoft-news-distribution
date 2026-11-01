<?php
/**
 * The author the platforms will be told.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Checks;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Bylines;

defined( 'ABSPATH' ) || exit;

/**
 * Codes: `byline.missing`, `byline.not_a_person`.
 *
 * Asks `Core\Bylines`, so this check and the channels agree on the name.
 */
final class BylineCheck extends AbstractCheck {

	/**
	 * @return string
	 */
	public function id(): string {
		return 'byline';
	}

	/**
	 * @return int
	 */
	public function order(): int {
		return 70;
	}

	/**
	 * @param Article $article The article.
	 * @return array<int, array{code: string, severity: string, message: string, channels: string[], fix: string}>
	 */
	public function run( Article $article ): array {
		$byline = $this->plugin->make( Bylines::class )->resolve( $article );

		if ( $byline['replaced'] ) {
			$was  = implode( ', ', $byline['originals'] );
			$sent = $byline['joined'];

			return $this->emit(
				$article,
				'byline.not_a_person',
				static function () use ( $was, $sent ): string {
					return sprintf(
						/* translators: 1: the author name that is not a person, such as admin, 2: the name sent instead. */
						__( 'The author is "%1$s", which is not a person, so feeds say "%2$s" instead.', 'estasoft-news-distribution' ),
						$was,
						$sent
					);
				},
				'set-author'
			);
		}

		if ( Bylines::SOURCE_NEWSROOM !== $byline['source'] ) {
			return array();
		}

		$sent = $byline['joined'];

		return $this->emit(
			$article,
			'byline.missing',
			static function () use ( $sent ): string {
				if ( '' === $sent ) {
					return __( 'The article has no author.', 'estasoft-news-distribution' );
				}

				return sprintf(
					/* translators: %s: the name sent instead, such as Example Times staff. */
					__( 'The article has no author, so feeds say "%s".', 'estasoft-news-distribution' ),
					$sent
				);
			},
			'set-author'
		);
	}
}

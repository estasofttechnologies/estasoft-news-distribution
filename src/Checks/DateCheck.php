<?php
/**
 * The publication and modified dates.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Checks;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Codes: `date.invalid`, `date.future`, `date.modified_before_published`.
 *
 * Whether the last save kept or moved the modified date is not a finding: it is
 * the date guard's decision, shown as the Dates row of the Article check and in
 * the History tab (`Core\DateGuard`).
 */
final class DateCheck extends AbstractCheck {

	/**
	 * The earliest date accepted as real: 1 January 1990.
	 */
	private const EARLIEST = 631152000;

	/**
	 * A publication date up to this many seconds ahead is clock drift, not a future date.
	 */
	private const SLACK = 300;

	/**
	 * @return string
	 */
	public function id(): string {
		return 'date';
	}

	/**
	 * @return int
	 */
	public function order(): int {
		return 50;
	}

	/**
	 * @param Article $article The article.
	 * @return array<int, array{code: string, severity: string, message: string, channels: string[], fix: string}>
	 */
	public function run( Article $article ): array {
		$published = $article->published_ts();
		$modified  = $article->modified_ts();
		$status    = $article->status();

		// A draft has no publication date yet.
		if ( ! in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
			return array();
		}

		if ( $published < self::EARLIEST ) {
			return $this->emit(
				$article,
				'date.invalid',
				static function (): string {
					return __( 'The publication date is missing or is not a real date.', 'estasoft-news-distribution' );
				},
				'fix-date'
			);
		}

		if ( 'publish' === $status && $published > Clock::now() + self::SLACK ) {
			return $this->emit(
				$article,
				'date.future',
				static function (): string {
					return __( 'The publication date is in the future.', 'estasoft-news-distribution' );
				},
				'fix-date'
			);
		}

		if ( $modified >= self::EARLIEST && $modified + 60 < $published ) {
			return $this->emit(
				$article,
				'date.modified_before_published',
				static function (): string {
					return __( 'The modified date is earlier than the publication date.', 'estasoft-news-distribution' );
				},
				'fix-date'
			);
		}

		return array();
	}
}

<?php
/**
 * The one table that turns a status code into a word, an icon and a tone.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Status codes are stored and sent over REST. Screens print the display word
 * from here, so a word is never built by joining pieces and is translated once.
 *
 * Families (never mixed): result, health, fetch, application, check, severity.
 */
final class Status {

	// Results: one article on one channel.
	public const IN_FEED = 'in_feed';
	public const SENT    = 'sent';
	public const QUEUED  = 'queued';
	public const RETRY   = 'retry';
	public const SKIPPED = 'skipped';
	public const FAILED  = 'failed';
	public const OFF     = 'off';
	public const REMOVED = 'removed';

	// Channel health.
	public const HEALTHY    = 'healthy';
	public const ATTENTION  = 'attention';
	public const NOT_SET_UP = 'not_set_up';
	public const PROCESSING = 'processing';

	// Fetch results.
	public const ON_TIME     = 'on_time';
	public const LATE        = 'late';
	public const NOT_FETCHED = 'not_fetched';
	public const NEVER       = 'never';

	// Applications, set by hand.
	public const NOT_REQUESTED = 'not_requested';
	public const REQUESTED     = 'requested';
	public const IN_REVIEW     = 'in_review';
	public const LISTED        = 'listed';
	public const DECLINED      = 'declined';

	// Checks.
	public const CLEAN         = 'clean';
	public const DATE_KEPT     = 'date_kept';
	public const DATE_MOVED    = 'date_moved';
	public const WILL_FILL     = 'will_fill';
	public const TEST_PASSED   = 'test_passed';
	public const INSTALLED     = 'installed';
	public const NOT_INSTALLED = 'not_installed';
	public const CONFLICT      = 'conflict';
	public const NO_CONFLICT   = 'no_conflict';

	// Severity of a finding inside the article check.
	public const BLOCK = 'block';
	public const WARN  = 'warn';
	public const INFO  = 'info';

	/**
	 * Every code with its family, display word, icon name and tone.
	 *
	 * Tone is one of success, warning, danger, info, neutral, wait. The icon
	 * names match Admin\Ui\Icon.
	 *
	 * @return array<string, array{family: string, word: string, icon: string, tone: string}>
	 */
	public static function all(): array {
		static $table = null;

		if ( null === $table ) {
			$table = array(
				self::IN_FEED       => array( 'result', __( 'In feed', 'estasoft-news-distribution' ), 'check-circle', 'success' ),
				self::SENT          => array( 'result', __( 'Sent', 'estasoft-news-distribution' ), 'check-circle', 'success' ),
				self::QUEUED        => array( 'result', __( 'Waiting', 'estasoft-news-distribution' ), 'clock', 'wait' ),
				self::RETRY         => array( 'result', __( 'Retrying', 'estasoft-news-distribution' ), 'retry', 'warning' ),
				self::SKIPPED       => array( 'result', __( 'Skipped', 'estasoft-news-distribution' ), 'x-circle', 'danger' ),
				self::FAILED        => array( 'result', __( 'Failed', 'estasoft-news-distribution' ), 'x-circle', 'danger' ),
				self::OFF           => array( 'result', __( 'Off', 'estasoft-news-distribution' ), 'minus-circle', 'neutral' ),
				self::REMOVED       => array( 'result', __( 'Removed', 'estasoft-news-distribution' ), 'check-circle', 'success' ),

				self::HEALTHY       => array( 'health', __( 'Healthy', 'estasoft-news-distribution' ), 'check-circle', 'success' ),
				self::ATTENTION     => array( 'health', __( 'Needs attention', 'estasoft-news-distribution' ), 'warning', 'warning' ),
				self::NOT_SET_UP    => array( 'health', __( 'Not set up', 'estasoft-news-distribution' ), 'minus-circle', 'neutral' ),
				self::PROCESSING    => array( 'health', __( 'Processing', 'estasoft-news-distribution' ), 'info', 'info' ),

				self::ON_TIME       => array( 'fetch', __( 'On time', 'estasoft-news-distribution' ), 'check-circle', 'success' ),
				self::LATE          => array( 'fetch', __( 'Later than usual', 'estasoft-news-distribution' ), 'warning', 'warning' ),
				self::NOT_FETCHED   => array( 'fetch', __( 'Not fetched yet', 'estasoft-news-distribution' ), 'clock', 'wait' ),
				self::NEVER         => array( 'fetch', __( 'Never', 'estasoft-news-distribution' ), 'minus-circle', 'neutral' ),

				self::NOT_REQUESTED => array( 'application', __( 'Not requested', 'estasoft-news-distribution' ), 'minus-circle', 'neutral' ),
				self::REQUESTED     => array( 'application', __( 'Requested', 'estasoft-news-distribution' ), 'clock', 'info' ),
				self::IN_REVIEW     => array( 'application', __( 'In review', 'estasoft-news-distribution' ), 'clock', 'info' ),
				self::LISTED        => array( 'application', __( 'Listed', 'estasoft-news-distribution' ), 'check-circle', 'success' ),
				self::DECLINED      => array( 'application', __( 'Declined', 'estasoft-news-distribution' ), 'x-circle', 'danger' ),

				self::CLEAN         => array( 'check', __( 'Clean', 'estasoft-news-distribution' ), 'check-circle', 'success' ),
				self::DATE_KEPT     => array( 'check', __( 'Date kept', 'estasoft-news-distribution' ), 'check-circle', 'success' ),
				self::DATE_MOVED    => array( 'check', __( 'Date moved', 'estasoft-news-distribution' ), 'info', 'info' ),
				self::WILL_FILL     => array( 'check', __( 'Will fill', 'estasoft-news-distribution' ), 'info', 'info' ),
				self::TEST_PASSED   => array( 'check', __( 'Test passed', 'estasoft-news-distribution' ), 'check-circle', 'success' ),
				self::INSTALLED     => array( 'check', __( 'Installed', 'estasoft-news-distribution' ), 'check-circle', 'success' ),
				self::NOT_INSTALLED => array( 'check', __( 'Not installed', 'estasoft-news-distribution' ), 'minus-circle', 'neutral' ),
				self::CONFLICT      => array( 'check', __( 'Conflict', 'estasoft-news-distribution' ), 'warning', 'warning' ),
				self::NO_CONFLICT   => array( 'check', __( 'No conflict', 'estasoft-news-distribution' ), 'check-circle', 'success' ),

				self::BLOCK         => array( 'severity', __( 'Blocks the channel', 'estasoft-news-distribution' ), 'x-circle', 'danger' ),
				self::WARN          => array( 'severity', __( 'Risk', 'estasoft-news-distribution' ), 'warning', 'warning' ),
				self::INFO          => array( 'severity', __( 'Tip', 'estasoft-news-distribution' ), 'info', 'info' ),
			);

			foreach ( $table as $code => $row ) {
				$table[ $code ] = array(
					'family' => $row[0],
					'word'   => $row[1],
					'icon'   => $row[2],
					'tone'   => $row[3],
				);
			}
		}

		return $table;
	}

	/**
	 * Describes one code.
	 *
	 * An unknown code is described as a neutral status with the code as its
	 * word, so a screen never fails because of a code added later.
	 *
	 * @param string $code Status code.
	 * @return array{family: string, word: string, icon: string, tone: string}
	 */
	public static function describe( string $code ): array {
		$all = self::all();

		if ( isset( $all[ $code ] ) ) {
			return $all[ $code ];
		}

		return array(
			'family' => 'unknown',
			'word'   => $code,
			'icon'   => 'minus-circle',
			'tone'   => 'neutral',
		);
	}

	/**
	 * The display word for a code.
	 *
	 * @param string $code Status code.
	 * @return string
	 */
	public static function word( string $code ): string {
		return self::describe( $code )['word'];
	}

	/**
	 * Whether a code exists.
	 *
	 * @param string $code Status code.
	 * @return bool
	 */
	public static function exists( string $code ): bool {
		return isset( self::all()[ $code ] );
	}

	/**
	 * The codes of one family.
	 *
	 * @param string $family result, health, fetch, application, check or severity.
	 * @return string[]
	 */
	public static function codes_in( string $family ): array {
		$codes = array();

		foreach ( self::all() as $code => $row ) {
			if ( $row['family'] === $family ) {
				$codes[] = $code;
			}
		}

		return $codes;
	}

	/**
	 * Maps the status the delivery log stores to a result code.
	 *
	 * The skeleton's delivery result uses "success"; screens only see "sent".
	 *
	 * @param string $stored Stored status.
	 * @return string
	 */
	public static function from_log( string $stored ): string {
		return 'success' === $stored ? self::SENT : $stored;
	}

	/**
	 * The worst of several health codes. A parent channel shows its worst child.
	 *
	 * @param string[] $codes Health codes.
	 * @return string
	 */
	public static function worst_health( array $codes ): string {
		$order = array( self::ATTENTION, self::PROCESSING, self::NOT_SET_UP, self::HEALTHY );

		foreach ( $order as $code ) {
			if ( in_array( $code, $codes, true ) ) {
				return $code;
			}
		}

		return self::NOT_SET_UP;
	}
}

<?php
/**
 * Times the way the Article check boards write them.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Check;

use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * "12 min ago", "1 h ago", "19 Oct, 09:12", "Published today, 13:41". Every method reads the
 * site's clock (`Core\Clock`) and the site's time zone, so a test can freeze the time.
 */
final class Times {

	/**
	 * How long ago, short: "12 min ago", "1 h ago", and a date and time after a day.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function ago( int $timestamp ): string {
		$seconds = Clock::now() - $timestamp;

		if ( $seconds < MINUTE_IN_SECONDS ) {
			return __( 'Just now', 'estasoft-news-distribution' );
		}

		if ( $seconds < HOUR_IN_SECONDS ) {
			return sprintf(
				/* translators: %s: a number of minutes. */
				_n( '%s min ago', '%s min ago', (int) floor( $seconds / MINUTE_IN_SECONDS ), 'estasoft-news-distribution' ),
				number_format_i18n( (int) floor( $seconds / MINUTE_IN_SECONDS ) )
			);
		}

		if ( $seconds < DAY_IN_SECONDS ) {
			return sprintf(
				/* translators: %s: a number of hours. */
				_n( '%s h ago', '%s h ago', (int) floor( $seconds / HOUR_IN_SECONDS ), 'estasoft-news-distribution' ),
				number_format_i18n( (int) floor( $seconds / HOUR_IN_SECONDS ) )
			);
		}

		return self::short( $timestamp );
	}

	/**
	 * A date and time without the year when it is this year: "19 Oct, 09:12".
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function short( int $timestamp ): string {
		$date = Clock::format( $timestamp, Clock::format( $timestamp, 'Y' ) === Clock::format( Clock::now(), 'Y' ) ? 'j M' : 'j M Y' );

		/* translators: 1: a date such as "19 Oct", 2: a time such as "09:12". */
		return sprintf( __( '%1$s, %2$s', 'estasoft-news-distribution' ), $date, Clock::format( $timestamp, 'H:i' ) );
	}

	/**
	 * "Today, 13:41", "Yesterday, 17:48" or "19 Oct, 09:12".
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function day( int $timestamp ): string {
		$day   = Clock::format( $timestamp, 'Y-m-d' );
		$time  = Clock::format( $timestamp, 'H:i' );
		$today = Clock::format( Clock::now(), 'Y-m-d' );

		if ( $day === $today ) {
			/* translators: %s: a time such as "13:41". */
			return sprintf( __( 'Today, %s', 'estasoft-news-distribution' ), $time );
		}

		if ( Clock::format( Clock::now() - DAY_IN_SECONDS, 'Y-m-d' ) === $day ) {
			/* translators: %s: a time such as "17:48". */
			return sprintf( __( 'Yesterday, %s', 'estasoft-news-distribution' ), $time );
		}

		return self::short( $timestamp );
	}

	/**
	 * The time of day, 24-hour: "14:32".
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function clock( int $timestamp ): string {
		return Clock::format( $timestamp, 'H:i' );
	}

	/**
	 * A time of day with its date when it is not today: "14:32", or "19 Oct, 09:12".
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function at( int $timestamp ): string {
		return Clock::format( $timestamp, 'Y-m-d' ) === Clock::format( Clock::now(), 'Y-m-d' ) ? self::clock( $timestamp ) : self::short( $timestamp );
	}

	/**
	 * Unix time from an ISO 8601 value such as "2026-10-21T14:32:00Z".
	 *
	 * @param mixed $iso The value, or null.
	 * @return int Zero when there is none.
	 */
	public static function from_iso( $iso ): int {
		if ( ! is_string( $iso ) || '' === $iso ) {
			return 0;
		}

		$time = strtotime( $iso );

		return false === $time ? 0 : (int) $time;
	}
}

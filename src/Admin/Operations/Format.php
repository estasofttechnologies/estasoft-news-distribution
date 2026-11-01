<?php
/**
 * Small text helpers for the operations screens.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Operations;

use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Times the way the boards write them: "Today, 14:23", "Yesterday, 17:48",
 * "20 Oct, 16:42". All of them read the site's clock (`Core\Clock`), so a test
 * can freeze it.
 */
final class Format {

	/**
	 * A time with its day.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function when( int $timestamp ): string {
		$day   = Clock::format( $timestamp, 'Y-m-d' );
		$today = Clock::format( Clock::now(), 'Y-m-d' );
		$time  = Clock::format( $timestamp, 'H:i' );

		if ( $day === $today ) {
			/* translators: %s: a time, such as "14:23". */
			return sprintf( __( 'Today, %s', 'estasoft-news-distribution' ), $time );
		}

		if ( Clock::format( $timestamp, 'Y-m-d' ) === Clock::format( Clock::now() - DAY_IN_SECONDS, 'Y-m-d' ) ) {
			/* translators: %s: a time, such as "17:48". */
			return sprintf( __( 'Yesterday, %s', 'estasoft-news-distribution' ), $time );
		}

		$year = Clock::format( $timestamp, 'Y' ) === Clock::format( Clock::now(), 'Y' ) ? 'j M' : 'j M Y';

		/* translators: 1: a date, such as "20 Oct", 2: a time, such as "16:42". */
		return sprintf( __( '%1$s, %2$s', 'estasoft-news-distribution' ), Clock::format( $timestamp, $year ), $time );
	}

	/**
	 * How long ago, in words: "2 minutes ago".
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function ago( int $timestamp ): string {
		return sprintf(
			/* translators: %s: a length of time, such as "2 minutes". */
			__( '%s ago', 'estasoft-news-distribution' ),
			human_time_diff( $timestamp, Clock::now() )
		);
	}
}

<?php
/**
 * Time, in the site's time zone, with a fixed clock for tests.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Everything that needs "now" asks here, so tests can stop the clock and the
 * sample world can sit on Wednesday 21 October 2026, 14:35.
 */
final class Clock {

	/**
	 * A fixed Unix time, or null to use the real clock.
	 *
	 * @var int|null
	 */
	private static ?int $fixed = null;

	/**
	 * Current Unix time.
	 *
	 * @return int
	 */
	public static function now(): int {
		return null === self::$fixed ? time() : self::$fixed;
	}

	/**
	 * Stops the clock. Pass null to start it again. For tests and demo mode.
	 *
	 * @param int|null $timestamp Unix time to hold.
	 * @return void
	 */
	public static function freeze( ?int $timestamp ): void {
		self::$fixed = $timestamp;
	}

	/**
	 * The site's time zone.
	 *
	 * @return \DateTimeZone
	 */
	public static function zone(): \DateTimeZone {
		return wp_timezone();
	}

	/**
	 * Formats a Unix time in the site's zone.
	 *
	 * @param int    $timestamp Unix time.
	 * @param string $format    PHP date format. Defaults to 24-hour time.
	 * @return string
	 */
	public static function format( int $timestamp, string $format = 'H:i' ): string {
		return wp_date( $format, $timestamp, self::zone() );
	}

	/**
	 * A date and time in the site's zone, 24-hour, such as "21 Oct 2026, 14:35".
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function datetime( int $timestamp ): string {
		return wp_date( 'j M Y, H:i', $timestamp, self::zone() );
	}

	/**
	 * ISO 8601 in UTC, as the REST API returns times.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function iso( int $timestamp ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	/**
	 * Unix time from a UTC database value such as "2026-10-21 14:35:00".
	 *
	 * @param string $gmt Database date and time, in UTC.
	 * @return int Zero when the value is empty or invalid.
	 */
	public static function from_gmt( string $gmt ): int {
		if ( '' === $gmt || '0000-00-00 00:00:00' === $gmt ) {
			return 0;
		}

		$time = strtotime( $gmt . ' UTC' );

		return false === $time ? 0 : (int) $time;
	}

	/**
	 * A UTC database value for a Unix time.
	 *
	 * @param int|null $timestamp Unix time, or null for now.
	 * @return string
	 */
	public static function to_gmt( ?int $timestamp = null ): string {
		return gmdate( 'Y-m-d H:i:s', null === $timestamp ? self::now() : $timestamp );
	}
}

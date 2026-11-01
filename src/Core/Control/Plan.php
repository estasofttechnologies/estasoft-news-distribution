<?php
/**
 * The release plan of one article, as stored and as read back.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Control;

defined( 'ABSPATH' ) || exit;

/**
 * Pure values, no database: this class cleans what a form, the editor panel or
 * a route sends, and says what a plan means in seconds.
 *
 * Stored in `_estasoft_nd_options` under the key `release`:
 *
 *     mode          default, now, exclusive or custom
 *     exclusive     channel id that has the article first (mode exclusive)
 *     duration      seconds the exclusive lasts after publishing; -1 is the site's usual
 *                   index-first delay, 0 is "until I release it"
 *     delay_search  also hold Google News and the search sitemaps back (mode exclusive)
 *     times         seconds after publishing, per channel id (mode custom); the key `*`
 *                   is a time for every channel that has no time of its own
 *     ended         Unix time the person ended the exclusive early, or 0
 *     sent_now      channel ids that Send now released ahead of the plan
 *     saved, by     when the plan was saved and by whom
 *
 * Values may come back as strings (the options meta cleans every leaf as text),
 * so every read casts.
 */
final class Plan {

	public const MODE_DEFAULT   = 'default';
	public const MODE_NOW       = 'now';
	public const MODE_EXCLUSIVE = 'exclusive';
	public const MODE_CUSTOM    = 'custom';

	/**
	 * The `duration` that means the site's usual index-first delay.
	 */
	public const USUAL = -1;

	/**
	 * The `duration` that means "until I release it".
	 */
	public const UNTIL_RELEASED = 0;

	/**
	 * The key in `times` that applies to every channel without a time of its own.
	 */
	public const ALL = '*';

	/**
	 * The longest wait a plan can ask for.
	 */
	public const MAX_SECONDS = 172800;

	/**
	 * The release modes.
	 *
	 * @return string[]
	 */
	public static function modes(): array {
		return array( self::MODE_DEFAULT, self::MODE_NOW, self::MODE_EXCLUSIVE, self::MODE_CUSTOM );
	}

	/**
	 * What a plan has when nothing was chosen.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'mode'         => self::MODE_DEFAULT,
			'exclusive'    => '',
			'duration'     => self::USUAL,
			'delay_search' => false,
			'times'        => array(),
			'ended'        => 0,
			'sent_now'     => array(),
			'saved'        => 0,
			'by'           => 0,
		);
	}

	/**
	 * Cleans a plan. Anything unknown is dropped and a bad value falls back to the default.
	 *
	 * @param mixed $raw What was stored or sent.
	 * @return array<string, mixed>
	 */
	public static function normalize( $raw ): array {
		$plan = self::defaults();

		if ( ! is_array( $raw ) ) {
			return $plan;
		}

		$mode = isset( $raw['mode'] ) ? sanitize_key( (string) $raw['mode'] ) : '';

		if ( in_array( $mode, self::modes(), true ) ) {
			$plan['mode'] = $mode;
		}

		$plan['exclusive']    = isset( $raw['exclusive'] ) ? sanitize_key( (string) $raw['exclusive'] ) : '';
		$plan['delay_search'] = isset( $raw['delay_search'] ) && filter_var( $raw['delay_search'], FILTER_VALIDATE_BOOLEAN );
		$plan['ended']        = isset( $raw['ended'] ) ? max( 0, (int) $raw['ended'] ) : 0;
		$plan['saved']        = isset( $raw['saved'] ) ? max( 0, (int) $raw['saved'] ) : 0;
		$plan['by']           = isset( $raw['by'] ) ? max( 0, (int) $raw['by'] ) : 0;

		if ( isset( $raw['duration'] ) && '' !== $raw['duration'] && is_numeric( $raw['duration'] ) ) {
			$duration         = (int) $raw['duration'];
			$plan['duration'] = $duration < 0 ? self::USUAL : min( self::MAX_SECONDS, $duration );
		}

		foreach ( is_array( $raw['times'] ?? null ) ? $raw['times'] : array() as $channel => $seconds ) {
			$channel = self::ALL === (string) $channel ? self::ALL : sanitize_key( (string) $channel );

			if ( '' === $channel || ! is_numeric( $seconds ) ) {
				continue;
			}

			$plan['times'][ $channel ] = max( 0, min( self::MAX_SECONDS, (int) $seconds ) );
		}

		foreach ( is_array( $raw['sent_now'] ?? null ) ? $raw['sent_now'] : array() as $channel ) {
			$channel = sanitize_key( (string) $channel );

			if ( '' !== $channel ) {
				$plan['sent_now'][] = $channel;
			}
		}

		$plan['sent_now'] = array_values( array_unique( $plan['sent_now'] ) );

		return $plan;
	}

	/**
	 * How long the exclusive lasts, in seconds, or null when it lasts until it is released.
	 *
	 * @param array<string, mixed> $plan  A normalized plan.
	 * @param int                  $usual The site's usual index-first delay in seconds.
	 * @return int|null
	 */
	public static function exclusive_seconds( array $plan, int $usual ): ?int {
		$duration = (int) $plan['duration'];

		if ( self::USUAL === $duration ) {
			return max( 0, $usual );
		}

		return self::UNTIL_RELEASED === $duration ? null : $duration;
	}

	/**
	 * The time a custom plan gives one channel, in seconds after publishing.
	 *
	 * @param array<string, mixed> $plan       A normalized plan.
	 * @param string               $channel_id Channel id.
	 * @return int|null Null when the plan says nothing about the channel.
	 */
	public static function custom_seconds( array $plan, string $channel_id ): ?int {
		$times = (array) $plan['times'];

		if ( isset( $times[ $channel_id ] ) ) {
			return (int) $times[ $channel_id ];
		}

		return isset( $times[ self::ALL ] ) ? (int) $times[ self::ALL ] : null;
	}

	/**
	 * The waits a person can pick for one channel in a custom plan.
	 *
	 * @return array<int, string> Seconds => label.
	 */
	public static function custom_choices(): array {
		return array(
			0     => __( 'Right away', 'estasoft-news-distribution' ),
			300   => __( '5 minutes', 'estasoft-news-distribution' ),
			900   => __( '15 minutes', 'estasoft-news-distribution' ),
			1800  => __( '30 minutes', 'estasoft-news-distribution' ),
			3600  => __( '1 hour', 'estasoft-news-distribution' ),
			7200  => __( '2 hours', 'estasoft-news-distribution' ),
			21600 => __( '6 hours', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * How long an exclusive can last, as a person picks it.
	 *
	 * @param int $usual The site's usual index-first delay in seconds.
	 * @return array<int, string> Value => label. The value is USUAL, UNTIL_RELEASED or seconds.
	 */
	public static function exclusive_choices( int $usual ): array {
		$choices = array(
			self::USUAL => sprintf(
				/* translators: %s: a length of time such as "15 minutes". */
				__( '%s (the usual delay)', 'estasoft-news-distribution' ),
				self::words( $usual )
			),
		);

		foreach ( array( 1800, 3600, 7200 ) as $seconds ) {
			if ( $seconds !== $usual ) {
				$choices[ $seconds ] = self::words( $seconds );
			}
		}

		$choices[ self::UNTIL_RELEASED ] = __( 'Until I release it', 'estasoft-news-distribution' );

		return $choices;
	}

	/**
	 * A wait as words, such as "15 minutes" or "1 hour 30 minutes".
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function words( int $seconds ): string {
		$minutes = (int) round( max( 0, $seconds ) / 60 );

		if ( $minutes < 60 ) {
			/* translators: %d: a number of minutes. */
			return sprintf( _n( '%d minute', '%d minutes', $minutes, 'estasoft-news-distribution' ), $minutes );
		}

		$hours = intdiv( $minutes, 60 );
		$rest  = $minutes % 60;
		/* translators: %d: a number of hours. */
		$text = sprintf( _n( '%d hour', '%d hours', $hours, 'estasoft-news-distribution' ), $hours );

		if ( $rest > 0 ) {
			/* translators: 1: hours such as "1 hour", 2: minutes such as "30 minutes". */
			return sprintf( __( '%1$s %2$s', 'estasoft-news-distribution' ), $text, sprintf( _n( '%d minute', '%d minutes', $rest, 'estasoft-news-distribution' ), $rest ) );
		}

		return $text;
	}
}

<?php
/**
 * Small facts the Overview reads from stored results.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Overview;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers that the Overview, the dashboard widget and the Posts column share.
 * Nothing here reads another server: it reads the fetch log, the delivery log and
 * settings that are already stored.
 */
final class Facts {

	/**
	 * How long a push may wait past its time before the queue counts as stopped.
	 */
	public const QUEUE_STALLED_HOURS = 3;

	/**
	 * When the oldest push that should have gone out was due, if the queue has stopped.
	 *
	 * The queue is judged by its work: a push that was due more than three hours ago and is still
	 * waiting means Action Scheduler has not run. It reads the delivery log only.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return int Unix time the oldest overdue push was due, or 0 when nothing is overdue.
	 */
	public static function queue_stalled_since( Plugin $plugin ): int {
		$waiting = $plugin->log()->query(
			array(
				'status'      => Status::QUEUED,
				'since'       => Clock::now() - ( 7 * DAY_IN_SECONDS ),
				'latest_only' => true,
				'per_page'    => 100,
			)
		)['rows'];
		$oldest  = 0;

		foreach ( $waiting as $row ) {
			$due = (int) $row['run_at_ts'];

			if ( $due > 0 && $due < Clock::now() - ( self::QUEUE_STALLED_HOURS * HOUR_IN_SECONDS ) && ( 0 === $oldest || $due < $oldest ) ) {
				$oldest = $due;
			}
		}

		return $oldest;
	}

	/**
	 * Where a link to a channel goes. A person who may only view lands on the Channels
	 * list, because a channel page needs the Manage permission.
	 *
	 * @param Plugin $plugin     The running plugin.
	 * @param string $channel_id Channel id, or an empty string for the list.
	 * @return string
	 */
	public static function channel_url( Plugin $plugin, string $channel_id ): string {
		if ( '' === $channel_id || ! $plugin->access()->can( Access::MANAGE ) ) {
			return Links::admin( 'estasoft-nd-channels' );
		}

		return Links::channel( $channel_id );
	}

	/**
	 * How the platform's fetches look for each language it carries.
	 *
	 * A sitemap channel reads them by crawler name (Bing and Yandex share one sitemap).
	 * Any other channel reads the fetch log by the channel that answered.
	 *
	 * @param Plugin           $plugin  The running plugin.
	 * @param ChannelInterface $channel The channel.
	 * @return array<string, array{status: string, last_ts: int}> Language code => state. `status` is on_time, late or never.
	 */
	public static function fetch_states( Plugin $plugin, ChannelInterface $channel ): array {
		if ( method_exists( $channel, 'fetch_report' ) ) {
			$report = $channel->fetch_report();
			$states = array();

			foreach ( is_array( $report ) ? $report : array() as $language => $state ) {
				$states[ (string) $language ] = array(
					'status'  => (string) ( $state['status'] ?? Status::NEVER ),
					'last_ts' => (int) ( $state['last_ts'] ?? 0 ),
				);
			}

			return $states;
		}

		$latest   = $plugin->fetch_log()->latest();
		$interval = $plugin->rules()->fetch_interval_hours( $channel->id() ) * HOUR_IN_SECONDS;
		$states   = array();

		foreach ( $plugin->languages()->languages() as $language ) {
			if ( ! $channel->accepts_language( (string) $language ) ) {
				continue;
			}

			$row  = $latest[ $channel->id() . '|' . $language ] ?? null;
			$last = null === $row ? 0 : (int) $row['created_ts'];

			if ( 0 === $last ) {
				$code = Status::NEVER;
			} elseif ( Clock::now() - $last > 2 * $interval ) {
				$code = Status::LATE;
			} else {
				$code = Status::ON_TIME;
			}

			$states[ (string) $language ] = array(
				'status'  => $code,
				'last_ts' => $last,
			);
		}

		return $states;
	}

	/**
	 * How long ago, the way the boards write it: "6 min ago", "26 h ago", "2 d ago".
	 *
	 * @param int $timestamp Unix time in the past.
	 * @return string
	 */
	public static function ago( int $timestamp ): string {
		$seconds = max( 0, Clock::now() - $timestamp );

		if ( $seconds < HOUR_IN_SECONDS ) {
			$number = max( 1, (int) floor( $seconds / MINUTE_IN_SECONDS ) );

			return sprintf(
				/* translators: %s: a number of minutes. */
				_n( '%s min ago', '%s min ago', $number, 'estasoft-news-distribution' ),
				number_format_i18n( $number )
			);
		}

		if ( $seconds < 2 * DAY_IN_SECONDS ) {
			$number = (int) floor( $seconds / HOUR_IN_SECONDS );

			return sprintf(
				/* translators: %s: a number of hours. */
				_n( '%s h ago', '%s h ago', $number, 'estasoft-news-distribution' ),
				number_format_i18n( $number )
			);
		}

		$number = (int) floor( $seconds / DAY_IN_SECONDS );

		return sprintf(
			/* translators: %s: a number of days. */
			_n( '%s d ago', '%s d ago', $number, 'estasoft-news-distribution' ),
			number_format_i18n( $number )
		);
	}

	/**
	 * The clock time of a Unix time, such as "14:38".
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function clock( int $timestamp ): string {
		return Clock::format( $timestamp, 'H:i' );
	}

	/**
	 * Lower case for the middle of a sentence. Works without the mbstring extension, where only plain letters change.
	 *
	 * @param string $text Any text.
	 * @return string
	 */
	public static function lower( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
	}
}

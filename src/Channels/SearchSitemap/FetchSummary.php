<?php
/**
 * What a named crawler has fetched, read from the fetch log.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\SearchSitemap;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Db\Schema;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The fetch log records which channel answered a request. Bing and Yandex share
 * one sitemap, so the channel that answered is not always the platform that
 * asked. This class reads the log by crawler name (the `agent` column) across
 * the channels that can answer, so "Last fetched by Bingbot" is always true.
 *
 * Only answers that carried a document count (`ok` and `not_modified`). A 404 or
 * a 410 is a request, not a fetch.
 */
final class FetchSummary {

	/**
	 * The latest fetch of a channel for each language, read from the fetch log.
	 *
	 * A channel with a document of its own is read by its own rows. Bing, Yandex and Baidu share one sitemap and the log
	 * names the channel that answered, so each is read by its crawler name across the channels that can answer.
	 *
	 * @param Plugin                              $plugin  The running plugin.
	 * @param ChannelInterface                    $channel The channel.
	 * @param array<string, array<string, mixed>> $latest  `FetchLog::latest()`, when the caller already has it.
	 * @return array<string, array<string, mixed>> Language => row with created_ts.
	 */
	public static function latest_rows( Plugin $plugin, ChannelInterface $channel, array $latest ): array {
		if ( $channel instanceof AbstractSearchSitemapChannel ) {
			$agents = $channel->fetch_agents();

			if ( array() !== $agents ) {
				$rows = $plugin->fetch_log()->latest_by_agent( AbstractSearchSitemapChannel::ANSWERING, $agents, true );

				unset( $rows[''] );

				return $rows;
			}
		}

		$prefix = $channel->id() . '|';
		$out    = array();

		foreach ( $latest as $key => $row ) {
			if ( 0 === strpos( (string) $key, $prefix ) ) {
				$out[ (string) $row['language'] ] = $row;
			}
		}

		return $out;
	}

	/**
	 * The latest fetch and the fetches in the last 24 hours, by language.
	 *
	 * @param string[] $channel_ids Channel ids that may have answered.
	 * @param string[] $agents      Crawler names from the fetch log, such as "bingbot".
	 * @return array<string, array{last_ts: int, fetches_24h: int, agent: string}> Language code => summary.
	 */
	public static function by_language( array $channel_ids, array $agents ): array {
		global $wpdb;

		$channel_ids = array_values( array_filter( array_map( 'strval', $channel_ids ) ) );
		$agents      = array_values( array_filter( array_map( 'strval', $agents ) ) );

		if ( array() === $channel_ids || array() === $agents ) {
			return array();
		}

		$table = Schema::table( 'fetch_log' );
		$in_ch = implode( ',', array_fill( 0, count( $channel_ids ), '%s' ) );
		$in_ag = implode( ',', array_fill( 0, count( $agents ), '%s' ) );

		// The values, in the order of the placeholders: the day, the table, the channels, the agents.
		$args = array_merge( array( Clock::to_gmt( Clock::now() - DAY_IN_SECONDS ), $table ), $channel_ids, $agents );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT language, agent, MAX(created_gmt) AS last_gmt, SUM(CASE WHEN created_gmt >= %s THEN 1 ELSE 0 END) AS recent FROM %i WHERE channel IN ( {$in_ch} ) AND agent IN ( {$in_ag} ) AND status IN ( 'ok', 'not_modified' ) GROUP BY language, agent", $args ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only %s placeholders are added to the lists.
			ARRAY_A
		);

		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$language = (string) $row['language'];
			$last     = Clock::from_gmt( (string) $row['last_gmt'] );

			if ( ! isset( $out[ $language ] ) ) {
				$out[ $language ] = array(
					'last_ts'     => 0,
					'fetches_24h' => 0,
					'agent'       => '',
				);
			}

			$out[ $language ]['fetches_24h'] += (int) $row['recent'];

			if ( $last > $out[ $language ]['last_ts'] ) {
				$out[ $language ]['last_ts'] = $last;
				$out[ $language ]['agent']   = (string) $row['agent'];
			}
		}

		return $out;
	}

	/**
	 * How each language's sitemap has been fetched, in the shape channels use for health.
	 *
	 * @param string[] $channel_ids    Channel ids that may have answered.
	 * @param string[] $agents         Crawler names from the fetch log.
	 * @param string[] $languages      Language codes to report on.
	 * @param int      $interval_hours The platform's usual hours between fetches.
	 * @return array<string, array{status: string, last_ts: int, fetches_24h: int, agent: string}> Language code => state. `status` is on_time, late or never.
	 */
	public static function states( array $channel_ids, array $agents, array $languages, int $interval_hours ): array {
		$rows   = self::by_language( $channel_ids, $agents );
		$states = array();

		foreach ( $languages as $language ) {
			$language = (string) $language;
			$row      = $rows[ $language ] ?? array(
				'last_ts'     => 0,
				'fetches_24h' => 0,
				'agent'       => '',
			);
			$last     = (int) $row['last_ts'];

			if ( $last <= 0 ) {
				$code = Status::NEVER;
			} elseif ( Clock::now() - $last > 2 * max( 1, $interval_hours ) * HOUR_IN_SECONDS ) {
				$code = Status::LATE;
			} else {
				$code = Status::ON_TIME;
			}

			$states[ $language ] = array(
				'status'      => $code,
				'last_ts'     => $last,
				'fetches_24h' => (int) $row['fetches_24h'],
				'agent'       => (string) $row['agent'],
			);
		}

		return $states;
	}

	/**
	 * The status code a screen prints for a fetch state: a language that was never
	 * fetched reads "Not fetched yet".
	 *
	 * @param string $code on_time, late or never.
	 * @return string
	 */
	public static function display( string $code ): string {
		return Status::NEVER === $code ? Status::NOT_FETCHED : $code;
	}

	/**
	 * The worst of several fetch states: later than usual, then never fetched, then on time.
	 *
	 * @param string[] $codes Fetch state codes.
	 * @return string
	 */
	public static function worst( array $codes ): string {
		foreach ( array( Status::LATE, Status::NEVER, Status::ON_TIME ) as $code ) {
			if ( in_array( $code, $codes, true ) ) {
				return $code;
			}
		}

		return Status::NEVER;
	}

	/**
	 * A short "how long ago", such as "6 min ago", "26 h ago" or "3 d ago".
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
	 * The crawler's display name for the fetch log's agent value.
	 *
	 * @param string $agent Agent value such as "bingbot" or "googlebot-news".
	 * @return string
	 */
	public static function agent_name( string $agent ): string {
		$names = array(
			'bingbot'        => 'Bingbot',
			'yandex'         => 'YandexBot',
			'baidu'          => 'Baiduspider',
			'googlebot'      => 'Google',
			'googlebot-news' => 'Google',
			'flipboard'      => 'Flipboard',
			'feedly'         => 'Feedly',
			'inoreader'      => 'Inoreader',
			'newsblur'       => 'NewsBlur',
		);

		return $names[ $agent ] ?? $agent;
	}
}

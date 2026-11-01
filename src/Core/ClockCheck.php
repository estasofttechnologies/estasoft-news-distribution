<?php
/**
 * Compares the clocks that decide the times in feeds, sitemaps and pushes.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, and changes nothing. It looks at the site's time zone setting, PHP's time
 * zone, the database clock, the person's browser clock (when the screen sends it)
 * and the articles dated in the future.
 *
 * Shape of `run()`:
 *
 *     array(
 *       'ran_at'   => int,     // Unix time of the check (the plugin's clock)
 *       'ok'       => bool,    // true when no finding is a warning
 *       'zone'     => array(
 *         'string'       => string, // "Europe/Amsterdam", "+02:00" or "UTC"
 *         'kind'         => string, // 'named', 'offset' or 'utc'
 *         'label'        => string, // for people: "UTC+2 (fixed offset)"
 *         'observes_dst' => bool,   // true when a named zone changes its clocks within the next 13 months
 *       ),
 *       'findings' => array( array(
 *         'id'    => string,  // 'zone', 'server', 'php', 'browser' or 'future'
 *         'tone'  => string,  // 'success', 'info' or 'warning'
 *         'title' => string,
 *         'text'  => string,
 *         'link'  => array{label: string, url: string}|null,
 *       ) ),
 *       'clocks'   => array( array(
 *         'id'     => string, // 'site', 'browser', 'php' or 'database'
 *         'label'  => string,
 *         'result' => string,
 *         'zone'   => string,
 *         'time'   => string, // as shown: "14:35" or "12:35 UTC"
 *         'tone'   => string, // 'success', 'info' or 'warning'
 *       ) ),
 *       'future'   => array(
 *         'count'    => int,   // all articles dated in the future
 *         'skipping' => bool,  // whether feeds, sitemaps and pushes skip them (setting clock.skip_future)
 *         'items'    => array( array(
 *           'post_id'       => int,
 *           'title'         => string,
 *           'feed_ts'       => int,  // the time the article carries
 *           'ahead_seconds' => int,
 *         ) ),             // at most 20, soonest first
 *       ),
 *     )
 *
 * The browser is optional: `run( array( 'time' => 1792665000, 'zone' => 'Europe/Amsterdam' ) )`.
 * Without it the browser row says it was not read.
 */
final class ClockCheck {

	/**
	 * Clocks that differ by at least this many seconds are reported.
	 */
	public const BROWSER_TOLERANCE = 120;

	private Plugin $plugin;

	/**
	 * Creates the check.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Runs the check.
	 *
	 * @param array{time?: int, zone?: string}|null $browser The browser's Unix time and IANA time zone, when known.
	 * @return array<string, mixed> See the class description.
	 */
	public function run( ?array $browser = null ): array {
		$now     = Clock::now();
		$real    = time();
		$browser = $this->clean_browser( $browser );
		$zone    = $this->zone();

		$findings = array();
		$clocks   = array();

		$zone_finding = $this->zone_finding( $zone, $browser, $now );
		$findings[]   = $zone_finding;

		$clocks[] = array(
			'id'     => 'site',
			'label'  => __( 'WordPress setting', 'estasoft-news-distribution' ),
			'result' => $zone['observes_dst'] ? __( 'Changes with daylight saving', 'estasoft-news-distribution' ) : __( 'No daylight saving', 'estasoft-news-distribution' ),
			'zone'   => $zone['label'],
			'time'   => Clock::format( $now, 'H:i' ),
			'tone'   => 'success',
		);

		$browser_parts = $this->browser_parts( $browser, $real );
		$clocks[]      = $browser_parts['clock'];

		$php_parts = $this->php_parts( $real );
		$clocks[]  = $php_parts['clock'];

		$db_parts = $this->database_parts( $real );
		$clocks[] = $db_parts['clock'];

		$findings[] = $db_parts['finding'];

		if ( null !== $php_parts['finding'] ) {
			$findings[] = $php_parts['finding'];
		}

		if ( null !== $browser_parts['finding'] ) {
			$findings[] = $browser_parts['finding'];
		}

		$future     = $this->future( $now );
		$findings[] = $this->future_finding( $future );

		$ok = true;

		foreach ( $findings as $finding ) {
			if ( 'warning' === $finding['tone'] ) {
				$ok = false;
			}
		}

		return array(
			'ran_at'   => $now,
			'ok'       => $ok,
			'zone'     => $zone,
			'findings' => $findings,
			'clocks'   => $clocks,
			'future'   => $future,
		);
	}

	/**
	 * Describes a span of seconds for people: "1 h 55 min", "35 min", "2 days 3 h".
	 *
	 * @param int $seconds Seconds, at least zero.
	 * @return string
	 */
	public static function human_span( int $seconds ): string {
		$seconds = max( 0, $seconds );
		$minutes = (int) floor( $seconds / 60 );

		if ( $minutes < 1 ) {
			return __( 'less than a minute', 'estasoft-news-distribution' );
		}

		$days  = intdiv( $minutes, 1440 );
		$hours = intdiv( $minutes % 1440, 60 );
		$mins  = $minutes % 60;

		if ( $days > 0 ) {
			return sprintf(
				/* translators: 1: number of days, 2: number of hours. */
				__( '%1$d d %2$d h', 'estasoft-news-distribution' ),
				$days,
				$hours
			);
		}

		if ( $hours > 0 && $mins > 0 ) {
			return sprintf(
				/* translators: 1: number of hours, 2: number of minutes. */
				__( '%1$d h %2$d min', 'estasoft-news-distribution' ),
				$hours,
				$mins
			);
		}

		if ( $hours > 0 ) {
			/* translators: %d: number of hours. */
			return sprintf( __( '%d h', 'estasoft-news-distribution' ), $hours );
		}

		/* translators: %d: number of minutes. */
		return sprintf( __( '%d min', 'estasoft-news-distribution' ), $mins );
	}

	/**
	 * The site's time zone as WordPress has it set.
	 *
	 * @return array{string: string, kind: string, label: string, observes_dst: bool}
	 */
	public function zone(): array {
		$name   = (string) get_option( 'timezone_string' );
		$offset = (float) get_option( 'gmt_offset' );

		if ( '' !== $name ) {
			return array(
				'string'       => $name,
				'kind'         => 'named',
				'label'        => $name,
				'observes_dst' => null !== self::next_transition( $name, Clock::now() ),
			);
		}

		if ( 0.0 === $offset ) {
			return array(
				'string'       => 'UTC',
				'kind'         => 'utc',
				'label'        => 'UTC',
				'observes_dst' => false,
			);
		}

		$sign    = $offset < 0 ? '-' : '+';
		$hours   = (int) floor( abs( $offset ) );
		$minutes = (int) round( ( abs( $offset ) - $hours ) * 60 );
		$short   = 'UTC' . $sign . $hours . ( $minutes > 0 ? ':' . sprintf( '%02d', $minutes ) : '' );

		return array(
			'string'       => sprintf( '%s%02d:%02d', $sign, $hours, $minutes ),
			'kind'         => 'offset',
			'label'        => sprintf(
				/* translators: %s: a fixed offset such as UTC+2. */
				__( '%s (fixed offset)', 'estasoft-news-distribution' ),
				$short
			),
			'observes_dst' => false,
		);
	}

	/**
	 * The next time a named zone changes its clocks within 13 months.
	 *
	 * @param string $zone_name IANA name such as Europe/Amsterdam.
	 * @param int    $from      Unix time to look from.
	 * @return array{ts: int, seconds: int}|null The moment and how far the clock moves, or null when it never does.
	 */
	public static function next_transition( string $zone_name, int $from ): ?array {
		try {
			$zone = new \DateTimeZone( $zone_name );
		} catch ( \Exception $error ) {
			return null;
		}

		$transitions = $zone->getTransitions( $from, $from + 400 * DAY_IN_SECONDS );

		if ( ! is_array( $transitions ) || count( $transitions ) < 2 ) {
			return null;
		}

		return array(
			'ts'      => (int) $transitions[1]['ts'],
			'seconds' => (int) $transitions[1]['offset'] - (int) $transitions[0]['offset'],
		);
	}

	/**
	 * Keeps only a browser time and zone that make sense.
	 *
	 * @param array<string, mixed>|null $browser What the screen sent.
	 * @return array{time: int, zone: string}|null
	 */
	private function clean_browser( ?array $browser ): ?array {
		if ( null === $browser || ! isset( $browser['time'] ) ) {
			return null;
		}

		$time = (int) $browser['time'];

		if ( $time < 946684800 || $time > 4102444800 ) {
			return null;
		}

		$zone = isset( $browser['zone'] ) && is_string( $browser['zone'] ) ? $browser['zone'] : '';

		if ( '' !== $zone && ! in_array( $zone, \DateTimeZone::listIdentifiers(), true ) ) {
			$zone = '';
		}

		return array(
			'time' => $time,
			'zone' => $zone,
		);
	}

	/**
	 * The finding about the time zone setting.
	 *
	 * @param array<string, mixed>      $zone    The site's zone.
	 * @param array<string, mixed>|null $browser The browser, when read.
	 * @param int                       $now     The plugin's time.
	 * @return array<string, mixed>
	 */
	private function zone_finding( array $zone, ?array $browser, int $now ): array {
		$link = array(
			'label' => __( 'Open WordPress time zone', 'estasoft-news-distribution' ),
			'url'   => admin_url( 'options-general.php#timezone_string' ),
		);

		if ( 'named' === $zone['kind'] ) {
			return array(
				'id'    => 'zone',
				'tone'  => 'success',
				'title' => sprintf(
					/* translators: %s: a time zone name such as Europe/Amsterdam. */
					__( 'WordPress uses the time zone %s', 'estasoft-news-distribution' ),
					$zone['label']
				),
				'text'  => $zone['observes_dst']
					? __( 'It follows daylight saving by itself, so times stay right all year.', 'estasoft-news-distribution' )
					: __( 'It has no daylight saving.', 'estasoft-news-distribution' ),
				'link'  => null,
			);
		}

		if ( 'utc' === $zone['kind'] ) {
			return array(
				'id'    => 'zone',
				'tone'  => 'success',
				'title' => __( 'WordPress uses UTC', 'estasoft-news-distribution' ),
				'text'  => __( 'Every time is read the same way in every place. If your place has a local time, you can choose it in the WordPress time zone setting.', 'estasoft-news-distribution' ),
				'link'  => $link,
			);
		}

		$title = sprintf(
			/* translators: %s: a fixed offset such as UTC+2. */
			__( 'WordPress uses a fixed offset: %s', 'estasoft-news-distribution' ),
			trim( (string) preg_replace( '/\s*\(.*\)$/', '', $zone['label'] ) )
		);

		// A browser in a place that changes its clocks, while the site uses a fixed offset that matches it today.
		if ( null !== $browser && '' !== $browser['zone'] ) {
			$next = self::next_transition( $browser['zone'], $now );

			if ( null !== $next ) {
				$when = wp_date( 'l j F', $next['ts'], new \DateTimeZone( $browser['zone'] ) );

				return array(
					'id'    => 'zone',
					'tone'  => 'warning',
					'title' => $title,
					'text'  => sprintf(
						/* translators: 1: a time zone name such as Europe/Amsterdam, 2: a date such as Sunday 25 October, 3: a length of time such as 1 h. */
						__( 'Your browser is set to %1$s, a place that changes its clocks. A fixed offset does not, so on %2$s every feed and sitemap time would be %3$s off.', 'estasoft-news-distribution' ),
						$browser['zone'],
						$when,
						self::human_span( abs( $next['seconds'] ) )
					),
					'link'  => $link,
				);
			}
		}

		return array(
			'id'    => 'zone',
			'tone'  => 'info',
			'title' => $title,
			'text'  => __( 'A fixed offset does not follow daylight saving. If your place changes its clocks, choose a city in the WordPress time zone setting, so times stay right all year.', 'estasoft-news-distribution' ),
			'link'  => $link,
		);
	}

	/**
	 * The database clock against PHP's.
	 *
	 * @param int $real PHP's Unix time now.
	 * @return array{clock: array<string, mixed>, finding: array<string, mixed>}
	 */
	private function database_parts( int $real ): array {
		global $wpdb;

		$database = $wpdb->get_var( 'SELECT UNIX_TIMESTAMP()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the database clock, which cannot be cached.

		if ( null === $database || ! is_numeric( $database ) ) {
			return array(
				'clock'   => array(
					'id'     => 'database',
					'label'  => __( 'Database', 'estasoft-news-distribution' ),
					'result' => __( 'Could not be read', 'estasoft-news-distribution' ),
					'zone'   => '—',
					'time'   => '—',
					'tone'   => 'info',
				),
				'finding' => array(
					'id'    => 'server',
					'tone'  => 'info',
					'title' => __( 'The database clock could not be read', 'estasoft-news-distribution' ),
					'text'  => __( 'The database did not answer the question, so it was not compared with PHP.', 'estasoft-news-distribution' ),
					'link'  => null,
				),
			);
		}

		$difference = (int) $database - $real;
		$agree      = abs( $difference ) <= 1;
		$zone_name  = $this->database_zone();

		return array(
			'clock'   => array(
				'id'     => 'database',
				'label'  => __( 'Database', 'estasoft-news-distribution' ),
				'result' => $agree
					? __( 'Matches PHP', 'estasoft-news-distribution' )
					: sprintf(
						/* translators: %s: a length of time such as 2 min. */
						__( 'Differs from PHP by %s', 'estasoft-news-distribution' ),
						self::human_span( abs( $difference ) )
					),
				'zone'   => $zone_name,
				'time'   => gmdate( 'H:i', (int) $database ) . ' UTC',
				'tone'   => $agree ? 'success' : 'warning',
			),
			'finding' => $agree
				? array(
					'id'    => 'server',
					'tone'  => 'success',
					'title' => __( 'The database and PHP agree', 'estasoft-news-distribution' ),
					'text'  => __( 'The two clocks on your server differ by less than a second.', 'estasoft-news-distribution' ),
					'link'  => null,
				)
				: array(
					'id'    => 'server',
					'tone'  => 'warning',
					'title' => sprintf(
						/* translators: %s: a length of time such as 2 min. */
						__( 'The database and PHP differ by %s', 'estasoft-news-distribution' ),
						self::human_span( abs( $difference ) )
					),
					'text'  => __( 'News Distribution cannot tell which clock is wrong. Dates saved by WordPress and dates read by PHP would not line up. Your host can check it.', 'estasoft-news-distribution' ),
					'link'  => null,
				),
		);
	}

	/**
	 * A name for the database's time zone.
	 *
	 * @return string
	 */
	private function database_zone(): string {
		global $wpdb;

		$session = (string) $wpdb->get_var( 'SELECT @@session.time_zone' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads a server variable.

		if ( '' === $session || 'SYSTEM' === strtoupper( $session ) ) {
			$session = (string) $wpdb->get_var( 'SELECT @@system_time_zone' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads a server variable.
		}

		return '' === $session ? '—' : $session;
	}

	/**
	 * PHP's clock and zone.
	 *
	 * @param int $real PHP's Unix time now.
	 * @return array{clock: array<string, mixed>, finding: array<string, mixed>|null}
	 */
	private function php_parts( int $real ): array {
		$zone = date_default_timezone_get();
		$utc  = 'UTC' === $zone;

		return array(
			'clock'   => array(
				'id'     => 'php',
				'label'  => __( 'PHP on the server', 'estasoft-news-distribution' ),
				'result' => $utc ? __( 'Matches WordPress', 'estasoft-news-distribution' ) : __( 'Is not UTC', 'estasoft-news-distribution' ),
				'zone'   => $zone,
				'time'   => ( $utc ? gmdate( 'H:i', $real ) : wp_date( 'H:i', $real, new \DateTimeZone( $zone ) ) ) . ' ' . ( $utc ? 'UTC' : $zone ),
				'tone'   => $utc ? 'success' : 'warning',
			),
			'finding' => $utc ? null : array(
				'id'    => 'php',
				'tone'  => 'warning',
				'title' => __( 'PHP is not set to UTC', 'estasoft-news-distribution' ),
				'text'  => sprintf(
					/* translators: %s: a time zone name. */
					__( 'A plugin or theme changed PHP’s time zone to %s. WordPress expects UTC, and code that uses PHP’s date() function reads the wrong time.', 'estasoft-news-distribution' ),
					$zone
				),
				'link'  => null,
			),
		);
	}

	/**
	 * The browser's clock against the server's.
	 *
	 * @param array{time: int, zone: string}|null $browser The browser, when read.
	 * @param int                                 $real    PHP's Unix time now.
	 * @return array{clock: array<string, mixed>, finding: array<string, mixed>|null}
	 */
	private function browser_parts( ?array $browser, int $real ): array {
		if ( null === $browser ) {
			return array(
				'clock'   => array(
					'id'     => 'browser',
					'label'  => __( 'Your browser', 'estasoft-news-distribution' ),
					'result' => __( 'Not read', 'estasoft-news-distribution' ),
					'zone'   => '—',
					'time'   => '—',
					'tone'   => 'info',
				),
				'finding' => null,
			);
		}

		$difference = $browser['time'] - $real;
		$agree      = abs( $difference ) < self::BROWSER_TOLERANCE;
		$span       = self::human_span( abs( $difference ) );
		$zone       = '' !== $browser['zone'] ? new \DateTimeZone( $browser['zone'] ) : new \DateTimeZone( 'UTC' );

		if ( $agree ) {
			$result = __( 'Matches the server', 'estasoft-news-distribution' );
		} elseif ( $difference > 0 ) {
			$result = sprintf(
				/* translators: %s: a length of time such as 7 min. */
				__( '%s ahead', 'estasoft-news-distribution' ),
				$span
			);
		} else {
			$result = sprintf(
				/* translators: %s: a length of time such as 7 min. */
				__( '%s behind', 'estasoft-news-distribution' ),
				$span
			);
		}

		return array(
			'clock'   => array(
				'id'     => 'browser',
				'label'  => __( 'Your browser', 'estasoft-news-distribution' ),
				'result' => $result,
				'zone'   => '' !== $browser['zone'] ? $browser['zone'] : '—',
				'time'   => wp_date( 'H:i', $browser['time'], $zone ),
				'tone'   => $agree ? 'success' : 'warning',
			),
			'finding' => $agree ? array(
				'id'    => 'browser',
				'tone'  => 'success',
				'title' => __( 'Your browser clock matches the server', 'estasoft-news-distribution' ),
				'text'  => __( 'The two differ by less than two minutes.', 'estasoft-news-distribution' ),
				'link'  => null,
			) : array(
				'id'    => 'browser',
				'tone'  => 'warning',
				'title' => sprintf(
					/* translators: %s: a length of time such as 7 minutes. */
					__( 'Your browser clock differs by %s', 'estasoft-news-distribution' ),
					$span
				),
				'text'  => __( 'News Distribution cannot tell which clock is wrong. If it is the server, scheduled posts go out at the wrong time. Your host can check it.', 'estasoft-news-distribution' ),
				'link'  => null,
			),
		);
	}

	/**
	 * Articles WordPress calls published that carry a time that has not come yet.
	 *
	 * @param int $now The plugin's time.
	 * @return array{count: int, skipping: bool, items: array<int, array<string, mixed>>}
	 */
	private function future( int $now ): array {
		$query = new \WP_Query(
			array(
				'post_type'           => $this->plugin->settings()->post_types(),
				'post_status'         => 'publish',
				'posts_per_page'      => 20,
				'orderby'             => 'date',
				'order'               => 'ASC',
				'ignore_sticky_posts' => true,
				'lang'                => '',
				'date_query'          => array(
					array(
						'column'    => 'post_date_gmt',
						'after'     => gmdate( 'Y-m-d H:i:s', $now ),
						'inclusive' => false,
					),
				),
			)
		);

		$items = array();

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$feed_ts = Clock::from_gmt( (string) $post->post_date_gmt );

			$items[] = array(
				'post_id'       => (int) $post->ID,
				'title'         => wp_strip_all_tags( (string) $post->post_title ),
				'feed_ts'       => $feed_ts,
				'ahead_seconds' => max( 0, $feed_ts - $now ),
			);
		}

		return array(
			'count'    => (int) $query->found_posts,
			'skipping' => (bool) $this->plugin->settings()->get( 'clock.skip_future', true ),
			'items'    => $items,
		);
	}

	/**
	 * The finding about articles dated in the future.
	 *
	 * @param array<string, mixed> $future The future section of the result.
	 * @return array<string, mixed>
	 */
	private function future_finding( array $future ): array {
		$count = (int) $future['count'];

		if ( $count < 1 ) {
			return array(
				'id'    => 'future',
				'tone'  => 'success',
				'title' => __( 'No article is dated in the future', 'estasoft-news-distribution' ),
				'text'  => __( 'Every published article carries a time that has already come.', 'estasoft-news-distribution' ),
				'link'  => null,
			);
		}

		$text = $future['skipping']
			? sprintf(
				/* translators: %s: a number of articles. */
				_n(
					'A platform may not accept an item whose publication time has not happened yet. Feeds, sitemaps and pushes skip %s article until its time, while the skip setting in this screen is on.',
					'A platform may not accept an item whose publication time has not happened yet. Feeds, sitemaps and pushes skip these %s articles until their time, while the skip setting in this screen is on.',
					$count,
					'estasoft-news-distribution'
				),
				number_format_i18n( $count )
			)
			: sprintf(
				/* translators: %s: a number of articles. */
				_n(
					'A platform may not accept an item whose publication time has not happened yet. The skip setting in this screen is off, so %s article is listed now.',
					'A platform may not accept an item whose publication time has not happened yet. The skip setting in this screen is off, so these %s articles are listed now.',
					$count,
					'estasoft-news-distribution'
				),
				number_format_i18n( $count )
			);

		return array(
			'id'    => 'future',
			'tone'  => 'warning',
			'title' => sprintf(
				/* translators: %s: a number of articles. */
				_n( '%s article is dated in the future', '%s articles are dated in the future', $count, 'estasoft-news-distribution' ),
				number_format_i18n( $count )
			),
			'text'  => $text,
			'link'  => array(
				'label' => __( 'Open in Article check', 'estasoft-news-distribution' ),
				'url'   => admin_url( 'admin.php?page=estasoft-nd-check' ),
			),
		);
	}
}

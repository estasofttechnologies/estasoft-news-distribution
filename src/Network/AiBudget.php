<?php
/**
 * The network's monthly AI budget: the running total, the check against it and the alert email.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Network;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Queue;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Counts the tokens every site of a network uses in a month, and holds the network to
 * `network_tokens_month` (Network Admin, AI policy). It does nothing on a single site.
 *
 * What a number means:
 *
 * - `network_tokens_month` is what all sites may use together in a month. 0 means no network limit.
 * - `max_tokens_month` is the most one site may set for itself (`Ai\Policy::token_cap()`). 0 means no cap.
 *   A site's own limit is never 0: its setting starts at 1,000.
 * - The month is the calendar month in UTC, for the whole network. A site counts in its own time zone
 *   (`Ai\Usage`), so for a few hours at a month's edge a site and the network can be in different months.
 *   Both are only a safeguard; a provider's bill is what counts.
 *
 * Where the numbers live. `Ai\Usage::record()` calls `add()` when a site finishes a request. The counters are
 * rows of the main site's options table, `estasoft_nd_net_ai_usage_<YYYY-MM>_total` and
 * `estasoft_nd_net_ai_usage_<YYYY-MM>_s<blog id>`, because only that table has a unique key on the name, and an
 * increment that cannot lose a write needs one: a single `INSERT ... ON DUPLICATE KEY UPDATE value = value + n`
 * adds to both rows, so two sites recording at the same moment both count. (The network's own meta table has no
 * unique key, so two writers creating the same row would make two rows.) Nobody reads these rows with
 * `get_option()`; `usage()` returns them as `{ month, total, sites: { blog id: tokens } }`, with one query
 * and no visit to a site. A new month has new rows, so nothing needs resetting.
 *
 * `rebuild()` recomputes the per-site rows from the sites themselves, through `SiteLoop` (at most 200 sites,
 * at most 20 seconds), and the total from the rows. It is what Network Admin's Refresh and the first daily
 * clean-up of a month run, so a missed write corrects itself. A request that finishes while a rebuild runs can
 * be left out of that rebuild; the next one counts it. A site the pass did not reach keeps the number it had.
 *
 * The alert. When a record takes the total to the alert level (80 or 90 percent) or to the whole budget, `add()`
 * claims that threshold for the month and queues one action (`ALERT_HOOK`); the email is sent from the queue, never
 * inside the AI request, to the network admin email address only, in the network's language. What was done is kept
 * in the option `estasoft_nd_net_ai_alerts` (main site), `{ "<YYYY-MM>": { <percent>: { state, at, tries } } }`,
 * changed only by a compare-and-swap, so two sites crossing the same threshold at once send one email. States:
 * `queued`, `sending`, `sent`, `retry` (the mail failed once; one more try a day later), `failed`, `superseded`
 * (the whole budget was reached before the warning went out, so only that email is sent). An email that was
 * claimed is never sent twice, even if the worker stops before it writes `sent`.
 */
final class AiBudget {

	public const ALERT_HOOK    = 'estasoft_nd_net_ai_alert';
	public const ALERTS_OPTION = 'estasoft_nd_net_ai_alerts';
	public const USAGE_OPTION  = 'estasoft_nd_net_ai_usage';

	/**
	 * The threshold that means the whole budget is used.
	 */
	public const FULL = 100;

	/**
	 * Months of alert records kept.
	 */
	private const KEEP_MONTHS = 3;

	/**
	 * Cache group. It is global and not kept between requests, so a changed total is never read from an old request.
	 */
	private const GROUP = 'estasoft_nd_net_ai';

	/* --------------------------------------------------------------- Time. */

	/**
	 * The month the network counts, as `YYYY-MM` in UTC.
	 *
	 * @param int|null $time Unix time, or null for now.
	 * @return string
	 */
	public static function month( ?int $time = null ): string {
		return gmdate( 'Y-m', null === $time ? Clock::now() : $time );
	}

	/**
	 * The month a number of months before this one, as `YYYY-MM` in UTC.
	 *
	 * @param int $back Months back; 0 is this month.
	 * @return string
	 */
	private static function month_back( int $back ): string {
		$now = Clock::now();

		return gmdate( 'Y-m', (int) gmmktime( 0, 0, 0, (int) gmdate( 'n', $now ) - $back, 1, (int) gmdate( 'Y', $now ) ) );
	}

	/**
	 * When the count starts again: midnight UTC on the first of next month.
	 *
	 * @return int Unix time.
	 */
	public static function resets_at(): int {
		$now = Clock::now();

		return (int) gmmktime( 0, 0, 0, (int) gmdate( 'n', $now ) + 1, 1, (int) gmdate( 'Y', $now ) );
	}

	/* ------------------------------------------------------------ The limit. */

	/**
	 * Tokens a month all sites may use together, or 0 when there is no limit.
	 *
	 * @return int
	 */
	public static function limit(): int {
		return max( 0, (int) AiPolicy::get()['network_tokens_month'] );
	}

	/**
	 * Whether the network has used its whole budget this month. Always false on a single site and when there is no limit.
	 *
	 * @return bool
	 */
	public static function exhausted(): bool {
		$limit = self::limit();

		return is_multisite() && $limit > 0 && self::total() >= $limit;
	}

	/**
	 * Percent of the network limit used, rounded down; 0 when there is no limit.
	 *
	 * @return int
	 */
	public static function percent(): int {
		$limit = self::limit();

		return $limit > 0 ? (int) floor( self::total() * 100 / $limit ) : 0;
	}

	/* ------------------------------------------------------------ The count. */

	/**
	 * Adds the tokens one site used.
	 *
	 * One statement adds to the network row and the site row, so it never loses what another site adds at the
	 * same moment. When this takes the total over the alert level, one alert is queued.
	 *
	 * @param int $blog_id The site that used them.
	 * @param int $tokens  Tokens the provider reported.
	 * @return bool False when nothing was counted: not a network, nothing used or the database refused.
	 */
	public static function add( int $blog_id, int $tokens ): bool {
		global $wpdb;

		if ( ! is_multisite() || $blog_id < 1 || $tokens < 1 ) {
			return false;
		}

		$month = self::month();
		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own rows; the table name is built from the prefix.
		$done = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %d, 'no'), (%s, %d, 'no') ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + %d",
				$table,
				self::name( $month, 'total' ),
				$tokens,
				self::name( $month, 's' . $blog_id ),
				$tokens,
				$tokens
			)
		);
		// phpcs:enable

		if ( false === $done ) {
			return false;
		}

		wp_cache_delete( 'total_' . $month, self::GROUP );

		try {
			self::note();
		} catch ( \Throwable $failure ) {
			// A request that was already counted is never undone because the alert could not be queued; the daily clean-up tries again.
			unset( $failure );
		}

		return true;
	}

	/**
	 * The count this month.
	 *
	 * @return array{month: string, total: int, sites: array<int, int>} Tokens for the whole network, and for each site that used any.
	 */
	public static function usage(): array {
		global $wpdb;

		$month  = self::month();
		$prefix = self::USAGE_OPTION . '_' . $month . '_';
		$table  = self::table();
		$total  = 0;
		$sites  = array();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own rows; the table name is built from the prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s', $table, $wpdb->esc_like( $prefix ) . '%' ),
			ARRAY_A
		);
		// phpcs:enable

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$part = substr( (string) $row['option_name'], strlen( $prefix ) );

			if ( 'total' === $part ) {
				$total = max( 0, (int) $row['option_value'] );
			} elseif ( 1 === preg_match( '/^s([0-9]+)$/', $part, $found ) ) {
				$sites[ (int) $found[1] ] = max( 0, (int) $row['option_value'] );
			}
		}

		ksort( $sites );

		return array(
			'month' => $month,
			'total' => $total,
			'sites' => $sites,
		);
	}

	/**
	 * Tokens all sites have used this month. One small query, kept for the rest of the request; it never visits a site.
	 *
	 * @return int
	 */
	public static function total(): int {
		global $wpdb;

		$month = self::month();

		wp_cache_add_global_groups( array( self::GROUP ) );
		wp_cache_add_non_persistent_groups( array( self::GROUP ) );

		$cached = wp_cache_get( 'total_' . $month, self::GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own row; the table name is built from the prefix.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $table, self::name( $month, 'total' ) ) );
		// phpcs:enable

		$total = null === $value ? 0 : max( 0, (int) $value );

		wp_cache_set( 'total_' . $month, $total, self::GROUP );

		return $total;
	}

	/**
	 * Whether this month's count was checked against the sites at least once.
	 *
	 * @return bool
	 */
	public static function is_built(): bool {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own row; the table name is built from the prefix.
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $table, self::name( self::month(), 'built' ) ) );
		// phpcs:enable
	}

	/**
	 * Counts again from the sites.
	 *
	 * Visits at most `SiteLoop::MAX_SITES` sites, each for one option, and writes each one's tokens as its row, then
	 * the total as the sum of every site's row, so a site the pass did not reach keeps what it had.
	 *
	 * @return array{month: string, total: int, sites: array<int, int>, read: int, left: int} `read` sites were counted from the site, `left` were not reached in time.
	 */
	public static function rebuild(): array {
		global $wpdb;

		$month = self::month();
		$visit = SiteLoop::visit(
			SiteLoop::site_ids(),
			static function () use ( $month ): int {
				return SiteAi::tokens_in( $month );
			}
		);
		$table = self::table();
		$read  = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own rows; the table name is built from the prefix.
		foreach ( $visit['done'] as $blog_id => $tokens ) {
			if ( is_wp_error( $tokens ) ) {
				continue;
			}

			++$read;

			if ( (int) $tokens < 0 ) {
				// The site's own count is for another month (its calendar has not turned yet, or already has). What was
				// added to the network's count while it was used stays.
				continue;
			}

			if ( (int) $tokens > 0 ) {
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %d, 'no') ON DUPLICATE KEY UPDATE option_value = %d",
						$table,
						self::name( $month, 's' . (int) $blog_id ),
						(int) $tokens,
						(int) $tokens
					)
				);
			} else {
				// A site with nothing this month: forget what an old count said, and make no row for it.
				$wpdb->query( $wpdb->prepare( "UPDATE %i SET option_value = '0' WHERE option_name = %s", $table, self::name( $month, 's' . (int) $blog_id ) ) );
			}
		}

		$sum = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE( SUM( CAST( option_value AS UNSIGNED ) ), 0 ) FROM %i WHERE option_name LIKE %s',
				$table,
				$wpdb->esc_like( self::USAGE_OPTION . '_' . $month . '_s' ) . '%'
			)
		);

		foreach ( array(
			'total' => $sum,
			'built' => Clock::now(),
		) as $part => $value ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %d, 'no') ON DUPLICATE KEY UPDATE option_value = %d",
					$table,
					self::name( $month, $part ),
					$value,
					$value
				)
			);
		}
		// phpcs:enable

		wp_cache_delete( 'total_' . $month, self::GROUP );

		$usage = self::usage();

		return array(
			'month' => $usage['month'],
			'total' => $usage['total'],
			'sites' => $usage['sites'],
			'read'  => $read,
			'left'  => count( $visit['left'] ),
		);
	}

	/**
	 * The daily clean-up's part, run by every site's queue. It is cheap: a first run in a new month counts again from
	 * the sites and forgets old months, and every run looks for an alert that is due.
	 *
	 * @return void
	 */
	public static function sweep(): void {
		if ( ! is_multisite() ) {
			return;
		}

		if ( ! self::is_built() ) {
			self::rebuild();
			self::prune();
		}

		// The budget may have been lowered below what is used, or an action may have been lost.
		self::note();
		self::requeue();
	}

	/**
	 * Deletes the counters of months before last month.
	 *
	 * @return void
	 */
	private static function prune(): void {
		global $wpdb;

		$table = self::table();
		$like  = $wpdb->esc_like( self::USAGE_OPTION . '_' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own rows; the table name is built from the prefix.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s',
				$table,
				$like . '%',
				$like . $wpdb->esc_like( self::month_back( 0 ) . '_' ) . '%',
				$like . $wpdb->esc_like( self::month_back( 1 ) . '_' ) . '%'
			)
		);
		// phpcs:enable
	}

	/* ------------------------------------------------------------ The alert. */

	/**
	 * What was done about this month's alerts.
	 *
	 * @return array<int, array{state: string, at: int, tries: int}> By threshold in percent: the alert level (80 or 90) and 100.
	 */
	public static function alerts(): array {
		$out = array();

		foreach ( (array) ( self::read_alerts()[ self::month() ] ?? array() ) as $level => $entry ) {
			if ( is_array( $entry ) ) {
				$out[ (int) $level ] = array(
					'state' => (string) ( $entry['state'] ?? '' ),
					'at'    => (int) ( $entry['at'] ?? 0 ),
					'tries' => (int) ( $entry['tries'] ?? 0 ),
				);
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Looks at the total and claims the highest threshold it has reached, once a month.
	 *
	 * @return void
	 */
	private static function note(): void {
		$limit = self::limit();

		if ( $limit < 1 ) {
			return;
		}

		$total    = self::total();
		$alert_at = (int) AiPolicy::get()['alert_at'];
		$level    = 0;

		if ( $total >= $limit ) {
			$level = self::FULL;
		} elseif ( $total * 100 >= $limit * $alert_at ) {
			$level = $alert_at;
		}

		if ( 0 === $level ) {
			return;
		}

		$month = self::month();

		// One cheap read first: after the first claim every later record stops here.
		if ( isset( self::read_alerts()[ $month ][ $level ] ) ) {
			return;
		}

		$claimed = self::change(
			static function ( array $record ) use ( $month, $level ): ?array {
				if ( isset( $record[ $month ][ $level ] ) ) {
					return null;
				}

				$record[ $month ][ $level ] = array(
					'state' => 'queued',
					'at'    => Clock::now(),
					'tries' => 0,
				);

				// Months older than the ones kept are dropped as the record is written.
				$keep = array( $month => true );

				for ( $back = 1; $back < self::KEEP_MONTHS; $back++ ) {
					$keep[ self::month_back( $back ) ] = true;
				}

				return array_intersect_key( $record, $keep );
			}
		);

		if ( null !== $claimed ) {
			self::schedule( $month, $level, 0 );
		}
	}

	/**
	 * Queues the action that sends one alert. Nothing is sent here.
	 *
	 * The queue is asked for an action that is not "unique": its uniqueness looks at the hook and the group only, so
	 * the warning would keep the budget-used email out while it waits, and a retry could not be booked from the
	 * action that is running. Sending twice is prevented by the alert record, not by the queue.
	 *
	 * @param string $month Month of the alert.
	 * @param int    $level Threshold in percent.
	 * @param int    $delay Seconds to wait.
	 * @return void
	 */
	private static function schedule( string $month, int $level, int $delay ): void {
		$queue = Plugin::instance()->queue();
		$args  = array( $month, $level );

		if ( ! $queue->is_available() ) {
			wp_schedule_single_event( Clock::now() + max( 60, $delay ), self::ALERT_HOOK, $args );

			return;
		}

		if ( $delay > 0 ) {
			as_schedule_single_action( Clock::now() + $delay, self::ALERT_HOOK, $args, Queue::GROUP );
		} else {
			as_enqueue_async_action( self::ALERT_HOOK, $args, Queue::GROUP );
		}
	}

	/**
	 * Books again an alert whose action may have been lost: queued for over an hour, or a retry a day and an hour late.
	 * One that is still booked is left alone.
	 *
	 * @return void
	 */
	private static function requeue(): void {
		$month = self::month();
		$now   = Clock::now();
		$queue = Plugin::instance()->queue();

		foreach ( (array) ( self::read_alerts()[ $month ] ?? array() ) as $level => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$age = $now - (int) ( $entry['at'] ?? 0 );

			if ( ! ( 'queued' === ( $entry['state'] ?? '' ) && $age > HOUR_IN_SECONDS ) && ! ( 'retry' === ( $entry['state'] ?? '' ) && $age > DAY_IN_SECONDS + HOUR_IN_SECONDS ) ) {
				continue;
			}

			if ( $queue->is_available() && as_has_scheduled_action( self::ALERT_HOOK, array( $month, (int) $level ), Queue::GROUP ) ) {
				continue;
			}

			self::schedule( $month, (int) $level, 0 );
		}
	}

	/**
	 * Whether the total has reached a percent of the limit now.
	 *
	 * @param int $level Percent.
	 * @return bool
	 */
	private static function reached( int $level ): bool {
		$limit = self::limit();

		return $limit > 0 && self::total() * 100 >= $limit * $level;
	}

	/**
	 * The queue's handler: sends one alert email, once.
	 *
	 * @param mixed $month Month of the alert, `YYYY-MM`.
	 * @param mixed $level Threshold in percent.
	 * @return void
	 */
	public static function run_alert( $month = '', $level = 0 ): void {
		if ( ! is_multisite() ) {
			return;
		}

		$month = is_string( $month ) ? $month : '';
		$level = (int) $level;

		if ( '' === $month || $level < 1 || self::month() !== $month ) {
			return;
		}

		// The limit was raised since, or the total no longer reaches it: forget the claim, so a later crossing can ask again.
		if ( ! self::reached( $level ) ) {
			self::change(
				static function ( array $record ) use ( $month, $level ): ?array {
					if ( ! in_array( $record[ $month ][ $level ]['state'] ?? '', array( 'queued', 'retry' ), true ) ) {
						return null;
					}

					unset( $record[ $month ][ $level ] );

					return $record;
				}
			);

			return;
		}

		// The whole budget went first: that email says it all.
		$next = $level < self::FULL && self::exhausted() ? 'superseded' : 'sending';

		$claimed = self::change(
			static function ( array $record ) use ( $month, $level, $next ): ?array {
				$entry = $record[ $month ][ $level ] ?? null;

				if ( ! is_array( $entry ) || ! in_array( $entry['state'] ?? '', array( 'queued', 'retry' ), true ) ) {
					return null;
				}

				$record[ $month ][ $level ] = array(
					'state' => $next,
					'at'    => Clock::now(),
					'tries' => (int) ( $entry['tries'] ?? 0 ) + ( 'sending' === $next ? 1 : 0 ),
				);

				return $record;
			}
		);

		if ( null === $claimed || 'sending' !== $next ) {
			return;
		}

		$tries  = (int) $claimed[ $month ][ $level ]['tries'];
		$result = self::send( $month, $level, self::total(), self::limit() );
		$state  = $result['ok'] ? 'sent' : ( $tries < 2 ? 'retry' : 'failed' );

		self::change(
			static function ( array $record ) use ( $month, $level, $state ): ?array {
				if ( 'sending' !== ( $record[ $month ][ $level ]['state'] ?? '' ) ) {
					return null;
				}

				$record[ $month ][ $level ]['state'] = $state;
				$record[ $month ][ $level ]['at']    = Clock::now();

				return $record;
			}
		);

		if ( 'retry' === $state ) {
			self::schedule( $month, $level, DAY_IN_SECONDS );
		}

		self::audit( $result, $state );
	}

	/**
	 * Writes what happened to the audit log of the main site.
	 *
	 * @param array{ok: bool, subject: string, error: string} $result What the mail did.
	 * @param string                                          $state  `sent`, `retry` or `failed`.
	 * @return void
	 */
	private static function audit( array $result, string $state ): void {
		$detail = 'sent' === $state
			? sprintf(
				/* translators: %s: the subject of the email. */
				__( 'Network AI budget alert emailed to the network admin address: %s', 'estasoft-news-distribution' ),
				$result['subject']
			)
			: sprintf(
				/* translators: 1: the subject of the email, 2: why it could not be sent. */
				__( 'Network AI budget alert could not be emailed (%1$s): %2$s', 'estasoft-news-distribution' ),
				$result['subject'],
				'' === $result['error'] ? __( 'WordPress could not send the email.', 'estasoft-news-distribution' ) : $result['error']
			);

		SiteLoop::in_site(
			get_main_site_id(),
			static function () use ( $detail ): void {
				AiPolicy::audit( $detail );
			}
		);
	}

	/**
	 * Sends the email to the network admin address, in the network's language.
	 *
	 * @param string $month Month.
	 * @param int    $level Threshold in percent.
	 * @param int    $total Tokens used.
	 * @param int    $limit Network limit.
	 * @return array{ok: bool, subject: string, error: string}
	 */
	private static function send( string $month, int $level, int $total, int $limit ): array {
		$to     = (string) get_site_option( 'admin_email', '' );
		$locale = (string) get_site_option( 'WPLANG', '' );
		$locale = '' === $locale ? 'en_US' : $locale;
		$switch = switch_to_locale( $locale );

		try {
			$mail = self::compose( $month, $level, $total, $limit );
		} finally {
			if ( $switch ) {
				restore_previous_locale();
			}
		}

		if ( ! is_email( $to ) ) {
			return array(
				'ok'      => false,
				'subject' => $mail['subject'],
				'error'   => __( 'The network has no admin email address.', 'estasoft-news-distribution' ),
			);
		}

		$error   = '';
		$capture = static function ( $failure ) use ( &$error ): void {
			$error = $failure instanceof \WP_Error ? $failure->get_error_message() : '';
		};

		add_action( 'wp_mail_failed', $capture );

		try {
			$sent = wp_mail( $to, $mail['subject'], $mail['body'], array( 'Content-Type: text/plain; charset=UTF-8' ) );
		} finally {
			remove_action( 'wp_mail_failed', $capture );
		}

		return array(
			'ok'      => (bool) $sent,
			'subject' => $mail['subject'],
			'error'   => (bool) $sent ? '' : $error,
		);
	}

	/**
	 * The email, in the language that is active now. Figures and a link; never article text, a key or a site's name.
	 *
	 * @param string $month Month.
	 * @param int    $level Threshold in percent.
	 * @param int    $total Tokens used.
	 * @param int    $limit Network limit.
	 * @return array{subject: string, body: string}
	 */
	private static function compose( string $month, int $level, int $total, int $limit ): array {
		$utc     = new \DateTimeZone( 'UTC' );
		$network = get_network();
		$name    = $network instanceof \WP_Network && '' !== trim( (string) $network->site_name ) ? trim( (string) $network->site_name ) : (string) get_site_option( 'site_name', '' );
		$name    = '' === $name ? wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) : $name;
		$when    = wp_date( 'F Y', (int) strtotime( $month . '-01 00:00:00 UTC' ), $utc );
		$resets  = wp_date( 'j M Y', self::resets_at(), $utc );
		$full    = $level >= self::FULL;

		if ( $full ) {
			$subject = sprintf(
				/* translators: %s: the network's name. */
				__( '%s: the network’s monthly AI budget is used', 'estasoft-news-distribution' ),
				$name
			);
			$lead = __( 'The network’s monthly AI budget is used.', 'estasoft-news-distribution' );
			$next = sprintf(
				/* translators: %s: the date the count starts again, such as "1 Nov 2026". */
				__( 'The AI assistant is paused on every site until the count starts again on %s. Feeds, sitemaps and pushes are not affected.', 'estasoft-news-distribution' ),
				$resets
			);
			$ask = __( 'To let the assistant keep working this month, raise the budget in Network Admin, AI policy:', 'estasoft-news-distribution' );
		} else {
			$subject = sprintf(
				/* translators: 1: the network's name, 2: percent of the budget used, such as 80. */
				__( '%1$s: the AI assistant has used %2$d%% of the monthly budget', 'estasoft-news-distribution' ),
				$name,
				$level
			);
			$lead = sprintf(
				/* translators: %d: percent of the budget used, such as 80. */
				__( 'The AI assistant has used %d%% of this network’s monthly token budget.', 'estasoft-news-distribution' ),
				$level
			);
			$next = sprintf(
				/* translators: %s: the date the count starts again, such as "1 Nov 2026". */
				__( 'When the budget is used up, the AI assistant pauses on every site until the count starts again on %s. Feeds, sitemaps and pushes are not affected.', 'estasoft-news-distribution' ),
				$resets
			);
			$ask = __( 'To change the budget or the alert level, open Network Admin, AI policy:', 'estasoft-news-distribution' );
		}

		$lines = array(
			$lead,
			'',
			sprintf(
				/* translators: %s: a month and year, such as "October 2026". */
				__( 'Month: %s', 'estasoft-news-distribution' ),
				$when
			),
			sprintf(
				/* translators: %s: a number of tokens, such as "16,000,000". */
				__( 'Used: %s tokens', 'estasoft-news-distribution' ),
				number_format_i18n( $total )
			),
			sprintf(
				/* translators: %s: a number of tokens, such as "20,000,000". */
				__( 'Budget: %s tokens', 'estasoft-news-distribution' ),
				number_format_i18n( $limit )
			),
			'',
			$next,
			'',
			$ask,
			NetworkAdmin::url( 'ai-policy' ),
			'',
			__( 'News Distribution sent this to the network admin email address. It holds figures only: no article text and no keys.', 'estasoft-news-distribution' ),
		);

		return array(
			'subject' => wp_specialchars_decode( $subject, ENT_QUOTES ),
			'body'    => implode( "\n", $lines ),
		);
	}

	/* ------------------------------------------------------ The alert record. */

	/**
	 * The whole alert record, read from the database (never from a cache).
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function read_alerts(): array {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own row; the table name is built from the prefix.
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $table, self::ALERTS_OPTION ) );
		// phpcs:enable

		$record = null === $raw ? array() : maybe_unserialize( $raw );

		return is_array( $record ) ? $record : array();
	}

	/**
	 * Changes the alert record so that two changes at the same moment cannot both win.
	 *
	 * The record is read, `$change` makes the new one, and the new one is written only if the stored value is still
	 * the one that was read (or the row did not exist and nobody made it first). When another change got there first,
	 * the whole step is run again on what that one wrote, a few times.
	 *
	 * @param callable $change Gets the record; returns the new record, or null to change nothing.
	 * @return array<string, array<int, array<string, mixed>>>|null The record as written, or null when nothing was written.
	 */
	private static function change( callable $change ): ?array {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own row; the table name is built from the prefix.
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$raw    = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $table, self::ALERTS_OPTION ) );
			$record = null === $raw ? array() : maybe_unserialize( $raw );
			$next   = $change( is_array( $record ) ? $record : array() );

			if ( null === $next ) {
				return null;
			}

			$value = maybe_serialize( $next );

			if ( null === $raw ) {
				$won = 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $table, self::ALERTS_OPTION, $value ) );
			} else {
				$won = 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $table, $value, self::ALERTS_OPTION, (string) $raw ) );
			}

			if ( $won ) {
				return $next;
			}
		}
		// phpcs:enable

		return null;
	}

	/* ------------------------------------------------------------- Plumbing. */

	/**
	 * The options table of the main site, where the counters live.
	 *
	 * @return string
	 */
	private static function table(): string {
		global $wpdb;

		return $wpdb->get_blog_prefix( get_main_site_id() ) . 'options';
	}

	/**
	 * The name of one counter row.
	 *
	 * @param string $month `YYYY-MM`.
	 * @param string $part  `total`, `built` or `s<blog id>`.
	 * @return string
	 */
	private static function name( string $month, string $part ): string {
		return self::USAGE_OPTION . '_' . $month . '_' . $part;
	}
}

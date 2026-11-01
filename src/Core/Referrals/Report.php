<?php
/**
 * The figures of the Referrals report.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Referrals;

use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the counts and shapes them for the screen and for `GET /reports/referrals`.
 *
 * Plain values only: no HTML, days as `Y-m-d` in the site's time zone.
 *
 * - The period is today and the days before it: 7, 30 or 90 days.
 * - A source is listed once it has a visit in the period. Platforms come first, most visits first,
 *   then "other", then "direct".
 * - The change is against the same number of days just before the period. It is given only when
 *   counting has run since before that earlier period began (otherwise it would compare a whole
 *   period with part of one), and never for 90 days: counts are kept for 90 days, so there is
 *   nothing to compare the last 90 days with.
 * - The articles table shows the four platforms that sent the most visits, and the ten articles
 *   they sent the most readers to.
 */
final class Report {

	/**
	 * How many platform columns the articles table has, at most.
	 */
	public const COLUMNS = 4;

	/**
	 * How many articles the articles table lists, at most.
	 */
	public const ARTICLES = 10;

	/**
	 * Platforms that have an application status in the tracker, by their source id: the tracker's id.
	 *
	 * @var array<string, string>
	 */
	private const APPLICATIONS = array(
		'microsoft_start' => 'microsoft-start',
		'yahoo_news'      => 'yahoo-news',
		'apple_news'      => 'apple-news',
		'smartnews'       => 'smartnews',
	);

	private Plugin $plugin;

	/**
	 * Creates the report.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * A period from the address: the `days` argument, limited to the periods that exist.
	 *
	 * @param mixed $value What the address carried.
	 * @return int 7, 30 or 90.
	 */
	public static function days( $value ): int {
		$days = is_scalar( $value ) ? (int) $value : 0;

		return in_array( $days, Counter::PERIODS, true ) ? $days : Counter::PERIODS[0];
	}

	/**
	 * The figures for a period.
	 *
	 * @param int $days 7, 30 or 90.
	 * @return array<string, mixed>
	 */
	public function build( int $days ): array {
		$days    = self::days( $days );
		$counter = $this->plugin->make( Counter::class );
		$today   = Counter::day();
		$from    = Counter::first_day_of( $days );
		$since   = $counter->since();
		$before  = array(
			'to'   => Counter::shift( $from, -1 ),
			'from' => Counter::shift( $from, -$days ),
		);
		$compare = $days < Counter::RETENTION_DAYS && '' !== $since && $since <= $before['from'];

		$now  = $this->by_source( $from, $today );
		$prev = $compare ? $this->by_source( $before['from'], $before['to'] ) : array();
		$sum  = array_sum( $now );

		return array(
			'enabled'        => $counter->enabled(),
			'days'           => $days,
			'periods'        => Counter::PERIODS,
			'from'           => $from,
			'to'             => $today,
			'previous'       => $compare ? $before : null,
			'since'          => '' === $since ? null : $since,
			'retention_days' => Counter::RETENTION_DAYS,
			'total'          => $sum,
			'sources'        => $this->sources( $now, $prev, $sum, $compare ),
			'articles'       => $this->articles( $now, $from, $today ),
			'in_review'      => $this->in_review( $now ),
		);
	}

	/**
	 * Visits per source in a range of days.
	 *
	 * @param string $from First day, Y-m-d.
	 * @param string $to   Last day, Y-m-d.
	 * @return array<string, int> Source id => visits, only sources with a visit.
	 */
	private function by_source( string $from, string $to ): array {
		global $wpdb;

		$table = Counter::table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT source, SUM(visits) AS visits FROM %i WHERE day BETWEEN %s AND %s GROUP BY source', $table, $from, $to ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is built from the prefix.
			ARRAY_A
		);
		$out   = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$visits = (int) $row['visits'];

			if ( $visits > 0 && Sources::is_source( (string) $row['source'] ) ) {
				$out[ (string) $row['source'] ] = $visits;
			}
		}

		return $out;
	}

	/**
	 * The rows of "Visits by source".
	 *
	 * @param array<string, int> $now     Visits per source in the period.
	 * @param array<string, int> $prev    Visits per source in the period before, or none.
	 * @param int                $total   All visits in the period.
	 * @param bool               $compare Whether a change can be given.
	 * @return array<int, array<string, mixed>>
	 */
	private function sources( array $now, array $prev, int $total, bool $compare ): array {
		$order = array_flip( Sources::ids() );
		$ids   = array_keys( $now );

		usort(
			$ids,
			static function ( string $a, string $b ) use ( $now, $order ): int {
				$rank = static function ( string $id ): int {
					if ( Sources::DIRECT === $id ) {
						return 2;
					}

					return Sources::OTHER === $id ? 1 : 0;
				};

				$by_rank = $rank( $a ) <=> $rank( $b );

				if ( 0 !== $by_rank ) {
					return $by_rank;
				}

				$by_visits = $now[ $b ] <=> $now[ $a ];

				return 0 !== $by_visits ? $by_visits : ( $order[ $a ] ?? 99 ) <=> ( $order[ $b ] ?? 99 );
			}
		);

		$rows = array();

		foreach ( $ids as $id ) {
			$before = $compare ? (int) ( $prev[ $id ] ?? 0 ) : null;

			$rows[] = array(
				'id'             => $id,
				'label'          => Sources::label( $id ),
				'detail'         => Sources::detail( $id ),
				'visits'         => $now[ $id ],
				'share'          => $total > 0 ? (int) round( 100 * $now[ $id ] / $total ) : 0,
				'previous'       => $before,
				'change_percent' => null !== $before && $before > 0 ? (int) round( 100 * ( $now[ $id ] - $before ) / $before ) : null,
			);
		}

		return $rows;
	}

	/**
	 * The "Top articles by source" table.
	 *
	 * @param array<string, int> $now  Visits per source in the period.
	 * @param string             $from First day, Y-m-d.
	 * @param string             $to   Last day, Y-m-d.
	 * @return array{sources: array<int, array{id: string, label: string}>, rows: array<int, array<string, mixed>>}
	 */
	private function articles( array $now, string $from, string $to ): array {
		global $wpdb;

		$platforms = array_filter(
			$now,
			static function ( int $visits, string $id ): bool {
				return Sources::is_platform( $id ) && $visits > 0;
			},
			ARRAY_FILTER_USE_BOTH
		);

		arsort( $platforms );

		$order   = array_flip( Sources::ids() );
		$columns = array_keys( $platforms );

		usort(
			$columns,
			static function ( string $a, string $b ) use ( $platforms, $order ): int {
				$by_visits = $platforms[ $b ] <=> $platforms[ $a ];

				return 0 !== $by_visits ? $by_visits : $order[ $a ] <=> $order[ $b ];
			}
		);

		$columns = array_slice( $columns, 0, self::COLUMNS );

		if ( array() === $columns ) {
			return array(
				'sources' => array(),
				'rows'    => array(),
			);
		}

		$table        = Counter::table();
		$placeholders = implode( ', ', array_fill( 0, count( $columns ), '%s' ) );
		$found        = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One %s per source, and the days.
				"SELECT post_id, source, SUM(visits) AS visits FROM %i WHERE day BETWEEN %s AND %s AND source IN ({$placeholders}) GROUP BY post_id, source", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The placeholders are one %s per source.
				array_merge( array( $table, $from, $to ), $columns )
			),
			ARRAY_A
		);

		$by_post = array();

		foreach ( is_array( $found ) ? $found : array() as $row ) {
			$post_id = (int) $row['post_id'];

			if ( ! isset( $by_post[ $post_id ] ) ) {
				$by_post[ $post_id ] = array_fill_keys( $columns, 0 );
			}

			$by_post[ $post_id ][ (string) $row['source'] ] = (int) $row['visits'];
		}

		uksort(
			$by_post,
			static function ( int $a, int $b ) use ( $by_post ): int {
				$by_total = array_sum( $by_post[ $b ] ) <=> array_sum( $by_post[ $a ] );

				return 0 !== $by_total ? $by_total : $b <=> $a;
			}
		);

		$rows = array();

		foreach ( $by_post as $post_id => $visits ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$rows[] = array(
				'id'     => $post_id,
				'title'  => wp_specialchars_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES ),
				'url'    => (string) get_permalink( $post ),
				'visits' => $visits,
				'total'  => array_sum( $visits ),
			);

			if ( count( $rows ) >= self::ARTICLES ) {
				break;
			}
		}

		return array(
			'sources' => array_map(
				static function ( string $id ): array {
					return array(
						'id'    => $id,
						'label' => Sources::label( $id ),
					);
				},
				$columns
			),
			'rows'    => $rows,
		);
	}

	/**
	 * Platforms the owner marked "In review" in the application tracker that sent no visit in the period.
	 *
	 * The plugin reads only what the owner wrote there; it cannot ask the platform.
	 *
	 * @param array<string, int> $now Visits per source in the period.
	 * @return string[] Platform names.
	 */
	private function in_review( array $now ): array {
		$names = array();

		foreach ( self::APPLICATIONS as $source => $platform ) {
			if ( isset( $now[ $source ] ) ) {
				continue;
			}

			if ( Status::IN_REVIEW === $this->plugin->applications()->get( $platform )['status'] ) {
				$names[] = Sources::label( $source );
			}
		}

		return $names;
	}
}

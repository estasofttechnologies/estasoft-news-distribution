<?php
/**
 * Notices when a rule pack changes, and counts the recent articles it changes the result for.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Checks\ArticleChecker;
use Estasoft\NewsDistribution\Checks\CheckReport;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Rule packs ship inside the plugin and are never downloaded, so a pack changes only when the plugin is
 * updated (or when an administrator chooses an older bundled version in Platform rules). This class remembers
 * the version of each pack it has seen, in the option `estasoft_nd_rule_pack_seen` (platform => version). When a
 * pack in use differs from the one remembered, it records the change and counts how many of the articles
 * published in the last 14 days now get a different check result for that platform.
 *
 * - The count looks at 50 articles at most, the newest that have a stored check result. It runs each article
 *   through the checks without storing anything and compares the findings for the platform with the stored ones.
 * - Up to 5 articles are compared at once. More go through `Core\Jobs` and the queue, 10 at a time, and the
 *   record shows "counting" until the last batch is done.
 * - The outcome is kept in the option `estasoft_nd_rule_pack_changes`. It is forgotten after 14 days, the span it
 *   speaks about, or replaced when another pack changes.
 *
 * On the first look at a site there is nothing remembered, so nothing is compared: the versions are only noted.
 * Nothing here contacts another server, and it never changes an article or its stored result.
 */
final class RulePackWatch {

	public const SEEN    = 'estasoft_nd_rule_pack_seen';
	public const CHANGES = 'estasoft_nd_rule_pack_changes';
	public const HOOK    = 'estasoft_nd_rule_pack_compare';

	/**
	 * Days of articles that are looked at, and how long a record is kept.
	 */
	public const DAYS = 14;

	/**
	 * Most articles that are looked at.
	 */
	public const MAX = 50;

	/**
	 * Up to this many articles are compared before the request ends.
	 */
	public const INLINE = 5;

	/**
	 * Articles compared in one background batch.
	 */
	public const BATCH = 10;

	private const WORK = 'estasoft_nd_rule_pack_work_';

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The version of every pack in use, by platform.
	 *
	 * @return array<string, string>
	 */
	private function versions(): array {
		$versions = array();

		foreach ( $this->plugin->rules()->all() as $platform => $pack ) {
			$versions[ (string) $platform ] = (string) ( is_array( $pack ) ? ( $pack['version'] ?? '' ) : '' );
		}

		ksort( $versions );

		return $versions;
	}

	/**
	 * Compares the packs in use with the ones last seen, and records and counts a change.
	 *
	 * @return void
	 */
	public function detect(): void {
		$now  = $this->versions();
		$seen = get_option( self::SEEN, null );

		if ( ! is_array( $seen ) ) {
			// Nothing was remembered, so there is nothing to compare with.
			update_option( self::SEEN, $now, false );

			return;
		}

		ksort( $seen );

		if ( $seen === $now ) {
			return;
		}

		$changed = array();

		foreach ( $now as $platform => $version ) {
			if ( '' !== $version && isset( $seen[ $platform ] ) && '' !== (string) $seen[ $platform ] && (string) $seen[ $platform ] !== $version ) {
				$changed[ $platform ] = array(
					'from' => (string) $seen[ $platform ],
					'to'   => $version,
				);
			}
		}

		update_option( self::SEEN, $now, false );

		if ( array() !== $changed ) {
			$this->record( $changed );
		}
	}

	/**
	 * The change that is open: which packs, how many articles differ, and whether the count is finished.
	 *
	 * @return array{at: int, state: string, job: string, checked: int, affected: int, platforms: array<string, array{from: string, to: string, affected: int}>, token: string}|null
	 */
	public function current(): ?array {
		$stored = get_option( self::CHANGES, null );

		if ( ! is_array( $stored ) || empty( $stored['platforms'] ) || ! is_array( $stored['platforms'] ) ) {
			return null;
		}

		if ( Clock::now() >= (int) ( $stored['at'] ?? 0 ) + self::DAYS * DAY_IN_SECONDS ) {
			delete_option( self::CHANGES );

			return null;
		}

		$platforms = array();

		foreach ( $stored['platforms'] as $platform => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$platforms[ (string) $platform ] = array(
				'from'     => (string) ( $row['from'] ?? '' ),
				'to'       => (string) ( $row['to'] ?? '' ),
				'affected' => (int) ( $row['affected'] ?? 0 ),
			);
		}

		if ( array() === $platforms ) {
			return null;
		}

		ksort( $platforms );

		$parts = array();

		foreach ( $platforms as $platform => $row ) {
			$parts[] = $platform . ':' . $row['to'];
		}

		return array(
			'at'        => (int) ( $stored['at'] ?? 0 ),
			'state'     => in_array( $stored['state'] ?? '', array( 'counting', 'done', 'failed' ), true ) ? (string) $stored['state'] : 'done',
			'job'       => (string) ( $stored['job'] ?? '' ),
			'checked'   => (int) ( $stored['checked'] ?? 0 ),
			'affected'  => (int) ( $stored['affected'] ?? 0 ),
			'platforms' => $platforms,
			'token'     => implode( ',', $parts ),
		);
	}

	/**
	 * Records a change and starts counting.
	 *
	 * @param array<string, array{from: string, to: string}> $changed The packs that changed.
	 * @return void
	 */
	private function record( array $changed ): void {
		$platforms = array();
		$open      = $this->current();

		// A change that is still open keeps its packs: the count covers all of them.
		foreach ( null === $open ? array() : $open['platforms'] as $platform => $row ) {
			$platforms[ $platform ] = array(
				'from'     => $row['from'],
				'to'       => $row['to'],
				'affected' => 0,
			);
		}

		foreach ( $changed as $platform => $row ) {
			$platforms[ $platform ] = array(
				'from'     => isset( $platforms[ $platform ] ) ? $platforms[ $platform ]['from'] : $row['from'],
				'to'       => $row['to'],
				'affected' => 0,
			);
		}

		$ids   = $this->articles();
		$total = count( $ids );

		$record = array(
			'at'        => Clock::now(),
			'state'     => 'counting',
			'job'       => '',
			'checked'   => 0,
			'affected'  => 0,
			'platforms' => $platforms,
		);

		if ( 0 === $total ) {
			$record['state'] = 'done';
			update_option( self::CHANGES, $record, false );

			return;
		}

		$work = array(
			'ids'      => $ids,
			'cursor'   => 0,
			'counts'   => array_fill_keys( array_keys( $platforms ), 0 ),
			'distinct' => array(),
			'checked'  => 0,
		);

		if ( $total <= self::INLINE || ! $this->plugin->queue()->is_available() ) {
			$this->compare( $work, $total );
			$this->save_result( $record, $work, false );

			return;
		}

		$job = $this->plugin->jobs()->start( 'rule-pack-compare', $total, array( 'platforms' => array_keys( $platforms ) ) );

		$record['job'] = $job;

		update_option( self::CHANGES, $record, false );
		set_transient( self::WORK . $job, $work, DAY_IN_SECONDS );
		$this->schedule( $job, 1 );
	}

	/**
	 * The articles to look at: published in the last 14 days, newest first, at most 50, with a stored result.
	 *
	 * @return int[]
	 */
	private function articles(): array {
		$ids = get_posts(
			array(
				'post_type'              => $this->plugin->settings()->post_types(),
				'post_status'            => 'publish',
				'posts_per_page'         => self::MAX,
				'fields'                 => 'ids',
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- The re-check covers every language; another plugin's filters must not narrow it.
				'lang'                   => '',
				'meta_key'               => ArticleChecker::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only articles that have a stored result can be compared.
				'date_query'             => array(
					array(
						'column'    => 'post_date_gmt',
						'after'     => Clock::to_gmt( Clock::now() - self::DAYS * DAY_IN_SECONDS ),
						'inclusive' => true,
					),
				),
			)
		);

		return array_values( array_map( 'intval', (array) $ids ) );
	}

	/**
	 * Compares up to `$limit` more articles of a piece of work.
	 *
	 * @param array<string, mixed> $work  The work: `ids`, `cursor`, `counts` (platform => articles that differ), `distinct` (id => true) and `checked`.
	 * @param int                  $limit How many articles to do.
	 * @return void
	 */
	private function compare( array &$work, int $limit ): void {
		$checker = $this->plugin->make( ArticleChecker::class );
		$slice   = array_slice( $work['ids'], (int) $work['cursor'], $limit );

		foreach ( $slice as $post_id ) {
			$stored = $checker->stored( (int) $post_id );
			$fresh  = null === $stored ? null : $checker->check( (int) $post_id, false );

			if ( null === $stored || null === $fresh ) {
				continue;
			}

			++$work['checked'];

			foreach ( array_keys( $work['counts'] ) as $platform ) {
				if ( self::differs( $stored, $fresh, (string) $platform ) ) {
					++$work['counts'][ $platform ];
					$work['distinct'][ (int) $post_id ] = true;
				}
			}
		}

		$work['cursor'] += count( $slice );
	}

	/**
	 * Whether the check result for one platform is not the same in two reports.
	 *
	 * For the pack of the checks that apply to every channel (`article-checks`) it compares every finding; for a
	 * platform it compares the findings of that platform's channel and whether the channel can take the article.
	 *
	 * @param CheckReport $before The stored report.
	 * @param CheckReport $after  The report made now.
	 * @param string      $platform Platform id.
	 * @return bool
	 */
	public static function differs( CheckReport $before, CheckReport $after, string $platform ): bool {
		if ( 'article-checks' === $platform ) {
			return self::signature( $before->findings() ) !== self::signature( $after->findings() );
		}

		$one = $before->channels()[ $platform ] ?? array();
		$two = $after->channels()[ $platform ] ?? array();

		if ( array() === $one && array() === $two ) {
			return false;
		}

		return self::signature( (array) ( $one['findings'] ?? array() ) ) !== self::signature( (array) ( $two['findings'] ?? array() ) )
			|| (bool) ( $one['eligible'] ?? true ) !== (bool) ( $two['eligible'] ?? true );
	}

	/**
	 * What a list of findings amounts to: each code with its severity, in a fixed order.
	 *
	 * @param array<int, mixed> $findings Findings.
	 * @return string[]
	 */
	private static function signature( array $findings ): array {
		$out = array();

		foreach ( $findings as $finding ) {
			if ( is_array( $finding ) ) {
				$out[] = (string) ( $finding['code'] ?? '' ) . '|' . (string) ( $finding['severity'] ?? '' );
			}
		}

		$out = array_values( array_unique( $out ) );

		sort( $out );

		return $out;
	}

	/**
	 * Writes the outcome of a finished count into the option.
	 *
	 * @param array<string, mixed> $record The open record.
	 * @param array<string, mixed> $work   The finished work.
	 * @param bool                 $failed Whether the count could not finish.
	 * @return void
	 */
	private function save_result( array $record, array $work, bool $failed ): void {
		foreach ( $record['platforms'] as $platform => $row ) {
			$record['platforms'][ $platform ]['affected'] = (int) ( $work['counts'][ $platform ] ?? 0 );
		}

		$record['state']    = $failed ? 'failed' : 'done';
		$record['checked']  = (int) $work['checked'];
		$record['affected'] = count( $work['distinct'] );

		update_option( self::CHANGES, $record, false );
	}

	/**
	 * Does one background batch. The queue calls this.
	 *
	 * @param mixed $job   Job id.
	 * @param mixed $batch Batch number, so each batch is its own queue action.
	 * @return void
	 */
	public function process( $job = '', $batch = 0 ): void {
		unset( $batch );

		$job = sanitize_key( (string) $job );

		if ( '' === $job ) {
			return;
		}

		$jobs = $this->plugin->jobs();
		$work = get_transient( self::WORK . $job );
		$open = get_option( self::CHANGES, null );

		if ( ! is_array( $work ) || ! is_array( $open ) || (string) ( $open['job'] ?? '' ) !== $job ) {
			// The work was lost, or another change replaced this one.
			delete_transient( self::WORK . $job );
			$jobs->finish( $job, false, array(), __( 'The count was lost before it finished.', 'estasoft-news-distribution' ) );

			if ( is_array( $open ) && (string) ( $open['job'] ?? '' ) === $job ) {
				$open['state'] = 'failed';
				update_option( self::CHANGES, $open, false );
			}

			return;
		}

		$total = count( $work['ids'] );

		$this->compare( $work, self::BATCH );

		if ( (int) $work['cursor'] < $total ) {
			set_transient( self::WORK . $job, $work, DAY_IN_SECONDS );
			$jobs->progress( $job, (int) $work['cursor'], $total );
			$this->schedule( $job, (int) $work['cursor'] );

			return;
		}

		delete_transient( self::WORK . $job );
		$this->save_result( $open, $work, false );
		$jobs->finish(
			$job,
			true,
			array(
				'checked'  => (int) $work['checked'],
				'affected' => count( $work['distinct'] ),
			)
		);
	}

	/**
	 * Books the next batch in the queue.
	 *
	 * @param string $job   Job id.
	 * @param int    $batch Number that keeps each queue action different.
	 * @return void
	 */
	private function schedule( string $job, int $batch ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $job, $batch ), Queue::GROUP );
		}
	}
}

<?php
/**
 * Queue and delivery counts across the sites of a network.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Network;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Retry;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * For a period (today, 7 days or 30 days) this reads each site's delivery log through `SiteLoop`
 * and keeps the answer for five minutes. The period decides Sent, Failed, Slowest delivery and
 * the channel bars. Queued is what waits now, whatever the period.
 *
 * Shape:
 *
 *     {
 *       period, since, generated, cached, slow_seconds,
 *       sites:     [ { id, name, languages, status, slow, queued, sent, failed, slowest, log_url } ],
 *       channels:  [ { id, label, sent, failed } ],   // busiest first
 *       totals:    { queued, sent, failed },
 *       attention: [ { id, name, failed, log_url } ]
 *     }
 *
 * `status` is a `Core\Status` code: healthy, attention or not_set_up. `slow` is true when the slowest
 * delivery took longer than `slow_seconds`; the row then reads "Slow" with the attention look.
 */
final class Analytics {

	public const PERIODS = array( 'today', '7d', '30d' );
	public const CACHE   = 'estasoft_nd_net_delivery_';

	/**
	 * Builds the answer.
	 *
	 * @param string $period One of PERIODS; anything else is today.
	 * @param int    $site   One site's ID, or 0 for every site.
	 * @param bool   $fresh  Read again even when a kept copy exists.
	 * @return array<string, mixed>
	 */
	public function build( string $period = 'today', int $site = 0, bool $fresh = false ): array {
		$period = in_array( $period, self::PERIODS, true ) ? $period : 'today';
		$all    = $this->all_sites( $period, $fresh );

		if ( $site > 0 ) {
			$all = $this->only( $all, $site );
		}

		return $all;
	}

	/**
	 * The start of a period.
	 *
	 * @param string $period One of PERIODS.
	 * @return int Unix time.
	 */
	public static function since( string $period ): int {
		$now = Clock::now();

		if ( '7d' === $period ) {
			return $now - 7 * DAY_IN_SECONDS;
		}

		if ( '30d' === $period ) {
			return $now - 30 * DAY_IN_SECONDS;
		}

		$start = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( Clock::zone() )->setTime( 0, 0, 0 );

		return $start->getTimestamp();
	}

	/**
	 * Every site, for one period.
	 *
	 * @param string $period One of PERIODS.
	 * @param bool   $fresh  Read again.
	 * @return array<string, mixed>
	 */
	private function all_sites( string $period, bool $fresh ): array {
		if ( ! $fresh ) {
			$kept = get_site_transient( self::CACHE . $period );

			if ( is_array( $kept ) && isset( $kept['sites'], $kept['generated'] ) ) {
				$kept['cached'] = true;

				return $kept;
			}
		}

		$summaries = ( new SiteSummaries() )->all( $fresh );
		$since     = self::since( $period );
		$visit     = SiteLoop::visit(
			array_map(
				static function ( array $row ): int {
					return (int) $row['id'];
				},
				$summaries['rows']
			),
			static function ( int $blog_id ) use ( $since ): array {
				return array(
					'by_channel' => DeliveryQueries::by_channel( $since ),
					'queued'     => DeliveryQueries::queued(),
					'slowest'    => DeliveryQueries::slowest( $since ),
				);
			}
		);

		$sites     = array();
		$channels  = array();
		$totals    = array(
			'queued' => 0,
			'sent'   => 0,
			'failed' => 0,
		);
		$attention = array();

		foreach ( $summaries['rows'] as $row ) {
			$id   = (int) $row['id'];
			$read = $visit['done'][ $id ] ?? null;
			$base = array(
				'id'        => $id,
				'name'      => (string) $row['name'],
				'languages' => $row['languages'],
				'log_url'   => (string) $row['log_url'],
			);

			if ( ! is_array( $read ) || ! $row['active'] || Status::NOT_SET_UP === $row['status'] ) {
				$sites[] = array_merge(
					$base,
					array(
						'status'  => Status::NOT_SET_UP,
						'slow'    => false,
						'queued'  => null,
						'sent'    => null,
						'failed'  => null,
						'slowest' => null,
					)
				);
				continue;
			}

			$sent   = 0;
			$failed = 0;

			foreach ( $read['by_channel'] as $channel => $counts ) {
				$sent                += $counts['sent'];
				$failed              += $counts['failed'];
				$channels[ $channel ] = array(
					'sent'   => ( $channels[ $channel ]['sent'] ?? 0 ) + $counts['sent'],
					'failed' => ( $channels[ $channel ]['failed'] ?? 0 ) + $counts['failed'],
				);
			}

			$slow   = $read['slowest'] > DeliveryQueries::SLOW_SECONDS;
			$status = ( $failed > 0 || $slow ) ? Status::ATTENTION : Status::HEALTHY;

			$totals['queued'] += (int) $read['queued'];
			$totals['sent']   += $sent;
			$totals['failed'] += $failed;

			if ( $failed > 0 ) {
				$attention[] = array(
					'id'      => $id,
					'name'    => (string) $row['name'],
					'failed'  => $failed,
					'log_url' => (string) $row['log_url'],
				);
			}

			$sites[] = array_merge(
				$base,
				array(
					'status'  => $status,
					'slow'    => $slow && 0 === $failed,
					'queued'  => (int) $read['queued'],
					'sent'    => $sent,
					'failed'  => $failed,
					'slowest' => (int) $read['slowest'],
				)
			);
		}

		$answer = array(
			'period'       => $period,
			'since'        => Clock::iso( $since ),
			'generated'    => Clock::iso( Clock::now() ),
			'cached'       => false,
			'slow_seconds' => DeliveryQueries::SLOW_SECONDS,
			'sites'        => $sites,
			'channels'     => $this->channel_rows( $channels ),
			'totals'       => $totals,
			'attention'    => $attention,
			'left'         => count( $visit['left'] ),
		);

		set_site_transient( self::CACHE . $period, $answer, SiteSummaries::TTL );

		return $answer;
	}

	/**
	 * Narrows an answer to one site.
	 *
	 * @param array<string, mixed> $all  The answer for every site.
	 * @param int                  $site Site ID.
	 * @return array<string, mixed>
	 */
	private function only( array $all, int $site ): array {
		$all['sites']     = array_values(
			array_filter(
				$all['sites'],
				static function ( array $row ) use ( $site ): bool {
					return (int) $row['id'] === $site;
				}
			)
		);
		$all['attention'] = array_values(
			array_filter(
				$all['attention'],
				static function ( array $row ) use ( $site ): bool {
					return (int) $row['id'] === $site;
				}
			)
		);
		$all['site']      = $site;

		$totals = array(
			'queued' => 0,
			'sent'   => 0,
			'failed' => 0,
		);

		foreach ( $all['sites'] as $row ) {
			$totals['queued'] += (int) $row['queued'];
			$totals['sent']   += (int) $row['sent'];
			$totals['failed'] += (int) $row['failed'];
		}

		$all['totals'] = $totals;

		if ( array() === $all['sites'] ) {
			$all['channels'] = array();

			return $all;
		}

		// The channel bars for one site need that site's own counts; read them now, bounded to one site.
		$read = SiteLoop::visit(
			array( $site ),
			static function () use ( $all ): array {
				return DeliveryQueries::by_channel( self::since( (string) $all['period'] ) );
			}
		);

		$by_channel = isset( $read['done'][ $site ] ) && is_array( $read['done'][ $site ] ) ? $read['done'][ $site ] : array();

		$all['channels'] = $this->channel_rows( $by_channel );

		return $all;
	}

	/**
	 * Channel rows, busiest first, with the channel's name.
	 *
	 * @param array<string, array{sent: int, failed: int}> $counts Channel id => counts.
	 * @return array<int, array{id: string, label: string, sent: int, failed: int}>
	 */
	private function channel_rows( array $counts ): array {
		$registry = Plugin::instance()->registry();
		$rows     = array();

		foreach ( $counts as $id => $count ) {
			$channel = $registry->get( (string) $id );
			$rows[]  = array(
				'id'     => (string) $id,
				'label'  => null === $channel ? (string) $id : $channel->label(),
				'sent'   => (int) $count['sent'],
				'failed' => (int) $count['failed'],
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return array( $b['sent'], $a['label'] ) <=> array( $a['sent'], $b['label'] );
			}
		);

		return $rows;
	}

	/**
	 * The number of attempts after which a delivery counts as failed.
	 *
	 * @return int
	 */
	public static function attempts(): int {
		return Retry::MAX_ATTEMPTS;
	}
}

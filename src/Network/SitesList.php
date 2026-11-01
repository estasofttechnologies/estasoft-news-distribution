<?php
/**
 * The Sites tab's data: the sites, their counts and the templates.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Network;

use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * One builder for the Sites screen and `GET /network/sites`, so they agree.
 *
 * Shape:
 *
 *     {
 *       generated, cached, WordPress,
 *       total      int        sites in the network (every one a pass could visit)
 *       left       int        sites the pass did not reach (a pass visits at most 200, within 20 seconds)
 *       counts     { all, attention, not_set_up }   for the whole list, before the search and filter
 *       sites      [ see SiteSummaries ]            after the search and filter
 *       templates  [ { id, name, created, source, settings } ]
 *       last_applied { id, at, name, sites } | null
 *     }
 */
final class SitesList {

	/**
	 * Builds the answer.
	 *
	 * @param string $search Text to find in a site's name or address; empty for every site.
	 * @param string $filter all, attention or not_set_up.
	 * @param bool   $fresh  Read the sites again.
	 * @return array<string, mixed>
	 */
	public static function build( string $search = '', string $filter = 'all', bool $fresh = false ): array {
		$summary   = ( new SiteSummaries() )->all( $fresh );
		$rows      = $summary['rows'];
		$templates = new Templates();
		$counts    = array(
			'all'        => count( $rows ),
			'attention'  => 0,
			'not_set_up' => 0,
		);

		foreach ( $rows as $row ) {
			if ( Status::ATTENTION === $row['status'] ) {
				++$counts['attention'];
			} elseif ( Status::NOT_SET_UP === $row['status'] ) {
				++$counts['not_set_up'];
			}
		}

		$search = trim( $search );
		$rows   = array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $search, $filter ): bool {
					if ( 'attention' === $filter && Status::ATTENTION !== $row['status'] ) {
						return false;
					}

					if ( 'not_set_up' === $filter && Status::NOT_SET_UP !== $row['status'] ) {
						return false;
					}

					return '' === $search || false !== stripos( $row['name'] . ' ' . $row['domain'], $search );
				}
			)
		);

		return array(
			'generated'    => $summary['generated'],
			'cached'       => $summary['cached'],
			'wordpress'    => $summary['wordpress'],
			'total'        => $summary['total'],
			'left'         => $summary['left'],
			'counts'       => $counts,
			'sites'        => $rows,
			'templates'    => $templates->all(),
			'last_applied' => $templates->latest_applied(),
		);
	}
}

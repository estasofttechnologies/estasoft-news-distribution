<?php
/**
 * Finds the reports that exist on this site.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports\Parts;

use Estasoft\NewsDistribution\Admin\Reports\AbstractReport;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * Reports are one class per file in `src/Admin/Reports/`. The Reports page
 * and the REST route `GET /reports/{name}` both ask here, so a report that
 * is added to the folder shows in both without any other edit.
 */
final class Reports {

	/**
	 * The groups of the report list, in the order the side list prints them.
	 *
	 * @return array<string, string> Group key => heading.
	 */
	public static function groups(): array {
		return array(
			'platforms' => __( 'Platforms', 'estasoft-news-distribution' ),
			'content'   => __( 'Content', 'estasoft-news-distribution' ),
			'audience'  => __( 'Audience', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * Every report that exists on this site, by group and position.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return array<string, AbstractReport> Report name => report.
	 */
	public static function all( Plugin $plugin ): array {
		$reports = array();

		foreach ( Discovery::classes( 'Admin/Reports' ) as $class ) {
			if ( ! class_exists( $class ) || ! is_subclass_of( $class, AbstractReport::class ) ) {
				continue;
			}

			$report = new $class( $plugin );

			if ( $report->available() ) {
				$reports[ $report->name() ] = $report;
			}
		}

		$order = array_flip( array_keys( self::groups() ) );

		uasort(
			$reports,
			static function ( AbstractReport $a, AbstractReport $b ) use ( $order ): int {
				$by_group = ( $order[ $a->group() ] ?? 9 ) <=> ( $order[ $b->group() ] ?? 9 );

				return 0 !== $by_group ? $by_group : $a->order() <=> $b->order();
			}
		);

		return $reports;
	}

	/**
	 * One report by name.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @param string $name   The report name, such as "self-test".
	 * @return AbstractReport|null
	 */
	public static function find( Plugin $plugin, string $name ): ?AbstractReport {
		$all = self::all( $plugin );

		return $all[ $name ] ?? null;
	}
}

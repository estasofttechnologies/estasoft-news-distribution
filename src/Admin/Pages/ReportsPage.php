<?php
/**
 * Reports: the list of reports and the one that is open.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Pages;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Page;
use Estasoft\NewsDistribution\Admin\Reports\AbstractReport;
use Estasoft\NewsDistribution\Admin\Reports\Parts\Reports;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\PageHeader;
use Estasoft\NewsDistribution\Admin\Ui\SideNav;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-reports&report=<name>`. The side list holds the
 * reports the person may open, found by folder in `src/Admin/Reports/`; the
 * body is what the chosen report prints. Without a `report` argument the first
 * report opens.
 */
final class ReportsPage extends Page {

	public function slug(): string {
		return 'estasoft-nd-reports';
	}

	public function title(): string {
		return __( 'Reports', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 40;
	}

	/**
	 * The reports this person may open.
	 *
	 * @return array<string, AbstractReport>
	 */
	private function reports(): array {
		$access = $this->plugin->access();

		return array_filter(
			Reports::all( $this->plugin ),
			static function ( AbstractReport $report ) use ( $access ): bool {
				return $access->can( $report->permission() );
			}
		);
	}

	/**
	 * The report being opened: the `report` argument, else the first one.
	 *
	 * @return AbstractReport|null
	 */
	private function current(): ?AbstractReport {
		$reports = $this->reports();
		$name    = Query::key( 'report' );

		if ( isset( $reports[ $name ] ) ) {
			return $reports[ $name ];
		}

		$first = reset( $reports );

		return false === $first ? null : $first;
	}

	public function handle(): void {
		$report = $this->current();

		if ( null !== $report ) {
			$report->handle();
		}
	}

	public function enqueue(): void {
		Assets::screen_style( 'reports' );

		$report = $this->current();

		if ( null !== $report ) {
			$report->enqueue();
		}
	}

	public function render(): string {
		$report = $this->current();

		if ( null === $report ) {
			return PageHeader::render( __( 'Reports', 'estasoft-news-distribution' ) )
				. Message::text( 'info', __( 'There are no reports you can open.', 'estasoft-news-distribution' ) );
		}

		$side = SideNav::render( __( 'Reports', 'estasoft-news-distribution' ), $this->nav_groups( $report->name() ) );
		$main = Html::tag( 'div', array( 'class' => 'estasoft-nd-split__main' ), $report->render() );

		return PageHeader::render( __( 'Reports', 'estasoft-news-distribution' ), $report->description(), $report->actions() )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-split estasoft-nd-reports' ),
				Html::tag( 'div', array( 'class' => 'estasoft-nd-split__side' ), $side ) . $main
			);
	}

	/**
	 * The side list: the reports the person may open, by group.
	 *
	 * @param string $current Name of the open report.
	 * @return array<int, array{label: string, items: array<int, array{label: string, url: string, current: bool}>}>
	 */
	private function nav_groups( string $current ): array {
		$groups = array();

		foreach ( Reports::groups() as $key => $label ) {
			$groups[ $key ] = array(
				'label' => $label,
				'items' => array(),
			);
		}

		foreach ( $this->reports() as $name => $report ) {
			$key = isset( $groups[ $report->group() ] ) ? $report->group() : 'content';

			$groups[ $key ]['items'][] = array(
				'label'   => $report->title(),
				'url'     => $report->url(),
				'current' => $name === $current,
			);
		}

		return array_values( $groups );
	}
}

<?php
/**
 * Dense list as a grid.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * A CSS-grid table for the delivery log, the partner list and similar lists.
 * The columns come from a class that sets `--cols`, defined in the screen's own
 * stylesheet (for example `.estasoft-nd-cols-log { --cols: ... }`), so no
 * inline styles are needed.
 */
final class Table {

	/**
	 * @param string                         $cols_class Class that sets the grid columns.
	 * @param array<int, string>             $headers    Column headings (plain). Use an empty string for an action column; it gets a hidden label.
	 * @param array<int, array<int, string>> $rows Rows of cells; each cell is trusted HTML.
	 * @param string                         $caption    What the table lists, for screen readers.
	 * @param string                         $footer     Trusted HTML for a footer row, or empty.
	 * @return string
	 */
	public static function render( string $cols_class, array $headers, array $rows, string $caption, string $footer = '' ): string {
		$head = '';

		foreach ( $headers as $header ) {
			$head .= Html::tag(
				'div',
				array( 'role' => 'columnheader' ),
				'' === $header ? Html::tag( 'span', array( 'class' => 'estasoft-nd-sr' ), esc_html__( 'Actions', 'estasoft-news-distribution' ) ) : esc_html( $header )
			);
		}

		$body = Html::tag(
			'div',
			array(
				'class' => Html::classes( 'estasoft-nd-grid-row', 'estasoft-nd-grid-row--head', $cols_class ),
				'role'  => 'row',
			),
			$head
		);

		foreach ( $rows as $cells ) {
			$row = '';

			foreach ( $cells as $cell ) {
				$row .= Html::tag( 'div', array( 'role' => 'cell' ), $cell );
			}

			$body .= Html::tag(
				'div',
				array(
					'class' => Html::classes( 'estasoft-nd-grid-row', $cols_class ),
					'role'  => 'row',
				),
				$row
			);
		}

		if ( '' !== $footer ) {
			$body .= Html::tag( 'div', array( 'class' => 'estasoft-nd-table-footer' ), $footer );
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-card estasoft-nd-card--flush estasoft-nd-scroll' ),
			Html::tag(
				'div',
				array(
					'class'      => 'estasoft-nd-table',
					'role'       => 'table',
					'aria-label' => $caption,
				),
				$body
			)
		);
	}
}

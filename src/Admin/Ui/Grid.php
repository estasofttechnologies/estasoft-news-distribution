<?php
/**
 * A table that sits inside a card.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * `Table::render()` returns a whole card, and a card never contains a card. A settings
 * card that holds a table (recent saves, the transparency check, the rule packs) uses
 * this instead. Same markup as the table, no card around it. The columns come from a class
 * that sets `--cols` in the screen's own stylesheet.
 */
final class Grid {

	/**
	 * @param string                         $cols_class Class that sets the grid columns.
	 * @param array<int, string>             $headers    Column headings (plain). An empty string is an action column with a hidden label.
	 * @param array<int, array<int, string>> $rows       Rows of cells; each cell is trusted HTML.
	 * @param string                         $caption    What the table lists, for screen readers.
	 * @return string Trusted HTML.
	 */
	public static function render( string $cols_class, array $headers, array $rows, string $caption ): string {
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

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-scroll estasoft-nd-grid-wrap' ),
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

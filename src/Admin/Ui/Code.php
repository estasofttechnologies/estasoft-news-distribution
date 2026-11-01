<?php
/**
 * Code preview.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * Shows exactly what a platform receives. Always left to right, scrolls
 * sideways inside its block, and is focusable so a keyboard can scroll it.
 */
final class Code {

	/**
	 * @param string                                     $code  The text to show.
	 * @param string                                     $label What it is, for screen readers ("Feed entry").
	 * @param array<int, array{line: int, tone: string}> $flags Lines to flag: line number from 1 and tone "danger" or "ok".
	 * @return string
	 */
	public static function block( string $code, string $label, array $flags = array() ): string {
		$tones = array();

		foreach ( $flags as $flag ) {
			$tones[ (int) $flag['line'] ] = 'ok' === $flag['tone'] ? 'ok' : 'danger';
		}

		$html  = '';
		$lines = (array) preg_split( '/\r\n|\r|\n/', $code );
		$last  = count( $lines ) - 1;

		foreach ( $lines as $index => $line ) {
			$number = $index + 1;
			$line   = esc_html( (string) $line );

			if ( isset( $tones[ $number ] ) ) {
				// A flagged line is a block, so it ends the line by itself; a newline after it would add a blank line.
				$html .= Html::tag( 'span', array( 'class' => 'estasoft-nd-code__line--' . $tones[ $number ] ), $line );
				continue;
			}

			$html .= $line . ( $index < $last ? "\n" : '' );
		}

		return Html::tag(
			'pre',
			array(
				'class'      => 'estasoft-nd-code',
				'tabindex'   => '0',
				'dir'        => 'ltr',
				'aria-label' => $label,
			),
			Html::tag( 'code', array(), $html )
		);
	}
}

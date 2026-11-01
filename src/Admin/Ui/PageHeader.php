<?php
/**
 * The heading at the top of a screen.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * One h1 per screen, one line of help under it, actions at the end.
 */
final class PageHeader {

	/**
	 * @param string $title       Heading text (plain).
	 * @param string $description One line (plain), or empty.
	 * @param string $actions     Trusted HTML for buttons, or empty.
	 * @return string
	 */
	public static function render( string $title, string $description = '', string $actions = '' ): string {
		$text = Html::tag( 'h1', array( 'class' => 'estasoft-nd-page-title' ), esc_html( $title ) );

		if ( '' !== $description ) {
			$text .= Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $description ) );
		}

		$html = Html::tag( 'div', array( 'class' => 'estasoft-nd-page-header__text' ), $text );

		if ( '' !== $actions ) {
			$html .= Html::tag( 'div', array( 'class' => 'estasoft-nd-page-header__actions' ), $actions );
		}

		return Html::tag( 'header', array( 'class' => 'estasoft-nd-page-header' ), $html );
	}
}

<?php
/**
 * Empty state.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * Explains why a list is empty and offers the one next step. It never blames
 * the person, and it is not for errors: a failed load is a message bar.
 */
final class EmptyState {

	/**
	 * @param string $title  What is missing (plain).
	 * @param string $text   Why, and what News Distribution does (plain).
	 * @param string $action Trusted HTML for the one primary button, or empty.
	 * @return string
	 */
	public static function render( string $title, string $text, string $action = '' ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-card' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-empty' ),
				Icon::svg( 'info', 'estasoft-nd-icon--lg' )
				. Html::tag( 'h2', array( 'class' => 'estasoft-nd-title' ), esc_html( $title ) )
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $text ) )
				. $action
			)
		);
	}
}

<?php
/**
 * The banner that says something changes what every screen does (staging mode).
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * Full width above the app bar. Not dismissible, because the condition is still
 * true. One banner at most; anything that affects one screen is a message bar.
 */
final class GlobalBanner {

	/**
	 * @param string $state  Bold state, two words ("Staging mode.").
	 * @param string $effect One sentence of effect.
	 * @param string $link   Trusted HTML for the link to where it is changed, or empty.
	 * @return string
	 */
	public static function render( string $state, string $effect, string $link = '' ): string {
		return Html::tag(
			'div',
			array(
				'class' => 'estasoft-nd-globalbar',
				'role'  => 'status',
			),
			Html::tag( 'span', array( 'class' => 'estasoft-nd-warn-icon' ), Icon::svg( 'warning' ) )
			. Html::tag( 'strong', array(), esc_html( $state ) )
			. Html::tag( 'span', array(), esc_html( $effect ) )
			. ( '' !== $link ? Html::tag( 'span', array( 'class' => 'estasoft-nd-end estasoft-nd-strong' ), $link ) : '' )
		);
	}
}

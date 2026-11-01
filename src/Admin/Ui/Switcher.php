<?php
/**
 * An on and off switch with its words beside it.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * A real check box drawn as a switch (`estasoft-nd-toggle`), so it works with the
 * keyboard, posts with the form and needs no script. A hidden zero before it makes
 * "off" arrive as a value. A screen script may flip the word in front of the text
 * when the switch changes: it reads `data-on` and `data-off` from the word.
 */
final class Switcher {

	/**
	 * One switch with a line of text.
	 *
	 * @param string $name    Input name, such as `estasoft_nd[enabled]`.
	 * @param string $id      Control id.
	 * @param bool   $on      Whether it is on now.
	 * @param string $text    The sentence beside it (plain). It follows the word On or Off.
	 * @param bool   $word    Whether the word On or Off goes in front of the text. Without it the text stands alone.
	 * @return string Trusted HTML.
	 */
	public static function render( string $name, string $id, bool $on, string $text, bool $word = true ): string {
		$on_word  = __( 'On.', 'estasoft-news-distribution' );
		$off_word = __( 'Off.', 'estasoft-news-distribution' );

		return Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => $name,
				'value' => '0',
			)
		) . Html::tag(
			'label',
			array(
				'class' => 'estasoft-nd-switch',
				'for'   => $id,
			),
			Html::void(
				'input',
				array(
					'type'    => 'checkbox',
					'class'   => 'estasoft-nd-toggle',
					'id'      => $id,
					'name'    => $name,
					'value'   => '1',
					'checked' => $on,
				)
			) . Html::tag(
				'span',
				array(),
				( $word ? Html::tag(
					'strong',
					array(
						'data-switch-word' => true,
						'data-on'          => $on_word,
						'data-off'         => $off_word,
					),
					esc_html( $on ? $on_word : $off_word )
				) . ' ' : '' ) . esc_html( $text )
			)
		);
	}
}

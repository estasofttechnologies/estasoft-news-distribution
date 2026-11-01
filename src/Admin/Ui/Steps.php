<?php
/**
 * Wizard pieces: the stepper and the option card.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * Used on the first-run screens and the channel setup screens.
 */
final class Steps {

	/**
	 * The stepper. Done steps show a check, the current step is outlined and bold.
	 *
	 * @param string[] $labels  Step names (plain), in order.
	 * @param int      $current Position of the current step, from 1.
	 * @param string   $label   What the list is, for screen readers.
	 * @return string
	 */
	public static function stepper( array $labels, int $current, string $label ): string {
		$items = '';

		foreach ( array_values( $labels ) as $index => $text ) {
			$number = $index + 1;

			if ( $number < $current ) {
				$items .= Html::tag(
					'li',
					array(),
					Html::tag( 'span', array( 'class' => 'estasoft-nd-step__num estasoft-nd-step__num--done' ), Icon::svg( 'check' ) ) . Html::tag( 'span', array(), esc_html( $text ) )
				);
			} elseif ( $number === $current ) {
				$items .= Html::tag(
					'li',
					array( 'aria-current' => 'step' ),
					Html::tag( 'span', array( 'class' => 'estasoft-nd-step__num estasoft-nd-step__num--now' ), esc_html( (string) $number ) ) . Html::tag( 'strong', array(), esc_html( $text ) )
				);
			} else {
				$items .= Html::tag(
					'li',
					array(),
					Html::tag( 'span', array( 'class' => 'estasoft-nd-step__num' ), esc_html( (string) $number ) ) . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $text ) )
				);
			}
		}

		return Html::tag(
			'ol',
			array(
				'class'      => 'estasoft-nd-steps',
				'aria-label' => $label,
			),
			$items
		);
	}

	/**
	 * An option card: a whole-card label around a check box.
	 *
	 * @param string $name        Input name.
	 * @param string $value       Input value.
	 * @param bool   $checked     Whether it is selected.
	 * @param string $title       Option name (plain).
	 * @param string $description What it does (plain).
	 * @param string $requirement Trusted HTML: a StatusView::inline() saying what it needs. Every option says what it needs.
	 * @return string
	 */
	public static function choice( string $name, string $value, bool $checked, string $title, string $description, string $requirement ): string {
		return Html::tag(
			'label',
			array( 'class' => Html::classes( 'estasoft-nd-choice', $checked ? 'estasoft-nd-choice--on' : '' ) ),
			Html::void(
				'input',
				array(
					'type'    => 'checkbox',
					'name'    => $name,
					'value'   => $value,
					'checked' => $checked,
				)
			)
			. Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-title' ), esc_html( $title ) )
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $description ) )
				. $requirement
			)
		);
	}
}

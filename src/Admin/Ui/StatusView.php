<?php
/**
 * Prints a status code as a pill or an inline status.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * The word, the icon and the color all come from Core\Status, so a status looks
 * the same on every screen and is translated once. Pass a code, never a word.
 */
final class StatusView {

	/**
	 * A pill, for channel health, application status and check results.
	 *
	 * @param string      $code     Status code.
	 * @param string|null $override Replacement text when the code needs detail ("Waiting 14:38"). The icon and color stay.
	 * @return string
	 */
	public static function pill( string $code, ?string $override = null ): string {
		$info = Status::describe( $code );
		$tone = 'wait' === $info['tone'] ? 'neutral' : $info['tone'];

		return Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-badge estasoft-nd-badge--' . $tone ),
			Icon::svg( $info['icon'] ) . esc_html( null === $override ? $info['word'] : $override )
		);
	}

	/**
	 * An inline status, for a result in a table or a list.
	 *
	 * @param string      $code     Status code.
	 * @param string|null $override Replacement text with detail.
	 * @return string
	 */
	public static function inline( string $code, ?string $override = null ): string {
		$info = Status::describe( $code );

		return Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-status estasoft-nd-status--' . $info['tone'] ),
			Icon::svg( $info['icon'] ) . Html::tag( 'span', array(), esc_html( null === $override ? $info['word'] : $override ) )
		);
	}
}

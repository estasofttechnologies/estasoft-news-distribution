<?php
/**
 * Message bars.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * An inline message that belongs to the page, not a WordPress admin notice.
 * Tones: info, success, warning, danger.
 */
final class Message {

	/**
	 * Icon per tone.
	 *
	 * @var array<string, string>
	 */
	private const ICONS = array(
		'info'    => 'info',
		'success' => 'check-circle',
		'warning' => 'warning',
		'danger'  => 'x-circle',
	);

	/**
	 * A message bar.
	 *
	 * @param string $tone    info, success, warning or danger.
	 * @param string $html    Trusted, already escaped HTML for the text.
	 * @param string $actions Trusted HTML for buttons or links at the end, or empty.
	 * @return string
	 */
	public static function bar( string $tone, string $html, string $actions = '' ): string {
		$tone = isset( self::ICONS[ $tone ] ) ? $tone : 'info';
		$live = in_array( $tone, array( 'warning', 'danger' ), true ) ? 'alert' : 'status';

		$inner = Icon::svg( self::ICONS[ $tone ] ) . Html::tag( 'div', array( 'class' => 'estasoft-nd-grow' ), $html );

		if ( '' !== $actions ) {
			$inner .= Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $actions );
		}

		return Html::tag(
			'div',
			array(
				'class' => 'estasoft-nd-message estasoft-nd-message--' . $tone,
				'role'  => $live,
			),
			$inner
		);
	}

	/**
	 * A message bar from plain text.
	 *
	 * @param string $tone info, success, warning or danger.
	 * @param string $text Plain text; it is escaped here.
	 * @return string
	 */
	public static function text( string $tone, string $text ): string {
		return self::bar( $tone, esc_html( $text ) );
	}
}

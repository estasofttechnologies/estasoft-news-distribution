<?php
/**
 * Confirmation dialog.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * Asks before something that cannot be undone or that affects many items. The
 * script in assets/js/admin.js opens it from a button with
 * `data-open-dialog="<id>"`, closes it on Cancel or Escape, and returns focus
 * to the button. Not for saves or errors; use a message bar.
 */
final class Dialog {

	/**
	 * @param string $id      Unique id.
	 * @param string $title   A question that names the count ("Retry 4 failed deliveries?").
	 * @param string $text    One or two sentences on what happens and what does not.
	 * @param string $confirm Trusted HTML for the primary action, a button or a link. Its label repeats the verb and the count.
	 * @return string
	 */
	public static function render( string $id, string $title, string $text, string $confirm ): string {
		$title_id = 'estasoft-nd-dlg-' . sanitize_key( $id ) . '-title';

		return Html::tag(
			'div',
			array(
				'class'       => 'estasoft-nd-scrim',
				'id'          => 'estasoft-nd-dlg-' . sanitize_key( $id ),
				'hidden'      => true,
				'data-dialog' => true,
			),
			Html::tag(
				'div',
				array(
					'class'           => 'estasoft-nd-dialog',
					'role'            => 'dialog',
					'aria-modal'      => 'true',
					'aria-labelledby' => $title_id,
				),
				Html::tag(
					'h2',
					array(
						'class' => 'estasoft-nd-title',
						'id'    => $title_id,
					),
					esc_html( $title )
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $text ) )
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-dialog__actions' ),
					Button::button( __( 'Cancel', 'estasoft-news-distribution' ), array( 'attrs' => array( 'data-close-dialog' => true ) ) ) . $confirm
				)
			)
		);
	}

	/**
	 * A button that opens a dialog.
	 *
	 * @param string               $id    Dialog id.
	 * @param string               $label Button label (plain).
	 * @param array<string, mixed> $opts  Button options.
	 * @return string
	 */
	public static function trigger( string $id, string $label, array $opts = array() ): string {
		$opts['attrs'] = array_merge( (array) ( $opts['attrs'] ?? array() ), array( 'data-open-dialog' => 'estasoft-nd-dlg-' . sanitize_key( $id ) ) );

		return Button::button( $label, $opts );
	}
}

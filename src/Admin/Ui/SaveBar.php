<?php
/**
 * The bar that saves a settings form.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * Without a script the bar shows only Save changes, and the form posts to
 * admin-post.php. With the script it stays hidden until something changes, then
 * offers Discard as well.
 */
final class SaveBar {

	/**
	 * @return string
	 */
	public static function render(): string {
		$state = Html::tag(
			'span',
			array(
				'class'              => 'estasoft-nd-savebar__state',
				'data-savebar-state' => true,
				'hidden'             => true,
			),
			Icon::svg( 'warning' ) . Html::tag( 'strong', array(), esc_html__( 'You have unsaved changes', 'estasoft-news-distribution' ) )
		);

		$actions = Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-savebar__actions' ),
			Button::button(
				__( 'Discard', 'estasoft-news-distribution' ),
				array(
					'attrs' => array(
						'data-savebar-discard' => true,
						'hidden'               => true,
					),
				)
			)
			. Button::submit( __( 'Save changes', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
		);

		return Html::tag(
			'div',
			array(
				'class'        => 'estasoft-nd-savebar',
				'role'         => 'region',
				'aria-label'   => __( 'Unsaved changes', 'estasoft-news-distribution' ),
				'data-savebar' => true,
			),
			$state . $actions
		);
	}
}

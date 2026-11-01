<?php
/**
 * Small forms for the operations screens.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Operations;

use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;

defined( 'ABSPATH' ) || exit;

/**
 * Connections, Cache purge, Alerts and Backup and uninstall each have buttons that
 * do one thing: check a connection, send a test, download a file, restore a point.
 * Each button is a plain form post to `admin-post.php`, so it works without
 * JavaScript. `OperationsModule` answers the posts.
 */
final class Forms {

	/**
	 * A form that posts to admin-post.php with a nonce.
	 *
	 * @param string               $action       The `admin_post_` action name.
	 * @param string               $nonce_action The nonce action the handler checks.
	 * @param string               $body         Trusted HTML: the controls and buttons.
	 * @param array<string, mixed> $attrs        More form attributes (id, class, enctype, data-*).
	 * @param array<string, mixed> $hidden       Hidden fields, name => value.
	 * @return string
	 */
	public static function post( string $action, string $nonce_action, string $body, array $attrs = array(), array $hidden = array() ): string {
		$fields = Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => 'action',
				'value' => $action,
			)
		);

		foreach ( $hidden as $name => $value ) {
			$fields .= Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => (string) $name,
					'value' => (string) $value,
				)
			);
		}

		// Built by hand: wp_nonce_field() gives every copy the same id, and a page holds several of these forms.
		$fields .= Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => '_wpnonce',
				'value' => wp_create_nonce( $nonce_action ),
			)
		);

		return Html::tag(
			'form',
			array_merge(
				array(
					'method' => 'post',
					'action' => admin_url( 'admin-post.php' ),
				),
				$attrs
			),
			$fields . $body
		);
	}

	/**
	 * The nonce action of the Connections buttons. It names the buttons, not the engine: the engine is read after the nonce passes.
	 *
	 * @return string
	 */
	public static function connection_nonce(): string {
		return 'estasoft_nd_connection';
	}

	/**
	 * The nonce action of the Backup and uninstall buttons.
	 *
	 * @return string
	 */
	public static function data_nonce(): string {
		return 'estasoft_nd_data';
	}

	/**
	 * A line of text next to a button that says how the last action went.
	 *
	 * @param string $id   Element id, so a script can write the answer there.
	 * @param string $html Trusted HTML for the content, or an empty string.
	 * @return string
	 */
	public static function result( string $id, string $html = '' ): string {
		return Html::tag(
			'span',
			array(
				'class'       => 'estasoft-nd-ops-result',
				'id'          => $id,
				'role'        => 'status',
				'aria-live'   => 'polite',
				'data-result' => true,
			),
			$html
		);
	}

	/**
	 * A "What it cannot do" entry for the `notes` of a layout, for a screen whose board
	 * prints it before another note card. (`layout()['cannot']` always prints last.)
	 *
	 * @param string[] $items One plain sentence per limit.
	 * @return array{title: string, html: string}
	 */
	public static function cannot_note( array $items ): array {
		$list = '';

		foreach ( $items as $item ) {
			$list .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule' ), Icon::svg( 'minus-circle' ) . Html::tag( 'span', array(), esc_html( $item ) ) );
		}

		return array(
			'title' => __( 'What it cannot do', 'estasoft-news-distribution' ),
			'html'  => Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $list ),
		);
	}

	/**
	 * A form row with a label on one side and its controls on the other, as the settings forms print them.
	 *
	 * @param string $id      Unique id for the label.
	 * @param string $label   Label text (plain).
	 * @param string $control Trusted HTML for the controls.
	 * @return string
	 */
	public static function row( string $id, string $label, string $control ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-form-row' ),
			Html::tag(
				'div',
				array(
					'class' => 'estasoft-nd-form-label',
					'id'    => $id . '-label',
				),
				esc_html( $label )
			)
			. Html::tag(
				'div',
				array(
					'class'           => 'estasoft-nd-form-control',
					'role'            => 'group',
					'aria-labelledby' => $id . '-label',
				),
				$control
			)
		);
	}
}

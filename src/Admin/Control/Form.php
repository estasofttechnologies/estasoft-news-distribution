<?php
/**
 * The small pieces the Release plan, Takedown and Assign tabs share: a form that posts back to its tab, and a labelled row.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Control;

use Estasoft\NewsDistribution\Admin\Ui\Html;

defined( 'ABSPATH' ) || exit;

/**
 * Every form on these tabs works without a script: it posts to the tab's own address, the tab's `handle()` checks
 * the nonce and the person's access, does the work, keeps a message with `Flash` and redirects back.
 *
 * A form names what it does in a hidden `estasoft_nd_do` field and carries the article's nonce.
 */
final class Form {

	/**
	 * Name of the hidden field that says which form was posted.
	 */
	public const ACTION = 'estasoft_nd_do';

	/**
	 * Name under which a form sends its fields: `estasoft_nd_ctl[mode]`.
	 */
	public const FIELDS = 'estasoft_nd_ctl';

	/**
	 * The nonce action of the forms of one article. Every form on the article's tabs carries it: the form's name is
	 * read only after the nonce has passed.
	 *
	 * @param int $post_id Article ID.
	 * @return string
	 */
	public static function nonce_action( int $post_id ): string {
		return 'estasoft_nd_control_' . $post_id;
	}

	/**
	 * The nonce action that `verify()` last accepted. The readers below check it again before they read a field.
	 *
	 * @var string
	 */
	private static string $verified = '';

	/**
	 * Whether a form of these tabs was posted for this article. A posted form must carry the article's nonce: the
	 * request ends with the WordPress message when it does not, before any field is read.
	 *
	 * @param int $post_id Article ID.
	 * @return bool
	 */
	public static function submitted( int $post_id ): bool {
		if ( $post_id < 1 || ! isset( $_POST[ self::ACTION ] ) ) {
			return false;
		}

		check_admin_referer( self::nonce_action( $post_id ), '_wpnonce' );

		self::$verified = self::nonce_action( $post_id );

		return true;
	}

	/**
	 * Which form was posted to this tab, or an empty string. Call it after `verify()`.
	 *
	 * @return string
	 */
	public static function posted(): string {
		check_admin_referer( self::$verified, '_wpnonce' );

		return isset( $_POST[ self::ACTION ] ) && is_scalar( $_POST[ self::ACTION ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::ACTION ] ) ) : '';
	}

	/**
	 * One field of the posted form, as text. Call it after `verify()`.
	 *
	 * @param string $name Field name.
	 * @return string Empty when it was not sent.
	 */
	public static function text( string $name ): string {
		check_admin_referer( self::$verified, '_wpnonce' );

		return isset( $_POST[ self::FIELDS ][ $name ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::FIELDS ][ $name ] ) ) : '';
	}

	/**
	 * A field of the posted form that holds a list, such as the times of a custom plan. Call it after `verify()`.
	 *
	 * @param string $name Field name.
	 * @return array<string, mixed>
	 */
	public static function items( string $name ): array {
		check_admin_referer( self::$verified, '_wpnonce' );

		if ( ! isset( $_POST[ self::FIELDS ][ $name ] ) || ! is_array( $_POST[ self::FIELDS ][ $name ] ) ) {
			return array();
		}

		return array_map( 'sanitize_text_field', wp_unslash( $_POST[ self::FIELDS ][ $name ] ) );
	}

	/**
	 * Whether a checkbox of the posted form was ticked.
	 *
	 * @param string $name Field name.
	 * @return bool
	 */
	public static function checked( string $name ): bool {
		$value = self::text( $name );

		return '' !== $value && '0' !== $value;
	}

	/**
	 * The name of a control inside the form.
	 *
	 * @param string ...$path Field name, then deeper keys.
	 * @return string
	 */
	public static function name( string ...$path ): string {
		return self::FIELDS . '[' . implode( '][', $path ) . ']';
	}

	/**
	 * A form that posts to a tab.
	 *
	 * @param string               $url     The tab's address.
	 * @param string               $what      What the form does.
	 * @param int                  $post_id Article ID.
	 * @param string               $inner   Trusted HTML for the controls.
	 * @param array<string, mixed> $attrs   More attributes for the form element.
	 * @return string
	 */
	public static function open( string $url, string $what, int $post_id, string $inner, array $attrs = array() ): string {
		return Html::tag(
			'form',
			array_merge(
				array(
					'method'     => 'post',
					'action'     => $url,
					'class'      => 'estasoft-nd-ctl-form',
					'novalidate' => true,
				),
				$attrs
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => self::ACTION,
					'value' => $what,
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'post',
					'value' => (string) $post_id,
				)
			)
			. wp_nonce_field( self::nonce_action( $post_id ), '_wpnonce', false, false )
			. $inner
		);
	}

	/**
	 * A row with a label on one side and its control on the other, and the help under the control.
	 *
	 * @param string               $id      The control's id.
	 * @param string               $label   Label text (plain).
	 * @param string               $control Trusted HTML.
	 * @param string               $help    Help text (plain), or empty.
	 * @param array<string, mixed> $attrs   More attributes for the row.
	 * @return string
	 */
	public static function row( string $id, string $label, string $control, string $help = '', array $attrs = array() ): string {
		return Html::tag(
			'div',
			array_merge( array( 'class' => 'estasoft-nd-form-row' ), $attrs ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-form-label',
					'for'   => $id,
				),
				esc_html( $label )
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-form-control' ),
				$control . ( '' === $help ? '' : Html::tag(
					'p',
					array(
						'class' => 'estasoft-nd-muted estasoft-nd-small',
						'id'    => $id . '-help',
					),
					esc_html( $help )
				) )
			)
		);
	}

	/**
	 * A select.
	 *
	 * @param string                    $id      Control id.
	 * @param string                    $name    Control name.
	 * @param array<int|string, string> $options Value => label.
	 * @param int|string                $current The selected value.
	 * @param array<string, mixed>      $attrs   More attributes.
	 * @return string
	 */
	public static function select( string $id, string $name, array $options, $current, array $attrs = array() ): string {
		$html = '';

		foreach ( $options as $value => $label ) {
			$html .= Html::tag(
				'option',
				array(
					'value'    => (string) $value,
					'selected' => (string) $value === (string) $current,
				),
				esc_html( $label )
			);
		}

		return Html::tag(
			'select',
			array_merge(
				array(
					'id'    => $id,
					'name'  => $name,
					'class' => 'estasoft-nd-field',
				),
				$attrs
			),
			$html
		);
	}

	/**
	 * A line of small muted text.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	public static function note( string $text ): string {
		return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $text ) );
	}
}

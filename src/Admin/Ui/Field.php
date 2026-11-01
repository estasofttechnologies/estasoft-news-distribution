<?php
/**
 * Form controls.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Settings\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Turns one field spec and its current value into controls. Every control is
 * named `estasoft_nd[<field>]` so one POST (or one script) can save a section.
 *
 * Spec keys read here are documented on Core\Settings\Section.
 */
final class Field {

	public const INPUT = 'estasoft_nd';

	/**
	 * What the form posted for a list of fields, cleaned as it is read.
	 *
	 * Only the fields in the list are read. Text, whole numbers, choices and check boxes are cleaned the way the
	 * settings validator will clean them again; a list, a map or a group is cleaned value by value. A web address or
	 * a secret is only stripped of control characters here, because the usual text cleaning would remove the
	 * percent-encoded parts of an address and could change a token: the validator checks and cleans those by type.
	 * A secret also reads its `<field>__remove` flag.
	 *
	 * The nonce is checked here, before the form is read, so a form can not be read without it: the request ends with
	 * the WordPress message when the nonce is missing or wrong.
	 *
	 * @param array<string, array<string, mixed>> $fields       Field specs.
	 * @param string                              $nonce_action The action the form's nonce was made for.
	 * @return array<string, mixed> Unslashed, cleaned values by field name.
	 */
	public static function posted( array $fields, string $nonce_action ): array {
		check_admin_referer( $nonce_action );

		$out = array();

		if ( ! isset( $_POST[ self::INPUT ] ) || ! is_array( $_POST[ self::INPUT ] ) ) {
			return $out;
		}

		foreach ( $fields as $name => $spec ) {
			$type   = (string) ( $spec['type'] ?? 'string' );
			$format = (string) ( $spec['format'] ?? '' );
			$keys   = 'secret' === $type ? array( (string) $name, $name . '__remove' ) : array( (string) $name );

			foreach ( $keys as $key ) {
				if ( ! isset( $_POST[ self::INPUT ][ $key ] ) ) {
					continue;
				}

				if ( is_array( $_POST[ self::INPUT ][ $key ] ) ) {
					$out[ $key ] = map_deep( wp_unslash( $_POST[ self::INPUT ][ $key ] ), 'sanitize_textarea_field' );
				} elseif ( 'secret' === $type || 'url' === $type || 'url' === $format ) {
					$out[ $key ] = filter_var( wp_unslash( $_POST[ self::INPUT ][ $key ] ), FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW );
				} elseif ( 'textarea' === $format ) {
					$out[ $key ] = sanitize_textarea_field( wp_unslash( $_POST[ self::INPUT ][ $key ] ) );
				} else {
					$out[ $key ] = sanitize_text_field( wp_unslash( $_POST[ self::INPUT ][ $key ] ) );
				}
			}
		}

		return $out;
	}

	/**
	 * The id of a control.
	 *
	 * @param string $section Section name.
	 * @param string $field   Field name.
	 * @return string
	 */
	public static function id( string $section, string $field ): string {
		return 'estasoft-nd-f-' . sanitize_key( $section ) . '-' . sanitize_key( $field );
	}

	/**
	 * The input name for a field path.
	 *
	 * @param string ...$path Field name, then deeper keys.
	 * @return string
	 */
	public static function name( string ...$path ): string {
		$name = self::INPUT;

		foreach ( $path as $part ) {
			$name .= '[' . $part . ']';
		}

		return $name;
	}

	/**
	 * Which control a spec uses when it does not say.
	 *
	 * @param array<string, mixed> $spec Field spec.
	 * @return string
	 */
	public static function control_of( array $spec ): string {
		if ( isset( $spec['control'] ) ) {
			return (string) $spec['control'];
		}

		switch ( (string) ( $spec['type'] ?? 'string' ) ) {
			case 'bool':
				return 'checkbox';
			case 'int':
				return 'number';
			case 'enum':
				return 'select';
			case 'secret':
				return 'secret';
			case 'list':
				return 'checkboxes';
			case 'map':
				return 'lang_map';
			default:
				$format = (string) ( $spec['format'] ?? 'text' );
				return in_array( $format, array( 'url', 'email', 'textarea', 'color' ), true ) ? $format : 'text';
		}
	}

	/**
	 * Whether the control prints its own label (a check box carries its title).
	 *
	 * @param array<string, mixed> $spec Field spec.
	 * @return bool
	 */
	public static function is_self_labelled( array $spec ): bool {
		return 'checkbox' === self::control_of( $spec );
	}

	/**
	 * The options of a field as value => array( label, help ).
	 *
	 * @param array<string, mixed> $spec Field spec.
	 * @return array<string, array{label: string, help: string}>
	 */
	public static function options( array $spec ): array {
		$raw = $spec['options'] ?? ( $spec['item']['options'] ?? array() );

		if ( is_callable( $raw ) ) {
			$raw = call_user_func( $raw );
		}

		$out = array();

		foreach ( (array) $raw as $value => $entry ) {
			if ( is_array( $entry ) ) {
				$out[ (string) $value ] = array(
					'label' => (string) ( $entry['label'] ?? $value ),
					'help'  => (string) ( $entry['help'] ?? '' ),
				);
			} else {
				$out[ (string) $value ] = array(
					'label' => (string) $entry,
					'help'  => '',
				);
			}
		}

		return $out;
	}

	/**
	 * Prints the controls of one field.
	 *
	 * @param string               $section Section name.
	 * @param string               $field   Field name.
	 * @param array<string, mixed> $spec    Field spec.
	 * @param mixed                $value   Current value.
	 * @param string               $error   Error message for this field, or an empty string.
	 * @return string Escaped HTML.
	 */
	public static function render( string $section, string $field, array $spec, $value, string $error = '' ): string {
		$id       = self::id( $section, $field );
		$control  = self::control_of( $spec );
		$help_id  = '' !== (string) ( $spec['help'] ?? '' ) ? $id . '-help' : '';
		$err_id   = '' !== $error ? $id . '-error' : '';
		$describe = trim( $help_id . ' ' . $err_id );

		switch ( $control ) {
			case 'radio':
				$html = self::radios( $id, $field, $spec, (string) $value );
				break;
			case 'select':
				$html = self::select( $id, $field, $spec, (string) $value, $describe, '' !== $error );
				break;
			case 'checkbox':
				$html = self::checkbox( $id, $field, $spec, (bool) $value );
				break;
			case 'number':
				$html = self::number( $id, $field, $spec, $value, $describe, '' !== $error );
				break;
			case 'checkboxes':
				$html = self::checkboxes( $id, $field, $spec, (array) $value );
				break;
			case 'lang_map':
				$html = self::lang_map( $id, $field, $spec, (array) $value );
				break;
			case 'secret':
				$html = self::secret( $id, $field, $spec, (string) $value, $describe );
				break;
			case 'textarea':
				$html = self::textarea( $id, $field, $spec, (string) $value, $describe, '' !== $error );
				break;
			default:
				$html = self::text( $id, $field, $spec, is_scalar( $value ) ? (string) $value : '', $control, $describe, '' !== $error );
		}

		if ( '' !== $error ) {
			$html .= Html::tag(
				'p',
				array(
					'class'            => 'estasoft-nd-field-error',
					'id'               => $err_id,
					'role'             => 'alert',
					'data-field-error' => $field,
				),
				Icon::svg( 'x-circle' ) . Html::tag( 'span', array(), esc_html( $error ) )
			);
		} else {
			// An empty slot the script fills when the server rejects a value.
			$html .= Html::tag(
				'p',
				array(
					'class'            => 'estasoft-nd-field-error',
					'hidden'           => true,
					'role'             => 'alert',
					'data-field-error' => $field,
				),
				''
			);
		}

		$help = (string) ( $spec['help'] ?? '' );
		if ( '' !== $help && 'checkbox' !== $control ) {
			$html .= Html::tag(
				'p',
				array(
					'class' => 'estasoft-nd-muted estasoft-nd-small',
					'id'    => $help_id,
				),
				esc_html( $help )
			);
		}

		return $html;
	}

	/**
	 * A single-line input.
	 *
	 * @param string               $id       Control id.
	 * @param string               $field    Field name.
	 * @param array<string, mixed> $spec     Field spec.
	 * @param string               $value    Current value.
	 * @param string               $control  text, url or email.
	 * @param string               $describe Ids of the help and error text.
	 * @param bool                 $invalid  Whether the value was rejected.
	 * @return string
	 */
	private static function text( string $id, string $field, array $spec, string $value, string $control, string $describe, bool $invalid ): string {
		$type = in_array( $control, array( 'url', 'email', 'color' ), true ) ? ( 'color' === $control ? 'text' : $control ) : 'text';

		return Html::void(
			'input',
			array(
				'class'            => Html::classes( 'estasoft-nd-field', Html::width_class( (int) ( $spec['width'] ?? 0 ) ) ),
				'type'             => $type,
				'id'               => $id,
				'name'             => self::name( $field ),
				'value'            => $value,
				'maxlength'        => isset( $spec['max'] ) ? (int) $spec['max'] : null,
				'placeholder'      => isset( $spec['placeholder'] ) ? (string) $spec['placeholder'] : null,
				'dir'              => isset( $spec['dir'] ) ? (string) $spec['dir'] : null,
				'autocomplete'     => isset( $spec['autocomplete'] ) ? (string) $spec['autocomplete'] : 'off',
				'aria-describedby' => '' !== $describe ? $describe : null,
				'aria-invalid'     => $invalid ? 'true' : null,
			)
		);
	}

	/**
	 * A multi-line input.
	 *
	 * @param string               $id       Control id.
	 * @param string               $field    Field name.
	 * @param array<string, mixed> $spec     Field spec.
	 * @param string               $value    Current value.
	 * @param string               $describe Ids of the help and error text.
	 * @param bool                 $invalid  Whether the value was rejected.
	 * @return string
	 */
	private static function textarea( string $id, string $field, array $spec, string $value, string $describe, bool $invalid ): string {
		return Html::tag(
			'textarea',
			array(
				'class'            => Html::classes( 'estasoft-nd-field', Html::width_class( (int) ( $spec['width'] ?? 480 ) ) ),
				'id'               => $id,
				'name'             => self::name( $field ),
				'rows'             => (int) ( $spec['rows'] ?? 4 ),
				'maxlength'        => isset( $spec['max'] ) ? (int) $spec['max'] : null,
				'aria-describedby' => '' !== $describe ? $describe : null,
				'aria-invalid'     => $invalid ? 'true' : null,
			),
			esc_textarea( $value )
		);
	}

	/**
	 * A number input with its unit.
	 *
	 * @param string               $id       Control id.
	 * @param string               $field    Field name.
	 * @param array<string, mixed> $spec     Field spec.
	 * @param mixed                $value    Current value.
	 * @param string               $describe Ids of the help and error text.
	 * @param bool                 $invalid  Whether the value was rejected.
	 * @return string
	 */
	private static function number( string $id, string $field, array $spec, $value, string $describe, bool $invalid ): string {
		$input = Html::void(
			'input',
			array(
				'class'            => Html::classes( 'estasoft-nd-field', Html::width_class( (int) ( $spec['width'] ?? 90 ) ) ),
				'type'             => 'number',
				'id'               => $id,
				'name'             => self::name( $field ),
				'value'            => is_scalar( $value ) ? (string) $value : '',
				'min'              => isset( $spec['min'] ) ? (int) $spec['min'] : null,
				'max'              => isset( $spec['max'] ) ? (int) $spec['max'] : null,
				'step'             => 1,
				'inputmode'        => 'numeric',
				'aria-describedby' => '' !== $describe ? $describe : null,
				'aria-invalid'     => $invalid ? 'true' : null,
			)
		);

		$unit = (string) ( $spec['unit'] ?? '' );

		if ( '' === $unit ) {
			return $input;
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $input . Html::tag( 'span', array(), esc_html( $unit ) ) );
	}

	/**
	 * A drop-down.
	 *
	 * @param string               $id       Control id.
	 * @param string               $field    Field name.
	 * @param array<string, mixed> $spec     Field spec.
	 * @param string               $value    Current value.
	 * @param string               $describe Ids of the help and error text.
	 * @param bool                 $invalid  Whether the value was rejected.
	 * @return string
	 */
	private static function select( string $id, string $field, array $spec, string $value, string $describe, bool $invalid ): string {
		$items = '';

		foreach ( self::options( $spec ) as $key => $option ) {
			$items .= Html::tag(
				'option',
				array(
					'value'    => $key,
					'selected' => (string) $key === $value,
				),
				esc_html( $option['label'] )
			);
		}

		return Html::tag(
			'select',
			array(
				'class'            => Html::classes( 'estasoft-nd-field', Html::width_class( (int) ( $spec['width'] ?? 0 ) ) ),
				'id'               => $id,
				'name'             => self::name( $field ),
				'aria-describedby' => '' !== $describe ? $describe : null,
				'aria-invalid'     => $invalid ? 'true' : null,
			),
			$items
		);
	}

	/**
	 * A group of radio buttons, each with its own line of help.
	 *
	 * @param string               $id    Control id.
	 * @param string               $field Field name.
	 * @param array<string, mixed> $spec  Field spec.
	 * @param string               $value Current value.
	 * @return string
	 */
	private static function radios( string $id, string $field, array $spec, string $value ): string {
		$html = '';

		foreach ( self::options( $spec ) as $key => $option ) {
			$text = Html::tag( 'strong', array(), esc_html( $option['label'] ) );

			if ( '' !== $option['help'] ) {
				$text .= '<br>' . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $option['help'] ) );
			}

			$html .= Html::tag(
				'label',
				array( 'class' => 'estasoft-nd-check estasoft-nd-check--top' ),
				Html::void(
					'input',
					array(
						'type'    => 'radio',
						'name'    => self::name( $field ),
						'value'   => $key,
						'checked' => (string) $key === $value,
					)
				) . Html::tag( 'span', array(), $text )
			);
		}

		return $html;
	}

	/**
	 * One check box with its title and help beside it, as the design shows.
	 *
	 * @param string               $id    Control id.
	 * @param string               $field Field name.
	 * @param array<string, mixed> $spec  Field spec.
	 * @param bool                 $value Current value.
	 * @return string
	 */
	private static function checkbox( string $id, string $field, array $spec, bool $value ): string {
		$title = (string) ( $spec['title'] ?? $spec['label'] ?? $field );
		$text  = Html::tag( 'strong', array(), esc_html( $title ) );
		$help  = (string) ( $spec['help'] ?? '' );

		if ( '' !== $help ) {
			$text .= '<br>' . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $help ) );
		}

		// The hidden zero makes an unchecked box arrive as off instead of missing.
		return Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => self::name( $field ),
				'value' => '0',
			)
		)
			. Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-check estasoft-nd-check--top',
					'for'   => $id,
				),
				Html::void(
					'input',
					array(
						'type'    => 'checkbox',
						'id'      => $id,
						'name'    => self::name( $field ),
						'value'   => '1',
						'checked' => $value,
					)
				) . Html::tag( 'span', array(), $text )
			);
	}

	/**
	 * A list of check boxes.
	 *
	 * @param string               $id    Control id.
	 * @param string               $field Field name.
	 * @param array<string, mixed> $spec  Field spec.
	 * @param array<int, mixed>    $value Chosen values.
	 * @return string
	 */
	private static function checkboxes( string $id, string $field, array $spec, array $value ): string {
		$chosen  = array_map( 'strval', $value );
		$options = self::options( $spec );

		// An empty marker so that clearing every box still saves an empty list.
		$html = Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => self::name( $field ),
				'value' => '',
			)
		);

		if ( array() === $options ) {
			return $html . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Nothing to choose from yet.', 'estasoft-news-distribution' ) );
		}

		foreach ( $options as $key => $option ) {
			$text = '' === $option['help']
				? esc_html( $option['label'] )
				: Html::tag( 'span', array(), Html::tag( 'strong', array(), esc_html( $option['label'] ) ) . '<br>' . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $option['help'] ) ) );

			$html .= Html::tag(
				'label',
				array( 'class' => Html::classes( 'estasoft-nd-check', '' !== $option['help'] ? 'estasoft-nd-check--top' : '' ) ),
				Html::void(
					'input',
					array(
						'type'    => 'checkbox',
						'name'    => self::name( $field ) . '[]',
						'value'   => $key,
						'checked' => in_array( (string) $key, $chosen, true ),
					)
				) . $text
			);
		}

		return $html;
	}

	/**
	 * One text input per language, tagged with the language code.
	 *
	 * @param string               $id    Control id.
	 * @param string               $field Field name.
	 * @param array<string, mixed> $spec  Field spec.
	 * @param array<string, mixed> $value Current value, language code => text.
	 * @return string
	 */
	private static function lang_map( string $id, string $field, array $spec, array $value ): string {
		$codes = array();

		foreach ( self::language_codes() as $code ) {
			$codes[] = $code;
		}

		// A value saved for a language that is no longer published stays visible, so it is not lost silently.
		foreach ( array_keys( $value ) as $code ) {
			if ( ! in_array( (string) $code, $codes, true ) ) {
				$codes[] = (string) $code;
			}
		}

		$html = Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => self::name( $field ),
				'value' => '',
			)
		);

		foreach ( $codes as $code ) {
			$input_id = $id . '-' . sanitize_key( $code );
			$html    .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-lang-field' ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-lang' ), esc_html( Catalog::tag( $code ) ) )
				. Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-sr',
						'for'   => $input_id,
					),
					esc_html(
						sprintf(
							/* translators: %s: a language name such as English. */
							__( '%s publication name', 'estasoft-news-distribution' ),
							Catalog::name( $code )
						)
					)
				)
				. Html::void(
					'input',
					array(
						'class'        => Html::classes( 'estasoft-nd-field', Html::width_class( (int) ( $spec['width'] ?? 280 ) ) ),
						'type'         => 'text',
						'id'           => $input_id,
						'name'         => self::name( $field, $code ),
						'value'        => isset( $value[ $code ] ) && is_scalar( $value[ $code ] ) ? (string) $value[ $code ] : '',
						'dir'          => Catalog::direction( $code ),
						'maxlength'    => isset( $spec['item']['max'] ) ? (int) $spec['item']['max'] : null,
						'autocomplete' => 'off',
					)
				)
			);
		}

		return $html;
	}

	/**
	 * Language codes the site publishes in, from the translation plugin or the site language.
	 *
	 * @return string[]
	 */
	public static function language_codes(): array {
		return array_values( array_unique( array_map( 'strval', \Estasoft\NewsDistribution\Plugin::instance()->languages()->languages() ) ) );
	}

	/**
	 * A write-only field for an API key or token. The saved value is never shown.
	 *
	 * @param string               $id       Control id.
	 * @param string               $field    Field name.
	 * @param array<string, mixed> $spec     Field spec.
	 * @param string               $value    Mask of the saved value, or an empty string.
	 * @param string               $describe Ids of the help text.
	 * @return string
	 */
	private static function secret( string $id, string $field, array $spec, string $value, string $describe ): string {
		$is_set = '' !== $value;
		$html   = '';

		if ( $is_set ) {
			$html .= Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
				Icon::svg( 'lock' ) . Html::tag( 'span', array( 'class' => 'estasoft-nd-mono' ), esc_html( $value ) )
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Saved. It is never shown again.', 'estasoft-news-distribution' ) )
			);
		}

		$html .= Html::void(
			'input',
			array(
				'class'            => Html::classes( 'estasoft-nd-field', Html::width_class( (int) ( $spec['width'] ?? 360 ) ) ),
				'type'             => 'password',
				'id'               => $id,
				'name'             => self::name( $field ),
				'value'            => '',
				'autocomplete'     => 'new-password',
				'placeholder'      => $is_set ? __( 'Type a new value to replace it', 'estasoft-news-distribution' ) : null,
				'aria-describedby' => '' !== $describe ? $describe : null,
			)
		);

		if ( $is_set ) {
			$html .= Html::tag(
				'label',
				array( 'class' => 'estasoft-nd-check' ),
				Html::void(
					'input',
					array(
						'type'  => 'checkbox',
						'name'  => self::name( $field . '__remove' ),
						'value' => '1',
					)
				)
				. esc_html__( 'Remove the saved value', 'estasoft-news-distribution' )
			);
		}

		return $html;
	}

	/**
	 * The default value of a field, for tests and for screens that show a blank form.
	 *
	 * @param array<string, mixed> $spec Field spec.
	 * @return mixed
	 */
	public static function default_of( array $spec ) {
		return Validator::default_of( $spec );
	}
}

<?php
/**
 * Rows of form controls built from field specs.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * One label and its control per row, with the error under the control and an
 * optional "show only while another field has this value" condition. A settings
 * section and a channel page both build their forms with it, so every form in
 * the plugin looks and behaves the same.
 *
 * A row is `array( 'field' => 'name' )` or `array( 'html' => callable, 'label' => '...' )`,
 * with optional `nested` (`fields`, `note`, `show_if`). See Core\Settings\Section::layout().
 */
final class FieldRows {

	private string $scope;

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private array $fields;

	/**
	 * Values as the screen shows them.
	 *
	 * @var array<string, mixed>
	 */
	private array $values;

	/**
	 * Error messages by field path.
	 *
	 * @var array<string, string>
	 */
	private array $errors;

	/**
	 * @param string                              $scope  Section name or channel scope; makes control ids unique.
	 * @param array<string, array<string, mixed>> $fields Field specs.
	 * @param array<string, mixed>                $values Values to show.
	 * @param array<string, string>               $errors Rejected fields and their messages.
	 */
	public function __construct( string $scope, array $fields, array $values, array $errors = array() ) {
		$this->scope  = $scope;
		$this->fields = $fields;
		$this->values = $values;
		$this->errors = $errors;
	}

	/**
	 * One row: a field, or a computed row.
	 *
	 * @param array<string, mixed> $row Row from the layout.
	 * @return string
	 */
	public function row( array $row ): string {
		$fields = $this->fields;

		if ( isset( $row['html'] ) ) {
			$callable = $row['html'];
			$body     = is_callable( $callable ) ? (string) call_user_func( $callable, $this->values, $this->errors ) : '';

			if ( ! empty( $row['wide'] ) ) {
				// A row that spans the card, with no label column (a list of switches, for example).
				return Html::tag( 'div', array( 'class' => 'estasoft-nd-form-row estasoft-nd-form-row--wide' ), $body );
			}

			return $this->form_row( 'computed-' . md5( (string) ( $row['label'] ?? '' ) ), (string) ( $row['label'] ?? '' ), $body, true, null );
		}

		$name = (string) ( $row['field'] ?? '' );

		if ( '' === $name || ! isset( $fields[ $name ] ) ) {
			return '';
		}

		$spec    = $fields[ $name ];
		$id      = Field::id( $this->scope, $name );
		$control = Field::render( $this->scope, $name, $spec, $this->values[ $name ] ?? Field::default_of( $spec ), $this->error_for( $name ) );

		if ( isset( $row['nested'] ) ) {
			$control .= $this->nested( (array) $row['nested'] );
		}

		$grouped = ! in_array( Field::control_of( $spec ), array( 'text', 'url', 'email', 'number', 'select', 'textarea', 'secret', 'color' ), true );
		$label   = (string) ( $spec['row_label'] ?? $spec['label'] ?? $name );

		return $this->form_row( $id, $label, $control, $grouped, isset( $spec['show_if'] ) ? (array) $spec['show_if'] : null );
	}

	/**
	 * A form row: a label on one side and the controls on the other.
	 *
	 * @param string                    $id      Control id, or a unique id for a group.
	 * @param string                    $label   Label text (plain).
	 * @param string                    $control Trusted HTML for the controls.
	 * @param bool                      $grouped True when the label names a group rather than one input.
	 * @param array<string, mixed>|null $show_if Condition for showing the row.
	 * @return string
	 */
	private function form_row( string $id, string $label, string $control, bool $grouped, ?array $show_if ): string {
		if ( $grouped ) {
			$label_html = Html::tag(
				'div',
				array(
					'class' => 'estasoft-nd-form-label',
					'id'    => $id . '-label',
				),
				esc_html( $label )
			);
			$control    = Html::tag(
				'div',
				array(
					'class'           => 'estasoft-nd-form-control',
					'role'            => 'group',
					'aria-labelledby' => $id . '-label',
				),
				$control
			);
		} else {
			$label_html = Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-form-label',
					'for'   => $id,
				),
				esc_html( $label )
			);
			$control    = Html::tag( 'div', array( 'class' => 'estasoft-nd-form-control' ), $control );
		}

		return Html::tag( 'div', $this->condition_attrs( 'estasoft-nd-form-row', $show_if ), $label_html . $control );
	}

	/**
	 * The block of settings that belong under another setting.
	 *
	 * @param array<string, mixed> $nested The `nested` entry of a row.
	 * @return string
	 */
	private function nested( array $nested ): string {
		$fields = $this->fields;
		$html   = '';

		if ( ! empty( $nested['note'] ) ) {
			$html .= Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( (string) $nested['note'] ) );
		}

		foreach ( (array) ( $nested['fields'] ?? array() ) as $name ) {
			if ( ! isset( $fields[ $name ] ) ) {
				continue;
			}

			$spec    = $fields[ $name ];
			$control = Field::render( $this->scope, (string) $name, $spec, $this->values[ $name ] ?? Field::default_of( $spec ), $this->error_for( (string) $name ) );

			if ( Field::is_self_labelled( $spec ) ) {
				$html .= $control;
				continue;
			}

			$html .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-label',
						'for'   => Field::id( $this->scope, (string) $name ),
					),
					esc_html( (string) ( $spec['row_label'] ?? $spec['label'] ?? $name ) )
				)
				. $control
			);
		}

		return Html::tag(
			'div',
			$this->condition_attrs( 'estasoft-nd-nested', isset( $nested['show_if'] ) ? (array) $nested['show_if'] : null ),
			$html
		);
	}

	/**
	 * Attributes for something that shows only while another field has a value.
	 *
	 * The server hides it when the condition is false now; the script shows and
	 * hides it as the other field changes.
	 *
	 * @param string                    $css_class Class name.
	 * @param array<string, mixed>|null $show_if Condition: field => value.
	 * @return array<string, mixed>
	 */
	private function condition_attrs( string $css_class, ?array $show_if ): array {
		$attrs = array( 'class' => $css_class );

		if ( null === $show_if || array() === $show_if ) {
			return $attrs;
		}

		$visible = true;

		foreach ( $show_if as $field => $wanted ) {
			$current = $this->values[ $field ] ?? '';
			$current = is_bool( $current ) ? ( $current ? '1' : '0' ) : (string) ( is_scalar( $current ) ? $current : '' );
			$wanted  = is_bool( $wanted ) ? ( $wanted ? '1' : '0' ) : (string) $wanted;

			if ( $current !== $wanted ) {
				$visible = false;
			}
		}

		$attrs['data-show-if'] = (string) wp_json_encode( $show_if );

		if ( ! $visible ) {
			$attrs['hidden'] = true;
		}

		return $attrs;
	}

	/**
	 * The error for a field, including errors on its parts ("names.ku").
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	private function error_for( string $field ): string {
		if ( isset( $this->errors[ $field ] ) ) {
			return $this->errors[ $field ];
		}

		foreach ( $this->errors as $path => $message ) {
			if ( 0 === strpos( (string) $path, $field . '.' ) ) {
				return $message;
			}
		}

		return '';
	}
}

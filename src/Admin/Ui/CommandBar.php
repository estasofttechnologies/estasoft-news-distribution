<?php
/**
 * The bar above a list or table: filters on the left, actions on the right.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * Filters stay in the URL so a filtered view can be linked, so the bar is a
 * GET form. The screen supplies the `page` argument as a hidden input.
 */
final class CommandBar {

	/**
	 * @param string                $page    The `page` query argument.
	 * @param string                $filters Trusted HTML for the filters.
	 * @param string                $actions Trusted HTML for at most one or two secondary buttons, or empty.
	 * @param array<string, scalar> $hidden  Extra query arguments to keep (for example `report`).
	 * @return string
	 */
	public static function render( string $page, string $filters, string $actions = '', array $hidden = array() ): string {
		$inputs = Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => 'page',
				'value' => $page,
			)
		);

		foreach ( $hidden as $name => $value ) {
			$inputs .= Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => (string) $name,
					'value' => (string) $value,
				)
			);
		}

		return Html::tag(
			'form',
			array(
				'class'  => 'estasoft-nd-commandbar',
				'method' => 'get',
				'action' => admin_url( 'admin.php' ),
			),
			$inputs . $filters . ( '' !== $actions ? Html::tag( 'div', array( 'class' => 'estasoft-nd-end estasoft-nd-cluster estasoft-nd-gap-8' ), $actions ) : '' )
		);
	}

	/**
	 * A select with its label above it.
	 *
	 * @param string                $name    Query argument.
	 * @param string                $label   Visible label (plain).
	 * @param array<string, string> $options Value => label.
	 * @param string                $current Current value.
	 * @return string
	 */
	public static function select( string $name, string $label, array $options, string $current ): string {
		$items = '';

		foreach ( $options as $value => $text ) {
			$items .= Html::tag(
				'option',
				array(
					'value'    => (string) $value,
					'selected' => (string) $value === $current,
				),
				esc_html( $text )
			);
		}

		$id = 'estasoft-nd-filter-' . sanitize_key( $name );

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-label',
					'for'   => $id,
				),
				esc_html( $label )
			)
			. Html::tag(
				'select',
				array(
					'class' => 'estasoft-nd-field',
					'id'    => $id,
					'name'  => $name,
				),
				$items
			)
		);
	}

	/**
	 * A search field with its label above it.
	 *
	 * @param string $name    Query argument.
	 * @param string $label   Visible label (plain).
	 * @param string $current Current text.
	 * @return string
	 */
	public static function search( string $name, string $label, string $current ): string {
		$id = 'estasoft-nd-filter-' . sanitize_key( $name );

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-label',
					'for'   => $id,
				),
				esc_html( $label )
			)
			. Html::void(
				'input',
				array(
					'class' => 'estasoft-nd-field',
					'type'  => 'search',
					'id'    => $id,
					'name'  => $name,
					'value' => $current,
				)
			)
		);
	}

	/**
	 * A segmented control that filters by one value, as links so it works without script.
	 *
	 * @param array<string, array{label: string, url: string}> $items   Value => label and address.
	 * @param string                                           $current Current value.
	 * @param string                                           $label   What it filters, for screen readers.
	 * @return string
	 */
	public static function segments( array $items, string $current, string $label ): string {
		$links = '';

		foreach ( $items as $value => $item ) {
			$on     = (string) $value === $current;
			$links .= Html::tag(
				'a',
				array(
					'class'        => $on ? 'is-on' : null,
					'href'         => $item['url'],
					'aria-current' => $on ? 'true' : null,
				),
				esc_html( $item['label'] )
			);
		}

		return Html::tag(
			'div',
			array(
				'class'      => 'estasoft-nd-seg',
				'role'       => 'group',
				'aria-label' => $label,
			),
			$links
		);
	}
}

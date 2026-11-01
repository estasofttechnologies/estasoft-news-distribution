<?php
/**
 * A copyable address or value.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * Shows a sitemap or feed address, or a value to paste somewhere else, with a
 * Copy button. The address is plain text a person can select, so the row works
 * without a script; the script (`assets/js/screens/address-row.js`) only makes
 * the button copy it.
 */
final class AddressRow {

	/**
	 * One language's address: the language chip, the address and Copy.
	 *
	 * @param string $language Language code.
	 * @param string $url      The address.
	 * @param string $what     What it is, for the button's name, such as "news sitemap".
	 * @return string
	 */
	public static function language( string $language, string $url, string $what ): string {
		$name = \Estasoft\NewsDistribution\Core\Language\Catalog::name( $language );

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-address' ),
			Tag::lang( $language )
			. Html::tag(
				'code',
				array(
					'class'      => 'estasoft-nd-url estasoft-nd-mono',
					'dir'        => 'ltr',
					'tabindex'   => '0',
					'aria-label' => sprintf(
						/* translators: 1: what the address is, such as "news sitemap"; 2: a language name such as "Arabic". */
						__( 'Address of the %1$s, %2$s', 'estasoft-news-distribution' ),
						$what,
						$name
					),
				),
				esc_html( $url )
			)
			. self::copy_button(
				$url,
				sprintf(
					/* translators: 1: what the address is, such as "news sitemap"; 2: a language name such as "Arabic". */
					__( 'Copy the address of the %1$s, %2$s', 'estasoft-news-distribution' ),
					$what,
					$name
				)
			)
		);
	}

	/**
	 * A labelled value: the label, the value and Copy.
	 *
	 * @param string $label The value's name, such as "Publication".
	 * @param string $value The text to show and copy.
	 * @return string
	 */
	public static function value( string $label, string $value ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-address estasoft-nd-address--labelled' ),
			Html::tag( 'span', array( 'class' => 'estasoft-nd-address__label estasoft-nd-small' ), esc_html( $label ) )
			. Html::tag(
				'code',
				array(
					'class'      => 'estasoft-nd-url estasoft-nd-mono',
					'tabindex'   => '0',
					'aria-label' => $label,
				),
				esc_html( $value )
			)
			. self::copy_button(
				$value,
				sprintf(
					/* translators: %s: the name of a value, such as "Website". */
					__( 'Copy %s', 'estasoft-news-distribution' ),
					$label
				)
			)
		);
	}

	/**
	 * The Copy button. Without a script it does nothing; the text beside it can be selected.
	 *
	 * @param string $text  Text to copy.
	 * @param string $label The button's accessible name.
	 * @return string
	 */
	private static function copy_button( string $text, string $label ): string {
		return Button::button(
			__( 'Copy', 'estasoft-news-distribution' ),
			array(
				'size'  => 'sm',
				'icon'  => 'copy',
				'attrs' => array(
					'aria-label'        => $label,
					'data-copy'         => $text,
					'data-copied-label' => __( 'Copied', 'estasoft-news-distribution' ),
				),
			)
		);
	}
}

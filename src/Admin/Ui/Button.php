<?php
/**
 * Buttons and button-styled links.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * One filled primary button per view. Options for every method:
 *
 * - variant: "primary", "subtle" or "" (the default outlined look).
 * - size: "sm", "lg" or "".
 * - icon: an icon name placed before the label.
 * - attrs: more attributes (aria-label, data-*, id).
 */
final class Button {

	/**
	 * A link that looks like a button.
	 *
	 * @param string               $label Visible text (plain, escaped here).
	 * @param string               $url   Destination.
	 * @param array<string, mixed> $opts  Options.
	 * @return string
	 */
	public static function link( string $label, string $url, array $opts = array() ): string {
		return Html::tag(
			'a',
			array_merge(
				array(
					'class' => self::classes( $opts ),
					'href'  => $url,
				),
				(array) ( $opts['attrs'] ?? array() )
			),
			self::inner( $label, $opts )
		);
	}

	/**
	 * A button that does nothing until a script or a form gives it a job.
	 *
	 * @param string               $label Visible text.
	 * @param array<string, mixed> $opts  Options. `type` may be "button" (default), "submit" or "reset".
	 * @return string
	 */
	public static function button( string $label, array $opts = array() ): string {
		return Html::tag(
			'button',
			array_merge(
				array(
					'class' => self::classes( $opts ),
					'type'  => (string) ( $opts['type'] ?? 'button' ),
				),
				(array) ( $opts['attrs'] ?? array() )
			),
			self::inner( $label, $opts )
		);
	}

	/**
	 * A submit button.
	 *
	 * @param string               $label Visible text.
	 * @param array<string, mixed> $opts  Options.
	 * @return string
	 */
	public static function submit( string $label, array $opts = array() ): string {
		$opts['type'] = 'submit';

		return self::button( $label, $opts );
	}

	/**
	 * Class names for the options.
	 *
	 * @param array<string, mixed> $opts Options.
	 * @return string
	 */
	private static function classes( array $opts ): string {
		$variant = (string) ( $opts['variant'] ?? '' );
		$size    = (string) ( $opts['size'] ?? '' );

		return Html::classes(
			'estasoft-nd-btn',
			'' !== $variant ? 'estasoft-nd-btn--' . $variant : '',
			'' !== $size ? 'estasoft-nd-btn--' . $size : '',
			(string) ( $opts['class'] ?? '' )
		);
	}

	/**
	 * Icon and label.
	 *
	 * @param string               $label Visible text.
	 * @param array<string, mixed> $opts  Options.
	 * @return string
	 */
	private static function inner( string $label, array $opts ): string {
		$icon = isset( $opts['icon'] ) ? Icon::svg( (string) $opts['icon'] ) : '';

		return $icon . esc_html( $label );
	}
}

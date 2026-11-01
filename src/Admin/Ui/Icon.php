<?php
/**
 * Inline SVG icons from the design system.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * 16 x 16 line icons, 1.6 stroke, round caps, drawn in the current text color.
 * Icons are never mirrored for right-to-left. To add an icon, add its shape here.
 */
final class Icon {

	/**
	 * Icon name => inner SVG markup.
	 *
	 * @var array<string, string>
	 */
	private const SHAPES = array(
		'check-circle'  => '<circle cx="8" cy="8" r="6.5"/><path d="M5.2 8.2l2 2 3.6-4"/>',
		'check'         => '<path d="M3.5 8.5l3 3 6-7"/>',
		'chevron-up'    => '<path d="M3.5 10l4.5-4.5 4.5 4.5"/>',
		'chevron-down'  => '<path d="M3.5 6l4.5 4.5L12.5 6"/>',
		'chevron-right' => '<path d="M6 3.5l4.5 4.5L6 12.5"/>',
		'clock'         => '<circle cx="8" cy="8" r="6.5"/><path d="M8 4.8V8l2.2 1.4"/>',
		'copy'          => '<rect x="5.5" y="5.5" width="8" height="8" rx="1.5"/><path d="M10.5 5.5v-2a1 1 0 0 0-1-1h-6a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2"/>',
		'external-link' => '<path d="M9.5 2.5h4v4M13.5 2.5L7.5 8.5"/><path d="M12 9.5v3a1 1 0 0 1-1 1H3.5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h3"/>',
		'info'          => '<circle cx="8" cy="8" r="6.5"/><path d="M8 7.4v3.6M8 5.2v.1"/>',
		'lock'          => '<rect x="3.5" y="7" width="9" height="6.5" rx="1.2"/><path d="M5.5 7V5.2a2.5 2.5 0 0 1 5 0V7"/>',
		'minus-circle'  => '<circle cx="8" cy="8" r="6.5"/><path d="M5 8h6"/>',
		'retry'         => '<path d="M13 8a5 5 0 1 1-1.5-3.6"/><path d="M13 2.8v2.8h-2.8"/>',
		'warning'       => '<path d="M8 2.3l6.2 11H1.8z"/><path d="M8 6.6v3.2M8 11.6v.1"/>',
		'x-circle'      => '<circle cx="8" cy="8" r="6.5"/><path d="M5.8 5.8l4.4 4.4M10.2 5.8l-4.4 4.4"/>',
		'x'             => '<path d="M4 4l8 8M12 4l-8 8"/>',
		'plus'          => '<path d="M8 3.5v9M3.5 8h9"/>',
		'search'        => '<circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5L14 14"/>',
		'arrow-right'   => '<path d="M3 8h10M9 4l4 4-4 4"/>',
		'arrow-left'    => '<path d="M13 8H3M7 4L3 8l4 4"/>',
	);

	/**
	 * The SVG for an icon.
	 *
	 * @param string $name  Icon name.
	 * @param string $extra_class Extra class names, such as "estasoft-nd-icon--lg".
	 * @return string An empty string when the name is unknown.
	 */
	public static function svg( string $name, string $extra_class = '' ): string {
		if ( ! isset( self::SHAPES[ $name ] ) ) {
			return '';
		}

		return '<svg' . Html::attrs(
			array(
				'class'       => Html::classes( 'estasoft-nd-icon', $extra_class ),
				'viewBox'     => '0 0 16 16',
				'aria-hidden' => 'true',
				'focusable'   => 'false',
			)
		) . '>' . self::SHAPES[ $name ] . '</svg>';
	}

	/**
	 * Whether an icon exists.
	 *
	 * @param string $name Icon name.
	 * @return bool
	 */
	public static function exists( string $name ): bool {
		return isset( self::SHAPES[ $name ] );
	}

	/**
	 * The SVG elements and attributes wp_kses must allow so icons survive output filtering.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		$common = array(
			'class'           => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
		);

		return array(
			'svg'    => $common + array(
				'viewbox'     => true,
				'width'       => true,
				'height'      => true,
				'aria-hidden' => true,
				'focusable'   => true,
				'role'        => true,
				'xmlns'       => true,
			),
			'path'   => $common + array( 'd' => true ),
			'circle' => $common + array(
				'cx' => true,
				'cy' => true,
				'r'  => true,
			),
			'rect'   => $common + array(
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'rx'     => true,
			),
			'g'      => $common,
			'line'   => $common + array(
				'x1' => true,
				'y1' => true,
				'x2' => true,
				'y2' => true,
			),
		);
	}
}

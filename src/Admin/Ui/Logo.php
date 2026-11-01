<?php
/**
 * The Estasoft mark: the one place that draws it.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * The white "E" on a blue square that heads every News Distribution screen, and the blue "E" on its own that the
 * WordPress admin menu shows.
 *
 * Both are drawn from one outline, so the shape cannot drift between the screens, the menu and the pictures made for
 * the WordPress.org page (`bin/directory-assets/`). The coordinates are those of the 1563 px square the artwork
 * was supplied in.
 */
final class Logo {

	/**
	 * The brand blue.
	 */
	public const BLUE = '#005ece';

	/**
	 * The outline of the "E": three bars on a stem, the middle bar joined to the stem by a curve.
	 */
	private const SHAPE = 'M296.1 251.2H1266.6V430.9H475.6V731.6A335.6 335.6 0 0 1 633.3 692.2H1199.9V870.1H633.3A157.7 157.7 0 0 0 475.6 1027.8V1131.3H1266.6V1311.1H296.1Z';

	/**
	 * What the admin menu shows before WordPress paints it in the colour scheme (the colour of a menu icon at rest).
	 */
	private const MENU_COLOR = '#a7aaad';

	/**
	 * The mark for a screen: the white "E" on a blue square. It is decoration, the name of the product is written next to it.
	 *
	 * @param int $size Width and height in pixels.
	 * @return string
	 */
	public static function mark( int $size = 24 ): string {
		return '<svg class="estasoft-nd-logo" width="' . $size . '" height="' . $size . '" viewBox="0 0 1563 1563" aria-hidden="true" focusable="false">'
			. '<rect width="1563" height="1563" fill="' . self::BLUE . '"/>'
			. '<path fill="#fff" d="' . self::SHAPE . '"/>'
			. '</svg>';
	}

	/**
	 * The "E" on its own, for the admin menu, as the data address WordPress asks for.
	 *
	 * WordPress paints an icon of this kind in the colour of the admin colour scheme by replacing its `fill`, so the
	 * outline carries a `fill` and nothing else that sets a colour.
	 *
	 * @return string
	 */
	public static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="157.9 157.7 1246.9 1246.9">'
			. '<path fill="' . self::MENU_COLOR . '" d="' . self::SHAPE . '"/>'
			. '</svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WordPress takes a menu icon image only as a base64 data address.
	}
}

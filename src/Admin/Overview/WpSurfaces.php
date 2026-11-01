<?php
/**
 * The few things the dashboard widget and the Posts column share.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Overview;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Ui\Html;

defined( 'ABSPATH' ) || exit;

/**
 * News Distribution draws on two WordPress screens that are not its own: the dashboard and the Posts
 * list. It does not load its whole admin style there. One small style sheet, scoped to a wrapper class,
 * holds the status colors and the sizes these two surfaces use.
 */
final class WpSurfaces {

	public const STYLE   = 'estasoft-nd-wp-surfaces';
	public const WRAPPER = 'estasoft-nd-wp';

	/**
	 * Loads the style sheet.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		wp_enqueue_style(
			self::STYLE,
			ESTASOFT_ND_URL . 'assets/css/screens/wp-surfaces.css',
			array(),
			Assets::file_version( 'assets/css/screens/wp-surfaces.css' )
		);
	}

	/**
	 * Wraps markup in the element the style sheet is scoped to.
	 *
	 * @param string $inside Trusted HTML.
	 * @param string $extra  A second class name for the surface, such as "estasoft-nd-wp--column".
	 * @return string
	 */
	public static function wrap( string $inside, string $extra = '' ): string {
		return Html::tag( 'div', array( 'class' => Html::classes( self::WRAPPER, $extra ) ), $inside );
	}
}

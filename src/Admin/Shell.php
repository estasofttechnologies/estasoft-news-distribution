<?php
/**
 * The frame around every News Distribution screen.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin;

use Estasoft\NewsDistribution\Admin\Ui\AppBar;
use Estasoft\NewsDistribution\Admin\Ui\Html;

defined( 'ABSPATH' ) || exit;

/**
 * The wrapper WordPress expects (`wrap`, with an `hr.wp-header-end` so core's
 * notices land above the app), then the app bar, then the screen's body.
 */
final class Shell {

	private Admin $admin;

	/**
	 * @param Admin $admin The admin controller, which knows the screens and the view.
	 */
	public function __construct( Admin $admin ) {
		$this->admin = $admin;
	}

	/**
	 * Wraps a screen's body.
	 *
	 * @param Page   $page The screen.
	 * @param string $body Escaped HTML from Page::render().
	 * @return string
	 */
	public function frame( Page $page, string $body ): string {
		list( $tabs, $more ) = $this->admin->tabs();

		return Html::tag(
			'div',
			array(
				'class'     => 'wrap estasoft-nd',
				'id'        => 'estasoft-nd-app',
				'data-page' => $page->slug(),
				'data-view' => $this->admin->view(),
			),
			Html::void( 'hr', array( 'class' => 'wp-header-end' ) )
			. Html::tag(
				'main',
				array( 'class' => 'estasoft-nd-main' ),
				$this->banner( $page ) . AppBar::render( $tabs, $more, $page->slug() ) . Html::tag( 'div', array( 'class' => 'estasoft-nd-page' ), $body )
			)
		);
	}

	/**
	 * The banner above the app bar, when something changes what every screen does.
	 *
	 * Only one thing prints here today, staging mode, through the internal filter.
	 *
	 * @param Page $page The screen.
	 * @return string Escaped HTML, or an empty string.
	 */
	private function banner( Page $page ): string {
		$banner = apply_filters( 'estasoft_nd_internal_global_banner', '', $page );

		return is_string( $banner ) ? $banner : '';
	}
}

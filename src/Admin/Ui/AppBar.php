<?php
/**
 * The bar at the top of every News Distribution screen.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * Brand, the tabs, and a More menu that holds the tabs the Simple view hides.
 */
final class AppBar {

	/**
	 * @param array<int, array{slug: string, label: string, url: string}> $tabs    Tabs shown in the bar.
	 * @param array<int, array{slug: string, label: string, url: string}> $more    Links under More; empty hides the menu.
	 * @param string                                                      $current Slug of the current screen.
	 * @return string
	 */
	public static function render( array $tabs, array $more, string $current ): string {
		$brand = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-appbar__brand' ),
			Logo::mark()
			. Html::tag( 'span', array(), esc_html__( 'Estasoft News Distribution', 'estasoft-news-distribution' ) )
		);

		$links = '';

		foreach ( $tabs as $tab ) {
			$is_current = $tab['slug'] === $current;
			$links     .= Html::tag(
				'a',
				array(
					'class'        => Html::classes( 'estasoft-nd-tab', $is_current ? 'is-current' : '' ),
					'href'         => $tab['url'],
					'aria-current' => $is_current ? 'page' : null,
				),
				esc_html( $tab['label'] )
			);
		}

		if ( array() !== $more ) {
			$in_more = false;
			$items   = '';

			foreach ( $more as $item ) {
				$is_current = $item['slug'] === $current;
				$in_more    = $in_more || $is_current;
				$items     .= Html::tag(
					'a',
					array(
						'href'         => $item['url'],
						'aria-current' => $is_current ? 'page' : null,
					),
					esc_html( $item['label'] )
				);
			}

			$links .= Html::tag(
				'details',
				array(
					'class'     => 'estasoft-nd-more',
					'data-more' => true,
				),
				Html::tag(
					'summary',
					array( 'class' => Html::classes( 'estasoft-nd-tab', $in_more ? 'is-current' : '' ) ),
					esc_html__( 'More', 'estasoft-news-distribution' ) . Icon::svg( 'chevron-down' )
				) . Html::tag( 'div', array( 'class' => 'estasoft-nd-menu' ), $items )
			);
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-appbar' ),
			$brand
			. Html::tag(
				'nav',
				array(
					'class'      => 'estasoft-nd-tabs',
					'aria-label' => __( 'News Distribution', 'estasoft-news-distribution' ),
				),
				$links
			)
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-appbar__end' ), self::end( $current ) )
		);
	}

	/**
	 * What a screen puts at the end of the bar, such as the Overview's language filter.
	 *
	 * @param string $current Slug of the current screen.
	 * @return string Trusted HTML, empty when the screen adds nothing.
	 */
	private static function end( string $current ): string {
		$html = apply_filters( 'estasoft_nd_internal_appbar_end', '', $current );

		return is_string( $html ) ? $html : '';
	}
}

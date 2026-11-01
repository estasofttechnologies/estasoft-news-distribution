<?php
/**
 * The side list used on Settings.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * Groups of links with a small heading above each. The current link carries
 * aria-current="page".
 */
final class SideNav {

	/**
	 * @param string                                                                                                $label  What the list is, for screen readers.
	 * @param array<int, array{label: string, items: array<int, array{label: string, url: string, current: bool}>}> $groups Groups of links.
	 * @return string
	 */
	public static function render( string $label, array $groups ): string {
		$html = '';

		foreach ( $groups as $group ) {
			if ( array() === $group['items'] ) {
				continue;
			}

			$html .= Html::tag( 'span', array( 'class' => 'estasoft-nd-label' ), esc_html( $group['label'] ) );

			foreach ( $group['items'] as $item ) {
				$html .= Html::tag(
					'a',
					array(
						'href'         => $item['url'],
						'aria-current' => $item['current'] ? 'page' : null,
					),
					esc_html( $item['label'] )
				);
			}
		}

		return Html::tag(
			'nav',
			array(
				'class'      => 'estasoft-nd-sidenav',
				'aria-label' => $label,
			),
			$html
		);
	}
}

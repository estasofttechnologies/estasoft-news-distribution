<?php
/**
 * Disclosure: a group of options that stays closed until the person wants it.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * Built on <details>, so it works with the keyboard and screen readers without script.
 * Not for content people must read, such as errors.
 */
final class Disclosure {

	/**
	 * @param string $title   Group title (plain).
	 * @param string $summary One sentence under the title (plain), or empty.
	 * @param string $count   Short count shown at the end ("0 of 2 selected"), or empty.
	 * @param string $body    Trusted HTML for the content.
	 * @param bool   $open    Open by default.
	 * @return string
	 */
	public static function render( string $title, string $summary, string $count, string $body, bool $open = false ): string {
		$text = Html::tag( 'h2', array( 'class' => 'estasoft-nd-title' ), esc_html( $title ) );

		if ( '' !== $summary ) {
			$text .= Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $summary ) );
		}

		$end = Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
			( '' !== $count ? Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $count ) ) : '' )
			. Html::tag( 'span', array( 'class' => 'estasoft-nd-strong' ), esc_html__( 'Show', 'estasoft-news-distribution' ) )
		);

		return Html::tag(
			'details',
			array(
				'class' => 'estasoft-nd-collapse',
				'open'  => $open,
			),
			Html::tag( 'summary', array(), Html::tag( 'div', array(), $text ) . $end ) . $body
		);
	}
}

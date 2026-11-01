<?php
/**
 * Cards.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * A card never contains a card.
 */
final class Card {

	/**
	 * A flush card: a heading, an optional line of help, then rows that run edge to edge.
	 *
	 * @param string $id          Unique id, used for aria-labelledby.
	 * @param string $title       Heading text (plain).
	 * @param string $description One line under the heading (plain), or empty.
	 * @param string $body        Trusted HTML for the rows.
	 * @param string $actions     Trusted HTML placed at the end of the heading, or empty.
	 * @return string
	 */
	public static function flush( string $id, string $title, string $description, string $body, string $actions = '' ): string {
		$heading_id = 'estasoft-nd-card-' . sanitize_key( $id );

		$head = Html::tag(
			'h2',
			array(
				'class' => 'estasoft-nd-title',
				'id'    => $heading_id,
			),
			esc_html( $title )
		);

		if ( '' !== $description ) {
			$head .= Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $description ) );
		}

		if ( '' !== $actions ) {
			$head = Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ), Html::tag( 'div', array( 'class' => 'estasoft-nd-grow' ), $head ) . $actions );
		}

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--flush',
				'aria-labelledby' => $heading_id,
			),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-card-head' ), $head ) . $body
		);
	}

	/**
	 * A padded note card for a short block of text or a list.
	 *
	 * @param string $id    Unique id.
	 * @param string $title Heading text (plain).
	 * @param string $body  Trusted HTML.
	 * @return string
	 */
	public static function note( string $id, string $title, string $body ): string {
		$heading_id = 'estasoft-nd-card-' . sanitize_key( $id );

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--note',
				'aria-labelledby' => $heading_id,
			),
			Html::tag(
				'h2',
				array(
					'class' => 'estasoft-nd-title',
					'id'    => $heading_id,
				),
				esc_html( $title )
			) . $body
		);
	}

	/**
	 * The "What it cannot do" card every screen carries.
	 *
	 * @param string[] $items One plain sentence per limit.
	 * @param string   $title Heading; defaults to "What it cannot do".
	 * @return string Empty when there are no items.
	 */
	public static function cannot( array $items, string $title = '' ): string {
		if ( array() === $items ) {
			return '';
		}

		$list = '';

		foreach ( $items as $item ) {
			$list .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule' ), Icon::svg( 'minus-circle' ) . Html::tag( 'span', array(), esc_html( (string) $item ) ) );
		}

		return self::note(
			'cannot-' . md5( implode( '|', $items ) ),
			'' === $title ? __( 'What it cannot do', 'estasoft-news-distribution' ) : $title,
			Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $list )
		);
	}
}

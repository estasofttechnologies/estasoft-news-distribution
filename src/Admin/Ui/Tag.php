<?php
/**
 * Small labels: mode tags, language chips, highlight tags and counts.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

use Estasoft\NewsDistribution\Core\Language\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * A tag classifies something. It is never a state; use StatusView for that.
 */
final class Tag {

	/**
	 * A mode tag: "Pulled" or "Pushed".
	 *
	 * @param string $mode "pulled" or "pushed".
	 * @return string
	 */
	public static function mode( string $mode ): string {
		return self::plain( 'pushed' === $mode ? __( 'Pushed', 'estasoft-news-distribution' ) : __( 'Pulled', 'estasoft-news-distribution' ) );
	}

	/**
	 * A plain tag.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	public static function plain( string $text ): string {
		return Html::tag( 'span', array( 'class' => 'estasoft-nd-tag' ), esc_html( $text ) );
	}

	/**
	 * A language chip: the two-letter code in capitals, not translated.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function lang( string $code ): string {
		return Html::tag(
			'span',
			array(
				'class' => 'estasoft-nd-lang',
				'title' => Catalog::name( $code ),
			),
			esc_html( Catalog::tag( $code ) )
		);
	}

	/**
	 * A highlight tag: one short word such as Recommended or Selected.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	public static function highlight( string $text ): string {
		return Html::tag( 'span', array( 'class' => 'estasoft-nd-tier' ), esc_html( $text ) );
	}

	/**
	 * A count pill after a section title.
	 *
	 * @param int $number The number.
	 * @return string
	 */
	public static function count( int $number ): string {
		return Html::tag( 'span', array( 'class' => 'estasoft-nd-count' ), esc_html( number_format_i18n( $number ) ) );
	}
}

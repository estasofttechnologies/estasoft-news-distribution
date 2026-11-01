<?php
/**
 * Small pieces the report screens share.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports\Parts;

use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Core\Language\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Words, times and marks as the report boards print them. Every method that
 * returns HTML escapes what it prints.
 */
final class View {

	/**
	 * The icon each tone uses.
	 *
	 * @var array<string, string>
	 */
	private const ICONS = array(
		'success' => 'check-circle',
		'warning' => 'warning',
		'danger'  => 'x-circle',
		'neutral' => 'minus-circle',
		'wait'    => 'clock',
		'info'    => 'info',
	);

	/**
	 * An icon and a word, for a result that has no code in Core\Status ("Valid", "Allowed", "Lists all 3").
	 *
	 * The meaning is in the word, never in the colour alone.
	 *
	 * @param string $tone success, warning, danger, neutral, wait or info.
	 * @param string $text Plain text; it is escaped here.
	 * @return string
	 */
	public static function mark( string $tone, string $text ): string {
		$tone = isset( self::ICONS[ $tone ] ) ? $tone : 'neutral';

		return Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-status estasoft-nd-status--' . $tone ),
			Icon::svg( self::ICONS[ $tone ] ) . Html::tag( 'span', array(), esc_html( $text ) )
		);
	}

	/**
	 * The lead message of a report: a bold sentence, then the rest.
	 *
	 * @param string $tone    info, success, warning or danger.
	 * @param string $strong  The bold first sentence (plain text).
	 * @param string $text    The rest (plain text), or empty.
	 * @param string $actions Trusted HTML for a button or link at the end, or empty.
	 * @return string
	 */
	public static function lead( string $tone, string $strong, string $text = '', string $actions = '' ): string {
		$html = Html::tag( 'strong', array(), esc_html( $strong ) );

		if ( '' !== $text ) {
			$html .= ' ' . esc_html( $text );
		}

		return Message::bar( $tone, $html, $actions );
	}

	/**
	 * How long ago, in the words the boards use: "2 min ago", "26 hours ago", "3 days ago".
	 *
	 * @param int $timestamp Unix time.
	 * @param int $now       Unix time to count from.
	 * @return string
	 */
	public static function ago( int $timestamp, int $now ): string {
		$seconds = max( 0, $now - $timestamp );

		if ( $seconds < MINUTE_IN_SECONDS ) {
			return __( 'Just now', 'estasoft-news-distribution' );
		}

		if ( $seconds < HOUR_IN_SECONDS ) {
			$minutes = (int) floor( $seconds / MINUTE_IN_SECONDS );

			return sprintf(
				/* translators: %s: number of minutes. */
				_n( '%s min ago', '%s min ago', $minutes, 'estasoft-news-distribution' ),
				number_format_i18n( $minutes )
			);
		}

		if ( $seconds < 2 * DAY_IN_SECONDS ) {
			$hours = (int) floor( $seconds / HOUR_IN_SECONDS );

			return sprintf(
				/* translators: %s: number of hours. */
				_n( '%s hour ago', '%s hours ago', $hours, 'estasoft-news-distribution' ),
				number_format_i18n( $hours )
			);
		}

		$days = (int) floor( $seconds / DAY_IN_SECONDS );

		return sprintf(
			/* translators: %s: number of days. */
			_n( '%s day ago', '%s days ago', $days, 'estasoft-news-distribution' ),
			number_format_i18n( $days )
		);
	}

	/**
	 * A size in kilobytes, as the self-test table prints it: "4 KB". A body under a kilobyte is "1 KB".
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	public static function size( int $bytes ): string {
		return sprintf(
			/* translators: %s: size in kilobytes. */
			__( '%s KB', 'estasoft-news-distribution' ),
			number_format_i18n( max( 1, (int) round( $bytes / 1024 ) ) )
		);
	}

	/**
	 * A time to answer: "120 ms", or "1.9 s" from one second up.
	 *
	 * @param int $milliseconds Milliseconds.
	 * @return string
	 */
	public static function duration( int $milliseconds ): string {
		if ( $milliseconds >= 1000 ) {
			return sprintf(
				/* translators: %s: seconds, such as 1.9. */
				__( '%s s', 'estasoft-news-distribution' ),
				number_format_i18n( $milliseconds / 1000, 1 )
			);
		}

		return sprintf(
			/* translators: %s: milliseconds. */
			__( '%s ms', 'estasoft-news-distribution' ),
			number_format_i18n( max( 0, $milliseconds ) )
		);
	}

	/**
	 * The name of a language as the report headings print it.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function language( string $code ): string {
		if ( 'ku' === Catalog::primary( $code ) ) {
			return __( 'Kurdish (Kurmanji)', 'estasoft-news-distribution' );
		}

		return Catalog::name( $code );
	}

	/**
	 * The short name of a language, for a sentence such as "Create Kurdish translation".
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function language_short( string $code ): string {
		if ( 'ku' === Catalog::primary( $code ) ) {
			return __( 'Kurdish', 'estasoft-news-distribution' );
		}

		return Catalog::name( $code );
	}

	/**
	 * A day and time the way the boards print it: "today, 14:23", "yesterday, 09:10", "19 Oct, 09:12".
	 *
	 * @param int $timestamp Unix time.
	 * @param int $now       Unix time to count from.
	 * @return string
	 */
	public static function day( int $timestamp, int $now ): string {
		$clock = wp_date( 'H:i', $timestamp, wp_timezone() );
		$day   = wp_date( 'Y-m-d', $timestamp, wp_timezone() );

		if ( wp_date( 'Y-m-d', $now, wp_timezone() ) === $day ) {
			return sprintf(
				/* translators: %s: a time such as 14:23. */
				__( 'today, %s', 'estasoft-news-distribution' ),
				$clock
			);
		}

		if ( wp_date( 'Y-m-d', $now - DAY_IN_SECONDS, wp_timezone() ) === $day ) {
			return sprintf(
				/* translators: %s: a time such as 14:23. */
				__( 'yesterday, %s', 'estasoft-news-distribution' ),
				$clock
			);
		}

		return sprintf(
			/* translators: 1: a date such as "19 Oct", 2: a time such as 09:12. */
			__( '%1$s, %2$s', 'estasoft-news-distribution' ),
			wp_date( 'j M', $timestamp, wp_timezone() ),
			$clock
		);
	}

	/**
	 * Attributes that give a piece of text its direction when it is written in Arabic letters,
	 * so an Arabic title reads right to left inside an English screen.
	 *
	 * @param string $text The text.
	 * @return array<string, string> Attributes for Html::tag(), or none.
	 */
	public static function text_attributes( string $text ): array {
		if ( 1 === preg_match( '/\p{Arabic}/u', $text ) ) {
			return array(
				'lang' => 'ar',
				'dir'  => 'rtl',
			);
		}

		return array();
	}

	/**
	 * Bars for the last days, oldest on the left, today on the right.
	 *
	 * The height is a class (`estasoft-nd-bar--0` to `--10`), so no inline style is needed.
	 *
	 * @param int[]  $counts Fetches per day, oldest first.
	 * @param string $label  What the bars show, for screen readers.
	 * @return string
	 */
	public static function bars( array $counts, string $label ): string {
		$counts = array_map( 'intval', array_values( $counts ) );
		$peak   = max( 1, (int) max( array_merge( array( 0 ), $counts ) ) );
		$bars   = '';

		foreach ( $counts as $count ) {
			$level = $count < 1 ? 0 : max( 1, (int) round( 10 * $count / $peak ) );
			$bars .= Html::tag( 'span', array( 'class' => 'estasoft-nd-bar estasoft-nd-bar--' . $level ), '' );
		}

		return Html::tag(
			'span',
			array(
				'class'      => 'estasoft-nd-bars',
				'role'       => 'img',
				'aria-label' => $label . ' ' . implode( ', ', $counts ),
			),
			$bars
		);
	}

	/**
	 * A width in pixels, such as "1,200 px".
	 *
	 * @param int $pixels Pixels.
	 * @return string
	 */
	public static function pixels( int $pixels ): string {
		return sprintf(
			/* translators: %s: width in pixels. */
			__( '%s px', 'estasoft-news-distribution' ),
			number_format_i18n( $pixels )
		);
	}

	/**
	 * The footer of a list that shows only some of its rows: "Showing 6 of 10 articles, newest first."
	 *
	 * @param string $text   The sentence (plain).
	 * @param string $button Trusted HTML for the "Show all" link, or empty.
	 * @return string
	 */
	public static function footer( string $text, string $button = '' ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
			Html::tag( 'span', array( 'class' => 'estasoft-nd-grow estasoft-nd-small' ), esc_html( $text ) ) . $button
		);
	}
}

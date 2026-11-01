<?php
/**
 * Progress bar and timeline.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * Counts steps, never time: News Distribution cannot know when a platform will answer.
 */
final class Progress {

	/**
	 * A bar with its words underneath.
	 *
	 * @param string $name  What is measured ("SmartNews").
	 * @param int    $done  Steps done.
	 * @param int    $total Steps in all.
	 * @return string
	 */
	public static function steps( string $name, int $done, int $total ): string {
		$total   = max( 1, $total );
		$done    = max( 0, min( $total, $done ) );
		$percent = (int) round( $done / $total * 100 );
		$words   = sprintf(
			/* translators: 1: steps done, 2: steps in all. */
			__( '%1$d of %2$d steps', 'estasoft-news-distribution' ),
			$done,
			$total
		);

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
			Html::tag( 'span', array( 'class' => 'estasoft-nd-strong' ), esc_html( $name ) )
			. Html::tag(
				'span',
				array(
					'class'         => Html::classes( 'estasoft-nd-progress', $done === $total ? 'estasoft-nd-progress--done' : '' ),
					'role'          => 'progressbar',
					'aria-valuemin' => '0',
					'aria-valuemax' => (string) $total,
					'aria-valuenow' => (string) $done,
					'aria-label'    => $name . ': ' . $words,
				),
				Html::tag( 'span', array( 'style' => 'width:' . $percent . '%' ), '' )
			)
			. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $words ) )
		);
	}

	/**
	 * A timeline: what happened to something, newest first.
	 *
	 * @param array<int, array{title: string, text: string, current?: bool}> $entries Entries, newest first.
	 * @return string
	 */
	public static function timeline( array $entries ): string {
		$items = '';

		foreach ( $entries as $entry ) {
			$current = ! empty( $entry['current'] );
			$items  .= Html::tag(
				'li',
				array(),
				Html::tag( 'span', array( 'class' => Html::classes( 'estasoft-nd-timeline__dot', $current ? 'estasoft-nd-timeline__dot--now' : '' ) ), '' )
				. Html::tag(
					'div',
					array(),
					Html::tag( 'p', array( 'class' => 'estasoft-nd-strong' ), esc_html( $entry['title'] ) . ( $current ? ' · ' . esc_html__( 'Selected', 'estasoft-news-distribution' ) : '' ) )
					. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $entry['text'] ) )
				)
			);
		}

		return Html::tag( 'ol', array( 'class' => 'estasoft-nd-timeline' ), $items );
	}
}

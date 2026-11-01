<?php
/**
 * Settings, Clock and time zone.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Core\Clock as Time;
use Estasoft\NewsDistribution\Core\ClockCheck;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the site's time zone, the server's clocks and the dates of recent
 * articles, and holds the one switch: skip an article dated in the future until its
 * time (`skip_future`, on by default). The checks come from Core\ClockCheck.
 *
 * The switch is read by the content reader (feeds, sitemaps and pushes list no
 * article that is dated in the future) and by the delivery hold module (a push
 * waits for the article's time). Scheduled posts are not touched: WordPress keeps
 * them off the site until they are due.
 */
final class Clock extends AbstractSiteSection {

	public const NAME = 'clock';

	private ?array $report = null;

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'skip_future' => array(
				'type'      => 'bool',
				'label'     => __( 'While an item is dated in the future', 'estasoft-news-distribution' ),
				'row_label' => __( 'While an item is dated in the future', 'estasoft-news-distribution' ),
				'default'   => true,
				'control'   => 'checkbox',
				'title'     => __( 'Skip it in feeds, sitemaps and pushes until its time', 'estasoft-news-distribution' ),
				'help'      => __( 'Recommended. It appears by itself once the time has passed. Scheduled posts are not affected: WordPress keeps them off the site until they are due.', 'estasoft-news-distribution' ),
			),
		);
	}

	public function title(): string {
		return __( 'Clock and time zone', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'site';
	}

	public function order(): int {
		return 30;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function layout(): array {
		return array(
			'cards'  => array(
				array(
					'id'          => 'checks',
					'title'       => __( 'Checks', 'estasoft-news-distribution' ),
					'description' => __( 'These read your settings and change nothing until you press a button.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'Checks', 'estasoft-news-distribution' ),
							'wide'  => true,
							'html'  => array( $this, 'render_checks' ),
						),
					),
				),
				array(
					'id'          => 'clocks',
					'title'       => __( 'The clocks it compared', 'estasoft-news-distribution' ),
					'description' => __( 'Local time is shown as WordPress sees it.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'Clocks', 'estasoft-news-distribution' ),
							'wide'  => true,
							'html'  => array( $this, 'render_clocks' ),
						),
					),
				),
				array(
					'id'          => 'future',
					'title'       => __( 'Dated in the future', 'estasoft-news-distribution' ),
					'description' => __( 'These look published to WordPress but carry a time that has not come yet, usually because of a wrong time zone.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'Articles', 'estasoft-news-distribution' ),
							'wide'  => true,
							'html'  => array( $this, 'render_future' ),
						),
						array( 'field' => 'skip_future' ),
					),
				),
			),
			'notes'  => array(
				array(
					'title' => __( 'Where times are used', 'estasoft-news-distribution' ),
					'html'  => self::icon_list(
						array(
							__( 'News sitemap: the publication date, with its offset', 'estasoft-news-distribution' ),
							__( 'RSS: pubDate in the format RSS asks for', 'estasoft-news-distribution' ),
							__( 'Atom and JSON Feed: updated and date_published', 'estasoft-news-distribution' ),
							__( 'Article markup: datePublished and dateModified', 'estasoft-news-distribution' ),
						)
					) . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Every time carries its offset, so a platform in another zone reads the same moment.', 'estasoft-news-distribution' ) ),
				),
			),
			'cannot' => array(
				__( 'Change your time zone for you. That setting belongs to the whole site, so it opens in WordPress', 'estasoft-news-distribution' ),
				__( 'Tell which of two disagreeing clocks is right', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * The result of the check, once per request.
	 *
	 * @return array<string, mixed>
	 */
	private function report(): array {
		if ( null === $this->report ) {
			$this->report = ( new ClockCheck( Plugin::instance() ) )->run( null );
		}

		return $this->report;
	}

	/**
	 * The "Checks" row.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_checks(): string {
		return Html::tag(
			'div',
			array(
				'class'             => 'estasoft-nd-findings',
				'data-clock-region' => 'checks',
				'aria-live'         => 'polite',
			),
			self::checks_html( $this->report() )
		);
	}

	/**
	 * The "clocks it compared" row.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_clocks(): string {
		return Html::tag(
			'div',
			array(
				'data-clock-region' => 'clocks',
				'aria-live'         => 'polite',
			),
			self::clocks_html( $this->report() )
		);
	}

	/**
	 * The "Dated in the future" table.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_future(): string {
		$future = $this->report()['future'];

		if ( array() === $future['items'] ) {
			return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-clock-empty' ), esc_html__( 'No article is dated in the future.', 'estasoft-news-distribution' ) );
		}

		$rows = array();

		foreach ( $future['items'] as $item ) {
			$until = $item['feed_ts'];
			$when  = sprintf(
				/* translators: %s: a time such as 16:30. */
				__( 'Skipped until %s', 'estasoft-news-distribution' ),
				Time::format( $until )
			);

			$rows[] = array(
				'<a href="' . esc_url( admin_url( 'admin.php?page=estasoft-nd-check&post=' . (int) $item['post_id'] ) ) . '">' . esc_html( '' === (string) $item['title'] ? __( '(no title)', 'estasoft-news-distribution' ) : (string) $item['title'] ) . '</a>',
				$future['skipping'] ? self::result( 'danger', $when, 'x-circle' ) : self::result( 'warning', __( 'Listed now', 'estasoft-news-distribution' ) ),
				esc_html( self::day_label( $until ) ),
				esc_html( ClockCheck::human_span( (int) $item['ahead_seconds'] ) ),
			);
		}

		$html = self::grid(
			'estasoft-nd-cols-future',
			array(
				__( 'Article', 'estasoft-news-distribution' ),
				__( 'In feeds', 'estasoft-news-distribution' ),
				__( 'Feed time', 'estasoft-news-distribution' ),
				__( 'Ahead by', 'estasoft-news-distribution' ),
			),
			$rows,
			__( 'Articles dated in the future', 'estasoft-news-distribution' )
		);

		if ( (int) $future['count'] > count( $future['items'] ) ) {
			$html .= Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-clock-more' ),
				esc_html(
					sprintf(
						/* translators: 1: the number of articles shown, 2: all articles dated in the future. */
						__( 'Showing the first %1$s of %2$s, soonest first.', 'estasoft-news-distribution' ),
						number_format_i18n( count( $future['items'] ) ),
						number_format_i18n( (int) $future['count'] )
					)
				)
			);
		}

		return $html;
	}

	/**
	 * The findings, one row each.
	 *
	 * @param array<string, mixed> $report The result of ClockCheck::run().
	 * @return string Escaped HTML.
	 */
	public static function checks_html( array $report ): string {
		$html = '';

		foreach ( (array) $report['findings'] as $finding ) {
			$actions = '';

			if ( is_array( $finding['link'] ) ) {
				$actions = Button::link( (string) $finding['link']['label'], (string) $finding['link']['url'], array( 'size' => 'sm' ) );
			} elseif ( 'browser' === $finding['id'] && 'warning' === $finding['tone'] ) {
				$actions = Button::link(
					__( 'Check again', 'estasoft-news-distribution' ),
					self::url_of( self::NAME ),
					array(
						'size'  => 'sm',
						'attrs' => array( 'data-clock-again' => '1' ),
					)
				);
			}

			$html .= self::finding( (string) $finding['tone'], (string) $finding['title'], (string) $finding['text'], $actions );
		}

		return $html;
	}

	/**
	 * The table of clocks.
	 *
	 * @param array<string, mixed> $report The result of ClockCheck::run().
	 * @return string Escaped HTML.
	 */
	public static function clocks_html( array $report ): string {
		$rows = array();

		foreach ( (array) $report['clocks'] as $clock ) {
			$rows[] = array(
				esc_html( (string) $clock['label'] ),
				self::result( (string) $clock['tone'], (string) $clock['result'] ),
				esc_html( (string) $clock['zone'] ),
				esc_html( (string) $clock['time'] ),
			);
		}

		$html = self::grid(
			'estasoft-nd-cols-clocks',
			array(
				__( 'Clock', 'estasoft-news-distribution' ),
				__( 'Result', 'estasoft-news-distribution' ),
				__( 'Zone', 'estasoft-news-distribution' ),
				__( 'Time', 'estasoft-news-distribution' ),
			),
			$rows,
			__( 'The clocks that were compared', 'estasoft-news-distribution' )
		);

		$browser_read = false;

		foreach ( (array) $report['clocks'] as $clock ) {
			if ( 'browser' === $clock['id'] && '—' !== $clock['time'] ) {
				$browser_read = true;
			}
		}

		if ( ! $browser_read ) {
			$html .= Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-clock-more' ),
				esc_html__( 'Your browser’s clock is read by a script on this screen. Without JavaScript it stays “Not read”.', 'estasoft-news-distribution' )
			);
		}

		return $html;
	}

	/**
	 * "Today, 16:30", "Tomorrow, 09:00" or "28 Oct, 09:00" in the site's time zone.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function day_label( int $timestamp ): string {
		$zone  = Time::zone();
		$day   = wp_date( 'Y-m-d', $timestamp, $zone );
		$today = wp_date( 'Y-m-d', Time::now(), $zone );
		$next  = wp_date( 'Y-m-d', Time::now() + DAY_IN_SECONDS, $zone );
		$time  = wp_date( 'H:i', $timestamp, $zone );

		if ( $day === $today ) {
			/* translators: %s: a time such as 16:30. */
			return sprintf( __( 'Today, %s', 'estasoft-news-distribution' ), $time );
		}

		if ( $day === $next ) {
			/* translators: %s: a time such as 09:00. */
			return sprintf( __( 'Tomorrow, %s', 'estasoft-news-distribution' ), $time );
		}

		return wp_date( 'j M, H:i', $timestamp, $zone );
	}
}

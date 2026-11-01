<?php
/**
 * Referrals: which platforms send readers to your articles.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Operations\Forms;
use Estasoft\NewsDistribution\Admin\Reports\Parts\View;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\EmptyState;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Referrals\Counter;
use Estasoft\NewsDistribution\Core\Referrals\Report;
use Estasoft\NewsDistribution\Core\Referrals\Sources;
use Estasoft\NewsDistribution\Modules\ReferralsModule;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-reports&report=referrals`, and `GET /reports/referrals` for the same figures.
 *
 * It reads the counts that `Core\Referrals\Counter` keeps: one number per day, article and source name.
 * No person is in them. Counting is off until an administrator turns it on, and until then (and while
 * nothing is stored) the report shows an empty state with the switch. Turning counting off keeps the
 * counts; "Delete the counts" removes them, after a confirmation page.
 *
 * The period is the `days` argument: 7 (the default), 30 or 90.
 */
final class ReferralsReport extends AbstractReport {

	public function name(): string {
		return 'referrals';
	}

	public function title(): string {
		return __( 'Referrals', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Which platforms send readers to your site.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'audience';
	}

	public function order(): int {
		return 10;
	}

	public function enqueue(): void {
		Assets::screen_style( 'referrals' );
		Assets::screen_script( 'referrals-report' );
	}

	/**
	 * The period in the address.
	 *
	 * @return int 7, 30 or 90.
	 */
	private function days(): int {
		return Report::days( Query::text( 'days', '7' ) );
	}

	/**
	 * The figures, for the screen and for `GET /reports/referrals?days=30`.
	 *
	 * Aggregate counts only: no person, no address, no cookie is in them.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		return ( new Report( $this->plugin ) )->build( $this->days() );
	}

	/**
	 * The period choice, at the end of the page heading.
	 *
	 * @return string
	 */
	public function actions(): string {
		$counter = $this->plugin->make( Counter::class );

		if ( ! $counter->enabled() && 0 === $counter->rows() ) {
			return '';
		}

		$options = '';

		foreach ( $this->periods() as $days => $label ) {
			$options .= Html::tag(
				'option',
				array(
					'value'    => (string) $days,
					'selected' => $days === $this->days(),
				),
				esc_html( $label )
			);
		}

		$hidden = Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => 'page',
				'value' => 'estasoft-nd-reports',
			)
		) . Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => 'report',
				'value' => $this->name(),
			)
		);

		return Html::tag(
			'form',
			array(
				'class'  => 'estasoft-nd-cluster estasoft-nd-gap-8',
				'method' => 'get',
				'action' => admin_url( 'admin.php' ),
			),
			$hidden
			. Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-sr',
					'for'   => 'estasoft-nd-referrals-days',
				),
				esc_html__( 'Period', 'estasoft-news-distribution' )
			)
			. Html::tag(
				'select',
				array(
					'class'            => 'estasoft-nd-field',
					'id'               => 'estasoft-nd-referrals-days',
					'name'             => 'days',
					'data-auto-submit' => true,
				),
				$options
			)
			. Button::submit(
				__( 'Show', 'estasoft-news-distribution' ),
				array(
					'size'  => 'sm',
					'attrs' => array( 'data-auto-submit-button' => true ),
				)
			)
		);
	}

	/**
	 * The periods of the choice, with their words.
	 *
	 * @return array<int, string>
	 */
	private function periods(): array {
		return array(
			7  => __( 'Last 7 days', 'estasoft-news-distribution' ),
			30 => __( 'Last 30 days', 'estasoft-news-distribution' ),
			90 => __( 'Last 90 days', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * The address of this report with the period kept.
	 *
	 * @param array<string, scalar> $args Extra arguments.
	 * @return string
	 */
	private function here( array $args = array() ): string {
		return $this->url( array_merge( 7 === $this->days() ? array() : array( 'days' => $this->days() ), $args ) );
	}

	public function render(): string {
		$counter  = $this->plugin->make( Counter::class );
		$admin    = $this->plugin->access()->can( Access::ADMIN );
		$html     = Flash::take();
		$confirm  = $admin && $counter->rows() > 0 && Query::is( 'confirm', 'delete' );
		$counting = $counter->enabled();

		if ( $confirm ) {
			$html .= $this->confirm();
		}

		if ( ! $counting && 0 === $counter->rows() ) {
			return $html . $this->empty_state( $admin ) . $this->notes( $this->cannot() );
		}

		$figures = $this->data();

		if ( ! $counting ) {
			$html .= Message::bar(
				'warning',
				Html::tag( 'strong', array(), esc_html__( 'Counting is off.', 'estasoft-news-distribution' ) )
				. ' ' . esc_html__( 'These counts were made before you turned it off. No new visit is added.', 'estasoft-news-distribution' ),
				$admin ? $this->switch_form( true, __( 'Turn counting on', 'estasoft-news-distribution' ) ) : ''
			);
		}

		return $html
			. $this->lead()
			. $this->sources_card( $figures )
			. $this->articles_card( $figures )
			. $this->foot( $figures )
			. $this->notes( ( $admin && ! $confirm ? $this->manage( $counting ) : '' ) . $this->cannot() );
	}

	/**
	 * Note cards side by side when there is room, one under the other when there is not.
	 *
	 * @param string $cards Trusted HTML: one or more note cards.
	 * @return string
	 */
	private function notes( string $cards ): string {
		return Html::tag( 'div', array( 'class' => 'estasoft-nd-notes' ), $cards );
	}

	/**
	 * The board's note at the top: what is counted, and what is not.
	 *
	 * @return string
	 */
	private function lead(): string {
		return View::lead(
			'info',
			__( 'Counts only, no people.', 'estasoft-news-distribution' ),
			__( 'News Distribution counts visits by the site the reader came from. It stores no cookies and no personal data. Some platforms hide where a reader came from, so every number is a floor.', 'estasoft-news-distribution' )
		);
	}

	/**
	 * What the screen shows before anything is counted.
	 *
	 * @param bool $admin Whether the person may turn counting on.
	 * @return string
	 */
	private function empty_state( bool $admin ): string {
		return EmptyState::render(
			__( 'Visits by source are not counted yet', 'estasoft-news-distribution' ),
			sprintf(
				/* translators: %d: number of days the counts are kept. */
				__( 'Turn on counting to see which platforms send readers to your articles. News Distribution adds 1 to a count each time a reader arrives from another site, for the article and the day. It stores no cookies, no IP addresses and no visitor data, and keeps the counts for %d days.', 'estasoft-news-distribution' ),
				Counter::RETENTION_DAYS
			),
			$admin
				? $this->switch_form( true, __( 'Count visits by source', 'estasoft-news-distribution' ), true )
				: Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'An administrator can turn it on in Settings, Privacy and data.', 'estasoft-news-distribution' ) )
		);
	}

	/**
	 * The switch: a form that posts to admin-post.php, so it works without a script.
	 *
	 * @param bool   $on      The state the button sets.
	 * @param string $label   The button's words.
	 * @param bool   $primary Whether it is the one filled button of the page.
	 * @return string
	 */
	private function switch_form( bool $on, string $label, bool $primary = false ): string {
		return Forms::post(
			ReferralsModule::SWITCH_ACTION,
			ReferralsModule::NONCE,
			Button::submit( $label, $primary ? array( 'variant' => 'primary' ) : array() ),
			array( 'class' => 'estasoft-nd-inline-form' ),
			array( 'on' => $on ? '1' : '0' )
		);
	}

	/**
	 * The confirmation before the counts are deleted. Nothing is deleted until its button is pressed.
	 *
	 * @return string
	 */
	private function confirm(): string {
		$form = Forms::post(
			ReferralsModule::DELETE_ACTION,
			ReferralsModule::NONCE,
			Button::submit( __( 'Delete the counts', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
			. Button::link( __( 'Cancel', 'estasoft-news-distribution' ), $this->here() ),
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' )
		);

		return Message::bar(
			'warning',
			Html::tag( 'strong', array(), esc_html__( 'Delete every count?', 'estasoft-news-distribution' ) )
			. ' ' . esc_html__( 'This removes the counts of visits by source for all articles and cannot be undone. Turning counting off does not delete them.', 'estasoft-news-distribution' ),
			$form
		);
	}

	/**
	 * The card for turning counting off or deleting what was counted. Administrators only.
	 *
	 * @param bool $counting Whether counting is on.
	 * @return string
	 */
	private function manage( bool $counting ): string {
		$buttons = $counting ? $this->switch_form( false, __( 'Turn counting off', 'estasoft-news-distribution' ) ) : '';

		if ( $this->plugin->make( Counter::class )->rows() > 0 ) {
			$buttons .= Button::link( __( 'Delete the counts', 'estasoft-news-distribution' ), $this->here( array( 'confirm' => 'delete' ) ) );
		}

		return Card::note(
			'referrals-counting',
			__( 'Counting', 'estasoft-news-distribution' ),
			Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted' ),
				esc_html(
					sprintf(
						/* translators: %d: number of days the counts are kept. */
						__( 'Counts are kept for %d days, then deleted by the daily clean-up. Turning counting off keeps them until you delete them. You can also change this in Settings, Privacy and data.', 'estasoft-news-distribution' ),
						Counter::RETENTION_DAYS
					)
				)
			)
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $buttons )
		);
	}

	/**
	 * "Visits by source".
	 *
	 * @param array<string, mixed> $figures What `data()` returned.
	 * @return string
	 */
	private function sources_card( array $figures ): string {
		$days    = (int) $figures['days'];
		$compare = null !== $figures['previous'];
		$label   = $this->periods()[ $days ];
		$sources = (array) $figures['sources'];

		$subtitle = sprintf(
			/* translators: %s: a period such as "Last 7 days". */
			__( '%s. Each bar is that source’s share of all visits.', 'estasoft-news-distribution' ),
			$label
		);

		if ( array() === $sources ) {
			return Card::flush(
				'referrals-sources',
				__( 'Visits by source', 'estasoft-news-distribution' ),
				$subtitle,
				Html::tag(
					'p',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-card-pad' ),
					esc_html__( 'No visit has been counted in this period yet. A visit appears here when a reader opens an article.', 'estasoft-news-distribution' )
				)
			);
		}

		$headers = array(
			__( 'Source', 'estasoft-news-distribution' ),
			__( 'Visits', 'estasoft-news-distribution' ),
			__( 'Share of all visits', 'estasoft-news-distribution' ),
		);

		if ( $compare ) {
			$headers[] = sprintf(
				/* translators: %d: number of days. */
				_n( 'Change vs the %d day before', 'Change vs the %d days before', $days, 'estasoft-news-distribution' ),
				$days
			);
		}

		$rows = array();

		foreach ( $sources as $row ) {
			$cells = array(
				Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ), Html::tag( 'span', array( 'class' => 'estasoft-nd-strong' ), esc_html( (string) $row['label'] ) ) . ( '' === $row['detail'] ? '' : Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( (string) $row['detail'] ) ) ) ),
				esc_html( number_format_i18n( (int) $row['visits'] ) ),
				$this->share( (string) $row['label'], (int) $row['share'] ),
			);

			if ( $compare ) {
				$cells[] = $this->change( $row['change_percent'], $days );
			}

			$rows[] = $cells;
		}

		$total = array(
			Html::tag( 'span', array( 'class' => 'estasoft-nd-strong' ), esc_html__( 'All visits', 'estasoft-news-distribution' ) ),
			Html::tag( 'span', array( 'class' => 'estasoft-nd-strong' ), esc_html( number_format_i18n( (int) $figures['total'] ) ) ),
			Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Shares are rounded', 'estasoft-news-distribution' ) ),
		);

		if ( $compare ) {
			$total[] = '';
		}

		$rows[] = $total;

		$note = '';

		if ( ! $compare ) {
			$note = Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-card-pad' ),
				esc_html(
					$days < Counter::RETENTION_DAYS
						? __( 'The change against the period before appears once the counts cover both periods.', 'estasoft-news-distribution' )
						: sprintf(
							/* translators: %d: number of days the counts are kept. */
							__( 'Counts are kept for %d days, so there is nothing earlier to compare this period with.', 'estasoft-news-distribution' ),
							Counter::RETENTION_DAYS
						)
				)
			);
		}

		return Card::flush(
			'referrals-sources',
			__( 'Visits by source', 'estasoft-news-distribution' ),
			$subtitle,
			Grid::render( $compare ? 'estasoft-nd-cols-referrals' : 'estasoft-nd-cols-referrals-plain', $headers, $rows, __( 'Visits by source', 'estasoft-news-distribution' ) ) . $note
		);
	}

	/**
	 * A source's share: a bar and the rounded percentage.
	 *
	 * @param string $label   The source's name, for screen readers.
	 * @param int    $percent The share, 0 to 100.
	 * @return string
	 */
	private function share( string $label, int $percent ): string {
		$percent = max( 0, min( 100, $percent ) );

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-referrals-share' ),
			Html::tag(
				'span',
				array(
					'class'         => 'estasoft-nd-progress',
					'role'          => 'progressbar',
					'aria-valuemin' => '0',
					'aria-valuemax' => '100',
					'aria-valuenow' => (string) $percent,
					'aria-label'    => sprintf(
						/* translators: 1: a source such as "Google News", 2: a percentage such as 22. */
						__( '%1$s: %2$s%% of all visits', 'estasoft-news-distribution' ),
						$label,
						number_format_i18n( $percent )
					),
				),
				Html::tag( 'span', array( 'style' => 'width:' . $percent . '%' ), '' )
			)
			. Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-referrals-percent' ),
				esc_html(
					sprintf(
						/* translators: %s: a percentage number such as 22. */
						__( '%s%%', 'estasoft-news-distribution' ),
						number_format_i18n( $percent )
					)
				)
			)
		);
	}

	/**
	 * The change against the period before: a sign and a number, or a dash when there is nothing to compare.
	 *
	 * @param mixed $percent The change in percent, or null.
	 * @param int   $days    The length of the period, for the words a phone shows in place of the column heading.
	 * @return string
	 */
	private function change( $percent, int $days ): string {
		if ( ! is_int( $percent ) ) {
			return Html::tag( 'span', array( 'aria-hidden' => 'true' ), '—' )
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-sr' ), esc_html__( 'No visits in the period before to compare with', 'estasoft-news-distribution' ) )
				. $this->versus( $days );
		}

		if ( $percent > 0 ) {
			$class = 'estasoft-nd-change estasoft-nd-change--up';
			$text  = '+' . number_format_i18n( $percent );
		} elseif ( $percent < 0 ) {
			$class = 'estasoft-nd-change estasoft-nd-change--down';
			$text  = '−' . number_format_i18n( abs( $percent ) );
		} else {
			$class = 'estasoft-nd-change';
			$text  = number_format_i18n( 0 );
		}

		return Html::tag(
			'span',
			array( 'class' => $class ),
			esc_html(
				sprintf(
					/* translators: %s: a signed number such as +12 or −4. */
					__( '%s%%', 'estasoft-news-distribution' ),
					$text
				)
			)
		) . $this->versus( $days );
	}

	/**
	 * The words that stand for the column heading on a phone, where the heading row is hidden.
	 *
	 * @param int $days The length of the period.
	 * @return string
	 */
	private function versus( int $days ): string {
		return Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-referrals-vs' ),
			esc_html(
				sprintf(
					/* translators: %d: number of days. */
					_n( 'vs the %d day before', 'vs the %d days before', $days, 'estasoft-news-distribution' ),
					$days
				)
			)
		);
	}

	/**
	 * "Top articles by source".
	 *
	 * @param array<string, mixed> $figures What `data()` returned.
	 * @return string
	 */
	private function articles_card( array $figures ): string {
		$days     = (int) $figures['days'];
		$articles = (array) $figures['articles'];
		$columns  = (array) $articles['sources'];
		$subtitle = sprintf(
			/* translators: %d: number of days. */
			_n( 'Visits in the last %d day.', 'Visits in the last %d days.', $days, 'estasoft-news-distribution' ),
			$days
		);

		if ( array() === $columns || array() === (array) $articles['rows'] ) {
			return Card::flush(
				'referrals-articles',
				__( 'Top articles by source', 'estasoft-news-distribution' ),
				$subtitle,
				Html::tag(
					'p',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-card-pad' ),
					esc_html__( 'No platform has sent readers to an article in this period yet.', 'estasoft-news-distribution' )
				)
			);
		}

		$headers = array( __( 'Article', 'estasoft-news-distribution' ) );

		foreach ( $columns as $column ) {
			$headers[] = (string) $column['label'];
		}

		$rows = array();

		foreach ( (array) $articles['rows'] as $row ) {
			$title = (string) $row['title'];
			$cells = array( Html::tag( 'span', array_merge( array( 'class' => 'estasoft-nd-strong' ), View::text_attributes( $title ) ), esc_html( '' === $title ? __( '(no title)', 'estasoft-news-distribution' ) : $title ) ) );

			foreach ( $columns as $column ) {
				$cells[] = Html::tag( 'span', array( 'class' => 'estasoft-nd-referrals-label' ), esc_html( (string) $column['label'] ) )
					. Html::tag( 'span', array(), esc_html( number_format_i18n( (int) ( $row['visits'][ $column['id'] ] ?? 0 ) ) ) );
			}

			$rows[] = $cells;
		}

		return Card::flush(
			'referrals-articles',
			__( 'Top articles by source', 'estasoft-news-distribution' ),
			$subtitle,
			Grid::render( 'estasoft-nd-cols-referrals-articles-' . count( $columns ), $headers, $rows, __( 'Top articles by source', 'estasoft-news-distribution' ) )
		);
	}

	/**
	 * The line under the tables: platforms that are in review and so not listed, and the rule for what is listed.
	 *
	 * @param array<string, mixed> $figures What `data()` returned.
	 * @return string
	 */
	private function foot( array $figures ): string {
		$names = (array) $figures['in_review'];
		$text  = '';

		if ( array() !== $names ) {
			$text = sprintf(
				/* translators: %s: platform names such as "Apple News and SmartNews". */
				_n( '%s is in review and sends no visits yet, so it is not listed.', '%s are in review and send no visits yet, so they are not listed.', count( $names ), 'estasoft-news-distribution' ),
				wp_sprintf( '%l', $names )
			) . ' ';
		}

		$text .= __( 'A platform appears here once it sends readers.', 'estasoft-news-distribution' );

		return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $text ) );
	}

	/**
	 * "What it cannot do".
	 *
	 * @return string
	 */
	private function cannot(): string {
		return Card::cannot(
			array(
				__( 'Count every reader. A visit is counted only when the page’s script runs and the browser lets it send one, so ad blockers and privacy settings can hide visits.', 'estasoft-news-distribution' ),
				__( 'Tell a person from a program. Counts include some bots, and a reload of the page can count again.', 'estasoft-news-distribution' ),
				__( 'Prove a count is genuine. Anyone can send a count to your site, so read the numbers as an indication.', 'estasoft-news-distribution' ),
				__( 'Say where a reader came from when the platform hides it. Those visits are counted as direct, or as other sites.', 'estasoft-news-distribution' ),
				__( 'Count a page that a page cache served before you turned counting on, until the cache is cleared.', 'estasoft-news-distribution' ),
			)
		);
	}
}

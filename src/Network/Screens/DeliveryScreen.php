<?php
/**
 * Network Admin, Delivery health: queue and delivery counts across every site.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Network\Screens;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\CommandBar;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\PageHeader;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Channels\SearchSitemap\FetchSummary;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Network\Analytics;
use Estasoft\NewsDistribution\Network\NetworkAdmin;
use Estasoft\NewsDistribution\Network\SiteLoop;
use Estasoft\NewsDistribution\Network\SiteSummaries;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Read only. A period (today, 7 days, 30 days) and one site or all of them choose what the numbers
 * cover; both are links or a plain form, so the screen works without script. It never retries or
 * changes a delivery: each site's own delivery log does that, and every warning links to it.
 */
final class DeliveryScreen extends AbstractScreen {

	public function tab(): string {
		return 'delivery';
	}

	public function title(): string {
		return __( 'Delivery health', 'estasoft-news-distribution' );
	}

	public function enqueue(): void {
		Assets::screen_script( 'network' );
	}

	public function render(): string {
		$period = $this->period();
		$site   = $this->site();
		$data   = ( new Analytics() )->build( $period, $site, Query::has( 'refresh' ) );

		if ( $site > 0 && array() === $data['sites'] ) {
			$site = 0;
			$data = ( new Analytics() )->build( $period, 0 );
		}

		return PageHeader::render(
			__( 'Delivery health', 'estasoft-news-distribution' ),
			__( 'Queue and delivery health across every site in your network.', 'estasoft-news-distribution' )
		)
			. $this->take_flash()
			. $this->controls( $data, $period, $site )
			. $this->warnings( $data )
			. $this->table( $data, $site )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-notes' ),
				$this->channels_card( $data, $site )
				. Card::cannot(
					array(
						__( 'Retry or fix a failed delivery. Open that site’s delivery log to do it.', 'estasoft-news-distribution' ),
						__( 'Show what a platform did with a delivery. It counts what News Distribution sent and how long each delivery took.', 'estasoft-news-distribution' ),
					)
				)
			);
	}

	/**
	 * The period, the site and the line that says what the numbers cover.
	 *
	 * @param array<string, mixed> $data   The answer.
	 * @param string               $period Period.
	 * @param int                  $site   Chosen site, or 0.
	 * @return string
	 */
	private function controls( array $data, string $period, int $site ): string {
		$items = array(
			'today' => array(
				'label' => __( 'Today', 'estasoft-news-distribution' ),
				'url'   => $this->url( $this->args( 'today', $site ) ),
			),
			'7d'    => array(
				'label' => __( '7 days', 'estasoft-news-distribution' ),
				'url'   => $this->url( $this->args( '7d', $site ) ),
			),
			'30d'   => array(
				'label' => __( '30 days', 'estasoft-news-distribution' ),
				'url'   => $this->url( $this->args( '30d', $site ) ),
			),
		);

		$options = Html::tag( 'option', array( 'value' => '0' ), esc_html__( 'All sites', 'estasoft-news-distribution' ) );

		foreach ( $this->site_names() as $id => $name ) {
			$options .= Html::tag(
				'option',
				array(
					'value'    => (string) $id,
					'selected' => (int) $id === $site,
				),
				esc_html( $name )
			);
		}

		$segments = Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), CommandBar::segments( $items, $period, __( 'Period', 'estasoft-news-distribution' ) ) );
		$picker   = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-label',
					'for'   => 'estasoft-nd-net-site',
				),
				esc_html__( 'Site', 'estasoft-news-distribution' )
			)
			. Html::tag(
				'select',
				array(
					'class'            => 'estasoft-nd-field',
					'id'               => 'estasoft-nd-net-site',
					'name'             => 'site',
					'data-auto-submit' => true,
				),
				$options
			)
		);

		$since = (int) strtotime( (string) $data['since'] );
		$until = (int) strtotime( (string) $data['generated'] );
		$text  = 'today' === $period
			? sprintf(
				/* translators: 1: the date, such as "21 Oct", 2: the time the numbers were read, such as "14:35". */
				__( 'Showing today, %1$s, up to %2$s. The numbers below follow the period you choose.', 'estasoft-news-distribution' ),
				Clock::format( $until, 'j M' ),
				Clock::format( $until, 'H:i' )
			)
			: sprintf(
				/* translators: 1: the first date, such as "14 Oct", 2: the last date and time, such as "21 Oct, 14:35". */
				__( 'Showing %1$s to %2$s. The numbers below follow the period you choose.', 'estasoft-news-distribution' ),
				Clock::format( $since, 'j M' ),
				Clock::format( $until, 'j M, H:i' )
			);

		$text .= ' ' . sprintf(
			/* translators: %s: how long ago the sites were read, such as "3 min ago". */
			__( 'Updated %s.', 'estasoft-news-distribution' ),
			FetchSummary::ago( $until )
		);

		if ( (int) $data['left'] > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: %d: the most sites one reading covers. */
				__( 'A reading covers the first %d sites, so some sites are not counted.', 'estasoft-news-distribution' ),
				SiteLoop::MAX_SITES
			);
		}

		$form = Html::tag(
			'form',
			array(
				'class'  => 'estasoft-nd-commandbar',
				'method' => 'get',
				'action' => network_admin_url( 'admin.php' ),
			),
			$this->hidden( 'page', NetworkAdmin::PAGE )
			. $this->hidden( 'tab', 'delivery' )
			. $this->hidden( 'period', $period )
			. $segments
			. $picker
			. Html::tag(
				'div',
				array(
					'class'                   => 'estasoft-nd-cluster',
					'data-auto-submit-button' => true,
				),
				Button::submit( __( 'Show', 'estasoft-news-distribution' ) )
			)
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-net-showing' ), esc_html( $text ) )
		);

		return $form;
	}

	/**
	 * One warning for each site with failed deliveries.
	 *
	 * @param array<string, mixed> $data The answer.
	 * @return string
	 */
	private function warnings( array $data ): string {
		$html = '';

		foreach ( (array) $data['attention'] as $row ) {
			$html .= Message::bar(
				'warning',
				Html::tag(
					'strong',
					array(),
					esc_html(
						sprintf(
							/* translators: %s: a site's name. */
							__( '%s needs attention.', 'estasoft-news-distribution' ),
							(string) $row['name']
						)
					)
				)
				. ' '
				. esc_html(
					sprintf(
						/* translators: 1: number of deliveries, 2: the number of attempts after which a delivery counts as failed. */
						_n( '%1$d delivery failed: News Distribution gave up after %2$d attempts each.', '%1$d deliveries failed: News Distribution gave up after %2$d attempts each.', (int) $row['failed'], 'estasoft-news-distribution' ),
						(int) $row['failed'],
						Analytics::attempts()
					)
				),
				Button::link(
					sprintf(
						/* translators: %s: a site's name. */
						__( 'Open the %s delivery log', 'estasoft-news-distribution' ),
						(string) $row['name']
					),
					(string) $row['log_url']
				)
			);
		}

		return $html;
	}

	/**
	 * The table: a row for each site.
	 *
	 * @param array<string, mixed> $data The answer.
	 * @param int                  $site Chosen site, or 0.
	 * @return string
	 */
	private function table( array $data, int $site ): string {
		$cols = 'estasoft-nd-cols-net-delivery';
		$head = Html::tag(
			'div',
			array(
				'class' => Html::classes( 'estasoft-nd-grid-row', 'estasoft-nd-grid-row--head', $cols ),
				'role'  => 'row',
			),
			$this->head( __( 'Site', 'estasoft-news-distribution' ) )
			. $this->head( __( 'Deliveries', 'estasoft-news-distribution' ) )
			. $this->head( __( 'Queued', 'estasoft-news-distribution' ) )
			. $this->head( __( 'Sent', 'estasoft-news-distribution' ) )
			. $this->head( __( 'Failed', 'estasoft-news-distribution' ) )
			. $this->head( __( 'Slowest delivery', 'estasoft-news-distribution' ) )
		);

		$rows = '';

		foreach ( (array) $data['sites'] as $row ) {
			$chips = '';

			foreach ( (array) $row['languages'] as $code ) {
				$chips .= Tag::lang( (string) $code );
			}

			$name = Html::tag( 'span', array( 'class' => 'estasoft-nd-strong' ), esc_html( (string) $row['name'] ) )
				. ( '' === $chips ? '' : Html::tag( 'span', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-4 estasoft-nd-net-chips' ), $chips ) );

			$rows .= Html::tag(
				'div',
				array(
					'class' => Html::classes( 'estasoft-nd-grid-row', $cols ),
					'role'  => 'row',
				),
				$this->cell( Html::tag( 'span', array( 'class' => 'estasoft-nd-net-site' ), $name ) )
				. $this->cell( $this->pill( $row ) )
				. $this->cell( $this->number( $row['queued'] ) )
				. $this->cell( $this->number( $row['sent'] ) )
				. $this->cell( $this->failed( $row['failed'] ) )
				. $this->cell( $this->seconds( $row['slowest'] ) )
			);
		}

		if ( '' === $rows ) {
			$rows = Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-net-empty' ), esc_html__( 'This network has no sites yet.', 'estasoft-news-distribution' ) );
		}

		$totals = (array) $data['totals'];
		$scope  = $this->scope_label( $data, $site );
		$foot   = sprintf(
			/* translators: 1: "All sites" or a site's name, 2: queued, 3: sent, 4: failed, 5: the number of seconds after which a delivery counts as slow. */
			__( '%1$s: %2$s queued, %3$s sent, %4$s failed. Slow means the slowest delivery took over %5$d seconds.', 'estasoft-news-distribution' ),
			$scope,
			number_format_i18n( (int) $totals['queued'] ),
			number_format_i18n( (int) $totals['sent'] ),
			number_format_i18n( (int) $totals['failed'] ),
			(int) $data['slow_seconds']
		) . ' ' . __( 'Queued is what waits now, whatever the period.', 'estasoft-news-distribution' );

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-card estasoft-nd-card--flush estasoft-nd-scroll' ),
			Html::tag(
				'div',
				array(
					'class'      => 'estasoft-nd-table',
					'role'       => 'table',
					'aria-label' => __( 'Deliveries by site', 'estasoft-news-distribution' ),
				),
				$head . $rows . Html::tag( 'div', array( 'class' => 'estasoft-nd-table-footer' ), esc_html( $foot ) )
			)
		);
	}

	/**
	 * The Deliveries pill of a row. "Slow" has its own word and the attention look.
	 *
	 * @param array<string, mixed> $row The row.
	 * @return string
	 */
	private function pill( array $row ): string {
		if ( ! empty( $row['slow'] ) ) {
			return StatusView::pill( Status::ATTENTION, __( 'Slow', 'estasoft-news-distribution' ) );
		}

		return StatusView::pill( (string) $row['status'] );
	}

	/**
	 * Bars of what was sent on each platform.
	 *
	 * @param array<string, mixed> $data The answer.
	 * @param int                  $site Chosen site, or 0.
	 * @return string
	 */
	private function channels_card( array $data, int $site ): string {
		$channels = (array) $data['channels'];
		$top      = 0;

		foreach ( $channels as $row ) {
			$top = max( $top, (int) $row['sent'] );
		}

		$list = '';

		foreach ( $channels as $row ) {
			$list .= Html::tag(
				'li',
				array( 'class' => 'estasoft-nd-net-bar' ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-net-bar__name estasoft-nd-strong' ), esc_html( (string) $row['label'] ) )
				. Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-net-bar__count estasoft-nd-muted' ),
					esc_html(
						sprintf(
							/* translators: %s: number sent. */
							__( '%s sent', 'estasoft-news-distribution' ),
							number_format_i18n( (int) $row['sent'] )
						)
					)
				)
				. Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-net-bar__failed' ),
					(int) $row['failed'] > 0
						? StatusView::inline(
							Status::FAILED,
							sprintf(
								/* translators: %s: number failed. */
								__( '%s failed', 'estasoft-news-distribution' ),
								number_format_i18n( (int) $row['failed'] )
							)
						)
						: Html::tag(
							'span',
							array( 'class' => 'estasoft-nd-muted' ),
							esc_html(
								sprintf(
									/* translators: %s: number failed. */
									__( '%s failed', 'estasoft-news-distribution' ),
									number_format_i18n( 0 )
								)
							)
						)
				)
				. Html::tag(
					'progress',
					array(
						'class'      => 'estasoft-nd-net-bar__track',
						'max'        => (string) max( 1, $top ),
						'value'      => (string) (int) $row['sent'],
						'aria-label' => sprintf(
							/* translators: 1: a platform's name, 2: number sent. */
							__( '%1$s: %2$s sent', 'estasoft-news-distribution' ),
							(string) $row['label'],
							number_format_i18n( (int) $row['sent'] )
						),
					),
					''
				)
			);
		}

		$words = array() === $channels
			? __( 'Nothing was sent in this period.', 'estasoft-news-distribution' )
			: sprintf(
				/* translators: 1: "All sites" or a site's name, 2: the busiest platform. */
				__( '%1$s, same period. Bar length shows how many were sent, compared with %2$s, the busiest.', 'estasoft-news-distribution' ),
				$this->scope_label( $data, $site ),
				(string) $channels[0]['label']
			);

		$sent   = 0;
		$failed = 0;

		foreach ( $channels as $row ) {
			$sent   += (int) $row['sent'];
			$failed += (int) $row['failed'];
		}

		$body = Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $words ) )
			. ( '' === $list ? '' : Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12 estasoft-nd-net-bars' ), $list ) );

		if ( array() !== $channels ) {
			$body .= Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: 1: number sent, 2: number failed. */
						__( 'Total: %1$s sent, %2$s failed, the same as the table above.', 'estasoft-news-distribution' ),
						number_format_i18n( $sent ),
						number_format_i18n( $failed )
					)
				)
			);
		}

		return Card::note( 'pushes-by-channel', __( 'Pushes by channel', 'estasoft-news-distribution' ), $body );
	}

	/* ---------------------------------------------------------------- Helpers. */

	/**
	 * The period from the address.
	 *
	 * @return string
	 */
	private function period(): string {
		$period = Query::key( 'period', 'today' );

		return in_array( $period, Analytics::PERIODS, true ) ? $period : 'today';
	}

	/**
	 * The chosen site from the address, or 0.
	 *
	 * @return int
	 */
	private function site(): int {
		return Query::int( 'site' );
	}

	/**
	 * Query arguments for a period link that keeps the site.
	 *
	 * @param string $period Period.
	 * @param int    $site   Chosen site, or 0.
	 * @return array<string, scalar>
	 */
	private function args( string $period, int $site ): array {
		$args = array( 'period' => $period );

		if ( $site > 0 ) {
			$args['site'] = $site;
		}

		return $args;
	}

	/**
	 * Every site's name by ID, for the site picker, from the kept reading.
	 *
	 * @return array<int, string>
	 */
	private function site_names(): array {
		$names = array();

		foreach ( ( new SiteSummaries() )->all()['rows'] as $row ) {
			$names[ (int) $row['id'] ] = (string) $row['name'];
		}

		return $names;
	}

	/**
	 * "All sites", or the chosen site's name.
	 *
	 * @param array<string, mixed> $data The answer.
	 * @param int                  $site Chosen site, or 0.
	 * @return string
	 */
	private function scope_label( array $data, int $site ): string {
		if ( $site > 0 && isset( $data['sites'][0]['name'] ) ) {
			return (string) $data['sites'][0]['name'];
		}

		return __( 'All sites', 'estasoft-news-distribution' );
	}

	/**
	 * @param string $name  Name of a hidden input.
	 * @param string $value Its value.
	 * @return string
	 */
	private function hidden( string $name, string $value ): string {
		return Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => $name,
				'value' => $value,
			)
		);
	}

	/**
	 * @param string $text Plain heading.
	 * @return string
	 */
	private function head( string $text ): string {
		return Html::tag( 'div', array( 'role' => 'columnheader' ), esc_html( $text ) );
	}

	/**
	 * @param string $html Trusted HTML.
	 * @return string
	 */
	private function cell( string $html ): string {
		return Html::tag( 'div', array( 'role' => 'cell' ), $html );
	}

	/**
	 * The failed count: with the failed icon when there is any, so the colour never carries it alone.
	 *
	 * @param int|null $value The count.
	 * @return string
	 */
	private function failed( $value ): string {
		if ( null === $value || (int) $value < 1 ) {
			return $this->number( $value );
		}

		return StatusView::inline( Status::FAILED, number_format_i18n( (int) $value ) );
	}

	/**
	 * A count, or a dash for a site that has nothing to count.
	 *
	 * @param int|null $value The count.
	 * @return string
	 */
	private function number( $value ): string {
		return null === $value ? $this->dash() : esc_html( number_format_i18n( (int) $value ) );
	}

	/**
	 * The slowest delivery in seconds, or a dash.
	 *
	 * @param int|null $value Seconds.
	 * @return string
	 */
	private function seconds( $value ): string {
		if ( null === $value ) {
			return $this->dash();
		}

		return esc_html(
			sprintf(
				/* translators: %s: a number of seconds. */
				__( '%s s', 'estasoft-news-distribution' ),
				number_format_i18n( (int) $value )
			)
		);
	}

	/**
	 * @return string
	 */
	private function dash(): string {
		return Html::tag(
			'span',
			array(
				'class'      => 'estasoft-nd-muted',
				'aria-label' => __( 'None', 'estasoft-news-distribution' ),
			),
			'—'
		);
	}
}

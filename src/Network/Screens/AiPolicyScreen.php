<?php
/**
 * Network Admin, AI policy: the rules sites cannot loosen.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Network\Screens;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\PageHeader;
use Estasoft\NewsDistribution\Admin\Ui\SaveBar;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Network\AiExport;
use Estasoft\NewsDistribution\Network\AiOverview;
use Estasoft\NewsDistribution\Network\AiPolicy;
use Estasoft\NewsDistribution\Network\NetworkAdmin;
use Estasoft\NewsDistribution\Network\SiteAi;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * One form, one Save changes. A value that fails its check keeps what the person typed and shows
 * its message under the field; nothing is saved until every value is good. The audit-log export
 * is a link, not part of the form, so it never saves or loses unsaved changes.
 */
final class AiPolicyScreen extends AbstractScreen {

	public const SAVE   = 'estasoft_nd_net_policy';
	public const EXPORT = 'estasoft_nd_net_export';
	public const FIELD  = 'policy';

	/**
	 * The fields the form sends.
	 */
	private const FIELDS = array( 'providers', 'drafts', 'approve_translations', 'second_approver_role', 'network_tokens_month', 'max_tokens_month', 'alert_at', 'max_log_months' );

	public function tab(): string {
		return 'ai-policy';
	}

	public function title(): string {
		return __( 'AI policy', 'estasoft-news-distribution' );
	}

	public function enqueue(): void {
		Assets::screen_script( 'dirty-form' );
		Assets::screen_script( 'network' );
	}

	public function actions(): array {
		return array(
			self::SAVE   => 'post_save',
			self::EXPORT => 'get_export',
		);
	}

	/**
	 * The tab: the form with its cards, then the table of sites.
	 *
	 * @return string
	 */
	public function render(): string {
		$data   = AiOverview::build( Query::has( 'refresh' ) );
		$draft  = $this->take_draft();
		$values = $this->values( $data['policy'], $draft['input'] );
		$errors = $draft['errors'];

		$cards = $this->providers_card( $data, $values, $errors )
			. $this->data_card( $values )
			. $this->approvals_card( $values, $errors )
			. $this->budgets_card( $data, $values, $errors )
			. $this->audit_card( $values, $errors );

		$form = Html::tag(
			'form',
			array(
				'class'           => 'estasoft-nd-stack estasoft-nd-gap-16',
				'method'          => 'post',
				'action'          => $this->post_url( self::SAVE ),
				'novalidate'      => true,
				'data-dirty-form' => true,
				'aria-label'      => __( 'AI policy', 'estasoft-news-distribution' ),
			),
			$this->nonce( self::SAVE ) . $cards . $this->sites_card( $data ) . $this->notes() . SaveBar::render()
		);

		return PageHeader::render(
			__( 'AI policy', 'estasoft-news-distribution' ),
			__( 'Decide how AI may be used across your network.', 'estasoft-news-distribution' )
		) . $this->take_flash() . $this->used_up( $data ) . $form;
	}

	/**
	 * The message shown while the network's monthly budget is used: the assistant is paused on every site.
	 *
	 * @param array<string, mixed> $data The overview.
	 * @return string
	 */
	private function used_up( array $data ): string {
		if ( empty( $data['usage']['exhausted'] ) ) {
			return '';
		}

		return Message::bar(
			'warning',
			Html::tag( 'strong', array(), esc_html__( 'The network’s monthly AI budget is used.', 'estasoft-news-distribution' ) )
			. ' ' . esc_html(
				sprintf(
					/* translators: %s: the date the count starts again, such as "1 Nov 2026". */
					__( 'The assistant is paused on every site until %s. Nothing else is affected. To let it keep working this month, raise the network budget below.', 'estasoft-news-distribution' ),
					wp_date( 'j M Y', (int) strtotime( (string) $data['usage']['resets'] ), new \DateTimeZone( 'UTC' ) )
				)
			)
		);
	}

	/**
	 * Providers allowed, and what a site needs to use any.
	 *
	 * @param array<string, mixed>  $data   The overview.
	 * @param array<string, mixed>  $values Values to show.
	 * @param array<string, string> $errors Messages by field.
	 * @return string
	 */
	private function providers_card( array $data, array $values, array $errors ): string {
		$boxes = Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => $this->name( 'providers', true ),
				'value' => '',
			)
		);

		foreach ( $data['provider_options'] as $option ) {
			$boxes .= Html::tag(
				'label',
				array( 'class' => 'estasoft-nd-check' ),
				Html::void(
					'input',
					array(
						'type'    => 'checkbox',
						'name'    => $this->name( 'providers', true ),
						'value'   => (string) $option['id'],
						'checked' => in_array( (string) $option['id'], (array) $values['providers'], true ),
					)
				) . Html::tag( 'strong', array(), esc_html( (string) $option['label'] ) )
			);
		}

		$qualifying = $data['qualifying'];
		$needs      = sprintf(
			/* translators: 1: the lowest WordPress version, 2: sites that qualify, 3: sites in the network. */
			__( 'Every site on WordPress %1$s or newer. %2$d of %3$d sites qualify today.', 'estasoft-news-distribution' ),
			(string) $data['wordpress']['minimum'],
			(int) $qualifying['sites'],
			(int) $qualifying['total']
		);

		if ( ! $data['wordpress']['ok'] ) {
			$needs .= ' ' . sprintf(
				/* translators: %s: the WordPress version the network runs. */
				__( 'This network runs WordPress %s.', 'estasoft-news-distribution' ),
				(string) $data['wordpress']['version']
			);
		} elseif ( array() !== $qualifying['names_not'] ) {
			$needs .= ' ' . sprintf(
				/* translators: %s: names of sites that do not have News Distribution switched on, such as "Example Local and Example Weekend". */
				__( 'Not yet: %s.', 'estasoft-news-distribution' ),
				wp_sprintf_l( '%l', array_map( 'strval', $qualifying['names_not'] ) )
			);
		}

		return Card::flush(
			'providers',
			__( 'Providers allowed', 'estasoft-news-distribution' ),
			__( 'Site owners can choose only from these. Each connects through WordPress on their own site.', 'estasoft-news-distribution' ),
			$this->row(
				'estasoft-nd-net-providers',
				__( 'Allowed', 'estasoft-news-distribution' ),
				Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $boxes ) . $this->error( 'providers', $errors ),
				true
			)
			. $this->row( 'estasoft-nd-net-needs', __( 'Needs', 'estasoft-news-distribution' ), Html::tag( 'p', array(), esc_html( $needs ) ), true )
		);
	}

	/**
	 * What may be sent to a provider.
	 *
	 * @param array<string, mixed> $values Values to show.
	 * @return string
	 */
	private function data_card( array $values ): string {
		$authors = Html::tag(
			'label',
			array( 'class' => 'estasoft-nd-check estasoft-nd-check--top' ),
			Html::void(
				'input',
				array(
					'type'     => 'checkbox',
					'checked'  => true,
					'disabled' => true,
				)
			)
			. Html::tag( 'span', array(), Html::tag( 'strong', array(), esc_html__( 'Author names', 'estasoft-news-distribution' ) ) )
		) . $this->locked( __( 'Locked on for the whole network. News Distribution adds bylines back after the AI has finished.', 'estasoft-news-distribution' ) );

		return Card::flush(
			'data',
			__( 'Data', 'estasoft-news-distribution' ),
			__( 'Rules that sites cannot loosen.', 'estasoft-news-distribution' ),
			$this->row( 'estasoft-nd-net-authors', __( 'Never send', 'estasoft-news-distribution' ), $authors, true )
			. $this->row(
				'estasoft-nd-net-drafts',
				__( 'Drafts and scheduled posts', 'estasoft-news-distribution' ),
				$this->checkbox(
					'drafts',
					(bool) $values['drafts'],
					__( 'Sites may send them to their AI provider', 'estasoft-news-distribution' ),
					__( 'Each site decides in its own Settings, AI. The default there is Off, because a draft may hold an embargoed story.', 'estasoft-news-distribution' )
				),
				true
			)
		);
	}

	/**
	 * Who must sign off.
	 *
	 * @param array<string, mixed>  $values Values to show.
	 * @param array<string, string> $errors Messages by field.
	 * @return string
	 */
	private function approvals_card( array $values, array $errors ): string {
		$role = $this->select(
			'second_approver_role',
			array(
				'editors' => __( 'Editors and above', 'estasoft-news-distribution' ),
				'admins'  => __( 'Administrators only', 'estasoft-news-distribution' ),
			),
			(string) $values['second_approver_role']
		);

		return Card::flush(
			'approvals',
			__( 'Approvals', 'estasoft-news-distribution' ),
			__( 'Who must sign off before AI text can go live.', 'estasoft-news-distribution' ),
			$this->row(
				'estasoft-nd-net-translations',
				__( 'Translations', 'estasoft-news-distribution' ),
				$this->checkbox(
					'approve_translations',
					(bool) $values['approve_translations'],
					__( 'A person approves every AI translation before it is published', 'estasoft-news-distribution' ),
					__( 'That is the signed-off reviewer for the language. A language with no signed-off reviewer needs a second approver instead.', 'estasoft-news-distribution' )
				),
				true
			)
			. $this->row(
				'estasoft-nd-net-second',
				__( 'Second approver', 'estasoft-news-distribution' ),
				$role . $this->error( 'second_approver_role', $errors ) . $this->help( __( 'Needed for a language that has no signed-off reviewer.', 'estasoft-news-distribution' ) ),
				false,
				'estasoft-nd-f-net-second_approver_role'
			)
			. $this->row(
				'estasoft-nd-net-mark',
				__( 'AI-assisted mark', 'estasoft-news-distribution' ),
				$this->locked( __( 'Always on. Sites cannot turn it off.', 'estasoft-news-distribution' ) )
				. Html::tag( 'p', array(), esc_html__( 'Articles with AI-written text are marked AI-assisted automatically, and a person can remove the mark only by editing the article’s content label. The reviewer’s name is recorded.', 'estasoft-news-distribution' ) ),
				true
			)
		);
	}

	/**
	 * The token limits and when to warn.
	 *
	 * @param array<string, mixed>  $data   The overview.
	 * @param array<string, mixed>  $values Values to show.
	 * @param array<string, string> $errors Messages by field.
	 * @return string
	 */
	private function budgets_card( array $data, array $values, array $errors ): string {
		return Card::flush(
			'budgets',
			__( 'Budgets', 'estasoft-news-distribution' ),
			__( 'News Distribution counts tokens against these limits. Each site also sets its own requests a day. Your providers bill each site.', 'estasoft-news-distribution' ),
			$this->row(
				'estasoft-nd-net-network',
				__( 'Network', 'estasoft-news-distribution' ),
				$this->number_field( 'network_tokens_month', $values['network_tokens_month'], $errors )
				. $this->help(
					sprintf(
						/* translators: %s: tokens used so far this month, such as "2,767,000". */
						__( 'Tokens a month, across all sites. %s used so far this month. 0 means no limit.', 'estasoft-news-distribution' ),
						number_format_i18n( (int) $data['usage']['tokens_month'] )
					)
				)
				. $this->month_figures( $data ),
				false,
				'estasoft-nd-f-net-network_tokens_month'
			)
			. $this->row(
				'estasoft-nd-net-each',
				__( 'Each site', 'estasoft-news-distribution' ),
				$this->number_field( 'max_tokens_month', $values['max_tokens_month'], $errors )
				. $this->help( __( 'Tokens a month. A site owner can lower this, not raise it.', 'estasoft-news-distribution' ) ),
				false,
				'estasoft-nd-f-net-max_tokens_month'
			)
			. $this->row(
				'estasoft-nd-net-alert',
				__( 'Alert at', 'estasoft-news-distribution' ),
				$this->select(
					'alert_at',
					array(
						'80' => __( '80% of a limit', 'estasoft-news-distribution' ),
						'90' => __( '90% of a limit', 'estasoft-news-distribution' ),
					),
					(string) (int) $values['alert_at']
				)
				. $this->error( 'alert_at', $errors )
				. $this->help( __( 'A site limit alerts that site’s alert recipients. The network limit alerts the network administrators by email.', 'estasoft-news-distribution' ) )
				. $this->help( $this->alert_address() ),
				false,
				'estasoft-nd-f-net-alert_at'
			)
		);
	}

	/**
	 * This month's use against the network limit: a bar and a line, then what was done about the alert emails.
	 *
	 * @param array<string, mixed> $data The overview.
	 * @return string
	 */
	private function month_figures( array $data ): string {
		$usage  = (array) $data['usage'];
		$used   = (int) $usage['tokens_month'];
		$limit  = (int) $usage['limit'];
		$month  = wp_date( 'F Y', (int) strtotime( $usage['month'] . '-01 00:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
		$bar    = '';
		$alerts = '';

		if ( $limit > 0 ) {
			$text = sprintf(
				/* translators: 1: a month and year such as "October 2026", 2: tokens used, 3: the network limit, 4: percent used. */
				__( '%1$s: %2$s of %3$s tokens used (%4$d%%)', 'estasoft-news-distribution' ),
				$month,
				number_format_i18n( $used ),
				number_format_i18n( $limit ),
				(int) $usage['percent']
			);
			$bar   = Html::tag(
				'progress',
				array(
					'class'      => 'estasoft-nd-net-bar__track',
					'max'        => (string) $limit,
					'value'      => (string) min( $used, $limit ),
					'aria-label' => __( 'Network tokens this month', 'estasoft-news-distribution' ),
				),
				''
			);
			$lines = array();

			foreach ( (array) $usage['alerts'] as $alert ) {
				$line = $this->alert_line( (array) $alert );

				if ( '' !== $line ) {
					$lines[] = $line;
				}
			}

			$alerts = $this->help( array() === $lines ? __( 'No alert email has gone out this month.', 'estasoft-news-distribution' ) : implode( ' ', $lines ) );
		} else {
			$text = sprintf(
				/* translators: 1: a month and year such as "October 2026", 2: tokens used. */
				__( '%1$s: %2$s tokens used. The network has no limit, so nothing pauses the assistant.', 'estasoft-news-distribution' ),
				$month,
				number_format_i18n( $used )
			);
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4 estasoft-nd-net-month' ),
			$bar . $this->help( $text ) . $alerts
		);
	}

	/**
	 * One sentence about one alert email of this month.
	 *
	 * @param array<string, mixed> $alert Threshold, state and when (ISO 8601).
	 * @return string Plain text, or an empty string when there is nothing to say.
	 */
	private function alert_line( array $alert ): string {
		$percent = (int) $alert['threshold'];

		switch ( (string) $alert['state'] ) {
			case 'sent':
				return sprintf(
					/* translators: 1: percent of the limit, such as 80, 2: a date and time such as "21 Oct 2026, 14:35". */
					__( 'The %1$d%% alert was emailed on %2$s.', 'estasoft-news-distribution' ),
					$percent,
					Clock::datetime( (int) strtotime( (string) $alert['at'] ) )
				);
			case 'queued':
			case 'sending':
				return sprintf(
					/* translators: %d: percent of the limit, such as 80. */
					__( 'The %d%% alert email is waiting to be sent.', 'estasoft-news-distribution' ),
					$percent
				);
			case 'retry':
				return sprintf(
					/* translators: %d: percent of the limit, such as 80. */
					__( 'The %d%% alert email could not be sent. News Distribution tries once more tomorrow.', 'estasoft-news-distribution' ),
					$percent
				);
			case 'failed':
				return sprintf(
					/* translators: %d: percent of the limit, such as 80. */
					__( 'The %d%% alert email could not be sent. Check that WordPress can send email.', 'estasoft-news-distribution' ),
					$percent
				);
			default:
				return '';
		}
	}

	/**
	 * Where the network alert goes.
	 *
	 * @return string Plain text.
	 */
	private function alert_address(): string {
		$email = (string) get_site_option( 'admin_email', '' );

		if ( ! is_email( $email ) ) {
			return __( 'The network has no admin email address, so no alert can be sent. Set one in Settings, Network Settings.', 'estasoft-news-distribution' );
		}

		return sprintf(
			/* translators: %s: an email address. */
			__( 'The network alert is emailed to %s, the network admin email in Settings, Network Settings, at the alert level and again when the budget is used.', 'estasoft-news-distribution' ),
			$email
		);
	}

	/**
	 * Export, and how long the log is kept.
	 *
	 * @param array<string, mixed>  $values Values to show.
	 * @param array<string, string> $errors Messages by field.
	 * @return string
	 */
	private function audit_card( array $values, array $errors ): string {
		$links = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
			Button::link( __( 'Export CSV', 'estasoft-news-distribution' ), $this->export_url( 'csv' ) )
			. Button::link( __( 'Export JSON', 'estasoft-news-distribution' ), $this->export_url( 'json' ) )
		);

		return Card::flush(
			'audit-log',
			__( 'Audit log', 'estasoft-news-distribution' ),
			__( 'The AI log of every site, for your records or an auditor. It holds suggestions and decisions, never keys or copies of articles.', 'estasoft-news-distribution' ),
			$this->row( 'estasoft-nd-net-export', __( 'Export', 'estasoft-news-distribution' ), $links, true )
			. $this->row(
				'estasoft-nd-net-months',
				__( 'Keep the log for', 'estasoft-news-distribution' ),
				$this->select(
					'max_log_months',
					array(
						'12' => __( '12 months', 'estasoft-news-distribution' ),
						'24' => __( '24 months', 'estasoft-news-distribution' ),
						'36' => __( '36 months', 'estasoft-news-distribution' ),
					),
					(string) (int) $values['max_log_months']
				)
				. $this->error( 'max_log_months', $errors )
				. $this->help( __( 'A site can keep its log for less time, not longer. A single site changes it in Settings, Privacy and data. After that time entries are deleted, and this cannot be undone.', 'estasoft-news-distribution' ) ),
				false,
				'estasoft-nd-f-net-max_log_months'
			)
		);
	}

	/**
	 * Provider and use this month, for every site.
	 *
	 * @param array<string, mixed> $data The overview.
	 * @return string
	 */
	private function sites_card( array $data ): string {
		$cols = 'estasoft-nd-cols-net-ai';
		$head = Html::tag(
			'div',
			array(
				'class' => Html::classes( 'estasoft-nd-grid-row', 'estasoft-nd-grid-row--head', $cols ),
				'role'  => 'row',
			),
			$this->head( __( 'Site', 'estasoft-news-distribution' ) )
			. $this->head( __( 'Provider', 'estasoft-news-distribution' ) )
			. $this->head( __( 'Tokens this month', 'estasoft-news-distribution' ) )
			. $this->head( __( 'Assistant', 'estasoft-news-distribution' ) )
		);
		$rows = '';

		foreach ( (array) $data['sites'] as $site ) {
			$rows .= Html::tag(
				'div',
				array(
					'class' => Html::classes( 'estasoft-nd-grid-row', $cols ),
					'role'  => 'row',
				),
				$this->cell( Html::tag( 'span', array( 'class' => 'estasoft-nd-strong' ), esc_html( (string) $site['name'] ) ) )
				. $this->cell( '' === $site['provider_name'] ? $this->dash() : esc_html( (string) $site['provider_name'] ) )
				. $this->cell( (int) $site['tokens_month'] > 0 ? esc_html( number_format_i18n( (int) $site['tokens_month'] ) ) : $this->dash() )
				. $this->cell( $this->assistant( $site, (int) $data['policy']['alert_at'], $data ) )
			);
		}

		$limit = (int) $data['usage']['limit'];
		$text  = sprintf(
			/* translators: %d: number of sites. */
			_n( 'Provider and use this month, for %d site.', 'Provider and use this month, for all %d sites.', count( (array) $data['sites'] ), 'estasoft-news-distribution' ),
			count( (array) $data['sites'] )
		);

		if ( $limit > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: 1: tokens used, 2: the network limit. */
				__( 'Network total: %1$s of %2$s tokens.', 'estasoft-news-distribution' ),
				number_format_i18n( (int) $data['usage']['tokens_month'] ),
				number_format_i18n( $limit )
			);
		} else {
			$text .= ' ' . sprintf(
				/* translators: %s: tokens used. */
				__( 'Network total: %s tokens, with no limit.', 'estasoft-news-distribution' ),
				number_format_i18n( (int) $data['usage']['tokens_month'] )
			);
		}

		return Card::flush(
			'sites',
			__( 'Sites', 'estasoft-news-distribution' ),
			$text,
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-scroll' ),
				Html::tag(
					'div',
					array(
						'class'      => 'estasoft-nd-table',
						'role'       => 'table',
						'aria-label' => __( 'AI use by site', 'estasoft-news-distribution' ),
					),
					$head . $rows . Html::tag(
						'div',
						array( 'class' => 'estasoft-nd-table-footer' ),
						esc_html__( 'Sorted by tokens used this month. Each use is added as it happens.', 'estasoft-news-distribution' ) . ' '
						. Html::tag( 'a', array( 'href' => esc_url( $this->url( array( 'refresh' => 1 ) ) ) ), esc_html__( 'Refresh', 'estasoft-news-distribution' ) )
						. ' ' . esc_html__( 'counts again from every site.', 'estasoft-news-distribution' )
					)
				)
			)
		);
	}

	/**
	 * What the assistant is doing on a site, in words.
	 *
	 * @param array<string, mixed> $site     The site.
	 * @param int                  $alert_at Percent of a limit that is a warning.
	 * @param array<string, mixed> $data     The overview.
	 * @return string
	 */
	private function assistant( array $site, int $alert_at, array $data ): string {
		switch ( (string) $site['assistant'] ) {
			case 'on':
				if ( (int) $site['percent'] >= $alert_at && (int) $site['token_limit'] > 0 ) {
					return StatusView::inline(
						Status::ATTENTION,
						sprintf(
							/* translators: %d: percent of the site's limit used. */
							__( 'On, %d%% of its limit used', 'estasoft-news-distribution' ),
							(int) $site['percent']
						)
					);
				}

				return StatusView::inline( Status::HEALTHY, __( 'On', 'estasoft-news-distribution' ) );
			case 'needs_wordpress':
				return StatusView::inline(
					Status::OFF,
					sprintf(
						/* translators: 1: the lowest WordPress version, 2: the version now. */
						__( 'Off, needs WordPress %1$s (now %2$s)', 'estasoft-news-distribution' ),
						SiteAi::MIN_WORDPRESS,
						(string) $data['wordpress']['version']
					)
				);
			case 'blocked':
				return StatusView::inline( Status::OFF, __( 'Off, no provider is allowed', 'estasoft-news-distribution' ) );
			case 'paused_network':
				return StatusView::inline( Status::ATTENTION, __( 'Paused, the network’s monthly budget is used', 'estasoft-news-distribution' ) );
			case 'paused_site':
				return StatusView::inline( Status::ATTENTION, __( 'Paused, its monthly limit is used', 'estasoft-news-distribution' ) );
			default:
				return StatusView::inline( Status::OFF );
		}
	}

	/**
	 * "What it cannot do" and "Where a site sees this".
	 *
	 * @return string
	 */
	private function notes(): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-notes' ),
			Card::cannot(
				array(
					__( 'Change what a provider charges. Each site’s provider bills that site.', 'estasoft-news-distribution' ),
					__( 'Turn the assistant on for a site. Only that site’s owner can, after the consent step.', 'estasoft-news-distribution' ),
					__( 'Read article text from the log. The log keeps suggestions and decisions, not copies of articles.', 'estasoft-news-distribution' ),
				)
			)
			. Card::note(
				'where-a-site-sees-this',
				__( 'Where a site sees this', 'estasoft-news-distribution' ),
				Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'A site shows a setting set here with a lock and the words “Set by your network”. In Settings, AI assistant a site sees the drafts rule, author names, the allowed providers and the token limit this way.', 'estasoft-news-distribution' ) )
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'A site can still turn the assistant off or lower a limit.', 'estasoft-news-distribution' ) )
			)
		);
	}

	/* -------------------------------------------------------------- Handlers. */

	/**
	 * Save changes.
	 *
	 * @return void
	 */
	public function post_save(): void {
		$this->plugin->access()->require_or_die( Access::NETWORK );
		check_admin_referer( self::SAVE );

		$posted = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? map_deep( wp_unslash( $_POST[ self::FIELD ] ), 'sanitize_text_field' ) : array();
		$input  = array();

		foreach ( self::FIELDS as $field ) {
			if ( array_key_exists( $field, $posted ) ) {
				$input[ $field ] = $posted[ $field ];
			}
		}

		$saved = AiPolicy::save( $input );

		if ( is_wp_error( $saved ) ) {
			$data = $saved->get_error_data();

			set_site_transient(
				$this->draft_key(),
				array(
					'input'  => $input,
					'errors' => is_array( $data ) && isset( $data['fields'] ) ? (array) $data['fields'] : array(),
				),
				2 * MINUTE_IN_SECONDS
			);
			$this->flash( 'danger', __( 'Nothing was saved. Fix the fields marked below, then save again.', 'estasoft-news-distribution' ) );
			$this->back();
		}

		$this->flash(
			'success',
			$saved['allowed']
				? __( 'AI policy saved. Every site follows it from now on.', 'estasoft-news-distribution' )
				: __( 'AI policy saved. No provider is allowed, so the assistant is off on every site.', 'estasoft-news-distribution' )
		);
		$this->back();
	}

	/**
	 * Download the AI log of every site.
	 *
	 * @return void
	 */
	public function get_export(): void {
		$this->plugin->access()->require_or_die( Access::NETWORK );
		check_admin_referer( self::EXPORT );

		$format = Query::is( 'format', 'json' ) ? 'json' : 'csv';

		AiPolicy::audit(
			sprintf(
				/* translators: %s: CSV or JSON. */
				__( 'Network AI log exported as %s.', 'estasoft-news-distribution' ),
				strtoupper( $format )
			)
		);

		nocache_headers();
		header( 'Content-Type: ' . ( 'json' === $format ? 'application/json' : 'text/csv' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . AiExport::filename( $format ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		if ( 'csv' === $format ) {
			echo "\xEF\xBB\xBF"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The byte order mark, so a spreadsheet reads Arabic and Kurdish as UTF-8.
		}

		( new AiExport() )->write(
			$format,
			static function ( string $piece ): void {
				echo $piece; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A CSV or JSON file, never HTML.
			}
		);

		exit;
	}

	/* ---------------------------------------------------------------- Parts. */

	/**
	 * The address of an export link, with its nonce.
	 *
	 * @param string $format csv or json.
	 * @return string
	 */
	private function export_url( string $format ): string {
		return add_query_arg(
			array(
				'action'   => self::EXPORT,
				'format'   => $format,
				'_wpnonce' => wp_create_nonce( self::EXPORT ),
			),
			network_admin_url( 'edit.php' )
		);
	}

	/**
	 * A form row: a label on one side and the controls on the other.
	 *
	 * @param string      $id      Unique id of the row.
	 * @param string      $label   Label text.
	 * @param string      $control Trusted HTML.
	 * @param bool        $grouped True when the label names a group, not one control.
	 * @param string|null $label_for The id of the one control the label names.
	 * @return string
	 */
	private function row( string $id, string $label, string $control, bool $grouped, ?string $label_for = null ): string {
		if ( $grouped || null === $label_for ) {
			$name = Html::tag(
				'div',
				array(
					'class' => 'estasoft-nd-form-label',
					'id'    => $id . '-label',
				),
				esc_html( $label )
			);
			$body = Html::tag(
				'div',
				array(
					'class'           => 'estasoft-nd-form-control',
					'role'            => 'group',
					'aria-labelledby' => $id . '-label',
				),
				$control
			);
		} else {
			$name = Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-form-label',
					'for'   => $label_for,
				),
				esc_html( $label )
			);
			$body = Html::tag( 'div', array( 'class' => 'estasoft-nd-form-control' ), $control );
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-form-row' ), $name . $body );
	}

	/**
	 * A check box with a title and a line of help beside it.
	 *
	 * @param string $field Field name.
	 * @param bool   $on    Whether it is ticked.
	 * @param string $title Bold text.
	 * @param string $help  Help under the title.
	 * @return string
	 */
	private function checkbox( string $field, bool $on, string $title, string $help ): string {
		return Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => $this->name( $field ),
				'value' => '0',
			)
		)
			. Html::tag(
				'label',
				array( 'class' => 'estasoft-nd-check estasoft-nd-check--top' ),
				Html::void(
					'input',
					array(
						'type'    => 'checkbox',
						'name'    => $this->name( $field ),
						'value'   => '1',
						'checked' => $on,
					)
				)
				. Html::tag(
					'span',
					array(),
					Html::tag( 'strong', array(), esc_html( $title ) ) . '<br>' . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $help ) )
				)
			);
	}

	/**
	 * A select.
	 *
	 * @param string                $field   Field name.
	 * @param array<string, string> $options Value => label.
	 * @param string                $current Current value.
	 * @return string
	 */
	private function select( string $field, array $options, string $current ): string {
		$items = '';

		foreach ( $options as $value => $text ) {
			$items .= Html::tag(
				'option',
				array(
					'value'    => (string) $value,
					'selected' => (string) $value === $current,
				),
				esc_html( $text )
			);
		}

		return Html::tag(
			'select',
			array(
				'class' => Html::classes( 'estasoft-nd-field', 'estasoft-nd-w-260' ),
				'id'    => 'estasoft-nd-f-net-' . $field,
				'name'  => $this->name( $field ),
			),
			$items
		);
	}

	/**
	 * A text box for a whole number, with its message under it.
	 *
	 * @param string                $field  Field name.
	 * @param mixed                 $value  The value, a number or what was typed.
	 * @param array<string, string> $errors Messages by field.
	 * @return string
	 */
	private function number_field( string $field, $value, array $errors ): string {
		$error = isset( $errors[ $field ] );
		$shown = is_int( $value ) ? number_format_i18n( $value ) : (string) $value;

		return Html::void(
			'input',
			array(
				'class'            => Html::classes( 'estasoft-nd-field', 'estasoft-nd-w-200', $error ? 'has-error' : '' ),
				'type'             => 'text',
				'inputmode'        => 'numeric',
				'id'               => 'estasoft-nd-f-net-' . $field,
				'name'             => $this->name( $field ),
				'value'            => $shown,
				'autocomplete'     => 'off',
				'aria-invalid'     => $error ? 'true' : null,
				'aria-describedby' => $error ? 'estasoft-nd-net-err-' . $field : null,
			)
		) . $this->error( $field, $errors );
	}

	/**
	 * The message of a rejected field.
	 *
	 * @param string                $field  Field name.
	 * @param array<string, string> $errors Messages by field.
	 * @return string
	 */
	private function error( string $field, array $errors ): string {
		if ( ! isset( $errors[ $field ] ) ) {
			return '';
		}

		return Html::tag(
			'p',
			array(
				'class' => 'estasoft-nd-field-error',
				'id'    => 'estasoft-nd-net-err-' . $field,
				'role'  => 'alert',
			),
			Icon::svg( 'x-circle' ) . Html::tag( 'span', array(), esc_html( (string) $errors[ $field ] ) )
		);
	}

	/**
	 * A line of help.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	private function help( string $text ): string {
		return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $text ) );
	}

	/**
	 * A line with a lock: a rule the network sets and a site cannot change.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	private function locked( string $text ): string {
		return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-net-locked' ), Icon::svg( 'lock' ) . Html::tag( 'span', array(), esc_html( $text ) ) );
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

	/**
	 * The name of an input.
	 *
	 * @param string $field Field name.
	 * @param bool   $as_list Whether the field is a list.
	 * @return string
	 */
	private function name( string $field, bool $as_list = false ): string {
		return self::FIELD . '[' . $field . ']' . ( $as_list ? '[]' : '' );
	}

	/* ---------------------------------------------------------------- Drafts. */

	/**
	 * Where a rejected form is kept for the next view.
	 *
	 * @return string
	 */
	private function draft_key(): string {
		return NetworkAdmin::FLASH . 'draft_' . get_current_user_id();
	}

	/**
	 * What a rejected form held, and why it was rejected. Printed once.
	 *
	 * @return array{input: array<string, mixed>, errors: array<string, string>}
	 */
	private function take_draft(): array {
		$kept = get_site_transient( $this->draft_key() );

		if ( ! is_array( $kept ) ) {
			return array(
				'input'  => array(),
				'errors' => array(),
			);
		}

		delete_site_transient( $this->draft_key() );

		return array(
			'input'  => is_array( $kept['input'] ?? null ) ? $kept['input'] : array(),
			'errors' => is_array( $kept['errors'] ?? null ) ? array_map( 'strval', $kept['errors'] ) : array(),
		);
	}

	/**
	 * The values the form shows: the policy in force, with what a rejected form held on top.
	 *
	 * @param array<string, mixed> $policy The policy in force.
	 * @param array<string, mixed> $input  What a rejected form held.
	 * @return array<string, mixed>
	 */
	private function values( array $policy, array $input ): array {
		$values = $policy;

		foreach ( $input as $field => $value ) {
			if ( 'providers' === $field ) {
				$values['providers'] = array_values( array_filter( array_map( 'strval', (array) $value ), 'strlen' ) );
			} elseif ( in_array( $field, array( 'drafts', 'approve_translations' ), true ) ) {
				$values[ $field ] = '1' === (string) $value;
			} else {
				$values[ $field ] = $value;
			}
		}

		return $values;
	}
}
